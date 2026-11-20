import { useState } from 'react';
import { supabase, dateTime, audit } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Empty, Field, Modal, Badge, useLoad, must, notify } from '../components/ui';
import Icon from '../components/Icon';

type Promo = { id?: number; name: string; percent_off: number; category_ids: number[]; product_ids: number[]; starts_at: string; ends_at: string; active: boolean };
const local = (iso: string) => (iso ? new Date(iso).toLocaleString('sv-SE', { timeZone: 'America/Toronto' }).slice(0, 16).replace(' ', 'T') : '');

export const promoState = (p: { active: boolean; starts_at: string; ends_at: string }) => {
  const now = Date.now();
  if (!p.active) return ['désactivée', 'neutral'];
  if (new Date(p.starts_at).getTime() > now) return ['à venir', 'info'];
  if (new Date(p.ends_at).getTime() < now) return ['terminée', 'neutral'];
  return ['en cours', 'ok'];
};

export default function Promotions() {
  const t = useT();
  const [edit, setEdit] = useState<Promo | null>(null);
  const { data, error, loading, reload } = useLoad(async () => ({
    promos: must(await supabase.from('promotions').select('*').order('starts_at', { ascending: false })) as Promo[],
    cats: must(await supabase.from('categories').select('id, name_fr, parent_id').order('name_fr')),
  }));

  const save = async () => {
    if (!edit) return;
    if (!edit.name.trim() || !(edit.percent_off > 0 && edit.percent_off < 100)) return notify('Nom et pourcentage (1 à 99) requis.', 'bad');
    if (!edit.starts_at || !edit.ends_at || new Date(edit.ends_at) <= new Date(edit.starts_at)) return notify('Vérifiez les dates.', 'bad');
    if (!edit.category_ids.length && !edit.product_ids.length) return notify('Choisissez au moins une catégorie ou un produit.', 'bad');
    const { error } = await supabase.from('promotions').upsert(edit);
    if (error) return notify(error.message, 'bad');
    await audit('promotion.save', 'promotion', edit.id ?? edit.name);
    notify('Promotion enregistrée.');
    setEdit(null);
    reload();
  };

  return (
    <>
      <PageHead
        title={t('Promotions')}
        sub="Rabais en pourcentage sur des catégories ou des produits, pour une période. Pour un solde sur un seul produit, utilisez aussi le prix régulier dans sa fiche."
        actions={
          <button
            className="a-btn a-btn--primary"
            onClick={() => setEdit({ name: '', percent_off: 10, category_ids: [], product_ids: [], starts_at: new Date().toISOString(), ends_at: new Date(Date.now() + 7 * 86400000).toISOString(), active: true })}
          >
            <Icon name="plus" /> Nouvelle promotion
          </button>
        }
      />
      {loading ? (
        <Loading />
      ) : error ? (
        <ErrorBox error={error} />
      ) : !data!.promos.length ? (
        <Empty>Aucune promotion.</Empty>
      ) : (
        <Card>
          <table className="a-table">
            <tbody>
              {data!.promos.map((p) => {
                const [l, tone] = promoState(p);
                return (
                  <tr key={p.id}>
                    <td>
                      <strong>{p.name}</strong>
                      <div className="a-muted a-small">
                        {p.category_ids.map((c) => data!.cats.find((x: any) => x.id === c)?.name_fr).filter(Boolean).join(', ')}
                        {p.product_ids.length > 0 && ` · ${p.product_ids.length} produit(s)`}
                      </div>
                    </td>
                    <td>−{Number(p.percent_off)} %</td>
                    <td className="a-small">
                      {dateTime(p.starts_at)} → {dateTime(p.ends_at)}
                    </td>
                    <td>
                      <Badge tone={tone as any}>{l}</Badge>
                    </td>
                    <td className="a-num">
                      <button className="a-btn a-btn--ghost" onClick={() => setEdit(p)}>
                        {t('Modifier')}
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </Card>
      )}
      {edit && (
        <Modal
          title={edit.id ? 'Modifier la promotion' : 'Nouvelle promotion'}
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
            <Field label="Nom (interne et affiché)">
              <input className="a-input" value={edit.name} placeholder="Spécial des Fêtes : perruques" onChange={(e) => setEdit({ ...edit, name: e.target.value })} />
            </Field>
            <Field label="Rabais (%)">
              <input className="a-input" type="number" min={1} max={99} value={edit.percent_off} onChange={(e) => setEdit({ ...edit, percent_off: Number(e.target.value) })} />
            </Field>
            <div className="a-form--row">
              <Field label="Début">
                <input className="a-input" type="datetime-local" value={local(edit.starts_at)} onChange={(e) => setEdit({ ...edit, starts_at: new Date(e.target.value).toISOString() })} />
              </Field>
              <Field label="Fin">
                <input className="a-input" type="datetime-local" value={local(edit.ends_at)} onChange={(e) => setEdit({ ...edit, ends_at: new Date(e.target.value).toISOString() })} />
              </Field>
            </div>
            <fieldset className="a-checklist">
              <legend>Catégories visées</legend>
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
            <Field label="Produits précis (numéros séparés par des virgules)" hint="Le numéro est affiché dans la liste des produits">
              <input
                className="a-input"
                value={edit.product_ids.join(', ')}
                onChange={(e) => setEdit({ ...edit, product_ids: e.target.value.split(/[,\s]+/).map(Number).filter((n) => Number.isInteger(n) && n > 0) })}
              />
            </Field>
            <label className="a-check">
              <input type="checkbox" checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} /> Active
            </label>
          </div>
        </Modal>
      )}
    </>
  );
}
