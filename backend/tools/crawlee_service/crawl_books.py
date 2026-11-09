#!/usr/bin/env python3
"""Discover and fetch Yemeni textbook *source* material with Crawlee.

This is a helper, not a dependency. The platform already extracts text and OCR
from an uploaded PDF on its own; this script only answers the earlier question
"where is the official file, and what does this index page say". A reviewer can
run it to find candidate editions, and the backend calls it opportunistically
when a book was catalogued with an index URL but no PDF.

It prints one JSON object on stdout so the PHP side can consume it without a
shared database or a long-running service:

    {"ok": true, "links": [{"url": ..., "text": ..., "is_pdf": true}], "pages": [...]}

Usage:
    python crawl_books.py --url https://example.edu/books --max-pages 3
    python crawl_books.py --url ... --pdf-only
"""

from __future__ import annotations

import argparse
import asyncio
import json
import re
import sys

# PDFs are what we actually want; a direct .pdf link needs no second request.
PDF_RE = re.compile(r"\.pdf(\?|#|$)", re.IGNORECASE)
# Arabic + Latin words that mark a link as a book/edition worth collecting.
BOOK_WORDS = ("كتاب", "الكتاب", "منهج", "المنهج", "طبعة", "الصف", "الجزء", "تحميل", "pdf", "book")


def _text(node) -> str:
    """Anchor label, across the parsers Crawlee can hand us (bs4 / parsel)."""
    for getter in ("get_text", "get_all_text", "text"):
        fn = getattr(node, getter, None)
        if fn is None:
            continue
        try:
            value = fn() if callable(fn) else fn
        except Exception:
            continue
        if value:
            return " ".join(str(value).split())
    return ""


async def _crawl(url: str, max_pages: int, pdf_only: bool) -> dict:
    """Crawl `url` with Crawlee and return the book links it found."""
    from crawlee.crawlers import BeautifulSoupCrawler, BeautifulSoupCrawlingContext

    links: list[dict] = []
    pages: list[dict] = []
    seen: set[str] = set()

    crawler = BeautifulSoupCrawler(max_requests_per_crawl=max_pages)

    @crawler.router.default_handler
    async def handler(context: BeautifulSoupCrawlingContext) -> None:
        soup = context.soup
        title = soup.title.get_text(strip=True) if soup.title else ""
        body = " ".join(soup.get_text(" ", strip=True).split())[:2000]
        pages.append({"url": context.request.url, "title": title, "text": body})

        for a in soup.find_all("a", href=True):
            href = a["href"].strip()
            if not href or href.startswith(("#", "javascript:", "mailto:")):
                continue
            absolute = context.request.url
            try:
                from yarl import URL

                absolute = str(URL(context.request.url).join(URL(href)))
            except Exception:
                absolute = href
            if absolute in seen:
                continue

            label = _text(a)
            is_pdf = bool(PDF_RE.search(absolute))
            looks_like_book = any(w in label or w in absolute for w in BOOK_WORDS)
            if pdf_only and not is_pdf:
                continue
            if not is_pdf and not looks_like_book:
                continue

            seen.add(absolute)
            links.append({"url": absolute, "text": label, "is_pdf": is_pdf})

    await crawler.run([url])

    # PDFs first, then by whether the label mentions a book.
    links.sort(key=lambda item: (not item["is_pdf"],))
    return {"ok": True, "source": url, "links": links, "pages": pages}


def main() -> int:
    parser = argparse.ArgumentParser(description="Discover textbook source material.")
    parser.add_argument("--url", required=True, help="Index page to crawl.")
    parser.add_argument("--max-pages", type=int, default=2, help="Crawl budget.")
    parser.add_argument("--pdf-only", action="store_true", help="Only return .pdf links.")
    args = parser.parse_args()

    try:
        result = asyncio.run(_crawl(args.url, max(1, args.max_pages), args.pdf_only))
    except Exception as exc:  # a helper must fail loudly and cleanly, never hang
        print(json.dumps({"ok": False, "error": f"{type(exc).__name__}: {exc}"}, ensure_ascii=False))
        return 1

    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
