// Read the English texts that the current site shows under /en/ (TranslatePress)
// and save them for the MCR Gestion importer: wordpress/mcr-gestion/data/en.json.
// Read only: it only loads public pages of the live site, a few at a time.
//
//   NODE_PATH=<dir with node-html-parser> NODE_USE_ENV_PROXY=1 node wordpress/collect-english.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { parse } = require('node-html-parser');
const here = path.dirname(new URL(import.meta.url).pathname);
const catalog = JSON.parse(fs.readFileSync(path.join(here, 'mcr-gestion/data/catalog.json'), 'utf8'));
const pages = JSON.parse(fs.readFileSync(path.join(here, 'mcr-gestion/data/pages.json'), 'utf8'));
const out = path.join(here, 'mcr-gestion/data/en.json');
const BASE = 'https://marchecheminduroy.com';
const prev = fs.existsSync(out) ? JSON.parse(fs.readFileSync(out, 'utf8')) : { products: {}, categories: {}, pages: {} };

const norm = (s) => String(s ?? '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;|\s+/g, ' ').replace(/&#8217;|’/g, "'").trim().toLowerCase();
const clean = (html) =>
  String(html ?? '')
    .replace(/\s(data-trp[\w-]*|data-no-translation[\w-]*)="[^"]*"/g, '')
    .replace(/<\/?trp-[^>]*>/g, '')
    .trim();

const stripDescHeading = (h) => String(h ?? '').replace(/^\s*<h2[^>]*>\s*(Description|Descriptif)\s*<\/h2>\s*/i, '').trim();

async function get(url, tries = 3) {
  for (let i = 0; i < tries; i++) {
    try {
      const r = await fetch(url, { headers: { 'User-Agent': 'MCR-Gestion migration', Accept: 'text/html' }, redirect: 'follow' });
      if (r.status === 404) return null;
      if (r.ok) return await r.text();
    } catch {}
    await new Promise((res) => setTimeout(res, 1500 * (i + 1)));
  }
  return null;
}

async function pool(items, n, fn) {
  let i = 0, done = 0;
  await Promise.all(Array.from({ length: n }, async () => {
    while (i < items.length) {
      const it = items[i++];
      await fn(it);
      if (++done % 50 === 0) console.log(`  ${done}/${items.length}`);
    }
  }));
}

const res = { products: {}, categories: {}, pages: {} };
let same = 0, missing = 0;

console.log('products…');
await pool(catalog.products, 4, async (p) => {
  if (prev.products[p.id]) {
    const e = { ...prev.products[p.id] };
    if (e.description) e.description = stripDescHeading(e.description);
    if (e.description === '' || (e.description && norm(e.description) === norm(p.description))) delete e.description;
    res.products[p.id] = e;
    return;
  }
  const html = await get(`${BASE}/en/product/${encodeURI(p.slug)}/`);
  if (!html) { missing++; return; }
  const doc = parse(html);
  const title = doc.querySelector('h1.product_title')?.text.trim() ?? '';
  const short = clean(doc.querySelector('.woocommerce-product-details__short-description')?.innerHTML);
  const desc = stripDescHeading(clean(doc.querySelector('#tab-description')?.innerHTML));
  const e = {};
  if (title && norm(title) !== norm(p.name)) e.title = title;
  if (short && norm(short) !== norm(p.short_description)) e.short_description = short;
  if (desc && norm(desc) !== norm(p.description)) e.description = desc;
  if (Object.keys(e).length) res.products[p.id] = e; else same++;
});

console.log('categories…');
await pool(catalog.categories, 3, async (c) => {
  const html = await get(`${BASE}/en/product-category/${encodeURI(c.slug)}/`);
  if (!html) return;
  const doc = parse(html);
  const crumbs = doc.querySelectorAll('.woocommerce-breadcrumb li');
  const name = (crumbs.length ? crumbs[crumbs.length - 1].text : '').trim();
  const desc = clean(doc.querySelector('.term-description')?.innerHTML);
  const e = {};
  if (name && norm(name) !== norm(c.name)) e.name = name;
  if (desc && norm(desc) !== norm(c.description)) e.description = desc;
  if (Object.keys(e).length) res.categories[c.slug] = e;
});

console.log('pages…');
const esc = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
for (const [slug, pg] of Object.entries(pages)) {
  if (!pg.html) continue;
  const html = await get(`${BASE}/en/${slug}/`);
  if (!html) continue;
  const doc = parse(html);
  const nodes = doc.querySelectorAll('.elementor-widget-heading .elementor-heading-title, .elementor-widget-text-editor p, .elementor-widget-text-editor li, .elementor-widget-text-editor h2, .elementor-widget-text-editor h3, .elementor-widget-text-editor h4, .entry-content > p, .entry-content > ul > li, .entry-content > h2, .entry-content > h3');
  const blocks = nodes
    .map((n) => ({ tag: n.tagName.toLowerCase(), heading: n.classList.contains('elementor-heading-title') || /^h\d$/i.test(n.tagName), text: n.text.replace(/\s+/g, ' ').trim() }))
    .filter((b) => b.text);
  if (!blocks.length) continue;
  const title = blocks[0].heading ? blocks.shift().text : '';
  let body = '', inList = false;
  for (const b of blocks) {
    if (b.tag === 'li') { if (!inList) body += '<ul>'; inList = true; body += `<li>${esc(b.text)}</li>`; continue; }
    if (inList) { body += '</ul>'; inList = false; }
    body += b.heading || (/^\d+\.\s/.test(b.text) && b.text.length < 90) ? `<h2>${esc(b.text)}</h2>` : `<p>${esc(b.text)}</p>`;
  }
  if (inList) body += '</ul>';
  // The old site left some pages in French under /en/: keep only real English.
  const french = (t) => /\b(nous|votre|vos|chez|les|des|une|et|politique|générales)\b/i.test(t);
  const e = {};
  if (title && norm(title) !== norm(pg.title) && !french(title)) e.title = title;
  if (body && norm(body) !== norm(pg.html) && !french(body.replace(/<[^>]+>/g, ' '))) e.content = body;
  if (Object.keys(e).length) res.pages[slug] = e;
}
// Pages written for the new site (not on the old one): English versions.
res.pages.contact = { title: 'Contact', content: '<p>A question about a product, an order or a delivery? Write or call us: +1 819-372-9966, sc@marchecheminduroy.com, WhatsApp +1 514-578-7442.</p><p>65 Rue Notre-Dame E, Trois-Rivières, QC G8T 4B8</p>' };
res.pages.livraison = { title: 'Delivery', content: '<p>We deliver within 15 km of the store, every day from 9 a.m. to 6 p.m. The fee depends on the driving distance: under 5 km, $5.99; 5 to under 9 km, $6.99; 9 to under 11 km, $7.99; 11 to under 13 km, $8.99; 13 to 15 km, $9.99. Free delivery on orders of $99 or more, within 15 km. Beyond 15 km: in-store pickup.</p>' };
res.pages['ma-liste'] = { title: 'My list' };

fs.writeFileSync(out, JSON.stringify(res));
console.log(`English: ${Object.keys(res.products).length} products, ${Object.keys(res.categories).length} categories, ${Object.keys(res.pages).length} pages; ${same} products not translated on the live site; ${missing} not found.`);
