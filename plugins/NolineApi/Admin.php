<?php

namespace TypechoPlugin\NolineApi;

use Widget\Options;
use Widget\Security;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 主题管理页：把主题的配置项、更新入口、接口状态收到一个页面上。
 *
 * 页面挂在 index.php:begin 的前台路由（?noline_api=settings）上，不是 /admin/ 里的文件——
 * Typecho 没给插件留后台侧栏或独立页面的挂载点，插件能用的只有 admin/menu.php:navBar
 * 这一个「往顶栏塞链接」的钩子。所以链接指向自己渲染的页面，权限照样在页子里查：
 * 必须是 administrator 登录态，动作还要带会话令牌。
 *
 * 为什么主题设置不直接写进插件设置页：插件设置那张表单存的是 plugin:NolineApi，
 * 主题配置必须落到 theme:noline，两套 input 混在一张 form 里只会互相抢提交。
 * 而且整个设置页本身是个 form，往里再塞一个 form 是非法嵌套，浏览器会把它拆出去。
 */
class Admin
{
    public const ROUTE = 'settings';

    /* ======================================================================
       入口
       ====================================================================== */

    /**
     * 后台右上角的链接（admin/menu.php:navBar）。
     * 深色开关也挂在这儿：顶栏是唯一每个后台页面都在、又不会被各页面自己 include 掉的位置。
     */
    public static function menuLink()
    {
        echo '<a href="' . self::esc(self::pageUrl()) . '">' . self::esc(_t('主题管理')) . '</a> &nbsp;';

        if (self::skinOn()) {
            echo self::themeToggle();
        }
    }

    /**
     * 接在核心那三个 <link> 后面，输出后台皮肤 + 图床配置（admin/header.php:header）。
     */
    public static function head(string $header): string
    {
        return $header . self::skinTags();
    }

    /**
     * <head> 里我们追加的那一沓。顺序有讲究：
     * 深色初值必须最早落到 <html> 上，样式表才能一上来就按深色画。
     */
    public static function skinTags(): string
    {
        $html = '';

        if (self::skinOn()) {
            $html .= self::themeBoot();
            $html .= self::accentStyle();
            $html .= self::assetTag('admin.css');
        }

        /* 图床跟皮肤是两回事：把后台样式改动关掉，上传面板照样得能用。
           面板的样式在 admin.css 里，关掉皮肤时它会退回没有装饰的样子——
           功能一个不少，只是不跟着主题走，这正是那个开关的意思。 */
        $html .= Cloudreve::editorConfigTag();
        $html .= self::assetTag('admin.js');

        return $html;
    }

    private static function skinOn(): bool
    {
        return Plugin::setting('adminSkin', '1') === '1';
    }

    /**
     * 同步跑在 <head> 里的一段脚本：把用户选过的深浅色在首屏绘制之前就写到 <html> 上。
     * 放在这里而不是 admin.js 里，是因为那个文件带 defer，等它跑完页面已经白闪过一次了。
     */
    private static function themeBoot(): string
    {
        return '<script>(function(r){try{var v=localStorage.getItem("noline:admin-theme");'
            . 'if(v!=="dark"&&v!=="light"){v=(window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches)?"dark":"light";}'
            . 'r.setAttribute("data-nl-theme",v);}catch(e){}})(document.documentElement);</script>' . "\n";
    }

    private static function themeToggle(): string
    {
        $dark = _t('切换到深色');
        $light = _t('切换到浅色');

        return '<button type="button" id="nl-theme-toggle" class="nl-theme-toggle"'
            . ' aria-pressed="false"'
            . ' title="' . self::esc($dark) . '"'
            . ' data-label-dark="' . self::esc($dark) . '"'
            . ' data-label-light="' . self::esc($light) . '">'
            /* 两个图标都写进 DOM，显示哪个由 CSS 按 html[data-nl-theme] 决定，省得在这儿猜初始态 */
            . '<svg class="nl-ico-light" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"'
            . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">'
            . '<circle cx="12" cy="12" r="4.2" fill="currentColor" stroke="none"/>'
            . '<path d="M12 2.6v2.4M12 19v2.4M2.6 12h2.4M19 12h2.4M5.3 5.3l1.7 1.7M17 17l1.7 1.7M18.7 5.3L17 7M7 17l-1.7 1.7"/>'
            . '</svg>'
            . '<svg class="nl-ico-dark" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"'
            . ' fill="currentColor">'
            . '<path d="M20.5 14.6A8.6 8.6 0 0 1 9.4 3.5a8.7 8.7 0 1 0 11.1 11.1z"/>'
            . '</svg></button>';
    }

    /**
     * 把主题里那套强调色推导原样搬过来，后台和前台用同一个色。
     * 算法在 Plugin 里（对外接口 /api/theme 返回的也是它），这里只负责变成 CSS 变量。
     */
    private static function accentStyle(): string
    {
        $options = Options::alloc();

        // 主题选项是整块序列化存的，用户没去后台保存过一次就没有这个键；直接读会撞 __get()
        $configured = isset($options->accentColor) ? (string)$options->accentColor : '';
        $rgb = Plugin::accentRgb($configured);

        if ($rgb === null) {
            // 没配过就一行都不输出，admin.css 里 var() 的缺省值是唯一的一份靛蓝
            return '';
        }

        $hover = Plugin::mix($rgb, 0, 0.18);
        $dark = Plugin::luminance($rgb) > 0.5 ? $rgb : Plugin::mix($rgb, 255, 0.30);
        $darkHover = Plugin::mix($dark, 255, 0.12);

        return '<style id="nl-admin-accent">:root{'
            . '--nl-accent-user:' . self::esc(Plugin::hex($rgb)) . ';'
            . '--nl-accent-user-rgb:' . self::esc(implode(', ', $rgb)) . ';'
            . '--nl-accent-user-hover:' . self::esc(Plugin::hex($hover)) . ';'
            . '--nl-accent-user-dark:' . self::esc(Plugin::hex($dark)) . ';'
            . '--nl-accent-user-dark-rgb:' . self::esc(implode(', ', $dark)) . ';'
            . '--nl-accent-user-dark-hover:' . self::esc(Plugin::hex($darkHover))
            . '}</style>' . "\n";
    }

    /**
     * 静态资源：<link> 或 <script src>，带版本号查询参数——改完文件不用清缓存，URL 自己变。
     */
    private static function assetTag(string $file): string
    {
        $url = rtrim((string)Options::alloc()->pluginUrl, '/') . '/NolineApi/assets/' . $file
            . '?v=' . Plugin::VERSION;

        if (substr($file, -4) === '.css') {
            return '<link rel="stylesheet" href="' . self::esc($url) . '">' . "\n";
        }

        return '<script src="' . self::esc($url) . '" defer></script>' . "\n";
    }

    /**
     * 处理一次管理页请求并结束。只有 route() 认到 settings 才会走到这里。
     */
    public static function handle()
    {
        $options = Options::alloc();
        $user = User::alloc();

        if (!$user->pass('administrator', true)) {
            self::deny();

            return;
        }

        $token = Security::alloc()->getToken(null);
        $action = isset($_POST['action']) ? preg_replace('/[^a-z_]/', '', (string)$_POST['action']) : '';

        // 上次交换要是停在「旧的已挪走、新的还没顶上」，先把没换完的部分退回旧版本再谈别的
        $messages = array();
        $repaired = Updater::repairMessage();

        if ($repaired !== '') {
            $messages[] = array('warn', $repaired);
        }

        if ($action !== '') {
            $given = isset($_POST['_']) ? (string)$_POST['_'] : '';

            if (!hash_equals($token, $given)) {
                $messages[] = array(
                    'error',
                    _t('安全令牌对不上。多半是页面开着太久或者换了登录状态，刷新一次再提交。'),
                );
            } else {
                $messages[] = self::dispatch($action);
            }
        }

        self::page($options, $messages, $token);
        exit;
    }

    /**
     * @return array [级别, 文案]
     */
    private static function dispatch(string $action): array
    {
        switch ($action) {
            case 'save_theme':
                $result = Theme::write(Theme::patchFromPost());

                if ($result['ok']) {
                    return array('success', _t('主题设置已保存，前台刷新即生效。'));
                }

                $lines = array();
                foreach ($result['errors'] as $key => $reason) {
                    $label = isset(Theme::fields()[$key])
                        ? Theme::fields()[$key]['label']
                        : _t('（未知项）');
                    // 校验文案里会带回用户提交的原话（比如 navIcons 里那条不合法的项），
                    // 而 messages 是当 HTML 输出的，这里必须自己转义
                    $lines[] = self::esc($label) . '：' . self::esc($reason);
                }

                return array('error', _t('没有保存，任何一项不通过都整批退回：') . implode('<br>', $lines));

            default:
                // cr_probe / cr_forget 归图床，其余（refresh / upgrade / rollback / drop_backup / clean_work）归更新器
                if (strpos($action, 'cr_') === 0) {
                    try {
                        return Cloudreve::act($action);
                    } catch (\Throwable $e) {
                        return array('error', _t('图床这一步没跑完：%s', $e->getMessage()));
                    }
                }

                return Updater::act($action);
        }
    }

    /* ======================================================================
       工具
       ====================================================================== */

    private static function pageUrl(): string
    {
        return rtrim((string)Options::alloc()->index, '/') . '?noline_api=' . self::ROUTE;
    }

    /**
     * 这一页所有动态内容都从这里出去。更新器的文案也用它，免得两处转义口径不一。
     */
    public static function esc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private static function deny()
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex, nofollow"><title>'
            . _t('禁止访问') . '</title></head><body>'
            . '<p>' . _t('这个页面只对站点管理员开放。请先在后台登录。') . '</p>'
            . '<p><a href="' . htmlspecialchars((string)Options::alloc()->loginUrl, ENT_QUOTES, 'UTF-8') . '">'
            . _t('去登录') . '</a></p></body></html>';
        exit;
    }

    /* ======================================================================
       渲染
       ====================================================================== */

    private static function page(Options $options, array $messages, string $token)
    {
        $values = Theme::read();
        $url = self::pageUrl();
        $siteUrl = rtrim((string)$options->siteUrl, '/');
        $active = trim((string)$options->theme);
        $isNoline = $active === Theme::DIR;

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store');

        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="renderer" content="webkit">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . self::esc(_t('主题管理')) . ' - ' . self::esc($options->title) . '</title>'
            . '<link rel="stylesheet" href="' . self::esc($options->adminStaticUrl('css', 'normalize.css', true)) . '">'
            . '<link rel="stylesheet" href="' . self::esc($options->adminStaticUrl('css', 'grid.css', true)) . '">'
            . '<link rel="stylesheet" href="' . self::esc($options->adminStaticUrl('css', 'style.css', true)) . '">'
            . self::skinTags()
            . '</head><body><div class="main"><div class="body container">'
            . '<div class="typecho-page-title"><h2>' . self::esc(_t('主题管理')) . '</h2></div>'
            . '<div class="row typecho-page-main" role="main"><div class="col-mb-12">';

        echo '<div class="nl-bar">'
            . '<a class="btn btn-s" href="' . self::esc($options->adminUrl('admin.php', true)) . '">'
            . self::esc(_t('返回后台')) . '</a> '
            . '<a class="btn btn-s" href="' . self::esc($siteUrl) . '" target="_blank" rel="noopener">'
            . self::esc(_t('看前台')) . '</a> '
            . '<span class="nl-site">' . self::esc($options->title) . '</span>'
            . '</div>';

        foreach ($messages as $message) {
            echo '<div class="nl-msg nl-msg-' . self::esc($message[0]) . '">' . $message[1] . '</div>';
        }

        if (!$isNoline) {
            echo '<div class="nl-msg nl-msg-warn">'
                . self::esc(_t('当前启用的主题不是 noline（是 %s）。这一页改的东西存不进正在生效的配置，'
                    . '保存会直接失败。切回 noline 再来改。', $active))
                . '</div>';
        }

        echo self::themeSection($values, $token, $url, $isNoline);
        echo self::apiSection($options);
        echo self::hookSection();
        echo Updater::section($token, $url);

        // 图床那一段出任何错都不许把这一页带走——它同时是在线更新的唯一入口
        try {
            echo Cloudreve::section($token, $url);
        } catch (\Throwable $e) {
            echo '<h3 class="nl-h3">' . self::esc(_t('Cloudreve 图床')) . '</h3>'
                . '<div class="nl-msg nl-msg-error">'
                . self::esc(_t('这一段没能显示出来：%s', $e->getMessage()))
                . '</div>';
        }

        echo '</div></div></div></div>' . self::scripts() . '</body></html>';
    }

    private static function themeSection(array $values, string $token, string $url, bool $editable): string
    {
        $html = '<h3 class="nl-h3">' . self::esc(_t('主题设置')) . '</h3>'
            . '<p class="nl-lead">'
            . self::esc(_t('和「控制台 → 设置外观 → 设置外观」是同一份数据，改哪边都一样。'
                . '每次保存写整片配置，未列出的项保持原值。'))
            . '</p>'
            . '<form class="nl-form" method="post" action="' . self::esc($url) . '">'
            . '<input type="hidden" name="_" value="' . self::esc($token) . '">'
            . '<input type="hidden" name="action" value="save_theme">'
            . '<table class="nl-table"><tbody>'
            . Theme::formHtml($values)
            . '</tbody></table>'
            . '<p class="nl-actions"><button type="submit" class="btn btn-l primary"'
            . ($editable ? '' : ' disabled') . '>' . self::esc(_t('保存主题设置')) . '</button> '
            . '<span class="nl-note">' . self::esc(_t('含 HTML 的两项（首页公告、底部版权）按原样保存，不清洗标签。'))
            . '</span></p>'
            . '</form>';

        return $html;
    }

    private static function apiSection(Options $options): string
    {
        $keySet = Plugin::setting('apiKey', '') !== '';
        $write = Plugin::setting('themeWrite', '1') === '1';
        $base = rtrim((string)$options->siteUrl, '/');

        return '<h3 class="nl-h3">' . self::esc(_t('接口')) . '</h3>'
            . '<div class="nl-chips">'
            . self::chip($keySet ? 'ok' : 'bad', _t('接口密钥') . ($keySet ? _t('已设置') : _t('未设置')))
            . self::chip($write ? 'ok' : 'warn', _t('接口写主题设置') . ($write ? _t('已允许') : _t('已禁止')))
            . '</div>'
            . '<p class="nl-lead"><code>GET ' . self::esc($base . '/api/theme') . '</code> '
            . self::esc(_t('读全部配置；')) . '<code>PUT ' . self::esc($base . '/api/theme') . '</code> '
            . self::esc(_t('写配置，body 只带要改的键。两边都要请求头 X-Noline-Key。'))
            . '</p>'
            . '<p class="nl-note">'
            . self::esc(_t('开关在插件设置页里。拿到密钥就等于能改整站门面，接口文档里已经把这条写清。'))
            . '</p>';
    }

    private static function chip(string $level, string $text): string
    {
        return '<span class="nl-chip nl-chip-' . $level . '">' . self::esc($text) . '</span>';
    }

    /**
     * 后台挂钩自检。
     *
     * 皮肤、深色开关、图床面板三条命脉全挂在 admin/header.php:header 上；它要没登记上，
     * 页面只会退回核心原样、一句错都不报——「面板怎么没出来」光看后台是猜不出来的，
     * 所以把「当前代码声明的钩子」和「数据库里登记的钩子」并排列在这儿对。
     */
    private static function hookSection(): string
    {
        $registered = Plugin::registeredHooks();
        $chips = array();

        foreach (Plugin::hooks() as $hook) {
            $key = $hook[0] . ':' . $hook[1];
            $on = in_array($key, $registered, true);
            $chips[] = self::chip($on ? 'ok' : 'bad', $key . ' ' . ($on ? _t('已登记') : _t('未登记')));
        }

        $skin = self::skinOn();
        $chips[] = self::chip($skin ? 'ok' : 'muted', _t('后台皮肤') . ($skin ? _t('开') : _t('关')));

        $ready = Cloudreve::ready();
        $chips[] = self::chip($ready ? 'ok' : 'warn', _t('图床设置') . ($ready ? _t('已填齐') : _t('没填齐')));

        return '<h3 class="nl-h3">' . self::esc(_t('后台挂钩自检')) . '</h3>'
            . '<div class="nl-chips">' . implode('', $chips) . '</div>'
            . '<p class="nl-lead">'
            . self::esc(_t('后台皮肤、顶栏的深色开关、写文章页的图床面板都走 '))
            . '<code>admin/header.php:header</code>'
            . self::esc(_t(' 这一条钩子；接口和主题管理页各走自己那条。上面只要有一条显示「未登记」，'
                . '去「控制台 → 插件」把 NolineApi 停用再启用一次就补齐了。'))
            . '</p>'
            . '<p class="nl-note">'
            . self::esc(_t('在线更新换的是文件，换不掉库里那份登记——插件自己会在下一次请求比对并重写这张表，'
                . '等不及就手动停用启用一遍。面板长什么样不用猜：进「写文章」切到「附件」标签，最上面那块就是；'
                . '设置没填齐时它是灰的一行，写明缺了哪一项。'))
            . '</p>';
    }

    private static function scripts(): string
    {
        return '<script>(function(){'
            /* 强调色色块：边打字边染，写成非法颜色就切成斜纹，省得保存完才去前台发现颜色没变 */
            . 'function paint(){document.querySelectorAll(".nl-swatch").forEach(function(el){'
            . 'var i=document.getElementById(el.getAttribute("data-for"));var v=i?i.value.trim():"";'
            . 'if(!v){el.style.background="";el.className="nl-swatch";return;}'
            . 'if(v.charAt(0)!=="#"){v="#"+v;}'
            . 'if(!/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v)){el.style.background="";el.className="nl-swatch bad";return;}'
            . 'el.style.background=v;el.className="nl-swatch";});}'
            . 'document.addEventListener("input",paint);paint();'
            /* 危险动作的确认写在 data-ask 里，不写进 onsubmit：
               文案随时会带引号或换行，拼进 HTML 属性早晚会把脚本截断。 */
            . 'document.addEventListener("submit",function(e){'
            . 'var f=e.target.closest?e.target.closest("form[data-ask]"):null;'
            . 'if(!f){return;}'
            . 'if(!window.confirm(f.getAttribute("data-ask"))){e.preventDefault();return;}'
            /* 升级要跑下载+解压+两次 rename，中途再点一次就是并发搬同一个目录 */
            . 'f.querySelectorAll("button").forEach(function(b){b.disabled=true;});});})();</script>';
    }
}
