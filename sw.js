// Minimaler Service Worker: Registrierung soll funktionieren,
// ohne aggressive Caching-Logik (sicherer Quick-Fix).
self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// Bewusst kein Caching: Der leere Handler lässt jede Anfrage direkt ans Netz
// gehen, erfüllt aber die Installierbarkeits-Vorgabe der Browser (PWA).
self.addEventListener('fetch', () => {});
