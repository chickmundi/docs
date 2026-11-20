// Small shared UI pieces for the admin.
import { useEffect, useState, type ReactNode } from 'react';

export function Card({ title, actions, children, className = '' }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`a-card ${className}`}>
      {(title || actions) && (
        <header className="a-card__head">
          {title && <h2>{title}</h2>}
          {actions && <div className="a-card__actions">{actions}</div>}
        </header>
      )}
      {children}
    </section>
  );
}

export function PageHead({ title, sub, actions }: { title: string; sub?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="a-pagehead">
      <div>
        <h1>{title}</h1>
        {sub && <p>{sub}</p>}
      </div>
      {actions && <div className="a-pagehead__actions">{actions}</div>}
    </div>
  );
}

export function Field({ label, hint, children, wide }: { label: string; hint?: string; children: ReactNode; wide?: boolean }) {
  return (
    <label className={`a-field ${wide ? 'a-field--wide' : ''}`}>
      <span className="a-field__label">{label}</span>
      {children}
      {hint && <small className="a-field__hint">{hint}</small>}
    </label>
  );
}

export function Badge({ tone = 'neutral', children }: { tone?: 'neutral' | 'ok' | 'warn' | 'bad' | 'info' | 'brand'; children: ReactNode }) {
  return <span className={`a-badge a-badge--${tone}`}>{children}</span>;
}

export function Loading({ label = 'Chargement…' }: { label?: string }) {
  return (
    <div className="a-loading" role="status">
      <span className="a-spinner" /> {label}
    </div>
  );
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="a-empty">{children}</p>;
}

export function ErrorBox({ error }: { error: unknown }) {
  if (!error) return null;
  const msg = error instanceof Error ? error.message : typeof error === 'object' && error && 'message' in error ? String((error as { message: unknown }).message) : String(error);
  return (
    <p className="a-error" role="alert">
      {msg}
    </p>
  );
}

// Toast notifications
let push: ((m: { text: string; tone: 'ok' | 'bad' }) => void) | null = null;
export const notify = (text: string, tone: 'ok' | 'bad' = 'ok') => push?.({ text, tone });

export function Toasts() {
  const [items, setItems] = useState<{ id: number; text: string; tone: 'ok' | 'bad' }[]>([]);
  useEffect(() => {
    push = (m) => {
      const id = Date.now() + Math.random();
      setItems((l) => [...l, { id, ...m }]);
      setTimeout(() => setItems((l) => l.filter((x) => x.id !== id)), 3500);
    };
    return () => {
      push = null;
    };
  }, []);
  return (
    <div className="a-toasts" aria-live="polite">
      {items.map((t) => (
        <div key={t.id} className={`a-toast a-toast--${t.tone}`}>
          {t.text}
        </div>
      ))}
    </div>
  );
}

export function Modal({ title, onClose, children, footer }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);
  return (
    <div className="a-modal" role="dialog" aria-modal="true" aria-label={title}>
      <div className="a-modal__backdrop" onClick={onClose} />
      <div className="a-modal__panel">
        <header className="a-modal__head">
          <h2>{title}</h2>
          <button className="a-btn a-btn--ghost a-btn--icon" onClick={onClose} aria-label="Fermer">
            ×
          </button>
        </header>
        <div className="a-modal__body">{children}</div>
        {footer && <footer className="a-modal__foot">{footer}</footer>}
      </div>
    </div>
  );
}

export function Tabs({ tabs, active, onChange }: { tabs: { key: string; label: string; hidden?: boolean }[]; active: string; onChange: (k: string) => void }) {
  return (
    <div className="a-tabs" role="tablist">
      {tabs
        .filter((t) => !t.hidden)
        .map((t) => (
          <button key={t.key} role="tab" aria-selected={active === t.key} className="a-tab" onClick={() => onChange(t.key)}>
            {t.label}
          </button>
        ))}
    </div>
  );
}

/** Simple data hook: runs `load` on mount and when deps change. */
export function useLoad<T>(load: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const [tick, setTick] = useState(0);
  useEffect(() => {
    let alive = true;
    setLoading(true);
    load()
      .then((d) => alive && (setData(d), setError(null)))
      .catch((e) => alive && setError(e))
      .finally(() => alive && setLoading(false));
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);
  return { data, error, loading, reload: () => setTick((t) => t + 1), setData };
}

/** Throws Supabase errors so callers can use try/catch. */
export function must<T>(res: { data: T; error: unknown }): NonNullable<T> {
  if (res.error) throw res.error;
  return (res.data ?? []) as NonNullable<T>;
}
