import { useEffect, useState } from 'react';
import { supabase, audit } from './supabase';
import { notify } from '../components/ui';

/** Load and save one settings entry (JSON) from the `settings` table. */
export function useSetting<T = Record<string, any>>(key: string) {
  const [value, setValue] = useState<T | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    supabase
      .from('settings')
      .select('value')
      .eq('key', key)
      .maybeSingle()
      .then(({ data, error }) => (error ? setError(error) : setValue((data?.value ?? {}) as T)));
  }, [key]);
  const save = async (next?: T) => {
    const v = next ?? value;
    setSaving(true);
    const { data: u } = await supabase.auth.getUser();
    const { error } = await supabase.from('settings').upsert({ key, value: v, updated_at: new Date().toISOString(), updated_by: u.user?.id });
    setSaving(false);
    if (error) {
      notify(error.message.includes('row-level security') ? 'Seuls le propriétaire et les gestionnaires peuvent modifier ces paramètres.' : error.message, 'bad');
      return false;
    }
    await audit('settings.update', 'settings', key);
    notify('Enregistré.');
    return true;
  };
  return { value, setValue, error, saving, save };
}
