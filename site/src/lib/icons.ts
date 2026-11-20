// Icon for each category (Lucide names). Unknown categories fall back to "tag".
const CATEGORY_ICONS: Record<string, string> = {
  epicerie: 'shopping-basket',
  'petit-dejeuner': 'coffee',
  'epices-et-condiments': 'flame',
  surgeles: 'snowflake',
  viandes: 'beef',
  'poissons-et-fruits-de-mer': 'fish',
  'fruits-et-legumes': 'carrot',
  perruques: 'sparkles',
  'extensions-capillaires': 'scissors',
  'soins-capillaires': 'droplet',
  'soins-corporels': 'flower-2',
  conserves: 'soup',
  farines: 'wheat',
  'garde-manger': 'package',
  autres: 'ellipsis',
  naturel: 'leaf',
  'semi-naturel': 'sparkles',
  synthetiques: 'sparkles',
  'accessoires-de-cheveux': 'brush',
  'produits-capillaires': 'spray-can',
};

export const categoryIcon = (slug: string) => CATEGORY_ICONS[slug] ?? 'tag';
