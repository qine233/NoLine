<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<html lang="zh-CN">
<head>
    <meta charset="<?php $this->options->charset(); ?>">
    <?php $this->header('generator=&template=&pingback=&xmlrpc=&wlw=&commentReply=&rss1=&rss2=&atom='); ?>
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, shrink-to-fit=no, viewport-fit=cover">
    <meta name="keywords" content="<?php echo $this->fields->keywords ? $this->fields->keywords : htmlspecialchars($this->_keywords); ?>" />
    <meta name="description" content="<?php echo $this->fields->description ? $this->fields->description : htmlspecialchars($this->_description); ?>" />
    <script src="https://lf3-cdn-tos.bytecdntp.com/cdn/expire-2-M/jquery/3.1.1/jquery.min.js"></script>
    <script src="https://lf26-cdn-tos.bytecdntp.com/cdn/expire-1-M/jquery.pjax/2.0.1/jquery.pjax.min.js"></script>
    <script src="https://lf3-cdn-tos.bytecdntp.com/cdn/expire-1-M/viewerjs/1.10.4/viewer.min.js"></script>
    <script src="<?php $this->options->themeUrl('css/nprogress.js'); ?>"></script>
    <!-- <script src="<?php $this->options->themeUrl('css/smooth-scroll.js'); ?>"></script> -->
    <title><?php $this->archiveTitle(array('category' => '分类 %s 下的文章', 'search' => '包含关键字 %s 的文章', 'tag' => '标签 %s 下的文章', 'author' => '%s 发布的文章'), '', ' - '); ?><?php $this->options->title(); ?></title>
    <link rel="stylesheet" href="https://lib.baomitu.com/twitter-bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('css/typora-indigo.css'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('css/Q-style.css'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('css/post.css'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('css/mobile.css');  ?>">
    <link rel="stylesheet" href="https://lf9-cdn-tos.bytecdntp.com/cdn/expire-1-M/viewerjs/1.10.4/viewer.min.css">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('css/nprogress.css');  ?>">
    <script src="https://lf3-cdn-tos.bytecdntp.com/cdn/expire-1-M/highlight.js/11.3.1/highlight.min.js"></script>
    <link rel="stylesheet" href="https://lf6-cdn-tos.bytecdntp.com/cdn/expire-1-M/highlight.js/11.3.1/styles/atom-one-dark.min.css">
       <link rel="shortcut icon" href="<?php $this->options->logoCss(); ?>" />

   <script>
        $(document)
            .on('click', 'a[href*="#"]', function() {
                if ( this.hash ) {
                    $.bbq.pushState( '#/' + this.hash.slice(1) );
                    return false;
                }
            })
            .ready(function() {
                $(window).bind('hashchange', function(event) {
                    var tgt = location.hash.replace(/^#\/?/,'');
                    if ( document.getElementById(tgt) ) {
                        $.smoothScroll({scrollTarget: '#' + tgt});
                    }
                });

                $(window).trigger('hashchange');
            });
    </script>

    <script>
        window.onload = function ()
        {   
            superLink();
            var ob="test";
            var oBtn3 = document.getElementById("nav_list_a_f");
            var oBox3 = document.getElementById("nav_list_a");
            var oBtn4 = document.getElementById("nav_list_a_g");
            var oBox4 = document.getElementById("nav_list_b");
            var oBtn2 = document.getElementById("sideroom-blur");
            var oBtn = document.getElementById("box_hover");
            var oBox = document.getElementById("sideroom");

            var backBFB = document.getElementById("background-img");
            var adminBFB = '<?php $this->options->logocontactbBFB(); ?>' ;

            oBtn.onclick = function ()
            {

                // oBox.style.cssText = "transform:translateX(100%);"
                oBtn2.style.cssText= "display:block;"
                if(getComputedStyle(sideroom).transform=="translateX(100%)"){
                    oBox.style.cssText = "transform: translateX(0);"
                } else{
                    oBox.style.cssText = "transform: translateX(100%);"
                }
            };
            oBtn2.onclick = function ()
            {
                oBox.style.cssText = "transform: translateX(0);"
                oBtn2.style.cssText= "display:none;"
            };
            // oBtn3.onclick = function ()
            // {

            //     if(getComputedStyle(nav_list_a).display=="none"){
            //         oBox3.style.cssText = "display:block;"
            //     } else{
            //         oBox3.style.cssText = "display:none;"
            //     }


            // };
         
           oBtn4.onclick = function ()
              {

                if(getComputedStyle(nav_list_b).display=="none"){
                    oBox4.style.cssText = "display:block;"
                } else if(getComputedStyle(nav_list_b).display=="block"){
                    oBox4.style.cssText = "display:none;"
                }

            };

        }
        window.onscroll = function() {
            var Ncolor = document.getElementById("nevColor");
            var NcolorHeader = document.getElementById("backgroundHeader");
            var NcolorTWO = document.getElementById("nav_menu");
            let percentage = document.getElementById('percentage');
            let totalH = document.body.scrollHeight || document.documentElement.scrollHeight;
            let clientH = window.innerHeight || document.documentElement.clientHeight;
            let validH = totalH - clientH;
            let scrollH = document.body.scrollTop || document.documentElement.scrollTop;
            if (scrollH == 0){
                percentage.innerHTML = '0%';
            }else{
                let fullWindowHeightInPercentage = Math.round((scrollH / validH) * 100);
                percentage.innerHTML = fullWindowHeightInPercentage+'%';
                if (fullWindowHeightInPercentage < 47  ) percentage.innerHTML = fullWindowHeightInPercentage+'%';
               // if (fullWindowHeightInPercentage >= 100) percentage.innerHTML = '<div style="font-size: 1.7rem;">100%</div>';
            }
            $('#percentage').on('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
             $(document).on('scroll', function () {
                if ($(document).scrollTop() <= 380) {
            //         Ncolor.style.cssText= "color:rgb(53 75 96);";
                    NcolorTWO.style.cssText= "color:rgb(53 75 96);";
                    $('.header').addClass('nobg').removeClass('hasbg');

                 } else {
            //         Ncolor.style.cssText= "color:#000000;";
                    NcolorTWO.style.cssText= "color:rgb(53 75 96);";
                    $('.header').removeClass('nobg p1').addClass('hasbg');
                }
             });
        };
    </script>


</head>
<body>
<div class="content-all center-block">

<div class="content-last">
  <div class="contact border-wid">
      
    <div class="bg_color" style="background: url(<?php $this->options->logobgcolor(); ?>);
            background-position-x: center;
            background-position-y: center;
            background-size: cover;
            object-fit: cover;
            border-radius: 1.9rem 1.9rem 0 0;
            ">
  <!-- <h1 class="contact-h1">#社交频道</h1> -->
   <span class="logowide"><img class="logo" src="<?php $this->options->logoCss(); ?>"></span>
  <h2 id="nevColor" href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></h2>
  <span><h2 class="name-talk"><?php $this->options->logobg(); ?></h2></span>
    </div>
 <!-- <button class="a-left"><a target="_blank" href="<?php $this->options->logocontacta(); ?>">关注</a></button> -->
             <div class="sibar-data-a">

           
            </div>


</div>

  </div>