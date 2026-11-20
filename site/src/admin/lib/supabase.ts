import { createClient } from '@supabase/supabase-js';

// Public values (safe in the browser): access is enforced by row-level security in the database.
export const SUPABASE_URL = import.meta.env.PUBLIC_SUPABASE_URL as string;
export const SUPABASE_ANON_KEY = import.meta.env.PUBLIC_SUPABASE_ANON_KEY as string;

export const supabase = createClient(SUPABASE_URL, SUPABASE_ANON_KEY, {
  auth: { persistSession: true, autoRefreshToken: true, storageKey: 'mcr-admin-auth' },
});

export const money = (cents: number | null | undefined) =>
  cents == null ? '—' : new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);

export const toCents = (dollars: string | number | null | undefined): number | null => {
  if (dollars === '' || dollars == null) return null;
  const n = typeof dollars === 'number' ? dollars : Number(String(dollars).replace(',', '.').replace(/[^\d.-]/g, ''));
  return Number.isFinite(n) ? Math.round(n * 100) : null;
};

export const toDollars = (cents: number | null | undefined) => (cents == null ? '' : (cents / 100).toFixed(2));

export const dateTime = (iso: string | null | undefined) =>
  iso ? new Intl.DateTimeFormat('fr-CA', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Toronto' }).format(new Date(iso)) : '—';

export const dateOnly = (iso: string | null | undefined) =>
  iso ? new Intl.DateTimeFormat('fr-CA', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(iso)) : '—';

export async function audit(action: string, entity: string, entityId: string | number | null, details?: unknown) {
  const { data } = await supabase.auth.getUser();
  await supabase.from('audit_log').insert({ actor: data.user?.id, action, entity, entity_id: entityId == null ? null : String(entityId), details });
}

export const slugify = (s: string) =>
  s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
