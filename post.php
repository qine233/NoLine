<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('public/header.php'); ?>

<?php
/* 文章首屏。值和 header.php 那趟各算各的：模板局部变量不跨 need 传递，
   而 noline_post_cover() 背后是 noline_thumbs() 的静态缓存，第二次调用不再查库。 */
$heroCover = noline_post_cover($this);
$wordCount = noline_word_count($this->text);
?>

<div class="post-hero<?php if ($heroCover !== ''): ?> has-cover<?php endif; ?>">
    <?php if ($heroCover !== ''): ?>
    <div class="post-hero-cover" style="background-image:url('<?php echo htmlspecialchars($heroCover, ENT_QUOTES, 'UTF-8'); ?>')" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="post-hero-info">
        <div class="post-hero-top">
            <?php /* sticky 是独立字段，不是分类也不是标签；没置顶就整颗不出现 */ ?>
            <?php if (!empty($this->sticky)): ?>
            <span class="post-badge"><?php _e('置顶'); ?></span>
            <?php endif; ?>
            <span class="post-cats"><?php $this->category(', ', true, _t('未分类')); ?></span>
        </div>

        <h1 class="post-hero-title"><?php $this->title(); ?></h1>

        <div class="post-hero-meta">
            <?php /* 日期不是入口，挂 is-plain 不给它静息底色，见 design.css */ ?>
            <span class="meta-pill is-plain"><?php noline_icon('clock'); ?><time datetime="<?php $this->date('c'); ?>"><?php $this->date('Y-m-d'); ?></time></span>
            <span class="meta-pill is-plain"><?php noline_icon('eye'); ?><?php _e('阅读'); ?> <b><?php echo noline_get_views($this->cid); ?></b></span>
            <?php if ($wordCount > 0): ?>
            <span class="meta-pill is-plain"><?php noline_icon('book-open'); ?><?php echo sprintf(_t('约 %d 分钟'), max(1, (int)ceil($wordCount / 350))); ?></span>
            <?php endif; ?>
            <a class="meta-pill" href="#comments"><?php noline_icon('comment'); ?><?php $this->commentsNum(_t('暂无评论'), _t('1 条评论'), _t('%d 条评论')); ?></a>
        </div>
    </div>

    <?php /* 跟手的封面小卡：整块首屏的氛围由上面那张模糊背景负责，
             这张才是读得清的封面。窄屏不显示，见 design.css 的 639px 一档。 */ ?>
    <?php if ($heroCover !== ''): ?>
    <div class="post-hero-card">
        <img src="<?php echo htmlspecialchars($heroCover, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="300" height="169">
    </div>
    <?php endif; ?>

    <div class="hero-lip" aria-hidden="true"></div>
</div>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <!-- 正文卡用 article-card：不带 hover 上浮，避免鼠标在正文里移动时整块跟着抖 -->
            <article class="card article-card">

                <div class="post-content">
                    <?php $this->content(); ?>
                </div>

                <?php
                /* 版权卡接在正文后面：日期/阅读/评论都已经在首屏那排胶囊里了，
                   这一张只管「谁写的、怎么转」。
                   刻意不放「最后更新时间」——核心只把 created 包成 Typecho_Date 对象
                   （Widget/Base/Contents.php 的 date() 只收 format，没有第二个参数），
                   $this->modified 是裸时间戳，自己 date() 格式化会跟着 PHP 默认时区走，
                   和站内其它日期未必同一天。 */
                $heroBio = trim((string)$this->options->logobg);
                if ($heroBio === '') {
                    $heroBio = trim((string)$this->options->description);
                }
                /* 取一次就好：$archive->author 是魔法 getter，两层头像各跑一遍
                   就是两次作者查表（widget 按类名缓存，代价小，但没必要）。 */
                $heroAvatar = noline_author_avatar($this);
                ?>
                <div class="post-copyright">
                    <?php /* 两层同一个头像，hover 时前面回落、后面退开，见 design.css 的版权卡一段 */ ?>
                    <span class="post-copyright-avatar" aria-hidden="true">
                        <img src="<?php echo $heroAvatar; ?>" alt="" class="post-copyright-avatar-back" width="66" height="66" loading="lazy">
                        <img src="<?php echo $heroAvatar; ?>" alt="" class="post-copyright-avatar-front" width="66" height="66" loading="lazy">
                    </span>
                    <p class="post-copyright-name"><?php $this->author(); ?></p>
                    <?php if ($heroBio !== ''): ?>
                    <p class="post-copyright-desc"><?php echo htmlspecialchars($heroBio, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <div class="post-copyright-badges">
                        <span class="post-copyright-badge"><?php _e('转载请注明出处'); ?></span>
                    </div>
                </div>

                <?php $postTags = $this->tags; ?>
                <?php if (!empty($postTags)): ?>
                <div class="post-tags">
                    <?php foreach ($postTags as $tag): ?>
                    <a class="tag" href="<?php echo htmlspecialchars($tag['permalink'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </article>

            <?php /* 随机去往：noline_random_url() 在 PHP 侧 array_rand 挑一篇（含日记，
                      随机不能写进 SQL，order('RAND()') 会被查询构造器当列名包反引号）。
                      URL 走 Typecho_Router::url 不手拼。没文章时返回空串，整张卡不出现。 */ ?>
            <?php $randomUrl = noline_random_url(); ?>
            <?php if ($randomUrl !== ''): ?>
            <div class="card random-card">
                <p><?php _e('不知道看什么？'); ?></p>
                <a class="btn-primary" href="<?php echo htmlspecialchars($randomUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php _e('随机去往一篇'); ?></a>
            </div>
            <?php endif; ?>

            <?php
            /* 上下篇是一条玻璃 dock，左右两格。
               thePrev() 取更早的一篇、theNext() 取更晚的一篇——核心 var/Widget/Archive.php
               里 thePrev 的条件是 created < 当前、theNext 是 created > 当前且 < 现在，
               命名和直觉相反。某一篇不存在时核心只输出第二个实参（这里传空串），
               整个格子跟着不输出——所以 design.css 里的分栏线用「左边还有另一格」
               那条相邻选择器，单格时它自己就是第一条，线不会出现。 */
            ?>
            <nav class="article-nav" aria-label="<?php _e('上一篇 / 下一篇'); ?>">
                <div class="article-nav-dock">
                    <?php
                    /* 格式串里的 %%s 由 thePrev/theNext 内部的 printf 换成标题链接 */
                    $cellFormat = '<div class="article-nav-cell%s"><span class="article-nav-label">%s</span>%%s</div>';
                    $this->thePrev(sprintf($cellFormat, '', _t('上一篇')), '', array('tagClass' => 'article-nav-title'));
                    $this->theNext(sprintf($cellFormat, ' article-nav-next', _t('下一篇')), '', array('tagClass' => 'article-nav-title'));
                    ?>
                </div>
            </nav>

            <?php /* 相关文章：同分类优先、最近发布补齐，最多 3 条。取数理由见 noline_related_posts()。 */ ?>
            <?php $related = noline_related_posts($this); ?>
            <?php if (!empty($related)): ?>
            <section class="related">
                <h2 class="related-headline">
                    <?php noline_icon('heart'); ?>
                    <span class="related-lead"><?php _e('喜欢这篇文章的人也看了'); ?></span>
                    <?php if ($randomUrl !== ''): ?>
                    <a class="related-random" href="<?php echo htmlspecialchars($randomUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php _e('随便逛逛'); ?></a>
                    <?php endif; ?>
                </h2>

                <div class="related-list">
                    <?php foreach ($related as $item): ?>
                    <a class="related-item"
                       href="<?php echo htmlspecialchars($item['permalink'], ENT_QUOTES, 'UTF-8'); ?>"
                       title="<?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if ($item['thumb'] !== ''): ?>
                        <span class="related-cover" style="background-image:url('<?php echo htmlspecialchars($item['thumb'], ENT_QUOTES, 'UTF-8'); ?>')" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="related-text">
                            <span class="related-title"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="related-date"><?php echo $item['date']; ?></span>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php $this->need('comments.php'); ?>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
