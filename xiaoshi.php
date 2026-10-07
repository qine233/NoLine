<?php
/**
 * 单分类页面
 *
 * 用法：后台新建一个页面，模板选「单分类」，再给这个页面加一个自定义字段
 *   名称：category
 *   值：  要展示的分类缩略名（slug，不是中文名，也不是 mid）
 * slug 在后台「管理 → 分类」里编辑分类可以看到。页面正文会显示在列表上方，
 * 适合写这个分类的说明。
 *
 * @package custom
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 一页显示多少篇。
 *
 * 这里刻意不做分页：pageNav() / pageLink() 是拿 parameter->type 反查路由来生成链接的，
 * 而页面模板里手动 alloc 出来的分类归档，type 仍然是 category，
 * 于是分页链接会指向真正的分类归档页（archive.php）而不是当前页面，
 * 点「下一页」看起来就像跳到了另一个页面。
 * 所以只取最新的一批，剩下的用一个链接导流到分类归档。
 */
$limit = 20;

$slug = trim((string)$this->fields->category);

$categoryName = null;
$categoryUrl = null;
$total = 0;
$posts = null;
$missing = false;

if ($slug !== '') {
    try {
        $this->widget(
            'Widget_Archive@noline_xiaoshi',
            'type=category&pageSize=' . $limit,
            'slug=' . urlencode($slug)
        )->to($posts);

        // push() 在遍历时会整体改写 $row，分类信息必须在 next() 之前取出来
        $categoryName = (string)$posts->archiveTitle;
        $categoryUrl = (string)$posts->archiveUrl;
        $total = $posts->getTotal();
    } catch (Typecho\Widget\Exception $e) {
        // categoryHandle() 找不到 slug 时抛 404，这里拦下来显示成一句提示，
        // 否则整个页面白屏
        $missing = true;
    }
}

$intro = trim((string)$this->text);

$this->need('public/header.php');
?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">

            <div class="archive-header">
                <h1 class="archive-title"><?php $this->title(); ?></h1>
                <?php if ($categoryName !== null): ?>
                <p class="archive-count">
                    <?php _e('分类「%s」 · 共 %d 篇', $categoryName, $total); ?>
                </p>
                <?php endif; ?>
            </div>

            <?php if ($intro !== ''): ?>
            <article class="card article-card">
                <div class="post-content"><?php $this->content(); ?></div>
            </article>
            <?php endif; ?>

            <?php if ($slug === '' || $missing): ?>
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-state-icon"><?php noline_icon('circle-info'); ?></div>
                        <p class="empty-state-text">
                            <?php if ($missing): ?>
                                <?php _e('没有找到缩略名为「%s」的分类。', htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')); ?>
                            <?php else: ?>
                                <?php _e('还没有配置分类。给这个页面加一个名为 category 的自定义字段，值填分类缩略名。'); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php elseif ($posts->have()): ?>
                <?php while ($posts->next()): ?>
                <article class="card post-card">
                    <div class="post-header">
                        <img src="<?php echo noline_author_avatar($posts); ?>" alt="" class="avatar" width="40" height="40" loading="lazy">
                        <div class="header-text">
                            <div class="author-name"><?php $posts->author(); ?></div>
                            <div class="post-meta">
                                <span class="meta-item"><?php noline_icon('clock'); ?><time datetime="<?php $posts->date('c'); ?>"><?php $posts->date('Y-m-d'); ?></time></span>
                                <span class="category-tag"><?php $posts->category(', ', true, _t('未分类')); ?></span>
                            </div>
                        </div>
                    </div>

                    <h2 class="post-title">
                        <a class="post-title-link" href="<?php $posts->permalink(); ?>"><?php $posts->title(); ?></a>
                    </h2>

                    <div class="post-excerpt"><?php $posts->excerpt(140, '…'); ?></div>

                    <div class="post-footer">
                        <span class="stat-item"><?php noline_icon('eye'); ?> <?php _e('阅读'); ?> <?php echo noline_get_views($posts->cid); ?></span>
                        <a class="stat-item" href="<?php $posts->permalink(); ?>#comments"><?php noline_icon('comment'); ?> <?php _e('评论'); ?> <?php $posts->commentsNum('0', '1', '%d'); ?></a>
                        <a class="read-more" href="<?php $posts->permalink(); ?>"><?php _e('阅读全文'); ?></a>
                    </div>

                    <?php noline_comment_preview($posts); ?>
                </article>
                <?php endwhile; ?>

                <?php if ($total > $limit && $categoryUrl !== ''): ?>
                <div class="load-more-container">
                    <a class="btn-load-more" href="<?php echo htmlspecialchars($categoryUrl, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php _e('查看全部 %d 篇', $total); ?>
                    </a>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-state-icon"><?php noline_icon('magnifying-glass'); ?></div>
                        <p class="empty-state-text"><?php _e('这个分类下还没有文章。'); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
