<ul  id="nav_menu">
    <li><a style="color: #ffffff" href="<?php $this->options->siteUrl(); ?>">Home</a></li>

    <?php $this->widget('Widget_Contents_Page_List')
        ->parse('<li><a style="color: #ffffff" href="{permalink}">{title}</a></li>'); ?>
</ul>

//     <a style="color: #ffffff" href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a>

<?php $this->need('sidebar-right.php'); ?>

<div class="sibar-data-a">

    <?php Typecho_Widget::widget('Widget_Stat')->to($stat); ?>
    <ul class="sibar-data-a-ul">
        <li><?php $stat->publishedPostsNum() ?></li><li>文章数</li>
    </ul class="sibar-data-a-ul">
    <ul class="sibar-data-a-ul"><li><?php $stat->categoriesNum() ?></li><li>分类数</li></ul>
    <ul class="sibar-data-a-ul"><li><?php $stat->publishedCommentsNum() ?></li><li>评论数</li></ul>
</div>

<div class="button-flex">
    <button class="a-left"><a target="_blank" href="<?php $this->options->logocontacta(); ?>">follow&nbsp&nbspme</a></button>
</div>

<ul class="siber-comments">
    <?php $this->widget('Widget_Comments_Recent','ignoreAuthor=true&pageSize=5')->to($comments); ?>
    <?php while($comments->next()): ?>
        <li><?php $email=$comments->mail; $imgUrl = getGravatar($email);echo '<img src="'.$imgUrl.'" width="40px" height="40px" style="border-radius: 50%;" >'; ?>&nbsp;&nbsp;&nbsp;<?php $comments->author(false); ?> <a href="<?php $comments->permalink(); ?>"><span class="siber-com"> <?php $comments->excerpt(50, '...'); ?></span></a></li>
    <?php endwhile; ?>
</ul>





<?php $this->options->themeUrl('css/jquery.min.js'); ?>
<?php $this->options->themeUrl('css/pjax.js'); ?>


<div class="sibar-all">
<div class="sibar-data border-wid" >
<div class="mac"><span>classification</span>
    <i class="bg-primary"></i></div>
<div class="sibar-data-abc" >


<div class="sibar-data-b">
 <!-- <ul style="display: inline;">



</ul> -->
<div class="archives"> <ul>
    <?php $this->widget('Widget_Metas_Category_List')
               ->parse('<li><a href="{permalink}">{name}</a> <span id="archives_span">{count}</span></li>'); ?>
</ul>  </div>

</div>
</div>
</div>
</div>

<h1 class="name"><?php $this->options->logoName(); ?></h1>

<?php Typecho_Widget::widget('Widget_Stat')->to($stat); ?>
            <ul class="sibar-data-a-ul">
            <li><?php $stat->publishedPostsNum() ?></li><li>文章数</li>
            </ul class="sibar-data-a-ul">
            <ul class="sibar-data-a-ul"><li><?php $stat->categoriesNum() ?></li><li>分类数</li></ul>
            <ul class="sibar-data-a-ul"><li><?php $stat->publishedCommentsNum() ?></li><li>评论数</li></ul>

            
            <div class="site-search">
        <form id="a-search" method="post" action="<?php $this->options->siteUrl(); ?>" role="search">
            <!-- <label for="s" class="sr-only"><?php _e('搜索关键字'); ?></label> -->
            <input type="text" id="s" name="s" class="a-text" placeholder="<?php _e('search for ...'); ?>" />
            <button type="submit" class="a-submit"><?php _e('<svg style="width:25px; height:25px" t="1633546689663" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="2378" width="128" height="128"><path d="M924.16 903.168c-1.536-4.096-1.536-4.096-2.56-5.632l-3.072-4.608c-2.048-2.56-4.096-5.12-7.68-9.216-5.632-6.656-14.336-15.36-25.6-27.136-17.408-18.432-35.328-36.352-77.824-79.872-42.496-43.008-59.904-61.44-77.312-79.36-10.752-11.264-18.944-19.968-24.576-26.112-2.56-3.072-4.608-5.12-5.632-6.656 0.512 1.536 0.512 1.536 2.048 4.608 1.536 6.144 1.536 6.144-5.632 19.968 67.072-64.512 105.984-154.112 105.984-250.368 0-190.464-151.552-344.576-338.432-344.576-186.88 0-338.432 154.112-338.432 344.576 0 190.464 151.552 344.576 338.432 344.576 4.096 0 8.192 0 13.312-0.512 3.584-0.512 6.144-0.512 11.776-1.024 10.752-1.024 14.848-1.536 19.456-1.536 7.68 0 19.968-2.56 38.4-7.68 2.048-0.512 4.096-1.024 6.656-2.048 7.168-2.048 13.824-4.096 20.992-6.144 4.096-1.024 7.168-2.048 9.216-3.072 10.752-3.584 16.896-15.36 13.312-26.112-3.584-10.752-14.848-16.896-25.6-13.824-1.536 0.512-4.608 1.536-8.704 2.56l-19.968 6.144-6.144 1.536c-13.824 4.096-25.088 6.144-27.648 6.144-6.656 0-11.264 0.512-23.552 1.536-5.12 0.512-7.68 1.024-10.752 1.024-3.584 0.512-7.168 0.512-9.728 0.512-164.352 0-297.472-135.68-297.472-302.592 0-167.424 133.12-302.592 297.472-302.592s297.472 135.68 297.472 302.592c0 84.48-33.792 163.328-93.184 220.16-7.68 14.336-7.68 14.336-5.632 20.992 1.536 3.584 1.536 3.584 2.56 5.632l3.072 4.608c2.048 2.56 4.096 5.12 7.68 9.216 5.632 6.656 14.336 15.36 25.6 27.136 17.408 18.432 35.328 36.352 77.824 79.872 42.496 43.52 60.416 61.44 77.824 79.36 10.752 11.264 18.944 19.968 24.576 26.112 2.56 3.072 4.608 5.12 5.632 6.656-0.512-1.536-0.512-1.536-2.048-4.608-1.536-6.656-1.536-6.656 6.656-20.48-8.704 7.68-9.728 20.48-2.56 29.184 7.168 8.704 20.48 9.728 28.672 2.56 6.656-14.336 6.656-14.336 5.12-21.504z" fill="#707070" p-id="2379"></path></svg>'); ?></button>

        </form>
    </div>