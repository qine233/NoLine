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
     */
    public static function menuLink()
    {
        echo '<a href="' . self::esc(self::pageUrl()) . '">' . self::esc(_t('主题管理')) . '</a> &nbsp;';
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

        // 上次升级要是停在「旧主题已挪走、新的还没顶上」，先把主题目录搬回来再谈别的
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
                    return Cloudreve::act($action);
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
            . '<style>' . self::styles() . '</style>'
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
        echo Updater::section($token, $url);
        echo Cloudreve::section($token, $url);

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
            . self::esc(_t('写配置，body 只带要改的键。两边都要请求头 X-NoLine-Key。'))
            . '</p>'
            . '<p class="nl-note">'
            . self::esc(_t('开关在插件设置页里。拿到密钥就等于能改整站门面，接口文档里已经把这条写清。'))
            . '</p>';
    }

    private static function chip(string $level, string $text): string
    {
        return '<span class="nl-chip nl-chip-' . $level . '">' . self::esc($text) . '</span>';
    }

    private static function styles(): string
    {
        return '.nl-bar{margin:0 0 1rem;padding:.6rem .8rem;background:#fff;border:1px solid #e3e3e3;'
            . 'border-radius:4px;display:flex;align-items:center;gap:.4rem}'
            . '.nl-bar .nl-site{margin-left:auto;color:#777;font-size:.92em}'
            . '.nl-h3{margin:1.6rem 0 .4rem;font-size:1.15em}'
            . '.nl-lead{margin:0 0 .8rem;color:#666;line-height:1.7}'
            . '.nl-msg{margin:0 0 1rem;padding:.7rem .9rem;border-radius:4px;line-height:1.7}'
            . '.nl-msg-success{background:#eaf6ec;border:1px solid #b7dfc0;color:#2c6e3a}'
            . '.nl-msg-error{background:#fdeeee;border:1px solid #f0bcbc;color:#a3342f}'
            . '.nl-msg-warn{background:#fdf6e3;border:1px solid #e8d9a6;color:#8a6d1f}'
            . '.nl-form{background:#fff;border:1px solid #e3e3e3;border-radius:4px;padding:0 .8rem .8rem}'
            . '.nl-table{width:100%;border-collapse:collapse}'
            . '.nl-table td{padding:.8rem 0;border-bottom:1px solid #f0f0f0;vertical-align:top}'
            . '.nl-table tr:last-child td{border-bottom:none}'
            . '.nl-key{width:11rem;padding-right:1rem}'
            . '.nl-key label{display:block;font-weight:700;line-height:1.6;cursor:pointer}'
            . '.nl-key code{display:block;margin-top:.2rem;font-size:.82em;color:#999;font-weight:400}'
            . '.nl-val input[type=text],.nl-val textarea{width:100%;max-width:34rem;line-height:1.6}'
            . '.nl-val textarea{font-family:Menlo,Consolas,monospace;font-size:.9em}'
            . '.nl-hint{margin:.3rem 0 0;color:#888;font-size:.86em;line-height:1.7}'
            . '.nl-switch{display:inline-flex;align-items:center;gap:.3rem}'
            . '.nl-swatch{display:inline-block;width:1.4rem;height:1.4rem;border-radius:50%;'
            . 'border:1px solid rgba(0,0,0,.15);vertical-align:-.35rem;background:#4482e5}'
            . '.nl-swatch.bad{background:repeating-linear-gradient(45deg,#eee,#eee 4px,#fff 4px,#fff 8px)}'
            . '.nl-actions{margin:1rem 0 0;display:flex;align-items:center;gap:.8rem;flex-wrap:wrap}'
            . '.nl-note{color:#888;font-size:.86em;line-height:1.7}'
            . '.nl-chips{display:flex;gap:.4rem;flex-wrap:wrap;margin:0 0 .6rem}'
            . '.nl-chip{padding:.2rem .6rem;border-radius:999px;font-size:.86em;border:1px solid #e3e3e3;background:#fff}'
            . '.nl-chip-ok{border-color:#b7dfc0;background:#eaf6ec;color:#2c6e3a}'
            . '.nl-chip-warn{border-color:#e8d9a6;background:#fdf6e3;color:#8a6d1f}'
            . '.nl-chip-bad{border-color:#f0bcbc;background:#fdeeee;color:#a3342f}'
            . '.nl-chip-muted{border-color:#e3e3e3;background:#f7f7f7;color:#666}'
            . '.nl-ver{display:flex;align-items:center;gap:1rem;margin:0 0 1rem;padding:.9rem 1rem;'
            . 'background:#fff;border:1px solid #e3e3e3;border-radius:4px}'
            . '.nl-ver b{display:block;font-size:1.3em;line-height:1.3;word-break:break-all}'
            . '.nl-ver em{display:block;margin-top:.2rem;color:#888;font-size:.86em;font-style:normal}'
            . '.nl-ver-arrow{color:#bbb;font-size:1.3em}'
            . '.nl-inline{margin:0 0 1rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}'
            . '.nl-log{margin:0 0 1rem;background:#fff;border:1px solid #e3e3e3;border-radius:4px;padding:.6rem .9rem}'
            . '.nl-log summary{cursor:pointer;color:#666}'
            . '.nl-log pre{margin:.7rem 0 0;padding:.7rem .9rem;background:#f7f7f7;border-radius:4px;'
            . 'white-space:pre-wrap;word-break:break-word;font-size:.9em;line-height:1.7;'
            . 'font-family:Menlo,Consolas,monospace}'
            . '.nl-h4{margin:1.2rem 0 .4rem;font-size:1em;color:#444}'
            . '.nl-list{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e3e3e3;border-radius:4px}'
            . '.nl-list td{padding:.6rem .8rem;border-bottom:1px solid #f0f0f0;vertical-align:middle}'
            . '.nl-list tr:last-child td{border-bottom:none}'
            . '.nl-list b{display:block;word-break:break-all}'
            . '.nl-list em{display:block;margin-top:.15rem;color:#888;font-size:.86em;font-style:normal}'
            . '.nl-ops{text-align:right;white-space:nowrap}'
            . '.nl-ops form{display:inline-block;margin:0}'
            . '@media (max-width:757px){.nl-key{width:auto;display:block;padding-right:0}'
            . '.nl-table td{display:block;padding:.4rem 0}'
            . '.nl-ver{flex-direction:column;align-items:flex-start;gap:.4rem}'
            . '.nl-ver-arrow{display:none}'
            . '.nl-ops{text-align:left;white-space:normal}}';
    }

    private static function scripts(): string
    {
        return '<script>(function(){'
            /* 强调色色块：边打字边染，写成非法颜色就切成斜纹，省得保存完才去前台发现颜色没变 */
            . 'function paint(){document.querySelectorAll(".nl-swatch").forEach(function(el){'
            . 'var i=document.getElementById(el.getAttribute("data-for"));var v=i?i.value.trim():"";'
            . 'if(!v){el.style.background="#4482e5";el.className="nl-swatch";return;}'
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
