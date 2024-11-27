
<footer class="footer">

<h3 class="text-ellipsis">&copy;2024 Copyright&nbsp;&nbsp;<a href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a><?php $this->options->logoFooter(); ?></h3>
    <h4 class="text-ellipsis_copy">Powered by Typecho | Theme by <a href="https://github.com/qine233/NoLineLite">Noline-Demo-V1.0</a></h4>
</footer>
<script>


    $(document).pjax('a[href^="<?php Helper::options()->siteUrl()?>"]:not(a[target="_blank"], a[no-pjax])', {
        container: '#pjax-container',
        fragment: '#pjax-container',
        timeout: 8000
    }).on('pjax:send',
        function() {
            NProgress.start();

        }).on('pjax:complete',
        function() {
            NProgress.done();
            // setupContents();
            // reHighlightCodeBlock();//代码高亮函数重载

        })

</script>
