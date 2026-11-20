import { useSetting } from '../lib/settings';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Field } from '../components/ui';
import Icon from '../components/Icon';
import { DAYS } from './Delivery';

export default function Settings() {
  const t = useT();
  const store = useSetting<Record<string, any>>('store');
  const hours = useSetting<{ days: { day: number; open: string; close: string; closed: boolean }[] }>('store_hours');
  const ann = useSetting<Record<string, any>>('announcement');
  const tax = useSetting<Record<string, any>>('taxes');
  const err = store.error || hours.error || ann.error || tax.error;
  if (err) return <ErrorBox error={err} />;
  if (!store.value || !hours.value || !ann.value || !tax.value) return <Loading />;
  const s = store.value;
  const setS = (k: string, v: unknown) => store.setValue({ ...s, [k]: v });
  const setAddr = (k: string, v: string) => store.setValue({ ...s, address: { ...s.address, [k]: v } });
  const days = hours.value.days;
  const setDay = (i: number, patch: Record<string, unknown>) => hours.setValue({ days: days.map((d, j) => (j === i ? { ...d, ...patch } : d)) });

  return (
    <>
      <PageHead title={t('Paramètres')} sub="Informations du commerce, heures d’ouverture, bandeau d’annonce et taxes" />
      <div className="a-grid2">
        <Card title="Commerce" actions={<button className="a-btn a-btn--primary" disabled={store.saving} onClick={() => store.save()}><Icon name="save" /> {t('Enregistrer')}</button>}>
          <div className="a-form">
            <Field label="Nom">
              <input className="a-input" value={s.name} onChange={(e) => setS('name', e.target.value)} />
            </Field>
            <div className="a-form--row">
              <Field label="Téléphone">
                <input className="a-input" value={s.phone} onChange={(e) => setS('phone', e.target.value)} />
              </Field>
              <Field label="Courriel">
                <input className="a-input" type="email" value={s.email} onChange={(e) => setS('email', e.target.value)} />
              </Field>
            </div>
            <div className="a-form--row">
              <Field label="WhatsApp (chiffres avec indicatif, ex. 15145787442)" hint="Vide = boutons WhatsApp masqués">
                <input className="a-input" value={s.whatsapp} onChange={(e) => setS('whatsapp', e.target.value.replace(/\D/g, ''))} />
              </Field>
              <Field label="WhatsApp (affichage)">
                <input className="a-input" value={s.whatsapp_display} onChange={(e) => setS('whatsapp_display', e.target.value)} />
              </Field>
            </div>
            <Field label="Adresse">
              <input className="a-input" value={s.address?.street} onChange={(e) => setAddr('street', e.target.value)} />
            </Field>
            <div className="a-form--row">
              <Field label="Ville">
                <input className="a-input" value={s.address?.city} onChange={(e) => setAddr('city', e.target.value)} />
              </Field>
              <Field label="Code postal">
                <input className="a-input" value={s.address?.postal_code} onChange={(e) => setAddr('postal_code', e.target.value)} />
              </Field>
            </div>
            <p className="a-muted a-small">
              Point de départ des livraisons : {s.location?.lat}, {s.location?.lon} (65 Rue Notre-Dame E). À modifier seulement si le magasin déménage.
            </p>
          </div>
        </Card>

        <Card title="Heures d’ouverture du magasin" actions={<button className="a-btn a-btn--primary" disabled={hours.saving} onClick={() => hours.save()}><Icon name="save" /> {t('Enregistrer')}</button>}>
          <table className="a-table a-table--edit">
            <tbody>
              {days.map((d, i) => (
                <tr key={d.day}>
                  <td>{DAYS[d.day - 1]}</td>
                  <td>
                    <input className="a-input a-input--sm" type="time" disabled={d.closed} value={d.open} onChange={(e) => setDay(i, { open: e.target.value })} />
                  </td>
                  <td>
                    <input className="a-input a-input--sm" type="time" disabled={d.closed} value={d.close} onChange={(e) => setDay(i, { close: e.target.value })} />
                  </td>
                  <td>
                    <label className="a-check a-small">
                      <input type="checkbox" checked={d.closed} onChange={(e) => setDay(i, { closed: e.target.checked })} /> fermé
                    </label>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="a-muted a-small">Heures du magasin seulement. Les heures de livraison se règlent dans Livraison.</p>
        </Card>

        <Card title="Bandeau d’annonce" actions={<button className="a-btn a-btn--primary" disabled={ann.saving} onClick={() => ann.save()}><Icon name="save" /> {t('Enregistrer')}</button>}>
          <div className="a-form">
            <label className="a-check">
              <input type="checkbox" checked={!!ann.value.enabled} onChange={(e) => ann.setValue({ ...ann.value!, enabled: e.target.checked })} /> Afficher le bandeau en haut du site
            </label>
            <Field label="Texte (français)">
              <textarea className="a-input" rows={2} value={ann.value.text_fr} onChange={(e) => ann.setValue({ ...ann.value!, text_fr: e.target.value })} />
            </Field>
            <Field label="Texte (anglais)">
              <textarea className="a-input" rows={2} value={ann.value.text_en} onChange={(e) => ann.setValue({ ...ann.value!, text_en: e.target.value })} />
            </Field>
          </div>
        </Card>

        <Card title="Taxes (TPS / TVQ)" actions={<button className="a-btn a-btn--primary" disabled={tax.saving} onClick={() => tax.save()}><Icon name="save" /> {t('Enregistrer')}</button>}>
          <div className="a-form">
            <label className="a-check">
              <input type="checkbox" checked={!!tax.value.enabled} onChange={(e) => tax.setValue({ ...tax.value!, enabled: e.target.checked })} /> Calculer les taxes automatiquement au paiement
            </label>
            <div className="a-form--row">
              <Field label="TPS">
                <input className="a-input" type="number" step="0.00001" value={tax.value.gst_rate} onChange={(e) => tax.setValue({ ...tax.value!, gst_rate: Number(e.target.value) })} />
              </Field>
              <Field label="TVQ">
                <input className="a-input" type="number" step="0.00001" value={tax.value.qst_rate} onChange={(e) => tax.setValue({ ...tax.value!, qst_rate: Number(e.target.value) })} />
              </Field>
            </div>
            <p className="a-muted a-small">
              La plupart des aliments de base sont détaxés au Québec, mais pas les grignotines, boissons gazeuses ni les produits de beauté. Avant d’activer le calcul automatique, il faut indiquer quels produits sont
              taxables (à valider avec votre comptable).
            </p>
          </div>
        </Card>
      </div>
    </>
  );
}
