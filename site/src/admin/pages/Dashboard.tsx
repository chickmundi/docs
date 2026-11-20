import { supabase, money, dateOnly } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Empty, useLoad, must, Badge } from '../components/ui';
import Icon from '../components/Icon';

type Summary = Record<string, number>;

const monthStart = () => {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString();
};
const tomorrow = () => new Date(Date.now() + 86400000).toISOString();

export default function Dashboard() {
  const t = useT();
  const { data, error, loading } = useLoad(async () => {
    const [summary, depts, topGrocery, topBeauty, cats, low, expiring, catalog] = await Promise.all([
      supabase.rpc('dashboard_summary').then(must),
      supabase.rpc('sales_by_department', { from_ts: monthStart(), to_ts: tomorrow() }).then(must),
      supabase.rpc('top_products', { from_ts: monthStart(), to_ts: tomorrow(), dept: 1, lim: 5 }).then(must),
      supabase.rpc('top_products', { from_ts: monthStart(), to_ts: tomorrow(), dept: 2, lim: 5 }).then(must),
      supabase.rpc('top_categories', { from_ts: monthStart(), to_ts: tomorrow(), lim: 5 }).then(must),
      supabase.from('low_stock').select('*').limit(8).then(must),
      supabase.from('expiring_lots').select('*').limit(8).then(must),
      supabase.from('products').select('id', { count: 'exact', head: true }).eq('status', 'published'),
    ]);
    return { summary: summary as Summary, depts, topGrocery, topBeauty, cats, low, expiring, catalogCount: catalog.count ?? 0 };
  });

  if (loading) return <Loading />;
  if (error) return <ErrorBox error={error} />;
  const s = data!.summary;
  const delivery = s.delivery_month ?? 0;
  const pickup = s.pickup_month ?? 0;
  const total = delivery + pickup;

  const kpis: [string, string, string][] = [
    ['Ventes du jour', money(s.sales_today_cents), 'calendar-days'],
    ['Ventes de la semaine', money(s.sales_week_cents), 'calendar-range'],
    ['Ventes du mois', money(s.sales_month_cents), 'chart-line'],
    ['Panier moyen', money(s.avg_order_cents), 'shopping-basket'],
  ];
  const queues: [string, number, string, string][] = [
    ['À préparer', s.awaiting_preparation, 'package', '#/commandes?statut=a-preparer'],
    ['Prêtes au retrait', s.awaiting_pickup, 'store', '#/commandes?statut=ready_for_pickup'],
    ['À livrer', s.awaiting_delivery, 'truck', '#/commandes?statut=a-livrer'],
    ['Non payées', s.unpaid_open, 'wallet', '#/commandes?paiement=unpaid'],
    ['Livrées ce mois', s.delivered_month, 'circle-check', '#/commandes?statut=delivered'],
    ['Annulées ce mois', s.cancelled_month, 'circle-x', '#/commandes?statut=cancelled'],
  ];

  return (
    <>
      <PageHead title={t('Tableau de bord')} sub={`${data!.catalogCount} produits en ligne · chiffres calculés à partir des commandes enregistrées (annulations exclues)`} />
      <div className="a-kpis">
        {kpis.map(([l, v, i]) => (
          <div className="a-kpi" key={l}>
            <Icon name={i} size={20} />
            <span>{t(l)}</span>
            <strong>{v}</strong>
          </div>
        ))}
      </div>
      <div className="a-queues">
        {queues.map(([l, n, i, href]) => (
          <a className="a-queue" href={href} key={l}>
            <Icon name={i} size={18} />
            <span>{t(l)}</span>
            <strong>{n ?? 0}</strong>
          </a>
        ))}
      </div>

      <div className="a-grid2">
        <Card title="Ventes du mois par département">
          <table className="a-table">
            <tbody>
              {data!.depts.map((d: { department_id: number; name: string; revenue_cents: number; items: number }) => (
                <tr key={d.department_id}>
                  <td>{d.name}</td>
                  <td className="a-num">{Number(d.items)} article(s)</td>
                  <td className="a-num">
                    <strong>{money(d.revenue_cents)}</strong>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="a-muted a-small" style={{ marginTop: 12 }}>
            {t('Livraison ou retrait')} (mois) : {delivery} livraison(s), {pickup} retrait(s)
            {total > 0 && ` · ${Math.round((delivery / total) * 100)} % en livraison`}
          </p>
        </Card>
        <Card title="Catégories les plus vendues (mois)">
          {data!.cats.length ? (
            <table className="a-table">
              <tbody>
                {data!.cats.map((c: { category_id: number; name: string; revenue_cents: number; orders: number }) => (
                  <tr key={c.category_id}>
                    <td>{c.name}</td>
                    <td className="a-num">{c.orders} cmd</td>
                    <td className="a-num">{money(c.revenue_cents)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <Empty>Aucune vente ce mois-ci.</Empty>
          )}
        </Card>
        {[
          ['Meilleures ventes : Épicerie', data!.topGrocery],
          ['Meilleures ventes : Beauté et cheveux', data!.topBeauty],
        ].map(([title, rows]) => (
          <Card key={title as string} title={title as string}>
            {(rows as unknown[]).length ? (
              <table className="a-table">
                <tbody>
                  {(rows as { product_id: number; name: string; quantity: number; revenue_cents: number }[]).map((r) => (
                    <tr key={r.product_id}>
                      <td>
                        <a href={`#/produits/${r.product_id}`}>{r.name}</a>
                      </td>
                      <td className="a-num">× {Number(r.quantity)}</td>
                      <td className="a-num">{money(r.revenue_cents)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <Empty>Aucune vente ce mois-ci.</Empty>
            )}
          </Card>
        ))}
        <Card title={t('Stock faible')} actions={<a href="#/inventaire">Inventaire</a>}>
          {data!.low.length ? (
            <table className="a-table">
              <tbody>
                {data!.low.map((r: { product_id: number; variant_id: number | null; name: string; stock_qty: number }) => (
                  <tr key={`${r.product_id}-${r.variant_id}`}>
                    <td>
                      <a href={`#/produits/${r.product_id}`}>{r.name}</a>
                    </td>
                    <td className="a-num">
                      <Badge tone={Number(r.stock_qty) <= 0 ? 'bad' : 'warn'}>{Number(r.stock_qty)}</Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <Empty>Aucun produit suivi en stock faible. Le suivi de stock s’active par produit.</Empty>
          )}
        </Card>
        <Card title={t('Péremption proche')} actions={<a href="#/inventaire">Lots</a>}>
          {data!.expiring.length ? (
            <table className="a-table">
              <tbody>
                {data!.expiring.map((l: { id: number; product_id: number; product_name: string; lot_number: string; expiry_date: string; days_left: number; quantity: number }) => (
                  <tr key={l.id}>
                    <td>
                      <a href={`#/produits/${l.product_id}`}>{l.product_name}</a>
                      {l.lot_number && <small className="a-muted"> · lot {l.lot_number}</small>}
                    </td>
                    <td>{dateOnly(l.expiry_date)}</td>
                    <td className="a-num">
                      <Badge tone={l.days_left < 0 ? 'bad' : 'warn'}>{l.days_left < 0 ? 'expiré' : `${l.days_left} j`}</Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <Empty>Aucun lot n’expire dans les 14 prochains jours.</Empty>
          )}
        </Card>
      </div>
    </>
  );
}
