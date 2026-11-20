import { useState } from 'react';
import { supabase, money } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { PageHead, Loading, ErrorBox, Empty, Badge, useLoad, must } from '../components/ui';
import Icon from '../components/Icon';

const PAGE = 50;
const STATUS: Record<string, [string, string]> = { published: ['En ligne', 'ok'], draft: ['Brouillon', 'neutral'], archived: ['Archivé', 'bad'] };

export default function Products() {
  const t = useT();
  const [q, setQ] = useState('');
  const [dept, setDept] = useState('');
  const [cat, setCat] = useState('');
  const [status, setStatus] = useState('');
  const [flag, setFlag] = useState('');
  const [page, setPage] = useState(0);

  const meta = useLoad(async () => ({
    depts: must(await supabase.from('departments').select('id, name_fr').order('sort')),
    cats: must(await supabase.from('categories').select('id, name_fr, parent_id').order('name_fr')),
  }));

  const { data, error, loading } = useLoad(async () => {
    const catJoin = cat ? 'product_categories!inner(category_id)' : 'product_categories(category_id)';
    let query = supabase
      .from('products')
      .select(`id, name_fr, slug, status, price_cents, regular_price_cents, has_variants, sku, track_stock, stock_qty, in_stock, department_id, extra_attributes, product_images(url, sort), ${catJoin}`, { count: 'exact' })
      .order('id', { ascending: false })
      .range(page * PAGE, page * PAGE + PAGE - 1);
    if (q.trim()) {
      const s = q.trim().replace(/[%,()]/g, ' ');
      query = /^\d+$/.test(s) ? query.or(`id.eq.${s},sku.ilike.%${s}%,barcode.eq.${s}`) : query.or(`name_fr.ilike.%${s}%,sku.ilike.%${s}%,name_en.ilike.%${s}%`);
    }
    if (dept) query = query.eq('department_id', Number(dept));
    if (cat) query = query.eq('product_categories.category_id', Number(cat));
    if (status) query = query.eq('status', status);
    if (flag === 'out') query = query.eq('in_stock', false);
    if (flag === 'nosku') query = query.is('sku', null).eq('has_variants', false);
    if (flag === 'tracked') query = query.eq('track_stock', true);
    if (flag === 'expiry') query = query.eq('track_expiry', true);
    const res = await query;
    return { rows: must(res), count: res.count ?? 0 };
  }, [q, dept, cat, status, flag, page]);

  const reset = () => setPage(0);
  const parentName = (id: number | null) => meta.data?.cats.find((c: any) => c.id === id)?.name_fr;

  return (
    <>
      <PageHead
        title={t('Produits')}
        sub={data ? `${data.count} produit(s)` : undefined}
        actions={
          <a className="a-btn a-btn--primary" href="#/produits/nouveau">
            <Icon name="plus" /> Nouveau produit
          </a>
        }
      />
      <div className="a-filters">
        <input className="a-input" placeholder="Nom, SKU, code-barres ou n°…" value={q} onChange={(e) => (setQ(e.target.value), reset())} />
        <select className="a-input" value={dept} onChange={(e) => (setDept(e.target.value), reset())}>
          <option value="">Tous les départements</option>
          {meta.data?.depts.map((d: any) => (
            <option key={d.id} value={d.id}>
              {d.name_fr}
            </option>
          ))}
        </select>
        <select className="a-input" value={cat} onChange={(e) => (setCat(e.target.value), reset())}>
          <option value="">Toutes les catégories</option>
          {meta.data?.cats.map((c: any) => (
            <option key={c.id} value={c.id}>
              {c.parent_id ? `${parentName(c.parent_id)} › ` : ''}
              {c.name_fr}
            </option>
          ))}
        </select>
        <select className="a-input" value={status} onChange={(e) => (setStatus(e.target.value), reset())}>
          <option value="">Tous les statuts</option>
          {Object.entries(STATUS).map(([k, [l]]) => (
            <option key={k} value={k}>
              {l}
            </option>
          ))}
        </select>
        <select className="a-input" value={flag} onChange={(e) => (setFlag(e.target.value), reset())}>
          <option value="">Tous</option>
          <option value="out">En rupture</option>
          <option value="tracked">Stock suivi</option>
          <option value="expiry">Avec dates de péremption</option>
          <option value="nosku">Sans SKU</option>
        </select>
      </div>
      {loading ? (
        <Loading />
      ) : error ? (
        <ErrorBox error={error} />
      ) : !data!.rows.length ? (
        <Empty>Aucun produit.</Empty>
      ) : (
        <>
          <div className="a-tablewrap">
            <table className="a-table a-table--rows">
              <thead>
                <tr>
                  <th />
                  <th>Produit</th>
                  <th>Département</th>
                  <th>Statut</th>
                  <th className="a-num">Prix</th>
                  <th className="a-num">Stock</th>
                </tr>
              </thead>
              <tbody>
                {data!.rows.map((p: any) => {
                  const img = [...(p.product_images ?? [])].sort((a: any, b: any) => a.sort - b.sort)[0]?.url;
                  const onSale = p.regular_price_cents && p.price_cents < p.regular_price_cents;
                  return (
                    <tr key={p.id} onClick={() => (location.hash = `#/produits/${p.id}`)}>
                      <td className="a-thumbcell">{img ? <img src={img} alt="" width="44" height="44" loading="lazy" /> : <span className="a-thumb-empty" />}</td>
                      <td>
                        <a href={`#/produits/${p.id}`}>
                          <strong>{p.name_fr}</strong>
                        </a>
                        <div className="a-muted a-small">
                          n° {p.id}
                          {p.sku ? ` · SKU ${p.sku}` : p.extra_attributes?.legacy_sku ? ' · SKU à compléter' : ''}
                          {p.has_variants && ' · avec variations'}
                        </div>
                      </td>
                      <td>{meta.data?.depts.find((d: any) => d.id === p.department_id)?.name_fr ?? <Badge tone="warn">aucun</Badge>}</td>
                      <td>
                        <Badge tone={STATUS[p.status][1] as any}>{STATUS[p.status][0]}</Badge>
                      </td>
                      <td className="a-num">
                        {p.has_variants && 'dès '}
                        {money(p.price_cents)}
                        {onSale && <div className="a-small a-muted"><s>{money(p.regular_price_cents)}</s></div>}
                      </td>
                      <td className="a-num">
                        {p.track_stock && !p.has_variants ? (
                          <Badge tone={Number(p.stock_qty) <= 0 ? 'bad' : Number(p.stock_qty) <= 5 ? 'warn' : 'ok'}>{Number(p.stock_qty ?? 0)}</Badge>
                        ) : p.in_stock ? (
                          <Badge tone="ok">en stock</Badge>
                        ) : (
                          <Badge tone="bad">rupture</Badge>
                        )}
                      </td>
                    </tr>
                  );
                })}
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
