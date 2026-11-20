import { store } from '../config';

/** wa.me link with a prefilled message, or null when no WhatsApp number is set. */
export const whatsappLink = (text: string) =>
  store.whatsapp ? `https://wa.me/${store.whatsapp}?text=${encodeURIComponent(text)}` : null;
