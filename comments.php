<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 评论区。
 *
 * listComments() 的 before/after 会在每一层嵌套都输出一对标签，所以这里必须给回
 * <ol>——传空串会让 <li class="comment-body"> 直接裸露在 <div class="comment-list"> 里，
 * 既不是合法 HTML，design.css 里针对 ol 的规则也全都匹配不上。
 */
$this->comments()->to($comments);
?>

<div id="comments" class="card article-card">

    <h2 class="comments-header">
        <?php noline_icon('comments'); ?>
        <?php _e('评论'); ?>
        <span class="count"><?php $this->commentsNum('%d'); ?></span>
    </h2>

    <?php if ($this->allow('comment')): ?>
    <!-- 这个 div 的 id 由 respondId() 生成，Typecho 的评论 JS 会把整块搬到被回复的评论下面 -->
    <div id="<?php $this->respondId(); ?>" class="comment-respond">
        <div class="comment-form">
            <div class="comment-form-head">
                <h3 class="comment-form-title"><?php _e('添加新评论'); ?></h3>
                <?php $comments->cancelReply(); ?>
            </div>

            <form method="post" action="<?php $this->commentUrl(); ?>" id="comment-form" role="form">
                <?php if ($this->user->hasLogin()): ?>
                <p class="comment-login-as">
                    <?php _e('登录身份：'); ?>
                    <a href="<?php $this->options->profileUrl(); ?>"><?php $this->user->screenName(); ?></a>
                    <a href="<?php $this->options->logoutUrl(); ?>" rel="nofollow"><?php _e('退出'); ?> &raquo;</a>
                </p>
                <?php else: ?>
                <div class="comment-form-grid">
                    <div class="comment-field">
                        <label class="sr-only" for="author"><?php _e('称呼'); ?></label>
                        <input type="text" name="author" id="author" class="comment-input"
                               placeholder="<?php _e('昵称 *'); ?>" value="<?php $this->remember('author'); ?>" required>
                    </div>
                    <div class="comment-field">
                        <label class="sr-only" for="mail">Email</label>
                        <input type="email" name="mail" id="mail" class="comment-input"
                               placeholder="Email<?php if ($this->options->commentsRequireMail): ?> *<?php endif; ?>"
                               value="<?php $this->remember('mail'); ?>"<?php if ($this->options->commentsRequireMail): ?> required<?php endif; ?>>
                    </div>
                </div>
                <div class="comment-field">
                    <label class="sr-only" for="url"><?php _e('网站'); ?></label>
                    <input type="url" name="url" id="url" class="comment-input"
                           placeholder="<?php _e('网站 (https://)'); ?><?php if ($this->options->commentsRequireURL): ?> *<?php endif; ?>"
                           value="<?php $this->remember('url'); ?>"<?php if ($this->options->commentsRequireURL): ?> required<?php endif; ?>>
                </div>
                <?php endif; ?>

                <label class="sr-only" for="textarea"><?php _e('评论内容'); ?></label>
                <textarea rows="8" cols="50" name="text" id="textarea" class="comment-textarea"
                          placeholder="<?php _e('说点什么吧…'); ?>" required></textarea>

                <div class="comment-form-actions">
                    <button type="submit" class="submit-btn"><?php _e('提交评论'); ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php else: ?>
    <p class="comment-closed"><?php _e('评论已关闭'); ?></p>
    <?php endif; ?>

    <?php if ($comments->have()): ?>
    <div class="comment-list">
        <?php $comments->listComments(array(
            'before'     => '<ol>',
            'after'      => '</ol>',
            'avatarSize' => 48,
            'replyWord'  => _t('回复')
        )); ?>
    </div>

    <?php $comments->pageNav('&laquo; ' . _t('前一页'), _t('后一页') . ' &raquo;'); ?>
    <?php endif; ?>

</div>
