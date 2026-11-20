import { useState } from 'react';
import { supabase, slugify, audit } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Field, Modal, Badge, useLoad, must, notify } from '../components/ui';
import Icon from '../components/Icon';

type Cat = { id?: number; slug: string; name_fr: string; name_en: string | null; description_fr: string | null; parent_id: number | null; department_id: number | null; icon: string | null; sort: number; visible: boolean; image_url: string | null };

const ICONS = ['shopping-basket', 'coffee', 'flame', 'snowflake', 'beef', 'fish', 'carrot', 'wheat', 'soup', 'package', 'sparkles', 'scissors', 'droplet', 'flower-2', 'brush', 'spray-can', 'leaf', 'tag', 'gift', 'baby', 'milk', 'cookie', 'apple', 'egg', 'wine', 'ellipsis'];

export default function Categories() {
  const t = useT();
  const { data, error, loading, reload } = useLoad(async () => {
    const [cats, depts, counts] = await Promise.all([
      supabase.from('categories').select('*').order('sort').order('name_fr').then(must),
      supabase.from('departments').select('*').order('sort').then(must),
      supabase.from('product_categories').select('category_id').then(must),
    ]);
    const n = new Map<number, number>();
    (counts as any[]).forEach((c) => n.set(c.category_id, (n.get(c.category_id) ?? 0) + 1));
    return { cats: cats as Cat[], depts, n };
  });
  const [edit, setEdit] = useState<Cat | null>(null);

  if (loading) return <Loading />;
  if (error) return <ErrorBox error={error} />;
  const { cats, depts, n } = data!;
  const tops = cats.filter((c) => !c.parent_id);
  const kids = (id?: number) => cats.filter((c) => c.parent_id === id);

  const save = async () => {
    if (!edit) return;
    if (!edit.name_fr.trim()) return notify('Nom requis.', 'bad');
    const row = { ...edit, slug: edit.slug || slugify(edit.name_fr), name_fr: edit.name_fr.trim() };
    // A sub-category belongs to its parent's department.
    if (row.parent_id) row.department_id = cats.find((c) => c.id === row.parent_id)?.department_id ?? row.department_id;
    delete (row as any).created_at;
    delete (row as any).updated_at;
    const { error } = await supabase.from('categories').upsert(row);
    if (error) return notify(error.message.includes('slug') ? 'Cette adresse (slug) existe déjà.' : error.message, 'bad');
    await audit(edit.id ? 'category.update' : 'category.create', 'category', edit.id ?? row.slug);
    notify('Catégorie enregistrée.');
    setEdit(null);
    reload();
  };

  const row = (c: Cat, child = false) => (
    <tr key={c.id}>
      <td>
        <span className={child ? 'a-indent' : ''}>
          <Icon name={c.icon || 'tag'} size={16} /> <strong>{c.name_fr}</strong>
        </span>
        <div className="a-muted a-small">/categorie/{c.slug}/</div>
      </td>
      <td>{depts.find((d: any) => d.id === c.department_id)?.name_fr ?? '—'}</td>
      <td className="a-num">{n.get(c.id!) ?? 0}</td>
      <td>{c.visible ? <Badge tone="ok">visible</Badge> : <Badge>masquée</Badge>}</td>
      <td className="a-num">
        <button className="a-btn a-btn--ghost" onClick={() => setEdit(c)}>
          {t('Modifier')}
        </button>
      </td>
    </tr>
  );

  return (
    <>
      <PageHead
        title={t('Catégories')}
        sub="Les sous-catégories suivent le département de leur catégorie parente"
        actions={
          <button className="a-btn a-btn--primary" onClick={() => setEdit({ slug: '', name_fr: '', name_en: null, description_fr: null, parent_id: null, department_id: 1, icon: 'tag', sort: 50, visible: true, image_url: null })}>
            <Icon name="plus" /> Nouvelle catégorie
          </button>
        }
      />
      {depts.map((d: any) => (
        <Card key={d.id} title={d.name_fr}>
          <table className="a-table">
            <thead>
              <tr>
                <th>Catégorie</th>
                <th>Département</th>
                <th className="a-num">Produits</th>
                <th>Boutique</th>
                <th />
              </tr>
            </thead>
            <tbody>{tops.filter((c) => c.department_id === d.id).flatMap((c) => [row(c), ...kids(c.id).map((k) => row(k, true))])}</tbody>
          </table>
        </Card>
      ))}
      {tops.some((c) => !c.department_id) && (
        <Card title="Sans département">
          <table className="a-table">
            <tbody>{tops.filter((c) => !c.department_id).flatMap((c) => [row(c), ...kids(c.id).map((k) => row(k, true))])}</tbody>
          </table>
        </Card>
      )}

      {edit && (
        <Modal
          title={edit.id ? `Modifier : ${edit.name_fr}` : 'Nouvelle catégorie'}
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
            <Field label="Nom (français)">
              <input className="a-input" value={edit.name_fr} onChange={(e) => setEdit({ ...edit, name_fr: e.target.value, slug: edit.id ? edit.slug : slugify(e.target.value) })} />
            </Field>
            <Field label="Nom (anglais)">
              <input className="a-input" value={edit.name_en ?? ''} onChange={(e) => setEdit({ ...edit, name_en: e.target.value })} />
            </Field>
            <Field label="Catégorie parente" hint="Vide = catégorie principale">
              <select className="a-input" value={edit.parent_id ?? ''} onChange={(e) => setEdit({ ...edit, parent_id: e.target.value ? Number(e.target.value) : null })}>
                <option value="">Aucune (catégorie principale)</option>
                {tops
                  .filter((c) => c.id !== edit.id)
                  .map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name_fr}
                    </option>
                  ))}
              </select>
            </Field>
            {!edit.parent_id && (
              <Field label="Département">
                <select className="a-input" value={edit.department_id ?? ''} onChange={(e) => setEdit({ ...edit, department_id: e.target.value ? Number(e.target.value) : null })}>
                  {depts.map((d: any) => (
                    <option key={d.id} value={d.id}>
                      {d.name_fr}
                    </option>
                  ))}
                </select>
              </Field>
            )}
            <Field label="Adresse (slug)" hint={edit.id ? 'Modifier l’adresse casse les liens déjà partagés' : undefined}>
              <input className="a-input" value={edit.slug} onChange={(e) => setEdit({ ...edit, slug: slugify(e.target.value) })} />
            </Field>
            <Field label="Description">
              <textarea className="a-input" rows={3} value={edit.description_fr ?? ''} onChange={(e) => setEdit({ ...edit, description_fr: e.target.value })} />
            </Field>
            <Field label="Icône">
              <div className="a-iconpick">
                {ICONS.map((i) => (
                  <button type="button" key={i} aria-pressed={edit.icon === i} onClick={() => setEdit({ ...edit, icon: i })} title={i}>
                    <Icon name={i} size={18} />
                  </button>
                ))}
              </div>
            </Field>
            <div className="a-form--row">
              <Field label="Ordre d’affichage">
                <input className="a-input" type="number" value={edit.sort} onChange={(e) => setEdit({ ...edit, sort: Number(e.target.value) })} />
              </Field>
              <label className="a-check">
                <input type="checkbox" checked={edit.visible} onChange={(e) => setEdit({ ...edit, visible: e.target.checked })} /> Visible en boutique
              </label>
            </div>
          </div>
        </Modal>
      )}
    </>
  );
}
