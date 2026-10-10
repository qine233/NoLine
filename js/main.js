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
            /* 纯锚点跳转交给浏览器，否则 hash 定位会被淡出动画吃掉 */
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

    /* 分类下拉。CSS 的 :hover / :focus-within 已经能开合它，这里补三件 CSS 做不到的事：
       触发器上如实的 aria-expanded、点击也能开合（触屏没有 hover）、以及点外面收起。 */
    function initNavGroups() {
        var groups = document.querySelectorAll('.nav-group');

        if (!groups.length) {
            return;
        }

        var wide = window.matchMedia ? window.matchMedia('(min-width: 1024px)') : null;

        Array.prototype.forEach.call(groups, function (group) {
            var button = group.querySelector('.nav-group-btn');

            if (!button) {
                return;
            }

            function setPinned(open) {
                group.classList.toggle('is-open', open);
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            button.addEventListener('click', function () {
                setPinned(!group.classList.contains('is-open'));
            });

            /* 宽屏上开合是 CSS 干的，aria-expanded 得跟着说真话，否则读屏器
               一直以为菜单是关的。窄屏下面板只由点击控制，别把 hover 写进去。 */
            if (wide) {
                var onIn = function () {
                    if (wide.matches) {
                        button.setAttribute('aria-expanded', 'true');
                    }
                };
                var onOut = function () {
                    if (wide.matches && !group.classList.contains('is-open')) {
                        button.setAttribute('aria-expanded', 'false');
                    }
                };
                group.addEventListener('mouseenter', onIn);
                group.addEventListener('focusin', onIn);
                group.addEventListener('mouseleave', onOut);
                group.addEventListener('focusout', onOut);
            }

            document.addEventListener('click', function (event) {
                if (group.classList.contains('is-open') && !group.contains(event.target)) {
                    setPinned(false);
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }
            Array.prototype.forEach.call(groups, function (group) {
                var button = group.querySelector('.nav-group-btn');
                if (button && group.classList.contains('is-open')) {
                    group.classList.remove('is-open');
                    button.setAttribute('aria-expanded', 'false');
                    button.focus();
                }
            });
        });
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
           的唯一判据，而且必须先于 .page-navigator 判。post / page / links / talk 的评论
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
            say(LABEL_FEED_FAIL);
            retry.hidden = false;
        }

        function load(url) {
            loading = true;
            retry.hidden = true;
            spinner.hidden = false;
            say(LABEL_FEED_LOADING);

            fetch(url, { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('HTTP ' + res.status);
                    }
                    return res.text();
                })
                .then(function (html) {
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

    /* ========================================================================== */

    initTransition();
    initThemeToggle();
    initDrawer();
    initNavGroups();
    initScrollPercent();

    ready(function () {
        initHighlight();
        initCopyButtons();
        initStatusFold();
        /* 排在 initStatusFold 之后：自动加载不可能早于 DOMContentLoaded，
           整页那把量先跑完，后面追加进来的卡片只会被逐张量到，不会重复绑监听器。 */
        initFeedPaging();
    });
})();
