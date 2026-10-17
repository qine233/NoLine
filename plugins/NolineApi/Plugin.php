<?php

namespace TypechoPlugin\NolineApi;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Noline 对外接口
 *
 * 把主题设置、个人健康数据（体重 / BMI）和买菜流水以 JSON 接口暴露出去，给 App 或脚本对接用；
 * 顺带在后台挂一个「主题管理」页，主题的全部配置项、GitHub 更新检测与一键升级都在那儿，
 * 还能把后台编辑器的附件面板接上 Cloudreve v4 当图床（传完往正文插永久直链）。
 * 鉴权走自设密钥：请求头 X-Noline-Key，或查询参数 key。站点没上 HTTPS 时务必用只读模式。
 *
 * 接口清单、鉴权方式、curl 示例都在「控制台 → 插件 → NolineApi → 设置」页面里。
 *
 * @package NolineApi
 * @author 州州
 * @version 1.7.3
 * @link https://ui.zhouzhou.work/
 */
class Plugin implements PluginInterface
{
    /** 改这里要同时改文件头注释里的 @version，后台插件列表读的是注释 */
    private const VERSION = '1.7.3';

    /** 数据目录放在 uploads 下，不在主题目录内，升级主题不会清空记录 */
    private const DATA_DIR = '/usr/uploads/noline_api';

    /** 记录文件用 .php 后缀 + 头部守卫：直接访问只会拿到空白页，不会漏出体重数据 */
    private const DATA_FILE = 'store.php';
    private const GUARD = "<?php if (!defined('__TYPECHO_ROOT_DIR__')) { exit; } ?>\n";

    /** 接口一次最多吐出多少条记录 */
    private const MAX_LIMIT = 366;

    /** 文件里最多留多少条：按一天一条能存 10 年，再多才丢最旧的，趋势不会莫名断头 */
    private const KEEP = 3660;

    /** 核心把钩子记在 options.plugins 里时用的名字，就是插件目录名 */
    private const HANDLE = 'NolineApi';

    /**
     * 这个插件往核心身上挂的所有钩子，一处声明。
     * 每项 [句柄文件, 组件, 静态方法]，activate() 照它注册，换装后的自愈照它比对。
     *
     * 改这张表要守一条规矩：<strong>onRequest 和 onAdminMenu 这两个入口不能同时消失或改名</strong>。
     * 在线更新只换文件，换不掉数据库里已经登记好的钩子——自愈全靠这两条里至少有一条还挂着，
     * 下一次请求才进得来。真要挪走谁，先留一个版本做兼容，再在下一个版本删。
     */
    private const HOOKS = array(
        // begin 在 Router::dispatch() 之前触发，此时还没有任何输出，可以直接吐 JSON 并 exit
        array('index.php', 'begin', 'onRequest'),
        // 后台没有给插件留独立页面的挂载点，能塞东西的只有顶栏这一条，页面本身自己渲染
        array('admin/menu.php', 'navBar', 'onAdminMenu'),
        // 图床面板挂在写文章/写页面的 bottom。不占 richEditor：那个钩子一旦被插件占了，
        // 核心就跳过 editor-js.php，自带编辑器和它的插入行为一起没了
        array('admin/write-post.php', 'bottom', 'onEditorBottom'),
        array('admin/write-page.php', 'bottom', 'onEditorBottom'),
    );

    public static function activate()
    {
        self::registerHooks();

        return _t('接口已启用。先在插件设置里填密钥，再访问 <code>/api/ping</code> 自检；'
            . '主题设置、更新记录与回滚在后台右上角的「主题管理」里。'
            . '<strong>主题自动更新默认开着</strong>：后台路过任意一个页面时如果发现 '
            . 'Release 里有新版本，会当场把旧主题和旧插件各整份备份、再换成新版本，不需要点任何东西，'
            . '不想让它自己动手就在那个页面的设置里关掉。连不上 GitHub 的站点可以在设置里填「主题更新镜像」，'
            . '直连失败会自动退到镜像。图床同样在设置里填 Cloudreve 地址和 '
            . 'Refresh Token，填好后进写文章页的「附件」标签就能看到上传区。');
    }

    /** 照 HOOKS 把钩子挂上核心。激活和自愈重登记走的都是这一个入口 */
    private static function registerHooks()
    {
        foreach (self::HOOKS as $hook) {
            \Typecho\Plugin::factory($hook[0])->{$hook[1]} = array(__CLASS__, $hook[2]);
        }
    }

    /**
     * 换装之后的钩子自愈。
     *
     * 在线更新能把 usr/plugins/NolineApi 整个换掉，却换不掉数据库里登记好的钩子——
     * 核心只在「停用再启用」那一刻把 activate() 注册的东西序列化进 options.plugins。
     * 于是每次路过入口钩子都比对一次：登记的和当前代码要的不一样，就照 activate() 的路子
     * 重新登记一遍写回去。人不用去插件页点任何东西，设置那行 <code>plugin:NolineApi</code> 也不碰。
     *
     * 常态是一次字符串比较就返回，不碰数据库；真要写而写失败也不吭声——钩子照旧能跑，
     * 下一个请求还会再试，比一次失败的写库把整站拖崩划算得多。
     */
    public static function resyncHooks()
    {
        static $asked = false;

        if ($asked) {
            return;
        }

        $asked = true;

        try {
            $plugins = \Typecho\Plugin::export();
            $mine = isset($plugins['activated'][self::HANDLE]['handles'])
                && is_array($plugins['activated'][self::HANDLE]['handles'])
                ? $plugins['activated'][self::HANDLE]['handles'] : null;

            /* 插件不在启用列表里（被停用了，或者那条记录被人动过）：不该在这儿替人启用 */
            if ($mine === null) {
                return;
            }

            $expected = array();

            foreach (self::HOOKS as $hook) {
                $expected[$hook[0] . ':' . $hook[1]][] = array(__CLASS__, $hook[2]);
            }

            if (self::hookSignature($mine) === self::hookSignature($expected)) {
                return;
            }

            /* 摘掉旧的、按当前代码重新挂上，用的都是核心自己的方法：
               出来的顺序和权重，跟人去插件页「停用再启用」一次完全一样 */
            \Typecho\Plugin::deactivate(self::HANDLE);
            self::registerHooks();
            \Typecho\Plugin::activate(self::HANDLE);

            $db = Db::get();
            $db->query($db->update('table.options')
                ->rows(array('value' => serialize(\Typecho\Plugin::export())))
                ->where('name = ?', 'plugins'));
        } catch (\Throwable $e) {
            // 见上文：静默，下个请求再来
        }
    }

    /**
     * 把「句柄:组件 → 回调列表」压成一个能直接比字符串的形状。
     * 顺序也排进去：核心按权重排序存过，比对不该因为顺序不同就白写一次库。
     */
    private static function hookSignature(array $handles): string
    {
        $flat = array();

        foreach ($handles as $component => $callbacks) {
            foreach ((array)$callbacks as $callback) {
                $flat[] = $component . '=' . (is_array($callback)
                    ? implode('::', $callback)
                    : (is_string($callback) ? $callback : gettype($callback)));
            }
        }

        sort($flat);

        return implode('|', $flat);
    }

    public static function deactivate()
    {
    }

    /**
     * 后台配置面板
     *
     * 注意：这里注册的字段名必须和存进 options 的键一一对应——
     * Widget\Plugins\Config::config() 会对着已存的每个键调 $form->getInput($key)->value()，
     * 存了没注册的键会让设置页直接白屏。删字段时记得顺手删掉那条 options。
     */
    public static function config(Form $form)
    {
        $enabled = new Radio(
            'enabled',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('对外接口'),
            _t('关闭时所有 /api 请求返回 503，健康页仍照常显示已录入的数据。')
        );
        $form->addInput($enabled);

        $apiKey = new Password(
            'apiKey',
            null,
            null,
            _t('接口密钥'),
            _t('自己随机生成一串长一点的值贴在这里，原文存进数据库。请求时带 <code>X-Noline-Key: 密钥</code>（或 <code>?key=密钥</code>）。'
                . '<strong>清空并保存会同时清掉密钥</strong>，届时接口全部 401。')
        );
        $form->addInput($apiKey);

        $write = new Radio(
            'writeEnabled',
            array('1' => _t('允许写入'), '0' => _t('只读')),
            '0',
            _t('数据写入（健康 / 记账）'),
            _t('只读时两类数据的 POST / PUT / DELETE 一律 403（GET 照常）。'
                . 'App 端要能记体重、记菜钱才需要开启，且务必只在 HTTPS 下开。')
        );
        $form->addInput($write);

        $height = new Text('height', null, null, _t('身高（厘米）'), _t('算 BMI 用，例如 172。留空则 BMI 相关字段为 null。'));
        $form->addInput($height);

        $gender = new Radio(
            'gender',
            array('male' => _t('男'), 'female' => _t('女')),
            'male',
            _t('性别'),
            _t('只用于给出理想体重区间，不影响 BMI 计算。')
        );
        $form->addInput($gender);

        $target = new Text('targetWeight', null, null, _t('目标体重（公斤）'), _t('例如 65。留空则不输出目标进度。'));
        $form->addInput($target);

        $cors = new Text(
            'corsOrigin',
            null,
            null,
            _t('允许跨域来源'),
            _t('网页版 App 才需要。填 <code>*</code> 或逗号分隔的完整 Origin（<code>https://app.example.com</code>）。'
                . '留空则不发任何 CORS 头，原生 App 和脚本不受影响。')
        );
        $form->addInput($cors);

        $visible = new Radio(
            'healthVisible',
            array('1' => _t('所有人可见'), '0' => _t('仅自己可见')),
            '1',
            _t('健康页可见性'),
            _t('只管主题里那个「健康记录」页面，接口不受影响（接口本来就要密钥）。'
                . '选「仅自己可见」时，未登录的访客只看得到一句提示。')
        );
        $form->addInput($visible);

        $billsVisible = new Radio(
            'billsVisible',
            array('1' => _t('所有人可见'), '0' => _t('仅自己可见')),
            '1',
            _t('记账页可见性'),
            _t('只管主题里那个「买菜记账」页面，和健康页各管各的。'
                . '选「仅自己可见」时，未登录的访客只看得到一句提示；接口本来就要密钥，不受这里影响。')
        );
        $form->addInput($billsVisible);

        $themeWrite = new Radio(
            'themeWrite',
            array('1' => _t('允许写入'), '0' => _t('禁止写入')),
            '1',
            _t('接口写主题设置'),
            _t('管 <code>PUT /api/theme</code>。禁止时接口仍能读，写入一律 403。'
                . '<strong>开着它 = 拿到密钥的人能改整站门面</strong>（公告和底部版权是原样存的 HTML，'
                . '等于能在前台执行任意脚本），没有 App 需要就关掉。后台「主题管理」页不受这项影响。')
        );
        $form->addInput($themeWrite);

        $repo = new Text(
            'nolineRepo',
            null,
            'qine233/noline',
            _t('主题更新仓库'),
            _t('格式 <code>owner/repo</code>。后台「主题管理」页按这个仓库最新的 <strong>已发布</strong> Release '
                . '找附件 <code>noline.zip</code>。留空按 <code>qine233/noline</code> 处理。')
        );
        $form->addInput($repo);

        $ghToken = new Password(
            'ghToken',
            null,
            null,
            _t('GitHub 只读 token（可选）'),
            _t('匿名访问 API 每个 IP 每小时只有 60 次，被限流时检测会失败。'
                . '填一个<strong>只勾 public_repo 读权限</strong>的 fine-grained / classic token 能把额度提到 5000 次/小时。'
                . '原文存库，只在请求头里发给 api.github.com。')
        );
        $form->addInput($ghToken);

        $autoUp = new Radio(
            'autoUpgrade',
            array('1' => _t('自动升级'), '0' => _t('只提示，我自己点')),
            '1',
            _t('主题自动更新'),
            _t('开着的时候，后台任意一个页面路过都会查一次（结果缓存 6 小时），'
                . '发现 Release 里有新版本就当场下载、解压、把旧主题和旧插件各整份挪进备份再让新版本顶上，不需要你点任何东西。'
                . '<strong>站点会在那几十微秒里对撞上的请求返回一次错误</strong>；'
                . '中途被打断会自己把备份搬回原位。旧版本全留在 <code>usr/uploads/noline_backup</code>，'
                . '「主题管理」页随时能回滚。换完插件不用再去插件页停用再启用：'
                . '它登记的钩子会在下一个页面自动对齐当前代码。'
                . '两轮之间至少隔 1 小时，失败也不会每个页面刷新一次去撞 GitHub 限额。'
                . '只有管理员登录的后台请求会触发。<strong>注意它不判断 Release 本身是好是坏</strong>——'
                . '传上去的包只要版本号和 tag 对得上就会直接顶上，发布前自己验一遍。')
        );
        $form->addInput($autoUp);

        $mirrors = new Textarea(
            'updateMirrors',
            null,
            Updater::DEFAULT_MIRRORS,
            _t('主题更新镜像（可选）'),
            _t('一行一条，按顺序试。<strong>GitHub 直连优先</strong>，只有直连失败（连不上、超时、被限流、5xx）才退到镜像；'
                . '镜像也一条条试，试不通的进 30 分钟冷却，不会拿每个后台页面去撞它。<br>'
                . '三种写法：<strong>纯域名前缀</strong>（如 <code>https://gh-proxy.com/</code>，插件把整条 GitHub 地址拼在后面，'
                . '检测和下载都能用）；<strong>含 <code>{api}</code></strong> 的当检测模板；'
                . '<strong>含 <code>{tag}</code> 或 <code>{asset}</code></strong> 的只当下载地址（适合只镜像了包的自有 CDN）。<br>'
                . '留空就是只用 GitHub 直连。<strong>镜像站不会收到你的 GitHub token</strong>（token 只发给 api.github.com），'
                . '但镜像能决定它回给你什么字节，所以<em>只有从 api.github.com 直连拿到的 sha256 摘要才算可信</em>：'
                . '检测只能走镜像时，自动覆盖会停下来等你在「主题管理」里手动确认，接口和页面本身不受影响。')
        );
        $mirrors->input->setAttribute('rows', '4');
        $form->addInput($mirrors);

        $crEnabled = new Radio(
            'crEnabled',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('Cloudreve 图床'),
            _t('关闭时写文章页不出现图床上传区，已插进正文的直链不受影响。')
        );
        $form->addInput($crEnabled);

        $crHost = new Text(
            'crHost',
            null,
            null,
            _t('Cloudreve 实例地址'),
            _t('例如 <code>https://pan.example.com</code>，装在子目录就一起填上。'
                . '插件只会往这个地址的 <code>/api/v4/</code> 发请求。留空等于关掉图床。')
        );
        $form->addInput($crHost);

        $crDir = new Text(
            'crDir',
            null,
            'noline',
            _t('存放目录'),
            _t('相对 Cloudreve 用户根目录的路径，例如 <code>noline</code> 或 <code>博客/图床</code>。'
                . '不存在会在第一次上传时自动建。')
        );
        $form->addInput($crDir);

        $crRefresh = new Password(
            'crRefresh',
            null,
            null,
            _t('Refresh Token（推荐）'),
            _t('v4 没有长期有效的个人 token：浏览器用的 access token 默认只活 1 小时，'
                . 'refresh token 默认 180 天。登录 Cloudreve 后在浏览器控制台执行 '
                . '<code>localStorage.getItem("refresh_token")</code> 把这一串贴进来，'
                . '插件会自己在快到期时换新的 access token。原文存库，只发给上面那个地址。')
        );
        $form->addInput($crRefresh);

        $crAccess = new Password(
            'crAccess',
            null,
            null,
            _t('Access Token（备选，活不长）'),
            _t('拿不到 refresh token 时才填。填了 Refresh Token 就不用管这里——'
                . '它过期后接口会明确报 401，到时候回来补 Refresh Token。')
        );
        $form->addInput($crAccess);

        $crMaxSize = new Text(
            'crMaxSize',
            null,
            (string)Cloudreve::DEFAULT_MAX,
            _t('单文件上限（MB）'),
            _t('只是插件这边的闸，Cloudreve 自己那套存储策略限额照样生效；'
                . 'PHP 的 <code>upload_max_filesize</code> 更小时以 PHP 为准，文件会在进插件之前就被拒。')
        );
        $form->addInput($crMaxSize);

        $crAllow = new Text(
            'crAllow',
            null,
            Cloudreve::DEFAULT_ALLOW,
            _t('允许的扩展名'),
            _t('逗号分隔，不带点。按文件真实内容判类型后再比扩展名。')
        );
        $form->addInput($crAllow);

        $crPaste = new Radio(
            'crPaste',
            array('1' => _t('开'), '0' => _t('关')),
            '1',
            _t('粘贴截图直传'),
            _t('开着时在正文框里 Ctrl+V 一张截图就直接走图床。'
                . '关掉后仍然可以点按钮选文件或者拖进图床框。')
        );
        $form->addInput($crPaste);

        self::renderDocs();
    }

    /**
     * 给主题模板用的读配置入口（健康页要看「仅自己可见」这个开关）。
     * 插件没配置过时 Options::plugin() 会抛异常，这里统一兜到默认值。
     */
    public static function setting(string $name, string $default = ''): string
    {
        return self::configValue($name, $default);
    }

    public static function personalConfig(Form $form)
    {
    }

    /* ======================================================================
       请求入口
       ====================================================================== */

    /**
     * 后台顶栏那一个链接。插件能挂的只有这一处，页面本体还是走前台路由渲染。
     * 顺路当自动更新的触发点：这一段每个后台页面都会走，且只在登录态下渲染。
     */
    public static function onAdminMenu()
    {
        self::resyncHooks();
        Admin::menuLink();
        Updater::autoTick();
    }

    /**
     * 写文章 / 写页面页脚（admin/write-post.php:bottom、write-page.php:bottom）。
     * 两个钩子共用这一个入口，图床面板自己判断该不该出现。
     */
    public static function onEditorBottom($content = null)
    {
        Cloudreve::onWriteBottom($content);
    }

    /**
     * index.php:begin 钩子。不是接口请求就直接返回，让 Typecho 正常路由。
     */
    public static function onRequest($unused = null)
    {
        // 前台每一次请求都先确认钩子登记和当前代码对不对得上：在线更新换的是文件，换不掉数据库
        self::resyncHooks();

        $route = self::route();

        if ($route === '') {
            return;
        }

        // 管理页看的是登录态，跟对外接口开没开、密钥对不对都没关系
        if ($route === Admin::ROUTE) {
            Admin::handle();

            return;
        }

        // 图床上传同理：它在浏览器和 Cloudreve 之间当代理，凭据在库里，不该要接口密钥
        if ($route === Cloudreve::ROUTE) {
            Cloudreve::handle();

            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';

        self::cors();

        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if (!self::configValue('enabled', '1')) {
            self::json(array('code' => 503, 'message' => _t('接口未开启')), 503);
        }

        // ping 不带鉴权，方便确认「路径通了」和「鉴权挂了」是两件事
        if ($route === 'ping') {
            $options = Options::alloc();
            self::json(array(
                'code' => 0,
                'data' => array(
                    'name' => (string)$options->title,
                    'url' => (string)$options->siteUrl,
                    'theme' => (string)$options->theme,
                    'plugin' => self::VERSION,
                    'time' => date('c'),
                ),
            ));
        }

        self::requireKey();

        switch ($route) {
            case 'theme':
                if ($method === 'PUT') {
                    self::requireThemeWrite();
                    self::themePatch();
                }
                if ($method !== 'GET') {
                    self::json(array('code' => 405, 'message' => _t('该端点只支持 GET / PUT')), 405);
                }
                self::json(array('code' => 0, 'data' => self::themeSettings()));

            case 'health':
                self::json(array('code' => 0, 'data' => self::summary()));

            case 'health/records':
                if ($method === 'POST') {
                    self::requireWrite();
                    self::json(self::saveInput());
                }
                if ($method === 'DELETE') {
                    self::requireWrite();
                    self::json(self::deleteRecord());
                }
                if ($method !== 'GET') {
                    self::json(array('code' => 405, 'message' => _t('该端点只支持 GET / POST / DELETE')), 405);
                }

                $records = self::records(
                    self::intParam('limit', 30),
                    self::dateParam('from'),
                    self::dateParam('to')
                );
                self::json(array('code' => 0, 'data' => array('total' => count($records), 'records' => $records)));

            case 'bills':
            case 'bills/records':
                if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
                    self::requireWrite();
                }
                Bills::handle($route, $method);

            default:
                self::json(array('code' => 404, 'message' => _t('接口不存在')), 404);
        }
    }

    /**
     * 解析接口路径。同时认三种写法：
     *   https://site/api/health          伪静态开启
     *   https://site/index.php/api/...   伪静态关闭
     *   https://site/?noline_api=health  服务器把 /api 抢去用了、或连 index.php 都进不来时的兜底
     */
    private static function route(): string
    {
        if (isset($_GET['noline_api'])) {
            return trim(preg_replace('/[^a-z0-9\/_-]/i', '', (string)$_GET['noline_api']), '/');
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '';
        }

        $root = parse_url((string)Options::alloc()->siteUrl, PHP_URL_PATH);
        if (is_string($root) && $root !== '/' && $root !== '' && strpos($path, $root) === 0) {
            $path = substr($path, strlen($root));
        }

        $path = ltrim($path, '/');

        if (strpos($path, 'index.php/') === 0) {
            $path = substr($path, 10);
        }

        return strpos($path, 'api/') === 0 ? trim(substr($path, 4), '/') : '';
    }

    /* ======================================================================
       鉴权
       ====================================================================== */

    private static function requireKey()
    {
        $stored = trim((string)self::configValue('apiKey', ''));

        if ($stored === '') {
            self::json(array('code' => 503, 'message' => _t('接口密钥未设置')), 503);
        }

        $given = '';
        if (isset($_SERVER['HTTP_X_NOLINE_KEY'])) {
            $given = trim((string)$_SERVER['HTTP_X_NOLINE_KEY']);
        } elseif (isset($_GET['key'])) {
            $given = trim((string)$_GET['key']);
        }

        if ($given === '') {
            self::json(array('code' => 401, 'message' => _t('缺少密钥：请在请求头带 X-Noline-Key')), 401);
        }

        // 后台存的就是原文，直接比。走 hash_equals 而不是 ==，不泄露长度信息。
        if (!hash_equals($stored, $given)) {
            self::json(array('code' => 403, 'message' => _t('密钥不正确')), 403);
        }
    }

    private static function requireWrite()
    {
        if (!self::configValue('writeEnabled', '0')) {
            self::json(array('code' => 403, 'message' => _t('写入未开启，请到插件设置里允许写入')), 403);
        }
    }

    /**
     * 主题设置是整站门面，和健康数据分开开关。
     */
    private static function requireThemeWrite()
    {
        if (!self::configValue('themeWrite', '1')) {
            self::json(array(
                'code' => 403,
                'message' => _t('接口写主题设置未开启，到插件设置里把「接口写主题设置」改成允许'),
            ), 403);
        }
    }

    private static function cors()
    {
        $allow = trim((string)self::configValue('corsOrigin', ''));
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';

        if ($allow === '' || $origin === '') {
            return;
        }

        $list = preg_split('/[\s,]+/', $allow, -1, PREG_SPLIT_NO_EMPTY);
        if ($allow === '*' || in_array($origin, (array)$list, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: X-Noline-Key, Content-Type');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Max-Age: 600');
        }
    }

    /* ======================================================================
       主题设置
       ====================================================================== */

    /**
     * 只吐主题自己的配置 + 站点门面信息，绝不碰 options 全表（里面有利落、密码哈希等）。
     *
     * $values 是 Theme::write() 刚写进去的那一份：Options 在请求开头就摊平好了，
     * 同一次请求里再读还是旧值，PUT 的响应必须用传进来的值，否则 App 会看到改了没变。
     */
    private static function themeSettings(?array $values = null): array
    {
        $options = Options::alloc();
        $theme = Theme::shape($values === null ? Theme::read() : $values);

        return array(
            'site' => array(
                'title' => (string)$options->title,
                'description' => (string)$options->description,
                'url' => (string)$options->siteUrl,
                'theme' => (string)$options->theme,
                'lang' => (string)$options->language,
                'timezone' => (int)$options->timezone,
            ),
            'theme' => $theme,
            'accent' => self::accent(isset($theme['accentColor']) ? $theme['accentColor'] : ''),
        );
    }

    /**
     * PUT /api/theme：body 是 {字段名: 值} 的补丁，只带想改的键。
     *
     * 任一项校验不过就整批不写，App 不用猜「到底存进去几项」。
     */
    private static function themePatch()
    {
        $body = self::body();

        if ($body === null) {
            self::json(array('code' => 400, 'message' => _t('请求体不是合法 JSON 对象')), 400);
        }

        // JSON 数组（[{…}]）解码出来是数字键，混进 write() 只会得到一排「不是本主题的配置项」，
        // 对客户端毫无信息量，这里当场按「不是对象」退回
        if (self::isList($body)) {
            self::json(array('code' => 400, 'message' => _t('请求体不是合法 JSON 对象')), 400);
        }

        $result = Theme::write($body);

        if (!$result['ok']) {
            self::json(array(
                'code' => 400,
                'message' => _t('一项都没写入。data.errors 的键就是字段名，改对再整批重发。'),
                'data' => array('errors' => $result['errors']),
            ), 400);
        }

        self::json(array(
            'code' => 0,
            'data' => array_merge(self::themeSettings($result['values']), array(
                'saved' => array_keys($body),
            )),
        ));
    }

    /**
     * 强调色 + 由它推出来的前景色，App 不用自己再实现一遍亮度算法。
     * 十六进制解析失败时回落到主题默认靛蓝，和 functions.php 的 noline_accent_style() 保持一致。
     */
    private static function accent(string $hex): array
    {
        $fallback = '#4482e5';

        if (preg_match('/^#?([0-9a-f]{3})$/i', trim($hex), $m)) {
            $hex = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        } elseif (preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) {
            $hex = '#' . $m[1];
        } else {
            $hex = $fallback;
        }

        $rgb = array(
            (int)hexdec(substr($hex, 1, 2)),
            (int)hexdec(substr($hex, 3, 2)),
            (int)hexdec(substr($hex, 5, 2)),
        );

        // 线性化后的相对亮度（和主题里同一个公式）
        $channels = array();
        foreach ($rgb as $c) {
            $s = $c / 255;
            $channels[] = $s <= 0.03928 ? $s / 12.92 : pow(($s + 0.055) / 1.055, 2.4);
        }
        $l = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return array(
            'hex' => strtolower($hex),
            'rgb' => implode(', ', $rgb),
            'luminance' => round($l, 4),
            // 深色底上放白字还是黑字：亮度 0.45 以下用浅色前景
            'on' => $l < 0.45 ? '#ffffff' : '#161412',
            // 深色模式用的提亮版，避免强调色在深底上糊成一片
            'dark' => self::lighten($rgb, 0.28),
        );
    }

    private static function lighten(array $rgb, float $ratio): string
    {
        $out = array();
        foreach ($rgb as $c) {
            $out[] = (int)round($c + (255 - $c) * $ratio);
        }

        return '#' . sprintf('%02x%02x%02x', $out[0], $out[1], $out[2]);
    }

    /* ======================================================================
       健康数据：读
       ====================================================================== */

    /**
     * 记录列表（按日期升序），每行都补了 BMI 和与上一条的差值，模板和 App 拿到就能直接画。
     */
    public static function records(int $limit = 0, string $from = '', string $to = ''): array
    {
        $all = array_values(self::raw());
        $height = self::height();
        $rows = array();

        foreach ($all as $index => $record) {
            $date = $record['date'];

            if ($from !== '' && $date < $from) {
                continue;
            }
            if ($to !== '' && $date > $to) {
                continue;
            }

            $weight = (float)$record['weight'];
            $previous = $index > 0 ? (float)$all[$index - 1]['weight'] : null;
            $bmi = $height === null ? null : self::bmi($weight, $height);

            $rows[] = array(
                'date' => $date,
                'weight' => $weight,
                'delta' => $previous === null ? null : round($weight - $previous, 1),
                'bmi' => $bmi,
                'category' => $bmi === null ? '' : self::category($bmi),
                'fat' => $record['fat'],
                'note' => $record['note'],
            );
        }

        if ($limit > 0) {
            $rows = array_slice($rows, -$limit);
        }

        return $rows;
    }

    /**
     * 概览：最新一条、区间统计、目标进度、近 90 天趋势。
     */
    public static function summary(): array
    {
        $all = self::records();

        if (empty($all)) {
            return array(
                'profile' => self::profile(),
                'count' => 0,
                'first' => null,
                'last' => null,
                'latest' => null,
                'stats' => null,
                'series' => array(),
                'goal' => null,
                'changes' => array(),
            );
        }

        $weights = array_column($all, 'weight');
        $latest = $all[count($all) - 1];

        $byDate = array();
        foreach ($all as $row) {
            $byDate[$row['date']] = $row['weight'];
        }
        $changes = self::changes($byDate, $latest['date'], (float)$latest['weight']);

        return array(
            'profile' => self::profile(),
            'count' => count($all),
            'first' => $all[0]['date'],
            'last' => $latest['date'],
            'latest' => $latest,
            'stats' => array(
                'min' => round((float)min($weights), 1),
                'max' => round((float)max($weights), 1),
                'avg' => round(array_sum($weights) / count($weights), 1),
            ),
            'series' => $all,
            'goal' => self::goal((float)$latest['weight']),
            'changes' => $changes,
        );
    }

    private static function profile(): array
    {
        $height = self::height();
        $gender = self::configValue('gender', 'male') === 'female' ? 'female' : 'male';

        return array(
            'height' => $height,
            'gender' => $gender,
            'target_weight' => self::floatConfig('targetWeight'),
            // 简单的身高减 105 公式给的理想体重区间，男女差 2kg，仅供参考
            'ideal' => $height === null ? null : array(
                'low' => round($height - 105 - 2.5, 1),
                'high' => round($height - 105 + 2.5, 1),
            ),
            'bmi_ranges' => array(
                'thin' => 18.5,
                'normal' => 24.0,
                'over' => 28.0,
            ),
        );
    }

    private static function goal(float $current)
    {
        $target = self::floatConfig('targetWeight');

        if ($target === null) {
            return null;
        }

        $diff = round($current - $target, 1);

        return array(
            'target' => $target,
            'remaining' => $diff,
            'done' => abs($diff) < 0.05,
        );
    }

    /**
     * 相对最新记录往前 7、30、90 天的体重变化。跨度内没有正好那天的记录时，
     * 取该跨度内最近的一条，并把它的真实日期一起返回，App 侧不用猜。
     */
    private static function changes(array $byDate, string $recent, float $current): array
    {
        $out = array();

        foreach (array(7, 30, 90) as $days) {
            $anchor = date('Y-m-d', strtotime($recent) - $days * 86400);
            $from = null;
            $fromDate = null;

            foreach ($byDate as $date => $weight) {
                if ($date <= $anchor) {
                    $from = (float)$weight;
                    $fromDate = $date;
                } else {
                    break;
                }
            }

            $out['d' . $days] = array(
                'span' => $days,
                'from_date' => $fromDate,
                'delta' => $from === null ? null : round($current - $from, 1),
            );
        }

        return $out;
    }

    /* ======================================================================
       健康数据：写
       ====================================================================== */

    /**
     * 记录一条或多条。body 可以是单条对象，也可以是 {"records":[...]}。
     */
    private static function saveInput(): array
    {
        $body = self::body();

        if ($body === null) {
            self::json(array('code' => 400, 'message' => _t('请求体不是合法 JSON')), 400);
        }

        $input = isset($body['records']) && is_array($body['records']) ? $body['records'] : array($body);
        $saved = array();
        $errors = array();

        foreach ($input as $item) {
            if (!is_array($item)) {
                continue;
            }

            $record = self::normalize($item);

            if ($record === null) {
                $errors[] = isset($item['date']) ? (string)$item['date'] : _t('(无日期)');
                continue;
            }

            $saved[$record['date']] = $record;
        }

        if (empty($saved)) {
            self::json(array(
                'code' => 400,
                'message' => _t('没有可用记录'),
                'data' => array('invalid' => $errors),
            ), 400);
        }

        $total = self::mutate(function (array $existing) use (&$saved) {
            foreach ($saved as $date => $record) {
                $existing[$date] = $record;
            }
            return $existing;
        });

        if ($total === null) {
            self::json(array('code' => 500, 'message' => _t('数据目录不可写，检查 usr/uploads/noline_api')), 500);
        }

        self::json(array(
            'code' => 0,
            'data' => array(
                'saved' => count($saved),
                'total' => $total,
                'invalid' => $errors,
            ),
        ));
    }

    private static function deleteRecord(): array
    {
        $date = self::dateParam('date');

        if ($date === '') {
            self::json(array('code' => 400, 'message' => _t('缺少参数 date（YYYY-MM-DD）')), 400);
        }

        $removed = false;
        $total = self::mutate(function (array $existing) use ($date, &$removed) {
            if (isset($existing[$date])) {
                unset($existing[$date]);
                $removed = true;
            }
            return $existing;
        });

        if ($total === null) {
            self::json(array('code' => 500, 'message' => _t('数据目录不可写')), 500);
        }
        if (!$removed) {
            self::json(array('code' => 404, 'message' => _t('该日期没有记录')), 404);
        }

        self::json(array('code' => 0, 'data' => array('deleted' => $date, 'total' => $total)));
    }

    /**
     * 校验并整理一条记录。日期必须真实存在，体重 20–400kg，体脂 1–70%（都可选），备注截到 200 字。
     */
    private static function normalize(array $item)
    {
        $date = isset($item['date']) ? trim((string)$item['date']) : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $parts = explode('-', $date);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            return null;
        }

        if (!isset($item['weight'])) {
            return null;
        }

        $weight = round((float)$item['weight'], 1);
        if ($weight < 20 || $weight > 400) {
            return null;
        }

        $record = array('date' => $date, 'weight' => $weight);

        if (isset($item['fat']) && $item['fat'] !== '' && $item['fat'] !== null) {
            $fat = round((float)$item['fat'], 1);
            if ($fat >= 1 && $fat <= 70) {
                $record['fat'] = $fat;
            }
        }

        if (isset($item['note'])) {
            $note = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$item['note'])));
            if ($note !== '') {
                $record['note'] = mb_substr($note, 0, 200);
            }
        }

        return $record;
    }

    /**
     * 读—改—写全程带独占锁，避免 App 连续提交时后一次覆盖前一次。
     * 返回改后的总条数，写不进去返回 null。
     */
    private static function mutate(callable $callback)
    {
        $dir = self::dataDir();
        if ($dir === null) {
            return null;
        }

        $lock = @fopen($dir . '/store.lock', 'c');
        if ($lock === false) {
            return null;
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return null;
        }

        $existing = self::raw();
        $existing = $callback($existing);
        ksort($existing);

        $existing = array_slice($existing, -self::KEEP);
        $ok = self::put($existing);

        flock($lock, LOCK_UN);
        fclose($lock);

        return $ok ? count($existing) : null;
    }

    private static function put(array $records): bool
    {
        $dir = self::dataDir();
        if ($dir === null) {
            return false;
        }

        $payload = self::GUARD . json_encode(
            array('records' => array_values($records)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $file = $dir . '/' . self::DATA_FILE;
        $tmp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $payload) === false) {
            return false;
        }

        @chmod($tmp, 0644);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * 原始记录，按日期升序、同日期后写的覆盖先写的。
     */
    private static function raw(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = array();
        $dir = self::dataDir();

        if ($dir === null) {
            return $cache;
        }

        $file = $dir . '/' . self::DATA_FILE;
        if (!is_file($file)) {
            return $cache;
        }

        $raw = @file_get_contents($file, false, null, strlen(self::GUARD));
        $data = json_decode((string)$raw, true);

        if (!is_array($data) || !isset($data['records']) || !is_array($data['records'])) {
            return $cache;
        }

        foreach ($data['records'] as $record) {
            if (!is_array($record) || !isset($record['date'], $record['weight'])) {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$record['date'])) {
                continue;
            }

            $cache[(string)$record['date']] = array(
                'date' => (string)$record['date'],
                'weight' => round((float)$record['weight'], 1),
                'fat' => isset($record['fat']) ? (float)$record['fat'] : null,
                'note' => isset($record['note']) ? (string)$record['note'] : '',
            );
        }

        ksort($cache);

        return $cache;
    }

    /* ======================================================================
       存取与工具
       ====================================================================== */

    /**
     * 数据目录。不可创建或不可写时返回 null，此时读为空、写报错，不会把警告打在页面上。
     */
    private static function dataDir()
    {
        static $dir = false;

        if ($dir !== false) {
            return $dir;
        }

        $path = __TYPECHO_ROOT_DIR__ . self::DATA_DIR;

        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        if (!is_dir($path) || !is_writable($path)) {
            $dir = null;
            return $dir;
        }

        // 兜底：Apache 下直接封锁目录；nginx 靠 store.php 的 PHP 守卫挡爬。
        if (!is_file($path . '/.htaccess')) {
            @file_put_contents($path . '/.htaccess', "Require all denied\n");
        }

        $dir = $path;
        return $dir;
    }

    private static function height()
    {
        $height = self::floatConfig('height');

        if ($height === null || $height < 80 || $height > 250) {
            return null;
        }

        return $height;
    }

    private static function bmi(float $weight, float $height): float
    {
        return round($weight / pow($height / 100, 2), 1);
    }

    /**
     * 中国成人标准（WHO 的 25/30 门槛对亚洲人群偏松）。
     * 返回的是稳定的代码，文案交给模板和 App 自己翻，别让接口跟着站点的语言环境变。
     */
    private static function category(float $bmi): string
    {
        if ($bmi < 18.5) {
            return 'thin';
        }
        if ($bmi < 24.0) {
            return 'normal';
        }
        if ($bmi < 28.0) {
            return 'over';
        }

        return 'obese';
    }

    /**
     * 插件自己的设置。没配过或没激活时 Options::plugin() 会抛 500，全部兜到默认值。
     */
    private static function configValue(string $name, string $default): string
    {
        static $config = null;

        if ($config === null) {
            try {
                $config = Options::alloc()->plugin('NolineApi');
            } catch (\Throwable $e) {
                $config = false;
            }
        }

        if ($config === false) {
            return $default;
        }

        $value = $config->$name;

        return ($value === null || $value === '') ? $default : (string)$value;
    }

    private static function floatConfig(string $name)
    {
        $value = trim((string)self::configValue($name, ''));

        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return round((float)$value, 1);
    }

    private static function intParam(string $name, int $default): int
    {
        $value = isset($_GET[$name]) ? (int)$_GET[$name] : $default;

        return max(0, min($value, self::MAX_LIMIT));
    }

    /**
     * 只认 YYYY-MM-DD，其它一律当没传——日期串会直接参与文件里的比较，不能放脏值进来。
     */
    private static function dateParam(string $name): string
    {
        $value = isset($_GET[$name]) ? trim((string)$_GET[$name]) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function body()
    {
        $raw = file_get_contents('php://input');

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // 表单提交也接住，App 用 multipart 或 URL 编码时不用另外拼 JSON
        if (strpos($raw, '{') !== 0) {
            parse_str($raw, $parsed);
            $raw = isset($parsed['payload']) ? (string)$parsed['payload'] : json_encode($parsed);
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * 解码出来是「列表」而不是「对象」。空数组算对象——`{}` 是合法的空补丁，
     * 而 `[]` 本来也不该出现在这个端点上，当成空操作放过比报错省事。
     */
    private static function isList(array $data): bool
    {
        if ($data === array()) {
            return false;
        }

        return array_keys($data) === range(0, count($data) - 1);
    }

    private static function json(array $payload, int $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * 设置页顶上的接口说明书。不写 markdown 文件，就在这儿——配密钥的人本来就得进这个页面。
     */
    private static function renderDocs()
    {
        $url = rtrim((string)Options::alloc()->siteUrl, '/');
        $rows = array(
            array('GET', '/api/ping', _t('连通性自检，不需要密钥')),
            array('GET', '/api/theme', _t('站点门面 + 主题全部配置 + 由强调色推出的配色')),
            array('PUT', '/api/theme', _t('写主题配置，body 为 {"accentColor":"#4482e5","notice":"…"}，'
                . '只带要改的键；一项不过整批不写')),
            array('GET', '/api/health', _t('体重概览：最新值 / BMI / 统计 / 趋势 / 目标')),
            array('GET', '/api/health/records?limit=30&from=&to=', _t('明细，limit 最大 366')),
            array('POST', '/api/health/records', _t('写入，body 为 {"date":"2026-10-12","weight":68.4,"fat":18.2,"note":"…"}，也收 {"records":[…]} 批量')),
            array('DELETE', '/api/health/records?date=2026-10-12', _t('删除某天记录')),
        );

        // 记账那几条的说明写在 Bills 里（端点归它管），这儿只负责拼进同一张表
        $rows = array_merge($rows, Bills::docRows());

        $list = '';
        foreach ($rows as $row) {
            $list .= '<tr><td><b>' . htmlspecialchars($row[0]) . '</b></td><td><code>'
                . htmlspecialchars($url . $row[1]) . '</code></td><td>' . htmlspecialchars($row[2]) . '</td></tr>';
        }

        $keys = '';
        foreach (Theme::fields() as $key => $spec) {
            $keys .= '<code>' . htmlspecialchars($key) . '</code>'
                . '(' . htmlspecialchars($spec['kind']) . ') ';
        }

        echo '<div class="typecho-option" style="padding:.6rem 0">'
            . '<h3 style="margin:0 0 .4rem">' . _t('接口一览') . '</h3>'
            . '<p style="margin:0 0 .4rem;color:#666">'
            . _t('鉴权：请求头 <code>X-Noline-Key: 你的密钥</code>（或 <code>?key=</code>）。'
                . '路径不通时用兜底写法 <code>' . htmlspecialchars($url) . '/index.php?noline_api=health&key=…</code>。')
            . '</p>'
            . '<table class="w-100" style="border-collapse:collapse;font-size:.92em">'
            . '<tbody>' . $list . '</tbody></table>'
            . '<p style="margin:.6rem 0 0;color:#666;font-size:.92em">'
            . _t('可写字段（括号里是校验类型，含义和长度上限见后台「主题管理」页）：') . $keys . '</p>'
            . '<p style="margin:.4rem 0 0;color:#666;font-size:.92em">'
            . _t('<strong>注意</strong>：首页公告与底部版权原样入库、不清洗 HTML。拿到密钥就能往前台塞任意脚本，'
                . '不需要写主题时把「接口写主题设置」改成禁止。')
            . '</p>'
            . '<p style="margin:.6rem 0 0;color:#666"><code>curl -H "X-Noline-Key: ***" '
            . htmlspecialchars($url) . '/api/health</code></p>'
            . '<p style="margin:.4rem 0 0;color:#666"><code>curl -X PUT -H "X-Noline-Key: ***" '
            . '-H "Content-Type: application/json" -d \'{"accentColor":"#e0662e"}\' '
            . htmlspecialchars($url) . '/api/theme</code></p>'
            . '</div>';
    }
}
