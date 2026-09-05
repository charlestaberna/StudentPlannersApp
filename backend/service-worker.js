// Minimal service worker — its main job is to satisfy Chrome's install
// criteria. It caches static assets (CSS, icons) for speed, but leaves
// all .php pages to always hit the network, since they need a live DB
// connection (login, tasks, etc. can't really work offline anyway).

const CACHE = 'student-planner-static-v1';
const STATIC_ASSETS = [
  'assets/css/style.css',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Never cache PHP pages/API responses — always go to the network so
  // data (tasks, messages, etc.) is always fresh.
  if (url.pathname.endsWith('.php') || event.request.method !== 'GET') {
    return;
  }

  // Static assets: cache-first for speed.
  event.respondWith(
    caches.match(event.request).then((cached) => {
      return (
        cached ||
        fetch(event.request).then((response) => {
          const clone = response.clone();
          caches.open(CACHE).then((cache) => cache.put(event.request, clone));
          return response;
        })
      );
    })
  );
});
