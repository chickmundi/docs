import { products, categories } from '../lib/catalog';

const STATIC = ['/', '/boutique/', '/promotions/', '/a-propos/', '/contact/', '/conditions-generales/', '/politique-de-confidentialite/', '/politique-de-remboursement-et-de-retour/'];

export function GET({ site }: { site: URL }) {
  const urls = [
    ...STATIC,
    ...categories.map((c) => `/categorie/${c.slug}/`),
    ...products.map((p) => `/produit/${p.slug}/`),
  ];
  const body = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls
    .map((u) => `  <url><loc>${new URL(u, site).toString()}</loc></url>`)
    .join('\n')}\n</urlset>\n`;
  return new Response(body, { headers: { 'Content-Type': 'application/xml' } });
}
