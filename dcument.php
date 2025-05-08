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
<div class="container">
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
$output = '<div id="archives">';
while ($archives->next()):
    $year_tmp = date('Y', $archives->created);
    $mon_tmp = date('m', $archives->created);

    if ($mon != $mon_tmp && $mon > 0) $output .= '</ul></li>';
    if ($year != $year_tmp && $year > 0) $output .= '</ul>';

    if ($year != $year_tmp) {
        $year = $year_tmp;
        $output .= '<h2>' . $year . ' 年</h2><ul class="article-ach">';
    }

    // 获取文章分类
    $categories = $archives->categories;
    $isDiary = false;
    if (is_array($categories)) {
        foreach ($categories as $cat) {
            if ($cat['name'] == '日记') {
                $isDiary = true;
                break;
            }
        }
    }

    // 构造输出
    $dateStr = date('n月d日', $archives->created);
    $titleStr = $isDiary ? '日记' : $archives->title;

    $output .= '<li class="list-style-art"><span>' . $dateStr . '</span><a href="' . $archives->permalink . '">' . $titleStr . '</a></li>';
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