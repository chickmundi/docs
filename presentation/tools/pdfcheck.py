"""Fail if a PDF contains soft masks (semi-transparent layers).
Chrome writes blurred shadows, filters and see-through gradients as soft masks;
the iPhone PDF viewer shows them as gray rectangles. Usage: python3 pdfcheck.py file.pdf"""
import sys
import pikepdf

def check(path):
    pdf = pikepdf.open(path)
    found = []
    for n, page in enumerate(pdf.pages, 1):
        seen = set()
        def walk(res, where):
            if res is None or id(res) in seen:
                return
            seen.add(id(res))
            for name, gs in (res.get('/ExtGState') or {}).items():
                sm = gs.get('/SMask')
                if sm is not None and sm != pikepdf.Name('/None'):
                    found.append(f'page {n}: soft mask in graphics state {name} ({where})')
            for name, x in (res.get('/XObject') or {}).items():
                if x.get('/Subtype') == '/Image' and x.get('/SMask') is not None:
                    found.append(f'page {n}: image {name} has transparency ({where})')
                if x.get('/Subtype') == '/Form':
                    walk(x.get('/Resources'), where + '>' + str(name))
            for name, pat in (res.get('/Pattern') or {}).items():
                if hasattr(pat, 'get'):
                    walk(pat.get('/Resources'), where + '>pattern ' + str(name))
        walk(page.get('/Resources'), 'page')
    return found

if __name__ == '__main__':
    bad = check(sys.argv[1])
    if bad:
        print('PDF NOT SAFE for iPhone viewers:')
        print('\n'.join(bad[:40]))
        sys.exit(1)
    print('PDF safe: no soft masks or transparent images')
