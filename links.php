<?php
/**
 * 友情链接
 *
 * 依赖 Links 插件（后台「控制台 → 插件」里启用，然后到「友情链接」面板添加）。
 * 没装插件时这一页只显示提示，不再像旧版那样把十几个私人站点硬编码在模板里。
 *
 * @package custom
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Links 插件的模板串。可用占位符：
 *   {lid} {name} {url} {sort} {title} {description} {image} {user}
 * 其中 {title} 和 {description} 插件里都映射到 description 字段。
 *
 * 注意插件是用 str_replace 直接拼的，不做任何转义——
 * 后台填的站名/描述里带英文双引号会破坏这段 HTML，自己填的时候留意。
 * {image} 留空时插件会填 /usr/plugins/Links/nopic.jpg，是本地图，不会产生外链请求。
 */
$linkPattern = '<a class="link-card" href="{url}" target="_blank" rel="noopener nofollow" title="{description}">'
    . '<img class="link-avatar" src="{image}" alt="{name}" width="54" height="54" loading="lazy">'
    . '<span class="link-info">'
    . '<span class="link-title">{name}</span>'
    . '<span class="link-desc">{description}</span>'
    . '</span></a>';

$links = '';

if (class_exists('Links_Plugin')) {
    $links = (string)Links_Plugin::output_str($linkPattern);

    // 插件装了但没启用时，output_str() 返回这句提示文字而不是空串
    if (trim($links) === '友情链接插件未激活') {
        $links = '';
    }
}

$this->need('public/header.php');
?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title"><?php $this->title(); ?></h1>
                <p class="page-subtitle"><?php noline_icon('link'); ?> <?php _e('与世界连接'); ?></p>

                <div class="post-content"><?php $this->content(); ?></div>

                <?php if ($links !== ''): ?>
                <div class="links-grid"><?php echo $links; ?></div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><?php noline_icon('link'); ?></div>
                    <p class="empty-state-text"><?php _e('还没有友情链接。'); ?></p>
                    <p class="empty-state-text">
                        <?php _e('启用 Links 插件后，在「控制台 → 友情链接」里添加即可；也可以在页面正文里写 &lt;links&gt;&lt;/links&gt; 标签由插件自己渲染。'); ?>
                    </p>
                </div>
                <?php endif; ?>
            </article>

            <?php $this->need('comments.php'); ?>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
