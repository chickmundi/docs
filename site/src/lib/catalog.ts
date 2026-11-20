import productsData from '../data/products.json';
import categoriesData from '../data/categories.json';

export type Product = (typeof productsData)[number];
export type Category = (typeof categoriesData)[number];

export const products: Product[] = productsData;
// "Tout" is a catch-all category on the old site; it is not shown in navigation.
const HIDDEN = new Set(['tout']);
export const categories: Category[] = categoriesData.filter((c) => !HIDDEN.has(c.slug));

const NAV_ORDER = [
  'epicerie',
  'petit-dejeuner',
  'epices-et-condiments',
  'surgeles',
  'viandes',
  'poissons-et-fruits-de-mer',
  'fruits-et-legumes',
  'perruques',
  'extensions-capillaires',
  'soins-capillaires',
  'soins-corporels',
];

export const topCategories = categories
  .filter((c) => !c.parent)
  .sort((a, b) => {
    const ia = NAV_ORDER.indexOf(a.slug);
    const ib = NAV_ORDER.indexOf(b.slug);
    return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
  });

export const childrenOf = (c: Category) => categories.filter((x) => x.parent === c.id);
export const categoryBySlug = (slug: string) => categories.find((c) => c.slug === slug);
export const categoryById = (id: number) => categories.find((c) => c.id === id);

export function productsIn(c: Category): Product[] {
  const ids = new Set([c.id, ...childrenOf(c).map((x) => x.id)]);
  const list = sortDefault(products.filter((p) => p.categories.some((id) => ids.has(id))));
  // On a parent page, "Autres" (pet food, household items) comes after the real groceries.
  const autres = categoriesData.find((x) => x.slug === 'autres');
  if (!autres || c.id === autres.id || !childrenOf(c).some((k) => k.id === autres.id)) return list;
  const isAutre = (p: Product) => p.categories.includes(autres.id);
  return [...list.filter((p) => !isAutre(p)), ...list.filter(isAutre)];
}

// In stock first, then newest.
export const sortDefault = (list: Product[]) =>
  [...list].sort((a, b) => Number(b.inStock) - Number(a.inStock) || b.id - a.id);

/** Most specific visible category of a product (a sub-category if it has one). */
export function primaryCategory(p: Product): Category | undefined {
  const cats = p.categories.map(categoryById).filter(Boolean) as Category[];
  return cats.find((c) => c.parent) ?? cats[0];
}

const fmt = new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' });
export const money = (cents: number | null | undefined) => (cents == null ? '' : fmt.format(cents / 100));

export function priceLabel(p: Product) {
  if (p.priceRange) return `${money(p.priceRange.min)} – ${money(p.priceRange.max)}`;
  return money(p.price);
}

export const discountPct = (p: Product) =>
  p.onSale && p.regularPrice ? Math.round((1 - (p.price ?? 0) / p.regularPrice) * 100) : 0;

export const plainText = (html: string) =>
  html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

export const PAGE_SIZE = 48;
