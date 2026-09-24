// Minimaler Service Worker: Registrierung soll funktionieren,
// ohne aggressive Caching-Logik (sicherer Quick-Fix).
self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// Kein spezielles Fetch-Caching → Standardverhalten.
