#!/usr/bin/env node
/**
 * Baut app.js aus src/app.jsx.
 *
 * Verwendet Babel, das ohnehin im Repo liegt (vendor/babel.min.js) – es wird
 * nichts installiert und nichts heruntergeladen. Auf dem Webserver wird Node
 * nicht gebraucht: app.js ist eine fertige, statische Datei.
 *
 * Aufruf:
 *   node tools/build_app.mjs
 *   node tools/build_app.mjs --watch     # bei jeder Aenderung neu bauen
 */

import { createRequire } from 'node:module';
import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { gzipSync } from 'node:zlib';
import { createHash } from 'node:crypto';

const require = createRequire(import.meta.url);
const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const SOURCE = join(ROOT, 'src', 'app.jsx');
const TARGET = join(ROOT, 'app.js');
const INDEX = join(ROOT, 'index.html');
const BABEL = join(ROOT, 'vendor', 'babel.min.js');

const Babel = require(BABEL);

const HEADER = `/* FamTask – automatisch gebaut aus src/app.jsx.
 * Nicht direkt bearbeiten. Neu bauen mit: node tools/build_app.mjs
 * Ohne Build: index.html?dev=1 laedt src/app.jsx und uebersetzt es im Browser.
 */
`;

const kb = (bytes) => `${Math.round(bytes / 1024)} KB`;

async function build() {
  const started = Date.now();
  const code = await readFile(SOURCE, 'utf8');

  let out;
  try {
    out = Babel.transform(code, {
      presets: [['react', { runtime: 'classic' }]],
      filename: 'app.jsx',
      sourceType: 'script',
      compact: true,
      comments: false,
      babelrc: false,
      configFile: false,
    }).code;
  } catch (err) {
    console.error(`\nFehler in src/app.jsx:\n${err.message}\n`);
    process.exitCode = 1;
    return;
  }

  const body = HEADER + out + '\n';
  await writeFile(TARGET, body, 'utf8');

  // Cache-Busting: der Hash landet als ?v=… in index.html, damit nach einem
  // Update nicht die alte app.js aus dem Browser-Cache laeuft.
  const hash = createHash('sha1').update(body).digest('hex').slice(0, 8);
  const html = await readFile(INDEX, 'utf8');
  const updated = html.replace(
    /app\.src = 'app\.js(?:\?v=[0-9a-f]*)?';/,
    `app.src = 'app.js?v=${hash}';`
  );
  if (updated !== html) await writeFile(INDEX, updated, 'utf8');

  const gz = gzipSync(body, { level: 9 }).length;
  console.log(
    `app.js gebaut: ${kb(Buffer.byteLength(body))} (${kb(gz)} gzip) ` +
      `als app.js?v=${hash} in ${Date.now() - started} ms`
  );
}

await build();

if (process.argv.includes('--watch')) {
  const { watch } = await import('node:fs');
  console.log('Beobachte src/app.jsx – Strg+C beendet.');
  let timer = null;
  watch(SOURCE, () => {
    clearTimeout(timer);
    timer = setTimeout(build, 120);
  });
}
