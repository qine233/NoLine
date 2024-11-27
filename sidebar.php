<div class="background-img" id="background-img" >

<div id="NewPjax-container">


<!--    <div class="indexTitle">-->
<!--        --><?php //if($this->is('index')): ?>
<!--            <div class="Indexpost-title" itemprop="name headline">--><?php //$this->options->title(); ?><!--</div>-->
<!--        --><?php //else: ?>
<!--            <div class="Indexpost-title" itemprop="name headline">--><?php //$this->title(); ?><!--</div>-->
<!--        --><?php //endif; ?>
<!--    </div>-->
    <?php $this->need('sideroom.php'); ?>


<header class="header " id="backgroundHeader" >
    <div class="header-wide">
        <div id="box_hover" ><svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-menu"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg></div>

  

        <ul  id="nav_menu">

        <li><a id="nevColorTWO" style="color: inherit" href="<?php $this->options->siteUrl(); ?>">主页</a></li>
            <?php $this->widget('Widget_Contents_Page_List')
                ->parse('<li><a id="nevColorTWO" style="color: inherit" href="{permalink}">{title}</a></li>'); ?>
        </ul> 
        <div class="percentagewide">
        <div id="percentage"></div></div>
      </div>
       
</header>
