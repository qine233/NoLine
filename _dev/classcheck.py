#!/usr/bin/env python3
"""
交叉核对：模板里用到的每个 class 是否都在样式表里有对应规则，
以及样式表里定义了哪些 class 没有任何模板在用。

只报事实，不做判断——有些 class 是 JS 运行时加的（is-open / is-loading / active …），
有些是给 Typecho 自己渲染的评论/分页 HTML 用的（comment-* / page-navigator …），
这些在模板源码里扫不到属于正常。
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# JS 在运行时挂上去的状态类。模板里最多只有钩子名，扫不到最终形态。
RUNTIME = {
    'is-open', 'is-loading', 'is-leaving', 'is-active', 'is-done', 'is-collapsed',
    'is-clamped',             # main.js 量过动态卡正文高度之后才挂
    'active', 'code-block', 'hljs',
    'btn-copy-code',          # main.js 包代码块时创建
    'js-theme-toggle',        # 纯 JS 钩子，故意不带样式
    'comment-respond',        # Typecho 评论回复 JS 靠这个 id/class 定位
    # 滚动进场 + 骨架屏：main.js 运行时挂上去/凭空造的，模板里当然没有
    'reveal', 'is-visible',
    'feed-skeleton', 'sk-head', 'sk-avatar', 'sk-line', 'sk-line-sm', 'sk-line-70',
    # 灯箱打开时往 <html> 上挂，锁背景滚动
    'lightbox-open',
    # 目录的三层结构由 main.js 从正文标题现搭，模板里只有那层空 <ol class="toc-list">
    # 目录的三层结构由 main.js 从正文标题现搭，分类条「更多」面板的每一格由它从
    # 现存的药丸克隆：两边模板里都只有空壳。
    'toc-item', 'toc-link', 'toc-child',
    'feed-cats-pop-item', 'feed-cats-pop-count',
    # 顶栏的两档状态：has-hero / hero-cover 由 header.php 拼进 $bodyClass 再整体 echo，
    # nav-fixed 由 main.js 在滚动时挂撤。三者都是 design.css 里 --nav-* 开关的扳机。
    'has-hero', 'hero-cover', 'nav-fixed',
}

# Typecho 核心（评论列表、分页）和 Markdown 解析器（脚注）自己渲染出来的类，
# 不会出现在主题模板的源码里，但必须有样式。
CORE_RENDERED = {
    'comment-author', 'comment-meta', 'comment-content', 'comment-reply',
    'comment-children', 'comment-awaiting-moderation', 'fn',
    'page-navigator', 'current',
    'footnotes', 'footnote-ref',
}

# 由 PHP 拼出来的类名：noline_icon() 的第二个参数，以及 printf 格式串里的字面量。
PHP_EMITTED = {
    'noline-icon', 'icon-moon', 'icon-sun',
    'article-nav-cell', 'article-nav-next', 'article-nav-title', 'article-nav-label',
    # 日历格的条件类：sidebar.php 里拼进 $cell 再整体 echo，class 属性中没有字面量
    'calendar-today', 'calendar-posted',
    # 健康页的配色状态类：$deltaClass() / $badgeClass 返回整串类名，
    # 属性里只剩 <?php echo …?>，扫不到字面量
    'health-badge',
    'is-up', 'is-down', 'is-quiet',
    'is-thin', 'is-normal', 'is-over', 'is-obese',
    # 信息流卡片的左右朝向位：index/archive.php 算成 $flip（' flip' 或 ''）再 echo，
    # class 属性里没有 'flip' 这个字面量
    'flip',
}

# 从 PHP 片段里收割带引号字符串时会顺手捞到的非类名词，比如 $this->is('index')。
NOT_A_CLASS = {
    'index', 'page', 'post', 'single', 'category', 'ENT_QUOTES', 'UTF-8',
    'sidebar', 'header', 'footer', 'comments',
    # feed-cats.php 里 $c['slug'] 的数组键——$this->is('category', $slug) 比的是
    # archiveSlug，跟 class 无关
    'slug',
}

TEMPLATES = sorted(
    p for p in ROOT.rglob('*.php')
    # 仓库布局下主题根就是仓库根；插件的 .nl-* 样式在 Admin.php 自带的 <style> 里，
    # 不在 css/design.css，混进来会被当成「模板在用、样式表没有」
    if not any(x in p.parts for x in ('_trash', '_preview', '_dev', 'plugins'))
)

STYLESHEETS = [ROOT / 'css' / 'design.css', ROOT / 'css' / 'article.css']


def used_classes():
    """从模板里扒出所有出现在 class="…" / class='…' 里的名字。"""
    found = {}
    pat = re.compile(r'''class\s*=\s*(["'])(.*?)\1''', re.S)
    # noline_icon('moon', 'icon-moon') 的第二个实参会被拼进 class，
    # 但调用点周围没有 class= 字样，上面的属性正则会漏掉
    icon_pat = re.compile(r'''noline_icon\(\s*('[^']*'|"[^"]*")\s*,\s*('[^']*'|"[^"]*")''')
    for path in TEMPLATES:
        text = path.read_text(encoding='utf-8')
        for m in icon_pat.finditer(text):
            for name in m.group(2)[1:-1].split():
                found.setdefault(name, set()).add(path.name)
        for m in pat.finditer(text):
            raw = m.group(2)
            # 剥掉 PHP 片段：<?php … ?> 里的东西是条件拼接，单独处理
            php_bits = re.findall(r'<\?php.*?\?>', raw, re.S)
            literal = re.sub(r'<\?.*?\?>', ' ', raw)
            for bit in php_bits:
                # 常见写法：if (…): ?> active<?php endif;  /  echo 'a b'
                for quoted in re.findall(r'''(['"])((?:(?!\1).)*)\1''', bit):
                    literal += ' ' + quoted[1]
            for name in literal.replace('.', ' ').replace(',', ' ').split():
                # post.php 里的 '<div class="article-nav-cell%s">' 是 printf 格式串，
                # 先把占位符剔掉才能认出真正的类名
                name = re.sub(r'%+s|%+d', ' ', name).strip()
                for part in name.split():
                    if re.fullmatch(r'[A-Za-z_][\w-]*', part):
                        found.setdefault(part, set()).add(path.name)
    return found


def defined_classes():
    """从样式表里扒出所有 .class 选择器片段。"""
    defined = set()
    for path in STYLESHEETS:
        text = path.read_text(encoding='utf-8')
        text = re.sub(r'/\*.*?\*/', ' ', text, flags=re.S)
        # 只扫选择器部分：把所有 {…} 声明块挖空
        text = re.sub(r'\{[^{}]*\}', ' {} ', text)
        for m in re.finditer(r'\.(-?[_A-Za-z][\w-]*)', text):
            defined.add(m.group(1))
    return defined


def php_literals():
    """收集所有 PHP 文件里出现过的带引号字符串，用来反向校验白名单没有腐烂。"""
    literals = set()
    pat = re.compile(r'''(['"])((?:(?!\1)[^\\]|\\.)*?)\1''', re.S)
    for path in TEMPLATES:
        for m in pat.finditer(path.read_text(encoding='utf-8')):
            literals.add(m.group(2))
    return literals


def main():
    used = used_classes()
    defined = defined_classes()
    literals = php_literals()

    explained = RUNTIME | CORE_RENDERED | PHP_EMITTED

    missing = sorted(k for k in used
                     if k not in defined and k not in explained and k not in NOT_A_CLASS)
    unused = sorted(k for k in defined if k not in used and k not in explained)

    # 白名单反向校验：PHP_EMITTED 里每个名字必须真的以字面量出现过，
    # 否则说明是改名/删除后忘了同步，白名单会把真正的死样式藏起来。
    stale_whitelist = sorted(n for n in PHP_EMITTED
                             if not any(n in lit for lit in literals))

    print('模板: %d 个, 样式表: %d 个' % (len(TEMPLATES), len(STYLESHEETS)))
    print('模板用到 %d 个 class, 样式表定义 %d 个\n' % (len(used), len(defined)))

    print('=== 模板在用、样式表里没有规则 (%d) ===' % len(missing))
    for name in missing:
        print('  %-28s %s' % (name, ', '.join(sorted(used[name]))))

    print('\n=== 样式表里有、模板和白名单都解释不了 (%d) ===' % len(unused))
    for name in unused:
        print('  %s' % name)

    print('\n=== 白名单里已经没有对应字面量的条目 (%d) ===' % len(stale_whitelist))
    for name in stale_whitelist:
        print('  %s' % name)

    if missing or unused or stale_whitelist:
        print('\nFAIL: %d 个无样式, %d 个疑似死样式, %d 个白名单过期'
              % (len(missing), len(unused), len(stale_whitelist)))
        return 1

    print('\nOK: 双向对得上——模板用到的 class 都有样式，样式表里没有解释不了的死规则')
    return 0


if __name__ == '__main__':
    sys.exit(main())
