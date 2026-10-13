/**
 * NoLine 主题的前端行为。
 *
 * 以 defer 加载，执行时机在文档解析完成之后、DOMContentLoaded 之前，
 * 所以这里注册的 DOMContentLoaded 监听一定会被触发（readyState 仍是 'loading'）。
 *
 * 全站没有 jQuery，也没有任何外部依赖。
 */
(function () {
    'use strict';

    var THEME_KEY = 'noline-theme';
    var NAV_KEY = 'noline-nav';

    /* 复制按钮的文案。这里硬编码中文而不是走 Typecho 的 _e()：
       main.js 是静态资源，PHP 的翻译函数够不着，为一句话再开一个内联 JSON 不值得。 */
    var LABEL_COPY = '复制';
    var LABEL_COPIED = '已复制';
    var LABEL_FAILED = '复制失败';

    /* 同上，动态卡折叠按钮 */
    var LABEL_FOLD_MORE = '全文';
    var LABEL_FOLD_LESS = '收起';

    /* 同上，列表自动加载的状态区 */
    var LABEL_FEED_LOADING = '正在加载…';
    var LABEL_FEED_FAIL = '加载失败';
    var LABEL_FEED_END = '没有更多了';

    var root = document.documentElement;

    var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var systemQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    /* prefers-reduced-motion 是可以在会话中途改的，所以每次都实时读，不缓存成常量。 */
    function reduceMotion() {
        return !!(motionQuery && motionQuery.matches);
    }

    function readStore(store, key) {
        try {
            return store.getItem(key);
        } catch (e) {
            /* Safari 隐私模式、Cookie 被禁用时 localStorage/sessionStorage 会直接抛 */
            return null;
        }
    }

    function writeStore(store, key, value) {
        try {
            store.setItem(key, value);
        } catch (e) {}
    }

    function dropStore(store, key) {
        try {
            store.removeItem(key);
        } catch (e) {}
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    /* ==========================================================================
       顶部进度条
       用 transform: scaleX() 而不是 width，走合成层不触发重排。
       ========================================================================== */

    var progress = document.getElementById('noline-progress');

    function progressTo(scale) {
        if (progress) {
            progress.style.transform = 'scaleX(' + scale + ')';
        }
    }

    function progressShow(scale) {
        if (!progress || reduceMotion()) {
            return;
        }
        progress.classList.add('is-active');
        progressTo(scale);
    }

    var hideTimer = null;
    var resetTimer = null;

    function progressDone() {
        if (!progress || reduceMotion()) {
            return;
        }
        clearTimeout(hideTimer);
        clearTimeout(resetTimer);
        progressTo(1);
        /* 先等 scaleX 到 1（.2s），再淡出（.3s），淡完把刻度归零，
           否则下一次导航会从满格闪回 0。 */
        hideTimer = setTimeout(function () {
            progress.classList.remove('is-active');
            resetTimer = setTimeout(function () {
                progressTo(0);
            }, 320);
        }, 240);
    }

    /* ==========================================================================
       页面转场：离开淡出 + 进场揭示
       ========================================================================== */

    var leaving = false;
    var revealed = false;

    function reveal() {
        if (revealed) {
            return;
        }
        revealed = true;
        root.classList.remove('is-loading');
        progressDone();
    }

    function startLeave(href) {
        /* 快速连点会叠加多个定时器，一次性守卫 */
        if (leaving) {
            return;
        }
        leaving = true;

        root.classList.add('is-leaving');
        progressShow(0);
        /* 连等两帧再推进。scaleX(0) 和 scaleX(.7) 若落在同一次样式计算里，
           浏览器看不到起始值，过渡会被直接跳过。 */
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                progressTo(0.7);
            });
        });

        /* 让新页面接着把进度条推完，而不是重新从 0 开始 */
        writeStore(window.sessionStorage, NAV_KEY, '1');

        /* 和 design.css 里 .18s 的淡出对齐 */
        setTimeout(function () {
            location.assign(href);
        }, 200);
    }

    function initTransition() {
        /* 上一次导航留下的进度条状态。放在这里读，新页面一进来就接着显示。 */
        if (readStore(window.sessionStorage, NAV_KEY) === '1') {
            dropStore(window.sessionStorage, NAV_KEY);
            progressShow(0.85);
        }

        /* bfcache 恢复：从下一页点「后退」回来时脚本不会重跑，
           而 is-leaving 还挂在 html 上，内容会是透明的。必须显式撤销。 */
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                leaving = false;
                root.classList.remove('is-leaving', 'is-loading');
                progressDone();
            }
        });

        /* 揭示时机不能用 window.load——图片多的文章页会白屏好几秒，
           正是这套转场要避免的。DOMContentLoaded 之后连等两帧，
           确保样式已算完、首屏已合成。兜底定时器在 header.php 的内联脚本里，
           那样即使 main.js 404 或被扩展拦下，内容也不会永久藏起来。 */
        ready(function () {
            requestAnimationFrame(function () {
                requestAnimationFrame(reveal);
            });
        });

        if (reduceMotion()) {
            /* CSS 媒体查询压不住这里的 setTimeout 和淡出逻辑，只能显式判断：
               命中时完全不接管点击，走浏览器原生跳转。 */
            return;
        }

        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0) {
                return;
            }
            /* 新标签页 / 强制下载 / 右键菜单里的「在新标签页打开」都不该被接管 */
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            var a = event.target.closest ? event.target.closest('a[href]') : null;
            if (!a) {
                return;
            }
            if (a.target && a.target.toLowerCase() === '_blank') {
                return;
            }
            if (a.hasAttribute('download')) {
                return;
            }
            /* #comment-respond 装的是评论表单本体，Typecho 的回复 JS 会把它整块搬到
               被回复的楼层下面。表单里也有链接（退出登录、取消回复），不该被接管。
               至于评论列表里的「回复」链接，上面那条 defaultPrevented 已经兜住了——
               它是 onclick="return TypechoComment.reply(…)"，会 preventDefault；
               而且 href 是 javascript:void(0)，下面的 origin 判断也会放行。 */
            if (a.closest('#comment-respond')) {
                return;
            }
            /* javascript: / mailto: / tel: 的 origin 是 'null'，一并被这条挡掉 */
            if (a.origin !== location.origin) {
                return;
            }
            /* 同一路径、同一串查询参数，只有 hash 不同（或者压根没有 hash）的链接
               一律放行：这类点击要的是浏览器原地的锚点定位或自我刷新，接管成
               「淡出再淡入」只会把 hash 定位吃掉。目录那些 href="#toc-1" 就走这条。 */
            if (a.pathname === location.pathname && a.search === location.search) {
                return;
            }

            event.preventDefault();
            closeDrawer();
            startLeave(a.href);
        });
    }

    /* ==========================================================================
       代码高亮 + 复制按钮
       ========================================================================== */

    function initHighlight() {
        if (!window.hljs) {
            /* header.php 只在正文真的含代码块时才发 hljs，其余页面走到这里是正常的 */
            return;
        }
        var blocks = document.querySelectorAll('.post-content pre code');
        Array.prototype.forEach.call(blocks, function (block) {
            window.hljs.highlightElement(block);
        });
    }

    function legacyCopy(text) {
        /* HTTP 站点没有安全上下文，navigator.clipboard 会被直接拒绝，只能回落到这条路 */
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        area.setSelectionRange(0, area.value.length);

        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(area);

        return ok;
    }

    function flashLabel(button, text) {
        button.textContent = text;
        button.classList.add('is-done');
        setTimeout(function () {
            button.textContent = LABEL_COPY;
            button.classList.remove('is-done');
        }, 2000);
    }

    function copyFrom(button, pre) {
        var text = pre.innerText;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                flashLabel(button, LABEL_COPIED);
            }, function () {
                flashLabel(button, legacyCopy(text) ? LABEL_COPIED : LABEL_FAILED);
            });
            return;
        }

        flashLabel(button, legacyCopy(text) ? LABEL_COPIED : LABEL_FAILED);
    }

    function initCopyButtons() {
        var pres = document.querySelectorAll('.post-content pre');

        Array.prototype.forEach.call(pres, function (pre) {
            if (!pre.parentNode) {
                return;
            }

            var wrap = document.createElement('div');
            wrap.className = 'code-block';
            pre.parentNode.insertBefore(wrap, pre);
            wrap.appendChild(pre);

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn-copy-code';
            button.textContent = LABEL_COPY;
            button.setAttribute('aria-label', LABEL_COPY);
            wrap.appendChild(button);

            button.addEventListener('click', function () {
                copyFrom(button, pre);
            });
        });
    }

    /* WebKit 会把「没有标记的列表」从无障碍树里降成一组普通方块——
       只要 list-style-type 是 none 就摘掉 list 角色。article.css 正是这么画列表的
       （自绘的圆点和 counter 序号读屏软件看不见），所以这里把角色显式加回来。
       只碰 .post-content：样式表里也只有这一层关掉了 list-style。 */
    function initListSemantics() {
        var lists = document.querySelectorAll('.post-content ul, .post-content ol');

        Array.prototype.forEach.call(lists, function (list) {
            if (!list.hasAttribute('role')) {
                list.setAttribute('role', 'list');
            }
        });
    }

    /* ==========================================================================
       动态卡：长内容折叠
       模板里的 .status-toggle 带 hidden，只有这里判定「确实超了」才放开。
       脚本没跑起来时正文就是全量铺开，不会把内容藏掉。
       ========================================================================== */

    /* 动态卡折叠。scope 传具体某张新追加的卡，不传就是整页——
       下面那个 click 监听是无条件绑的，拿整把列表重跑会把老卡片绑第二遍，
       点一次「全文」toggle 两次，等于没反应。 */
    function initStatusFold(scope) {
        var folds = (scope || document).querySelectorAll('.status-fold');

        Array.prototype.forEach.call(folds, function (fold) {
            var content = fold.querySelector('.status-content');
            var toggle = fold.querySelector('.status-toggle');

            if (!content || !toggle) {
                return;
            }

            function measure() {
                /* 先量自然高度，再挂钳位量被裁后的高度，顺序不能反。
                   这么绕一圈是为了让「多长算长」这个阈值只写在 CSS 的 max-height 里：
                   JS 不硬编码行数，以后调字号或行高也不用回来同步改脚本。 */
                var natural = content.scrollHeight;

                fold.classList.add('is-clamped');

                if (natural <= content.clientHeight + 1) {
                    /* 没超一屏，摘掉钳位，按钮继续 hidden */
                    fold.classList.remove('is-clamped');
                    toggle.hidden = true;
                } else {
                    toggle.hidden = false;
                }
            }

            measure();

            /* 无条件绑：按钮 hidden 的时候根本点不到，不值得为省一个监听器再判一次 */
            toggle.addEventListener('click', function () {
                var open = fold.classList.toggle('is-open');
                toggle.textContent = open ? LABEL_FOLD_LESS : LABEL_FOLD_MORE;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });

            /* Markdown 渲染出的 <img> 不带 width/height，DOMContentLoaded 那一刻高度还是 0，
               一条纯图动态会被误判成「短内容」，从此永远不出「全文」按钮。等图片落地再量一次。 */
            Array.prototype.forEach.call(content.querySelectorAll('img'), function (img) {
                if (img.complete) {
                    return;
                }

                var settle = function () {
                    img.removeEventListener('load', settle);
                    img.removeEventListener('error', settle);
                    /* 用户已经手动展开过的别再动，否则内容会在他眼前被重新钳回去 */
                    if (!fold.classList.contains('is-open')) {
                        measure();
                    }
                };

                img.addEventListener('load', settle);
                img.addEventListener('error', settle);
            });
        });
    }

    /* ==========================================================================
       深色模式
       header.php 的内联脚本已经在首帧之前定好了 data-theme，这里只负责交互。
       ========================================================================== */

    function storedTheme() {
        var value = readStore(window.localStorage, THEME_KEY);
        return (value === 'dark' || value === 'light') ? value : null;
    }

    function initThemeToggle() {
        var buttons = document.querySelectorAll('.js-theme-toggle');

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                writeStore(window.localStorage, THEME_KEY, next);
            });
        });

        if (!systemQuery) {
            return;
        }

        /* 用户在系统层面切换主题时，已经打开的标签页也要跟上——
           但只在他没有手动选过时。手动点过按钮就意味着「我就要这个」，不该再被系统覆盖。 */
        var followSystem = function () {
            if (storedTheme() !== null) {
                return;
            }
            root.setAttribute('data-theme', systemQuery.matches ? 'dark' : 'light');
        };

        if (systemQuery.addEventListener) {
            systemQuery.addEventListener('change', followSystem);
        } else if (systemQuery.addListener) {
            systemQuery.addListener(followSystem);   /* Safari < 14 */
        }
    }

    /* ==========================================================================
       顶栏：窄屏下拉面板 / 分类分组 / 滚动百分比
       ========================================================================== */

    var drawerPanel = null;
    var drawerToggle = null;

    function closeDrawer() {
        if (!drawerPanel || !drawerPanel.classList.contains('is-open')) {
            return;
        }
        drawerPanel.classList.remove('is-open');
        var scrim = document.getElementById('nav-scrim');
        if (scrim) {
            scrim.classList.remove('is-open');
        }
        if (drawerToggle) {
            drawerToggle.setAttribute('aria-expanded', 'false');
        }
    }

    function initDrawer() {
        drawerToggle = document.getElementById('nav-toggle');
        drawerPanel = document.getElementById('topnav');
        var scrim = document.getElementById('nav-scrim');

        if (!drawerToggle || !drawerPanel) {
            return;
        }

        function setOpen(open) {
            drawerPanel.classList.toggle('is-open', open);
            if (scrim) {
                scrim.classList.toggle('is-open', open);
            }
            drawerToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        drawerToggle.addEventListener('click', function () {
            setOpen(!drawerPanel.classList.contains('is-open'));
        });

        if (scrim) {
            scrim.addEventListener('click', function () {
                setOpen(false);
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && drawerPanel.classList.contains('is-open')) {
                setOpen(false);
                drawerToggle.focus();
            }
        });

        /* 开着面板把窗口拉过断点时必须收掉：宽屏下 .topnav 已经回到文档流，
           is-open 看不出任何效果，遮罩却仍是 fixed 的一整块，整页都点不动。 */
        if (window.matchMedia) {
            var wide = window.matchMedia('(min-width: 1024px)');
            var onBreak = function () {
                if (wide.matches) {
                    setOpen(false);
                }
            };
            if (wide.addEventListener) {
                wide.addEventListener('change', onBreak);
            } else if (wide.addListener) {
                wide.addListener(onBreak);   /* Safari < 14 */
            }
        }
    }

    /* 滚动百分比。整数写进 #nav-percent，百分号是 CSS 的 ::after，JS 不拼字符串。
       passive 是因为这里绝不会 preventDefault，浏览器就能不等 JS 直接滚；
       rAF 节流是免得一次滚动里重排好几遍。 */
    function initScrollPercent() {
        var out = document.getElementById('nav-percent');

        if (!out) {
            return;
        }

        var queued = false;

        function render() {
            queued = false;

            var doc = document.documentElement;
            var range = doc.scrollHeight - window.innerHeight;

            /* 页面短到不能滚时 range ≤ 0，除下去是 Infinity/NaN。
               这种页面根本没有「读了多少」可言，整块读数直接藏掉。 */
            if (range <= 0) {
                out.hidden = true;
                return;
            }

            var ratio = (window.pageYOffset || doc.scrollTop || 0) / range;
            ratio = ratio < 0 ? 0 : (ratio > 1 ? 1 : ratio);

            out.hidden = false;
            out.textContent = String(Math.round(ratio * 100));
        }

        function schedule() {
            if (queued) {
                return;
            }
            queued = true;
            window.requestAnimationFrame(render);
        }

        window.addEventListener('scroll', schedule, { passive: true });
        /* 转屏、缩放、图片补完高度都会改文档高度，不重算就会停在错的读数上 */
        window.addEventListener('resize', schedule);
        render();
    }

    /* ==========================================================================
       顶栏收成玻璃胶囊
       阈值取 hero 的下沿：滚过它顶栏才该变胶囊，不然白字会一路压到正文上。
       没有 hero 的页面（归档、单页、404）压根没有这一屏，一进页面就是胶囊态——
       固定顶栏是透明的，不挂胶囊等于没有顶栏。
       ========================================================================== */
    function initNavCollapse() {
        var body = document.body;

        if (!body.classList.contains('has-hero')) {
            body.classList.add('nav-fixed');
            return;
        }

        var hero = document.querySelector('.site-hero, .post-hero');
        var queued = false;

        function render() {
            queued = false;

            var bottom = hero ? hero.getBoundingClientRect().bottom : 0;

            /* 用胶囊高度（44）而不是顶栏高度做阈值：胶囊在 hero 彻底离开之前就该成形，
               等它完全滚出再换，白字会有一瞬压在页底色上。 */
            body.classList.toggle('nav-fixed', bottom < 44);
        }

        function schedule() {
            if (queued) {
                return;
            }
            queued = true;
            window.requestAnimationFrame(render);
        }

        window.addEventListener('scroll', schedule, { passive: true });
        /* hero 高度是 min-height，标题换行、字体加载完、转屏都会改它，不重算阈值就错 */
        window.addEventListener('resize', schedule);
        render();
    }

    /* ==========================================================================
       文章首屏那张封面小卡跟手
       --tilt-x/--tilt-y/--glow-x/--glow-y 全写在元素上，CSS 负责把它们拼进
       transform 和两圈白光（见 design.css 的「首屏」一节）。
       一次移动只写一遍样式：读数攒在 pointer 里，写由 rAF 合并。
       ========================================================================== */
    function initHeroTilt() {
        var card = document.querySelector('.post-hero-card');

        if (!card || !window.matchMedia || reduceMotion()) {
            return;
        }
        /* 触屏没有指针可跟，窄屏下这张卡本身也已经被 CSS 藏掉 */
        if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            return;
        }

        var MAX_TILT = 7;
        var pointer = null;
        var queued = false;

        function render() {
            queued = false;

            if (!pointer) {
                return;
            }

            /* rect 每帧读一次：卡片在 hero 里，页面滚动会让它相对视口移位，
               缓存 rect 的话光斑和倾斜都会算歪。 */
            var rect = card.getBoundingClientRect();
            var x = (pointer.x - rect.left) / rect.width;
            var y = (pointer.y - rect.top) / rect.height;

            x = x < 0 ? 0 : (x > 1 ? 1 : x);
            y = y < 0 ? 0 : (y > 1 ? 1 : y);

            card.style.setProperty('--tilt-x', ((0.5 - y) * 2 * MAX_TILT).toFixed(2) + 'deg');
            card.style.setProperty('--tilt-y', ((x - 0.5) * 2 * MAX_TILT).toFixed(2) + 'deg');
            card.style.setProperty('--glow-x', (x * 100).toFixed(1) + '%');
            card.style.setProperty('--glow-y', (y * 100).toFixed(1) + '%');
        }

        function schedule() {
            if (queued) {
                return;
            }
            queued = true;
            window.requestAnimationFrame(render);
        }

        function reset() {
            pointer = null;
            card.style.setProperty('--tilt-x', '0deg');
            card.style.setProperty('--tilt-y', '0deg');
            card.style.setProperty('--glow-opacity', '0');
        }

        card.addEventListener('pointermove', function (event) {
            pointer = { x: event.clientX, y: event.clientY };
            card.style.setProperty('--glow-opacity', '1');
            schedule();
        });
        card.addEventListener('pointerleave', reset);
    }

    /* ==========================================================================
       列表自动加载（首页 / 归档页）
       页码由核心渲染，这里接管后设成 hidden，不是从模板里删掉：无 JS、fetch 失败、
       没有 IntersectionObserver 的老浏览器三种情况下页码原样可用，那是退路。

       刻意不 history.replaceState 把地址同步成 ?page=N。无限滚动下当前 URL 早就
       不对应「第 1 页」那一屏了，改写成了第 N 页，刷新只会拿到第 N 页那几条、
       停在时间线中段——比回到顶部更糟。
       ========================================================================== */

    /* 下一页地址只能从渲染好的标记里读，绝不自己拼。核心用
       Router::url($type . '_page') 生成它，开/关伪静态两种格式靠这一条同时兼容。
       用 getAttribute 不用 .href：DOMParser 产出的文档 URL 是 about:blank，
       相对地址经 .href 解析出来是垃圾。 */
    function nextHref(scope) {
        var a = scope.querySelector('.page-navigator .next a');
        var href = a ? a.getAttribute('href') : null;

        if (!href) {
            return null;
        }

        try {
            return new URL(href, document.baseURI).href;
        } catch (e) {
            return null;
        }
    }

    function initFeedPaging() {
        var list = document.querySelector('.main-content');

        if (!list) {
            return;
        }

        /* #feed-status 只有 index.php / archive.php 会输出，它是区分归档分页和评论分页
           的唯一判据，而且必须先于 .page-navigator 判。post / page / links 的评论
           分页渲染出的是同名 <ol class="page-navigator">，那几个模板同样有 .main-content
           ——顺序换过来就会静默劫持文章页：评论页码被藏、抓的是评论翻页地址、
           卡片被追加到正文后面，一句错都不报。 */
        var status = document.getElementById('feed-status');

        if (!status) {
            return;
        }

        /* 总条数不超过一页时核心一个 <ol> 都不输出，没东西可接管，状态区保持 hidden */
        var nav = list.querySelector('.page-navigator');

        if (!nav) {
            return;
        }

        if (!window.IntersectionObserver || !window.fetch || !window.DOMParser) {
            return;
        }

        var nextUrl = nextHref(list);

        if (!nextUrl) {
            /* 已经是最后一页：页码留着给人点 prev/current，状态区不揭开 */
            return;
        }

        var spinner = status.querySelector('.feed-spinner');
        var text = status.querySelector('.feed-text');
        var retry = status.querySelector('.feed-retry');
        var loading = false;
        var skeletons = [];

        nav.hidden = true;
        status.hidden = false;

        function say(message) {
            text.textContent = message;
        }

        function stop() {
            loading = false;
            spinner.hidden = true;
        }

        /* 摘掉再重挂，强制观察器补发一次初始回调。
           它只在相交状态「发生变化」时才回调：新卡片要是没把状态区推出那 600px
           预取带，状态一路都是「相交」，就不会有第二次回调，加载就此卡住。
           重挂之后要么继续加载下一页，要么 entry 的 isIntersecting 是 false、什么都不做。 */
        function arm() {
            observer.unobserve(status);
            observer.observe(status);
        }

        function finish() {
            stop();
            observer.disconnect();
            nextUrl = null;
            say(LABEL_FEED_END);
        }

        function fail() {
            stop();
            clearSkeleton();
            say(LABEL_FEED_FAIL);
            retry.hidden = false;
        }

        function makeSkeleton() {
            var card = document.createElement('article');
            var body = document.createElement('div');
            var head = document.createElement('div');
            var avatar = document.createElement('span');
            var name = document.createElement('span');
            var line1 = document.createElement('div');
            var line2 = document.createElement('div');

            card.className = 'card post-card feed-skeleton';
            card.setAttribute('aria-hidden', 'true');
            /* 骨架也得有 .post-card-body：正文卡的内边距挂在那一层上，
               .post-card 在信息流里是 0（封面要贴到卡片边缘）。少了这一层，
               骨架条会顶到卡片圆角上，加载完成后的抖动一眼可见。 */
            body.className = 'post-card-body';
            head.className = 'sk-head';
            avatar.className = 'sk-avatar';
            name.className = 'sk-line sk-line-sm';
            line1.className = 'sk-line';
            line2.className = 'sk-line sk-line-70';

            head.appendChild(avatar);
            head.appendChild(name);
            body.appendChild(head);
            body.appendChild(line1);
            body.appendChild(line2);
            card.appendChild(body);

            list.insertBefore(card, status);
            return card;
        }

        function showSkeleton() {
            skeletons.push(makeSkeleton());
            skeletons.push(makeSkeleton());
        }

        function clearSkeleton() {
            skeletons.forEach(function (el) {
                if (el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            });
            skeletons = [];
        }

        function load(url) {
            loading = true;
            retry.hidden = true;
            spinner.hidden = false;
            say(LABEL_FEED_LOADING);
            showSkeleton();

            fetch(url, { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('HTTP ' + res.status);
                    }
                    return res.text();
                })
                .then(function (html) {
                    clearSkeleton();
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.querySelector('.main-content');
                    var cards;
                    var following;

                    if (!fresh) {
                        throw new Error('missing .main-content');
                    }

                    /* 只取 <article>：首页的 .main-content 里还挂着公告 div.card，
                       归档页还有 .archive-header，按 .card 选会把它们一起捞进来。
                       文章卡和动态卡的根元素都是 article.card。 */
                    cards = fresh.querySelectorAll(':scope > article');
                    following = nextHref(doc);

                    if (cards.length === 0) {
                        /* 空页算到底，不算错误。少了这条，rootMargin 会让观察器
                           立刻再触发一次，对着空列表无限空转。 */
                        finish();
                        return;
                    }

                    Array.prototype.forEach.call(cards, function (card) {
                        /* 显式 importNode：跨文档直接 append 的 adopts 行为各家不一致，别赌。
                           insertBefore 而不是 appendChild：状态区本来就是列表的最后一个孩子，
                           append 会把它挤到列表中间去。 */
                        var node = list.insertBefore(document.importNode(card, true), status);
                        /* 得先进文档再量，脱离文档时 scrollHeight 是 0 */
                        initStatusFold(node);
                        /* 追加进来的卡也淡入，和首屏的滚动进场同一套语言 */
                        revealCard(node);
                    });

                    if (!following || following === url) {
                        /* following === url 是保险丝：服务端把每一页都渲染成同一页时，
                           没有这条就会原地反复抓同一个地址。 */
                        finish();
                        return;
                    }

                    nextUrl = following;
                    stop();
                    say('');
                    arm();
                })
                .catch(fail);
        }

        function onEnter(entries) {
            if (loading || !nextUrl) {
                return;
            }
            if (entries[0].isIntersecting) {
                load(nextUrl);
            }
        }

        var observer = new window.IntersectionObserver(onEnter, { rootMargin: '600px 0px' });

        retry.addEventListener('click', function () {
            /* 不直接调 load()，把时机交回 onEnter：整条链路只有这一个加载入口 */
            arm();
        });

        /* 初次挂载。状态区本来就在列表末尾，短列表下它已经在预取带里，
           这一次回调会让第 2 页立刻开始加载。 */
        arm();
    }

    /* ==========================================================================
       滚动进场：列表卡进视口时淡入上移。
       .reveal 只在 JS 里挂——无 JS / 无 IO / reduced-motion 时卡片保持默认可见，
       不会因为脚本缺席而白屏。revealCard 同时被无限滚动复用：追加进来的卡片
       同样淡入，而不是硬生生蹦出来。
       ========================================================================== */

    function revealCard(card) {
        if (reduceMotion() || card.classList.contains('is-visible')) {
            return;
        }
        card.classList.add('reveal');
        /* 先让 opacity:0 的初始态被浏览器画出来，再补 is-visible 触发过渡；
           同帧加上去的话 transition 看不到起始值，直接跳到终态。 */
        window.requestAnimationFrame(function () {
            card.classList.add('is-visible');
        });
    }

    function initScrollReveal() {
        var list = document.querySelector('.main-content');

        if (!list || !window.IntersectionObserver || reduceMotion()) {
            return;
        }

        var cards = list.querySelectorAll('.post-card, .status-card, .link-card');

        if (!cards.length) {
            return;
        }

        Array.prototype.forEach.call(cards, function (card) {
            card.classList.add('reveal');
        });

        var observer = new window.IntersectionObserver(function (entries) {
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].isIntersecting) {
                    observer.unobserve(entries[i].target);
                    revealCard(entries[i].target);
                }
            }
        }, { rootMargin: '0px 0px -6% 0px' });

        Array.prototype.forEach.call(cards, function (card) {
            observer.observe(card);
        });

        /* 兜底：IO 若因边界情况迟迟不回调，不能让卡片永久透明 */
        setTimeout(function () {
            Array.prototype.forEach.call(cards, function (card) {
                revealCard(card);
            });
        }, 2000);
    }

    /* ==========================================================================
       站点统计滚动计数：站点信息卡四个数字进视口后从 0 滚到目标。
       数字取 <b data-count="N">，后缀（如「 天」）从 textContent 里剥出，
       所以「2190 天」和「42」两种写法都能滚。只滚一次。
       ========================================================================== */

    function initStatCount() {
        var stats = document.querySelector('.site-stats');

        if (!stats) {
            return;
        }

        var items = stats.querySelectorAll('.site-stat b[data-count]');

        if (!items.length) {
            return;
        }

        function countUp(el, target, suffix, duration) {
            var start = null;

            function step(ts) {
                if (start === null) {
                    start = ts;
                }
                /* 除法两侧不能留空格：jscheck.py 会把空格后的 / 误认成正则字面量，
                   一路吞到换行，把右括号也吃掉，静态检查就报括号不平衡。 */
                var t = Math.min((ts-start)/duration, 1);
                var eased = 1 - Math.pow(1 - t, 3);
                el.textContent = String(Math.round(target * eased)) + suffix;
                if (t < 1) {
                    window.requestAnimationFrame(step);
                }
            }

            window.requestAnimationFrame(step);
        }

        function startCount() {
            Array.prototype.forEach.call(items, function (el) {
                var target = parseInt(el.getAttribute('data-count'), 10);
                var suffix = el.textContent.replace(/^\d+/, '');

                if (isNaN(target)) {
                    return;
                }
                if (reduceMotion()) {
                    el.textContent = String(target) + suffix;
                    return;
                }
                countUp(el, target, suffix, 800);
            });
        }

        if (!window.IntersectionObserver) {
            startCount();
            return;
        }

        var observer = new window.IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) {
                observer.disconnect();
                startCount();
            }
        }, { threshold: 0.4 });

        observer.observe(stats);
    }

    /* ==========================================================================
       文章目录
       标题的 id 和整棵树都在这一步现搭：正文是后台写的，PHP 吐出来的是已经解析
       好的 HTML，主题改不动它，只能在前端补 id、拼列表、量位置。
       少于两个标题不出卡——一行字的目录不是导航，是占位。
       高亮跟随没用 IntersectionObserver：标题只有几十像素高，快速滚动时会整条
       掠过交叉判定，两节之间的正文更是没有任何 entry，那一瞬间整栏会一起失去高亮。
       直接量「视口上沿之上最靠后的那个标题」，任何时候都恰好有一个答案。
       ========================================================================== */

    function initToc() {
        var card = document.getElementById('sidebar-toc');
        var content = document.getElementById('toc-content');
        var list = document.getElementById('toc-list');
        var body = document.querySelector('.post-content');

        if (!card || !content || !list || !body) {
            return;
        }

        var nodes = body.querySelectorAll('h2, h3');

        if (nodes.length < 2) {
            return;
        }

        var items = [];
        var sectionLis = [];
        var lastH2 = null;

        Array.prototype.forEach.call(nodes, function (heading, i) {
            if (!heading.id) {
                /* 用户没给标题写锚点就补一个。toc- 前缀是为了不撞上正文里已有的 id */
                heading.id = 'toc-' + (i+1);
            }

            var li = document.createElement('li');
            li.className = 'toc-item';

            var link = document.createElement('a');
            link.className = 'toc-link';
            /* 走 setAttribute 不拼 innerHTML：id 可能来自正文自带的中文锚点 */
            link.setAttribute('href', '#' + heading.id);

            var text = document.createElement('span');
            /* trim：Markdown 渲染出来的标题 textContent 常带成对的换行，
               目录行是高 flex 容器，前后各多一个空行就会把行高顶开。 */
            text.textContent = heading.textContent.trim();

            link.appendChild(text);
            li.appendChild(link);

            var parent = null;

            if (heading.tagName === 'H3' && lastH2) {
                if (!lastH2.child) {
                    lastH2.child = document.createElement('ol');
                    lastH2.child.className = 'toc-child';
                    lastH2.li.appendChild(lastH2.child);
                }
                lastH2.child.appendChild(li);
                parent = lastH2.li;
            } else {
                /* 第一个标题就三级、或者 h3 出现在任何 h2 之前：没有可挂的父项，
                   直接当一级项排，目录不能因为正文少写一个 h2 就漏条目。 */
                list.appendChild(li);

                if (heading.tagName === 'H2') {
                    lastH2 = { li: li, child: null };
                    sectionLis.push(li);
                }
            }

            items.push({ heading: heading, li: li, link: link, parent: parent });
        });

        card.hidden = false;

        var topbar = parseInt(getComputedStyle(root).getPropertyValue('--topbar-h'), 10) || 60;
        var EDGE = topbar + 24;

        var current = null;
        var queued = false;

        /* 当前项变了才把列表滚到它那里——每次都硬对齐会把用户自己在列表里的
           微调滚动手感顶掉。 */
        function keepVisible(el) {
            var box = content.getBoundingClientRect();
            var rect = el.getBoundingClientRect();

            if (rect.top < box.top + 8) {
                content.scrollTop -= (box.top + 8 - rect.top);
            } else if (rect.bottom > box.bottom - 8) {
                content.scrollTop += (rect.bottom - box.bottom + 8);
            }
        }

        function render() {
            queued = false;

            var active = null;

            Array.prototype.forEach.call(items, function (item) {
                if (item.heading.getBoundingClientRect().top - EDGE <= 0) {
                    active = item;
                }
            });

            /* 第一个标题还没进过判定线时不给预选：正文一开头就亮着某一行是假的。 */
            var open = active ? (active.parent || active.li) : null;

            Array.prototype.forEach.call(items, function (item) {
                item.link.classList.toggle('is-active', item === active);
            });
            Array.prototype.forEach.call(sectionLis, function (li) {
                li.classList.toggle('is-open', li === open);
            });

            if (active !== current) {
                current = active;

                if (active) {
                    keepVisible(active.link);
                }
            }
        }

        function schedule() {
            if (queued) {
                return;
            }
            queued = true;
            window.requestAnimationFrame(render);
        }

        window.addEventListener('scroll', schedule, { passive: true });
        /* 图片补完高度、转屏、字体加载完都会挪动标题位置，不重算高亮就停在错的那一行 */
        window.addEventListener('resize', schedule);
        render();

        list.addEventListener('click', function (event) {
            var link = event.target && event.target.closest ? event.target.closest('.toc-link') : null;

            if (!link) {
                return;
            }

            var target = document.getElementById(link.getAttribute('href').slice(1));

            if (!target) {
                return;
            }

            /* 拦在这里就不可能走到 initTransition 那条淡出：它的双保险是
               「event.defaultPrevented」和「同 pathname + 同 search 直接放行」，
               两条都不依赖监听器的绑定顺序——这个 href 只有 hash、path 和 search
               跟当前页完全一样，第二条一定命中。 */
            event.preventDefault();

            /* design.css 那条 html { scroll-padding-top } 会给 scrollIntoView 让出顶栏高度，
               这里只管要不要动画。 */
            target.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'start' });

            if (window.history && window.history.replaceState) {
                /* 只改地址栏、不产生历史记录：这条链接复制出去能指到同一节，
                   后退键也不会把用户困在目录里一格一格地跳。 */
                window.history.replaceState(null, '', '#' + target.id);
            }
        });
    }

    /* ==========================================================================
       吸顶分类条右端那颗「更多」
       --------------------------------------------------------------------------
       药丸那一行是横向滚的，滚动条又被 CSS 藏掉了：鼠标用户既看不见进度，也不知道
       右边还剩几颗。这颗按钮把「看不见的分类」变成一列点得着的东西。
       面板内容每次打开的那一刻现算——判据是「此刻没有完整落在这行的可视区里」，
       不是「第 N 颗往后」：用户可能已经把行往右滑过一段，那左边被推出可视区的
       同样算没显示。
       ========================================================================== */

    function initFeedCats() {
        var row = document.getElementById('feed-cats-scroll');
        var btn = document.getElementById('feed-cats-more');
        var pop = document.getElementById('feed-cats-pop');

        if (!row || !btn || !pop) {
            return;
        }

        /* 8px 容差：窗口拉到「只差一个描边宽度」那种临界值时，不留容差按钮会一闪一闪。 */
        function hasOverflow() {
            return row.scrollWidth - row.clientWidth > 8;
        }

        function cutOff(el) {
            var a = el.getBoundingClientRect();
            var b = row.getBoundingClientRect();

            return a.left < b.left - 1 || a.right > b.right + 1;
        }

        /* 「全部」那颗只有裸文字，其余是 <span>名字</span> 加一颗计数。
           先认 span，取不到才退回整颗的 textContent。 */
        function textOf(el) {
            var first = el.querySelector('span');

            return (first ? first.textContent : el.textContent).trim();
        }

        function fill() {
            while (pop.firstChild) {
                pop.removeChild(pop.firstChild);
            }

            var made = 0;

            Array.prototype.forEach.call(row.querySelectorAll('.feed-cat'), function (el) {
                if (!cutOff(el)) {
                    return;
                }

                var link = document.createElement('a');

                /* 读原始属性而不是 el.href：这一行里拼的都是 permalink，两者此刻一样，
                   但走 getAttribute 不需要假设核心输出的地址形态。 */
                link.setAttribute('href', el.getAttribute('href'));
                link.className = 'feed-cats-pop-item' + (el.classList.contains('active') ? ' active' : '');

                var name = document.createElement('span');
                name.textContent = textOf(el);
                link.appendChild(name);

                var count = el.querySelector('.feed-cat-count');

                if (count) {
                    var num = document.createElement('span');
                    num.className = 'feed-cats-pop-count';
                    num.textContent = count.textContent.trim();
                    link.appendChild(num);
                }

                pop.appendChild(link);
                made++;
            });

            return made;
        }

        function close() {
            if (pop.hidden) {
                return;
            }
            pop.hidden = true;
            btn.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
        }

        function open(fromKeyboard) {
            /* 一颗都没捞到就不开：空面板比没有面板更让人以为分类没了 */
            if (fill() === 0) {
                return;
            }
            pop.hidden = false;
            btn.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');

            /* 键盘打开的直接把焦点送进面板（面板就在按钮正下方，读者不会去找焦点去哪了）；
               鼠标打开的不动焦点——点一下按钮就被抢走焦点，会让人下一次点击落在错的地方。 */
            if (fromKeyboard) {
                focusItem(items()[0]);
            }
        }

        function items() {
            return pop.querySelectorAll('.feed-cats-pop-item');
        }

        function focusItem(el) {
            if (el) {
                el.focus();
            }
        }

        function step(list, from, delta) {
            var i = Array.prototype.indexOf.call(list, from);

            if (i < 0) {
                return;
            }
            /* 到尽头就绕回另一头：面板可能有几十项，末端停住会让人以为下面没有了 */
            focusItem(list[(i + delta + list.length) % list.length]);
        }

        /* 面板是 auto-fill 多列网格，左右按「项」走，上下得按「行」走，
           否则按一次下移只是隔壁那一格，视觉上毫无规律。
           列数不靠 getComputedStyle 去猜网格定义——按同一 top 值分组更直接，
           而且字体缩放、滚动、换行列数变化都自动跟着对。 */
        function rows(list) {
            var out = [];
            var lastTop = null;

            Array.prototype.forEach.call(list, function (el) {
                var top = Math.round(el.getBoundingClientRect().top);

                if (lastTop === null || Math.abs(top - lastTop) > 4) {
                    out.push([]);
                    lastTop = top;
                }
                out[out.length - 1].push(el);
            });

            return out;
        }

        function stepRow(list, from, delta) {
            var grid = rows(list);
            var r = -1;
            var c = 0;

            grid.forEach(function (line, ri) {
                line.forEach(function (el, ci) {
                    if (el === from) {
                        r = ri;
                        c = ci;
                    }
                });
            });

            if (r < 0) {
                return;
            }

            var nr = (r + delta + grid.length) % grid.length;
            var line = grid[nr];

            /* 最后一行常常不满一格，光标落到该行最后一项而不是消失 */
            focusItem(line[Math.min(c, line.length - 1)]);
        }

        btn.addEventListener('click', function () {
            if (pop.hidden) {
                open(false);
            } else {
                close();
            }
        });

        document.addEventListener('click', function (event) {
            var to = event.target;

            if (pop.hidden || to === btn || btn.contains(to) || pop.contains(to)) {
                return;
            }
            close();
        });

        document.addEventListener('keydown', function (event) {
            var list = items();
            var active = document.activeElement;

            if (pop.hidden) {
                /* 焦点还在按钮上按方向键 = 想用键盘逛分类，那就开面板并把焦点交进去。
                   Enter/空格不用管：button 元素自己就会触发 click。 */
                if (!btn.hidden && active === btn && event.key === 'ArrowDown') {
                    event.preventDefault();
                    open(true);
                }
                return;
            }

            if (event.key === 'Escape') {
                close();
                btn.focus();
                return;
            }

            /* Tab 离开就是离开：面板隐藏后焦点若还停在里面的链接上，
               下一次 Tab 会从一个看不见的元素开始。 */
            if (event.key === 'Tab') {
                close();
                return;
            }

            /* 上下走整行、左右走相邻项，Home/End 到首尾 */
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                stepRow(list, active, 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                stepRow(list, active, -1);
            } else if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                event.preventDefault();
                step(list, active, event.key === 'ArrowRight' ? 1 : -1);
            } else if (event.key === 'Home') {
                event.preventDefault();
                focusItem(list[0]);
            } else if (event.key === 'End') {
                event.preventDefault();
                focusItem(list[list.length - 1]);
            } else if (pop.contains(active) && /^[a-z0-9\u4e00-\u9fa5]$/i.test(event.key)) {
                /* 打字定位：几十项的分类列表，按「前」直接跳到「前端」比连按方向键快得多 */
                var target = null;
                Array.prototype.forEach.call(list, function (el) {
                    if (target === null && textOf(el).slice(0, event.key.length).toLowerCase() === event.key.toLowerCase()) {
                        target = el;
                    }
                });
                if (target !== null) {
                    event.preventDefault();
                    focusItem(target);
                }
            }
        });

        /* 布局一变「哪几颗没显示」就全变了，而面板里的内容是打开那一刻算的快照——
           所以 resize 直接把面板收掉，不让它挂在一份过期的名单上。 */
        function measure() {
            close();
            btn.hidden = !hasOverflow();
        }

        window.addEventListener('resize', measure);
        /* 自定义字体到位之前，药丸量的是回退字的宽度，量早了会把「刚好放不下」判成
           放得下。main.js 是 defer 的，一定跑在 load 之前，这个监听器不会漏掉事件。 */
        window.addEventListener('load', measure);

        /* 当前分类那颗如果被挤在可视区外面，一进页面就该把它挪进来：读者得知道自己
           在哪个分类里，而且不挪的话它会被算进「更多」面板，等于把当前位置藏起来了。
           只在初始化挪这一次，resize 时不碰，免得跟用户自己的横向滚动较劲。
           增量用 rect 算而不是 offsetLeft：.feed-cats 是 sticky、已经「已定位」，
           药丸的 offsetParent 是它而不是这一行，两个坐标系差一个行内的滚动量。 */
        var current = row.querySelector('.feed-cat.active');

        if (current && cutOff(current)) {
            row.scrollLeft += current.getBoundingClientRect().left
                              - row.getBoundingClientRect().left - 12;
        }

        measure();
    }

    /* ==========================================================================
       搜索快捷键：⌘K / Ctrl+K 任何时候都管用，单键 / 只在「没在打字」时管用。
       窄屏下搜索框在抽屉面板里，所以快捷键要先替把面板拉开——
       对一个 offsetParent 为 null 的元素调 focus() 是点了个寂寞，一句错都不报。
       ========================================================================== */
    function initSearchShortcut() {
        var input = document.getElementById('nav-search-input');
        var kbd = document.getElementById('search-kbd');

        if (!input) {
            return;
        }

        /* 徽章上的符号是 JS 写的，不在 PHP 里赌平台：模板里留空，
           Mac 系给 ⌘K，其余给 Ctrl K，不会出现「安卓上写着 ⌘K」这种笑话。 */
        if (kbd) {
            kbd.textContent = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent)
                ? '⌘K' : 'Ctrl K';
        }

        function typing(el) {
            if (!el || !el.tagName) {
                return false;
            }
            var tag = el.tagName.toLowerCase();

            return tag === 'input' || tag === 'textarea' || tag === 'select'
                || el.isContentEditable === true;
        }

        function focusSearch() {
            if (input.offsetParent === null && drawerToggle && drawerPanel
                && !drawerPanel.classList.contains('is-open')) {
                drawerToggle.click();
            }
            input.focus();
            input.select();
        }

        document.addEventListener('keydown', function (event) {
            /* 中文输入法组词过程中的按键 key 一律是 'Process'，
               但部分浏览器仍会上报真实按键——这时候绝不能抢走 /。 */
            if (event.isComposing) {
                return;
            }

            if (event.key === 'Escape' && document.activeElement === input) {
                /* 先清字，字本来就是空的才退出焦点：「搜一下、算了」是两次按键 */
                if (input.value !== '') {
                    input.value = '';
                } else {
                    input.blur();
                }
                return;
            }

            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                focusSearch();
                return;
            }

            if (event.key === '/' && !typing(event.target)) {
                event.preventDefault();
                focusSearch();
            }
        });
    }

    /* ==========================================================================
       回到顶部：滚过一屏才挂 .is-visible。passive 监听里直接 toggle——
       阈值只在跨过一屏那一刻变一次，没有每帧更新的必要。 */
    function initBackTop() {
        var btn = document.getElementById('back-top');

        if (!btn) {
            return;
        }

        var shown = false;

        function update() {
            var y = window.pageYOffset || document.documentElement.scrollTop || 0;
            var should = y > window.innerHeight;

            if (should !== shown) {
                shown = should;
                btn.classList.toggle('is-visible', should);
            }
        }

        window.addEventListener('scroll', update, { passive: true });
        update();

        btn.addEventListener('click', function () {
            if (reduceMotion()) {
                window.scrollTo(0, 0);
            } else {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    }

    /* ==========================================================================
       图片灯箱。事件委托：不在每张图上绑监听，无限滚动追加进来的图天然可用；
       打开时才把图片地址写进 .lightbox-img，初始不加载任何资源。 */
    function initLightbox() {
        var box = document.getElementById('lightbox');

        if (!box) {
            return;
        }

        var img = box.querySelector('.lightbox-img');
        var caption = box.querySelector('.lightbox-caption');
        var closeBtn = box.querySelector('.lightbox-close');
        var prevBtn = box.querySelector('.lightbox-prev');
        var nextBtn = box.querySelector('.lightbox-next');
        var items = [];
        var index = 0;
        var lastFocus = null;
        var SELECTOR = '.post-content img, .status-content img';

        function collect() {
            items = [];
            var nodes = document.querySelectorAll(SELECTOR);
            Array.prototype.forEach.call(nodes, function (el) {
                /* 换成占位图的不要进轮播：放大一张「资料失效」没有任何意义 */
                if (el.getAttribute('src') && !el.classList.contains('img-dead')) {
                    items.push(el);
                }
            });
        }

        function render() {
            var el = items[index];

            /* 上一张可能是重试中的图：换下一张之前把它的占位痕迹擦掉，
               不然一张好图会一直顶着 .img-dead 的底板和描边 */
            img.classList.remove('img-dead', 'img-dead-retry');
            img.removeAttribute('data-dead');
            img.removeAttribute('title');
            img.src = el.getAttribute('src');
            img.alt = el.getAttribute('alt') || '';
            caption.textContent = el.getAttribute('alt') || '';
            prevBtn.hidden = items.length <= 1;
            nextBtn.hidden = items.length <= 1;
        }

        function open(i) {
            if (i < 0 || i >= items.length) {
                return;
            }
            index = i;
            lastFocus = document.activeElement;
            render();
            box.hidden = false;
            document.documentElement.classList.add('lightbox-open');
            closeBtn.focus();
        }

        function close() {
            box.hidden = true;
            document.documentElement.classList.remove('lightbox-open');
            if (lastFocus && lastFocus.focus) {
                lastFocus.focus();
            }
            lastFocus = null;
        }

        function step(delta) {
            if (!items.length) {
                return;
            }
            index = (index + delta + items.length) % items.length;
            render();
        }

        document.addEventListener('click', function (event) {
            var el = event.target;

            if (!el || !el.matches || !el.matches(SELECTOR)) {
                return;
            }
            /* 图片被包在链接里（如 [![alt](img)](url)）时交给链接，别抢导航 */
            if (el.closest && el.closest('a[href]')) {
                return;
            }
            event.preventDefault();
            collect();
            open(items.indexOf(el));
        });

        closeBtn.addEventListener('click', close);
        prevBtn.addEventListener('click', function () { step(-1); });
        nextBtn.addEventListener('click', function () { step(1); });
        box.addEventListener('click', function (event) {
            if (event.target === box) {
                close();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (box.hidden) {
                return;
            }
            if (event.key === 'Escape') {
                close();
            } else if (event.key === 'ArrowLeft') {
                step(-1);
            } else if (event.key === 'ArrowRight') {
                step(1);
            }
        });
    }

    /* ==========================================================================
       失效图片。图挂了浏览器只留一个碎图标，读的人分不清「这张本来就没有」和
       「链接烂了」，所以统一换成主题自带的占位图，把话说明白。

       两条路都堵：img 的 error 不冒泡，只能在捕获阶段收，管脚本装上监听器之后挂的；
       DOMContentLoaded 再扫一遍 complete && naturalWidth === 0，管在那之前就挂完的。
       占位图是内联 SVG 的 data URI——不占网络请求，也不会自己再挂一次。

       分三种形态：圆形头像换中性剪影（一行字塞进 40px 的圆里只会糊成一团），
       顶栏 logo 换后台没配头像时用的那套首字母占位块，其余按量出来的宽度换整卡或纯标记。 */
    function initDeadImages() {
        var AVATAR = '.avatar, .status-avatar, .rcomment-face, .card-comments-face, '
            + '.site-hero-avatar, .post-copyright-avatar-back, .post-copyright-avatar-front, .link-avatar';

        var DEAD_TIP = '图片资料已失效';

        function svg(markup) {
            return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup);
        }

        var CARD = svg("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 480 300' width='480' height='300'>"
            + "<rect x='7' y='7' width='466' height='286' rx='18' fill='none' stroke='#8b8579' stroke-opacity='.42' stroke-width='2' stroke-dasharray='11 9'/>"
            + "<g fill='none' stroke='#8b8579' stroke-opacity='.5' stroke-width='6' stroke-linecap='round' stroke-linejoin='round'>"
            + "<path d='M148 178l52-58 38 42 34-34 50 50z'/><circle cx='200' cy='92' r='18'/>"
            + "</g>"
            + "<path d='M246 42l-14 58 20 24-16 54 16 26' fill='none' stroke='#b5695a' stroke-opacity='.62' stroke-width='6' stroke-linecap='round' stroke-linejoin='round'/>"
            + "<text x='240' y='242' text-anchor='middle' font-family='system-ui, PingFang SC, Microsoft YaHei, sans-serif' font-size='27' fill='#8b8579'>图片资料已失效</text>"
            + "</svg>");

        var MARK = svg("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96' width='96' height='96'>"
            + "<rect x='5' y='5' width='86' height='86' rx='16' fill='none' stroke='#8b8579' stroke-opacity='.42' stroke-width='3' stroke-dasharray='9 7'/>"
            + "<g fill='none' stroke='#8b8579' stroke-opacity='.5' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'>"
            + "<path d='M26 66l16-19 11 13 10-10 15 16z'/><circle cx='38' cy='35' r='6'/>"
            + "</g></svg>");

        var FACE = svg("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96' width='96' height='96'>"
            + "<circle cx='48' cy='35' r='16' fill='#8b8579' fill-opacity='.34'/>"
            + "<path d='M16 88c0-19 14-31 32-31s32 12 32 31z' fill='#8b8579' fill-opacity='.34'/>"
            + "</svg>");

        function initialChar(text) {
            text = (text || '').trim();
            /* 站点名可能以 emoji 开头，按码点取才不会被切成半个代理对 */
            return text ? String.fromCodePoint(text.codePointAt(0)) : '';
        }

        function mark(img) {
            if (img.getAttribute('data-dead') === '1') {
                return;
            }

            var src = img.getAttribute('src') || '';

            /* 空 src 是灯箱那张还没装图的壳子，data: 是自家占位图，都不算挂了 */
            if (src === '' || src.indexOf('data:') === 0) {
                return;
            }

            /* 顶栏 logo 挂了不如直接换回首字母块：那儿本来就有这个设计 */
            if (img.classList.contains('topbar-logo')) {
                var brand = img.closest('.topbar-brand');
                var nameEl = brand ? brand.querySelector('.topbar-name') : null;
                var span = document.createElement('span');

                span.className = 'topbar-logo topbar-logo-placeholder';
                span.setAttribute('aria-hidden', 'true');
                span.textContent = initialChar(nameEl ? nameEl.textContent : '');
                img.parentNode.replaceChild(span, img);

                return;
            }

            var face = img.matches(AVATAR);
            var width = img.clientWidth || parseInt(img.getAttribute('width'), 10) || 0;

            if (!img.getAttribute('data-dead-src')) {
                img.setAttribute('data-dead-src', src);
            }

            img.setAttribute('data-dead', '1');
            img.src = face ? FACE : (width && width < 120 ? MARK : CARD);
            img.classList.add('img-dead');
            img.setAttribute('title', DEAD_TIP);
        }

        /* 没被链接占用的失效图，点一下拿原地址再试一次；两次还不行就别再点了 */
        function retry(img) {
            var old = img.getAttribute('data-dead-src');
            var tries = parseInt(img.getAttribute('data-dead-tries'), 10) || 0;

            if (!old || tries >= 2) {
                return;
            }

            img.setAttribute('data-dead-tries', String(tries + 1));
            img.removeAttribute('data-dead');
            img.classList.remove('img-dead');
            img.classList.add('img-dead-retry');
            /* 重试成功就把占位痕迹整个摘掉。绑在这一次请求上（{once:true}），
               比在 DOMContentLoaded 里扫一遍稳——那会儿大半的图还没挂过。 */
            img.addEventListener('load', function () {
                img.classList.remove('img-dead-retry');
                img.removeAttribute('data-dead-tries');
                img.removeAttribute('data-dead-src');
                img.removeAttribute('title');
            }, { once: true });
            img.src = old;
        }

        document.addEventListener('error', function (event) {
            var el = event.target;

            if (el && el.tagName === 'IMG') {
                mark(el);
            }
        }, true);

        document.addEventListener('click', function (event) {
            var el = event.target;

            if (!el || !el.matches || !el.matches('img.img-dead')) {
                return;
            }
            /* 包在链接里的（封面、版权卡头像）让导航走完，别在这儿抢 */
            if (el.closest('a[href]')) {
                return;
            }
            event.preventDefault();
            retry(el);
        });

        ready(function () {
            Array.prototype.forEach.call(document.querySelectorAll('img'), function (img) {
                if (img.complete && img.naturalWidth === 0) {
                    mark(img);
                }
            });
        });
    }

    /* ==========================================================================
       动态取色（Material You 式）：后台没配强调色时，从站点头像抽主色派生一套
       accent。noline_accent_style() 配了强调色会输出 <style id="noline-accent">，
       没有它才走这里。结果按头像 URL 缓存进 localStorage，跨页面复用；
       跨域头像 getImageData 会抛错，静默回退默认靛蓝。 */
    function initDynamicAccent() {
        if (document.getElementById('noline-accent')) {
            return;
        }

        var avatar = document.querySelector('.topbar-logo');

        if (!avatar || !avatar.src) {
            return;
        }

        var src = avatar.src;
        var cached = readStore(window.localStorage, 'noline-accent-v2-' + src);

        if (cached) {
            try {
                applyAccent(JSON.parse(cached));
                return;
            } catch (e) {}
        }

        var img = document.createElement('img');
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            try {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                canvas.width = 16;
                canvas.height = 16;
                ctx.drawImage(img, 0, 0, 16, 16);
                var rgb = dominantColor(ctx.getImageData(0, 0, 16, 16).data);
                if (!rgb) {
                    return;
                }
                var accent = deriveAccent(rgb);
                writeStore(window.localStorage, 'noline-accent-v2-' + src, JSON.stringify(accent));
                applyAccent(accent);
            } catch (e) {}
        };
        img.src = src;
    }

    function applyAccent(a) {
        var root = document.documentElement;
        root.style.setProperty('--accent-user', a.light);
        root.style.setProperty('--accent-user-rgb', a.rgb.join(', '));
        root.style.setProperty('--accent-user-hover', a.hover);
        root.style.setProperty('--accent-user-dark', a.dark);
        root.style.setProperty('--accent-user-dark-rgb', a.darkRgb.join(', '));
        root.style.setProperty('--accent-user-dark-hover', a.darkHover);
    }

    function dominantColor(data) {
        var r = 0, g = 0, b = 0, n = 0, i;

        for (i = 0; i < data.length; i += 4) {
            if (data[i + 3] < 128) {
                continue;
            }
            r += data[i];
            g += data[i + 1];
            b += data[i + 2];
            n++;
        }

        if (n === 0) {
            return null;
        }

        r /= n;
        g /= n;
        b /= n;

        /* 底色太灰（白/黑/灰头像、普通照片）抽不出像样的强调色，直接放弃，
           回退默认靛蓝——宁可不取色，也不拿一个发灰的颜色毁掉全站对比度 */
        if (Math.max(r, g, b) - Math.min(r, g, b) < 40) {
            return null;
        }

        return [Math.round(r), Math.round(g), Math.round(b)];
    }

    function hex(rgb) {
        var out = '#';
        rgb.forEach(function (c) {
            var h = c.toString(16);
            out += h.length === 1 ? '0' + h : h;
        });
        return out;
    }

    function mixRgb(rgb, target, ratio) {
        return [
            Math.round(rgb[0] + (target - rgb[0]) * ratio),
            Math.round(rgb[1] + (target - rgb[1]) * ratio),
            Math.round(rgb[2] + (target - rgb[2]) * ratio)
        ];
    }

    function luminance(rgb) {
        return (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;
    }

    /* 把亮度压进安全区间：浅色强调色要够深（能当白底上的文字/圆点），
       深色强调色要够亮（能当黑底上的文字）。逐个往黑/白里混，混到达标为止。 */
    function clampLightness(rgb, min, max) {
        var out = rgb;
        var i = 0;

        while (luminance(out) > max && i < 10) {
            out = mixRgb(out, 0, 0.22);
            i++;
        }
        i = 0;
        while (luminance(out) < min && i < 10) {
            out = mixRgb(out, 255, 0.22);
            i++;
        }

        return out;
    }

    function deriveAccent(rgb) {
        var light = clampLightness(rgb, 0, 0.45);
        var dark = clampLightness(rgb, 0.55, 1);
        var hover = mixRgb(light, 0, 0.18);
        var darkHover = mixRgb(dark, 255, 0.12);

        return {
            rgb: light,
            light: hex(light),
            hover: hex(hover),
            dark: hex(dark),
            darkRgb: dark,
            darkHover: hex(darkHover)
        };
    }

    /* ========================================================================== */

    initDeadImages();
    initDynamicAccent();
    initTransition();
    initThemeToggle();
    initDrawer();
    initSearchShortcut();
    initScrollPercent();
    initNavCollapse();
    initHeroTilt();
    initBackTop();
    initLightbox();

    ready(function () {
        initHighlight();
        initCopyButtons();
        initListSemantics();
        initStatusFold();
        initScrollReveal();
        /* 排在 initStatusFold 之后：自动加载不可能早于 DOMContentLoaded，
           整页那把量先跑完，后面追加进来的卡片只会被逐张量到，不会重复绑监听器。 */
        initFeedPaging();
        initStatCount();
        initToc();
        initFeedCats();
    });
})();
