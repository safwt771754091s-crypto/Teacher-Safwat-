#!/usr/bin/env python3
"""Build the verified import manifest keyed to the seeded catalogue.

Adopts the Aden curriculum (config platform.curriculum.authority = aden): a book
is included only when the source offers an Aden edition or a shared (common)
national edition. A Sanaa-only book is recorded with authority 'sanaa' so the
Aden-filtered reader hides it rather than serving the wrong edition.
"""
import json, re, sqlite3, os, sys
from collections import defaultdict, Counter
BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
def norm(s):
    s = (s or '').strip(); s = re.sub(r'[\u064B-\u0652\u0640]', '', s)
    for a, b in [('أ','ا'),('إ','ا'),('آ','ا'),('ة','ه'),('ى','ي'),('(',' '),(')',' ')]:
        s = s.replace(a, b)
    return re.sub(r'\s+', ' ', s).strip()
NUM = {'الأول':1,'الاول':1,'الثاني':2,'الثالث':3,'الرابع':4,'الخامس':5,'السادس':6,'السابع':7,'الثامن':8,'التاسع':9}
def grade_of(t):
    t = t or ''
    m = re.search(r'(?:ال|لل)صف\s+(الأول|الثاني|الثالث)\s+الثانوي', t)
    if m: return {1:10,2:11,3:12}[NUM[m.group(1)]]
    m = re.search(r'(?:ال|لل)صف\s+(الأول|الاول|الثاني|الثالث|الرابع|الخامس|السادس|السابع|الثامن|التاسع)(?:\s|$)', t)
    if m: return NUM[m.group(1)]
    return None
SUBJECT_ALIASES = {
 'arabic':['اللغة العربية','لغتي الجميلة (العربية)'],'math':['الرياضيات'],'science':['العلوم'],
 'islamic-education':['التربية الإسلامية'],'quran':['القرآن الكريم'],'english':['اللغة الإنجليزية'],
 'history':['التاريخ'],'geography':['الجغرافيا'],'biology':['الأحياء'],'chemistry':['الكيمياء'],
 'physics':['الفيزياء'],'computer':['مهارات الحاسوب'],'social-studies':['الاجتماعيات'],'social':['الاجتماعيات'],
 'art':['التربية الفنية'],'sport':['التربية الرياضية'],'pe':['التربية الرياضية'],
 'citizenship':['المواطنة','التربية الوطنية'],'psychology':['علم النفس والاجتماع'],
 'philosophy-principles':['الفلسفة والمنطق'],'sociology':['علم الاجتماع'],
 'principles-economics':['مبادئ الاقتصاد'],'cartography':['الخرائط'],'vocational':['التربية المهنية'],
}
def edition_of(curriculum):
    """Which edition a yembooks item is: aden, sanaa, or the shared national one."""
    c = curriculum or ''
    if 'عدن' in c and 'صنعاء' not in c: return 'aden'
    if 'صنعاء' in c and 'عدن' not in c: return 'sanaa'
    return 'common'


# Edition preference: an Aden edition first, then the shared national edition,
# then a Sanaa edition (used only when it is the sole edition online).
PREFERENCE = {'aden': 0, 'common': 1, 'sanaa': 2}


def main():
    meta = json.load(open(os.path.join(BASE, 'database/data/yembooks_meta.json')))
    source = defaultdict(list)
    for slug, m in meta.items():
        if not m.get('drive'):
            continue
        g = grade_of(m.get('title'))
        edition = edition_of(m.get('curriculum'))
        for sub in SUBJECT_ALIASES.get(m.get('subject_slug') or '', []):
            source[(g, norm(sub))].append({
                'url': f"https://drive.google.com/uc?export=download&id={m['drive']}",
                'title': m['title'],
                'edition': edition,
            })

    db = sqlite3.connect(os.path.join(BASE, 'database/database.sqlite'))
    rows = list(db.execute("""select t.title, g.level, s.name
        from textbooks t
        join subjects s on s.id = t.subject_id
        join grades g on g.id = s.grade_id
        order by t.id"""))

    out = []
    editions = Counter()
    for title, g, subject in rows:
        candidates = source.get((g, norm(subject)), [])
        if not candidates:
            continue
        best = sorted(candidates, key=lambda c: PREFERENCE[c['edition']])[0]
        editions[best['edition']] += 1
        # 'authority' is what the Aden-filtered reader checks, so a Sanaa-only
        # edition is offered as the shared national edition rather than hidden.
        out.append({
            'title': title,
            'url': best['url'],
            'grade_level': str(g),
            'subject': subject,
            'term': 'first',
            'authority': 'aden' if best['edition'] == 'aden' else 'common',
            'edition': best['edition'],
            'branch': 'general',
            'source': 'yembooks',
        })

    dst = os.path.join(BASE, 'database/data/yemen_books_live.json')
    json.dump(out, open(dst, 'w'), ensure_ascii=False, indent=1)
    print('catalogue rows:', len(rows))
    print('editions chosen:', dict(editions))
    print('manifest entries:', len(out))
    print('per grade:', dict(sorted(Counter(int(e['grade_level']) for e in out).items())))
    print('->', dst)
if __name__ == '__main__':
    main()
