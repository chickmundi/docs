import { useEffect, useState } from 'react';
import { supabase, money, toCents, toDollars, slugify, audit, dateOnly } from '../lib/supabase';
import { Card, PageHead, Loading, ErrorBox, Field, Tabs, Badge, notify, must, Empty } from '../components/ui';
import Icon from '../components/Icon';

type P = Record<string, any>;
type Variant = { id?: number; options: Record<string, string>; sku: string; barcode: string; price: string; regular: string; track_stock: boolean; stock_qty: string; low_stock_threshold: string; in_stock: boolean; image_url: string; active: boolean; _delete?: boolean };
type Img = { id?: number; url: string; alt: string; _delete?: boolean };
type Lot = { id?: number; lot_number: string; expiry_date: string; quantity: string; received_at: string; notes: string; _delete?: boolean };

const EMPTY: P = {
  status: 'draft',
  name_fr: '',
  name_en: '',
  short_description_fr: '',
  short_description_en: '',
  description_fr: '',
  description_en: '',
  department_id: null,
  brand_id: null,
  has_variants: false,
  sale_unit: 'unit',
  unit_label: '',
  sku: '',
  barcode: '',
  track_stock: false,
  in_stock: true,
  track_expiry: false,
  cold_chain: null,
  measure_unit: null,
  extra_attributes: {},
  tags: [],
  featured: false,
};

const GROCERY_FIELDS: [string, string, string?][] = [
  ['weight_value', 'Poids / volume (nombre)'],
  ['measure_unit', 'Unité de mesure', 'select'],
  ['net_quantity', 'Quantité nette (ex. 6 × 355 ml)'],
  ['packaging', 'Emballage'],
  ['country_of_origin', 'Pays d’origine'],
  ['cold_chain', 'Conservation', 'cold'],
  ['ingredients', 'Ingrédients', 'textarea'],
  ['allergens', 'Allergènes', 'textarea'],
  ['storage_instructions', 'Instructions de conservation', 'textarea'],
];
const HAIR_FIELDS: [string, string][] = [
  ['hair_type', 'Type de cheveux (naturel, semi-naturel, synthétique)'],
  ['hair_length', 'Longueur'],
  ['hair_color', 'Couleur'],
  ['hair_texture', 'Texture (lisse, bouclé, crépu…)'],
  ['material', 'Matière'],
  ['style', 'Style'],
  ['packaging', 'Emballage'],
  ['product_size', 'Format / taille du produit'],
];

const toVariant = (v: any): Variant => ({
  id: v.id,
  options: v.options ?? {},
  sku: v.sku ?? '',
  barcode: v.barcode ?? '',
  price: toDollars(v.price_cents),
  regular: toDollars(v.regular_price_cents),
  track_stock: v.track_stock,
  stock_qty: v.stock_qty == null ? '' : String(Number(v.stock_qty)),
  low_stock_threshold: v.low_stock_threshold == null ? '' : String(Number(v.low_stock_threshold)),
  in_stock: v.in_stock,
  image_url: v.image_url ?? '',
  active: v.active,
});

export default function ProductEdit({ params }: { params: string[] }) {
  const isNew = params[0] === 'nouveau';
  const id = isNew ? null : Number(params[0]);
  const [p, setP] = useState<P | null>(null);
  const [cats, setCats] = useState<number[]>([]);
  const [options, setOptions] = useState<{ name: string; values: string }[]>([]);
  const [variants, setVariants] = useState<Variant[]>([]);
  const [images, setImages] = useState<Img[]>([]);
  const [lots, setLots] = useState<Lot[]>([]);
  const [meta, setMeta] = useState<{ depts: any[]; cats: any[]; brands: any[] } | null>(null);
  const [tab, setTab] = useState('general');
  const [error, setError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [price, setPrice] = useState({ price: '', regular: '' });

  useEffect(() => {
    (async () => {
      try {
        const [depts, allCats, brands] = await Promise.all([
          supabase.from('departments').select('*').order('sort').then(must),
          supabase.from('categories').select('id, name_fr, parent_id, department_id').order('sort').order('name_fr').then(must),
          supabase.from('brands').select('*').order('name').then(must),
        ]);
        setMeta({ depts, cats: allCats, brands });
        if (isNew) {
          setP({ ...EMPTY });
          return;
        }
        const [prod, pc, opts, vars, imgs, lts] = await Promise.all([
          supabase.from('products').select('*').eq('id', id).single().then(must),
          supabase.from('product_categories').select('category_id').eq('product_id', id).then(must),
          supabase.from('product_options').select('*').eq('product_id', id).order('sort').then(must),
          supabase.from('product_variants').select('*').eq('product_id', id).order('sort').then(must),
          supabase.from('product_images').select('*').eq('product_id', id).order('sort').then(must),
          supabase.from('inventory_lots').select('*').eq('product_id', id).order('expiry_date').then(must),
        ]);
        setP(prod as P);
        setPrice({ price: toDollars((prod as P).price_cents), regular: toDollars((prod as P).regular_price_cents) });
        setCats((pc as any[]).map((c) => c.category_id));
        setOptions((opts as any[]).map((o) => ({ name: o.name, values: (o.values_list ?? []).join(', ') })));
        setVariants((vars as any[]).map(toVariant));
        setImages((imgs as any[]).map((i) => ({ id: i.id, url: i.url, alt: i.alt ?? '' })));
        setLots((lts as any[]).map((l) => ({ id: l.id, lot_number: l.lot_number ?? '', expiry_date: l.expiry_date ?? '', quantity: String(Number(l.quantity)), received_at: l.received_at ?? '', notes: l.notes ?? '' })));
      } catch (e) {
        setError(e);
      }
    })();
  }, [params[0]]);

  if (error) return <ErrorBox error={error} />;
  if (!p || !meta) return <Loading />;

  const set = (k: string, v: unknown) => setP((x) => ({ ...x!, [k]: v }));
  const setExtra = (k: string, v: string) => setP((x) => ({ ...x!, extra_attributes: { ...(x!.extra_attributes ?? {}), [k]: v } }));
  const deptSlug = meta.depts.find((d) => d.id === p.department_id)?.slug;
  const isGrocery = deptSlug === 'epicerie';
  const isHair = deptSlug === 'beaute-cheveux';

  const generateVariants = () => {
    const opts = options.map((o) => ({ name: o.name.trim(), values: o.values.split(',').map((v) => v.trim()).filter(Boolean) })).filter((o) => o.name && o.values.length);
    if (!opts.length) return notify('Ajoutez d’abord une option avec des valeurs.', 'bad');
    let combos: Record<string, string>[] = [{}];
    for (const o of opts) combos = combos.flatMap((c) => o.values.map((v) => ({ ...c, [o.name]: v })));
    const key = (o: Record<string, string>) => JSON.stringify(Object.keys(o).sort().map((k) => [k, o[k]]));
    const existing = new Set(variants.filter((v) => !v._delete).map((v) => key(v.options)));
    const fresh = combos
      .filter((c) => !existing.has(key(c)))
      .map((c) => ({ options: c, sku: '', barcode: '', price: price.price, regular: price.regular, track_stock: false, stock_qty: '', low_stock_threshold: '', in_stock: true, image_url: '', active: true }));
    setVariants((vs) => [...vs, ...fresh]);
    notify(`${fresh.length} variation(s) ajoutée(s).`);
  };

  const upload = async (files: FileList | null) => {
    if (!files?.length) return;
    setUploading(true);
    try {
      for (const f of Array.from(files)) {
        const path = `products/${id ?? 'new'}/${Date.now()}-${slugify(f.name.replace(/\.[^.]+$/, ''))}.${f.name.split('.').pop()?.toLowerCase()}`;
        const { error } = await supabase.storage.from('catalog').upload(path, f, { cacheControl: '31536000', upsert: false });
        if (error) throw error;
        const { data } = supabase.storage.from('catalog').getPublicUrl(path);
        setImages((im) => [...im, { url: data.publicUrl, alt: p.name_fr }]);
      }
      notify('Image(s) ajoutée(s). Enregistrez pour confirmer.');
    } catch (e) {
      notify(e instanceof Error ? e.message : String((e as any)?.message ?? e), 'bad');
    } finally {
      setUploading(false);
    }
  };

  const save = async () => {
    if (!p.name_fr?.trim()) return (setTab('general'), notify('Le nom en français est requis.', 'bad'));
    if (!p.department_id) return (setTab('general'), notify('Choisissez un département.', 'bad'));
    if (!p.has_variants && toCents(price.price) == null) return (setTab('prix'), notify('Indiquez un prix.', 'bad'));
    setSaving(true);
    try {
      const priceCents = toCents(price.price);
      const regularCents = toCents(price.regular);
      const row: P = {
        ...p,
        name_fr: p.name_fr.trim(),
        slug: p.slug?.trim() || slugify(p.name_fr),
        price_cents: p.has_variants ? p.price_cents ?? null : priceCents,
        regular_price_cents: p.has_variants ? p.regular_price_cents ?? null : regularCents ?? priceCents,
        sku: p.sku?.trim() || null,
        barcode: p.barcode?.trim() || null,
        stock_qty: p.stock_qty === '' ? null : p.stock_qty,
        low_stock_threshold: p.low_stock_threshold === '' ? null : p.low_stock_threshold,
        weight_value: p.weight_value === '' ? null : p.weight_value,
        sale_starts_at: p.sale_starts_at || null,
        sale_ends_at: p.sale_ends_at || null,
        tags: Array.isArray(p.tags) ? p.tags : String(p.tags ?? '').split(',').map((s) => s.trim()).filter(Boolean),
      };
      delete row.created_at;
      delete row.updated_at;
      if (isNew) delete row.id;
      const saved = must(await supabase.from('products').upsert(row).select('id').single()) as { id: number };
      const pid = saved.id;

      // Categories
      must(await supabase.from('product_categories').delete().eq('product_id', pid));
      if (cats.length) must(await supabase.from('product_categories').insert(cats.map((c) => ({ product_id: pid, category_id: c }))));

      // Options and variations
      must(await supabase.from('product_options').delete().eq('product_id', pid));
      const opts = options.filter((o) => o.name.trim());
      if (p.has_variants && opts.length) {
        must(await supabase.from('product_options').insert(opts.map((o, i) => ({ product_id: pid, name: o.name.trim(), values_list: o.values.split(',').map((v) => v.trim()).filter(Boolean), sort: i }))));
      }
      for (const [i, v] of variants.entries()) {
        if (v._delete) {
          if (v.id) must(await supabase.from('product_variants').delete().eq('id', v.id));
          continue;
        }
        const vr = {
          ...(v.id ? { id: v.id } : {}),
          product_id: pid,
          options: v.options,
          sku: v.sku.trim() || null,
          barcode: v.barcode.trim() || null,
          price_cents: toCents(v.price),
          regular_price_cents: toCents(v.regular) ?? toCents(v.price),
          track_stock: v.track_stock,
          stock_qty: v.stock_qty === '' ? null : Number(v.stock_qty),
          low_stock_threshold: v.low_stock_threshold === '' ? null : Number(v.low_stock_threshold),
          in_stock: v.in_stock,
          image_url: v.image_url || null,
          active: v.active,
          sort: i,
        };
        must(await supabase.from('product_variants').upsert(vr));
      }

      // Images
      for (const [i, im] of images.entries()) {
        if (im._delete) {
          if (im.id) must(await supabase.from('product_images').delete().eq('id', im.id));
          continue;
        }
        must(await supabase.from('product_images').upsert({ ...(im.id ? { id: im.id } : {}), product_id: pid, url: im.url, alt: im.alt || null, sort: i }));
      }

      // Lots
      for (const l of lots) {
        if (l._delete) {
          if (l.id) must(await supabase.from('inventory_lots').delete().eq('id', l.id));
          continue;
        }
        must(
          await supabase.from('inventory_lots').upsert({
            ...(l.id ? { id: l.id } : {}),
            product_id: pid,
            lot_number: l.lot_number || null,
            expiry_date: l.expiry_date || null,
            quantity: Number(l.quantity.replace(',', '.')) || 0,
            received_at: l.received_at || null,
            notes: l.notes || null,
          }),
        );
      }
      await audit(isNew ? 'product.create' : 'product.update', 'product', pid);
      notify('Produit enregistré.');
      if (isNew) location.hash = `#/produits/${pid}`;
      else location.reload();
    } catch (e) {
      const msg = (e as any)?.message ?? String(e);
      notify(/products_sku_key|variants_sku_key/.test(msg) ? 'Ce SKU est déjà utilisé par un autre produit.' : /slug/.test(msg) ? 'Cette adresse (slug) est déjà utilisée.' : msg, 'bad');
    } finally {
      setSaving(false);
    }
  };

  const archive = async () => {
    if (!confirm('Archiver ce produit ? Il disparaîtra de la boutique, mais restera dans les commandes et rapports.')) return;
    const { error } = await supabase.from('products').update({ status: 'archived' }).eq('id', id);
    if (error) return notify(error.message, 'bad');
    await audit('product.archive', 'product', id);
    notify('Produit archivé.');
    set('status', 'archived');
  };

  const catLabel = (c: any) => {
    const parent = meta.cats.find((x) => x.id === c.parent_id);
    return parent ? `${parent.name_fr} › ${c.name_fr}` : c.name_fr;
  };

  return (
    <>
      <PageHead
        title={isNew ? 'Nouveau produit' : p.name_fr}
        sub={
          !isNew && (
            <>
              n° {p.id} ·{' '}
              <a href={`/produit/${p.slug}/`} target="_blank" rel="noopener">
                voir en boutique ↗
              </a>
              {p.extra_attributes?.legacy_sku && (
                <>
                  {' '}
                  · <Badge tone="warn">SKU à compléter (ancien code partagé : {p.extra_attributes.legacy_sku})</Badge>
                </>
              )}
            </>
          )
        }
        actions={
          <>
            <a className="a-btn" href="#/produits">
              <Icon name="arrow-left" /> Produits
            </a>
            {!isNew && p.status !== 'archived' && (
              <button className="a-btn a-btn--danger" onClick={archive}>
                Archiver
              </button>
            )}
            <button className="a-btn a-btn--primary" onClick={save} disabled={saving}>
              <Icon name="save" /> {saving ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </>
        }
      />
      <Tabs
        active={tab}
        onChange={setTab}
        tabs={[
          { key: 'general', label: 'Général' },
          { key: 'prix', label: 'Prix et vente' },
          { key: 'inventaire', label: 'Inventaire' },
          { key: 'variations', label: `Variations${variants.filter((v) => !v._delete).length ? ` (${variants.filter((v) => !v._delete).length})` : ''}` },
          { key: 'attributs', label: isHair ? 'Attributs cheveux' : isGrocery ? 'Attributs épicerie' : 'Attributs' },
          { key: 'images', label: `Images (${images.filter((i) => !i._delete).length})` },
          { key: 'seo', label: 'SEO' },
        ]}
      />

      {tab === 'general' && (
        <div className="a-grid2 a-grid2--wide">
          <Card>
            <div className="a-form">
              <Field label="Nom (français)">
                <input className="a-input" value={p.name_fr} onChange={(e) => set('name_fr', e.target.value)} />
              </Field>
              <Field label="Nom (anglais)" hint="Facultatif">
                <input className="a-input" value={p.name_en ?? ''} onChange={(e) => set('name_en', e.target.value)} />
              </Field>
              <Field label="Résumé (français)" hint="Texte court affiché près du prix">
                <textarea className="a-input" rows={3} value={p.short_description_fr ?? ''} onChange={(e) => set('short_description_fr', e.target.value)} />
              </Field>
              <Field label="Description (français)" hint="Le HTML simple est accepté : <p>, <ul>, <li>, <strong>">
                <textarea className="a-input" rows={8} value={p.description_fr ?? ''} onChange={(e) => set('description_fr', e.target.value)} />
              </Field>
              <Field label="Résumé (anglais)">
                <textarea className="a-input" rows={2} value={p.short_description_en ?? ''} onChange={(e) => set('short_description_en', e.target.value)} />
              </Field>
              <Field label="Description (anglais)">
                <textarea className="a-input" rows={5} value={p.description_en ?? ''} onChange={(e) => set('description_en', e.target.value)} />
              </Field>
            </div>
          </Card>
          <div className="a-stack">
            <Card title="Publication">
              <div className="a-form">
                <Field label="Statut">
                  <select className="a-input" value={p.status} onChange={(e) => set('status', e.target.value)}>
                    <option value="published">En ligne</option>
                    <option value="draft">Brouillon (invisible)</option>
                    <option value="archived">Archivé</option>
                  </select>
                </Field>
                <label className="a-check">
                  <input type="checkbox" checked={!!p.featured} onChange={(e) => set('featured', e.target.checked)} /> Produit vedette
                </label>
              </div>
            </Card>
            <Card title="Classement">
              <div className="a-form">
                <Field label="Département">
                  <select className="a-input" value={p.department_id ?? ''} onChange={(e) => set('department_id', e.target.value ? Number(e.target.value) : null)}>
                    <option value="">Choisir…</option>
                    {meta.depts.map((d) => (
                      <option key={d.id} value={d.id}>
                        {d.name_fr}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Marque">
                  <select className="a-input" value={p.brand_id ?? ''} onChange={(e) => set('brand_id', e.target.value ? Number(e.target.value) : null)}>
                    <option value="">Aucune</option>
                    {meta.brands.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <fieldset className="a-checklist">
                  <legend>Catégories</legend>
                  {meta.cats
                    .filter((c) => !p.department_id || !c.department_id || c.department_id === p.department_id || cats.includes(c.id))
                    .map((c) => (
                      <label key={c.id} className={`a-check ${c.parent_id ? 'a-check--child' : ''}`}>
                        <input type="checkbox" checked={cats.includes(c.id)} onChange={(e) => setCats((cs) => (e.target.checked ? [...cs, c.id] : cs.filter((x) => x !== c.id)))} />
                        {catLabel(c)}
                      </label>
                    ))}
                </fieldset>
                <Field label="Mots-clés" hint="Séparés par des virgules, aident la recherche">
                  <input className="a-input" value={Array.isArray(p.tags) ? p.tags.join(', ') : p.tags} onChange={(e) => set('tags', e.target.value)} />
                </Field>
              </div>
            </Card>
          </div>
        </div>
      )}

      {tab === 'prix' && (
        <Card>
          <div className="a-form a-form--grid">
            {p.has_variants ? (
              <p className="a-muted a-field--wide">Ce produit a des variations : le prix se règle pour chaque variation (onglet Variations). Prix affiché : dès {money(p.price_cents)}.</p>
            ) : (
              <>
                <Field label="Prix de vente ($)" hint="Prix payé par le client">
                  <input className="a-input" inputMode="decimal" value={price.price} onChange={(e) => setPrice({ ...price, price: e.target.value })} />
                </Field>
                <Field label="Prix régulier ($)" hint="Plus élevé que le prix de vente = produit en solde">
                  <input className="a-input" inputMode="decimal" value={price.regular} onChange={(e) => setPrice({ ...price, regular: e.target.value })} />
                </Field>
              </>
            )}
            <Field label="Début du solde" hint="Facultatif">
              <input className="a-input" type="datetime-local" value={p.sale_starts_at?.slice(0, 16) ?? ''} onChange={(e) => set('sale_starts_at', e.target.value ? new Date(e.target.value).toISOString() : null)} />
            </Field>
            <Field label="Fin du solde" hint="Facultatif">
              <input className="a-input" type="datetime-local" value={p.sale_ends_at?.slice(0, 16) ?? ''} onChange={(e) => set('sale_ends_at', e.target.value ? new Date(e.target.value).toISOString() : null)} />
            </Field>
            <Field label="Vendu à">
              <select className="a-input" value={p.sale_unit} onChange={(e) => set('sale_unit', e.target.value)}>
                <option value="unit">L’unité</option>
                <option value="package">Au paquet</option>
                <option value="weight">Au poids</option>
                <option value="other">Autre</option>
              </select>
            </Field>
            <Field label="Libellé de l’unité" hint="Affiché après le prix, ex. « par kg », « le paquet de 6 »">
              <input className="a-input" value={p.unit_label ?? ''} onChange={(e) => set('unit_label', e.target.value)} />
            </Field>
            {p.sale_unit === 'weight' && <p className="a-muted a-field--wide">Au poids : le client commande une quantité en kg (ex. 1,5) et le montant final est ajusté à la préparation.</p>}
          </div>
        </Card>
      )}

      {tab === 'inventaire' && (
        <div className="a-stack">
          <Card title="Stock">
            <div className="a-form a-form--grid">
              <Field label="SKU">
                <input className="a-input" value={p.sku ?? ''} onChange={(e) => set('sku', e.target.value)} />
              </Field>
              <Field label="Code-barres (UPC/EAN)">
                <input className="a-input" value={p.barcode ?? ''} onChange={(e) => set('barcode', e.target.value)} />
              </Field>
              {p.has_variants ? (
                <p className="a-muted a-field--wide">Le stock se gère pour chaque variation.</p>
              ) : (
                <>
                  <label className="a-check a-field--wide">
                    <input type="checkbox" checked={!!p.track_stock} onChange={(e) => set('track_stock', e.target.checked)} /> Suivre la quantité en stock (diminue à chaque commande)
                  </label>
                  {p.track_stock ? (
                    <>
                      <Field label="Quantité en stock">
                        <input className="a-input" inputMode="decimal" value={p.stock_qty ?? ''} onChange={(e) => set('stock_qty', e.target.value)} />
                      </Field>
                      <Field label="Alerte de stock faible à">
                        <input className="a-input" inputMode="decimal" value={p.low_stock_threshold ?? ''} placeholder="5" onChange={(e) => set('low_stock_threshold', e.target.value)} />
                      </Field>
                    </>
                  ) : (
                    <label className="a-check a-field--wide">
                      <input type="checkbox" checked={!!p.in_stock} onChange={(e) => set('in_stock', e.target.checked)} /> En stock (disponible à la vente)
                    </label>
                  )}
                </>
              )}
              <label className="a-check a-field--wide">
                <input type="checkbox" checked={!!p.track_expiry} onChange={(e) => set('track_expiry', e.target.checked)} /> Produit périssable : suivre les lots et dates de péremption
              </label>
            </div>
          </Card>
          {p.track_expiry && (
            <Card
              title="Lots et dates de péremption"
              actions={
                <button className="a-btn" onClick={() => setLots((l) => [...l, { lot_number: '', expiry_date: '', quantity: '', received_at: new Date().toISOString().slice(0, 10), notes: '' }])}>
                  <Icon name="plus" /> Ajouter un lot
                </button>
              }
            >
              {lots.filter((l) => !l._delete).length ? (
                <table className="a-table a-table--edit">
                  <thead>
                    <tr>
                      <th>N° de lot</th>
                      <th>Péremption</th>
                      <th>Quantité</th>
                      <th>Reçu le</th>
                      <th>Note</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {lots.map((l, i) =>
                      l._delete ? null : (
                        <tr key={l.id ?? `n${i}`}>
                          {(['lot_number', 'expiry_date', 'quantity', 'received_at', 'notes'] as const).map((k) => (
                            <td key={k}>
                              <input
                                className="a-input a-input--sm"
                                type={k === 'expiry_date' || k === 'received_at' ? 'date' : 'text'}
                                value={l[k]}
                                onChange={(e) => setLots((ls) => ls.map((x, j) => (j === i ? { ...x, [k]: e.target.value } : x)))}
                              />
                            </td>
                          ))}
                          <td>
                            <button className="a-btn a-btn--ghost a-btn--icon" onClick={() => setLots((ls) => ls.map((x, j) => (j === i ? { ...x, _delete: true } : x)))} aria-label="Retirer">
                              <Icon name="trash-2" size={16} />
                            </button>
                          </td>
                        </tr>
                      ),
                    )}
                  </tbody>
                </table>
              ) : (
                <Empty>Aucun lot. Ajoutez les lots reçus avec leur date de péremption.</Empty>
              )}
              {lots.some((l) => l.expiry_date && !l._delete) && (
                <p className="a-muted a-small">
                  Prochaine péremption :{' '}
                  {dateOnly(
                    lots
                      .filter((l) => l.expiry_date && !l._delete)
                      .map((l) => l.expiry_date)
                      .sort()[0],
                  )}
                </p>
              )}
            </Card>
          )}
        </div>
      )}

      {tab === 'variations' && (
        <div className="a-stack">
          <Card title="Options">
            <label className="a-check">
              <input type="checkbox" checked={!!p.has_variants} onChange={(e) => set('has_variants', e.target.checked)} /> Ce produit existe en plusieurs variations (couleur, longueur, style, format…)
            </label>
            {p.has_variants && (
              <>
                <table className="a-table a-table--edit">
                  <thead>
                    <tr>
                      <th>Option</th>
                      <th>Valeurs (séparées par des virgules)</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {options.map((o, i) => (
                      <tr key={i}>
                        <td>
                          <input className="a-input a-input--sm" value={o.name} placeholder="Couleur" onChange={(e) => setOptions((os) => os.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
                        </td>
                        <td>
                          <input className="a-input a-input--sm" value={o.values} placeholder="1B, 2, 4, 27" onChange={(e) => setOptions((os) => os.map((x, j) => (j === i ? { ...x, values: e.target.value } : x)))} />
                        </td>
                        <td>
                          <button className="a-btn a-btn--ghost a-btn--icon" onClick={() => setOptions((os) => os.filter((_, j) => j !== i))} aria-label="Retirer">
                            <Icon name="trash-2" size={16} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <div className="a-actions">
                  <button className="a-btn" onClick={() => setOptions((os) => [...os, { name: '', values: '' }])}>
                    <Icon name="plus" /> Ajouter une option
                  </button>
                  <button className="a-btn a-btn--primary" onClick={generateVariants}>
                    Créer les combinaisons manquantes
                  </button>
                </div>
              </>
            )}
          </Card>
          {p.has_variants && (
            <Card title="Variations">
              {variants.filter((v) => !v._delete).length ? (
                <div className="a-tablewrap">
                  <table className="a-table a-table--edit">
                    <thead>
                      <tr>
                        <th>Variation</th>
                        <th>SKU</th>
                        <th>Prix ($)</th>
                        <th>Prix régulier</th>
                        <th>Stock</th>
                        <th>Image (adresse)</th>
                        <th>Active</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {variants.map((v, i) => {
                        if (v._delete) return null;
                        const upd = (patch: Partial<Variant>) => setVariants((vs) => vs.map((x, j) => (j === i ? { ...x, ...patch } : x)));
                        return (
                          <tr key={v.id ?? `n${i}`}>
                            <td>
                              {Object.entries(v.options).map(([k, val]) => (
                                <div key={k} className="a-small">
                                  <span className="a-muted">{k} :</span> {val || <em className="a-muted">toutes</em>}
                                </div>
                              ))}
                            </td>
                            <td>
                              <input className="a-input a-input--sm" value={v.sku} onChange={(e) => upd({ sku: e.target.value })} />
                            </td>
                            <td>
                              <input className="a-input a-input--sm" inputMode="decimal" value={v.price} onChange={(e) => upd({ price: e.target.value })} />
                            </td>
                            <td>
                              <input className="a-input a-input--sm" inputMode="decimal" value={v.regular} onChange={(e) => upd({ regular: e.target.value })} />
                            </td>
                            <td>
                              <label className="a-check a-small">
                                <input type="checkbox" checked={v.track_stock} onChange={(e) => upd({ track_stock: e.target.checked })} /> suivre
                              </label>
                              {v.track_stock ? (
                                <input className="a-input a-input--sm" inputMode="decimal" value={v.stock_qty} onChange={(e) => upd({ stock_qty: e.target.value })} aria-label="Quantité" />
                              ) : (
                                <label className="a-check a-small">
                                  <input type="checkbox" checked={v.in_stock} onChange={(e) => upd({ in_stock: e.target.checked })} /> en stock
                                </label>
                              )}
                            </td>
                            <td>
                              <select className="a-input a-input--sm" value={v.image_url} onChange={(e) => upd({ image_url: e.target.value })}>
                                <option value="">Image du produit</option>
                                {images
                                  .filter((im) => !im._delete)
                                  .map((im, k) => (
                                    <option key={im.url} value={im.url}>
                                      Image {k + 1}
                                    </option>
                                  ))}
                              </select>
                            </td>
                            <td>
                              <input type="checkbox" checked={v.active} onChange={(e) => upd({ active: e.target.checked })} aria-label="Active" />
                            </td>
                            <td>
                              <button className="a-btn a-btn--ghost a-btn--icon" onClick={() => upd({ _delete: true })} aria-label="Supprimer la variation">
                                <Icon name="trash-2" size={16} />
                              </button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              ) : (
                <Empty>Aucune variation. Définissez les options puis « Créer les combinaisons manquantes ».</Empty>
              )}
            </Card>
          )}
        </div>
      )}

      {tab === 'attributs' && (
        <Card>
          {!isGrocery && !isHair && <p className="a-muted">Choisissez un département (onglet Général) pour voir les attributs adaptés. Tous les champs restent disponibles ci-dessous.</p>}
          {(isGrocery || (!isGrocery && !isHair)) && (
            <>
              <h3>Épicerie</h3>
              <div className="a-form a-form--grid">
                {GROCERY_FIELDS.map(([k, l, kind]) => (
                  <Field key={k} label={l} wide={kind === 'textarea'}>
                    {kind === 'select' ? (
                      <select className="a-input" value={p[k] ?? ''} onChange={(e) => set(k, e.target.value || null)}>
                        <option value="">—</option>
                        {['g', 'kg', 'ml', 'L', 'units'].map((u) => (
                          <option key={u} value={u}>
                            {u === 'units' ? 'unités' : u}
                          </option>
                        ))}
                      </select>
                    ) : kind === 'cold' ? (
                      <select className="a-input" value={p[k] ?? ''} onChange={(e) => set(k, e.target.value || null)}>
                        <option value="">—</option>
                        <option value="ambient">Température ambiante</option>
                        <option value="refrigerated">Réfrigéré</option>
                        <option value="frozen">Surgelé</option>
                      </select>
                    ) : kind === 'textarea' ? (
                      <textarea className="a-input" rows={2} value={p[k] ?? ''} onChange={(e) => set(k, e.target.value)} />
                    ) : (
                      <input className="a-input" value={p[k] ?? ''} onChange={(e) => set(k, e.target.value)} />
                    )}
                  </Field>
                ))}
              </div>
            </>
          )}
          {(isHair || (!isGrocery && !isHair)) && (
            <>
              <h3>Beauté et cheveux</h3>
              <div className="a-form a-form--grid">
                {HAIR_FIELDS.map(([k, l]) => (
                  <Field key={k} label={l}>
                    <input className="a-input" value={p[k] ?? ''} onChange={(e) => set(k, e.target.value)} />
                  </Field>
                ))}
              </div>
              <p className="a-muted a-small">Pour une perruque vendue en plusieurs couleurs ou longueurs, utilisez l’onglet Variations : chaque variation a son SKU, son prix, son stock et son image.</p>
            </>
          )}
          {deptSlug === 'soins-corporels' && (
            <div className="a-form a-form--grid">
              <Field label="Format / taille du produit">
                <input className="a-input" value={p.product_size ?? ''} onChange={(e) => set('product_size', e.target.value)} />
              </Field>
              <Field label="Ingrédients" wide>
                <textarea className="a-input" rows={2} value={p.ingredients ?? ''} onChange={(e) => set('ingredients', e.target.value)} />
              </Field>
            </div>
          )}
          <h3>Autres attributs</h3>
          <table className="a-table a-table--edit">
            <tbody>
              {Object.entries(p.extra_attributes ?? {}).map(([k, v]) => (
                <tr key={k}>
                  <td>{k === 'legacy_sku' ? 'Ancien SKU (WooCommerce)' : k === 'external_url' ? 'Lien externe' : k}</td>
                  <td>
                    <input className="a-input a-input--sm" value={String(v)} onChange={(e) => setExtra(k, e.target.value)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <button
            className="a-btn"
            onClick={() => {
              const k = prompt('Nom de l’attribut :');
              if (k) setExtra(k, '');
            }}
          >
            <Icon name="plus" /> Ajouter un attribut
          </button>
        </Card>
      )}

      {tab === 'images' && (
        <Card
          title="Images"
          actions={
            <label className="a-btn a-btn--primary">
              <Icon name="upload" /> {uploading ? 'Envoi…' : 'Téléverser'}
              <input type="file" accept="image/*" multiple hidden onChange={(e) => upload(e.target.files)} />
            </label>
          }
        >
          {images.filter((i) => !i._delete).length ? (
            <ul className="a-images">
              {images.map((im, i) =>
                im._delete ? null : (
                  <li key={im.id ?? im.url}>
                    <img src={im.url} alt="" width="140" height="140" />
                    {i === images.findIndex((x) => !x._delete) && <Badge tone="brand">principale</Badge>}
                    <input className="a-input a-input--sm" placeholder="Texte alternatif" value={im.alt} onChange={(e) => setImages((is) => is.map((x, j) => (j === i ? { ...x, alt: e.target.value } : x)))} />
                    <div className="a-actions">
                      <button className="a-btn a-btn--ghost a-btn--icon" disabled={i === 0} onClick={() => setImages((is) => { const c = [...is]; [c[i - 1], c[i]] = [c[i], c[i - 1]]; return c; })} aria-label="Avancer">
                        <Icon name="arrow-left" size={16} />
                      </button>
                      <button className="a-btn a-btn--ghost a-btn--icon" disabled={i === images.length - 1} onClick={() => setImages((is) => { const c = [...is]; [c[i + 1], c[i]] = [c[i], c[i + 1]]; return c; })} aria-label="Reculer">
                        <Icon name="arrow-right" size={16} />
                      </button>
                      <button className="a-btn a-btn--ghost a-btn--icon" onClick={() => setImages((is) => is.map((x, j) => (j === i ? { ...x, _delete: true } : x)))} aria-label="Retirer">
                        <Icon name="trash-2" size={16} />
                      </button>
                    </div>
                  </li>
                ),
              )}
            </ul>
          ) : (
            <Empty>Aucune image.</Empty>
          )}
        </Card>
      )}

      {tab === 'seo' && (
        <Card>
          <div className="a-form">
            <Field label="Adresse de la page (slug)" hint={`/produit/${p.slug || slugify(p.name_fr || '')}/ · changer l’adresse d’un produit existant casse les liens déjà partagés`}>
              <input className="a-input" value={p.slug ?? ''} onChange={(e) => set('slug', slugify(e.target.value))} />
            </Field>
            {p.legacy_slug && <p className="a-muted a-small">Ancienne adresse WooCommerce : /fr/product/{p.legacy_slug}/ (redirigée automatiquement)</p>}
            <Field label="Titre SEO" hint="60 caractères environ">
              <input className="a-input" value={p.seo_title ?? ''} onChange={(e) => set('seo_title', e.target.value)} />
            </Field>
            <Field label="Description SEO" hint="160 caractères environ">
              <textarea className="a-input" rows={3} value={p.seo_description ?? ''} onChange={(e) => set('seo_description', e.target.value)} />
            </Field>
          </div>
        </Card>
      )}
    </>
  );
}
