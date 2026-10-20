#!/usr/bin/env python3
"""重跑 README 里那几张图。

用法：python3 _dev/shots.py [--only 名字片段]

前置：先跑 _dev/mkpreview.py 生成 _preview/ 夹具。图是从**真模板**渲出来的静态夹具，
不是某个在线站点——所以模板一改，这里重跑一遍图就跟着改，不会烂在仓库里。

Chrome 用 --headless=new 直接出 PNG：--virtual-time-budget 给脚本留出跑完的时间，
--force-prefers-reduced-motion 让 main.js 的进场动画走「立即到位」那条分支
（否则首屏以下的卡片会停在 reveal 之前的透明态，图里凭空少一批内容）。
深色不是在 CSS 里挑选择器，而是把 header.php 那段防闪烁脚本读 localStorage 的一行
原地换成写死的主题——那是 data-theme 唯一的来源，换掉它比事后覆盖更确定。
"""
import os
import shutil
import signal
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent            # _dev/
THEME = HERE.parent                                # 主题目录（仓库布局下就是仓库根）
PREVIEW = THEME / '_preview'
OUT = THEME / 'docs' / 'screenshots'

CHROME = Path(os.environ.get(
    'NOLINE_CHROME', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'))

# 主题切换那段脚本里读 localStorage 的唯一一行；夹具改了这里必须跟着改，
# 宁可 assert 失败，也不要悄悄发一张「说是深色其实是浅色」的图。
THEME_HOOK = "theme = localStorage.getItem('noline-theme');"

# (夹具, 输出名, CSS 像素宽, 高, 倍率, 主题, 触屏, 走 iframe)
# 触屏那一栏是 (hover: none) 的开关：它是**设备**属性，不跟视口宽度走，
# 所以「手机上的分类条」必须同时给窄视口和触屏两样，缺一样就拍成桌面布局。
SHOTS = [
    ('index.html', 'index-light.png', 1440, 1040, 1, 'light', False, False),
    ('index.html', 'index-dark.png', 1440, 1040, 1, 'dark', False, False),
    ('post.html', 'post-light.png', 1440, 1040, 1, 'light', False, False),
    ('health.html', 'health.png', 1440, 900, 1, 'light', False, False),
    ('bills.html', 'bills.png', 1440, 1180, 1, 'light', False, False),
    ('showcase.html', 'showcase.png', 1440, 1240, 1, 'light', False, False),
    ('showcase.html', 'showcase-dark.png', 1440, 1240, 1, 'dark', False, False),
    ('showcase.html', 'showcase-mobile.png', 390, 844, 2, 'light', True, True),
    ('index.html', 'index-mobile.png', 390, 844, 2, 'light', True, True),
]

# headless=new 在这台机器上截完不自己退出，等够时间就整组收掉
WAIT = 75

# Chrome 的窗口有最小宽度，--window-size=390 会被抬到 500（量出来 innerWidth=500），
# 拍出来的就是一张被裁掉的桌面图。窄屏只能把夹具塞进一个 390 宽的 iframe 里拍：
# 媒体查询按 iframe 自己的视口算，宿主窗口负责撑过那个最小宽度。
MIN_WINDOW = 500


def build_page(fixture: Path, theme: str, dest: Path):
    """把夹具搬进临时目录：相对路径改成绝对，主题写死。"""
    html = fixture.read_text(encoding='utf-8')

    if theme != 'light':
        if THEME_HOOK not in html:
            sys.exit('FAIL: %s 里找不到主题开关那一行，夹具和这个脚本的认知不一致了' % fixture.name)
        html = html.replace(THEME_HOOK, "theme = '%s';" % theme, 1)

    # 夹具里只引用 ../css 和 ../js（图片全是内联 data URI），搬到别处就得指回主题目录
    html = html.replace('"../', '"file://%s/' % THEME)
    dest.write_text(html, encoding='utf-8')


def build_host(page: Path, width: int, height: int, dest: Path):
    """把夹具套进一个恰好 width 宽的 iframe，宿主窗口再宽也无所谓。"""
    dest.write_text(
        '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
        'html,body{margin:0;height:100%%;display:flex;align-items:center;justify-content:center;background:#fff}'
        '</style></head><body>'
        '<iframe src="%s" style="width:%dpx;height:%dpx;border:0;display:block"></iframe>'
        '</body></html>' % (page.as_uri(), width, height),
        encoding='utf-8')


def shoot(src: Path, png: Path, width, height, scale, touch, crop):
    args = [
        str(CHROME),
        '--headless=new',
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--hide-scrollbars',
        '--force-prefers-reduced-motion',
        '--user-data-dir=%s' % tempfile.mkdtemp(prefix='noline-shot-'),
        '--window-size=%d,%d' % (width, height),
        '--force-device-scale-factor=%d' % scale,
        '--virtual-time-budget=6000',
    ]

    if touch:
        # Blink 的枚举：pointer 2=coarse，hover 1=none —— 等价于 DevTools 勾上「触屏」
        args.append('--blink-settings=primaryPointerType=2,primaryHoverType=1')

    args.append('--screenshot=%s' % png)
    args.append(src.as_uri())

    proc = subprocess.Popen(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                            start_new_session=True)

    try:
        proc.wait(timeout=WAIT)
    except subprocess.TimeoutExpired:
        pass
    finally:
        # headless=new 截完常常自己就退了；这时进程组已经没了，
        # 再 killpg 会抛 ProcessLookupError 把整批图打断——图其实已经写出来了。
        try:
            os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        except ProcessLookupError:
            pass

    if not png.is_file():
        sys.exit('FAIL: Chrome 没吐出 %s（检查 %s 能不能跑）' % (png.name, CHROME))

    if crop:
        # 居中裁回 iframe 那一块：宿主窗口比它宽，多出来的是白边
        subprocess.run(['sips', '-c', str(crop[0]), str(crop[1]), str(png),
                        '--out', str(png)], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def main():
    only = None

    if '--only' in sys.argv:
        only = sys.argv[sys.argv.index('--only') + 1]

    if not CHROME.is_file():
        sys.exit('FAIL: 找不到 Chrome，用 NOLINE_CHROME 指个路径')

    missing = [f for f, *_ in SHOTS if not (PREVIEW / f).is_file()]

    if missing:
        sys.exit('FAIL: 缺夹具 %s，先跑 python3 _dev/mkpreview.py' % ' '.join(missing))

    OUT.mkdir(parents=True, exist_ok=True)
    tmp = Path(tempfile.mkdtemp(prefix='noline-pages-'))
    done = []

    try:
        for fixture, name, w, h, scale, theme, touch, frame in SHOTS:
            if only and only not in name:
                continue

            page = tmp / ('%s-%s.html' % (Path(fixture).stem, theme))
            png = OUT / name
            build_page(PREVIEW / fixture, theme, page)

            if frame:
                # 名字里不能带 .png：那是张 HTML，Chrome 按后缀决定怎么解，
                # 给它 .png 就当成图片去解码，结果一张黑底碎图标
                host = tmp / ('host-%s.html' % Path(name).stem)
                build_host(page, w, h, host)
                src = host
                # 宿主窗口得撑过 Chrome 的最小宽度，截完再居中裁掉白边
                window = (max(w, MIN_WINDOW), h)
                crop = (h * scale, w * scale)
            else:
                src = page
                window = (w, h)
                crop = None

            shoot(src, png, window[0], window[1], scale, touch, crop)
            done.append((name, w * scale, h * scale, png.stat().st_size))
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    for name, pw, ph, size in done:
        print('%-20s %4dx%-4d %6.1f KB' % (name, pw, ph, size / 1024))

    print('\n输出：%s' % OUT)
    print('README 用相对路径 docs/screenshots/ 引用——仓库根就是主题根，两边同一个位置。')


if __name__ == '__main__':
    main()
