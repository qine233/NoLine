<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 微博式动态卡。
 *
 * 由 index.php / archive.php 在 while ($this->next()) 循环里 need()，
 * 所以这里的 $this 就是当前这一篇（Widget_Archive::need() 只是个 require，
 * 不动 row，循环里调用是安全的）。
 *
 * 和普通文章卡（.post-card）的三处区别：
 *   1. 不出标题 —— 动态没有标题这回事；
 *   2. 正文用 content() 全量渲染，不是 excerpt(140) 那种截断的纯文本，
 *      图片、链接、代码都还在；
 *   3. 时间戳是唯一的详情入口，正文里没有「阅读全文」。
 *
 * 长内容由 main.js 量过之后才挂 .is-clamped 并放开「全文」按钮。按钮初始带
 * hidden —— 脚本没跑起来（404、被扩展拦下）时正文就是全量铺开，不会把内容藏掉。
 */
?>
<article class="card status-card">
    <div class="status-main">
        <img src="<?php echo noline_author_avatar($this); ?>" alt="" class="status-avatar" width="42" height="42" loading="lazy">

        <div class="status-body">
            <div class="status-author"><?php $this->author(); ?></div>

            <div class="status-meta">
                <a class="status-time" href="<?php $this->permalink(); ?>"
                   title="<?php $this->date('Y-m-d H:i'); ?>"><?php echo noline_time_ago($this->created); ?></a>
            </div>

            <div class="status-fold">
                <div class="status-content"><?php $this->content(); ?></div>
                <button type="button" class="status-toggle" hidden aria-expanded="false"><?php _e('全文'); ?></button>
            </div>

            <div class="status-actions">
                <span class="status-action"><?php noline_icon('eye'); ?> <?php _e('阅读'); ?> <?php echo noline_get_views($this->cid); ?></span>
                <a class="status-action" href="<?php $this->permalink(); ?>#comments"><?php noline_icon('comment'); ?> <?php _e('评论'); ?> <?php $this->commentsNum('0', '1', '%d'); ?></a>
            </div>

            <?php /* 放在 .status-body 里而不是 .status-main 之后：
                     这样它和正文左对齐，不会跑到头像下面去。 */ ?>
            <?php noline_comment_preview($this); ?>
        </div>
    </div>
</article>
