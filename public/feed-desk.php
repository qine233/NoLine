<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 桌面模式的入口胶囊（配 js/desk.js + js/matter.min.js）。
 *
 * 点「桌面」之后，信息流里的卡片就成了刚体：能拖、能撞、掉到容器底堆成一堆。
 * 默认是关的——来读文章的人不该被一堆斜着的卡片拦住，这是个加法不是改版。
 *
 * 位置用 fixed 钉在视口底部正中，而不是塞进那条吸顶药丸条：
 * 药丸条在一个分类都没有时整块不输出（那条决定没变），玩具的入口不能跟着一起消失。
 * 它是浮在正文之上的浮层，所以吃磨砂玻璃。
 *
 * 三颗按钮里只有「桌面」是真按钮：另外两颗空壳留在模板里、初始 hidden，
 * 由 desk.js 铺开桌面时揭开——图标得由 PHP 内联输出，JS 手上没有 SVG path，
 * 「更多」那颗弹层也是同一套做法。
 *
 * 「摇晃」那颗点下去是两件事：先去核心页码条里的真实分页地址随机抓一批文章换上桌
 * （desk.js 的 shakeBatch，按钮文案在抓取期间由 JS 改成「换一批中」），再把这批掀起来
 * 堆到中间。抓的是分页而不是拼 URL，所以开/关伪静态都成立；模板这边不用给任何东西。
 *
 * 少于四张卡就不出这颗按钮：两张卡堆不出「散落」的感觉，只会占住屏幕下沿。
 */
if ((int)$this->getTotal() < 4) {
    return;
}
?>
<div class="feed-desk-bar" id="feed-desk-bar">
    <button type="button" class="feed-desk-btn" id="feed-desk-toggle" aria-pressed="false">
        <?php noline_icon('cube'); ?><span><?php _e('桌面'); ?></span>
    </button>
    <button type="button" class="feed-desk-btn" id="feed-desk-shake" hidden>
        <?php noline_icon('shuffle'); ?><span><?php _e('摇晃'); ?></span>
    </button>
    <button type="button" class="feed-desk-btn" id="feed-desk-exit" hidden>
        <?php noline_icon('xmark'); ?><span><?php _e('收回'); ?></span>
    </button>
    <span class="feed-desk-count" id="feed-desk-count" hidden></span>
</div>
