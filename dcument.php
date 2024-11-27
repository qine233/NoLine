<?php
/**
 * 归档页面
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
?>
<?php $this->need('public/header.php'); ?>
<div class="content-all"><?php $this->need('sidebar.php'); ?>
<div id="pjax-container">
        <div class="typecho-user-text">
             <div class="content-list-post border-wid">
                 <!-- 下面text部分 -->
		<div class="typecho-text text-tream " >
        </div>
        <h1 class="post-title" itemprop="name headline">「&nbsp&nbsp<?php $this->title() ?>&nbsp&nbsp」</h1>
       <div class="content-text-2 "  id="write">

           <article>
               <?php
                    $this->widget('Widget_Contents_Post_Recent', 'pageSize=10000')->to($archives);
               $year = 0;
               $mon = 0;
               $i = 0;
               $j = 0;
               $output = '<div id="archives">';
               while ($archives->next()):
               $year_tmp = date('Y', $archives->created);
               $mon_tmp = date('m', $archives->created);
               $y = $year;
               $m = $mon;
               if ($mon != $mon_tmp && $mon > 0) $output .= '</ul></li>';
               if ($year != $year_tmp && $year > 0) $output .= '</ul>';
               if ($year != $year_tmp) {
               $year = $year_tmp;
               $output .= '<h2 >' . $year . ' 年</h2><ul class="article-ach">';
                   }
                   $output .= '<li class="list-style-art"><span>' . date('n月d日    ', $archives->created) . '</span><a href="' . $archives->permalink . '">' . $archives->title . '</a></li>'; //输出文章日期和标题
                   endwhile;
                   $output .= '</ul></li></ul></div>';
               echo $output;
               ?>
           </article>

    </div>

    </div>
    </div>


    <?php $this->need('sidebar-right.php'); ?>
           </div>
</div>
</div>
</div>
<?php $this->need('public/footer.php'); ?>



</body>