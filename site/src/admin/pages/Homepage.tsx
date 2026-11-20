import { useEffect, useState } from 'react';
import { supabase, audit, money } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Field, Badge, useLoad, must, notify } from '../components/ui';
import Icon from '../components/Icon';

type Section = { key: string; title_fr: string | null; title_en: string | null; eyebrow_fr: string | null; body_fr: string | null; mode: 'auto' | 'manual'; category_id: number | null; product_ids: number[]; max_items: number; visible: boolean; sort: number };

const AUTO_RULE: Record<string, string> = {
  hero: 'Bannière principale',
  categories: 'Toutes les catégories principales',
  promotions: 'Produits en solde, du plus grand rabais au plus petit',
  new: 'Derniers produits ajoutés, en stock',
  epicerie: 'Produits d’épicerie en stock (hors « Autres »)',
  surgeles: 'Produits surgelés en stock',
  perruques: 'Perruques en stock',
  'extensions-capillaires': 'Extensions en stock',
  'soins-capillaires': 'Soins capillaires en stock',
  'soins-corporels': 'Soins corporels en stock',
};

function ProductPicker({ ids, onChange }: { ids: number[]; onChange: (ids: number[]) => void }) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState<any[]>([]);
  const [chosen, setChosen] = useState<any[]>([]);
  useEffect(() => {
    if (!ids.length) return setChosen([]);
    supabase
      .from('products')
      .select('id, name_fr, price_cents, product_images(url, sort)')
      .in('id', ids)
      .then(({ data }) => setChosen(ids.map((id) => data?.find((p) => p.id === id)).filter(Boolean)));
  }, [ids.join(',')]);
  useEffect(() => {
    if (q.trim().length < 2) return setResults([]);
    const tm = setTimeout(async () => {
      const { data } = await supabase.from('products').select('id, name_fr, price_cents').ilike('name_fr', `%${q.trim()}%`).eq('status', 'published').limit(10);
      setResults(data ?? []);
    }, 250);
    return () => clearTimeout(tm);
  }, [q]);
  const move = (i: number, d: number) => {
    const c = [...ids];
    [c[i], c[i + d]] = [c[i + d], c[i]];
    onChange(c);
  };
  return (
    <div className="a-stack">
      <div className="a-search">
        <input className="a-input" placeholder="Ajouter un produit…" value={q} onChange={(e) => setQ(e.target.value)} />
        {results.length > 0 && (
          <ul className="a-search__results">
            {results.map((p) => (
              <li key={p.id}>
                <button type="button" disabled={ids.includes(p.id)} onClick={() => (onChange([...ids, p.id]), setQ(''))}>
                  {p.name_fr} <span>{money(p.price_cents)}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
      <ol className="a-picked">
        {chosen.map((p: any, i) => (
          <li key={p.id}>
            {p.product_images?.[0] && <img src={[...p.product_images].sort((a: any, b: any) => a.sort - b.sort)[0].url} alt="" width="36" height="36" />}
            <span>{p.name_fr}</span>
            <button className="a-btn a-btn--ghost a-btn--icon" disabled={i === 0} onClick={() => move(i, -1)} aria-label="Monter">
              <Icon name="arrow-up" size={14} />
            </button>
            <button className="a-btn a-btn--ghost a-btn--icon" disabled={i === chosen.length - 1} onClick={() => move(i, 1)} aria-label="Descendre">
              <Icon name="arrow-down" size={14} />
            </button>
            <button className="a-btn a-btn--ghost a-btn--icon" onClick={() => onChange(ids.filter((x) => x !== p.id))} aria-label="Retirer">
              <Icon name="x" size={14} />
            </button>
          </li>
        ))}
      </ol>
    </div>
  );
}

export default function Homepage() {
  const t = useT();
  const { data, error, loading, setData } = useLoad(async () => must(await supabase.from('homepage_sections').select('*').order('sort')) as Section[]);
  const [open, setOpen] = useState<string | null>(null);
  if (loading) return <Loading />;
  if (error) return <ErrorBox error={error} />;
  const sections = data!;
  const upd = (key: string, patch: Partial<Section>) => setData(sections.map((s) => (s.key === key ? { ...s, ...patch } : s)));
  const save = async (s: Section) => {
    const { error } = await supabase.from('homepage_sections').update({ ...s, updated_at: new Date().toISOString() }).eq('key', s.key);
    if (error) return notify(error.message, 'bad');
    await audit('homepage.update', 'homepage_section', s.key);
    notify('Section enregistrée.');
  };
  const reorder = async (i: number, d: number) => {
    const c = [...sections];
    [c[i], c[i + d]] = [c[i + d], c[i]];
    const withSort = c.map((s, j) => ({ ...s, sort: j + 1 }));
    setData(withSort);
    for (const s of withSort) await supabase.from('homepage_sections').update({ sort: s.sort }).eq('key', s.key);
  };

  return (
    <>
      <PageHead title={t("Page d'accueil")} sub="Titres, textes, ordre et produits de chaque section de l’accueil. Le style visuel du site reste le même." actions={<a className="a-btn" href="/" target="_blank" rel="noopener">Voir l’accueil ↗</a>} />
      <div className="a-stack">
        {sections.map((s, i) => (
          <Card
            key={s.key}
            title={
              <>
                {s.title_fr || s.key} {!s.visible && <Badge>masquée</Badge>} {s.mode === 'manual' && <Badge tone="brand">produits choisis</Badge>}
              </>
            }
            actions={
              <>
                <button className="a-btn a-btn--ghost a-btn--icon" disabled={i === 0} onClick={() => reorder(i, -1)} aria-label="Monter">
                  <Icon name="arrow-up" size={16} />
                </button>
                <button className="a-btn a-btn--ghost a-btn--icon" disabled={i === sections.length - 1} onClick={() => reorder(i, 1)} aria-label="Descendre">
                  <Icon name="arrow-down" size={16} />
                </button>
                <button className="a-btn" onClick={() => setOpen(open === s.key ? null : s.key)}>
                  {open === s.key ? 'Fermer' : t('Modifier')}
                </button>
              </>
            }
          >
            {open === s.key ? (
              <div className="a-form">
                <div className="a-form--row">
                  <Field label="Sur-titre">
                    <input className="a-input" value={s.eyebrow_fr ?? ''} onChange={(e) => upd(s.key, { eyebrow_fr: e.target.value })} />
                  </Field>
                  <Field label="Titre (français)">
                    <input className="a-input" value={s.title_fr ?? ''} onChange={(e) => upd(s.key, { title_fr: e.target.value })} />
                  </Field>
                </div>
                <Field label="Titre (anglais)">
                  <input className="a-input" value={s.title_en ?? ''} onChange={(e) => upd(s.key, { title_en: e.target.value })} />
                </Field>
                {s.key === 'hero' && (
                  <Field label="Texte de la bannière">
                    <textarea className="a-input" rows={3} value={s.body_fr ?? ''} onChange={(e) => upd(s.key, { body_fr: e.target.value })} />
                  </Field>
                )}
                {!['hero', 'categories'].includes(s.key) && (
                  <>
                    <div className="a-seg">
                      <label className={s.mode === 'auto' ? 'is-on' : ''}>
                        <input type="radio" checked={s.mode === 'auto'} onChange={() => upd(s.key, { mode: 'auto' })} /> Automatique
                      </label>
                      <label className={s.mode === 'manual' ? 'is-on' : ''}>
                        <input type="radio" checked={s.mode === 'manual'} onChange={() => upd(s.key, { mode: 'manual' })} /> Produits choisis
                      </label>
                    </div>
                    {s.mode === 'auto' ? (
                      <p className="a-muted a-small">Règle automatique : {AUTO_RULE[s.key] ?? 'produits de la catégorie'}.</p>
                    ) : (
                      <ProductPicker ids={s.product_ids} onChange={(ids) => upd(s.key, { product_ids: ids })} />
                    )}
                    <Field label="Nombre de produits affichés">
                      <input className="a-input" type="number" min={1} max={24} value={s.max_items} onChange={(e) => upd(s.key, { max_items: Number(e.target.value) })} />
                    </Field>
                  </>
                )}
                <label className="a-check">
                  <input type="checkbox" checked={s.visible} onChange={(e) => upd(s.key, { visible: e.target.checked })} /> Afficher cette section
                </label>
                <button className="a-btn a-btn--primary" onClick={() => save(s)}>
                  <Icon name="save" /> {t('Enregistrer')}
                </button>
              </div>
            ) : (
              <p className="a-muted a-small">{s.mode === 'manual' ? `${s.product_ids.length} produit(s) choisi(s)` : AUTO_RULE[s.key] ?? ''}</p>
            )}
          </Card>
        ))}
      </div>
    </>
  );
}
