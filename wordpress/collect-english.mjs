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
  if (prev.products[p.id]) { res.products[p.id] = prev.products[p.id]; return; }
  const html = await get(`${BASE}/en/product/${encodeURI(p.slug)}/`);
  if (!html) { missing++; return; }
  const doc = parse(html);
  const title = doc.querySelector('h1.product_title')?.text.trim() ?? '';
  const short = clean(doc.querySelector('.woocommerce-product-details__short-description')?.innerHTML);
  const desc = clean(doc.querySelector('#tab-description')?.innerHTML.replace(/^\s*<h2>[^<]*<\/h2>/, ''));
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
  const name = (doc.querySelector('h1.page-title, h1.entry-title, .woocommerce-products-header__title, h1')?.text ?? '').trim();
  const desc = clean(doc.querySelector('.term-description')?.innerHTML);
  const e = {};
  if (name && norm(name) !== norm(c.name)) e.name = name;
  if (desc && norm(desc) !== norm(c.description)) e.description = desc;
  if (Object.keys(e).length) res.categories[c.slug] = e;
});

console.log('pages…');
for (const [slug, pg] of Object.entries(pages)) {
  const html = await get(`${BASE}/en/${slug}/`);
  if (!html) continue;
  const doc = parse(html);
  const title = (doc.querySelector('h1.entry-title, h1.page-title, h1')?.text ?? '').trim();
  const content = clean((doc.querySelector('.entry-content') ?? doc.querySelector('article'))?.innerHTML);
  const e = {};
  if (title && norm(title) !== norm(pg.title)) e.title = title;
  if (content && norm(content) !== norm(pg.html)) e.content = content;
  if (Object.keys(e).length) res.pages[slug] = e;
}

fs.writeFileSync(out, JSON.stringify(res));
console.log(`English: ${Object.keys(res.products).length} products, ${Object.keys(res.categories).length} categories, ${Object.keys(res.pages).length} pages; ${same} products not translated on the live site; ${missing} not found.`);
