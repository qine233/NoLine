<?php
/**
 * 健康记录
 *
 * 体重 / BMI 的个人页。数据存在 NolineApi 插件的记录文件里（usr/uploads/noline_api/store.php），
 * 这一页只负责画：后台「插件 → NolineApi」填身高、目标体重和可见性，
 * 自己或用 App 调接口写数，写完这里立刻能看到。
 *
 * 后台新建页面时模板选「健康记录」，slug 建议用 health。
 *
 * @package custom
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

$plugin = noline_health_plugin();
$summary = noline_health_summary();
$visible = noline_health_visible($this);

$this->need('public/header.php');
?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title"><?php $this->title(); ?></h1>
                <p class="page-subtitle"><?php noline_icon('scale'); ?> <?php _e('体重、BMI 与它们的走势'); ?></p>

                <?php if (trim((string)$this->text) !== ''): ?>
                <div class="post-content"><?php $this->content(); ?></div>
                <?php endif; ?>

                <?php if ($plugin === null): ?>

                <div class="empty-state">
                    <div class="empty-state-icon"><?php noline_icon('circle-info'); ?></div>
                    <p class="empty-state-text"><?php _e('还没有安装 NolineApi 插件。'); ?></p>
                    <p class="empty-state-text"><?php _e('把插件目录放进 usr/plugins/NolineApi/，在「控制台 → 插件」里启用后，这一页才会开始显示记录。'); ?></p>
                </div>

                <?php elseif (!$visible): ?>

                <div class="empty-state">
                    <div class="empty-state-icon"><?php noline_icon('moon'); ?></div>
                    <p class="empty-state-text"><?php _e('这一页在插件里设成了「仅自己可见」。'); ?></p>
                    <p class="empty-state-text"><?php _e('登录后台后刷新即可看到。'); ?></p>
                </div>

                <?php elseif (empty($summary['count'])): ?>

                <div class="empty-state">
                    <div class="empty-state-icon"><?php noline_icon('sprout'); ?></div>
                    <p class="empty-state-text"><?php _e('还没有体重记录。'); ?></p>
                    <p class="empty-state-text"><?php _e('用 App、curl 或后台直接往接口提一条，日期 + 体重就够了；端点清单在插件设置页。'); ?></p>
                </div>

                <?php else: ?>

                <?php
                    $profile = $summary['profile'];
                    $latest = $summary['latest'];
                    $stats = $summary['stats'];
                    $goal = $summary['goal'];
                    $changes = $summary['changes'];
                    $daySpan = max(1, (int)floor(
                        (strtotime((string)$summary['last']) - strtotime((string)$summary['first'])) / 86400
                    ));
                    $labels = array(
                        'thin' => _t('偏瘦'), 'normal' => _t('正常'),
                        'over' => _t('超重'), 'obese' => _t('肥胖'),
                    );
                    $trend = noline_health_trend($summary['series']);
                    // 刻度数字取折线真正用的那套纵轴范围，不取 stats 的极值
                    $axis = noline_health_axis($summary['series']);

                    /** 变化值 → 「+0.4kg / -0.6kg / 持平 / —」，配色跟着走 */
                    $deltaText = function ($delta) {
                        if ($delta === null) {
                            return '—';
                        }
                        if (abs((float)$delta) < 0.05) {
                            return _t('持平');
                        }
                        return ($delta > 0 ? '+' : '') . $delta . 'kg';
                    };
                    $deltaClass = function ($delta) {
                        if ($delta === null || abs((float)$delta) < 0.05) {
                            return ' is-quiet';
                        }
                        return $delta > 0 ? ' is-up' : ' is-down';
                    };

                    /**
                     * BMI 分档 → 整串徽标类名。类名在这里写全，不在 class 属性里做
                     * 'is-' . $cat 的拼接——那样 _dev/classcheck.py 只能扫到 'is-'，
                     * 真正的 is-thin / is-obese 会被当成死样式报出来。
                     */
                    $badgeClass = array(
                        'thin' => 'health-badge is-thin',
                        'normal' => 'health-badge is-normal',
                        'over' => 'health-badge is-over',
                        'obese' => 'health-badge is-obese',
                    );
                    $badge = function ($cat) use ($badgeClass) {
                        return isset($badgeClass[$cat]) ? $badgeClass[$cat] : 'health-badge';
                    };
                ?>

                <div class="health-stats">

                    <div class="health-tile">
                        <span class="health-tile-label"><?php noline_icon('scale'); ?> <?php _e('当前体重'); ?></span>
                        <span class="health-tile-value"><?php echo (float)$latest['weight']; ?><i>kg</i></span>
                        <span class="health-tile-note">
                            <?php
                            echo $latest['delta'] === null
                                ? _t('第一条记录')
                                : htmlspecialchars(_t('较上次') . ' ' . $deltaText($latest['delta']));
                            ?>
                        </span>
                    </div>

                    <div class="health-tile">
                        <span class="health-tile-label"><?php noline_icon('heart'); ?> <?php _e('BMI'); ?></span>
                        <?php if ($latest['bmi'] === null): ?>
                        <span class="health-tile-value">—</span>
                        <span class="health-tile-note"><?php _e('先在插件设置里填身高'); ?></span>
                        <?php else: ?>
                        <span class="health-tile-value"><?php echo (float)$latest['bmi']; ?></span>
                        <span class="health-tile-note">
                            <?php
                            $cat = (string)$latest['category'];
                            echo '<b class="' . $badge($cat) . '">'
                                . htmlspecialchars(isset($labels[$cat]) ? $labels[$cat] : $cat) . '</b> · '
                                . htmlspecialchars((string)$latest['date']);
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="health-tile">
                        <span class="health-tile-label"><?php noline_icon('compass'); ?> <?php _e('目标'); ?></span>
                        <?php if ($goal === null): ?>
                        <span class="health-tile-value">
                            <?php if ($profile['ideal'] === null): ?>—<?php else: ?>
                            <?php echo (float)$profile['ideal']['low']; ?>–<?php echo (float)$profile['ideal']['high']; ?><i>kg</i>
                            <?php endif; ?>
                        </span>
                        <span class="health-tile-note"><?php _e('理想体重区间'); ?></span>
                        <?php else: ?>
                        <span class="health-tile-value"><?php echo abs((float)$goal['remaining']); ?><i>kg</i></span>
                        <span class="health-tile-note">
                            <?php
                            echo $goal['done']
                                ? _t('已经站在目标上')
                                : htmlspecialchars((float)$goal['remaining'] > 0 ? _t('还要减这么多') : _t('还要增这么多'));
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="health-tile">
                        <span class="health-tile-label"><?php noline_icon('clock'); ?> <?php _e('记录'); ?></span>
                        <span class="health-tile-value"><?php echo (int)$summary['count']; ?><i><?php _e('次'); ?></i></span>
                        <span class="health-tile-note">
                            <?php
                            echo htmlspecialchars(sprintf(
                                _t('跨 %1$d 天 · 均值 %2$skg'),
                                $daySpan,
                                (string)$stats['avg']
                            ));
                            ?>
                        </span>
                    </div>

                </div>

                <?php if ($trend !== ''): ?>
                <section class="health-chart">
                    <div class="health-block-head">
                        <span class="card-header"><?php noline_icon('chart-line'); ?><?php _e('体重趋势'); ?></span>
                        <span class="health-block-range">
                            <?php echo htmlspecialchars((string)$summary['first'] . ' → ' . (string)$summary['last']); ?>
                        </span>
                    </div>

                    <div class="health-plot">
                        <span class="health-axis"><?php echo (float)$axis['max']; ?></span>
                        <?php echo $trend; ?>
                        <span class="health-axis"><?php echo (float)$axis['min']; ?></span>
                    </div>

                    <div class="health-deltas">
                        <?php foreach (array(7, 30, 90) as $span): ?>
                        <?php
                            $key = 'd' . $span;
                            $delta = isset($changes[$key]) ? $changes[$key]['delta'] : null;
                            $from = isset($changes[$key]) ? $changes[$key]['from_date'] : null;
                        ?>
                        <span class="health-delta<?php echo $deltaClass($delta); ?>">
                            <b><?php echo htmlspecialchars($deltaText($delta)); ?></b>
                            <i><?php
                                echo htmlspecialchars(
                                    sprintf(_t('%d 天'), $span) . ($from === null ? '' : ' · ' . $from)
                                );
                            ?></i>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <section class="health-log">
                    <div class="health-block-head">
                        <span class="card-header"><?php noline_icon('clock'); ?><?php _e('最近记录'); ?></span>
                        <?php if ($profile['height'] !== null): ?>
                        <span class="health-block-range">
                            <?php echo htmlspecialchars(sprintf(_t('身高 %scm'), (string)$profile['height'])); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php foreach (array_reverse(array_slice($summary['series'], -12)) as $row): ?>
                    <?php $delta = $row['delta']; ?>
                    <div class="health-row">
                        <span class="health-row-date"><?php echo htmlspecialchars(substr((string)$row['date'], 5)); ?></span>
                        <span class="health-row-weight">
                            <?php echo (float)$row['weight']; ?><i>kg</i>
                        </span>
                        <span class="health-row-delta<?php echo $deltaClass($delta); ?>">
                            <?php echo htmlspecialchars($deltaText($delta)); ?>
                        </span>
                        <span class="health-row-bmi">
                            <?php if ($row['bmi'] !== null): ?>
                            <b class="<?php echo $badge((string)$row['category']); ?>">
                                <?php echo (float)$row['bmi']; ?>
                            </b>
                            <?php else: ?>—<?php endif; ?>
                        </span>
                        <?php if ($row['note'] !== ''): ?>
                        <span class="health-row-note"><?php echo htmlspecialchars((string)$row['note']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php if ((int)$summary['count'] > 12): ?>
                    <p class="health-more">
                        <?php echo htmlspecialchars(sprintf(_t('更早的 %d 条走接口取：GET /api/health/records?limit=366'), (int)$summary['count'] - 12)); ?>
                    </p>
                    <?php endif; ?>
                </section>

                <?php if ($this->user->hasLogin()): ?>
                <p class="health-foot"><?php _e('记录由 NolineApi 插件保管，主题只做展示；端点、密钥和写入开关都在插件设置页。'); ?></p>
                <?php endif; ?>

                <?php endif; ?>
            </article>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
