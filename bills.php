<?php
/**
 * 买菜记账
 *
 * 流水 / 周月合计的个人页。数据存在 NolineApi 插件的记录文件里（usr/uploads/noline_api/bills.php），
 * 这一页只负责画：后台「插件 → NolineApi」设可见性，自己或用 App 调接口写数，写完这里立刻能看到。
 *
 * 后台新建页面时模板选「买菜记账」，slug 建议用 bills。
 *
 * @package custom
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

$bills = noline_bills_plugin();
$summary = noline_bills_summary();
$visible = noline_bills_visible($this);

$this->need('public/header.php');
?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title"><?php $this->title(); ?></h1>
                <p class="page-subtitle"><?php noline_icon('wallet'); ?> <?php _e('买了什么、花了多少，按周和月汇总'); ?></p>

                <?php if (trim((string)$this->text) !== ''): ?>
                <div class="post-content"><?php $this->content(); ?></div>
                <?php endif; ?>

                <?php if ($bills === null): ?>

                <div class="empty-state">
                    <div class="empty-state-icon"><?php noline_icon('circle-info'); ?></div>
                    <p class="empty-state-text"><?php _e('还没有安装 NolineApi 插件。'); ?></p>
                    <p class="empty-state-text"><?php _e('把插件目录放进 usr/plugins/NolineApi/，在「控制台 → 插件」里启用后，这一页才会开始显示流水。'); ?></p>
                </div>

                <?php elseif (!$visible): ?>

                <div class="empty-state">
                    <div class="empty-state-icon"><?php noline_icon('moon'); ?></div>
                    <p class="empty-state-text"><?php _e('这一页在插件里设成了「仅自己可见」。'); ?></p>
                    <p class="empty-state-text"><?php _e('登录后台后刷新即可看到。'); ?></p>
                </div>

                <?php elseif ((int)$summary['count'] === 0): ?>

                <div class="empty-state">
                    <div class="empty-state-icon"><?php noline_icon('receipt'); ?></div>
                    <p class="empty-state-text"><?php _e('还没有一笔菜钱。'); ?></p>
                    <p class="empty-state-text"><?php _e('往接口提一条就够了：日期 + 品名 + 金额。端点清单在插件设置页。'); ?></p>
                </div>

                <?php else: ?>

                <?php
                    $week = $summary['week'];
                    $month = $summary['month'];
                    $totals = $summary['totals'];

                    $labels = array(
                        'veg' => _t('蔬菜'), 'fruit' => _t('水果'), 'meat' => _t('肉禽'),
                        'fish' => _t('水产'), 'egg' => _t('蛋奶'), 'grain' => _t('米面粮油'),
                        'seasoning' => _t('调料'), 'other' => _t('其他'),
                    );
                    /** 分类 → 流水行整串类名。类名在这儿写全，不拼 'is-' . $kind：
                     *  拼出来的串 _dev/classcheck.py 只能扫到 'is-'，真正的类会被当成死样式报掉。 */
                    $row_class = array(
                        'veg' => 'bill-item is-veg', 'fruit' => 'bill-item is-fruit',
                        'meat' => 'bill-item is-meat', 'fish' => 'bill-item is-fish',
                        'egg' => 'bill-item is-egg', 'grain' => 'bill-item is-grain',
                        'seasoning' => 'bill-item is-seasoning', 'other' => 'bill-item is-other',
                    );
                    $weekdays = array(_t('周一'), _t('周二'), _t('周三'), _t('周四'), _t('周五'), _t('周六'), _t('周日'));

                    /** 某天是周几；日期是真实的 YYYY-MM-DD，strtotime 不会失手 */
                    $weekday = function ($date) use ($weekdays) {
                        $n = (int)date('N', strtotime((string)$date));
                        return $n >= 1 && $n <= 7 ? $weekdays[$n - 1] : '';
                    };

                    /** 「较上周 +12.5 · 3/7」：数字带符号，配色跟着走，上一期空着就只留一个破折号。
                     * 后面那截「过了几天 / 一共几天」是必须的——周一到周三只花了 38 块，
                     * 不写清天数，读者会把它读成「这周比上周省了 55 块」。 */
                    $against = function ($current, $previous, $phrase, $hint = '') {
                        list($text, $mod) = noline_bills_delta($current, $previous);

                        return '<span class="bill-tile-note' . $mod . '">'
                            . htmlspecialchars($phrase . ' ' . $text)
                            . ($hint === '' ? '' : '<i>· ' . htmlspecialchars($hint) . '</i>')
                            . '</span>';
                    };

                    $span = $summary['span'];
                    // 一周就是七天，今天在这周的第几天插件已经算好了（ISO 周几，周一=1），
                    // 自己拿日期减法算会被夏令时差一天的坑到
                    $week_done = (int)$span['weekday'];
                ?>

                <div class="bill-stats">

                    <div class="bill-tile">
                        <span class="bill-tile-label"><?php noline_icon('calendar'); ?> <?php _e('本周'); ?></span>
                        <span class="bill-tile-value"><?php echo noline_bills_money($week['amount']); ?><i><?php _e('元'); ?></i></span>
                        <?php
                            echo $against(
                                $week,
                                $summary['prev_week'],
                                _t('较上周'),
                                sprintf(_t('%1$d/%2$d 天'), $week_done, 7)
                            );
                        ?>
                    </div>

                    <div class="bill-tile">
                        <span class="bill-tile-label"><?php noline_icon('chart-line'); ?> <?php _e('本月'); ?></span>
                        <span class="bill-tile-value"><?php echo noline_bills_money($month['amount']); ?><i><?php _e('元'); ?></i></span>
                        <?php
                            echo $against(
                                $month,
                                $summary['prev_month'],
                                _t('较上月'),
                                sprintf(_t('%1$d/%2$d 天'), (int)$span['month_day_of'], (int)$span['month_days'])
                            );
                        ?>
                    </div>

                    <div class="bill-tile">
                        <span class="bill-tile-label"><?php noline_icon('clock'); ?> <?php _e('本月日均'); ?></span>
                        <span class="bill-tile-value"><?php echo noline_bills_money($month['avg_day']); ?><i><?php _e('元'); ?></i></span>
                        <span class="bill-tile-note">
                            <?php
                            echo htmlspecialchars(sprintf(
                                _t('%1$d 天有开销 · 单笔均 %2$s 元'),
                                (int)$month['days'],
                                noline_bills_money($month['avg_bill'])
                            ));
                            ?>
                        </span>
                    </div>

                    <div class="bill-tile">
                        <span class="bill-tile-label"><?php noline_icon('wallet'); ?> <?php _e('累计'); ?></span>
                        <span class="bill-tile-value"><?php echo noline_bills_money($totals['amount']); ?><i><?php _e('元'); ?></i></span>
                        <span class="bill-tile-note">
                            <?php
                            echo htmlspecialchars(sprintf(
                                _t('%1$d 笔 · 从 %2$s 记到今天'),
                                (int)$totals['count'],
                                (string)$summary['first']
                            ));
                            ?>
                        </span>
                    </div>

                </div>

                <?php $bars = noline_bills_bars($summary['months']); ?>
                <?php if ($bars !== ''): ?>
                <section class="bill-chart">
                    <div class="bill-block-head">
                        <span class="card-header"><?php noline_icon('bars'); ?><?php _e('月度支出'); ?></span>
                        <span class="bill-block-range">
                            <?php
                            echo htmlspecialchars(sprintf(
                                _t('最贵的一月 %s 元'),
                                noline_bills_money(max(array_column($summary['months'], 'amount')))
                            ));
                            ?>
                        </span>
                    </div>
                    <?php echo $bars; ?>
                </section>
                <?php endif; ?>

                <?php if ((int)$month['count'] > 0): ?>
                <section class="bill-slice">
                    <div class="bill-block-head">
                        <span class="card-header"><?php noline_icon('tag'); ?><?php _e('这个月的钱花在哪'); ?></span>
                        <span class="bill-block-range">
                            <?php echo htmlspecialchars((string)$month['start'] . ' → ' . (string)$month['end']); ?>
                        </span>
                    </div>
                    <?php echo noline_bills_kinds($summary['kinds_month'], $labels); ?>
                </section>
                <?php endif; ?>

                <?php if (!empty($month['max'])): ?>
                <p class="bill-top">
                    <?php noline_icon('scale'); ?>
                    <?php
                    echo htmlspecialchars(sprintf(
                        _t('本月最贵的一笔：%1$s · %2$s 元（%3$s）'),
                        (string)$month['max']['title'],
                        noline_bills_money($month['max']['amount']),
                        (string)$month['max']['date']
                    ));
                    ?>
                </p>
                <?php endif; ?>

                <section class="bill-log">
                    <div class="bill-block-head">
                        <span class="card-header"><?php noline_icon('receipt'); ?><?php _e('最近流水'); ?></span>
                        <span class="bill-block-range">
                            <?php echo htmlspecialchars(sprintf(_t('共 %d 笔'), (int)$totals['count'])); ?>
                        </span>
                    </div>

                    <?php foreach ($summary['by_date'] as $day): ?>
                    <div class="bill-day">
                        <div class="bill-day-head">
                            <span class="bill-day-date"><?php echo htmlspecialchars(substr((string)$day['date'], 5)); ?></span>
                            <i class="bill-day-week"><?php echo htmlspecialchars($weekday($day['date'])); ?></i>
                            <span class="bill-day-total"><?php echo noline_bills_money($day['amount']); ?><i><?php _e('元'); ?></i></span>
                        </div>

                        <?php foreach ($day['items'] as $item): ?>
                        <div class="<?php echo isset($row_class[$item['kind']]) ? $row_class[$item['kind']] : 'bill-item'; ?>">
                            <span class="bill-item-kind"><?php
                                echo htmlspecialchars(isset($labels[$item['kind']]) ? $labels[$item['kind']] : $item['kind']);
                            ?></span>
                            <span class="bill-item-title"><?php echo htmlspecialchars((string)$item['title']); ?></span>
                            <?php if ($item['shop'] !== ''): ?>
                            <span class="bill-item-shop"><?php echo htmlspecialchars((string)$item['shop']); ?></span>
                            <?php endif; ?>
                            <span class="bill-item-amount"><?php echo noline_bills_money($item['amount']); ?></span>
                        </div>
                        <?php if ($item['note'] !== ''): ?>
                        <p class="bill-item-note"><?php echo htmlspecialchars((string)$item['note']); ?></p>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php if ((int)$totals['count'] > 120): ?>
                    <p class="bill-more"><?php _e('更早的流水走接口取：GET /api/bills/records?from=&to=&limit=1000'); ?></p>
                    <?php endif; ?>
                </section>

                <?php if ($this->user->hasLogin()): ?>
                <p class="bill-foot"><?php _e('流水由 NolineApi 插件保管，主题只做展示；端点、密钥和写入开关都在插件设置页。'); ?></p>
                <?php endif; ?>

                <?php endif; ?>
            </article>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
