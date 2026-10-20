<?php
/**
 * Noline —— 简洁的双栏 Typecho 主题
 *
 * @package Noline
 * @author QINE
 * @version 1.29.4
 * @link https://www.idkzr.com/
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('public/header.php');
?>

<?php
/* 首页 masthead：顶栏管导航，这块管站点身份——封面 + 居中大头像 + 站名 + 座右铭。
   值在这里重算一遍：header.php 的局部变量不会流进模板作用域。 */
$heroName = trim((string)$this->options->logoName);
if ($heroName === '') {
    $heroName = trim((string)$this->options->title);
}
$heroBio = trim((string)$this->options->logobg);
if ($heroBio === '') {
    $heroBio = trim((string)$this->options->description);
}
$heroAvatar = noline_profile_avatar();
$heroCover  = trim((string)$this->options->logobgcolor);
?>

<?php /* 首屏必须待在 .container 外面：版心只有 1200px，压在里面的封面就不通栏了，
       两侧会各留一条页底色。它自己的 --hero-pad-x 负责把文字拉回版心竖线上。 */ ?>
<div class="site-hero<?php if ($heroCover !== ''): ?> has-cover<?php endif; ?>">
    <?php if ($heroCover !== ''): ?>
    <div class="site-hero-cover" style="background-image:url('<?php echo htmlspecialchars($heroCover, ENT_QUOTES, 'UTF-8'); ?>')" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="site-hero-inner">
        <?php /* width/height 必须和 CSS 的 136px 一致：属性值是首帧占位的唯一依据，
                 写小了图片加载完会把下面整块内容顶下去。 */ ?>
        <?php if ($heroAvatar !== null): ?>
        <img src="<?php echo $heroAvatar; ?>" alt="" class="site-hero-avatar" width="136" height="136">
        <?php else: ?>
        <div class="site-hero-avatar site-hero-placeholder" aria-hidden="true"><?php echo noline_initial($heroName); ?></div>
        <?php endif; ?>
        <h1 class="site-hero-title"><?php echo htmlspecialchars($heroName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($heroBio !== ''): ?>
        <p class="site-hero-bio"><?php echo htmlspecialchars($heroBio, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>
    <div class="hero-lip" aria-hidden="true"></div>
</div>

<div class="container">
    <div class="grid-layout">

        <div class="main-content feed">
            <?php /* 公告卡：notice 留空时整张卡不出现，不留空框 */ ?>
            <?php $notice = trim((string)$this->options->notice); ?>
            <?php if ($notice !== ''): ?>
            <div class="card card-widget">
                <div class="card-header"><?php noline_icon('bullhorn'); ?><?php _e('公告'); ?></div>
                <div class="notice-body">
                    <span class="notice-icon" aria-hidden="true">📢</span>
                    <div class="notice-text"><?php echo $notice; ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php /* 吸顶的分类药丸条。文章卡和动态卡都算一个位置，所以翻页偏移用
                   「(当前页 - 1) × 每页条数」，而不是本页计数——第 2 页第一张的左右
                   朝向必须接得上第 1 页最后一张，自动加载拼起来才不会出现两张同侧。
                   currentPage / parameter->pageSize 都是 Widget_Archive 的真实成员
                   （var/Widget/Archive.php 的 ____currentPage() 与 parameter）。 */ ?>
            <?php $feedIndex = ((int)$this->currentPage - 1) * (int)$this->parameter->pageSize; ?>
            <?php $this->need('public/feed-cats.php'); ?>

            <?php if ($this->have()): ?>
                <?php $thumbs = noline_thumbs(); ?>
                <?php while ($this->next()): ?>
                    <?php $flip = ($feedIndex++ % 2) ? ' flip' : ''; ?>

                    <?php /* 动态和文章混在同一条时间线里按发布时间穿插，分页逻辑不变。
                           命中动态分类的走 status-card.php 然后 continue，
                           用 continue 而不是 if/else 包住下面整个 article，
                           省得把二十多行标记整体再缩进一层。 */ ?>
                    <?php if (noline_is_diary($this)): ?>
                        <?php $this->need('public/status-card.php'); ?>
                        <?php continue; ?>
                    <?php endif; ?>

                    <?php $thumb = isset($thumbs[(int)$this->cid]) ? $thumbs[(int)$this->cid] : ''; ?>
                    <?php $wordCount = noline_word_count($this->text); ?>
                <article class="card post-card<?php if ($thumb !== ''): ?> has-cover<?php endif; ?><?php echo $flip; ?>">
                    <?php if ($thumb !== ''): ?>
                    <a class="post-cover" href="<?php $this->permalink(); ?>" tabindex="-1" aria-hidden="true">
                        <img src="<?php echo htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
                    </a>
                    <?php endif; ?>
                    <div class="post-card-body">
                        <div class="post-header">
                            <img src="<?php echo noline_author_avatar($this); ?>" alt="" class="avatar" width="40" height="40" loading="lazy">
                            <div class="header-text">
                                <div class="author-name"><?php $this->author(); ?></div>
                                <div class="post-meta">
                                    <span class="meta-item"><?php noline_icon('clock'); ?><time datetime="<?php $this->date('c'); ?>"><?php $this->date('Y-m-d'); ?></time></span>
                                    <span class="category-tag"><?php $this->category(', ', true, _t('未分类')); ?></span>
                                    <?php if ($wordCount > 0): ?>
                                    <span class="meta-item"><?php noline_icon('book-open'); ?><?php echo sprintf(_t('约 %d 分钟'), max(1, (int)ceil($wordCount / 350))); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <h2 class="post-title">
                            <a class="post-title-link" href="<?php $this->permalink(); ?>"><?php $this->title(); ?></a>
                        </h2>

                        <div class="post-excerpt"><?php $this->excerpt(140, '…'); ?></div>

                        <div class="post-footer">
                            <span class="stat-item"><?php noline_icon('eye'); ?> <?php _e('阅读'); ?> <?php echo noline_get_views($this->cid); ?></span>
                            <a class="stat-item" href="<?php $this->permalink(); ?>#comments"><?php noline_icon('comment'); ?> <?php _e('评论'); ?> <?php $this->commentsNum('0', '1', '%d'); ?></a>
                            <a class="read-more" href="<?php $this->permalink(); ?>"><?php _e('阅读全文'); ?></a>
                        </div>

                        <?php noline_comment_preview($this); ?>
                    </div>
                </article>
                <?php endwhile; ?>

                <?php $this->pageNav('&laquo; ' . _t('前一页'), _t('后一页') . ' &raquo;'); ?>
                <?php $this->need('public/feed-status.php'); ?>
            <?php else: ?>
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-state-icon"><?php noline_icon('sprout'); ?></div>
                        <p class="empty-state-text"><?php _e('这里还什么都没有，先去写点什么吧。'); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
