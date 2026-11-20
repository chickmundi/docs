#!/usr/bin/env node
// Imports every product and category from the existing WooCommerce store
// (public Store API, no credentials needed) into src/data/*.json and
// downloads product images into public/images/products as optimized WebP.
//
// Usage: npm run import            (products + images)
//        npm run import -- --no-images   (data only)

import { mkdir, writeFile, access } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const SOURCE = 'https://marchecheminduroy.com';
const API = `${SOURCE}/wp-json/wc/store/v1`;
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DATA_DIR = path.join(ROOT, 'src', 'data');
const IMG_DIR = path.join(ROOT, 'public', 'images', 'products');
const IMG_MAX = 800;
const CONCURRENCY = 8;
const withImages = !process.argv.includes('--no-images');

async function getJSON(url, attempt = 1) {
  const res = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!res.ok) {
    if (attempt < 4 && res.status >= 500) {
      await new Promise((r) => setTimeout(r, 1000 * attempt));
      return getJSON(url, attempt + 1);
    }
    throw new Error(`${res.status} ${res.statusText} for ${url}`);
  }
  return { body: await res.json(), headers: res.headers };
}

async function getAllPages(endpoint) {
  const items = [];
  let page = 1;
  let totalPages = 1;
  do {
    const sep = endpoint.includes('?') ? '&' : '?';
    const { body, headers } = await getJSON(`${API}${endpoint}${sep}per_page=100&page=${page}`);
    items.push(...body);
    totalPages = Number(headers.get('x-wp-totalpages') || 1);
    process.stdout.write(`\r  ${endpoint}: page ${page}/${totalPages} (${items.length})`);
    page++;
  } while (page <= totalPages);
  process.stdout.write('\n');
  return items;
}

const NAMED = {
  amp: '&', quot: '"', apos: "'", lt: '<', gt: '>', nbsp: ' ', rsquo: '’', lsquo: '‘', rdquo: '”', ldquo: '“',
  laquo: '«', raquo: '»', ndash: '–', mdash: '—', hellip: '…', Prime: '″', prime: '′', times: '×', deg: '°',
  eacute: 'é', egrave: 'è', ecirc: 'ê', agrave: 'à', acirc: 'â', ccedil: 'ç', ocirc: 'ô', icirc: 'î', ucirc: 'û',
  euro: '€', reg: '®', trade: '™', copy: '©', frac12: '½', frac14: '¼',
};

// Some text is double-encoded on the old site ("&amp;rsquo;"), so decode until stable.
function decode(input = '') {
  let s = input;
  for (let i = 0; i < 3; i++) {
    const next = s
      .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
      .replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCodePoint(parseInt(n, 16)))
      .replace(/&([a-zA-Z0-9]+);/g, (m, name) => NAMED[name] ?? m);
    if (next === s) break;
    s = next;
  }
  return s;
}

// Keep simple formatting tags only; drop scripts, styles, attributes and links to the old site.
function cleanHtml(html = '') {
  return html
    .replace(/<(script|style|iframe)[^>]*>[\s\S]*?<\/\1>/gi, '')
    .replace(/<(\/?)(p|br|ul|ol|li|strong|b|em|i|h[2-6]|table|thead|tbody|tr|td|th)(\s[^>]*)?>/gi, '<$1$2>')
    .replace(/<(?!\/?(p|br|ul|ol|li|strong|b|em|i|h[2-6]|table|thead|tbody|tr|td|th)>)[^>]+>/gi, '')
    .replace(/<p>\s*<\/p>/g, '')
    .trim();
}

const toText = (html = '') => decode(html.replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();

// i0.wp.com/marchecheminduroy.com/wp-content/... -> https://marchecheminduroy.com/wp-content/...
function originalImageUrl(src) {
  const u = new URL(src);
  if (/^i\d\.wp\.com$/.test(u.hostname)) return `https://${u.pathname.slice(1)}`;
  u.search = '';
  return u.toString();
}

// Plain ASCII slugs: "18%e2%80%b3" -> "18", accents removed.
function cleanSlug(slug) {
  let s = slug;
  try {
    s = decodeURIComponent(slug);
  } catch {}
  return s
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

const cents = (v) => (v == null || v === '' ? null : Number(v));

function unitFromPriceHtml(priceHtml = '') {
  const text = toText(priceHtml).toLowerCase();
  const m = text.match(/(par|\/)\s*(kg|lb|livre|unité|paquet)/);
  return m ? `par ${m[2]}` : null;
}

async function fileExists(p) {
  try {
    await access(p);
    return true;
  } catch {
    return false;
  }
}

async function downloadImage(url, file) {
  const dest = path.join(IMG_DIR, file);
  if (await fileExists(dest)) return true;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`${res.status}`);
      const buf = Buffer.from(await res.arrayBuffer());
      await sharp(buf)
        .rotate()
        .resize({ width: IMG_MAX, height: IMG_MAX, fit: 'inside', withoutEnlargement: true })
        .webp({ quality: 80 })
        .toFile(dest);
      return true;
    } catch (err) {
      if (attempt === 3) {
        console.warn(`\n  ! image failed: ${url} (${err.message})`);
        return false;
      }
      await new Promise((r) => setTimeout(r, 800 * attempt));
    }
  }
}

async function pool(items, worker, size) {
  let i = 0;
  let done = 0;
  const runners = Array.from({ length: size }, async () => {
    while (i < items.length) {
      const item = items[i++];
      await worker(item);
      done++;
      if (done % 25 === 0 || done === items.length) process.stdout.write(`\r  images: ${done}/${items.length}`);
    }
  });
  await Promise.all(runners);
  process.stdout.write('\n');
}

async function main() {
  await mkdir(DATA_DIR, { recursive: true });
  await mkdir(IMG_DIR, { recursive: true });

  console.log('Fetching categories…');
  const rawCats = await getAllPages('/products/categories');
  const categories = rawCats
    .map((c) => ({
      id: c.id,
      name: decode(c.name),
      slug: c.slug,
      parent: c.parent || null,
      description: toText(c.description),
      count: c.count,
      image: c.image ? originalImageUrl(c.image.src) : null,
      parentSlug: null,
    }))
    .sort((a, b) => a.name.localeCompare(b.name, 'fr'));
  for (const c of categories) c.parentSlug = categories.find((x) => x.id === c.parent)?.slug ?? null;

  console.log('Fetching products…');
  const raw = await getAllPages('/products');

  // Variation prices live on separate records.
  const variationIds = raw.flatMap((p) => (p.type === 'variable' ? p.variations.map((v) => v.id) : []));
  console.log(`Fetching ${variationIds.length} variations…`);
  const variationById = new Map();
  await pool(
    variationIds,
    async (id) => {
      try {
        const { body } = await getJSON(`${API}/products/${id}`);
        variationById.set(id, body);
      } catch (err) {
        console.warn(`\n  ! variation ${id}: ${err.message}`);
      }
    },
    CONCURRENCY,
  );

  const images = new Map(); // url -> file
  const seenSlugs = new Set();

  const products = raw.map((p) => {
    let slug = cleanSlug(p.slug) || String(p.id);
    if (seenSlugs.has(slug)) slug = `${slug}-${p.id}`;
    seenSlugs.add(slug);

    const imgs = p.images.map((im, idx) => {
      const url = originalImageUrl(im.src);
      if (!images.has(url)) images.set(url, `${p.id}-${idx}.webp`);
      return { src: `/images/products/${images.get(url)}`, alt: decode(im.alt) || decode(p.name), source: url };
    });

    const variations = p.type === 'variable'
      ? p.variations.map((v) => {
          const full = variationById.get(v.id);
          return {
            id: v.id,
            attributes: Object.fromEntries(v.attributes.map((a) => [a.name, a.value])),
            price: full ? cents(full.prices.price) : null,
            regularPrice: full ? cents(full.prices.regular_price) : null,
            inStock: full ? full.is_in_stock : true,
          };
        })
      : [];

    const price = cents(p.prices.price);
    const regularPrice = cents(p.prices.regular_price);
    const range = p.prices.price_range
      ? { min: cents(p.prices.price_range.min_amount), max: cents(p.prices.price_range.max_amount) }
      : variations.length
        ? (() => {
            const vals = variations.map((v) => v.price).filter((v) => v != null);
            return vals.length ? { min: Math.min(...vals), max: Math.max(...vals) } : null;
          })()
        : null;

    return {
      id: p.id,
      slug,
      name: decode(p.name).trim(),
      type: p.type,
      sku: p.sku || null,
      price,
      regularPrice,
      onSale: Boolean(p.on_sale) && regularPrice != null && price != null && price < regularPrice,
      priceRange: range && range.min !== range.max ? range : null,
      unit: unitFromPriceHtml(p.price_html) || (/à peser|au kg|par kg/i.test(decode(p.name)) ? 'par kg' : null),
      currency: p.prices.currency_code,
      inStock: p.is_in_stock,
      lowStock: p.low_stock_remaining ?? null,
      categories: p.categories.map((c) => c.id),
      tags: p.tags.map((t) => decode(t.name)),
      shortDescription: cleanHtml(decode(p.short_description)),
      description: cleanHtml(decode(p.description)),
      images: imgs,
      attributes: p.attributes
        .filter((a) => a.terms?.length)
        .map((a) => ({ name: decode(a.name), variation: a.has_variations, values: a.terms.map((t) => decode(t.name)) })),
      variations,
      externalUrl: p.type === 'external' ? p.add_to_cart?.url || null : null,
      sourceSlug: p.slug,
      sourceUrl: p.permalink,
    };
  });

  await writeFile(path.join(DATA_DIR, 'categories.json'), JSON.stringify(categories, null, 2) + '\n');
  await writeFile(path.join(DATA_DIR, 'products.json'), JSON.stringify(products, null, 2) + '\n');
  console.log(`Saved ${products.length} products and ${categories.length} categories to src/data/`);

  if (withImages) {
    console.log(`Downloading ${images.size} images…`);
    const failed = new Set();
    await pool(
      [...images.entries()],
      async ([url, file]) => {
        if (!(await downloadImage(url, file))) failed.add(`/images/products/${file}`);
      },
      CONCURRENCY,
    );
    if (failed.size) {
      for (const p of products) p.images = p.images.filter((im) => !failed.has(im.src));
      await writeFile(path.join(DATA_DIR, 'products.json'), JSON.stringify(products, null, 2) + '\n');
      console.warn(`${failed.size} images could not be downloaded and were removed from the data.`);
    }
  }

  if (withImages) {
    const catDir = path.join(ROOT, 'public', 'images', 'categories');
    await mkdir(catDir, { recursive: true });
    for (const c of categories) {
      if (!c.image) continue;
      const file = `${c.slug}.webp`;
      const dest = path.join(catDir, file);
      if (!(await fileExists(dest))) {
        try {
          const res = await fetch(c.image);
          if (!res.ok) throw new Error(String(res.status));
          await sharp(Buffer.from(await res.arrayBuffer())).resize({ width: 480, height: 480, fit: 'inside', withoutEnlargement: true }).webp({ quality: 82 }).toFile(dest);
        } catch (err) {
          console.warn(`  ! category image ${c.slug}: ${err.message}`);
          c.image = null;
          continue;
        }
      }
      c.image = `/images/categories/${file}`;
    }
    await writeFile(path.join(DATA_DIR, 'categories.json'), JSON.stringify(categories, null, 2) + '\n');
  }

  await writeHtaccess(products, categories);

  const noImage = products.filter((p) => !p.images.length).length;
  console.log(`Done. ${noImage} products have no image.`);
}

// Apache rules for Hostinger: old WooCommerce URLs -> new pages (301), plus caching.
async function writeHtaccess(products, categories) {
  const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const changed = products
    .filter((p) => p.sourceSlug !== p.slug)
    .map((p) => {
      let decoded = p.sourceSlug;
      try {
        decoded = decodeURIComponent(p.sourceSlug);
      } catch {}
      // Match the ASCII parts and allow anything where special characters were.
      const pattern = decoded.split(/([^A-Za-z0-9-]+)/).map((part, i) => (i % 2 ? '.*' : esc(part))).join('');
      return `RewriteRule ^(?:fr|en)/product/${pattern}/?$ /produit/${p.slug}/ [R=301,L,NC]`;
    });
  const lines = [
    '# Generated by scripts/import-products.mjs',
    'Options -MultiViews',
    'ErrorDocument 404 /404.html',
    '',
    '<IfModule mod_rewrite.c>',
    'RewriteEngine On',
    '',
    '# Products whose address changed',
    ...changed,
    '',
    '# Old WooCommerce addresses',
    'RewriteRule ^(?:fr|en)/product/([^/]+)/?$ /produit/$1/ [R=301,L,NC]',
    'RewriteRule ^(?:fr|en)/product-category/(?:.*/)?tout/?$ /boutique/ [R=301,L,NC]',
    'RewriteRule ^(?:fr|en)/product-category/(?:[^/]+/)*([^/]+)/?$ /categorie/$1/ [R=301,L,NC]',
    'RewriteRule ^(?:fr|en)/(?:panier|cart|caisse|checkout)/?$ /panier/ [R=301,L,NC]',
    'RewriteRule ^(?:fr|en)/(?:boutique|shop|nouveautes)/?$ /boutique/ [R=301,L,NC]',
    'RewriteRule ^(?:fr|en)/(a-propos|contact|conditions-generales|politique-de-confidentialite|politique-de-remboursement-et-de-retour)/?$ /$1/ [R=301,L,NC]',
    'RewriteRule ^(?:fr|en)(?:/.*)?$ / [R=301,L,NC]',
    'RewriteRule ^(?:product|product-category|wp-content|wp-admin|wp-login\\.php)(?:/.*)?$ / [R=301,L,NC]',
    '</IfModule>',
    '',
    '<IfModule mod_expires.c>',
    'ExpiresActive On',
    'ExpiresByType image/webp "access plus 30 days"',
    'ExpiresByType image/png "access plus 30 days"',
    'ExpiresByType text/css "access plus 1 year"',
    'ExpiresByType application/javascript "access plus 1 year"',
    '</IfModule>',
    '',
    '<IfModule mod_deflate.c>',
    'AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml application/xml',
    '</IfModule>',
    '',
  ];
  // categories is unused for now but kept for future category-specific rules
  void categories;
  await writeFile(path.join(ROOT, 'public', '.htaccess'), lines.join('\n'));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
