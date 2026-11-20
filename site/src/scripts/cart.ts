// Cart stored in the visitor's browser (localStorage). No server needed.
export type CartItem = {
  key: string; // productId or productId:variationId
  id: number;
  variationId?: number;
  name: string;
  variant?: string;
  price: number; // cents
  unit?: string | null;
  image?: string;
  slug: string;
  qty: number;
};

const KEY = 'mcr-cart-v1';
let memory: CartItem[] = [];

export function getCart(): CartItem[] {
  try {
    const raw = localStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as CartItem[]) : memory;
  } catch {
    return memory;
  }
}

function save(items: CartItem[]) {
  memory = items;
  try {
    localStorage.setItem(KEY, JSON.stringify(items));
  } catch {
    /* storage unavailable: keep in memory for this page */
  }
  document.dispatchEvent(new CustomEvent('cart:change', { detail: items }));
}

export function addToCart(item: Omit<CartItem, 'qty'>, qty = 1) {
  const items = getCart();
  const existing = items.find((i) => i.key === item.key);
  if (existing) existing.qty = Math.min(99, existing.qty + qty);
  else items.push({ ...item, qty });
  save(items);
}

export function setQty(key: string, qty: number) {
  const items = getCart()
    .map((i) => (i.key === key ? { ...i, qty: Math.max(0, Math.min(99, qty)) } : i))
    .filter((i) => i.qty > 0);
  save(items);
}

export const clearCart = () => save([]);

export const cartCount = (items = getCart()) => items.reduce((n, i) => n + i.qty, 0);
export const cartTotal = (items = getCart()) => items.reduce((n, i) => n + i.price * i.qty, 0);

const fmt = new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' });
export const money = (cents: number) => fmt.format(cents / 100);

// Keep header badges in sync on every page.
function renderBadges() {
  const n = cartCount();
  document.querySelectorAll<HTMLElement>('[data-cart-count]').forEach((el) => {
    el.textContent = String(n);
    el.hidden = n === 0;
  });
}

document.addEventListener('cart:change', renderBadges);
window.addEventListener('storage', (e) => e.key === KEY && renderBadges());
renderBadges();

export function toast(message: string) {
  let el = document.getElementById('toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'toast';
    el.setAttribute('role', 'status');
    document.body.append(el);
  }
  el.textContent = message;
  el.classList.add('is-visible');
  clearTimeout((el as any)._t);
  (el as any)._t = setTimeout(() => el!.classList.remove('is-visible'), 2400);
}

// Any button with data-add-to-cart='{"id":…}' adds itself.
document.addEventListener('click', (e) => {
  const btn = (e.target as HTMLElement).closest<HTMLButtonElement>('[data-add-to-cart]');
  if (!btn) return;
  const data = JSON.parse(btn.dataset.addToCart!);
  addToCart({ key: String(data.id), ...data });
  toast(`« ${data.name} » ajouté au panier`);
});
