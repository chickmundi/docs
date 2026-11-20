// "Ma liste": products saved in the visitor's browser (localStorage).
export type WishItem = { id: number; slug: string; name: string; price: number | null; unit?: string | null; image?: string };

const KEY = 'mcr-wishlist-v1';
let memory: WishItem[] = [];

export function getWishlist(): WishItem[] {
  try {
    const raw = localStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as WishItem[]) : memory;
  } catch {
    return memory;
  }
}

function save(items: WishItem[]) {
  memory = items;
  try {
    localStorage.setItem(KEY, JSON.stringify(items));
  } catch {
    /* storage unavailable: keep in memory */
  }
  document.dispatchEvent(new CustomEvent('wishlist:change', { detail: items }));
}

export const inWishlist = (id: number) => getWishlist().some((i) => i.id === id);

export function toggleWishlist(item: WishItem): boolean {
  const items = getWishlist();
  const exists = items.some((i) => i.id === item.id);
  save(exists ? items.filter((i) => i.id !== item.id) : [item, ...items]);
  return !exists;
}

export const removeFromWishlist = (id: number) => save(getWishlist().filter((i) => i.id !== id));

function render() {
  const items = getWishlist();
  const ids = new Set(items.map((i) => i.id));
  document.querySelectorAll<HTMLElement>('[data-wish-count]').forEach((el) => {
    el.textContent = String(items.length);
    el.hidden = items.length === 0;
  });
  document.querySelectorAll<HTMLButtonElement>('[data-wish]').forEach((b) => {
    const id = JSON.parse(b.dataset.wish!).id;
    const on = ids.has(id);
    b.setAttribute('aria-pressed', String(on));
    b.setAttribute('aria-label', on ? 'Retirer de ma liste' : 'Ajouter à ma liste');
  });
}

document.addEventListener('wishlist:change', render);
window.addEventListener('storage', (e) => e.key === KEY && render());
render();

document.addEventListener('click', (e) => {
  const btn = (e.target as HTMLElement).closest<HTMLButtonElement>('[data-wish]');
  if (!btn) return;
  e.preventDefault();
  toggleWishlist(JSON.parse(btn.dataset.wish!));
  btn.classList.remove('bump');
  void btn.offsetWidth;
  btn.classList.add('bump');
});
