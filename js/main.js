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

    function initStatusFold() {
        var folds = document.querySelectorAll('.status-fold');

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
    });
})();
