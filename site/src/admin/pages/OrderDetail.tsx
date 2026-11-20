import { useEffect, useMemo, useState } from 'react';
import { supabase, money, dateTime, toCents, toDollars, audit } from '../lib/supabase';
import { useLang, label, FULFILLMENT, PAYMENT_STATUS, PAYMENT_METHOD, CHANNEL } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Badge, Field, useLoad, must, notify, Empty } from '../components/ui';
import Icon from '../components/Icon';
import { statusTone, payTone } from './Orders';

type Order = Record<string, any>;

// Allowed next steps for each fulfillment status.
const NEXT: Record<string, { delivery: string[]; pickup: string[] }> = {
  received: { delivery: ['confirmed', 'preparing', 'cancelled'], pickup: ['confirmed', 'preparing', 'cancelled'] },
  confirmed: { delivery: ['preparing', 'cancelled'], pickup: ['preparing', 'cancelled'] },
  preparing: { delivery: ['out_for_delivery', 'cancelled'], pickup: ['ready_for_pickup', 'cancelled'] },
  ready_for_pickup: { delivery: [], pickup: ['collected', 'cancelled'] },
  out_for_delivery: { delivery: ['delivered', 'preparing'], pickup: [] },
  delivered: { delivery: [], pickup: [] },
  collected: { delivery: [], pickup: [] },
  cancelled: { delivery: [], pickup: [] },
};

const NOTIFY_TEXT: Record<string, (o: Order) => string> = {
  confirmed: (o) => `Bonjour ${o.contact_name}, votre commande ${o.number} chez Marché Chemin-du-Roy est confirmée. Total : ${money(o.total_cents)}. Merci !`,
  ready_for_pickup: (o) =>
    `Bonjour ${o.contact_name}, votre commande ${o.number} est prête ! Vous pouvez la récupérer au 65 Rue Notre-Dame E, Trois-Rivières. Présentez votre numéro de commande à la caisse.`,
  out_for_delivery: (o) => `Bonjour ${o.contact_name}, votre commande ${o.number} est en route. Notre livreur vous appellera à l’arrivée.`,
  delivered: (o) => `Bonjour ${o.contact_name}, votre commande ${o.number} a été livrée. Merci de votre confiance !`,
  cancelled: (o) => `Bonjour ${o.contact_name}, votre commande ${o.number} a été annulée. Contactez-nous au +1 819-372-9966 pour toute question.`,
};

const digits = (s: string) => s.replace(/\D/g, '').replace(/^(?=\d{10}$)/, '1');

export default function OrderDetail({ params }: { params: string[] }) {
  if (params[0] === 'nouvelle') return <NewOrder />;
  return <ExistingOrder id={Number(params[0])} />;
}

function ExistingOrder({ id }: { id: number }) {
  const lang = useLang();
  const { data, error, loading, reload } = useLoad(async () => {
    const [order, items, payments, events] = await Promise.all([
      supabase.from('orders').select('*').eq('id', id).single().then(must),
      supabase.from('order_items').select('*').eq('order_id', id).order('id').then(must),
      supabase.from('payments').select('*').eq('order_id', id).order('created_at').then(must),
      supabase.from('order_events').select('*').eq('order_id', id).order('created_at', { ascending: false }).then(must),
    ]);
    return { order: order as Order, items, payments, events };
  }, [id]);
  const [busy, setBusy] = useState(false);
  const [pay, setPay] = useState({ amount: '', provider: 'cash', reference: '', note: '', refund: false });
  const [note, setNote] = useState('');
  const [feeEdit, setFeeEdit] = useState<string | null>(null);

  if (loading) return <Loading />;
  if (error) return <ErrorBox error={error} />;
  const { order: o, items, payments, events } = data!;
  const balance = o.total_cents - payments.filter((p: any) => p.status === 'succeeded').reduce((n: number, p: any) => n + p.amount_cents, 0);
  const next = NEXT[o.fulfillment_status]?.[o.fulfillment_method as 'delivery' | 'pickup'] ?? [];

  const setStatus = async (to: string) => {
    let reason: string | null = null;
    if (to === 'cancelled') {
      reason = prompt('Raison de l’annulation (visible à l’interne) :');
      if (reason === null) return;
    }
    setBusy(true);
    const { error } = await supabase.from('orders').update({ fulfillment_status: to, ...(reason ? { cancelled_reason: reason } : {}) }).eq('id', o.id);
    setBusy(false);
    if (error) return notify(error.message, 'bad');
    await audit('order.status', 'order', o.id, { to });
    notify(`Statut : ${label(FULFILLMENT, to, lang)}`);
    reload();
  };

  const recordPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    const cents = toCents(pay.amount);
    if (!cents || cents <= 0) return notify('Montant invalide.', 'bad');
    const { data: u } = await supabase.auth.getUser();
    const { error } = await supabase.from('payments').insert({
      order_id: o.id,
      method: o.payment_method,
      provider: pay.provider,
      provider_reference: pay.reference || null,
      amount_cents: pay.refund ? -cents : cents,
      status: 'succeeded',
      recorded_by: u.user?.id,
      note: pay.note || null,
    });
    if (error) return notify(error.message, 'bad');
    await audit(pay.refund ? 'order.refund' : 'order.payment', 'order', o.id, { cents, provider: pay.provider });
    setPay({ amount: '', provider: 'cash', reference: '', note: '', refund: false });
    notify(pay.refund ? 'Remboursement enregistré.' : 'Paiement enregistré.');
    reload();
  };

  const addNote = async () => {
    if (!note.trim()) return;
    const { data: u } = await supabase.auth.getUser();
    const { error } = await supabase.from('order_events').insert({ order_id: o.id, kind: 'note', note, actor: u.user?.id });
    if (error) return notify(error.message, 'bad');
    setNote('');
    reload();
  };

  const saveFee = async () => {
    const fee = toCents(feeEdit ?? '') ?? 0;
    const total = o.subtotal_cents - o.discount_cents + fee + o.tax_cents;
    const { error } = await supabase.from('orders').update({ delivery_fee_cents: fee, total_cents: total, delivery_fee_confirmed: true }).eq('id', o.id);
    if (error) return notify(error.message, 'bad');
    await audit('order.delivery_fee', 'order', o.id, { fee });
    setFeeEdit(null);
    notify('Frais de livraison confirmés.');
    reload();
  };

  const logNotification = async (channel: string, text: string) => {
    const { data: u } = await supabase.auth.getUser();
    await supabase.from('order_events').insert({ order_id: o.id, kind: 'notification', to_value: channel, note: text, actor: u.user?.id });
    reload();
  };
  const notifyText = (NOTIFY_TEXT[o.fulfillment_status] ?? NOTIFY_TEXT.confirmed)(o);
  const addr = o.delivery_address ?? {};

  return (
    <>
      <PageHead
        title={`Commande ${o.number}`}
        sub={
          <>
            {dateTime(o.created_at)} · {label(CHANNEL, o.channel, lang)} ·{' '}
            <Badge tone={statusTone(o.fulfillment_status) as any}>{label(FULFILLMENT, o.fulfillment_status, lang)}</Badge>{' '}
            <Badge tone={payTone(o.payment_status) as any}>{label(PAYMENT_STATUS, o.payment_status, lang)}</Badge>
          </>
        }
        actions={
          <>
            <a className="a-btn" href="#/commandes">
              <Icon name="arrow-left" /> Commandes
            </a>
            <button className="a-btn" onClick={() => window.print()}>
              <Icon name="printer" /> Bon de préparation
            </button>
          </>
        }
      />

      <div className="a-grid2 a-grid2--wide">
        <div className="a-stack">
          <Card title="Étapes de la commande">
            {next.length ? (
              <div className="a-actions">
                {next.map((s) => (
                  <button key={s} className={`a-btn ${s === 'cancelled' ? 'a-btn--danger' : 'a-btn--primary'}`} disabled={busy} onClick={() => setStatus(s)}>
                    {s === 'cancelled' ? 'Annuler la commande' : `Marquer : ${label(FULFILLMENT, s, lang)}`}
                  </button>
                ))}
              </div>
            ) : (
              <p className="a-muted">Commande terminée{o.completed_at ? ` le ${dateTime(o.completed_at)}` : ''}.</p>
            )}
            {o.cancelled_reason && <p className="a-error">Annulée : {o.cancelled_reason}</p>}
            {o.fulfillment_method === 'pickup' && o.fulfillment_status === 'ready_for_pickup' && (
              <p className="a-muted a-small">Le client présente le numéro {o.number} à la caisse. Encaissez puis marquez « Récupérée ».</p>
            )}
          </Card>

          <Card title={`Articles (${items.length})`}>
            <table className="a-table">
              <thead>
                <tr>
                  <th>Produit</th>
                  <th className="a-num">Prix</th>
                  <th className="a-num">Qté</th>
                  <th className="a-num">Total</th>
                </tr>
              </thead>
              <tbody>
                {items.map((i: any) => (
                  <tr key={i.id}>
                    <td>
                      {i.product_id ? <a href={`#/produits/${i.product_id}`}>{i.name}</a> : i.name}
                      {i.options && Object.keys(i.options).length > 0 && (
                        <div className="a-muted a-small">
                          {Object.entries(i.options)
                            .map(([k, v]) => `${k} : ${v}`)
                            .join(', ')}
                        </div>
                      )}
                      {i.sku && <div className="a-muted a-small">SKU {i.sku}</div>}
                    </td>
                    <td className="a-num">
                      {money(i.unit_price_cents)}
                      {i.unit_label ? ` ${i.unit_label}` : ''}
                    </td>
                    <td className="a-num">{Number(i.quantity)}</td>
                    <td className="a-num">{money(i.line_total_cents)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <td colSpan={3}>Sous-total</td>
                  <td className="a-num">{money(o.subtotal_cents)}</td>
                </tr>
                {o.discount_cents > 0 && (
                  <tr>
                    <td colSpan={3}>Rabais {o.coupon_code && `(${o.coupon_code})`}</td>
                    <td className="a-num">−{money(o.discount_cents)}</td>
                  </tr>
                )}
                {o.fulfillment_method === 'delivery' && (
                  <tr>
                    <td colSpan={3}>
                      Livraison {o.delivery_distance_km != null && `(${Number(o.delivery_distance_km).toFixed(1)} km, ${o.distance_method === 'road' ? 'par la route' : o.distance_method === 'straight_line' ? 'à vol d’oiseau' : 'manuel'})`}
                      {!o.delivery_fee_confirmed && <Badge tone="warn">à confirmer</Badge>}
                    </td>
                    <td className="a-num">
                      {feeEdit === null ? (
                        <button className="a-link" onClick={() => setFeeEdit(toDollars(o.delivery_fee_cents))}>
                          {money(o.delivery_fee_cents)}
                        </button>
                      ) : (
                        <span className="a-inline">
                          <input className="a-input a-input--sm" value={feeEdit} onChange={(e) => setFeeEdit(e.target.value)} />
                          <button className="a-btn a-btn--primary" onClick={saveFee}>
                            OK
                          </button>
                        </span>
                      )}
                    </td>
                  </tr>
                )}
                {o.tax_cents > 0 && (
                  <tr>
                    <td colSpan={3}>Taxes</td>
                    <td className="a-num">{money(o.tax_cents)}</td>
                  </tr>
                )}
                <tr className="a-total">
                  <td colSpan={3}>Total</td>
                  <td className="a-num">{money(o.total_cents)}</td>
                </tr>
              </tfoot>
            </table>
          </Card>

          <Card title="Paiements" actions={<span className="a-muted">Solde : <strong>{money(balance)}</strong></span>}>
            <p className="a-muted a-small">
              Mode choisi : {label(PAYMENT_METHOD, o.payment_method, lang)}. Le statut « payée » se met à jour seulement quand des paiements couvrant le total sont enregistrés.
            </p>
            {payments.length ? (
              <table className="a-table">
                <tbody>
                  {payments.map((p: any) => (
                    <tr key={p.id}>
                      <td>{dateTime(p.created_at)}</td>
                      <td>
                        {p.provider}
                        {p.provider_reference && <div className="a-muted a-small">Réf. {p.provider_reference}</div>}
                        {p.note && <div className="a-muted a-small">{p.note}</div>}
                      </td>
                      <td>{p.status}</td>
                      <td className={`a-num ${p.amount_cents < 0 ? 'a-warn' : ''}`}>{money(p.amount_cents)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <Empty>Aucun paiement enregistré.</Empty>
            )}
            {o.fulfillment_status !== 'cancelled' || payments.length ? (
              <form className="a-form a-form--row" onSubmit={recordPayment}>
                <Field label={pay.refund ? 'Montant remboursé ($)' : 'Montant reçu ($)'}>
                  <input className="a-input" inputMode="decimal" value={pay.amount} placeholder={toDollars(Math.max(balance, 0))} onChange={(e) => setPay({ ...pay, amount: e.target.value })} />
                </Field>
                <Field label="Moyen">
                  <select className="a-input" value={pay.provider} onChange={(e) => setPay({ ...pay, provider: e.target.value })}>
                    <option value="cash">Comptant</option>
                    <option value="debit">Débit (terminal)</option>
                    <option value="credit">Crédit (terminal)</option>
                    <option value="interac">Virement Interac</option>
                    <option value="stripe">Stripe (en ligne)</option>
                  </select>
                </Field>
                <Field label="Référence (reçu, transaction)">
                  <input className="a-input" value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} />
                </Field>
                <label className="a-check">
                  <input type="checkbox" checked={pay.refund} onChange={(e) => setPay({ ...pay, refund: e.target.checked })} /> Remboursement
                </label>
                <button className="a-btn a-btn--primary">{pay.refund ? 'Enregistrer le remboursement' : 'Enregistrer le paiement'}</button>
              </form>
            ) : null}
          </Card>
        </div>

        <div className="a-stack">
          <Card title="Client">
            <p>
              <strong>{o.contact_name}</strong>
              <br />
              <a href={`tel:${o.contact_phone}`}>{o.contact_phone}</a>
              {o.contact_email && (
                <>
                  <br />
                  <a href={`mailto:${o.contact_email}`}>{o.contact_email}</a>
                </>
              )}
            </p>
            {o.customer_note && <p className="a-note">« {o.customer_note} »</p>}
          </Card>
          <Card title={o.fulfillment_method === 'delivery' ? 'Livraison' : 'Retrait en magasin'}>
            {o.fulfillment_method === 'delivery' ? (
              <>
                <p>
                  {addr.line1}
                  {addr.line2 && `, ${addr.line2}`}
                  <br />
                  {addr.city} {addr.postal_code}
                </p>
                {addr.line1 && (
                  <a className="a-btn" target="_blank" rel="noopener" href={`https://www.google.com/maps/dir/?api=1&origin=65+Rue+Notre-Dame+E,+Trois-Rivi%C3%A8res&destination=${encodeURIComponent(`${addr.line1}, ${addr.city} ${addr.postal_code ?? ''}`)}`}>
                    <Icon name="navigation" /> Itinéraire
                  </a>
                )}
              </>
            ) : (
              <p>65 Rue Notre-Dame E, Trois-Rivières</p>
            )}
            {(o.requested_for || o.pickup_slot_start) && <p className="a-muted">Souhaité : {dateTime(o.pickup_slot_start ?? o.requested_for)}</p>}
          </Card>
          <Card title="Avertir le client">
            <p className="a-small a-muted">{notifyText}</p>
            <div className="a-actions">
              <a
                className="a-btn a-btn--wa"
                target="_blank"
                rel="noopener"
                href={`https://wa.me/${digits(o.contact_phone)}?text=${encodeURIComponent(notifyText)}`}
                onClick={() => logNotification('whatsapp', notifyText)}
              >
                <Icon name="message-circle" /> WhatsApp
              </a>
              <a className="a-btn" href={`sms:${o.contact_phone}?body=${encodeURIComponent(notifyText)}`} onClick={() => logNotification('sms', notifyText)}>
                <Icon name="smartphone" /> Texto
              </a>
              {o.contact_email && (
                <a
                  className="a-btn"
                  href={`mailto:${o.contact_email}?subject=${encodeURIComponent(`Commande ${o.number}`)}&body=${encodeURIComponent(notifyText)}`}
                  onClick={() => logNotification('email', notifyText)}
                >
                  <Icon name="mail" /> Courriel
                </a>
              )}
            </div>
          </Card>
          <Card title="Historique et notes">
            <div className="a-form a-form--row">
              <input className="a-input" placeholder="Ajouter une note interne…" value={note} onChange={(e) => setNote(e.target.value)} />
              <button className="a-btn" onClick={addNote}>
                Ajouter
              </button>
            </div>
            <ul className="a-timeline">
              {events.map((ev: any) => (
                <li key={ev.id}>
                  <small className="a-muted">{dateTime(ev.created_at)}</small>
                  <div>
                    {ev.kind === 'status' && <>Statut : {label(FULFILLMENT, ev.from_value, lang)} → <strong>{label(FULFILLMENT, ev.to_value, lang)}</strong></>}
                    {ev.kind === 'payment' && <>Paiement : {label(PAYMENT_STATUS, ev.from_value, lang)} → <strong>{label(PAYMENT_STATUS, ev.to_value, lang)}</strong></>}
                    {ev.kind === 'note' && <>Note : {ev.note}</>}
                    {ev.kind === 'notification' && <>Client averti ({ev.to_value})</>}
                  </div>
                </li>
              ))}
              <li>
                <small className="a-muted">{dateTime(o.created_at)}</small>
                <div>Commande reçue</div>
              </li>
            </ul>
          </Card>
        </div>
      </div>
    </>
  );
}

// ---------- Manual order entry (WhatsApp, phone, in store) ----------

type Line = { product_id: number; variant_id: number | null; name: string; options: Record<string, string> | null; sku: string | null; unit_price_cents: number; quantity: number; unit_label: string | null; department_id: number | null; category_id: number | null };

function NewOrder() {
  const [form, setForm] = useState({
    channel: 'whatsapp',
    contact_name: '',
    contact_phone: '',
    contact_email: '',
    fulfillment_method: 'pickup',
    payment_method: 'pay_in_store',
    line1: '',
    line2: '',
    city: 'Trois-Rivières',
    postal_code: '',
    km: '',
    fee: '',
    note: '',
  });
  const [lines, setLines] = useState<Line[]>([]);
  const [search, setSearch] = useState('');
  const [results, setResults] = useState<any[]>([]);
  const [delivery, setDelivery] = useState<any>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }));

  useEffect(() => {
    supabase
      .from('settings')
      .select('value')
      .eq('key', 'delivery')
      .single()
      .then(({ data }) => setDelivery(data?.value));
  }, []);

  useEffect(() => {
    const s = search.trim();
    if (s.length < 2) return setResults([]);
    const tm = setTimeout(async () => {
      const { data } = await supabase
        .from('products')
        .select('id, name_fr, price_cents, unit_label, sku, department_id, has_variants, product_variants(id, options, price_cents, sku, active), product_categories(category_id)')
        .or(`name_fr.ilike.%${s.replace(/[%,()]/g, ' ')}%,sku.ilike.%${s.replace(/[%,()]/g, ' ')}%`)
        .eq('status', 'published')
        .limit(12);
      setResults(data ?? []);
    }, 250);
    return () => clearTimeout(tm);
  }, [search]);

  const subtotal = lines.reduce((n, l) => n + Math.round(l.unit_price_cents * l.quantity), 0);
  const suggestedFee = useMemo(() => {
    if (form.fulfillment_method !== 'delivery' || !delivery || form.km === '') return null;
    const km = Math.round(Number(form.km.replace(',', '.')) * 10) / 10;
    if (!Number.isFinite(km)) return null;
    if (km > delivery.max_km) return 'trop loin';
    if (subtotal >= delivery.free_min_subtotal_cents && km <= delivery.free_radius_km) return 0;
    const tiers = delivery.tiers as { from_km: number; to_km: number; fee_cents: number }[];
    const t = tiers.find((t, i) => km >= t.from_km && (i === tiers.length - 1 ? km <= t.to_km : km < t.to_km));
    return t ? t.fee_cents : delivery.flat_fee_cents ?? null;
  }, [form.km, form.fulfillment_method, delivery, subtotal]);

  const fee = form.fulfillment_method === 'delivery' ? (form.fee !== '' ? toCents(form.fee) ?? 0 : typeof suggestedFee === 'number' ? suggestedFee : 0) : 0;
  const total = subtotal + fee;

  const add = (p: any, v?: any) => {
    const opts = v ? (v.options as Record<string, string>) : null;
    setLines((ls) => [
      ...ls,
      {
        product_id: p.id,
        variant_id: v?.id ?? null,
        name: p.name_fr,
        options: opts,
        sku: v?.sku ?? p.sku ?? null,
        unit_price_cents: (v?.price_cents ?? p.price_cents) || 0,
        quantity: 1,
        unit_label: p.unit_label,
        department_id: p.department_id,
        category_id: p.product_categories?.[0]?.category_id ?? null,
      },
    ]);
    setSearch('');
    setResults([]);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!lines.length) return notify('Ajoutez au moins un produit.', 'bad');
    if (!form.contact_name.trim() || !form.contact_phone.trim()) return notify('Nom et téléphone requis.', 'bad');
    if (form.fulfillment_method === 'delivery' && !form.line1.trim()) return notify('Adresse de livraison requise.', 'bad');
    if (suggestedFee === 'trop loin' && form.fee === '') return notify(`Au-delà de ${delivery.max_km} km : entrez des frais manuels ou choisissez le retrait.`, 'bad');
    setBusy(true);
    try {
      const order = must(
        await supabase
          .from('orders')
          .insert({
            channel: form.channel,
            contact_name: form.contact_name.trim(),
            contact_phone: form.contact_phone.trim(),
            contact_email: form.contact_email.trim() || null,
            fulfillment_method: form.fulfillment_method,
            payment_method: form.payment_method,
            subtotal_cents: subtotal,
            delivery_fee_cents: fee,
            total_cents: total,
            delivery_address: form.fulfillment_method === 'delivery' ? { line1: form.line1, line2: form.line2, city: form.city, postal_code: form.postal_code.toUpperCase() } : null,
            delivery_distance_km: form.km === '' ? null : Number(form.km.replace(',', '.')),
            distance_method: form.km === '' ? null : 'manual',
            customer_note: form.note || null,
          })
          .select('id, number')
          .single(),
      ) as { id: number; number: string };
      must(
        await supabase.from('order_items').insert(
          lines.map((l) => ({ ...l, order_id: order.id, line_total_cents: Math.round(l.unit_price_cents * l.quantity) })),
        ),
      );
      // Take tracked stock out.
      const { data: u } = await supabase.auth.getUser();
      for (const l of lines) {
        const table = l.variant_id ? 'product_variants' : 'products';
        const idv = l.variant_id ?? l.product_id;
        const { data: row } = await supabase.from(table).select('track_stock, stock_qty').eq('id', idv).single();
        if (row?.track_stock) {
          await supabase.from(table).update({ stock_qty: Number(row.stock_qty ?? 0) - l.quantity }).eq('id', idv);
          await supabase.from('stock_movements').insert({ product_id: l.product_id, variant_id: l.variant_id, change: -l.quantity, reason: 'order', order_id: order.id, actor: u.user?.id });
        }
      }
      await audit('order.create', 'order', order.id, { channel: form.channel });
      notify(`Commande ${order.number} créée.`);
      location.hash = `#/commandes/${order.id}`;
    } catch (err) {
      notify(err instanceof Error ? err.message : String((err as any)?.message ?? err), 'bad');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHead title="Nouvelle commande" sub="Pour les commandes reçues par WhatsApp, téléphone, courriel ou en magasin" actions={<a className="a-btn" href="#/commandes">Annuler</a>} />
      <form onSubmit={submit} className="a-grid2 a-grid2--wide">
        <div className="a-stack">
          <Card title="Produits">
            <div className="a-search">
              <input className="a-input" placeholder="Rechercher un produit (nom ou SKU)…" value={search} onChange={(e) => setSearch(e.target.value)} />
              {results.length > 0 && (
                <ul className="a-search__results">
                  {results.flatMap((p) =>
                    p.has_variants && p.product_variants?.length
                      ? p.product_variants
                          .filter((v: any) => v.active)
                          .map((v: any) => (
                            <li key={`v${v.id}`}>
                              <button type="button" onClick={() => add(p, v)}>
                                {p.name_fr} <small>({Object.values(v.options).join(', ')})</small> <span>{money(v.price_cents)}</span>
                              </button>
                            </li>
                          ))
                      : [
                          <li key={`p${p.id}`}>
                            <button type="button" onClick={() => add(p)}>
                              {p.name_fr} <span>{money(p.price_cents)}</span>
                            </button>
                          </li>,
                        ],
                  )}
                </ul>
              )}
            </div>
            {lines.length ? (
              <table className="a-table">
                <tbody>
                  {lines.map((l, i) => (
                    <tr key={i}>
                      <td>
                        {l.name}
                        {l.options && <div className="a-muted a-small">{Object.values(l.options).join(', ')}</div>}
                      </td>
                      <td>
                        <input
                          className="a-input a-input--sm"
                          inputMode="decimal"
                          value={toDollars(l.unit_price_cents)}
                          onChange={(e) => setLines((ls) => ls.map((x, j) => (j === i ? { ...x, unit_price_cents: toCents(e.target.value) ?? 0 } : x)))}
                          aria-label="Prix"
                        />
                      </td>
                      <td>
                        <input
                          className="a-input a-input--sm"
                          inputMode="decimal"
                          value={l.quantity}
                          onChange={(e) => setLines((ls) => ls.map((x, j) => (j === i ? { ...x, quantity: Math.max(0.001, Number(e.target.value.replace(',', '.')) || 1) } : x)))}
                          aria-label="Quantité"
                        />
                      </td>
                      <td className="a-num">{money(Math.round(l.unit_price_cents * l.quantity))}</td>
                      <td>
                        <button type="button" className="a-btn a-btn--ghost a-btn--icon" onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))} aria-label="Retirer">
                          <Icon name="trash-2" size={16} />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <Empty>Recherchez et ajoutez les produits demandés par le client.</Empty>
            )}
          </Card>
        </div>
        <div className="a-stack">
          <Card title="Client">
            <div className="a-form">
              <Field label="Reçue par">
                <select className="a-input" value={form.channel} onChange={(e) => set('channel', e.target.value)}>
                  {Object.entries(CHANNEL).map(([k, v]) => (
                    <option key={k} value={k}>
                      {v[0]}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Nom complet">
                <input className="a-input" value={form.contact_name} onChange={(e) => set('contact_name', e.target.value)} required />
              </Field>
              <Field label="Téléphone">
                <input className="a-input" type="tel" value={form.contact_phone} onChange={(e) => set('contact_phone', e.target.value)} required />
              </Field>
              <Field label="Courriel (facultatif)">
                <input className="a-input" type="email" value={form.contact_email} onChange={(e) => set('contact_email', e.target.value)} />
              </Field>
            </div>
          </Card>
          <Card title="Réception et paiement">
            <div className="a-form">
              <div className="a-seg">
                {[
                  ['pickup', 'Retrait en magasin'],
                  ['delivery', 'Livraison'],
                ].map(([k, l]) => (
                  <label key={k} className={form.fulfillment_method === k ? 'is-on' : ''}>
                    <input type="radio" name="fm" value={k} checked={form.fulfillment_method === k} onChange={() => set('fulfillment_method', k)} /> {l}
                  </label>
                ))}
              </div>
              {form.fulfillment_method === 'delivery' && (
                <>
                  <Field label="Adresse">
                    <input className="a-input" value={form.line1} onChange={(e) => set('line1', e.target.value)} />
                  </Field>
                  <div className="a-form--row">
                    <Field label="App.">
                      <input className="a-input" value={form.line2} onChange={(e) => set('line2', e.target.value)} />
                    </Field>
                    <Field label="Code postal">
                      <input className="a-input" value={form.postal_code} onChange={(e) => set('postal_code', e.target.value)} />
                    </Field>
                  </div>
                  <Field label="Ville">
                    <input className="a-input" value={form.city} onChange={(e) => set('city', e.target.value)} />
                  </Field>
                  <div className="a-form--row">
                    <Field label="Distance par la route (km)" hint="Vérifiez avec Google Maps">
                      <input className="a-input" inputMode="decimal" value={form.km} onChange={(e) => set('km', e.target.value)} />
                    </Field>
                    <Field label="Frais ($)" hint={suggestedFee === null ? '' : suggestedFee === 'trop loin' ? `Au-delà de ${delivery?.max_km} km` : `Selon les tarifs : ${money(suggestedFee as number)}`}>
                      <input className="a-input" inputMode="decimal" value={form.fee} placeholder={typeof suggestedFee === 'number' ? toDollars(suggestedFee) : ''} onChange={(e) => set('fee', e.target.value)} />
                    </Field>
                  </div>
                </>
              )}
              <Field label="Paiement">
                <select className="a-input" value={form.payment_method} onChange={(e) => set('payment_method', e.target.value)}>
                  <option value="pay_in_store">En magasin</option>
                  <option value="pay_on_delivery">À la livraison</option>
                  <option value="card_online">Carte en ligne (lien de paiement)</option>
                </select>
              </Field>
              <Field label="Note du client">
                <textarea className="a-input" rows={2} value={form.note} onChange={(e) => set('note', e.target.value)} />
              </Field>
            </div>
          </Card>
          <Card>
            <dl className="a-totals">
              <dt>Sous-total</dt>
              <dd>{money(subtotal)}</dd>
              {form.fulfillment_method === 'delivery' && (
                <>
                  <dt>Livraison</dt>
                  <dd>{money(fee)}</dd>
                </>
              )}
              <dt className="a-total">Total</dt>
              <dd className="a-total">{money(total)}</dd>
            </dl>
            <button className="a-btn a-btn--primary a-btn--block" disabled={busy}>
              {busy ? 'Création…' : 'Créer la commande'}
            </button>
            <p className="a-muted a-small">La commande est créée « non payée ». Enregistrez le paiement quand il est reçu.</p>
          </Card>
        </div>
      </form>
    </>
  );
}
