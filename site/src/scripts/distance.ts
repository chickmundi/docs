// Finds a customer's address and measures the road distance from the store.
// Address lookup: OpenStreetMap Nominatim, falling back to Photon. Road distance: OSRM.
// These free services allow light use; for heavy traffic, switch to a paid provider here.
import { store } from '../config';

export type Place = { lat: number; lon: number; label: string };

// Mauricie / Centre-du-Québec area, so "Rue Notre-Dame" doesn't match another city.
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
    bounded: '1',
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
    bbox: `${VIEWBOX.west},${VIEWBOX.south},${VIEWBOX.east},${VIEWBOX.north}`,
  });
  const d = await getJSON(`https://photon.komoot.io/api/?${p}`);
  const f = d?.features?.[0];
  if (!f) return null;
  const pr = f.properties ?? {};
  const label = [[pr.housenumber, pr.street ?? pr.name].filter(Boolean).join(' '), pr.city, pr.postcode].filter(Boolean).join(', ');
  return { lon: f.geometry.coordinates[0], lat: f.geometry.coordinates[1], label };
}

export async function geocode(q: string): Promise<Place | null> {
  try {
    const r = await nominatim(q);
    if (r) return r;
  } catch {
    /* rate limited or down: try the next service */
  }
  return photon(q);
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
