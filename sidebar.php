<div class="background-img" id="background-img" >



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

        <li><a id="newtitle" style="color: inherit" href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title() ?></a></li>
          <li><a id="hover-area" class="hover-area" style="color: inherit" href="#">分类</a></li>
            <?php $this->widget('Widget_Contents_Page_List')
                ->parse('<li><a id="nevColorTWO" style="color: inherit" href="{permalink}">{title}</a></li>'); ?>
        </ul> 
     
      </div>
      <a id="newtitle" style="color: inherit"class="newtitledesktop" href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a>
     <div id="classwork" ><ul>
    <?php $this->widget('Widget_Metas_Category_List')
               ->parse('<li><a href="{permalink}">{name}</a></li>'); ?>
</ul></div>
</header>

    <script>
const hoverArea = document.getElementById('hover-area');
const classwork = document.getElementById('classwork');

let isHovering = false;

const showClasswork = () => {
  classwork.style.display = 'block'; // 显示时改变 display
  setTimeout(() => {
    classwork.classList.add('visible'); // 添加动画效果
  }, 10); // 给一个小延迟确保 display 先变化
};

const hideClasswork = () => {
  classwork.classList.remove('visible'); // 移除动画效果
  setTimeout(() => {
    classwork.style.display = 'none'; // 隐藏时设置 display 为 none
  }, 300); // 与动画时间一致，等待动画结束后再隐藏
};

hoverArea.addEventListener('mouseenter', () => {
  isHovering = true;
  showClasswork();
});

classwork.addEventListener('mouseenter', () => {
  isHovering = true;
  showClasswork();
});

hoverArea.addEventListener('mouseleave', () => {
  isHovering = false;
  setTimeout(() => {
    if (!isHovering) hideClasswork();
  }, 200);
});

classwork.addEventListener('mouseleave', () => {
  isHovering = false;
  setTimeout(() => {
    if (!isHovering) hideClasswork();
  }, 200);
});

</script>