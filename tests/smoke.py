#!/usr/bin/env python3
"""Semantic-HTML / SEO smoke test against a running site.
Usage: python3 tests/smoke.py http://localhost:8090 [base=routes]
"""
import json, re, sys, urllib.request
from html.parser import HTMLParser

SITE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8090'
BASE = sys.argv[2] if len(sys.argv) > 2 else 'routes'
fails = []

def get(url):
    with urllib.request.urlopen(url, timeout=60) as r:
        return r.status, r.read().decode('utf-8')

def check(cond, msg):
    print(('ok   ' if cond else 'FAIL ') + msg)
    if not cond: fails.append(msg)

class P(HTMLParser):
    def __init__(s):
        super().__init__(); s.h = []; s.ids = []; s.imgs_noalt = 0; s.btn_unnamed = 0
        s.landmarks = set(); s.lang = None; s._btn = None; s._txt = ''
    def handle_starttag(s, t, a):
        a = dict(a)
        if re.fullmatch(r'h[1-6]', t): s.h.append(int(t[1]))
        if 'id' in a: s.ids.append(a['id'])
        if t == 'html': s.lang = a.get('lang')
        if t == 'img' and 'alt' not in a: s.imgs_noalt += 1
        if t in ('main', 'nav', 'header', 'footer'): s.landmarks.add(t)
        if a.get('role') in ('search', 'status', 'region'): s.landmarks.add(a['role'])
        if t == 'button': s._btn = 'aria-label' in a; s._txt = ''
    def handle_data(s, d):
        if s._btn is not None: s._txt += d.strip()
    def handle_endtag(s, t):
        if t == 'button':
            if not s._btn and not s._txt: s.btn_unnamed += 1
            s._btn = None

def audit(url, name):
    status, html = get(url)
    check(status == 200, f'{name}: HTTP 200')
    p = P(); p.feed(html)
    plugin_h1 = re.findall(r'<h1[ >]', html)
    check(len(plugin_h1) == 1, f'{name}: exactly one <h1> ({len(plugin_h1)})')
    levels = p.h
    jumps = [(a, b) for a, b in zip(levels, levels[1:]) if b - a > 1]
    check(not jumps, f'{name}: no skipped heading levels {jumps[:3]}')
    check(p.lang and p.lang.startswith('es'), f'{name}: html lang es ({p.lang})')
    check(len(p.ids) == len(set(p.ids)), f'{name}: unique ids')
    check(p.imgs_noalt == 0, f'{name}: all <img> have alt')
    check(p.btn_unnamed == 0, f'{name}: every button has an accessible name')
    check(len(re.findall(r'rel="canonical"', html)) == 1, f'{name}: single canonical')
    check(len(re.findall(r'<meta name="description"', html)) == 1, f'{name}: one meta description')
    d = re.search(r'<meta name="description" content="([^"]*)"', html)
    check(bool(d) and 50 <= len(d.group(1)) <= 200, f'{name}: description length {len(d.group(1)) if d else 0}')
    for m in re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S):
        try: json.loads(m); ok = True
        except Exception: ok = False
        check(ok, f'{name}: JSON-LD parses')
    check('trufi-skip-link' in html, f'{name}: skip link')
    check('aria-live' in html, f'{name}: live region')
    return html

idx = audit(f'{SITE}/{BASE}/', 'index')
check('role="search"' in idx, 'index: search landmark')
check('FAQPage' not in idx and 'trufi-faq' not in idx, 'index: no hidden/visible FAQ markup (lives in llms.txt)')
check(len(re.findall(r'class="trufi-line"', idx)) > 0, 'index: server-rendered lines')
stats = dict((lbl, int(n)) for n, lbl in re.findall(r'<strong>(\d+)</strong>\s*<span>([^<]+)</span>', idx))
named = [g for g in re.findall(r'<h3 class="trufi-group-name">(.*?)</h3>', idx) if g != 'Sin sindicato identificado']
check(stats.get('Sindicatos') == len(named), f'index: Sindicatos stat ({stats.get("Sindicatos")}) == named groups ({len(named)})')
if 'Sin sindicato identificado' in idx:
    tail = idx.split('Sin sindicato identificado', 1)[1]
    check(stats.get('Líneas sin sindicato') == len(re.findall(r'class="trufi-line"', tail)), 'index: "Líneas sin sindicato" stat == lines in that group')
else:
    check('Líneas sin sindicato' not in stats, 'index: no unassigned stat without the group')
check('Linea 103' not in re.findall(r'<h3 class="trufi-group-name">(.*?)</h3>', idx), 'index: no "Linea 103" group')

_, sm = get(f'{SITE}/wp-sitemap-routes-1.xml')
urls = re.findall(r'<loc>([^<]+)</loc>', sm)
check(len(urls) > 0, f'sitemap: {len(urls)} urls')
rt = audit(urls[0], 'route')
check('BusTrip' in rt and 'BreadcrumbList' in rt, 'route: BusTrip + Breadcrumb JSON-LD')
check('<ol class="trufi-stops-list"' in rt, 'route: stops are an ordered list')
check('trufi-hacia' in rt, 'route: pink "hacia" in subtitle')
check('→' not in rt.split('trufi-route-subtitle')[1][:300], 'route: no arrow in subtitle')
_, ll = get(f'{SITE}/llms.txt')
check(ll.startswith('# '), 'llms.txt served')
check('24 horas' in ll, 'llms.txt: no-24/7 disclaimer')
check('Preguntas frecuentes' in ll, 'llms.txt: FAQ')

print('\n%d failure(s)' % len(fails))
sys.exit(1 if fails else 0)
