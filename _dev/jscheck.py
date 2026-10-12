#!/usr/bin/env python3
"""
js/main.js 的静态验证。

本机没有 node，也没有可用的浏览器（file:// 和本地 HTTP 服务都被安全策略挡了），
所以 main.js 一行都跑不起来。这里退而求其次，做三件能被静态证明的事：

1. 词法平衡——括号 / 圆括号 / 方括号在剥掉字符串和注释之后必须配平
2. 引用完整——每个被调用的函数名都必须有定义，或者在浏览器全局白名单里
3. 钩子对得上——这是最关键的一组。main.js 里所有 getElementById / querySelector
   用到的 #id 和 .class，必须真的存在于模板里（或是 JS 自己创建的）。
   重构最容易出的事故就是改了 HTML 忘了改 JS，或者反过来，而这类错误
   不会报错，只会让功能静默失效。

顺带检查两份样式表的花括号平衡和 @media 嵌套。
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MAIN_JS = ROOT / 'js' / 'main.js'
STYLESHEETS = [ROOT / 'css' / 'design.css', ROOT / 'css' / 'article.css']

errors = []


def fail(group, message):
    errors.append('[%s] %s' % (group, message))


PHP_FILES = sorted(
    p for p in ROOT.rglob('*.php')
    # 仓库布局下主题根就是仓库根，plugins/ 里的插件不是主题模板，别混进来
    if not any(x in p.parts for x in ('_trash', '_preview', '_dev', 'plugins'))
)


# ---------------------------------------------------------------- JS 词法

def tokenize_js(src):
    """把 JS 切成 (kind, value) 序列。kind ∈ {code, string, comment, regex}。"""
    tokens = []
    i = 0
    n = len(src)
    # 正则字面量只可能出现在这些 token 之后，否则 / 是除号
    value_ctx = re.compile(r'(?:^|[=(,:\[!&|?{};+]|\breturn\b|\btypeof\b)\s*$', re.S)

    while i < n:
        c = src[i]
        two = src[i:i + 2]

        if two == '//':
            j = src.find('\n', i)
            j = n if j < 0 else j
            tokens.append(('comment', src[i:j]))
            i = j
            continue

        if two == '/*':
            j = src.find('*/', i + 2)
            j = n if j < 0 else j + 2
            tokens.append(('comment', src[i:j]))
            i = j
            continue

        if c in '\'"`':
            quote = c
            j = i + 1
            while j < n:
                if src[j] == '\\':
                    j += 2
                    continue
                if src[j] == quote:
                    j += 1
                    break
                j += 1
            tokens.append(('string', src[i + 1:j - 1]))
            i = j
            continue

        if c == '/' and value_ctx.search(''.join(t[1] for t in tokens[-1:]) if tokens else ''):
            j = i + 1
            in_class = False
            while j < n:
                if src[j] == '\\':
                    j += 2
                    continue
                if src[j] == '[':
                    in_class = True
                elif src[j] == ']':
                    in_class = False
                elif src[j] == '/' and not in_class:
                    j += 1
                    break
                elif src[j] == '\n':
                    break
                j += 1
            tokens.append(('regex', src[i:j]))
            i = j
            continue

        tokens.append(('code', c))
        i += 1

    return tokens


def check_balance(name, src):
    code = ''.join(v for k, v in tokenize_js(src) if k == 'code')
    pairs = {'{': '}', '(': ')', '[': ']'}
    stack = []
    line = 1
    for ch in code:
        if ch == '\n':
            line += 1
        elif ch in pairs:
            stack.append((ch, line))
        elif ch in pairs.values():
            if not stack:
                fail('JS', '%s:%d 多出一个 %s' % (name, line, ch))
            else:
                open_ch, open_line = stack.pop()
                if pairs[open_ch] != ch:
                    fail('JS', '%s:%d 的 %s 与 %d 行的 %s 不匹配'
                         % (name, line, ch, open_line, open_ch))
    for open_ch, open_line in stack:
        fail('JS', '%s:%d %s 没有闭合' % (name, open_line, open_ch))


# ---------------------------------------------------------------- 引用完整性

BROWSER_GLOBALS = {
    'window', 'document', 'location', 'navigator', 'console', 'setTimeout',
    'clearTimeout', 'setInterval', 'clearInterval', 'requestAnimationFrame',
    'cancelAnimationFrame', 'localStorage', 'sessionStorage', 'matchMedia',
    'Array', 'Object', 'String', 'Number', 'Boolean', 'JSON', 'Math', 'Date',
    'Promise', 'Error', 'RegExp', 'parseInt', 'parseFloat', 'isNaN',
    'encodeURIComponent', 'decodeURIComponent', 'getComputedStyle',
    # 自动加载要用到的三个：解析相对地址 / 抓下一页 / 把响应当文档解析
    'URL', 'fetch', 'DOMParser',
}


def check_references(name, src):
    tokens = tokenize_js(src)
    code = ''.join(v for k, v in tokens if k == 'code')

    declared = set(re.findall(r'\bfunction\s+([A-Za-z_$][\w$]*)', code))
    declared |= set(re.findall(r'\bvar\s+([A-Za-z_$][\w$]*)', code))
    # 函数参数
    for m in re.finditer(r'\bfunction\s*[A-Za-z_$\w]*\s*\(([^)]*)\)', code):
        for arg in m.group(1).split(','):
            arg = arg.strip()
            if arg:
                declared.add(arg)
    # 箭头函数与 catch(e) 之类
    for m in re.finditer(r'\bcatch\s*\(\s*([A-Za-z_$][\w$]*)', code):
        declared.add(m.group(1))

    # 只检查「裸调用」：前面不是 . 也不是函数声明关键字的 IDENT(
    calls = set()
    for m in re.finditer(r'(?<![.\w$])([A-Za-z_$][\w$]*)\s*\(', code):
        calls.add(m.group(1))

    keywords = {
        'if', 'for', 'while', 'switch', 'catch', 'return', 'typeof', 'function',
        'new', 'delete', 'void', 'do', 'else', 'try', 'finally', 'throw', 'in',
        'instanceof', 'case',
    }

    undefined = sorted(c for c in calls
                       if c not in declared and c not in BROWSER_GLOBALS and c not in keywords)
    for c in undefined:
        fail('JS', '%s 调用了未定义的函数 %s()' % (name, c))

    # 把函数当回调传出去（requestAnimationFrame(reveal)）时没有括号，
    # 所以不能只找 NAME( ——挖掉声明本身，名字还独立出现过就算用到了
    without_decl = re.sub(r'\bfunction\s+[A-Za-z_$][\w$]*', 'function ', code)
    for d in sorted(declared):
        if not re.search(r'\bfunction\s+%s\b' % re.escape(d), code):
            continue
        if not re.search(r'(?<![.\w$])%s(?![\w$])' % re.escape(d), without_decl):
            fail('JS', '%s 定义了 %s() 但从未使用' % (name, d))


# ---------------------------------------------------------------- 钩子对得上

# Typecho 核心自己渲染出来的类，模板源码里当然没有。
# 和 classcheck.py 的 CORE_RENDERED 是同一批东西，两边都得认。
CORE_CLASSES = {'page-navigator', 'next'}


def conditional_class_words():
    """class="…" 属性里那些 <?php … ?> 片段拼出来的类名。

    常见形态是 class="feed-cat<?php if (…): ?> active<?php endif; ?>"：整条属性
    用一个 [^"']* 去扫，会在 PHP 片段里第一个单引号处断掉，于是 active 这种
    「只在条件里出现」的类名看着像模板里没有。这里把属性内的 PHP 片段单独拆开，
    连引号字符串里的词一起收出来。
    """
    words = set()
    text = '\n'.join(p.read_text(encoding='utf-8') for p in PHP_FILES)
    for attr in re.finditer(r'''class\s*=\s*(["'])(?:(?!\1).)*\1''', text, re.S):
        body = attr.group(0)
        # 属性里 PHP 片段之外的字面量部分：class="feed-cat<?php … ?> active<?php … ?>"
        # 里的 feed-cat 和 active 都是这样落地的
        plain = re.sub(r'<\?.*?\?>', ' ', body, flags=re.S)
        words.update(re.findall(r'[A-Za-z_][\w-]*', plain))
        # 片段内部再用 echo 'a b' 拼的类名
        for bit in re.findall(r'<\?php.*?\?>', body, flags=re.S):
            for lit in re.findall(r'''(['"])((?:(?!\1).)*)\1''', bit, flags=re.S):
                words.update(lit[1].replace('.', ' ').replace(',', ' ').split())
    return words


CONDITIONAL_CLASSES = conditional_class_words()


def markup_corpus():
    """所有模板的源码拼在一起，加上 JS 自己会创建的类名。"""
    text = '\n'.join(p.read_text(encoding='utf-8') for p in PHP_FILES)
    # main.js 运行时凭空造出来的，模板里当然没有
    js_created = {
        'code-block', 'btn-copy-code', 'is-open', 'is-done', 'is-active',
        'is-loading', 'is-leaving', 'hljs', 'is-clamped',
        # 滚动进场 + 骨架屏 + 灯箱：main.js 挂上去/凭空造的
        'reveal', 'is-visible',
        'feed-skeleton', 'sk-head', 'sk-avatar', 'sk-line', 'sk-line-sm', 'sk-line-70',
        'lightbox-open',
        # 目录：列表项由 initToc() 从正文标题现搭，模板里只有空的那层 <ol>
        'toc-link', 'toc-item', 'toc-child',
        # 分类条「更多」面板：每一格由 initFeedCats() 从看得见的药丸现克隆，
        # 模板里只有那个空壳 .feed-cats-pop
        'feed-cats-pop-item', 'feed-cats-pop-count',
        # body 上的三档状态开关，是 --nav-* 的扳机：has-hero / hero-cover 由 header.php
        # 拼进 $bodyClass 再整体 echo，nav-fixed 由 main.js 按滚动位置挂撤。
        # 三者都不会以 class="…" 字面量出现在模板源码里。
        'has-hero', 'hero-cover', 'nav-fixed',
    }
    return text, js_created


def check_hooks(src):
    markup, js_created = markup_corpus()

    id_pat = re.compile(r"""getElementById\(\s*'([\w-]+)'\s*\)""")
    sel_pat = re.compile(r"""querySelector(?:All)?\(\s*'([^']+)'\s*\)""")

    ids = set(id_pat.findall(src))
    for m in sel_pat.finditer(src):
        for part in re.findall(r'#([\w-]+)', m.group(1)):
            ids.add(part)

    for element_id in sorted(ids):
        if 'id="%s"' % element_id not in markup:
            fail('钩子', 'main.js 找 #%s，但没有任何模板输出这个 id' % element_id)

    classes = set()
    for m in sel_pat.finditer(src):
        for part in re.findall(r'\.([\w-]+)', m.group(1)):
            classes.add(part)

    for cls in sorted(classes):
        if cls in js_created or cls in CORE_CLASSES:
            continue
        # classList.toggle/add/remove 里的名字单独查
        if ('class="%s' % cls) in markup or re.search(r'class\s*=\s*["\'][^"\']*\b%s\b' % cls, markup):
            continue
        if re.search(r"""['"]%s['"]""" % re.escape(cls), markup):
            continue
        if cls in CONDITIONAL_CLASSES:
            continue
        fail('钩子', 'main.js 选 .%s，但没有任何模板输出这个 class' % cls)

    # classList 操作的字符串
    cl_pat = re.compile(r"""classList\.(?:add|remove|toggle|contains)\(\s*'([\w-]+)'""")
    for cls in sorted(set(cl_pat.findall(src))):
        if cls in js_created or cls in CORE_CLASSES:
            continue
        if re.search(r'class\s*=\s*["\'][^"\']*\b%s\b' % re.escape(cls), markup):
            continue
        # 条件拼进 class 属性的写法：class="feed-cat<?php if (…): ?> active<?php endif; ?>"。
        # 属性里夹了 <?php … ?>，上面那条 [^"']* 会在 PHP 里第一个单引号处断掉，
        # 所以再认一遍这些片段里的类名——和 classcheck.py 的 PHP_EMITTED 是同一件事。
        if cls in CONDITIONAL_CLASSES:
            continue
        fail('钩子', "main.js 操作 class '%s'，但模板里没有它，样式表也未必有" % cls)

    # localStorage 的键必须在 header.php 的防闪烁脚本里也读一遍，否则首帧会闪。
    # sessionStorage 的 noline-nav 不算——那是 main.js 在两个页面之间自己传的接力棒。
    # main.js 统一走 readStore/writeStore/dropStore(store, key)，所以只认这一个形状。
    constants = dict(re.findall(r"""var\s+(\w+)\s*=\s*'([\w-]+)'\s*;""", src))
    local_keys = {constants.get(k, k)
                  for k in re.findall(r'window\.localStorage\s*,\s*(\w+)', src)}

    inline = (ROOT / 'public' / 'header.php').read_text(encoding='utf-8')
    for key in sorted(local_keys):
        if key not in inline:
            fail('钩子', "main.js 用 localStorage 键 '%s'，但 header.php 的防闪烁脚本没读它" % key)
    if not local_keys:
        fail('钩子', '没能从 main.js 里解析出任何 localStorage 键，检查逻辑本身失效了')

    print('钩子: main.js 引用 %d 个 id、%d 个 class' % (len(ids), len(classes)))


# ---------------------------------------------------------------- CSS 结构

def check_css(path):
    src = path.read_text(encoding='utf-8')
    body = re.sub(r'/\*.*?\*/', lambda m: re.sub(r'[^\n]', ' ', m.group(0)), src, flags=re.S)

    depth = 0
    line = 1
    at_depth = []
    for ch in body:
        if ch == '\n':
            line += 1
        elif ch == '{':
            depth += 1
            at_depth.append(line)
        elif ch == '}':
            depth -= 1
            if depth < 0:
                fail('CSS', '%s:%d 多出一个 }' % (path.name, line))
                depth = 0
            elif at_depth:
                at_depth.pop()

    if depth != 0:
        fail('CSS', '%s: 花括号差 %d 个没闭合' % (path.name, depth))
        for ln in at_depth:
            fail('CSS', '%s:%d 这个 { 没有对应的 }' % (path.name, ln))

    print('CSS: %s 花括号平衡，最深 %d 层' % (path.name, max_depth(body)))


def max_depth(body):
    depth = best = 0
    for ch in body:
        if ch == '{':
            depth += 1
            best = max(best, depth)
        elif ch == '}':
            depth -= 1
    return best


# ---------------------------------------------------------------- main

def main():
    if not MAIN_JS.is_file():
        print('找不到 js/main.js')
        return 1

    src = MAIN_JS.read_text(encoding='utf-8')
    print('验证 js/main.js（%d 行）\n' % (src.count('\n') + 1))

    check_balance('main.js', src)
    check_references('main.js', src)
    check_hooks(src)
    print()
    for path in STYLESHEETS:
        check_css(path)

    if errors:
        print('\n=== 失败 (%d) ===' % len(errors))
        for err in errors:
            print('  ' + err)
        return 1

    print('\nOK: main.js 括号平衡、无未定义引用、钩子与模板一一对应；CSS 花括号平衡')
    return 0


if __name__ == '__main__':
    sys.exit(main())
