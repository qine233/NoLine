<?php

/**
 * 说说
 *
 * @package custom
 *
 **/

?>
<?php $this->need('public/header.php'); ?>

<div class="content-all content-all-post">
    <?php $this->need('sidebar.php'); ?> 
    <div id="pjax-container">
    <!-- <div class="typecho-user-text"> -->
        <!-- <div class="content-list"> -->
       

        <div class="content-list-post border-wid">

            <div id="comments-padding-talk">
                <div class="typecho-header">
                    <!-- 判断设置是否允许对当前文章进行评论 -->
                    <?php if($this->allow('comment')): ?>

                    <h4 id="response"><?php $this->commentsNum('「&nbsp&nbsp时光动态&nbsp&nbsp」'); ?></h4>
                    <?php if($this->user->hasLogin()): ?>
                    <!-- 输入表单开始 -->
                    <form method="post" action="<?php $this->commentUrl() ?>" id="comment_form">

                        <!-- 如果当前用户已经登录 -->


                        <!-- 显示当前登录用户的用户名以及登出连接 -->
                        <p style="margin-left: 4rem; font-size: 1.33rem;">快来书写今日心情吧
                        </p>

                        <!-- 若当前用户未登录 -->


                        <!-- 输入要回复的内容 -->
                        <div class="talk-content-talk">
                            <p><textarea class="text" rows="10" cols="50" name="text"><?php $this->
                                remember('text'); ?></textarea></p>
                            <!-- <p><textarea  type="text" name="text" size="35" value="<?php $this->remember('text'); ?>" /></p> -->

                            <p class="submit-align"> <input type="submit" value="发送" class="submit-talk"/></p>
                        </div>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="talk-list">

                <ol style="  display: flex;
   flex-wrap: wrap;     flex-direction: column;     padding-bottom: 3rem; " id="comment_list_talk">
                    <?php $this->comments()->to($comments); ?>
                    <?php while($comments->next()): ?>
                    <li class="listTalkc" id="list-talk-list-talk <?php $comments->theId(); ?>">
                        <div class="comment_data">
                            <!-- <?php echo $comments->sequence(); ?>.  -->
                            <img id="talkIMG" src="<?php $this->options->logoCss(); ?>"/>

                            <div class="comment_body">

                                <div class="talkUserTitle"> <strong><?php $comments->author(); ?></strong>
                                &nbsp; <?php $comments->date('Y-m-d H:i'); ?>
                            </div>
                            <?php $comments->content(); ?>
                            </div>
                        </div>
                    </li>


                    <?php endwhile; ?>
                    <div class="nav-page Page navigation"  >
                        <?php $comments->pageNav('<', '>', 1, '...', array('wrapTag' => 'ul', 'wrapClass' => 'pagination' )  ); ?>
                    </div>
                </ol>
                <!-- <?php $comments->pageNav('« 前一页', '后一页 »'); ?>  -->
            </div>
        </div>
        <?php $this->need('sidebar-right.php'); ?>
    </div>

</div>

</div>
</div>

<?php $this->need('public/footer.php'); ?>
</div>
</div>

</body>
