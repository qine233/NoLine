<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<html lang="zh-CN">
<head>
    
    <meta charset="<?php $this->options->charset(); ?>">
    <?php $this->header('generator=&template=&pingback=&xmlrpc=&wlw=&commentReply=&rss1=&rss2=&atom='); ?>
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, shrink-to-fit=no, viewport-fit=cover">
    <meta name="keywords" content="<?php echo $this->fields->keywords ? $this->fields->keywords : htmlspecialchars($this->_keywords); ?>" />
    <meta name="description" content="<?php echo $this->fields->description ? $this->fields->description : htmlspecialchars($this->_description); ?>" />
    <script src="https://lf3-cdn-tos.bytecdntp.com/cdn/expire-2-M/jquery/3.1.1/jquery.min.js"></script>
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
    <script src="https://lf26-cdn-tos.bytecdntp.com/cdn/expire-1-M/highlight.js/11.4.0/highlight.min.js"></script>
    <link rel="stylesheet" href="https://lf6-cdn-tos.bytecdntp.com/cdn/expire-1-M/highlight.js/11.4.0/styles/atom-one-dark.min.css">
       <link rel="shortcut icon" href="<?php $this->options->logoCss(); ?>" />

</head>
<body class="body">
     

<div id="content-all" class="content-all center-block">


  </div>