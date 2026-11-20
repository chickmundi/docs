import { money, toCents, toDollars } from '../lib/supabase';
import { useSetting } from '../lib/settings';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Field } from '../components/ui';
import Icon from '../components/Icon';

export const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
type Tier = { from_km: number; to_km: number; fee_cents: number };

export default function Delivery() {
  const t = useT();
  const { value: d, setValue, error, saving, save } = useSetting<Record<string, any>>('delivery');
  if (error) return <ErrorBox error={error} />;
  if (!d) return <Loading />;
  const set = (k: string, v: unknown) => setValue({ ...d, [k]: v });
  const tiers: Tier[] = d.tiers ?? [];
  const setTier = (i: number, patch: Partial<Tier>) => set('tiers', tiers.map((x, j) => (j === i ? { ...x, ...patch } : x)));

  const problems: string[] = [];
  tiers.forEach((tr, i) => {
    if (tr.to_km <= tr.from_km) problems.push(`Palier ${i + 1} : la distance de fin doit dépasser celle de début.`);
    if (i > 0 && tr.from_km !== tiers[i - 1].to_km) problems.push(`Palier ${i + 1} : devrait commencer à ${tiers[i - 1].to_km} km (fin du palier précédent).`);
  });
  if (tiers.length && tiers[tiers.length - 1].to_km !== Number(d.max_km)) problems.push(`Le dernier palier se termine à ${tiers[tiers.length - 1].to_km} km mais la distance maximale est ${d.max_km} km.`);

  return (
    <>
      <PageHead
        title={t('Livraison')}
        sub="Ces règles sont appliquées au panier de la boutique et aux commandes saisies dans la gestion"
        actions={
          <button className="a-btn a-btn--primary" disabled={saving || problems.length > 0} onClick={() => save()}>
            <Icon name="save" /> {t('Enregistrer')}
          </button>
        }
      />
      {problems.length > 0 && (
        <div className="a-error">
          {problems.map((p) => (
            <div key={p}>{p}</div>
          ))}
        </div>
      )}
      <div className="a-grid2">
        <Card title="Disponibilité">
          <div className="a-form">
            <label className="a-check">
              <input type="checkbox" checked={!!d.enabled} onChange={(e) => set('enabled', e.target.checked)} /> Livraison offerte (décocher pour n’offrir que le retrait en magasin)
            </label>
            <fieldset className="a-checklist a-checklist--inline">
              <legend>Jours de livraison</legend>
              {DAYS.map((name, i) => (
                <label key={name} className="a-check">
                  <input
                    type="checkbox"
                    checked={(d.days ?? []).includes(i + 1)}
                    onChange={(e) => set('days', e.target.checked ? [...(d.days ?? []), i + 1].sort() : (d.days ?? []).filter((x: number) => x !== i + 1))}
                  />
                  {name}
                </label>
              ))}
            </fieldset>
            <div className="a-form--row">
              <Field label="De">
                <input className="a-input" type="time" value={d.start} onChange={(e) => set('start', e.target.value)} />
              </Field>
              <Field label="À">
                <input className="a-input" type="time" value={d.end} onChange={(e) => set('end', e.target.value)} />
              </Field>
            </div>
            <p className="a-muted a-small">Heures de livraison seulement. Les heures d’ouverture du magasin se règlent dans Paramètres.</p>
            <Field label="Temps de préparation minimum (minutes)">
              <input className="a-input" type="number" min={0} value={d.prep_minutes} onChange={(e) => set('prep_minutes', Number(e.target.value))} />
            </Field>
            <Field label="Délai annoncé aux clients">
              <input className="a-input" value={d.eta_text_fr ?? ''} onChange={(e) => set('eta_text_fr', e.target.value)} />
            </Field>
            <Field label="Instructions de livraison (affichées aux clients)">
              <textarea className="a-input" rows={3} value={d.instructions_fr ?? ''} onChange={(e) => set('instructions_fr', e.target.value)} />
            </Field>
          </div>
        </Card>

        <Card title="Zone et calcul de la distance">
          <div className="a-form">
            <Field label="Distance maximale de livraison (km)">
              <input className="a-input" type="number" min={1} step="0.5" value={d.max_km} onChange={(e) => set('max_km', Number(e.target.value))} />
            </Field>
            <Field label="Méthode de calcul">
              <select className="a-input" value={d.distance_method} onChange={(e) => set('distance_method', e.target.value)}>
                <option value="road">Distance par la route (recommandé)</option>
                <option value="straight_line">Ligne droite × facteur</option>
                <option value="manual">Manuelle : frais confirmés par l’équipe</option>
              </select>
            </Field>
            {d.distance_method === 'straight_line' && (
              <Field label="Facteur appliqué à la ligne droite" hint="1,3 = la route est en moyenne 30 % plus longue">
                <input className="a-input" type="number" step="0.05" min={1} value={d.straight_line_factor} onChange={(e) => set('straight_line_factor', Number(e.target.value))} />
              </Field>
            )}
            <div className="a-note a-small">
              <strong>Limites à connaître :</strong>
              <ul>
                <li>
                  <strong>Par la route</strong> : adresse trouvée avec OpenStreetMap, trajet calculé avec OSRM (services gratuits sans garantie). Si le numéro civique est introuvable, la distance est approximative et la
                  commande est marquée « frais à confirmer ».
                </li>
                <li>
                  <strong>Ligne droite</strong> : toujours disponible, mais sous-estime le trajet (rivière, ponts, autoroutes), d’où le facteur.
                </li>
                <li>
                  <strong>Manuelle</strong> : aucun calcul automatique ; le client voit « frais à confirmer » et l’équipe fixe les frais dans la commande.
                </li>
              </ul>
              Dans tous les cas, l’équipe peut corriger les frais de livraison d’une commande.
            </div>
          </div>
        </Card>

        <Card title="Tarifs selon la distance" className="a-span2">
          <table className="a-table a-table--edit">
            <thead>
              <tr>
                <th>De (km, inclus)</th>
                <th>À (km)</th>
                <th>Frais ($)</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {tiers.map((tr, i) => (
                <tr key={i}>
                  <td>
                    <input className="a-input a-input--sm" type="number" step="0.5" value={tr.from_km} onChange={(e) => setTier(i, { from_km: Number(e.target.value) })} />
                  </td>
                  <td>
                    <input className="a-input a-input--sm" type="number" step="0.5" value={tr.to_km} onChange={(e) => setTier(i, { to_km: Number(e.target.value) })} />
                    <span className="a-muted a-small">{i === tiers.length - 1 ? ' inclus' : ' exclu'}</span>
                  </td>
                  <td>
                    <input className="a-input a-input--sm" inputMode="decimal" value={toDollars(tr.fee_cents)} onChange={(e) => setTier(i, { fee_cents: toCents(e.target.value) ?? 0 })} />
                  </td>
                  <td>
                    <button className="a-btn a-btn--ghost a-btn--icon" onClick={() => set('tiers', tiers.filter((_, j) => j !== i))} aria-label="Retirer">
                      <Icon name="trash-2" size={16} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <button className="a-btn" onClick={() => set('tiers', [...tiers, { from_km: tiers.at(-1)?.to_km ?? 0, to_km: (tiers.at(-1)?.to_km ?? 0) + 2, fee_cents: (tiers.at(-1)?.fee_cents ?? 599) + 100 }])}>
            <Icon name="plus" /> Ajouter un palier
          </button>
          <p className="a-muted a-small">Au-delà de {d.max_km} km : pas de livraison (le client choisit le retrait).</p>
        </Card>

        <Card title="Livraison gratuite">
          <div className="a-form">
            <Field label="Gratuite à partir de ($ d’achat)" hint="Laisser vide pour ne jamais offrir la livraison gratuite">
              <input className="a-input" inputMode="decimal" value={d.free_min_subtotal_cents == null ? '' : toDollars(d.free_min_subtotal_cents)} onChange={(e) => set('free_min_subtotal_cents', toCents(e.target.value))} />
            </Field>
            <Field label="Dans un rayon de (km)">
              <input className="a-input" type="number" step="0.5" value={d.free_radius_km} onChange={(e) => set('free_radius_km', Number(e.target.value))} />
            </Field>
            <p className="a-muted a-small">
              Actuellement : gratuite dès {d.free_min_subtotal_cents != null ? money(d.free_min_subtotal_cents) : '—'} jusqu’à {d.free_radius_km} km ; sinon {tiers.length ? `${money(Math.min(...tiers.map((x) => x.fee_cents)))} à ${money(Math.max(...tiers.map((x) => x.fee_cents)))}` : 'aucun tarif'} selon la distance.
            </p>
          </div>
        </Card>
      </div>
    </>
  );
}
