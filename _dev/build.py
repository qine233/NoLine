#!/usr/bin/env python3
"""打一个能直接当 GitHub Release 附件的 noline.zip。

用法：python3 _dev/build.py [输出路径]
     默认输出到主题目录外面同级位置 <项目根>/dist/noline.zip。

为什么用白名单而不是「排除 _dev/_preview/_trash」：排除表会随目录变乱悄悄漏掉新东西，
而白名单少收一个文件会当场构建失败。发布物宁可发不出来，也不能发错。

zip 内部带一层 noline/ 目录，解压到 usr/themes/ 下正好是 usr/themes/noline/。
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
    '404.php', 'archive.php', 'comments.php', 'dcument.php', 'functions.php',
    'health.php', 'index.php', 'links.php', 'page.php', 'post.php', 'xiaoshi.php',
]
ASSET_DIRS = ['css', 'js', 'public']
DATA_DIRS = ['views']
LOOSE = ['screenshot.jpg']

# 明确知道是本地工作区、绝不外发的东西。仓库布局下主题根就是仓库根，
# 所以 README / 接口文档 / 插件目录也算「在仓库里但不进主题包」。
# 顶层出现任何不在白名单也不在这张表里的文件/目录，构建直接失败——
# 防止哪天多了个临时目录就顺手被打包走。
LOCAL_ONLY = ['_dev', '_preview', '_trash', 'dist', '.DS_Store', '.git',
              '.github', '.gitignore', '.qoder', 'README.md', 'APP-API-SPEC.md', 'plugins']

BIG_WARN = 300 * 1024
VERSION_RE = re.compile(r"define\('NOLINE_VERSION',\s*'([^']+)'\)")
# Typecho 的主题列表和后台升级都读 index.php 头部注释里的 @version，
# 而资源缓存指纹读 functions.php 的 NOLINE_VERSION。两个数字一旦分家，
# 升级判断就会永远「有新版本」或者永远不动，所以打包前强制对齐。
DOC_VERSION_RE = re.compile(r'@version\s+([^\s*]+)')


def fail(msg):
    print('FAIL: %s' % msg)
    sys.exit(1)


def theme_version():
    text = (THEME / 'functions.php').read_text(encoding='utf-8')
    m = VERSION_RE.search(text)
    if not m:
        fail('functions.php 里找不到 define(\'NOLINE_VERSION\', ...)')
    return m.group(1)


def collect():
    """返回 [(绝对路径, zip 内路径)]，顺序稳定。"""
    entries = []

    for name in PHP_PAGES + LOOSE:
        path = THEME / name
        if not path.is_file():
            fail('白名单里的 %s 不存在，主题已经和这个脚本的认知不一致了' % name)
        entries.append((path, name))

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
            entries.append((path, '/'.join(parts)))

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
    entries = collect()

    total = sum(p.stat().st_size for p, _ in entries)
    for path in entries:
        if path[0].stat().st_size > BIG_WARN:
            print('注意：%s 有 %.1f KB，值得压一下'
                  % (path[1], path[0].stat().st_size / 1024))

    out.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for path, arcname in entries:
            zf.write(path, 'noline/' + arcname)

    size = out.stat().st_size
    print('noline %s：%d 个文件，%.1f KB → %.1f KB（deflate）'
          % (version, len(entries), total / 1024, size / 1024))
    print('输出：%s' % out)
    print('把它作为 release 附件上传，文件名保持 noline.zip——插件按这个名字找附件。')


if __name__ == '__main__':
    main()
