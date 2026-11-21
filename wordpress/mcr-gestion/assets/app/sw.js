/* MCR Commandes: service worker. Shows order notifications and keeps the app shell available offline. */
const CACHE = 'mcr-app-v1';
const SCOPE = self.registration.scope; // https://…/mcr-app/

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll([SCOPE]).catch(() => {})));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())
  );
});

// Network first for the app page (always fresh orders), cache as fallback when offline.
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET' || req.mode !== 'navigate' || !req.url.startsWith(SCOPE)) return;
  e.respondWith(
    fetch(req)
      .then((res) => {
        const copy = res.clone();
        if (res.ok && res.type === 'basic') caches.open(CACHE).then((c) => c.put(SCOPE, copy));
        return res;
      })
      .catch(() => caches.match(SCOPE))
  );
});

self.addEventListener('push', (e) => {
  let data = {};
  try { data = e.data ? e.data.json() : {}; } catch (err) { data = { title: 'Nouvelle commande', body: e.data ? e.data.text() : '' }; }
  const title = data.title || 'MCR Commandes';
  const opts = {
    body: data.body || '',
    tag: data.tag || 'mcr',
    renotify: data.renotify !== false,
    requireInteraction: !!data.order,
    vibrate: [300, 120, 300, 120, 600],
    icon: data.icon,
    badge: data.badge,
    data: { url: data.url || SCOPE, order: data.order || null },
  };
  e.waitUntil(Promise.all([
    self.registration.showNotification(title, opts),
    // Tell open app windows to refresh and play a sound.
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => list.forEach((c) => c.postMessage({ type: 'mcr-push', data }))),
    // Badge on the app icon (supported on Android and iOS home-screen apps).
    self.navigator && self.navigator.setAppBadge ? self.navigator.setAppBadge().catch(() => {}) : null,
  ]));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || SCOPE, self.location.origin).href;
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if (c.url.startsWith(SCOPE) && 'focus' in c) {
          c.postMessage({ type: 'mcr-open', url });
          return c.focus();
        }
      }
      return self.clients.openWindow(url);
    })
  );
});
