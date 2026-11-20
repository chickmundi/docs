import { useSetting } from '../lib/settings';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Field, Badge } from '../components/ui';
import Icon from '../components/Icon';

const ACCEPTS: Record<string, string> = { cash: 'Comptant', debit: 'Débit', credit: 'Crédit' };

export default function Payments() {
  const t = useT();
  const { value: p, setValue, error, saving, save } = useSetting<Record<string, any>>('payments');
  if (error) return <ErrorBox error={error} />;
  if (!p) return <Loading />;
  const set = (method: string, k: string, v: unknown) => setValue({ ...p, [method]: { ...p[method], [k]: v } });
  const toggleAccept = (method: string, a: string, on: boolean) => set(method, 'accepts', on ? [...(p[method].accepts ?? []), a] : (p[method].accepts ?? []).filter((x: string) => x !== a));

  return (
    <>
      <PageHead
        title={t('Paiements')}
        sub="Modes de paiement offerts aux clients. Le statut de paiement de chaque commande est distinct de sa préparation."
        actions={
          <button className="a-btn a-btn--primary" disabled={saving} onClick={() => save()}>
            <Icon name="save" /> {t('Enregistrer')}
          </button>
        }
      />
      <div className="a-grid2">
        <Card title={<><Icon name="credit-card" /> Carte en ligne (Stripe)</>}>
          <p>
            <Badge tone="warn">À configurer</Badge>
          </p>
          <p className="a-small">
            Le paiement par carte en ligne passera par Stripe (cartes, Apple Pay, Google Pay, en dollars canadiens). Il s’active quand le compte Stripe du commerce est relié : les paiements reçus s’enregistreront alors
            automatiquement dans les commandes, avec la référence de transaction Stripe.
          </p>
          <label className="a-check">
            <input type="checkbox" checked={!!p.card_online?.enabled} disabled onChange={() => {}} /> Offrir le paiement par carte en ligne
          </label>
          <p className="a-muted a-small">Bloqué tant que Stripe n’est pas relié, pour qu’aucune commande ne soit marquée « payée » sans paiement réel.</p>
        </Card>
        {(['pay_on_delivery', 'pay_in_store'] as const).map((m) => (
          <Card key={m} title={<><Icon name={m === 'pay_on_delivery' ? 'truck' : 'store'} /> {m === 'pay_on_delivery' ? 'Paiement à la livraison' : 'Paiement en magasin'}</>}>
            <div className="a-form">
              <label className="a-check">
                <input type="checkbox" checked={!!p[m]?.enabled} onChange={(e) => set(m, 'enabled', e.target.checked)} /> Offert aux clients
              </label>
              <Field label="Libellé affiché">
                <input className="a-input" value={p[m]?.label_fr ?? ''} onChange={(e) => set(m, 'label_fr', e.target.value)} />
              </Field>
              <fieldset className="a-checklist a-checklist--inline">
                <legend>Moyens acceptés</legend>
                {Object.entries(ACCEPTS).map(([k, l]) => (
                  <label key={k} className="a-check">
                    <input type="checkbox" checked={(p[m]?.accepts ?? []).includes(k)} onChange={(e) => toggleAccept(m, k, e.target.checked)} /> {l}
                  </label>
                ))}
              </fieldset>
              <p className="a-muted a-small">L’équipe enregistre le paiement reçu dans la commande (montant, moyen, n° de reçu). La commande passe à « payée » quand le total est couvert.</p>
            </div>
          </Card>
        ))}
        <Card title={<><Icon name="banknote" /> Transfert d’argent Ria</>}>
          <p className="a-small">
            Ria est un <strong>service offert en magasin</strong>, distinct de la boutique en ligne. Il n’est pas un mode de paiement des commandes et n’apparaît pas au paiement. Il est seulement mentionné dans
            l’en-tête et le pied de page du site.
          </p>
        </Card>
      </div>
    </>
  );
}
