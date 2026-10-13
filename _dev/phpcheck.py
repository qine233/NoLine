#!/usr/bin/env python3
"""对 plugins/NolineApi/*.php 做一遍能靠读代码抓到的检查。

本机没有 php，没法 lint，更没法跑起来验证。所以把「语法括号对不齐」「调了不存在的方法」
这类一改就白屏的错误先用 Python 兜住：这两个错了不是报错页面，是整个站点打不开。

检查项：
  1. 括号配对（跳过字符串与注释），行号定位
  2. 文件头：<?php 必须在第 1 个字节、有 namespace、有 __TYPECHO_ROOT_DIR__ 守卫
  3. self::X / 本插件类::X 指向的方法必须存在（跨文件也认）
  4. 私有方法定义了却没人用（漏调用的信号；钩子/入口列在白名单里）
  5. _t('…%d…') 之类带占位符的调用必须传参数，否则 vsprintf 直接抛异常
"""
import re
import sys
from pathlib import Path

DIR = Path(sys.argv[1]) if len(sys.argv) > 1 else next(
    (p for p in (
        # 仓库布局：主题在根，插件在 <根>/plugins/NolineApi
        Path(__file__).resolve().parent.parent / 'plugins' / 'NolineApi',
        # 本地布局：主题和插件是同级目录
        Path(__file__).resolve().parent.parent.parent / 'plugins' / 'NolineApi',
    ) if p.is_dir()),
    Path('plugins/NolineApi'),
)

# 比对主题配置项用，不跟着 argv 走：换目录只是测检查器本身
THEME_DIR = Path(__file__).resolve().parent.parent

# 由 Typecho 核心或别的类直接调用的入口，不做「没人用」判定
ENTRY = {'activate', 'deactivate', 'config', 'personalConfig', 'onRequest',
         'onAdminMenu', 'handle', 'menuLink', 'section', 'act', 'setting',
         'repairMessage', 'repair', 'fields', 'read', 'shape',
         'write', 'formHtml', 'patchFromPost'}

PAIRS = {'(': ')', '[': ']', '{': '}'}
CLOSERS = {v: k for k, v in PAIRS.items()}

problems = []


def fail(msg):
    problems.append(msg)


def strip_code(text):
    """返回只含代码字符的等长字符串（字符串/注释内容换成空格），并记录行数表。

    我们的代码里没有 heredoc，也没有双引号字符串内的 {$var} 插值，
    所以按「引号包住一整段」处理就够用；真用到插值时这里会先炸出来。
    """
    out = []
    i, n = 0, len(text)
    line = 1
    while i < n:
        c = text[i]
        nxt = text[i + 1] if i + 1 < n else ''

        if c == '\n':
            line += 1
            out.append('\n')
            i += 1
            continue

        if c == '/' and nxt == '*':
            end = text.find('*/', i + 2)
            end = n - 1 if end == -1 else end + 1
            for ch in text[i:end]:
                out.append('\n' if ch == '\n' else ' ')
            line += text[i:end].count('\n')
            i = end
            continue

        if c == '/' and nxt == '/':
            end = text.find('\n', i)
            end = n if end == -1 else end
            out.append(' ' * (end - i))
            i = end
            continue

        if c == '#':
            end = text.find('\n', i)
            end = n if end == -1 else end
            out.append(' ' * (end - i))
            i = end
            continue

        if c in ("'", '"'):
            j = i + 1
            while j < n:
                if text[j] == '\\':
                    j += 2
                    continue
                if text[j] == c:
                    break
                if text[j] == '\n':
                    break          # 单行字符串里换行：PHP 会当语法错误，这里也停住
                j += 1
            if j >= n or text[j] != c:
                fail('第 %d 行：字符串引号 %s 没闭合' % (line, c))
                j = min(j, n - 1)
            out.append(' ')
            out.append(' ' * (j - i - 1))
            out.append(' ')
            line += text[i:j + 1].count('\n')
            i = j + 1
            continue

        out.append(c)
        i += 1

    return ''.join(out)


def line_of(text, pos):
    return text.count('\n', 0, pos) + 1


def check_balance(name, raw, code):
    stack = []

    for pos, ch in enumerate(code):
        if ch in PAIRS:
            stack.append((ch, line_of(raw, pos)))
        elif ch in CLOSERS:
            if not stack:
                fail('%s 第 %d 行：多出一个 “%s”' % (name, line_of(raw, pos), ch))
                return
            opener, opened = stack.pop()
            if opener != CLOSERS[ch]:
                fail('%s 第 %d 行的 “%s” 对不上第 %d 行的 “%s”'
                     % (name, line_of(raw, pos), ch, opened, opener))
                return

    if stack:
        opener, opened = stack[0]
        fail('%s 第 %d 行的 “%s” 一直没闭合' % (name, opened, opener))


def collect_methods(code):
    """{类名: {方法名}}。够用的近似：class 块里的 function 名字。"""
    classes = {}
    current = None
    depth = 0
    pending_class = None

    for m in re.finditer(r'\b(class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)|\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)|\{|\}', code):
        token = m.group(0)

        if m.group(1):
            pending_class = m.group(2)
            continue

        if m.group(3):
            if current is not None:
                classes.setdefault(current, set()).add(m.group(3))
            continue

        if token == '{':
            depth += 1
            if depth == 1 and pending_class is not None:
                current = pending_class
                pending_class = None
                classes.setdefault(current, set())
            continue

        if token == '}':
            depth -= 1
            if depth == 0:
                current = None

    return classes


def collect_private(code):
    """私有方法名：用来判断「定义了却没人调」。"""
    out = set()
    for m in re.finditer(r'\bprivate\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)', code):
        out.add(m.group(1))
    return out


def collect_private_members(code):
    """{类名: {私有成员名}}——私有方法和私有常量都算。

    跨类读私有成员在 PHP 里是当场致命错误（Cannot access private ...），
    而插件这几个类互相引用很频繁，「方法存不存在」那关查不出来。
    """
    out = {}
    current = None
    depth = 0
    pending = None

    pattern = (r'\bclass\s+([A-Za-z_][A-Za-z0-9_]*)'
               r'|\b(?:final\s+)?(?:private|protected)\s+(?:static\s+)?(?:function|const)\s+([A-Za-z_][A-Za-z0-9_]*)'
               r'|\{|\}')

    for m in re.finditer(pattern, code):
        token = m.group(0)

        if m.group(1):
            pending = m.group(1)
            continue

        if m.group(2):
            if current is not None:
                out.setdefault(current, set()).add(m.group(2))
            continue

        if token == '{':
            depth += 1
            if depth == 1 and pending is not None:
                current = pending
                pending = None
                out.setdefault(current, set())
            continue

        if token == '}':
            depth -= 1
            if depth == 0:
                current = None

    return out


def tokenize_php(raw):
    """粗粒度 PHP 词法：字符串 / 注释 / 代码三种段，带起始位置。"""
    toks = []
    i, n = 0, len(raw)

    while i < n:
        c = raw[i]

        if raw.startswith('//', i) or raw.startswith('/*', i) or c == '#':
            if raw.startswith('/*', i):
                j = raw.find('*/', i)
                j = n if j < 0 else j + 2
            else:
                j = raw.find('\n', i)
                j = n if j < 0 else j
            toks.append(('cmt', i, raw[i:j]))
            i = j
            continue

        if c in '"\'':
            j = i + 1
            while j < n and raw[j] != c and raw[j] != '\n':
                j += 2 if raw[j] == '\\' else 1
            toks.append(('str', i, raw[i:j + 1]))
            i = j + 1
            continue

        j = i + 1                    # 至少吃一个字符，不然卡在换行上永远不前进
        while (j < n and raw[j] not in '"\'\n#'
               and not raw.startswith('//', j) and not raw.startswith('/*', j)):
            j += 1
        toks.append(('code', i, raw[i:j]))
        i = j

    return toks


def check_quote_nesting(name, raw):
    """字符串里手滑套了同种引号 = 当场致命语法错误，而且括号还是配平的。

    信号很具体：一个字符串段后面紧跟一段「只有一个裸标识符」的代码，再紧跟一个字符串。
    合法的写法中间总有运算符或空白兜着（\'a\' . $b . \'c\'），不会命中这个形状。
    """
    toks = tokenize_php(raw)

    for k in range(len(toks) - 2):
        a, b, c = toks[k], toks[k + 1], toks[k + 2]

        if a[0] == 'str' and b[0] == 'code' and c[0] == 'str' \
                and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', b[2]):
            fail('%s 第 %d 行：字符串里套了同种引号（…%s…），PHP 在前一个引号处就把字符串断掉了，'
                 '后面整段按代码解析——这是致命语法错误，插件一加载就白屏。'
                 '文案里的引号换成双引号或 &quot;' % (name, line_of(raw, a[1]), b[2]))


def check_headers(name, raw):
    if not raw.startswith('<?php'):
        fail('%s：文件头第一个字节必须是 <?php（多了 BOM 或空行的话，插件列表解析不到注释）' % name)
    if 'namespace TypechoPlugin\\NolineApi;' not in raw:
        fail('%s：缺 namespace TypechoPlugin\\NolineApi; ——核心按命名空间找文件，找不到就致命错误' % name)
    if '__TYPECHO_ROOT_DIR__' not in raw.split('class ')[0]:
        fail('%s：class 之前没有 __TYPECHO_ROOT_DIR__ 守卫' % name)


def required_args(literal):
    """文案里到底要几个参数：%s/%d 按出现次数，%1$s 按最大编号，%% 不算。"""
    numbered = [int(n) for n in re.findall(r'%(\d+)\$[sd]', literal)]

    if numbered:
        return max(numbered)

    return len(re.findall(r'(?<!%)%[sd]', literal))


def check_translations(name, code, text):
    """_t('带 %s 的话') 少了参数会在 vsprintf 里抛异常，整页 500。

    字面量只在原文里找（code 把字符串挖空了），位置两边是对齐的，
    所以括号还是按 code 来数——这样字符串里的括号不会骗到计数。
    """
    for m in re.finditer(r"_t\(\s*(')((?:[^'\\]|\\.)*)\1", text):
        if m.start() >= len(code) or code[m.start()] != '_':
            continue                      # 命中在注释或字符串里

        wanted = required_args(m.group(2))

        if wanted == 0:
            continue

        depth = 0
        commas = 0
        end = None

        for pos in range(m.start(), len(code)):
            if code[pos] in '([{':
                depth += 1
            elif code[pos] in ')]}':
                depth -= 1

                if depth == 0:
                    end = pos
                    break
            elif code[pos] == ',' and depth == 1:
                commas += 1

        if end is None:
            fail('%s 第 %d 行：_t() 调用没找到配对的右括号' % (name, text.count('\n', 0, m.start()) + 1))
            continue

        if commas < wanted:
            fail('%s 第 %d 行：_t() 的文案要 %d 个参数，只传了 %d 个，vsprintf 会抛异常「%s…」'
                 % (name, text.count('\n', 0, m.start()) + 1, wanted, commas, m.group(2)[:56]))


def theme_field_specs():
    """解析 Theme.php 里 FIELDS 常量：{键: (kind, label)}。"""
    raw = (DIR / 'Theme.php').read_text(encoding='utf-8')
    start = raw.find('private const FIELDS')

    if start == -1:
        fail('Theme.php 里找不到 private const FIELDS —— 那份「唯一真相」没了')
        return {}

    block = raw[start:raw.find("\n    ];", start)]
    specs = {}

    for m in re.finditer(r"^ {8}'([A-Za-z_]+)' => \[", block, re.M):
        seg = block[m.end():]
        nxt = re.search(r"^ {8}'[A-Za-z_]+' => \[", seg, re.M)
        seg = seg[:nxt.start()] if nxt else seg
        kind = re.search(r"'kind'\s*=>\s*'(\w+)'", seg)
        label = re.search(r"'label'\s*=>\s*'([^']*)'", seg)

        if kind is None or label is None:
            fail('Theme.php 的 %s 缺 kind 或 label' % m.group(1))
            continue

        specs[m.group(1)] = (kind.group(1), label.group(1))

    return specs


def check_theme_fields():
    """插件认的字段必须和主题 themeConfig() 里的一模一样。

    需求原话是「把主题管理里的所有设置开放出来」，所以主题加/删/改名一个配置项时，
    插件这边漏改就是「后台看得到、接口写不进」或者反过来——这类不一致必须当场炸出来。
    """
    functions = THEME_DIR / 'functions.php'

    if not functions.is_file():
        fail('找不到 %s，没法比对主题配置项' % functions)
        return

    specs = theme_field_specs()
    raw = functions.read_text(encoding='utf-8')
    start = raw.find('function themeConfig')

    if start == -1:
        fail('主题 functions.php 里没有 themeConfig()')
        return

    tail = raw[start:]
    end = re.search(r'^}', tail, re.M)
    cfg = tail[:end.start()] if end else tail

    elements = re.findall(r"Form_Element_(\w+)\('([A-Za-z_]+)'", cfg)
    keys = [name for _, name in elements]

    for key in keys:
        if key not in specs:
            fail('主题 themeConfig() 里的 %s 插件不认 —— 后台能改，接口/管理页写不进' % key)

    for key in specs:
        if key not in keys:
            fail('插件 Theme::FIELDS 里的 %s 在主题 themeConfig() 里已经不存在 —— 删掉它' % key)

    if len(keys) != len(set(keys)):
        fail('主题 themeConfig() 里有重复的字段名：%s' % keys)

    want_element = {'html': 'Textarea', 'bool': 'Checkbox'}

    for element, key in elements:
        if key not in specs:
            continue

        kind, label = specs[key]
        expected = want_element.get(kind, 'Text')

        if element != expected:
            fail('%s：主题里是 Element_%s，插件把它当 kind=%s（应为 %s）—— 控件类型换了，校验规则也得跟着换'
                 % (key, element, kind, expected))

        if label not in cfg:
            fail('%s：插件用的标签「%s」在主题 themeConfig() 里找不到 —— 一边改了显示名，另一边没跟' % (key, label))


NAME = {}
METHODS = {}
RAW = {}
CODE = {}

files = sorted(DIR.glob('*.php'))
if not files:
    print('FAIL: 找不到 %s/*.php' % DIR)
    sys.exit(1)

for path in files:
    NAME[path] = path.name
    RAW[path] = path.read_text(encoding='utf-8')
    CODE[path] = strip_code(RAW[path])
    check_headers(path.name, RAW[path])
    check_balance(path.name, RAW[path], CODE[path])
    check_quote_nesting(path.name, RAW[path])
    if problems:
        # 括号或引号错位时后面什么都不可信，先停下
        break

if not problems:
    for path in files:
        METHODS.update(collect_methods(CODE[path]))

    ALL_PRIVATE = {}
    for path in files:
        for name in collect_private(CODE[path]):
            ALL_PRIVATE[name] = path.name

    own = set(METHODS)
    known = set()
    for methods in METHODS.values():
        known |= methods

    PRIVATE_MEMBERS = {}
    CLASSES_OF = {}
    for path in files:
        for cls, members in collect_private_members(CODE[path]).items():
            PRIVATE_MEMBERS.setdefault(cls, set()).update(members)
            CLASSES_OF.setdefault(cls, set()).add(NAME[path])

    aliases_of = {}
    for path in files:
        # use 引进来的别名：PHP 里它优先于「同命名空间的同名类」，撞名就是致命错误
        aliases = set()
        for m in re.finditer(r'^\s*use\s+(?:function\s+)?([A-Za-z0-9_\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;',
                             CODE[path], re.M):
            alias = m.group(2) or m.group(1).rstrip('\\').split('\\')[-1]
            aliases.add(alias)
            if alias in own:
                fail('%s 第 %d 行：use %s 把别名 “%s” 引进来了，和本插件自己的 %s 类撞名——'
                     '之后的 %s::xxx() 全都解析到核心类，同命名空间下的同名类会被盖掉'
                     % (NAME[path], line_of(RAW[path], m.start()), m.group(1), alias, alias, alias))
        aliases_of[path] = aliases

        for m in re.finditer(r'(\\?)\b(%s|self|static)::\$?([A-Za-z_][A-Za-z0-9_]*)\s*\('
                             % '|'.join(own), CODE[path]):
            qualified, cls, target = m.group(1), m.group(2), m.group(3)

            if qualified:
                continue                      # \Typecho\Plugin::factory() —— 指的是核心类
            if cls in aliases:
                continue

            if cls in own:
                if target not in METHODS[cls]:
                    fail('%s 第 %d 行：%s::%s() 不存在（%s 类里没有这个方法）'
                         % (NAME[path], line_of(RAW[path], m.start()), cls, target, cls))
            elif target not in known:
                # self:: 落在哪个类只能靠大括号层数猜，这里只要求「某个类里有」
                fail('%s 第 %d 行：%s::%s() 在插件的任何一个类里都没找到定义'
                     % (NAME[path], line_of(RAW[path], m.start()), cls, target))

    for path in files:
        for m in re.finditer(r'\b([A-Z][A-Za-z0-9_]*)::\$?([A-Za-z_][A-Za-z0-9_]*)\b', CODE[path]):
            cls, member = m.group(1), m.group(2)

            if cls not in own or cls in aliases_of[path] or CLASSES_OF.get(cls) == {NAME[path]}:
                continue
            if member in PRIVATE_MEMBERS.get(cls, set()):
                fail('%s 第 %d 行：跨类读了 %s::%s，可它是私有成员 —— PHP 会当场致命错误'
                     '（Cannot access private）。要么改成 public，要么别跨类用'
                     % (NAME[path], line_of(RAW[path], m.start()), cls, member))

    used = set()
    for path in files:
        for m in re.finditer(r'::\$?([A-Za-z_][A-Za-z0-9_]*)\s*\(', CODE[path]):
            used.add(m.group(1))
        for m in re.finditer(r'array\(__CLASS__,\s*\'([A-Za-z_][A-Za-z0-9_]*)\'\)', CODE[path]):
            used.add(m.group(1))

    for name, owner in ALL_PRIVATE.items():
        if name not in used:
            fail('%s 里的私有方法 %s() 定义了但没有任何调用点' % (owner, name))

    for path in files:
        check_translations(path.name, CODE[path], RAW[path])

    check_theme_fields()

if problems:
    print('FAIL：%d 个问题' % len(problems))
    for p in problems:
        print('  - %s' % p)
    sys.exit(1)

print('phpcheck：%d 个文件，括号配对 / 文件头 / 静态调用 / 私有方法可达 / _t 占位符 / 主题字段一致 全过'
      % len(files))
for path in files:
    print('  %s  %d 行' % (NAME[path], RAW[path].count('\n') + 1))
