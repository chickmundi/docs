import { store } from '../config';

export type DeliveryQuote =
  | { ok: true; km: number; fee: number; free: boolean }
  | { ok: false; km: number; reason: 'too-far' };

/** Distance is rounded to 0.1 km first, so the tier always matches the distance shown. */
export const roundKm = (km: number) => Math.round(km * 10) / 10;

export function quoteDelivery(rawKm: number, subtotal: number): DeliveryQuote {
  const km = roundKm(rawKm);
  if (km > store.maxDeliveryKm) return { ok: false, km, reason: 'too-far' };
  const tiers = store.deliveryTiers;
  const tier =
    tiers.find((t, i) => km >= t.fromKm && (i === tiers.length - 1 ? km <= t.toKm : km < t.toKm)) ?? tiers[tiers.length - 1];
  const free = subtotal >= store.freeDeliveryThreshold;
  return { ok: true, km, fee: free ? 0 : tier.fee, free };
}

export const minDeliveryFee = () => Math.min(...store.deliveryTiers.map((t) => t.fee));
export const maxDeliveryFee = () => Math.max(...store.deliveryTiers.map((t) => t.fee));
