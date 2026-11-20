#!/usr/bin/env node
// Turns the imported WooCommerce catalog (src/data/*.json) into SQL insert files for the
// back-office database, keeping WooCommerce product, variation and category IDs.
// Output: supabase/seed/catalog-NN.sql, each under ~1.5 MB. Safe to re-run: every insert
// uses "on conflict do nothing", so existing rows (and admin edits) are never overwritten.
//
// Usage: node scripts/catalog-to-sql.mjs

import { readFile, writeFile, mkdir, rm } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OUT = path.join(ROOT, 'supabase', 'seed');
const products = JSON.parse(await readFile(path.join(ROOT, 'src/data/products.json'), 'utf8'));
const categories = JSON.parse(await readFile(path.join(ROOT, 'src/data/categories.json'), 'utf8'));

// Department of each top-level category. Food categories → Épicerie, hair → Beauté et cheveux.
const DEPT_BY_TOP = {
  epicerie: 1, 'petit-dejeuner': 1, 'epices-et-condiments': 1, surgeles: 1, viandes: 1,
  'poissons-et-fruits-de-mer': 1, 'fruits-et-legumes': 1,
  perruques: 2, 'extensions-capillaires': 2, 'soins-capillaires': 2,
  'soins-corporels': 3,
};
const ICONS = {
  epicerie: 'shopping-basket', 'petit-dejeuner': 'coffee', 'epices-et-condiments': 'flame', surgeles: 'snowflake',
  viandes: 'beef', 'poissons-et-fruits-de-mer': 'fish', 'fruits-et-legumes': 'carrot', perruques: 'sparkles',
  'extensions-capillaires': 'scissors', 'soins-capillaires': 'droplet', 'soins-corporels': 'flower-2',
};
const NAV_ORDER = Object.keys(DEPT_BY_TOP);

const byId = new Map(categories.map((c) => [c.id, c]));

// WooCommerce reuses some placeholder SKUs across many unrelated products. Those are kept as
// "legacy_sku" (extra attribute) and the SKU field is left empty for staff to fill in.
const skuCount = new Map();
for (const p of products) if (p.sku) skuCount.set(p.sku, (skuCount.get(p.sku) ?? 0) + 1);
const isRealSku = (sku) => Boolean(sku) && skuCount.get(sku) === 1;
const topOf = (c) => (c.parent ? topOf(byId.get(c.parent)) : c);
const deptOfCategory = (c) => DEPT_BY_TOP[topOf(c).slug] ?? null;

// ---------- SQL helpers ----------
const q = (v) => {
  if (v === null || v === undefined || v === '') return 'null';
  if (typeof v === 'number') return Number.isFinite(v) ? String(v) : 'null';
  if (typeof v === 'boolean') return v ? 'true' : 'false';
  return `'${String(v).replace(/'/g, "''")}'`;
};
const arr = (a) => (a.length ? `array[${a.map(q).join(',')}]::text[]` : `'{}'::text[]`);
const json = (o) => `${q(JSON.stringify(o))}::jsonb`;
const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

const chunks = [];
let current = [];
let size = 0;
const emit = (sql) => {
  current.push(sql);
  size += sql.length;
  if (size > 1_400_000) {
    chunks.push(current);
    current = [];
    size = 0;
  }
};

// ---------- Categories (parents first) ----------
const sortedCats = [...categories].sort((a, b) => (a.parent ? 1 : 0) - (b.parent ? 1 : 0));
for (const c of sortedCats) {
  const sort = NAV_ORDER.indexOf(c.slug);
  emit(
    `insert into categories (id, slug, name_fr, description_fr, parent_id, department_id, image_url, icon, sort, visible) values (` +
      [c.id, q(c.slug), q(c.name), q(c.description), c.parent ?? 'null', q(deptOfCategory(c)), q(c.image), q(ICONS[c.slug]), sort === -1 ? 99 : sort, c.slug === 'tout' ? 'false' : 'true'].join(', ') +
      `) on conflict (id) do nothing;`,
  );
}
emit(`select setval('categories_id_seq', greatest(100000, (select max(id) + 1 from categories)), false);`);

// ---------- Products ----------
let variantCount = 0;
let imageCount = 0;
for (const p of products) {
  const cats = p.categories.map((id) => byId.get(id)).filter(Boolean);
  const depts = [...new Set(cats.map(deptOfCategory).filter(Boolean))];
  const dept = depts[0] ?? null;
  const hasVariants = p.type === 'variable' && p.variations.length > 0;
  const saleUnit = p.unit === 'par kg' ? 'weight' : 'unit';
  const optionAttrs = p.attributes.filter((a) => a.variation);
  const infoAttrs = p.attributes.filter((a) => !a.variation);
  const extra = Object.fromEntries(infoAttrs.map((a) => [a.name, a.values.join(', ')]));
  if (p.externalUrl) extra.external_url = p.externalUrl;
  if (p.sku && !isRealSku(p.sku)) extra.legacy_sku = p.sku;

  emit(
    `insert into products (id, slug, legacy_slug, status, name_fr, short_description_fr, description_fr, department_id, has_variants, ` +
      `price_cents, regular_price_cents, sale_unit, unit_label, sku, track_stock, stock_qty, low_stock_threshold, in_stock, extra_attributes, tags, seo_description, source_url) values (` +
      [
        p.id,
        q(p.slug),
        q(p.sourceSlug),
        `'published'`,
        q(p.name),
        q(p.shortDescription),
        q(p.description),
        q(dept),
        q(hasVariants),
        q(p.price),
        q(p.regularPrice),
        `'${saleUnit}'`,
        q(p.unit),
        q(isRealSku(p.sku) ? p.sku : null),
        // The public store data only gives exact stock when it is low; track stock where it is known.
        q(p.lowStock != null),
        q(p.lowStock),
        'null',
        q(p.inStock),
        json(extra),
        arr(p.tags),
        q(p.shortDescription ? p.shortDescription.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160) : null),
        q(p.sourceUrl),
      ].join(', ') +
      `) on conflict (id) do nothing;`,
  );
  if (p.categories.length) {
    emit(
      `insert into product_categories (product_id, category_id) values ${p.categories.map((c) => `(${p.id}, ${c})`).join(', ')} on conflict do nothing;`,
    );
  }
  optionAttrs.forEach((a, i) =>
    emit(`insert into product_options (product_id, name, values_list, sort) values (${p.id}, ${q(cap(a.name))}, ${arr(a.values)}, ${i}) on conflict do nothing;`),
  );
  for (const [i, v] of p.variations.entries()) {
    variantCount++;
    const options = Object.fromEntries(Object.entries(v.attributes).map(([k, val]) => [cap(k), val]));
    emit(
      `insert into product_variants (id, product_id, options, price_cents, regular_price_cents, in_stock, sort) values (` +
        [v.id, p.id, json(options), q(v.price), q(v.regularPrice), q(v.inStock), i].join(', ') +
        `) on conflict (id) do nothing;`,
    );
  }
  for (const [i, im] of p.images.entries()) {
    imageCount++;
    emit(
      `insert into product_images (product_id, url, alt, source_url, sort) select ${p.id}, ${q(im.src)}, ${q(im.alt)}, ${q(im.source)}, ${i} ` +
        `where not exists (select 1 from product_images where product_id = ${p.id} and url = ${q(im.src)});`,
    );
  }
}
emit(`select setval('products_id_seq', greatest(100000, (select max(id) + 1 from products)), false);`);
emit(`select setval('product_variants_id_seq', greatest(100000, (select max(id) + 1 from product_variants)), false);`);
if (current.length) chunks.push(current);

await rm(OUT, { recursive: true, force: true });
await mkdir(OUT, { recursive: true });
for (const [i, c] of chunks.entries()) {
  const file = path.join(OUT, `catalog-${String(i + 1).padStart(2, '0')}.sql`);
  await writeFile(file, `-- Generated by scripts/catalog-to-sql.mjs\nbegin;\n${c.join('\n')}\ncommit;\n`);
}
console.log(`${categories.length} categories, ${products.length} products, ${variantCount} variations, ${imageCount} images -> ${chunks.length} file(s) in supabase/seed/`);
