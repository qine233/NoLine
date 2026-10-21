/**
 * 桌面模式：把信息流里的卡片交给 matter.js 当刚体。
 *
 * 这个文件和 js/vendor/matter.min.js 都是 main.js 在第一次点「桌面」时才拉的——
 * 压缩后仍有 80KB 的物理引擎不该出现在首屏关键路径上。脚本到位之后只往全局挂一个
 * window.NolineDesk，剩下的动作一律由那次点击触发。
 *
 * 坐标系：桌面（.main-content.feed）自己的盒子就是物理世界，原点在它左上角，单位 px。
 * 卡片全部 position:absolute; left:0; top:0，位置只由 transform 里的 translate3d 决定；
 * 逐帧改 left/top 会触发整页重排，卡片会抖、鼠标还会跟丢。
 *
 * 尺寸一律读 offsetWidth/offsetHeight，不读 getBoundingClientRect：旋转之后的
 * bounding box 是斜着的外接矩形，拿它当刚体尺寸会越撞越大。
 */
(function () {
    'use strict';

    var M = window.Matter;

    if (!M) {
        /* 引擎没到位就别半路跑起来：没有 Matter 的 desk.js 什么都做不了，
           宁可可回退成「没有这个功能」。 */
        return;
    }

    var STORE_KEY = 'noline-desk';
    var CARD_SEL = '.post-card, .status-card';
    var DESK_CLASS = 'is-desk';

    var PAD = 6;            /* 卡片离桌面边缘的最小间距，四面墙就压在这条线上 */
    var WALL_T = 400;       /* 墙必须做厚：高速运动的卡片会被瞬移进薄墙里然后穿出去 */
    var WALL_H = 8;         /* 左右两面墙向上延伸几个桌面高，见 buildWalls */
    var DRAG_SLOP = 6;      /* 走过这么多像素才算「拖」，否则仍然算「点了一下」 */
    var IDLE_FRAMES = 60;   /* 连续这么多帧全桌静止 → 停掉循环省电 */
    var QUIET_SPEED = .06;  /* 速度低于这条线就算「停了」，单位是 px / 物理步 */
    var PILE_MS = 1900;     /* 「摇晃」之后往桌子中间拉多久，见 pilePull() */
    var PILE_G = 1.6;       /* 往中间拉的力，按重力倍数算——见 pilePull 里为什么不能用冲量 */
    var STEP = 1000 / 60;   /* 物理走固定步长：真实 dt 在掉帧的那一帧会把卡片弹飞 */
    var FIT_SLOP = 32;      /* 桌面宽度和存下来的一致才敢还原位置 */
    var ESCAPE_H = 2;       /* 飞出台面这么多倍桌面高，就直接放回桌上（见 frame 里的兜底） */

    var root = document.documentElement;
    var feed = document.querySelector('.main-content.feed');
    var btnToggle = document.getElementById('feed-desk-toggle');
    var btnShake = document.getElementById('feed-desk-shake');
    var btnExit = document.getElementById('feed-desk-exit');
    var chipCount = document.getElementById('feed-desk-count');

    if (!feed || !btnToggle) {
        return;
    }

    var reduceQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    var engine = null;
    var walls = null;
    var items = [];           /* { el, body, id, w, h } */
    var size = { w: 0, h: 0 };
    var raf = 0;
    var idle = 0;
    var drag = null;
    var running = false;
    var suppressClick = false;
    var pileFrames = 0;       /* 还剩几帧往中间拉，归零就放开了堆 */
    var saveTimer = 0;
    var resizeTimer = 0;

    function rand(a, b) {
        return a + Math.random() * (b - a);
    }

    function clamp(v, a, b) {
        return v < a ? a : (v > b ? b : v);
    }

    function reduceMotion() {
        return !!(reduceQuery && reduceQuery.matches);
    }

    function now() {
        return window.performance && window.performance.now ? window.performance.now() : Date.now();
    }

    function readStore() {
        try {
            return JSON.parse(window.localStorage.getItem(STORE_KEY));
        } catch (e) {
            return null;
        }
    }

    function writeStore(value) {
        try {
            window.localStorage.setItem(STORE_KEY, JSON.stringify(value));
        } catch (e) {
            /* 隐私模式下 setItem 会直接抛，记不住位置不是世界末日 */
        }
    }

    /* 视口坐标 → 桌面局部坐标。每次单独取 rect：拖动的过程中页面可能被滚走了。 */
    function local(event) {
        var r = feed.getBoundingClientRect();

        return { x: event.clientX - r.left, y: event.clientY - r.top };
    }

    function cards() {
        var out = [];

        Array.prototype.forEach.call(feed.children, function (el) {
            if (el.matches && el.matches(CARD_SEL)) {
                out.push(el);
            }
        });

        return out;
    }

    function itemOf(el) {
        for (var i = 0; i < items.length; i++) {
            if (items[i].el === el) {
                return items[i];
            }
        }

        return null;
    }

    /* ==========================================================================
       刚体的尺寸与落点
       ========================================================================== */

    var BODY_OPTS = {
        /* 倒角 ≈ 卡片圆角：纯直角最容易互相卡住抖动，切一刀之后是「滑进堆里」的手感 */
        chamfer: { radius: 10 },
        restitution: 0.12,    /* 纸片不是台球，撞一撞就该停 */
        friction: 0.06,
        frictionStatic: 0.2,
        frictionAir: 0.035    /* 空气阻力给足，两三秒内整桌安静下来 */
    };

    /**
     * 没有存过位置就下一场雨：卡片从桌面上方一张张掉进来。
     * prefers-reduced-motion 时直接摊在桌面里——该看到的是结果，不是过程。
     */
    function scatter(index, w, h) {
        var left = PAD + w / 2;
        var right = Math.max(left, size.w - PAD - w / 2);

        if (reduceMotion()) {
            return {
                x: rand(left, right),
                y: rand(PAD + h / 2, Math.max(PAD + h / 2, size.h - PAD - h / 2)),
                a: rand(-.22, .22)
            };
        }

        /* 间隔按卡片高度走，不然十张卡挤在同一条线上会互相穿模 */
        return {
            x: rand(left, right),
            y: -h / 2 - 40 - index * (h * .5 + 24),
            a: rand(-.6, .6)
        };
    }

    function savedSpot(saved, id, w, h) {
        if (!saved || !saved.items || Math.abs(saved.w - size.w) > FIT_SLOP) {
            return null;
        }

        for (var i = 0; i < saved.items.length; i++) {
            if (String(saved.items[i].i) === String(id)) {
                return {
                    x: clamp(saved.items[i].x, PAD + w / 2, Math.max(PAD + w / 2, size.w - PAD - w / 2)),
                    y: clamp(saved.items[i].y, PAD + h / 2, size.h - PAD - h / 2),
                    a: saved.items[i].a || 0
                };
            }
        }

        return null;
    }

    function addBody(el, index, total, saved) {
        var w = el.offsetWidth;
        var h = el.offsetHeight;
        var id = el.getAttribute('data-cid') || String(index);
        var spot = savedSpot(saved, id, w, h) || scatter(index, w, h);
        var opts = {};
        var body;

        /* 逐条抄一遍而不是直接展开 BODY_OPTS：这个浏览器上没有对象展开的语法糖 */
        for (var k in BODY_OPTS) {
            if (BODY_OPTS.hasOwnProperty(k)) {
                opts[k] = BODY_OPTS[k];
            }
        }
        opts.angle = spot.a;

        body = M.Bodies.rectangle(spot.x, spot.y, w, h, opts);

        items.push({ el: el, body: body, id: id, w: w, h: h });
        M.World.add(engine.world, body);

        return body;
    }

    function buildAll() {
        var saved = readStore();
        var els = cards();

        for (var i = 0; i < els.length; i++) {
            addBody(els[i], i, els.length, saved);
        }
    }

    /* ==========================================================================
       桌面四周的墙
       ========================================================================== */

    function buildWalls() {
        /* 左右两面墙往上做长：卡片被抛起来时会飞出桌面好几倍高，
           墙只到桌面上沿的话它就能斜着越过边界，落到台子外面再也回不来。
           上面不封墙：见 pilePull() 上面那段——封顶会把「从上方落进来」那一场雨堵死。 */
        walls = {
            left: M.Bodies.rectangle(PAD - WALL_T / 2, size.h / 2, WALL_T, size.h * WALL_H, { isStatic: true }),
            right: M.Bodies.rectangle(size.w - PAD + WALL_T / 2, size.h / 2, WALL_T, size.h * WALL_H, { isStatic: true }),
            floor: M.Bodies.rectangle(size.w / 2, size.h - PAD + WALL_T / 2, size.w * 3, WALL_T, { isStatic: true })
        };

        M.World.add(engine.world, [walls.left, walls.right, walls.floor]);
    }

    /**
     * 「摇晃」往桌子中间拉的那股力。
     *
     * 原先是把左右两面墙瞬移收拢，模拟里当场就把卡片挤穿：静态体一次挪 200px，
     * 夹在中间的卡片被解算器推出去，速度大得直接穿过地板离开世界。
     * 现在改成按重力倍数往中线加一段横向力，力的大小按离中线的距离收着给，
     * 卡片是被「吹」到中间堆起来的，谁也不会被夹住。
     */
    function pilePull() {
        for (var i = 0; i < items.length; i++) {
            var body = items[i].body;
            var dx = size.w / 2 - body.position.x;
            var strength = Math.min(Math.abs(dx) / 120, 1) * body.mass * PILE_G * 0.001;

            M.Body.applyForce(body, body.position, { x: (dx < 0 ? -strength : strength), y: 0 });
        }
    }

    /* ==========================================================================
       循环
       ========================================================================== */

    function paint() {
        for (var i = 0; i < items.length; i++) {
            var it = items[i];

            it.el.style.transform = 'translate3d(' + (it.body.position.x - it.w / 2).toFixed(2) + 'px,'
                + (it.body.position.y - it.h / 2).toFixed(2) + 'px,0) rotate(' + it.body.angle.toFixed(4) + 'rad)';
        }
    }

    /**
     * 全桌是否已经停住。
     * 没用 matter 自己的 enableSleeping：在 node 里跑同一套参数验过，卡片被抛到
     * 最高那一瞬间速度恰好过零，观察者判定「它停了」，于是它就一直悬在半空中睡着，
     * 醒来时桌面凭空少几张卡。按速度自己判，稳得多。
     */
    function quiet() {
        for (var i = 0; i < items.length; i++) {
            if (items[i].body.speed > QUIET_SPEED) {
                return false;
            }
        }

        return true;
    }

    /* 飞出台面上沿 ESCAPE_H 倍高度的卡片（被墙角挤出去、或者窗口中途变小）：
       直接放回台子上方重新落一次。重力会自己把它带回来，但这一下不能等几十秒。 */
    function recover() {
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var body = it.body;

            if (body.position.y > size.h + ESCAPE_H * size.h || body.position.y < -ESCAPE_H * size.h) {
                M.Body.setPosition(body, {
                    x: clamp(body.position.x, PAD + it.w / 2, Math.max(PAD + it.w / 2, size.w - PAD - it.w / 2)),
                    y: -it.h / 2 - 40
                });
                M.Body.setVelocity(body, { x: 0, y: 0 });
                M.Body.setAngularVelocity(body, 0);
            }
        }
    }

    function frame() {
        raf = window.requestAnimationFrame(frame);

        if (pileFrames > 0) {
            pilePull();
            pileFrames--;
        }

        M.Engine.update(engine, STEP);
        paint();
        recover();

        if (drag || !quiet()) {
            idle = 0;
            return;
        }

        /* 整桌安静下来就没必要继续烧电，等下一次交互再叫醒 */
        if (++idle > IDLE_FRAMES) {
            pause();
            save();
        }
    }

    function wake() {
        idle = 0;

        if (!raf && running) {
            raf = window.requestAnimationFrame(frame);
        }
    }

    function pause() {
        if (raf) {
            window.cancelAnimationFrame(raf);
            raf = 0;
        }
    }

    /* ==========================================================================
       拖动：自己接 Pointer Events，不用 matter 的 MouseConstraint
       ========================================================================== */

    /**
     * MouseConstraint 要一块画布，靠 element 的 rect 标定坐标；我们身后是会滚动的
     * 文档，滚一下就得重算 offset，触屏还得另接一套 touch 事件。自己接 Pointer Events
     * 反而短，而且拖动中途的滚动、多点触控都是同一条路径。
     */
    function onDown(event) {
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        var el = event.target && event.target.closest ? event.target.closest(CARD_SEL) : null;

        if (!el || !feed.contains(el)) {
            return;
        }

        var it = itemOf(el);

        if (!it) {
            return;
        }

        var p = local(event);

        drag = {
            it: it,
            id: event.pointerId,
            /* 抓住点离刚体中心的偏移：按住卡片右下角，右下角就得一直在手底下 */
            gx: it.body.position.x - p.x,
            gy: it.body.position.y - p.y,
            x0: p.x,
            y0: p.y,
            moved: 0,
            trail: [{ t: now(), x: p.x, y: p.y }]
        };

        /* 拖的时候改成静态体：它推得开别的卡片，自己又完全不归重力管，手感是「拿在手里」
           而不是「拴了根橡皮筋」。松手再改回来，把速度一次性还给它。 */
        M.Body.setStatic(it.body, true);
        el.classList.add('is-dragging');

        try {
            el.setPointerCapture(event.pointerId);
        } catch (e) {
            /* 老浏览器没有指针捕获，顶多是拖出边界就断，不影响能用 */
        }

        wake();
        /* 不拦就是选中一片文字 + 图片被拖成浏览器原生那套拖拽 */
        event.preventDefault();
    }

    function onMove(event) {
        if (!drag || event.pointerId !== drag.id) {
            return;
        }

        var p = local(event);

        drag.moved = Math.max(drag.moved, Math.abs(p.x - drag.x0) + Math.abs(p.y - drag.y0));
        M.Body.setPosition(drag.it.body, { x: p.x + drag.gx, y: p.y + drag.gy });
        drag.trail.push({ t: now(), x: p.x, y: p.y });

        if (drag.trail.length > 6) {
            drag.trail.shift();
        }

        wake();
    }

    /* 抛出速度取最近 120ms 的位移——只算最后一次 move 会让轻手轻放的卡片原地掉下去，
       而快速甩出去的又会慢得像在蜜里游。 */
    function trailVelocity(trail) {
        var last = trail[trail.length - 1];
        var first = trail[0];

        for (var i = trail.length - 1; i >= 0; i--) {
            if (last.t - trail[i].t <= 120) {
                first = trail[i];
            } else {
                break;
            }
        }

        var dt = Math.max(1, last.t - first.t);

        return {
            x: clamp((last.x - first.x) / dt * STEP, -45, 45),
            y: clamp((last.y - first.y) / dt * STEP, -45, 45)
        };
    }

    function onUp(event) {
        if (!drag || event.pointerId !== drag.id) {
            return;
        }

        var it = drag.it;

        M.Body.setStatic(it.body, false);

        if (drag.moved > DRAG_SLOP) {
            var v = trailVelocity(drag.trail);

            /* 拖完会跟着派发一次 click，卡片上全是链接，不拦就是「拖一张卡跳一篇文章」 */
            suppressClick = true;
            M.Body.setVelocity(it.body, v);
            M.Body.setAngularVelocity(it.body, clamp(v.x * .004, -.28, .28));
        }

        it.el.classList.remove('is-dragging');

        try {
            it.el.releasePointerCapture(event.pointerId);
        } catch (e) {}

        drag = null;
        saveSoon();
        wake();
    }

    function onClickCapture(event) {
        if (!suppressClick) {
            return;
        }

        suppressClick = false;
        event.stopPropagation();
        event.preventDefault();
    }

    function onKey(event) {
        if (event.key === 'Escape' || event.key === 'Esc') {
            stop();
        }
    }

    function bind(on) {
        var method = on ? 'addEventListener' : 'removeEventListener';

        feed[method]('pointerdown', onDown);
        feed[method]('pointermove', onMove);
        feed[method]('pointerup', onUp);
        feed[method]('pointercancel', onUp);
        feed[method]('click', onClickCapture, true);
    }

    /* ==========================================================================
       自动加载拼进来的新卡
       ========================================================================== */

    var observer = window.MutationObserver ? new MutationObserver(function () {
        if (!running) {
            return;
        }

        var els = cards();

        for (var i = 0; i < els.length; i++) {
            if (!itemOf(els[i])) {
                addBody(els[i], i, els.length, null);
            }
        }

        setCount(els.length);
        wake();
    }) : null;

    /* ==========================================================================
       存位置
       ========================================================================== */

    function save() {
        var data = { w: Math.round(size.w), items: [] };

        for (var i = 0; i < items.length; i++) {
            data.items.push({
                i: items[i].id,
                x: Math.round(items[i].body.position.x),
                y: Math.round(items[i].body.position.y),
                /* 角度存到 3 位小数就够，JSON 里塞一长串浮点没意义 */
                a: +items[i].body.angle.toFixed(3)
            });
        }

        writeStore(data);
    }

    function saveSoon() {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(save, 500);
    }

    function setCount(n) {
        if (chipCount) {
            chipCount.textContent = n + ' 张卡';
        }
    }

    /* ==========================================================================
       开关
       ========================================================================== */

    function reveal(on) {
        btnToggle.setAttribute('aria-pressed', on ? 'true' : 'false');

        if (btnShake) {
            btnShake.hidden = !on;
        }
        if (btnExit) {
            btnExit.hidden = !on;
        }
        if (chipCount) {
            chipCount.hidden = !on;
        }
    }

    /* 桌面铺满一屏，进的时候把它顶到顶栏下面：不滚一下就只看见桌面下半截。 */
    function scrollDeskIntoView() {
        var top = feed.getBoundingClientRect().top + (window.pageYOffset || 0) - 88;

        if (window.scrollTo) {
            window.scrollTo({ top: top, behavior: reduceMotion() ? 'auto' : 'smooth' });
        }
    }

    function start() {
        if (running) {
            return;
        }

        root.classList.add(DESK_CLASS);

        /* class 先挂再量：卡片宽度是这条 class 给的 --desk-card-w，
           顺序反了量到的就是信息流原来的列宽，刚体和 DOM 从第一帧就对不上。 */
        var r = feed.getBoundingClientRect();

        size.w = r.width;
        size.h = r.height;

        /* 不开 enableSleeping：见 quiet() 上面那段注释，它会把卡片冻在抛物线顶点。 */
        engine = M.Engine.create({ positionIterations: 8, velocityIterations: 6 });
        engine.gravity.y = 1;

        buildWalls();
        buildAll();

        running = true;
        reveal(true);
        setCount(items.length);
        bind(true);

        if (observer) {
            observer.observe(feed, { childList: true });
        }

        window.addEventListener('resize', onResize);
        document.addEventListener('keydown', onKey);

        scrollDeskIntoView();
        wake();
    }

    function stop() {
        if (!running) {
            return;
        }

        pileFrames = 0;
        window.clearTimeout(saveTimer);
        save();

        running = false;
        pause();
        bind(false);

        if (observer) {
            observer.disconnect();
        }

        window.removeEventListener('resize', onResize);
        document.removeEventListener('keydown', onKey);

        for (var i = 0; i < items.length; i++) {
            items[i].el.style.transform = '';
            items[i].el.classList.remove('is-dragging');
        }

        items = [];
        drag = null;

        M.World.clear(engine.world, false);
        M.Engine.clear(engine);
        engine = null;
        walls = null;

        root.classList.remove(DESK_CLASS);
        reveal(false);
    }

    function toggle() {
        if (running) {
            stop();
        } else {
            start();
        }
    }

    /**
     * 「摇晃」：全体挨一下随机冲量，再接 PILE_MS 的往中间拉。
     * 拉完之后卡片就顺势在桌子中央堆成一座——这是这个按钮存在的理由，
     * 光是抖一抖然后散得满桌都是没什么意义。
     *
     * 冲量给得不算大（模拟里 -26 往上抛会飞出三屏远，落回来要等十几秒），
     * 手感上就是「纸片被掀起来」，不是「纸片被发射」。
     */
    function shake() {
        if (!running) {
            return;
        }

        for (var i = 0; i < items.length; i++) {
            var body = items[i].body;

            M.Body.setStatic(body, false);
            M.Body.setVelocity(body, { x: rand(-12, 12), y: rand(-20, -6) });
            M.Body.setAngularVelocity(body, rand(-.3, .3));
        }

        pileFrames = Math.round(PILE_MS / STEP);
        wake();
    }

    /* ==========================================================================
       窗口变化
       ========================================================================== */

    /**
     * 断点一过卡片宽度就变，刚体必须跟着改尺寸，否则 DOM 和物理各说各话。
     * 位置按新旧桌面的比例搬，窗口只是变窄一点的时候不至于把整桌打乱。
     */
    function rebuild() {
        if (!running) {
            return;
        }

        var r = feed.getBoundingClientRect();
        var sx = size.w ? r.width / size.w : 1;
        var sy = size.h ? r.height / size.h : 1;

        size.w = r.width;
        size.h = r.height;

        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var w = it.el.offsetWidth;
            var h = it.el.offsetHeight;

            if (w !== it.w || h !== it.h) {
                /* matter 没有 Body.setSize，只有按比例缩的 Body.scale。
                   顶点就是当初那张 w×h 矩形，所以比例直接拿新旧尺寸相除。 */
                M.Body.scale(it.body, w / it.w, h / it.h);
                it.w = w;
                it.h = h;
            }

            M.Body.setPosition(it.body, {
                x: clamp(it.body.position.x * sx, PAD + w / 2, Math.max(PAD + w / 2, size.w - PAD - w / 2)),
                y: clamp(it.body.position.y * sy, PAD + h / 2, size.h - PAD - h / 2)
            });
        }

        M.Body.setPosition(walls.left, { x: PAD - WALL_T / 2, y: size.h / 2 });
        M.Body.setPosition(walls.right, { x: size.w - PAD + WALL_T / 2, y: size.h / 2 });
        M.Body.setPosition(walls.floor, { x: size.w / 2, y: size.h - PAD + WALL_T / 2 });

        wake();
    }

    function onResize() {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(rebuild, 200);
    }

    if (btnShake) {
        btnShake.addEventListener('click', shake);
    }
    if (btnExit) {
        btnExit.addEventListener('click', stop);
    }

    window.NolineDesk = {
        toggle: toggle,
        shake: shake,
        active: function () {
            return running;
        }
    };
})();
