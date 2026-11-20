import { useEffect, useState } from 'react';
import { supabase, dateOnly, dateTime, audit } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Empty, Badge, Tabs, Field, useLoad, must, notify } from '../components/ui';

const REASONS: Record<string, string> = { receiving: 'Réception de marchandise', adjustment: 'Correction d’inventaire', loss: 'Perte / bris / périmé', order: 'Commande', cancel: 'Annulation', import: 'Import' };

export default function Inventory() {
  const t = useT();
  const [tab, setTab] = useState('low');
  return (
    <>
      <PageHead title={t('Inventaire')} sub="Le stock se suit produit par produit : activez « Suivre la quantité » dans la fiche produit" />
      <Tabs
        active={tab}
        onChange={setTab}
        tabs={[
          { key: 'low', label: t('Stock faible') },
          { key: 'expiry', label: 'Lots et péremption' },
          { key: 'adjust', label: 'Ajuster le stock' },
          { key: 'moves', label: 'Mouvements' },
        ]}
      />
      {tab === 'low' && <LowStock />}
      {tab === 'expiry' && <Expiry />}
      {tab === 'adjust' && <Adjust />}
      {tab === 'moves' && <Moves />}
    </>
  );
}

function LowStock() {
  const { data, error, loading } = useLoad(async () => ({
    low: must(await supabase.from('low_stock').select('*').order('stock_qty')),
    out: must(await supabase.from('products').select('id, name_fr').eq('in_stock', false).eq('status', 'published').order('name_fr').limit(200)),
  }));
  if (loading) return <Loading />;
  if (error) return <ErrorBox error={error} />;
  return (
    <div className="a-grid2">
      <Card title="Stock faible (produits suivis)">
        {data!.low.length ? (
          <table className="a-table">
            <tbody>
              {data!.low.map((r: any) => (
                <tr key={`${r.product_id}-${r.variant_id}`}>
                  <td>
                    <a href={`#/produits/${r.product_id}`}>{r.name}</a>
                    {r.sku && <div className="a-muted a-small">SKU {r.sku}</div>}
                  </td>
                  <td className="a-num">
                    <Badge tone={Number(r.stock_qty) <= 0 ? 'bad' : 'warn'}>{Number(r.stock_qty)}</Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <Empty>Aucun produit suivi n’est sous son seuil.</Empty>
        )}
      </Card>
      <Card title={`En rupture (${data!.out.length})`}>
        {data!.out.length ? (
          <ul className="a-list">
            {data!.out.map((p: any) => (
              <li key={p.id}>
                <a href={`#/produits/${p.id}`}>{p.name_fr}</a>
              </li>
            ))}
          </ul>
        ) : (
          <Empty>Aucun produit en rupture.</Empty>
        )}
      </Card>
    </div>
  );
}

function Expiry() {
  const [days, setDays] = useState(30);
  const { data, error, loading, reload } = useLoad(async () => {
    const limit = new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);
    return must(await supabase.from('inventory_lots').select('*, products(name_fr)').gt('quantity', 0).not('expiry_date', 'is', null).lte('expiry_date', limit).order('expiry_date'));
  }, [days]);
  const clear = async (l: any) => {
    if (!confirm(`Retirer le lot ${l.lot_number ?? ''} (${Number(l.quantity)} unités) du stock ?`)) return;
    const { data: u } = await supabase.auth.getUser();
    must(await supabase.from('inventory_lots').update({ quantity: 0, notes: `${l.notes ?? ''} [retiré ${new Date().toLocaleDateString('fr-CA')}]`.trim() }).eq('id', l.id));
    await supabase.from('stock_movements').insert({ product_id: l.product_id, change: -Number(l.quantity), reason: 'loss', note: `Lot ${l.lot_number ?? l.id} périmé`, actor: u.user?.id });
    await audit('lot.clear', 'inventory_lot', l.id);
    reload();
  };
  return (
    <Card
      title="Lots qui expirent"
      actions={
        <select className="a-input" value={days} onChange={(e) => setDays(Number(e.target.value))}>
          <option value={7}>7 prochains jours</option>
          <option value={14}>14 prochains jours</option>
          <option value={30}>30 prochains jours</option>
          <option value={90}>90 prochains jours</option>
        </select>
      }
    >
      {loading ? (
        <Loading />
      ) : error ? (
        <ErrorBox error={error} />
      ) : data!.length ? (
        <table className="a-table">
          <thead>
            <tr>
              <th>Produit</th>
              <th>Lot</th>
              <th>Péremption</th>
              <th className="a-num">Qté</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {data!.map((l: any) => {
              const left = Math.round((new Date(l.expiry_date).getTime() - Date.now()) / 86400000);
              return (
                <tr key={l.id}>
                  <td>
                    <a href={`#/produits/${l.product_id}`}>{l.products?.name_fr}</a>
                  </td>
                  <td>{l.lot_number ?? '—'}</td>
                  <td>
                    {dateOnly(l.expiry_date)} <Badge tone={left < 0 ? 'bad' : left <= 7 ? 'warn' : 'neutral'}>{left < 0 ? 'expiré' : `${left} j`}</Badge>
                  </td>
                  <td className="a-num">{Number(l.quantity)}</td>
                  <td className="a-num">
                    <button className="a-btn a-btn--ghost" onClick={() => clear(l)}>
                      Retirer du stock
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      ) : (
        <Empty>Aucun lot n’expire dans cette période. Les lots se saisissent dans la fiche des produits périssables.</Empty>
      )}
    </Card>
  );
}

function Adjust() {
  const [q, setQ] = useState('');
  const [results, setResults] = useState<any[]>([]);
  const [pick, setPick] = useState<any>(null);
  const [change, setChange] = useState('');
  const [reason, setReason] = useState('receiving');
  const [note, setNote] = useState('');
  useEffect(() => {
    if (q.trim().length < 2) return setResults([]);
    const tm = setTimeout(async () => {
      const s = q.trim().replace(/[%,()]/g, ' ');
      const { data } = await supabase
        .from('products')
        .select('id, name_fr, sku, track_stock, stock_qty, has_variants, product_variants(id, options, sku, track_stock, stock_qty)')
        .or(`name_fr.ilike.%${s}%,sku.ilike.%${s}%,barcode.eq.${s}`)
        .limit(10);
      setResults(data ?? []);
    }, 250);
    return () => clearTimeout(tm);
  }, [q]);

  const apply = async (e: React.FormEvent) => {
    e.preventDefault();
    const n = Number(change.replace(',', '.'));
    if (!pick || !n) return notify('Choisissez un produit et une quantité (ex. 12 ou -3).', 'bad');
    const table = pick.variant ? 'product_variants' : 'products';
    const id = pick.variant?.id ?? pick.product.id;
    const current = Number((pick.variant ?? pick.product).stock_qty ?? 0);
    const { data: u } = await supabase.auth.getUser();
    const { error } = await supabase.from(table).update({ track_stock: true, stock_qty: current + n, ...(current + n > 0 ? { in_stock: true } : {}) }).eq('id', id);
    if (error) return notify(error.message, 'bad');
    await supabase.from('stock_movements').insert({ product_id: pick.product.id, variant_id: pick.variant?.id ?? null, change: n, reason, note: note || null, actor: u.user?.id });
    await audit('stock.adjust', table, id, { change: n, reason });
    notify(`Stock mis à jour : ${current} → ${current + n}`);
    setPick(null);
    setChange('');
    setNote('');
    setQ('');
  };

  return (
    <Card title="Ajuster le stock">
      <form className="a-form" onSubmit={apply}>
        <Field label="Produit" hint="Nom, SKU ou code-barres (lecteur de code-barres accepté)">
          <div className="a-search">
            <input className="a-input" value={pick ? `${pick.product.name_fr}${pick.variant ? ` (${Object.values(pick.variant.options).join(', ')})` : ''}` : q} onChange={(e) => (setPick(null), setQ(e.target.value))} />
            {!pick && results.length > 0 && (
              <ul className="a-search__results">
                {results.flatMap((p) =>
                  p.has_variants && p.product_variants?.length
                    ? p.product_variants.map((v: any) => (
                        <li key={`v${v.id}`}>
                          <button type="button" onClick={() => setPick({ product: p, variant: v })}>
                            {p.name_fr} <small>({Object.values(v.options).join(', ')})</small> <span>{v.track_stock ? `stock ${Number(v.stock_qty ?? 0)}` : 'non suivi'}</span>
                          </button>
                        </li>
                      ))
                    : [
                        <li key={`p${p.id}`}>
                          <button type="button" onClick={() => setPick({ product: p })}>
                            {p.name_fr} <span>{p.track_stock ? `stock ${Number(p.stock_qty ?? 0)}` : 'non suivi'}</span>
                          </button>
                        </li>,
                      ],
                )}
              </ul>
            )}
          </div>
        </Field>
        <div className="a-form--row">
          <Field label="Quantité à ajouter (négatif pour retirer)">
            <input className="a-input" inputMode="decimal" value={change} onChange={(e) => setChange(e.target.value)} placeholder="ex. 24 ou -2" />
          </Field>
          <Field label="Raison">
            <select className="a-input" value={reason} onChange={(e) => setReason(e.target.value)}>
              <option value="receiving">{REASONS.receiving}</option>
              <option value="adjustment">{REASONS.adjustment}</option>
              <option value="loss">{REASONS.loss}</option>
            </select>
          </Field>
        </div>
        <Field label="Note (fournisseur, facture…)">
          <input className="a-input" value={note} onChange={(e) => setNote(e.target.value)} />
        </Field>
        <button className="a-btn a-btn--primary">Mettre à jour le stock</button>
        <p className="a-muted a-small">Si le stock n’était pas suivi pour ce produit, le suivi s’active automatiquement.</p>
      </form>
    </Card>
  );
}

function Moves() {
  const { data, error, loading } = useLoad(async () => must(await supabase.from('stock_movements').select('*, products(name_fr)').order('created_at', { ascending: false }).limit(100)));
  if (loading) return <Loading />;
  if (error) return <ErrorBox error={error} />;
  return (
    <Card title="100 derniers mouvements">
      {data!.length ? (
        <table className="a-table">
          <tbody>
            {data!.map((m: any) => (
              <tr key={m.id}>
                <td>{dateTime(m.created_at)}</td>
                <td>
                  <a href={`#/produits/${m.product_id}`}>{m.products?.name_fr}</a>
                  {m.note && <div className="a-muted a-small">{m.note}</div>}
                </td>
                <td>{REASONS[m.reason] ?? m.reason}</td>
                <td className={`a-num ${Number(m.change) < 0 ? 'a-warn' : ''}`}>
                  {Number(m.change) > 0 ? '+' : ''}
                  {Number(m.change)}
                </td>
                <td>{m.order_id && <a href={`#/commandes/${m.order_id}`}>commande</a>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <Empty>Aucun mouvement de stock pour l’instant.</Empty>
      )}
    </Card>
  );
}
