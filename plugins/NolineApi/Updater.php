<?php

namespace TypechoPlugin\NolineApi;

use Typecho\Http\Client;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 从 GitHub Release 更新主题：检测 → 下载 noline.zip → 解压验收 → 旧主题整份挪走当备份 → 新版本顶上。
 *
 * 三个硬门槛，缺任何一个都只给手动说明，不做半吊子的自动升级：
 *   连不上 GitHub（没 curl 又没 allow_url_fopen）
 *   解不开包（没 ZipArchive）
 *   换不了目录（usr/themes 与 usr/uploads 不在同一设备，rename 会 EXDEV 失败）
 *
 * 「拉不到」和「没有新版本」必须分开说：一次网络抖动被报成「已是最新」，人会照着做错误决定。
 * 所以成功过的检测结果落盘缓存，重新拉取失败时把上一次的成功结果摊出来、并标清它是旧的。
 *
 * 交换期间 usr/themes/noline 有几十微秒不存在，并发请求可能撞上一次 500；
 * 这是选定的代价，换来的是不用 FTP。中途被 PHP 超时或断电打断由 state.json 里的 pending 标记兜：
 * autoTick() 和进「主题管理」页都会先 repair()，发现主题目录不见了就自动把备份搬回去。
 *
 * 自动升级默认开着：后台每个页面都会过一遍 autoTick()，检测到新版本就自己下载覆盖完，不等人点按钮。
 * 它只认管理员的登录态、每 AUTO_WAIT 秒最多跑一轮（失败也一样，免得每个页面都重试打爆 GitHub 限额），
 * 结论落进 state.json，人下次进这一页看到的是「已经升完了」而不是一个待办的按钮。
 */
class Updater
{
    /** Release 附件必须叫这个名字，build.py 也是这么产出的 */
    private const ASSET = 'noline.zip';

    private const WORK = '/usr/uploads/noline_update';
    private const BACKUP = '/usr/uploads/noline_backup';

    /** 检测结果的缓存时长。GitHub 匿名限流是每 IP 每小时 60 次，别拿刷新去撞它 */
    private const TTL = 21600;

    private const MAX_ZIP = 20971520;           // 20MB，主题包实际只有几百 KB
    private const MAX_UNCOMPRESSED = 62914560;  // 解压后 60MB 封顶，挡压缩炸弹
    private const MAX_ENTRIES = 4000;

    /** 自动模式两轮之间的最短间隔。检测本身有 TTL 缓存，这个闸是给「失败也先歇一小时」用的 */
    private const AUTO_WAIT = 3600;

    private const DEFAULT_REPO = 'qine233/noline';

    /* ======================================================================
       页面区块与动作
       ====================================================================== */

    /**
     * 管理页里的「主题更新」这一段。
     */
    public static function section(string $token, string $url): string
    {
        $check = self::check(false);
        $env = self::env();
        $block = self::blocking();
        $version = self::installedVersion();
        $canUpgrade = $check['ok'] && $check['available'] && $block === '';

        $html = '<h3 class="nl-h3">' . Admin::esc(_t('主题更新')) . '</h3>';

        $auto = Plugin::setting('autoUpgrade', '1') === '1';
        $state = self::readState();
        $last = isset($state['auto']) && is_array($state['auto']) ? $state['auto'] : array();

        $html .= '<div class="nl-chips">';
        foreach ($env as $item) {
            $html .= '<span class="nl-chip nl-chip-' . $item[0] . '">' . Admin::esc($item[1]) . '</span>';
        }
        $html .= '<span class="nl-chip nl-chip-' . ($auto ? 'ok' : 'muted') . '">'
            . Admin::esc($auto ? _t('自动升级开着') : _t('自动升级已关')) . '</span>';
        $html .= '</div>';

        $stateText = !$check['ok']
            ? _t('没拿到')
            : ($check['available'] ? _t('有新版本') : _t('已经是最新'));

        $html .= '<div class="nl-ver">'
            . '<div class="nl-ver-now"><b>' . Admin::esc($version === '' ? _t('未知') : $version) . '</b>'
            . '<em>' . Admin::esc(_t('本机在跑')) . '</em></div>'
            . '<div class="nl-ver-arrow">→</div>'
            . '<div class="nl-ver-latest"><b>' . Admin::esc($check['ok'] ? $check['tag'] : _t('未知')) . '</b>'
            . '<em>' . Admin::esc($stateText) . '</em></div></div>';

        if (isset($last['at']) && !empty($last['html'])) {
            $level = in_array(isset($last['level']) ? $last['level'] : '', array('success', 'error', 'warn'), true)
                ? $last['level'] : 'warn';
            $html .= '<div class="nl-msg nl-msg-' . $level . '">'
                . Admin::esc(_t('%s 自动跑过一轮：', date('m-d H:i', (int)$last['at']))) . $last['html']
                . '</div>';
        }

        if ($check['cached']) {
            $html .= '<p class="nl-note">' . Admin::esc(_t(
                '上面是 %s 的检测结果，缓存 %d 小时。点「重新检测」才会真的再访问一次 GitHub。',
                date('m-d H:i', (int)$check['checked']),
                (int)round(self::TTL / 3600)
            )) . '</p>';
        }

        if (!$check['ok']) {
            $html .= '<div class="nl-msg nl-msg-error">' . Admin::esc($check['error']) . '</div>';
        }

        if ($check['failed'] !== '') {
            $html .= '<div class="nl-msg nl-msg-warn">'
                . Admin::esc(_t('刚才重新拉取失败了：%s。下面显示的仍是上次成功的结果。', $check['failed']))
                . '</div>';
        }

        if ($check['ok'] && !$check['comparable']) {
            $html .= '<div class="nl-msg nl-msg-warn">'
                . Admin::esc(_t('版本号写法对不上（本机 %s / Release %s），只能按「不相等就当有更新」处理，'
                    . '升级前自己确认一下哪个更新。', $version, $check['tag']))
                . '</div>';
        }

        if ($block !== '') {
            $html .= '<div class="nl-msg nl-msg-warn">' . Admin::esc($block) . '</div>';
        }

        if ($check['ok'] && $check['body'] !== '') {
            $html .= '<details class="nl-log" open><summary>'
                . Admin::esc(_t('这个版本改了什么（Release 正文原文，Markdown）'))
                . '</summary><pre>' . Admin::esc(self::trimBody($check['body'])) . '</pre></details>';
        }

        $ask = $canUpgrade
            ? _t('确定升级主题吗？旧版本会先整份备份，站点会有极短的重启窗口。')
            : _t('重新访问 GitHub 检测一次？');

        $html .= '<form class="nl-inline" method="post" action="' . Admin::esc($url) . '"'
            . ' data-ask="' . Admin::esc($ask) . '">'
            . '<input type="hidden" name="_" value="' . Admin::esc($token) . '">';

        if ($canUpgrade) {
            $html .= '<button type="submit" class="btn btn-l primary" name="action" value="upgrade">'
                . Admin::esc(_t('备份并升级到 %s', $check['tag'])) . '</button> ';
        }

        $html .= '<button type="submit" class="btn" name="action" value="refresh">'
            . Admin::esc(_t('重新检测')) . '</button> ';

        if ($check['page'] !== '') {
            $html .= '<a class="btn btn-s" href="' . Admin::esc($check['page']) . '" target="_blank" rel="noopener">'
                . Admin::esc(_t('Release 页')) . '</a>';
        }

        if ($check['asset'] !== '') {
            $html .= ' <a class="btn btn-s" href="' . Admin::esc($check['asset']) . '" rel="noopener">'
                . Admin::esc(_t('只下 zip')) . '</a>'
                . '<span class="nl-note">' . Admin::esc(_t('手动更新：解压后覆盖 usr/themes/noline，记得留 views/ 里本机独有的文件。'))
                . '</span>';
        }

        $html .= '</form>';

        $html .= self::backupsHtml($token, $url);
        $html .= self::leftoversHtml($token, $url);

        return $html;
    }

    /**
     * 更新相关的动作。文案一律已经 HTML 转义，页面直接输出。
     *
     * @return array [级别, HTML 文案]
     */
    public static function act(string $action): array
    {
        switch ($action) {
            case 'refresh':
                $check = self::check(true);

                /* 强制拉取失败时 check() 会退回上一次的成功结果，那里的 ok 仍是 true。
                   不先岔开这一条，就会拿旧版本号报一句「检测成功」，看着像按钮压根没生效 */
                if ($check['failed'] !== '') {
                    return array('error', Admin::esc(_t(
                        '这一轮没拉到新的：%s。下面显示的仍是 %s 那次成功检测的结果。',
                        $check['failed'],
                        date('m-d H:i', (int)$check['checked'])
                    )));
                }

                if (!$check['ok']) {
                    return array('error', Admin::esc(_t('检测失败：') . $check['error']));
                }

                return array('success', Admin::esc($check['available']
                    ? _t('发现新版本 %s（本机 %s）。', $check['tag'], self::installedVersion())
                    : _t('已经是最新版本 %s。', $check['tag'])));

            case 'upgrade':
                return self::upgrade();

            case 'rollback':
                return self::rollback(isset($_POST['to']) ? (string)$_POST['to'] : '');

            case 'drop_backup':
                return self::dropBackup(isset($_POST['to']) ? (string)$_POST['to'] : '');

            case 'clean_work':
                return self::cleanWork();

            default:
                return array('error', Admin::esc(_t('不认识的更新操作。')));
        }
    }

    /* ======================================================================
       自动升级
       ====================================================================== */

    /**
     * 后台每一个页面都会过一下（Plugin::onAdminMenu → admin/menu.php:navBar）。
     * 发现新版本就当场升完，不再攒着等人点按钮。
     */
    public static function autoTick()
    {
        if (Plugin::setting('autoUpgrade', '1') !== '1') {
            return;
        }

        /* 顶栏对所有能进后台的人都渲染，编辑和 subscriber 也算；
           搬主题目录这种事只跟着站长走 */
        if (!User::alloc()->pass('administrator', true)) {
            return;
        }

        $state = self::readState();
        $last = isset($state['auto']) && is_array($state['auto']) ? $state['auto'] : array();

        if (isset($last['at']) && (int)$last['at'] + self::AUTO_WAIT > time()) {
            return;
        }

        /* 先把上次没走完的交换圆回来。主题目录已经不在原地时还去下载新包，
           只会把「前台坏了」变成「前台坏了而且没人知道」 */
        $fixed = self::repair();

        if ($fixed !== '') {
            self::note('warn', Admin::esc($fixed));

            return;
        }

        $check = self::check(false);

        if (empty($check['ok'])) {
            /* 一次都没成功过、这回又连不上：打时间戳，别拿每个后台页面去撞限流 */
            self::note('warn', Admin::esc($check['error']));

            return;
        }

        if (empty($check['available'])) {
            /* 已经是最新。结论在 TTL 缓存里，缓存到期前不会再碰网络，不用额外打标记 */
            return;
        }

        /* 刚被人手动回滚掉的正是这一版，别当场又顶上去。等下一个比它新的 tag */
        $fresh = self::readState();
        $floor = self::normalize(isset($fresh['auto_floor']) ? (string)$fresh['auto_floor'] : '');

        if ($floor !== '' && self::normalize($check['tag']) === $floor) {
            return;
        }

        $block = self::blocking();

        if ($block !== '') {
            self::note('warn', Admin::esc($block));

            return;
        }

        $result = self::upgrade(true);
        self::note($result[0], $result[1]);
    }

    /**
     * 自动这一轮的结论落盘，好让人下次进页面看见的是「已经升完了」而非一排待办按钮。
     * 存进去的 HTML 已经转义过，页面上直接输出、不再二次转义。
     */
    private static function note(string $level, string $html)
    {
        $state = self::readState();
        $state['auto'] = array('at' => time(), 'level' => $level, 'html' => $html);
        self::saveState($state);
    }

    /* ======================================================================
       检测
       ====================================================================== */

    /**
     * 远端最新情况。默认优先吃缓存。
     */
    private static function check(bool $force = false): array
    {
        $state = self::readState();
        $repo = self::repo();
        $cached = isset($state['check']) && is_array($state['check']) ? $state['check'] : null;

        /* 换了仓库就是换了上游，旧结果一点参考价值都没有 */
        if ($cached !== null && (isset($cached['repo']) ? (string)$cached['repo'] : '') !== $repo) {
            $cached = null;
            $force = true;
        }

        if (!$force && $cached !== null
            && (isset($cached['checked']) ? (int)$cached['checked'] : 0) + self::TTL > time()) {
            $cached['cached'] = true;

            return self::withRelation($cached);
        }

        $fresh = self::fetch($repo);
        $fresh['checked'] = time();
        $fresh['repo'] = $repo;
        $fresh['cached'] = false;
        $fresh['failed'] = '';

        if ($fresh['ok']) {
            $state['check'] = $fresh;
            unset($state['last_error']);
            self::saveState($state);

            return self::withRelation($fresh);
        }

        if ($cached !== null && !empty($cached['ok'])) {
            $cached['cached'] = true;
            $cached['failed'] = $fresh['error'];

            return self::withRelation($cached);
        }

        $state['last_error'] = $fresh['error'];
        self::saveState($state);

        return self::withRelation($fresh);
    }

    /**
     * 远端事实可以缓存，本机版本是当下的——回滚或手动换过主题之后，
     * 「有没有新版本」必须重算，不能沿用缓存里那个结论。
     */
    private static function withRelation(array $check): array
    {
        $check = array_merge(array(
            'ok' => false, 'error' => '', 'tag' => '', 'body' => '', 'page' => '',
            'asset' => '', 'size' => 0, 'sha' => '', 'checked' => 0, 'cached' => false,
            'failed' => '', 'repo' => '',
        ), $check);

        $mine = self::normalize(self::installedVersion());
        $theirs = self::normalize($check['tag']);

        $check['available'] = false;
        $check['comparable'] = true;

        if ($check['ok'] && $theirs !== '') {
            if (self::looksNumeric($mine) && self::looksNumeric($theirs)) {
                $check['available'] = version_compare($theirs, $mine) > 0;
            } else {
                $check['comparable'] = false;
                $check['available'] = $theirs !== $mine;
            }
        }

        return $check;
    }

    /**
     * 打一次 GitHub API。错误文案要能让人自己判断下一步：限流、没发过 release、还是根本连不上。
     */
    private static function fetch(string $repo): array
    {
        $out = array(
            'ok' => false, 'error' => '', 'tag' => '', 'body' => '', 'page' => '',
            'asset' => '', 'size' => 0, 'sha' => '',
        );

        if (!preg_match('#^[A-Za-z0-9._-]{1,60}/[A-Za-z0-9._-]{1,100}$#', $repo)) {
            $out['error'] = _t('仓库要写成 owner/repo，现在是「%s」。', $repo);

            return $out;
        }

        $http = self::http('https://api.github.com/repos/' . $repo . '/releases/latest', 15, true);

        if ($http['error'] !== '') {
            $out['error'] = $http['error'];

            return $out;
        }

        if ($http['status'] === 404) {
            $out['error'] = _t('%s 没有可用的 Release。latest 只认「已发布、非草稿」里最新的那一个，'
                . 'draft 状态的 release 看不见。', $repo);

            return $out;
        }

        if ($http['status'] === 401) {
            $out['error'] = _t('GitHub 不认设置里那个 token（HTTP 401），它多半已被撤销或过期。'
                . '把 token 清空保存一次就退回匿名访问。');

            return $out;
        }

        if ($http['status'] === 403 || $http['status'] === 429) {
            $out['error'] = trim(Plugin::setting('ghToken', '')) === ''
                ? _t('被 GitHub 限流了（匿名访问每个 IP 每小时 60 次）。'
                    . '在插件设置里填一个只读 token，或者一小时后再来。')
                : _t('GitHub 用 HTTP %d 挡了这个 token：权限不够、已被撤销，或者额度用完了。'
                    . '先把 token 清空退回匿名访问试一次。', $http['status']);

            return $out;
        }

        if ($http['status'] !== 200) {
            $out['error'] = _t('GitHub 返回了 HTTP %d。', $http['status']);

            return $out;
        }

        $data = json_decode($http['body'], true);

        if (!is_array($data) || empty($data['tag_name'])) {
            $out['error'] = _t('返回的内容不像预期的 release JSON，可能被代理或 CDN 改写了。');

            return $out;
        }

        $out['tag'] = trim((string)$data['tag_name']);
        $out['body'] = isset($data['body']) && is_string($data['body']) ? $data['body'] : '';
        $out['page'] = isset($data['html_url']) ? (string)$data['html_url'] : '';

        $assets = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array();

        foreach ($assets as $asset) {
            if (!is_array($asset) || !isset($asset['name'])) {
                continue;
            }

            if (strtolower(trim((string)$asset['name'])) !== self::ASSET) {
                continue;
            }

            $out['asset'] = isset($asset['browser_download_url'])
                ? (string)$asset['browser_download_url'] : '';
            $out['size'] = isset($asset['size']) ? (int)$asset['size'] : 0;
            /* GitHub 给了摘要就校验，没给就跳过——不能因为它没提供 digest 就拒绝升级 */
            $out['sha'] = isset($asset['digest']) && is_string($asset['digest'])
                ? strtolower(trim($asset['digest'])) : '';

            break;
        }

        if ($out['asset'] === '') {
            $out['error'] = _t('这个 Release 里没有叫 %s 的附件。发布时把 build.py 产出的 dist/noline.zip '
                . '按原名上传成附件即可。', self::ASSET);

            return $out;
        }

        if ($out['size'] > self::MAX_ZIP) {
            $out['error'] = _t('附件超过 %d MB，不像一个主题包，拒绝下载。',
                (int)round(self::MAX_ZIP / 1048576));

            return $out;
        }

        $out['ok'] = true;

        return $out;
    }

    /**
     * 一次 GET。curl 优先，没 curl 退到 allow_url_fopen，两条都不通才算连不上。
     *
     * @return array ['status' => int, 'body' => string, 'error' => string]
     */
    private static function http(string $url, int $timeout, bool $json): array
    {
        if (extension_loaded('curl')) {
            $client = Client::get();

            if ($client === null) {
                return array('status' => 0, 'body' => '', 'error' => _t('curl 扩展状态异常。'));
            }

            $client->setTimeout($timeout);
            $client->setAgent('NolineApi/updater');
            $client->setHeader('Accept', $json ? 'application/vnd.github+json' : 'application/zip');

            $token = trim(Plugin::setting('ghToken', ''));

            if ($token !== '') {
                $client->setHeader('Authorization', 'Bearer ' . $token);
            }

            /* 核心的客户端不带 FOLLOWLOCATION，而 Release 附件直链会 302 到
               objects.githubusercontent.com：少这一条，下载永远只拿到一个空 body */
            $client->setOption(CURLOPT_FOLLOWLOCATION, true);
            $client->setOption(CURLOPT_MAXREDIRS, 5);
            $client->setOption(CURLOPT_CONNECTTIMEOUT, min(10, $timeout));

            try {
                $client->send($url);

                return array(
                    'status' => $client->getResponseStatus(),
                    'body' => $client->getResponseBody(),
                    'error' => '',
                );
            } catch (\Throwable $e) {
                /* 证书、DNS、TLS 版本都从这里冒出来，原文递给用户比自己猜有用 */
                return array('status' => 0, 'body' => '', 'error' => _t('请求 %s 失败：%s', $url, $e->getMessage()));
            }
        }

        if (!ini_get('allow_url_fopen')) {
            return array('status' => 0, 'body' => '', 'error' => _t(
                'PHP 既没有 curl 扩展，也没开 allow_url_fopen，这一页连不上 GitHub。'
                . '请手动下载 zip 覆盖到 usr/themes/noline。'));
        }

        $headers = array(
            'Accept: ' . ($json ? 'application/vnd.github+json' : 'application/zip'),
            'User-Agent: NolineApi/updater',
        );
        $token = trim(Plugin::setting('ghToken', ''));

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create(array('http' => array(
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
        )));

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return array('status' => 0, 'body' => '', 'error' => _t('allow_url_fopen 也连不上 %s，检查出站网络和证书。', $url));
        }

        $status = 0;

        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string)$http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        return array('status' => $status, 'body' => $body, 'error' => '');
    }

    /* ======================================================================
       升级
       ====================================================================== */

    /**
     * 下载、验收、把旧主题整份挪去备份再让新版本顶上。
     *
     * @param bool $auto 只影响历史里那一条怎么标，动作本身两种触发完全一样
     */
    private static function upgrade(bool $auto = false): array
    {
        $state = self::readState();

        if (!isset($state['check']) || !is_array($state['check'])) {
            return self::fail(_t('还没有一次成功的检测，先点「重新检测」。'));
        }

        $check = self::withRelation($state['check']);

        if (empty($check['ok'])) {
            return self::fail(_t('缓存里的检测结果不完整，重新检测一次。'));
        }

        if (empty($check['available'])) {
            return self::fail(_t('本机版本不低于 Release 里的 %s，不需要升级。', $check['tag']));
        }

        $block = self::blocking();

        if ($block !== '') {
            return self::fail($block);
        }

        $work = self::workDir();
        $backupRoot = self::backupDir();

        if ($work === null || $backupRoot === null) {
            return self::fail(_t('临时目录或备份目录建不出来，检查 usr/uploads 是否可写。'));
        }

        $stamp = date('Ymd-His');
        $themeDir = self::themeDir();
        $from = self::installedVersion();
        $backup = $backupRoot . '/' . self::safe($from === '' ? 'unknown' : $from) . '-' . $stamp;
        $staging = $work . '/package-' . $stamp;
        $zip = $work . '/' . self::safe($check['tag']) . '-' . $stamp . '.zip';

        set_time_limit(0);
        ignore_user_abort(true);

        $error = self::download($check['asset'], $zip, (int)$check['size'], (string)$check['sha']);

        if ($error !== '') {
            return self::fail($error);
        }

        $root = '';
        $error = self::extract($zip, $staging, $root);

        if ($error !== '') {
            self::rrmdir($staging);
            @unlink($zip);

            return self::fail($error);
        }

        $newVersion = self::versionFromIndex($root . '/index.php');

        if ($newVersion === '') {
            self::rrmdir($staging);
            @unlink($zip);

            return self::fail(_t('包里的 index.php 读不到 @version，这个包不能用来升级，已放弃。'));
        }

        if (self::normalize($newVersion) === self::normalize($from)) {
            self::rrmdir($staging);
            @unlink($zip);

            return self::fail(_t('包里的版本（%s）和本机一模一样。是 Release 重发过没换 tag 吗？已放弃。', $newVersion));
        }

        $pending = array(
            'theme'   => $themeDir,
            'backup'  => $backup,
            'staging' => $root,
            'from'    => $from,
            'to'      => $newVersion,
            'stage'   => 'prepare',
            'at'      => date('c'),
        );

        $state['pending'] = $pending;
        self::saveState($state);

        /* 旧主题整份挪走：一次 rename，不复制不删除，所以 views/ 下那些还没迁进
           usr/uploads 的历史计数文件一定还在原地，回滚时也完整。 */
        if (!@rename($themeDir, $backup)) {
            $state = self::readState();
            unset($state['pending']);
            self::saveState($state);

            return self::fail(_t('旧主题挪不动：%s（站点没受影响，主题还是原来那份。）', self::phpError()));
        }

        $pending['stage'] = 'moved';
        $state = self::readState();
        $state['pending'] = $pending;
        self::saveState($state);

        if (!@rename($root, $themeDir)) {
            $restored = @rename($backup, $themeDir);
            $state = self::readState();
            unset($state['pending']);
            self::saveState($state);

            return self::fail($restored
                ? _t('新版本没能落到主题目录：%s。旧主题已原样放回，站点照常。', self::phpError())
                : _t('新版本没能落到主题目录，旧主题也放不回去：%s。'
                    . '请手动把 %s 挪回 usr/themes/noline。', self::phpError(), $backup));
        }

        $pending['stage'] = 'placed';
        $state = self::readState();
        $state['pending'] = $pending;
        self::saveState($state);

        /* 权威计数在 usr/uploads/noline_views/ 里，交换碰不到；
           这一步只补主题目录 views/ 下「本机有、新版本没有」的旧版兜底文件。 */
        $kept = self::mergeViews($backup . '/views', $themeDir . '/views');

        self::rrmdir($staging);
        @unlink($zip);

        $state = self::readState();
        unset($state['pending']);
        $history = isset($state['upgrades']) && is_array($state['upgrades']) ? $state['upgrades'] : array();
        $history[] = array(
            'from' => $from, 'to' => $newVersion, 'at' => date('c'),
            'backup' => $backup, 'kept_views' => $kept,
        );
        $state['upgrades'] = array_slice($history, -20);
        self::saveState($state);

        $tagVersion = self::normalize($check['tag']);
        $mismatch = $tagVersion !== '' && self::normalize($newVersion) !== $tagVersion;

        return array('success', Admin::esc(_t(
            '已升级到 %1$s（原 %2$s）。旧主题完整留在 %3$s，随时能回滚。',
            $newVersion, $from === '' ? _t('未知') : $from, $backup
        )) . ($kept > 0 ? '<br>' . Admin::esc(_t('另外把本机独有的 %d 个 views/ 文件补进了新版本。', $kept)) : '')
            . ($mismatch ? '<br>' . Admin::esc(_t(
                '提醒：Release 的 tag 是 %s，包里的 @version 是 %s，两者对不上，回头确认一下发布。',
                $check['tag'], $newVersion)) : ''));
    }

    /**
     * 回滚：把备份搬回主题目录，被顶掉的现行版本顺势存成一份新备份。
     */
    private static function rollback(string $name): array
    {
        $src = self::backupPath($name);

        if ($src === '') {
            return self::fail(_t('找不到这个备份，或者名字不合法。'));
        }

        $block = self::blocking();

        if ($block !== '') {
            return self::fail($block);
        }

        $backupRoot = self::backupDir();

        if ($backupRoot === null) {
            return self::fail(_t('备份目录不可写。'));
        }

        set_time_limit(0);
        ignore_user_abort(true);

        $themeDir = self::themeDir();
        $current = self::installedVersion();
        $aside = $backupRoot . '/' . self::safe($current === '' ? 'unknown' : $current) . '-' . date('Ymd-His');

        if (is_dir($themeDir) && !@rename($themeDir, $aside)) {
            return self::fail(_t('现在的主题挪不动：%s', self::phpError()));
        }

        if (!@rename($src, $themeDir)) {
            if (is_dir($aside)) {
                @rename($aside, $themeDir);
            }

            return self::fail(_t('备份放不回主题目录：%s', self::phpError()));
        }

        /* 手动回滚掉的那一版不能被自动更新当场顶回去：记下这个 tag，以后只有比它新的才自动上 */
        $state = self::readState();
        $seen = isset($state['check']) && is_array($state['check']) ? $state['check'] : array();
        $state['auto_floor'] = self::normalize(isset($seen['tag']) ? (string)$seen['tag'] : '');
        self::saveState($state);

        return array('success', Admin::esc(_t(
            '已回到 %1$s。被顶掉的那一版存成了新备份 %2$s。', $name, basename($aside)
        )) . '<br>' . Admin::esc(_t('自动升级不会再把它顶上来了，除非 Release 里出了更新的版本。')));
    }

    /* ======================================================================
       下载 / 解压 / 校验
       ====================================================================== */

    private static function download(string $url, string $dest, int $expectedSize, string $expectedSha): string
    {
        $http = self::http($url, 120, false);

        if ($http['error'] !== '') {
            return $http['error'];
        }

        if ($http['status'] !== 200) {
            return _t('下载附件返回 HTTP %d。', $http['status']);
        }

        if (@file_put_contents($dest, $http['body']) === false) {
            return _t('写不进 %s。', $dest);
        }

        $actual = (int)@filesize($dest);

        if ($expectedSize > 0 && $actual !== $expectedSize) {
            @unlink($dest);

            return _t('下载不完整：GitHub 说 %1$d 字节，只拿到 %2$d 字节。', $expectedSize, $actual);
        }

        if ($expectedSha !== '') {
            $position = strpos($expectedSha, ':');
            $algorithm = $position === false ? '' : substr($expectedSha, 0, $position);
            $wanted = $position === false ? $expectedSha : substr($expectedSha, $position + 1);

            if (!in_array($algorithm, array('sha256', 'sha1', 'md5'), true)) {
                @unlink($dest);

                return _t('GitHub 给的摘要格式不认识（%s），为稳妥起见没有继续。', $expectedSha);
            }

            $got = @hash_file($algorithm, $dest);

            if ($got === false || !hash_equals(strtolower($wanted), $got)) {
                @unlink($dest);

                return _t('%s 摘要对不上：期望 %s，实际 %s。包在路上被改过，已删除。', $algorithm, $wanted, (string)$got);
            }
        }

        return '';
    }

    /**
     * 解压并验收。$root 带出包真正的根目录——build.py 打的是 noline/…，顶层平铺的也认。
     *
     * @return string 空串代表通过
     */
    private static function extract(string $zip, string $into, &$root): string
    {
        if (!class_exists('ZipArchive')) {
            return _t('PHP 没有 zip 扩展，解不开包。');
        }

        $archive = new \ZipArchive();

        if ($archive->open($zip) !== true) {
            return _t('压缩包打不开，可能不是 zip 或者下载已经损坏。');
        }

        if ($archive->numFiles > self::MAX_ENTRIES) {
            $archive->close();

            return _t('包里条目超过 %d 个，不像一个主题包。', self::MAX_ENTRIES);
        }

        $uncompressed = 0;

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entry = $archive->statIndex($i);

            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }

            $name = (string)$entry['name'];

            /* 绝对路径、反斜杠、任何一层的 .. 都不许有：解压目标必须完全在临时目录里 */
            if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false
                || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                $archive->close();

                return _t('包里有非法路径「%s」，拒绝解压。', $name);
            }

            if (substr($name, -1) !== '/') {
                $uncompressed += isset($entry['size']) ? (int)$entry['size'] : 0;
            }
        }

        if ($uncompressed > self::MAX_UNCOMPRESSED) {
            $archive->close();

            return _t('包解压后要占 %d MB 以上，像压缩炸弹，拒绝解压。',
                (int)round(self::MAX_UNCOMPRESSED / 1048576));
        }

        if (!@mkdir($into, 0755, true) && !is_dir($into)) {
            $archive->close();

            return _t('临时目录建不出来：%s', $into);
        }

        $done = $archive->extractTo($into);
        $archive->close();

        if (!$done) {
            return _t('解压过程出错，磁盘可能满了。');
        }

        foreach (array($into . '/' . Theme::DIR, $into) as $candidate) {
            if (!self::looksLikeTheme($candidate)) {
                continue;
            }

            /* 解出来的东西里有软链就放弃：软链能把文件写到主题目录外面去 */
            foreach (self::walk($candidate) as $path) {
                if (is_link($path)) {
                    return _t('包里有软链接（%s），不做交换。', $path);
                }
            }

            $root = $candidate;

            return '';
        }

        return _t('包里找不到主题的 index.php / functions.php / public/header.php。'
            . '顶层应该是一层 noline/ 目录，重新跑一次 _dev/build.py 再发布。');
    }

    private static function looksLikeTheme(string $dir): bool
    {
        return is_dir($dir) && is_file($dir . '/index.php')
            && is_file($dir . '/functions.php') && is_file($dir . '/public/header.php');
    }

    /**
     * 只补「备份里有、新版本没有」的 views 文件。同名文件以新版本为准。
     */
    private static function mergeViews(string $from, string $to): int
    {
        if (!is_dir($from) || !is_dir($to)) {
            return 0;
        }

        $kept = 0;

        foreach (self::walk($from) as $path) {
            if (is_link($path)) {
                continue;
            }

            $relative = substr($path, strlen($from) + 1);

            if ($relative === '' || file_exists($to . '/' . $relative)) {
                continue;
            }

            @mkdir($to . '/' . dirname($relative), 0755, true);

            if (@copy($path, $to . '/' . $relative)) {
                $kept++;
            }
        }

        return $kept;
    }

    /* ======================================================================
       中途被打断的收尾
       ====================================================================== */

    /**
     * 上次升级停在半路就把主题目录恢复回来。进这一页第一件事就是它。
     */
    public static function repair(): string
    {
        $state = self::readState();

        if (empty($state['pending']) || !is_array($state['pending'])) {
            return '';
        }

        $pending = $state['pending'];
        $theme = isset($pending['theme']) ? (string)$pending['theme'] : '';
        $backup = isset($pending['backup']) ? (string)$pending['backup'] : '';
        $root = self::rootDir();

        /* 只认我们自己名下的路径，state.json 被手动改过也动不到别处 */
        if ($theme === '' || strpos($theme, $root . '/usr/themes/') !== 0) {
            unset($state['pending']);
            self::saveState($state);

            return '';
        }

        if (is_dir($theme)) {
            unset($state['pending']);
            self::saveState($state);

            return _t('上次升级在收尾阶段被打断，主题目录已经是新版本上的了，标记清掉了。'
                . '再确认一次前台是否正常。');
        }

        if ($backup !== '' && is_dir($backup) && @rename($backup, $theme)) {
            unset($state['pending']);
            $state['repairs'][] = array('at' => date('c'), 'restored' => $backup);
            self::saveState($state);

            return _t('检测到上次升级中途停下、主题目录不见了。已把 %s 搬回原位，站点恢复。', $backup);
        }

        return _t('主题目录不见了，备份也找不回来（%s）。只能手动重新上传主题。', $backup);
    }

    /**
     * repair() 的文案要上页面，单独包一层转义。
     */
    public static function repairMessage(): string
    {
        $message = self::repair();

        return $message === '' ? '' : Admin::esc($message);
    }

    private static function cleanWork(): array
    {
        $dir = self::workDir();

        if ($dir === null) {
            return self::fail(_t('临时目录不可用。'));
        }

        $removed = 0;

        foreach ((array)glob($dir . '/package-*') as $path) {
            if (is_dir($path)) {
                $removed += self::rrmdir($path) ? 1 : 0;
            }
        }

        foreach ((array)glob($dir . '/*.zip') as $path) {
            if (is_file($path) && !is_link($path) && @unlink($path)) {
                $removed++;
            }
        }

        return array('success', Admin::esc(_t('清掉了 %d 项残留。', $removed)));
    }

    /* ======================================================================
       环境闸门
       ====================================================================== */

    /**
     * @return array 每项 [级别 ok|warn|bad|muted, 文案]
     */
    private static function env(): array
    {
        $chips = array();
        $version = self::installedVersion();
        $active = trim((string)Options::alloc()->theme);
        $themeDir = self::themeDir();

        $chips[] = array('muted', _t('本机 %s', $version === '' ? _t('版本未知') : $version));
        $chips[] = array('muted', _t('仓库 %s', self::repo()));

        $net = extension_loaded('curl') ? 'curl' : (ini_get('allow_url_fopen') ? 'allow_url_fopen' : '');
        $chips[] = array($net === '' ? 'bad' : 'ok', $net === ''
            ? _t('没有 curl 也没开 allow_url_fopen')
            : _t('联网检测：%s', $net));

        $chips[] = array(class_exists('ZipArchive') ? 'ok' : 'bad',
            class_exists('ZipArchive') ? _t('zip 扩展可用') : _t('缺 zip 扩展，装不了'));

        $chips[] = array($active === Theme::DIR ? 'ok' : 'warn',
            $active === Theme::DIR ? _t('主题 noline 正在启用') : _t('当前启用的主题是 %s', $active));

        $chips[] = array(is_dir($themeDir) && !is_link($themeDir) ? 'ok' : 'bad',
            is_dir($themeDir)
                ? (is_link($themeDir) ? _t('主题目录是软链接，不敢动') : _t('主题目录正常'))
                : _t('主题目录不存在'));

        $parent = dirname($themeDir);
        $chips[] = array(is_dir($parent) && is_writable($parent) ? 'ok' : 'bad',
            is_dir($parent) && is_writable($parent) ? _t('usr/themes 可写') : _t('usr/themes 不可写，换不了目录'));

        $work = self::workDir();
        $chips[] = array($work === null ? 'bad' : 'ok',
            $work === null ? _t('usr/uploads 建不出临时目录') : _t('usr/uploads 可写'));

        $chips[] = array(self::sameDevice() ? 'ok' : 'bad', self::sameDevice()
            ? _t('themes 与 uploads 在同一设备，rename 可用')
            : _t('themes 与 uploads 不在同一设备，rename 会失败，只能手动更新'));

        $doc = self::versionFromIndex($themeDir . '/index.php');
        $code = self::versionFromFunctions($themeDir . '/functions.php');

        if ($doc !== '' && $code !== '' && $doc !== $code) {
            $chips[] = array('warn', _t('index.php 写 %s，functions.php 写 %s，两个版本号对不上', $doc, $code));
        }

        return $chips;
    }

    /**
     * 有 bad 就不许一键升级，并把第一条原因端出去。
     */
    private static function blocking(): string
    {
        foreach (self::env() as $item) {
            if ($item[0] === 'bad') {
                return _t('一键升级做不成：%s', $item[1]);
            }
        }

        return '';
    }

    private static function sameDevice(): bool
    {
        $theme = @stat(dirname(self::themeDir()));
        $uploads = @stat(self::baseUploads());

        return is_array($theme) && is_array($uploads) && $theme['dev'] === $uploads['dev'];
    }

    private static function repo(): string
    {
        $repo = trim(Plugin::setting('nolineRepo', self::DEFAULT_REPO));

        return $repo === '' ? self::DEFAULT_REPO : $repo;
    }

    /* ======================================================================
       路径与状态
       ====================================================================== */

    private static function rootDir(): string
    {
        return rtrim((string)__TYPECHO_ROOT_DIR__, '/');
    }

    private static function themeDir(): string
    {
        return rtrim(Options::alloc()->themeFile(Theme::DIR), '/');
    }

    private static function baseUploads(): string
    {
        return self::rootDir() . '/usr/uploads';
    }

    /**
     * 工作目录。建不出来或不可写返回 null——此时只读，不下载也不交换。
     */
    private static function workDir()
    {
        return self::ensureDir(self::rootDir() . self::WORK);
    }

    /**
     * 备份根目录。同上，拿不到就是 null。
     */
    private static function backupDir()
    {
        return self::ensureDir(self::rootDir() . self::BACKUP);
    }

    private static function ensureDir(string $path)
    {
        static $done = array();

        if (array_key_exists($path, $done)) {
            return $done[$path];
        }

        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        if (!is_dir($path) || !is_writable($path)) {
            $done[$path] = null;

            return null;
        }

        /* 里面会有整份主题副本和下载下来的 zip，能少一个入口就少一个 */
        if (!is_file($path . '/.htaccess')) {
            @file_put_contents($path . '/.htaccess', "Require all denied\n");
        }

        $done[$path] = $path;

        return $path;
    }

    private static function stateFile(): string
    {
        $dir = self::workDir();

        return $dir === null ? '' : $dir . '/state.json';
    }

    private static function readState(): array
    {
        $file = self::stateFile();

        if ($file === '' || !is_file($file)) {
            return array();
        }

        $data = json_decode((string)@file_get_contents($file), true);

        return is_array($data) ? $data : array();
    }

    private static function saveState(array $state): bool
    {
        $file = self::stateFile();

        if ($file === '') {
            return false;
        }

        return @file_put_contents(
            $file,
            json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        ) !== false;
    }

    /* ======================================================================
       版本
       ====================================================================== */

    /**
     * 本机主题版本。读 index.php 头部注释里的 @version——Typecho 的主题列表读的就是它，
     * 所以这才是「站点当前跑的版本」。
     */
    private static function installedVersion(): string
    {
        return self::versionFromIndex(self::themeDir() . '/index.php');
    }

    private static function versionFromIndex(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }

        try {
            /* 核心的解析器就是主题列表用的那一个，口径保持一致。
               这里必须写全 \\Typecho\\Plugin：本命名空间下也有一个同名的 Plugin 类。 */
            $info = \Typecho\Plugin::parseInfo($file);

            if (isset($info['version']) && trim((string)$info['version']) !== '') {
                return trim((string)$info['version']);
            }
        } catch (\Throwable $e) {
            // 下面退回正则
        }

        $head = (string)@file_get_contents($file, false, null, 0, 800);

        return preg_match('/@version\s+([^\s*]+)/', $head, $m) ? trim($m[1]) : '';
    }

    private static function versionFromFunctions(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }

        $head = (string)@file_get_contents($file, false, null, 0, 800);

        return preg_match("/NOLINE_VERSION',\s*'([^']+)'/", $head, $m) ? trim($m[1]) : '';
    }

    private static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    private static function looksNumeric(string $version): bool
    {
        return (bool)preg_match('/^\d+(\.\d+)*([-._+][0-9A-Za-z.-]+)?$/', $version);
    }

    /* ======================================================================
       备份列表
       ====================================================================== */

    /**
     * @return array 备份目录名，新的在前
     */
    private static function backupNames(): array
    {
        $root = self::backupDir();

        if ($root === null) {
            return array();
        }

        $names = array();

        foreach ((array)@scandir($root, SCANDIR_SORT_DESCENDING) as $name) {
            if (!is_string($name) || $name[0] === '.') {
                continue;
            }

            if (is_dir($root . '/' . $name) && !is_link($root . '/' . $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * 把用户传来的名字钉死在备份目录里面。空串表示这个名字不合法。
     */
    private static function backupPath(string $name): string
    {
        $name = trim($name);
        $root = self::backupDir();

        if ($root === null || $name === '' || $name !== basename($name)) {
            return '';
        }

        if (!in_array($name, self::backupNames(), true)) {
            return '';
        }

        return $root . '/' . $name;
    }

    private static function backupsHtml(string $token, string $url): string
    {
        $names = self::backupNames();
        $root = self::backupDir();

        if ($root === null) {
            return '';
        }

        if (empty($names)) {
            return '<p class="nl-note">' . Admin::esc(_t(
                '还没有备份。第一次升级之后，旧主题会整份留在 %s 下，随时回滚。', self::BACKUP)) . '</p>';
        }

        $html = '<h4 class="nl-h4">' . Admin::esc(_t('备份（%d 份）', count($names))) . '</h4>'
            . '<table class="nl-list"><tbody>';

        foreach ($names as $name) {
            $stat = self::treeSize($root . '/' . $name);

            $html .= '<tr><td><b>' . Admin::esc($name) . '</b>'
                . '<em>' . Admin::esc(_t('%1$d 个文件 · %2$s', $stat[0], self::humanSize($stat[1]))) . '</em></td>'
                . '<td class="nl-ops">';

            foreach (array(
                'rollback' => _t('回滚到这一版'),
                'drop_backup' => _t('删除这份备份'),
            ) as $action => $label) {
                $ask = $action === 'drop_backup'
                    ? _t('删掉这份备份就没了，views/ 里的旧计数也一起没了。确定？')
                    : _t('把这一版搬回主题目录？现在的版本会被存成一份新备份。');

                $html .= '<form method="post" action="' . Admin::esc($url) . '"'
                    . ' data-ask="' . Admin::esc($ask) . '">'
                    . '<input type="hidden" name="_" value="' . Admin::esc($token) . '">'
                    . '<input type="hidden" name="to" value="' . Admin::esc($name) . '">'
                    . '<button type="submit" class="btn btn-s' . ($action === 'drop_backup' ? ' btn-warn' : '')
                    . '" name="action" value="' . $action . '">' . Admin::esc($label) . '</button></form> ';
            }

            $html .= '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private static function leftoversHtml(string $token, string $url): string
    {
        $dir = self::workDir();

        if ($dir === null) {
            return '';
        }

        $leftovers = array_merge(
            (array)glob($dir . '/package-*'),
            (array)glob($dir . '/*.zip')
        );

        if (empty($leftovers)) {
            return '';
        }

        return '<form class="nl-inline" method="post" action="' . Admin::esc($url) . '"'
            . ' data-ask="' . Admin::esc(_t('删掉这些升级残留？不影响站点和备份。')) . '">'
            . '<input type="hidden" name="_" value="' . Admin::esc($token) . '">'
            . '<button type="submit" class="btn btn-s" name="action" value="clean_work">'
            . Admin::esc(_t('清掉 %d 项升级残留', count($leftovers))) . '</button> '
            . '<span class="nl-note">' . Admin::esc(_t('上次下载或解压没走完留下的东西。')) . '</span></form>';
    }

    private static function dropBackup(string $name): array
    {
        $path = self::backupPath($name);

        if ($path === '') {
            return self::fail(_t('找不到这个备份，或者名字不合法。'));
        }

        if (!self::rrmdir($path)) {
            return self::fail(_t('备份删不干净：%s', self::phpError()));
        }

        return array('success', Admin::esc(_t('已删除备份 %s。', $name)));
    }

    /* ======================================================================
       杂项
       ====================================================================== */

    private static function fail(string $message): array
    {
        return array('error', Admin::esc($message));
    }

    private static function phpError(): string
    {
        $last = error_get_last();

        return is_array($last) && isset($last['message']) ? (string)$last['message'] : _t('没有更多细节');
    }

    private static function safe(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        if ($clean === '' || $clean === '.' || $clean === '..') {
            return 'x';
        }

        return substr($clean, 0, 80);
    }

    private static function trimBody(string $body): string
    {
        $body = trim($body);

        return mb_strlen($body, 'UTF-8') > 4000 ? mb_substr($body, 0, 4000, 'UTF-8') . '…' : $body;
    }

    /**
     * 目录下的所有文件（含软链本身），返回绝对路径。目录自己不入表，也不跟软链往下走。
     *
     * @return array
     */
    private static function walk(string $dir): array
    {
        $out = array();
        $stack = array($dir);

        while (!empty($stack)) {
            $current = array_pop($stack);
            $handle = @opendir($current);

            if ($handle === false) {
                continue;
            }

            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $current . '/' . $entry;

                /* 软链只记录、不进入：遍历不能顺着它爬到目录外面去 */
                if (is_link($path)) {
                    $out[] = $path;
                    continue;
                }

                if (is_dir($path)) {
                    $stack[] = $path;
                    continue;
                }

                $out[] = $path;
            }

            closedir($handle);
        }

        return $out;
    }

    /**
     * @return array [文件数, 字节数]
     */
    private static function treeSize(string $dir): array
    {
        $count = 0;
        $bytes = 0;

        foreach (self::walk($dir) as $path) {
            if (is_link($path)) {
                continue;
            }

            $count++;
            $bytes += (int)@filesize($path);
        }

        return array($count, $bytes);
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return (int)max(1, round($bytes / 1024)) . ' KB';
    }

    /**
     * 递归删目录。只用在自己造的临时目录和备份上，入口前都已经验过名字合法性。
     */
    private static function rrmdir(string $dir): bool
    {
        $root = self::rootDir();

        if ($dir === '' || !is_dir($dir) || is_link($dir)) {
            return false;
        }

        /* 只允许删 uploads 下我们自己的两个目录 */
        if (strpos($dir, $root . '/usr/uploads/noline_update') !== 0
            && strpos($dir, $root . '/usr/uploads/noline_backup') !== 0) {
            return false;
        }

        $stack = array($dir);

        while (!empty($stack)) {
            $current = array_pop($stack);
            $handle = @opendir($current);

            if ($handle === false) {
                continue;
            }

            $children = array();

            while (($entry = readdir($handle)) !== false) {
                if ($entry !== '.' && $entry !== '..') {
                    $children[] = $current . '/' . $entry;
                }
            }

            closedir($handle);

            foreach ($children as $child) {
                if (is_dir($child) && !is_link($child)) {
                    $stack[] = $child;
                    continue;
                }

                @unlink($child);
            }

            /* 这一层空了才删得掉；上面的 unlink 失败时这里会留痕 */
            @rmdir($current);
        }

        return !is_dir($dir);
    }
}
