# Schriften

Selbst gehostete Kopien der Google Fonts **Fredoka One** und **Nunito**.
Beide stehen unter der SIL Open Font License 1.1.

- Quelle: <https://fonts.google.com/specimen/Fredoka+One>,
  <https://fonts.google.com/specimen/Nunito>
- Lizenz: <https://openfontlicense.org/open-font-license-official-text/>

Inhalt:

- `fredoka-one-400.woff2` – Fredoka One, Regular (fuer Wortmarke und
  Ueberschriften).
- `nunito-latin.woff2`, `nunito-latin-ext.woff2`, `nunito-cyrillic.woff2`,
  `nunito-cyrillic-ext.woff2`, `nunito-vietnamese.woff2` – Nunito-Subsets, wie
  sie Google Fonts ausliefert. Die Dateien decken die Gewichte 400 bis 900 ab.
- `fonts.css` – aus dem Google-Fonts-CSS abgeleitet. Die `unicode-range`-
  Angaben sorgen dafuer, dass der Browser nur die benoetigten Subsets laedt.

Beim Aktualisieren `fonts.css` neu aus dem Google-Fonts-CSS erzeugen und die
WOFF2-Dateien ersetzen. Keine Anfragen an Google zur Laufzeit - die Dateien
werden ausschliesslich lokal ausgeliefert.
