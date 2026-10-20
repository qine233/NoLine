#!/usr/bin/env python3
"""
生成 _preview/ 下的静态 HTML 夹具。

本机没有 php，模板跑不起来，但 CSS 和 main.js 是静态的——只要把 PHP 会渲染出的
HTML 手写出来，就能用真实浏览器验证：设计令牌、深色模式、顶栏导航（分类下拉、
窄屏汉堡面板、滚动百分比）、代码块红绿灯与复制按钮、动态卡与长内容折叠、
列表自动加载（index.html 滑到底依次追加 page2/page3）、页面转场，
以及 main.js 有没有语法错误（看 console）。

夹具忠实照抄 public/header.php + 各模板的结构，图标从 public/icons.php 里取真 path。
所有图片都是内联 SVG data URI，零外部请求，和成品运行时的表现一致。

用法：python3 _dev/mkpreview.py
      →   写出 _preview/ 下的 index.html、page2.html、page3.html、post.html

⚠ 自动加载那几页必须用 http 打开，不能双击用 file:// 开：
   fetch 在 file:// 下抓同目录文件会被 Chrome / Firefox 直接拦掉
   （URL scheme "file" is not supported），只会看到「加载失败」，
   把失败路径当成正常路径验。在 noline/_preview/ 里起一个静态服务即可：
       python3 -m http.server 8000   →   http://localhost:8000/index.html
"""
import calendar
import math
import re
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / '_preview'


# ---------------------------------------------------------------- 图标

def load_icons():
    text = (ROOT / 'public' / 'icons.php').read_text(encoding='utf-8')
    icons = {}
    pat = re.compile(r"'([\w-]+)'\s*=>\s*array\(\s*'([^']+)'\s*,\s*'([^']+)'\s*\)", re.S)
    for m in pat.finditer(text):
        icons[m.group(1)] = (m.group(2), m.group(3))
    return icons


ICONS = load_icons()


def load_version():
    text = (ROOT / 'functions.php').read_text(encoding='utf-8')
    m = re.search(r"define\(\s*'NOLINE_VERSION'\s*,\s*'([^']+)'\s*\)", text)
    if not m:
        raise SystemExit('functions.php 里找不到 NOLINE_VERSION')
    return m.group(1)


# 别硬编码：?v= 必须和 functions.php 的 NOLINE_VERSION 一致，否则夹具验的不是成品在发的那份资源
VERSION = load_version()


def icon(name, extra=''):
    if name not in ICONS:
        raise SystemExit('夹具用到了 icons.php 里不存在的图标: %s' % name)
    viewbox, path = ICONS[name]
    classes = ('noline-icon ' + extra).strip()
    return ('<svg class="%s" viewBox="%s" fill="currentColor" aria-hidden="true" '
            'focusable="false"><path d="%s"/></svg>' % (classes, viewbox, path))


def sub_icons(html):
    """把 {{icon:name}} 和 {{icon:name extra-class}} 换成真的内联 SVG。"""
    def repl(m):
        parts = m.group(1).split(None, 1)
        return icon(parts[0], parts[1] if len(parts) > 1 else '')
    return re.sub(r'\{\{icon:([\w\-\s]+)\}\}', repl, html)


# ---------------------------------------------------------------- 假图

AVATAR = ("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96'>"
          "<rect width='96' height='96' fill='%234482e5'/>"
          "<circle cx='48' cy='36' r='17' fill='%23ffffff' opacity='.9'/>"
          "<path d='M14 96c0-19 15-33 34-33s34 14 34 33z' fill='%23ffffff' opacity='.9'/></svg>")

COVER = ("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 240 72'>"
         "<defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'>"
         "<stop offset='0' stop-color='%234482e5'/><stop offset='1' stop-color='%23764ba2'/>"
         "</linearGradient></defs><rect width='240' height='72' fill='url(%23g)'/></svg>")

FIGURE = ("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 300'>"
          "<rect width='800' height='300' fill='%23eef2f8'/>"
          "<circle cx='200' cy='120' r='60' fill='%234482e5' opacity='.55'/>"
          "<rect x='330' y='70' width='180' height='180' rx='16' fill='%23354b60' opacity='.45'/>"
          "<path d='M560 250 L640 90 L720 250 Z' fill='%23fdbc40' opacity='.7'/></svg>")


# ---------------------------------------------------------------- 共享外壳

HEAD = """<!DOCTYPE html>
<html lang="zh-CN" data-vt>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="preload" as="style" href="../css/design.css?v=__VERSION__">
    <title>__TITLE__ — Noline 夹具</title>
    <script>
    (function () {
        var el = document.documentElement;
        var theme = null, reduce = false;
        try {
            theme = localStorage.getItem('noline-theme');
            reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        } catch (e) {}
        if (theme !== 'dark' && theme !== 'light') {
            theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        el.setAttribute('data-theme', theme);
        if (reduce) { return; }
        /* 和 public/header.php 保持一份：夹具要能在本地 http 上验视图过渡。
           data-vt 在这里是写死的，真站上由 PHP 确认 COOP 头挂上后才给。 */
        var vt = el.hasAttribute('data-vt');
        if (vt) {
            try {
                var sheet = new CSSStyleSheet();
                sheet.replaceSync('@view-transition{navigation:auto}');
                vt = sheet.cssRules.length > 0;
            } catch (e) { vt = false; }
        }
        if (vt) { el.classList.add('vt-cross'); return; }
        el.classList.add('is-loading');
        setTimeout(function () { el.classList.remove('is-loading'); }, 900);
    })();
    </script>
    <link rel="stylesheet" href="../css/design.css?v=__VERSION__">
    <link rel="stylesheet" href="../css/article.css?v=__VERSION__">
__EXTRA_HEAD__
    <script defer src="../js/main.js?v=__VERSION__"></script>
</head>
<body>

<!-- 进度条和遮罩都必须是 .topbar 的兄弟：顶栏有 backdrop-filter，
     它会给固定定位的后代建包含块，塞进去就会跟着顶栏一起跑。 -->
<div id="noline-progress" aria-hidden="true"></div>
<div class="nav-scrim" id="nav-scrim"></div>

<header class="topbar">
    <div class="topbar-inner">

        <a class="topbar-brand" href="./index.html" title="回到首页">
            <img src="__AVATAR__" alt="" class="topbar-logo" width="32" height="32">
            <span class="topbar-name">Noline</span>
        </a>

        <!-- 窄屏下这整块脱离文档流，变成挂在顶栏下沿的下拉面板（见 design.css 与 main.js） -->
        <nav class="topnav" id="topnav" aria-label="主导航">

            <a class="nav-item__HOME__" href="./index.html"__CURR__>
                {{icon:house}}<span class="nav-label">首页</span>
            </a>
            <a class="nav-item" href="./post.html" title="归档">
                {{icon:box-archive}}<span class="nav-label">归档</span>
            </a>
            <a class="nav-item" href="./post.html" title="留言">
                {{icon:comments}}<span class="nav-label">留言</span>
            </a>
            <a class="nav-item" href="./post.html" title="友链">
                {{icon:link}}<span class="nav-label">友链</span>
            </a>
            <a class="nav-item" href="./post.html" title="关于">
                {{icon:circle-info}}<span class="nav-label">关于</span>
            </a>
            <a class="nav-item" href="./post.html" title="主题研制">
                {{icon:code}}<span class="nav-label">主题研制</span>
            </a>
            <!-- 下面两条走 emoji 透传（noline_nav_mark），上面几条走 SVG。
                 放在一起是为了对比两种图标在 40px 高的横排导航里基线和尺寸对不对齐。 -->
            <a class="nav-item" href="./post.html" title="🧰百宝箱">
                <span class="noline-emoji" aria-hidden="true">🧰</span><span class="nav-label">百宝箱</span>
            </a>
            <a class="nav-item" href="./post.html" title="📷相册">
                <span class="noline-emoji" aria-hidden="true">📷</span><span class="nav-label">相册</span>
            </a>

            <form class="topbar-search" method="get" action="./index.html" role="search">
                <label class="sr-only" for="nav-search-input">搜索文章</label>
                <input type="search" id="nav-search-input" name="s" placeholder="搜索文章…" autocomplete="off">
                <button type="submit" class="icon-btn" aria-label="搜索">
                    {{icon:magnifying-glass}}
                </button>
            </form>

        </nav>

        <div class="topbar-actions">

            <a href="#" class="icon-btn icon-btn-label" rel="noopener nofollow me" target="_blank" title="qine" aria-label="qine">
                {{icon:github}}<span>qine</span>
            </a>
            <a href="#" class="icon-btn" rel="noopener nofollow me" target="_blank" title="Bilibili" aria-label="Bilibili">
                {{icon:bilibili}}
            </a>

            <!-- 整数由 main.js 写进来，百分号是 CSS 的 ::after。
                 起始就挂 hidden：页面短到不能滚时它根本不该出现。 -->
            <span class="topbar-percent" id="nav-percent" hidden aria-hidden="true">0</span>

            <button type="button" class="icon-btn js-theme-toggle" title="切换深浅色" aria-label="切换深浅色">
                {{icon:moon icon-moon}}{{icon:sun icon-sun}}
            </button>

            <button type="button" class="icon-btn mobile-menu-btn" id="nav-toggle" aria-expanded="false" aria-controls="topnav" aria-label="展开菜单">
                {{icon:bars}}
            </button>

        </div>

    </div>
</header>
"""

FOOT = """
<button type="button" class="back-top" id="back-top" aria-label="回到顶部">
    {{icon:arrow-up}}
</button>

<div class="lightbox" id="lightbox" hidden>
    <button type="button" class="lightbox-btn lightbox-close" aria-label="关闭"></button>
    <button type="button" class="lightbox-btn lightbox-prev" aria-label="上一张"></button>
    <figure class="lightbox-stage">
        <img class="lightbox-img" src="" alt="">
        <figcaption class="lightbox-caption"></figcaption>
    </figure>
    <button type="button" class="lightbox-btn lightbox-next" aria-label="下一张"></button>
</div>

</body>
</html>
"""

SIDEBAR = """        <aside class="sidebar">

            <div class="card card-widget">
                <div class="card-header">{{icon:calendar}}十月历</div>
                <div class="calendar-wrap">
                    <div class="calendar-head">
                        <span class="calendar-cell">日</span><span class="calendar-cell">一</span><span class="calendar-cell">二</span><span class="calendar-cell">三</span><span class="calendar-cell">四</span><span class="calendar-cell">五</span><span class="calendar-cell">六</span>
                    </div>
                    <div class="calendar-week">
                        <span class="calendar-cell"></span><span class="calendar-cell"></span><span class="calendar-cell"></span><span class="calendar-cell"></span><span class="calendar-cell calendar-posted">1</span><span class="calendar-cell">2</span><span class="calendar-cell">3</span>
                    </div>
                    <div class="calendar-week">
                        <span class="calendar-cell">4</span><span class="calendar-cell">5</span><span class="calendar-cell calendar-today">6</span><span class="calendar-cell">7</span><span class="calendar-cell">8</span><span class="calendar-cell">9</span><span class="calendar-cell">10</span>
                    </div>
                    <div class="calendar-week">
                        <span class="calendar-cell">11</span><span class="calendar-cell">12</span><span class="calendar-cell">13</span><span class="calendar-cell calendar-posted">14</span><span class="calendar-cell">15</span><span class="calendar-cell">16</span><span class="calendar-cell">17</span>
                    </div>
                    <div class="calendar-week">
                        <span class="calendar-cell">18</span><span class="calendar-cell">19</span><span class="calendar-cell">20</span><span class="calendar-cell">21</span><span class="calendar-cell">22</span><span class="calendar-cell">23</span><span class="calendar-cell">24</span>
                    </div>
                    <div class="calendar-week">
                        <span class="calendar-cell">25</span><span class="calendar-cell">26</span><span class="calendar-cell">27</span><span class="calendar-cell">28</span><span class="calendar-cell">29</span><span class="calendar-cell">30</span><span class="calendar-cell">31</span>
                    </div>
                </div>
            </div>

            <div class="card card-widget">
                <div class="card-header">{{icon:history}}那年今日</div>
                <div class="history-list">
                    <div class="history-item">
                        <div class="history-year">2023<span class="history-ago">3 年前</span></div>
                        <div class="history-title">把博客搬回 Typecho 的那个晚上</div>
                    </div>
                    <div class="history-item">
                        <div class="history-year">2021<span class="history-ago">5 年前</span></div>
                        <div class="history-title">写下 Noline 主题的第一行 CSS</div>
                    </div>
                </div>
            </div>

            <!-- 这张卡在模板里受后台「右栏分类卡」那个开关控制，默认是关的。
                 夹具故意把它留着，为的是开关打开之后长什么样还能预先看到；
                 对照线上默认外观时请当它不存在。 -->
            <div class="card card-widget">
                <div class="card-header">{{icon:folder}}分类</div>
                <div class="archive-list">
                    <div class="archive-item">
                        <span class="archive-item-title"><a href="#">开发</a></span>
                        <span class="archive-item-meta">12</span>
                    </div>
                    <div class="archive-item">
                        <span class="archive-item-title" style="padding-left:14px"><a href="#">前端</a></span>
                        <span class="archive-item-meta">7</span>
                    </div>
                    <div class="archive-item">
                        <span class="archive-item-title" style="padding-left:14px"><a href="#">后端</a></span>
                        <span class="archive-item-meta">5</span>
                    </div>
                    <div class="archive-item">
                        <span class="archive-item-title"><a href="#">生活</a></span>
                        <span class="archive-item-meta">3</span>
                    </div>
                    <div class="archive-item">
                        <span class="archive-item-title"><a href="#">日记</a></span>
                        <span class="archive-item-meta">26</span>
                    </div>
                </div>
            </div>

            <div class="card card-widget">
                <div class="card-header">{{icon:tag}}标签</div>
                <div class="tags-container">
                    <a href="#" class="tag" title="Typecho（8）">Typecho<span class="tag-count">8</span></a>
                    <a href="#" class="tag" title="CSS（6）">CSS<span class="tag-count">6</span></a>
                    <a href="#" class="tag" title="PHP（5）">PHP<span class="tag-count">5</span></a>
                    <a href="#" class="tag" title="随笔（3）">随笔<span class="tag-count">3</span></a>
                    <a href="#" class="tag" title="JavaScript（2）">JavaScript<span class="tag-count">2</span></a>
                </div>
            </div>

            <div class="sidebar-sticky">

__TOC__
                <div class="card card-widget">
                    <div class="card-header">{{icon:comments}}近期评论</div>
                    <div class="rcomment-list">
                        <a class="rcomment-item" href="./post.html#comment-1">
                            <img class="rcomment-face" src="__FACE_A__" alt="" width="32" height="32" loading="lazy">
                            <span class="rcomment-main">
                                <span class="rcomment-head">
                                    <span class="rcomment-author">朝朝听雨</span>
                                    <span class="rcomment-time">2 小时前</span>
                                </span>
                                <span class="rcomment-bubble">评论区也是相当好的设计！</span>
                            </span>
                        </a>
                        <a class="rcomment-item" href="./post.html#comment-2">
                            <img class="rcomment-face" src="__FACE_B__" alt="" width="32" height="32" loading="lazy">
                            <span class="rcomment-main">
                                <span class="rcomment-head">
                                    <span class="rcomment-author">ayiya</span>
                                    <span class="rcomment-time">3 天前</span>
                                </span>
                                <span class="rcomment-bubble">好可爱的站点啊，是让人眼前一亮的！可惜我休假了，暂时没法添加友链</span>
                            </span>
                        </a>
                        <a class="rcomment-item" href="./post.html#comment-3">
                            <img class="rcomment-face" src="__FACE_C__" alt="" width="32" height="32" loading="lazy">
                            <span class="rcomment-main">
                                <span class="rcomment-head">
                                    <span class="rcomment-author">云间漫步</span>
                                    <span class="rcomment-time">1 周前</span>
                                </span>
                                <span class="rcomment-bubble">路过贵站，排版很舒服，喜欢这种记录生活的调调</span>
                            </span>
                        </a>
                    </div>
                </div>

                <div class="card card-widget">
                    <div class="card-header">{{icon:chart-line}}站点信息</div>
                    <div class="site-stats">
                        <span class="site-stat">文章<b data-count="42">42</b></span>
                        <span class="site-stat">评论<b data-count="318">318</b></span>
                        <span class="site-stat">分类<b data-count="5">5</b></span>
                        <span class="site-stat">运行<b data-count="2190">2190 天</b></span>
                    </div>
                    <div class="site-footer-note">
                        <p>&copy; 2026 Noline</p>
                        <p>由 Typecho 驱动 · 主题 Noline</p>
                        <p><a href="https://beian.miit.gov.cn/">京ICP备00000000号</a></p>
                    </div>
                </div>

            </div>

        </aside>
"""


# 目录卡：模板里被 $this->is('single') 挡着，所以只有 post/health 两份夹具有它，
# index 与 page2/page3 替换成空串——列表页压根不该出现这张卡。
# 里面只有模板那层空 <ol>：条目和标题 id 都是 main.js 现场搭的，夹具在浏览器里
# 打开时会跑一遍真的 initToc。健康页正文只有一两个 h2，正好当「少于两个标题
# 整张卡保持 hidden」那条分支的反例。
TOC_CARD = """                <div class="card card-widget sidebar-toc" id="sidebar-toc" hidden>
                    <div class="card-header">{{icon:bars}}目录</div>
                    <nav class="toc-content" id="toc-content" aria-label="文章目录">
                        <ol class="toc-list" id="toc-list"></ol>
                    </nav>
                </div>
"""


# 评论预览用的假头像。颜色必须各不相同，否则叠在一起看不出描边和层次。
FACE_COLOURS = {'a': '4482e5', 'b': 'e5764a', 'c': '47b26f', 'd': '9a5fd0'}


def fake_face(key):
    return ("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96'>"
            "<rect width='96' height='96' fill='%23" + FACE_COLOURS[key] + "'/>"
            "<circle cx='48' cy='36' r='17' fill='%23ffffff' opacity='.92'/>"
            "<path d='M14 96c0-19 15-33 34-33s34 14 34 33z' fill='%23ffffff' opacity='.92'/></svg>")


def comment_preview(items, indent=20):
    """照抄 noline_comment_preview() 会渲染出的结构。

    items 是 (作者, 回复给谁, 正文, 头像色) 的列表，新→旧。
    空列表返回空串——夹具要验证的正是「没有已审核评论就整块不出现」这条分支。
    头像按色去重，对应 PHP 里按邮箱去重。
    """
    if not items:
        return ''

    pad = ' ' * indent

    faces = []
    for item in items:
        key = item[3]
        if key not in faces:
            faces.append(key)
        if len(faces) >= 4:
            break

    face_html = ''.join(
        '<img class="card-comments-face" src="%s" alt="" width="26" height="26" loading="lazy">'
        % fake_face(key) for key in faces)

    rows = []
    for author, reply, text, _ in items:
        reply_html = '<span class="card-comments-reply">＠%s</span>' % reply if reply else ''
        rows.append('%s    <span class="card-comments-item">'
                    '<span class="card-comments-author">%s</span>%s'
                    '<span class="card-comments-text">%s</span>'
                    '</span>' % (pad, author, reply_html, text))

    return ('%s<a class="card-comments" href="./post.html#comments">\n'
            '%s    <span class="card-comments-faces" aria-hidden="true">%s</span>\n'
            '%s    <span class="card-comments-list">\n%s\n%s    </span>\n'
            '%s</a>' % (pad, pad, face_html, pad, '\n'.join(rows), pad, pad))


# 信息流的左右朝向。和 index/archive.php 的 $feedIndex 同一套算法：文章卡、动态卡
# 都占一号，按全局序号的奇偶决定封面在左还是在右。夹具三页共用一个计数器连续递增，
# 验的就是翻页接缝那两张不会同侧。
FEED_INDEX = [0]


def next_feed_flip():
    flip = FEED_INDEX[0] % 2
    FEED_INDEX[0] += 1
    return flip


def post_card(title, excerpt, views, comments, preview=(), cover=False, reading=0):
    block = comment_preview(preview, indent=20)
    cover_cls = ' has-cover' if cover else ''
    flip_cls = ' flip' if next_feed_flip() else ''
    cover_html = ('                    <a class="post-cover" href="./post.html" tabindex="-1" aria-hidden="true">\n'
                  '                        <img src="__COVER__" alt="" loading="lazy">\n'
                  '                    </a>\n') if cover else ''
    reading_html = ('                                <span class="meta-item">{{icon:book-open}}约 %d 分钟</span>\n'
                    % reading) if reading > 0 else ''
    return """                <article class="card post-card__COVER_CLS____FLIP_CLS__">
__COVER_BLOCK__                    <div class="post-card-body">
                    <div class="post-header">
                        <img src="__AVATAR__" alt="" class="avatar" width="40" height="40" loading="lazy">
                        <div class="header-text">
                            <div class="author-name">QINE</div>
                            <div class="post-meta">
                                <span class="meta-item">{{icon:clock}}<time datetime="2026-09-28">2026-09-28</time></span>
                                <span class="category-tag"><a href="#">开发</a></span>
__READING_BLOCK__                            </div>
                        </div>
                    </div>

                    <h2 class="post-title">
                        <a class="post-title-link" href="./post.html">__TITLE__</a>
                    </h2>

                    <div class="post-excerpt">__EXCERPT__</div>

                    <div class="post-footer">
                        <span class="stat-item">{{icon:eye}} 阅读 __VIEWS__</span>
                        <a class="stat-item" href="./post.html#comments">{{icon:comment}} 评论 __COMMENTS__</a>
                        <a class="read-more" href="./post.html">阅读全文</a>
                    </div>__PREVIEW__
                    </div>
                </article>
""".replace('__COVER_CLS__', cover_cls).replace('__FLIP_CLS__', flip_cls) \
   .replace('__COVER_BLOCK__', cover_html).replace('__READING_BLOCK__', reading_html) \
   .replace('__TITLE__', title).replace('__EXCERPT__', excerpt) \
   .replace('__VIEWS__', str(views)).replace('__COMMENTS__', str(comments)) \
   .replace('__PREVIEW__', '\n\n' + block if block else '')


def status_card(content, ago, absolute, views, comments, preview=()):
    """照抄 public/status-card.php 会渲染出的结构。
    .status-toggle 必须带 hidden —— main.js 量过之后才放开，
    夹具要验证的正是「短内容不出按钮、长内容出按钮」这条分支。

    动态卡也要占一个信息流序号：模板里的 $feedIndex 对文章卡和动态卡一起递增，
    漏掉它会让动态卡后面那张文章卡的左右朝向和真实渲染不一致。"""
    block = comment_preview(preview, indent=28)
    next_feed_flip()
    return """                <article class="card status-card">
                    <div class="status-main">
                        <img src="__AVATAR__" alt="" class="status-avatar" width="42" height="42" loading="lazy">

                        <div class="status-body">
                            <div class="status-author">QINE</div>

                            <div class="status-meta">
                                <a class="status-time" href="./post.html" title="__ABS__">__AGO__</a>
                            </div>

                            <div class="status-fold">
                                <div class="status-content">__CONTENT__</div>
                                <button type="button" class="status-toggle" hidden aria-expanded="false">全文</button>
                            </div>

                            <div class="status-actions">
                                <span class="status-action">{{icon:eye}} 阅读 __VIEWS__</span>
                                <a class="status-action" href="./post.html#comments">{{icon:comment}} 评论 __COMMENTS__</a>
                            </div>__PREVIEW__
                        </div>
                    </div>
                </article>
""".replace('__CONTENT__', content).replace('__AGO__', ago).replace('__ABS__', absolute) \
   .replace('__VIEWS__', str(views)).replace('__COMMENTS__', str(comments)) \
   .replace('__PREVIEW__', '\n\n' + block if block else '')


PAGE_HREF = {1: './index.html', 2: './page2.html', 3: './page3.html'}


def page_nav(page, total):
    """照抄 Typecho 1.2.1 核心 Box::render() 的输出形状。
       itemTag 是 'li'，所以 prev/next/current 三个类挂在 <li> 上、<a> 是裸的；
       <span> 只出现在 ... 省略号项里，current 里不是 span。
       prev 只在 page>1 时输出，next 只在 page<total 时输出——
       initFeedPaging 认的就是那个 <li class="next"><a href>，形状错了整条链路就断了。"""
    rows = []

    if page > 1:
        rows.append('                <li class="prev"><a href="%s">&laquo; 前一页</a></li>'
                    % PAGE_HREF[page - 1])
    for n in range(1, total + 1):
        if n == page:
            rows.append('                <li class="current"><a href="%s">%d</a></li>'
                        % (PAGE_HREF[n], n))
        else:
            rows.append('                <li><a href="%s">%d</a></li>' % (PAGE_HREF[n], n))
    if page < total:
        rows.append('                <li class="next"><a href="%s">后一页 &raquo;</a></li>'
                    % PAGE_HREF[page + 1])

    return '            <ol class="page-navigator">\n%s\n            </ol>' % '\n'.join(rows)


# 和 public/feed-status.php 逐字一致。这块在源码里初始 hidden，
# 由 main.js 确认能接管才揭开——所以夹具里也照抄 hidden，验的就是那个揭开动作。
FEED_STATUS = """            <div class="feed-status" id="feed-status" hidden>
                <span class="feed-spinner" hidden aria-hidden="true"></span>
                <span class="feed-text" role="status" aria-live="polite"></span>
                <button type="button" class="feed-retry" hidden>重新加载</button>
            </div>"""


def feed_cats(active='index'):
    """照抄 public/feed-cats.php 的输出形状。active 是「当前归档」的判据：
    首页传 index，分类页传那个分类的 slug。命中的那颗必须带 aria-current="page"，
    读屏才知道它是「你现在在这儿」，而不是又一个分类链接。

    分类给到九条、还塞了一条超长名：这一行放不下的时候右端那颗「更多」才量得出来，
    夹具要是刚好排得下，那个面板永远打不开，等于没验。"""
    cats = [('dev', '开发', 6), ('life', '日记', 12), ('tech', '技术', 3),
            ('read', '阅读', 2), ('shot', '随手拍', 9), ('travel', '旅行', 4),
            ('film', '影音', 7), ('kernel', '一个名字特别特别长的分类', 1), ('food', '美食', 5)]
    rows = []
    for slug, name, count in cats:
        on = ' active' if slug == active else ''
        cur = ' aria-current="page"' if on else ''
        rows.append('                <a class="feed-cat%s" href="./index.html"%s>'
                    '<span>%s</span>'
                    '<span class="feed-cat-count">%d</span></a>' % (on, cur, name, count))

    home_on = ' active' if active == 'index' else ''
    home_cur = ' aria-current="page"' if home_on else ''
    return ('        <div class="feed-cats">\n'
            '            <nav class="feed-cats-scroll" id="feed-cats-scroll" aria-label="分类导航">\n'
            '                <a class="feed-cat%s" href="./index.html"%s>全部</a>\n%s\n'
            '            </nav>\n'
            '            <button type="button" class="feed-cat-more" id="feed-cats-more" hidden'
            ' aria-haspopup="true" aria-expanded="false" aria-controls="feed-cats-pop">'
            '{{icon:folder}}<span>更多</span></button>\n'
            '            <div class="feed-cats-pop" id="feed-cats-pop" role="group"'
            ' aria-label="更多分类" hidden></div>\n'
            '        </div>' % (home_on, home_cur, '\n'.join(rows)))


INDEX_BODY = """
<div class="site-hero has-cover">
    <div class="site-hero-cover" style="background-image:url('__COVER__')" aria-hidden="true"></div>
    <div class="site-hero-inner">
        <img src="__FACE_A__" alt="" class="site-hero-avatar" width="136" height="136">
        <h1 class="site-hero-title">Noline 演示站</h1>
        <p class="site-hero-bio">众里寻他千百度，蓦然回首，那人却在灯火阑珊处。</p>
    </div>
    <div class="hero-lip" aria-hidden="true"></div>
</div>

<div class="container">
    <div class="grid-layout">

        <div class="main-content feed">
            <div class="card card-widget">
                <div class="card-header">{{icon:bullhorn}}公告</div>
                <div class="notice-body">
                    <span class="notice-icon" aria-hidden="true">📢</span>
                    <div class="notice-text"><p>本周迁移右栏 card-widget 与 1px 描边卡片语言，若发现排版异常请在评论区反馈。</p></div>
                </div>
            </div>
__CATS__
__CARDS__
__NAV__
__FEED__
        </div>

__SIDEBAR__
    </div>
</div>
"""


# 第 2、3 页的壳。头部用归档页那套（archive-header 是 div，不是 article），
# 顺便验证 initFeedPaging 的 :scope > article 不会把它当成卡片捞进去。
LIST_PAGE_BODY = """
<div class="container">
    <div class="grid-layout">

        <div class="main-content feed">
            <div class="archive-header">
                <h1 class="archive-title">分类：开发</h1>
                <p class="archive-count">{{icon:file-lines}}共 6 篇文章</p>
            </div>
__CATS__
__CARDS__
__NAV__
__FEED__
        </div>

__SIDEBAR__
    </div>
</div>
"""


POST_BODY = """
<div class="post-hero has-cover">
    <div class="post-hero-cover" style="background-image:url('__COVER__')" aria-hidden="true"></div>

    <div class="post-hero-info">
        <div class="post-hero-top">
            <span class="post-cats"><a href="#">开发</a>, <a href="#">前端</a></span>
        </div>

        <h1 class="post-hero-title">把散落的硬编码颜色收敛成一套设计令牌</h1>

        <div class="post-hero-meta">
            <span class="meta-pill is-plain">{{icon:clock}}<time datetime="2026-09-28">2026-09-28</time></span>
            <span class="meta-pill is-plain">{{icon:eye}}阅读 <b>192</b></span>
            <span class="meta-pill is-plain">{{icon:book-open}}约 6 分钟</span>
            <a class="meta-pill" href="#comments">{{icon:comment}}2 条评论</a>
        </div>
    </div>

    <div class="post-hero-card">
        <img src="__COVER__" alt="" width="300" height="169">
    </div>

    <div class="hero-lip" aria-hidden="true"></div>
</div>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">

                <div class="post-content">
                    <p>这是一段正文。主题原来把 <strong>加粗</strong>、<a href="./index.html">站内链接</a>、
                        <a href="https://example.com/">站外链接</a>、<code>行内代码</code>、
                        <mark>高亮</mark> 和 <del>删除线</del> 的颜色散落在各处，换一次肤要改十几个文件。</p>

                    <h2>二级标题带下划线</h2>
                    <p>现在所有颜色都从 <code>:root</code> 的令牌取值，浅色和深色各一套，
                        强调色还能在后台配。下面这段代码块用来验证三件事：红绿灯圆点在横向滚动时是否钉住、
                        复制按钮会不会跟着内容滚走、以及 highlight.js 跑过之后 padding 有没有变。</p>

                    <pre><code class="language-javascript">function noline_accent_style() {
    // 这是一行足够长的注释，用来撑出横向滚动条，验证 background-image 画的圆点不会跟着内容一起滚走，而 position:absolute 的伪元素会
    const tokens = ['--accent', '--accent-rgb', '--accent-hover', '--accent-bg', '--accent-ring'];
    return tokens.map(function (name, index) {
        return { name: name, index: index, value: getComputedStyle(document.documentElement).getPropertyValue(name) };
    }).filter(function (token) {
        return token.value.trim() !== '';
    });
}</code></pre>

                    <p>再来一段没有语言标注的代码块——highlight.js 识别不出语言时不会加 <code>.hljs</code>，
                        这时候必须靠 article.css 显式给的浅色前景，否则就是深底深字：</p>

                    <pre><code>plain text block
第二行
    缩进的一行</code></pre>

                    <h3>三级标题</h3>
                    <blockquote>
                        <p>引用块。左边框和底色都走 <code>--accent</code>，所以后台换个强调色这里会跟着变。</p>
                    </blockquote>

                    <ul>
                        <li>无序列表第一项</li>
                        <li>第二项，带一个<a href="./index.html">链接</a>
                            <ul>
                                <li>嵌套一层</li>
                                <li>嵌套两层</li>
                            </ul>
                        </li>
                        <li>第三项</li>
                    </ul>

                    <ol>
                        <li>有序列表第一项</li>
                        <li>第二项</li>
                    </ol>

                    <h3>表格</h3>
                    <table>
                        <thead>
                            <tr><th>令牌</th><th>浅色</th><th>深色</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>--bg-color</td><td>#f2f5fa</td><td>#14181f</td></tr>
                            <tr><td>--card-bg</td><td>#ffffff</td><td>#1c222b</td></tr>
                            <tr><td>--topbar-bg</td><td>rgba(255,255,255,.86)</td><td>rgba(23,28,36,.88)</td></tr>
                            <tr><td>--text-main</td><td>#354b60</td><td>#c3cedb</td></tr>
                        </tbody>
                    </table>

                    <h3>图片</h3>
                    <p><img src="__FIGURE__" alt="一张示意图" width="800" height="300"></p>
                    <p><img src="这张图故意不存在.png" alt="一张已经失效的外链图" width="800" height="300"></p>

                    <hr>

                    <p>最后是脚注区<sup class="footnote-ref"><a href="#fn1">[1]</a></sup>。</p>

                    <div class="footnotes">
                        <ol>
                            <li id="fn1">
                                <p>脚注正文。<a href="#fnref1" class="footnote-backref">&#8617;</a></p>
                            </li>
                        </ol>
                    </div>
                </div>

                <div class="post-copyright">
                    <span class="post-copyright-avatar" aria-hidden="true">
                        <img src="__AVATAR__" alt="" class="post-copyright-avatar-back" width="66" height="66" loading="lazy">
                        <img src="__AVATAR__" alt="" class="post-copyright-avatar-front" width="66" height="66" loading="lazy">
                    </span>
                    <p class="post-copyright-name">QINE</p>
                    <p class="post-copyright-desc">众里寻他千百度，蓦然回首，那人却在灯火阑珊处。</p>
                    <div class="post-copyright-badges">
                        <span class="post-copyright-badge">转载请注明出处</span>
                    </div>
                </div>

                <div class="post-tags">
                    <a class="tag" href="#">Typecho</a>
                    <a class="tag" href="#">CSS</a>
                    <a class="tag" href="#">设计令牌</a>
                </div>

            </article>

            <div class="card random-card">
                <p>不知道看什么？</p>
                <a class="btn-primary" href="./index.html">随机去往一篇</a>
            </div>

            <nav class="article-nav" aria-label="上一篇 / 下一篇">
                <div class="article-nav-dock">
                    <div class="article-nav-cell"><span class="article-nav-label">上一篇</span><a class="article-nav-title" href="./index.html">给 Typecho 主题加上深色模式要注意什么</a></div>
                    <div class="article-nav-cell article-nav-next"><span class="article-nav-label">下一篇</span><a class="article-nav-title" href="./index.html">为什么 backdrop-filter 会吃掉 position: fixed</a></div>
                </div>
            </nav>

            <section class="related">
                <h2 class="related-headline">
                    {{icon:heart}}
                    <span class="related-lead">喜欢这篇文章的人也看了</span>
                    <a class="related-random" href="./index.html">随便逛逛</a>
                </h2>

                <div class="related-list">
                    <a class="related-item" href="./post.html" title="Typecho 的 widget() 按类名缓存实例，参数不参与">
                        <span class="related-cover" style="background-image:url('__COVER__')" aria-hidden="true"></span>
                        <span class="related-text">
                            <span class="related-title">Typecho 的 widget() 按类名缓存实例，参数不参与</span>
                            <span class="related-date">2026-09-18</span>
                        </span>
                    </a>
                    <a class="related-item" href="./post.html" title="把 400 行的 design.css 拆成令牌层和组件层">
                        <span class="related-cover" style="background-image:url('__COVER__')" aria-hidden="true"></span>
                        <span class="related-text">
                            <span class="related-title">把 400 行的 design.css 拆成令牌层和组件层，改主题配色只用改一处</span>
                            <span class="related-date">2026-08-30</span>
                        </span>
                    </a>
                    <a class="related-item" href="./post.html" title="没有封面的那一条">
                        <span class="related-text">
                            <span class="related-title">没填 thumb 自定义字段的文章，相关文章这张卡不该留一块灰斑</span>
                            <span class="related-date">2026-07-12</span>
                        </span>
                    </a>
                </div>
            </section>

            <div id="comments" class="card article-card">

                <h2 class="comments-header">
                    {{icon:comments}} 评论 <span class="count">2</span>
                </h2>

                <div id="comment-respond" class="comment-respond">
                    <div class="comment-form">
                        <div class="comment-form-head">
                            <h3 class="comment-form-title">添加新评论</h3>
                        </div>

                        <form method="post" action="#" id="comment-form" role="form">
                            <div class="comment-form-grid">
                                <div class="comment-field">
                                    <label class="sr-only" for="author">称呼</label>
                                    <input type="text" name="author" id="author" class="comment-input" placeholder="昵称 *" required>
                                </div>
                                <div class="comment-field">
                                    <label class="sr-only" for="mail">Email</label>
                                    <input type="email" name="mail" id="mail" class="comment-input" placeholder="Email *">
                                </div>
                            </div>
                            <div class="comment-field">
                                <label class="sr-only" for="url">网站</label>
                                <input type="url" name="url" id="url" class="comment-input" placeholder="网站 (https://)">
                            </div>

                            <label class="sr-only" for="textarea">评论内容</label>
                            <textarea rows="8" cols="50" name="text" id="textarea" class="comment-textarea" placeholder="说点什么吧…" required></textarea>

                            <div class="comment-form-actions">
                                <button type="submit" class="submit-btn">提交评论</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="comment-list">
                    <ol>
                        <li id="comment-1" class="comment-body">
                            <div class="comment-author">
                                <img class="avatar" src="__AVATAR__" alt="" width="48" height="48">
                                <cite class="fn"><a href="https://example.com/" rel="nofollow">路人甲</a></cite>
                                <span class="comment-meta"><a href="#comment-1">2026-09-29 10:24</a></span>
                                <span class="comment-awaiting-moderation">评论待审中</span>
                            </div>
                            <div class="comment-content">
                                <p>评论里的代码块必须是浅色的，不能被正文那套红绿灯污染：</p>
                                <pre><code>echo 'hello';</code></pre>
                            </div>
                            <div class="comment-reply"><a rel="nofollow" href="#comment-respond">回复</a></div>

                            <div class="comment-children">
                                <ol>
                                    <li id="comment-2" class="comment-body">
                                        <div class="comment-author">
                                            <img class="avatar" src="__AVATAR__" alt="" width="48" height="48">
                                            <cite class="fn">QINE</cite>
                                            <span class="comment-meta"><a href="#comment-2">2026-09-29 11:02</a></span>
                                        </div>
                                        <div class="comment-content">
                                            <p>嵌套回复。作用域限定在 <code>.post-content pre</code>，这里不受影响。</p>
                                        </div>
                                        <div class="comment-reply"><a rel="nofollow" href="#comment-respond">回复</a></div>
                                    </li>
                                </ol>
                            </div>
                        </li>
                    </ol>
                </div>

                <!-- 位置照抄 comments.php:83 的那排评论页码，用的是 $comments->pageNav()，
                     渲染出来和归档分页同名同结构。本页有 .main-content、有分页 <ol>，
                     却没有 #feed-status，就是那条雷的活体反例：initFeedPaging 必须在
                     判到 #feed-status 时就退出，这排页码要照常显示、照常能点。 -->
                <ol class="page-navigator">
                    <li class="prev"><a href="#">&laquo; 前一页</a></li>
                    <li class="current"><a href="#">1</a></li>
                    <li><a href="#">2</a></li>
                    <li class="next"><a href="#">后一页 &raquo;</a></li>
                </ol>

            </div>
        </div>

__SIDEBAR__
    </div>
</div>
"""


# ---------------------------------------------------------------- 健康页夹具
#
# 数字全部现算，公式抄 plugins/NolineApi/Plugin.php（BMI 阈值、理想区间、7/30/90 天基线）
# 和 functions.php 的 noline_health_trend()（600×170 画布、x 按真实日期分布、上下 12% 余量）。
# 刻意留下的形态：一条断档（08-24 → 09-06）、一段反弹、一次跨档（over → normal）、
# 一条持平（09-06 → 09-20，验 is-quiet）。thin / obese 两档要极端体重，不造假。

HEALTH_HEIGHT = 175.0
HEALTH_TARGET = 65.0
HEALTH_DATA = [
    ('2026-05-06', 74.0, '开始记录'),
    ('2026-05-13', 73.4, ''),
    ('2026-05-20', 72.8, ''),
    ('2026-05-28', 72.1, '换了早餐'),
    ('2026-06-04', 71.6, ''),
    ('2026-06-11', 71.2, ''),
    ('2026-06-19', 70.9, ''),
    ('2026-06-27', 70.3, ''),
    ('2026-07-05', 69.8, ''),
    ('2026-07-14', 69.1, ''),
    ('2026-07-23', 68.7, ''),
    ('2026-08-02', 68.2, ''),
    ('2026-08-13', 68.6, '出差一周'),
    ('2026-08-24', 68.1, ''),
    ('2026-09-06', 67.4, '作息回来了'),
    ('2026-09-20', 67.4, ''),
    ('2026-10-05', 67.0, ''),
    ('2026-10-12', 66.0, '还差 1 公斤'),
]


def health_bmi(weight):
    return round(weight / (HEALTH_HEIGHT / 100) ** 2, 1)


def health_category(bmi):
    """阈值抄 Plugin.php 的 category()——中国成人标准，不是 WHO 的 25/30。"""
    if bmi < 18.5:
        return 'thin'
    if bmi < 24.0:
        return 'normal'
    if bmi < 28.0:
        return 'over'
    return 'obese'


def health_delta(value):
    """health.php 里 $deltaText / $deltaClass 那对闭包的 Python 版。"""
    if value is None or abs(value) < 0.05:
        return ('—' if value is None else '持平', ' is-quiet')
    return ('+' if value > 0 else '') + str(value) + 'kg', (' is-up' if value > 0 else ' is-down')


def health_axis(weights):
    """同 functions.php 的 noline_health_axis()：几乎没变化时按 ±1kg 撑开，
    否则在极值外各让 12% 余量、再往外取到 0.5kg。"""
    lo, hi = min(weights), max(weights)
    if hi - lo < 2:
        mid = (hi + lo) / 2
        return round(mid - 1, 1), round(mid + 1, 1)
    room = (hi - lo) * 0.12
    return math.floor((lo - room) * 2) / 2, math.ceil((hi + room) * 2) / 2


def health_trend_svg(series):
    """复刻 noline_health_trend()：元素只带 class，颜色一律由 design.css 赋值。"""
    width, height, pad = 600, 170, 10
    days = [date.fromisoformat(r['date']).toordinal() for r in series]
    weights = [r['weight'] for r in series]
    t0, t1 = min(days), max(days)
    lo, hi = health_axis(weights)

    xy = []
    for i, (t, w) in enumerate(zip(days, weights)):
        # 同 noline_health_trend()：全挤在同一天时退回按序号等距铺开
        ratio = (t - t0) / (t1 - t0) if t1 > t0 else i / (len(series) - 1)
        xy.append((round(pad + ratio * (width - 2 * pad), 1),
                   round(height - pad - (w - lo) / (hi - lo) * (height - 2 * pad), 1)))

    line = ''.join(('M' if i == 0 else 'L') + '%s %s' % p for i, p in enumerate(xy))
    area = (line
            + 'L%s %s' % (xy[-1][0], height)
            + 'L%s %s' % (xy[0][0], height) + 'Z')
    grid = ''.join(
        '<line class="health-grid" x1="%s" y1="%s" x2="%s" y2="%s"/>'
        % (pad, round(pad + (height - 2 * pad) * g / 3, 1),
           width - pad, round(pad + (height - 2 * pad) * g / 3, 1))
        for g in (1, 2))

    return ('<svg class="health-svg" viewBox="0 0 %s %s" role="img" aria-label="体重趋势">'
            % (width, height)
            + '<path class="health-area" d="%s"/>' % area
            + grid
            + '<path class="health-line" pathLength="1" d="%s"/>' % line
            + '<circle class="health-dot" cx="%s" cy="%s" r="3"/>' % xy[0]
            + '<circle class="health-dot is-last" cx="%s" cy="%s" r="4.5"/>' % xy[-1]
            + '</svg>')


HEALTH_BODY = """
<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title">健康记录</h1>
                <p class="page-subtitle">{{icon:scale}} 体重、BMI 与它们的走势</p>

                <div class="post-content">
                    <p>这一页只负责画。数据在 <code>NolineApi</code> 插件的记录文件里，
                        写入走带密钥的接口，改身高、目标体重和可见性去插件设置页。</p>
                </div>

__TILES__

__CHART__

__LOG__

                <p class="health-foot">记录由 NolineApi 插件保管，主题只做展示；端点、密钥和写入开关都在插件设置页。</p>
            </article>
        </div>

__SIDEBAR__
    </div>
</div>
"""


def health_page():
    series = []
    prev = None
    for day, weight, note in HEALTH_DATA:
        bmi = health_bmi(weight)
        series.append({
            'date': day,
            'weight': weight,
            'note': note,
            'bmi': bmi,
            'category': health_category(bmi),
            'delta': None if prev is None else round(weight - prev, 1),
        })
        prev = weight

    weights = [r['weight'] for r in series]
    latest = series[-1]
    span = (date.fromisoformat(latest['date']) - date.fromisoformat(series[0]['date'])).days
    avg = round(sum(weights) / len(weights), 1)
    lo, hi = health_axis(weights)
    labels = {'thin': '偏瘦', 'normal': '正常', 'over': '超重', 'obese': '肥胖'}

    # ---- 四块统计磁贴
    latest_text, _ = health_delta(latest['delta'])
    category = latest['category']
    remaining = round(latest['weight'] - HEALTH_TARGET, 1)
    goal_value, goal_note = (
        ('0', '已经站在目标上') if abs(remaining) < 0.05 else
        (str(abs(remaining)), '还要减这么多' if remaining > 0 else '还要增这么多'))

    tiles = '''
                <div class="health-stats">

                    <div class="health-tile">
                        <span class="health-tile-label">{{icon:scale}} 当前体重</span>
                        <span class="health-tile-value">%s<i>kg</i></span>
                        <span class="health-tile-note">%s</span>
                    </div>

                    <div class="health-tile">
                        <span class="health-tile-label">{{icon:heart}} BMI</span>
                        <span class="health-tile-value">%s</span>
                        <span class="health-tile-note"><b class="health-badge is-%s">%s</b> · %s</span>
                    </div>

                    <div class="health-tile">
                        <span class="health-tile-label">{{icon:compass}} 目标</span>
                        <span class="health-tile-value">%s<i>kg</i></span>
                        <span class="health-tile-note">%s</span>
                    </div>

                    <div class="health-tile">
                        <span class="health-tile-label">{{icon:clock}} 记录</span>
                        <span class="health-tile-value">%d<i>次</i></span>
                        <span class="health-tile-note">跨 %d 天 · 均值 %skg</span>
                    </div>

                </div>''' % (
        latest['weight'],
        '第一条记录' if latest['delta'] is None else '较上次 ' + latest_text,
        latest['bmi'], category, labels[category], latest['date'],
        goal_value, goal_note,
        len(series), span, avg)

    # ---- 折线 + 7/30/90 天涨跌
    chips = []
    for days in (7, 30, 90):
        anchor = (date.fromisoformat(latest['date']) - timedelta(days=days)).isoformat()
        from_date, from_weight = None, None
        for row in series:
            if row['date'] <= anchor:
                from_date, from_weight = row['date'], row['weight']
        delta = None if from_weight is None else round(latest['weight'] - from_weight, 1)
        text, cls = health_delta(delta)
        chips.append('<span class="health-delta%s"><b>%s</b><i>%d 天%s</i></span>'
                     % (cls, text, days,
                        '' if from_date is None else ' · ' + from_date))

    chart = '''
                <section class="health-chart">
                    <div class="health-block-head">
                        <span class="card-header">{{icon:chart-line}}体重趋势</span>
                        <span class="health-block-range">%s → %s</span>
                    </div>

                    <div class="health-plot">
                        <span class="health-axis">%s</span>
                        %s
                        <span class="health-axis">%s</span>
                    </div>

                    <div class="health-deltas">
                        %s
                    </div>
                </section>''' % (
        series[0]['date'], latest['date'], hi, health_trend_svg(series), lo,
        '\n                        '.join(chips))

    # ---- 最近 12 条
    rows = []
    for row in list(series)[-12:][::-1]:
        text, cls = health_delta(row['delta'])
        rows.append(
            '<div class="health-row">'
            '<span class="health-row-date">%s</span>'
            '<span class="health-row-weight">%s<i>kg</i></span>'
            '<span class="health-row-delta%s">%s</span>'
            '<span class="health-row-bmi"><b class="health-badge is-%s">%s</b></span>'
            '%s</div>'
            % (row['date'][5:], row['weight'], cls, text,
               row['category'], row['bmi'],
               ('<span class="health-row-note">%s</span>' % row['note']) if row['note'] else ''))

    log = '''
                <section class="health-log">
                    <div class="health-block-head">
                        <span class="card-header">{{icon:clock}}最近记录</span>
                        <span class="health-block-range">身高 %scm</span>
                    </div>

                    %s

                    <p class="health-more">更早的 %d 条走接口取：GET /api/health/records?limit=366</p>
                </section>''' % (
        ('%g' % HEALTH_HEIGHT), '\n                    '.join(rows), len(series) - 12)

    return HEALTH_BODY.replace('__TILES__', tiles).replace('__CHART__', chart).replace('__LOG__', log)


BILLS_TODAY = date(2026, 10, 14)

# 分类代码 → 中文名，和 bills.php 模板里那张 $labels 同一份
BILLS_KIND_LABELS = {
    'veg': '蔬菜', 'fruit': '水果', 'meat': '肉禽', 'fish': '水产',
    'egg': '蛋奶', 'grain': '米面粮油', 'seasoning': '调料', 'other': '其他',
}

# 同 bills.php 的 $row_class：流水行的分类配色跟着占比条走，类名写全不拼前缀
BILLS_ROW_CLASS = {
    'veg': 'bill-item is-veg', 'fruit': 'bill-item is-fruit', 'meat': 'bill-item is-meat',
    'fish': 'bill-item is-fish', 'egg': 'bill-item is-egg', 'grain': 'bill-item is-grain',
    'seasoning': 'bill-item is-seasoning', 'other': 'bill-item is-other',
}

# (品名, 分类代码, 参考价)。分类代码和插件 Bills::KINDS 一一对应，改了那边要改这里。
BILLS_CATALOG = [
    ('番茄', 'veg', 4.5), ('黄瓜', 'veg', 2.8), ('小白菜', 'veg', 2.0),
    ('土豆', 'veg', 3.2), ('茄子', 'veg', 3.6), ('青椒', 'veg', 4.0),
    ('萝卜', 'veg', 2.4), ('菠菜', 'veg', 5.0), ('南瓜', 'veg', 3.0),
    ('苹果', 'fruit', 8.8), ('香蕉', 'fruit', 4.6), ('橘子', 'fruit', 6.2),
    ('葡萄', 'fruit', 12.0), ('西瓜', 'fruit', 15.0), ('草莓', 'fruit', 22.0),
    ('五花肉', 'meat', 16.8), ('鸡腿', 'meat', 11.5), ('牛腩', 'meat', 42.0),
    ('排骨', 'meat', 28.5), ('鸡胸肉', 'meat', 9.9),
    ('鲫鱼', 'fish', 12.5), ('基围虾', 'fish', 38.0), ('带鱼', 'fish', 21.0),
    ('花蛤', 'fish', 8.0),
    ('鸡蛋', 'egg', 6.8), ('牛奶', 'egg', 12.5), ('豆腐', 'egg', 3.5),
    ('大米', 'grain', 32.0), ('挂面', 'grain', 6.5), ('面粉', 'grain', 15.0),
    ('食用油', 'grain', 59.9),
    ('生抽', 'seasoning', 9.9), ('食盐', 'seasoning', 2.5), ('生姜', 'seasoning', 3.8),
    ('大蒜', 'seasoning', 4.2),
    ('垃圾袋', 'other', 7.9), ('厨房用纸', 'other', 11.0),
]
BILLS_SHOPS = ['东门菜场', '盒马', '小区门口', '永辉', '']
# 价格的浮动周期，写死而不用随机数：夹具要每次跑出来一模一样，
# 否则重跑一次 mkpreview 就把 README 里的图换了一批数字。
BILLS_JITTER = [1.0, 0.82, 1.25, 0.94, 1.1, 0.76]
BILLS_NOTES = {
    '排骨': '煲汤用，让摊主剁好了',
    '基围虾': '周末才到货，比工作日便宜',
    '食用油': '5L 桶装，一次买够两个月',
}


def bills_records():
    """造五个月的买菜流水，形状和插件读出来的一样。

    六月整月空着：验的就是柱状图上「这个月一条没记」那一根矮柱和它旁边的金额，
    这种情况线上很常见（出差、家里囤货没吃完），不能只验 happy path。
    """
    rows = []
    day = date(2026, 5, 6)
    index = 0

    while day <= BILLS_TODAY:
        if day.month != 6 and index % 3 != 2:      # 不是天天买菜，三天里去两天
            picks = 1 + index % 3
            for offset in range(picks):
                name, kind, price = BILLS_CATALOG[(index * 5 + offset * 3) % len(BILLS_CATALOG)]
                amount = round(price * BILLS_JITTER[(index + offset) % len(BILLS_JITTER)], 1)
                rows.append({
                    'date': day.isoformat(),
                    'title': name,
                    'kind': kind,
                    'amount': amount,
                    'shop': BILLS_SHOPS[(index + offset) % len(BILLS_SHOPS)],
                    'note': BILLS_NOTES.get(name, ''),
                })
        # index 跟着日子走，不是跟着「记了账的那些天」走：漏一天就得往后错一格，
        # 写在 if 里会让跳过的那些天永远停在同一个格子上，流水卡死在月初。
        index += 1
        day += timedelta(days=1)

    return rows


def bills_money(value):
    """同 noline_bills_money()：12.50 → 12.5、20.00 → 20。"""
    text = '%.2f' % float(value)
    return text.rstrip('0').rstrip('.')


def bills_span():
    """同 Bills::span()：周一为一周之始，月按自然月。"""
    today = BILLS_TODAY
    monday = today - timedelta(days=today.isoweekday() - 1)
    first = today.replace(day=1)
    prev_first = (first - timedelta(days=1)).replace(day=1)
    prev_last = prev_first + timedelta(days=(calendar.monthrange(prev_first.year, prev_first.month)[1] - 1))

    return {
        'week_start': monday.isoformat(),
        'week_end': (monday + timedelta(days=6)).isoformat(),
        'prev_week_start': (monday - timedelta(days=7)).isoformat(),
        'prev_week_end': (monday - timedelta(days=1)).isoformat(),
        'month_start': first.isoformat(),
        'month_end': date(first.year, first.month,
                          calendar.monthrange(first.year, first.month)[1]).isoformat(),
        'prev_month_start': prev_first.isoformat(),
        'prev_month_end': prev_last.isoformat(),
    }


def bills_slice(rows, start, end):
    return [r for r in rows if start <= r['date'] <= end]


def bills_agg(rows, start, end):
    """同 Bills::aggregate()。"""
    picked = bills_slice(rows, start, end)
    amount = round(sum(r['amount'] for r in picked), 2)
    days = len({r['date'] for r in picked})
    top = max(picked, key=lambda r: r['amount']) if picked else None

    return {
        'start': start, 'end': end, 'amount': amount, 'count': len(picked), 'days': days,
        'avg_day': round(amount / days, 2) if days else 0.0,
        'avg_bill': round(amount / len(picked), 2) if picked else 0.0,
        'max': top,
    }


def bills_delta(current, previous):
    """同 noline_bills_delta()：返回 (文本, 类名后缀)。"""
    if not previous or not previous['count']:
        return '—', 'is-quiet'

    diff = round(current['amount'] - previous['amount'], 2)
    if abs(diff) < 0.005:
        return '持平', 'is-quiet'

    return ('+' if diff > 0 else '−') + bills_money(abs(diff)), ('is-up' if diff > 0 else 'is-down')


def bills_months(rows):
    """同 Bills::months()：最近 6 个自然月，没记录的那个月也要出现。"""
    keys = []
    cursor = BILLS_TODAY.replace(day=1)
    for _ in range(6):
        cursor = (cursor - timedelta(days=1)).replace(day=1)
        keys.append(cursor.isoformat()[:7])
    keys.append(BILLS_TODAY.isoformat()[:7])
    keys.sort()

    out = []
    for key in keys:
        picked = [r for r in rows if r['date'][:7] == key]
        out.append({
            'month': key,
            'amount': round(sum(r['amount'] for r in picked), 2),
            'count': len(picked),
        })
    return out


def bills_bars(months):
    """同 noline_bills_bars()：柱高是相对最贵那个月的百分比。"""
    top = max(m['amount'] for m in months)
    if top <= 0:
        return ''

    bars = ''
    for month in months:
        amount = month['amount']
        height = max(3, round(amount / top * 100, 1)) if amount > 0 else 0
        bars += ('<div class="bill-bar%s">'
                 '<span class="bill-bar-plot"><i class="bill-bar-fill" style="height:%s%%"></i></span>'
                 '<span class="bill-bar-value">%s</span>'
                 '<span class="bill-bar-label">%d<i>月</i></span>'
                 '</div>') % (
            '' if amount > 0 else ' is-empty', height,
            bills_money(amount) if amount > 0 else '—', int(month['month'][5:]))

    return '<div class="bill-bars" role="img" aria-label="近 7 个月支出">%s</div>' % bars


def bills_kind_rows(rows):
    """同 noline_bills_kinds()：不足 4% 的归进最后那条「其余」。"""
    mods = {'veg': 'is-veg', 'fruit': 'is-fruit', 'meat': 'is-meat', 'fish': 'is-fish',
            'egg': 'is-egg', 'grain': 'is-grain', 'seasoning': 'is-seasoning',
            'other': 'is-other'}
    buckets = {}
    total = sum(r['amount'] for r in rows)

    for row in rows:
        kind = buckets.setdefault(row['kind'], {'amount': 0.0, 'count': 0})
        kind['amount'] = round(kind['amount'] + row['amount'], 2)
        kind['count'] += 1

    ranked = sorted(buckets.items(), key=lambda kv: -kv[1]['amount'])
    rest = {'amount': 0.0, 'share': 0.0, 'count': 0}
    out = ''

    for kind, bucket in ranked:
        share = round(bucket['amount'] / total, 3) if total else 0.0
        if share < 0.04:
            rest['amount'] = round(rest['amount'] + bucket['amount'], 2)
            rest['share'] += share
            rest['count'] += bucket['count']
            continue
        out += bills_kind_row(BILLS_KIND_LABELS[kind], bucket['amount'], share, mods[kind])

    if rest['count']:
        out += bills_kind_row('其余', rest['amount'], round(rest['share'], 3), 'is-rest')

    return '<div class="bill-kinds">%s</div>' % out


def bills_kind_row(label, amount, share, mod):
    width = round(max(0.0, min(1.0, share)) * 100, 1)

    # 配色类挂在整行上，跟 noline_bills_kind_row() 一致：design.css 选的是
    # .bill-kind.is-meat .bill-kind-fill，写错一层就是一片透明条。
    return ('<div class="bill-kind %s"><span class="bill-kind-label">%s</span>'
            '<span class="bill-kind-track"><i class="bill-kind-fill" style="width:%s%%"></i></span>'
            '<span class="bill-kind-amount">%s<i>元</i></span>'
            '<span class="bill-kind-share">%s%%</span></div>') % (mod, label, width,
                                                                  bills_money(amount), width)


BILLS_BODY = """
<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title">买菜记账</h1>
                <p class="page-subtitle">{{icon:wallet}} 买了什么、花了多少，按周和月汇总</p>

                <div class="post-content">
                    <p>这一页只负责画。流水记在 <code>NolineApi</code> 插件的文件里，
                        写入走带密钥的接口，可见性和写入开关都在插件设置页。</p>
                </div>

__TILES__

__CHART__

__SLICE__

__LOG__

                <p class="bill-foot">流水由 NolineApi 插件保管，主题只做展示；端点、密钥和写入开关都在插件设置页。</p>
            </article>
        </div>

__SIDEBAR__
    </div>
</div>
"""


def bills_page():
    """记账页夹具：把 Bills::summary() + 那几个 noline_bills_*() 的算法在这儿重算一遍，
    这样浏览器里看到的柱高、占比和涨跌配色和线上是同一套数。"""
    rows = bills_records()
    span = bills_span()
    week = bills_agg(rows, span['week_start'], span['week_end'])
    prev_week = bills_agg(rows, span['prev_week_start'], span['prev_week_end'])
    month = bills_agg(rows, span['month_start'], span['month_end'])
    prev_month = bills_agg(rows, span['prev_month_start'], span['prev_month_end'])
    totals = bills_agg(rows, rows[0]['date'], rows[-1]['date'])

    def tile(icon, label, value, note, cls='', hint=''):
        return ('<div class="bill-tile">'
                '<span class="bill-tile-label">{{icon:%s}} %s</span>'
                '<span class="bill-tile-value">%s<i>元</i></span>'
                '<span class="bill-tile-note%s">%s%s</span></div>') % (
            icon, label, bills_money(value),
            (' ' + cls) if cls else '',
            note,
            ('<i>· %s</i>' % hint) if hint else '')

    week_text, week_cls = bills_delta(week, prev_week)
    month_text, month_cls = bills_delta(month, prev_month)
    # 今天在一周/一个月里的第几天：不写这个，「较上周 −55.8」会被读成省了钱
    week_done = BILLS_TODAY.isoweekday()
    month_done = BILLS_TODAY.day
    month_len = calendar.monthrange(BILLS_TODAY.year, BILLS_TODAY.month)[1]

    tiles = '\n                <div class="bill-stats">\n\n                    %s\n\n                    %s\n\n                    %s\n\n                    %s\n\n                </div>' % (
        tile('calendar', '本周', week['amount'], '较上周 ' + week_text, week_cls,
             '%d/%d 天' % (week_done, 7)),
        tile('chart-line', '本月', month['amount'], '较上月 ' + month_text, month_cls,
             '%d/%d 天' % (month_done, month_len)),
        tile('clock', '本月日均', month['avg_day'],
             '%d 天有开销 · 单笔均 %s 元' % (month['days'], bills_money(month['avg_bill']))),
        tile('wallet', '累计', totals['amount'],
             '%d 笔 · 从 %s 记到今天' % (totals['count'], rows[0]['date'])),
    )

    months = bills_months(rows)
    chart = '''
                <section class="bill-chart">
                    <div class="bill-block-head">
                        <span class="card-header">{{icon:bars}}月度支出</span>
                        <span class="bill-block-range">最贵的一月 %s 元</span>
                    </div>
                    %s
                </section>''' % (bills_money(max(m['amount'] for m in months)), bills_bars(months))

    slice_html = '''
                <section class="bill-slice">
                    <div class="bill-block-head">
                        <span class="card-header">{{icon:tag}}这个月的钱花在哪</span>
                        <span class="bill-block-range">%s → %s</span>
                    </div>
                    %s
                </section>
                <p class="bill-top">{{icon:scale}}本月最贵的一笔：%s · %s 元（%s）</p>''' % (
        span['month_start'], span['month_end'], bills_kind_rows(bills_slice(rows, span['month_start'], span['month_end'])),
        month['max']['title'], bills_money(month['max']['amount']), month['max']['date'])

    # 按天分组，新的在前，取最近 12 天
    groups = []
    for row in reversed(rows):
        if not groups or groups[-1]['date'] != row['date']:
            if len(groups) >= 12 and groups[-1]['date'] != row['date']:
                break
            groups.append({'date': row['date'], 'amount': 0.0, 'items': []})
        groups[-1]['amount'] = round(groups[-1]['amount'] + row['amount'], 2)
        groups[-1]['items'].append(row)

    weekdays = ['周一', '周二', '周三', '周四', '周五', '周六', '周日']
    days = ''
    for group in groups:
        items = ''
        for item in group['items']:
            items += ('<div class="%s">'
                      '<span class="bill-item-kind">%s</span>'
                      '<span class="bill-item-title">%s</span>'
                      '%s'
                      '<span class="bill-item-amount">%s</span></div>%s') % (
                BILLS_ROW_CLASS[item['kind']],
                BILLS_KIND_LABELS[item['kind']], item['title'],
                ('<span class="bill-item-shop">%s</span>' % item['shop']) if item['shop'] else '',
                bills_money(item['amount']),
                ('<p class="bill-item-note">%s</p>' % item['note']) if item['note'] else '')

        days += ('<div class="bill-day"><div class="bill-day-head">'
                 '<span class="bill-day-date">%s</span>'
                 '<i class="bill-day-week">%s</i>'
                 '<span class="bill-day-total">%s<i>元</i></span></div>%s</div>') % (
            group['date'][5:], weekdays[date.fromisoformat(group['date']).isoweekday() - 1],
            bills_money(group['amount']), items)

    log = '''
                <section class="bill-log">
                    <div class="bill-block-head">
                        <span class="card-header">{{icon:receipt}}最近流水</span>
                        <span class="bill-block-range">共 %d 笔</span>
                    </div>
                    %s
                    <p class="bill-more">更早的流水走接口取：GET /api/bills/records?from=&amp;to=&amp;limit=1000</p>
                </section>''' % (totals['count'], days)

    return (BILLS_BODY.replace('__TILES__', tiles).replace('__CHART__', chart)
            .replace('__SLICE__', slice_html).replace('__LOG__', log))


# ---------------------------------------------------------------- 项目展示页
#
# 这一页的文案全在 showcase.php 顶部那六张 array() 里。夹具不重抄一遍：
# 中文说明重抄一次就是两份，模板改了夹具不会跟着改，而 classcheck 只看得见 class。
# 所以这里把那几个字面量解开直接用。语法只认模板里实际写的那几种
# （array / _t / 单引号串 / 点号拼接 / 整数），碰到别的字符就当场报错，
# 宁可解析失败，也不要悄悄渲出一份「和模板不一样但看着对」的页面。

PHP_TOK_END = ('end', None)


def php_tokenize(src):
    """拆到第一个解析不了的字符为止——数组字面量的右括号一定先于它出现。"""
    toks, i, n = [], 0, len(src)
    unescape = {'n': '\n', 't': '\t', 'r': '\r'}
    while i < n:
        c = src[i]
        if c in ' \t\r\n':
            i += 1
            continue
        if src.startswith('//', i):
            j = src.find('\n', i)
            i = n if j < 0 else j
            continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            i = n if j < 0 else j + 2
            continue
        if c == "'":
            j, buf = i + 1, []
            while j < n and src[j] != "'":
                if src[j] == '\\':
                    nxt = src[j + 1:j + 2]
                    buf.append(unescape.get(nxt, nxt))
                    j += 2
                    continue
                buf.append(src[j])
                j += 1
            toks.append(('str', ''.join(buf)))
            i = j + 1
            continue
        if c.isdigit():
            j = i
            while j < n and src[j].isdigit():
                j += 1
            toks.append(('num', int(src[i:j])))
            i = j
            continue
        word = re.match(r'[A-Za-z_]\w*', src[i:])
        if word:
            toks.append(('word', word.group(0)))
            i += word.end()
            continue
        if c in '()[],.':
            toks.append(('punct', c))
            i += 1
            continue
        toks.append(PHP_TOK_END)
        return toks
    toks.append(PHP_TOK_END)
    return toks


def php_value(toks, pos):
    """解一个值：字符串（拼接后）或 list。返回 (值, 新位置)。"""
    parts, first = [], True
    while True:
        kind, val = toks[pos]
        if kind in ('str', 'num'):
            parts.append(str(val))
            pos += 1
        elif kind == 'word' and val == 'array':
            assert toks[pos + 1] == ('punct', '('), 'array 后面不是左括号'
            pos += 2
            items = []
            while toks[pos] != ('punct', ')'):
                if toks[pos] == PHP_TOK_END:
                    raise SystemExit('array 没闭合，夹具解析器该扩了')
                item, pos = php_value(toks, pos)
                items.append(item)
                if toks[pos] == ('punct', ','):
                    pos += 1
            pos += 1
            parts.append(items)
        elif kind == 'word' and val == '_t':
            assert toks[pos + 1] == ('punct', '('), '_t 后面不是左括号'
            inner, pos = php_value(toks, pos + 2)
            assert toks[pos] == ('punct', ')'), '_t 没闭合'
            parts.append(inner)
            pos += 1
        else:
            raise SystemExit('夹具解析器不认识的结构: %r %r' % (kind, val))
        if first and isinstance(parts[-1], list):
            return parts[-1], pos
        first = False
        if pos < len(toks) and toks[pos] == ('punct', '.'):
            pos += 1
            continue
        return ''.join(parts), pos


def php_table(name):
    """取出 showcase.php 里 $name = array(…) 的内容。"""
    src = (ROOT / 'showcase.php').read_text(encoding='utf-8')
    head = src.index('$%s = array(' % name)
    start = src.index('array(', head)
    value, _ = php_value(php_tokenize(src[start:]), 0)
    assert isinstance(value, list) and value, '%s 解出来是空的' % name
    return value


def showcase_page():
    endpoints = php_table('endpoints')
    features = php_table('features')
    pillars = php_table('pillars')
    templates = php_table('templates')
    steps = php_table('steps')
    trades = php_table('trades')
    swatches = php_table('swatches')

    # 站内计数和强调色在运行时来自数据库与后台配置，夹具里给一组示例数；
    # 这一页要验的是排版，不是数对不对。
    tiles = '''
                <div class="show-facts">

                    <div class="show-tile">
                        <span class="show-tile-label">{{icon:tag}} 主题版本</span>
                        <span class="show-tile-value">__VERSION__</span>
                        <span class="show-tile-note">就是正在渲染这一页的那份</span>
                    </div>

                    <div class="show-tile">
                        <span class="show-tile-label">{{icon:box-archive}} 接口插件</span>
                        <span class="show-tile-value">已启用</span>
                        <span class="show-tile-note">和主题同一个包、同一次交换</span>
                    </div>

                    <div class="show-tile">
                        <span class="show-tile-label">{{icon:code}} 接口端点</span>
                        <span class="show-tile-value">%d<i>条</i></span>
                        <span class="show-tile-note">鉴权走请求头 X-Noline-Key</span>
                    </div>

                    <div class="show-tile">
                        <span class="show-tile-label">{{icon:calendar}} 本站在跑</span>
                        <span class="show-tile-value">128<i>篇</i></span>
                        <span class="show-tile-note">342 条评论 · 开站第 512 天</span>
                    </div>

                </div>''' % len(endpoints)

    cards = ''.join(
        '\n                    <div class="show-card">'
        '\n                        <span class="show-card-title">{{icon:%s}} %s</span>'
        '\n                        <p class="show-card-text">%s</p>'
        '\n                    </div>' % (item[0], item[1], item[2])
        for item in features)

    palette = ''.join(
        '\n                        <span class="show-swatch">'
        '\n                            <i class="show-swatch-fill" style="background-color: var(%s)"></i>'
        '\n                            <code class="show-swatch-name">%s</code>'
        '\n                            <span class="show-swatch-role">%s</span>'
        '\n                        </span>' % (item[0], item[0], item[1])
        for item in swatches)

    pillar_html = ''.join(
        '\n                    <div class="show-pillar">'
        '\n                        <span class="show-card-title">{{icon:%s}} %s</span>'
        '\n                        <p class="show-pillar-text">%s</p>'
        '\n                    </div>' % (item[0], item[1], item[2])
        for item in pillars)

    api = ''.join(
        '\n                        <div class="show-api-row">'
        '\n                            <span class="show-api-method" data-m="%s">%s</span>'
        '\n                            <code class="show-api-path">%s</code>'
        '\n                            <span class="show-api-desc">%s</span>'
        '\n                        </div>' % (row[0], row[0].upper(), row[1], row[2])
        for row in endpoints)

    tpl = ''.join(
        '\n                    <div class="show-tpl-row">'
        '\n                        <code class="show-tpl-file">%s</code>'
        '\n                        <span class="show-tpl-desc">%s</span>'
        '\n                    </div>' % (row[0], row[1])
        for row in templates)

    steps_html = ''.join(
        '\n                    <li class="show-step">'
        '\n                        <span class="show-step-no">%d</span>'
        '\n                        <div class="show-step-body">'
        '\n                            <span class="show-step-title">%s</span>'
        '\n                            <p class="show-step-text">%s</p>'
        '\n                        </div>'
        '\n                    </li>' % (i + 1, step[0], step[1])
        for i, step in enumerate(steps))

    notes = ''.join(
        '\n                    <li class="show-note">%s</li>' % trade for trade in trades)

    return (SHOWCASE_BODY
            .replace('__FACTS__', tiles)
            .replace('__CARDS__', cards)
            .replace('__PALETTE__', palette)
            .replace('__PILLARS__', pillar_html)
            .replace('__API__', api)
            .replace('__TPL__', tpl)
            .replace('__STEPS__', steps_html)
            .replace('__NOTES__', notes))


# 夹具固定走「插件已装 + 已登录」那一支：试一下自检 和 末尾那段给你自己看的，
# 在线上分别要 noline_health_plugin() 和 $this->user->hasLogin() 为真才出现。
# 这里把两个分支都渲出来一次，是为了让截图能看到它们长什么样；
# 另一支（没装插件时的 empty-state + 「没启用」）由 showcase.php 自己保证。
SHOWCASE_BODY = """
<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title">项目展示</h1>
                <p class="page-subtitle">{{icon:github}} 一个 Typecho 主题和一个可选的 REST 接口插件；这一页由它自己画</p>

                <div class="post-content">
                    <p>下面这些不是宣传语：版本、端点数、那五格颜色，都是这一页当次请求现取的。
                        切到深色模式再看一次，整页会跟着翻——包括那一排色块。</p>
                </div>

__FACTS__

                <div class="show-actions">
                    <a class="btn-primary" href="#">{{icon:box-archive}} 下载 noline.zip</a>
                    <a class="show-action" href="#">{{icon:github}} 仓库</a>
                    <a class="show-action" href="#">{{icon:history}} 更新记录</a>
                    <a class="show-action" href="#">{{icon:file-lines}} 接口文档</a>
                    <a class="show-action" href="#">{{icon:paper-plane}} 试一下自检</a>
                </div>

                <p class="show-req">
                    环境：Typecho 1.2.x · PHP 7.4 及以上 · 主题当前版本 __VERSION__。
                    前端没有 CDN：图标是内联 SVG，highlight.js 走本地文件且只在有代码块的那几页加载。
                </p>

                <h2 class="show-heading">主题画了什么</h2>

                <div class="show-grid">__CARDS__
                </div>

                <h2 class="show-heading">颜色只有两支</h2>

                <div class="show-block">
                    <div class="show-block-head">
                        <span class="card-header">{{icon:compass}}这一排是实时取的</span>
                        <span class="show-block-note">#425aef</span>
                    </div>

                    <div class="show-palette">__PALETTE__
                    </div>

                    <p class="show-palette-note">
                        后台只填了 #425aef 这一支；深色模式按亮度自动推亮，剩下的层次全是这两支掺透明度——
                        上面这五格现在显示的就是这一档。
                        切到深色模式再看一次，这五格会跟着翻，因为每一格挂的都是变量而不是色值。
                    </p>
                </div>

                <h2 class="show-heading">插件管了什么</h2>

                <div class="show-pillars">__PILLARS__
                </div>

                <div class="show-block">
                    <div class="show-block-head">
                        <span class="card-header">{{icon:link}}接口清单</span>
                        <span class="show-block-note">本机正在提供这些端点</span>
                    </div>

                    <div class="show-api">__API__
                    </div>

                    <p class="show-palette-note">
                        三种写法都认：/api/health（伪静态开着）、/index.php/api/health（伪静态关了）、
                        ?noline_api=health（服务器把 /api 抢走了时的兜底）。
                        后台顶栏的「主题管理」页是另一个入口，它看的是登录态，不吃接口密钥。
                    </p>
                </div>

                <h2 class="show-heading">页面模板</h2>

                <div class="show-tpl">__TPL__
                </div>

                <h2 class="show-heading">怎么装进去</h2>

                <ol class="show-steps">__STEPS__
                </ol>

                <h2 class="show-heading">已知取舍</h2>

                <ul class="show-notes">__NOTES__
                </ul>

                <p class="show-foot">
                    给你自己看的：版本号一共四处——主题的 NOLINE_VERSION 与 index.php 头部注释的 @version，
                    插件的 const VERSION 与文件头 @version。前两处必须相等、后两处必须相等，
                    _dev/build.py 会核对；推一个等于版本号的 tag，Actions 就会打出 noline.zip 并挂到 Release 上。
                </p>
            </article>
        </div>

__SIDEBAR__
    </div>
</div>
"""


def render(name, title, body, extra_head='', is_home=False):
    html = HEAD.replace('__TITLE__', title).replace('__EXTRA_HEAD__', extra_head)
    html += body + FOOT
    html = (html
            .replace('__HOME__', ' active' if is_home else '')
            .replace('__CURR__', ' aria-current="page"' if is_home else '')
            .replace('__VERSION__', VERSION)
            .replace('__COVER__', COVER)
            .replace('__AVATAR__', AVATAR)
            .replace('__FACE_A__', fake_face('a'))
            .replace('__FACE_B__', fake_face('b'))
            .replace('__FACE_C__', fake_face('c'))
            .replace('__FACE_D__', fake_face('d'))
            .replace('__FIGURE__', FIGURE))
    html = sub_icons(html)

    leftovers = sorted(set(re.findall(r'__[A-Z][A-Z0-9_]*__', html)))
    if leftovers:
        raise SystemExit('%s 里残留未替换的占位符: %s' % (name, ', '.join(leftovers)))

    (OUT / name).write_text(html, encoding='utf-8')
    print('  %-14s %6.1f KB' % (name, len(html.encode('utf-8')) / 1024))


def main():
    OUT.mkdir(exist_ok=True)
    print('图标: 从 icons.php 取到 %d 个' % len(ICONS))
    print('写出夹具:')

    # 短的这条不该触发折叠：main.js 量完会把 .is-clamped 摘掉，「全文」按钮保持 hidden
    # 评论数 0 → 预览条整块不出现，验的是 noline_comment_preview() 的空分支
    short_status = status_card(
        '<p>下午把 <code>views/</code> 的一次性迁移跑通了，192 个历史阅读量一个没丢。</p>',
        '3小时前', '2026-10-06 14:23', 41, 0)

    # 长的这条必须超过 CSS 里的钳位高度，才会出现「全文」按钮。
    # 顺便把 .status-content 那套紧凑排版全过一遍：多段落、列表、引用、行内代码、图片。
    long_status = status_card(
        '<p>今天想清楚一件事：动态卡不能直接套 <code>.post-content</code>。那套排版是给整页文章写的，'
        '17px 字号、1.9 行高、二级标题还带下划线，塞进一张信息流卡片里整块都在往外顶。</p>\n'
        '<p>所以另起了一套紧凑的：</p>\n'
        '<ul>\n'
        '<li>正文 15px，行高 1.85</li>\n'
        '<li>标题全部压平成 15px 粗体 —— Markdown 里一个手滑的 <code>#</code> 就能把卡片撑爆</li>\n'
        '<li>代码块不带 macOS 红绿灯，那套 <code>padding-top</code> 和背景图是给 <code>.post-content pre</code> 的</li>\n'
        '</ul>\n'
        '<blockquote><p>折叠阈值只写在 CSS 的 <code>max-height</code> 里。JS 先量自然高度、挂上钳位再量一次，'
        '两次一比就知道该不该出「全文」按钮，以后调字号不用回来同步改脚本。</p></blockquote>\n'
        '<p>再放一张图，顺便验证图片在动态卡里的圆角和最大宽度对不对：</p>\n'
        '<p><img src="__FIGURE__" alt="一张示意图" width="800" height="300"></p>',
        '昨天 21:40', '2026-10-05 21:40', 128, 3,
        preview=[
            # 超长作者名 + 超长正文：验 .card-comments-author / -text 的单行省略号
            ('一个名字特别特别特别特别长的路过网友', None,
             '这段评论故意写得非常长，长到一行绝对放不下，用来确认省略号是不是真的生效了而不是把整行撑破', 'a'),
            ('QINE', '一个名字特别特别特别特别长的路过网友', '确实，钳位高度改成 rem 之后要记得回来同步这一行', 'b'),
            ('路人丙', None, '学到了', 'c'),
        ])

    cards = (short_status
             + post_card(
                 '把散落的硬编码颜色收敛成一套设计令牌',
                 '主题原来把加粗、链接、行内代码的颜色散落在各处，换一次肤要改十几个文件。'
                 '现在所有颜色都从 :root 的令牌取值，浅色和深色各一套，强调色还能在后台配……',
                 192, 2,
                 cover=True, reading=3,
                 preview=[
                     ('QINE', '路人甲', '作用域限定在 .post-content pre，评论里那块不受影响', 'b'),
                     ('路人甲', None, '评论里的代码块得是浅色的，别被正文那套红绿灯污染了', 'a'),
                 ])
             + long_status
             + post_card(
                 '为什么 backdrop-filter 会吃掉 position: fixed',
                 '给导航栏加个毛玻璃效果，结果固定在视口上的遮罩跟着一起跑了。'
                 '原因是 backdrop-filter 和 transform 一样，会为后代元素创建新的包含块……',
                 87, 0, reading=2))

    def list_body(shell, cards_html, page, total, active):
        return (shell
                .replace('__CATS__', feed_cats(active))
                .replace('__CARDS__', cards_html.rstrip('\n'))
                .replace('__NAV__', page_nav(page, total))
                .replace('__FEED__', FEED_STATUS)
                .replace('__SIDEBAR__', SIDEBAR.rstrip('\n'))
                .replace('__TOC__', ''))

    index = list_body(INDEX_BODY, cards, 1, 3, 'index')
    render('index.html', '首页', index, is_home=True)

    # 第 2 页的卡片接着第 1 页的序号继续递增，这正是模板里
    # 「(当前页 - 1) × 每页条数 + 全局序号」想达到的效果：自动加载把两页拼进
    # 同一个列表时，接缝处不会出现两张同侧。
    # 第 2 页必须同时有 .prev 和 .next（和第 1 页形状不同，正好验 page_nav 的两个守卫）。
    # 那条长动态卡专验追加进来之后 initStatusFold 有没有重跑——「全文」按钮出不来
    # 就是 scope 没生效；短动态卡则用来确认重跑不会把它误钳住。
    page2 = list_body(LIST_PAGE_BODY, (
        post_card(
            '无限滚动那 600px 的 rootMargin 到底该怎么给',
            '太小就要等用户真的滑到底才开始加载，体验成「卡一下再冒内容」；太大又会在他还'
            '没看到的位置就提前抓好几页。现在的做法是观察状态区本身、提前 600px 预取……',
            143, 4, reading=4)
        + status_card(
            '<p>把页码换成自动加载，有几件事得先想清楚，不是「挂个 IntersectionObserver」那么轻：</p>\n'
            '<ul>\n'
            '<li>页码不能从 PHP 里删掉。无 JS 的用户、fetch 挂掉的时候，那一排药丸是唯一的退路</li>\n'
            '<li>地址栏不跟着改。同步成 <code>?page=N</code> 之后刷新只会看到第 N 页那几条，比回到顶部更糟</li>\n'
            '<li>评论分页和归档分页是同一个类名，判据顺序写错就把文章页静默搞坏了</li>\n'
            '<li>状态区是列表的最后一个孩子，追加必须 <code>insertBefore</code> 而不是 <code>appendChild</code></li>\n'
            '</ul>\n'
            '<p>最后一条最容易漏：append 进去的新卡片会排到状态区下面，那句「正在加载…」'
            '就被夹在列表中间，再滑下去又是另一屏。</p>',
            '2天前', '2026-10-04 10:15', 77, 1)
        + short_status
    ), 2, 3, 'dev')
    render('page2.html', '第 2 页', page2)

    # 第 3 页：page_nav 不输出 .next，验「没有更多了」那条终态
    page3 = list_body(LIST_PAGE_BODY, (
        post_card(
            'DOMParser 之后的相对 href 为什么只能用 getAttribute 读',
            '解析出来的文档 URL 是 about:blank，任何相对地址经过 a.href 都会被解析成完全无关的东西。'
            '得取原始字符串，再用当前页面的 document.baseURI 显式解析……',
            58, 0, reading=1)
        + status_card(
            '<p>最后一页了。到底要做三件事：断开观察器、收掉小圆圈、文案换成「没有更多了」。'
            '断开是必须的，不然后来每次滚到底都会重新抓一次那个空页。</p>',
            '5天前', '2026-10-01 08:30', 33, 0)
    ), 3, 3, 'dev')
    render('page3.html', '第 3 页', page3)

    # 文章页带上 highlight.js，才能验证 pre code.hljs 的 padding 特异性冲突有没有真的解决
    hl = ('    <link rel="stylesheet" href="../css/vendor/atom-one-dark.min.css?v=__VERSION__">\n'
          '    <script defer src="../js/vendor/highlight.min.js?v=__VERSION__"></script>')
    render('post.html', '文章',
           POST_BODY.replace('__SIDEBAR__', SIDEBAR.rstrip('\n')).replace('__TOC__', TOC_CARD.rstrip('\n')), hl)

    # 健康页：SVG 折线是 PHP 拼出来的，夹具里按同一套算法重算一遍，
    # 这样在浏览器里看到的坐标、圆点、涨跌配色和线上一致
    render('health.html', '健康记录',
           health_page().replace('__SIDEBAR__', SIDEBAR.rstrip('\n')).replace('__TOC__', TOC_CARD.rstrip('\n')))

    # 记账页：周/月合计、柱高、占比条全部按插件里那套算法重算，
    # 六月一根空柱是故意留的——那个月一条没记，图上不能凭空少一格
    render('bills.html', '买菜记账',
           bills_page().replace('__SIDEBAR__', SIDEBAR.rstrip('\n')).replace('__TOC__', TOC_CARD.rstrip('\n')))

    # 项目展示页：六张表的内容直接从 showcase.php 的 PHP 字面量里解出来，
    # 页面上任何一句文案改了，夹具会跟着改，不会各写一份。
    # 这一页没有目录卡（正文不是长文章，右栏不需要跟着跳锚点），__TOC__ 置空。
    render('showcase.html', '项目展示',
           showcase_page().replace('__SIDEBAR__', SIDEBAR.rstrip('\n')).replace('__TOC__', ''))

    print('\n提醒：夹具在 noline/_preview/ 下，验证完必须删掉，不能随主题发布。')


if __name__ == '__main__':
    main()
