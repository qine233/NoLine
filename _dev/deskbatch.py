#!/usr/bin/env python3
"""桌面模式「换一批」的端到端回归：点「摇晃」真的换一批随机文章、收回桌面原卡回位。

为什么单独一个脚本、还要自己起 http 服务：
  * 这套行为只在 fetch 成功时才成立，而 _dev/shots.py 那条 file:// 管线里
    fetch 一律被 Chrome 拒掉（desk-shake.png 拍到的一直是「只晃没换」的那条兜底路径）。
    只有走 http，夹具里那三条页码链接才抓得动。
  * 本地没有 PHP，主题模板跑不起来，所以判据全部落在 _preview/ 夹具的 DOM 上：
    夹具由 mkpreview.py 按 index.php / archive.php 的真实输出形状生成，页码条
    照抄核心 Box::render()，desk.js 读的就是这一条。

判据用 --dump-dom 吐出的最终文档，不看像素：换批这件事是 DOM 层的增减，
截图既判不准也说不清。

用法：python3 _dev/deskbatch.py [--debug]   （--debug 把三份 DOM 落到 /tmp/dom-*）
"""
import http.server
import socketserver
import subprocess
import sys
import threading
from pathlib import Path

DEV = Path(__file__).resolve().parent
THEME = DEV.parent
ROOT = THEME.parent
PREVIEW = THEME / '_preview'
CHROME = Path('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
PORT = 8791
BASE = 'http://127.0.0.1:%d/%s/_preview/' % (PORT, THEME.name)
DEBUG = '--debug' in sys.argv

# 各页独有的标题，用来判断桌上到底是哪一批。改夹具记得同步这里。
T_INDEX = ['把散落的硬编码颜色收敛成一套设计令牌', '为什么 backdrop-filter 会吃掉 position: fixed']
T_OTHER = ['无限滚动那 600px 的 rootMargin 到底该怎么给',
           'DOMParser 之后的相对 href 为什么只能用 getAttribute 读']

# 桌面入口是懒加载 matter.js 之后才挂上的，第一下要等脚本到位；
# 第二下等卡片落稳（prefers-reduced-motion 下它们直接摊在桌上）再点。
CLICK_SHAKE = """<script>
setTimeout(function () { document.getElementById('feed-desk-toggle').click(); }, 400);
setTimeout(function () { document.getElementById('feed-desk-shake').click(); }, 1600);
</script>"""

CLICK_EXIT = """<script>
setTimeout(function () { document.getElementById('feed-desk-toggle').click(); }, 400);
setTimeout(function () { document.getElementById('feed-desk-shake').click(); }, 1600);
setTimeout(function () { document.getElementById('feed-desk-exit').click(); }, 3200);
</script>"""

PAGES = ('bt-shake.html', 'bt-exit.html', 'bt-same.html')


def make_page(name, inject, same_page_only=False):
    html = (PREVIEW / 'index.html').read_text(encoding='utf-8')

    if same_page_only:
        """把页码条里所有地址都指向当前这一页——核心给「当前页」那条也包了 <a>，
        真实站点上它经常和地址栏不完全相等，buildPages 按地址剔不掉。
        抓回来还是同一批，desk.js 的 sameBatch 就该识破并只晃不换。"""
        html = html.replace('./page2.html', './index.html').replace('./page3.html', './index.html')

    (PREVIEW / name).write_text(html.replace('</body>', inject + '</body>', 1), encoding='utf-8')


def dump_dom(name, budget):
    args = [str(CHROME), '--headless=new', '--disable-gpu', '--no-first-run',
            '--no-default-browser-check', '--window-size=1440,1040',
            '--force-prefers-reduced-motion', '--virtual-time-budget=%d' % budget,
            '--user-data-dir=/tmp/noline-deskbatch-%s' % name,
            '--dump-dom', BASE + name]
    out = subprocess.run(args, capture_output=True, text=True, timeout=240)

    if DEBUG:
        Path('/tmp/dom-%s' % name).write_text(out.stdout, encoding='utf-8')

    return out.stdout


def main():
    if not (PREVIEW / 'index.html').exists():
        sys.exit('FAIL: 先跑 _dev/mkpreview.py 生成夹具')

    handler = lambda *a, **kw: http.server.SimpleHTTPRequestHandler(*a, directory=str(ROOT), **kw)
    socketserver.TCPServer.allow_reuse_address = True
    httpd = socketserver.TCPServer(('127.0.0.1', PORT), handler)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()

    codes = {}

    try:
        make_page('bt-shake.html', CLICK_SHAKE)
        make_page('bt-exit.html', CLICK_EXIT)
        make_page('bt-same.html', CLICK_SHAKE, same_page_only=True)

        shake = dump_dom('bt-shake.html', 9000)
        exit_ = dump_dom('bt-exit.html', 9000)
        same = dump_dom('bt-same.html', 9000)

        checks = [
            ('换一批抓到了别的页', bool([t for t in T_OTHER if t in shake])),
            ('换批时首页原卡整批下桌', not [t for t in T_INDEX if t in shake]),
            ('收回后原卡回到信息流', all(t in exit_ for t in T_INDEX)),
            ('收回后抓来的那批不见了', not [t for t in T_OTHER if t in exit_]),
            ('按钮没留在「换一批中」', '换一批中' not in shake and '换一批中' not in exit_),
            ('页码条节点还在（只是被 CSS 收掉）', 'page-navigator' in shake),
            ('只有一页可抓时不硬换', all(t in same for t in T_INDEX) and 'is-desk' in same),
        ]

        for label, ok in checks:
            codes[label] = ok
    finally:
        for f in PAGES:
            p = PREVIEW / f
            if p.exists():
                p.unlink()
        httpd.shutdown()

    for label, ok in codes.items():
        print('%-34s %s' % (label, 'yes' if ok else 'NO'))

    if all(codes.values()):
        print('\nOK: 桌面「换一批」抓得到别的页、收回桌面原卡回位')
        return 0

    print('\nFAIL')
    return 1


sys.exit(main())
