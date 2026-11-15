/// <reference lib="webworker" />
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst } from 'workbox-strategies';

declare const self: ServiceWorkerGlobalScope;

self.addEventListener('install', () => {
  void self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

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
