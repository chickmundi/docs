// Lucide icons for the admin (same set as the storefront).
import { ICONS } from './icons';

const cache = new Map<string, string>();

export default function Icon({ name, size = 18, className = '' }: { name: string; size?: number; className?: string }) {
  const key = `${name}:${size}`;
  let svg = cache.get(key);
  if (!svg) {
    const raw = ICONS[name] ?? ICONS.circle;
    svg = raw
      .replace(/<svg[^>]*>/, (t) => t.replace(/\s(class|width|height)="[^"]*"/g, ''))
      .replace('<svg', `<svg width="${size}" height="${size}" aria-hidden="true" focusable="false"`);
    cache.set(key, svg);
  }
  return <span className={`a-icon ${className}`} dangerouslySetInnerHTML={{ __html: svg }} />;
}
