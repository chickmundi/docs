import { useState } from 'react';
import { supabase, money, dateTime } from '../lib/supabase';
import { useT, useLang, label, FULFILLMENT, PAYMENT_STATUS, PAYMENT_METHOD, CHANNEL } from '../lib/i18n';
import { PageHead, Loading, ErrorBox, Empty, Badge, useLoad, must } from '../components/ui';
import Icon from '../components/Icon';

export const statusTone = (s: string) =>
  s === 'cancelled' ? 'bad' : s === 'delivered' || s === 'collected' ? 'ok' : s === 'ready_for_pickup' || s === 'out_for_delivery' ? 'info' : 'warn';
export const payTone = (s: string) => (s === 'paid' ? 'ok' : s === 'failed' ? 'bad' : s === 'refunded' || s === 'partially_refunded' ? 'neutral' : 'warn');

const PAGE = 50;

function params() {
  const q = location.hash.split('?')[1] ?? '';
  return new URLSearchParams(q);
}

export default function Orders() {
  const t = useT();
  const lang = useLang();
  const p = params();
  const [status, setStatus] = useState(p.get('statut') ?? '');
  const [payment, setPayment] = useState(p.get('paiement') ?? '');
  const [method, setMethod] = useState('');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(0);

  const { data, error, loading } = useLoad(async () => {
    let query = supabase
      .from('orders')
      .select('id, number, created_at, contact_name, contact_phone, channel, fulfillment_method, fulfillment_status, payment_method, payment_status, total_cents, delivery_fee_confirmed', { count: 'exact' })
      .order('created_at', { ascending: false })
      .range(page * PAGE, page * PAGE + PAGE - 1);
    if (status === 'a-preparer') query = query.in('fulfillment_status', ['received', 'confirmed', 'preparing']);
    else if (status === 'a-livrer') query = query.eq('fulfillment_method', 'delivery').in('fulfillment_status', ['received', 'confirmed', 'preparing', 'out_for_delivery']);
    else if (status) query = query.eq('fulfillment_status', status);
    if (payment === 'unpaid') query = query.in('payment_status', ['unpaid', 'pending']).neq('fulfillment_status', 'cancelled');
    else if (payment) query = query.eq('payment_status', payment);
    if (method) query = query.eq('fulfillment_method', method);
    if (q.trim()) {
      const s = q.trim().replace(/[%,()]/g, ' ');
      query = query.or(`number.ilike.%${s}%,contact_name.ilike.%${s}%,contact_phone.ilike.%${s}%,contact_email.ilike.%${s}%`);
    }
    const res = await query;
    return { rows: must(res), count: res.count ?? 0 };
  }, [status, payment, method, q, page]);

  return (
    <>
      <PageHead
        title={t('Commandes')}
        sub="Commandes du site, de WhatsApp, du téléphone et du magasin"
        actions={
          <a className="a-btn a-btn--primary" href="#/commandes/nouvelle">
            <Icon name="plus" /> Nouvelle commande
          </a>
        }
      />
      <div className="a-filters">
        <input className="a-input" placeholder="N°, nom, téléphone, courriel…" value={q} onChange={(e) => (setQ(e.target.value), setPage(0))} />
        <select className="a-input" value={status} onChange={(e) => (setStatus(e.target.value), setPage(0))}>
          <option value="">Tous les statuts</option>
          <option value="a-preparer">À préparer (reçues, confirmées, en préparation)</option>
          <option value="a-livrer">À livrer</option>
          {Object.keys(FULFILLMENT).map((k) => (
            <option key={k} value={k}>
              {label(FULFILLMENT, k, lang)}
            </option>
          ))}
        </select>
        <select className="a-input" value={payment} onChange={(e) => (setPayment(e.target.value), setPage(0))}>
          <option value="">Tous les paiements</option>
          <option value="unpaid">Non payées (commandes ouvertes)</option>
          {Object.keys(PAYMENT_STATUS).map((k) => (
            <option key={k} value={k}>
              {label(PAYMENT_STATUS, k, lang)}
            </option>
          ))}
        </select>
        <select className="a-input" value={method} onChange={(e) => (setMethod(e.target.value), setPage(0))}>
          <option value="">Livraison et retrait</option>
          <option value="delivery">Livraison</option>
          <option value="pickup">Retrait en magasin</option>
        </select>
      </div>
      {loading ? (
        <Loading />
      ) : error ? (
        <ErrorBox error={error} />
      ) : !data!.rows.length ? (
        <Empty>Aucune commande pour ces critères.</Empty>
      ) : (
        <>
          <div className="a-tablewrap">
            <table className="a-table a-table--rows">
              <thead>
                <tr>
                  <th>Commande</th>
                  <th>Date</th>
                  <th>Client</th>
                  <th>Mode</th>
                  <th>Statut</th>
                  <th>Paiement</th>
                  <th className="a-num">Total</th>
                </tr>
              </thead>
              <tbody>
                {data!.rows.map((o: Record<string, any>) => (
                  <tr key={o.id} onClick={() => (location.hash = `#/commandes/${o.id}`)}>
                    <td>
                      <a href={`#/commandes/${o.id}`}>
                        <strong>{o.number}</strong>
                      </a>
                      <div className="a-muted a-small">{label(CHANNEL, o.channel, lang)}</div>
                    </td>
                    <td>{dateTime(o.created_at)}</td>
                    <td>
                      {o.contact_name}
                      <div className="a-muted a-small">{o.contact_phone}</div>
                    </td>
                    <td>
                      <Icon name={o.fulfillment_method === 'delivery' ? 'truck' : 'store'} size={16} /> {o.fulfillment_method === 'delivery' ? 'Livraison' : 'Retrait'}
                    </td>
                    <td>
                      <Badge tone={statusTone(o.fulfillment_status) as any}>{label(FULFILLMENT, o.fulfillment_status, lang)}</Badge>
                    </td>
                    <td>
                      <Badge tone={payTone(o.payment_status) as any}>{label(PAYMENT_STATUS, o.payment_status, lang)}</Badge>
                      <div className="a-muted a-small">{label(PAYMENT_METHOD, o.payment_method, lang)}</div>
                    </td>
                    <td className="a-num">
                      <strong>{money(o.total_cents)}</strong>
                      {!o.delivery_fee_confirmed && <div className="a-small a-warn">frais à confirmer</div>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="a-pager">
            <span className="a-muted">
              {page * PAGE + 1}–{Math.min((page + 1) * PAGE, data!.count)} sur {data!.count}
            </span>
            <button className="a-btn" disabled={page === 0} onClick={() => setPage(page - 1)}>
              Précédent
            </button>
            <button className="a-btn" disabled={(page + 1) * PAGE >= data!.count} onClick={() => setPage(page + 1)}>
              Suivant
            </button>
          </div>
        </>
      )}
    </>
  );
}
