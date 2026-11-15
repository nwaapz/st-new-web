/// <reference lib="webworker" />
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst } from 'workbox-strategies';
import { initializeApp } from 'firebase/app';
import { getMessaging, onBackgroundMessage } from 'firebase/messaging/sw';

declare const self: ServiceWorkerGlobalScope;

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

registerRoute(
  ({ url, request }) =>
    request.method === 'GET' && url.pathname.includes('/api/') && url.pathname.endsWith('.php'),
  new NetworkFirst({
    cacheName: 'sales-api-get',
    networkTimeoutSeconds: 8,
  }),
);

registerRoute(
  ({ url, request }) =>
    request.method === 'GET' &&
    (/\/uploads\//.test(url.pathname) || /\.(?:png|jpe?g|webp|gif|svg|ico)$/i.test(url.pathname)),
  new CacheFirst({ cacheName: 'sales-images' }),
);

function deepLinkFromData(data: Record<string, string>): string {
  const type = data.type || '';
  if (type === 'direct_chat') return '/test2/sales/messages';
  const orderId = data.order_id || '';
  if (orderId) {
    const focus = type === 'order_chat' ? '?focus=chat' : '';
    return `/test2/sales/orders/${orderId}${focus}`;
  }
  return '/test2/sales/';
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const data = (event.notification.data || {}) as Record<string, string>;
  const url = deepLinkFromData(data);
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientsArr) => {
      const existing = clientsArr[0];
      if (existing) {
        return existing.focus().then(() => existing.navigate(url));
      }
      return self.clients.openWindow(url);
    }),
  );
});

self.addEventListener('message', (event) => {
  const payload = event.data as { type?: string; firebase?: Record<string, string> } | undefined;
  if (payload?.type !== 'FIREBASE_CONFIG' || !payload.firebase?.apiKey) return;
  try {
    const app = initializeApp(payload.firebase);
    const messaging = getMessaging(app);
    onBackgroundMessage(messaging, (message) => {
      const title = message.notification?.title || 'StarTech';
      const body = message.notification?.body || '';
      const data = (message.data || {}) as Record<string, string>;
      void self.registration.showNotification(title, {
        body,
        data,
        icon: '/test2/images/logo.png',
        badge: '/test2/images/logo.png',
      });
    });
  } catch {
    /* optional */
  }
});
