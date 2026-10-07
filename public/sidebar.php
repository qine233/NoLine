<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 右栏。站点身份（头像/名字/座右铭）在顶栏品牌和首页 masthead 里，
 * 这里放组件卡：日历 / 那年今日 / 近期评论 / 分类 / 标签 / 站点信息。
 *
 * 药丸标题不塞图标：圆点由 .card-header::before 统一画，整排卡片才是同一种语言。
 */
$options = $this->options;

$customFooter = trim((string)$options->logoFooter);

$calendar = noline_calendar_data();
$history  = noline_history_today();
$stats    = noline_site_stats();
?>

<aside class="sidebar">

    <!-- 当月日历 -->
    <div class="card">
        <div class="card-header"><?php echo noline_month_name($calendar['month']) . _t('月历'); ?></div>
        <div class="calendar-wrap">
            <div class="calendar-head">
                <?php foreach (array(_t('日'), _t('一'), _t('二'), _t('三'), _t('四'), _t('五'), _t('六')) as $wd): ?>
                <span class="calendar-cell"><?php echo $wd; ?></span>
                <?php endforeach; ?>
            </div>
            <?php foreach ($calendar['weeks'] as $week): ?>
            <div class="calendar-week">
                <?php foreach ($week as $day): ?>
                <?php if ($day === 0): ?>
                <span class="calendar-cell"></span>
                <?php else: ?>
                <?php
                /* 条件类先拼进变量再整体输出：class 属性里只留一个 echo，
                   静态检查才不会把数组键名误认成类名。 */
                $cell = 'calendar-cell';
                if ($day === $calendar['today']) {
                    $cell .= ' calendar-today';
                } elseif (isset($calendar['posted'][$day])) {
                    $cell .= ' calendar-posted';
                }
                ?>
                <span class="<?php echo $cell; ?>"><?php echo $day; ?></span>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 那年今日。没有命中就不出卡——空框比没有框更糟 -->
    <?php if (!empty($history)): ?>
    <div class="card">
        <div class="card-header"><?php _e('那年今日'); ?></div>
        <div class="history-list">
            <?php foreach ($history as $hit): ?>
            <div class="history-item">
                <div class="history-year">
                    <?php echo $hit['year']; ?>
                    <span class="history-ago"><?php echo sprintf(_t('%d 年前'), $hit['ago']); ?></span>
                </div>
                <div class="history-title"><?php echo htmlspecialchars($hit['title'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 近期评论：头像之间一条竖向发丝线，正文坐进浅灰气泡 -->
    <?php $this->widget('Widget_Comments_Recent', 'pageSize=5')->to($recent); ?>
    <?php if ($recent->have()): ?>
    <div class="card">
        <div class="card-header"><?php _e('近期评论'); ?></div>
        <div class="rcomment-list">
            <?php while ($recent->next()): ?>
            <a class="rcomment-item" href="<?php $recent->permalink(); ?>#comment-<?php echo (int)$recent->coid; ?>">
                <img class="rcomment-face" src="<?php echo htmlspecialchars(noline_gravatar((string)$recent->mail, 64), ENT_QUOTES, 'UTF-8'); ?>" alt="" width="32" height="32" loading="lazy">
                <span class="rcomment-main">
                    <span class="rcomment-head">
                        <span class="rcomment-author"><?php echo htmlspecialchars((string)$recent->author, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="rcomment-time"><?php echo noline_time_ago($recent->created); ?></span>
                    </span>
                    <span class="rcomment-bubble"><?php echo htmlspecialchars(noline_plain_excerpt($recent->text, 46), ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
            </a>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 分类。数据取自 noline_categories()，不再自己遍历 widget：
         顶栏的分类下拉已经遍历过一遍，而 Typecho 的 widget() 只按类名缓存实例、
         Rows 又没有 rewind()，这里第二遍 while 会直接空转，卡片静默变空。 -->
    <?php $cats = noline_categories(); ?>
    <?php if (!empty($cats)): ?>
    <div class="card">
        <div class="card-header"><?php _e('分类'); ?></div>
        <div class="archive-list">
            <?php foreach ($cats as $cat): ?>
            <div class="archive-item">
                <?php /* levels 是层级深度：Rows::execute() 已经把整棵分类树摊平成一维深度优先序列。
                         缩进是每行动态算出来的，没法写成静态 class，只能走内联样式。 */ ?>
                <span class="archive-item-title"<?php if ($cat['levels'] > 0): ?> style="padding-left:<?php echo $cat['levels'] * 14; ?>px"<?php endif; ?>>
                    <a href="<?php echo htmlspecialchars($cat['permalink'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                </span>
                <span class="archive-item-meta"><?php echo $cat['count']; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 标签云 -->
    <div class="card">
        <div class="card-header"><?php _e('标签'); ?></div>
        <div class="tags-container">
            <?php $this->widget('Widget_Metas_Tag_Cloud', 'sort=count&desc=1&limit=20')->to($tags); ?>
            <?php if ($tags->have()): ?>
                <?php while ($tags->next()): ?>
                <a href="<?php $tags->permalink(); ?>" class="tag" title="<?php echo htmlspecialchars(sprintf('%s（%d）', (string)$tags->name, (int)$tags->count), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php $tags->name(); ?><span class="tag-count"><?php $tags->count(); ?></span>
                </a>
                <?php endwhile; ?>
            <?php else: ?>
                <span class="tag"><?php _e('暂无标签'); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- 站点信息 -->
    <div class="card">
        <div class="card-header"><?php _e('站点信息'); ?></div>
        <div class="site-stats">
            <span class="site-stat"><?php _e('文章'); ?><b><?php echo $stats['posts']; ?></b></span>
            <span class="site-stat"><?php _e('评论'); ?><b><?php echo $stats['comments']; ?></b></span>
            <span class="site-stat"><?php _e('分类'); ?><b><?php echo $stats['categories']; ?></b></span>
            <span class="site-stat"><?php _e('运行'); ?><b><?php echo sprintf(_t('%d 天'), $stats['days']); ?></b></span>
        </div>
        <div class="site-footer-note">
            <p>&copy; <?php echo date('Y'); ?> <?php $options->title(); ?></p>
            <p><?php _e('由 Typecho 驱动'); ?> · <?php _e('主题 NoLine'); ?></p>
            <?php if ($customFooter !== ''): ?>
            <p><?php echo $customFooter; ?></p>
            <?php endif; ?>
        </div>
    </div>

</aside>
