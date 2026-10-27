(function(){
  window._langData = {};
  // Deutsche Texte bleiben als Rueckfallbasis erhalten, wenn eine andere
  // Sprache einen Schluessel noch nicht uebersetzt hat. Ohne das zeigt die
  // Oberflaege rohe Schluessel wie "audio.for_child" an.
  window._langDataDe = {};
  window._lang = 'de';

  function lookup(data, key) {
    let val = data[key];
    if (typeof val === 'string') return val;
    const keys = key.split('.');
    val = data;
    for (const k of keys) {
      if (val && typeof val === 'object' && k in val) val = val[k];
      else return null;
    }
    return typeof val === 'string' ? val : null;
  }

  window.t = function(key, vars) {
    // Listen (z. B. pwa.step_share = [Icon, Titel, Untertitel]) unveraendert
    // zurueckgeben – lookup() liefert nur Textwerte.
    if (Array.isArray(window._langData[key])) return window._langData[key];
    if (Array.isArray(window._langDataDe[key])) return window._langDataDe[key];
    let val = lookup(window._langData, key);
    if (val === null) val = lookup(window._langDataDe, key);
    if (val === null) val = key;
    if (vars) {
      Object.entries(vars).forEach(([k,v]) => { val = val.replace('{'+k+'}', v); });
    }
    return val;
  };

  window.getLocale = function() {
    var map = { de:'de-CH', en:'en-US', es:'es-ES', fr:'fr-FR', it:'it-IT', pt:'pt-PT', zh:'zh-CN', ar:'ar-SA', hi:'hi-IN', ru:'ru-RU', ja:'ja-JP', ko:'ko-KR', tr:'tr-TR', pl:'pl-PL', nl:'nl-NL', id:'id-ID', vi:'vi-VN', bn:'bn-BD', th:'th-TH' };
    return map[window._lang] || 'de-CH';
  };

  function inject(lang, done) {
    const s = document.createElement('script');
    s.src = 'lang/' + lang + '.js';
    s.onload = done;
    s.onerror = done;          // Fehlende Datei darf die App nicht blockieren
    document.head.appendChild(s);
  }

  window.loadLang = function(lang) {
    const done = function() {
      window._lang = lang;
      document.documentElement.lang = lang;
    };
    // Deutsch zuerst laden, damit es als Rueckfallbasis bereitsteht
    if (lang !== 'de' && Object.keys(window._langDataDe).length === 0) {
      inject('de', function() {
        window._langDataDe = window._langData;
        window._langData = {};
        inject(lang, done);
      });
    } else {
      inject(lang, done);
    }
  };
})();
