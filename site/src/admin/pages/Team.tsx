import { supabase, dateTime, audit } from '../lib/supabase';
import { useT } from '../lib/i18n';
import { Card, PageHead, Loading, ErrorBox, Badge, useLoad, must, notify } from '../components/ui';

const ROLES: Record<string, [string, string]> = {
  owner: ['Propriétaire', 'Tout, y compris l’équipe et les paramètres'],
  manager: ['Gestionnaire', 'Tout sauf retirer le propriétaire'],
  staff: ['Employé', 'Commandes, produits, inventaire, clients ; pas les paramètres, coupons ni promotions'],
};

export default function Team() {
  const t = useT();
  const { data, error, loading, reload } = useLoad(async () => ({
    staff: must(await supabase.from('staff').select('*').order('created_at')),
    emails: (await supabase.rpc('staff_emails')).data ?? [],
    // Only the owner and managers can read the journal; employees get an empty list.
    log: (await supabase.from('audit_log').select('*').order('created_at', { ascending: false }).limit(50)).data ?? [],
  }));
  if (loading) return <Loading />;
  if (error) return <ErrorBox error={error} />;

  const update = async (s: any, patch: Record<string, unknown>) => {
    const { error } = await supabase.from('staff').update(patch).eq('user_id', s.user_id);
    if (error) return notify(error.message.includes('row-level') ? 'Réservé au propriétaire et aux gestionnaires.' : error.message, 'bad');
    await audit('staff.update', 'staff', s.user_id, patch);
    reload();
  };
  const nameOf = (id: string) => (data!.staff as any[]).find((s) => s.user_id === id)?.full_name ?? '—';

  return (
    <>
      <PageHead title={t('Équipe')} sub="Personnes ayant accès à la gestion" />
      <Card>
        <table className="a-table">
          <thead>
            <tr>
              <th>Nom</th>
              <th>Rôle</th>
              <th>Accès</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {(data!.staff as any[]).map((s) => (
              <tr key={s.user_id}>
                <td>
                  <strong>{s.full_name}</strong>
                  <div className="a-muted a-small">
                    {(data!.emails as any[]).find((e) => e.user_id === s.user_id)?.email} · depuis {dateTime(s.created_at)}
                  </div>
                </td>
                <td>
                  <select className="a-input a-input--sm" value={s.role} onChange={(e) => update(s, { role: e.target.value })}>
                    {Object.entries(ROLES).map(([k, [l]]) => (
                      <option key={k} value={k}>
                        {l}
                      </option>
                    ))}
                  </select>
                </td>
                <td>{s.active ? <Badge tone="ok">actif</Badge> : <Badge tone="warn">en attente / désactivé</Badge>}</td>
                <td className="a-num">
                  <button className={`a-btn ${s.active ? 'a-btn--ghost' : 'a-btn--primary'}`} onClick={() => update(s, { active: !s.active })}>
                    {s.active ? 'Désactiver' : 'Approuver'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="a-note a-small">
          <strong>Ajouter une personne :</strong> elle crée son accès sur la page de connexion (« Créer un accès »), confirme son courriel, se connecte une fois, puis apparaît ici « en attente » : cliquez « Approuver ». Rôles :
          <ul>
            {Object.values(ROLES).map(([l, d]) => (
              <li key={l}>
                <strong>{l}</strong> : {d}
              </li>
            ))}
          </ul>
        </div>
      </Card>
      <Card title="Journal des modifications (50 dernières)">
        <table className="a-table">
          <tbody>
            {(data!.log as any[]).map((l) => (
              <tr key={l.id}>
                <td>{dateTime(l.created_at)}</td>
                <td>{nameOf(l.actor)}</td>
                <td>
                  {l.action} <span className="a-muted">{l.entity_id}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </>
  );
}
