#!/usr/bin/env python3
"""Design-token audit for the Noline theme.

Checks, per the plan's verification section:
  1. no literal colour (hex or rgb/rgba with numeric channels) outside the :root blocks
  2. every colour-bearing light token has a dark counterpart
  3. no stale references to --accent-blue / --primary-color anywhere
  4. every var(--x) used in the CSS is actually declared somewhere -- either in a
     :root token block or as a component-local custom property (the --nav-* colour
     switch on .topbar, the --tilt-*/--glow-* knobs on .post-hero-card). Those are
     not design tokens: they carry no literal colour of their own and are never
     referenced outside the component that declares them.
"""
import re
import sys
import pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
FILES = [ROOT / 'css' / 'design.css', ROOT / 'css' / 'article.css']

# selectors whose bodies are allowed to hold literal colours
TOKEN_SELECTORS = (':root', ':root[data-theme="dark"]')

HEX = re.compile(r'#[0-9a-fA-F]{3,8}\b')
RGB_LITERAL = re.compile(r'\brgba?\(\s*-?[\d.]')


def blank(text, start, end):
    """Replace text[start:end] with spaces, preserving newlines so line numbers stay valid."""
    seg = text[start:end]
    return text[:start] + re.sub(r'[^\n]', ' ', seg) + text[end:]


def strip_comments(css):
    return re.sub(r'/\*.*?\*/', lambda m: re.sub(r'[^\n]', ' ', m.group(0)), css, flags=re.S)


def find_block(css, open_idx):
    """Given the index of '{', return the index just past its matching '}'."""
    depth = 0
    i = open_idx
    while i < len(css):
        if css[i] == '{':
            depth += 1
        elif css[i] == '}':
            depth -= 1
            if depth == 0:
                return i + 1
        i += 1
    raise ValueError('unbalanced braces from %d' % open_idx)


def parse_tokens(body):
    """Return {name: value} for every custom property declared in a block body."""
    out = {}
    # custom-property values may span lines and contain commas, so split on ';' at depth 0
    depth = 0
    cur = []
    for ch in body:
        if ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        if ch == ';' and depth == 0:
            decl = ''.join(cur).strip()
            cur = []
            if decl.startswith('--'):
                name, _, value = decl.partition(':')
                out[name.strip()] = ' '.join(value.split())
            continue
        cur.append(ch)
    tail = ''.join(cur).strip()
    if tail.startswith('--'):
        name, _, value = tail.partition(':')
        out[name.strip()] = ' '.join(value.split())
    return out


def is_colour(value):
    return bool(HEX.search(value) or RGB_LITERAL.search(value))


def main():
    problems = []
    light, dark = {}, {}

    for path in FILES:
        css = strip_comments(path.read_text(encoding='utf-8'))
        rel = path.relative_to(ROOT).as_posix()

        # locate the token blocks, harvest them, then blank them out
        for m in re.finditer(r'([^{}]+)\{', css):
            # 选择器前面可能还挂着 @charset 之类的 at-rule 残留，只取最后一个分号之后的部分
            sel = ' '.join(m.group(1).rsplit(';', 1)[-1].split())
            if sel not in TOKEN_SELECTORS:
                continue
            end = find_block(css, m.end() - 1)
            body = css[m.end():end - 1]
            toks = parse_tokens(body)
            if sel == ':root[data-theme="dark"]':
                dark.update(toks)
            else:
                light.update(toks)
            css = blank(css, m.start(), end)

        # 1. literal colours outside token blocks
        for lineno, line in enumerate(css.split('\n'), 1):
            for hit in HEX.finditer(line):
                problems.append('%s:%d literal hex %s' % (rel, lineno, hit.group(0)))
            for hit in RGB_LITERAL.finditer(line):
                problems.append('%s:%d literal %s...' % (rel, lineno, line[hit.start():hit.start() + 24].strip()))

    alltokens = dict(light)
    alltokens.update(dark)

    # 2. light colour tokens must all have a dark counterpart
    missing = sorted(n for n, v in light.items() if is_colour(v) and n not in dark)
    for name in missing:
        problems.append('token %s is a colour in light but has no dark counterpart' % name)

    # 3. stale names
    for path in FILES:
        text = path.read_text(encoding='utf-8')
        rel = path.relative_to(ROOT).as_posix()
        for stale in ('accent-blue', '--primary-color'):
            for lineno, line in enumerate(text.split('\n'), 1):
                if stale in line:
                    problems.append('%s:%d stale reference to %s' % (rel, lineno, stale))

    # 4. every var(--x) resolves
    declared = set(alltokens)
    for path in FILES:
        text = strip_comments(path.read_text(encoding='utf-8'))
        declared.update(m.group(1) for m in re.finditer(r'(--[a-z0-9-]+)\s*:', text))

    for path in FILES:
        text = strip_comments(path.read_text(encoding='utf-8'))
        rel = path.relative_to(ROOT).as_posix()
        for lineno, line in enumerate(text.split('\n'), 1):
            for m in re.finditer(r'var\(\s*(--[a-z0-9-]+)\s*(,|\))', line):
                name = m.group(1)
                has_fallback = m.group(2) == ','
                if name in declared or has_fallback:
                    continue
                problems.append('%s:%d uses %s which is never declared and has no fallback' % (rel, lineno, name))

    print('light tokens: %d (%d colour)' % (len(light), sum(1 for v in light.values() if is_colour(v))))
    print('dark  tokens: %d (%d colour)' % (len(dark), sum(1 for v in dark.values() if is_colour(v))))
    print('colour tokens matched across themes: %s'
          % ('yes' if not missing else 'NO -> ' + ', '.join(missing)))
    print()
    for p in problems:
        print('  ' + p)
    print()
    print('ALL OK' if not problems else '%d PROBLEM(S)' % len(problems))
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
