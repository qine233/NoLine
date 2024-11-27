<?php
/**
 * “ 一款简单的主题，主题文件夹务必命名为Noline,注意本主题仅适用于typecho 1.2版本系列，未针对1.3进行语法上的适配 ”
 * @package NOLINELITE_1.0—Demo
 * @author QINE
 * @version 1.0Demo
 * @link https://www.idkzr.com/
 */
?>
<?php $this->need('public/header.php'); ?>
<?php $this->need('sidebar.php'); ?>

<div class="container">
</div>
<div id="pjax-container">

<script type="text/javascript">
    //点击加载更多
    jQuery(document).ready(function($) {
        //点击下一页的链接(即那个a标签)
        $('.next').click(function() {
            $this = $(this);
            $this.addClass('loading').text('正在努力加载'); //给a标签加载一个loading的class属性，用来添加加载效果
            var href = $this.attr('href'); //获取下一页的链接地址
            if (href != undefined) { //如果地址存在
                $.ajax({ //发起ajax请求
                    url: href,
                    //请求的地址就是下一页的链接
                    type: 'get',
                    //请求类型是get
                    error: function(request) {
                        //如果发生错误怎么处理
                    },
                    success: function(data) { //请求成功
                        $this.removeClass('loading').text('点击查看更多'); //移除loading属性
                        var $res = $(data).find('.post-list'); //从数据中挑出文章数据，请根据实际情况更改
                        $('.content-list').append($res.fadeIn(500)); //将数据加载加进posts-loop的标签中。
                        var newhref = $(data).find('.next').attr('href'); //找出新的下一页链接
                        if (newhref != undefined) {
                            $('.next').attr('href', newhref);
                        } else {
                            $('.next').remove(); //如果没有下一页了，隐藏
                        }
                    }
                });
            }
            return false;
        });
    });
</script>



    <div class="content">

        <div class="content-list">
            <?php while ($this->next()): ?>
                <div class="post-list">
                <div class="post" style="
                    object-fit: cover;
                    background-position-x: center;
                    background-position-y: center;
                    background-size: cover;">
                    <!-- <?php
                    preg_match_all("/\<img.*?src\=(\'|\")(.*?)(\'|\")[^>]*>/i", $this->content, $matches);
                    $imgCount = count($matches[0]);
                    if ($imgCount >= 1) {
                        $img = $matches[2][0];
                    } else {
                        $img = "/usr/themes/Noline/nbg2.jpg";
                    };
                    $CCimg = "<p class='post-images'><a href='{$this->permalink}' title='{$this->title}'><img src='{$img}' alt='{$this->title}'></a></p>";
                    echo $CCimg;
                    ?> -->
                 <h2 class="entry_title"><a href="<?php $this->permalink() ?>"><?php $this->title() ?></a></h2>


                <div class="entry_text">

                    <p>

                        <?php $this->excerpt(160, '...'); ?>
                    </p>
                </div>
                <span class="post_index-more"> <?php $this->date('Y年n月d日'); ?></span>
                </div>
              

                </div>
            <?php endwhile; ?>

        </div>

    <div class="nextWide">
        <?php $this->pageLink('点击查看更多','next'); ?>
    </div></div>
  <?php $this->need('sidebar-right.php'); ?>
</div></div>
</div><?php $this->need('public/footer.php'); ?>
</div>



</div>
</div>
</div></div>
</body>