
<footer class="footer">

<h3 class="text-ellipsis">&copy;2022 Copyright&nbsp;&nbsp;<a href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a><?php $this->options->logoFooter(); ?></h3>
    <h4 class="text-ellipsis_copy">Powered by Typecho | Theme by <a href="https://github.com/qine233/NoLine-Typecho-theme">Noline</a></h4>
</footer>
<script src="<?php $this->options->themeUrl('css/jquery.min.js'); ?>"></script>
<script src="<?php $this->options->themeUrl('css/pjax.js'); ?>"></script>
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
</div>
</div>
</div></div></div></div>