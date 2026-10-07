<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 404 页面。
 *
 * 旧版本自己手写了一份不完整的 <html>（没有 DOCTYPE / charset / viewport，
 * 也没有 __TYPECHO_ROOT_DIR__ 守卫），还引用了已删除的 css/boot-404.css。
 * 这里改成复用主题的 header/footer，样式跟着 design.css 走。
 */
$this->need('public/header.php');
?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <div class="card article-card">
                <div class="error-page">
                    <div class="error-code">404</div>
                    <p class="error-message"><?php _e('页面没找到'); ?></p>
                    <p class="error-hint"><?php _e('您访问的内容可能已被移动、重命名或删除。'); ?></p>
                    <div class="error-actions">
                        <a class="btn-primary" href="<?php $this->options->siteUrl(); ?>"><?php _e('回到首页'); ?></a>
                    </div>
                </div>
            </div>

            <!-- 给迷路的访客一个出口，比原来那三个 COUNT 统计数字有用 -->
            <div class="card">
                <div class="card-header"><?php _e('最新文章'); ?></div>
                <div class="archive-list">
                    <?php $this->widget('Widget_Contents_Post_Recent', 'pageSize=6')->to($recent); ?>
                    <?php if ($recent->have()): ?>
                        <?php while ($recent->next()): ?>
                        <div class="archive-item">
                            <span class="archive-date"><?php $recent->date('Y-m-d'); ?></span>
                            <span class="archive-item-title">
                                <a href="<?php echo htmlspecialchars($recent->permalink, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($recent->title, ENT_QUOTES, 'UTF-8'); ?></a>
                            </span>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="archive-item"><span class="archive-item-title"><?php _e('还没有文章。'); ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
