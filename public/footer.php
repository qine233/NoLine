<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->footer(); ?>

<!-- 回到顶部：滚过一屏才浮现（main.js 挂 .is-visible）。
     不能塞进 .topbar——顶栏的 backdrop-filter 会给 fixed 后代建包含块，钉不住视口。 -->
<button type="button" class="back-top" id="back-top" aria-label="<?php _e('回到顶部'); ?>">
    <?php noline_icon('arrow-up'); ?>
</button>

<!-- 图片灯箱：点击正文或动态卡里的图片打开，main.js 用事件委托填 src 和说明。
     初始 hidden，不加载任何图片，打开时才把当前图的地址写进 .lightbox-img。 -->
<div class="lightbox" id="lightbox" hidden>
    <button type="button" class="lightbox-btn lightbox-close" aria-label="<?php _e('关闭'); ?>"></button>
    <button type="button" class="lightbox-btn lightbox-prev" aria-label="<?php _e('上一张'); ?>"></button>
    <figure class="lightbox-stage">
        <img class="lightbox-img" src="" alt="">
        <figcaption class="lightbox-caption"></figcaption>
    </figure>
    <button type="button" class="lightbox-btn lightbox-next" aria-label="<?php _e('下一张'); ?>"></button>
</div>

</body>
</html>
