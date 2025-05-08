<?php
/**
 * “ 一款简单的主题，主题文件夹务必命名为Noline,注意本主题仅适用于typecho 1.2版本系列，未针对1.3进行语法上的适配 ”
 * @package NOLINE
 * @author QINE
 * @version 3.0_END
 * @link https://www.idkzr.com/
 */
?>
<?php $this->need('public/header.php'); ?>
<?php $this->need('sidebar.php'); ?>
<div class="content-all content-all-post">

</div>
<div class="container">
    
</div>
</div>

<div id="pjax-container">

<script type="text/javascript">
    // 下滑加载更多
    jQuery(document).ready(function($) {
        var loading = false;

        $(window).on('scroll', function() {
            if (loading) return;

            var scrollTop = $(window).scrollTop();
            var windowHeight = $(window).height();
            var documentHeight = $(document).height();

            if (scrollTop + windowHeight >= documentHeight - 900) {
                var $next = $('.next');
                var href = $next.attr('href');

                if (href !== undefined) {
                    loading = true;
                    $next.addClass('loading').text('正在努力加载');

                    $.ajax({
                        url: href,
                        type: 'get',
                        success: function(data) {
                            var $res = $(data).find('.post-list');
                            $('.content-list').append($res.fadeIn(500));

                            var newhref = $(data).find('.next').attr('href');
                            if (newhref !== undefined) {
                                $next.attr('href', newhref).removeClass('loading').text('滑动加载更多');
                            } else {
                                $next.remove();
                            }

                            loading = false;
                        },
                        error: function() {
                            $next.removeClass('loading').text('加载失败，请重试');
                            loading = false;
                        }
                    });
                }
            }
        });
    });
</script>

<div class="content">
    <div class="content-list">
        <?php while ($this->next()): ?>
            <?php 
                $categories = $this->categories;
                $isDiary = false;
                foreach ($categories as $category) {
                    if ($category['name'] === '日记') {
                        $isDiary = true;
                        break;
                    }
                }
            ?>
            <div class="post-list <?php echo $isDiary ? 'diary-style' : 'normal-style'; ?>">
                <div class="post" style="
                    object-fit: cover;
                    background-position-x: center;
                    background-position-y: center;
                    background-size: cover;">
            <div class="postinfo">     <img class="logo" src="<?php $this->options->logoCss(); ?>"> 
        
            <div class="postinfoofdaily">
              <span class="nameinfo"> <?php $this->options->logoName(); ?> </span>
            <div class="postinfoofdailytime"> <?php echo time_ago_in_words($this->created); ?> ·            <?php $this->category(','); ?> </div>
            </div>
        
        
        
        
        </div>
                  

                    <?php if (!$isDiary): ?>
                        <!-- 正常文章显示标题 -->
                        <h2 class="entry_title"><a href="<?php $this->permalink() ?>"><?php $this->title() ?></a></h2>
                    <?php endif; ?>

                    <div class="entry_text" id="entry_text">
                        <p> <?php 
    // 判断当前文章是否属于“日记”分类
    if ($category['name'] === '日记') {
        $this->content(); // 输出全文
        

      
    } else {
        $this->excerpt(160, '...'); // 只截取160字
    }
    ?></p>
 

                    </div>
               
         <span class="post_views"> 阅读 <?php get_post_view($this); ?> </span>
         <span class="post_views"> 评论 <?php $this->commentsNum(); ?> </span>

                </div>
            </div>
        <?php endwhile; ?>
    </div>
  <script type="text/javascript">
        var image = new Viewer(document.getElementById('entry_text'),{
                            url: 'src'
                        });
        </script>
    <div class="nextWide">
        <?php $this->pageLink('点击查看更多','next'); ?>
    </div>
</div>

<?php $this->need('sidebar-right.php'); ?>
</div></div></div>

<?php $this->need('public/footer.php'); ?>
</div></div></div></div>
</body>
