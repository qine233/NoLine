<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 主题版本号。同时用作静态资源的缓存指纹（?v=），改版本即可强制刷新客户端缓存。
 */
define('NOLINE_VERSION', '1.6.0');

/* ==========================================================================
   后台设置
   ========================================================================== */

/**
 * 字段名沿用旧版本的 key（gtihubname / BliBliname 的拼写错误也保留），
 * 这样已经保存过设置的站点升级主题后不会丢数据。只修正显示文案。
 */
function themeConfig($form)
{
    $logoCss = new Typecho_Widget_Helper_Form_Element_Text('logoCss', NULL, NULL,
        _t('站点头像地址'),
        _t('填入一个图片 URL，用作顶栏头像、首页 masthead 头像与站点 favicon。留空时文章卡片头像回退到作者 Gravatar。'));
    $form->addInput($logoCss);

    $logoName = new Typecho_Widget_Helper_Form_Element_Text('logoName', NULL, NULL,
        _t('博主名字'),
        _t('显示在顶栏品牌和首页 masthead 上的名字。留空则回退到站点标题。'));
    $form->addInput($logoName);

    $logobg = new Typecho_Widget_Helper_Form_Element_Text('logobg', NULL, NULL,
        _t('座右铭'),
        _t('显示在首页 masthead 站名下方，最多两行，超出部分截断。留空则回退到站点描述。'));
    $form->addInput($logobg);

    $logobgcolor = new Typecho_Widget_Helper_Form_Element_Text('logobgcolor', NULL, NULL,
        _t('首页头图'),
        _t('填入头图链接，铺在首页 masthead 当封面，上面会自动压一层渐变罩保证文字读得清。建议使用外链图床节省服务器带宽。留空则不出封面。'));
    $form->addInput($logobgcolor);

    // Typecho 1.2 没有颜色选择器元件（只有 Text/Textarea/Radio/Select/Checkbox/Hidden），
    // 后台会把 type="color" 渲染成 type="text"，所以这里只能用文本框。
    $accentColor = new Typecho_Widget_Helper_Form_Element_Text('accentColor', NULL, NULL,
        _t('强调色'),
        _t('链接、按钮、hover、标签底色、代码块圆点光环都用它。填 6 位或 3 位十六进制，例如 #4482e5。留空或格式不对时回退到默认靛蓝。深色模式会自动按这个颜色算一个更亮的变体。'));
    $form->addInput($accentColor);

    // 默认值是数据，不是文案，所以不走 _t()：被翻译之后就匹配不上用户真实的分类名了。
    $diaryCategory = new Typecho_Widget_Helper_Form_Element_Text('diaryCategory', NULL, '日记',
        _t('动态分类'),
        _t('命中这个分类的文章不当文章处理：在首页和分类页直接渲染成微博式动态卡，不显示标题，正文原地铺开，不用点进详情页就能看完；归档时间线里也只写分类名、不暴露真标题。缩略名（slug）和分类名都认，多个用逗号分隔。留空则关闭，所有文章都按普通文章卡渲染。'));
    $form->addInput($diaryCategory);

    $navIcons = new Typecho_Widget_Helper_Form_Element_Text('navIcons', NULL, '',
        _t('导航图标'),
        _t('给顶栏导航里的独立页面指定图标，格式 slug:图标名，多个用逗号分隔，例如 yanzhi:code,photos:image。页面的 slug 在「管理 → 页面」里能看到。留空则按内置规则猜：先认 about / links / archives 这类常见 slug，再按标题里的「关于 / 友链 / 归档 / 留言 / 相册 / 读书 / 主题」等词匹配，都猜不中用文件图标。可用图标名：house box-archive file-lines comments link circle-info folder tag code image book-open clock eye comment bars magnifying-glass sun moon github bilibili。写错名字的那一条会被跳过。'));
    $form->addInput($navIcons);

    $notice = new Typecho_Widget_Helper_Form_Element_Textarea('notice', NULL, NULL,
        _t('首页公告'),
        _t('显示在首页内容区顶部的公告卡里，留空则整张卡不出现。支持 HTML。'));
    $form->addInput($notice);

    $gtihubname = new Typecho_Widget_Helper_Form_Element_Text('gtihubname', NULL, _t('GitHub'),
        _t('GitHub 显示名'),
        _t('写在顶栏 GitHub 按钮上，配了之后按钮会从方块长成药丸。窄屏收成纯图标，名字只在气泡和读屏标签里出现。留空则始终只显示图标。'));
    $form->addInput($gtihubname);

    $gtihubsite = new Typecho_Widget_Helper_Form_Element_Text('gtihubsite', NULL, _t('https://github.com/'),
        _t('GitHub 链接'),
        _t('填入你的 GitHub 主页链接。留空则不显示该图标。'));
    $form->addInput($gtihubsite);

    $BliBliname = new Typecho_Widget_Helper_Form_Element_Text('BliBliname', NULL, _t('哔哩哔哩'),
        _t('Bilibili 显示名'),
        _t('写在顶栏 Bilibili 按钮上，配了之后按钮会从方块长成药丸。窄屏收成纯图标，名字只在气泡和读屏标签里出现。留空则始终只显示图标。'));
    $form->addInput($BliBliname);

    $BliBlisite = new Typecho_Widget_Helper_Form_Element_Text('BliBlisite', NULL, NULL,
        _t('Bilibili 链接'),
        _t('填入你的 Bilibili 主页链接。留空则不显示该图标。'));
    $form->addInput($BliBlisite);

    $logoFooter = new Typecho_Widget_Helper_Form_Element_Textarea('logoFooter', NULL, NULL,
        _t('站点底部版权'),
        _t('显示在右栏"站点信息"卡片底部，可填入 ICP 备案号等。支持 HTML，例如 &lt;a href="https://beian.miit.gov.cn/"&gt;京ICP备00000000号&lt;/a&gt;'));
    $form->addInput($logoFooter);
}

/* ==========================================================================
   文章浏览量
   --------------------------------------------------------------------------
   计数在 themeInit() 里完成 —— Typecho 在渲染任何模板之前调用它，
   所以 setcookie() 不会撞上 "headers already sent"。
   计数文件放在 usr/uploads/noline_views/，不在主题目录内，升级主题不会清零。
   ========================================================================== */

/**
 * Typecho 在渲染模板前调用，此时还没有任何输出。
 */
function themeInit($archive)
{
    if (!$archive->is('single') || !$archive->is('post')) {
        return;
    }

    $cid = (int)$archive->cid;
    $cookie = 'noline_viewed_' . $cid;

    // 同一访客 24 小时内只计一次。取不到 cookie 就不计数，避免刷新灌水。
    if (isset($_COOKIE[$cookie]) || headers_sent()) {
        return;
    }

    noline_bump_views($cid);
    setcookie($cookie, '1', time() + 86400, '/');
}

/**
 * 计数文件目录。不可写时返回 null，此时浏览量显示为 0 而不是抛警告。
 */
function noline_views_dir()
{
    static $dir = false;

    if ($dir === false) {
        $path = __TYPECHO_ROOT_DIR__ . '/usr/uploads/noline_views';
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $dir = (is_dir($path) && is_writable($path)) ? $path : null;
    }

    return $dir;
}

function noline_views_path($cid)
{
    $dir = noline_views_dir();
    return $dir === null ? null : $dir . '/' . (int)$cid . '.txt';
}

/**
 * 旧版本把计数写在主题目录的 views/ 下，首次读取时搬过来，避免历史数据丢失。
 */
function noline_migrate_legacy_view($cid, $path)
{
    $legacy = __DIR__ . '/views/' . (int)$cid . '.txt';
    if (is_file($legacy)) {
        @copy($legacy, $path);
    }
}

/**
 * 只读地取浏览量，供列表页使用（列表页不应该增加计数）。
 */
function noline_get_views($cid)
{
    $path = noline_views_path($cid);
    if ($path === null) {
        return 0;
    }

    if (!is_file($path)) {
        noline_migrate_legacy_view($cid, $path);
    }

    return is_file($path) ? (int)file_get_contents($path) : 0;
}

/**
 * 浏览量 +1。用 LOCK_EX 独占锁，避免并发请求互相覆盖导致丢计数。
 */
function noline_bump_views($cid)
{
    $path = noline_views_path($cid);
    if ($path === null) {
        return;
    }

    if (!is_file($path)) {
        noline_migrate_legacy_view($cid, $path);
    }

    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return;
    }

    if (flock($fp, LOCK_EX)) {
        $views = (int)stream_get_contents($fp) + 1;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)$views);
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);
}

/* ==========================================================================
   头像
   ========================================================================== */

/**
 * QQ 邮箱直接取 QQ 头像，其余走 Gravatar 镜像。
 *
 * 注意：cdn.sep.cc 是第三方 Gravatar 镜像；q2.qlogo.cn 会把评论者的 QQ 号发给腾讯。
 * 介意的话把下面两个域名换成自建代理。
 */
function noline_gravatar($email, $size = 96, $default = 'mp', $rating = 'g')
{
    $email = strtolower(trim((string)$email));

    if (preg_match('/^(\d+)@qq\.com$/', $email, $match)) {
        return 'https://q2.qlogo.cn/headimg_dl?dst_uin=' . $match[1] . '&spec=100';
    }

    return 'https://cdn.sep.cc/avatar/' . md5($email) . "?s={$size}&d={$default}&r={$rating}";
}

/**
 * 文章作者头像：优先用后台配置的站点头像，未配置时回退到作者 Gravatar。
 * 返回值已做 HTML 转义，可直接放进 src="" 里。
 */
function noline_author_avatar($archive, $size = 100)
{
    $options = Typecho_Widget::widget('Widget_Options');
    $logo = trim((string)$options->logoCss);

    if ($logo !== '') {
        return htmlspecialchars($logo, ENT_QUOTES, 'UTF-8');
    }

    // 不能用 isset($archive->author)：Widget::__isSet() 只查 $row，不会去调 ___author()，
    // 那样判断永远是 false，头像会静默退化成 md5('') 的匿名 Gravatar。
    $mail = '';
    $author = $archive->author;
    if ($author !== null && !empty($author->mail)) {
        $mail = (string)$author->mail;
    }

    return htmlspecialchars(noline_gravatar($mail, $size), ENT_QUOTES, 'UTF-8');
}

/**
 * 侧边栏资料卡头像。后台没填 logoCss 时返回 null，
 * 由 sidebar.php 渲染一个名字首字母占位块——比一张来路不明的 Gravatar 默认头像更诚实。
 * 返回值已做 HTML 转义，可直接放进 src="" 里。
 */
function noline_profile_avatar()
{
    $logo = trim((string)Typecho_Widget::widget('Widget_Options')->logoCss);

    return $logo === '' ? null : htmlspecialchars($logo, ENT_QUOTES, 'UTF-8');
}

/**
 * 名字占位头像用的单个字符。中文取首字，英文取首字母大写。
 */
function noline_initial($name)
{
    $name = trim((string)$name);

    if ($name === '') {
        return '?';
    }

    // 中文等多字节字符不能用 substr，会切出半个字
    preg_match('/./u', $name, $match);

    return function_exists('mb_strtoupper') ? mb_strtoupper($match[0], 'UTF-8') : strtoupper($match[0]);
}

/* ==========================================================================
   强调色
   --------------------------------------------------------------------------
   design.css 里 --accent 是通过 var(--accent-user, #4482e5) 间接取值的。
   这里只输出 --accent-user*，绝不能直接写 --accent：内嵌样式的优先级高于样式表，
   一旦占了 --accent 这个名字，:root[data-theme="dark"] 就再也盖不住它，
   深色模式会一直用浅色那套强调色。
   ========================================================================== */

/**
 * 解析 #rgb / #rrggbb，返回 array(r, g, b)。格式不对返回 null。
 */
function noline_parse_hex($value)
{
    $value = trim((string)$value);

    if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value, $match)) {
        return null;
    }

    $hex = $match[1];
    if (strlen($hex) === 3) {
        // #abc 要先展开成 aabbcc，按两位直接截取会把 'ab' 当成一个通道
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    return array(
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    );
}

/**
 * WCAG 相对亮度，0（黑）到 1（白）。
 * 用来判断用户选的颜色本身够不够亮：够亮就不再提亮，
 * 否则深色模式下填个黄色会得到接近白色的链接。
 */
function noline_luminance($rgb)
{
    $weights = array(0.2126, 0.7152, 0.0722);
    $sum = 0.0;

    foreach ($rgb as $index => $channel) {
        $c = $channel / 255;
        $c = ($c <= 0.03928) ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        $sum += $weights[$index] * $c;
    }

    return $sum;
}

/**
 * 把每个通道按 ratio 往 $target（0 或 255）方向推。
 */
function noline_mix($rgb, $target, $ratio)
{
    $out = array();

    foreach ($rgb as $channel) {
        $out[] = max(0, min(255, (int)round($channel + ($target - $channel) * $ratio)));
    }

    return $out;
}

/**
 * 输出后台强调色对应的 CSS 变量。没配置过就什么都不输出，样式表自己回落到默认靛蓝。
 */
function noline_accent_style()
{
    $options = Typecho_Widget::widget('Widget_Options');

    // 主题选项是整块序列化存的，新字段在用户去后台保存一次之前根本不存在。
    // 直接读会撞上 __get()，用 isset() 探一下最稳。
    $configured = isset($options->accentColor) ? $options->accentColor : null;
    $rgb = noline_parse_hex($configured);

    if ($rgb === null) {
        return;
    }

    $lightHover = noline_mix($rgb, 0, 0.18);
    $dark = noline_luminance($rgb) > 0.5 ? $rgb : noline_mix($rgb, 255, 0.30);
    $darkHover = noline_mix($dark, 255, 0.12);

    echo '<style id="noline-accent">:root{'
        . '--accent-user:' . vsprintf('#%02x%02x%02x', $rgb) . ';'
        . '--accent-user-rgb:' . implode(', ', $rgb) . ';'
        . '--accent-user-hover:' . vsprintf('#%02x%02x%02x', $lightHover) . ';'
        . '--accent-user-dark:' . vsprintf('#%02x%02x%02x', $dark) . ';'
        . '--accent-user-dark-rgb:' . implode(', ', $dark) . ';'
        . '--accent-user-dark-hover:' . vsprintf('#%02x%02x%02x', $darkHover)
        . '}</style>' . "\n";
}

/* ==========================================================================
   动态分类（日记）
   --------------------------------------------------------------------------
   命中这个分类的文章不当文章处理：首页与分类页改用 public/status-card.php
   渲染成微博式动态卡，不出标题，正文原地铺开。
   ========================================================================== */

/**
 * 后台配置的动态分类关键字。缩略名和分类名都收，省得用户去后台查 slug。
 * 返回空数组表示这个功能关掉了。
 */
function noline_diary_keys()
{
    static $keys = false;

    if ($keys !== false) {
        return $keys;
    }

    $options = Typecho_Widget::widget('Widget_Options');

    // 主题选项是整块序列化存的，新字段在用户去后台保存一次之前根本不存在。
    // null 和空串必须分开对待：null 是从没配过，回退默认值；空串是用户主动清空，
    // 那就该真的关掉，不能又给他填回「日记」。
    $raw = isset($options->diaryCategory) ? $options->diaryCategory : null;

    if ($raw === null) {
        $raw = '日记';
    }

    $keys = array();

    foreach (preg_split('/[,，]/', (string)$raw) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $keys[] = $part;
        }
    }

    return $keys;
}

/**
 * 这篇文章命中的动态分类，返回它的显示名；没命中返回 null。
 *
 * 之所以不只返回布尔：dcument.php 的归档时间线要拿这个名字当占位标题
 * （动态不暴露真标题），两边必须共用同一个判定来源，否则后台改了分类名，
 * 时间线还按老常量在藏。
 *
 * $post->categories 不是主题造出来的，是 Widget_Abstract_Contents::filter()
 * 在 push() 之前查关系表塞进 row 的分类行数组（Typecho 1.2.1
 * var/Widget/Base/Contents.php:497），每行都带 slug / name / permalink。
 * 一篇文章可以挂多个分类，任意一个命中就算动态。
 */
function noline_diary_category($post)
{
    $keys = noline_diary_keys();

    if ($keys === array() || empty($post->categories) || !is_array($post->categories)) {
        return null;
    }

    foreach ($post->categories as $category) {
        if ((isset($category['slug']) && in_array($category['slug'], $keys, true))
            || (isset($category['name']) && in_array($category['name'], $keys, true))) {
            return isset($category['name']) ? (string)$category['name'] : '';
        }
    }

    return null;
}

/**
 * 这篇文章算不算动态。
 */
function noline_is_diary($post)
{
    return noline_diary_category($post) !== null;
}

/* ==========================================================================
   杂项
   ========================================================================== */

/**
 * 相对时间，用在"说说"这类时间线页面上。
 */
function noline_time_ago($timestamp)
{
    $seconds = time() - (int)$timestamp;

    if ($seconds < 0) {
        return '刚刚';
    }

    $steps = array(
        array(31553280, '年前', 1),
        array(2629440, '月前', 1),
        array(604800, '周前', 1),
        array(86400, '天前', 1),
        array(3600, '小时前', 1),
        array(60, '分前', 1),
    );

    foreach ($steps as $step) {
        list($span, $label) = $step;
        if ($seconds >= $span) {
            return round($seconds / $span) . $label;
        }
    }

    return '刚刚';
}

/* ==========================================================================
   标题开头的 emoji
   --------------------------------------------------------------------------
   后台把页面标题写成「🧰百宝箱」，那个 emoji 就直接当图标用——换图标既不用改
   主题设置也不用碰代码。标题里没有开头 emoji 时，才回落到 noline_nav_icon()。

   只认开头：标题中间的 emoji 是内容的一部分，不是图标。
   ========================================================================== */

/**
 * 标题开头的 emoji（连带跟在后面的变体选择符 U+FE0F 和零宽连接符 U+200D）。
 * 没有就返回 null。
 *
 * 区段挑的是「只可能是 emoji」的范围：1F000–1FAFF 是表情与补充图形，
 * 2190–2BFF 是箭头、杂项符号和装饰符号。中文（4E00–9FFF）和拉丁字母都不在里面，
 * 所以正常标题不会被误判。
 */
function noline_leading_emoji($text)
{
    $text = trim((string)$text);

    if ($text === '') {
        return null;
    }

    $pattern = '/^(?:[\x{1F000}-\x{1FAFF}\x{2190}-\x{2BFF}][\x{200D}\x{FE0F}]*)+/u';

    return preg_match($pattern, $text, $match) ? $match[0] : null;
}

/**
 * 去掉开头那个当图标用的 emoji，剩下的才是要显示的文字。
 * 不去掉的话同一个 emoji 会在图标位和文字位各出现一次。
 */
function noline_strip_emoji($text)
{
    $text = trim((string)$text);
    $emoji = noline_leading_emoji($text);

    /* substr/strlen 都按字节走，两边一致就不会切出半个多字节字符 */
    return $emoji === null ? $text : trim(substr($text, strlen($emoji)));
}

/**
 * 给导航项挑一个图标，四级取值：
 *   1. 后台「导航图标」字段的手动映射（slug:图标名）；
 *   2. 内置 slug 表（about / links / archives 这类约定俗成的命名）；
 *   3. 标题关键词（中文站点的页面标题比 slug 稳定得多）；
 *   4. file-lines 兜底——总比某一项空着好看。
 */
function noline_nav_icon($page)
{
    static $icons = null;
    static $slugMap = array(
        'index'    => 'house',
        'home'     => 'house',
        'archive'  => 'box-archive',
        'archives' => 'box-archive',
        'dcument'  => 'box-archive',
        'talk'     => 'comments',
        'shuoshuo' => 'comments',
        'links'    => 'link',
        'flink'    => 'link',
        'friend'   => 'link',
        'about'    => 'circle-info',
        'guanyu'   => 'circle-info',
    );

    /* 标题里出现这些词就认。中文站点的独立页面标题比 slug 稳定得多。 */
    static $titleMap = array(
        'circle-info' => array('关于', '自述', '简介', '自我介绍'),
        'link'        => array('友链', '友情链接', '链接', '邻居'),
        'box-archive' => array('归档', '时间线', '档案', '历程'),
        'comments'    => array('留言', '说说', '碎语', '闲聊', '弹幕'),
        'image'       => array('相册', '摄影', '照片', '图片', '图集'),
        'book-open'   => array('读书', '书评', '阅读', '书单'),
        'code'        => array('主题', '模板', '研制', '开发', '代码', '实验室'),
        'tag'         => array('标签'),
        'folder'      => array('分类', '目录'),
    );

    if (is_object($page)) {
        $slug  = strtolower(trim((string)$page->slug));
        $title = trim((string)$page->title);
    } else {
        $slug  = strtolower(trim((string)$page));
        $title = '';
    }

    if ($icons === null) {
        $icons = require __DIR__ . '/public/icons.php';
    }

    $options = Typecho_Widget::widget('Widget_Options');
    $custom  = isset($options->navIcons) ? (string)$options->navIcons : '';
    foreach (preg_split('/[,，]/', $custom) as $pair) {
        $parts = explode(':', $pair);
        if (count($parts) !== 2) {
            continue;
        }
        $pairSlug = strtolower(trim($parts[0]));
        $pairIcon = trim($parts[1]);
        /* 写错图标名就跳过这一条：noline_icon() 对不存在的名字是静默返回，
           不验的话用户只会看到导航上凭空少一个图标，查都查不出来。 */
        if ($pairSlug !== '' && $pairSlug === $slug && isset($icons[$pairIcon])) {
            return $pairIcon;
        }
    }

    if (isset($slugMap[$slug])) {
        return $slugMap[$slug];
    }

    if ($title !== '') {
        foreach ($titleMap as $icon => $words) {
            foreach ($words as $word) {
                if (strpos($title, $word) !== false) {
                    return $icon;
                }
            }
        }
    }

    return 'file-lines';
}

/**
 * 输出导航项的图标位：标题开头有 emoji 就用 emoji，否则回落到内联 SVG。
 *
 * 两个分支都自带 aria-hidden——图标位纯装饰，页面名字由后面的 .nav-label 读出来，
 * 不能让读屏器把同一个词念两遍。
 */
function noline_nav_mark($page)
{
    $title = is_object($page) ? (string)$page->title : '';
    $emoji = noline_leading_emoji($title);

    if ($emoji !== null) {
        echo '<span class="noline-emoji" aria-hidden="true">' . $emoji . '</span>';
        return;
    }

    noline_icon(noline_nav_icon($page));
}

/**
 * 导航项要显示的文字。emoji 已经挪去图标位了，这里必须去掉，否则重复。
 */
function noline_nav_label($page)
{
    $title = is_object($page) ? (string)$page->title : '';

    echo htmlspecialchars(noline_strip_emoji($title), ENT_QUOTES, 'UTF-8');
}

/**
 * 分类行的单程缓存。
 *
 * Typecho 的 widget() 只按类名缓存实例（参数不参与），Rows 又没有 rewind()，
 * 所以 Widget_Metas_Category_List 全站只能遍历一次，第二遍的 while 直接空转。
 * 顶栏的分类下拉先跑，顺手把行存进来，右栏的分类卡再从这里读——两处同一份数据。
 *
 * 依赖「header.php 一定先于 sidebar.php 渲染」，这条由 _dev/structure.py 的骨架检查兜住。
 *
 * @param array|null $row 传一行就存一行；不传则返回已存的全部行
 * @return array
 */
function noline_categories($row = null)
{
    static $rows = array();

    if ($row !== null) {
        $rows[] = $row;
    }

    return $rows;
}

/**
 * 输出一个内联 SVG 图标。
 * 替代原先从 cdnjs 加载的 FontAwesome（约 100KB CSS + 300KB webfont）。
 *
 * 可用名称见 public/icons.php：
 *   导航  house box-archive file-lines comments link circle-info folder
 *   界面  bars magnifying-glass sun moon clock eye comment tag
 *   品牌  github bilibili
 */
function noline_icon($name, $class = '')
{
    static $icons = null;

    if ($icons === null) {
        $icons = require __DIR__ . '/public/icons.php';
    }

    if (!isset($icons[$name])) {
        return;
    }

    list($viewBox, $path) = $icons[$name];
    $classes = $class === '' ? 'noline-icon' : 'noline-icon ' . $class;

    echo '<svg class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8')
        . '" viewBox="' . $viewBox . '" fill="currentColor"'
        . ' aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>';
}

/* ==========================================================================
   卡片上的最近评论
   --------------------------------------------------------------------------
   列表卡片原本只有一个「评论 N」，读者看不到里面在聊什么，也就少了点进去的理由。
   这里把最新几条已审核评论的头像和一句话摘要摆到卡片底部。

   代价是每张卡多一次查询（有回复的再多一次批量补作者名）。按 cid 做了请求内缓存，
   「回复给谁」的作者名还跨卡片共享，同一批人反复出现时不会重复查。
   ========================================================================== */

/**
 * 取一篇文章最新几条已审核评论，新→旧。
 *
 * 只认 type = 'comment'：pingback 既没有作者头像也没有正文，摆上去就是一排空壳。
 */
function noline_recent_comments($cid, $limit = 3)
{
    static $cache = array();

    $cid   = (int)$cid;
    $limit = max(1, (int)$limit);

    /* 键里带上 limit：同一篇文章在不同模板里可能取不同条数，
       只按 cid 缓存会让后调用的那次拿到条数不对的结果。 */
    $key = $cid . ':' . $limit;

    if (!isset($cache[$key])) {
        $db = Typecho_Db::get();

        /* select() 不带字段列表：Typecho 自己的 widget 一律这么写。
           反正只有 limit 条，多带几个列的开销可以忽略，换来的是不依赖一个
           在本机无从验证的调用形式。 */
        $cache[$key] = $db->fetchAll(
            $db->select()
                ->from('table.comments')
                ->where('cid = ?', $cid)
                ->where('status = ?', 'approved')
                ->where('type = ?', 'comment')
                ->order('coid', Typecho_Db::SORT_DESC)
                ->limit($limit)
        );
    }

    return $cache[$key];
}

/**
 * 批量查「回复给谁」的作者名，返回 coid => 作者名。
 *
 * 结果存在静态缓存里跨卡片共享，所以一整页下来这里通常只跑一两次查询，
 * 而不是每条评论一次。
 */
function noline_parent_authors(array $coids)
{
    static $known = array();

    $missing = array();
    foreach ($coids as $coid) {
        $coid = (int)$coid;
        if ($coid > 0 && !isset($known[$coid])) {
            $missing[] = $coid;
        }
    }

    if (!empty($missing)) {
        $db = Typecho_Db::get();

        /* 不用 where('coid IN ?', $array)：Typecho 各版本对数组绑定的处理不一致。
           自己拼是安全的——每个值都过了 intval，拼出来只能是数字和逗号。 */
        $ids  = implode(',', $missing);
        $rows = $db->fetchAll($db->select()->from('table.comments')->where("coid IN ({$ids})"));

        foreach ($rows as $row) {
            $known[(int)$row['coid']] = trim((string)$row['author']);
        }
        /* 查不到的也要落一个空值。被删掉的父评论永远查不出来，
           不记一笔的话后面每张卡片都会为它再跑一次 IN 查询。 */
        foreach ($missing as $coid) {
            if (!isset($known[$coid])) {
                $known[$coid] = '';
            }
        }
    }

    $found = array();
    foreach ($coids as $coid) {
        $coid = (int)$coid;
        if (isset($known[$coid]) && $known[$coid] !== '') {
            $found[$coid] = $known[$coid];
        }
    }

    return $found;
}

/**
 * 把评论正文压成一行纯文本。
 *
 * strip_tags 之后还会剩换行和连续空格，不压掉的话 CSS 的单行省略号根本不生效
 * （text-overflow 只对不换行的行内内容起作用）。
 */
function noline_plain_excerpt($text, $length = 38)
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));

    if ($text === '') {
        return '';
    }

    /* preg_split('//u') 按字符而不是字节切，中文不会被从中间断开 */
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

    return count($chars) > $length
        ? implode('', array_slice($chars, 0, $length)) . '…'
        : $text;
}

/**
 * 输出卡片底部的评论预览条。没有已审核评论时一个字都不输出——空框比没有框更糟。
 *
 * 整块是一个指向 #comments 的链接：它本身不承载任何可交互子元素，
 * 做成一个大热区比让读者去瞄底部那个「评论 N」省事。
 */
function noline_comment_preview($post, $limit = 3)
{
    if (!is_object($post)) {
        return;
    }

    $rows = noline_recent_comments($post->cid, $limit);

    if (empty($rows)) {
        return;
    }

    $parents = array();
    foreach ($rows as $row) {
        $parents[] = (int)$row['parent'];
    }
    $names = noline_parent_authors($parents);

    /* 头像按邮箱去重：同一个人连回三条，摆三张一样的脸没有意义 */
    $faces = array();
    foreach ($rows as $row) {
        $mail = strtolower(trim((string)$row['mail']));
        if ($mail === '' || isset($faces[$mail])) {
            continue;
        }
        $faces[$mail] = noline_gravatar($mail, 52);
        if (count($faces) >= 4) {
            break;
        }
    }
    ?>
    <a class="card-comments" href="<?php echo htmlspecialchars((string)$post->permalink, ENT_QUOTES, 'UTF-8'); ?>#comments">
        <?php if (!empty($faces)): ?>
        <span class="card-comments-faces" aria-hidden="true">
            <?php foreach ($faces as $src): ?>
            <img class="card-comments-face" src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="26" height="26" loading="lazy">
            <?php endforeach; ?>
        </span>
        <?php endif; ?>
        <span class="card-comments-list">
            <?php foreach ($rows as $row): ?>
            <?php $text = noline_plain_excerpt($row['text']); if ($text === '') { continue; } ?>
            <?php $author = trim((string)$row['author']); if ($author === '') { $author = _t('匿名'); } ?>
            <span class="card-comments-item">
                <span class="card-comments-author"><?php echo htmlspecialchars($author, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (isset($names[(int)$row['parent']])): ?>
                <span class="card-comments-reply">＠<?php echo htmlspecialchars($names[(int)$row['parent']], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <span class="card-comments-text"><?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
            <?php endforeach; ?>
        </span>
    </a>
    <?php
}

/* ==========================================================================
   右栏组件：日历 / 那年今日 / 近期评论 / 站点信息
   --------------------------------------------------------------------------
   三个自绘组件共用 noline_post_rows() 一次轻列查询（只取 title 和 created），
   日期过滤全部放在 PHP 侧——Typecho 同时支持 MySQL 和 SQLite，
   MONTH() / FROM_UNIXTIME() 这类函数两边名字不一样，写进 SQL 就是埋雷。
   相对时间复用杂项一节已有的 noline_time_ago()。
   ========================================================================== */

/**
 * 全部已发布文章的 title 与 created。
 *
 * 字段表写成单个字符串：select() 无论按哪种签名实现都吃得下。
 * 只取两列是为了不把整篇正文拖进内存——右栏每次请求都会调用这里。
 */
function noline_post_rows()
{
    static $rows = null;

    if ($rows === null) {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll(
            $db->select('title, created')
                ->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
        );
    }

    return $rows;
}

/**
 * 当月日历：weeks 是二维数组（0 表示空 cell），posted 是有文章的日子，today 是今天几号。
 */
function noline_calendar_data()
{
    $now   = time();
    $year  = (int)date('Y', $now);
    $month = (int)date('n', $now);
    $first = mktime(0, 0, 0, $month, 1, $year);

    $posted = array();
    foreach (noline_post_rows() as $row) {
        $ts = (int)$row['created'];
        if ((int)date('Y', $ts) === $year && (int)date('n', $ts) === $month) {
            $posted[(int)date('j', $ts)] = true;
        }
    }

    /* date('w') 周日为 0，和表头「日一二三四五六」的顺序正好对上 */
    $weeks = array();
    $week  = array_fill(0, (int)date('w', $first), 0);
    $days  = (int)date('t', $first);

    for ($d = 1; $d <= $days; $d++) {
        $week[] = $d;
        if (count($week) === 7) {
            $weeks[] = $week;
            $week = array();
        }
    }
    if (!empty($week)) {
        $weeks[] = array_pad($week, 7, 0);
    }

    return array(
        'year'   => $year,
        'month'  => $month,
        'today'  => (int)date('j', $now),
        'weeks'  => $weeks,
        'posted' => $posted,
    );
}

/** 中文月名：日历药丸标题要写「十月历」而不是「10月历」。 */
function noline_month_name($month)
{
    static $names = array('一', '二', '三', '四', '五', '六', '七', '八', '九', '十', '十一', '十二');

    $month = (int)$month;

    return ($month >= 1 && $month <= 12) ? $names[$month - 1] : (string)$month;
}

/**
 * 往年今天发布的文章，年份新的在前。
 *
 * 只输出文字不做链接：循环外拼 permalink 依赖后台的固定链接格式，
 * 本机没有 PHP 环境无从验证，宁可少一个点击也不埋 404。
 */
function noline_history_today($limit = 4)
{
    $now      = time();
    $monthDay = date('n-j', $now);
    $year     = (int)date('Y', $now);

    $hits = array();
    foreach (noline_post_rows() as $row) {
        $ts = (int)$row['created'];
        if (date('n-j', $ts) !== $monthDay || (int)date('Y', $ts) >= $year) {
            continue;
        }
        $hits[] = array(
            'year'  => (int)date('Y', $ts),
            'ago'   => $year - (int)date('Y', $ts),
            'title' => trim((string)$row['title']),
        );
    }

    usort($hits, function ($a, $b) { return $b['year'] - $a['year']; });

    return array_slice($hits, 0, max(1, (int)$limit));
}

/**
 * 全站计数：文章 / 已审核评论 / 分类 / 自建站起的天数。
 * 建站日取最早一篇文章的发布时间——options 里没有可靠的开站时间戳。
 */
function noline_site_stats()
{
    static $stats = null;

    if ($stats === null) {
        $db   = Typecho_Db::get();
        $rows = noline_post_rows();

        $oldest = time();
        foreach ($rows as $row) {
            $ts = (int)$row['created'];
            if ($ts < $oldest) {
                $oldest = $ts;
            }
        }

        $comments = $db->fetchAll(
            $db->select('coid')->from('table.comments')->where('status = ?', 'approved')
        );
        $categories = $db->fetchAll(
            $db->select('mid')->from('table.metas')->where('type = ?', 'category')
        );

        $stats = array(
            'posts'      => count($rows),
            'comments'   => count($comments),
            'categories' => count($categories),
            'days'       => max(1, (int)floor((time() - $oldest) / 86400)),
        );
    }

    return $stats;
}
