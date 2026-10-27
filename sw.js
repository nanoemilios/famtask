// FamTask – Service Worker (Stufe 1 der Ladezeit-Optimierung)
//
// Strategie:
//   • Seitenaufruf (index.html): Netzwerk zuerst, bei Ausfall der Cache.
//     So kommen Updates an, offline startet die App trotzdem.
//   • App-Dateien (app.js, styles.css, i18n.js, lang/, vendor/, icons/):
//     Cache zuerst, im Hintergrund wird aufgefrischt.
//   • API-Endpunkte (api.php, jukebox.php, remote.php): niemals cachen.
//
// Bei jedem Release CACHE_VERSION erhoehen – dann wird der alte Cache geloescht.

const CACHE_VERSION = 'famtask-1.5.0-2';

// Grundgeruest ohne index.html (wird separat geholt, siehe precache()).
// app.js kommt mit seinem Inhalts-Hash dazu und wird aus index.html gelesen.
const SHELL = [
  'styles.css',
  'i18n.js',
  'lang/de.js',
  'manifest.json',
  'vendor/react.production.min.js',
  'vendor/react-dom.production.min.js',
  'vendor/fonts/fonts.css',
  'icons/favicon.svg',
  'icons/favicon-32x32.png',
  'icons/apple-touch-icon.png'
];

// Alles, was pro Aufruf frisch sein muss.
const NEVER_CACHE = /(?:api|jukebox|remote)\.php$/i;

// Dateien, die aus dem Cache kommen duerfen (Pfad ohne Host).
const CACHE_FIRST = /\/app\.js$|\/(?:styles\.css|i18n\.js|manifest\.json)$|\/(?:lang|vendor|icons)\//;

self.addEventListener('install', (event) => {
  event.waitUntil(precache().then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                    // Schreibvorgaenge nie abfangen

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;     // externe Quellen nicht anfassen
  if (NEVER_CACHE.test(url.pathname)) return;          // API immer direkt ans Netz
  if (url.searchParams.has('dev')) return;             // Entwicklungsmodus: nichts cachen

  if (req.mode === 'navigate') {
    event.respondWith(networkFirst(req));
    return;
  }
  if (CACHE_FIRST.test(url.pathname)) {
    event.respondWith(cacheFirst(event));
  }
});

// index.html holen, App-Hash daraus lesen und das Geruest einlagern.
async function precache() {
  const cache = await caches.open(CACHE_VERSION);
  const files = SHELL.slice();

  try {
    const page = await fetch('index.html', { cache: 'reload' });
    if (page.ok) {
      const html = await page.clone().text();
      await cache.put('index.html', page);
      const app = html.match(/app\.js\?v=[0-9a-f]+/i);
      if (app) files.push(app[0]);
    }
  } catch (e) { /* offline installieren: nur die festen Dateien */ }

  // Einzeln laden: eine fehlende Datei soll die Installation nicht kippen.
  await Promise.all(files.map(async (file) => {
    try {
      const res = await fetch(file, { cache: 'reload' });
      if (res.ok) await cache.put(file, res);
    } catch (e) { /* ignorieren */ }
  }));
}

async function networkFirst(req) {
  const cache = await caches.open(CACHE_VERSION);
  let res;
  try {
    res = await fetch(req);
  } catch (e) {
    return (await cache.match('index.html')) || Response.error();
  }
  if (res.ok) {
    await cache.put('index.html', res.clone());
    return res;
  }
  // 5xx (Server weg, Proxy-Fehler) wie offline behandeln – 404 dagegen durchreichen.
  if (res.status >= 500) {
    const cached = await cache.match('index.html');
    if (cached) return cached;
  }
  return res;
}

async function cacheFirst(event) {
  const req = event.request;
  const cache = await caches.open(CACHE_VERSION);
  const cached = await cache.match(req);

  const network = fetch(req)
    .then((res) => {
      if (res.ok && res.type === 'basic') cache.put(req, res.clone());
      return res;
    })
    .catch(() => null);

  if (cached) {
    try { event.waitUntil(network); } catch (e) { /* Ereignis schon beendet */ }
    return cached;
  }
  const res = await network;
  return res || Response.error();
}
