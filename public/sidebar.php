<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 右栏。站点身份（头像/名字/座右铭）在顶栏品牌和首页头图（.site-hero）里，
 * 这里放组件卡：日历 / 那年今日 / 分类（后台开关，默认关）/ 标签 / 近期评论（不出文章页）
 * / 目录（仅文章页）/ 站点信息。
 *
 * 每张卡都挂 .card-widget：8px/12px 内缩 + 卡内 8px gap，标题是一枚强调色图标
 * 领一行粗字。图标按「这张卡在讲什么」挑，不是装饰——六个一样的圆点读不出区别。
 *
 * 尾部那几张（站点信息，加上按页面类型出现的目录 / 近期评论）包在 .sidebar-sticky 里，
 * 是右栏唯一跟着页面滚的
 * 部分——列表改成无限加载之后正文会一直往下长，整栏都固定住的话前面四张滚走以后
 * 右栏就空了，整栏都不固定的话滚过一屏之后右栏又是空的。
 */
$options = $this->options;

$customFooter = trim((string)$options->logoFooter);

$calendar = noline_calendar_data();
$history  = noline_history_today();
$stats    = noline_site_stats();
?>

<aside class="sidebar">

    <!-- 当月日历 -->
    <div class="card card-widget">
        <div class="card-header"><?php noline_icon('calendar'); ?><?php echo noline_month_name($calendar['month']) . _t('月历'); ?></div>
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
    <div class="card card-widget">
        <div class="card-header"><?php noline_icon('history'); ?><?php _e('那年今日'); ?></div>
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

    <!-- 分类卡。默认不出：分类导航在信息流顶上那条吸顶药丸条里已经有一份了，
         这里再铺一长列是重复；后台「右栏分类卡」那个开关勾上才出。
         数据读 noline_categories() 而不是自己遍历 widget：整站只允许遍历一次
         （public/header.php 那次），原因见那个文件顶部的注释。 -->
    <?php $cats = noline_categories(); ?>
    <?php if (!empty($options->showSidebarCats) && !empty($cats)): ?>
    <div class="card card-widget">
        <div class="card-header"><?php noline_icon('folder'); ?><?php _e('分类'); ?></div>
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
    <div class="card card-widget">
        <div class="card-header"><?php noline_icon('tag'); ?><?php _e('标签'); ?></div>
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

    <!-- 跟着页面滚的尾部。组里这几张卡必须是相邻的兄弟：同级 sticky 元素共用一个 top
         会精确重叠，而 .card 是不透明背景，后一张会整张盖住前一张。 -->
    <div class="sidebar-sticky">

        <!-- 目录。只在单篇文章/单页出卡，且要等 main.js 从正文里数出至少两个标题
             才揭开：标题的 id 和整棵树都是它现搭的（正文是后台写的，PHP 侧改不了
             已经解析好的 content HTML），模板这里只留一个空的有序列表占位。
             放在 sticky 组的第一张：读长文时这一栏跟着滚，目录就该在最上面。 -->
        <?php if ($this->is('single')): ?>
        <div class="card card-widget sidebar-toc" id="sidebar-toc" hidden>
            <div class="card-header"><?php noline_icon('bars'); ?><?php _e('目录'); ?></div>
            <nav class="toc-content" id="toc-content" aria-label="<?php _e('文章目录'); ?>">
                <ol class="toc-list" id="toc-list"></ol>
            </nav>
        </div>
        <?php endif; ?>

        <!-- 近期评论：头像之间一条竖向发丝线，正文坐进浅灰气泡。
             单篇文章/单页不放：正文下面就是它自己的评论区，右栏再来一份是重复，
             而且文章页的右栏已经有目录要跟着滚，越短越好。
             查询也一起关在这道门外——不给 single 页白跑一次 Widget_Comments_Recent。 -->
        <?php if (!$this->is('single')): ?>
        <?php $this->widget('Widget_Comments_Recent', 'pageSize=5')->to($recent); ?>
        <?php if ($recent->have()): ?>
        <div class="card card-widget">
            <div class="card-header"><?php noline_icon('comments'); ?><?php _e('近期评论'); ?></div>
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
        <?php endif; ?>

        <!-- 站点信息 -->
        <div class="card card-widget">
            <div class="card-header"><?php noline_icon('chart-line'); ?><?php _e('站点信息'); ?></div>
            <div class="site-stats">
                <span class="site-stat"><?php _e('文章'); ?><b data-count="<?php echo $stats['posts']; ?>"><?php echo $stats['posts']; ?></b></span>
                <span class="site-stat"><?php _e('评论'); ?><b data-count="<?php echo $stats['comments']; ?>"><?php echo $stats['comments']; ?></b></span>
                <span class="site-stat"><?php _e('分类'); ?><b data-count="<?php echo $stats['categories']; ?>"><?php echo $stats['categories']; ?></b></span>
                <span class="site-stat"><?php _e('运行'); ?><b data-count="<?php echo $stats['days']; ?>"><?php echo sprintf(_t('%d 天'), $stats['days']); ?></b></span>
            </div>
            <div class="site-footer-note">
                <p>&copy; <?php echo date('Y'); ?> <?php $options->title(); ?></p>
                <p><?php _e('由 Typecho 驱动'); ?> · <?php _e('主题 Noline'); ?></p>
                <?php if ($customFooter !== ''): ?>
                <p><?php echo $customFooter; ?></p>
                <?php endif; ?>
            </div>
        </div>

    </div>

</aside>
