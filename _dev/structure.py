#!/usr/bin/env python3
"""
静态结构验证。本机没有 php 也没有 node，模板跑不起来，
所以这里只做能被静态证明的事，分五组：

1. PHP 平衡——花括号 / 圆括号 / 方括号 / 替代语法（if:…endif; 等）
2. HTML 标签闭合
3. 页面骨架——9 个页面模板都必须是 container > grid-layout > main-content，
   且都 need 了 header.php 与 footer.php
4. 全局唯一钩子——#noline-progress / #topnav / #nav-scrim / #nav-toggle /
   #nav-search-input / #nav-percent / #nav-cats / #feed-status 以及 .mobile-menu-btn，
   在整个主题里各出现且仅出现一次（完整清单见 HOOKS）
5. 图标可解析——所有 noline_icon('…') 的字面量名，以及 noline_nav_icon()
   映射表里的每一个值，都必须能在 icons.php 中找到

任何一组出问题都返回非零。
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

PAGE_TEMPLATES = [
    '404.php', 'archive.php', 'dcument.php', 'health.php', 'index.php', 'links.php',
    'page.php', 'post.php', 'xiaoshi.php',
]

VOID = {
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr',
}

errors = []
notes = []


def fail(group, message):
    errors.append('[%s] %s' % (group, message))


# ---------------------------------------------------------------- PHP 区域切分

PHP_OPEN = re.compile(r'<\?(?:php\b|=)?', re.I)
PHP_CLOSE = re.compile(r'\?>')


def php_regions(text):
    """返回 [(start, end, code)]，只含 <?php … ?> 之内的代码，不含定界符本身。"""
    regions = []
    pos = 0
    while True:
        m = PHP_OPEN.search(text, pos)
        if not m:
            break
        end = PHP_CLOSE.search(text, m.end())
        stop = end.start() if end else len(text)
        regions.append((m.end(), stop, text[m.end():stop]))
        pos = end.end() if end else len(text)
    return regions


def strip_php_code(code, keep_strings=False):
    """去掉注释；keep_strings=False 时连字符串一起去掉，只留下影响括号计数的真代码。"""
    out = []
    i = 0
    n = len(code)
    while i < n:
        c = code[i]
        two = code[i:i + 2]
        if two == '//':
            j = code.find('\n', i)
            i = n if j < 0 else j
            out.append(' ')
            continue
        if two == '/*':
            j = code.find('*/', i + 2)
            stop = n if j < 0 else j + 2
            # 保留换行，报出来的行号才是对的
            out.append(re.sub(r'[^\n]', ' ', code[i:stop]))
            i = stop
            continue
        if c == '#' and two != '#[':
            # PHP 8 属性 #[…] 里的括号要保留；裸 # 注释整行丢掉
            j = code.find('\n', i)
            i = n if j < 0 else j
            out.append(' ')
            continue
        if c in '\'"':
            quote = c
            start = i
            i += 1
            while i < n:
                if code[i] == '\\':
                    i += 2
                    continue
                if code[i] == quote:
                    i += 1
                    break
                i += 1
            out.append(code[start:i] if keep_strings else ' ')
            continue
        out.append(c)
        i += 1
    return ''.join(out)


def blank_php_comments(text):
    """整份文件里只挖空 PHP 注释，HTML 区域原样保留，行号不变。"""
    pieces = []
    pos = 0
    for start, stop, code in php_regions(text):
        pieces.append(text[pos:start])
        pieces.append(strip_php_code(code, keep_strings=True))
        pos = stop
    pieces.append(text[pos:])
    return ''.join(pieces)


# ---------------------------------------------------------------- 1. PHP 平衡

def check_php_balance(path, text):
    code = ''.join(strip_php_code(r[2]) for r in php_regions(text))

    for open_ch, close_ch in (('{', '}'), ('(', ')'), ('[', ']')):
        depth = 0
        for ch in code:
            if ch == open_ch:
                depth += 1
            elif ch == close_ch:
                depth -= 1
                if depth < 0:
                    fail('PHP', '%s: 多出一个 %s' % (path.name, close_ch))
                    depth = 0
        if depth != 0:
            fail('PHP', '%s: %s 未闭合，差 %d 个' % (path.name, open_ch, depth))

    # 替代语法。不能用带 re.S 的正则去数：`while ($x->next()) {` 会一路懒惰匹配到
    # 几十行外的 `foreach (…):`，产生假警报。这里做真正的括号配对扫描。
    for kw, endkw in (('if', 'endif'), ('while', 'endwhile'),
                      ('for', 'endfor'), ('foreach', 'endforeach')):
        opens = count_alternative_syntax(code, kw)
        closes = len(re.findall(r'\b%s\s*;' % endkw, code))
        if opens != closes:
            fail('PHP', '%s: %s(…):…%s; 不匹配（%d 开 / %d 闭）'
                 % (path.name, kw, endkw, opens, closes))


def count_alternative_syntax(code, keyword):
    """数 `keyword (…):` 的出现次数——即替代语法，而不是 `{}` 语法。"""
    found = 0
    for m in re.finditer(r'\b%s\b' % keyword, code):
        i = m.end()
        while i < len(code) and code[i] in ' \t\r\n':
            i += 1
        if i >= len(code) or code[i] != '(':
            continue
        depth = 0
        while i < len(code):
            if code[i] == '(':
                depth += 1
            elif code[i] == ')':
                depth -= 1
                if depth == 0:
                    i += 1
                    break
            i += 1
        while i < len(code) and code[i] in ' \t\r\n':
            i += 1
        # else if / else: 走的是 elseif 分支，这里只认紧跟右括号的那个冒号
        if i < len(code) and code[i] == ':':
            found += 1
    return found


# ---------------------------------------------------------------- 2. HTML 标签

TAG = re.compile(r'<(/?)([A-Za-z][\w:-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(/?)>', re.S)


def strip_php_tags(text):
    """把 PHP 块换成空格（保留换行以便报行号），只留下模板里的静态 HTML。"""
    def blank(m):
        return re.sub(r'[^\n]', ' ', m.group(0))
    return re.sub(r'<\?(?:php\b|=)?.*?(?:\?>|$)', blank, text, flags=re.S | re.I)


# html / body 是跨文件闭合的：header.php 开、footer.php 关。
# 单文件平衡检查必然报错，所以跳过，交给 check_document_shell() 专门核对。
CROSS_FILE = {'html', 'body'}


def check_html_balance(path, text):
    html = strip_php_tags(text)
    stack = []
    for m in TAG.finditer(html):
        closing, name, _attrs, self_close = m.groups()
        name = name.lower()
        if name in CROSS_FILE:
            continue
        line = html.count('\n', 0, m.start()) + 1
        if self_close == '/' or name in VOID:
            continue
        if not closing:
            stack.append((name, line))
        else:
            if not stack:
                fail('HTML', '%s:%d 多出一个 </%s>' % (path.name, line, name))
                continue
            # 允许中间有可选块被 PHP 条件跳过，向上找最近的同名标签
            for i in range(len(stack) - 1, -1, -1):
                if stack[i][0] == name:
                    if i != len(stack) - 1:
                        inner = ', '.join(t for t, _ in stack[i + 1:])
                        notes.append('%s:%d </%s> 跨过了未闭合的 %s'
                                     % (path.name, line, name, inner))
                    del stack[i:]
                    break
            else:
                fail('HTML', '%s:%d 孤立的 </%s>' % (path.name, line, name))
    for name, line in stack:
        fail('HTML', '%s:%d <%s> 没有闭合' % (path.name, line, name))


def check_document_shell(files):
    """<html>/<body> 必须由 header.php 开、footer.php 关，且各只有一次。"""
    by_name = {path.name: strip_php_tags(text) for path, text in files}

    def count(name, tag, closing):
        text = by_name.get(name, '')
        return len(re.findall(r'<%s%s\b' % ('/' if closing else '', tag), text, re.I))

    for name in ('header.php', 'footer.php'):
        if name not in by_name:
            fail('HTML', '缺少 public/%s' % name)
            return

    for tag in ('html', 'body'):
        opened = count('header.php', tag, False)
        closed = count('footer.php', tag, True)
        if opened != 1 or closed != 1:
            fail('HTML', '<%s> 应当由 header.php 开一次、footer.php 关一次，实际是 %d 开 / %d 闭'
                 % (tag, opened, closed))

        # 其余模板一个都不能碰，否则页面里会出现两层 <body>
        for name, text in sorted(by_name.items()):
            if name in ('header.php', 'footer.php'):
                continue
            stray = len(re.findall(r'</?%s\b' % tag, text, re.I))
            if stray:
                fail('HTML', '%s 里出现了 %d 个 <%s> 标签，文档外壳只能由 header/footer 负责'
                     % (name, stray, tag))


# ---------------------------------------------------------------- 3. 页面骨架

def check_skeleton(path, text):
    if "need('public/header.php')" not in text and 'need("public/header.php")' not in text:
        fail('骨架', '%s: 没有 need public/header.php' % path.name)
    if "need('public/footer.php')" not in text and 'need("public/footer.php")' not in text:
        fail('骨架', '%s: 没有 need public/footer.php' % path.name)

    classes = set()
    for m in re.finditer(r'''class\s*=\s*(["'])(.*?)\1''', text, re.S):
        literal = re.sub(r'<\?.*?\?>', ' ', m.group(2))
        classes.update(literal.replace(',', ' ').split())

    for required in ('container', 'grid-layout', 'main-content'):
        if required not in classes:
            fail('骨架', '%s: 缺少 .%s' % (path.name, required))

    if '__TYPECHO_ROOT_DIR__' not in text:
        fail('骨架', '%s: 缺少直接访问防护' % path.name)


# ---------------------------------------------------------------- 4. 唯一钩子

HOOKS = {
    'id="noline-progress"': 'id',
    'id="topnav"': 'id',
    'id="nav-scrim"': 'id',
    'id="nav-toggle"': 'id',
    'id="nav-search-input"': 'id',
    'id="nav-percent"': 'id',
    # 分类条的三件套：滚动行、右端那颗「更多」、它掀开的空面板。
    # 顶栏的分类下拉已经删了，全站唯一的分类导航就是这条带。
    'id="feed-cats-scroll"': 'id',
    'id="feed-cats-more"': 'id',
    'id="feed-cats-pop"': 'id',
    # 目录卡只在 is('single') 的分支里输出，但 id 字面量在 sidebar.php 里只有一份
    'id="sidebar-toc"': 'id',
    'id="toc-content"': 'id',
    'id="toc-list"': 'id',
    # 只在 public/feed-status.php 里；index/archive 都 need 同一个 partial，
    # 而这里按文件计数，不是按出现次数
    'id="feed-status"': 'id',
    # 汉堡和 .icon-btn 共用一个 class 属性，写成 class="…" 就匹配不上了，只查裸类名
    'mobile-menu-btn': 'class',
}


def check_unique_hooks(files):
    counts = {k: [] for k in HOOKS}
    for path, text in files:
        for hook in HOOKS:
            if hook in text:
                counts[hook].append(path.name)
    for hook, where in sorted(counts.items()):
        if len(where) == 0:
            fail('钩子', '%s 在整个主题里找不到——main.js 会拿不到元素' % hook)
        elif len(where) > 1:
            fail('钩子', '%s 出现了 %d 次（%s），getElementById 只会拿到第一个'
                 % (hook, len(where), ', '.join(where)))


# ---------------------------------------------------------------- 5. 图标解析

def parse_icon_names(path):
    text = path.read_text(encoding='utf-8')
    return set(re.findall(r"""^\s*'([\w-]+)'\s*=>\s*array\(""", text, re.M))


def check_icons(theme_files, icons_path):
    available = parse_icon_names(icons_path)
    if not available:
        fail('图标', 'icons.php 里一个图标都没解析出来')
        return

    wanted = {}

    literal = re.compile(r"""noline_icon\(\s*'([\w-]+)'""")
    for path, text in theme_files:
        for m in literal.finditer(text):
            wanted.setdefault(m.group(1), set()).add(path.name)

    # noline_nav_icon() 里的所有字面量。映射键是页面 slug 不是图标名，要减掉；
    # 兜底值写在三元表达式里，只匹配 `return '…'` 会漏。
    funcs = (ROOT / 'functions.php').read_text(encoding='utf-8')
    fn = re.search(r'function noline_nav_icon.*?\n\}', funcs, re.S)
    if not fn:
        fail('图标', 'functions.php 里找不到 noline_nav_icon()')
        return
    body = fn.group(0)
    # 两级映射：slugMap 是 'slug' => '图标'，titleMap 是 '图标' => array(中文关键词…)。
    # 关键词是标题词不是图标名，不能再像旧版那样把函数里所有字面量都当图标收。
    icons = set()
    for m in re.finditer(r"""'([\w-]+)'\s*=>\s*'([\w-]+)'""", body):
        icons.add(m.group(2))
    for m in re.finditer(r"""'([\w-]+)'\s*=>\s*array\(""", body):
        icons.add(m.group(1))
    for m in re.finditer(r"""return\s+'([\w-]+)'""", body):
        icons.add(m.group(1))
    for name in icons:
        wanted.setdefault(name, set()).add('noline_nav_icon()')

    missing = sorted(k for k in wanted if k not in available)
    if missing:
        for name in missing:
            fail('图标', "%s 在 icons.php 里不存在（被 %s 引用）"
                 % (name, ', '.join(sorted(wanted[name]))))

    unused = sorted(available - set(wanted))
    if unused:
        notes.append('icons.php 里没有被任何模板直接引用的图标: %s'
                     % ', '.join(unused))

    print('图标: icons.php 定义 %d 个, 模板与映射表引用 %d 个'
          % (len(available), len(wanted)))


# ---------------------------------------------------------------- 6. 注释无关的调用视图

def code_view(text):
    """挖空 PHP 注释和 HTML 注释，只留下会真正执行的调用。"""
    text = blank_php_comments(text)
    return re.sub(r'<!--.*?-->', lambda m: re.sub(r'[^\n]', ' ', m.group(0)),
                  text, flags=re.S)


# ---------------------------------------------------------------- 7. 死函数与重复声明

# Typecho 核心自己会调，主题源码里不会出现调用点
CORE_CALLBACKS = {'themeConfig', 'themeInit'}


def check_dead_functions(files):
    funcs_src = (ROOT / 'functions.php').read_text(encoding='utf-8')
    defined = re.findall(r'^function\s+(\w+)', funcs_src, re.M)

    # 重复定义会在线上直接抛 Cannot redeclare 致命错误，PHP 语法本身不报错，
    # 静态检查是唯一能在上传前拦住它的地方。范围覆盖全部模板，不只 functions.php。
    seen = {}
    for path, text in files:
        for m in re.finditer(r'^function\s+(\w+)', text, re.M):
            seen.setdefault(m.group(1), []).append(path.name)

    dupes = sorted(n for n, where in seen.items() if len(where) > 1)
    for name in dupes:
        fail('重复定义', '%s() 被声明了 %d 次: %s'
             % (name, len(seen[name]), ', '.join(seen[name])))

    # 用挖空注释之后的文本统计，否则注释里提到某个函数名就会被当成真的调用
    corpus = [code_view(text) for _path, text in files]

    dead = []
    for name in defined:
        if name in CORE_CALLBACKS:
            continue
        hits = 0
        for text in corpus:
            for m in re.finditer(r'\b%s\b' % re.escape(name), text):
                # 跳过定义行本身
                line_start = text.rfind('\n', 0, m.start()) + 1
                if text[line_start:m.start()].rstrip().endswith('function'):
                    continue
                hits += 1
        if hits == 0:
            dead.append(name)

    for name in dead:
        fail('死代码', 'functions.php 定义了 %s() 但没有任何地方调用' % name)

    print('函数: functions.php 定义 %d 个（含 %d 个核心回调），死函数 %d 个，重复声明 %d 个'
          % (len(defined), len(CORE_CALLBACKS & set(defined)), len(dead), len(dupes)))


# ---------------------------------------------------------------- main

def main():
    php_files = sorted(
        p for p in ROOT.rglob('*.php')
        # 仓库布局下主题根就是仓库根，plugins/ 里的插件不是主题模板，别混进来
        if not any(x in p.parts for x in ('_trash', '_preview', '_dev', 'plugins'))
    )
    files = [(p, p.read_text(encoding='utf-8')) for p in php_files]

    print('扫描 %d 个 PHP 文件\n' % len(files))

    for path, text in files:
        check_php_balance(path, text)
        check_html_balance(path, text)

    check_document_shell(files)

    for name in PAGE_TEMPLATES:
        path = ROOT / name
        if not path.is_file():
            fail('骨架', '缺少页面模板 %s' % name)
            continue
        check_skeleton(path, code_view(path.read_text(encoding='utf-8')))

    check_unique_hooks(files)
    check_icons(files, ROOT / 'public' / 'icons.php')
    check_dead_functions(files)

    # 所有页面模板都必须加载右栏，两列 grid 才不会空出一整列
    for name in PAGE_TEMPLATES:
        path = ROOT / name
        if not path.is_file():
            continue
        if not re.search(r"""need\(\s*['"]public/sidebar\.php['"]\s*\)""",
                         code_view(path.read_text(encoding='utf-8'))):
            fail('骨架', '%s 没有加载右侧栏，两列 grid 会空出一整列' % name)

    if notes:
        print('\n=== 提示 (%d) ===' % len(notes))
        for note in notes:
            print('  ' + note)

    if errors:
        print('\n=== 失败 (%d) ===' % len(errors))
        for err in errors:
            print('  ' + err)
        return 1

    print('\nOK: PHP 平衡、HTML 闭合、文档外壳、页面骨架、唯一钩子、图标引用、无死函数、无重复声明 全部通过')
    return 0


if __name__ == '__main__':
    sys.exit(main())
