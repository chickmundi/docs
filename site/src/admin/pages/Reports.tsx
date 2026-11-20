import { useState } from 'react';
import { supabase, money } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Empty, useLoad, must } from '../components/ui';

const iso = (d: Date) => d.toISOString().slice(0, 10);

export default function Reports() {
  const t = useT();
  const [from, setFrom] = useState(iso(new Date(Date.now() - 29 * 86400000)));
  const [to, setTo] = useState(iso(new Date()));
  const [dept, setDept] = useState('');
  const { data, error, loading } = useLoad(async () => {
    const fromTs = new Date(`${from}T00:00:00`).toISOString();
    const toTs = new Date(new Date(`${to}T00:00:00`).getTime() + 86400000).toISOString();
    const d = dept ? Number(dept) : null;
    const [days, depts, top, cats] = await Promise.all([
      supabase.rpc('sales_by_day', { from_date: from, to_date: to, dept: d }).then(must),
      supabase.rpc('sales_by_department', { from_ts: fromTs, to_ts: toTs }).then(must),
      supabase.rpc('top_products', { from_ts: fromTs, to_ts: toTs, dept: d, lim: 15 }).then(must),
      supabase.rpc('top_categories', { from_ts: fromTs, to_ts: toTs, lim: 10 }).then(must),
    ]);
    return { days: days as { day: string; orders: number; revenue_cents: number }[], depts, top, cats };
  }, [from, to, dept]);

  const exportCsv = () => {
    if (!data) return;
    const rows = [['jour', 'commandes', 'ventes ($)'], ...data.days.map((r) => [r.day, r.orders, (r.revenue_cents / 100).toFixed(2)])];
    const blob = new Blob([rows.map((r) => r.join(';')).join('\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `ventes-${from}-au-${to}.csv`;
    a.click();
  };

  const total = data?.days.reduce((n, r) => n + Number(r.revenue_cents), 0) ?? 0;
  const orders = data?.days.reduce((n, r) => n + Number(r.orders), 0) ?? 0;
  const max = Math.max(1, ...(data?.days.map((r) => Number(r.revenue_cents)) ?? [1]));

  return (
    <>
      <PageHead title={t('Rapports')} sub="Calculés à partir des commandes enregistrées, annulations exclues" actions={<button className="a-btn" onClick={exportCsv}>Exporter (CSV)</button>} />
      <div className="a-filters">
        <input className="a-input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="Du" />
        <input className="a-input" type="date" value={to} onChange={(e) => setTo(e.target.value)} aria-label="Au" />
        <select className="a-input" value={dept} onChange={(e) => setDept(e.target.value)}>
          <option value="">Tous les départements</option>
          <option value="1">Épicerie</option>
          <option value="2">Beauté et cheveux</option>
          <option value="3">Soins corporels</option>
        </select>
      </div>
      {loading ? (
        <Loading />
      ) : error ? (
        <ErrorBox error={error} />
      ) : (
        <>
          <div className="a-kpis">
            <div className="a-kpi">
              <span>Ventes</span>
              <strong>{money(total)}</strong>
            </div>
            <div className="a-kpi">
              <span>Commandes</span>
              <strong>{orders}</strong>
            </div>
            <div className="a-kpi">
              <span>{t('Panier moyen')}</span>
              <strong>{orders ? money(Math.round(total / orders)) : '—'}</strong>
            </div>
          </div>
          <Card title="Ventes par jour">
            <div className="a-bars" role="img" aria-label="Ventes par jour">
              {data!.days.map((r) => (
                <div key={r.day} className="a-bar" title={`${r.day} : ${money(Number(r.revenue_cents))} (${r.orders} commande(s))`}>
                  <span style={{ height: `${(Number(r.revenue_cents) / max) * 100}%` }} />
                </div>
              ))}
            </div>
            <div className="a-bars__axis">
              <span>{from}</span>
              <span>{to}</span>
            </div>
          </Card>
          <div className="a-grid2">
            <Card title="Par département">
              <table className="a-table">
                <tbody>
                  {data!.depts.map((d: any) => (
                    <tr key={d.department_id}>
                      <td>{d.name}</td>
                      <td className="a-num">{Number(d.items)} article(s)</td>
                      <td className="a-num">{money(d.revenue_cents)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
            <Card title="Catégories">
              {data!.cats.length ? (
                <table className="a-table">
                  <tbody>
                    {data!.cats.map((c: any) => (
                      <tr key={c.category_id}>
                        <td>{c.name}</td>
                        <td className="a-num">{c.orders} cmd</td>
                        <td className="a-num">{money(c.revenue_cents)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <Empty>Aucune vente sur la période.</Empty>
              )}
            </Card>
            <Card title="Produits les plus vendus" className="a-span2">
              {data!.top.length ? (
                <table className="a-table">
                  <thead>
                    <tr>
                      <th>Produit</th>
                      <th className="a-num">Quantité</th>
                      <th className="a-num">Ventes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data!.top.map((p: any) => (
                      <tr key={p.product_id}>
                        <td>
                          <a href={`#/produits/${p.product_id}`}>{p.name}</a>
                        </td>
                        <td className="a-num">{Number(p.quantity)}</td>
                        <td className="a-num">{money(p.revenue_cents)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <Empty>Aucune vente sur la période.</Empty>
              )}
            </Card>
          </div>
        </>
      )}
    </>
  );
}
