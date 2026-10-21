<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('public/header.php'); ?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content feed">

            <div class="archive-header">
                <h1 class="archive-title">
                    <?php $this->archiveTitle(array(
                        'category' => _t('分类：%s'),
                        'search'   => _t('搜索：%s'),
                        'tag'      => _t('标签：%s'),
                        'author'   => _t('作者：%s'),
                        'date'     => _t('%s')
                    ), '', ''); ?>
                </h1>
                <p class="archive-count"><?php noline_icon('file-lines'); ?><?php _e('共 %d 篇文章', $this->getTotal()); ?></p>
            </div>

            <?php /* 和 index.php 同一张药丸条、同一套左右朝向计数，理由见那边注释。 */ ?>
            <?php $feedIndex = ((int)$this->currentPage - 1) * (int)$this->parameter->pageSize; ?>
            <?php $this->need('public/feed-cats.php'); ?>
            <?php /* 和 index.php 同一颗桌面入口，理由见那边的注释。 */ ?>
            <?php $this->need('public/feed-desk.php'); ?>

            <?php if ($this->have()): ?>
                <?php $thumbs = noline_thumbs(); ?>
                <?php while ($this->next()): ?>
                    <?php $flip = ($feedIndex++ % 2) ? ' flip' : ''; ?>

                    <?php /* 和 index.php 保持同一套分流：从侧边栏点进「日记」分类时，
                           看到的应该是动态卡而不是带标题的文章卡。 */ ?>
                    <?php if (noline_is_diary($this)): ?>
                        <?php $this->need('public/status-card.php'); ?>
                        <?php continue; ?>
                    <?php endif; ?>

                    <?php $thumb = isset($thumbs[(int)$this->cid]) ? $thumbs[(int)$this->cid] : ''; ?>
                    <?php $wordCount = noline_word_count($this->text); ?>
                <article class="card post-card<?php if ($thumb !== ''): ?> has-cover<?php endif; ?><?php echo $flip; ?>"
                         data-cid="<?php $this->cid(); ?>">
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
                        <div class="empty-state-icon"><?php noline_icon('paper-plane'); ?></div>
                        <p class="empty-state-text"><?php _e('没有找到相关内容。'); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
