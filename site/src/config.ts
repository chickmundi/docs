// Store details used across the site. Edit here to update everywhere.
export const store = {
  name: 'Marché Chemin-du-Roy',
  shortName: 'MCR',
  tagline: 'Épicerie, saveurs d’Afrique et beauté capillaire à Trois-Rivières',
  phone: '+1 819-372-9966',
  phoneHref: 'tel:+18193729966',
  email: 'sc@marchecheminduroy.com',
  address: {
    street: '65 Rue Notre-Dame E',
    city: 'Trois-Rivières',
    region: 'QC',
    postalCode: 'G8T 4B8',
    country: 'Canada',
  },
  mapUrl: 'https://www.google.com/maps/search/?api=1&query=65+Rue+Notre-Dame+E,+Trois-Rivi%C3%A8res,+QC+G8T+4B8',
  hours: [
    { days: 'Lundi au samedi', time: '8 h 30 à 22 h' },
    { days: 'Dimanche', time: '9 h à 22 h' },
  ],
  deliveryHours: 'Livraison tous les jours de 9 h à 18 h',
  // Store position, used to measure the road distance to each customer.
  location: { lat: 46.3629863, lon: -72.5258898 },
  deliveryArea: 'Région de Trois-Rivières',
  freeDeliveryThreshold: 9900, // cents (only within maxDeliveryKm)
  // Delivery fee by road distance from the store. A tier applies from `fromKm` (included)
  // up to `toKm` (excluded), except the last tier, which includes `toKm`.
  deliveryTiers: [
    { fromKm: 0, toKm: 5, fee: 599 },
    { fromKm: 5, toKm: 9, fee: 699 },
    { fromKm: 9, toKm: 11, fee: 799 },
    { fromKm: 11, toKm: 13, fee: 899 },
    { fromKm: 13, toKm: 15, fee: 999 },
  ],
  maxDeliveryKm: 15, // no delivery beyond this
  social: {
    facebook: 'https://www.facebook.com/MarcheCheminduRoy',
    instagram: 'https://www.instagram.com/marchechemindu/',
  },
  // Orders are sent by email. Add a WhatsApp number (digits only, e.g. '18193729966')
  // to also offer "Commander par WhatsApp" in the cart.
  whatsapp: '',
  services: ['Transfert d’argent Ria'],
};
