import json, openpyxl
from openpyxl.styles import Font, PatternFill, Alignment

# fit, signal, reach on 0-10; score = .4 fit + .4 signal + .2 reach
L = [
 dict(name="Rela Marché Africain", area="Saint-Laurent, Langelier, Montréal-Nord, Honoré-Beaugrand", addr="925 boul. Décarie (main); 5815 boul. Léger (new)", phone="", web="No store website found; Facebook + TikTok", fit=9, sig=10, reach=3,
      reason="Opened a new branch at 5815 boul. Léger, Montréal-Nord, announced Nov 2025 on their TikTok. Now 3-4 locations and no online store found. One online store could serve every branch.",
      contact="Unknown. Ask for the owner at the Décarie store, or message the Facebook page", src="TikTok @rela_marche_africain (Nov 2025); Tripadvisor; sagafrika.ca branch list; Facebook"),
 dict(name="Marché du Mboa", area="Montréal-Nord", addr="6293 boul. Léger, H1G 6K8", phone="438-381-4179", web="None found", fit=8, sig=6, reach=3,
      reason="Rela opened a new store on the same street (5815 Léger) in Nov 2025. Online ordering and delivery would help Mboa keep its regular customers.",
      contact="Unknown. Store phone only", src="africanstorescanada.ca listing (via search); Rela TikTok"),
 dict(name="Petite Afrique", area="Ville-Émard", addr="6415 boul. Monk", phone="", web="petiteafrique.org (online ordering not confirmed)", fit=6, sig=7, reach=3,
      reason="A grocery and West African restaurant. Their site gives 2026 as the founding year, so they are a new business that may still be building its sales channels. The pitch is to put the grocery side online.",
      contact="Unknown. Use the contact form on their site", src="petiteafrique.org"),
 dict(name="Marché Mokolo", area="Côte-des-Neiges", addr="4974 ch. Queen-Mary", phone="514-344-5799", web="None. 3 separate Facebook pages", fit=9, sig=2, reach=3,
      reason="Cameroonian grocery open since 2009 in Côte-des-Neiges, a dense area. Its online presence is split across three Facebook pages and it has no website. It's the kind of established store that is easy to pitch.",
      contact="Unknown. Store phone; an email is listed on Facebook", src="411.ca; allbiz.ca; Facebook"),
 dict(name="Épicerie Africaine de Montréal", area="Ahuntsic / Sauvé", addr="1364 rue Sauvé E, H2C 2A2", phone="514-327-2666", web="None found", fit=8, sig=2, reach=3,
      reason="Long-standing store with a phone listing and no website or online ordering found.",
      contact="Unknown. Store phone", src="cylex-canada.ca"),
 dict(name="Continent Africain", area="Longueuil", addr="1562 ch. de Chambly", phone="", web="None found", fit=8, sig=2, reach=3,
      reason="Draws loyal customers from Longueuil and Montréal. Three African grocers are on Chemin de Chambly, and Marché Gouroh already sells online on the South Shore. The first of them to go online gets the street's delivery customers.",
      contact="Unknown", src="afros-et-fiers.com"),
 dict(name="Elikya Rive Sud", area="Longueuil", addr="2150 ch. de Chambly", phone="", web="None found", fit=8, sig=2, reach=3,
      reason="African and Caribbean grocery on the same Chambly strip, with no website found. Same angle as Continent Africain.",
      contact="Unknown", src="search directory listing"),
 dict(name="Ghanacan", area="Parc-Extension", addr="549 av. Ogilvy, H3N 1M9", phone="514-278-6987", web="marcheghanacan.ca (basic shop page)", fit=7, sig=2, reach=3,
      reason="African butcher and grocery. Its current site has only a simple shop page, so pitch an upgrade to a real online store with delivery.",
      contact="Unknown. Store phone", src="marcheghanacan.ca"),
 dict(name="Marché Sabiraa", area="Montréal-Nord", addr="rue Monselet", phone="", web="Facebook only (1 review)", fit=7, sig=2, reach=3,
      reason="African, Antillean and Spanish products. Its only online presence is a nearly empty Facebook page.",
      contact="Unknown. Facebook message", src="Facebook /MarcheSabiraa"),
 dict(name="Marché Castel", area="Saint-Michel", addr="3455 rue Jean-Talon E, H2A 1X1", phone="", web="None found", fit=7, sig=2, reach=3,
      reason="Large North African grocery with a butcher, produce and a restaurant. It's a bigger operation than most on this list. Check that North African stores fit your offer.",
      contact="Unknown", src="beauteherins.com list (via search)"),
 dict(name="Marché LT Eben-Ezer", area="Montréal-Nord / Rivière-des-Prairies", addr="4821 boul. Henri-Bourassa E", phone="", web="marchelteben-ezer.com (e-commerce not confirmed)", fit=6, sig=2, reach=3,
      reason="Congolese and African products. It has a website, but I couldn't confirm whether it takes orders. If it's brochure-only, it's a good fit.",
      contact="Unknown. Use the contact on their site", src="marchelteben-ezer.com"),
 dict(name="Épicerie Ebenezer", area="Montréal", addr="", phone="", web="epicerieebenezer.com (e-commerce not confirmed)", fit=6, sig=2, reach=3,
      reason="African, Caribbean, Haitian and Asian products. It has a site, but online ordering is not confirmed. Check before pitching.",
      contact="Unknown", src="epicerieebenezer.com"),
]
for r in L:
    r["score"] = round(.4*r["fit"] + .4*r["sig"] + .2*r["reach"], 1)
L.sort(key=lambda r: -r["score"])

CUT = [
 ("Afroleck", "Already sells online with delivery across Canada"),
 ("Marché Kaïa", "Launched its online store in March 2026"),
 ("Marché Mamou", "Already has online ordering"),
 ("Marché Gouroh", "Already an online grocery for the South Shore"),
 ("Distribution LovaF", "Already sells online with delivery"),
 ("Le Baobab", "Already has an online store at lebaobab.store"),
 ("Sagafrika (Laval)", "Has a site. Hot dishes are pickup only. Lower fit"),
 ("Afro-can (Longueuil)", "Already has a website. Ordering not checked"),
 ("3A Épicerie, Yasolo, Marché du Fouta", "Couldn't confirm an address or that they're still active"),
]
json.dump({"leads": L, "cut": CUT}, open("leads.json", "w"), ensure_ascii=False, indent=1)

wb = openpyxl.Workbook(); ws = wb.active; ws.title = "Prospects"
cols = ["Rank","Company","Score","Fit","Signal","Reachability","Reason to call","Area","Address","Phone (public listing)","Current web presence","Decision-maker","Sources"]
ws.append(cols)
for i, r in enumerate(L, 1):
    ws.append([i, r["name"], r["score"], r["fit"], r["sig"], r["reach"], r["reason"], r["area"], r["addr"], r["phone"], r["web"], r["contact"], r["src"]])
for c in ws[1]:
    c.font = Font(bold=True, color="FFFFFF"); c.fill = PatternFill("solid", fgColor="1F5130")
for col, w in zip("ABCDEFGHIJKLM", [6,28,7,5,7,12,70,24,30,18,34,34,40]):
    ws.column_dimensions[col].width = w
for row in ws.iter_rows(min_row=2):
    for c in row: c.alignment = Alignment(wrap_text=True, vertical="top")
ws.freeze_panes = "C2"
ws2 = wb.create_sheet("Cut - already online")
ws2.append(["Company", "Why cut"])
for c in CUT: ws2.append(list(c))
ws2.column_dimensions["A"].width = 34; ws2.column_dimensions["B"].width = 60
wb.save("african-grocers-montreal-prospects.xlsx")
print(len(L), "leads")
