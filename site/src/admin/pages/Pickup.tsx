import { useSetting } from '../lib/settings';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Field } from '../components/ui';
import Icon from '../components/Icon';
import { DAYS } from './Delivery';

export default function Pickup() {
  const t = useT();
  const { value: p, setValue, error, saving, save } = useSetting<Record<string, any>>('pickup');
  if (error) return <ErrorBox error={error} />;
  if (!p) return <Loading />;
  const set = (k: string, v: unknown) => setValue({ ...p, [k]: v });
  const hours: { day: number; open: string; close: string; closed: boolean }[] = p.hours ?? DAYS.map((_, i) => ({ day: i + 1, open: i === 6 ? '09:00' : '08:30', close: '22:00', closed: false }));

  return (
    <>
      <PageHead
        title={t('Retrait en magasin')}
        sub="Gratuit pour le client. Le suivi (en préparation, prête, récupérée) se fait dans chaque commande."
        actions={
          <button className="a-btn a-btn--primary" disabled={saving} onClick={() => save()}>
            <Icon name="save" /> {t('Enregistrer')}
          </button>
        }
      />
      <div className="a-grid2">
        <Card title="Disponibilité">
          <div className="a-form">
            <label className="a-check">
              <input type="checkbox" checked={!!p.enabled} onChange={(e) => set('enabled', e.target.checked)} /> Offrir le retrait en magasin
            </label>
            <Field label="Adresse de retrait">
              <input className="a-input" value={p.location} onChange={(e) => set('location', e.target.value)} />
            </Field>
            <Field label="Temps de préparation (minutes)" hint="Délai minimum entre la commande et le retrait">
              <input className="a-input" type="number" min={0} value={p.prep_minutes} onChange={(e) => set('prep_minutes', Number(e.target.value))} />
            </Field>
            <Field label="Instructions au client">
              <textarea className="a-input" rows={2} value={p.instructions_fr ?? ''} onChange={(e) => set('instructions_fr', e.target.value)} />
            </Field>
          </div>
        </Card>
        <Card title="Heures de retrait">
          <div className="a-form">
            <label className="a-check">
              <input type="checkbox" checked={!!p.use_store_hours} onChange={(e) => set('use_store_hours', e.target.checked)} /> Mêmes heures que l’ouverture du magasin (Paramètres)
            </label>
            {!p.use_store_hours && (
              <table className="a-table a-table--edit">
                <tbody>
                  {hours.map((h, i) => (
                    <tr key={h.day}>
                      <td>{DAYS[h.day - 1]}</td>
                      <td>
                        <input className="a-input a-input--sm" type="time" disabled={h.closed} value={h.open} onChange={(e) => set('hours', hours.map((x, j) => (j === i ? { ...x, open: e.target.value } : x)))} />
                      </td>
                      <td>
                        <input className="a-input a-input--sm" type="time" disabled={h.closed} value={h.close} onChange={(e) => set('hours', hours.map((x, j) => (j === i ? { ...x, close: e.target.value } : x)))} />
                      </td>
                      <td>
                        <label className="a-check a-small">
                          <input type="checkbox" checked={h.closed} onChange={(e) => set('hours', hours.map((x, j) => (j === i ? { ...x, closed: e.target.checked } : x)))} /> fermé
                        </label>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            <label className="a-check">
              <input type="checkbox" checked={!!p.slots_enabled} onChange={(e) => set('slots_enabled', e.target.checked)} /> Le client choisit une plage horaire de retrait
            </label>
            {p.slots_enabled && (
              <Field label="Durée d’une plage (minutes)">
                <select className="a-input" value={p.slot_minutes} onChange={(e) => set('slot_minutes', Number(e.target.value))}>
                  {[30, 60, 120].map((m) => (
                    <option key={m} value={m}>
                      {m} min
                    </option>
                  ))}
                </select>
              </Field>
            )}
          </div>
        </Card>
      </div>
    </>
  );
}
