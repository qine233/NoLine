#!/usr/bin/env python3
"""打一个能直接当 GitHub Release 附件的 noline.zip。

用法：python3 _dev/build.py [输出路径]
     默认输出到主题目录外面同级位置 <项目根>/dist/noline.zip。

为什么用白名单而不是「排除 _dev/_preview/_trash」：排除表会随目录变乱悄悄漏掉新东西，
而白名单少收一个文件会当场构建失败。发布物宁可发不出来，也不能发错。

zip 里是两层根目录，正好对着后台「主题更新」一次换掉的两个地方：
    noline/…              → usr/themes/noline/
    plugins/NolineApi/…   → usr/plugins/NolineApi/
插件的更新必须和主题一起发：接口形态、字段表、后台页面是配着长的，
只换一边迟早让另一边拿到没见过的键。
"""
import os
import re
import sys
import zipfile
from pathlib import Path

from minify import minimize

HERE = Path(__file__).resolve().parent          # _dev/
THEME = HERE.parent                              # 主题目录（仓库布局下就是仓库根）
# 仓库布局下主题根带着 .git，dist 只能放主题里面（.gitignore 掉）；
# 本地工作区布局下主题和插件是同级目录，dist 放它们上面一层。
DIST = THEME / 'dist' if (THEME / '.git').exists() else THEME.parent / 'dist'

PHP_PAGES = [
    '404.php', 'archive.php', 'bills.php', 'comments.php', 'dcument.php', 'functions.php',
    'health.php', 'index.php', 'links.php', 'page.php', 'post.php', 'showcase.php',
    'xiaoshi.php',
]
ASSET_DIRS = ['css', 'js', 'public']
DATA_DIRS = ['views']
LOOSE = ['screenshot.jpg']

# 插件按文件白名单收：目录里多出任何没列进来的东西都当构建失败处理，
# 免得哪天在插件目录里落个调试脚本就跟着发出去了。
# assets/ 是后台皮肤和脚本，它们由 admin/header.php:header 用绝对 URL 引，
# 不能像 PHP 那样靠 __DIR__ 兜底，所以必须跟着包一起发。
PLUGIN_DIR = 'NolineApi'
PLUGIN_ROOT_NAME = 'plugins/NolineApi'
PLUGIN_FILES = ['Admin.php', 'Bills.php', 'Cloudreve.php', 'Plugin.php', 'Theme.php', 'Updater.php']
PLUGIN_DIRS = ['assets']

# 明确知道是本地工作区、绝不外发的东西。仓库布局下主题根就是仓库根，
# 所以 README / 接口文档 / 插件目录也算「在仓库里但不进主题包」。
# 顶层出现任何不在白名单也不在这张表里的文件/目录，构建直接失败——
# 防止哪天多了个临时目录就顺手被打包走。
LOCAL_ONLY = ['_dev', '_preview', '_trash', 'dist', '.DS_Store', '.git',
              '.github', '.gitignore', '.qoder', 'README.md', 'APP-API-SPEC.md',
              'plugins', 'docs']

BIG_WARN = 300 * 1024
VERSION_RE = re.compile(r"define\('NOLINE_VERSION',\s*'([^']+)'\)")
# Typecho 的主题列表和后台升级都读 index.php 头部注释里的 @version，
# 而资源缓存指纹读 functions.php 的 NOLINE_VERSION。两个数字一旦分家，
# 升级判断就会永远「有新版本」或者永远不动，所以打包前强制对齐。
DOC_VERSION_RE = re.compile(r'@version\s+([^\s*]+)')
PLUGIN_CONST_RE = re.compile(r"const VERSION = '([^']+)'")


def fail(msg):
    print('FAIL: %s' % msg)
    sys.exit(1)


def theme_version():
    text = (THEME / 'functions.php').read_text(encoding='utf-8')
    m = VERSION_RE.search(text)
    if not m:
        fail('functions.php 里找不到 define(\'NOLINE_VERSION\', ...)')
    return m.group(1)


def plugin_dir():
    """插件目录。本地工作区里它和主题同级，仓库布局下它在主题（=仓库根）里面。"""
    for root in (THEME.parent / 'plugins' / PLUGIN_DIR, THEME / 'plugins' / PLUGIN_DIR):
        if root.is_dir():
            return root
    fail('找不到 plugins/%s，插件不再随主题一起发布这一条就没人校验了' % PLUGIN_DIR)


def plugin_version():
    path = plugin_dir() / 'Plugin.php'
    if not path.is_file():
        fail('plugins/%s/Plugin.php 不存在' % PLUGIN_DIR)
    text = path.read_text(encoding='utf-8')
    m = PLUGIN_CONST_RE.search(text)
    if not m:
        fail('Plugin.php 里找不到 const VERSION = ...')
    d = DOC_VERSION_RE.search(text[:900])
    if not d:
        fail('Plugin.php 头部注释里没有 @version，后台插件列表读不到版本')
    if d.group(1) != m.group(1):
        fail('Plugin.php 的 @version 是 %s，const VERSION 是 %s，两边必须一起改'
             % (d.group(1), m.group(1)))
    return m.group(1)


def collect():
    """返回 [(绝对路径, zip 内路径)]，顺序稳定。zip 内路径已经带上顶层目录。"""
    entries = []

    for name in PHP_PAGES + LOOSE:
        path = THEME / name
        if not path.is_file():
            fail('白名单里的 %s 不存在，主题已经和这个脚本的认知不一致了' % name)
        entries.append((path, 'noline/' + name))

    for dirname in ASSET_DIRS + DATA_DIRS:
        root = THEME / dirname
        if not root.is_dir():
            fail('目录 %s/ 不存在' % dirname)
        for path in sorted(root.rglob('*')):
            if not path.is_file():
                continue
            parts = path.relative_to(THEME).parts
            if any(p in ('.DS_Store',) for p in parts):
                fail('%s 里混进了 .DS_Store' % '/'.join(parts))
            entries.append((path, 'noline/' + '/'.join(parts)))

    root = plugin_dir()
    for name in PLUGIN_FILES:
        path = root / name
        if not path.is_file():
            fail('插件白名单里的 %s 不存在，发布包和插件的实际文件已经不一致了' % name)
        entries.append((path, PLUGIN_ROOT_NAME + '/' + name))

    for name in PLUGIN_DIRS:
        subdir = root / name
        if not subdir.is_dir():
            fail('插件目录 %s/%s 不存在，后台皮肤和脚本就没地方发了' % (PLUGIN_DIR, name))
        for path in sorted(subdir.rglob('*')):
            if not path.is_file():
                continue
            if path.name == '.DS_Store':
                fail('plugins/%s/%s 里混进了 .DS_Store' % (PLUGIN_DIR, name))
            parts = path.relative_to(root).parts
            entries.append((path, PLUGIN_ROOT_NAME + '/' + '/'.join(parts)))

    extra = sorted(p.name for p in root.iterdir()
                   if p.is_file() and p.name not in PLUGIN_FILES and p.name != '.DS_Store')
    if extra:
        fail('plugins/%s 里有没进白名单的文件：%s——先决定它发不发'
             % (PLUGIN_DIR, '、'.join(extra)))
    dirs = [p.name for p in root.iterdir() if p.is_dir() and p.name not in PLUGIN_DIRS]
    if dirs:
        fail('plugins/%s 下出现了没进白名单的子目录 %s'
             % (PLUGIN_DIR, '、'.join(sorted(dirs))))

    return entries



def check_top_level():
    ship = set(PHP_PAGES + LOOSE + ASSET_DIRS + DATA_DIRS)
    for name in sorted(os.listdir(THEME)):
        if name in ship or name in LOCAL_ONLY:
            continue
        fail('顶层的 %s 既不在发布白名单也不在本地名单里——'
             '先决定它该去哪边，再构建' % name)


def check_minified_fresh():
    """header.php 见 .min.css 存在就用它，所以过期产物会静默把新样式挡在门外。

    按内容比，不按 mtime 比：git clone / Actions 的 checkout 里时间戳是随写入顺序产生的，
    拿它当判据会在一个根本没改过样式的仓库上报「css 比 .min.css 新」。
    """
    for name in ('design.css', 'article.css'):
        src = THEME / 'css' / name
        min_ = THEME / 'css' / (src.stem + '.min.css')
        if not min_.is_file():
            fail('css/%s 还没有压缩版，先跑 python3 _dev/minify.py' % name)
        if minimize(src.read_text(encoding='utf-8')) != min_.read_text(encoding='utf-8'):
            fail('css/%s 的 .min.css 和源文件对不上——改过样式没重跑 minify.py，'
                 '线上会拿到旧样式' % name)


def check_version_sync():
    index = (THEME / 'index.php').read_text(encoding='utf-8')
    head = index[:800]
    m = DOC_VERSION_RE.search(head)
    if not m:
        fail('index.php 头部注释里找不到 @version，Typecho 读不到主题版本')
    if m.group(1) != theme_version():
        fail('index.php 的 @version 是 %s，functions.php 的 NOLINE_VERSION 是 %s，'
             '两边必须一起改' % (m.group(1), theme_version()))


def main():
    out = Path(sys.argv[1]) if len(sys.argv) > 1 else DIST / 'noline.zip'
    check_top_level()
    check_minified_fresh()
    check_version_sync()
    version = theme_version()
    pversion = plugin_version()
    entries = collect()

    total = sum(p.stat().st_size for p, _ in entries)
    for path in entries:
        if path[0].stat().st_size > BIG_WARN:
            print('注意：%s 有 %.1f KB，值得压一下'
                  % (path[1], path[0].stat().st_size / 1024))

    out.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for path, arcname in entries:
            zf.write(path, arcname)

    size = out.stat().st_size
    print('主题 %s + 插件 %s：%d 个文件，%.1f KB → %.1f KB（deflate）'
          % (version, pversion, len(entries), total / 1024, size / 1024))
    print('输出：%s' % out)
    print('包里有 %s/ 和 %s/ 两个顶层目录，后台「主题更新」会一次把两边都换掉。'
          % ('noline', PLUGIN_ROOT_NAME))


if __name__ == '__main__':
    main()
