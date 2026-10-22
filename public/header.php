<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 后台设置字段名沿用了旧版本的拼写（logoName 是名字、logobg 是座右铭、logobgcolor 是头图），
 * 见 functions.php 的 themeConfig()。
 *
 * 这里只管全站常驻的顶栏。站点身份（头像大图 / 站名 / 座右铭 / 头图）是首页 masthead 的事，
 * 见 index.php——其余页面没有 masthead，也就不需要那几个字段。
 *
 * 分类必须在这里遍历一次 Widget_Metas_Category_List，逐行存进 noline_categories()：
 * 信息流顶上那条吸顶药丸条和右栏的分类卡都只读这份缓存。Typecho 的 widget() 只按类名
 * 缓存实例、Rows 又没有 rewind()，同一个 widget 全站只能遍历一次，两处各遍历一遍的话
 * 后跑的那个必然是空的。顶栏本身不再显示分类——导航只留首页和独立页面。
 */
$name = trim((string)$this->options->logoName);
if ($name === '') {
    $name = trim((string)$this->options->title);
}

$avatar       = noline_profile_avatar();
$github       = trim((string)$this->options->gtihubsite);
$bilibili     = trim((string)$this->options->BliBlisite);
$githubName   = trim((string)$this->options->gtihubname);
$bilibiliName = trim((string)$this->options->BliBliname);

/* 窄屏下显示名被 CSS 藏起来了，title 与 aria-label 是它仅剩的出口，所以两边都要有值 */
$githubTitle   = $githubName !== '' ? $githubName : 'GitHub';
$bilibiliTitle = $bilibiliName !== '' ? $bilibiliName : 'Bilibili';

/* 转场样式（进度条 / 遮罩 / 两者）。后台没保存过时回退成 both，见 noline_transition_mode()。 */
$transition = noline_transition_mode();
/* 顶栏压在首屏上时要白字，压在页底色上时要墨色——这两件事由 body 上这两个类决定，
   design.css 里的 --nav-* 开关跟着换（见「顶栏」一节）。
 * has-hero：这一页有没有首屏。首页和文章页有，其它页面没有，
 *           没有的页面 body 要让出一条导航的高度。
 * hero-cover：首屏有没有封面图。有图才有照片做背景，白字才立得住。 */
$hasHero = $this->is('index') || $this->is('post');
$heroCover = $this->is('post')
    ? noline_post_cover($this) !== ''
    : trim((string)$this->options->logobgcolor) !== '';
$bodyClass = ($hasHero ? ' has-hero' : '') . ($heroCover ? ' hero-cover' : '');

/* 跨文档视图过渡的开关。COOP 头是 themeInit 发的，这里回头确认它真的挂上了：
   没挂上就还走原先那套淡入淡出，别让页面既没有过渡、也没有淡出地硬切。 */
$vtReady = noline_header_sent('Cross-Origin-Opener-Policy');
?>
<!DOCTYPE html>
<html lang="zh-CN"<?php if ($vtReady): ?> data-vt<?php endif; ?>>
<head>
    <meta charset="<?php $this->options->charset(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php /* 预加载：主样式表在防闪烁脚本和 header() 之后才出现，顶栏头像更要等到 body 里
              那个 <img>（中间隔着几百行 HTML）。跨文档视图过渡是等新文档「就绪」才换页的，
              这两个请求越早落地，换页那一瞬越不会闪出一块空头像。
              头像只在 http(s) 时预载：data: URI 没有请求可提前，preload 反而会报一条警告。 */ ?>
    <link rel="preload" as="style" href="<?php echo noline_css_url('css/design.css'); ?>?v=<?php echo NOLINE_VERSION; ?>">
    <?php if ($avatar !== null && preg_match('%^https?://%i', $avatar)): ?>
    <link rel="preload" as="image" href="<?php echo $avatar; ?>">
    <?php endif; ?>
    <?php
    /**
     * header() 自己就会输出转义好的 description / keywords meta，不要再手写一遍。
     * 这里只屏蔽指纹和 XML-RPC 相关的头部信息，RSS/ATOM 的自动发现链接要保留。
     *
     * 注意：不要把 commentReply= 或 antiSpam= 放进屏蔽列表，
     * 前者缺失会让嵌套评论的"回复"按钮退化成整页刷新，后者缺失会让评论表单被反垃圾拦下。
     */
    $this->header('generator=&template=&pingback=&xmlrpc=&wlw=');
    ?>
    <title><?php $this->archiveTitle(array(
        'category'  => _t('分类 %s 下的文章'),
        'search'    => _t('包含关键字 %s 的文章'),
        'tag'       => _t('标签 %s 下的文章'),
        'author'    => _t('%s 发布的文章')
    ), '', ' - '); ?><?php $this->options->title(); ?></title>

    <?php $favicon = trim((string)$this->options->logoCss); if ($favicon !== ''): ?>
    <link rel="shortcut icon" href="<?php echo htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>

    <?php
    /**
     * canonical + Open Graph + JSON-LD。
     *
     * 核心 header() 输出的只有 description / keywords / 三个订阅链接——1.2.1 的
     * var/Widget/Archive.php 里 header() 拼的就是这几样，没有 canonical，也没有任何
     * og: 标签。所以链接发到微信、Telegram、X 不带头图和摘要，搜索引擎也拿不到结构化数据。
     *
     * URL 走 getArchiveUrl()：核心在 index / 单页 / 分类 / 标签 / 作者 / 日期 / 搜索
     * 每一种归档里都赋过值（分别是 siteUrl、permalink、各归档的 permalink）。
     * 分页（/page/2）拿到的是不带页码的那一份，这是有意的：
     * 第 2 页之后的列表没有独立收录价值，文章本体有自己的 permalink 规范链接。
     */
    $canonical = method_exists($this, 'getArchiveUrl') ? trim((string)$this->getArchiveUrl()) : '';
    if ($canonical === '') {
        $canonical = $this->is('single')
            ? trim((string)$this->permalink)
            : rtrim((string)$this->options->siteUrl, '/') . '/';
    }

    $ogTitle = $name;
    if ($this->is('single')) {
        $ogTitle = trim((string)$this->title);
    } elseif (method_exists($this, 'getArchiveTitle') && $this->getArchiveTitle() !== null) {
        $ogTitle = trim((string)$this->getArchiveTitle()) . ' · ' . $name;
    }

    /* 描述优先用文章自己的正文，其次核心给归档配的说明，最后才是站点的副标题 */
    $ogDesc = '';
    if ($this->is('single')) {
        $ogDesc = noline_plain_excerpt(noline_plain_text((string)$this->text), 120);
    }
    if ($ogDesc === '' && method_exists($this, 'getDescription')) {
        $ogDesc = trim((string)$this->getDescription());
    }
    if ($ogDesc === '') {
        $ogDesc = trim((string)$this->options->description);
    }

    /* 图：文章有 thumb 自定义字段就用封面，其余场景用站点头像。
       两者都必须是绝对地址——社交爬虫不会替相对路径补域名。 */
    $ogImage = '';
    if ($this->is('post')) {
        $ogImage = noline_post_cover($this);
    }
    if ($ogImage === '') {
        $ogImage = trim((string)$this->options->logoCss);
    }
    if ($ogImage !== '' && strpos($ogImage, '://') === false) {
        $ogImage = rtrim((string)$this->options->siteUrl, '/') . '/' . ltrim($ogImage, '/');
    }
    ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="<?php echo $this->is('single') ? 'article' : 'website'; ?>">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($ogDesc !== ''): ?>
    <meta property="og:description" content="<?php echo htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <?php if ($ogImage !== ''): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?php echo $ogImage !== '' ? 'summary_large_image' : 'summary'; ?>">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($ogDesc !== ''): ?>
    <meta name="twitter:description" content="<?php echo htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <?php if ($ogImage !== ''): ?>
    <meta name="twitter:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>

    <?php
    /**
     * JSON-LD：文章给 BlogPosting，首页给 WebSite + 搜索动作。
     * 其它归档类型不输出——列不出一个诚实的 schema 比列出一个错的更省事。
     */
    $jsonLd = null;

    if ($this->is('post')) {
        /* 和 noline_author_avatar() 同一个理由：先取对象再判空，
           直接链式 ->author->screenName 在 author 为 null 时会抛出 PHP 8 警告，
           警告文本会印在 <head> 里，把整页结构弄脏。 */
        $ldAuthor   = $this->author;
        $authorName = trim((string)($ldAuthor !== null ? $ldAuthor->screenName : ''));

        $jsonLd = array(
            '@context'      => 'https://schema.org',
            '@type'         => 'BlogPosting',
            'headline'      => $ogTitle,
            'url'           => $canonical,
            'mainEntityOfPage' => array('@type' => 'WebPage', '@id' => $canonical),
            'datePublished' => date('c', (int)$this->created),
            'dateModified'  => date('c', (int)($this->modified ?: $this->created)),
            'inLanguage'    => 'zh-CN',
            'author'        => array(
                '@type' => 'Person',
                'name'  => $authorName !== '' ? $authorName : $name,
            ),
            'publisher'     => array(
                '@type' => 'Organization',
                'name'  => $name,
            ),
        );
        if ($ogImage !== '') {
            $jsonLd['image'] = $ogImage;
        }
        if ($ogDesc !== '') {
            $jsonLd['description'] = $ogDesc;
        }
    } elseif ($this->is('index') && $this->getCurrentPage() <= 1) {
        $jsonLd = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'WebSite',
            'name'        => $name,
            'url'         => $canonical,
            'description' => $ogDesc,
            'potentialAction' => array(
                '@type'       => 'SearchAction',
                'target'      => $canonical . '?s={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ),
        );
    }

    if ($jsonLd !== null) {
        /* JSON_HEX_* 是必须的：正文里出现 </script> 或 & 时，
           不转义就会把这段脚本就地截断，整页 head 跟着烂掉。 */
        echo '<script type="application/ld+json">'
            . json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>' . "\n";
    }
    ?>

    <?php
    /**
     * 主题与进场状态必须在首帧之前定下来，否则会先白屏一闪再变暗。
     * 这段是阻塞式内联脚本，不能加 defer，也不能挪进 main.js。
     *
     * 因为这里保证了 data-theme 一定存在，design.css 只需要一个 :root[data-theme="dark"] 块，
     * 不必再抄一份 @media (prefers-color-scheme: dark)。无 JS 时回落到浅色。
     */
    ?>
    <script>
    (function () {
        var el = document.documentElement;
        var theme = null, reduce = false;
        try {
            theme = localStorage.getItem('noline-theme');
            reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        } catch (e) {}
        if (theme !== 'dark' && theme !== 'light') {
            theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        el.setAttribute('data-theme', theme);
        if (reduce) { return; }

        <?php /* 跨文档视图过渡用不用得上，两件事都得成立：浏览器认 @view-transition 这条
                 at-rule，以及服务端把 COOP 头真送出去了（data-vt 就是 PHP 回头查过 headers_list()
                 给的标记）。探测走构造样式表——不认识的 at-rule 会被解析器整条丢掉，
                 cssRules 的长度就是答案；CSS.supports 只能测属性，而属性支持不等于跨文档支持
                 （Firefox 正卡在这个差上）。 */ ?>
        var vt = el.hasAttribute('data-vt');

        if (vt) {
            try {
                var sheet = new CSSStyleSheet();
                sheet.replaceSync('@view-transition{navigation:auto}');
                vt = sheet.cssRules.length > 0;
            } catch (e) { vt = false; }
        }

        if (vt) {
            /* 交换时机交给浏览器：新页没准备好之前，旧页原样挂着。
               这时候绝不能先藏内容——藏了就被拍进快照里，过渡完是一片白。 */
            el.classList.add('vt-cross');
            return;
        }

        el.classList.add('is-loading');
        /* 兜底。main.js 若加载失败（404、被扩展拦下），is-loading 就永远不会被移除，
           整页空白——所以这个定时器必须待在内联脚本里，不能只放在 main.js。 */
        setTimeout(function () { el.classList.remove('is-loading'); }, 900);
    })();
    </script>

    <?php noline_accent_style(); ?>

    <?php
    /**
     * highlight.js 压缩后仍有 120KB，没必要发给首页和归档页——那些页面根本不会有代码块。
     * $this->text 是未渲染的原文：Markdown 代码块是三个反引号，直接写 HTML 的是 <pre。
     */
    $needHighlight = $this->is('single')
        && (strpos((string)$this->text, '```') !== false || stripos((string)$this->text, '<pre') !== false);
    ?>

    <link rel="stylesheet" href="<?php echo noline_css_url('css/design.css'); ?>?v=<?php echo NOLINE_VERSION; ?>">
    <?php /* article.css 通篇只服务 .post-content 和 .btn-copy-code，
            而首页/归档/搜索列表的摘要卡片里根本没有这两个容器（已逐个模板核对），
            所以只在单页和文章页发。 */ ?>
    <?php if ($this->is('single')): ?>
    <link rel="stylesheet" href="<?php echo noline_css_url('css/article.css'); ?>?v=<?php echo NOLINE_VERSION; ?>">
    <?php endif; ?>
    <?php if ($needHighlight): ?>
    <?php /* 这两个第三方文件平铺在 css/ 与 js/ 下，不放进 vendor/ 子目录：
       线上 nginx 对任何带 /vendor/ 的请求一律回 404（和文件在不在无关），
       桌面模式的物理引擎和文章页的代码高亮会一起哑掉。 */ ?>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('css/atom-one-dark.min.css'); ?>?v=<?php echo NOLINE_VERSION; ?>">
    <script defer src="<?php $this->options->themeUrl('js/highlight.min.js'); ?>?v=<?php echo NOLINE_VERSION; ?>"></script>
    <?php endif; ?>

    <script defer src="<?php $this->options->themeUrl('js/main.js'); ?>?v=<?php echo NOLINE_VERSION; ?>"></script>
</head>
<body class="<?php echo $bodyClass; ?>">

<?php /* 进度条和遮罩都必须是 .topbar 的兄弟：顶栏有 backdrop-filter，
         它会给固定定位的后代建包含块，塞进去就会跟着顶栏一起跑。
         两个元素都默认不可见，只有 main.js 挂上 .is-active 才出现——
         脚本 404 或被扩展拦下时页面绝不能被遮罩永久盖住，所以宁可不出、不要常开。 */ ?>
<?php if ($transition !== 'splash'): ?>
<div id="noline-progress" aria-hidden="true"></div>
<?php endif; ?>
<?php if ($transition !== 'bar'): ?>
<div id="noline-splash" aria-hidden="true">
    <span class="noline-splash-inner">
        <?php if ($avatar !== null): ?>
        <img src="<?php echo $avatar; ?>" alt="" class="noline-splash-logo" width="72" height="72">
        <?php else: ?>
        <span class="noline-splash-logo"><?php echo noline_initial($name); ?></span>
        <?php endif; ?>
        <span class="noline-splash-text"><?php _e('稍等，正在打开'); ?></span>
    </span>
</div>
<?php endif; ?>
<div class="nav-scrim" id="nav-scrim"></div>

<header class="topbar">
    <div class="topbar-inner">

        <a class="topbar-brand" href="<?php $this->options->siteUrl(); ?>" title="<?php _e('回到首页'); ?>">
            <?php if ($avatar !== null): ?>
            <img src="<?php echo $avatar; ?>" alt="" class="topbar-logo" width="32" height="32">
            <?php else: ?>
            <span class="topbar-logo topbar-logo-placeholder" aria-hidden="true"><?php echo noline_initial($name); ?></span>
            <?php endif; ?>
            <span class="topbar-name"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></span>
        </a>

        <?php /* 窄屏下这整块脱离文档流，变成挂在顶栏下沿的下拉面板（见 design.css 与 main.js） */ ?>
        <nav class="topnav" id="topnav" aria-label="<?php _e('主导航'); ?>">

            <a class="nav-item<?php if ($this->is('index')): ?> active<?php endif; ?>" href="<?php $this->options->siteUrl(); ?>"<?php if ($this->is('index')): ?> aria-current="page"<?php endif; ?>>
                <?php noline_icon('house'); ?><span class="nav-label"><?php _e('首页'); ?></span>
            </a>

            <?php $this->widget('Widget_Contents_Page_List')->to($pages); ?>
            <?php while ($pages->next()): ?>
            <a class="nav-item<?php if ($this->is('page', $pages->slug)): ?> active<?php endif; ?>" href="<?php $pages->permalink(); ?>" title="<?php echo htmlspecialchars((string)$pages->title, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($this->is('page', $pages->slug)): ?> aria-current="page"<?php endif; ?>>
                <?php noline_nav_mark($pages); ?><span class="nav-label"><?php noline_nav_label($pages); ?></span>
            </a>
            <?php endwhile; ?>

            <?php
            /* 全站只在这里遍历一次分类，逐行存进 noline_categories()：
               吸顶药丸条（public/feed-cats.php）和右栏分类卡都读这份缓存。
               原因见本文件顶部的注释——顶栏自己不再出分类。 */
            $this->widget('Widget_Metas_Category_List')->to($cats);
            while ($cats->next()) {
                noline_categories(array(
                    'name'      => (string)$cats->name,
                    'slug'      => (string)$cats->slug,
                    'permalink' => (string)$cats->permalink,
                    'count'     => (int)$cats->count,
                    'levels'    => (int)$cats->levels,
                ));
            }
            ?>

            <form class="topbar-search" method="get" action="<?php $this->options->siteUrl(); ?>" role="search">
                <label class="sr-only" for="nav-search-input"><?php _e('搜索文章'); ?></label>
                <input type="search" id="nav-search-input" name="s" placeholder="<?php _e('搜索文章…'); ?>" autocomplete="off">
                <?php /* 快捷键徽章：里面写什么由 main.js 按平台决定（⌘K 或 Ctrl K）。
                        聚焦、已有输入、窄屏这三种情况下 CSS 会把它收掉。 */ ?>
                <kbd class="search-kbd" id="search-kbd" aria-hidden="true"></kbd>
                <button type="submit" class="icon-btn" aria-label="<?php _e('搜索'); ?>">
                    <?php noline_icon('magnifying-glass'); ?>
                </button>
            </form>

        </nav>

        <div class="topbar-actions">

            <?php if ($github !== ''): ?>
            <a href="<?php echo htmlspecialchars($github, ENT_QUOTES, 'UTF-8'); ?>" class="icon-btn<?php if ($githubName !== ''): ?> icon-btn-label<?php endif; ?>" rel="noopener nofollow me" target="_blank" title="<?php echo htmlspecialchars($githubTitle, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($githubTitle, ENT_QUOTES, 'UTF-8'); ?>">
                <?php noline_icon('github'); ?><?php if ($githubName !== ''): ?><span><?php echo htmlspecialchars($githubName, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>

            <?php if ($bilibili !== ''): ?>
            <a href="<?php echo htmlspecialchars($bilibili, ENT_QUOTES, 'UTF-8'); ?>" class="icon-btn<?php if ($bilibiliName !== ''): ?> icon-btn-label<?php endif; ?>" rel="noopener nofollow me" target="_blank" title="<?php echo htmlspecialchars($bilibiliTitle, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($bilibiliTitle, ENT_QUOTES, 'UTF-8'); ?>">
                <?php noline_icon('bilibili'); ?><?php if ($bilibiliName !== ''): ?><span><?php echo htmlspecialchars($bilibiliName, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>

            <?php /* 整数由 main.js 写进来，百分号是 CSS 的 ::after。
                     起始就挂 hidden：页面短到不能滚时它根本不该出现。 */ ?>
            <span class="topbar-percent" id="nav-percent" hidden aria-hidden="true">0</span>

            <button type="button" class="icon-btn js-theme-toggle" title="<?php _e('切换深浅色'); ?>" aria-label="<?php _e('切换深浅色'); ?>">
                <?php noline_icon('moon', 'icon-moon'); ?><?php noline_icon('sun', 'icon-sun'); ?>
            </button>

            <button type="button" class="icon-btn mobile-menu-btn" id="nav-toggle" aria-expanded="false" aria-controls="topnav" aria-label="<?php _e('展开菜单'); ?>">
                <?php noline_icon('bars'); ?>
            </button>

        </div>

    </div>
</header>
