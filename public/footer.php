
<footer class="footer">

<h3 class="text-ellipsis">&copy;2024 Copyright&nbsp;&nbsp;<a href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a><?php $this->options->logoFooter(); ?></h3>
    <h4 class="text-ellipsis_copy">Powered by Typecho | Theme by <a href="https://github.com/qine233/NoLine">Noline-V3.0</a></h4>
    
</footer>


 <script>
    $(document).pjax(
        'a[href^="<?php Helper::options()->siteUrl()?>"]:not(a[target="_blank"], a[no-pjax])', 
        {
            container: '#pjax-container',
            fragment: '#pjax-container',
            timeout: 8000
        }
    )
    .on('pjax:send', function() {
        NProgress.start(); // 开始加载动画
      //  superLink();
      console.log('PJAX Send');
    })
    .on('pjax:complete', function() {
        NProgress.done();  // 完成加载动画
         superLink(); 
         console.log('PJAX Complete');  
       
    });

 
</script>
       <script src="<?php $this->options->pluginUrl('superLink/superlink.js?ver=2024.01.19.01'); ?>"></script>
