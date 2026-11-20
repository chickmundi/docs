// Admin interface text. French (Canada) by default, English optional.
import { useSyncExternalStore } from 'react';

export type Lang = 'fr' | 'en';
const KEY = 'mcr-admin-lang';
let lang: Lang = (() => {
  try {
    return (localStorage.getItem(KEY) as Lang) || 'fr';
  } catch {
    return 'fr';
  }
})();
const listeners = new Set<() => void>();

export function setLang(l: Lang) {
  lang = l;
  try {
    localStorage.setItem(KEY, l);
  } catch {}
  listeners.forEach((f) => f());
}

export function useLang(): Lang {
  return useSyncExternalStore(
    (cb) => {
      listeners.add(cb);
      return () => listeners.delete(cb);
    },
    () => lang,
    () => 'fr',
  );
}

const EN: Record<string, string> = {
  'Tableau de bord': 'Dashboard',
  Commandes: 'Orders',
  Produits: 'Products',
  Catégories: 'Categories',
  Marques: 'Brands',
  Inventaire: 'Inventory',
  Clients: 'Customers',
  Promotions: 'Promotions',
  Coupons: 'Coupons',
  Livraison: 'Delivery',
  'Retrait en magasin': 'In-store pickup',
  Paiements: 'Payments',
  Rapports: 'Reports',
  Paramètres: 'Settings',
  "Page d'accueil": 'Homepage',
  'Heures et magasin': 'Hours and store',
  Équipe: 'Team',
  'Se déconnecter': 'Sign out',
  Enregistrer: 'Save',
  Annuler: 'Cancel',
  Supprimer: 'Delete',
  Ajouter: 'Add',
  Modifier: 'Edit',
  Rechercher: 'Search',
  'Voir le site': 'View store',
  'Enregistré.': 'Saved.',
  Nouveau: 'New',
  Nouvelle: 'New',
  Tous: 'All',
  Toutes: 'All',
  Statut: 'Status',
  Prix: 'Price',
  Stock: 'Stock',
  Département: 'Department',
  'Ventes du jour': "Today's sales",
  'Ventes de la semaine': 'Sales this week',
  'Ventes du mois': 'Sales this month',
  'Panier moyen': 'Average order',
  'À préparer': 'To prepare',
  'Prêtes au retrait': 'Ready for pickup',
  'À livrer': 'To deliver',
  'Livrées ce mois': 'Delivered this month',
  'Annulées ce mois': 'Cancelled this month',
  'Non payées': 'Unpaid',
  'Stock faible': 'Low stock',
  'Péremption proche': 'Expiring soon',
  'Meilleures ventes': 'Best sellers',
  'Livraison ou retrait': 'Delivery or pickup',
};

export function useT() {
  const l = useLang();
  return (fr: string) => (l === 'en' ? EN[fr] ?? fr : fr);
}

// Status labels (French / English)
export const FULFILLMENT: Record<string, [string, string]> = {
  received: ['Reçue', 'Received'],
  confirmed: ['Confirmée', 'Confirmed'],
  preparing: ['En préparation', 'Preparing'],
  ready_for_pickup: ['Prête au retrait', 'Ready for pickup'],
  out_for_delivery: ['En livraison', 'Out for delivery'],
  delivered: ['Livrée', 'Delivered'],
  collected: ['Récupérée', 'Collected'],
  cancelled: ['Annulée', 'Cancelled'],
};
export const PAYMENT_STATUS: Record<string, [string, string]> = {
  unpaid: ['Non payée', 'Unpaid'],
  pending: ['Paiement partiel / en attente', 'Pending / partial'],
  paid: ['Payée', 'Paid'],
  partially_refunded: ['Remboursée en partie', 'Partially refunded'],
  refunded: ['Remboursée', 'Refunded'],
  failed: ['Échec du paiement', 'Payment failed'],
};
export const PAYMENT_METHOD: Record<string, [string, string]> = {
  card_online: ['Carte en ligne', 'Card online'],
  pay_on_delivery: ['À la livraison', 'On delivery'],
  pay_in_store: ['En magasin', 'In store'],
};
export const CHANNEL: Record<string, [string, string]> = {
  web: ['Site web', 'Website'],
  whatsapp: ['WhatsApp', 'WhatsApp'],
  email: ['Courriel', 'Email'],
  phone: ['Téléphone', 'Phone'],
  in_store: ['En magasin', 'In store'],
};
export const label = (map: Record<string, [string, string]>, key: string | null | undefined, l: Lang) =>
  key ? (map[key]?.[l === 'en' ? 1 : 0] ?? key) : '—';
