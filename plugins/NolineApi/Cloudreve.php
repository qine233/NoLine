<?php

namespace TypechoPlugin\NolineApi;

use Widget\Options;
use Widget\Security;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Cloudreve v4 图床：后台编辑器里选文件 → 传到 Cloudreve → 把永久直链插进正文。
 *
 * 只做「并存」：Typecho 自带的附件上传一点不动，这里是在附件面板上多加一块图床区，
 * 传完的内容是正文里的一个外链，不进 table.contents 的附件表。
 * 之所以不接管自带上传：那要 hook Widget_Upload:uploadHandle，一旦挂了，核心的
 * beforeUpload/upload 通知和附件行照样跑，等于在核心的数据结构里伪造一个本地不存在的路径。
 *
 * Cloudreve 侧只走 v4 REST（routers/router.go）：
 *   POST   /api/v4/session/token          邮箱 + 密码登录，data 是 {user, token}
 *   POST   /api/v4/session/token/refresh  续一个新的 access，data 直接就是 token 本身
 *   DELETE /api/v4/session/token          作废这一串会话
 *   PUT    /api/v4/file/upload            建会话，拿 session_id / chunk_size / upload_urls
 *   POST   /api/v4/file/upload/{sid}/{i}  原始字节，一段一个 index；最后一段传完核心自己收尾
 *   PUT    /api/v4/file/source            拿永久直链（/f/{id}/{name}，匿名可访问）
 * 三段上传都不需要 POST /api/v4/file/create——那个接口造的是空文件，上传路径用不上。
 *
 * 登录：设置里只填邮箱和密码。需要凭据时先看缓存的 access（v4 默认只活 1 小时），
 * 快到期就用登录时一起拿到的 refresh token 续，续不动再重新登一次。留这层缓存是因为
 * 一次上传本身要打好几个请求，每个都先登一遍既慢又会在 Cloudreve 那边堆一堆会话。
 * 而 refresh 也随时可能废掉——在 Cloudreve 改过密码或者退出过登录，StateHash 一变
 * refresh 就拒（pkg/auth/jwt.go:150），能救回来的只有账号密码，所以这个入口不能省。
 *
 * 两道绕不过去的坎，命中就直接说清楚该去哪儿改：实例开了登录验证码
 * （middleware.CaptchaRequired 在业务逻辑之前把请求挡下，回 40026）、账号开了两步验证
 * （登录回 203 + 一个会话 id，动态码只在用户手上）。
 *
 * 邮箱、密码和令牌都只待在服务端：浏览器请求打到本插件，由这里带上 Authorization 转发给 Cloudreve。
 */
class Cloudreve
{
    /** 前台路由：?noline_api=upload，只吃 POST，登录态 + 会话令牌才放行 */
    public const ROUTE = 'upload';

    /** 一次从盘上读多少字节往 Cloudreve 推。chunk_size=0 时也不把整个文件读进内存 */
    private const SLICE = 2097152;

    /** access token 到期前多久就重换，避免卡在请求中途过期 */
    private const EARLY = 120;

    /** 默认单文件上限（MB），设置里没填 crMaxSize 时用它 */
    public const DEFAULT_MAX = 20;

    /** 插件设置里那个默认值要跟这里一致，所以露出去给 Plugin::config() 用 */
    public const DEFAULT_ALLOW = 'jpg,jpeg,png,gif,webp,bmp,svg,ico,pdf,zip,txt,mp4,webm';

    /** 状态文件跟接口数据放一起，靠 .php 后缀 + 守卫挡住直接访问 */
    private const STATE_FILE = '/usr/uploads/noline_api/cloudreve.php';
    private const GUARD = "<?php if (!defined('__TYPECHO_ROOT_DIR__')) { exit; } ?>\n";

    /** 管理页「最近上传」列几条 */
    private const KEEP_RECORDS = 20;

    /* ======================================================================
       入口一：编辑器注入与上传请求
       ====================================================================== */

    /**
     * 写文章 / 写页面底部的图床面板（admin/write-post.php:bottom、write-page.php:bottom）。
     *
     * 挂在 bottom 而不是 richEditor：一旦有插件占了 richEditor，核心就跳过 editor-js.php，
     * 默认编辑器连带它的插入行为整个没了。这里只在页面收口前追加一块自己的 DOM。
     */
    public static function onWriteBottom($content = null)
    {
        try {
            if (!self::ready() || !self::canUpload()) {
                return;
            }

            echo self::panel();
        } catch (\Throwable $e) {
            /* 这块 DOM 坏了不该把写文章页一起带走；原因记进状态文件，
               在「主题管理」的图床区块里当「上次失败」显示出来 */
            self::note(_t('图床面板没渲染出来：%s', $e->getMessage()));
        }
    }

    /**
     * 处理一次上传请求并结束。只有 route() 认到 upload 才会走到这里。
     */
    public static function handle()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';

        if ($method !== 'POST') {
            self::reply(array('code' => 405, 'message' => _t('上传只接受 POST。')));
        }

        if (!self::ready()) {
            self::reply(array('code' => 503, 'message' => _t('图床没有开启，或者实例地址 / 邮箱密码没填全。')));
        }

        if (!self::canUpload()) {
            self::reply(array('code' => 403, 'message' => _t('登录状态不对，只有能写文章的用户可以传图。')));
        }

        $given = isset($_POST['_']) ? (string)$_POST['_'] : '';

        if ($given === '' || !hash_equals(Security::alloc()->getToken(null), $given)) {
            self::reply(array('code' => 403, 'message' => _t('安全令牌对不上，刷新页面再传一次。')));
        }

        if (!isset($_FILES['file'])) {
            self::reply(array('code' => 400, 'message' => _t('请求里没有 file 字段。')));
        }

        self::reply(self::receive($_FILES['file']));
    }

    /**
     * @param array $file 一个 $_FILES 元素
     * @return array 直接发给浏览器的 JSON 结构
     */
    private static function receive(array $file): array
    {
        if (!isset($file['error'])) {
            return array('code' => 400, 'message' => _t('文件字段不完整。'));
        }

        $error = (int)$file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            return array('code' => 400, 'message' => self::phpError($error));
        }

        $path = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';

        // 不是本次请求传上来的文件一律不读，免得被人拿任意路径试探盘上的东西
        if ($path === '' || !is_uploaded_file($path)) {
            return array('code' => 400, 'message' => _t('上传临时文件无效。'));
        }

        $size = isset($file['size']) ? (int)$file['size'] : 0;
        $limit = self::maxBytes();

        if ($size <= 0) {
            return array('code' => 400, 'message' => _t('空文件不用传。'));
        }

        if ($size > $limit) {
            return array('code' => 400, 'message' => _t('文件 %1$s，超过上限 %2$s MB。',
                self::humanSize($size), self::maxSetting()));
        }

        $mime = self::mimeOf($path, isset($file['name']) ? (string)$file['name'] : '');
        $ext = self::extensionOf(isset($file['name']) ? (string)$file['name'] : '', $mime);

        if (!self::allowed($ext)) {
            return array('code' => 400, 'message' => _t('「.%s」不在允许的扩展名里，要放行去插件设置里加。', $ext));
        }

        $result = self::upload($path, $size, $mime,
            isset($file['name']) ? (string)$file['name'] : '', $ext);

        if (!$result['ok']) {
            return array('code' => 502, 'message' => $result['message']);
        }

        self::remember($result, $size);

        return array(
            'code' => 0,
            'data' => array(
                'url' => $result['url'],
                'name' => $result['name'],
                'size' => $size,
                'bytes' => self::humanSize($size),
                'isImage' => $result['isImage'],
                'mime' => $mime,
            ),
        );
    }

    /* ======================================================================
       上传链路
       ====================================================================== */

    /**
     * @return array [ok, url, name, isImage, message]
     */
    private static function upload(string $path, int $size, string $mime, string $original, string $ext): array
    {
        $token = self::accessToken();

        if ($token['error'] !== '') {
            return self::bad($token['error']);
        }

        $name = self::nameFor($original, $ext);
        $uri = self::uri($name);
        $session = self::createSession($uri, $size, $mime, $token['token']);

        // 401 = 缓存的会话被 Cloudreve 判死了（在网盘那边改过密码、退出过登录都会这样）。
        // 留账号密码的意义就在这里：这时候能自己爬起来，不用人回插件设置里补东西
        if (!$session['ok'] && $session['code'] === 401) {
            $token = self::accessToken(true);

            if ($token['error'] !== '') {
                return self::bad($token['error']);
            }

            $session = self::createSession($uri, $size, $mime, $token['token']);
        }

        if (!$session['ok']) {
            // 目录不存在是最常见的第一次失败，建一次重试；建目录失败本身不用往外说
            if (!self::createFolder(self::parentUri($uri), $token['token'])) {
                return self::bad($session['message']);
            }

            $session = self::createSession($uri, $size, $mime, $token['token']);

            if (!$session['ok']) {
                return self::bad($session['message']);
            }
        }

        $data = $session['data'];

        // upload_urls 非空说明策略是浏览器直传第三方对象存储：那套回调协议本插件没有实现，
        // 与其猜字段拼请求，不如让人去 Cloudreve 后台把这个策略改成走服务端
        if (!empty($data['upload_urls'])) {
            return self::bad(_t('这个存储策略要求直传第三方对象存储（COS / S3 / 七牛等），插件只实现了走 Cloudreve '
                . '服务端的链路。请在 Cloudreve 后台把该策略的传输方式改成「服务端中转」，'
                . '或者换一个本地存储策略。'));
        }

        $sent = self::sendChunks($path, $size, $session, $token['token']);

        if (!$sent['ok']) {
            return self::bad($sent['message']);
        }

        $link = self::directLink($uri, $token['token']);

        if (!$link['ok']) {
            return self::bad($link['message']);
        }

        return array(
            'ok' => true,
            'url' => $link['url'],
            'name' => $original === '' ? $name : $original,
            'isImage' => strpos($mime, 'image/') === 0,
            'uri' => $uri,
            'message' => '',
        );
    }

    /**
     * PUT /api/v4/file/upload：size 必须和真实字节数一致，核心拿 Content-Length 逐块核对。
     *
     * @return array [ok, code, data, message] code 是 Cloudreve 的业务码，调用点靠它认 401
     */
    private static function createSession(string $uri, int $size, string $mime, string $token): array
    {
        $response = self::request('PUT', self::api('file/upload'), array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
       ), json_encode(array(
            'uri' => $uri,
            'size' => $size,
            'mime_type' => $mime,
            'last_modified' => (int)(microtime(true) * 1000),
        )), 30);

        if (!$response['ok']) {
            return array('ok' => false, 'code' => $response['code'], 'data' => array(),
                'message' => $response['message']);
        }

        return array('ok' => true, 'code' => 0,
            'data' => is_array($response['data']) ? $response['data'] : array(),
            'message' => '',
        );
    }

    /**
     * POST /api/v4/file/upload/{session}/{index}：原始字节，不是 multipart。
     *
     * chunk_size 为 0 表示不分块（一次整个文件）；否则按 chunk_size 切，最后一段不足也要照发。
     * 核心在收齐所有段之后自己收尾（processChunkUpload 里 MarkChunkUploaded + CompleteUpload），
     * 所以这里传完不需要再调 file/create。
     *
     * @return array [ok, message]
     */
    private static function sendChunks(string $path, int $size, array $session, string $token): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return array('ok' => false, 'message' => _t('读不到上传的临时文件。'));
        }

        $chunk = isset($session['data']['chunk_size']) ? (int)$session['data']['chunk_size'] : 0;
        $sessionId = isset($session['data']['session_id']) ? (string)$session['data']['session_id'] : '';

        if ($sessionId === '') {
            fclose($handle);

            return array('ok' => false, 'message' => _t('Cloudreve 没返回 session_id，传不了。'));
        }

        $step = $chunk > 0 ? $chunk : self::SLICE;
        $index = 0;
        $read = 0;

        while ($read < $size) {
            $want = $size - $read;

            if ($want > $step) {
                $want = $step;
            }

            $body = self::readExactly($handle, $want);

            if ($body === null) {
                fclose($handle);

                return array('ok' => false, 'message' => _t('文件比声称的 %d 字节短，中断了。', $size));
            }

            $response = self::request('POST',
                self::api('file/upload/' . rawurlencode($sessionId) . '/' . $index),
                array(
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/octet-stream',
                ), $body, 120);

            if (!$response['ok']) {
                fclose($handle);

                $hint = $chunk > 0 && $index > 0
                    ? _t('（第 %1$d 段失败，每段 %2$s）', $index + 1, self::humanSize($chunk))
                    : '';

                return array('ok' => false, 'message' => $response['message'] . $hint);
            }

            $read += strlen($body);
            $index++;
        }

        fclose($handle);

        return array('ok' => true, 'message' => '');
    }

    /**
     * PUT /api/v4/file/source：{link, file_url}。
     *
     * link 是 /f/{id}/{name} 形态的中转永久直链（匿名可访问，302 到当期签名 URL），
     * 只有它能写进正文当图床链接。POST /api/v4/file/url 那个是带过期的临时链接，不做默认。
     *
     * @return array [ok, url, message]
     */
    private static function directLink(string $uri, string $token): array
    {
        $response = self::request('PUT', self::api('file/source'), array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ), json_encode(array('uris' => array($uri))), 30);

        if (!$response['ok']) {
            return array('ok' => false, 'url' => '', 'message' => $response['message']);
        }

        $first = (is_array($response['data']) && isset($response['data'][0]) && is_array($response['data'][0]))
            ? $response['data'][0] : array();
        $url = isset($first['link']) ? trim((string)$first['link']) : '';

        if ($url === '') {
            return array('ok' => false, 'url' => '',
                'message' => _t('文件传上去了，但 Cloudreve 没给回直链（source 接口返回空）。'));
        }

        return array('ok' => true, 'url' => $url, 'message' => '');
    }

    /**
     * @return bool 目录已经在（或者建成功）
     */
    private static function createFolder(string $uri, string $token): bool
    {
        $response = self::request('POST', self::api('file/create'), array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ), json_encode(array('uri' => $uri, 'type' => 'folder')), 20);

        return $response['ok'];
    }

    /* ======================================================================
       登录与令牌
       ====================================================================== */

    /**
     * 拿到一个可用的 access token：缓存没到期直接用，到期前拿 refresh token 续一个，
     * 续不动就用邮箱密码重新登一次。
     *
     * @param bool $force 跳过缓存和 refresh，直接重新登录；接口回了 401 才走这条路
     * @return array [token, error]
     */
    private static function accessToken(bool $force = false): array
    {
        $current = self::fingerprint();
        $state = self::state();

        // 换了实例地址、换了账号、改了密码：手里的会话是上一份凭据登出来的，不能接着用
        if ($force || (string)$state['account'] !== $current) {
            return self::login($current);
        }

        $access = trim((string)$state['access']);
        $expires = (int)$state['expires'];

        if ($access !== '' && $expires - time() > self::EARLY) {
            return array('token' => $access, 'error' => '');
        }

        $renewed = self::renewToken(trim((string)$state['refresh']));

        if ($renewed['error'] === '') {
            return $renewed;
        }

        $fresh = self::login($current);

        if ($fresh['error'] === '') {
            return $fresh;
        }

        // 两条路的失败原因都可能有用：refresh 为什么废了、登录又被什么挡住了
        return array('token' => '', 'error' => $renewed['error'] . ' ' . $fresh['error']);
    }

    /**
     * POST /api/v4/session/token：邮箱 + 密码登录，会话成对拿回来。
     *
     * @param string $account 这份会话属于哪份凭据，见 fingerprint()
     * @return array [token, error]
     */
    private static function login(string $account): array
    {
        $email = trim(self::setting('crEmail', ''));
        $password = (string)self::setting('crPassword', '');

        if ($email === '' || $password === '') {
            return array('token' => '', 'error' => _t('没配登录信息：Cloudreve 的邮箱和密码都要填。'));
        }

        $body = json_encode(array('email' => $email, 'password' => $password), JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            return array('token' => '', 'error' => _t('邮箱或密码里有非 UTF-8 字节，登录请求发不出去。'));
        }

        $response = self::request('POST', self::api('session/token'), array(
            'Content-Type: application/json',
        ), $body, 20);

        if (!$response['ok']) {
            return array('token' => '', 'error' => self::blocked($response['code'])
                . _t('登录失败：%s', $response['message']));
        }

        $token = self::tokenOf($response['data']);

        if ($token['access'] === '') {
            return array('token' => '', 'error' => _t('登录接口没返回 access_token，不像 Cloudreve v4。'));
        }

        self::keep($account, $token);

        return array('token' => $token['access'], 'error' => '');
    }

    /**
     * POST /api/v4/session/token/refresh：登录时拿到的 refresh token 还没到期，
     * 就只用它换一个新的 access，省掉一次密码上线。
     *
     * @return array [token, error]
     */
    private static function renewToken(string $refresh): array
    {
        if ($refresh === '') {
            return array('token' => '', 'error' => _t('本地还没有会话。'));
        }

        $response = self::request('POST', self::api('session/token/refresh'), array(
            'Content-Type: application/json',
        ), json_encode(array('refresh_token' => $refresh)), 20, true);

        if (!$response['ok']) {
            return array('token' => '', 'error' => _t('续会话失败：%s', $response['message']));
        }

        $token = self::tokenOf($response['data']);

        if ($token['access'] === '') {
            return array('token' => '', 'error' => _t('续会话接口没返回 access_token。'));
        }

        // v4 不轮换 refresh token（Issue 复用同一个 RootTokenID），它回什么就存什么
        $token['refresh'] = $token['refresh'] === '' ? $refresh : $token['refresh'];
        self::keep('', $token);

        return array('token' => $token['access'], 'error' => '');
    }

    /**
     * 把会话写进状态文件。$account 留空表示「凭据没变，只换了令牌」。
     */
    private static function keep(string $account, array $token): void
    {
        $state = self::state();
        $state['access'] = $token['access'];
        $state['refresh'] = $token['refresh'];
        $state['expires'] = $token['expires'];
        $state['checked'] = time();
        $state['error'] = '';

        if ($account !== '') {
            $state['account'] = $account;
        }

        self::saveState($state);
    }

    /**
     * 两种响应的令牌字段位置不一样：登录的 data 外面还包了一层 {user, token}，
     * 续会话的 data 本身就是 token。这里一次抹平，别在调用点各写一份。
     *
     * @param mixed $data
     * @return array [access, refresh, expires]
     */
    private static function tokenOf($data): array
    {
        if (!is_array($data)) {
            return array('access' => '', 'refresh' => '', 'expires' => 0);
        }

        $token = (isset($data['token']) && is_array($data['token'])) ? $data['token'] : $data;

        return array(
            'access' => isset($token['access_token']) ? trim((string)$token['access_token']) : '',
            'refresh' => isset($token['refresh_token']) ? trim((string)$token['refresh_token']) : '',
            'expires' => self::expiresAt(
                isset($token['access_expires']) ? (string)$token['access_expires'] : ''),
        );
    }

    /**
     * 「这份会话是哪份凭据登出来的」。存哈希不存原文：状态文件在 usr/uploads 底下，
     * 多留一份能反推出密码的东西没有意义，比对这个哈希又只需要相等与否。
     */
    private static function fingerprint(): string
    {
        return hash('sha256', self::baseUrl() . '|' . trim(self::setting('crEmail', ''))
            . '|' . self::setting('crPassword', ''));
    }

    /**
     * 这两道坎插件自己过不去，光看错误码没人知道该动哪里，所以把话说完。
     */
    private static function blocked(int $code): string
    {
        if ($code === 40026 || $code === 40027) {
            return _t('这个 Cloudreve 开了登录验证码：请求在进业务逻辑之前就被挡下了，'
                . '插件既拿不到也解不开那张图。请在 Cloudreve 后台关掉登录验证码，'
                . '图床才能用账号密码登录。');
        }

        if ($code === 203) {
            return _t('这个账号开了两步验证：登录只回一个会话 id，动态码只在你手上，插件填不了。'
                . '关掉两步验证，或者给图床单独建一个不开两步验证的账号。');
        }

        return '';
    }

    /**
     * access_expires 是 RFC3339；解析不动就按 1 小时算，反正误判最多是提前一点重新登录。
     */
    private static function expiresAt(string $text): int
    {
        if ($text === '') {
            return time() + 3600;
        }

        $time = strtotime($text);

        return $time === false ? time() + 3600 : $time;
    }

    /* ======================================================================
       入口二：管理页区块与动作
       ====================================================================== */

    /**
     * 管理页里的「Cloudreve 图床」一段。
     */
    public static function section(string $token, string $url): string
    {
        $state = self::state();
        $host = trim(self::setting('crHost', ''));
        $email = trim(self::setting('crEmail', ''));
        $password = (string)self::setting('crPassword', '');

        $html = '<h3 class="nl-h3">' . Admin::esc(_t('Cloudreve 图床')) . '</h3>'
            . '<p class="nl-lead">' . Admin::esc(_t(
                '后台编辑器里的「传图床」：选文件 → 传到 Cloudreve → 永久直链插进正文，'
                . '和 Typecho 自带的附件上传并存。配置项在「控制台 → 插件 → NolineApi → 设置」里。'))
            . '</p>';

        $chips = array();
        $chips[] = self::config('crEnabled', '1') === '1'
            ? array('ok', _t('已开启'))
            : array('muted', _t('已关闭'));

        $base = self::baseUrl();

        if ($host === '') {
            $chips[] = array('bad', _t('没填实例地址'));
        } elseif ($base === '') {
            $chips[] = array('bad', _t('实例地址看不懂：%s', $host));
        } else {
            $chips[] = array('ok', _t('实例 %s', $base));
        }

        if ($email === '' || $password === '') {
            $chips[] = array('bad', _t('没填 Cloudreve 的邮箱或密码'));
        } else {
            $chips[] = array('ok', _t('账号 %s', $email));
        }

        $expires = isset($state['expires']) ? (int)$state['expires'] : 0;

        if ((string)$state['account'] === '') {
            $chips[] = array('muted', _t('还没登录过，第一次传图会自动登'));
        } elseif ((string)$state['account'] !== self::fingerprint()) {
            $chips[] = array('warn', _t('地址 / 邮箱 / 密码改过了，下次传图重新登录'));
        } elseif ($expires > time()) {
            $chips[] = array('muted', _t('已登录，会话能用到 %s', date('H:i:s', $expires)));
        } else {
            $chips[] = array('muted', _t('会话到期了，下次传图自动续'));
        }

        $html .= '<div class="nl-chips">';

        foreach ($chips as $chip) {
            $html .= '<span class="nl-chip nl-chip-' . Admin::esc($chip[0]) . '">'
                . Admin::esc($chip[1]) . '</span>';
        }

        $html .= '</div>';

        $html .= '<div class="nl-inline">'
            . '<form method="post" action="' . Admin::esc($url) . '">'
            . '<input type="hidden" name="action" value="cr_probe">'
            . '<input type="hidden" name="_" value="' . Admin::esc($token) . '">'
            . '<button class="btn btn-s" type="submit">' . Admin::esc(_t('测试连接')) . '</button>'
            . '</form> '
            . '<form method="post" action="' . Admin::esc($url) . '">'
            . '<input type="hidden" name="action" value="cr_forget">'
            . '<input type="hidden" name="_" value="' . Admin::esc($token) . '">'
            . '<button class="btn btn-s" type="submit">' . Admin::esc(_t('清掉登录缓存')) . '</button>'
            . '</form>'
            . '<span class="nl-note">' . Admin::esc(_t(
                '测试连接只做三件事：用邮箱密码登一次、列一下目标目录、确认能取直链，不会真的传文件。'
                . '「清掉登录缓存」会顺手把这一串会话在 Cloudreve 那边作废掉，下次传图重新登录。'))
            . '</span></div>';

        $html .= '<table class="nl-list"><tbody>';
        $html .= self::row(_t('目标目录'), self::targetDir(),
            _t('直链落在这里；不存在会顺手建出来'));
        $html .= self::row(_t('单文件上限'), self::maxSetting() . ' MB',
            _t('PHP 侧 upload_max_filesize=%1$s、post_max_size=%2$s，实际生效的是两者里更小的那个',
                self::iniSize('upload_max_filesize'), self::iniSize('post_max_size')));
        $html .= self::row(_t('允许的扩展名'), self::allowList(), _t('逗号分隔，改完直接生效'));
        $html .= self::row(_t('粘贴截图直传'),
            self::config('crPaste', '1') === '1' ? _t('开') : _t('关'),
            _t('开着时，在正文框里粘贴截图会自动走图床；拖拽只认图床框自己，不抢自带上传的落点'));

        if (isset($state['error']) && trim((string)$state['error']) !== '') {
            $html .= self::row(_t('上次失败'), (string)$state['error'], _t('原文摘录，便于对着 Cloudreve 查'));
        }

        $html .= '</tbody></table>';

        $records = (isset($state['records']) && is_array($state['records'])) ? $state['records'] : array();

        if ($records !== array()) {
            $html .= '<h4 class="nl-h4">' . Admin::esc(_t('最近上传')) . '</h4><table class="nl-list"><tbody>';

            foreach ($records as $record) {
                $html .= '<tr><td><b>' . Admin::esc(isset($record['name']) ? $record['name'] : '') . '</b>'
                    . '<em>' . Admin::esc(isset($record['time']) ? date('m-d H:i', (int)$record['time']) : '')
                    . ' · ' . Admin::esc(isset($record['bytes']) ? $record['bytes'] : '') . '</em></td>'
                    . '<td class="nl-ops"><a class="btn btn-s" target="_blank" rel="noopener" href="'
                    . Admin::esc(isset($record['url']) ? $record['url'] : '') . '">'
                    . Admin::esc(_t('看')) . '</a></td></tr>';
            }

            $html .= '</tbody></table>';
        }

        return $html;
    }

    /**
     * @return array [级别, 文案]
     */
    public static function act(string $action): array
    {
        if ($action === 'cr_forget') {
            $state = self::state();
            $refresh = trim((string)$state['refresh']);

            // 本地清掉不够：那一串 refresh token 在 Cloudreve 那边还能用到 180 天。
            // 作废失败不拦着本地清理——多半是它本来就已经死了
            if ($refresh !== '') {
                self::request('DELETE', self::api('session/token'), array(
                    'Content-Type: application/json',
                ), json_encode(array('refresh_token' => $refresh)), 20, true);
            }

            $state['access'] = '';
            $state['refresh'] = '';
            $state['expires'] = 0;
            $state['account'] = '';
            self::saveState($state);

            return array('success', _t('登录缓存已清掉，Cloudreve 那边那串会话也一并作废了，下次传图会重新登录。'));
        }

        if ($action !== 'cr_probe') {
            return array('error', _t('不认识的动作 %s', $action));
        }

        $lines = array();
        $token = self::accessToken();

        if ($token['error'] !== '') {
            return array('error', $token['error']);
        }

        $expires = (int)self::state()['expires'];

        $lines[] = _t('已登录，会话有效到 %s。', date('m-d H:i:s', $expires));

        $dir = self::uri('');
        $listed = self::request('GET', self::api('file?uri=' . rawurlencode($dir) . '&page=1'),
            array('Authorization: Bearer ' . $token['token']), null, 20, true);

        if (!$listed['ok']) {
            return array('error', _t('列目录失败：%s', $listed['message'])
                . _t('（目录不存在不要紧，第一次传文件会自动建）'));
        }

        $lines[] = _t('目标目录 %s 连得上。', $dir);

        // 直链接口拿一个不存在的 uri，只看它会不会好好回话：回话了就说明这条路通了
        $probe = self::request('PUT', self::api('file/source'), array(
            'Authorization: Bearer ' . $token['token'],
            'Content-Type: application/json',
        ), json_encode(array('uris' => array(self::uri('__noline_probe__')))), 20, true);

        $link = (isset($probe['data'][0]['link']) && is_string($probe['data'][0]['link']))
            ? $probe['data'][0]['link'] : '';

        if ($probe['ok'] && $link !== '') {
            $lines[] = _t('直链接口给了链接（这个测试路径居然存在）：%s', $link);
        } elseif ($probe['ok']) {
            $lines[] = _t('直链接口回话正常，只是没带链接字段。');
        } elseif ($probe['code'] > 0) {
            $lines[] = _t('直链接口回话正常：错误码 %d（测试路径不存在是预期的）。', $probe['code']);
        } else {
            return array('error', _t('直链接口连不通：%s', $probe['message']));
        }

        return array('success', implode('<br>', array_map(array('Admin', 'esc'), $lines)));
    }

    private static function row(string $key, string $value, string $hint): string
    {
        return '<tr><td><b>' . Admin::esc($key) . '</b>'
            . '<em>' . Admin::esc($value) . '</em>'
            . '<em>' . Admin::esc($hint) . '</em></td></tr>';
    }

    /* ======================================================================
       编辑器面板
       ====================================================================== */

    private static function panel(): string
    {
        $options = Options::alloc();
        $endpoint = rtrim((string)$options->index, '/') . '/?noline_api=' . self::ROUTE;
        $max = self::maxSetting();
        $md = !empty($options->markdown);

        return '<div id="nl-cr" class="nl-cr"'
            . ' data-endpoint="' . Admin::esc($endpoint) . '"'
            . ' data-token="' . Admin::esc(Security::alloc()->getToken(null)) . '"'
            . ' data-paste="' . Admin::esc(self::config('crPaste', '1')) . '"'
            . ' data-tpl-img="' . Admin::esc(self::template(true, $md)) . '"'
            . ' data-tpl-file="' . Admin::esc(self::template(false, $md)) . '">'
            . '<div class="nl-cr-bar">'
            . '<span class="nl-cr-title">' . Admin::esc(_t('图床 · Cloudreve')) . '</span>'
            . '<label class="nl-cr-pick btn btn-s">' . Admin::esc(_t('选择文件'))
            . '<input type="file" multiple></label>'
            . '<span class="nl-cr-hint">' . Admin::esc(_t('拖进来也可以；单个不超过 %d MB，传完自动把直链插到光标处。', $max))
            . '</span></div>'
            . '<ul class="nl-cr-list"></ul>'
            . '</div>' . self::panelStyle() . self::panelScript();
    }

    /**
     * 插入正文的片段模板。占位符在这里定死，JS 只负责替换，
     * 免得在 JS 字符串里再套一层带引号的 HTML——那儿的转义错一个字符就整段脚本报废。
     *
     * @param bool $isImage
     * @param bool $md 站点开了 Markdown 编辑器就用 md 语法，否则用 HTML
     */
    private static function template(bool $isImage, bool $md): string
    {
        if ($md) {
            return $isImage ? '![{n}]({u})' : '[{n}]({u})';
        }

        return $isImage ? '<img src="{u}" alt="{n}">' : '<a href="{u}">{n}</a>';
    }

    private static function panelStyle(): string
    {
        return '<style>'
            . '.nl-cr{margin:0 0 .8rem;padding:.7rem .8rem;border:1px dashed #cfd6df;border-radius:6px;background:#fbfcfe}'
            . '.nl-cr-bar{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}'
            . '.nl-cr-title{font-weight:700;color:#3b4552}'
            . '.nl-cr-pick{cursor:pointer}'
            . '.nl-cr-pick input{display:none}'
            . '.nl-cr-hint{color:#8a94a0;font-size:.86em}'
            . '.nl-cr-list{list-style:none;margin:.6rem 0 0;padding:0}'
            . '.nl-cr-item{display:flex;align-items:center;gap:.5rem;padding:.35rem 0;'
            . 'border-top:1px solid #eef1f5;font-size:.9em}'
            . '.nl-cr-name{max-width:14rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '.nl-cr-bar-track{flex:1;height:4px;border-radius:2px;background:#e6eaf0;overflow:hidden}'
            . '.nl-cr-bar-fill{height:100%;width:0;background:#4482e5;transition:width .2s}'
            . '.nl-cr-item.ok .nl-cr-bar-fill{background:#4caf70}'
            . '.nl-cr-item.bad .nl-cr-bar-fill{background:#e05c5c}'
            . '.nl-cr-item.bad{flex-wrap:wrap}'
            . '.nl-cr-msg{width:100%;color:#a3342f;word-break:break-all}'
            . '.nl-cr-item a.nl-cr-copy{text-decoration:none}'
            . '@media (max-width:757px){.nl-cr-name{max-width:8rem}}'
            . '</style>';
    }

    /**
     * 插入走最朴素的 DOM 写入：#text 在富文本和 Markdown（pagedown）两种编辑器里都是真正存正文的那个
     * textarea，写完派发一个 input 事件让预览和「有未保存修改」的状态跟上来。
     * 不用 Typecho.uploadComplete——它只在 Markdown 分支里定义（editor-js.php:212），
     * 关掉 Markdown 的站点调它就是未定义。
     * 也不用 Typecho.insertFileToEditor——Markdown 分支里它实现成「弹图片对话框、把 URL 填进输入框、
     * 等用户再点一次确定」（editor-js.php:197），直链都已经在手上了还多一步。
     */
    private static function panelScript(): string
    {
        return '<script>(function(){'
            . 'var box=document.getElementById("nl-cr");if(!box){return;}'
            . 'var panel=document.getElementById("upload-panel");'
            . 'if(panel&&panel.firstChild){panel.insertBefore(box,panel.firstChild);}else if(panel){panel.appendChild(box);}'
            . 'var list=box.querySelector(".nl-cr-list");var input=box.querySelector(".nl-cr-pick input");'
            /* 插入语法由服务端算好塞进 data 属性：JS 里再拼 HTML 就得跟「引号套引号」较劲，容易漏一个 */
            . 'var link=function(name,url,isImage){'
            . 'return box.getAttribute(isImage?"data-tpl-img":"data-tpl-file")'
            . '.split("{n}").join(name).split("{u}").join(url);};'
            . 'var put=function(text){var t=document.getElementById("text");if(!t){return;}'
            . 'var s=t.selectionStart===undefined?t.value.length:t.selectionStart;'
            . 'var e=t.selectionEnd===undefined?t.value.length:t.selectionEnd;'
            . 't.value=t.value.slice(0,s)+text+t.value.slice(e);'
            . 't.focus();t.setSelectionRange(s+text.length,s+text.length);'
            /* input + keyup：Markdown 的实时预览挂在编辑框的输入事件上，只派 input 它不一定跟 */
            . '["input","keyup"].forEach(function(type){'
            . 't.dispatchEvent(new Event(type,{bubbles:true}));});};'
            . 'var copy=function(text){'
            . 'if(navigator.clipboard&&navigator.clipboard.writeText){return navigator.clipboard.writeText(text);}'
            . 'var ta=document.createElement("textarea");ta.value=text;document.body.appendChild(ta);ta.select();'
            . 'try{document.execCommand("copy");}catch(e){}document.body.removeChild(ta);'
            . 'return Promise.resolve();};'
            . 'var item=function(name){var li=document.createElement("li");li.className="nl-cr-item";'
            . 'var nm=document.createElement("span");nm.className="nl-cr-name";nm.textContent=name;'
            . 'var track=document.createElement("span");track.className="nl-cr-bar-track";'
            . 'var fill=document.createElement("span");fill.className="nl-cr-bar-fill";'
            . 'track.appendChild(fill);li.appendChild(nm);li.appendChild(track);'
            . 'list.appendChild(li);return li;};'
            . 'var fail=function(li,fill,text){li.className="nl-cr-item bad";fill.style.width="100%";'
            . 'var em=document.createElement("div");em.className="nl-cr-msg";'
            . 'em.textContent=text;li.appendChild(em);};'
            . 'var send=function(file){'
            . 'if(!file){return;}'
            . 'var li=item(file.name);var fill=li.querySelector(".nl-cr-bar-fill");'
            . 'var data=new FormData();'
            . 'data.append("file",file);data.append("_",box.getAttribute("data-token"));'
            . 'var xhr=new XMLHttpRequest();xhr.open("POST",box.getAttribute("data-endpoint"),true);'
            . 'xhr.upload.onprogress=function(ev){if(ev.lengthComputable){fill.style.width=Math.round(ev.loaded*100/ev.total)+"%";}};'
            . 'xhr.onload=function(){'
            . 'var res=null;try{res=JSON.parse(xhr.responseText);}catch(e){}'
            . 'if(!res||res.code!==0){fail(li,fill,(res&&res.message)?res.message:("HTTP "+xhr.status));return;}'
            . 'var d=res.data;fill.style.width="100%";li.className="nl-cr-item ok";'
            . 'put(link(d.name,d.url,d.isImage));'
            . 'var a=document.createElement("a");a.href=d.url;a.target="_blank";a.rel="noopener";'
            . 'a.className="nl-cr-copy";a.textContent="' . Admin::esc(_t('直链')) . '";li.appendChild(a);'
            . 'var c=document.createElement("a");c.href="###";c.className="nl-cr-copy";'
            . 'c.textContent="' . Admin::esc(_t('复制')) . '";'
            . 'c.onclick=function(ev){ev.preventDefault();copy(d.url).then(function(){'
            . 'c.textContent="' . Admin::esc(_t('已复制')) . '";});};'
            . 'li.appendChild(c);};'
            . 'xhr.onerror=function(){fail(li,fill,"' . Admin::esc(_t(
                '请求没发出去：网络断了，或者 PHP 先一步拒了这个体积的文件（看 upload_max_filesize）。')) + '");};'
            . 'xhr.onloadend=function(){if(li.className.indexOf("ok")<0&&li.className.indexOf("bad")<0){'
            . 'fail(li,fill,"' . Admin::esc(_t('上传中断。')) + '");}};'
            . 'xhr.send(data);};'
            . 'if(input){input.addEventListener("change",function(){'
            . 'var fs=Array.prototype.slice.call(input.files||[]);fs.forEach(send);input.value="";});}'
            /* 粘贴截图直传：只对图片生效，传文字不拦 */
            . 'document.addEventListener("paste",function(ev){'
            . 'if(box.getAttribute("data-paste")==="0"){return;}'
            . 'var t=ev.target;if(!t||t.id!=="text"){return;}'
            . 'var items=(ev.clipboardData||{}).items||[];var picked=null;'
            . 'for(var i=0;i<items.length;i++){if(items[i].kind==="file"&&items[i].type.indexOf("image/")===0){'
            . 'picked=items[i].getAsFile();break;}}'
            . 'if(picked){ev.preventDefault();send(picked);}});'
            /* 拖拽只认图床框自己：附件区里那一块是核心自带上传的落点，不去抢 */
            . 'box.addEventListener("dragover",function(ev){ev.preventDefault();});'
            . 'box.addEventListener("drop",function(ev){ev.preventDefault();'
            . 'Array.prototype.slice.call(ev.dataTransfer.files||[]).forEach(send);});'
            . '})();</script>';
    }

    /* ======================================================================
       设置与环境
       ====================================================================== */

    /** 开了、地址齐、邮箱密码齐，才往编辑器里塞东西 */
    private static function ready(): bool
    {
        return self::config('crEnabled', '1') === '1'
            && self::baseUrl() !== ''
            && trim(self::setting('crEmail', '')) !== ''
            && self::setting('crPassword', '') !== '';
    }

    private static function canUpload(): bool
    {
        $user = User::alloc();

        try {
            return (bool)$user->pass('contributor', true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function setting(string $name, string $default = ''): string
    {
        return Plugin::setting($name, $default);
    }

    private static function config(string $name, string $default): string
    {
        return trim((string)self::setting($name, $default));
    }

    /**
     * Cloudreve 根地址：补上 https、只认 scheme://host[/子目录]，
     * 不接受带 userinfo 或查询串的写法，免得把密码和 Bearer 令牌转发到别人指的地方。
     */
    private static function baseUrl(): string
    {
        $host = trim(self::setting('crHost', ''));

        if ($host === '') {
            return '';
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $host)) {
            $host = 'https://' . $host;
        }

        $parts = parse_url($host);

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

        if ($scheme !== 'https' && $scheme !== 'http') {
            return '';
        }

        $path = isset($parts['path']) ? trim($parts['path'], '/') : '';
        $root = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');

        return $path === '' ? $root : $root . '/' . $path;
    }

    private static function api(string $tail): string
    {
        return self::baseUrl() . '/api/v4/' . ltrim($tail, '/');
    }

    /** cloudreve://my/<目录>/<名字>；传 '' 只要目录本身 */
    private static function uri(string $name): string
    {
        $dir = self::targetDir();
        $segments = array();

        foreach (explode('/', $dir) as $segment) {
            $segment = trim($segment);

            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        if ($name !== '') {
            $segments[] = $name;
        }

        return 'cloudreve://my' . ($segments === array() ? '' : '/' . implode('/', $segments));
    }

    private static function targetDir(): string
    {
        $dir = trim(self::setting('crDir', 'noline'));

        return $dir === '' ? 'noline' : trim($dir, '/');
    }

    /**
     * 落盘名自己生成：日期 + 随机串 + 原扩展名。
     * 依赖 Cloudreve 的重名处理语义（会改名还是覆盖）我没在源码里核实，
     * 与其拿它赌，不如让文件名本身就不会撞。
     */
    private static function nameFor(string $original, string $ext): string
    {
        $random = bin2hex(self::randomBytes(4));

        return date('Ymd') . '-' . $random . ($ext === '' ? '' : '.' . $ext);
    }

    private static function randomBytes(int $length): string
    {
        if (function_exists('random_bytes')) {
            try {
                return random_bytes($length);
            } catch (\Throwable $e) {
                // 走到下面兜底
            }
        }

        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= chr(mt_rand(0, 255));
        }

        return $out;
    }

    private static function extensionOf(string $original, string $mime): string
    {
        $name = str_replace(array('\\', '/'), '.', $original);
        $dot = strrpos($name, '.');
        $ext = $dot === false ? '' : strtolower(substr($name, $dot + 1));

        if ($ext !== '' && preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
            return $ext;
        }

        $map = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'text/plain' => 'txt',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
        );

        return isset($map[$mime]) ? $map[$mime] : 'bin';
    }

    /**
     * 只信魔数，不信浏览器报的 type——那完全是客户端可写的。
     */
    private static function mimeOf(string $path, string $original): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return self::mimeFromName($original);
    }

    private static function mimeFromName(string $original): string
    {
        $ext = self::extensionOf($original, '');
        $map = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        );

        return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
    }

    private static function allowList(): string
    {
        $allow = trim(self::setting('crAllow', self::DEFAULT_ALLOW));

        return $allow === '' ? self::DEFAULT_ALLOW : $allow;
    }

    private static function allowed(string $ext): bool
    {
        foreach (explode(',', self::allowList()) as $item) {
            if (strtolower(trim($item)) === strtolower($ext)) {
                return true;
            }
        }

        return false;
    }

    private static function maxSetting(): int
    {
        $value = (int)trim(self::setting('crMaxSize', (string)self::DEFAULT_MAX));

        return $value > 0 ? $value : self::DEFAULT_MAX;
    }

    private static function maxBytes(): int
    {
        $limit = self::maxSetting() * 1048576;

        // PHP 报 0 表示不限，别拿它当上限；两个限制都得让路
        foreach (array('upload_max_filesize', 'post_max_size') as $name) {
            $php = self::iniBytes($name);

            if ($php > 0 && $php < $limit) {
                $limit = $php;
            }
        }

        return $limit;
    }

    private static function iniBytes(string $name): int
    {
        $value = trim((string)ini_get($name));

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int)$value;

        if ($unit === 'g') {
            return $number * 1073741824;
        }

        if ($unit === 'm') {
            return $number * 1048576;
        }

        if ($unit === 'k') {
            return $number * 1024;
        }

        return $number;
    }

    private static function iniSize(string $name): string
    {
        $value = trim((string)ini_get($name));

        return $value === '' ? _t('未设置') : $value;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return _t('%.1f MB', $bytes / 1048576);
        }

        return round($bytes / 1024) . ' KB';
    }

    /**
     * parentUri 只管 cloudreve:// 这一种 uri，去掉最后一段就是目录。
     */
    private static function parentUri(string $uri): string
    {
        $slash = strrpos($uri, '/');

        return $slash === false ? '' : substr($uri, 0, $slash);
    }

    /**
     * @return string|null 读满 want 字节才返回，短了说明文件和声称的大小不符
     */
    private static function readExactly($handle, int $want)
    {
        $body = '';

        while (strlen($body) < $want) {
            $chunk = fread($handle, $want - strlen($body));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $body .= $chunk;
        }

        return $body;
    }

    /* ======================================================================
       HTTP 与响应
       ====================================================================== */

    /**
     * @param string|null $body 字符串按原始字节发出，null 表示没有请求体
     * @param bool $quiet 探测用的请求不该把「预期中的失败」写进管理页的「上次失败」
     * @return array [ok, code, data, message] code 是 Cloudreve 自己的业务码，连不上或非 JSON 时为 -1
     */
    private static function request(string $method, string $url, array $headers, $body,
                                    int $timeout, bool $quiet = false): array
    {
        if ($url === '' || strpos($url, '://') === false) {
            return self::failure(_t('Cloudreve 地址没配好。'), $quiet);
        }

        $headers[] = 'Accept: application/json';

        if (function_exists('curl_init')) {
            $result = self::curl($method, $url, $headers, $body, $timeout);
        } elseif (ini_get('allow_url_fopen')) {
            $result = self::stream($method, $url, $headers, $body, $timeout);
        } else {
            return self::failure(
                _t('PHP 既没有 curl 扩展，也没开 allow_url_fopen，连不上 Cloudreve。'), $quiet);
        }

        if ($result['error'] !== '') {
            return self::failure($result['error'], $quiet);
        }

        $decoded = json_decode((string)$result['text'], true);

        if (!is_array($decoded)) {
            return self::failure(
                _t('接口返回不是 JSON（HTTP %d）。检查地址是不是 Cloudreve v4。', $result['status']), $quiet);
        }

        $code = isset($decoded['code']) ? (int)$decoded['code'] : -1;

        // v4 的错误也走 HTTP 200，真正的状态在 body 的 code 里
        if ($code !== 0 || ($result['status'] >= 400)) {
            return self::failure(self::explain($code, $decoded, $result['status']), $quiet, $code,
                isset($decoded['data']) ? $decoded['data'] : null);
        }

        return array('ok' => true, 'code' => 0,
            'data' => isset($decoded['data']) ? $decoded['data'] : array(), 'message' => '');
    }

    private static function failure(string $message, bool $quiet, int $code = -1, $data = null): array
    {
        if (!$quiet) {
            self::note($message);
        }

        return array('ok' => false, 'code' => $code, 'data' => $data, 'message' => $message);
    }

    private static function curl(string $method, string $url, array $headers, $body, int $timeout): array
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            /* 不跟跳转：Bearer 令牌不能跟着 30x 发给第三个主机；v4 的接口本来也只回 200 JSON */
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ));

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $text = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($text === false) {
            return array('status' => 0, 'text' => '',
                'error' => _t('请求 %1$s 失败：%2$s', $url, $error === '' ? _t('未知错误') : $error));
        }

        return array('status' => $status, 'text' => (string)$text, 'error' => '');
    }

    private static function stream(string $method, string $url, array $headers, $body, int $timeout): array
    {
        $context = stream_context_create(array('http' => array(
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        )));

        $text = @file_get_contents($url, false, $context);

        if ($text === false) {
            return array('status' => 0, 'text' => '',
                'error' => _t('allow_url_fopen 也连不上 %s，检查出站网络和证书。', $url));
        }

        $status = 0;

        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string)$http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        return array('status' => $status, 'text' => $text, 'error' => '');
    }

    /**
     * Cloudreve 的错误码翻成人话，原文（msg）一并带上。
     */
    private static function explain(int $code, array $decoded, int $status): string
    {
        $raw = isset($decoded['msg']) ? trim((string)$decoded['msg']) : '';

        $names = array(
            203 => _t('需要两步验证'),
            401 => _t('未登录：会话过期或者不对'),
            403 => _t('没有权限'),
            404 => _t('找不到目标'),
            40001 => _t('参数不合法'),
            40002 => _t('上传失败'),
            40004 => _t('同名文件已存在'),
            40006 => _t('这个存储策略不允许该操作'),
            40011 => _t('上传会话已过期'),
            40012 => _t('分块序号不对'),
            40013 => _t('内容长度和会话里声明的大小不符'),
            40017 => _t('账号被禁用'),
            40018 => _t('账号还没激活'),
            40020 => _t('凭证无效'),
            40021 => _t('用户不存在'),
            40022 => _t('两步验证码不对'),
            40023 => _t('登录会话不存在'),
            40026 => _t('登录验证码没过'),
            40027 => _t('验证码要刷新'),
            40080 => _t('邮箱或密码不对'),
            40089 => _t('令牌权限范围不够'),
        );

        $text = isset($names[$code]) ? $names[$code] : _t('接口返回错误码 %d（HTTP %d）', $code, $status);

        return $raw === '' ? $text : $text . '：' . $raw;
    }

    /* ======================================================================
       状态文件
       ====================================================================== */

    private static function state(): array
    {
        $defaults = array(
            'account' => '', 'access' => '', 'refresh' => '', 'expires' => 0,
            'checked' => 0, 'error' => '', 'records' => array(),
        );

        $path = self::statePath();

        if (!is_file($path)) {
            return $defaults;
        }

        $text = (string)@file_get_contents($path);
        $body = substr($text, strlen(self::GUARD));
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return $defaults;
        }

        return $decoded + $defaults;
    }

    private static function saveState(array $state): void
    {
        $path = self::statePath();
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents($path, self::GUARD . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX);
        @chmod($path, 0600);
    }

    private static function note(string $message): void
    {
        $state = self::state();

        if ($state['error'] === $message) {
            return;
        }

        $state['error'] = $message;
        self::saveState($state);
    }

    private static function remember(array $result, int $size): void
    {
        $state = self::state();
        $records = isset($state['records']) && is_array($state['records']) ? $state['records'] : array();

        array_unshift($records, array(
            'name' => $result['name'],
            'url' => $result['url'],
            'bytes' => self::humanSize($size),
            'time' => time(),
        ));

        $state['records'] = array_slice($records, 0, self::KEEP_RECORDS);
        $state['error'] = '';
        self::saveState($state);
    }

    private static function statePath(): string
    {
        return __TYPECHO_ROOT_DIR__ . self::STATE_FILE;
    }

    private static function phpError(int $error): string
    {
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return _t('文件太大，被 PHP 先拦下了（upload_max_filesize=%s，post_max_size=%s）。',
                self::iniSize('upload_max_filesize'), self::iniSize('post_max_size'));
        }

        if ($error === UPLOAD_ERR_PARTIAL) {
            return _t('只传上来一半，多半是网络或者超时断了。');
        }

        if ($error === UPLOAD_ERR_NO_FILE) {
            return _t('没有收到文件。');
        }

        if ($error === UPLOAD_ERR_NO_TMP_DIR) {
            return _t('PHP 找不到临时目录（upload_tmp_dir 没配或者不可写）。');
        }

        if ($error === UPLOAD_ERR_CANT_WRITE) {
            return _t('PHP 写临时文件失败，磁盘可能满了。');
        }

        return _t('上传出错（错误码 %d）。', $error);
    }

    private static function bad(string $message): array
    {
        return array('ok' => false, 'url' => '', 'name' => '', 'isImage' => false,
            'uri' => '', 'message' => $message);
    }

    private static function reply(array $payload)
    {
        // 浏览器那边只看 body 里的 code，但 HTTP 状态也得对上：
        // 缓存层、代理、以及 XHR 的 onerror 分支都按状态码判断
        if (isset($payload['code'])) {
            $status = (int)$payload['code'];

            if ($status >= 400 && $status <= 599) {
                http_response_code($status);
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
