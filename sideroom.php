
<div class="sideroom" id="sideroom">
<div class="bg_color-sideroom" style="background: linear-gradient(to bottom, rgb(255 255 255 / 0%) 0%, rgb(255 255 255 / 80%) 100%, #fff, 18%, #fff), url(<?php $this->options->logobgcolor(); ?>) no-repeat center;

        background-position-x: center;
        background-position-y: center;
        background-size: cover;
        object-fit: cover;">
<img class="alaver-img" src="<?php $this->options->logoCss(); ?>">

</div>



               <div class="sibar-data-ab"><div class="sibar-data-a">
            <?php Typecho_Widget::widget('Widget_Stat')->to($stat); ?>
            <ul class="sibar-data-a-ul">
            <li><?php $stat->publishedPostsNum() ?></li><li>文章数</li>
            </ul class="sibar-data-a-ul">
            <ul class="sibar-data-a-ul"><li><?php $stat->categoriesNum() ?></li><li>分类数</li></ul>
            <ul class="sibar-data-a-ul"><li><?php $stat->publishedCommentsNum() ?></li><li>评论数</li></ul>
            </div></div>
 <div class="nav_menu-mb" >
                        <ul  id="nav_menu-m">
                                
                      
                                 <div id="nav_list_a">
                                  <div class="nav_list_a-padding"> <li><a href="<?php $this->options->siteUrl(); ?>">首页</a></li>
                                  <?php $this->widget('Widget_Contents_Page_List')
                                 ->parse('<li ><a href="{permalink}">{title}</a></li>'); ?>
</div></div>
                             </ul>
                               <!-- <ul  id="nav_menu-m">
                                <li id="nav_list_a_g" >分类</li>
                                      <div id="nav_list_b">  <div class="nav_list_a-padding"> <?php $this->widget('Widget_Metas_Category_List')
                                           ->parse('<li><a href="{permalink}">{name}</a> </li>'); ?>
                               </ul> -->
                             </div>
                             <footer id="footermobile" class="footermobile">

<h3 class="text-ellipsis">&copy;2025 Copyright&nbsp;&nbsp;<a href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a><?php $this->options->logoFooter(); ?></h3>
    <h4 class="text-ellipsis_copy">Powered by Typecho | Theme by <a href="https://github.com/qine233/NoLine">Noline-V20250409</a></h4>
    <span id="runtime_span" style="    color: #656d8b;
    font-weight: bold;"></span>


</footer></div>
                             </div>
                             </div>
                <div class="sideroom-blur" id="sideroom-blur">
                    
                </div>
