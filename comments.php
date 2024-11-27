<?php function threadedComments($comments, $options) {
    $commentClass = '';
    if ($comments->authorId) {
if ($comments->authorId == $comments->ownerId) {
$commentClass .= ' comment-by-author';
} else {
$commentClass .= ' comment-by-user';
}
}

$commentLevelClass = $comments->levels > 0 ? ' comment-child' : ' comment-parent';
?>

<div id="<?php $comments->theId(); ?>" class="CommentsId" >
   <div class="comment-user-flex"> <?php $email=$comments->mail; $imgUrl = getGravatar($email);echo '<img src="'.$imgUrl.'" width="45px" height="45px" style="border-radius: 20%;box-shadow: 2px 2px 3px #e1e1e1;" >'; ?>
    <h5 class="comment-list-username"><?php $comments->author(); ?></h5>
    </div>
    <div id="comment_list">
        <?php $comments->content(); ?>
    </div>
    <h5 class="comment-list-time"><?php $comments->date('Y年m月d日 H:i'); ?><p class="submit"><?php $comments->reply(); ?></p></h5>  
    <?php if ($comments->children) { ?>
    <?php $comments->threadedComments($options); ?>
    <?php } ?>
    </div>



<?php } ?>
<div class="comments border-wid">
    <div id="comments-padding">
        <?php $this->comments()->to($comments); ?>
        <?php if ($this->allow('comment')) : ?>
        <!-- 评论表单form放的地方-->
        <div id="<?php $this->respondId(); ?>"  class="comments-type" >
        <h4 style="margin-left: 1rem;font-size: 14px;color: rgb(53 75 96 / 73%);"><?php $this->commentsNum(_t('评论'), _t('评论'), _t('评论')); ?></h4>

           
            <form method="post" action="<?php $this->commentUrl() ?>" id="comment-form" role="form">
    <div class="comments-3-text">

        <textarea rows="8" cols="50" name="text" id="textarea" placeholder="写出你精彩的想法"  class="textarea" required ><?php $this->remember('text'); ?></textarea>
                <?php if($this->user->hasLogin()): ?>
               <div> <p><?php _e('登录身份: '); ?><a href="<?php $this->options->profileUrl(); ?>"><?php $this->user->screenName(); ?></a>. <a href="<?php $this->options->logoutUrl(); ?>" title="Logout"><?php _e('退出'); ?> &raquo;</a> <button type="submit" class="submit-alone"><?php _e('发表'); ?></button></p></div></div>
                <?php else: ?>
                    <div class="comments-unuser">
                        <input type="text"  placeholder="昵称(*)" name="author" class="text"  value="<?php $this->remember('author'); ?>" />
                        <input type="text" name="mail" class="text" placeholder="邮箱(*)"  value="<?php $this->remember('mail'); ?>" />
                        <input type="text" name="url" class="text" placeholder="网址"  value="<?php $this->remember('url'); ?>" />
                        <button type="submit" class="submit-alone"><?php _e('发表'); ?></button>
                    </div>
                  
                    <div class="talk-content">
                    </div>
                   </div><?php endif; ?>  
                    <div style="width: 100%;"> <!-- 取消回复 -->
                     <?php $comments->cancelReply(); ?>
                     </div>
            </form>
        </div>
        <?php else : ?>
        <h4><?php _e('评论已走丢'); ?></h4>
        <?php endif; ?>

        <!-- 回复列表 -->

        <?php if ($comments->have()) : ?>
        <!-- 评论头部提示信息 -->

        <!-- 评论的内容 -->
        <?php $comments->listComments(); ?>
        <!-- 评论page -->

        <div class="nav-page Page navigation"  >
            <?php $comments->pageNav('<', '>', 1, '...', array('wrapTag' => 'ul', 'wrapClass' => 'pagination' )  ); ?>
        </div>
    

<?php endif; ?>