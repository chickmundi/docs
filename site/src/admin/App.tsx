// Marché Chemin-du-Roy back office: shell, sign-in and hash routing.
import { useEffect, useState, lazy, Suspense, type ComponentType } from 'react';
import type { Session } from '@supabase/supabase-js';
import { supabase } from './lib/supabase';
import { useT, useLang, setLang } from './lib/i18n';
import { Toasts, Loading, ErrorBox } from './components/ui';
import Icon from './components/Icon';
import { PREVIEW } from './lib/preview';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const Orders = lazy(() => import('./pages/Orders'));
const OrderDetail = lazy(() => import('./pages/OrderDetail'));
const Products = lazy(() => import('./pages/Products'));
const ProductEdit = lazy(() => import('./pages/ProductEdit'));
const Categories = lazy(() => import('./pages/Categories'));
const Brands = lazy(() => import('./pages/Brands'));
const Inventory = lazy(() => import('./pages/Inventory'));
const Customers = lazy(() => import('./pages/Customers'));
const Promotions = lazy(() => import('./pages/Promotions'));
const Coupons = lazy(() => import('./pages/Coupons'));
const Delivery = lazy(() => import('./pages/Delivery'));
const Pickup = lazy(() => import('./pages/Pickup'));
const Payments = lazy(() => import('./pages/Payments'));
const Homepage = lazy(() => import('./pages/Homepage'));
const Reports = lazy(() => import('./pages/Reports'));
const Settings = lazy(() => import('./pages/Settings'));
const Team = lazy(() => import('./pages/Team'));

type Route = { path: string; component: ComponentType<{ params: string[] }> };
const ROUTES: Route[] = [
  { path: '', component: Dashboard },
  { path: 'commandes', component: Orders },
  { path: 'commandes/*', component: OrderDetail },
  { path: 'produits', component: Products },
  { path: 'produits/*', component: ProductEdit },
  { path: 'categories', component: Categories },
  { path: 'marques', component: Brands },
  { path: 'inventaire', component: Inventory },
  { path: 'clients', component: Customers },
  { path: 'promotions', component: Promotions },
  { path: 'coupons', component: Coupons },
  { path: 'livraison', component: Delivery },
  { path: 'retrait', component: Pickup },
  { path: 'paiements', component: Payments },
  { path: 'accueil', component: Homepage },
  { path: 'rapports', component: Reports },
  { path: 'parametres', component: Settings },
  { path: 'equipe', component: Team },
];

const NAV: { group: string; items: { path: string; label: string; icon: string }[] }[] = [
  { group: '', items: [{ path: '', label: 'Tableau de bord', icon: 'layout-dashboard' }] },
  {
    group: 'Ventes',
    items: [
      { path: 'commandes', label: 'Commandes', icon: 'receipt' },
      { path: 'clients', label: 'Clients', icon: 'users' },
    ],
  },
  {
    group: 'Catalogue',
    items: [
      { path: 'produits', label: 'Produits', icon: 'package' },
      { path: 'categories', label: 'Catégories', icon: 'folder-tree' },
      { path: 'marques', label: 'Marques', icon: 'tag' },
      { path: 'inventaire', label: 'Inventaire', icon: 'boxes' },
    ],
  },
  {
    group: 'Marketing',
    items: [
      { path: 'promotions', label: 'Promotions', icon: 'badge-percent' },
      { path: 'coupons', label: 'Coupons', icon: 'ticket' },
      { path: 'accueil', label: "Page d'accueil", icon: 'house' },
    ],
  },
  {
    group: 'Opérations',
    items: [
      { path: 'livraison', label: 'Livraison', icon: 'truck' },
      { path: 'retrait', label: 'Retrait en magasin', icon: 'store' },
      { path: 'paiements', label: 'Paiements', icon: 'credit-card' },
      { path: 'rapports', label: 'Rapports', icon: 'chart-line' },
    ],
  },
  {
    group: 'Administration',
    items: [
      { path: 'parametres', label: 'Paramètres', icon: 'settings' },
      { path: 'equipe', label: 'Équipe', icon: 'key-round' },
    ],
  },
];

function useHashPath() {
  const read = () => decodeURIComponent(location.hash.replace(/^#\/?/, '')).replace(/\/$/, '');
  const [path, setPath] = useState(read);
  useEffect(() => {
    const on = () => {
      setPath(read());
      window.scrollTo(0, 0);
    };
    window.addEventListener('hashchange', on);
    return () => window.removeEventListener('hashchange', on);
  }, []);
  return path;
}

function match(path: string): { route: Route; params: string[] } | null {
  for (const r of ROUTES) {
    if (r.path === path) return { route: r, params: [] };
    if (r.path.endsWith('/*')) {
      const base = r.path.slice(0, -2);
      if (path.startsWith(base + '/')) return { route: r, params: path.slice(base.length + 1).split('/') };
    }
  }
  return null;
}

function Login() {
  const t = useT();
  const [mode, setMode] = useState<'in' | 'up'>('in');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [created, setCreated] = useState(false);
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    if (mode === 'up') {
      if (password.length < 10) {
        setBusy(false);
        return setError('Le mot de passe doit contenir au moins 10 caractères.');
      }
      const { data, error } = await supabase.auth.signUp({ email, password, options: { data: { full_name: name }, emailRedirectTo: `${location.origin}/admin/` } });
      setBusy(false);
      if (error) return setError(error);
      if (data.session) return; // email confirmation disabled: already signed in
      return setCreated(true);
    }
    const { error } = await supabase.auth.signInWithPassword({ email, password });
    setBusy(false);
    if (error) setError(error.message === 'Invalid login credentials' ? 'Courriel ou mot de passe incorrect.' : error.message === 'Email not confirmed' ? 'Confirmez d’abord votre courriel (lien reçu par courriel).' : error);
  };
  if (created)
    return (
      <div className="a-login">
        <div className="a-login__card">
          <img src="/images/brand/MRC-logo.png" alt="Marché Chemin-du-Roy" width="200" height="62" />
          <h1>Vérifiez vos courriels</h1>
          <p>Un lien de confirmation a été envoyé à <strong>{email}</strong>. Cliquez dessus, puis revenez ici pour vous connecter.</p>
          <p className="a-muted a-small">Après la connexion, votre accès doit être approuvé par le propriétaire ou un gestionnaire (sauf pour le compte du commerce, sc@marchecheminduroy.com, qui devient propriétaire).</p>
          <button className="a-btn a-btn--block" onClick={() => (setCreated(false), setMode('in'))}>
            Retour à la connexion
          </button>
        </div>
      </div>
    );
  const reset = async () => {
    if (!email) return setError('Entrez votre courriel d’abord.');
    const { error } = await supabase.auth.resetPasswordForEmail(email, { redirectTo: `${location.origin}/admin/` });
    if (error) setError(error);
    else setSent(true);
  };
  return (
    <div className="a-login">
      <form className="a-login__card" onSubmit={submit}>
        <img src="/images/brand/MRC-logo.png" alt="Marché Chemin-du-Roy" width="200" height="62" />
        <h1>{mode === 'in' ? 'Gestion de la boutique' : 'Créer un accès'}</h1>
        <p className="a-muted">Accès réservé à l’équipe du Marché Chemin-du-Roy.</p>
        {mode === 'up' && (
          <label className="a-field">
            <span className="a-field__label">Nom complet</span>
            <input className="a-input" autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} required />
          </label>
        )}
        <label className="a-field">
          <span className="a-field__label">Courriel</span>
          <input className="a-input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </label>
        <label className="a-field">
          <span className="a-field__label">Mot de passe</span>
          <input className="a-input" type="password" autoComplete={mode === 'in' ? 'current-password' : 'new-password'} value={password} onChange={(e) => setPassword(e.target.value)} required />
        </label>
        <ErrorBox error={error} />
        {sent && <p className="a-ok">Un lien de réinitialisation a été envoyé à {email}.</p>}
        <button className="a-btn a-btn--primary a-btn--block" disabled={busy}>
          {busy ? 'Un instant…' : mode === 'in' ? 'Se connecter' : 'Créer mon accès'}
        </button>
        {mode === 'in' && (
          <button type="button" className="a-link" onClick={reset}>
            Mot de passe oublié ?
          </button>
        )}
        <button type="button" className="a-link" onClick={() => (setMode(mode === 'in' ? 'up' : 'in'), setError(null))}>
          {mode === 'in' ? 'Nouveau membre de l’équipe ? Créer un accès' : 'J’ai déjà un accès : se connecter'}
        </button>
        <p className="a-muted a-small">{t('Voir le site')} : <a href="/">nouveau.marchecheminduroy.com</a></p>
      </form>
    </div>
  );
}

function SetPassword({ onDone }: { onDone: () => void }) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState<unknown>(null);
  return (
    <div className="a-login">
      <form
        className="a-login__card"
        onSubmit={async (e) => {
          e.preventDefault();
          if (password.length < 10) return setError('Au moins 10 caractères.');
          const { error } = await supabase.auth.updateUser({ password });
          if (error) setError(error);
          else onDone();
        }}
      >
        <h1>Choisir un mot de passe</h1>
        <label className="a-field">
          <span className="a-field__label">Nouveau mot de passe</span>
          <input className="a-input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <ErrorBox error={error} />
        <button className="a-btn a-btn--primary a-btn--block">Enregistrer</button>
      </form>
    </div>
  );
}

export default function App() {
  const t = useT();
  const lang = useLang();
  const path = useHashPath();
  const [session, setSession] = useState<Session | null | undefined>(undefined);
  const [staff, setStaff] = useState<{ full_name: string; role: string } | null | undefined>(undefined);
  const [recovery, setRecovery] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

  useEffect(() => {
    supabase.auth.getSession().then(({ data }) => setSession(data.session));
    const { data } = supabase.auth.onAuthStateChange((event, s) => {
      setSession(s);
      if (event === 'PASSWORD_RECOVERY') setRecovery(true);
    });
    return () => data.subscription.unsubscribe();
  }, []);

  useEffect(() => {
    if (!session) {
      setStaff(session === null ? null : undefined);
      return;
    }
    (async () => {
      const name = (session.user.user_metadata?.full_name as string) || '';
      await supabase.rpc('request_staff_access', { full_name: name });
      const { data } = await supabase.from('staff').select('full_name, role, active').eq('user_id', session.user.id).maybeSingle();
      setStaff(data && data.active ? data : null);
    })();
  }, [session]);

  useEffect(() => setMenuOpen(false), [path]);

  if (PREVIEW) return <Shell staff={{ full_name: 'Aperçu', role: 'owner' }} path={path} menuOpen={menuOpen} setMenuOpen={setMenuOpen} />;
  if (session === undefined || (session && staff === undefined)) return <Loading />;
  if (recovery && session) return <SetPassword onDone={() => setRecovery(false)} />;
  if (!session) return <Login />;
  if (!staff)
    return (
      <div className="a-login">
        <div className="a-login__card">
          <h1>Accès en attente</h1>
          <p>Votre demande d’accès ({session.user.email}) a été transmise. Le propriétaire ou un gestionnaire doit l’approuver dans Équipe.</p>
          <button className="a-btn" onClick={() => supabase.auth.signOut()}>
            Se déconnecter
          </button>
        </div>
      </div>
    );

  return <Shell staff={staff} path={path} menuOpen={menuOpen} setMenuOpen={setMenuOpen} />;
}

function Shell({ staff, path, menuOpen, setMenuOpen }: { staff: { full_name: string; role: string }; path: string; menuOpen: boolean; setMenuOpen: React.Dispatch<React.SetStateAction<boolean>> }) {
  const t = useT();
  const lang = useLang();
  const m = match(path);
  const Page = m?.route.component;
  const section = path.split('/')[0];

  return (
    <div className={`a-shell ${menuOpen ? 'is-menu' : ''}`}>
      <aside className="a-side">
        <a className="a-side__brand" href="#/">
          <img src="/images/brand/MRC-logo-whit.png" alt="Marché Chemin-du-Roy" width="150" height="47" />
          <span>Gestion</span>
        </a>
        <nav>
          {NAV.map((g) => (
            <div key={g.group} className="a-side__group">
              {g.group && <p className="a-side__title">{g.group}</p>}
              {g.items.map((i) => (
                <a key={i.path} href={`#/${i.path}`} aria-current={section === i.path ? 'page' : undefined}>
                  <Icon name={i.icon} size={18} className="a-side__ic" />
                  {t(i.label)}
                </a>
              ))}
            </div>
          ))}
        </nav>
      </aside>
      <div className="a-main">
        <header className="a-top">
          <button className="a-btn a-btn--ghost a-top__menu" onClick={() => setMenuOpen((o) => !o)} aria-label="Menu">
            <Icon name="menu" size={20} />
          </button>
          <div className="a-top__right">
            <a className="a-btn a-btn--ghost" href="/" target="_blank" rel="noopener">
              {t('Voir le site')} ↗
            </a>
            <button className="a-btn a-btn--ghost" onClick={() => setLang(lang === 'fr' ? 'en' : 'fr')} aria-label="Langue / Language">
              {lang === 'fr' ? 'EN' : 'FR'}
            </button>
            {PREVIEW ? (
              <a className="a-btn a-btn--primary" href="/admin/">
                Se connecter
              </a>
            ) : (
              <>
            <span className="a-top__user">
              {staff.full_name} <small>({staff.role === 'owner' ? 'propriétaire' : staff.role === 'manager' ? 'gestionnaire' : 'employé'})</small>
            </span>
            <button className="a-btn a-btn--ghost" onClick={() => supabase.auth.signOut()}>
              {t('Se déconnecter')}
            </button>
              </>
            )}
          </div>
        </header>
        {PREVIEW && (
          <div className="a-preview">
            <Icon name="info" size={18} />
            <span>
              <strong>Mode aperçu, lecture seule.</strong> Vous voyez le vrai catalogue et les vrais paramètres ; les commandes, clients et ventes ne s’affichent qu’une fois connecté, et rien n’est enregistré.
            </span>
          </div>
        )}
        <main className="a-content">
          <Suspense fallback={<Loading />}>{Page ? <Page params={m!.params} /> : <p>Page introuvable.</p>}</Suspense>
        </main>
      </div>
      <div className="a-scrim" onClick={() => setMenuOpen(false)} />
      <Toasts />
    </div>
  );
}
