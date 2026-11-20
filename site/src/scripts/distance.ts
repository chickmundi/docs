// Finds a customer's address and measures the road distance from the store.
// Address lookup: OpenStreetMap Nominatim, falling back to Photon. Road distance: OSRM.
// These free services allow light use; for heavy traffic, switch to a paid provider here.
import { store } from '../config';

export type Place = { lat: number; lon: number; label: string };

// Mauricie / Centre-du-Québec area. Results here are preferred (so "Rue Notre-Dame" matches
// Trois-Rivières first), but addresses elsewhere are still found, so they can be refused as too far.
const VIEWBOX = { west: -73.4, south: 45.9, east: -71.8, north: 46.9 };

async function getJSON(url: string, timeoutMs = 8000) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await fetch(url, { signal: ctrl.signal, headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  } finally {
    clearTimeout(t);
  }
}

async function nominatim(q: string): Promise<Place | null> {
  const p = new URLSearchParams({
    format: 'jsonv2',
    limit: '1',
    countrycodes: 'ca',
    'accept-language': 'fr',
    viewbox: `${VIEWBOX.west},${VIEWBOX.north},${VIEWBOX.east},${VIEWBOX.south}`,
    q,
  });
  const d = await getJSON(`https://nominatim.openstreetmap.org/search?${p}`);
  return d?.[0] ? { lat: Number(d[0].lat), lon: Number(d[0].lon), label: d[0].display_name } : null;
}

async function photon(q: string): Promise<Place | null> {
  const p = new URLSearchParams({
    q,
    limit: '1',
    lat: String(store.location.lat),
    lon: String(store.location.lon),
  });
  const d = await getJSON(`https://photon.komoot.io/api/?${p}`);
  const f = d?.features?.[0];
  if (!f) return null;
  const pr = f.properties ?? {};
  const label = [[pr.housenumber, pr.street ?? pr.name].filter(Boolean).join(' '), pr.city, pr.postcode].filter(Boolean).join(', ');
  return { lon: f.geometry.coordinates[0], lat: f.geometry.coordinates[1], label };
}

export async function geocode(q: string): Promise<Place | null> {
  let nominatimAnswered = false;
  for (let attempt = 0; attempt < 2 && !nominatimAnswered; attempt++) {
    try {
      const r = await nominatim(q);
      if (r) return r;
      nominatimAnswered = true; // a clean "not found"
    } catch {
      // Rate limited (about 1 request per second) or down: wait and retry once, then use the backup.
      if (attempt === 0) await new Promise((res) => setTimeout(res, 1500));
    }
  }
  try {
    return await photon(q);
  } catch (err) {
    // The backup failing after a clean "not found" still means "not found", not "service down".
    if (nominatimAnswered) return null;
    throw err;
  }
}

/** Road distance in km from the store to a place. */
export async function roadDistanceKm(to: Place): Promise<number> {
  const { lat, lon } = store.location;
  const d = await getJSON(`https://router.project-osrm.org/route/v1/driving/${lon},${lat};${to.lon},${to.lat}?overview=false`, 10000);
  if (d?.code !== 'Ok' || !d.routes?.length) throw new Error('route');
  return d.routes[0].distance / 1000;
}

const CACHE = 'mcr-distance-v1';
const inFlight = new Map<string, Promise<{ km: number; label: string } | null>>();

/** Cached per address for the browser session. Returns null when the address can't be found. */
export function distanceForAddress(address: string): Promise<{ km: number; label: string } | null> {
  const key = address.trim().toLowerCase().replace(/\s+/g, ' ');
  // Several triggers (typing, button) can ask at once: share one lookup.
  if (!inFlight.has(key)) inFlight.set(key, lookup(key, address).finally(() => inFlight.delete(key)));
  return inFlight.get(key)!;
}

async function lookup(key: string, address: string): Promise<{ km: number; label: string } | null> {
  let cache: Record<string, { km: number; label: string }> = {};
  try {
    cache = JSON.parse(sessionStorage.getItem(CACHE) || '{}');
  } catch {}
  if (cache[key]) return cache[key];
  const place = await geocode(address);
  if (!place) return null;
  const km = await roadDistanceKm(place);
  const result = { km, label: place.label };
  try {
    sessionStorage.setItem(CACHE, JSON.stringify({ ...cache, [key]: result }));
  } catch {}
  return result;
}

// ---------- Address suggestions while typing (Photon, built for search-as-you-type) ----------

export type Suggestion = {
  street: string; // "4520 Boulevard des Récollets"
  hasNumber: boolean; // false when only the street is known
  city: string;
  postcode: string;
  lat: number;
  lon: number;
  label: string; // one-line display
};

const strip = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Up to 5 Quebec address suggestions, nearest to the store first. Pass an AbortSignal to cancel stale requests. */
export async function suggestAddresses(query: string, signal?: AbortSignal): Promise<Suggestion[]> {
  const p = new URLSearchParams({
    q: query,
    limit: '10',
    lang: 'fr',
    lat: String(store.location.lat),
    lon: String(store.location.lon),
  });
  p.append('layer', 'house');
  p.append('layer', 'street');
  const res = await fetch(`https://photon.komoot.io/api/?${p}`, { signal, headers: { Accept: 'application/json' } });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const d = await res.json();
  const typedNumber = query.trim().match(/^\d+[a-zA-Z]?/)?.[0];
  const seen = new Set<string>();
  const out: Suggestion[] = [];
  for (const f of d?.features ?? []) {
    const pr = f.properties ?? {};
    if (pr.countrycode && pr.countrycode !== 'CA') continue;
    const streetName = pr.street ?? pr.name;
    if (!streetName) continue;
    const number = pr.housenumber ?? '';
    // A street-only match keeps the number the customer typed (the distance is then approximate).
    const street = [number || typedNumber || '', streetName].filter(Boolean).join(' ');
    const city = pr.city ?? pr.town ?? pr.village ?? pr.district ?? pr.county ?? '';
    const key = strip(`${street}|${city}`);
    if (seen.has(key)) continue;
    seen.add(key);
    out.push({
      street,
      hasNumber: Boolean(number),
      city,
      postcode: pr.postcode ?? '',
      lon: f.geometry.coordinates[0],
      lat: f.geometry.coordinates[1],
      label: [street, city, pr.postcode].filter(Boolean).join(', '),
    });
    if (out.length === 5) break;
  }
  return out;
}

/** Road distance for a place already pinned on the map (e.g. a chosen suggestion). */
export async function distanceForPlace(place: Place): Promise<{ km: number; label: string }> {
  return { km: await roadDistanceKm(place), label: place.label };
}
