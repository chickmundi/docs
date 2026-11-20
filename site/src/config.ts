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
  deliveryArea: 'Région de Trois-Rivières',
  freeDeliveryThreshold: 9900, // cents
  social: {
    facebook: 'https://www.facebook.com/MarcheCheminduRoy',
    instagram: 'https://www.instagram.com/marchechemindu/',
  },
  // Orders are sent by email. Add a WhatsApp number (digits only, e.g. '18193729966')
  // to also offer "Commander par WhatsApp" in the cart.
  whatsapp: '',
  services: ['Transfert d’argent Ria'],
};
