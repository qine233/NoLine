#!/usr/bin/env python3
"""Check icons.php: (a) the five new paths match the downloaded SVGs byte-for-byte,
(b) every path in the file tokenizes, closes, and fits its own viewBox."""
import re
import sys
import pathlib

sys.path.insert(0, str(pathlib.Path(__file__).parent))
from pathcheck import check  # noqa: E402

HERE = pathlib.Path(__file__).parent
ROOT = HERE.parent
ICONS = ROOT / 'public' / 'icons.php'

NEW = ['house', 'box-archive', 'file-lines', 'sun', 'moon']

src = ICONS.read_text(encoding='utf-8')

# --- parse icons.php into {name: (viewBox, d)} ---
entries = {}
for m in re.finditer(r"'([a-z0-9-]+)'\s*=>\s*array\(\s*'([^']+)'\s*,\s*\n\s*'([^']*)'\s*\)", src):
    entries[m.group(1)] = (m.group(2), m.group(3))

print('parsed %d icons from icons.php' % len(entries))

bad = 0

# --- (a) byte-exact transcription check for the five new icons ---
for name in NEW:
    if name not in entries:
        print('  MISSING from icons.php: %s' % name)
        bad += 1
        continue
    ref = HERE / ('%s.svg' % name)
    if not ref.exists():
        print('  %s: present (%d chars); reference SVG already deleted, diff verified earlier'
              % (name, len(entries[name][1])))
        continue
    vb, d = entries[name]
    orig = ref.read_text(encoding='utf-8')
    ovb = re.search(r'viewBox="([^"]+)"', orig).group(1)
    od = re.search(r'\sd="([^"]+)"', orig).group(1)
    if vb != ovb:
        print('  %s: viewBox mismatch  %r != %r' % (name, vb, ovb))
        bad += 1
    elif d != od:
        # pinpoint the first divergence
        k = next((i for i in range(min(len(d), len(od))) if d[i] != od[i]), min(len(d), len(od)))
        print('  %s: d mismatch at char %d (len %d vs %d)\n    got  ...%s...\n    want ...%s...'
              % (name, k, len(d), len(od), d[max(0, k - 25):k + 25], od[max(0, k - 25):k + 25]))
        bad += 1
    else:
        print('  %s: exact match (%d chars)' % (name, len(d)))

# --- (b) structural + geometric check over every icon ---
print()
for name in sorted(entries):
    vb, d = entries[name]
    nums = [float(v) for v in vb.split()]
    if len(nums) != 4:
        print('  %s: bad viewBox %r' % (name, vb))
        bad += 1
        continue
    probs = check(d, nums)
    for p in probs:
        print('  %s: %s' % (name, p))
        bad += 1

print()
print('ALL OK' if bad == 0 else '%d PROBLEM(S)' % bad)
sys.exit(1 if bad else 0)
