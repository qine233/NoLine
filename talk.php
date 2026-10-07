<?php
/**
 * 说说 / 时光动态
 *
 * 把这个页面的评论当成微博式的短内容来展示：一条评论就是一条说说。
 * 后台新建页面时模板选「说说」，并且只给自己（登录用户）开放投稿。
 *
 * @package custom
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

$this->comments()->to($comments);
$showAvatar = (bool)$this->options->commentsAvatar;
$intro = trim((string)$this->text);

$this->need('public/header.php');
?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title"><?php $this->title(); ?></h1>
                <?php if ($intro !== ''): ?>
                <div class="post-content"><?php $this->content(); ?></div>
                <?php endif; ?>
            </article>

            <div id="comments" class="card article-card">
                <h2 class="comments-header">
                    <?php noline_icon('comments'); ?>
                    <?php _e('时光动态'); ?>
                    <span class="count"><?php $this->commentsNum('%d'); ?></span>
                </h2>

                <?php if ($this->allow('comment')): ?>
                <!-- 这个 div 的 id 由 respondId() 生成，Typecho 的评论 JS 靠它定位表单 -->
                <div id="<?php $this->respondId(); ?>" class="comment-respond">
                    <div class="comment-form">
                        <?php if ($this->user->hasLogin()): ?>
                        <div class="comment-form-head">
                            <h3 class="comment-form-title"><?php _e('写点今天的想法'); ?></h3>
                        </div>

                        <form method="post" action="<?php $this->commentUrl(); ?>" id="comment-form" role="form">
                            <p class="comment-login-as">
                                <?php _e('登录身份：'); ?>
                                <a href="<?php $this->options->profileUrl(); ?>"><?php $this->user->screenName(); ?></a>
                            </p>

                            <label class="sr-only" for="textarea"><?php _e('说说内容'); ?></label>
                            <?php /* 不要用 $this->remember('text')：remember() 只认 author/mail/url，传 text 永远返回空串。 */ ?>
                            <textarea rows="6" cols="50" name="text" id="textarea" class="comment-textarea"
                                      placeholder="<?php _e('说点什么吧…'); ?>" required></textarea>

                            <div class="comment-form-actions">
                                <button type="submit" class="submit-btn"><?php _e('发送'); ?></button>
                            </div>
                        </form>
                        <?php else: ?>
                        <p class="comment-closed"><?php _e('这里是博主的私人说说，登录后才能发布。'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($comments->have()): ?>
                <div class="talk-list">
                    <ol>
                        <?php while ($comments->next()): ?>
                        <li class="talk-item" id="<?php $comments->theId(); ?>">
                            <?php if ($showAvatar): ?>
                            <!-- 不用 $comments->gravatar()：它写死 class="avatar"，
                                 而且后台关掉评论头像时会整块不输出，时间线会左右错位。 -->
                            <img class="talk-avatar" width="42" height="42" loading="lazy"
                                 src="<?php echo htmlspecialchars(noline_gravatar($comments->mail, 84), ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="">
                            <?php endif; ?>
                            <div class="talk-body">
                                <div class="talk-meta">
                                    <span class="talk-author"><?php echo htmlspecialchars((string)$comments->author, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <time datetime="<?php $comments->date('c'); ?>" title="<?php $comments->date('Y-m-d H:i'); ?>">
                                        <?php echo noline_time_ago($comments->created); ?>
                                    </time>
                                </div>
                                <div class="talk-content"><?php $comments->content(); ?></div>
                            </div>
                        </li>
                        <?php endwhile; ?>
                    </ol>
                </div>

                <?php $comments->pageNav('&laquo; ' . _t('前一页'), _t('后一页') . ' &raquo;'); ?>
                <?php else: ?>
                <div class="empty-state">
                    <p class="empty-state-text"><?php _e('还没有说说，来写第一条吧。'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
