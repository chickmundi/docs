import { useState } from 'react';
import { supabase, money, toCents, toDollars, dateTime, audit } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Empty, Field, Modal, Badge, useLoad, must, notify } from '../components/ui';
import Icon from '../components/Icon';

type Coupon = { id?: number; code: string; description: string | null; discount_type: 'percent' | 'fixed' | 'free_delivery'; value: number; min_subtotal_cents: number; starts_at: string | null; ends_at: string | null; usage_limit: number | null; usage_count?: number; per_customer_limit: number | null; category_ids: number[]; active: boolean };
const TYPES = { percent: 'Pourcentage', fixed: 'Montant fixe', free_delivery: 'Livraison gratuite' };
const local = (iso: string | null) => (iso ? new Date(iso).toLocaleString('sv-SE', { timeZone: 'America/Toronto' }).slice(0, 16).replace(' ', 'T') : '');

export default function Coupons() {
  const t = useT();
  const [edit, setEdit] = useState<Coupon | null>(null);
  const [minDollars, setMinDollars] = useState('');
  const { data, error, loading, reload } = useLoad(async () => ({
    coupons: must(await supabase.from('coupons').select('*').order('created_at', { ascending: false })) as Coupon[],
    cats: must(await supabase.from('categories').select('id, name_fr, parent_id').order('name_fr')),
  }));

  const open = (c: Coupon) => {
    setEdit(c);
    setMinDollars(c.min_subtotal_cents ? toDollars(c.min_subtotal_cents) : '');
  };
  const save = async () => {
    if (!edit) return;
    const code = edit.code.trim().toUpperCase().replace(/\s+/g, '');
    if (!code) return notify('Code requis.', 'bad');
    if (edit.discount_type === 'percent' && !(edit.value > 0 && edit.value < 100)) return notify('Pourcentage entre 1 et 99.', 'bad');
    if (edit.discount_type === 'fixed' && !(edit.value > 0)) return notify('Montant requis.', 'bad');
    const row = { ...edit, code, min_subtotal_cents: toCents(minDollars) ?? 0 };
    delete row.usage_count;
    const { error } = await supabase.from('coupons').upsert(row);
    if (error) return notify(error.message.includes('duplicate') ? 'Ce code existe déjà.' : error.message.includes('row-level') ? 'Réservé au propriétaire et aux gestionnaires.' : error.message, 'bad');
    await audit('coupon.save', 'coupon', code);
    notify('Coupon enregistré.');
    setEdit(null);
    reload();
  };

  return (
    <>
      <PageHead
        title={t('Coupons')}
        sub="Codes saisis par le client au moment de commander"
        actions={
          <button
            className="a-btn a-btn--primary"
            onClick={() => open({ code: '', description: null, discount_type: 'percent', value: 10, min_subtotal_cents: 0, starts_at: null, ends_at: null, usage_limit: null, per_customer_limit: 1, category_ids: [], active: true })}
          >
            <Icon name="plus" /> Nouveau coupon
          </button>
        }
      />
      {loading ? (
        <Loading />
      ) : error ? (
        <ErrorBox error={error} />
      ) : !data!.coupons.length ? (
        <Empty>Aucun coupon.</Empty>
      ) : (
        <Card>
          <table className="a-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Rabais</th>
                <th>Conditions</th>
                <th className="a-num">Utilisations</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data!.coupons.map((c) => (
                <tr key={c.id}>
                  <td>
                    <code className="a-code">{c.code}</code> {!c.active && <Badge>désactivé</Badge>}
                    {c.description && <div className="a-muted a-small">{c.description}</div>}
                  </td>
                  <td>{c.discount_type === 'percent' ? `−${Number(c.value)} %` : c.discount_type === 'fixed' ? `−${money(Number(c.value) * 100)}` : 'Livraison gratuite'}</td>
                  <td className="a-small">
                    {c.min_subtotal_cents > 0 && `dès ${money(c.min_subtotal_cents)} · `}
                    {c.ends_at ? `jusqu’au ${dateTime(c.ends_at)}` : 'sans date de fin'}
                  </td>
                  <td className="a-num">
                    {c.usage_count ?? 0}
                    {c.usage_limit ? ` / ${c.usage_limit}` : ''}
                  </td>
                  <td className="a-num">
                    <button className="a-btn a-btn--ghost" onClick={() => open(c)}>
                      {t('Modifier')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
      {edit && (
        <Modal
          title={edit.id ? `Coupon ${edit.code}` : 'Nouveau coupon'}
          onClose={() => setEdit(null)}
          footer={
            <>
              <button className="a-btn" onClick={() => setEdit(null)}>
                {t('Annuler')}
              </button>
              <button className="a-btn a-btn--primary" onClick={save}>
                {t('Enregistrer')}
              </button>
            </>
          }
        >
          <div className="a-form">
            <Field label="Code" hint="Lettres et chiffres, sans espaces (ex. BIENVENUE10)">
              <input className="a-input" value={edit.code} onChange={(e) => setEdit({ ...edit, code: e.target.value.toUpperCase() })} />
            </Field>
            <Field label="Description interne">
              <input className="a-input" value={edit.description ?? ''} onChange={(e) => setEdit({ ...edit, description: e.target.value })} />
            </Field>
            <div className="a-form--row">
              <Field label="Type">
                <select className="a-input" value={edit.discount_type} onChange={(e) => setEdit({ ...edit, discount_type: e.target.value as Coupon['discount_type'] })}>
                  {Object.entries(TYPES).map(([k, v]) => (
                    <option key={k} value={k}>
                      {v}
                    </option>
                  ))}
                </select>
              </Field>
              {edit.discount_type !== 'free_delivery' && (
                <Field label={edit.discount_type === 'percent' ? 'Rabais (%)' : 'Rabais ($)'}>
                  <input className="a-input" inputMode="decimal" value={edit.value} onChange={(e) => setEdit({ ...edit, value: Number(e.target.value.replace(',', '.')) || 0 })} />
                </Field>
              )}
            </div>
            <Field label="Achat minimum ($)">
              <input className="a-input" inputMode="decimal" value={minDollars} onChange={(e) => setMinDollars(e.target.value)} />
            </Field>
            <div className="a-form--row">
              <Field label="Début (facultatif)">
                <input className="a-input" type="datetime-local" value={local(edit.starts_at)} onChange={(e) => setEdit({ ...edit, starts_at: e.target.value ? new Date(e.target.value).toISOString() : null })} />
              </Field>
              <Field label="Fin (facultatif)">
                <input className="a-input" type="datetime-local" value={local(edit.ends_at)} onChange={(e) => setEdit({ ...edit, ends_at: e.target.value ? new Date(e.target.value).toISOString() : null })} />
              </Field>
            </div>
            <div className="a-form--row">
              <Field label="Utilisations max. au total">
                <input className="a-input" type="number" min={0} value={edit.usage_limit ?? ''} onChange={(e) => setEdit({ ...edit, usage_limit: e.target.value ? Number(e.target.value) : null })} />
              </Field>
              <Field label="Par client">
                <input className="a-input" type="number" min={0} value={edit.per_customer_limit ?? ''} onChange={(e) => setEdit({ ...edit, per_customer_limit: e.target.value ? Number(e.target.value) : null })} />
              </Field>
            </div>
            <fieldset className="a-checklist">
              <legend>Limité aux catégories (aucune = toute la boutique)</legend>
              {data!.cats.map((c: any) => (
                <label key={c.id} className={`a-check ${c.parent_id ? 'a-check--child' : ''}`}>
                  <input
                    type="checkbox"
                    checked={edit.category_ids.includes(c.id)}
                    onChange={(e) => setEdit({ ...edit, category_ids: e.target.checked ? [...edit.category_ids, c.id] : edit.category_ids.filter((x) => x !== c.id) })}
                  />
                  {c.name_fr}
                </label>
              ))}
            </fieldset>
            <label className="a-check">
              <input type="checkbox" checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} /> Actif
            </label>
          </div>
        </Modal>
      )}
    </>
  );
}
