<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 后台设置字段名沿用了旧版本的拼写（logoName 是名字、logobg 是座右铭、logobgcolor 是头图），
 * 见 functions.php 的 themeConfig()。
 *
 * 这里只管全站常驻的顶栏。站点身份（头像大图 / 站名 / 座右铭 / 头图）是首页 masthead 的事，
 * 见 index.php——其余页面没有 masthead，也就不需要那几个字段。
 *
 * 分类下拉必须在这里遍历 Widget_Metas_Category_List，顺手把每行存进 noline_categories()，
 * 右栏的分类卡改读那份缓存。Typecho 的 widget() 只按类名缓存实例、Rows 又没有 rewind()，
 * 同一个 widget 全站只能遍历一次，两处各遍历一遍的话后跑的那个必然是空的。
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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="<?php $this->options->charset(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
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

    <link rel="stylesheet" href="<?php $this->options->themeUrl('css/design.css'); ?>?v=<?php echo NOLINE_VERSION; ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('css/article.css'); ?>?v=<?php echo NOLINE_VERSION; ?>">
    <?php if ($needHighlight): ?>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('css/vendor/atom-one-dark.min.css'); ?>?v=<?php echo NOLINE_VERSION; ?>">
    <script defer src="<?php $this->options->themeUrl('js/vendor/highlight.min.js'); ?>?v=<?php echo NOLINE_VERSION; ?>"></script>
    <?php endif; ?>

    <script defer src="<?php $this->options->themeUrl('js/main.js'); ?>?v=<?php echo NOLINE_VERSION; ?>"></script>
</head>
<body>

<?php /* 进度条和遮罩都必须是 .topbar 的兄弟：顶栏有 backdrop-filter，
         它会给固定定位的后代建包含块，塞进去就会跟着顶栏一起跑。 */ ?>
<div id="noline-progress" aria-hidden="true"></div>
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
            /* 全站只在这里遍历一次分类，逐行存进 noline_categories()，
               右栏的分类卡改读那份缓存。原因见本文件顶部的注释。 */
            $this->widget('Widget_Metas_Category_List')->to($cats);
            while ($cats->next()) {
                noline_categories(array(
                    'name'      => (string)$cats->name,
                    'permalink' => (string)$cats->permalink,
                    'count'     => (int)$cats->count,
                    'levels'    => (int)$cats->levels,
                ));
            }
            $navCats = noline_categories();
            ?>
            <?php if (!empty($navCats)): ?>
            <div class="nav-group">
                <button type="button" class="nav-item nav-group-btn<?php if ($this->is('category')): ?> active<?php endif; ?>" aria-haspopup="true" aria-expanded="false" aria-controls="nav-cats">
                    <?php noline_icon('folder'); ?><span class="nav-label"><?php _e('分类'); ?></span><span class="nav-caret" aria-hidden="true"></span>
                </button>
                <ul class="nav-sub" id="nav-cats">
                    <?php foreach ($navCats as $c): ?>
                    <?php /* levels 是层级深度，缩进每行动态算，没法写成静态 class，只能走内联样式 */ ?>
                    <li class="nav-sub-item"<?php if ($c['levels'] > 0): ?> style="padding-left:<?php echo $c['levels'] * 12; ?>px"<?php endif; ?>>
                        <a href="<?php echo htmlspecialchars($c['permalink'], ENT_QUOTES, 'UTF-8'); ?>">
                            <span><?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="nav-sub-count"><?php echo $c['count']; ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form class="topbar-search" method="get" action="<?php $this->options->siteUrl(); ?>" role="search">
                <label class="sr-only" for="nav-search-input"><?php _e('搜索文章'); ?></label>
                <input type="search" id="nav-search-input" name="s" placeholder="<?php _e('搜索文章…'); ?>" autocomplete="off">
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
