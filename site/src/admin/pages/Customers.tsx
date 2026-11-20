import { useState } from 'react';
import { supabase, money, dateTime, audit } from '../lib/supabase';
import { useT, useLang, label, FULFILLMENT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Empty, Field, Modal, Badge, useLoad, must, notify } from '../components/ui';
import Icon from '../components/Icon';

type C = Record<string, any>;

export default function Customers() {
  const t = useT();
  const lang = useLang();
  const [q, setQ] = useState('');
  const [edit, setEdit] = useState<C | null>(null);
  const [view, setView] = useState<C | null>(null);
  const { data, error, loading, reload } = useLoad(async () => {
    let query = supabase.from('customers').select('*, orders(id, total_cents, created_at, fulfillment_status)').order('created_at', { ascending: false }).limit(200);
    if (q.trim()) {
      const s = q.trim().replace(/[%,()]/g, ' ');
      query = query.or(`first_name.ilike.%${s}%,last_name.ilike.%${s}%,email.ilike.%${s}%,phone.ilike.%${s}%`);
    }
    return must(await query) as C[];
  }, [q]);

  const save = async () => {
    if (!edit) return;
    if (!edit.first_name && !edit.phone && !edit.email) return notify('Indiquez au moins un nom, un téléphone ou un courriel.', 'bad');
    const row = { ...edit };
    delete row.orders;
    delete row.created_at;
    delete row.updated_at;
    const { error } = await supabase.from('customers').upsert(row);
    if (error) return notify(error.message, 'bad');
    await audit(edit.id ? 'customer.update' : 'customer.create', 'customer', edit.id ?? edit.email);
    notify('Client enregistré.');
    setEdit(null);
    reload();
  };

  return (
    <>
      <PageHead
        title={t('Clients')}
        sub="Les clients de WooCommerce ne sont pas encore importés (prévu plus tard)."
        actions={
          <button className="a-btn a-btn--primary" onClick={() => setEdit({ first_name: '', last_name: '', email: '', phone: '', preferred_language: 'fr', marketing_consent: false, notes: '' })}>
            <Icon name="plus" /> Nouveau client
          </button>
        }
      />
      <div className="a-filters">
        <input className="a-input" placeholder="Nom, courriel ou téléphone…" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>
      {loading ? (
        <Loading />
      ) : error ? (
        <ErrorBox error={error} />
      ) : !data!.length ? (
        <Empty>Aucun client. Les clients s’ajoutent ici manuellement ou automatiquement lors des commandes en ligne.</Empty>
      ) : (
        <div className="a-tablewrap">
          <table className="a-table a-table--rows">
            <thead>
              <tr>
                <th>Client</th>
                <th>Contact</th>
                <th className="a-num">Commandes</th>
                <th className="a-num">Total dépensé</th>
                <th>Infolettre</th>
              </tr>
            </thead>
            <tbody>
              {data!.map((c) => {
                const valid = (c.orders ?? []).filter((o: any) => o.fulfillment_status !== 'cancelled');
                return (
                  <tr key={c.id} onClick={() => setView(c)}>
                    <td>
                      <strong>
                        {c.first_name} {c.last_name}
                      </strong>
                      <div className="a-muted a-small">depuis {dateTime(c.created_at)}</div>
                    </td>
                    <td>
                      {c.phone}
                      <div className="a-muted a-small">{c.email}</div>
                    </td>
                    <td className="a-num">{valid.length}</td>
                    <td className="a-num">{money(valid.reduce((n: number, o: any) => n + o.total_cents, 0))}</td>
                    <td>{c.marketing_consent ? <Badge tone="ok">consent</Badge> : <Badge>non</Badge>}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {view && (
        <Modal
          title={`${view.first_name ?? ''} ${view.last_name ?? ''}`}
          onClose={() => setView(null)}
          footer={
            <button className="a-btn a-btn--primary" onClick={() => (setEdit(view), setView(null))}>
              {t('Modifier')}
            </button>
          }
        >
          <p>
            {view.phone && <a href={`tel:${view.phone}`}>{view.phone}</a>}
            <br />
            {view.email && <a href={`mailto:${view.email}`}>{view.email}</a>}
          </p>
          {view.notes && <p className="a-note">{view.notes}</p>}
          <h3>Commandes</h3>
          {view.orders?.length ? (
            <ul className="a-list">
              {[...view.orders]
                .sort((a: any, b: any) => b.created_at.localeCompare(a.created_at))
                .map((o: any) => (
                  <li key={o.id}>
                    <a href={`#/commandes/${o.id}`}>{dateTime(o.created_at)}</a> · {money(o.total_cents)} · {label(FULFILLMENT, o.fulfillment_status, lang)}
                  </li>
                ))}
            </ul>
          ) : (
            <Empty>Aucune commande.</Empty>
          )}
        </Modal>
      )}

      {edit && (
        <Modal
          title={edit.id ? 'Modifier le client' : 'Nouveau client'}
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
            <div className="a-form--row">
              <Field label="Prénom">
                <input className="a-input" value={edit.first_name ?? ''} onChange={(e) => setEdit({ ...edit, first_name: e.target.value })} />
              </Field>
              <Field label="Nom">
                <input className="a-input" value={edit.last_name ?? ''} onChange={(e) => setEdit({ ...edit, last_name: e.target.value })} />
              </Field>
            </div>
            <Field label="Téléphone">
              <input className="a-input" type="tel" value={edit.phone ?? ''} onChange={(e) => setEdit({ ...edit, phone: e.target.value })} />
            </Field>
            <Field label="Courriel">
              <input className="a-input" type="email" value={edit.email ?? ''} onChange={(e) => setEdit({ ...edit, email: e.target.value })} />
            </Field>
            <Field label="Langue">
              <select className="a-input" value={edit.preferred_language} onChange={(e) => setEdit({ ...edit, preferred_language: e.target.value })}>
                <option value="fr">Français</option>
                <option value="en">English</option>
              </select>
            </Field>
            <label className="a-check">
              <input type="checkbox" checked={!!edit.marketing_consent} onChange={(e) => setEdit({ ...edit, marketing_consent: e.target.checked })} /> A consenti à recevoir les promotions (Loi 25 / LCAP)
            </label>
            <Field label="Notes internes">
              <textarea className="a-input" rows={3} value={edit.notes ?? ''} onChange={(e) => setEdit({ ...edit, notes: e.target.value })} />
            </Field>
          </div>
        </Modal>
      )}
    </>
  );
}
