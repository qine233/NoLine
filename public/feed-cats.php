<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 信息流顶上那条吸顶的分类药丸条。
 *
 * 数据读 noline_categories()——那份缓存由 public/header.php 遍历
 * Widget_Metas_Category_List 时逐行填进来。这里绝不能再遍历一遍那个 widget：
 * 核心只按类名缓存实例，Rows 又没有 rewind()，第二遍 while 直接空转。
 *
 * slug 用来判「当前分类」：Widget_Archive::is($type, $slug) 是拿 archiveSlug 比的，
 * 手拼 URL 去比 permalink 会在开/关伪静态时错。
 *
 * 结构是三层：外层 .feed-cats 负责 sticky 和给弹层当定位参照，内层 .feed-cats-scroll
 * 才是那条横向滚的行，右端那颗「更多」是它的兄弟。按钮不能放进滚动行里——放进去就得靠
 * sticky + left:100% 那种把按钮钉在滚动容器右缘的写法，而那两条属性在一个 overflow 容器
 * 上的表现浏览器之间不一致，钉住的按钮还会跟着内容一起被推走。
 *
 * 一个分类都没有时整块不输出，不留一条空的吸顶带。
 */
$cats = noline_categories();

if (empty($cats)) {
    return;
}
?>
<div class="feed-cats">
    <nav class="feed-cats-scroll" id="feed-cats-scroll" aria-label="<?php _e('分类导航'); ?>">
        <a class="feed-cat<?php if ($this->is('index')): ?> active<?php endif; ?>" href="<?php $this->options->siteUrl(); ?>"<?php if ($this->is('index')): ?> aria-current="page"<?php endif; ?>><?php _e('全部'); ?></a>
        <?php foreach ($cats as $c): ?>
        <a class="feed-cat<?php if ($this->is('category', $c['slug'])): ?> active<?php endif; ?>" href="<?php echo htmlspecialchars($c['permalink'], ENT_QUOTES, 'UTF-8'); ?>"<?php if ($this->is('category', $c['slug'])): ?> aria-current="page"<?php endif; ?>>
            <span><?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="feed-cat-count"><?php echo $c['count']; ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <?php /* 起始 hidden：main.js 量过「这一行到底溢没溢出」才决定要不要露出来。
              一行放得下全站分类的时候，这颗按钮纯属多余。 */ ?>
    <button type="button" class="feed-cat-more" id="feed-cats-more" hidden
            aria-haspopup="true" aria-expanded="false" aria-controls="feed-cats-pop">
        <?php noline_icon('folder'); ?><span><?php _e('更多'); ?></span>
    </button>

    <?php /* 面板内容由 main.js 现搭：它知道此刻哪几颗药丸被横向滚动裁掉了。
              空壳留在模板里，只为了给按钮的 aria-controls 一个真实的挂载点。
              role=group 加标签：展开之后这堆链接在读屏里得有个归属，不能是一串孤零零的链接。 */ ?>
    <div class="feed-cats-pop" id="feed-cats-pop" role="group"
         aria-label="<?php _e('更多分类'); ?>" hidden></div>
</div>
