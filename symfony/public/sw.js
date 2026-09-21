const CACHE_PREFIX = 'cos-pwa-shell-';
const CACHE_VERSION = 'v1';
const SHELL_CACHE = CACHE_PREFIX + CACHE_VERSION;
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/icons/cos-192.svg',
  '/icons/cos-512.svg',
  '/icons/cos-maskable.svg'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.addAll(PRECACHE_URLS))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => key.startsWith(CACHE_PREFIX) && key !== SHELL_CACHE)
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET' || request.mode !== 'navigate') {
    return;
  }

  event.respondWith(
    fetch(request).catch(async () => {
      const cache = await caches.open(SHELL_CACHE);
      return (await cache.match(OFFLINE_URL))
        ?? new Response('COS is offline.', {
          status: 503,
          headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        });
    })
  );
});
