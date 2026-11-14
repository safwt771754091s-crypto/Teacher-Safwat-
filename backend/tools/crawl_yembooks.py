#!/usr/bin/env python3
"""Crawl yembooks.com item metadata (subject, curriculum, Google Drive id)."""
import subprocess, re, json, sys, os
from concurrent.futures import ThreadPoolExecutor
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"

def fetch(slug):
    h = subprocess.run(["curl","-s","-m","40","-A",UA,"-L",
        f"https://yembooks.com/item/{slug}"], capture_output=True, text=True).stdout
    def f(p):
        m = re.search(p, h); return m.group(1).strip() if m else None
    return slug, {
        'title': f(r'<h1[^>]*>([^<]*)</h1>'),
        'subject_slug': (re.findall(r'explorer/subject-([a-z0-9-]+)', h) or [None])[0],
        'curriculum': f(r'class="curriculum-badge"[^>]*title="([^"]*)"'),
        'pages': f(r'"numberOfPages":"(\d+)"'),
        'drive': (re.findall(r'drive\.google\.com/file/d/([A-Za-z0-9_-]{20,})', h) or [None])[0],
    }

def main():
    slugs = sys.argv[1:]
    if not slugs:
        d = os.path.join(os.path.dirname(__file__), '..', 'database', 'data', 'books')
        slugs = sorted(f[:-4] for f in os.listdir(d) if f.endswith('.pdf'))
    out = {}
    with ThreadPoolExecutor(max_workers=8) as ex:
        for slug, meta in ex.map(fetch, slugs):
            out[slug] = meta
    dst = os.path.join(os.path.dirname(__file__), '..', 'database', 'data', 'yembooks_meta.json')
    json.dump(out, open(dst, 'w'), ensure_ascii=False, indent=1)
    withdr = sum(1 for m in out.values() if m.get('drive'))
    print(f"crawled {len(out)} items, {withdr} with drive -> {dst}")

if __name__ == '__main__':
    main()
