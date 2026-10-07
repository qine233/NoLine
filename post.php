<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('public/header.php'); ?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <!-- 正文卡用 article-card：不带 hover 上浮，避免鼠标在正文里移动时整块跟着抖 -->
            <article class="card article-card">

                <div class="post-header">
                    <img src="<?php echo noline_author_avatar($this); ?>" alt="" class="avatar" width="40" height="40" loading="lazy">
                    <div class="header-text">
                        <div class="author-name"><?php $this->author(); ?></div>
                        <div class="post-meta">
                            <span class="meta-item"><?php noline_icon('clock'); ?><time datetime="<?php $this->date('c'); ?>"><?php $this->date('Y-m-d'); ?></time></span>
                            <span class="category-tag"><?php $this->category(', ', true, _t('未分类')); ?></span>
                        </div>
                    </div>
                </div>

                <h1 class="post-title"><?php $this->title(); ?></h1>

                <div class="post-content">
                    <?php $this->content(); ?>
                </div>

                <div class="post-footer">
                    <span class="stat-item"><?php noline_icon('eye'); ?> <?php _e('阅读'); ?> <?php echo noline_get_views($this->cid); ?></span>
                    <a class="stat-item" href="#comments"><?php noline_icon('comment'); ?> <?php $this->commentsNum(_t('暂无评论'), _t('1 条评论'), _t('%d 条评论')); ?></a>
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

            <nav class="article-nav">
                <?php
                /* thePrev() 是更早的一篇，theNext() 是更晚的一篇（Typecho 的命名和直觉相反）。
                   格式串里的 %s 由 thePrev/theNext 内部的 printf 填入标题链接。 */
                $cellFormat = '<div class="article-nav-cell%s"><span class="article-nav-label">%s</span>%%s</div>';
                $this->thePrev(sprintf($cellFormat, '', _t('上一篇')), '', array('tagClass' => 'article-nav-title'));
                $this->theNext(sprintf($cellFormat, ' article-nav-next', _t('下一篇')), '', array('tagClass' => 'article-nav-title'));
                ?>
            </nav>

            <?php $this->need('comments.php'); ?>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
