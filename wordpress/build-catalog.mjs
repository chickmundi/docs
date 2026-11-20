#!/usr/bin/env node
// Builds wordpress/mcr-gestion/data/catalog.json (for the test-site importer) from site/src/data.
import { readFileSync, writeFileSync } from 'node:fs';

const products = JSON.parse(readFileSync('site/src/data/products.json', 'utf8'));
const categories = JSON.parse(readFileSync('site/src/data/categories.json', 'utf8'));
const byId = new Map(categories.map((c) => [c.id, c]));
const topOf = (c) => (c.parent ? topOf(byId.get(c.parent)) : c);
const DEPT = {
  epicerie: 'epicerie', 'petit-dejeuner': 'epicerie', 'epices-et-condiments': 'epicerie', surgeles: 'epicerie', viandes: 'epicerie',
  'poissons-et-fruits-de-mer': 'epicerie', 'fruits-et-legumes': 'epicerie',
  perruques: 'beaute-cheveux', 'extensions-capillaires': 'beaute-cheveux', 'soins-capillaires': 'beaute-cheveux',
  'soins-corporels': 'soins-corporels',
};
const ICONS = {
  epicerie: 'shopping-basket', 'petit-dejeuner': 'coffee', 'epices-et-condiments': 'flame', surgeles: 'snowflake', viandes: 'beef',
  'poissons-et-fruits-de-mer': 'fish', 'fruits-et-legumes': 'carrot', perruques: 'sparkles', 'extensions-capillaires': 'scissors',
  'soins-capillaires': 'droplet', 'soins-corporels': 'flower-2', conserves: 'soup', farines: 'wheat', 'garde-manger': 'package', autres: 'ellipsis',
};
const ORDER = Object.keys(DEPT);
const skuCount = new Map();
for (const p of products) if (p.sku) skuCount.set(p.sku, (skuCount.get(p.sku) ?? 0) + 1);
const money = (c) => (c == null ? null : (c / 100).toFixed(2));

const out = {
  categories: categories.map((c) => ({
    slug: c.slug, name: c.name, description: c.description || '', parent: c.parent ? byId.get(c.parent).slug : null,
    image: c.image, icon: ICONS[c.slug] ?? 'tag', sort: ORDER.indexOf(c.slug) === -1 ? 99 : ORDER.indexOf(c.slug),
  })),
  products: products.map((p) => {
    const depts = [...new Set(p.categories.map((id) => byId.get(id)).filter(Boolean).map((c) => DEPT[topOf(c).slug]).filter(Boolean))];
    return {
      id: p.id, slug: p.sourceSlug, name: p.name, type: p.type,
      price: money(p.price), regular_price: money(p.regularPrice),
      sku: p.sku && skuCount.get(p.sku) === 1 ? p.sku : null, legacy_sku: p.sku && skuCount.get(p.sku) > 1 ? p.sku : null,
      in_stock: p.inStock, low_stock: p.lowStock ?? null, unit: p.unit,
      categories: p.categories.map((id) => byId.get(id)?.slug).filter(Boolean), department: depts[0] ?? null, tags: p.tags,
      short_description: p.shortDescription, description: p.description,
      images: p.images.map((i) => ({ src: i.source, alt: i.alt })),
      attributes: p.attributes.map((a) => ({ name: a.name.charAt(0).toUpperCase() + a.name.slice(1), values: a.values, variation: a.variation })),
      variations: p.variations.map((v) => ({
        id: v.id, price: money(v.price), regular_price: money(v.regularPrice), in_stock: v.inStock,
        attributes: Object.fromEntries(Object.entries(v.attributes).map(([k, val]) => [k.charAt(0).toUpperCase() + k.slice(1), val])),
      })),
      external_url: p.externalUrl,
    };
  }),
};
writeFileSync('wordpress/mcr-gestion/data/catalog.json', JSON.stringify(out));
console.log(out.categories.length, 'categories,', out.products.length, 'products,', out.products.reduce((n, p) => n + p.variations.length, 0), 'variations');

// Information pages (text from the live site) as simple HTML.
const pages = JSON.parse(readFileSync('site/src/data/pages.json', 'utf8'));
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const html = (blocks) => {
  let out = '';
  let inList = false;
  for (const b of blocks) {
    if (b.type === 'li') {
      if (!inList) out += '<ul>';
      inList = true;
      out += `<li>${esc(b.text)}</li>`;
      continue;
    }
    if (inList) out += '</ul>';
    inList = false;
    out += b.type === 'h' || (/^\d+\.\s/.test(b.text) && b.text.length < 90) ? `<h2>${esc(b.text)}</h2>` : `<p>${esc(b.text)}</p>`;
  }
  return out + (inList ? '</ul>' : '');
};
const outPages = {};
for (const [slug, p] of Object.entries(pages)) {
  if (slug === 'suivi-de-commande') continue;
  const blocks = p.blocks[0]?.type === 'h' ? p.blocks.slice(1) : p.blocks;
  outPages[slug] = { title: p.title, html: html(blocks) };
}
outPages.contact = { title: 'Contact', html: '<p>Une question sur un produit, une commande ou une livraison ? Écrivez-nous ou appelez-nous : +1 819-372-9966, sc@marchecheminduroy.com, WhatsApp +1 514-578-7442.</p><p>65 Rue Notre-Dame E, Trois-Rivières, QC G8T 4B8</p>' };
outPages.livraison = { title: 'Livraison', html: '<p>Nous livrons dans un rayon de 15 km autour du magasin, tous les jours de 9 h à 18 h. Les frais dépendent de la distance par la route : moins de 5 km, 5,99 $ ; de 5 à moins de 9 km, 6,99 $ ; de 9 à moins de 11 km, 7,99 $ ; de 11 à moins de 13 km, 8,99 $ ; de 13 à 15 km, 9,99 $. Livraison gratuite dès 99 $ d’achat, jusqu’à 15 km. Au-delà de 15 km : retrait en magasin.</p>' };
outPages['ma-liste'] = { title: 'Ma liste', html: '' };
writeFileSync('wordpress/mcr-gestion/data/pages.json', JSON.stringify(outPages));
console.log(Object.keys(outPages).length, 'pages:', Object.keys(outPages).join(', '));
