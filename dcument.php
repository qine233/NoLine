<?php
/**
 * 归档页面
 *
 * @package custom
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 归档页要一次列出全部文章，所以不能用后台的"每页显示条数"。
 *
 * 注意代价：Widget_Base_Contents::filter() 会为每篇文章额外查一次分类表，
 * 也就是说这一页的查询数是 O(文章数)。文章数上千时这里会明显变慢，
 * 到那个量级应该改成按年分页，或者直接链到 Typecho 内置的日期归档。
 */
$archiveLimit = 2000;

$this->widget('Widget_Contents_Post_Recent', 'pageSize=' . $archiveLimit)->to($archives);

$groups = array();

while ($archives->next()) {
    // 用 $archives->year / $archives->date，它们是 filter() 按后台时区算好的；
    // 直接 date('Y', $archives->created) 会用服务器时区，跨时区部署时会串日。
    $year = (int)$archives->year;

    if (!isset($groups[$year])) {
        $groups[$year] = array();
    }

    // 哪个分类算动态由后台的 diaryCategory 统一决定，判定和 index.php / archive.php
    // 共用 noline_diary_category()。这里原先硬编码了一个 '日记' 常量，
    // 后台一改分类就会两边对不上。
    // 动态在时间线里不暴露真标题，只写分类名——和首页动态卡"不出标题"是同一个意思。
    $diaryName = noline_diary_category($archives);

    $groups[$year][] = array(
        'date'      => $archives->date->format('n月d日'),
        'title'     => $diaryName !== null ? $diaryName : $archives->title,
        'permalink' => $archives->permalink
    );
}

krsort($groups);

$this->need('public/header.php');
?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title"><?php $this->title(); ?></h1>

                <div class="post-content"><?php $this->content(); ?></div>

                <?php if (empty($groups)): ?>
                <div class="empty-state">
                    <p class="empty-state-text"><?php _e('还没有文章。'); ?></p>
                </div>
                <?php else: ?>
                <div class="post-archive">
                    <?php foreach ($groups as $year => $items): ?>
                    <h2 class="archive-year"><?php echo $year; ?> <span class="archive-year-count"><?php _e('%d 篇', count($items)); ?></span></h2>
                    <div class="archive-list">
                        <?php foreach ($items as $item): ?>
                        <div class="archive-item">
                            <span class="archive-date"><?php echo htmlspecialchars($item['date'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="archive-item-title">
                                <a href="<?php echo htmlspecialchars($item['permalink'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </article>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
