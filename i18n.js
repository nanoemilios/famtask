(function(){
  window._langData = {};
  window._lang = 'de';

  window.t = function(key, vars) {
    let val = window._langData[key];
    if (typeof val !== 'string') {
      const keys = key.split('.');
      val = window._langData;
      for (const k of keys) {
        if (val && typeof val === 'object' && k in val) val = val[k];
        else { val = key; break; }
      }
      if (typeof val !== 'string') val = key;
    }
    if (vars) {
      Object.entries(vars).forEach(([k,v]) => { val = val.replace('{'+k+'}', v); });
    }
    return val;
  };

  window.getLocale = function() {
    var map = { de:'de-CH', en:'en-US', es:'es-ES', fr:'fr-FR', it:'it-IT', pt:'pt-PT', zh:'zh-CN', ar:'ar-SA', hi:'hi-IN', ru:'ru-RU', ja:'ja-JP', ko:'ko-KR', tr:'tr-TR', pl:'pl-PL', nl:'nl-NL', id:'id-ID', vi:'vi-VN', bn:'bn-BD', th:'th-TH' };
    return map[window._lang] || 'de-CH';
  };

  window.loadLang = function(lang) {
    const script = document.createElement('script');
    script.src = 'lang/' + lang + '.js';
    script.onload = function() { window._lang = lang; document.documentElement.lang = lang; };
    document.head.appendChild(script);
  };
})();
