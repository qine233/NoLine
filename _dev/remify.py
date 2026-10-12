#!/usr/bin/env python3
"""一次性把 design.css / article.css 里「字号与留白」类的 px 换成 rem（基准 16px）。

只碰 font-size / padding / margin / gap 四类——密度旋钮该管的就是文字和留白。
骨架尺寸（width / height / top / left / border / border-radius / box-shadow）留 px：
它们描述的是物理几何，不该跟着根字号漂。

跳过两种块：
  * 选择器以 :root 开头的令牌块（html 的 font-size 写成 rem 会回指自身初始值，旋钮就废了）
  * ::before / ::after 伪元素（那里面 top/margin-top 和 width/height 是配套的几何，
    只换算 margin 会让装饰条偏出中心）
"""
import re
import sys
import pathlib

BASE = 16.0
ROOT = pathlib.Path(__file__).resolve().parent.parent
FILES = [ROOT / 'css' / 'design.css', ROOT / 'css' / 'article.css']

PROPS = {
    'font-size',
    'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'gap', 'row-gap', 'column-gap',
}

PX = re.compile(r'(?<![\w.])(-?\d*\.?\d+)px\b')


def fmt(value):
    s = ('%.5f' % value).rstrip('0').rstrip('.')
    if s in ('', '-0'):
        return '0'
    if s.startswith('0.'):
        return s[1:]
    if s.startswith('-0.'):
        return '-.' + s[3:]
    return s


def to_rem(match):
    num = float(match.group(1))
    return '0' if num == 0 else fmt(num / BASE) + 'rem'


def split_decls(body):
    """按深度 0 的分号切开声明块，保留分隔符位置以便原样拼回。"""
    parts, depth, cur, spans = [], 0, [], []
    for i, ch in enumerate(body):
        if ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        if ch == ';' and depth == 0:
            parts.append(''.join(cur))
            spans.append(i)
            cur = []
            continue
        cur.append(ch)
    parts.append(''.join(cur))
    spans.append(None)
    return parts, spans


def conv_body(body, stats):
    parts, spans = split_decls(body)
    out = []
    for part in parts:
        if ':' in part:
            name, _, value = part.partition(':')
            if name.strip() in PROPS:
                new = PX.sub(to_rem, value)
                if new != value:
                    stats[0] += len(PX.findall(value))
                    part = name + ':' + new
        out.append(part)

    text = ''
    for part, span in zip(out, spans):
        text += part
        if span is not None:
            text += ';'
    return text


# 令牌块本身不参与换算：html 的 font-size 写成 rem 会回指自身初始值，旋钮就废了。
# 必须精确匹配——:root[data-theme="dark"] .topbar 这种后代选择器不是令牌块，得照常换算。
TOKEN_BLOCKS = {':root', ':root[data-theme="dark"]'}

COMMENT = re.compile(r'/\*.*?\*/', re.S)


def skip(selector):
    sel = COMMENT.sub(' ', selector).strip()
    return sel in TOKEN_BLOCKS or '::before' in sel or '::after' in sel


def walk(css, stats):
    """扫过整份 CSS，只对声明块（大括号里不含大括号的那种）动手。"""
    out = []
    i = 0
    last = 0
    n = len(css)
    while i < n:
        ch = css[i]
        if ch == '/' and css.startswith('/*', i):
            end = css.find('*/', i + 2)
            i = n if end < 0 else end + 2
            continue
        if ch == '{':
            # 找到与之匹配的 '}'
            depth = 0
            j = i
            while j < n:
                if css[j] == '{':
                    depth += 1
                elif css[j] == '}':
                    depth -= 1
                    if depth == 0:
                        break
                j += 1
            selector = css[last:i]
            body = css[i + 1:j]
            if '{' in body:            # at-rule 或嵌套，递归处理
                out.append(css[last:i + 1])
                inner = walk(body, stats)
                out.append(inner)
                out.append('}')
            else:
                out.append(selector)
                out.append('{')
                out.append(body if skip(selector) else conv_body(body, stats))
                out.append('}')
            i = j + 1
            last = i
            continue
        if ch == '}':
            i += 1
            last = i
            continue
        i += 1
    out.append(css[last:])
    return ''.join(out)


def main():
    total = 0
    for path in FILES:
        src = path.read_text(encoding='utf-8')
        stats = [0]
        dst = walk(src, stats)
        path.write_text(dst, encoding='utf-8')
        print('%s: %d 处 px -> rem' % (path.relative_to(ROOT).as_posix(), stats[0]))
        total += stats[0]
    print('合计 %d 处' % total)
    return 0


if __name__ == '__main__':
    sys.exit(main())
