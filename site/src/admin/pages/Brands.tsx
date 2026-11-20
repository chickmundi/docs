import { useState } from 'react';
import { supabase, slugify, audit } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Empty, useLoad, must, notify } from '../components/ui';
import Icon from '../components/Icon';

export default function Brands() {
  const t = useT();
  const [name, setName] = useState('');
  const { data, error, loading, reload } = useLoad(async () => {
    const [brands, counts] = await Promise.all([supabase.from('brands').select('*').order('name').then(must), supabase.from('products').select('brand_id').not('brand_id', 'is', null).then(must)]);
    const n = new Map<number, number>();
    (counts as any[]).forEach((c) => n.set(c.brand_id, (n.get(c.brand_id) ?? 0) + 1));
    return { brands: brands as any[], n };
  });

  const add = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    const { error } = await supabase.from('brands').insert({ name: name.trim(), slug: slugify(name) });
    if (error) return notify(error.message.includes('duplicate') ? 'Cette marque existe déjà.' : error.message, 'bad');
    await audit('brand.create', 'brand', name);
    setName('');
    reload();
  };
  const rename = async (b: any) => {
    const v = prompt('Nouveau nom :', b.name);
    if (!v?.trim()) return;
    const { error } = await supabase.from('brands').update({ name: v.trim(), slug: slugify(v) }).eq('id', b.id);
    if (error) return notify(error.message, 'bad');
    reload();
  };
  const remove = async (b: any) => {
    if (!confirm(`Supprimer la marque « ${b.name} » ? Les produits liés n’auront plus de marque.`)) return;
    const { error } = await supabase.from('brands').delete().eq('id', b.id);
    if (error) return notify(error.message, 'bad');
    await audit('brand.delete', 'brand', b.id);
    reload();
  };

  return (
    <>
      <PageHead title={t('Marques')} sub="Associez une marque à chaque produit depuis sa fiche (onglet Général)" />
      <Card>
        <form className="a-form a-form--row" onSubmit={add}>
          <input className="a-input" placeholder="Nouvelle marque (ex. Cantu, Sensationnel, Clic)" value={name} onChange={(e) => setName(e.target.value)} />
          <button className="a-btn a-btn--primary">
            <Icon name="plus" /> {t('Ajouter')}
          </button>
        </form>
        {loading ? (
          <Loading />
        ) : error ? (
          <ErrorBox error={error} />
        ) : !data!.brands.length ? (
          <Empty>Aucune marque pour l’instant.</Empty>
        ) : (
          <table className="a-table">
            <tbody>
              {data!.brands.map((b) => (
                <tr key={b.id}>
                  <td>
                    <strong>{b.name}</strong>
                  </td>
                  <td className="a-num">{data!.n.get(b.id) ?? 0} produit(s)</td>
                  <td className="a-num">
                    <button className="a-btn a-btn--ghost" onClick={() => rename(b)}>
                      {t('Modifier')}
                    </button>
                    <button className="a-btn a-btn--ghost" onClick={() => remove(b)}>
                      {t('Supprimer')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </>
  );
}
