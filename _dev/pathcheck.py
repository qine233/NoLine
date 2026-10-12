#!/usr/bin/env python3
"""Validate SVG path data: tokenization, command arity, closure, true geometric bounds.

Usage: pathcheck.py icon1.svg icon2.svg ...
Each file must be a single-path SVG with a viewBox.
"""
import re
import sys

CMD_ARITY = {
    'M': 2, 'L': 2, 'H': 1, 'V': 1, 'C': 6, 'S': 4, 'Q': 4, 'T': 2, 'A': 7, 'Z': 0,
}

TOKEN = re.compile(r'[MmLlHhVvCcSsQqTtAaZz]|[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?')


def tokenize(d):
    """Return list of tokens, or None if any character is unaccounted for."""
    toks, pos, n = [], 0, len(d)
    while pos < n:
        ch = d[pos]
        if ch in ' \t\r\n,':
            pos += 1
            continue
        m = TOKEN.match(d, pos)
        if not m:
            return None, 'unparsed char %r at %d' % (ch, pos)
        toks.append(m.group(0))
        pos = m.end()
    return toks, None


def cubic_extrema(p0, p1, p2, p3):
    """Exact min/max of a cubic Bezier on t in [0,1] for one axis."""
    lo = hi = None

    def consider(v):
        nonlocal lo, hi
        lo = v if lo is None else min(lo, v)
        hi = v if hi is None else max(hi, v)

    consider(p0)
    consider(p3)
    a = -3 * p0 + 9 * p1 - 9 * p2 + 3 * p3
    b = 6 * p0 - 12 * p1 + 6 * p2
    c = -3 * p0 + 3 * p1
    roots = []
    if abs(a) < 1e-12:
        if abs(b) > 1e-12:
            roots.append(-c / b)
    else:
        disc = b * b - 4 * a * c
        if disc >= 0:
            sq = disc ** 0.5
            roots.append((-b + sq) / (2 * a))
            roots.append((-b - sq) / (2 * a))
    for t in roots:
        if 0.0 < t < 1.0:
            mt = 1 - t
            consider(mt ** 3 * p0 + 3 * mt ** 2 * t * p1 + 3 * mt * t ** 2 * p2 + t ** 3 * p3)
    return lo, hi


def quad_extrema(p0, p1, p2):
    lo = min(p0, p2)
    hi = max(p0, p2)
    denom = p0 - 2 * p1 + p2
    if abs(denom) > 1e-12:
        t = (p0 - p1) / denom
        if 0.0 < t < 1.0:
            mt = 1 - t
            v = mt * mt * p0 + 2 * mt * t * p1 + t * t * p2
            lo = min(lo, v)
            hi = max(hi, v)
    return lo, hi


def arc_samples(x1, y1, rx, ry, phi_deg, fa, fs, x2, y2, steps=64):
    """Endpoint -> centre parameterisation (SVG spec F.6.5), then dense sampling."""
    import math
    if rx == 0 or ry == 0:
        return [(x2, y2)]
    rx, ry = abs(rx), abs(ry)
    phi = math.radians(phi_deg % 360)
    cp, sp = math.cos(phi), math.sin(phi)
    dx, dy = (x1 - x2) / 2.0, (y1 - y2) / 2.0
    x1p = cp * dx + sp * dy
    y1p = -sp * dx + cp * dy
    lam = (x1p * x1p) / (rx * rx) + (y1p * y1p) / (ry * ry)
    if lam > 1:
        s = math.sqrt(lam)
        rx, ry = rx * s, ry * s
        lam = 1.0
    num = rx * rx * ry * ry - rx * rx * y1p * y1p - ry * ry * x1p * x1p
    den = rx * rx * y1p * y1p + ry * ry * x1p * x1p
    co = 0.0 if den == 0 else max(0.0, num / den) ** 0.5
    if fa == fs:
        co = -co
    cxp = co * (rx * y1p) / ry
    cyp = -co * (ry * x1p) / rx
    cx = cp * cxp - sp * cyp + (x1 + x2) / 2.0
    cy = sp * cxp + cp * cyp + (y1 + y2) / 2.0

    def angle(ux, uy, vx, vy):
        dot = ux * vx + uy * vy
        ln = math.hypot(ux, uy) * math.hypot(vx, vy)
        if ln == 0:
            return 0.0
        r = max(-1.0, min(1.0, dot / ln))
        a = math.acos(r)
        return -a if (ux * vy - uy * vx) < 0 else a

    th1 = angle(1, 0, (x1p - cxp) / rx, (y1p - cyp) / ry)
    dth = angle((x1p - cxp) / rx, (y1p - cyp) / ry, (-x1p - cxp) / rx, (-y1p - cyp) / ry)
    if not fs and dth > 0:
        dth -= 2 * math.pi
    elif fs and dth < 0:
        dth += 2 * math.pi
    pts = []
    for i in range(steps + 1):
        th = th1 + dth * (i / steps)
        px = rx * math.cos(th)
        py = ry * math.sin(th)
        pts.append((cp * px - sp * py + cx, sp * px + cp * py + cy))
    return pts


def check(d, vb):
    toks, err = tokenize(d)
    if err:
        return ['tokenize: ' + err]
    problems = []
    vbw, vbh = vb[2], vb[3]

    x = y = 0.0
    sx = sy = 0.0            # start of current subpath (for Z)
    px = py = None           # previous control point (for S/T reflection)
    prev = None              # previous command letter, uppercased
    xs, ys = [], []
    closed = False
    started = False

    i, n = 0, len(toks)
    cmd = None
    while i < n:
        t = toks[i]
        if t[0].isalpha() and len(t) == 1 and t in 'MmLlHhVvCcSsQqTtAaZz':
            cmd = t
            i += 1
            if cmd in 'Zz':
                x, y = sx, sy
                closed = True
                px = py = None
                prev = 'Z'
                cmd = None
                continue
        if cmd is None:
            return ['path does not start with a command']

        upper = cmd.upper()
        rel = cmd.islower()
        arity = CMD_ARITY[upper]

        if i + arity > n:
            problems.append('truncated: %s expects %d more number(s)' % (cmd, arity))
            break
        try:
            args = [float(toks[i + k]) for k in range(arity)]
        except ValueError:
            problems.append('%s: expected %d number(s), got %s' % (cmd, arity, toks[i:i + arity]))
            break
        i += arity

        if upper == 'M':
            nx, ny = args
            if rel:
                nx, ny = x + nx, y + ny
            x, y = nx, ny
            sx, sy = nx, ny
            started = True
            xs.append(x)
            ys.append(y)
            px = py = None
            # extra coordinate pairs after an M are implicit lineto
            cmd = 'l' if rel else 'L'
            prev = 'M'
            continue
        if upper == 'L':
            nx, ny = args
            if rel:
                nx, ny = x + nx, y + ny
            x, y = nx, ny
            xs.append(x)
            ys.append(y)
            px = py = None
        elif upper == 'H':
            nx = args[0] + (x if rel else 0)
            x = nx
            xs.append(x)
            ys.append(y)
            px = py = None
        elif upper == 'V':
            ny = args[0] + (y if rel else 0)
            y = ny
            xs.append(x)
            ys.append(y)
            px = py = None
        elif upper == 'C':
            x1, y1, x2, y2, x3, y3 = args
            if rel:
                x1, y1, x2, y2, x3, y3 = x + x1, y + y1, x + x2, y + y2, x + x3, y + y3
            lox, hix = cubic_extrema(x, x1, x2, x3)
            loy, hiy = cubic_extrema(y, y1, y2, y3)
            xs += [lox, hix]
            ys += [loy, hiy]
            px, py = x2, y2
            x, y = x3, y3
        elif upper == 'S':
            x2, y2, x3, y3 = args
            if rel:
                x2, y2, x3, y3 = x + x2, y + y2, x + x3, y + y3
            if prev in ('C', 'S'):
                x1, y1 = 2 * x - px, 2 * y - py
            else:
                x1, y1 = x, y
            lox, hix = cubic_extrema(x, x1, x2, x3)
            loy, hiy = cubic_extrema(y, y1, y2, y3)
            xs += [lox, hix]
            ys += [loy, hiy]
            px, py = x2, y2
            x, y = x3, y3
        elif upper == 'Q':
            x1, y1, x2, y2 = args
            if rel:
                x1, y1, x2, y2 = x + x1, y + y1, x + x2, y + y2
            lox, hix = quad_extrema(x, x1, x2)
            loy, hiy = quad_extrema(y, y1, y2)
            xs += [lox, hix]
            ys += [loy, hiy]
            px, py = x1, y1
            x, y = x2, y2
        elif upper == 'T':
            x2, y2 = args
            if rel:
                x2, y2 = x + x2, y + y2
            if prev in ('Q', 'T'):
                x1, y1 = 2 * x - px, 2 * y - py
            else:
                x1, y1 = x, y
            lox, hix = quad_extrema(x, x1, x2)
            loy, hiy = quad_extrema(y, y1, y2)
            xs += [lox, hix]
            ys += [loy, hiy]
            px, py = x1, y1
            x, y = x2, y2
        elif upper == 'A':
            rx, ry, rot, fa, fs, x2, y2 = args
            if rel:
                x2, y2 = x + x2, y + y2
            for ptx, pty in arc_samples(x, y, rx, ry, rot, int(fa), int(fs), x2, y2):
                xs.append(ptx)
                ys.append(pty)
            x, y = x2, y2
            px = py = None

        prev = upper
        # an implicit repeat keeps the same command; Z resets it
        if upper == 'Z':
            cmd = None

    if not started:
        problems.append('no moveto')
    if not closed:
        problems.append('no Z (not explicitly closed)')
    if i != n:
        problems.append('%d leftover token(s): %s' % (n - i, toks[i:i + 6]))

    if xs:
        minx, maxx, miny, maxy = min(xs), max(xs), min(ys), max(ys)
        eps = 0.75
        if minx < vb[0] - eps or miny < vb[1] - eps or maxx > vb[0] + vbw + eps or maxy > vb[1] + vbh + eps:
            problems.append('bounds x[%.1f,%.1f] y[%.1f,%.1f] vs viewBox %s' % (minx, maxx, miny, maxy, vb))
        else:
            print('    bounds ok: x[%.1f,%.1f] y[%.1f,%.1f] in %s' % (minx, maxx, miny, maxy, vb))
    return problems


def main():
    bad = 0
    for path in sys.argv[1:]:
        src = open(path, encoding='utf-8').read()
        m = re.search(r'viewBox="([^"]+)"', src)
        if not m:
            print('%s: no viewBox' % path)
            bad += 1
            continue
        vb = [float(v) for v in m.group(1).split()]
        ds = re.findall(r'\sd="([^"]+)"', src)
        if not ds:
            print('%s: no path d' % path)
            bad += 1
            continue
        print('%s  viewBox=%s  paths=%d' % (path, m.group(1), len(ds)))
        for k, d in enumerate(ds):
            probs = check(d, vb)
            for p in probs:
                print('    [path %d] %s' % (k, p))
                bad += 1
    print('\n%s' % ('ALL OK' if bad == 0 else '%d PROBLEM(S)' % bad))
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
