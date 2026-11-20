import { products, primaryCategory, priceLabel } from '../lib/catalog';

// Compact index loaded by /recherche/ in the browser.
export function GET() {
  const rows = products.map((p) => [
    p.slug,
    p.name,
    primaryCategory(p)?.name ?? '',
    priceLabel(p) + (p.unit ? ` ${p.unit}` : ''),
    p.images[0]?.src ?? '',
    p.inStock ? 1 : 0,
    p.tags.join(' '),
  ]);
  return new Response(JSON.stringify(rows), { headers: { 'Content-Type': 'application/json' } });
}
