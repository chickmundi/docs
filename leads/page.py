import json, html
d = json.load(open("leads.json")); E = html.escape
main = [r for r in d["leads"] if r["score"] >= 4]; check = [r for r in d["leads"] if r["score"] < 4]
def sigtag(r):
    return ('<span class="pill hot">Dated signal</span>' if r["sig"] >= 6 else '<span class="pill cold">No signal found</span>')
def row(i, r):
    phone = f'<span class="ph">{E(r["phone"])}</span>' if r["phone"] else ""
    return f'''<li class="lead"><div class="rk">{i}</div><div class="body">
<div class="hd"><h3>{E(r["name"])}</h3><span class="sc">{r["score"]}</span></div>
<div class="meta">{E(r["area"])}{(" · " + E(r["addr"])) if r["addr"] else ""}</div>
<p>{E(r["reason"])}</p>
<div class="tags">{sigtag(r)}<span class="pill web">Online now: {E(r["web"])}</span></div>
<div class="ct"><span class="pill unk">Contact not verified</span> {E(r["contact"])} {phone}</div>
<div class="src">Source: {E(r["src"])}</div></div></li>'''
lis = "\n".join(row(i, r) for i, r in enumerate(main, 1))
chk = "\n".join(row(len(main)+i, r) for i, r in enumerate(check, 1))
cut = "\n".join(f"<li><b>{E(a)}</b>: {E(b)}</li>" for a, b in d["cut"])
tpl = open("template.html").read()
open("prospects.html","w").write(tpl.replace("{{N}}", str(len(main))).replace("{{LEADS}}", lis).replace("{{CHECK}}", chk).replace("{{CUT}}", cut))
