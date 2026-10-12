#!/usr/bin/env python3
"""把 css/*.css 压成同目录的 .min.css，并证明压缩没有改变语义。

用法：python3 _dev/minify.py            # 压 design.css / article.css
     python3 _dev/minify.py a.css     # 压指定文件，输出 a.min.css

为什么不做更狠的压缩（合并声明、去前导零、色值缩写、选择器重排）：
那些都要求真正解析 CSS，一旦解析器对某个写法理解错了，站点就会以「样式莫名其妙掉了」
的形式表现出来，而本机没有 PHP、也没法跑浏览器回归。收益大概再多 10%，不值得拿正确性换。

真正的字节大头是注释——design.css 三分之一以上是中文注释和分节横幅，删掉就够省很多。

验证方式（不是「我自己压的自己信」）：
  1) 输出里不允许残留注释；
  2) 源文件去注释后的「非空白字符序列」必须和输出完全一致 —— 吃掉任何一个字母都会失败；
  3) 引号里的字符串（url、content）必须逐条一模一样，包括其中的空格。
这三条合起来说明：变换只删了空白和注释，别的什么都没动。
"""
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
THEME = HERE.parent
DEFAULTS = ['css/design.css', 'css/article.css']

QUOTES = '"\''
BREAKERS = '{};'


def scan(text):
    """切成 (kind, value) 序列：comment / string / ws / other。

    字符串和注释必须整体成块，后面的处理才不会拿空白规则去动 url()、content 里的内容。
    """
    out = []
    i, n = 0, len(text)
    while i < n:
        ch = text[i]
        if text.startswith('/*', i):
            end = text.find('*/', i + 2)
            if end == -1:
                raise SystemExit('FAIL: 有未闭合的注释（从 %d 字符开始）' % i)
            out.append(('comment', text[i:end + 2]))
            i = end + 2
        elif ch in QUOTES:
            j = i + 1
            while j < n and text[j] != ch:
                j += 2 if text[j] == '\\' else 1
            if j >= n:
                raise SystemExit('FAIL: 有未闭合的字符串（从 %d 字符开始）' % i)
            out.append(('string', text[i:j + 1]))
            i = j + 1
        elif ch.isspace():
            j = i
            while j < n and text[j].isspace():
                j += 1
            out.append(('ws', text[i:j]))
            i = j
        else:
            out.append(('other', ch))
            i += 1
    return out


def minimize(text):
    tokens = scan(text)
    parts = []
    for kind, value in tokens:
        if kind == 'comment':
            # 注释整块丢弃；前后紧邻的空白已经在 ws 分支里处理，不会粘连两个词
            continue
        if kind == 'ws':
            parts.append(' ')
        elif kind == 'string':
            parts.append(value)
        else:
            parts.append(value)

    # 挨着 { } ; 的空格可以省，其他一律保留至少一个空格：
    # .a + .b 里的空格本身就是相邻选择器组合符，删掉就变成另一种选择了。
    result = []
    for token in parts:
        if token == ' ':
            if result and (result[-1][-1] in BREAKERS):
                continue
            result.append(token)
        else:
            if result and result[-1] == ' ' and token[0] in BREAKERS:
                result[-1] = token
            else:
                result.append(token)
    return ''.join(result).strip()


def non_ws(text):
    """去掉注释和空白之后的字符序列——两个文件这个必须一模一样。"""
    return ''.join(v for k, v in scan(text) if k not in ('comment', 'ws'))


def strings(text):
    return [v for k, v in scan(text) if k == 'string']


def verify(src, out):
    """out 必须是 src 去掉注释和多余空白后的结果，否则直接失败。"""
    if any(k == 'comment' for k, _ in scan(out)):
        return '输出里还残留注释'

    src_code = ''.join(v for k, v in scan(src) if k != 'comment')
    if non_ws(src_code) != non_ws(out):
        a, b = non_ws(src_code), non_ws(out)
        pos = next((i for i in range(min(len(a), len(b))) if a[i] != b[i]),
                   min(len(a), len(b)))
        return '第 %d 个非空白字符之后不一致：源 %r / 压后 %r' % (pos, a[pos:pos + 40], b[pos:pos + 40])

    if strings(src_code) != strings(out):
        return '字符串字面量被改动了（url 或 content 里的空格不能动）'

    for opener, closer in (('{', '}'), ('(', ')')):
        if out.count(opener) != out.count(closer):
            return '%s 和 %s 数量不相等' % (opener, closer)
    return None


def build_one(rel):
    src_path = THEME / rel
    if not src_path.is_file():
        raise SystemExit('FAIL: 找不到 %s' % rel)

    text = src_path.read_text(encoding='utf-8')
    out = minimize(text)

    problem = verify(text, out)
    if problem:
        raise SystemExit('FAIL: %s —— %s' % (rel, problem))

    dst = src_path.with_name(src_path.stem + '.min.css')
    dst.write_text(out, encoding='utf-8')
    print('%-18s %6.1f KB → %6.1f KB（省 %4.0f%%）'
          % (rel, len(text.encode()) / 1024, len(out.encode()) / 1024,
             100 - 100 * len(out.encode()) / len(text.encode())))
    return dst


def main():
    targets = sys.argv[1:] or DEFAULTS
    for rel in targets:
        build_one(rel)
    print('验证通过：只删了注释和空白，非空白字符与字符串字面量逐字节一致。')
    print('提醒：发布前 build.py 会检查 .min.css 不比源文件旧，改过 css 记得重跑这个脚本。')


if __name__ == '__main__':
    main()
