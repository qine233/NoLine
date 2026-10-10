<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 列表自动加载的状态区。
 *
 * 由 index.php / archive.php 在 $this->pageNav() 之后 need()，必须落在
 * have() 分支里——空列表没有东西可加载，不该出现这一块。
 *
 * 整块初始 hidden，main.js 确认自己能接管（有下一页、且有 IntersectionObserver
 * / fetch / DOMParser）才揭开。无 JS 用户的退路是上面那排页码，不是这里，
 * 所以这里不放任何「请启用 JavaScript」的提示。
 *
 * .feed-text 挂 role="status" + aria-live="polite"：内容是新追加到列表尾部的，
 * 读屏器不会自己念出来，没有实时区域的话「正在加载／没有更多了」对读屏用户
 * 完全是静默的。
 */
?>
<div class="feed-status" id="feed-status" hidden>
    <span class="feed-spinner" hidden aria-hidden="true"></span>
    <span class="feed-text" role="status" aria-live="polite"></span>
    <button type="button" class="feed-retry" hidden><?php _e('重新加载'); ?></button>
</div>
