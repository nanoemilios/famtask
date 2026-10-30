/* FamTask – Anwendungsquelle (JSX).
 *
 * Gebaut wird nach ../app.js:
 *   node tools/build_app.mjs
 *
 * Ohne Build laeuft die App auch direkt: index.html haengt ?dev=1 an die URL,
 * laedt dann vendor/babel.min.js und uebersetzt diese Datei im Browser.
 * Diese Datei ist die Quelle der Wahrheit – app.js nicht direkt bearbeiten.
 */

    const { useState, useEffect, useRef, useMemo } = React;

    // ─────────────────────────────────────────────────────────────────
    // 🗄️ MariaDB REST-API SYNC
    const API = 'api.php';

    // Family Code — aus localStorage laden, wird beim Login gesetzt
    function _getFC() { try { const fc=localStorage.getItem('ft_fc')||'FAM001'; return fc==='default'?'FAM001':fc; } catch(e){return 'FAM001';} }
    // Admin-PIN (nur fuer Server-Aufrufe, die Admin-Rechte brauchen)
    function _adminPin() { try { const v=storage.get('ft-pin'); return (v&&v.value)||'1234'; } catch(e){ return '1234'; } }
    function _apiUrl(action='') { const fc=_getFC(); return API+(action?'?action='+action+'&fc='+fc:'?fc='+fc); }

    // Externe Musikquellen (my/remote.php). Zugangsdaten bleiben auf dem
    // Server – remote.php gibt sie niemals zurück, nur hasPassword.
    function _remoteUrl(action) { return 'remote.php?action='+action+'&fc='+_getFC(); }
    async function _remotePost(action, body) {
      const r = await fetch(_remoteUrl(action), {
        method:'POST',
        headers:{'Content-Type':'application/json','X-Family-Code':_getFC(),'X-Admin-Pin':_adminPin()},
        body: JSON.stringify(body||{})
      });
      let d = null;
      try { d = await r.json(); } catch(e) {}
      return { ok:r.ok && d && d.ok!==false, status:r.status, data:d };
    }

    function _trackEvent(type, childId, childName, refId, refLabel, minutes=0) {
      const day = new Date().toISOString().split('T')[0];
      fetch(_apiUrl('track'), {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({day, child_id:childId, child_name:childName,
                              event_type:type, ref_id:refId, ref_label:refLabel, minutes})
      }).catch(()=>{}); // Fehler ignorieren — Statistik ist nicht kritisch
    }

    let _cache         = {};
    let _dirty         = false;
    let _saveTimer     = null;
    let _everLoaded    = false;   // true sobald einmal erfolgreich vom Server geladen
    let _failStreak    = 0;       // Anzahl aufeinanderfolgender Fehler
    let _coversBusy    = false;   // verhindert doppelte parallele Cover-Suchen
    let _tracksLoading = false;   // verhindert doppelte parallele Track-Ladungen
    const FAIL_THRESH  = 3;       // erst nach 3 Fehlern → Ampel Rot

    function _setSyncStatus(state, msg) {
      const light = document.getElementById('sync-light');
      const label = document.getElementById('sync-label');
      if (!light || !label) return;
      if (state === 'online') {
        light.style.background = '#6BCB77';
        light.style.boxShadow  = '0 0 7px #6BCB77';
        light.style.animation  = 'none';
        label.style.color      = '#6BCB77';
        label.textContent      = t('sync.online');
      } else if (state === 'offline') {
        light.style.background = '#FF6B6B';
        light.style.boxShadow  = '0 0 7px #FF6B6B';
        light.style.animation  = 'none';
        label.style.color      = '#FF6B6B';
        label.textContent      = msg || t('sync.offline');
      } else {
        light.style.background = '#FFB347';
        light.style.boxShadow  = '0 0 7px #FFB347';
        light.style.animation  = 'syncPulse 1s ease-in-out infinite';
        label.style.color      = '#FFB347';
        label.textContent      = msg || t('sync.connecting');
      }
    }

    // Sync-Button State — von React abonniert
    let _syncStateListeners = [];
    let _syncState = 'idle';
    const _setSyncState = (s, meta={}) => { _syncState=s; _syncStateListeners.forEach(fn=>fn(s,meta)); };
    window._subscribeSyncState = fn => { _syncStateListeners.push(fn); fn(_syncState,{}); };

    // localStorage
    const _lsKeys  = ["ft-ch","ft-ta","ft-co","ft-mu","ft-pin","ft-timers","ft-fi","ft-fh","ft-mt","ft-ev","ft-yt","ft-ml","ft-game-scores","ft-game-reward","ft-jb"];
    const _lsClear = () => { try { _lsKeys.forEach(k=>localStorage.removeItem("ft_"+k)); localStorage.removeItem("ft_ts"); } catch(e){} };
    const _lsSave  = () => {
      try {
        Object.entries(_cache).forEach(([k,v])=>localStorage.setItem("ft_"+k, typeof v==="string"?v:JSON.stringify(v)));
        localStorage.setItem("ft_ts", String(_cache['_ts']||0));
      } catch(e){}
    };
    const _lsLoad  = () => {
      if (_everLoaded) return;
      try { _lsKeys.forEach(k=>{const v=localStorage.getItem("ft_"+k);if(v)_cache[k]=v;}); } catch(e){}
      try { const t=localStorage.getItem("ft_ts");if(t)_cache['_ts']=parseInt(t)||0; } catch(e){}
    };

    window._famtaskClearLocalStorage = () => { _lsClear(); return true; };

    const storage = {
      get: (key) => { const v=_cache[key]; return v!==undefined?{value:typeof v==="string"?v:JSON.stringify(v)}:null; },
      set: (key, value) => {
        _cache[key]   = value;
        _cache['_ts'] = Date.now(); // Zeitstempel bei jeder Änderung
        _dirty = true;
        clearTimeout(_saveTimer);
        _saveTimer = setTimeout(()=>_pushData(), 800);
        return true;
      }
    };

    let _reloading       = false;
    let _lastPushedTs    = 0;  // was wir zuletzt zum Server geschickt haben
    let _lastSeenServerTs = 0; // was wir zuletzt vom Server gelesen haben

    async function _loadData(silent=false) {
      if (!silent) _setSyncStatus('connecting');
      try {
        const r = await fetch(_apiUrl(), { cache: 'no-store', headers: { 'Accept': 'application/json' } });
        if (r.ok) {
          const d = await r.json();
          if (d && d.error === 'not_configured') { _setSyncStatus('offline',t('sync.not_configured')); return false; }
          if (_dirty) { _failStreak=0; _setSyncStatus('online'); return false; }
          const serverTs = parseInt(d['_ts'] || 0);
          // Reload nur wenn Server NACH unserem letzten Lesen neuer wurde → anderes Gerät hat geändert
          const isNewFromOtherDevice = serverTs > _lastSeenServerTs;
          _lastSeenServerTs = serverTs; // immer aktualisieren
          if (isNewFromOtherDevice) _cache = d || {};
          _everLoaded=true; _failStreak=0; _setSyncStatus('online');
          return isNewFromOtherDevice;
        }
        _failStreak++;
        if (_failStreak>=FAIL_THRESH) _setSyncStatus('offline',t('sync.server_error',{code:r.status}));
      } catch(e) {
        _failStreak++;
        if (_failStreak>=FAIL_THRESH) _setSyncStatus('offline', navigator.onLine?t('sync.server_unreachable'):t('sync.no_internet'));
        _lsLoad();
      }
      return false;
    }

    async function _pushData() {
      if (!_dirty) return;
      try {
        const r = await fetch(_apiUrl(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(_cache)});
        if (r.ok) {
          const pushed = parseInt(_cache['_ts'] || 0);
          _dirty=false; _failStreak=0;
          _lastPushedTs    = pushed;
          _lastSeenServerTs = pushed; // Server hat jetzt unseren Stand
          _setSyncStatus('online'); _lsSave();
        } else {
          _failStreak++; if(_failStreak>=FAIL_THRESH) _setSyncStatus('offline',t('sync.save_failed'));
        }
      } catch(e) {
        _failStreak++;
        if (_failStreak>=FAIL_THRESH) _setSyncStatus('offline', navigator.onLine?t('sync.server_unreachable'):t('sync.offline'));
        _lsSave();
      }
    }

    // ── Manueller Sync mit Zeitstempel-Vergleich ──────────────────────────────
    window._famtaskManualSync = async () => {
      _setSyncState('syncing');
      const fmtTs = ts => ts ? new Date(parseInt(ts)).toLocaleTimeString(getLocale(),{hour:'2-digit',minute:'2-digit',second:'2-digit'}) : '–';
      try {
        const r = await fetch(_apiUrl(), { cache: 'no-store', headers: { 'Accept': 'application/json' } });
        const serverData = await r.json();
        if (serverData?.error === 'not_configured') throw new Error('API nicht konfiguriert');

        const serverTs = parseInt(serverData['_ts'] || 0);
        const localTs  = parseInt(_cache['_ts']    || 0);
        const diff     = Math.abs(serverTs - localTs);

        if (diff < 2000) {
          // Identisch (< 2s Unterschied)
          _failStreak=0; _setSyncStatus('online');
          _setSyncState('ok', { msg:t('sync.already_synced'), detail:t('sync.detail_identical',{ts:fmtTs(serverTs||localTs)}) });

        } else if (serverTs > localTs) {
          _cache=serverData; _everLoaded=true; _dirty=false; _failStreak=0;
          _lastPushedTs=serverTs; _lastSeenServerTs=serverTs;
          _lsSave(); _setSyncStatus('online');
          if (window._famtaskReload) window._famtaskReload();
          _setSyncState('ok', {
            msg: t('sync.server_data_taken'),
            detail: t('sync.detail_server_won',{serverTs:fmtTs(serverTs),localTs:fmtTs(localTs),diff:Math.round(diff/1000)}),
            winner: 'server'
          });
        } else {
          const pushR = await fetch(_apiUrl(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(_cache)});
          if (!pushR.ok) throw new Error('Upload HTTP ' + pushR.status);
          _dirty=false; _failStreak=0;
          _lastPushedTs=localTs; _lastSeenServerTs=localTs;
          _lsSave(); _setSyncStatus('online');
          _setSyncState('ok', {
            msg: t('sync.local_data_uploaded'),
            detail: t('sync.detail_local_won',{localTs:fmtTs(localTs),serverTs:fmtTs(serverTs),diff:Math.round(diff/1000)}),
            winner: 'local'
          });
        }
      } catch(e) {
        _setSyncStatus('offline',t('sync.sync_failed'));
        _setSyncState('error', { msg:'❌ '+t('sync.sync_failed'), detail: e.message });
      }
    };

    // iCal-Refresh alle 15 Min (silent)
    setInterval(async () => {
      try {
        const ir = await fetch(_apiUrl('ical'),{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
        if(ir.ok){const id=await ir.json();if(id.ok&&id.events?.length&&window._famtaskSetIcal)window._famtaskSetIcal(id.events);}
      } catch(e){}
    }, 15*60*1000);
    // Hintergrund-Polling alle 8s — silent
    setInterval(async () => {
      if (_dirty) return;
      const ok = await _loadData(true);
      if (ok && window._famtaskReload) window._famtaskReload();
    }, 8000);

    window.addEventListener('online',  () => _loadData(false));
    window.addEventListener('offline', () => { _failStreak=FAIL_THRESH; _setSyncStatus('offline',t('sync.no_internet')); });

    const COLORS = ["#FF6B6B","#4ECDC4","#A78BFA","#FFB347","#6BCB77","#74B9FF","#FD79A8","#FF8E53"];
    const EMOJIS = ["⭐","🦁","🐯","🦊","🐻","🐼","🦄","🐸","🤖","👾","🦋","🐬","🎃","🦕","🐲"];
    const TASK_ICONS = ["📚","🧹","🍽️","🦷","🛏️","🐕","🌱","🧼","🏃","🎨","📖","💪","🚲","🎵","🧺","🗑️","🐠","🌮","🛁","🥗"];
    const DAYS_S     = [t('days.sun'),t('days.mon'),t('days.tue'),t('days.wed'),t('days.thu'),t('days.fri'),t('days.sat')];
    const DAYS_MON   = [t('days.mon'),t('days.tue'),t('days.wed'),t('days.thu'),t('days.fri'),t('days.sat'),t('days.sun')];
    const DAYS_MON_I = [1,2,3,4,5,6,0];                     // JS-Indices Mo-first
    const MEDIA_COLORS = ["#FF6B6B","#4ECDC4","#A78BFA","#FFB347","#6BCB77","#74B9FF","#FD79A8","#FF8E53"];
    const MEDIA_ICONS  = ["📺","📱","🎮","💻","🎵","📷","🕹️","🎲","📻","🎬","🖥️","⌚"];
    const DEFAULT_MEDIA_TYPES = [
      {id:"tv",       label:"TV",       icon:"📺", color:"#FF6B6B", bg:"rgba(255,107,107,0.15)", carryOver:true },
      {id:"tablet",   label:"Tablet",   icon:"📱", color:"#4ECDC4", bg:"rgba(78,205,196,0.15)",  carryOver:true },
      {id:"nintendo", label:"Nintendo", icon:"🎮", color:"#A78BFA", bg:"rgba(167,139,250,0.15)", carryOver:false},
    ];
    const TIMER_PRESETS = [
      {label:t('timer.preset_5min'), secs:300},{label:t('timer.preset_10min'),secs:600},{label:t('timer.preset_15min'),secs:900},{label:t('timer.preset_20min'),secs:1200},
      {label:t('timer.preset_30min'),secs:1800},{label:t('timer.preset_45min'),secs:2700},{label:t('timer.preset_1hour'), secs:3600},{label:t('timer.preset_2hours'), secs:7200},
    ];

    const GAME_EMOJIS = ['🍎','🎈','🎲','🎨','🎵','⚽','🦋','🐠','🍕','🎁','🌺','🦒','🎪','🎭','🎸','⭐','🌈','🐱','🌳','💧','☀️','📖','🏫','🐶','🌸','🍀','🌙','⚡','🎃','👻','🤖'];
    const WORD_LISTS = {
      leicht:[
        {word:'APFEL',meaning:'Eine rote oder grüne Frucht',emoji:'🍎',opts:['Frucht','Auto','Baum']},
        {word:'KATZE',meaning:'Ein flauschiges Haustier',emoji:'🐱',opts:['Tier','Pflanze','Farbe']},
        {word:'BAUM',meaning:'Groß mit Stamm und Blättern',emoji:'🌳',opts:['Pflanze','Tier','Stein']},
        {word:'WASSER',meaning:'Du trinkst das jeden Tag',emoji:'💧',opts:['Flüssigkeit','Gas','Stein']},
        {word:'SONNE',meaning:'Scheint tagsüber am Himmel',emoji:'☀️',opts:['Stern','Planet','Mond']},
        {word:'BUCH',meaning:'Zum Lesen mit vielen Seiten',emoji:'📖',opts:['Etwas zum Lesen','Spielzeug','Werkzeug']},
        {word:'SCHULE',meaning:'Ort zum Lernen',emoji:'🏫',opts:['Lernort','Spielplatz','Haus']},
        {word:'FISCH',meaning:'Lebt im Wasser',emoji:'🐠',opts:['Tier','Pflanze','Farbe']},
      ],mittel:[
        {word:'FREUNDSCHAFT',meaning:'Die Beziehung zwischen guten Freunden',emoji:'👫',opts:['Beziehung','Spiel','Farbe']},
        {word:'FANTASTISCH',meaning:'Sehr gut oder wunderbar',emoji:'⭐',opts:['Wunderbar','Schlecht','Langweilig']},
        {word:'ABENTEUER',meaning:'Eine spannende Reise',emoji:'🗺️',opts:['Reise','Ruhe','Essen']},
        {word:'TRAUM',meaning:'Was du nachts hast',emoji:'💭',opts:['Gedanke','Farbe','Baum']},
        {word:'MUSIK',meaning:'Mit Noten und Melodien',emoji:'🎵',opts:['Kunst','Sport','Gemüse']},
        {word:'GEHEIMNIS',meaning:'Etwas das nur wenige wissen',emoji:'🤫',opts:['Geheim','Laut','Hell']},
        {word:'HOFFNUNG',meaning:'Der Glaube dass etwas gut wird',emoji:'🌈',opts:['Glaube','Angst','Wut']},
        {word:'KREATIVITÄT',meaning:'Schöpferische Fähigkeit',emoji:'🎨',opts:['Schöpfung','Mathematik','Sport']},
      ],schwer:[
        {word:'VERANTWORTUNG',meaning:'Die Pflicht sich um etwas zu kümmern',emoji:'🎯',opts:['Pflicht','Spaß','Angst']},
        {word:'GEDULD',meaning:'Ruhig warten können',emoji:'⏳',opts:['Wartet ruhig','Schnell handeln','Laut sprechen']},
        {word:'MITGEFÜHL',meaning:'Sich in andere hineinversetzen',emoji:'❤️',opts:['Einfühlung','Distanz','Ignoranz']},
        {word:'PERSPEKTIVE',meaning:'Der Sichtwinkel oder die Ansicht',emoji:'👁️',opts:['Sichtweise','Farbe','Geschmack']},
        {word:'TOLERANZ',meaning:'Die Akzeptanz von Unterschieden',emoji:'🤝',opts:['Akzeptanz','Strafe','Regeln']},
        {word:'AUTHENTISCH',meaning:'Echt und ursprünglich',emoji:'✨',opts:['Echt','Falsch','Bunt']},
        {word:'NOSTALGIE',meaning:'Sehnsucht nach der Vergangenheit',emoji:'🕰️',opts:['Erinnerung','Zukunft','Gegenwart']},
        {word:'DISZIPLIN',meaning:'Selbstbeherrschung und Ausdauer',emoji:'💪',opts:['Selbstbeherrschung','Chaos','Freiheit']},
      ]
    };
    const GAME_AGE_MAP = [6,9];
    const GEO_FLAGS = [
      {emoji:'🇩🇪',name:'Deutschland'},{emoji:'🇫🇷',name:'Frankreich'},{emoji:'🇮🇹',name:'Italien'},
      {emoji:'🇪🇸',name:'Spanien'},{emoji:'🇬🇧',name:'England'},{emoji:'🇺🇸',name:'USA'},
      {emoji:'🇯🇵',name:'Japan'},{emoji:'🇨🇳',name:'China'},{emoji:'🇧🇷',name:'Brasilien'},
      {emoji:'🇦🇺',name:'Australien'},{emoji:'🇨🇦',name:'Kanada'},{emoji:'🇲🇽',name:'Mexiko'},
      {emoji:'🇮🇳',name:'Indien'},{emoji:'🇿🇦',name:'Südafrika'},{emoji:'🇸🇪',name:'Schweden'},
      {emoji:'🇳🇴',name:'Norwegen'},{emoji:'🇬🇷',name:'Griechenland'},{emoji:'🇹🇷',name:'Türkei'},
      {emoji:'🇦🇷',name:'Argentinien'},{emoji:'🇪🇬',name:'Ägypten'}
    ];
    const HANGMAN_WORDS = {
      leicht: ['BAUM','HAUS','SONNE','TIER','FISCH','BLUME','KATZE','HUND','WOLKE','REGEN','BALL','BUCH','APFEL','MILCH','BROT','NASE'],
      mittel: ['SCHULE','GARTEN','WASSER','STEIN','TÜR','FENSTER','PAPIER','STIFTE','ZAHLEN','BILDER','LAMPE','DACH','RADIO','DUSCHE'],
      schwer: ['ELEFANT','GESCHENK','SCHOKOLADE','ABENTEUER','APOTHEKE','FLUGZEUG','KROKODIL','TASCHENLAMPE','SPIEGEL','FAHRRAD','WERKSTATT','KASTEN']
    };
    const OPPOSITES = [
  {word:'groß',answer:'klein',opts:['klein','dick','weit','hoch']},{word:'schnell',answer:'langsam',opts:['langsam','leise','kurz','dünn']},
  {word:'hell',answer:'dunkel',opts:['dunkel','kalt','schwer','rund']},{word:'heiß',answer:'kalt',opts:['kalt','warm','nass','weich']},
  {word:'dick',answer:'dünn',opts:['dünn','klein','groß','lang']},{word:'laut',answer:'leise',opts:['leise','hart','bunt','süß']},
  {word:'hart',answer:'weich',opts:['weich','tief','kurz','leicht']},{word:'hoch',answer:'tief',opts:['tief','schmal','schwer','breit']},
  {word:'süß',answer:'sauer',opts:['sauer','bitter','salzig','scharf']},{word:'leicht',answer:'schwer',opts:['schwer','hell','schnell','rund']},
  {word:'jung',answer:'alt',opts:['alt','neu','frisch','stark']},{word:'reich',answer:'arm',opts:['arm','dick','groß','stark']},
  {word:'sauber',answer:'schmutzig',opts:['schmutzig','nass','kalt','leer']},{word:'mutig',answer:'feige',opts:['feige','klug','fleißig','schnell']},
  {word:'offen',answer:'geschlossen',opts:['geschlossen','frei','leer','nass']},{word:'voll',answer:'leer',opts:['leer','weit','tief','breit']},
  {word:'kurz',answer:'lang',opts:['lang','dünn','klein','tief']},{word:'nah',answer:'fern',opts:['fern','weit','groß','hoch']},
];
const getGameLevel = (c) => {
  if (c && c.gameLevel && c.gameLevel >= 1 && c.gameLevel <= 3) return c.gameLevel;
  if (!c || !c.birthday) return 2;
  const age = Math.floor((Date.now()-new Date(c.birthday))/31557600000);
  return age <= 6 ? 1 : age <= 9 ? 2 : 3;
};
const getGameDiff = (c) => ['leicht','mittel','schwer'][getGameLevel(c)-1];
const ANIMALS = [
  {emoji:'🐶',name:'Hund'},{emoji:'🐱',name:'Katze'},{emoji:'🐰',name:'Hase'},{emoji:'🐻',name:'Bär'},
  {emoji:'🦊',name:'Fuchs'},{emoji:'🐸',name:'Frosch'},{emoji:'🦁',name:'Löwe'},{emoji:'🐯',name:'Tiger'},
  {emoji:'🐮',name:'Kuh'},{emoji:'🐷',name:'Schwein'},{emoji:'🐒',name:'Affe'},{emoji:'🦄',name:'Einhorn'},
  {emoji:'🐧',name:'Pinguin'},{emoji:'🦉',name:'Eule'},{emoji:'🐢',name:'Schildkröte'},{emoji:'🐍',name:'Schlange'},
  {emoji:'🐙',name:'Oktopus'},{emoji:'🦈',name:'Hai'},{emoji:'🐬',name:'Delfin'},{emoji:'🦋',name:'Schmetterling'},
  {emoji:'🐝',name:'Biene'},{emoji:'🐌',name:'Schnecke'},{emoji:'🦀',name:'Krabbe'},{emoji:'🐳',name:'Wal'},
];
    const uid      = () => Math.random().toString(36).slice(2,9);
    const todayStr = () => new Date().toISOString().split("T")[0];
   
    
    const getDs    = (d) => (d instanceof Date ? d : new Date(d+"T00:00:00")).toISOString().split("T")[0];
    const darken   = (hex,a=50) => { const c=(_,i)=>Math.max(0,parseInt(hex.slice(i,i+2),16)-a).toString(16).padStart(2,"0"); return `#${c(0,1)}${c(0,3)}${c(0,5)}`; };
    const fmtMM    = (s) => { if(s<=0)return"00:00"; const h=Math.floor(s/3600),m=Math.floor((s%3600)/60),ss=s%60; return h>0?`${String(h).padStart(2,"0")}:${String(m).padStart(2,"0")}:${String(ss).padStart(2,"0")}`:`${String(m).padStart(2,"0")}:${String(ss).padStart(2,"0")}`; };
    const h2r      = h => `${parseInt(h.slice(1,3),16)},${parseInt(h.slice(3,5),16)},${parseInt(h.slice(5,7),16)}`;
    // ── Geld (CHF) — Beträge werden in Rappen (Integer) gespeichert ──────────
    const CHF_LOG_MAX = 500;
    const fmtCHF   = c => ((c||0)/100).toLocaleString(getLocale(),{minimumFractionDigits:2,maximumFractionDigits:2})+" CHF";
    const parseCHF = s => Math.max(0, Math.round(parseFloat(String(s??"").replace(",","."))*100) || 0);
    // ISO-Kalenderwoche ("2026-W42") – Basis für die automatische Wochenvergütung.
    const weekKey = d => {
      const t=new Date(Date.UTC(d.getFullYear(),d.getMonth(),d.getDate())),dow=t.getUTCDay()||7;
      t.setUTCDate(t.getUTCDate()+4-dow);
      const y0=new Date(Date.UTC(t.getUTCFullYear(),0,1));
      return t.getUTCFullYear()+"-W"+String(Math.ceil(((t-y0)/86400000+1)/7)).padStart(2,"0");
    };

    const DEFAULT_CHILDREN = [
      {id:"c1",name:"Santiago",color:"#FF6B6B",emoji:"🦁",earnedMinutes:0,pocket:0,weeklyPocket:500,birthday:"",gameLevel:0,
        mediaLimits:{tv:60,tablet:45,nintendo:60},
        mediaWeeklyLimits:{tv:0,tablet:0,nintendo:0},
        mediaCarryover:{tv:false,tablet:false,nintendo:false},
        mediaDays:{tv:[1,2,3,4,5,6,0],tablet:[1,2,3,4,5,6,0],nintendo:[1,2,3,4,5,6,0]}},
      {id:"c2",name:"Samuel",  color:"#4ECDC4",emoji:"🦊",earnedMinutes:0,pocket:0,weeklyPocket:500,birthday:"",gameLevel:0,
        mediaLimits:{tv:60,tablet:45,nintendo:60},
        mediaWeeklyLimits:{tv:0,tablet:0,nintendo:0},
        mediaCarryover:{tv:false,tablet:false,nintendo:false},
        mediaDays:{tv:[1,2,3,4,5,6,0],tablet:[1,2,3,4,5,6,0],nintendo:[1,2,3,4,5,6,0]}},
    ];
    const DEFAULT_TASKS = [
      {id:"t1",title:t('default.task.clean_room'),icon:"🧹",assignedTo:["c1","c2"],minutesReward:15,minutesPenalty:10,dueTime:"18:00",repeatDays:[1,2,3,4,5]},
      {id:"t2",title:t('default.task.homework'),    icon:"📚",assignedTo:["c1","c2"],minutesReward:20,minutesPenalty:15,dueTime:"17:00",repeatDays:[1,2,3,4,5]},
      {id:"t3",title:t('default.task.brush_teeth'),    icon:"🦷",assignedTo:["c1","c2"],minutesReward:5, minutesPenalty:5, dueTime:"20:30",repeatDays:[0,1,2,3,4,5,6]},
      {id:"t4",title:t('default.task.set_table'),    icon:"🍽️",assignedTo:["c1"],    minutesReward:10,minutesPenalty:5, dueTime:"12:00",repeatDays:[0,1,2,3,4,5,6]},
    ];
    const DEFAULT_FINES = [
      {id:"f1",title:t('default.fine.lying'),        icon:"🤥",assignedTo:["c1","c2"],minutesPenalty:15,moneyPenalty:0},
      {id:"f2",title:t('default.fine.slamming_doors'),icon:"🚪",assignedTo:["c1","c2"],minutesPenalty:10,moneyPenalty:0},
      {id:"f3",title:t('default.fine.bad_word'),  icon:"🤬",assignedTo:["c1","c2"],minutesPenalty:20,moneyPenalty:0},
    ];
    const FINE_ICONS = ["🤥","🚪","🤬","😤","🗣️","👊","🙈","🤦","😠","🔇","📱","🍭","💤","🤷","👋","🤚","😒","🧹","🚫","⚠️"];

    let _alarmCtx = null;

    function stopAlarmSound() {
      if (_alarmCtx) { try { _alarmCtx.close(); } catch(e){} _alarmCtx = null; }
    }

    function playAlarmSound() {
      stopAlarmSound();
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        _alarmCtx = ctx;
        const now = ctx.currentTime;

        // Phase 1: Melodie wiederholen für 55 Sekunden
        const notes   = [523.25, 659.25, 783.99, 1046.5];
        const dur = 0.22, gap = 0.08;
        const cycleLen = notes.length * (dur + gap) + 0.25;
        const phase1End = now + 55;
        let t = now;
        while (t < phase1End) {
          notes.forEach((freq, i) => {
            const nt = t + i * (dur + gap);
            if (nt + dur > phase1End) return;
            const osc = ctx.createOscillator(), gain = ctx.createGain();
            osc.connect(gain); gain.connect(ctx.destination);
            osc.type = "sine"; osc.frequency.value = freq;
            gain.gain.setValueAtTime(0, nt);
            gain.gain.linearRampToValueAtTime(0.45, nt + 0.03);
            gain.gain.setValueAtTime(0.45, nt + dur - 0.04);
            gain.gain.linearRampToValueAtTime(0, nt + dur);
            osc.start(nt); osc.stop(nt + dur + 0.01);
          });
          t += cycleLen;
        }

        // Phase 2: 5-Sekunden lauter Sirenen-Schlusston (55s bis 60s)
        const warnStart = now + 55;
        [880, 1100].forEach((freq, idx) => {
          const osc  = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.connect(gain); gain.connect(ctx.destination);
          osc.type = "sawtooth";
          osc.frequency.setValueAtTime(freq,        warnStart);
          osc.frequency.linearRampToValueAtTime(freq * 1.12, warnStart + 1.2);
          osc.frequency.linearRampToValueAtTime(freq,        warnStart + 2.4);
          osc.frequency.linearRampToValueAtTime(freq * 1.12, warnStart + 3.6);
          osc.frequency.linearRampToValueAtTime(freq,        warnStart + 4.8);
          gain.gain.setValueAtTime(0, warnStart);
          gain.gain.linearRampToValueAtTime(idx === 0 ? 0.65 : 0.45, warnStart + 0.15);
          gain.gain.setValueAtTime(idx === 0 ? 0.65 : 0.45, warnStart + 4.7);
          gain.gain.linearRampToValueAtTime(0, warnStart + 5.0);
          osc.start(warnStart); osc.stop(warnStart + 5.1);
        });

        // Automatisch nach 60 Sekunden stoppen
        setTimeout(() => { if (_alarmCtx === ctx) stopAlarmSound(); }, 61000);

      } catch(e) {}
    }




    // ─────────────────────────────────────────────────────────────────────────
    // 🖼️ Bild-Helper — hier auf Modul-Ebene, damit Modals ihn referenzieren können
    // Liest eine Bilddatei, skaliert sie auf max. 400px und gibt JPEG-DataURL zurück
    function readImg(file, cb) {
      if (!file) return;
      const r = new FileReader();
      r.onload = e => {
        const img = new Image();
        img.onload = () => {
          const c = document.createElement('canvas'), max = 400;
          let w = img.width, h = img.height;
          if (w > h) { if (w > max) { h = h * max / w; w = max; } }
          else        { if (h > max) { w = w * max / h; h = max; } }
          c.width = w; c.height = h;
          c.getContext('2d').drawImage(img, 0, 0, w, h);
          cb(c.toDataURL('image/jpeg', 0.75));
        };
        img.src = e.target.result;
      };
      r.readAsDataURL(file);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ⚠️ WARUM stehen diese Modals hier draussen?
    //
    // Wenn eine Komponente INNERHALB einer anderen Komponente definiert ist
    // (z.B. const TaskModal = () => ... innerhalb von FamTask), erstellt React
    // bei jedem Re-Render eine NEUE Funktionsreferenz. React sieht das als
    // komplett neuen Komponenten-Typ → unmount + remount → Eingabefeld verliert
    // den Fokus nach jedem Buchstaben.
    //
    // Lösung: Modals mit Textfeldern als eigene Top-Level-Komponenten definieren
    // und die benötigten Werte als Props übergeben. Dann bleibt die Komponenten-
    // Identität stabil und der Fokus bleibt erhalten.
    // ─────────────────────────────────────────────────────────────────────────

    // ── Kind bearbeiten / hinzufügen ─────────────────────────────────────────
    function ChildModal({editChild, setEditChild, onSave, mediaTypes}) {
      if (!editChild) return null;
      const isNew = !editChild.id;
      const u  = (k,v) => setEditChild(p => ({...p, [k]: v}));
      const ul = (k,v) => setEditChild(p => ({...p, mediaLimits: {...p.mediaLimits, [k]: parseInt(v)||0}}));
      const uc = (k,rawField,v) => setEditChild(p => ({...p, [k]: parseCHF(v), [rawField]: v}));
      return (
        <div className="ft-overlay" onClick={e=>e.target===e.currentTarget&&setEditChild(null)}>
          <div className="ft-modal">
            <div className="ft-modal-title">{isNew?t('child.add'):t('child.edit')}</div>
            <div className="ft-field"><label className="ft-lbl">{t('common.photo')}</label>
              <div className="ft-img-upload" onClick={()=>document.getElementById('child-img-in').click()}>
                {editChild.image?<img src={editChild.image} className="ft-img-preview" alt=""/>:<div className="ft-img-placeholder">🧒</div>}
                <div className="ft-img-info">                <div className="ft-img-title">{t('common.upload_photo')}</div><div className="ft-img-sub">{t('common.jpg_or_png')}</div>{editChild.image&&<span className="ft-img-remove" onClick={e=>{e.stopPropagation();u('image',null);}}>✕ {t('common.remove')}</span>}</div>
              </div>
              <input id="child-img-in" type="file" accept="image/*" style={{display:'none'}} onChange={e=>readImg(e.target.files[0],img=>u('image',img))}/>
            </div>
            <div className="ft-field"><label className="ft-lbl">{t('common.name')}</label><input className="ft-input" aria-label={t('common.name')} value={editChild.name} onChange={e=>u("name",e.target.value)} placeholder={t('common.name')} autoFocus={isNew}/></div>
            <div className="ft-field"><label className="ft-lbl">{t('child.emoji_fallback')}</label><div className="ft-emoji-grid">{EMOJIS.map(e=><div key={e} className={`ft-emoji-opt ${editChild.emoji===e?"sel":""}`} onClick={()=>u("emoji",e)}>{e}</div>)}</div></div>
            <div className="ft-field"><label className="ft-lbl">{t('common.color')}</label><div className="ft-col-grid">{COLORS.map(c=><div key={c} className={`ft-col-sw ${editChild.color===c?"sel":""}`} style={{background:c}} onClick={()=>u("color",c)}/>)}</div></div>
            <div className="ft-field"><label className="ft-lbl">{t('child.birthday')}</label><input className="ft-input" aria-label={t('child.birthday')} type="date" value={editChild.birthday||""} onChange={e=>u("birthday",e.target.value)}/></div>
            <div className="ft-field"><label className="ft-lbl">{t('games.title')}</label>
              <div style={{display:'flex',gap:5}}>
                {[{v:0,l:t('games.difficulty_auto')},{v:1,l:t('games.difficulty_easy')},{v:2,l:t('games.difficulty_medium')},{v:3,l:t('games.difficulty_hard')}].map(({v,l}) => (
                  <button key={v} type="button" onClick={()=>u("gameLevel",v)} style={{
                    flex:1,padding:'8px 4px',borderRadius:8,border:'2px solid '+(editChild.gameLevel===v?'#4ECDC4':'rgba(255,255,255,0.1)'),
                    background:editChild.gameLevel===v?'rgba(78,205,196,0.12)':'rgba(255,255,255,0.04)',
                    color:editChild.gameLevel===v?'#4ECDC4':'rgba(255,255,255,0.68)',
                    fontFamily:'inherit',fontSize:11,fontWeight:800,cursor:'pointer'
                  }}>{l}</button>
                ))}
              </div>
              <div style={{fontSize:10,color:'rgba(255,255,255,0.6)',marginTop:4}}>{t('games.difficulty_auto')} = {t('child.game_difficulty_auto')}</div>
            </div>
            <div className="ft-field"><label className="ft-lbl">{t('child.media_limits')}</label>
              {mediaTypes.map(m=>{
                const days=editChild.mediaDays?.[m.id]??[0,1,2,3,4,5,6];
                const togDay=i=>{const cur=editChild.mediaDays??{};const nd=(cur[m.id]||[0,1,2,3,4,5,6]).includes(i)?(cur[m.id]||[0,1,2,3,4,5,6]).filter(x=>x!==i):[...(cur[m.id]||[0,1,2,3,4,5,6]),i].sort((a,b)=>a-b);u("mediaDays",{...cur,[m.id]:nd});};
                return(<div key={m.id} className="ft-field" style={{background:"rgba(255,255,255,0.03)",borderRadius:11,padding:"10px 12px",marginBottom:6}}>
                  <div style={{display:"flex",alignItems:"center",gap:8,marginBottom:8}}>
                    <span style={{fontSize:16}}>{m.icon}</span>
                    <label className="ft-lbl" style={{margin:0,flex:1}}>{m.label}</label>
                    <input className="ft-input" aria-label={m.label} type="number" value={editChild.mediaLimits?.[m.id]||0} onChange={e=>ul(m.id,e.target.value)} min={0} style={{width:70,textAlign:"center",padding:"5px 8px"}}/>
                    <span style={{fontSize:11,color:"rgba(255,255,255,0.62)",fontWeight:700}}>{t('common.minutes_short')}</span>
                  </div>
                  <div style={{display:"flex",gap:3}}>{DAYS_MON.map((d,di)=>{const i=DAYS_MON_I[di];return<button key={i} type="button" onClick={()=>togDay(i)} style={{flex:1,padding:"5px 2px",background:days.includes(i)?"rgba(255,255,255,0.12)":"transparent",border:days.includes(i)?'1.5px solid '+m.color:"1.5px solid rgba(255,255,255,0.1)",borderRadius:7,color:days.includes(i)?"#fff":"rgba(255,255,255,0.25)",fontSize:10,fontWeight:800,cursor:"pointer"}}>{d}</button>})}</div>
                </div>);
              })}
            </div>
            {isNew&&<div className="ft-field"><label className="ft-lbl">{t('child.start_minutes')}</label><input className="ft-input" aria-label={t('child.start_minutes')} type="number" value={editChild.earnedMinutes} onChange={e=>u("earnedMinutes",parseInt(e.target.value)||0)} min={0}/></div>}
            <div className="ft-row">
              <div className="ft-field"><label className="ft-lbl">{t('child.weekly_pocket')}</label><input className="ft-input" aria-label={t('child.weekly_pocket')} type="number" step="0.01" inputMode="decimal" value={editChild.pocketRaw??(((editChild.weeklyPocket??500)/100).toFixed(2))} onChange={e=>uc("weeklyPocket","pocketRaw",e.target.value)} placeholder="5.00"/></div>
              {isNew&&<div className="ft-field"><label className="ft-lbl">{t('child.start_pocket')}</label><input className="ft-input" aria-label={t('child.start_pocket')} type="number" step="0.01" inputMode="decimal" value={editChild.startPocketRaw??(((editChild.pocket||0)/100).toFixed(2))} onChange={e=>uc("pocket","startPocketRaw",e.target.value)} placeholder="0.00"/></div>}
            </div>
            <div className="ft-modal-acts">
              <button className="ft-btn ft-btn-sec" onClick={()=>setEditChild(null)}>{t('common.cancel')}</button>
              <button className="ft-btn ft-btn-pri" onClick={()=>editChild.name.trim()&&onSave(editChild)}>{t('common.save')}</button>
            </div>
          </div>
        </div>
      );
    }

    // ── Aufgabe bearbeiten / hinzufügen ──────────────────────────────────────
    function TaskModal({editTask, setEditTask, onSave, childrenList}) {
      if (!editTask) return null;
      const isNew     = !editTask.id;
      const u         = (k,v) => setEditTask(p => ({...p, [k]: v}));
      const togDay    = d => u("repeatDays", editTask.repeatDays.includes(d) ? editTask.repeatDays.filter(x=>x!==d) : [...editTask.repeatDays, d]);
      const togAssign = cid => u("assignedTo", editTask.assignedTo.includes(cid) ? editTask.assignedTo.filter(x=>x!==cid) : [...editTask.assignedTo, cid]);
      return (
        <div className="ft-overlay" onClick={e=>e.target===e.currentTarget&&setEditTask(null)}>
          <div className="ft-modal">
            <div className="ft-modal-title">{isNew?t('task.add'):t('task.edit')}</div>
            <div className="ft-field"><label className="ft-lbl">{t('common.image')}</label>
              <div className="ft-img-upload" onClick={()=>document.getElementById('task-img-in').click()}>
                {editTask.image?<img src={editTask.image} className="ft-img-preview-sq" alt=""/>:<div className="ft-img-placeholder-sq">{editTask.icon||"📋"}</div>}
                <div className="ft-img-info"><div className="ft-img-title">{t('common.upload_image')}</div><div className="ft-img-sub">{t('common.replaces_emoji')}</div>{editTask.image&&<span className="ft-img-remove" onClick={e=>{e.stopPropagation();u('image',null);}}>✕ {t('common.remove')}</span>}</div>
              </div>
              <input id="task-img-in" type="file" accept="image/*" style={{display:'none'}} onChange={e=>readImg(e.target.files[0],img=>u('image',img))}/>
            </div>
            <div className="ft-field"><label className="ft-lbl">{t('common.title')}</label><input className="ft-input" aria-label={t('common.title')} value={editTask.title} onChange={e=>u("title",e.target.value)} placeholder={t('task.title_placeholder')} autoFocus={isNew}/></div>
            <div className="ft-field"><label className="ft-lbl">{t('common.icon')}</label><div className="ft-emoji-grid">{TASK_ICONS.map(ic=><div key={ic} className={`ft-emoji-opt ${editTask.icon===ic?"sel":""}`} onClick={()=>u("icon",ic)}>{ic}</div>)}</div></div>
            <div className="ft-row">
              <div className="ft-field"><label className="ft-lbl">{t('task.reward')}</label><input className="ft-input" aria-label={t('task.reward')} type="number" value={editTask.minutesReward} onChange={e=>u("minutesReward",parseInt(e.target.value)||0)} min={0}/></div>
              <div className="ft-field"><label className="ft-lbl">{t('task.penalty')}</label><input className="ft-input" aria-label={t('task.penalty')} type="number" value={editTask.minutesPenalty} onChange={e=>u("minutesPenalty",parseInt(e.target.value)||0)} min={0}/></div>
              <div className="ft-field"><label className="ft-lbl">{t('task.deadline')}</label><input className="ft-input" aria-label={t('task.deadline')} type="time" value={editTask.dueTime} onChange={e=>u("dueTime",e.target.value)}/></div>
            </div>
            <div className="ft-field"><label className="ft-lbl">{t('task.weekdays')}</label><div className="ft-day-row">{DAYS_MON.map((d,di)=>{const i=DAYS_MON_I[di];return<button key={i} className={`ft-day-btn ${editTask.repeatDays.includes(i)?"sel":""}`} onClick={()=>togDay(i)}>{d}</button>})}</div></div>
            <div className="ft-field"><label className="ft-lbl">{t('task.assigned_to')}</label><div className="ft-assign-row">{childrenList.map(c=><button key={c.id} className={`ft-assign-btn ${editTask.assignedTo.includes(c.id)?"sel":""}`} style={{"--cc":c.color,"--ccr":h2r(c.color)}} onClick={()=>togAssign(c.id)}>{c.emoji} {c.name}</button>)}</div></div>
            <div className="ft-modal-acts">
              <button className="ft-btn ft-btn-sec" onClick={()=>setEditTask(null)}>{t('common.cancel')}</button>
              <button className="ft-btn ft-btn-pri" onClick={()=>editTask.title.trim()&&onSave(editTask)}>{t('common.save')}</button>
            </div>
          </div>
        </div>
      );
    }

    // ── Strafe bearbeiten / hinzufügen ───────────────────────────────────────
    function FineModal({editFine, setEditFine, onSave, childrenList}) {
      if (!editFine) return null;
      const isNew     = !editFine.id;
      const u         = (k,v) => setEditFine(p => ({...p, [k]: v}));
      const togAssign = cid => u("assignedTo", editFine.assignedTo.includes(cid) ? editFine.assignedTo.filter(x=>x!==cid) : [...editFine.assignedTo, cid]);
      const chf      = v => setEditFine(p => ({...p, moneyPenalty: parseCHF(v), moneyPenaltyRaw: v}));
      return (
        <div className="ft-overlay" onClick={e=>e.target===e.currentTarget&&setEditFine(null)}>
          <div className="ft-modal">
            <div className="ft-modal-title">{isNew?t('fine.add'):t('fine.edit')}</div>
            <div className="ft-field"><label className="ft-lbl">{t('common.title')}</label><input className="ft-input" aria-label={t('common.title')} value={editFine.title} onChange={e=>u("title",e.target.value)} placeholder={t('fine.title_placeholder')} autoFocus={isNew}/></div>
            <div className="ft-field"><label className="ft-lbl">{t('common.icon')}</label><div className="ft-emoji-grid">{FINE_ICONS.map(ic=><div key={ic} className={`ft-emoji-opt ${editFine.icon===ic?"sel":""}`} onClick={()=>u("icon",ic)}>{ic}</div>)}</div></div>
            <div className="ft-row">
              <div className="ft-field"><label className="ft-lbl">{t('fine.deduction')}</label><input className="ft-input" aria-label={t('fine.deduction')} type="number" value={editFine.minutesPenalty} onChange={e=>u("minutesPenalty",parseInt(e.target.value)||0)} min={0}/></div>
              <div className="ft-field"><label className="ft-lbl">{t('fine.money_penalty')}</label><input className="ft-input" aria-label={t('fine.money_penalty')} type="number" step="0.01" inputMode="decimal" value={editFine.moneyPenaltyRaw??(((editFine.moneyPenalty||0)/100).toFixed(2))} onChange={e=>chf(e.target.value)} placeholder="0.00"/></div>
            </div>
            <div className="ft-hint">{t('fine.penalty_hint')}</div>
            <div className="ft-field"><label className="ft-lbl">{t('fine.applies_to')}</label><div className="ft-assign-row">{childrenList.map(c=><button key={c.id} className={`ft-assign-btn ${editFine.assignedTo.includes(c.id)?"sel":""}`} style={{"--cc":c.color,"--ccr":h2r(c.color)}} onClick={()=>togAssign(c.id)}>{c.emoji} {c.name}</button>)}</div></div>
            <div className="ft-modal-acts">
              <button className="ft-btn ft-btn-sec" onClick={()=>setEditFine(null)}>{t('common.cancel')}</button>
              <button className="ft-btn ft-btn-pri" style={{background:'linear-gradient(135deg,#FF6B6B,#FF4444)'}} onClick={()=>editFine.title.trim()&&onSave(editFine)}>{t('common.save')}</button>
            </div>
          </div>
        </div>
      );
    }

    // ── Termin bearbeiten / hinzufügen ───────────────────────────────────────
    // Termin-Struktur: { id, title, emoji, dateFrom, dateTo, allDay,
    //                    timeFrom, timeTo, assignedTo[] }
    // assignedTo = [] bedeutet "Alle Kinder"
    const TERMIN_EMOJIS = ["🎉","🏖️","🎂","🏥","🦷","✈️","🏕️","🎭","🎪","⛷️","🏊","🎓","🏆","🎈","📅","🏠","🌴","❄️","🎄","🎃","🏒","⚽","🏀","🎾","🏋️","🤸","🚴","🎯","🥊","🏓","🤾","🏃","🎻","🎹","🎸","🧘","🛒","🎡","🎠","🎃"];
    function TerminModal({editTermin, setEditTermin, onSave, childrenList}) {
      if (!editTermin) return null;
      const isNew = !editTermin.id;
      const u = (k,v) => setEditTermin(p => ({...p, [k]: v}));
      const togAssign = cid => {
        const cur = editTermin.assignedTo;
        u("assignedTo", cur.includes(cid) ? cur.filter(x=>x!==cid) : [...cur, cid]);
      };
      return (
        <div className="ft-overlay" onClick={e=>e.target===e.currentTarget&&setEditTermin(null)}>
          <div className="ft-modal">
            <div className="ft-modal-title">{isNew?t('termin.add'):t('termin.edit')}</div>

            {/* Titel */}
            <div className="ft-field">
              <label className="ft-lbl">{t('common.title')}</label>
              <input className="ft-input" aria-label={t('common.title')} value={editTermin.title} onChange={e=>u("title",e.target.value)} placeholder={t('termin.title_placeholder')} autoFocus={isNew}/>
            </div>

            {/* Emoji */}
            <div className="ft-field">
              <label className="ft-lbl">{t('common.icon')}</label>
              <div className="ft-emoji-grid">{TERMIN_EMOJIS.map(ic=><div key={ic} className={`ft-emoji-opt ${editTermin.emoji===ic?"sel":""}`} onClick={()=>u("emoji",ic)}>{ic}</div>)}</div>
            </div>

            {/* Datum Von / Bis */}
            <div className="ft-row">
              <div className="ft-field">
                <label className="ft-lbl">{t('termin.from')}</label>
                <input className="ft-input" aria-label={t('termin.from')} type="date" value={editTermin.dateFrom} onChange={e=>u("dateFrom",e.target.value)}/>
              </div>
              <div className="ft-field">
                <label className="ft-lbl">{t('termin.to')}</label>
                <input className="ft-input" aria-label={t('termin.to')} type="date" value={editTermin.dateTo} onChange={e=>u("dateTo",e.target.value)}/>
              </div>
            </div>

            {/* Ganztägig Toggle */}
            <div className="ft-field" style={{display:"flex",alignItems:"center",gap:10}}>
              <label className="ft-lbl" style={{margin:0}}>{t('termin.all_day')}</label>
              <div onClick={()=>u("allDay",!editTermin.allDay)} style={{width:42,height:24,borderRadius:12,background:editTermin.allDay?"#6BCB77":"rgba(255,255,255,0.12)",cursor:"pointer",transition:"background 0.2s",position:"relative",flexShrink:0}}>
                <div style={{position:"absolute",top:3,left:editTermin.allDay?20:3,width:18,height:18,borderRadius:"50%",background:"#fff",transition:"left 0.18s"}}/>
              </div>
            </div>

            {/* Uhrzeit (nur wenn nicht Ganztägig) */}
            {!editTermin.allDay&&<div className="ft-row">
              <div className="ft-field">
                <label className="ft-lbl">{t('termin.from_time')}</label>
                <input className="ft-input" aria-label={t('termin.from_time')} type="time" value={editTermin.timeFrom||""} onChange={e=>u("timeFrom",e.target.value)}/>
              </div>
              <div className="ft-field">
                <label className="ft-lbl">{t('termin.to_time')}</label>
                <input className="ft-input" aria-label={t('termin.to_time')} type="time" value={editTermin.timeTo||""} onChange={e=>u("timeTo",e.target.value)}/>
              </div>
            </div>}

            {/* Zugewiesen an */}
            <div className="ft-field">
              <label className="ft-lbl">{t('termin.for_whom')}</label>
              <div className="ft-assign-row">
                {/* "Alle"-Button */}
                <button className={`ft-assign-btn ${editTermin.assignedTo.length===0?"sel":""}`}
                  style={{"--cc":"#FFE66D","--ccr":"255,230,109"}}
                  onClick={()=>u("assignedTo",[])}>{t('termin.all_kids')}</button>
                {childrenList.map(c=>(
                  <button key={c.id}
                    className={`ft-assign-btn ${editTermin.assignedTo.includes(c.id)?"sel":""}`}
                    style={{"--cc":c.color,"--ccr":h2r(c.color)}}
                    onClick={()=>togAssign(c.id)}>
                    {c.emoji} {c.name}
                  </button>
                ))}
              </div>
            </div>

            {/* ── Wiederholen (Recurrence) ── */}
            <div className="ft-field">
              <label className="ft-lbl">{t('termin.recurrence')}</label>
              <div style={{display:"flex",alignItems:"center",gap:10,marginBottom:8}}>
                <div onClick={()=>{
                  if(editTermin.recurrence){u("recurrence",null);}
                  else{
                    const d=new Date((editTermin.dateFrom||todayStr())+"T00:00:00");
                    u("recurrence",{type:"weekly",days:[d.getDay()===0?0:d.getDay()],dayOfMonth:d.getDate(),monthDay:String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0"),endDate:null});
                  }
                }} style={{width:42,height:24,borderRadius:12,background:editTermin.recurrence?"#4ECDC4":"rgba(255,255,255,0.12)",cursor:"pointer",transition:"background 0.2s",position:"relative",flexShrink:0}}>
                  <div style={{position:"absolute",top:3,left:editTermin.recurrence?20:3,width:18,height:18,borderRadius:"50%",background:"#fff",transition:"left 0.18s"}}/>
                </div>
                <span style={{fontSize:12,color:"rgba(255,255,255,0.55)",fontWeight:700}}>{t('termin.recurring_event')}</span>
              </div>
              {editTermin.recurrence&&<>
                <label className="ft-lbl" style={{marginTop:6}}>{t('termin.type')}</label>
                <div className="ft-assign-row" style={{marginBottom:10}}>
                  {[{t:"weekly",l:t('termin.weekly')},{t:"monthly",l:t('termin.monthly')},{t:"yearly",l:t('termin.yearly')}].map(r=>
                    <button key={r.t} className={`ft-assign-btn ${editTermin.recurrence.type===r.t?"sel":""}`}
                      style={editTermin.recurrence.type===r.t?{"--cc":"#4ECDC4","--ccr":"78,205,196"}:{"--cc":"rgba(255,255,255,0.2)","--ccr":"255,255,255"}}
                      onClick={()=>u("recurrence",{...editTermin.recurrence,type:r.t})}>{r.l}</button>
                  )}
                </div>
                {editTermin.recurrence.type==="weekly"&&<>
                  <label className="ft-lbl">{t('termin.weekly_days')}</label>
                  <div className="ft-day-row" style={{marginBottom:10}}>
                    {DAYS_MON.map((d,di)=>{
                      const i=DAYS_MON_I[di];
                      const sel=(editTermin.recurrence.days||[]).includes(i);
                      const tog=()=>{
                        const cur=editTermin.recurrence.days||[];
                        const nd=sel?cur.filter(x=>x!==i):[...cur,i].sort((a,b)=>a-b);
                        if(nd.length>0)u("recurrence",{...editTermin.recurrence,days:nd});
                      };
                      return<button key={i} className={`ft-day-btn ${sel?"sel":""}`} onClick={tog}>{d}</button>;
                    })}
                  </div>
                </>}
                {editTermin.recurrence.type==="monthly"&&<>
                  <label className="ft-lbl">{t('termin.monthly_day')}</label>
                  <div className="ft-day-row" style={{marginBottom:10}}>
                    {Array.from({length:31},(_,i)=>i+1).map(d=>
                      <button key={d} className={`ft-day-btn ${editTermin.recurrence.dayOfMonth===d?"sel":""}`}
                        onClick={()=>u("recurrence",{...editTermin.recurrence,dayOfMonth:d})}>{d}</button>
                    )}
                  </div>
                </>}
                {editTermin.recurrence.type==="yearly"&&<>
                  <label className="ft-lbl">{t('termin.yearly_date')}</label>
                  <input className="ft-input" aria-label={t('termin.yearly_date')} type="date" value={(editTermin.dateFrom||"2024-01-01").substr(0,4)+"-"+editTermin.recurrence.monthDay}
                    onChange={e=>{const v=e.target.value;if(v){const p=v.split("-");u("recurrence",{...editTermin.recurrence,monthDay:p[1]+"-"+p[2]});}}}
                    style={{marginBottom:10}}/>
                </>}
                <div className="ft-field">
                  <label className="ft-lbl">{t('termin.calendar_time')}</label>
                  <input className="ft-input" aria-label={t('termin.calendar_time')} type="time" value={editTermin.timeFrom||""} onChange={e=>u("timeFrom",e.target.value)} placeholder="17:30"/>
                </div>
                <div className="ft-field">
                  <label className="ft-lbl">{t('termin.repeat_until')}</label>
                  <input className="ft-input" aria-label={t('termin.repeat_until')} type="date" value={editTermin.recurrence.endDate||""} onChange={e=>u("recurrence",{...editTermin.recurrence,endDate:e.target.value||null})}/>
                </div>
                <div className="ft-rec-pill">
                  {editTermin.recurrence.type==="weekly"&&<>🔁 {t('termin.recurring_weekly_label',{days:(editTermin.recurrence.days||[]).map(d=>DAYS_S[d]).join(", ")})}</>}
                  {editTermin.recurrence.type==="monthly"&&<>🔁 {t('termin.recurring_monthly_label',{day:editTermin.recurrence.dayOfMonth})}</>}
                  {editTermin.recurrence.type==="yearly"&&<>🔁 {t('termin.recurring_yearly_label',{date:editTermin.recurrence.monthDay})}</>}
                  {editTermin.timeFrom?" · "+editTermin.timeFrom+" "+t('common.o_clock'):""}
                  {editTermin.recurrence.endDate?" · "+t('calendar.until',{date:new Date(editTermin.recurrence.endDate+"T12:00:00").toLocaleDateString(getLocale(),{day:"numeric",month:"short",year:"numeric"})}):""}
                </div>
              </>}
            </div>

            <div className="ft-modal-acts">
              <button className="ft-btn ft-btn-sec" onClick={()=>setEditTermin(null)}>{t('common.cancel')}</button>
              <button className="ft-btn ft-btn-pri"
                style={{background:"linear-gradient(135deg,#FFE66D,#FFB347)",color:"#1a1200"}}
                onClick={()=>editTermin.title.trim()&&editTermin.dateFrom&&onSave(editTermin)}>
                {t('common.save')}
              </button>
            </div>
          </div>
        </div>
      );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Top-Level-Dialoge
    //
    // Warum ausserhalb von FamTask()? Eine Komponente, die im Render-Body von
    // FamTask definiert wird, ist bei JEDEM Render eine neue Funktion – React
    // sieht damit einen neuen Komponenten-Typ, unmountet den alten Teilbaum und
    // mountet neu. Bei Eingabefeldern heisst das: nach jedem Tastendruck
    // verschwindet der Cursor (und bei autoFocus springt er auf ein anderes
    // Feld zurueck). Auf Modulebene definierte Komponenten behalten ihre
    // Identität, React aktualisiert nur den Inhalt – Fokus und getippter Wert
    // bleiben erhalten.
    //
    // Der komplette benötigte Zustand kommt als einzelnes `ui`-Prop herein.
    // ─────────────────────────────────────────────────────────────────────────
    const MONEY_ACTIONS={
      payout: {icon:'💶',sign:+1,log:'payout', title:()=>t('money.pay_out_title'), btn:()=>t('money.confirm_payout'), hint:()=>t('money.paid'),            msg:v=>t('money.paid_toast',{amount:v})},
      savings:{icon:'🏦',sign:-1,log:'savings',title:()=>t('money.to_savings'),    btn:()=>t('money.confirm_out'),   hint:()=>t('money.reason_savings'), msg:v=>t('money.removed_toast',{amount:v})},
      gift:   {icon:'🎁',sign:-1,log:'gift',   title:()=>t('money.personal'),     btn:()=>t('money.confirm_out'),   hint:()=>t('money.reason_gift'),    msg:v=>t('money.removed_toast',{amount:v})},
    };
    const MONEY_LOG_ICONS={payout:'💶',savings:'🏦',gift:'🎁',fine:'⚠️'};
    const BONUS_REASONS=[
      {icon:'🌟',label:t('bonus.reason.good_behavior')},
      {icon:'🎂',label:t('bonus.reason.birthday')},
      {icon:'🏆',label:t('bonus.reason.special_achievement')},
      {icon:'🤝',label:t('bonus.reason.helpful')},
      {icon:'📖',label:t('bonus.reason.reading')},
      {icon:'✏️',label:t('bonus.reason.other')},
    ];

    function MoneyModal({ui}) {
      const {moneyModal,setMoneyModal,getChild,moneyPinDigit,confirmMoneyAction}=ui;
      if(!moneyModal) return null;
      const child=getChild(moneyModal.cid);
      if(!child) return null;
      const cfg=MONEY_ACTIONS[moneyModal.kind];

      if(moneyModal.step==='pin') return(
        <div className="ft-overlay">
          <div className="ft-modal" style={{maxWidth:320}}>
            <div style={{textAlign:'center',marginBottom:16}}>
              <div style={{fontSize:36,marginBottom:4}}>{cfg?cfg.icon:'⚠️'}</div>
              <div className="ft-modal-title" style={{marginBottom:4}}>{cfg?cfg.title():t('money.money_fine')}</div>
              <div style={{fontSize:12,color:'rgba(255,255,255,0.65)'}}>{t('money.pin_hint')}</div>
            </div>
            <div className="ft-pin-disp">
              <div className="ft-pin-dots">
                {[0,1,2,3].map(i=><div key={i} className={`ft-pin-dot ${i<moneyModal.pinInput.length?'on':''}`} style={moneyModal.pinErr?{background:'#FF6B6B',animation:'shake 0.4s'}:{}}/>)}
              </div>
              {moneyModal.pinErr&&<div className="ft-pin-err">{t('admin.pin_wrong')}</div>}
            </div>
            <div className="ft-pin-grid">
              {["1","2","3","4","5","6","7","8","9","⌫","0","✓"].map(d=>(
                <button key={d} className="ft-pin-btn"
                  style={d==="⌫"?{background:"rgba(255,107,107,0.12)",color:"#FF8E8E"}:d==="✓"?{background:"rgba(107,203,119,0.12)",color:"#6BCB77"}:{}}
                  onClick={()=>d!=="✓"&&moneyPinDigit(d)}>{d}</button>
              ))}
            </div>
            <button className="ft-btn ft-btn-sec" style={{width:"100%",marginTop:8}} onClick={()=>setMoneyModal(null)}>{t('common.cancel')}</button>
          </div>
        </div>
      );

      if(!cfg) return null;
      const tint=cfg.sign>0?'linear-gradient(135deg,#6BCB77,#3FA860)':'linear-gradient(135deg,#FFB347,#FF8E53)';
      return(
        <div className="ft-overlay" onClick={e=>e.target===e.currentTarget&&setMoneyModal(null)}>
          <div className="ft-modal">
            <div style={{textAlign:'center',marginBottom:18}}>
              <div style={{fontSize:42,marginBottom:4}}>{cfg.icon}</div>
              <div className="ft-modal-title" style={{marginBottom:2}}>{cfg.title()}</div>
              <div style={{fontSize:13,color:'rgba(255,255,255,0.68)'}}>{child.emoji} {child.name}</div>
            </div>
            <div className="ft-field">
              <label className="ft-lbl">{t('money.amount')}</label>
              <input className="ft-input ft-input-chf" type="number" step="0.01" inputMode="decimal" autoFocus value={moneyModal.cents} onChange={e=>setMoneyModal(p=>({...p,cents:e.target.value}))}/>
            </div>
            <div className="ft-money-quick">
              {[child.weeklyPocket??500,500,1000,2000].filter((v,i,a)=>v>0&&a.indexOf(v)===i).map(v=>(
                <button key={v} className={`ft-money-quick-b ${parseCHF(moneyModal.cents)===v?'sel':''}`} onClick={()=>setMoneyModal(p=>({...p,cents:(v/100).toFixed(2)}))}>{fmtCHF(v)}</button>
              ))}
            </div>
            <div className="ft-field">
              <label className="ft-lbl">{t('money.note')}</label>
              <input className="ft-input" aria-label={t('money.note')} type="text" maxLength={60} value={moneyModal.note} onChange={e=>setMoneyModal(p=>({...p,note:e.target.value}))} placeholder={cfg.hint()}/>
            </div>
            <div className="ft-modal-acts">
              <button className="ft-btn ft-btn-sec" onClick={()=>setMoneyModal(null)}>{t('common.cancel')}</button>
              <button className="ft-btn ft-btn-pri" style={{background:tint}} onClick={confirmMoneyAction}>{cfg.btn()}</button>
            </div>
          </div>
        </div>
      );
    }

    function BonusModal({ui}) {
      const {bonusModal,setBonusModal,getChild,bonusPinDigit,confirmBonus,adminPin}=ui;
      if(!bonusModal) return null;
      const child=getChild(bonusModal.cid);
      if(!child) return null;

      // ── Schritt 1: PIN ──────────────────────────────────────────────────
      if(bonusModal.step==='pin') return(
        <div className="ft-overlay">
          <div className="ft-modal" style={{maxWidth:320}}>
            <div style={{textAlign:'center',marginBottom:16}}>
              <div style={{fontSize:36,marginBottom:4}}>⭐</div>
              <div className="ft-modal-title" style={{marginBottom:4}}>{t('bonus.title',{name:child.name})}</div>
              <div style={{fontSize:12,color:'rgba(255,255,255,0.65)'}}>{t('bonus.pin_hint')}</div>
            </div>
            <div className="ft-pin-disp">
              <div className="ft-pin-dots">
                {[0,1,2,3].map(i=><div key={i} className={`ft-pin-dot ${i<bonusModal.pinInput.length?'on':''}`} style={bonusModal.pinErr?{background:'#FF6B6B',animation:'shake 0.4s'}:{}}/>)}
              </div>
              {bonusModal.pinErr&&<div className="ft-pin-err">{t('admin.pin_wrong')}</div>}
            </div>
            <div className="ft-pin-grid">
              {["1","2","3","4","5","6","7","8","9","⌫","0","✓"].map(d=>(
                <button key={d} className="ft-pin-btn"
                  style={d==="⌫"?{background:"rgba(255,107,107,0.12)",color:"#FF8E8E"}:d==="✓"?{background:"rgba(107,203,119,0.12)",color:"#6BCB77"}:{}}
                  onClick={()=>d!=="✓"&&bonusPinDigit(d)}>{d}</button>
              ))}
            </div>
            <button className="ft-btn ft-btn-sec" style={{width:"100%",marginTop:8}} onClick={()=>setBonusModal(null)}>{t('common.cancel')}</button>
          </div>
        </div>
      );

      // ── Schritt 2: Minuten + Grund ──────────────────────────────────────
      return(
        <div className="ft-overlay" onClick={e=>e.target===e.currentTarget&&setBonusModal(null)}>
          <div className="ft-modal">
            <div style={{textAlign:'center',marginBottom:18}}>
              <div style={{fontSize:42,marginBottom:4}}>🎁</div>
              <div className="ft-modal-title" style={{marginBottom:2}}>{t('bonus.grant')}</div>
              <div style={{fontSize:13,color:'rgba(255,255,255,0.68)'}}>{child.emoji} {child.name}</div>
            </div>

            <div className="ft-field">
              <label className="ft-lbl">{t('bonus.reason')}</label>
              <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:7,marginBottom:4}}>
                {BONUS_REASONS.map(r=>(
                  <button key={r.label} className={`ft-bonus-preset ${bonusModal.reason===r.label?'sel':''}`}
                    onClick={()=>setBonusModal(p=>({...p,reason:r.label}))}>
                    {r.icon} {r.label}
                  </button>
                ))}
              </div>
            </div>

            <div className="ft-field">
              <label className="ft-lbl">{t('bonus.minutes')}</label>
              <div style={{display:'flex',gap:8,marginBottom:8}}>
                {[10,15,20,30,45,60].map(m=>(
                  <button key={m} onClick={()=>setBonusModal(p=>({...p,mins:String(m)}))}
                    style={{flex:1,padding:'8px 2px',background:bonusModal.mins===String(m)?'rgba(255,230,109,0.2)':'rgba(255,255,255,0.06)',border:bonusModal.mins===String(m)?'1.5px solid #FFE66D':'1.5px solid rgba(255,255,255,0.09)',borderRadius:9,color:bonusModal.mins===String(m)?'#FFE66D':'rgba(255,255,255,0.55)',fontSize:12,fontWeight:800,cursor:'pointer'}}>
                    {m}'
                  </button>
                ))}
              </div>
              <input className="ft-input" type="number" min={1} max={999}
                placeholder={t('common.or_custom')}
                value={bonusModal.mins}
                onChange={e=>setBonusModal(p=>({...p,mins:e.target.value}))}
                inputMode="numeric"/>
            </div>

            {bonusModal.mins>0&&<div style={{background:'rgba(255,230,109,0.08)',border:'1.5px solid rgba(255,230,109,0.2)',borderRadius:12,padding:'12px 14px',margin:'14px 0 0',display:'flex',justifyContent:'space-between',alignItems:'center'}}>
              <span style={{fontSize:13,color:'rgba(255,230,109,0.7)',fontWeight:700}}>{bonusModal.reason}</span>
              <span style={{fontFamily:"'Fredoka One',sans-serif",fontSize:24,color:'#FFE66D'}}>+{bonusModal.mins} {t('common.minutes_short')}</span>
            </div>}

            <div className="ft-modal-acts" style={{marginTop:16}}>
              <button className="ft-btn ft-btn-sec" onClick={()=>setBonusModal(null)}>{t('common.cancel')}</button>
              <button className="ft-btn" style={{background:'linear-gradient(135deg,#FFE66D,#FFB347)',color:'#1a1200'}}
                onClick={confirmBonus}>{t('bonus.grant_btn')}</button>
            </div>
          </div>
        </div>
      );
    }

    function MediaTypeModal({initial,onSave,onClose}){
      if(!initial) return null;
      const isNew=initial._isNew===true||!initial.id;
      const [form,setForm]=useState({...initial});
      const u=(k,v)=>setForm(p=>({...p,[k]:v}));
      return(<div className="ft-overlay" onClick={e=>e.target===e.currentTarget&&onClose()}>
        <div className="ft-modal">
          <div className="ft-modal-title">{isNew?t('mediatype.add'):t('mediatype.edit')}</div>
          <div className="ft-field"><label className="ft-lbl">{t('common.name')}</label><input className="ft-input" aria-label={t('common.name')} value={form.label} onChange={e=>u('label',e.target.value)} placeholder={t('mediatype.name_placeholder')} autoFocus={isNew}/></div>
          <div className="ft-field"><label className="ft-lbl">{t('common.icon')}</label><div className="ft-emoji-grid">{MEDIA_ICONS.map(e=><div key={e} className={`ft-emoji-opt ${form.icon===e?'sel':''}`} onClick={()=>u('icon',e)}>{e}</div>)}</div></div>
          <div className="ft-field"><label className="ft-lbl">{t('common.color')}</label><div className="ft-col-grid">{MEDIA_COLORS.map(c=><div key={c} className={`ft-col-sw ${form.color===c?'sel':''}`} style={{background:c}} onClick={()=>u('color',c)}/>)}</div></div>
          <div className="ft-modal-acts">
            <button className="ft-btn ft-btn-sec" onClick={onClose}>{t('common.cancel')}</button>
            <button className="ft-btn ft-btn-pri" onClick={()=>form.label.trim()&&onSave({...form,_isNew:isNew?true:undefined})}>{t('common.save')}</button>
          </div>
        </div>
      </div>);
    }

    function TimerModalView({ui}) {
      const {timerModal,setTimerModal,getChild,timers,custMin,setCustMin,custSec,setCustSec,startTimer,stopTimer,showToast}=ui;
      if(!timerModal) return null;
      const c=getChild(timerModal); if(!c) return null;
      const tmr=timers[timerModal];const running=tmr&&(tmr.endsAt-Date.now())>0;const expired=tmr&&!running;const pct=tmr?Math.min(100,Math.max(0,((tmr.endsAt-Date.now())/(tmr.totalSecs*1000))*100)):0;
      return(<div className="ft-overlay" onClick={e=>e.target===e.currentTarget&&setTimerModal(null)}>
        <div className="ft-tmodal">
          <div className="ft-tmod-badge"><span className="ft-tmod-emoji">{c.emoji}</span><div><div className="ft-tmod-name">{c.name}</div>            <div className="ft-tmod-status">{running?t('timer.running'):expired?t('timer.expired'):t('timer.no_active')}</div></div></div>
          {(running||expired)&&<div className="ft-tmod-active"><div className="ft-tmod-active-lbl">{expired?t('timer.expired_label'):t('timer.remaining')}</div><div id="dom-tmod-val" className={`ft-tmod-active-val ${running&&(tmr.endsAt-Date.now())<60000?"urgent":""}`}>{running?fmtMM(Math.round((tmr.endsAt-Date.now())/1000)):'00:00'}</div>{running&&<div className="ft-tmod-bar-bg"><div id="dom-tmod-bar" className="ft-tmod-bar-fill" style={{width:pct+'%'}}/></div>}</div>}
          {running&&<button className="ft-stop-btn" onClick={()=>{stopTimer(c.id);showToast(t('timer.stopped'),"inf");}}>{t('timer.stop')}</button>}
          <div className="ft-preset-lbl">{t('timer.quickstart')}</div>
          <div className="ft-presets">{TIMER_PRESETS.map(p=><button key={p.secs} className="ft-preset-btn" onClick={()=>{startTimer(c.id,p.secs);setTimerModal(null);}}>{p.label}</button>)}</div>
          <div className="ft-preset-lbl">{t('timer.custom')}</div>
          <div className="ft-custom-row">
            <div className="ft-field"><label className="ft-lbl">{t('timer.min')}</label><input className="ft-input" aria-label={t('timer.min')} type="number" min="0" placeholder="0" value={custMin} onChange={e=>setCustMin(e.target.value)} inputMode="numeric"/></div>
            <div className="ft-field"><label className="ft-lbl">{t('timer.sec')}</label><input className="ft-input" aria-label={t('timer.sec')} type="number" min="0" max="59" placeholder="0" value={custSec} onChange={e=>setCustSec(e.target.value)} inputMode="numeric"/></div>
            <button className="ft-custom-start" onClick={()=>{const total=(parseInt(custMin)||0)*60+(parseInt(custSec)||0);if(total>0){startTimer(c.id,total);setTimerModal(null);}}}>{t('common.start')}</button>
          </div>
          <button className="ft-btn ft-btn-sec" style={{width:"100%"}} onClick={()=>setTimerModal(null)}>{t('common.close')}</button>
        </div>
      </div>);
    }

    // ── Externe Musikquellen (WebDAV / FTP / HTTPS / gemountetes Laufwerk) ──
    function AdminMusicSources({ui}) {
      const {showToast} = ui;
      const [caps,setCaps]=useState(null);
      const [srcs,setSrcs]=useState([]);
      const [busy,setBusy]=useState(false);
      const [form,setForm]=useState({id:0,name:'',kind:'webdav',url:'',username:'',password:'',mode:'copy'});
      const [imp,setImp]=useState({});
      const [browseFor,setBrowseFor]=useState(null);
      const [browseResult,setBrowseResult]=useState(null);

      const reload = async () => {
        try {
          const r = await fetch(_remoteUrl('list'), {headers:{'X-Family-Code':_getFC(),'X-Admin-Pin':_adminPin()}});
          const d = await r.json();
          if (d && d.sources) setSrcs(d.sources);
        } catch(e) { /* offline: Liste bleibt stehen */ }
      };
      useEffect(()=>{
        fetch(_remoteUrl('status')).then(r=>r.json()).then(d=>{ if(d&&d.ok) setCaps(d); }).catch(()=>{});
        reload();
      },[]);

      const save = async () => {
        if (!form.name.trim() || !form.url.trim()) {
          showToast(t('remote.need_name_url'), 'err'); return;
        }
        setBusy(true);
        const res = await _remotePost('save', form);
        setBusy(false);
        if (!res.ok) { showToast((res.data&&res.data.error)||t('remote.save_failed'), 'err'); return; }
        showToast(t('remote.saved'));
        setForm({id:0,name:'',kind:'webdav',url:'',username:'',password:'',mode:'copy'});
        reload();
      };

      const del = async (s) => {
        if (!confirm(t('remote.confirm_delete',{name:s.name}))) return;
        setBusy(true);
        const res = await _remotePost('delete', {id:s.id});
        setBusy(false);
        if (!res.ok) { showToast((res.data&&res.data.error)||t('remote.delete_failed'), 'err'); return; }
        showToast(t('remote.deleted'));
        reload();
      };

      const test = async (s) => {
        setBusy(true);
        const res = await _remotePost('browse', {id:s.id});
        setBusy(false);
        if (!res.ok) { showToast((res.data&&res.data.error)||t('remote.conn_failed'), 'err'); return; }
        const d = res.data||{};
        showToast(t('remote.conn_ok',{count:d.count, ms:d.ms}));
      };

      const doImport = async (s, patch) => {
        setBusy(true);
        const res = await _remotePost('import', Object.assign({id:s.id}, patch));
        setBusy(false);
        if (!res.ok) { showToast((res.data&&res.data.error)||t('remote.import_failed'), 'err'); return; }
        const d = res.data||{};
        showToast(t('remote.import_done',{
          imported:d.imported||0, unchanged:d.unchanged||0, size:d.size||'', ms:d.ms||0
        }));
        setImp(Object.assign({}, imp, {[s.id]:Object.assign({album:'', path:''}, patch)}));
      };

      const doBrowse = async (s) => {
        const cur = imp[s.id]||{};
        setBusy(true);
        const res = await _remotePost('browse', {id:s.id, path:cur.path||''});
        setBusy(false);
        if (!res.ok) { showToast((res.data&&res.data.error)||t('remote.browse_failed'), 'err'); return; }
        setBrowseFor(s.id);
        setBrowseResult(res.data);
      };

      const edit = (s) => {
        setForm({id:s.id, name:s.name, kind:s.kind, url:s.url, username:s.username,
                 password:'', mode:s.mode});
        showToast(t('remote.editing',{name:s.name}));
      };

      const KINDS = [
        {id:'webdav', lbl:t('remote.kind_webdav')},
        {id:'ftp',    lbl:t('remote.kind_ftp')},
        {id:'https',  lbl:t('remote.kind_https')},
        {id:'local',  lbl:t('remote.kind_local')},
      ];

      return(<div>
        {/* Fähigkeiten des Servers */}
        {caps && <div className="ft-remote-caps">
          {KINDS.map(k=>(<span key={k.id} className={'ft-remote-cap'+(caps.can[k.id]?'':' off')}>
            {caps.can[k.id]?'✓':'✕'} {k.lbl}
          </span>))}
          <span className={'ft-remote-cap'+(caps.can.link?'':' off')}>✓/✕ symlink</span>
        </div>}
        {caps && caps.hint && <div className="ft-remote-warn">⚠ {caps.hint}</div>}
        {!caps && <div className="ft-remote-warn">⚠ {t('remote.unreachable')}</div>}

        {/* Neue Quelle */}
        <div className="ft-set-card" style={{marginTop:14}}>
          <div className="ft-set-title">{form.id? t('remote.edit_source') : t('remote.add_source')}</div>

          <div className="ft-field">
            <label className="ft-lbl">{t('remote.type')}</label>
            <div style={{display:'flex',gap:6,flexWrap:'wrap'}}>
              {KINDS.map(k=>(<button key={k.id}
                className={'ft-remote-kind'+(form.kind===k.id?' on':'')}
                disabled={caps && !caps.can[k.id]}
                onClick={()=>setForm({...form, kind:k.id, mode:k.id==='local'?'link':form.mode})}
              >{k.lbl}</button>))}
            </div>
          </div>

          <div className="ft-field">
            <label className="ft-lbl">{t('remote.url_label')}</label>
            <input className="ft-input" aria-label={t('remote.url_label')} value={form.url} placeholder={form.kind==='local'?'/mnt/music':'https://…'} onChange={e=>setForm({...form, url:e.target.value})}/>
            <div className="ft-lbl" style={{marginTop:4, fontWeight:600}}>{t('remote.url_hint')}</div>
          </div>

          <div className="ft-field">
            <label className="ft-lbl">{t('remote.name')}</label>
            <input className="ft-input" aria-label={t('remote.name')} value={form.name} placeholder={t('remote.name_hint')} onChange={e=>setForm({...form, name:e.target.value})}/>
          </div>

          {form.kind!=='local' && <>
            <div className="ft-field">
              <label className="ft-lbl">{t('remote.user')}</label>
              <input className="ft-input" aria-label={t('remote.user')} value={form.username} autoCapitalize="none" onChange={e=>setForm({...form, username:e.target.value})}/>
            </div>
            <div className="ft-field">
              <label className="ft-lbl">{t('remote.pass')}</label>
              <input className="ft-input" aria-label={t('remote.pass')} type="password" value={form.password} placeholder={form.id?t('remote.pass_unchanged'):''} onChange={e=>setForm({...form, password:e.target.value})}/>
            </div>
          </>}

          {form.kind!=='https' && <div className="ft-field">
            <label className="ft-lbl">{t('remote.mode')}</label>
            <div style={{display:'flex',gap:6}}>
              <button className={'ft-remote-kind'+(form.mode==='copy'?' on':'')}
                onClick={()=>setForm({...form, mode:'copy'})}>{t('remote.mode_copy')}</button>
              <button className={'ft-remote-kind'+(form.mode==='link'?' on':'')}
                disabled={caps && !caps.can.link}
                onClick={()=>setForm({...form, mode:'link'})}>{t('remote.mode_link')}</button>
            </div>
            <div className="ft-lbl" style={{marginTop:4, fontWeight:600}}>
              {form.mode==='link'? t('remote.mode_link_hint') : t('remote.mode_copy_hint')}
            </div>
          </div>}

          <div style={{display:'flex',gap:8,marginTop:12}}>
            <button className="ft-btn ft-btn-pri" disabled={busy} onClick={save}>
              {form.id? t('common.save') : t('remote.add_source')}
            </button>
            {form.id>0 && <button className="ft-btn ft-btn-sec" onClick={()=>setForm({id:0,name:'',kind:'webdav',url:'',username:'',password:'',mode:'copy'})}>{t('common.cancel')}</button>}
          </div>
        </div>

        {/* Gespeicherte Quellen */}
        <div className="ft-set-title" style={{margin:'18px 0 10px'}}>{t('remote.your_sources')} ({srcs.length})</div>
        {srcs.length===0 && <div className="ft-empty">{t('remote.none')}</div>}
        {srcs.map(s=>{
          const cur = imp[s.id]||{};
          return(<div key={s.id} className="ft-remote-card">
            <div className="ft-remote-card-top">
              <div style={{flex:1,minWidth:0}}>
                <div className="ft-remote-card-name">{s.name}</div>
                <div className="ft-remote-card-url">{s.url}</div>
                <div className="ft-remote-card-meta">
                  {(KINDS.find(k=>k.id===s.kind)||{}).lbl} · {s.mode==='link'?t('remote.mode_link'):t('remote.mode_copy')}
                  {s.username? ' · '+s.username : ''} {s.hasPassword? '🔑' : ''}
                  {s.lastSynced? ' · '+new Date(s.lastSynced.replace(' ','T')).toLocaleDateString() : ''}
                </div>
              </div>
              <button className="ft-remote-icon" aria-label={t('a11y.edit')} title={t('common.edit')} onClick={()=>edit(s)}>✏️</button>
              <button className="ft-remote-icon" aria-label={t('remote.test_conn')} title={t('remote.test_conn')} disabled={busy} onClick={()=>test(s)}>🔌</button>
              <button className="ft-remote-icon del" aria-label={t('a11y.delete')} title={t('a11y.delete')} disabled={busy} onClick={()=>del(s)}>🗑</button>
            </div>

            <div className="ft-remote-import">
              <input className="ft-input" aria-label={t('remote.subfolder_hint')} placeholder={t('remote.subfolder_hint')} value={cur.path||''} onChange={e=>setImp({...imp, [s.id]:Object.assign({}, cur, {path:e.target.value})})}/>
              <input className="ft-input" aria-label={t('remote.album_hint')} placeholder={t('remote.album_hint')} value={cur.album||''} onChange={e=>setImp({...imp, [s.id]:Object.assign({}, cur, {album:e.target.value})})}/>
              <button className="ft-remote-btn" disabled={busy} onClick={()=>doBrowse(s)}>📂 {t('remote.browse')}</button>
              <button className="ft-remote-btn go" disabled={busy} onClick={()=>doImport(s, cur)}>⬇ {t('remote.import')}</button>
            </div>

            {browseFor===s.id && browseResult && (
              <div className="ft-remote-listing">
                <div className="ft-remote-listing-h">{t('remote.content',{count:browseResult.count})}</div>
                {browseResult.items.map((it,i)=>(<div key={i} className="ft-remote-item" onClick={()=>{
                    if(!it.isDir) return;
                    const base=(cur.path||'').replace(/\/+$/,'');
                    const next=base? base+'/'+it.name : it.name;
                    setImp({...imp, [s.id]:Object.assign({}, cur, {path:next})});
                    setBrowseFor(null);
                  }}>
                  <span>{it.isDir?'📁':'🎵'}</span>
                  <span style={{flex:1,minWidth:0,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{it.name}</span>
                  <span style={{opacity:.85, fontSize:10}}>{it.isDir?'':it.sizeH}</span>
                </div>))}
              </div>
            )}
          </div>);
        })}

        <div className="ft-remote-note">
          <strong>{t('remote.note_title')}</strong><br/>
          {t('remote.note_body',{roots:(caps&&caps.roots||[]).join(', ')})}
        </div>
      </div>);
    }

    function AdminTasks({ui}) {
      const {children,tasks,fines,setEditTask,delTask,setEditChild,delChild,setEditFine}=ui;
      const[filterCid,setFilterCid]=useState(null);
      const ft=tasks.filter(t=>!filterCid||t.assignedTo.includes(filterCid));
      return(<div>{children.length>1&&<div className="ft-gcal-filter"><button className="ft-gcal-fchip" onClick={()=>setFilterCid(null)} style={{borderColor:filterCid===null?'#FFE66D':'rgba(255,255,255,0.12)',background:filterCid===null?'rgba(255,230,109,0.15)':'transparent',color:filterCid===null?'#FFE66D':'rgba(255,255,255,0.6)'}}>{t('common.all')}</button>{children.map(c=><button key={c.id} className="ft-gcal-fchip" onClick={()=>setFilterCid(filterCid===c.id?null:c.id)} style={{borderColor:filterCid===c.id?c.color:'rgba(255,255,255,0.12)',background:filterCid===c.id?'rgba('+h2r(c.color)+',0.15)':'transparent',color:filterCid===c.id?c.color:'rgba(255,255,255,0.6)'}}>{c.emoji} {c.name}</button>)}</div>}{ft.map(tsk=>{const names=children.filter(c=>tsk.assignedTo.includes(c.id)).map(c=>c.name).join(", ")||t('task.no_one');return(<div key={tsk.id} className="ft-aitem"><div style={{fontSize:24,flexShrink:0}}>{tsk.icon}</div><div className="ft-aitem-info"><div className="ft-aitem-title">{tsk.title}</div><div className="ft-aitem-sub">+{tsk.minutesReward}' / -{tsk.minutesPenalty}' · {tsk.dueTime} · {names}</div></div><div className="ft-aitem-actions"><button aria-label={t('a11y.edit')} className="ft-ic-btn" onClick={()=>setEditTask({...tsk})}>✏️</button><button aria-label={t('a11y.delete')} className="ft-ic-btn del" onClick={()=>delTask(tsk.id)}>🗑️</button></div></div>);})}<button className="ft-add-btn" onClick={()=>setEditTask({title:"",icon:"📚",assignedTo:[],minutesReward:10,minutesPenalty:5,dueTime:"18:00",repeatDays:[1,2,3,4,5]})}>{t('task.add_btn')}</button></div>);
    }

    function AdminSettings({ui}) {
      const {familyName,familyCode,onRename,onLogout,showToast,
             newPin1,setNewPin1,newPin2,setNewPin2,pinMsg,setPinMsg,adminPin,setAdminPin,
             children,setChildren,mediaTypes,setMediaU,getMediaUsed}=ui;
        const [lsCleared,setLsCleared]=React.useState(false);
        const [editingName,setEditingName]=React.useState(false);
        const [newName,setNewName]=React.useState(familyName||'');
        const [renaming,setRenaming]=React.useState(false);
        const [deleting,setDeleting]=React.useState(false);
        const [gameReward,setGameReward]=React.useState(()=>{try{const v=storage.get('ft-game-reward');return v?parseInt(v.value)||5:5;}catch(e){return 5;}});
        const clearLs=()=>{window._famtaskClearLocalStorage();setLsCleared(true);showToast(t('settings.ls_cleared'),"inf");setTimeout(()=>setLsCleared(false),3000);};
        const doRename=async()=>{
          const name=newName.trim();
          if(!name)return;
          setRenaming(true);
          try{
            const r=await fetch('api.php?action=rename_family',{method:'POST',headers:{'Content-Type':'application/json','X-Admin-Pin':adminPin},body:JSON.stringify({fc:familyCode,name})});
            const d=await r.json();
            if(d.ok){onRename(name);setEditingName(false);showToast(t('settings.name_changed'));}
            else showToast(d.msg||t('common.error'),'err');
          }catch(e){showToast(t('common.error'),'err');}
          setRenaming(false);
        };
        const doDelete=async()=>{
          if(!confirm(t('settings.confirm_delete_1')))return;
          if(!confirm(t('settings.confirm_delete_2')))return;
          setDeleting(true);
          try{
            const r=await fetch('api.php?action=delete_family',{method:'POST',headers:{'Content-Type':'application/json','X-Admin-Pin':adminPin},body:JSON.stringify({fc:familyCode})});
            const d=await r.json();
            if(d.ok){showToast(t('settings.family_deleted'));onLogout();}
            else showToast(d.msg||t('common.error'),'err');
          }catch(e){showToast(t('common.error'),'err');}
          setDeleting(false);
        };
        return(<div>
        <div className="ft-set-card" style={{borderLeft:'3px solid rgba(255,230,109,0.5)'}}>
          <div className="ft-set-title">{t('settings.family_code')}</div>
          <div style={{fontSize:12,color:'rgba(255,255,255,0.65)',marginBottom:10}}>{t('settings.family_code_desc')}</div>
          <div style={{display:'flex',alignItems:'center',gap:10,marginBottom:10}}>
            <span style={{fontFamily:'monospace',fontSize:28,fontWeight:900,letterSpacing:'4px',color:'#FFE66D',background:'rgba(255,230,109,0.08)',border:'1.5px solid rgba(255,230,109,0.2)',borderRadius:10,padding:'8px 14px'}}>{familyCode}</span>
            <button aria-label={t('a11y.copy_code')} className="ft-ic-btn" onClick={()=>{navigator.clipboard?.writeText(familyCode);showToast(t('settings.code_copied'));}}>📋</button>
          </div>
          {editingName?<div style={{display:'flex',alignItems:'center',gap:8,marginBottom:10}}><input className="ft-input" aria-label={t('settings.family_name')} style={{flex:1,fontSize:14}} value={newName} onChange={e=>setNewName(e.target.value)} placeholder={t('settings.family_name')} /><button className="ft-btn ft-btn-pri" style={{padding:'6px 14px',fontSize:12}} onClick={doRename} disabled={renaming}>{renaming?'...':t('common.save')}</button><button aria-label={t('a11y.close')} className="ft-ic-btn" onClick={()=>setEditingName(false)}>✕</button></div>:<div style={{display:'flex',alignItems:'center',gap:6,marginBottom:10}}><span style={{fontSize:13,color:'rgba(255,255,255,0.68)'}}>{familyName||t('settings.no_name')}</span><button aria-label={t('a11y.edit')} className="ft-ic-btn" style={{fontSize:14}} onClick={()=>{setNewName(familyName||'');setEditingName(true);}}>✏️</button></div>}
          <button className="ft-warn-btn" style={{marginTop:10,borderColor:'rgba(255,107,107,0.4)',color:'#FF9A9A',background:'rgba(255,107,107,0.08)'}} onClick={()=>{if(confirm(t('settings.confirm_logout')))onLogout();}}>{t('settings.logout')}</button>
          <button className="ft-warn-btn" style={{marginTop:6,borderColor:'rgba(255,50,50,0.35)',color:'#FF9A9A',background:'rgba(255,50,50,0.08)'}} onClick={doDelete} disabled={deleting}>{deleting?t('settings.deleting'):t('settings.delete_family')}</button>
        </div>
        <div className="ft-set-card"><div className="ft-set-title">{t('settings.server_admin')}</div><div style={{fontSize:12,color:'rgba(255,255,255,0.65)',marginBottom:10}}>{t('settings.server_admin_desc')}</div><a href="api.php?action=admin" className="ft-btn ft-btn-sec" style={{display:'block',textAlign:'center',textDecoration:'none',width:'100%',padding:13,borderRadius:11,fontSize:13}}>{t('settings.open_admin')}</a></div>
        <div className="ft-set-card"><div className="ft-set-title">{t('settings.language')}</div>
          <div style={{display:'grid',gridTemplateColumns:'1fr 1fr 1fr',gap:6,marginTop:6}}>
            {['de','en','es','fr','it','pt','zh','ar','hi','ru','ja','ko','tr','pl','nl','id','vi','bn','th'].map(l=>{
              const cur=(localStorage.getItem('ft_lang')||'de')===l;
              const flag = {
                de: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="6.67" fill="#000"/><rect y="6.67" width="30" height="6.67" fill="#DD0000"/><rect y="13.33" width="30" height="6.67" fill="#FFCC00"/></svg>,
                en: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="20" fill="#012169"/><rect width="30" height="4" y="8" fill="#FFF"/><rect x="13" width="4" height="20" fill="#FFF"/><rect width="30" height="1.5" y="9.25" fill="#477050"/><rect x="14.25" width="1.5" height="20" fill="#477050"/><polygon points="0,0 8,0 30,14 30,20 22,20 0,6" fill="#FFF"/><polygon points="30,0 22,0 0,14 0,20 8,20 30,6" fill="#FFF"/><polygon points="0,0 4,0 30,17 30,20 26,20 0,3" fill="#C8102E"/><polygon points="30,0 26,0 0,17 0,20 4,20 30,3" fill="#C8102E"/></svg>,
                es: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="5" fill="#C60B1E"/><rect y="5" width="30" height="10" fill="#FFC400"/><rect y="15" width="30" height="5" fill="#C60B1E"/></svg>,
                fr: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="10" height="20" fill="#0055A4"/><rect x="10" width="10" height="20" fill="#FFF"/><rect x="20" width="10" height="20" fill="#EF4135"/></svg>,
                it: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="10" height="20" fill="#009246"/><rect x="10" width="10" height="20" fill="#FFF"/><rect x="20" width="10" height="20" fill="#CE2B37"/></svg>,
                pt: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="11" height="20" fill="#006600"/><rect x="11" width="19" height="20" fill="#FF0000"/><circle cx="11" cy="10" r="6" fill="#FFCC00"/></svg>,
                zh: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="20" fill="#DE2910"/><polygon points="5,3 6.5,7.5 11,7.5 7.5,10.5 9,15 5,12 1,15 2.5,10.5 -1,7.5 3.5,7.5" fill="#FFDE00"/><polygon points="13,4 13.8,6.2 16,6.2 14.2,7.8 15,10 13,8.5 11,10 11.8,7.8 10,6.2 12.2,6.2" fill="#FFDE00" transform="scale(0.6) translate(12,2)"/><polygon points="13,4 13.8,6.2 16,6.2 14.2,7.8 15,10 13,8.5 11,10 11.8,7.8 10,6.2 12.2,6.2" fill="#FFDE00" transform="scale(0.5) translate(24,1)"/><polygon points="13,4 13.8,6.2 16,6.2 14.2,7.8 15,10 13,8.5 11,10 11.8,7.8 10,6.2 12.2,6.2" fill="#FFDE00" transform="scale(0.5) translate(28,11)"/><polygon points="13,4 13.8,6.2 16,6.2 14.2,7.8 15,10 13,8.5 11,10 11.8,7.8 10,6.2 12.2,6.2" fill="#FFDE00" transform="scale(0.45) translate(36,8)"/></svg>,
                ar: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="20" fill="#006C35"/><rect y="10" width="30" height="10" fill="#FFF"/></svg>,
                hi: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="6.67" fill="#FF9933"/><rect y="6.67" width="30" height="6.67" fill="#FFF"/><rect y="13.33" width="30" height="6.67" fill="#138808"/><circle cx="15" cy="10" r="2.5" fill="#000080"/></svg>,
                ru: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="6.67" fill="#FFF"/><rect y="6.67" width="30" height="6.67" fill="#0039A6"/><rect y="13.33" width="30" height="6.67" fill="#D52B1E"/></svg>,
                ja: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="20" fill="#FFF"/><circle cx="15" cy="10" r="6" fill="#BC002D"/></svg>,
                ko: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="20" fill="#FFF"/><ellipse cx="10" cy="10" rx="3.5" ry="3.5" fill="#C60C30"/><ellipse cx="13" cy="10" rx="3.5" ry="3.5" fill="#003478"/></svg>,
                tr: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="20" fill="#E30A17"/><ellipse cx="15" cy="10" rx="4" ry="3.5" fill="#FFF"/><ellipse cx="16" cy="10" rx="4" ry="3.5" fill="#E30A17"/></svg>,
                pl: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="10" fill="#FFF"/><rect y="10" width="30" height="10" fill="#DC143C"/></svg>,
                nl: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="6.67" fill="#AE1C28"/><rect y="6.67" width="30" height="6.67" fill="#FFF"/><rect y="13.33" width="30" height="6.67" fill="#21468B"/></svg>,
                id: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="10" fill="#FF0000"/><rect y="10" width="30" height="10" fill="#FFF"/></svg>,
                vi: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="20" fill="#DA251D"/><polygon points="15,3 18,12 9,7 21,7 12,12" fill="#FFCC00"/></svg>,
                bn: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="20" fill="#006A4E"/><circle cx="15" cy="10" r="5" fill="#F42A41"/></svg>,
                th: <svg key="f" viewBox="0 0 30 20" style={{width:18,height:12,verticalAlign:'middle',marginRight:4,borderRadius:2}}><rect width="30" height="20" fill="#241D4F"/><rect y="3" width="30" height="5.5" fill="#FFF"/><rect y="11.5" width="30" height="5.5" fill="#FFF"/><rect y="5" width="30" height="2.5" fill="#ED1C24"/><rect y="12.5" width="30" height="2.5" fill="#ED1C24"/></svg>
              }[l];
              return <button key={l} className="ft-btn" style={{padding:'8px 0',borderRadius:10,fontSize:12,background:cur?'rgba(255,230,109,0.15)':'transparent',borderColor:cur?'#FFE66D':'rgba(255,255,255,0.12)',color:cur?'#FFE66D':'rgba(255,255,255,0.65)',fontWeight:cur?700:400}} onClick={()=>{localStorage.setItem('ft_lang',l);location.reload();}}>{flag}{t('lang.'+l)}</button>;
            })}
          </div>
        </div>
        <div className="ft-set-card"><div className="ft-set-title">{t('admin.pin_change')}</div><div className="ft-field"><input className="ft-input" aria-label={t('admin.pin_new')} type="password" placeholder={t('admin.pin_new')} maxLength={4} value={newPin1} onChange={e=>setNewPin1(e.target.value)} inputMode="numeric"/></div><div className="ft-field"><input className="ft-input" aria-label={t('admin.pin_confirm')} type="password" placeholder={t('admin.pin_confirm')} maxLength={4} value={newPin2} onChange={e=>setNewPin2(e.target.value)} inputMode="numeric"/></div>{pinMsg&&<div style={{color:pinMsg.includes("✅")?"#6BCB77":"#FF6B6B",fontWeight:700,fontSize:11,marginBottom:8}}>{pinMsg}</div>}<button className="ft-btn ft-btn-pri" style={{width:"100%"}} onClick={()=>{if(!/^\d{4}$/.test(newPin1)){setPinMsg(t('admin.pin_must_be_4'));return;}if(newPin1!==newPin2){setPinMsg(t('admin.pin_no_match'));return;}setAdminPin(newPin1);setNewPin1("");setNewPin2("");setPinMsg(t('admin.pin_changed'));setTimeout(()=>setPinMsg(""),2000);}}>{t('admin.pin_save')}</button></div>
        <div className="ft-set-card" style={{borderLeft:'3px solid rgba(78,205,196,0.5)'}}>
          <div className="ft-set-title">{t('settings.game_reward_title')}</div>
          <div style={{fontSize:12,color:'rgba(255,255,255,0.65)',marginBottom:10}}>{t('settings.game_reward_desc')}</div>
          <div style={{display:'flex',alignItems:'center',gap:10}}>
            {[3,5,10,15].map(v => {
              const sel = gameReward === v;
              const set = () => { setGameReward(v); try { storage.set('ft-game-reward', String(v)); showToast(t('settings.game_reward_toast') + ' ' + v + ' ' + t('common.minutes_short'), 'ok'); } catch(e) {} };
              return <button key={v} onClick={set} style={{
                flex:1, padding:'10px 6px', borderRadius:10, fontSize:13, fontWeight:800, cursor:'pointer', fontFamily:'inherit',
                background: sel ? 'rgba(78,205,196,0.15)' : 'rgba(255,255,255,0.05)',
                border: sel ? '2px solid #4ECDC4' : '2px solid rgba(255,255,255,0.1)',
                color: sel ? '#4ECDC4' : 'rgba(255,255,255,0.5)'
              }}>{v} {t('common.minutes_short')}</button>;
            })}
          </div>
          <div style={{fontSize:10,color:'rgba(255,255,255,0.6)',marginTop:8}}>{t('settings.game_reward_current')}: {gameReward} {t('common.minutes_short')}</div>
        </div>
        <div className="ft-set-card"><div className="ft-set-title">{t('settings.ls_title')}</div><div style={{fontSize:12,color:"rgba(255,255,255,0.65)",marginBottom:10,lineHeight:1.5}}>{t('settings.ls_desc')}</div><button className="ft-warn-btn" style={{marginTop:0,background:lsCleared?"rgba(107,203,119,0.1)":"rgba(255,107,107,0.09)",borderColor:lsCleared?"rgba(107,203,119,0.3)":"rgba(255,107,107,0.28)",color:lsCleared?"#6BCB77":"#FF6B6B"}} onClick={clearLs}>{lsCleared?t('settings.ls_cleared'):t('settings.ls_clear_now')}</button></div>
        {children.map(c=>(<div key={c.id} className="ft-set-card"><div className="ft-set-title">{c.emoji} {c.name}</div>{mediaTypes.map(m=>{const used=getMediaUsed(c.id,m.id),lim=c.mediaLimits?.[m.id]||0;return<div key={m.id} className="ft-set-row"><span style={{fontSize:11,color:"rgba(255,255,255,0.55)",fontWeight:700}}>{m.icon} {m.label}</span><span style={{fontSize:11,color:"rgba(255,255,255,0.62)",fontWeight:700}}>{t('media.sub',{label:`${m.icon} ${m.label}`,used,limit:lim})}</span></div>;})}<button className="ft-warn-btn" onClick={()=>{const d=todayStr();setMediaU(p=>{const n={...p};mediaTypes.forEach(mt=>{delete n[`${c.id}_${mt.id}_${d}`];});return n;});showToast(t('settings.reset_media')+' '+c.name,"inf");}}>{t('settings.reset_media')}</button><button className="ft-warn-btn" style={{marginTop:5,borderColor:"rgba(255,165,0,0.3)",color:"#FFB347",background:"rgba(255,165,0,0.07)"}} onClick={()=>{setChildren(p=>p.map(x=>x.id===c.id?{...x,earnedMinutes:0}:x));showToast(t('settings.reset_minutes')+' '+c.name,"inf");}}>{t('settings.reset_minutes')}</button></div>))}</div>);
      }

    function AdminScreen({ui}) {
      const {adminTab,setAdminTab,setScreen,AdminChildren,AdminFines,AdminTermine,AdminMediaTypes}=ui;
      return(<div className="ft-admin"><div className="ft-admin-hdr"><button className="ft-back" style={{background:"rgba(255,255,255,0.07)"}} onClick={()=>setScreen("home")}>←</button><div className="ft-admin-title">{t('admin.title')}</div></div><div className="ft-tabs">{[{id:"children",lbl:t('admin.children_tab')},{id:"tasks",lbl:t('admin.tasks_tab')},{id:"fines",lbl:t('admin.fines_tab')},{id:"termine",lbl:t('admin.termine_tab')},{id:"media",lbl:t('admin.media_tab')},{id:"music",lbl:t('admin.music_tab')},{id:"settings",lbl:t('admin.settings_tab')}].map(t=><button key={t.id} className={`ft-tab ${adminTab===t.id?"active":""}`} onClick={()=>setAdminTab(t.id)}>{t.lbl}</button>)}</div><div className="ft-admin-body">{adminTab==="children"&&<AdminChildren/>}{adminTab==="tasks"&&<AdminTasks ui={ui}/>}{adminTab==="fines"&&<AdminFines/>}{adminTab==="termine"&&<AdminTermine/>}{adminTab==="media"&&<AdminMediaTypes/>}{adminTab==="music"&&<AdminMusicSources ui={ui}/>}{adminTab==="settings"&&<AdminSettings ui={ui}/>}</div></div>);
    }

    function MediaTab({ui}) {
      const {selChild,mediaTypes,getWeekStart,getMediaUsed,getMediaUsedWeek,getWeeklyLimit,getMediaAllowedDays,getMediaTimer,getMediaTimerSecs,getMediaTimerPct,useMedia,startMediaTimer,stopMediaTimer,stopTimer,startTimer}=ui;
      if(!selChild) return null;
      const todayDow=new Date().getDay();
      const ws=getWeekStart();
      return(<>
        <div className="ft-media-hero">
          <div className="ft-media-hero-lbl">{t('media.earned_minutes')}</div>
          <div className="ft-media-hero-val">{selChild.earnedMinutes}</div>
          <div className="ft-media-hero-sub">{t('media.redeem_minutes')}</div>
        </div>
        <div className="ft-media-grid">{mediaTypes.map(m=>{
          const dailyUsed=getMediaUsed(selChild.id,m.id);
          const dailyLim=selChild.mediaLimits?.[m.id]||0;
          const weekUsed=getMediaUsedWeek(selChild.id,m.id);
          const weekLim=getWeeklyLimit(selChild.id,m.id);
          const weekRem=Math.max(0,weekLim-weekUsed);
          const allowedDays=getMediaAllowedDays(selChild.id,m.id);
          const allowedToday=allowedDays.includes(todayDow);
          const pct=m.carryOver?(weekLim>0?Math.min(100,weekUsed/weekLim*100):0):(dailyLim>0?Math.min(100,dailyUsed/dailyLim*100):0);
          return(<div key={m.id} className="ft-mcard" style={{background:allowedToday?m.bg:"rgba(255,255,255,0.03)",opacity:allowedToday?1:0.6}}>
            <div className="ft-mcard-hdr">
              <span className="ft-mcard-icon">{m.icon}</span>
              <div style={{flex:1}}>
                <div className="ft-mcard-title">{m.label}</div>
                <div className="ft-mcard-sub">
                  {allowedToday
                    ? m.carryOver
                      ? t('week.remaining',{used:weekUsed,limit:weekLim})
                      : t('week.today_remaining',{remaining:dailyLim-dailyUsed,limit:dailyLim})
                    : t('media.not_available_today')}
                </div>
              </div>
              {!allowedToday&&<span style={{fontSize:18}}>🔒</span>}
              {m.carryOver&&allowedToday&&<span style={{fontSize:10,fontWeight:800,color:'#FFE66D',background:'rgba(255,230,109,0.1)',border:'1px solid rgba(255,230,109,0.25)',borderRadius:6,padding:'2px 6px',flexShrink:0}}>{t('media.carry_over')}</span>}
            </div>
            <div style={{display:"flex",gap:3,margin:"7px 0 9px",flexWrap:"wrap"}}>
              {DAYS_MON.map((d,di)=>{
                const dow=DAYS_MON_I[di];
                const on=allowedDays.includes(dow);
                const isToday=dow===todayDow;
                return<span key={di} style={{fontSize:10,fontWeight:800,padding:"2px 6px",borderRadius:6,
                  background:on?(isToday?"rgba(255,255,255,0.22)":"rgba(255,255,255,0.09)"):"transparent",
                  color:on?(isToday?"#fff":"rgba(255,255,255,0.65)"):"rgba(255,255,255,0.55)",
                  border:isToday&&on?`1.5px solid ${m.color}`:"1.5px solid transparent"}}>{d}</span>;
              })}
            </div>
            {allowedToday&&<>
              <div className="ft-mprog-bg"><div className="ft-mprog-fill" style={{width:pct+'%',background:m.color}}/></div>
              <div className="ft-mstats">
                {m.carryOver
                  ? <><span>{t('week.week_used',{used:weekUsed,limit:weekLim})}</span><span>{t('week.today_used',{used:dailyUsed})}</span></>
                  : <><span>{t('week.today_used',{used:dailyUsed})}</span><span>{t('week.limit',{limit:dailyLim})}</span></>}
              </div>
              <div className="ft-media-action-group">
                <div className="ft-media-action-label">{t('media.redeem_direct')}</div>
                <div className="ft-mbtns">
                  {[15,30,60].map(mins=>{
                    const budget=m.carryOver?weekRem:Math.max(0,dailyLim-dailyUsed);
                    const ok=selChild.earnedMinutes>=mins&&budget>=mins;
                    return<button key={mins} className={`ft-mbtn ${!ok?"dis":""}`} onClick={()=>ok&&useMedia(selChild.id,m.id,mins)}>{mins} {t('common.minutes_short')}</button>;
                  })}
                  <input className="ft-mbtn ft-mbtn-custom" type="number" min="1" max={selChild.earnedMinutes} placeholder={t('media.custom_minutes')} onKeyDown={e=>{if(e.key==='Enter'){const mins=parseInt(e.target.value)||0;const budget=m.carryOver?weekRem:Math.max(0,dailyLim-dailyUsed);const ok=mins>0&&selChild.earnedMinutes>=mins&&budget>=mins;if(ok){useMedia(selChild.id,m.id,mins);e.target.value='';}}}}/>
                </div>
              </div>
              {/* Media Timer Start Buttons */}
              {(!getMediaTimer(selChild.id)||getMediaTimer(selChild.id).mediaId!==m.id)&&<div className="ft-media-action-group">
                <div className="ft-media-action-label">{t('media.timer_start_label')}</div>
                <div className="ft-mtimer-btns">
                  {[15,30,60].map(mins=>{
                    const budget=m.carryOver?weekRem:Math.max(0,dailyLim-dailyUsed);
                    const ok=selChild.earnedMinutes>=mins&&budget>=mins;
                    return<button key={mins} className={`ft-mtimer-btn ${!ok?"dis":""}`} style={{"--mt-bg":m.color+"22","--mt-border":m.color+"44","--mt-shadow":m.color}} onClick={()=>ok&&startMediaTimer(selChild.id,m.id,mins)}>{t('media.timer_start',{mins})}</button>;
                  })}
                  <input className="ft-mtimer-btn ft-mtimer-btn-custom" type="number" min="1" max={selChild.earnedMinutes} placeholder={t('media.timer_custom')} onKeyDown={e=>{if(e.key==='Enter'){const mins=parseInt(e.target.value)||0;const budget=m.carryOver?weekRem:Math.max(0,dailyLim-dailyUsed);const ok=mins>0&&selChild.earnedMinutes>=mins&&budget>=mins;if(ok){startMediaTimer(selChild.id,m.id,mins);e.target.value='';}}}}/>
                </div>
              </div>}
              {/* Media Timer */}
              {(()=>{
                const mtimer=getMediaTimer(selChild.id);
                if(mtimer&&mtimer.mediaId===m.id){
                  const secs=getMediaTimerSecs(selChild.id);
                  const pct=getMediaTimerPct(selChild.id);
                  const running=secs>0;
                  return(<div className="ft-mtimer" key="timer">
                    <div className="ft-mtimer-hdr">
                      <span>{running?t('media.timer_running'):t('media.timer_expired')}</span>
                      <span style={{fontSize:18,fontWeight:900,fontFamily:'monospace',color:running?m.color:'#FF6B6B'}}>{running?fmtMM(secs):'00:00'}</span>
                    </div>
                    <div className="ft-mtimer-bar-bg"><div className="ft-mtimer-bar-fill" style={{width:pct+'%',background:m.color}}/></div>
                    {running&&<button className="ft-mtimer-stop" style={{"--mt-bg":m.color+"22","--mt-border":m.color+"44","--mt-color":m.color,"--mt-hover":m.color+"40"}} onClick={()=>stopMediaTimer(selChild.id)}>{t('media.timer_stop')}</button>}
                  </div>);
                }
                return null;
              })()}
            </>}
          </div>);
        })}
      </div></>);
      };

    function ChildScreen({ui}) {
      const {selChild,setScreen,setTimerModal,setCustMin,setCustSec,childTab,setChildTab,openBonus,getTodayTasks,isDone,TasksTab,MediaTab,CalendarTab,FinesTab,MoneyTab}=ui;
      if(!selChild)return null;
      const tt=getTodayTasks(selChild.id),done=tt.filter(t=>isDone(selChild.id,t.id)).length,pct=tt.length?(done/tt.length)*100:0;
      return(<div className="ft-child-screen" style={{"--cc":selChild.color,"--ccd":darken(selChild.color,55)}}>
        <div className="ft-child-hdr">
          <div className="ft-child-hdr-top">
            <button className="ft-back" onClick={()=>setScreen("home")}>←</button>
            <span style={{fontSize:26}}>{selChild.image?<img src={selChild.image} style={{width:34,height:34,borderRadius:'50%',objectFit:'cover',border:'2px solid rgba(255,255,255,0.65)',verticalAlign:'middle'}} alt=""/>:selChild.emoji}</span>
            <div className="ft-child-hdr-name">{selChild.name}</div>
            <div className="ft-ctimer-pill" onClick={()=>{setTimerModal(selChild.id);setCustMin("");setCustSec("");}}>
              <span className="ft-ctimer-lbl">{t('child.timer')}</span>
              <span id="dom-ctimer-val" className="ft-ctimer-val">⏱</span>
            </div>
            <div className="ft-clock-sm">
              <div id="dom-child-clock-t" className="ft-clock-sm-t">{new Date().toLocaleTimeString(getLocale(),{hour:"2-digit",minute:"2-digit"})}</div>
              <div id="dom-child-clock-d" className="ft-clock-sm-d">{new Date().toLocaleDateString(getLocale(),{day:"numeric",month:"short"})}</div>
            </div>
          </div>
          <div className="ft-mins-box">
            <div><div className="ft-mins-lbl">{t('child.earned_minutes')}</div><div className="ft-mins-val">{selChild.earnedMinutes}</div><div className="ft-mins-sub">{t('child.total_saved')}</div><div className={`ft-pocket-chip ${childTab==='money'?'active':''}`} onClick={()=>setChildTab("money")}><span>💶</span><span>{fmtCHF(selChild.pocket||0)}</span></div></div>
            <div style={{display:'flex',flexDirection:'column',alignItems:'flex-end',gap:6}}>
              <div style={{textAlign:"right"}}><div className="ft-mins-lbl">{t('child.today')}</div><div style={{fontFamily:"'Fredoka One',sans-serif",fontSize:28,color:"#fff"}}>{t('child.today_progress',{done,total:tt.length})}</div><div className="ft-mins-sub">{t('child.tasks')}</div></div>
              <button className="ft-bonus-btn" onClick={()=>openBonus(selChild.id)}>{t('child.bonus')}</button>
            </div>
          </div>
          <div className="ft-progress-bar"><div className="ft-progress-fill" style={{width:pct+'%'}}/></div>
          <div id="dom-countdown" className="ft-countdown" style={{display:"none"}}>
            <div><div className="ft-countdown-lbl">{t('child.next_task')}</div><div id="dom-cd-name" className="ft-countdown-task"></div></div>
            <div><div className="ft-countdown-sub">{t('child.remaining')}</div><div id="dom-cd-val" className="ft-countdown-val">--:--:--</div></div>
          </div>
        </div>
        <div className="ft-content">
          {childTab==="tasks"&&<TasksTab/>}
          {childTab==="media"&&<MediaTab ui={ui}/>}
          {childTab==="calendar"&&<CalendarTab/>}
          {childTab==="fines"&&<FinesTab/>}
          {childTab==="money"&&<MoneyTab/>}
        </div>
      </div>);
      };

    function FamTask({familyCode='default', familyName='', onLogout, onRename}) {
      const [screen,setScreen]=useState("home");
      const [childTab,setChildTab]=useState("tasks");
      const [adminTab,setAdminTab]=useState("children");
      const [selCid,setSelCid]=useState(null);
      const [children,setChildren]=useState(DEFAULT_CHILDREN);
      const [tasks,setTasks]=useState(DEFAULT_TASKS);
      const [mediaTypes,setMediaTypes]=useState(DEFAULT_MEDIA_TYPES);
      const [fines,setFines]=useState(DEFAULT_FINES);
      const [fineHits,setFineHits]=useState({});
      const [moneyLog,setMoneyLog]=useState([]);        // Kontoauszüge (CHF)
      const [termine,setTermine]=useState([]);         // Kalender-Termine
      const [editTermin,setEditTermin]=useState(null); // null = kein Modal
      const [comps,setComps]=useState({});
      const [mediaU,setMediaU]=useState({});
      const [timers,setTimers]=useState({});
      const [alarms,setAlarms]=useState([]);
      const [timerModal,setTimerModal]=useState(null);
      const [custMin,setCustMin]=useState("");
      const [custSec,setCustSec]=useState("");
      // Media Timer: {cid: {mediaId, startedAt, duration, endsAt, originalMins}}
      const [mediaTimers,setMediaTimers]=useState({});
      const firedRef=useRef({});
      const [adminPin,setAdminPin]=useState("1234");
      const [pinInput,setPinInput]=useState("");
      const [pinErr,setPinErr]=useState(false);
      const [showPin,setShowPin]=useState(false);
      const [adminOpen,setAdminOpen]=useState(false);
      const [editChild,setEditChild]=useState(null);
      const [editTask,setEditTask]=useState(null);
      const [calOffset,setCalOffset]=useState(0);
      const [calDay,setCalDay]=useState(todayStr());
      const [newPin1,setNewPin1]=useState("");
      const [newPin2,setNewPin2]=useState("");
      const [pinMsg,setPinMsg]=useState("");
      const nowRef=useRef(new Date());
      const timersRef=useRef({});
      const childrenRef=useRef(DEFAULT_CHILDREN);
      const compsRef=useRef({});
      const tasksRef=useRef(DEFAULT_TASKS);
      const selCidRef=useRef(null);
      const [loaded,setLoaded]=useState(false);
      const [toast,setToast]=useState(null);
      const toastHideRef=useRef(null);
      const [iCalEvs,setICalEvs]=useState([]);       // iCal-Events vom Server
      const [gcalDay,setGcalDay]=useState(todayStr()); // Global-Kalender selektierter Tag
      const [gcalOff,setGcalOff]=useState(0);          // Monat-Offset für Global-Kalender
      const [gcalFilter,setGcalFilter]=useState(null);  // Kind-Filter im Global-Kalender
      const [gcalView,setGcalView]=useState('week');     // day/week/month
      // Einmalig: Cover aus dem Speicher werfen, die mit einer früheren
      // (fehlerhaften) Suchlogik gefunden wurden – etwa das Cover der
      // Tracknummer "01" oder ein fremdes Album gleichen Namens. Lokal
      // hinzugefügte Dateien und selbst gesetzte Cover bleiben erhalten.
      (()=>{ try{
        if (localStorage.getItem('ft-cover-ver') === '3') return;
        const bereinigen = (key) => {
          const v = localStorage.getItem(key); if (!v) return;
          const arr = JSON.parse(v); if (!Array.isArray(arr)) return;
          arr.forEach(a => {
            if (!a || typeof a !== 'object') return;
            if (a.cover && a.cover.indexOf('data:') !== 0) a.cover = null;
            if (Array.isArray(a.songs)) a.songs.forEach(s => { if (s && s.cover && s.cover.indexOf('data:') !== 0) s.cover = null; });
          });
          localStorage.setItem(key, JSON.stringify(arr));
        };
        bereinigen('ft-al'); bereinigen('ft-ab');
        localStorage.removeItem('ft-cover-cache');   // gefundene Cover neu ermitteln
        localStorage.setItem('ft-cover-ver', '3');
      }catch(e){} })();
      const [audioList,setAudioList]=useState(()=>{try{const v=localStorage.getItem('ft-al');return v?JSON.parse(v):[];}catch(e){return [];}});   // Audio-Playlist (flat)
      const [albums,setAlbums]=useState(()=>{try{const v=localStorage.getItem('ft-ab');return v?JSON.parse(v):[];}catch(e){return [];}});   // Server-Alben (gruppiert)
      const [currentAudio,setCurrentAudio]=useState(null); // Aktuell spielende Audio
      const [playing,setPlaying]=useState(false);
      const [audioLoading,setAudioLoading]=useState(false); // true, solange der Stream noch puffert
      const audioRef=useRef(null);
      const audioTimeRef=useRef(0);
      const audioDurationRef=useRef(0);
      const audioFallbackTriedRef=useRef(false); // PHP-Weg schon versucht?
      const [audioVolume,setAudioVolume]=useState(0.7);

      // ── Jukebox pro Kind (serverseitig synchronisiert) ─────────────────────
      // Die Bibliothek bleibt gemeinsam, aber jeder Knabe hat seinen eigenen
      // Player-Zustand: letzter Titel und eigene Album-Reihenfolge.
      // Schlüssel eines Titels ist die 'src', weil die Laufzeit-uid() der
      // Songs bei jedem Neuladen neu erzeugt wird.
      const [jb,setJb] = useState(()=>{
        try {
          const s = storage.get('ft-jb');
          const v = s ? JSON.parse(s.value) : null;
          return (v && v.kids) ? {lastChild:v.lastChild||'', kids:v.kids||{}} : {lastChild:'', kids:{}};
        } catch(e) { return {lastChild:'', kids:{}}; }
      });
      const saveJb  = (next) => { setJb(next); storage.set('ft-jb', next); };

      // Ansicht 'albums' | 'favs' und gewaehltes Album liegen hier in FamTask
      // und nicht in AudioPlayerScreen: AudioPlayerScreen wird bei jedem
      // Rendern neu erzeugt, ihr useState waere sofort wieder verworfen.
      const [jbView,setJbView] = useState('albums');
      const [jbAlbumSel,setJbAlbumSel] = useState(null);
      const setJbChild = (cid) => saveJb({...jb, lastChild:cid});
      const setJbKid   = (cid, patch) =>
        saveJb({...jb, kids:{...jb.kids, [cid]: {...(jb.kids[cid]||{}), ...patch}}});

      // Aktives Kind: gespeicherte Auswahl, sonst erstes Kind. Faellt die
      // Auswahl weg (Kind geloescht), wird automatisch zurueckgesetzt.
      const jbPick = jb.lastChild || '';
      const jbKid   = children.some(c=>c.id===jbPick) ? jbPick : (children[0]?.id || '');
      const jbKidObj= children.find(c=>c.id===jbKid) || null;
      const jbKidSt = jb.kids[jbKid] || {};
      const [menuOpen,setMenuOpen]=useState(false);
      
      const showToast = (msg, type='ok') => {
        setToast({msg, type});
        if (toastHideRef.current) clearTimeout(toastHideRef.current);
        toastHideRef.current = setTimeout(() => setToast(null), 2600);
      };

      useEffect(()=>{timersRef.current=timers;},[timers]);
      useEffect(()=>{childrenRef.current=children;},[children]);
      useEffect(()=>{compsRef.current=comps;},[comps]);
      useEffect(()=>{tasksRef.current=tasks;},[tasks]);
      useEffect(()=>{selCidRef.current=selCid;},[selCid]);

      useEffect(()=>{try{localStorage.setItem('ft-al',JSON.stringify(audioList));}catch(e){}},[audioList]);
      useEffect(()=>{try{localStorage.setItem('ft-ab',JSON.stringify(albums));}catch(e){}},[albums]);
      useEffect(()=>{if(audioRef.current)audioRef.current.volume=audioVolume;},[audioVolume]);

      const playAudio=(audio)=>{setCurrentAudio(audio);setPlaying(true);setAudioLoading(true);audioFallbackTriedRef.current=false;const a=audioRef.current;if(!a)return;a.pause();a.src=audio.src;a.load();a.play().catch(err=>{if(err.name==='NotAllowedError'){setPlaying(false);setAudioLoading(false);showToast(t('audio.autoplay_blocked'),'err');}else{console.warn('Playback failed:',err.name);}});};
      const pauseAudio=()=>{if(audioRef.current)audioRef.current.pause();setPlaying(false);setAudioLoading(false);};
      const addAudio=(file)=>{const reader=new FileReader();reader.onload=e=>{const aud={id:uid(),name:file.name.replace(/\.[^.]+$/,''),src:e.target.result,duration:0};setAudioList(p=>[...p,aud]);showToast(t('audio.added',{name:aud.name}));};reader.readAsDataURL(file);};
      const deleteAudio=(id)=>{if(currentAudio?.id===id)pauseAudio();setAudioList(p=>p.filter(a=>a.id!==id));setAlbums(p=>{const r=p.map(a=>({...a,songs:a.songs.filter(s=>s.id!==id)}));return r.filter(a=>a.songs.length>0);});};
      const playNext=()=>{const idx=audioList.findIndex(a=>a.id===currentAudio?.id);if(idx>=0&&idx<audioList.length-1)playAudio(audioList[idx+1]);};
      const playPrev=()=>{const idx=audioList.findIndex(a=>a.id===currentAudio?.id);if(idx>0)playAudio(audioList[idx-1]);};

      // ── Audio Helpers (für persistente Player-Leiste) ──
      // Infinity/NaN kommt bei grossen MP3s vor (VBR ohne Xing-Kopf oder
      // Server ohne Content-Length): dann lieber "–:––" als "Infinity:NaN".
      const fmtTime=(sec)=>{if(typeof sec!=='number'||!isFinite(sec)||sec<0)return'–:––';const m=Math.floor(sec/60);const s=Math.floor(sec%60);return m+':'+String(s).padStart(2,'0');};
      // Dauer, die der Browser wirklich kennt: audio.duration, sonst das Ende
      // des puffernbaren Bereichs (bei Infinity erst nach dem Nachladen bekannt).
      const knownDuration=(audio)=>{
        if(!audio)return 0;
        if(isFinite(audio.duration)&&audio.duration>0)return audio.duration;
        try{const n=audio.seekable.length;if(n>0){const e=audio.seekable.end(n-1);if(isFinite(e)&&e>0)return e;}}catch(e){}
        return 0;
      };
      const refreshDuration=()=>{
        const audio=audioRef.current;if(!audio)return;
        const d=knownDuration(audio);audioDurationRef.current=d;
        const txt=d>0?fmtTime(d):'–:––';
        ['dom-player-dur','jb-player-dur'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent=txt;});
      };
      const handleProgressClick=(e)=>{const audio=audioRef.current;if(!audio)return;const dur=knownDuration(audio)||audioDurationRef.current;if(!dur||!isFinite(dur)||dur<=0)return;const rect=e.currentTarget.getBoundingClientRect();const pct=Math.max(0,Math.min(1,(e.clientX-rect.left)/rect.width));audio.currentTime=pct*dur;};

      // ── Persistente Audio-Event-Listener (immer aktiv, auch ausserhalb AudioPlayerScreen) ──
      useEffect(()=>{
        const audio=audioRef.current;
        if(!audio||!currentAudio)return;
        const handleTimeUpdate=()=>{
          audioTimeRef.current=audio.currentTime;
          const t=document.getElementById('dom-player-time');
          if(t)t.textContent=fmtTime(audio.currentTime);
          const b=document.getElementById('dom-player-bar');
          if(b){const d=audioDurationRef.current;b.style.width=(d&&d>0?(audio.currentTime/d)*100:0)+'%';}
          // Zweiter Fortschrittsbalken: schwebender Mini-Player der Jukebox
          const mt=document.getElementById('jb-player-time');
          if(mt)mt.textContent=fmtTime(audio.currentTime);
          const mb=document.getElementById('jb-player-bar');
          if(mb){const d=audioDurationRef.current;mb.style.width=(d&&d>0?(audio.currentTime/d)*100:0)+'%';}
          // Dauer mitziehen: bei grossen Dateien wird sie erst nachgeladen
          refreshDuration();
        };
        const handleLoadedMetadata=()=>{refreshDuration();};
        const handleEnded=()=>{setPlaying(false);setAudioLoading(false);const idx=audioList.findIndex(a=>a.id===currentAudio?.id);if(idx>=0&&idx<audioList.length-1)playAudio(audioList[idx+1]);};
        const handleError=(e)=>{
          console.error('Audio error:',e);
          // Direkter Dateizugriff gescheitert (z. B. audio/ gesperrt) → einmal
          // ueber jukebox.php versuchen, bevor wir aufgeben.
          const fb=currentAudio&&currentAudio.srcFallback;
          if(fb&&!audioFallbackTriedRef.current){
            audioFallbackTriedRef.current=true;
            console.warn('Direkter Dateizugriff fehlgeschlagen, nutze jukebox.php');
            audio.src=fb;
            audio.load();
            audio.play().catch(()=>{setPlaying(false);setAudioLoading(false);});
            return;
          }
          setPlaying(false);setAudioLoading(false);showToast(t('audio.could_not_load'),'err');
        };
        const handleCanPlay=()=>{setAudioLoading(false);refreshDuration();if(playing&&audioRef.current)audioRef.current.play().catch(err=>{console.warn('Playback failed:',err.name);setPlaying(false);});};
        const handleWaiting=()=>setAudioLoading(true);
        const handlePlaying=()=>{setAudioLoading(false);refreshDuration();};
        audio.addEventListener('timeupdate',handleTimeUpdate);
        audio.addEventListener('loadedmetadata',handleLoadedMetadata);
        // durationchange/progress: bei grossen Dateien meldet der Browser die
        // Dauer erst nach dem Nachladen (vorher Infinity) – Anzeige nachziehen.
        audio.addEventListener('durationchange',refreshDuration);
        audio.addEventListener('progress',refreshDuration);
        audio.addEventListener('ended',handleEnded);
        audio.addEventListener('error',handleError);
        audio.addEventListener('canplay',handleCanPlay);
        audio.addEventListener('waiting',handleWaiting);
        audio.addEventListener('playing',handlePlaying);
        return()=>{audio.removeEventListener('timeupdate',handleTimeUpdate);audio.removeEventListener('loadedmetadata',handleLoadedMetadata);audio.removeEventListener('durationchange',refreshDuration);audio.removeEventListener('progress',refreshDuration);audio.removeEventListener('ended',handleEnded);audio.removeEventListener('error',handleError);audio.removeEventListener('canplay',handleCanPlay);audio.removeEventListener('waiting',handleWaiting);audio.removeEventListener('playing',handlePlaying);};
      },[currentAudio,audioList,playing]);

      // Nach JEDEM Render die Player-Anzeigen aus den Refs setzen: die
      // Jukebox-Ansicht wird bei jedem Eltern-Render neu aufgebaut (schon die
      // Uhrzeit im Kopf loest das sekundlich aus). Dabei entstehen Zeit- und
      // Dauer-Spans mit ihren Startwerten neu – ohne diesen Abgleich wuerde
      // die Anzeige waehrend der Wiedergabe auf 0:00 zurueckspringen.
      useEffect(()=>{
        const a=audioRef.current;
        const pos=a?a.currentTime:audioTimeRef.current;
        const txt=fmtTime(pos||0);
        ['dom-player-time','jb-player-time'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent=txt;});
        const d=knownDuration(a)||audioDurationRef.current;
        const pct=(a&&d>0)?Math.min(100,(a.currentTime/d)*100):0;
        ['dom-player-bar','jb-player-bar'].forEach(id=>{const el=document.getElementById(id);if(el)el.style.width=pct+'%';});
        refreshDuration();
      });

      

      // ─────────────────────────────────────────────────────────────────────────
// 🎵 FIXED: AudioPlayerScreen mit vollständigem Error-Handling
// ─────────────────────────────────────────────────────────────────────────


const AudioPlayerScreen = () => {
  useEffect(() => {
    if (audioList.length === 0) loadServerTracks();
  }, []);

  // ── Audio hinzufügen mit Validierung ──
  const addAudio = (file) => {
    if (!file) return;

    // Validierung
    const maxSize = 50 * 1024 * 1024; // 50MB
    if (file.size > maxSize) {
      showToast(t('audio.file_too_large'), 'err');
      return;
    }

    const reader = new FileReader();
    reader.onload = (e) => {
      try {
        const aud = {
          id: uid(),
          name: file.name.replace(/\.[^.]+$/, ''),
          src: e.target.result,
          duration: 0,
          cover: null
        };
        setAudioList(p => [...p, aud]);
        showToast(t('audio.added',{name:aud.name}));
        fetchCovers([aud]);
      } catch (err) {
        console.error('Audio load error:', err);
        showToast(t('audio.could_not_load'), 'err');
      }
    };
    reader.onerror = () => {
      showToast(t('audio.could_not_read'), 'err');
    };
    reader.readAsDataURL(file);
  };
  // ── Server-Tracks laden (von jukebox.php) ──
  const loadServerTracks = async () => {
    // AudioPlayerScreen wird bei jedem FamTask-Render neu erzeugt und mountet
    // dabei erneut. Ohne diesen Wächter starten mehrere Ladevorgaenge parallel;
    // der spaetere Lauf setzt albums/audioList zurueck auf cover:null und
    // verwirft die bereits geladenen Cover.
    if (_tracksLoading) return;
    _tracksLoading = true;
    try {
      const r = await fetch('jukebox.php?action=tracks');
      const data = await r.json();
      if (data && data.albums && data.albums.length) {
        const tracks = [];
        const albumData = data.albums.map(a => ({
          album: a.album,
          localCover: a.localCover || '',
          songs: (a.songs || []).map(s => ({
            id: uid(),
            name: (a.album !== 'Singles' && a.album ? a.album + ' — ' : '') + s.title,
            src: s.srcStatic || s.src,
            srcFallback: s.srcStatic ? s.src : '',
            duration: 0,
            title: s.title,
            artist: s.artist || '',
            albumTag: s.albumTag || '',
            file: s.file || '',
            hasCover: !!s.hasCover,
            album: a.album,
            // Album-Cover (Datei oder aus den Metadaten) gilt für alle Songs
            cover: a.localCover || null
          }))
        }));
        albumData.forEach(album => {
          album.songs.forEach(s => tracks.push({...s}));
        });
        setAlbums(albumData);
        setAudioList(tracks);
        showToast(t('audio.loaded_tracks',{count:tracks.length}));
        setTimeout(() => fetchCovers(tracks), 500);
      } else {
        showToast(t('audio.no_server_tracks'), 'err');
      }
    } catch (e) {
      showToast(t('audio.server_unreachable',{msg:e.message}), 'err');
    } finally {
      _tracksLoading = false;
    }
  };

  // ── Platzhalter-Cover aus Song-Titel generieren (buntes SVG) ──
  const genCover = (name) => {
    const s = (name||'?').charAt(0).toUpperCase();
    let h = 0; for(let i=0;i<name.length;i++) h=((h<<5)-h)+name.charCodeAt(i),h=h&h;
    const c1='hsl('+(Math.abs(h)%360)+',60%,45%)',c2='hsl('+((Math.abs(h)+40)%360)+',55%,35%)';
    return 'data:image/svg+xml,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="'+c1+'"/><stop offset="100%" stop-color="'+c2+'"/></linearGradient></defs><rect width="100" height="100" fill="url(#g)" rx="16"/><text x="50" y="62" text-anchor="middle" fill="rgba(255,255,255,0.85)" font-family="Arial,sans-serif" font-size="44" font-weight="bold">'+s+'</text></svg>');
  };

  // ── Cover-Bilder passend zu den Songs suchen ──
  // Reihenfolge: Album-Cover (Datei oder aus den Musik-Metadaten) → eine Suche
  // pro Album → farbiger Platzhalter. Der Server prüft jeden Treffer gegen den
  // gesuchten Namen und liefert lieber gar nichts als ein unpassendes Bild.
  const coverCache = (()=>{ try{ return JSON.parse(localStorage.getItem('ft-cover-cache')||'{}')||{}; }catch(e){ return {}; } })();
  const coverCacheSave = () => { try{ localStorage.setItem('ft-cover-cache', JSON.stringify(coverCache)); }catch(e){} };
  const needsCover    = (t) => !t.cover || t.cover.indexOf('data:') === 0;
  const coverTitleOf  = (t) => (t.title || t.name || '').replace(/\.[a-z0-9]{2,4}$/i,'').trim();
  const tagCoverUrl   = (t) => 'jukebox.php?action=tagcover&album='+encodeURIComponent(t.album||'')+'&file='+encodeURIComponent(t.file||'');
  // Wichtig: Bei Server-Tracks zeigt die UI 'album.songs' an, nicht 'audioList'.
  // Ohne dieses Spiegeln bleibt der Album-Baum bei cover:null.
  const setCoverToList = (track, url) => {
    setAudioList(p => p.map(a => a.id === track.id ? {...a, cover: url} : a));
    setAlbums(p => p.map(al => ({ ...al, songs: al.songs.map(s => s.id === track.id ? {...s, cover: url} : s) })));
  };
  /** Cover für ein ganzes Album setzen: Kachel und alle Songs ohne eigenes Bild. */
  const setAlbumCover = (album, url) => {
    setAlbums(p => p.map(al => al.album === album ? {
      ...al,
      localCover: al.localCover || url,
      songs: al.songs.map(s => s.cover ? s : {...s, cover: url})
    } : al));
  };
  /** Cover-Suche mit Zwischenspeicher: Treffer dauerhaft, Fehlschlag 1 Tag. */
  const searchCover = async (key, params) => {
    const c = coverCache[key];
    if (c) {
      if (c.url) return c.url;
      if (Date.now() - (c.ts||0) < 86400000) return null;
    }
    let url = null;
    try {
      const qs = Object.keys(params).filter(k => params[k]).map(k => k+'='+encodeURIComponent(params[k])).join('&');
      const r = await fetch('jukebox.php?action=cover&'+qs);
      if (r.ok) { const d = await r.json(); if (d && d.coverUrl) url = d.coverUrl; }
    } catch(e) {}
    coverCache[key] = { url: url || '', ts: Date.now() };
    coverCacheSave();
    return url;
  };
  const fetchCovers = async (tracks) => {
    if (_coversBusy) return;
    _coversBusy = true;
    try {
      const need = tracks.filter(needsCover);
      if (need.length === 0) return;
      // Sofort farbige Platzhalter setzen, damit nichts leer bleibt
      need.forEach(t => setAudioList(p => p.map(a => a.id === t.id ? {...a, cover: genCover(coverTitleOf(t) || t.album || '?')} : a)));

      const albumCovers = {};   // Album -> Cover-URL oder ''
      for (const t of need) {
        const isSingle = !t.album || t.album === 'Singles';
        if (isSingle) {
          // Lose Datei: eingebettetes Bild oder Suche nach dem Titel
          const url = t.hasCover
            ? tagCoverUrl(t)
            : await searchCover('ttl:'+coverTitleOf(t).toLowerCase(), { title: coverTitleOf(t), artist: t.artist });
          if (url) setCoverToList(t, url);
          continue;
        }
        if (!(t.album in albumCovers)) {
          // Ein Bild aus den Metadaten hat Vorrang, sonst einmal pro Album suchen
          const mitBild = need.find(x => x.album === t.album && x.hasCover);
          let url = mitBild ? tagCoverUrl(mitBild) : null;
          if (!url) url = await searchCover('alb:'+t.album.toLowerCase(), { album: t.album, albumTag: t.albumTag, artist: t.artist });
          albumCovers[t.album] = url || '';
          if (url) setAlbumCover(t.album, url);
        }
        if (albumCovers[t.album]) { setCoverToList(t, albumCovers[t.album]); continue; }
        // Kein Album gefunden – der Ordner ist dann kein Album, sondern nur
        // eine Sammlung ("Mami", "Papi"). Deshalb nach dem Titel suchen; der
        // Dateiname "Interpret - Titel (Zusatz)" wird serverseitig zerlegt.
        // Gilt nur für diesen einen Titel, nicht für das ganze Album.
        const turl = await searchCover('ttl:'+coverTitleOf(t).toLowerCase(), { title: coverTitleOf(t), artist: t.artist });
        if (turl) setCoverToList(t, turl);
      }
    } finally { _coversBusy = false; }
  };
  // ── Auto-load Server-Tracks wenn Liste leer ──
  useEffect(() => {
    if (audioList.length === 0) loadServerTracks();
  }, []);

  // ── Beim Laden auch Platzhalter-Covers durch echte ersetzen ──
  useEffect(() => {
    if (audioList.length === 0) return;
    const needReal = audioList.filter(a => !a.cover || a.cover.indexOf('data:')===0);
    if (needReal.length > 0) setTimeout(() => fetchCovers(needReal), 500);
  }, []);

  // ── Pro-Kind-Zustand ──────────────────────────────────────────────────────
  // jbAlbumSel und jbView kommen aus FamTask, nicht von hier.

  // Alben in der Reihenfolge, die dieses Kind zuletzt angefasst hat.
  const albumList = (()=>{
    const byName = {};
    albums.forEach(a=>{ byName[a.album] = a; });
    const out = [];
    (Array.isArray(jbKidSt.order) ? jbKidSt.order : []).forEach(nm=>{
      if (byName[nm]) { out.push(byName[nm]); delete byName[nm]; }
    });
    albums.forEach(a=>{ if (byName[a.album]) out.push(a); });
    return out;
  })();

  // ── Favoriten: pro Kind eine Liste von 'src' (stabil ueber Neuladen) ────
  const favs = Array.isArray(jbKidSt.favs) ? jbKidSt.favs : [];
  const isFav = (audio) => favs.indexOf(audio.src) >= 0;
  const toggleFav = (audio) => {
    const next = isFav(audio) ? favs.filter(x=>x!==audio.src) : [audio.src, ...favs];
    setJbKid(jbKid, { favs:next });
  };

  // Alle Titel der Bibliothek in Favoriten-Reihenfolge, Album als Gruppe.
  const favTracks = favs
    .map(src=>audioList.find(a=>a.src===src))
    .filter(Boolean);

  // Beim Kindwechsel zuruecksetzen, damit kein fremdes Album haengenbleibt.
  useEffect(()=>{ setJbAlbumSel(null); },[jbKid]);

  const jbAlbumName = albumList.some(a=>a.album===jbAlbumSel) ? jbAlbumSel
                   : (albumList.some(a=>a.album===jbKidSt.album) ? jbKidSt.album
                   : (albumList[0] ? albumList[0].album : ''));
  const jbAlbum = albumList.find(a=>a.album===jbAlbumName) || null;

  const selectAlbum = (nm) => {
    setJbAlbumSel(nm);
    const prev = Array.isArray(jbKidSt.order) ? jbKidSt.order : [];
    setJbKid(jbKid, { album:nm, order:[nm, ...prev.filter(x=>x!==nm)].slice(0,20) });
  };

  // Abspielen und dabei den letzten Titel dieses Kindes merken.
  const playAndRemember = (audio) => {
    playAudio(audio);
    setJbKid(jbKid, {
      lastSrc:   audio.src,
      lastTitle: audio.title || audio.name || '',
      lastAlbum: audio.album || '',
      lastCover: audio.cover || ''
    });
  };

  const jbHex = (jbKidObj && jbKidObj.color) || '';
  const jbAccent = /^#[0-9a-fA-F]{6}$/.test(jbHex) ? jbHex : '#4ECDC4';
  const jbStyle = {
    '--jb':jbAccent,
    '--jbc':jbAccent,
    '--jbb':'rgba('+h2r(jbAccent)+',0.16)',
    '--jbs':'rgba('+h2r(jbAccent)+',0.32)'
  };

  const songRow = (audio, i, removable) => {
    const on = currentAudio && currentAudio.id === audio.id;
    const fav = isFav(audio);
    return (
      <div key={audio.id} className={'ft-jb-row'+(on?' on':'')} onClick={()=>playAndRemember(audio)}>
        <div className="ft-jb-row-n">{i+1}</div>
        {audio.cover
          ? <img src={audio.cover} alt="" className="ft-jb-row-c" loading="lazy"/>
          : <div className="ft-jb-row-cf">🎵</div>}
        <div style={{flex:1,minWidth:0}}>
          <div className="ft-jb-row-t">{audio.title || audio.name}</div>
          <div className="ft-jb-row-s">{audio.album || t('audio.click_to_play')}</div>
        </div>
        <button className={'ft-jb-fav'+(fav?' on':'')}
          title={fav ? t('audio.remove_fav') : t('audio.add_fav')}
          onClick={(e)=>{e.stopPropagation();toggleFav(audio);}}>{fav?'❤️':'🤍'}</button>
        {removable
          ? <button className="ft-jb-row-b"
              style={{background:'rgba(255,107,107,0.15)',color:'#FF6B6B'}}
              onClick={(e)=>{e.stopPropagation();deleteAudio(audio.id);}}>🗑</button>
          : <button className="ft-jb-row-b"
              onClick={(e)=>{e.stopPropagation();on&&playing?pauseAudio():playAndRemember(audio);}}>
              {on && audioLoading ? '⏳' : (on&&playing?'⏸':'▶')}
            </button>}
      </div>
    );
  };

  // ── Main JSX ──────────────────────────────────────────────────────────────
  return (
    <div className="ft-jb" style={jbStyle}>
      {/* Kopf mit Kind-Auswahl */}
      <div className="ft-jb-head">
        <div style={{display:'flex',alignItems:'center',gap:10}}>
          <button className="ft-back" onClick={()=>setScreen('home')}>←</button>
          <div style={{flex:1,minWidth:0}}>
            <div className="ft-jb-title">{t('audio.title')}</div>
            <div className="ft-jb-sub">
              {jbKidObj ? t('audio.for_child',{name:jbKidObj.name}) : t('audio.pick_child')}
            </div>
          </div>
          <button
            className="ft-jb-row-b"
            style={{background:'rgba(78,205,196,0.14)',color:'#4ECDC4',width:32,height:32}}
            onClick={loadServerTracks}
            title={t('audio.reload')}
          >⟳</button>
        </div>

        {children.length>0 && (
          <div className="ft-jb-kids">
            {children.map(c=>{
              const hex = /^#[0-9a-fA-F]{6}$/.test(c.color||'') ? c.color : '#4ECDC4';
              return (
                <button
                  key={c.id}
                  className={'ft-jb-chip'+(c.id===jbKid?' on':'')}
                  style={{'--jbc':hex,'--jbb':'rgba('+h2r(hex)+',0.16)','--jbs':'rgba('+h2r(hex)+',0.32)'}}
                  onClick={()=>setJbChild(c.id)}
                >
                  <span className="ft-jb-chip-av" style={{background:c.color}}>{c.emoji||'🎵'}</span>
                  {c.name}
                </button>
              );
            })}
          </div>
        )}

        {/* Alben / Meine Lieder */}
        <div className="ft-jb-tabs">
          <button className={'ft-jb-tab'+(jbView==='albums'?' on':'')}
            onClick={()=>setJbView('albums')}>{t('audio.view_albums')}</button>
          <button className={'ft-jb-tab'+(jbView==='favs'?' on':'')}
            onClick={()=>setJbView('favs')}>
            {t('audio.view_favs')}
            {favTracks.length>0 && <span className="ft-jb-tab-b">{favTracks.length}</span>}
          </button>
        </div>
      </div>

      <div style={{flex:1}}>
        {albums.length===0 && audioList.length===0 ? (
          <div className="ft-jb-empty" style={{marginTop:44}}>
            <div style={{fontSize:46,marginBottom:12}}>🎶</div>
            {t('audio.no_files')}
          </div>
        ) : jbView==='favs' ? (
          /* ── Ansicht: Favoriten dieses Kindes ── */
          <div className="ft-jb-list" style={{padding:'0 14px'}}>
            <div className="ft-jb-lbl">
              ❤️ {t('audio.view_favs')}
              {favTracks.length>0 && (
                <span style={{color:'rgba(255,255,255,0.3)'}}>
                  {t('audio.fav_count',{count:favTracks.length})}
                </span>
              )}
            </div>
            {favTracks.length===0
              ? <div className="ft-jb-empty" style={{padding:'26px 10px'}}>{t('audio.no_favs')}</div>
              : favTracks.map((audio,i)=>songRow(audio,i,false))}
          </div>
        ) : (
          <>
            {/* Waagerechtes Album-Cover-Carousel */}
            {albumList.length>0 && (
              <div className="ft-jb-car" style={{marginTop:16}}>
                {albumList.map(a=>{
                  const hit = a.songs.find(s=>s.cover);
                  const cov = a.localCover || (hit ? hit.cover : '');
                  return (
                    <button key={a.album}
                      className={'ft-jb-card'+(a.album===jbAlbumName?' on':'')}
                      onClick={()=>selectAlbum(a.album)}>
                      {cov
                        ? <img src={cov} alt="" className="ft-jb-cover" loading="lazy"/>
                        : <div className="ft-jb-cover-fb">📀</div>}
                      <div className="ft-jb-card-t">{a.album}</div>
                      <div className="ft-jb-card-s">{t('audio.tracks_count',{count:a.songs.length})}</div>
                    </button>
                  );
                })}
              </div>
            )}

            {/* Schlanke Titelliste */}
            {jbAlbum ? (
              <div className="ft-jb-list" style={{padding:'0 14px'}}>
                <div className="ft-jb-lbl">🎧 {jbAlbum.album}</div>
                {jbAlbum.songs.map((audio,i)=>songRow(audio,i,false))}
              </div>
            ) : (
              <div className="ft-jb-list" style={{padding:'0 14px'}}>
                <div className="ft-jb-lbl">{t('audio.local_tracks',{count:audioList.length})}</div>
                {audioList.map((audio,i)=>songRow(audio,i,true))}
              </div>
            )}

            {/* Lokal hinzufügen */}
            <div style={{padding:'14px'}}>
              <label style={{
                display:'flex',alignItems:'center',justifyContent:'center',gap:8,
                padding:'12px',borderRadius:13,cursor:'pointer',
                fontFamily:"'Nunito',sans-serif",fontSize:13,fontWeight:800,color:'#6BCB77',
                background:'rgba(107,203,119,0.1)',border:'1.5px dashed rgba(107,203,119,0.35)'
              }}>
                ➕ {t('audio.add_audio')}
                <input type="file" accept="audio/*"
                  onChange={(e)=>e.target.files[0] && addAudio(e.target.files[0])}
                  style={{display:'none'}}/>
              </label>
            </div>
          </>
        )}
      </div>

      {/* Schwebender Mini-Player */}
      {currentAudio && (
        <div className="ft-jb-mini">
          {currentAudio.cover
            ? <img src={currentAudio.cover} alt="" className="ft-jb-mini-c"/>
            : <div className="ft-jb-mini-cf">🎵</div>}
          <div style={{flex:1,minWidth:0}}>
            <div className="ft-jb-mini-t">{currentAudio.title || currentAudio.name}</div>
            <div className="ft-jb-mini-s">
              {jbKidObj?jbKidObj.name:''}{currentAudio.album?' · '+currentAudio.album:''}
            </div>
            <div className="ft-jb-prog" onClick={handleProgressClick}>
              <div id="jb-player-bar" className="ft-jb-prog-b"
                style={{background:'linear-gradient(90deg,'+jbAccent+',#FFE66D)'}}/>
            </div>
            <div className="ft-jb-prog-t">
              <span id="jb-player-time">0:00</span>
              <span id="jb-player-dur">–:––</span>
            </div>
          </div>
          <button className="ft-jb-row-b" style={{width:28,height:28,fontSize:11}}
            onClick={playPrev} title={t('audio.previous')}>⏮</button>
          <button className="ft-jb-mini-b"
            title={audioLoading ? t('common.loading') : undefined}
            onClick={()=>{ playing ? pauseAudio() : playAudio(currentAudio); }}>
            {audioLoading ? '⏳' : (playing?'⏸':'▶')}
          </button>
          <button className="ft-jb-row-b" style={{width:28,height:28,fontSize:11}}
            onClick={playNext} title={t('audio.next')}>⏭</button>
        </div>
      )}

      <div style={{height:150}}/>
    </div>
  );
};

// ── GAME COMPONENTS ──────────────────────────────────────────────────
const GameScreen = ({ onBack }) => {
  const [gameActive, setGameActive] = useState(null);
  const [gameChildId, setGameChildId] = useState(selCid || children[0]?.id || '');
  const gameScores = {};
  try { const gs = storage.get('ft-game-scores'); if (gs) Object.assign(gameScores, JSON.parse(gs.value)); } catch(e) {}
  const getChildScore = (cid, gname) => gameScores[cid]?.[gname] || null;
  const saveGameScore = (cid, gname, score) => {
    const old = gameScores[cid] || {};
    const prev = old[gname] || { bestScore:0, totalScore:0, gamesPlayed:0 };
    const upd = { bestScore: Math.max(prev.bestScore, score), totalScore: prev.totalScore + score, gamesPlayed: prev.gamesPlayed + 1, lastPlayed: new Date().toISOString() };
    gameScores[cid] = { ...gameScores[cid], [gname]: upd };
    try { storage.set('ft-game-scores', JSON.stringify(gameScores)); } catch(e) {}
    let reward = 5;
    try { const v = storage.get('ft-game-reward'); if (v) reward = parseInt(v.value) || 5; } catch(e) {}
    reward = Math.max(0, Math.min(60, reward));
    if (reward > 0) {
      setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:(c.earnedMinutes||0)+reward}:c));
      const child = children.find(c=>c.id===cid);
      if (child) _trackEvent('task_done', cid, child.name, 'game_'+gname, '🎮 '+gname, reward);
    }
  };
  if (!gameChildId) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.title')}</div></div>
      <div className="ft-game-body"><div className="ft-empty">{t('games.no_child_yet')}</div></div>
    </div>
  );
  if (gameActive === 'mathe') return <MathGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'mathe',s)}/>;
  if (gameActive === 'memory') return <MemoryGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'memory',s)}/>;
  if (gameActive === 'worte') return <WordGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'worte',s)}/>;
  if (gameActive === 'ttt') return <TicTacToeGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'ttt',s)}/>;
  if (gameActive === 'geo') return <GeoQuizGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'geo',s)}/>;
  if (gameActive === 'simon') return <SimonGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'simon',s)}/>;
  if (gameActive === 'clock') return <ClockGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'clock',s)}/>;
  if (gameActive === 'hangman') return <HangmanGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'hangman',s)}/>;
  if (gameActive === 'memory-recall') return <MemoryRecallGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'memory-recall',s)}/>;
  if (gameActive === 'scramble') return <ScrambleGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'scramble',s)}/>;
  if (gameActive === 'dice') return <DiceCountGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'dice',s)}/>;
  if (gameActive === 'opposites') return <OppositesGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'opposites',s)}/>;
  if (gameActive === 'compare') return <CompareGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'compare',s)}/>;
  if (gameActive === 'animals') return <AnimalQuizGame childId={gameChildId} onBack={()=>setGameActive(null)} onSave={(s)=>saveGameScore(gameChildId,'animals',s)}/>;
  const child = children.find(c => c.id === gameChildId);
  const lvl = getGameLevel(child);
  const GAMES = [
    { id:'mathe', icon:'🧮', name:t('games.name.math'), desc:lvl===1?t('games.desc.math_easy'):lvl===2?t('games.desc.math_medium'):t('games.desc.math_hard') },
    { id:'memory', icon:'🧠', name:t('games.name.memory'), desc:lvl===1?t('games.desc.memory_easy'):lvl===2?t('games.desc.memory_medium'):t('games.desc.memory_hard') },
    { id:'worte', icon:'📚', name:t('games.name.word'), desc:lvl===1?t('games.desc.word_easy'):lvl===2?t('games.desc.word_medium'):t('games.desc.word_hard') },
    { id:'ttt', icon:'🕹️', name:t('games.name.ttt'), desc:t('games.desc.ttt') },
    { id:'geo', icon:'🌍', name:t('games.name.geo'), desc:t('games.desc.geo') },
    { id:'simon', icon:'🔴', name:t('games.name.simon'), desc:t('games.desc.simon') },
    { id:'clock', icon:'🕐', name:t('games.name.clock'), desc:t('games.desc.clock') },
    { id:'hangman', icon:'💀', name:t('games.name.hangman'), desc:t('games.desc.hangman') },
    { id:'memory-recall', icon:'🎯', name:t('games.name.recall'), desc:t('games.desc.recall') },
    { id:'scramble', icon:'🔤', name:t('games.name.scramble'), desc:t('games.desc.scramble') },
    { id:'dice', icon:'🎲', name:t('games.name.dice'), desc:t('games.desc.dice') },
    { id:'opposites', icon:'🔄', name:t('games.name.opposites'), desc:t('games.desc.opposites') },
    { id:'compare', icon:'⚖️', name:t('games.name.compare'), desc:t('games.desc.compare') },
    { id:'animals', icon:'🐾', name:t('games.name.animals'), desc:t('games.desc.animals') },
  ];
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr">
        <button className="ft-game-back" onClick={onBack}>←</button>
        <div className="ft-game-title">{t('games.title')}</div>
        <div className="ft-game-sub">{child ? child.emoji+' '+child.name : ''} · {t('games.difficulty_'+['easy','medium','hard'][lvl-1])}</div>
      </div>
      <div className="ft-game-body">
        {children.length > 1 && <div style={{display:'flex',gap:6,marginBottom:12,flexWrap:'wrap',justifyContent:'center'}}>
          {children.map(c => (
            <button key={c.id} onClick={()=>setGameChildId(c.id)} style={{
              padding:'6px 14px',borderRadius:50,fontSize:12,fontWeight:800,cursor:'pointer',fontFamily:'inherit',
              background:c.id===gameChildId ? c.color : 'rgba(255,255,255,0.06)',
              border:c.id===gameChildId ? '2px solid '+c.color : '2px solid rgba(255,255,255,0.1)',
              color:c.id===gameChildId ? '#0D0E1A' : 'rgba(255,255,255,0.62)'
            }}>{c.emoji} {c.name}</button>
          ))}
        </div>}
        <div className="ft-game-grid">
          {GAMES.map(g => {
            const sc = getChildScore(gameChildId, g.id);
            return (
              <div key={g.id} className="ft-game-tile" onClick={()=>setGameActive(g.id)}>
                <div className="ft-game-tile-icon">{g.icon}</div>
                <div className="ft-game-tile-name">{g.name}</div>
                <div className="ft-game-tile-desc">{g.desc}</div>
                {sc && <div className="ft-game-tile-stat">{t('games.best_score',{score:sc.bestScore,plays:sc.gamesPlayed})}</div>}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
};

const MathGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const cfg = lvl === 1 ? { max:20, ops:['+'], qs:5 } : lvl === 2 ? { max:60, ops:['+','-'], qs:8 } : { max:100, ops:['+','-','*'], qs:10 };
  const [qs, setQs] = useState([]); const [cur, setCur] = useState(0); const [score, setScore] = useState(0); const [done, setDone] = useState(false);
  const [input, setInput] = useState(''); const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  useEffect(() => {
    const qs = []; for (let i = 0; i < cfg.qs; i++) {
      const op = cfg.ops[Math.floor(Math.random()*cfg.ops.length)];
      let a = Math.floor(Math.random()*cfg.max)+1, b = Math.floor(Math.random()*cfg.max)+1;
      if (op === '-') { if (a < b) [a,b]=[b,a]; qs.push({q:`${a} - ${b}`, a:a-b}); }
      else if (op === '*') qs.push({q:`${a} × ${b}`, a:a*b});
      else qs.push({q:`${a} + ${b}`, a:a+b});
    } setQs(qs);
  }, []);
  const check = () => {
    if (locked || !qs.length) return; const v = parseInt(input); if (isNaN(v)) return;
    setLocked(true);
    if (v === qs[cur].a) { setScore(s=>s+10); setFb('ok'); }
    else setFb('err');
    setTimeout(() => {
      if (cur + 1 >= qs.length) { setDone(true); onSave(score + (v === qs[cur].a ? 10 : 0)); }
      else { setCur(c=>c+1); setInput(''); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.mathe.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score >= cfg.qs*10*0.8 ? '⭐⭐⭐' : score >= cfg.qs*10*0.5 ? '⭐⭐' : score >= cfg.qs*10*0.3 ? '⭐' : '💪'}</div>
        <div className="ft-game-finish-msg">{score >= cfg.qs*10*0.8 ? t('games.praise_excellent') : score >= cfg.qs*10*0.5 ? t('games.praise_good') : t('games.praise_practice')}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.mathe.title')}</div><div className="ft-game-sub">{cur+1}/{cfg.qs}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:(cur/(cfg.qs||1))*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        {qs.length > 0 && <div className="ft-game-question" style={{padding:'36px 20px'}}>
          <div className="ft-game-qtext" style={{fontSize:36}}>{qs[cur].q} = ?</div>
          <div style={{display:'flex',gap:10,justifyContent:'center',marginTop:16,flexWrap:'wrap'}}>
            <input className="ft-game-input" style={{fontSize:32,maxWidth:160}} value={input} onChange={e=>setInput(e.target.value.replace(/\D/g,''))} onKeyDown={e=>e.key==='Enter'&&check()} disabled={locked} autoFocus/>
            <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 24px',borderRadius:14,fontSize:16}} onClick={check} disabled={locked}>✓</button>
          </div>
          {fb && <div className={`ft-game-feedback ${fb}`} style={{marginTop:16,fontSize:18}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer:qs[cur].a})}</div>}
        </div>}
      </div>
    </div>
  );
};

const MemoryGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const pairs = lvl === 1 ? 4 : lvl === 2 ? 6 : 8;
  const cols = 4;
  const [cards, setCards] = useState([]); const [flipped, setFlipped] = useState([]); const [matched, setMatched] = useState([]);
  const [moves, setMoves] = useState(0); const [score, setScore] = useState(0); const [gameDone, setGameDone] = useState(false);
  const [msg, setMsg] = useState(''); const [lock, setLock] = useState(false);
  useEffect(() => {
    const em = ['🍎','🎈','🎲','🎨','🎵','⚽','🦋','🐠','🍕','🎁','🌺','🦒','🎪','🎭','🎸','⭐'];
    let sel = []; for (let i = 0; i < pairs; i++) sel.push(em[i], em[i]);
    sel.sort(()=>Math.random()-0.5);
    setCards(sel.map((e,i)=>({emoji:e, idx:i})));
  }, []);
  const flip = (idx) => {
    if (lock || gameDone || flipped.includes(idx) || matched.includes(idx)) return;
    if (flipped.length >= 2) return;
    const nf = [...flipped, idx]; setFlipped(nf);
    if (nf.length === 2) {
      setLock(true); setMoves(m=>m+1);
      const c1 = cards[nf[0]], c2 = cards[nf[1]];
      if (c1.emoji === c2.emoji) {
        setMatched(m=>[...m, nf[0], nf[1]]); setScore(s=>s+50); setMsg(t('games.memory.match'));
        setTimeout(() => {
          setFlipped([]); setLock(false); setMsg('');
          if (matched.length + 2 >= cards.length) { setGameDone(true); onSave(score + 50); }
        }, 500);
      } else {
        setScore(s=>Math.max(0, s-10)); setMsg(t('games.memory.no_match'));
        setTimeout(() => { setFlipped([]); setLock(false); setMsg(''); }, 1000);
      }
    }
  };
  if (gameDone) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.memory.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.memory.won')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score >= pairs * 50 - 10 ? '⭐⭐⭐' : score >= pairs * 25 ? '⭐⭐' : '⭐'}</div>
        <div className="ft-game-finish-msg">{t('games.memory.stats',{moves,perPair:Math.floor(moves/(pairs||1))})}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.memory.title')}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-stats">
          <div className="ft-game-stat"><div className="ft-game-stat-val">{moves}</div><div className="ft-game-stat-lbl">{t('games.memory.moves')}</div></div>
          <div className="ft-game-stat"><div className="ft-game-stat-val">{matched.length/2}/{pairs}</div><div className="ft-game-stat-lbl">{t('games.memory.pairs')}</div></div>
          <div className="ft-game-stat"><div className="ft-game-stat-val">{score}</div><div className="ft-game-stat-lbl">{t('games.memory.points')}</div></div>
        </div>
        {msg && <div className={`ft-game-feedback ${msg.startsWith('✅')?'ok':'err'}`} style={{textAlign:'center',marginBottom:8}}>{msg}</div>}
        <div className="ft-mem-board" style={{gridTemplateColumns:'repeat('+cols+',1fr)',maxWidth:Math.min(480,cols*80)}}>
          {cards.map((c,i) => (
            <button key={i} className={`ft-mem-card ${flipped.includes(i)||matched.includes(i)?'flipped':''} ${matched.includes(i)?'matched':''}`}
              onClick={()=>flip(i)} disabled={lock||gameDone||flipped.includes(i)||matched.includes(i)}>
              {flipped.includes(i) || matched.includes(i) ? c.emoji : '❓'}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
};

const WordGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const diff = getGameDiff(child);
  const [words, setWords] = useState([]); const [cur, setCur] = useState(0); const [score, setScore] = useState(0); const [done, setDone] = useState(false);
  const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  useEffect(() => {
    const list = [...(WORD_LISTS[diff]||WORD_LISTS.leicht)];
    list.sort(()=>Math.random()-0.5);
    setWords(list);
  }, []);
  const answer = (isCorrect) => {
    if (locked) return; setLocked(true);
    if (isCorrect) { setScore(s=>s+10); setFb('ok'); }
    else setFb('err');
    setTimeout(() => {
      if (cur + 1 >= words.length) { setDone(true); onSave(score + (isCorrect?10:0)); }
      else { setCur(c=>c+1); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.word.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.word.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score >= words.length*10*0.8 ? '⭐⭐⭐' : score >= words.length*10*0.5 ? '⭐⭐' : '⭐'}</div>
        <div className="ft-game-finish-msg">{score >= words.length*10*0.8 ? t('games.praise_sensational') : score >= words.length*10*0.5 ? t('games.praise_super') : t('games.praise_keep_going')}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  if (!words.length) return null;
  const w = words[cur];
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.word.title')}</div><div className="ft-game-sub">{cur+1}/{words.length}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:((cur+1)/words.length)*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        <div className="ft-game-question">
          <div className="ft-game-emoji">{w.emoji}</div>
          <div className="ft-game-qtext" style={{fontSize:24,fontFamily:"'Courier New',monospace",letterSpacing:4}}>{w.word}</div>
          <div className="ft-game-hint">{t('games.word.what_meaning')}</div>
          <div className="ft-game-options">
            {[w.meaning, ...w.opts].sort(()=>Math.random()-0.5).map((opt,i) => (
              <button key={i} className={`ft-game-opt-btn ${fb ? (opt===w.meaning ? 'correct' : '') : ''}`}
                onClick={()=>answer(opt===w.meaning)} disabled={locked}>
                {opt}
              </button>
            ))}
          </div>
          {fb && <div className={`ft-game-feedback ${fb}`} style={{fontSize:16}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer:w.meaning})}</div>}
        </div>
      </div>
    </div>
  );
};
// ── NEW GAMES ────────────────────────────────────────────────────────
const TicTacToeGame = ({ childId, onBack, onSave }) => {
  const [board, setBoard] = useState(Array(9).fill(null));
  const [xNext, setXNext] = useState(true);
  const [status, setStatus] = useState(t('games.ttt.your_turn'));
  const [gameOver, setGameOver] = useState(false);
  const [finScore, setFinScore] = useState(0);

  const calcWinner = (sq) => {
    const lines = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
    for (let l of lines) { const [a,b,c]=l; if (sq[a]&&sq[a]===sq[b]&&sq[a]===sq[c]) return sq[a]; }
    return sq.every(s=>s) ? 'draw' : null;
  };

  const aiMove = (sq) => {
    for (let i=0;i<9;i++) { if (!sq[i]) { const n=[...sq]; n[i]='O'; if (calcWinner(n)==='O') return i; } }
    for (let i=0;i<9;i++) { if (!sq[i]) { const n=[...sq]; n[i]='X'; if (calcWinner(n)==='X') return i; } }
    if (!sq[4]) return 4;
    const corners = [0,2,6,8].filter(i=>!sq[i]);
    if (corners.length) return corners[Math.floor(Math.random()*corners.length)];
    const sides = [1,3,5,7].filter(i=>!sq[i]);
    return sides.length ? sides[Math.floor(Math.random()*sides.length)] : -1;
  };

  const handleClick = (i) => {
    if (board[i] || gameOver || !xNext) return;
    const nb = [...board]; nb[i] = 'X';
    setBoard(nb);
    const w = calcWinner(nb);
    if (w) {
      if (w === 'X') { const s=50; setFinScore(s); onSave(s); setStatus(t('games.ttt.you_won')); }
      else if (w === 'draw') { const s=20; setFinScore(s); onSave(s); setStatus(t('games.ttt.draw')); }
      setGameOver(true); return;
    }
    setXNext(false); setStatus(t('games.ttt.ai_thinking'));
    setTimeout(() => {
      const ai = aiMove(nb);
      if (ai < 0) { setGameOver(true); const s=20; setFinScore(s); onSave(s); setStatus(t('games.ttt.draw')); return; }
      nb[ai] = 'O';
      setBoard([...nb]);
      const w2 = calcWinner(nb);
      if (w2) {
        if (w2 === 'O') { setFinScore(0); onSave(0); setStatus(t('games.ttt.ai_wins')); }
        else if (w2 === 'draw') { const s=20; setFinScore(s); onSave(s); setStatus(t('games.ttt.draw')); }
        setGameOver(true); return;
      }
      setXNext(true); setStatus(t('games.ttt.your_turn'));
    }, 400);
  };

  if (gameOver) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.ttt.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{status}</div>
        <div className="ft-game-finish-score">{finScore}</div>
        <div className="ft-game-stars">{finScore>=50?'⭐⭐⭐':finScore>=20?'⭐⭐':'⭐'}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );

  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.ttt.title')}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-stats">
          <div className="ft-game-stat"><div className="ft-game-stat-val">❌</div><div className="ft-game-stat-lbl">{t('games.ttt.you')}</div></div>
          <div className="ft-game-stat"><div className="ft-game-stat-val">⭕</div><div className="ft-game-stat-lbl">{t('games.ttt.ai')}</div></div>
        </div>
        <div className="ft-ttt-status">{status}</div>
        <div className="ft-ttt-board">
          {board.map((v,i) => (
            <button key={i} className={`ft-ttt-cell ${v?.toLowerCase()||''}`} onClick={()=>handleClick(i)} disabled={!!board[i]||gameOver||!xNext}>{v||''}</button>
          ))}
        </div>
        {gameOver && <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#4ECDC4,#45B7AA)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16,marginBottom:10}} onClick={()=>{setBoard(Array(9).fill(null));setXNext(true);setStatus(t('games.ttt.your_turn'));setGameOver(false);setFinScore(0);}}>{t('games.ttt.play_again')}</button>}
      </div>
    </div>
  );
};

const GeoQuizGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const counts = lvl === 1 ? 6 : lvl === 2 ? 10 : 15;
  const qcount = 5;
  const [questions, setQuestions] = useState([]);
  const [cur, setCur] = useState(0); const [score, setScore] = useState(0);
  const [done, setDone] = useState(false); const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  useEffect(() => {
    const pool = [...GEO_FLAGS].sort(()=>Math.random()-0.5).slice(0, counts);
    const qs = [];
    for (let i = 0; i < qcount && i < pool.length; i++) {
      const correct = pool[i];
      const opts = [correct.name];
      const others = GEO_FLAGS.filter(f => f.name !== correct.name).sort(()=>Math.random()-0.5).slice(0,3);
      others.forEach(o => opts.push(o.name));
      opts.sort(()=>Math.random()-0.5);
      qs.push({ flag: correct.emoji, answer: correct.name, options: opts });
    }
    setQuestions(qs);
  }, []);
  const answer = (opt) => {
    if (locked || !questions.length) return; setLocked(true);
    const isCorrect = opt === questions[cur].answer;
    if (isCorrect) { setScore(s=>s+10); setFb('ok'); } else setFb('err');
    setTimeout(() => {
      if (cur + 1 >= questions.length) { setDone(true); onSave(score + (isCorrect?10:0)); }
      else { setCur(c=>c+1); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.geo.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.geo.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score>=qcount*10*0.8?'⭐⭐⭐':score>=qcount*10*0.5?'⭐⭐':'⭐'}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  if (!questions.length) return null;
  const q = questions[cur];
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.geo.title')}</div><div className="ft-game-sub">{cur+1}/{questions.length}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:(cur/(questions.length||1))*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        {q && <div className="ft-game-question">
          <div className="ft-game-emoji">{q.flag}</div>
          <div className="ft-game-qtext">{t('games.geo.which_country')}</div>
          <div className="ft-game-options">
            {q.options.map((opt,i) => (
              <button key={i} className={`ft-game-opt-btn ${fb ? (opt===q.answer?'correct':'') : ''}`} onClick={()=>answer(opt)} disabled={locked}>{opt}</button>
            ))}
          </div>
          {fb && <div className={`ft-game-feedback ${fb}`} style={{fontSize:16}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer:q.answer})}</div>}
        </div>}
      </div>
    </div>
  );
};

const SimonGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const maxRounds = lvl === 1 ? 5 : lvl === 2 ? 8 : 12;
  const COLORS = ['#FF6B6B','#FFE66D','#6BCB77','#4ECDC4'];
  const simonRef = useRef([]);
  useEffect(() => () => { simonRef.current.forEach(id => { try { clearInterval(id); clearTimeout(id); } catch(e){} }); }, []);
  const [seq, setSeq] = useState([]);
  const [playerSeq, setPlayerSeq] = useState([]);
  const [round, setRound] = useState(0);
  const [score, setScore] = useState(0);
  const [showing, setShowing] = useState(false);
  const [lit, setLit] = useState(null);
  const [phase, setPhase] = useState('start');
  const [done, setDone] = useState(false);
  const startGame = () => {
    const first = Math.floor(Math.random()*4);
    setSeq([first]); setPlayerSeq([]); setRound(1); setScore(0); setPhase('show');
    setShowing(true);
    let i = 0;
    const iv = setInterval(() => {
      const t = setTimeout(() => setLit(null), 400);
      simonRef.current.push(iv, t);
      setLit(first);
      i++;
      if (i >= 1) { clearInterval(iv); setShowing(false); setPhase('input'); setPlayerSeq([]); }
    }, 600);
  };
  const playSeq = (s) => {
    setPhase('show'); setShowing(true);
    let idx = 0;
    const iv = setInterval(() => {
      const t1 = setTimeout(() => setLit(null), 400);
      simonRef.current.push(iv, t1);
      setLit(s[idx]);
      idx++;
      if (idx >= s.length) { clearInterval(iv); const t2 = setTimeout(() => { setShowing(false); setPhase('input'); setPlayerSeq([]); }, 300); simonRef.current.push(t2); }
    }, 600);
  };
  const playerClick = (c) => {
    if (phase !== 'input' || showing) return;
    const ps = [...playerSeq, c];
    setPlayerSeq(ps);
    setLit(c); const t0 = setTimeout(() => setLit(null), 200); simonRef.current.push(t0);
    if (ps[ps.length-1] !== seq[ps.length-1]) {
      const s = score; setDone(true); onSave(s); return;
    }
    if (ps.length === seq.length) {
      const ns = score + 10; setScore(ns);
      if (seq.length >= maxRounds) { setDone(true); onSave(ns); return; }
      const ns2 = [...seq, Math.floor(Math.random()*4)];
      setSeq(ns2);
      const t1 = setTimeout(() => playSeq(ns2), 400); simonRef.current.push(t1);
    }
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.simon.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{score >= maxRounds*10 ? t('games.simon.perfect') : t('games.simon.done')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score >= maxRounds*10*0.8?'⭐⭐⭐':score >= maxRounds*10*0.5?'⭐⭐':'⭐'}</div>
        <div className="ft-game-finish-msg">{t('games.simon.rounds_completed',{round})}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.simon.title')}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-stats">
          <div className="ft-game-stat"><div className="ft-game-stat-val">{round}</div><div className="ft-game-stat-lbl">{t('games.simon.round')}</div></div>
          <div className="ft-game-stat"><div className="ft-game-stat-val">{score}</div><div className="ft-game-stat-lbl">{t('games.simon.points')}</div></div>
        </div>
        <div className="ft-simon-round">{phase==='start'?t('games.simon.press_start'):phase==='show'?t('games.simon.memorize'):phase==='input'?t('games.simon.repeat'):''}</div>
        <div className="ft-simon-grid">
          {COLORS.map((c,i) => (
            <button key={i} className={`ft-simon-btn${lit===i?' lit':''}`} style={{background:c}} onClick={()=>playerClick(i)} disabled={phase!=='input'||showing}> </button>
          ))}
        </div>
        {phase==='start' && <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#4ECDC4,#45B7AA)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={startGame}>{t('games.simon.start')}</button>}
      </div>
    </div>
  );
};

const ClockGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const qcount = 5;
  const [questions, setQuestions] = useState([]);
  const [cur, setCur] = useState(0); const [score, setScore] = useState(0);
  const [done, setDone] = useState(false); const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  const genTime = () => {
    if (lvl === 1) { const h = Math.floor(Math.random()*12)+1; return {h,m:0,str:`${h}:00`}; }
    if (lvl === 2) { const h = Math.floor(Math.random()*12)+1; const m = [0,30][Math.floor(Math.random()*2)]; return {h,m,str:`${h}:${String(m).padStart(2,'0')}`}; }
    const h = Math.floor(Math.random()*12)+1; const m = Math.floor(Math.random()*12)*5;
    return {h,m,str:`${h}:${String(m).padStart(2,'0')}`};
  };
  useEffect(() => {
    const qs = [];
    for (let i = 0; i < qcount; i++) {
      const correct = genTime();
      const opts = [correct.str];
      while (opts.length < 4) {
        const t = genTime().str;
        if (!opts.includes(t)) opts.push(t);
      }
      opts.sort(()=>Math.random()-0.5);
      qs.push({ h: correct.h, m: correct.m, answer: correct.str, options: opts });
    }
    setQuestions(qs);
  }, []);
  const answer = (opt) => {
    if (locked || !questions.length) return; setLocked(true);
    const isCorrect = opt === questions[cur].answer;
    if (isCorrect) { setScore(s=>s+10); setFb('ok'); } else setFb('err');
    setTimeout(() => {
      if (cur + 1 >= questions.length) { setDone(true); onSave(score + (isCorrect?10:0)); }
      else { setCur(c=>c+1); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.clock.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.clock.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score>=qcount*10*0.8?'⭐⭐⭐':score>=qcount*10*0.5?'⭐⭐':'⭐'}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  if (!questions.length) return null;
  const q = questions[cur];
  const hdeg = ((q.h % 12) * 30) + (q.m / 2);
  const mdeg = q.m * 6;
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.clock.title')}</div><div className="ft-game-sub">{cur+1}/{questions.length}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:(cur/(questions.length||1))*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        {q && <div className="ft-game-question" style={{padding:'20px 16px'}}>
          <div className="ft-game-qtext" style={{fontSize:16,marginBottom:14}}>{t('games.clock.which_time')}</div>
          <div className="ft-clock-face">
            <div className="ft-clock-hand ft-clock-h" style={{transform:'translateX(-50%) rotate('+hdeg+'deg)'}}/>
            <div className="ft-clock-hand ft-clock-m" style={{transform:'translateX(-50%) rotate('+mdeg+'deg)'}}/>
            <div className="ft-clock-dot"/>
          </div>
          <div className="ft-game-options">
            {q.options.map((opt,i) => (
              <button key={i} className={`ft-game-opt-btn ${fb ? (opt===q.answer?'correct':'') : ''}`} onClick={()=>answer(opt)} disabled={locked}>{opt}</button>
            ))}
          </div>
          {fb && <div className={`ft-game-feedback ${fb}`} style={{fontSize:16}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer:q.answer})}</div>}
        </div>}
      </div>
    </div>
  );
};

const HangmanGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const diff = getGameDiff(child);
  const maxWrong = 6;
  const pool = HANGMAN_WORDS[diff] || HANGMAN_WORDS.leicht;
  const [word, setWord] = useState('');
  const [guessed, setGuessed] = useState([]);
  const [wrong, setWrong] = useState(0);
  const [done, setDone] = useState(false);
  const [won, setWon] = useState(false);
  const [score, setScore] = useState(0);
  useEffect(() => { setWord(pool[Math.floor(Math.random()*pool.length)]); }, []);
  const guess = (l) => {
    if (done || !word || guessed.includes(l)) return;
    setGuessed(g=>[...g, l]);
    if (!word.includes(l)) {
      const nw = wrong + 1; setWrong(nw);
      if (nw >= maxWrong) { const s = 0; setScore(s); setDone(true); setWon(false); onSave(s); }
    } else {
      const revealed = [...word].filter(c => guessed.includes(c) || c === l);
      if (revealed.length === word.length) {
        const s = (maxWrong - wrong) * 10 + word.length * 5;
        setScore(s); setDone(true); setWon(true); onSave(s);
      }
    }
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.hangman.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{won ? t('games.hangman.won') : t('games.hangman.lost')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-finish-msg">{won ? t('games.hangman.win_word',{word}) : t('games.hangman.word_was',{word})}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  if (!word) return null;
  const displayed = [...word].map(c => guessed.includes(c) ? c : '_');
  const allLetters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.hangman.title')}</div></div>
      <div className="ft-game-body">
        <div className="ft-hangman-lives">{'❤️'.repeat(maxWrong - wrong)}{'🖤'.repeat(wrong)}</div>
        <div className="ft-game-stats">
          <div className="ft-game-stat"><div className="ft-game-stat-val">{wrong}/{maxWrong}</div><div className="ft-game-stat-lbl">{t('games.hangman.mistakes')}</div></div>
        </div>
        <div className="ft-hangman-word">
          {displayed.map((c,i) => (
            <div key={i} className={`ft-hangman-ltr${c!=='_'?' ok':''}`}>{c}</div>
          ))}
        </div>
        <div className="ft-hangman-kbd">
          {allLetters.map(l => {
            const used = guessed.includes(l);
            const isGood = used && word.includes(l);
            const isBad = used && !word.includes(l);
            return (
              <button key={l} className={`ft-hangman-key${isGood?' good':''}${isBad?' bad':''}`} onClick={()=>guess(l)} disabled={used}>{l}</button>
            );
          })}
        </div>
      </div>
    </div>
  );
};
// ── NEW GAMES 2 ──────────────────────────────────────────────────────
const MemoryRecallGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const qcount = 5;
  const seqLen = lvl === 1 ? 3 : lvl === 2 ? 4 : 5;
  const [round, setRound] = useState(0); const [score, setScore] = useState(0);
  const [done, setDone] = useState(false); const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  const [phase, setPhase] = useState('init'); const [items, setItems] = useState([]);
  const [qPos, setQPos] = useState(0); const [opts, setOpts] = useState([]);
  useEffect(() => {
    const pool = [...GAME_EMOJIS].sort(()=>Math.random()-0.5);
    const selected = pool.slice(0, seqLen);
    const pos = Math.floor(Math.random()*seqLen);
    setItems(selected); setQPos(pos); setPhase('memo'); setFb(null); setLocked(false);
    const t = setTimeout(() => {
      setPhase('ask');
      const ch = [selected[pos]];
      while (ch.length < 4) { const e = GAME_EMOJIS[Math.floor(Math.random()*GAME_EMOJIS.length)]; if (!ch.includes(e)) ch.push(e); }
      ch.sort(()=>Math.random()-0.5); setOpts(ch);
    }, 2000);
    return () => clearTimeout(t);
  }, [round]);
  const answer = (e) => {
    if (locked) return; setLocked(true);
    const correct = e === items[qPos];
    if (correct) setScore(s=>s+10); setFb(correct?'ok':'err');
    setTimeout(() => {
      if (round + 1 >= qcount) { setDone(true); onSave(score + (correct?10:0)); }
      else { setRound(r=>r+1); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.recall.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.recall.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score>=qcount*10*0.8?'⭐⭐⭐':score>=qcount*10*0.5?'⭐⭐':'⭐'}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.recall.title')}</div><div className="ft-game-sub">{round+1}/{qcount}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:(round/qcount)*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        <div className="ft-game-question" style={{padding:'28px 16px'}}>
          <div className="ft-game-qtext" style={{fontSize:15,marginBottom:14}}>{phase==='memo'?t('games.recall.memorize_positions'):t('games.recall.which_emoji',{pos:qPos+1})}</div>
          {phase==='memo' ? (
            <div className="ft-mem-seq">
              {items.map((e,i) => <div key={i} className="ft-mem-seq-item">{e}<div style={{fontSize:10,color:'rgba(255,255,255,0.3)',marginTop:2}}>{i+1}</div></div>)}
            </div>
          ) : (
            <>
              <div className="ft-mem-seq">
                {items.map((e,i) => <div key={i} className="ft-mem-seq-item hidden">❓<div style={{fontSize:10,color:'rgba(255,255,255,0.3)',marginTop:2}}>{i+1}</div></div>)}
              </div>
              <div className="ft-game-options">
                {opts.map((e,i) => <button key={i} className={`ft-game-opt-btn ${fb ? (e===items[qPos]?'correct':'') : ''}`} onClick={()=>answer(e)} disabled={locked} style={{fontSize:24}}>{e}</button>)}
              </div>
            </>
          )}
          {fb && <div className={`ft-game-feedback ${fb}`} style={{fontSize:16}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer:items[qPos]})}</div>}
        </div>
      </div>
    </div>
  );
};

const ScrambleGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const diff = getGameDiff(child);
  const qcount = 5;
  const pool = HANGMAN_WORDS[diff] || HANGMAN_WORDS.leicht;
  const [round, setRound] = useState(0); const [score, setScore] = useState(0);
  const [done, setDone] = useState(false); const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  const [word, setWord] = useState(''); const [scrambled, setScrambled] = useState(''); const [input, setInput] = useState('');
  useEffect(() => {
    const w = pool[Math.floor(Math.random()*pool.length)];
    setWord(w);
    const s = [...w].sort(()=>Math.random()-0.5).join('');
    setScrambled(s === w ? [...w].sort(()=>Math.random()-0.5).join('') : s);
    setInput(''); setFb(null); setLocked(false);
  }, [round]);
  const check = () => {
    if (locked || !input.trim()) return; setLocked(true);
    const correct = input.trim().toUpperCase() === word;
    if (correct) setScore(s=>s+10); setFb(correct?'ok':'err');
    setTimeout(() => {
      if (round + 1 >= qcount) { setDone(true); onSave(score + (correct?10:0)); }
      else { setRound(r=>r+1); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.scramble.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.scramble.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score>=qcount*10*0.8?'⭐⭐⭐':score>=qcount*10*0.5?'⭐⭐':'⭐'}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.scramble.title')}</div><div className="ft-game-sub">{round+1}/{qcount}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:(round/qcount)*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        <div className="ft-game-question" style={{padding:'28px 20px'}}>
          <div className="ft-game-qtext" style={{fontSize:14,marginBottom:4}}>{t('games.scramble.unscramble')}</div>
          <div className="ft-scramble-word">{scrambled}</div>
          <div style={{display:'flex',gap:10,justifyContent:'center',marginTop:16}}>
            <input className="ft-game-input" style={{fontSize:20,maxWidth:200,textTransform:'uppercase'}} value={input} onChange={e=>setInput(e.target.value.replace(/[^a-zA-Z]/g,'').toUpperCase())} onKeyDown={e=>e.key==='Enter'&&check()} disabled={locked} autoFocus/>
            <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 24px',borderRadius:14,fontSize:16}} onClick={check} disabled={locked}>✓</button>
          </div>
          {fb && <div className={`ft-game-feedback ${fb}`} style={{marginTop:16,fontSize:16}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer:word})}</div>}
        </div>
      </div>
    </div>
  );
};

const DiceCountGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const qcount = 5; const diceCount = lvl === 1 ? 2 : lvl === 2 ? 3 : 4;
  const [round, setRound] = useState(0); const [score, setScore] = useState(0);
  const [done, setDone] = useState(false); const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  const [dice, setDice] = useState([]); const [total, setTotal] = useState(0); const [input, setInput] = useState('');
  const dotPos = (n) => {
    const map = {1:[[1,1]],2:[[0,0],[2,2]],3:[[0,0],[1,1],[2,2]],4:[[0,0],[2,0],[0,2],[2,2]],5:[[0,0],[2,0],[1,1],[0,2],[2,2]],6:[[0,0],[2,0],[0,1],[2,1],[0,2],[2,2]]};
    return map[n]||[];
  };
  useEffect(() => {
    const d = []; let t = 0;
    for (let i = 0; i < diceCount; i++) { const v = Math.floor(Math.random()*6)+1; d.push(v); t += v; }
    setDice(d); setTotal(t); setInput(''); setFb(null); setLocked(false);
  }, [round]);
  const check = () => {
    if (locked || !input.trim()) return; setLocked(true);
    const c = parseInt(input); const correct = c === total;
    if (correct) setScore(s=>s+10); setFb(correct?'ok':'err');
    setTimeout(() => {
      if (round + 1 >= qcount) { setDone(true); onSave(score + (correct?10:0)); }
      else { setRound(r=>r+1); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.dice.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.dice.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score>=qcount*10*0.8?'⭐⭐⭐':score>=qcount*10*0.5?'⭐⭐':'⭐'}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.dice.title')}</div><div className="ft-game-sub">{round+1}/{qcount}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:(round/qcount)*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        <div className="ft-game-question" style={{padding:'24px 16px'}}>
          <div className="ft-game-qtext" style={{fontSize:15,marginBottom:14}}>{t('games.dice.how_many_pips')}</div>
          <div className="ft-dice-grid">
            {dice.map((v,i) => (
              <div key={i} className="ft-dice">
                {[[0,0],[1,0],[2,0],[0,1],[1,1],[2,1],[0,2],[1,2],[2,2]].map(([x,y],j) => (
                  <div key={j} className="ft-dice-dot" style={{visibility:dotPos(v).some(([px,py])=>px===x&&py===y)?'visible':'hidden'}}/>
                ))}
              </div>
            ))}
          </div>
          <div style={{display:'flex',gap:10,justifyContent:'center',marginTop:10}}>
            <input className="ft-game-input" style={{fontSize:28,maxWidth:120}} value={input} onChange={e=>setInput(e.target.value.replace(/\D/g,''))} onKeyDown={e=>e.key==='Enter'&&check()} disabled={locked} autoFocus/>
            <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 24px',borderRadius:14,fontSize:16}} onClick={check} disabled={locked}>✓</button>
          </div>
          {fb && <div className={`ft-game-feedback ${fb}`} style={{marginTop:16,fontSize:16}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer:total})}</div>}
        </div>
      </div>
    </div>
  );
};

const OppositesGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const qcount = 5;
  const pool = lvl === 1 ? OPPOSITES.slice(0,8) : lvl === 2 ? OPPOSITES.slice(0,12) : OPPOSITES;
  const [round, setRound] = useState(0); const [score, setScore] = useState(0);
  const [done, setDone] = useState(false); const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  const [q, setQ] = useState({word:'',answer:'',opts:[]});
  useEffect(() => {
    const items = [...pool].sort(()=>Math.random()-0.5);
    const selected = items[0];
    const ch = [...selected.opts].sort(()=>Math.random()-0.5);
    setQ({word:selected.word,answer:selected.answer,opts:ch}); setFb(null); setLocked(false);
  }, [round]);
  const answer = (opt) => {
    if (locked) return; setLocked(true);
    const correct = opt === q.answer;
    if (correct) setScore(s=>s+10); setFb(correct?'ok':'err');
    setTimeout(() => {
      if (round + 1 >= qcount) { setDone(true); onSave(score + (correct?10:0)); }
      else { setRound(r=>r+1); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.opposites.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.opposites.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score>=qcount*10*0.8?'⭐⭐⭐':score>=qcount*10*0.5?'⭐⭐':'⭐'}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.opposites.title')}</div><div className="ft-game-sub">{round+1}/{qcount}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:(round/qcount)*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        <div className="ft-game-question">
          <div className="ft-game-qtext">{t('games.opposites.what_is_opposite')}</div>
          <div className="ft-scramble-word" style={{fontSize:36,margin:'8px 0 14px'}}>{q.word}</div>
          <div className="ft-game-options">
            {q.opts.map((opt,i) => <button key={i} className={`ft-game-opt-btn ${fb ? (opt===q.answer?'correct':'') : ''}`} onClick={()=>answer(opt)} disabled={locked}>{opt}</button>)}
          </div>
          {fb && <div className={`ft-game-feedback ${fb}`} style={{fontSize:16}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer:q.answer})}</div>}
        </div>
      </div>
    </div>
  );
};

const CompareGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const qcount = 5;
  const [round, setRound] = useState(0); const [score, setScore] = useState(0);
  const [done, setDone] = useState(false); const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  const [left, setLeft] = useState(''); const [right, setRight] = useState('');
  const [answer, setAns] = useState('');
  const gen = () => {
    if (lvl === 1) {
      const a = Math.floor(Math.random()*9)+1, b = Math.floor(Math.random()*9)+1;
      return {l:String(a),r:String(b),ans:a>b?'>':a<b?'<':'='};
    }
    if (lvl === 2) {
      const a = Math.floor(Math.random()*50)+10, b = Math.floor(Math.random()*50)+10;
      return {l:String(a),r:String(b),ans:a>b?'>':a<b?'<':'='};
    }
    const ops = ['+','-','*'];
    const op = ops[Math.floor(Math.random()*3)];
    const a = Math.floor(Math.random()*12)+2, b = Math.floor(Math.random()*12)+2;
    let lval, rval;
    if (op === '+') { lval = a+b; rval = Math.floor(Math.random()*30)+5; }
    else if (op === '-') { const mx = Math.max(a,b); lval = mx; rval = Math.min(a,b); }
    else { lval = a*b; rval = Math.floor(Math.random()*50)+5; }
    return {l:`${a}${op}${b}`,r:String(rval),ans:lval>rval?'>':lval<rval?'<':'='};
  };
  useEffect(() => {
    const g = gen(); setLeft(g.l); setRight(g.r); setAns(g.ans); setFb(null); setLocked(false);
  }, [round]);
  const answerPick = (a) => {
    if (locked) return; setLocked(true);
    const correct = a === answer;
    if (correct) setScore(s=>s+10); setFb(correct?'ok':'err');
    setTimeout(() => {
      if (round + 1 >= qcount) { setDone(true); onSave(score + (correct?10:0)); }
      else { setRound(r=>r+1); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.compare.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.compare.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score>=qcount*10*0.8?'⭐⭐⭐':score>=qcount*10*0.5?'⭐⭐':'⭐'}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.compare.title')}</div><div className="ft-game-sub">{round+1}/{qcount}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:(round/qcount)*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        <div className="ft-game-question">
          <div className="ft-game-qtext" style={{fontSize:14,marginBottom:6}}>{t('games.compare.which_sign')}</div>
          <div style={{display:'flex',alignItems:'center',justifyContent:'center',gap:14,fontSize:28,fontWeight:900,fontFamily:"'Fredoka One',sans-serif",margin:'10px 0 18px'}}>
            <span style={{color:'#FFE66D'}}>{left}</span>
            <span style={{color:'rgba(255,255,255,0.2)'}}>?</span>
            <span style={{color:'#4ECDC4'}}>{right}</span>
          </div>
          <div className="ft-game-options" style={{gridTemplateColumns:'repeat(3,1fr)'}}>
            {['<','=','>'].map(a => <button key={a} className={`ft-game-opt-btn ${fb ? (a===answer?'correct':'') : ''}`} onClick={()=>answerPick(a)} disabled={locked} style={{fontSize:24}}>{a}</button>)}
          </div>
          {fb && <div className={`ft-game-feedback ${fb}`} style={{fontSize:16}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer})}</div>}
        </div>
      </div>
    </div>
  );
};

const AnimalQuizGame = ({ childId, onBack, onSave }) => {
  const child = children.find(c => c.id === childId);
  const lvl = getGameLevel(child);
  const qcount = 5;
  const pool = lvl === 1 ? ANIMALS.slice(0,8) : lvl === 2 ? ANIMALS.slice(0,14) : ANIMALS;
  const [round, setRound] = useState(0); const [score, setScore] = useState(0);
  const [done, setDone] = useState(false); const [fb, setFb] = useState(null); const [locked, setLocked] = useState(false);
  const [q, setQ] = useState({emoji:'',name:'',opts:[]});
  useEffect(() => {
    const items = [...pool].sort(()=>Math.random()-0.5);
    const sel = items[0];
    const ch = [sel.name];
    while (ch.length < 4) { const r = ANIMALS[Math.floor(Math.random()*ANIMALS.length)].name; if (!ch.includes(r)) ch.push(r); }
    ch.sort(()=>Math.random()-0.5);
    setQ({emoji:sel.emoji,name:sel.name,opts:ch}); setFb(null); setLocked(false);
  }, [round]);
  const answer = (opt) => {
    if (locked) return; setLocked(true);
    const correct = opt === q.name;
    if (correct) setScore(s=>s+10); setFb(correct?'ok':'err');
    setTimeout(() => {
      if (round + 1 >= qcount) { setDone(true); onSave(score + (correct?10:0)); }
      else { setRound(r=>r+1); setFb(null); setLocked(false); }
    }, 1200);
  };
  if (done) return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.animals.title')}</div></div>
      <div className="ft-game-body"><div className="ft-game-finish">
        <div className="ft-game-finish-lbl">{t('games.animals.finished')}</div>
        <div className="ft-game-finish-score">{score}</div>
        <div className="ft-game-stars">{score>=qcount*10*0.8?'⭐⭐⭐':score>=qcount*10*0.5?'⭐⭐':'⭐'}</div>
        <button className="ft-game-opt-btn" style={{background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',color:'#fff',border:'none',padding:'14px 32px',borderRadius:14,fontSize:16}} onClick={onBack}>{t('common.back')}</button>
      </div></div>
    </div>
  );
  return (
    <div className="ft-game-screen">
      <div className="ft-game-hdr"><button className="ft-game-back" onClick={onBack}>←</button><div className="ft-game-title">{t('games.animals.title')}</div><div className="ft-game-sub">{round+1}/{qcount}</div></div>
      <div className="ft-game-body">
        <div className="ft-game-progress"><div className="ft-game-prog-bg"><div className="ft-game-prog-fill" style={{width:(round/qcount)*100+'%'}}/></div></div>
        <div className="ft-game-score">{t('games.score_pts',{score})}</div>
        <div className="ft-game-question">
          <div className="ft-game-emoji">{q.emoji}</div>
          <div className="ft-game-qtext">{t('games.animals.which_animal')}</div>
          <div className="ft-game-options">
            {q.opts.map((opt,i) => <button key={i} className={`ft-game-opt-btn ${fb ? (opt===q.name?'correct':'') : ''}`} onClick={()=>answer(opt)} disabled={locked}>{opt}</button>)}
          </div>
          {fb && <div className={`ft-game-feedback ${fb}`} style={{fontSize:16}}>{fb==='ok'?t('games.feedback_correct'):t('games.feedback_wrong',{answer:q.name})}</div>}
        </div>
      </div>
    </div>
  );
};
// ── END GAME COMPONENTS ──────────────────────────────────────────────
      useEffect(()=>{
        const tick=()=>{
          const n=new Date();
          nowRef.current=n;
          const ms=Date.now();

          // ── Header clock (HH:MM:SS — min-width verhindert Layout-Shift) ──
          const hct=document.getElementById('dom-hdr-clock-t');
          const hcd=document.getElementById('dom-hdr-clock-d');
          if(hct)hct.textContent=n.toLocaleTimeString(getLocale(),{hour:"2-digit",minute:"2-digit",second:"2-digit"});
          if(hcd)hcd.textContent=n.toLocaleDateString(getLocale(),{day:"numeric",month:"short"});

          // ── Child screen clock ──
          const cct=document.getElementById('dom-child-clock-t');
          const ccd=document.getElementById('dom-child-clock-d');
          if(cct)cct.textContent=n.toLocaleTimeString(getLocale(),{hour:"2-digit",minute:"2-digit"});
          if(ccd)ccd.textContent=n.toLocaleDateString(getLocale(),{day:"numeric",month:"short"});

          const _timers=timersRef.current;
          const _children=childrenRef.current;

          // ── Timer chips ──
          _children.forEach(c=>{
            const chip=document.getElementById('dom-chip-'+c.id);
            const val=document.getElementById('dom-chip-val-'+c.id);
            if(!chip||!val)return;
            const tmr=_timers[c.id];
            if(!tmr){val.textContent='⏱';chip.className='ft-tchip';chip.style.setProperty('--tc',c.color);chip.style.setProperty('--tcr',h2r(c.color));return;}
            const secs=Math.max(0,Math.round((tmr.endsAt-ms)/1000));
            val.textContent=secs>0?fmtMM(secs):'✓';
            chip.className='ft-tchip'+(secs>0?' running':'')+(secs>0&&secs<=60?' urgent':'')+(secs===0?' expired':'');
          });

          // ── Child screen timer pill ──
          const cid=selCidRef.current;
          const ctv=document.getElementById('dom-ctimer-val');
          if(ctv&&cid){
            const tmr=_timers[cid];
            if(!tmr){ctv.textContent='⏱';ctv.className='ft-ctimer-val';}
            else{const s=Math.max(0,Math.round((tmr.endsAt-ms)/1000));ctv.textContent=s>0?fmtMM(s):'✓';ctv.className='ft-ctimer-val'+(s>0?(s<=60?' urgent':' on'):'');}
          }

          // ── Countdown to next task ──
          const cdWrap=document.getElementById('dom-countdown');
          if(cdWrap&&cid){
            const _tasks=tasksRef.current,_comps=compsRef.current,nowMin=n.getHours()*60+n.getMinutes(),dow=n.getDay(),d=todayStr();
            const tt=_tasks.filter(t=>t.assignedTo.includes(cid)&&t.repeatDays.includes(dow)).sort((a,b)=>a.dueTime.localeCompare(b.dueTime));
            let found=null;
            for(const t of tt){if(_comps[`${cid}_${t.id}_${d}`]?.completed)continue;const[h,m]=t.dueTime.split(":").map(Number),tMin=h*60+m;if(tMin>nowMin){found={task:t,secsLeft:(tMin-nowMin)*60-n.getSeconds()};break;}}
            if(found){
              cdWrap.style.display='flex';
              const cn=document.getElementById('dom-cd-name');const cv=document.getElementById('dom-cd-val');
              if(cn)cn.textContent=found.task.icon+' '+found.task.title;
              if(cv){const s=found.secsLeft;cv.textContent=s<=0?'00:00:00':[Math.floor(s/3600),Math.floor((s%3600)/60),s%60].map(v=>String(v).padStart(2,"0")).join(":");}
            }else{cdWrap.style.display='none';}
          }

          // ── Fire alarms ──
          Object.entries(_timers).forEach(([cid2,tmr])=>{
            if(!tmr)return;
            if(tmr.endsAt<=ms&&!firedRef.current[cid2]){firedRef.current[cid2]=true;playAlarmSound();setAlarms(a=>a.includes(cid2)?a:[...a,cid2]);}
          });
          // Media Timers check
          checkMediaTimers();
        };
        tick();
        const t=setInterval(tick,1000);
        return()=>clearInterval(t);
      },[]);

      useEffect(()=>{
        (async()=>{
          await _loadData();
          try{
            const r1=storage.get("ft-ch"),r2=storage.get("ft-ta"),r3=storage.get("ft-co"),r4=storage.get("ft-mu"),r5=storage.get("ft-pin"),r6=storage.get("ft-timers"),r7=storage.get("ft-fi"),r8=storage.get("ft-fh"),r9=storage.get("ft-mt"),r10=storage.get("ft-ev"),r11=storage.get("ft-yt");
            const r12=storage.get("ft-ml");
            if(r1)setChildren(JSON.parse(r1.value));
            if(r2)setTasks(JSON.parse(r2.value));
            if(r3)setComps(JSON.parse(r3.value));
            if(r4)setMediaU(JSON.parse(r4.value));
            if(r5)setAdminPin(r5.value);
            if(r6)setTimers(JSON.parse(r6.value));
            if(r7)setFines(JSON.parse(r7.value));
            if(r8)setFineHits(JSON.parse(r8.value));
            if(r9)setMediaTypes(JSON.parse(r9.value));
            if(r10)setTermine(JSON.parse(r10.value));
            if(r12)setMoneyLog(JSON.parse(r12.value));
          }catch(e){}
          // Game scores sind im Cache, kein expliziter State nötig — via storage.get()
          setLoaded(true);
          // ── iCal-Events vom Server laden (stiller Hintergrund-Import) ──
          try {
            const ir = await fetch(_apiUrl('ical'),{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
            if(ir.ok){const id=await ir.json();if(id.ok&&id.events?.length)setICalEvs(id.events);}
          } catch(e){}
        })();
        // Reload-Hook für Intervall — _reloading verhindert Rück-Saves in useEffects
        window._famtaskSetIcal = (evs) => { setICalEvs(evs); };
        window._famtaskReload = () => {
          try{
            _reloading = true;
            const r1=storage.get("ft-ch"),r2=storage.get("ft-ta"),r3=storage.get("ft-co"),r4=storage.get("ft-mu"),r5=storage.get("ft-pin"),r6=storage.get("ft-timers"),r7=storage.get("ft-fi"),r8=storage.get("ft-fh");
            if(r1)setChildren(JSON.parse(r1.value));
            if(r2)setTasks(JSON.parse(r2.value));
            if(r3)setComps(JSON.parse(r3.value));
            if(r4)setMediaU(JSON.parse(r4.value));
            if(r5)setAdminPin(r5.value);
            if(r6)setTimers(JSON.parse(r6.value));
            if(r7)setFines(JSON.parse(r7.value));
            if(r8)setFineHits(JSON.parse(r8.value));
            const r9b=storage.get("ft-mt"); if(r9b)setMediaTypes(JSON.parse(r9b.value));
            const r10b=storage.get("ft-ev"); if(r10b)setTermine(JSON.parse(r10b.value));
            const r12b=storage.get("ft-ml"); if(r12b)setMoneyLog(JSON.parse(r12b.value));
            setTimeout(() => { _reloading = false; }, 200);
          }catch(e){ _reloading = false; }
        };
      },[]);

      const sv=(k,v)=>{
        if(_reloading) return;
        try{storage.set(k,JSON.stringify(v));}catch(e){}
      };
      useEffect(()=>{if(loaded)sv("ft-ch",children);},[children,loaded]);
      useEffect(()=>{if(loaded)sv("ft-ta",tasks);},[tasks,loaded]);
      useEffect(()=>{if(loaded)sv("ft-co",comps);},[comps,loaded]);
      useEffect(()=>{if(loaded)sv("ft-mu",mediaU);},[mediaU,loaded]);
      useEffect(()=>{if(loaded)sv("ft-timers",timers);},[timers,loaded]);
      useEffect(()=>{if(loaded)sv("ft-fi",fines);},[fines,loaded]);
      useEffect(()=>{if(loaded)sv("ft-fh",fineHits);},[fineHits,loaded]);
      useEffect(()=>{if(loaded)sv("ft-mt",mediaTypes);},[mediaTypes,loaded]);
      useEffect(()=>{if(loaded)sv("ft-ev",termine);},[termine,loaded]);
      useEffect(()=>{if(loaded)sv("ft-ml",moneyLog);},[moneyLog,loaded]);

      // ── Wochenvergütung ───────────────────────────────────────────────────
      // Einmal pro Kalenderwoche wird der Wochenbetrag gutsgeschrieben.
      // lastWeekCredit steht pro Kind, damit neu angelegte Kinder nicht
      // rückwirkend eine ganze Woche nachgezahlt bekommen.
      const [weekReady,setWeekReady]=useState(false);
      useEffect(()=>{
        if(!loaded||weekReady) return;
        const wk=weekKey(new Date());
        const credited=children.filter(c=>(c.weeklyPocket??500)>0&&c.lastWeekCredit!==wk);
        if(credited.length===0){ setWeekReady(true); return; }
        setChildren(p=>p.map(c=>(c.weeklyPocket??500)>0&&c.lastWeekCredit!==wk?{...c,pocket:(c.pocket||0)+c.weeklyPocket,lastWeekCredit:wk}:c));
        setMoneyLog(p=>[...p.slice(-(CHF_LOG_MAX-credited.length)),...credited.map(c=>({id:uid(),cid:c.id,cents:c.weeklyPocket,kind:'payout',label:t('money.weekly_label'),date:todayStr()}))]);
        setWeekReady(true);
      },[loaded,weekReady]);
      useEffect(()=>{if(loaded)try{storage.set("ft-pin",adminPin);}catch(e){};},[adminPin,loaded]);

      // Geld-Felder für Datensätze ergänzen, die vor dem Sackgeld-Feature
      // angelegt wurden (einmalig, danach bleibt der gespeicherte Wert).
      useEffect(()=>{
        if(!loaded) return;
        setChildren(p=>{let ch=false;const n=p.map(c=>{
          if(typeof c.pocket==='number'&&typeof c.weeklyPocket==='number') return c;
          ch=true;return {...c,pocket:c.pocket||0,weeklyPocket:(typeof c.weeklyPocket==='number'?c.weeklyPocket:500)};
        });return ch?n:p;});
        setFines(p=>{let ch=false;const n=p.map(f=>{if(typeof f.moneyPenalty==='number') return f;ch=true;return {...f,moneyPenalty:0};});return ch?n:p;});
      },[loaded]);

      useEffect(()=>{
        if(!loaded)return;
        const d=todayStr(),n=new Date(),nowMin=n.getHours()*60+n.getMinutes(),dow=n.getDay();
        let nc={...comps},nch=[...children],changed=false;
        tasks.forEach(task=>{
          if(!task.repeatDays.includes(dow))return;
          const[dh,dm]=task.dueTime.split(":").map(Number);if(nowMin<dh*60+dm)return;
          task.assignedTo.forEach(cid=>{const key=`${cid}_${task.id}_${d}`,ex=nc[key];
            if(!ex?.completed&&!ex?.penaltyApplied){nc[key]={completed:false,penaltyApplied:true};const ci=nch.findIndex(c=>c.id===cid);if(ci>=0){nch[ci]={...nch[ci],earnedMinutes:Math.max(0,nch[ci].earnedMinutes-task.minutesPenalty)};changed=true;_trackEvent('task_missed',cid,nch[ci].name,task.id,task.title,task.minutesPenalty);}}
          });
        });
        if(changed){setChildren(nch);setComps(nc);}
      },[loaded]);

      const startTimer=(cid,secs)=>{setTimers(p=>({...p,[cid]:{endsAt:Date.now()+secs*1000,totalSecs:secs}}));delete firedRef.current[cid];setAlarms(a=>a.filter(x=>x!==cid));showToast(t('timer.started'),"inf");};
      const stopTimer=cid=>{setTimers(p=>({...p,[cid]:null}));delete firedRef.current[cid];setAlarms(a=>{const n=a.filter(x=>x!==cid);if(!n.length)stopAlarmSound();return n;});};
      const dismissAlarm=cid=>{stopAlarmSound();setAlarms(a=>a.filter(x=>x!==cid));setTimers(p=>({...p,[cid]:null}));};
      const getTimerSecs=cid=>{const t=timers[cid];if(!t)return null;return Math.max(0,Math.round((t.endsAt-Date.now())/1000));};
      const getTimerPct=cid=>{const t=timers[cid];if(!t||!t.totalSecs)return 0;const s=getTimerSecs(cid);return Math.min(100,(s/t.totalSecs)*100);};

      // Media Timer Functions
      const getMediaTimer=(cid)=>mediaTimers[cid]||null;
      const getMediaTimerSecs=(cid)=>{const t=mediaTimers[cid];if(!t)return null;return Math.max(0,Math.round((t.endsAt-Date.now())/1000));};
      const getMediaTimerPct=(cid)=>{const t=mediaTimers[cid];if(!t||!t.duration)return 0;const s=getMediaTimerSecs(cid);return Math.min(100,(s/t.duration)*100);};
      const startMediaTimer=(cid,mediaId,mins)=>{
        const mediaType=mediaTypes.find(m=>m.id===mediaId);
        if(!mediaType) return;
        const dailyUsed=getMediaUsed(cid,mediaId);
        const dailyLim=selChild.mediaLimits?.[mediaId]||0;
        const weekUsed=getMediaUsedWeek(cid,mediaId);
        const weekLim=getWeeklyLimit(cid,mediaId);
        const weekRem=Math.max(0,weekLim-weekUsed);
        const budget=mediaType.carryOver?weekRem:Math.max(0,dailyLim-dailyUsed);
        const ok=selChild.earnedMinutes>=mins&&budget>=mins;
        if(!ok) return;
        const now=Date.now();
        setMediaTimers(p=>({...p,[cid]:{mediaId,startedAt:now,duration:mins*60*1000,endsAt:now+mins*60*1000,originalMins:mins}}));
        // Also start general timer for this child
        startTimer(cid,mins*60);
        setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:c.earnedMinutes-mins}:c));
        _trackEvent('media_used',cid,selChild.name,mediaId,mediaType.label,mins);
        showToast(t('media.timer_started',{label:mediaType.label,mins}),"inf");
      };
      const stopMediaTimer=(cid)=>{
        const timer=mediaTimers[cid];
        if(!timer) return;
        const usedSecs=Math.floor((Date.now()-timer.startedAt)/1000);
        const usedMins=Math.max(1,Math.ceil(usedSecs/60));
        const unused=Math.max(0,timer.originalMins-usedMins);
        if(unused>0){
          setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:c.earnedMinutes+unused}:c));
        }
        // Also stop general timer for this child
        stopTimer(cid);
        setMediaTimers(p=>{const n={...p};delete n[cid];return n;});
        showToast(t('media.timer_stopped',{used:usedMins,label:mediaTypes.find(m=>m.id===timer.mediaId)?.label||timer.mediaId}),"inf");
      };
      const checkMediaTimers=()=>{
        const now=Date.now();
        Object.entries(mediaTimers).forEach(([cid,timer])=>{
          if(timer.endsAt<=now){
            // Timer expired - trigger alarm
            setAlarms(a=>[...a,cid]);
            playAlarmSound();
            // Also clear general timer
            stopTimer(cid);
            setMediaTimers(p=>{const n={...p};delete n[cid];return n;});
          }
        });
      };

      const getChild=id=>children.find(c=>c.id===id);
      const getByDow=(c,d)=>tasks.filter(t=>t.assignedTo.includes(c)&&t.repeatDays.includes(d)).sort((a,b)=>a.dueTime.localeCompare(b.dueTime));
      const getTodayTasks=cid=>getByDow(cid,new Date().getDay());
      const isDone=(c,t,d=todayStr())=>comps[`${c}_${t}_${d}`]?.completed||false;
      const isMissed=(c,t,d=todayStr())=>{const x=comps[`${c}_${t}_${d}`];return x&&!x.completed&&x.penaltyApplied;};
      const getMediaUsed=(c,t)=>mediaU[`${c}_${t}_${todayStr()}`]||0;
      const toggleTask=(cid,tid)=>{
        const d=todayStr(),key=`${cid}_${tid}_${d}`,ex=comps[key],task=tasks.find(t=>t.id===tid);
        if(!task) return;
        const now=new Date(),nowMin=now.getHours()*60+now.getMinutes();
        const[dh,dm]=task.dueTime.split(":").map(Number);
        const deadlinePassed=nowMin>=dh*60+dm;
        if(ex?.penaltyApplied && deadlinePassed) return;
        const child=getChild(cid);
        if(ex?.completed){
          setComps(p=>({...p,[key]:{completed:false,penaltyApplied:false}}));
          setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:Math.max(0,c.earnedMinutes-task.minutesReward)}:c));
          showToast(t('common.undo'),"inf");
        } else {
          if(ex?.penaltyApplied && !deadlinePassed){
            setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:c.earnedMinutes+task.minutesPenalty}:c));
          }
          setComps(p=>({...p,[key]:{completed:true,penaltyApplied:false}}));
          setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:c.earnedMinutes+task.minutesReward}:c));
          _trackEvent('task_done', cid, child?.name||cid, tid, task.title, task.minutesReward);
          showToast(t('task.earned_minutes',{minutes:task.minutesReward}));
        }
      };
      const getWeekStart=()=>{const d=new Date(),dow=d.getDay(),diff=dow===0?-6:1-dow;d.setDate(d.getDate()+diff);return d.toISOString().split('T')[0];};
      const getMediaUsedWeek=(cid,type)=>{const ws=getWeekStart();let tot=0;for(let i=0;i<7;i++){const d=new Date(ws+'T00:00:00');d.setDate(d.getDate()+i);tot+=mediaU[`${cid}_${type}_${d.toISOString().split('T')[0]}`]||0;}return tot;};
      const getWeeklyLimit=(cid,type)=>{const ch=getChild(cid);if(!ch)return 0;return getMediaAllowedDays(cid,type).length*(ch.mediaLimits?.[type]||0);};
      const getWeeklyRemaining=(cid,type)=>Math.max(0,getWeeklyLimit(cid,type)-getMediaUsedWeek(cid,type));
      const getMediaAllowedDays=(cid,type)=>{const ch=getChild(cid);if(!ch)return[0,1,2,3,4,5,6];return ch.mediaDays?.[type]??[0,1,2,3,4,5,6];};
      const isMediaAllowedToday=(cid,type)=>getMediaAllowedDays(cid,type).includes(new Date().getDay());
      const useMedia=(cid,type,mins)=>{
        const ch=getChild(cid);
        if(!ch||ch.earnedMinutes<mins){showToast(t('media.not_enough_minutes'),"err");return;}
        if(!isMediaAllowedToday(cid,type)){showToast(t('media.not_allowed_today',{label:mediaTypes.find(m=>m.id===type)?.label||type}),"err");return;}
        const mt=mediaTypes.find(m=>m.id===type);
        if(mt?.carryOver){
          const wr=getWeeklyRemaining(cid,type);
          if(mins>wr){showToast(t('media.weekly_budget_reached',{remaining:wr}),"err");return;}
        } else {
          if(getMediaUsed(cid,type)+mins>(ch.mediaLimits?.[type]||0)){showToast(t('media.daily_limit_reached'),"err");return;}
        }
        setMediaU(p=>({...p,[`${cid}_${type}_${todayStr()}`]:(p[`${cid}_${type}_${todayStr()}`]||0)+mins}));
        setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:c.earnedMinutes-mins}:c));
        _trackEvent('media_used',cid,ch.name,type,mt?.label||type,mins);
        showToast(t('media.unlocked',{minutes:mins}));
      };

      // ── Strafen ─────────────────────────────────────────────────────────────
      const getFineKey    = (cid,fid) => `${cid}_${fid}_${todayStr()}`;
      const getFineDeductions = (cid,fid) => {
        const v = fineHits[getFineKey(cid,fid)];
        if (!v) return [];
        if (Array.isArray(v)) return v;
        // Migration: old format was number (count) or {count, deduction}
        if (typeof v === 'number') return Array(v).fill(0); // assume full deduction
        if (v.count) return Array(v.count).fill(v.deduction / v.count || 0);
        return [];
      };
      const getFineCount  = (cid,fid) => getFineDeductions(cid,fid).length;
      const getTodayFineMinutes = (cid) => fines.filter(f=>f.assignedTo.includes(cid)).reduce((sum,f)=>sum+(getFineCount(cid,f.id)*f.minutesPenalty),0);

      // ── Sackgeld (CHF) ──────────────────────────────────────────────────────
      // Kontoführung analog zu den Minuten: child.pocket ist der Saldo,
      // moneyLog das Kontoauszugsbuch (kind: 'payout' | 'fine').
      const getFineMoneyHits = (cid,fid) => moneyLog.filter(e=>e.kind==='fine'&&e.cid===cid&&e.fid===fid).map(e=>e.cents);
      const addMoneyLog = (cid,cents,kind,label,fid) =>
        setMoneyLog(p=>[...p.slice(-(CHF_LOG_MAX-1)),{id:uid(),cid,cents,kind,label,date:todayStr(),...(fid?{fid}:{})}]);
      // Guthaben darf ins Minus gehen – das Kind schuldet dann Geld.
      const addPocket = (cid,cents) => setChildren(p=>p.map(c=>c.id===cid?{...c,pocket:(c.pocket||0)+cents}:c));
      // Letzten Geld-Abzug dieser Strafe zurückbuchen (für undoFine / delFine).
      const popFineMoney = (cid,fid) => {
        for(let i=moneyLog.length-1;i>=0;i--){
          const e=moneyLog[i];
          if(e.kind==='fine'&&e.cid===cid&&e.fid===fid){
            setMoneyLog(p=>p.filter(x=>x.id!==e.id));
            return Math.abs(e.cents);
          }
        }
        return 0;
      };
      const getTodayFineMoney = (cid) => {
        const d=todayStr();
        return moneyLog.filter(e=>e.cid===cid&&e.kind==='fine'&&e.date===d).reduce((s,e)=>s+Math.abs(e.cents),0);
      };
      const childMoneyLog = (cid) => moneyLog.filter(e=>e.cid===cid).slice().reverse();
      // "−15' · −1.00 CHF" — je nachdem, was die Strafe kostet.
      const finePenaltyLabel = f => {
        const p=[];
        if(f.minutesPenalty>0) p.push(`-${f.minutesPenalty} ${t('common.minutes_short')}`);
        if((f.moneyPenalty||0)>0) p.push(`-${fmtCHF(f.moneyPenalty)}`);
        return p.length?p.join(' · '):'-';
      };

      const applyFine = (cid, fid) => {
        const fine=fines.find(f=>f.id===fid),child=getChild(cid);
        if(!fine||!child) return;
        const key=getFineKey(cid,fid);
        const actualDeduction = Math.min(fine.minutesPenalty, child.earnedMinutes);
        setFineHits(p=>({...p,[key]:[...(p[key]||[]), actualDeduction]}));
        setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:c.earnedMinutes-actualDeduction}:c));
        const cash = fine.moneyPenalty||0;
        if(cash>0){ addPocket(cid,-cash); addMoneyLog(cid,-cash,'fine',fine.title,fid); }
        _trackEvent('task_missed',cid,child.name,fid,'⚠️ '+fine.title,actualDeduction);
        showToast(cash>0
          ? t('fines.applied_money',{minutes:actualDeduction,money:fmtCHF(cash),title:fine.title})
          : t('fines.applied',{minutes:actualDeduction,title:fine.title}),'err');
      };
      const undoFine = (cid, fid) => {
        const fine=fines.find(f=>f.id===fid);
        if(!fine) return;
        const key=getFineKey(cid,fid);
        const deductions = getFineDeductions(cid,fid);
        if(deductions.length===0) return;
        const lastDeduction = deductions[deductions.length-1];
        setFineHits(p=>({...p,[key]:deductions.slice(0,-1)}));
        setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:c.earnedMinutes+lastDeduction}:c));
        const cash = popFineMoney(cid,fid);
        if(cash>0) addPocket(cid,cash);
        showToast(t('common.undo'),'inf');
      };
      const [editFine,setEditFine]=useState(null);
      const saveFine=f=>{const{moneyPenaltyRaw,...rest}=f;if(rest.id)setFines(p=>p.map(x=>x.id===rest.id?rest:x));else setFines(p=>[...p,{...rest,id:uid()}]);setEditFine(null);showToast(t('common.saved'));};
      const delFine=id=>{
        const fine=fines.find(f=>f.id===id);
        if(!fine) return;
        const cid = fine.assignedTo[0]||'c1';
        const key=getFineKey(cid, fine.id);
        const deductions = getFineDeductions(cid,fine.id);
        if(deductions.length>0) {
          const lastDeduction = deductions[deductions.length-1];
          setFineHits(p=>({...p,[key]:deductions.slice(0,-1)}));
          setChildren(p=>p.map(c=>c.id===cid?{...c,earnedMinutes:c.earnedMinutes+lastDeduction}:c));
        }
        const cash = popFineMoney(cid, fine.id);
        if(cash>0) addPocket(cid,cash);
        setFines(p=>p.filter(f=>f.id!==id));
        showToast(t('common.delete'),'inf');
      };

      // ── Sackgeld-Aktionen (alle PIN-geschützt, weil echtes Geld betroffen) ──
      // Vier Aktionen teilen sich PIN + Betrag-Dialog (Konstanten liegen oben auf
      // Modulebene, damit MoneyModal stabil bleibt):
      //   payout  – Eltern zahlen aus            (+ Betrag)
      //   savings – Betrag wandert ins Sparkonto (− Betrag)
      //   gift    – Eltern haben etwas gekauft    (− Betrag)
      //   fine    – Strafen anwenden / zurücknehmen (wirkt direkt, kein Betragsfeld)
      const [moneyModal,setMoneyModal]=useState(null);
      // moneyModal = {kind:'payout'|'savings'|'gift'|'fine', cid, fid?, dir?, step:'pin'|'input', pinInput, pinErr, cents, note}
      const openMoneyAction=(kind,cid)=>{
        const child=getChild(cid);
        if(!child) return;
        const weekly=((child.weeklyPocket??500)/100).toFixed(2);
        setMoneyModal({kind,cid,step:'pin',pinInput:'',pinErr:false,note:'',cents:weekly});
      };
      const openPayout=cid=>openMoneyAction('payout',cid);
      // Strafen ohne Geldbeteil bleiben wie bisher (ein Tap = sofort).
      const requestFine=(cid,fid,dir)=>{
        const fine=fines.find(f=>f.id===fid);
        if(!fine) return;
        if(dir==='+'&&(fine.moneyPenalty||0)<=0) return applyFine(cid,fid);
        if(dir==='-'&&getFineMoneyHits(cid,fid).length===0) return undoFine(cid,fid);
        setMoneyModal({kind:'fine',cid,fid,dir,step:'pin',pinInput:'',pinErr:false});
      };
      const moneyPinDigit=d=>{
        if(!moneyModal) return;
        if(d==='⌫'){setMoneyModal(p=>({...p,pinInput:p.pinInput.slice(0,-1),pinErr:false}));return;}
        const next=moneyModal.pinInput+d;
        if(next.length<4){setMoneyModal(p=>({...p,pinInput:next}));return;}
        if(next!==adminPin){setMoneyModal(p=>({...p,pinInput:next,pinErr:true}));setTimeout(()=>setMoneyModal(p=>p?({...p,pinInput:'',pinErr:false}):p),700);return;}
        if(moneyModal.kind==='fine'){
          const {cid,fid,dir}=moneyModal;
          setMoneyModal(null);
          if(dir==='+') applyFine(cid,fid); else undoFine(cid,fid);
        } else setMoneyModal(p=>({...p,step:'input',pinInput:'',pinErr:false}));
      };
      const confirmMoneyAction=()=>{
        if(!moneyModal) return;
        const cfg=MONEY_ACTIONS[moneyModal.kind];
        if(!cfg) return;
        const cents=parseCHF(moneyModal.cents);
        if(cents<=0){showToast(t('money.enter_amount'),'err');return;}
        const cid=moneyModal.cid,child=getChild(cid);
        const signed=cfg.sign*cents;
        addPocket(cid,signed);
        addMoneyLog(cid,signed,cfg.log,(moneyModal.note||'').trim()||cfg.hint());
        showToast(cfg.msg(fmtCHF(cents)+(child?' · '+child.name:'')),cfg.sign>0?'ok':'inf');
        setMoneyModal(null);
      };

      // ── Bonus Zeit ───────────────────────────────────────────────────────────
      const [bonusModal,setBonusModal]=useState(null); // {cid, step:'pin'|'input', pinInput, pinErr, mins, reason}
      const openBonus=(cid)=>setBonusModal({cid,step:'pin',pinInput:'',pinErr:false,mins:'',reason:BONUS_REASONS[0].label});
      const bonusPinDigit=(d)=>{
        if(!bonusModal) return;
        if(d==='⌫'){setBonusModal(p=>({...p,pinInput:p.pinInput.slice(0,-1),pinErr:false}));return;}
        const next=bonusModal.pinInput+d;
        if(next.length<4){setBonusModal(p=>({...p,pinInput:next}));return;}
        if(next===adminPin){setBonusModal(p=>({...p,step:'input',pinInput:'',pinErr:false}));}
        else{setBonusModal(p=>({...p,pinInput:next,pinErr:true}));setTimeout(()=>setBonusModal(p=>p?({...p,pinInput:'',pinErr:false}):p),700);}
      };
      const confirmBonus=()=>{
        if(!bonusModal) return;
        const mins=parseInt(bonusModal.mins)||0;
        if(mins<=0){showToast(t('bonus.please_enter_minutes'),'err');return;}
        const child=getChild(bonusModal.cid);
        setChildren(p=>p.map(c=>c.id===bonusModal.cid?{...c,earnedMinutes:c.earnedMinutes+mins}:c));
        _trackEvent('task_done',bonusModal.cid,child?.name||'',`bonus_${Date.now()}`,`🎁 ${bonusModal.reason}`,mins);
        showToast(t('bonus.granted',{minutes:mins,reason:bonusModal.reason}),'ok');
        setBonusModal(null);
      };
      const fmtShort=d=>d.toLocaleTimeString(getLocale(),{hour:"2-digit",minute:"2-digit"});
      const getWeekDates=(off=0)=>{const d=new Date(),dow=d.getDay(),mon=new Date(d);mon.setDate(d.getDate()-(dow===0?6:dow-1)+off*7);return Array.from({length:7},(_,i)=>{const x=new Date(mon);x.setDate(mon.getDate()+i);return x;});};
      const handlePin=digit=>{if(digit==="⌫"){setPinInput(p=>p.slice(0,-1));setPinErr(false);return;}const next=pinInput+digit;setPinInput(next);if(next.length===4){if(next===adminPin){setAdminOpen(true);setShowPin(false);setPinInput("");setPinErr(false);setScreen("admin");}else{setPinErr(true);setTimeout(()=>{setPinInput("");setPinErr(false);},700);}}};
      const saveChild=c=>{const{pocketRaw,startPocketRaw,...rest}=c;if(rest.id)setChildren(p=>p.map(x=>x.id===rest.id?rest:x));else setChildren(p=>[...p,{...rest,id:uid(),earnedMinutes:rest.earnedMinutes||0,pocket:rest.pocket||0,weeklyPocket:(typeof rest.weeklyPocket==='number'?rest.weeklyPocket:500),lastWeekCredit:weekKey(new Date())}]);setEditChild(null);showToast(t('common.saved'));};
      const delChild=id=>{setChildren(p=>p.filter(c=>c.id!==id));setTasks(p=>p.map(t=>({...t,assignedTo:t.assignedTo.filter(x=>x!==id)})));showToast(t('common.delete'),"inf");};
      const saveTask=t=>{if(t.id)setTasks(p=>p.map(x=>x.id===t.id?t:x));else setTasks(p=>[...p,{...t,id:uid()}]);setEditTask(null);showToast(t('common.saved'));};
      const delTask=id=>{setTasks(p=>p.filter(t=>t.id!==id));showToast(t('common.delete'),"inf");};
      const selChild=getChild(selCid);

      const TimerChips=()=>(
        <div className="ft-hdr-timers">
          {children.map(c=>(
            <div key={c.id} id={'dom-chip-'+c.id} className="ft-tchip" style={{"--tc":c.color,"--tcr":h2r(c.color)}} onClick={()=>{setTimerModal(c.id);setCustMin("");setCustSec("");}}>
              <div className="ft-tchip-row"><span className="ft-tchip-emoji">{c.emoji}</span><span className="ft-tchip-name">{c.name}</span></div>
              <span id={'dom-chip-val-'+c.id} className="ft-tchip-val">⏱</span>
            </div>
          ))}
        </div>
      );

      const AlarmOverlay=()=>{if(!alarms.length)return null;const cid=alarms[0],c=getChild(cid);if(!c)return null;
        return(<div className="ft-alarm"><div className="ft-alarm-glow" style={{"--ar":h2r(c.color)}}/><div className="ft-alarm-emoji">⏰</div><div className="ft-alarm-name">{c.name}</div><div className="ft-alarm-sub">{t('alarm.expired')}</div><button className="ft-alarm-btn" onClick={()=>dismissAlarm(cid)}>{t('alarm.dismiss')}</button></div>);
      };

      const isBirthdayToday = c => c.birthday && todayStr().substr(5)===c.birthday.substr(5);
      const Home=()=>(<div className="ft-home"><div className="ft-sec-title">{t('home.who_are_you')}</div><div className="ft-grid">{children.map(c=>{const tt=getTodayTasks(c.id),done=tt.filter(t=>isDone(c.id,t.id)).length,todayFines=getTodayFineMinutes(c.id),bday=isBirthdayToday(c);const openChild=()=>{setSelCid(c.id);setChildTab("tasks");setCalDay(todayStr());setScreen("child");};return(<div key={c.id} className="ft-child-card" role="button" tabIndex={0} style={{"--col":c.color,"--cold":darken(c.color,50)}} onClick={openChild} onKeyDown={e=>{(e.key==='Enter'||e.key===' ')&&(e.preventDefault(),openChild());}}>{c.image?<img src={c.image} className="ft-child-avatar" alt={c.name}/>:<span className="ft-child-emoji">{c.emoji}</span>}<div className="ft-child-name">{c.name}</div><div className="ft-child-badge">{t('home.earned',{minutes: c.earnedMinutes})}</div><div className="ft-child-prog">{t('home.tasks_today',{done,total:tt.length})}</div>{bday&&<div className="ft-child-prog" style={{color:'#FFE66D',fontWeight:900}}>{t('home.birthday')}</div>}{todayFines>0&&<div className="ft-child-prog" style={{color:'rgba(255,160,160,0.9)'}}>{t('home.fines_today',{minutes:todayFines})}</div>}</div>);})}</div></div>);

      const TasksTab=()=>{if(!selChild)return null;const tt=getTodayTasks(selChild.id);if(!tt.length)return<div className="ft-empty"><div style={{fontSize:40,marginBottom:9}}>🎉</div>{t('tasks.empty')}</div>;return(<div className="ft-task-grid">{tt.map(task=>{const done=isDone(selChild.id,task.id),missed=isMissed(selChild.id,task.id);return(<div key={task.id} className={`ft-task ${done?"done":""} ${missed?"missed":""}`} onClick={()=>toggleTask(selChild.id,task.id)}><div className="ft-task-check">{done?"✓":""}</div>{task.image?<img src={task.image} className="ft-task-img" alt={task.title}/>:<span className="ft-task-ic">{task.icon}</span>}<div className="ft-task-title">{task.title}</div><div className="ft-task-due">{t('task.due_until',{time:task.dueTime})}</div>{missed&&<div className="ft-task-pen">{t('task.missed',{penalty:task.minutesPenalty})}</div>}{done?<div className="ft-task-reward">⭐</div>:<><div className="ft-task-reward">+{task.minutesReward}'</div><div className="ft-task-rsub">{t('task.penalty_sub',{penalty:task.minutesPenalty})}</div></>}</div>);})}</div>);};

      const CalendarTab=()=>{
        if(!selChild) return null;
        const wd=getWeekDates(calOffset),td=todayStr();
        const selDate=new Date(calDay+"T00:00:00");
        const selTasks=getByDow(selChild.id,selDate.getDay());
        const weekLbl=calOffset===0?t('week.this'):calOffset===-1?t('week.last'):calOffset===1?t('week.next'):calOffset>0?t('week.offset',{n:calOffset}):t('week.offset_neg',{n:Math.abs(calOffset)});

        // Termine die für dieses Kind und ein bestimmtes Datum gelten (inkl. Recurrence)
        const termineForDay = (ds) => {
          const evs = termine.filter(t => {
            if(t.assignedTo.length>0 && !t.assignedTo.includes(selChild.id)) return false;
            if(t.recurrence?.type){
              if(ds < t.dateFrom) return false;
              if(t.recurrence.endDate && ds > t.recurrence.endDate) return false;
              const d=new Date(ds+'T00:00:00');
              if(t.recurrence.type==='weekly') return (t.recurrence.days||[]).includes(d.getDay());
              if(t.recurrence.type==='monthly') return d.getDate()===t.recurrence.dayOfMonth;
              if(t.recurrence.type==='yearly'){const md=t.recurrence.monthDay;return md===String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0");}
              return false;
            }
            const from=t.dateFrom, to=t.dateTo||t.dateFrom;
            return ds>=from && ds<=to;
          });
          // Geburtstage der Kinder
          children.filter(c=>c.birthday&&c.id===selChild.id&&ds.substr(5)===c.birthday.substr(5)).forEach(c=>{
            const bd=c.birthday; if(!bd)return;
            evs.push({id:'bday_'+c.id,title:c.name+' 🎂',emoji:'🎂',dateFrom:ds,dateTo:ds,allDay:true,timeFrom:'',timeTo:'',assignedTo:[c.id],recurrence:null,_birthday:true});
          });
          return evs;
        };

        return(<>
          <div className="ft-week-nav">
            <button className="ft-week-btn" onClick={()=>setCalOffset(o=>o-1)}>‹</button>
            <div className="ft-week-lbl">{weekLbl}</div>
            <button className="ft-week-btn" onClick={()=>setCalOffset(o=>o+1)}>›</button>
          </div>
          <div className="ft-days-row">
            {wd.map((date,i)=>{
              const ds=getDs(date),isToday=ds===td,isSel=ds===calDay;
              const dtasks=getByDow(selChild.id,date.getDay());
              const doneCnt=dtasks.filter(t=>isDone(selChild.id,t.id,ds)).length;
              const missedCnt=dtasks.filter(t=>isMissed(selChild.id,t.id,ds)).length;
              const hasTermin=termineForDay(ds).length>0;
              const recTermine=termine.filter(t=>{
                if(t.assignedTo.length>0&&!t.assignedTo.includes(selChild.id))return false;
                return matchesRecurrence(t, ds);
              });
              const hasRecurring=recTermine.length>0;
              return(<div key={i} className={`ft-day ${isToday?"today":""} ${isSel?"sel":""}`} style={isSel?{background:selChild.color}:{}} onClick={()=>setCalDay(ds)}>
                <div className="ft-day-lbl">{DAYS_MON[i]}</div>
                <div className="ft-day-num">{date.getDate()}</div>
                <div className="ft-day-dots">
                  {Array.from({length:Math.min(doneCnt,3)},(_,j)=><div key={"g"+j} className="ft-dot" style={{background:"#6BCB77"}}/>)}
                  {Array.from({length:Math.min(missedCnt,3)},(_,j)=><div key={"r"+j} className="ft-dot" style={{background:"#FF6B6B"}}/>)}
                  {(hasTermin||hasRecurring)&&<div className="ft-dot" style={{background:"#FFE66D"}}/>}
                  {hasRecurring&&<div className="ft-dot" style={{background:"#4ECDC4"}}/>}
                </div>
              </div>);
            })}
          </div>
          <div className="ft-cal-day-title">{selDate.toLocaleDateString(getLocale(),{weekday:"long",day:"numeric",month:"long"})}</div>

          {/* Termine des ausgewählten Tages */}
          {termineForDay(calDay).map(t=>(
            <div key={t.id} className="ft-cal-task" style={{background:'rgba(255,230,109,0.07)',border:'1.5px solid rgba(255,230,109,0.18)',marginBottom:6}}>
              <span className="ft-cal-ic">{t.emoji||'📅'}</span>
              <div className="ft-cal-info">
                <div className="ft-cal-title" style={{color:'#FFE66D'}}>{t.title}</div>
                <div className="ft-cal-due">
                  {t.recurrence?.type==='weekly'?t('calendar.recurring_weekly')+(t.timeFrom?' · '+t.timeFrom+' '+t('common.o_clock'):'')
                    : t.recurrence?.type==='monthly'?t('calendar.recurring_monthly',{day:t.recurrence.dayOfMonth})+(t.timeFrom?' · '+t.timeFrom+' '+t('common.o_clock'):'')
                    : t.recurrence?.type==='yearly'?t('calendar.recurring_yearly')+(t.timeFrom?' · '+t.timeFrom+' '+t('common.o_clock'):'')
                    : t.allDay?t('calendar.all_day'):t('calendar.time_range',{from:t.timeFrom||'?',to:t.timeTo||'?'})}
                </div>
              </div>
              {!t._birthday&&<span className="ft-cal-ic" style={{fontSize:16,marginLeft:4,cursor:'default',opacity:0.5}}>📅</span>}
            </div>
          ))}

          {/* Aufgaben des ausgewählten Tages */}
          {selTasks.length===0 && termineForDay(calDay).length===0
            ? <div className="ft-empty">{t('calendar.nothing_today')}</div>
            : selTasks.map(tsk=>{
                const done=isDone(selChild.id,tsk.id,calDay),missed=isMissed(selChild.id,tsk.id,calDay);
                return(<div key={tsk.id} className="ft-cal-task">
                  <span className="ft-cal-ic">{tsk.icon}</span>
                  <div className="ft-cal-info"><div className="ft-cal-title">{tsk.title}</div><div className="ft-cal-due">{t('calendar.bis',{time:tsk.dueTime})} · +{tsk.minutesReward} {t('common.minutes_short')}</div></div>
                  <span className="ft-cal-status">{done?"✅":missed?"❌":"⏳"}</span>
                </div>);
              })
          }
        </>);
      };

      const FinesTab=()=>{
        if(!selChild) return null;
        const myFines=fines.filter(f=>f.assignedTo.includes(selChild.id));
        const totalToday=getTodayFineMinutes(selChild.id);
        const cashToday=getTodayFineMoney(selChild.id);
        return(<>
          <div style={{background:'rgba(255,107,107,0.07)',border:'1.5px solid rgba(255,107,107,0.18)',borderRadius:14,padding:'12px 14px',marginBottom:12,display:'flex',justifyContent:'space-between',alignItems:'center'}}>
            <div><div style={{fontSize:11,color:'#FF9A9A',fontWeight:800,textTransform:'uppercase',letterSpacing:.5}}>{t('fines.today')}</div><div style={{fontFamily:"'Fredoka One',sans-serif",fontSize:28,color:'#FF6B6B',lineHeight:1}}>{t('fines.total',{minutes:totalToday})}</div>{cashToday>0&&<div style={{fontSize:11,color:'#6BCB77',fontWeight:800,marginTop:3}}>{t('money.fines_today',{amount:fmtCHF(cashToday)})}</div>}</div>
            <div style={{fontSize:36}}>⚠️</div>
          </div>
          {myFines.length===0 && <div className="ft-empty"><div style={{fontSize:36,marginBottom:8}}>✅</div>{t('fines.none_configured')}</div>}
          <div className="ft-fine-grid">{myFines.map(f=>{
            const cnt=getFineCount(selChild.id,f.id);
            return(<div key={f.id} className="ft-fine-card">
              {cnt>0&&<div className="ft-fine-count"><div className="ft-fine-count-num">{cnt}</div><div className="ft-fine-count-lbl">×</div></div>}
              <span className="ft-fine-icon">{f.icon}</span>
              <div className="ft-fine-title">{f.title}</div>
              <div className="ft-fine-sub">{finePenaltyLabel(f)}</div>
              <div className="ft-fine-actions">
                {cnt>0&&<button className="ft-fine-undo" onClick={()=>requestFine(selChild.id,f.id,'-')} title={t('common.undo')}>↩</button>}
                <button className="ft-fine-btn" onClick={()=>requestFine(selChild.id,f.id,'+')}>+</button>
              </div>
            </div>);
          })}</div>
        </>);
      };



      const MoneyTab=()=>{
        if(!selChild) return null;
        const entries=childMoneyLog(selChild.id).slice(0,20);
        const cashFines=fines.filter(f=>f.assignedTo.includes(selChild.id)&&(f.moneyPenalty||0)>0);
        const weekly=selChild.weeklyPocket??500;
        const pocket=selChild.pocket||0;
        const fineMoneyToday=getTodayFineMoney(selChild.id);
        return(<>
          <div className={`ft-money-hero ${pocket<0?'neg':''}`}>
            <div className="ft-money-hero-lbl">{pocket<0?t('money.debt'):t('money.balance')}</div>
            <div className="ft-money-hero-val">{fmtCHF(pocket)}</div>
            <div className="ft-money-hero-sub">{t('money.weekly_hint',{amount:fmtCHF(weekly)})}</div>
          </div>
          <button className="ft-money-pay" onClick={()=>openPayout(selChild.id)}>
            <span className="ft-money-pay-ic">💶</span>
            <span className="ft-money-pay-tx">{t('money.pay_out',{amount:fmtCHF(weekly)})}</span>
          </button>
          <div className="ft-money-row">
            <button className="ft-money-out" onClick={()=>openMoneyAction('savings',selChild.id)}>
              <span className="ft-money-out-ic">🏦</span>
              <span className="ft-money-out-lb">{t('money.to_savings')}</span>
            </button>
            <button className="ft-money-out" onClick={()=>openMoneyAction('gift',selChild.id)}>
              <span className="ft-money-out-ic">🎁</span>
              <span className="ft-money-out-lb">{t('money.personal')}</span>
            </button>
          </div>
          {fineMoneyToday>0&&<div className="ft-money-alert">{t('money.fines_today',{amount:fmtCHF(fineMoneyToday)})}</div>}
          <div className="ft-sec-title" style={{marginTop:16}}>{t('money.fines_title')}</div>
          {cashFines.length===0
            ? <div className="ft-empty" style={{padding:'18px 12px'}}><div style={{fontSize:28,marginBottom:6}}>🫙</div>{t('money.no_money_fines')}</div>
            : <div className="ft-fine-grid">{cashFines.map(f=>{
              const cnt=getFineCount(selChild.id,f.id);
              return(<div key={f.id} className="ft-fine-card">
                {cnt>0&&<div className="ft-fine-count"><div className="ft-fine-count-num">{cnt}</div><div className="ft-fine-count-lbl">×</div></div>}
                <span className="ft-fine-icon">{f.icon}</span>
                <div className="ft-fine-title">{f.title}</div>
                <div className="ft-fine-sub">{finePenaltyLabel(f)}</div>
                <div className="ft-fine-actions">
                  {cnt>0&&<button className="ft-fine-undo" onClick={()=>requestFine(selChild.id,f.id,'-')} title={t('common.undo')}>↩</button>}
                  <button className="ft-fine-btn" onClick={()=>requestFine(selChild.id,f.id,'+')}>+</button>
                </div>
              </div>);
            })}</div>}
          <div className="ft-sec-title" style={{marginTop:16}}>{t('money.history')}</div>
          {entries.length===0
            ? <div className="ft-empty" style={{padding:'18px 12px'}}><div style={{fontSize:28,marginBottom:6}}>🧾</div>{t('money.no_history')}</div>
            : <div className="ft-money-log">{entries.map(e=>(
                <div key={e.id} className="ft-money-log-row">
                  <span className={`ft-money-log-ic ${e.cents>=0?'pos':'neg'}`}>{MONEY_LOG_ICONS[e.kind]||'⚠️'}</span>
                  <div className="ft-money-log-info">
                    <div className="ft-money-log-t">{e.label}</div>
                    <div className="ft-money-log-d">{e.date}</div>
                  </div>
                  <div className={`ft-money-log-v ${e.cents>=0?'pos':'neg'}`}>{e.cents>=0?'+':'−'}{fmtCHF(Math.abs(e.cents))}</div>
                </div>))}</div>}
        </>);
      };

      const AdminChildren=()=>(<div>{children.map(c=>(<div key={c.id} className="ft-aitem"><div style={{width:34,height:34,borderRadius:10,background:c.color,display:"flex",alignItems:"center",justifyContent:"center",fontSize:18,flexShrink:0}}>{c.emoji}</div><div className="ft-aitem-info"><div className="ft-aitem-title">{c.name}{c.birthday?' 🎂 '+new Date(c.birthday+"T12:00:00").toLocaleDateString(getLocale(),{day:"numeric",month:"short"}):""}</div><div className="ft-aitem-sub">{mediaTypes.map(m=>m.icon+' '+(c.mediaLimits?.[m.id]||0)+"'").join(' · ')} | ⏱️ {c.earnedMinutes} {t('common.minutes_short')} | 💶 {fmtCHF(c.pocket||0)}</div></div><div className="ft-aitem-actions"><button aria-label={t('a11y.edit')} className="ft-ic-btn" onClick={()=>setEditChild({...c})}>✏️</button><button aria-label={t('a11y.delete')} className="ft-ic-btn del" onClick={()=>delChild(c.id)}>🗑️</button></div></div>))}<button className="ft-add-btn" onClick={()=>setEditChild({name:"",color:COLORS[0],emoji:EMOJIS[0],earnedMinutes:0,pocket:0,weeklyPocket:500,birthday:"",mediaLimits:mediaTypes.reduce((a,m)=>({...a,[m.id]:60}),{}),mediaDays:mediaTypes.reduce((a,m)=>({...a,[m.id]:[0,1,2,3,4,5,6]}),{})})}>+ {t('child.add')}</button></div>);
      const AdminTasks=()=>{const[filterCid,setFilterCid]=useState(null);const ft=tasks.filter(t=>!filterCid||t.assignedTo.includes(filterCid));return(<div>{children.length>1&&<div className="ft-gcal-filter"><button className="ft-gcal-fchip" onClick={()=>setFilterCid(null)} style={{borderColor:filterCid===null?'#FFE66D':'rgba(255,255,255,0.12)',background:filterCid===null?'rgba(255,230,109,0.15)':'transparent',color:filterCid===null?'#FFE66D':'rgba(255,255,255,0.6)'}}>{t('common.all')}</button>{children.map(c=><button key={c.id} className="ft-gcal-fchip" onClick={()=>setFilterCid(filterCid===c.id?null:c.id)} style={{borderColor:filterCid===c.id?c.color:'rgba(255,255,255,0.12)',background:filterCid===c.id?'rgba('+h2r(c.color)+',0.15)':'transparent',color:filterCid===c.id?c.color:'rgba(255,255,255,0.6)'}}>{c.emoji} {c.name}</button>)}</div>}{ft.map(tsk=>{const names=children.filter(c=>tsk.assignedTo.includes(c.id)).map(c=>c.name).join(", ")||t('task.no_one');return(<div key={tsk.id} className="ft-aitem"><div style={{fontSize:24,flexShrink:0}}>{tsk.icon}</div><div className="ft-aitem-info"><div className="ft-aitem-title">{tsk.title}</div><div className="ft-aitem-sub">+{tsk.minutesReward}' / -{tsk.minutesPenalty}' · {tsk.dueTime} · {names}</div></div><div className="ft-aitem-actions"><button aria-label={t('a11y.edit')} className="ft-ic-btn" onClick={()=>setEditTask({...tsk})}>✏️</button><button aria-label={t('a11y.delete')} className="ft-ic-btn del" onClick={()=>delTask(tsk.id)}>🗑️</button></div></div>);})}<button className="ft-add-btn" onClick={()=>setEditTask({title:"",icon:"📚",assignedTo:[],minutesReward:10,minutesPenalty:5,dueTime:"18:00",repeatDays:[1,2,3,4,5]})}>{t('task.add_btn')}</button></div>);};

      const AdminFines=()=>(<div>{fines.map(f=>{const names=children.filter(c=>f.assignedTo.includes(c.id)).map(c=>c.name).join(", ")||t('task.no_one');return(<div key={f.id} className="ft-aitem" style={{borderLeft:'3px solid rgba(255,107,107,0.4)'}}><div style={{fontSize:24,flexShrink:0}}>{f.icon}</div><div className="ft-aitem-info"><div className="ft-aitem-title">{f.title}</div><div className="ft-aitem-sub" style={{color:'#FF9A9A'}}>{finePenaltyLabel(f)} · {names}</div></div><div className="ft-aitem-actions"><button aria-label={t('a11y.edit')} className="ft-ic-btn" onClick={()=>setEditFine({...f})}>✏️</button><button aria-label={t('a11y.delete')} className="ft-ic-btn del" onClick={()=>delFine(f.id)}>🗑️</button></div></div>);})}<button className="ft-add-btn" style={{borderColor:'rgba(255,107,107,0.25)',color:'#FF9A9A'}} onClick={()=>setEditFine({title:"",icon:"🤥",assignedTo:[...children.map(c=>c.id)],minutesPenalty:10,moneyPenalty:0})}>{t('fines.add_btn')}</button></div>);

      // ── Termine: Speichern / Löschen ──────────────────────────────────────
      const saveTermin = t => {
        if (t.id) setTermine(p=>p.map(x=>x.id===t.id?t:x));
        else      setTermine(p=>[...p, {...t, id:uid()}]);
        setEditTermin(null);
        showToast(t('common.saved'));
      };
      const delTermin = id => { setTermine(p=>p.filter(t=>t.id!==id)); showToast(t('common.delete'),"inf"); };

      // ── Termine: Admin-Tab ────────────────────────────────────────────────
      // Zeigt alle Termine sortiert nach Datum mit Bearbeiten/Löschen-Buttons.
      // Darunter ein Hinweis wie man Google Kalender einbinden kann.
      const AdminTermine = () => {
        const fmt = d => d ? new Date(d+'T12:00:00').toLocaleDateString(getLocale(),{day:'numeric',month:'short',year:'numeric'}) : '';
        const sorted = [...termine].sort((a,b)=>a.dateFrom.localeCompare(b.dateFrom));
        return (
          <div>
            {sorted.length===0&&<div className="ft-empty"><div style={{fontSize:36,marginBottom:8}}>📅</div>{t('admin.no_termine')}</div>}
            {sorted.map(ev=>{
              const names = ev.assignedTo.length===0
                ? t('admin.all_children')
                : children.filter(c=>ev.assignedTo.includes(c.id)).map(c=>c.name).join(', ');
              const sameDay = ev.dateFrom===ev.dateTo || !ev.dateTo;
              const dateStr = sameDay ? fmt(ev.dateFrom) : `${fmt(ev.dateFrom)} – ${fmt(ev.dateTo)}`;
              const timeStr = ev.allDay ? t('calendar.all_day') : `${ev.timeFrom||'?'}–${ev.timeTo||'?'}`;
              const avatars = ev.assignedTo.length===0
                ? children.map(c=>c.emoji).join('')
                : children.filter(c=>ev.assignedTo.includes(c.id)).map(c=>c.emoji).join('');
              return (
                <div key={ev.id} className="ft-aitem" style={{borderLeft:'3px solid rgba(255,230,109,0.4)'}}>
                  <div style={{fontSize:26,flexShrink:0}}>{ev.emoji||'📅'}</div>
                  <div className="ft-aitem-info">
                    <div className="ft-aitem-title">{ev.title}</div>
                    <div className="ft-aitem-sub" style={{color:'rgba(255,230,109,0.6)'}}>
                      {ev.recurrence?.type==='weekly'
                        ? t('calendar.recurring_every',{days:(ev.recurrence.days||[]).map(d=>DAYS_S[d]).join('/')})
                        : ev.recurrence?.type==='monthly'
                        ? t('calendar.recurring_monthly_on',{day:ev.recurrence.dayOfMonth})
                        : ev.recurrence?.type==='yearly'
                        ? t('calendar.recurring_yearly_on',{date:ev.recurrence.monthDay})
                        : dateStr+' · '+timeStr
                      }{(ev.recurrence?.type&&ev.timeFrom)?' · '+ev.timeFrom+' '+t('common.o_clock'):''}{(ev.recurrence?.endDate)?' · '+t('calendar.bis',{time:ev.recurrence.endDate}):''} · {avatars} {names}
                    </div>
                  </div>
                  <div className="ft-aitem-actions">
                    <button aria-label={t('a11y.edit')} className="ft-ic-btn" onClick={()=>setEditTermin({...ev})}>✏️</button>
                    <button aria-label={t('a11y.delete')} className="ft-ic-btn del" onClick={()=>delTermin(ev.id)}>🗑️</button>
                  </div>
                </div>
              );
            })}

            {/* Automatische Geburtstage */}
            {children.some(c=>c.birthday)&&<div style={{background:'rgba(255,230,109,0.06)',border:'1.5px solid rgba(255,230,109,0.15)',borderRadius:13,padding:'12px 15px',marginBottom:14}}>
              <div style={{fontSize:12,fontWeight:800,color:'#FFE66D',marginBottom:4}}>{t('admin.birthdays_auto')}</div>
              <div style={{fontSize:11,color:'rgba(255,255,255,0.65)',lineHeight:1.6}}>
                {children.filter(c=>c.birthday).map(c=>`${c.emoji} ${c.name}: ${new Date(c.birthday+"T12:00:00").toLocaleDateString(getLocale(),{day:"numeric",month:"long"})}`).join(' · ')}
              </div>
            </div>}

            {/* Neuer Termin */}
            <button className="ft-add-btn" style={{borderColor:'rgba(255,230,109,0.25)',color:'rgba(255,230,109,0.7)',marginBottom:20}}
              onClick={()=>setEditTermin({title:'',emoji:'📅',dateFrom:todayStr(),dateTo:todayStr(),allDay:true,timeFrom:'',timeTo:'',assignedTo:[],recurrence:null})}>
              {t('admin.new_termin')}
            </button>

            {/* Google Kalender Hinweis */}
            <div style={{background:'rgba(78,205,196,0.06)',border:'1.5px solid rgba(78,205,196,0.18)',borderRadius:13,padding:'13px 15px'}}>
              <div style={{fontSize:13,fontWeight:800,color:'#4ECDC4',marginBottom:6}}>{t('admin.google_calendar')}</div>
              <div style={{fontSize:12,color:'rgba(255,255,255,0.65)',lineHeight:1.6,marginBottom:10}}>
                {t('admin.google_calendar_desc')}<strong style={{color:'rgba(255,255,255,0.7)'}}>{t('admin.api_php_section')}</strong>{t('admin.enter_text')}
              </div>
              <button className="ft-add-btn" style={{borderColor:'rgba(78,205,196,0.2)',color:'rgba(78,205,196,0.7)',marginBottom:0}}
                onClick={()=>window.location.href='api.php?action=admin'}>
                {t('admin.open_admin_panel')}
              </button>
            </div>
          </div>
        );
      };

      // ── Medientypen verwalten ────────────────────────────────────────────────
      const [editMediaType,setEditMediaType]=useState(null);

      const saveMediaType=(mt)=>{
        const isNew = mt._isNew === true;
        const saved = {...mt, id:mt.id||uid(), bg:`rgba(${h2r(mt.color)},0.15)`};
        delete saved._isNew;
        if(isNew){
          setMediaTypes(p=>[...p,saved]);
          setChildren(p=>p.map(c=>({...c,
            mediaLimits:{...c.mediaLimits,[saved.id]:60},
            mediaDays:{...c.mediaDays,[saved.id]:[0,1,2,3,4,5,6]},
          })));
        } else {
          setMediaTypes(p=>p.map(x=>x.id===saved.id?saved:x));
        }
        setEditMediaType(null);
        showToast(t('common.saved'));
      };

      const delMediaType=(id)=>{
        if(mediaTypes.length<=1){showToast(t('admin.at_least_one_media'),'err');return;}
        setMediaTypes(p=>p.filter(m=>m.id!==id));
        // Aus allen Kindern entfernen
        setChildren(p=>p.map(c=>{
          const ml={...c.mediaLimits}; delete ml[id];
          const md={...c.mediaDays};   delete md[id];
          return {...c,mediaLimits:ml,mediaDays:md};
        }));
        // Nutzungsdaten löschen
        setMediaU(p=>{const n={...p};Object.keys(n).filter(k=>k.includes(`_${id}_`)).forEach(k=>delete n[k]);return n;});
        showToast(t('common.delete'),'inf');
      };

      const AdminMediaTypes=()=>(<div>
        {mediaTypes.map(m=>(<div key={m.id} className="ft-aitem" style={{borderLeft:'3px solid '+m.color}}>
          <div style={{width:36,height:36,borderRadius:10,background:m.bg,border:'1.5px solid '+m.color+'44',display:'flex',alignItems:'center',justifyContent:'center',fontSize:20,flexShrink:0}}>{m.icon}</div>
          <div className="ft-aitem-info">
            <div className="ft-aitem-title">{m.label}</div>
            <div className="ft-aitem-sub" style={{color:m.color}}>ID: {m.id}</div>
          </div>
          <div className="ft-aitem-actions">
            <button aria-label={t('a11y.edit')} className="ft-ic-btn" onClick={()=>setEditMediaType({...m})}>✏️</button>
            <button aria-label={t('a11y.delete')} className="ft-ic-btn del" onClick={()=>delMediaType(m.id)}>🗑️</button>
          </div>
        </div>))}
        <button className="ft-add-btn" style={{borderColor:'rgba(78,205,196,0.25)',color:'rgba(78,205,196,0.7)'}}
          onClick={()=>setEditMediaType({label:'',icon:'📺',color:MEDIA_COLORS[mediaTypes.length%MEDIA_COLORS.length]})}>
          {t('admin.media_type_add')}
        </button>
      </div>);

      

      // ── Hilfsfunktion: Termine für ein bestimmtes Datum (unterstützt Recurrence) ──
      const termineForDateGlobal = (ds, cid=null) => {
        const evs = termine.filter(t => {
          // Kind-Filter
          if(cid && t.assignedTo.length>0 && !t.assignedTo.includes(cid)) return false;
          // Wiederkehrend
          if(t.recurrence?.type) return matchesRecurrence(t, ds);
          // Einmalig / Zeitraum
          const from=t.dateFrom, to=t.dateTo||t.dateFrom;
          return ds>=from && ds<=to;
        });
        // iCal-Events mergen
        const ic = iCalEvs.filter(e => {
          const from=e.date_start, to=e.date_end||e.date_start;
          return ds>=from && ds<=to;
        }).map(e => ({
          id:'ical_'+e.uid, title:e.title||t('common.event'), emoji:'📅',
          dateFrom:e.date_start, dateTo:e.date_end||e.date_start,
          allDay:!e.time_start, timeFrom:e.time_start||'', timeTo:'',
          assignedTo:[], recurrence:null, _ical:true
        }));
        // Geburtstage der Kinder
        children.filter(c=>c.birthday&&(!cid||cid==='all'||c.id===cid)&&ds.substr(5)===c.birthday.substr(5)).forEach(c=>{
          evs.push({id:'bday_'+c.id,title:c.name+' 🎂',emoji:'🎂',dateFrom:ds,dateTo:ds,allDay:true,timeFrom:'',timeTo:'',assignedTo:[c.id],recurrence:null,_birthday:true});
        });
        return [...evs, ...ic];
      };

      // ── Helper: Farbe für Termin ──
      const evColor = t => {
        if(t._birthday) return '#FF6B6B';
        if(t.assignedTo?.length===1){const ch=getChild(t.assignedTo[0]);return ch?.color||'#FFE66D';}
        if(t.assignedTo?.length>1) return '#A78BFA';
        return '#FFE66D';
      };

      // ── GlobalCalendarScreen ──
      const PinModal=()=>{if(!showPin)return null;return(<div className="ft-overlay"><div className="ft-modal" style={{maxWidth:320,borderRadius:20}}><div className="ft-modal-title" style={{textAlign:"center"}}>{t('admin.pin_title')}</div><div className="ft-pin-disp"><div className="ft-pin-title">{t('admin.pin_enter')}</div><div className="ft-pin-dots">{[0,1,2,3].map(i=><div key={i} className={`ft-pin-dot ${i<pinInput.length?"on":""}`} style={pinErr?{background:"#FF6B6B",animation:"shake 0.4s"}:{}}/>)}</div>{pinErr&&<div className="ft-pin-err">{t('admin.pin_wrong')}</div>}</div><div className="ft-pin-grid">{["1","2","3","4","5","6","7","8","9","⌫","0","✓"].map(d=><button key={d} className="ft-pin-btn" style={d==="⌫"?{background:"rgba(255,107,107,0.12)",color:"#FF8E8E"}:d==="✓"?{background:"rgba(107,203,119,0.12)",color:"#6BCB77"}:{}} onClick={()=>d!=="✓"&&handlePin(d)}>{d}</button>)}</div><button className="ft-btn ft-btn-sec" style={{width:"100%"}} onClick={()=>{setShowPin(false);setPinInput("");setPinErr(false);}}>{t('common.cancel')}</button></div></div>);};

      // ── Universelle Bottom-Nav ────────────────────────────────────────────
      // ── Hilfsfunktion: Prüft ob ein Datum zu einem Recurrence-Pattern passt ──
      const matchesRecurrence = (t, ds) => {
        if(!t.recurrence?.type) return false;
        if(ds < t.dateFrom) return false;
        if(t.recurrence.endDate && ds > t.recurrence.endDate) return false;
        const d = new Date(ds+'T00:00:00');
        if(t.recurrence.type==='weekly') return (t.recurrence.days||[]).includes(d.getDay());
        if(t.recurrence.type==='monthly') return d.getDate()===t.recurrence.dayOfMonth;
        if(t.recurrence.type==='yearly'){const md=t.recurrence.monthDay;return md===String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0");}
        return false;
      };

      const GlobalCalendarScreen = () => {
        const today = new Date();
        const todayDs = todayStr();
        const fmt2 = n => String(n).padStart(2,'0');

        // Von view abhängige Datumsberechnung
        const viewLabel = (() => {
          if(gcalView==='day'){
            return new Date(gcalDay+'T00:00:00').toLocaleDateString(getLocale(),{weekday:'long',day:'numeric',month:'long',year:'numeric'});
          }
          if(gcalView==='week'){
            const wd = getWeekDates(gcalOff);
            const from = wd[0], to = wd[6];
            const opt = {day:'numeric',month:'short'};
            const f = from.toLocaleDateString(getLocale(),opt);
            const sameMonth = from.getMonth()===to.getMonth() && from.getFullYear()===to.getFullYear();
            if(sameMonth) return f+' – '+to.getDate()+'. '+to.toLocaleDateString(getLocale(),{month:'short',year:'numeric'});
            const sameYear = from.getFullYear()===to.getFullYear();
            const t = to.toLocaleDateString(getLocale(), sameYear ? {day:'numeric',month:'short'} : {day:'numeric',month:'short',year:'numeric'});
            return f+' – '+t;
          }
          const vd = new Date(today.getFullYear(), today.getMonth()+gcalOff, 1);
          return vd.toLocaleDateString(getLocale(),{month:'long',year:'numeric'});
        })();

        const navBack = () => {
          setGcalOff(o=>o-1);
          if(gcalView==='day'){const d=new Date();d.setDate(d.getDate()+gcalOff-1);setGcalDay(d.getFullYear()+'-'+fmt2(d.getMonth()+1)+'-'+fmt2(d.getDate()));}
        };
        const navFwd = () => {
          setGcalOff(o=>o+1);
          if(gcalView==='day'){const d=new Date();d.setDate(d.getDate()+gcalOff+1);setGcalDay(d.getFullYear()+'-'+fmt2(d.getMonth()+1)+'-'+fmt2(d.getDate()));}
        };
        const navToday = () => { setGcalOff(0); setGcalDay(todayDs); };

        const selEvs = termineForDateGlobal(gcalDay, gcalFilter);

        const evWho = ev => {
          if(!ev.assignedTo||ev.assignedTo.length===0) return t('gcal.everyone');
          return children.filter(c=>ev.assignedTo.includes(c.id)).map(c=>c.emoji+' '+c.name).join(', ');
        };

        const openNewTermin = (ds) => setEditTermin({
          title:'',emoji:'📅',dateFrom:ds,dateTo:ds,
          allDay:true,timeFrom:'',timeTo:'',assignedTo:[],recurrence:null
        });

        // Event-Detail-Element (wiederverwendet)
        const eventDetail = (ev) => {
          const col=evColor(ev);
          const recLabel = ev._ical?t('gcal.ical')
            : ev.recurrence?.type==='weekly'?t('gcal.recurring_weekly',{days:(ev.recurrence.days||[]).map(d=>DAYS_S[d]).join('/')})
            : ev.recurrence?.type==='monthly'?t('gcal.recurring_monthly',{day:ev.recurrence.dayOfMonth})
            : ev.recurrence?.type==='yearly'?t('gcal.recurring_yearly',{date:ev.recurrence.monthDay})
            : null;
          const timeLabel = ev._birthday?'🎂'
            : ev.recurrence?.type&&ev.timeFrom?t('gcal.at_time',{time:ev.timeFrom})
            : !ev.allDay&&ev.timeFrom?t('gcal.time_range',{from:ev.timeFrom,to:ev.timeTo||''}):t('gcal.all_day');
          return (
            <div key={ev.id} className="ft-gcal-ev" style={{'--ec':col}}>
              <span className="ft-gcal-ev-icon">{ev.emoji||'📅'}</span>
              <div className="ft-gcal-ev-info">
                <div className="ft-gcal-ev-title">{ev.title}</div>
                <div className="ft-gcal-ev-sub">{timeLabel}</div>
                {recLabel&&<div className="ft-gcal-ev-sub" style={{color:'rgba(78,205,196,0.8)',marginTop:2}}>{recLabel}</div>}
                <div className="ft-gcal-ev-who" style={{color:col}}>{evWho(ev)}</div>
              </div>
              {adminOpen&&!ev._ical&&!ev._birthday&&<button aria-label={t('a11y.edit')} className="ft-ic-btn" onClick={()=>setEditTermin({...ev})}>✏️</button>}
            </div>
          );
        };

        return(
          <div className="ft-gcal">
            {/* Header */}
            <div className="ft-gcal-hdr">
              <button className="ft-back" onClick={()=>setScreen('home')}>←</button>
              <div className="ft-gcal-nav">
                <button className="ft-week-btn" onClick={navBack}>‹</button>
                <div className="ft-gcal-month" onClick={navToday} style={{cursor:'pointer'}}>{viewLabel}</div>
                <button className="ft-week-btn" onClick={navFwd}>›</button>
              </div>
              {adminOpen&&<button className="ft-gcal-add-btn" onClick={()=>openNewTermin(gcalDay)}>+</button>}
            </div>

            {/* Kind-Filter + View-Switcher */}
            <div className="ft-gcal-filter">
              <button className="ft-gcal-fchip" onClick={()=>setGcalFilter(null)}
                style={{borderColor:gcalFilter===null?'#FFE66D':'rgba(255,255,255,0.12)',
                        background:gcalFilter===null?'rgba(255,230,109,0.15)':'transparent',
                        color:gcalFilter===null?'#FFE66D':'rgba(255,255,255,0.62)'}}>
                {t('gcal.everyone')}
              </button>
              {children.map(c=>(
                <button key={c.id} className="ft-gcal-fchip"
                  onClick={()=>setGcalFilter(gcalFilter===c.id?null:c.id)}
                  style={{borderColor:gcalFilter===c.id?c.color:'rgba(255,255,255,0.12)',
                          background:gcalFilter===c.id?'rgba('+h2r(c.color)+',0.15)':'transparent',
                          color:gcalFilter===c.id?c.color:'rgba(255,255,255,0.62)'}}>
                  {c.emoji} {c.name}
                </button>
              ))}
              <div className="ft-gcal-view-group">
                {[
                  {id:'day',lbl:t('gcal.day')},
                  {id:'week',lbl:t('gcal.week')},
                  {id:'month',lbl:t('gcal.month')}
                ].map(v=>{
                  const sel=gcalView===v.id;
                  return(
                    <button key={v.id} className="ft-gcal-fchip"
                      style={{borderColor:sel?'#FFE66D':'rgba(255,255,255,0.12)',
                              background:sel?'rgba(255,230,109,0.15)':'transparent',
                              color:sel?'#FFE66D':'rgba(255,255,255,0.62)'}}
                      onClick={()=>{setGcalView(v.id);setGcalOff(0);}}>
                      {v.lbl}
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Ansichtswechsel - Tag */}
            {gcalView==='day'&&(
              <div className="ft-gcal-body" style={{flex:1}}>
                {selEvs.length===0 && <div className="ft-empty" style={{marginTop:20}}><div style={{fontSize:32,marginBottom:8}}>📭</div>{t('gcal.no_events')}</div>}
                {selEvs.map(ev => eventDetail(ev))}
              </div>
            )}

            {/* Ansichtswechsel - Woche */}
            {gcalView==='week'&&(
              <>
                <div className="ft-gcal-dow-row">
                  {[t('days.mon'),t('days.tue'),t('days.wed'),t('days.thu'),t('days.fri'),t('days.sat'),t('days.sun')].map(d=><div key={d} className="ft-gcal-dow">{d}</div>)}
                </div>
                <div className="ft-gcal-grid">
                  {getWeekDates(gcalOff).map((date,i)=>{
                    const ds=date.getFullYear()+'-'+fmt2(date.getMonth()+1)+'-'+fmt2(date.getDate());
                    const evs2=termineForDateGlobal(ds,gcalFilter);
                    const dotColors=[...new Set(evs2.map(x=>evColor(x)))].slice(0,4);
                    const isToday=ds===todayDs,isSel=ds===gcalDay;
                    return(
                      <div key={i} className={'ft-gcal-day'+(isToday?' today':'')+(isSel?' sel':'')} onClick={()=>setGcalDay(ds)}>
                        <div className="ft-gcal-day-num">{date.getDate()}</div>
                        <div className="ft-gcal-dots">{dotColors.map((c,j)=><div key={j} className="ft-gcal-dot" style={{background:c}}/>)}</div>
                      </div>
                    );
                  })}
                </div>
                <div className="ft-gcal-body">
                  <div className="ft-gcal-sel-date">
                    <span>{new Date(gcalDay+'T00:00:00').toLocaleDateString(getLocale(),{weekday:'long',day:'numeric',month:'long'})}</span>
                    {adminOpen&&<button className="ft-gcal-add-btn" style={{width:28,height:28,fontSize:16}} onClick={()=>openNewTermin(gcalDay)}>+</button>}
                  </div>
                  {selEvs.length===0&&<div className="ft-empty"><div style={{fontSize:32,marginBottom:8}}>📭</div>{t('gcal.no_events')}</div>}
                  {selEvs.map(ev => eventDetail(ev))}
                </div>
              </>
            )}

            {/* Ansichtswechsel - Monat */}
            {gcalView==='month'&&(()=>{
              const vd=new Date(today.getFullYear(),today.getMonth()+gcalOff,1);
              const vy=vd.getFullYear(),vm=vd.getMonth();
              const mkDs=d=>vy+'-'+fmt2(vm+1)+'-'+fmt2(d);
              const fd=vd.getDay(),fm=fd===0?6:fd-1;
              const dim=new Date(vy,vm+1,0).getDate();
              const cells=[];
              for(let i=0;i<fm;i++) cells.push(null);
              for(let d=1;d<=dim;d++) cells.push(d);
              return(<>
                <div className="ft-gcal-dow-row">
                  {[t('days.mon'),t('days.tue'),t('days.wed'),t('days.thu'),t('days.fri'),t('days.sat'),t('days.sun')].map(d=><div key={d} className="ft-gcal-dow">{d}</div>)}
                </div>
                <div className="ft-gcal-grid">
                  {cells.map((d,i)=>{
                    if(!d) return <div key={'e'+i} className="ft-gcal-day empty"/>;
                    const ds=mkDs(d);
                    const evs2=termineForDateGlobal(ds,gcalFilter);
                    const dotColors=[...new Set(evs2.map(x=>evColor(x)))].slice(0,4);
                    const isToday=ds===todayDs,isSel=ds===gcalDay;
                    return(
                      <div key={d} className={'ft-gcal-day'+(isToday?' today':'')+(isSel?' sel':'')} onClick={()=>setGcalDay(ds)}>
                        <div className="ft-gcal-day-num">{d}</div>
                        <div className="ft-gcal-dots">{dotColors.map((c,j)=><div key={j} className="ft-gcal-dot" style={{background:c}}/>)}</div>
                      </div>
                    );
                  })}
                </div>
                <div className="ft-gcal-body">
                  <div className="ft-gcal-sel-date">
                    <span>{new Date(gcalDay+'T00:00:00').toLocaleDateString(getLocale(),{weekday:'long',day:'numeric',month:'long'})}</span>
                    {adminOpen&&<button className="ft-gcal-add-btn" style={{width:28,height:28,fontSize:16}} onClick={()=>openNewTermin(gcalDay)}>+</button>}
                  </div>
                  {selEvs.length===0&&<div className="ft-empty"><div style={{fontSize:32,marginBottom:8}}>📭</div>{t('gcal.no_events')}</div>}
                  {selEvs.map(ev => eventDetail(ev))}
                </div>
              </>);
            })()}
          </div>
        );
      };

      const BottomNav = useMemo(() => () => {
        const [syncCls,  setSyncCls]  = useState('');
        const [toast,    setToast]    = useState(null);
        const [pwa,      setPwa]      = useState({canShow:false, type:null});
        const [iosSheet, setIosSheet] = useState(false);
        const hideRef = useRef(null);

        useEffect(() => {
          window._subscribeSyncState((s, m) => {
            if (s === 'syncing') { setSyncCls('ft-bnav-sync-ing'); }
            else if (s === 'ok')    { setSyncCls('ft-bnav-sync-ok');  setToast(m); clearTimeout(hideRef.current); hideRef.current=setTimeout(()=>{setToast(null);setSyncCls('');},4000); }
            else if (s === 'error') { setSyncCls('ft-bnav-sync-err'); setToast(m); clearTimeout(hideRef.current); hideRef.current=setTimeout(()=>{setToast(null);setSyncCls('');},4000); }
          });
          if (window._pwa) window._pwa.subscribe(p => setPwa({canShow:p.canShow, type:p.type}));
        }, []);

        const handleInstall = () => {
          if (pwa.type === 'ios') { setMenuOpen(false); setIosSheet(true); }
          else if (window._pwa?.prompt) { setMenuOpen(false); window._pwa.install(); }
          else { setMenuOpen(false); setIosSheet(true); } // Prompt noch nicht bereit → Anleitung zeigen
        };

        const cc = selChild?.color || '#FF6B6B';

        // iOS-Anleitung Sheet (auch für Android wenn Prompt noch nicht bereit)
        const IosSheet = () => !iosSheet ? null : (
          <div style={{position:'fixed',inset:0,background:'rgba(0,0,0,0.75)',zIndex:300,display:'flex',alignItems:'flex-end'}}
               onClick={e=>e.target===e.currentTarget&&setIosSheet(false)}>
            <div style={{background:'#1C1D30',borderRadius:'20px 20px 0 0',padding:'24px 20px calc(32px + env(safe-area-inset-bottom))',width:'100%',maxWidth:480,margin:'0 auto'}}>
              <div style={{fontSize:22,fontWeight:900,color:'#fff',marginBottom:6}}>{t('pwa.install_app')}</div>
              {pwa.type==='ios' ? (<>
                <div style={{fontSize:13,color:'rgba(255,255,255,0.5)',marginBottom:20}}>{t('pwa.ios_steps')}</div>
                {[
                  [t('pwa.step_share')[0], t('pwa.step_share')[1], t('pwa.step_share')[2]],
                  [t('pwa.step_homescreen')[0], t('pwa.step_homescreen')[1], t('pwa.step_homescreen')[2]],
                  [t('pwa.step_add')[0], t('pwa.step_add')[1], t('pwa.step_add')[2]],
                ].map(([icon, title, sub], i) => (
                  <div key={i} style={{display:'flex',gap:14,alignItems:'center',marginBottom:14}}>
                    <div style={{width:44,height:44,borderRadius:12,background:'rgba(78,205,196,0.1)',border:'1.5px solid rgba(78,205,196,0.25)',display:'flex',alignItems:'center',justifyContent:'center',fontSize:20,flexShrink:0}}>{icon}</div>
                    <div><div style={{fontSize:14,fontWeight:800,color:'#fff'}}>{title}</div><div style={{fontSize:12,color:'rgba(255,255,255,0.65)'}}>{sub}</div></div>
                  </div>
                ))}
              </>) : (<>
                <div style={{fontSize:13,color:'rgba(255,255,255,0.5)',marginBottom:20}}>{t('pwa.android_steps')}</div>
                {window._pwa?.prompt ? (
                  <div style={{marginBottom:16}}>
                    <button onClick={()=>{window._pwa.install();setIosSheet(false);}}
                      style={{width:'100%',padding:'14px',background:'linear-gradient(135deg,#FFE66D,#FFB347)',border:'none',borderRadius:12,color:'#1a1200',fontSize:15,fontWeight:900,cursor:'pointer'}}>
                      {t('pwa.install_now')}
                    </button>
                  </div>
                ) : (
                  [
                    [t('pwa.step_menu')[0], t('pwa.step_menu')[1], t('pwa.step_menu')[2]],
                    [t('pwa.step_app_install')[0], t('pwa.step_app_install')[1], t('pwa.step_app_install')[2]],
                    [t('pwa.step_confirm')[0], t('pwa.step_confirm')[1], t('pwa.step_confirm')[2]],
                  ].map(([icon, title, sub], i) => (
                    <div key={i} style={{display:'flex',gap:14,alignItems:'center',marginBottom:14}}>
                      <div style={{width:44,height:44,borderRadius:12,background:'rgba(255,230,109,0.1)',border:'1.5px solid rgba(255,230,109,0.25)',display:'flex',alignItems:'center',justifyContent:'center',fontSize:20,flexShrink:0}}>{icon}</div>
                      <div><div style={{fontSize:14,fontWeight:800,color:'#fff'}}>{title}</div><div style={{fontSize:12,color:'rgba(255,255,255,0.65)'}}>{sub}</div></div>
                    </div>
                  ))
                )}
              </>)}
              <button onClick={()=>setIosSheet(false)}
                style={{width:'100%',padding:'13px',background:'rgba(255,255,255,0.07)',border:'1.5px solid rgba(255,255,255,0.12)',borderRadius:12,color:'rgba(255,255,255,0.6)',fontSize:14,fontWeight:800,cursor:'pointer',marginTop:8}}>
                {t('pwa.close')}
              </button>
            </div>
          </div>
        );

        const InstallBtn = ({compact}) => !pwa.canShow ? null : (
          <button className="ft-bnav-it" style={{'--nav-color':'#FFE66D'}} onClick={handleInstall} title={t('pwa.install_btn')}>
            <span className="ft-bnav-icon">📲</span>
            {!compact && <span className="ft-bnav-lbl" style={{color:'#FFE66D'}}>{t('pwa.install_btn')}</span>}
          </button>
        );

        // Kind-Ansicht
        if (screen === 'child') return (
          <>
            {toast && <div className="ft-fab-toast" onClick={()=>setToast(null)}><div className="ft-fab-toast-title">{toast.msg}</div>{toast.detail&&<div className="ft-fab-toast-detail">{toast.detail}</div>}</div>}
            <IosSheet/>
              {menuOpen && (
              <div style={{position:'fixed',bottom:68,left:0,right:0,background:'#141526',borderTop:'1.5px solid rgba(255,255,255,0.08)',padding:'10px 16px',display:'flex',gap:8,zIndex:1001,flexWrap:'wrap'}}>
                <button className={`ft-bnav-it ${syncCls}`} style={{flex:1,minWidth:60,background:'rgba(78,205,196,0.08)',border:'1.5px solid rgba(78,205,196,0.2)',borderRadius:12}} onClick={()=>{setMenuOpen(false);if(syncCls!=='ft-bnav-sync-ing')window._famtaskManualSync();}}>
                  <span className="ft-bnav-icon">🔄</span><span className="ft-bnav-lbl" style={{color:'#4ECDC4'}}>{t('nav.sync')}</span>
                </button>
                <button className="ft-bnav-it" style={{flex:1,minWidth:60,background:'rgba(255,107,107,0.08)',border:'1.5px solid rgba(255,107,107,0.2)',borderRadius:12}} onClick={()=>{setMenuOpen(false);setScreen('home');}}>
                  <span className="ft-bnav-icon">🏠</span><span className="ft-bnav-lbl" style={{color:'#FF6B6B'}}>{t('nav.home')}</span>
                </button>
                <button className="ft-bnav-it" style={{flex:1,minWidth:60,background:'rgba(255,230,109,0.08)',border:'1.5px solid rgba(255,230,109,0.2)',borderRadius:12}} onClick={()=>{setMenuOpen(false);setGcalDay(todayStr());setGcalOff(0);setScreen('calendar');}}>
                  <span className="ft-bnav-icon">📅</span><span className="ft-bnav-lbl" style={{color:'#FFE66D'}}>{t('nav.calendar')}</span>
                </button>
                <button className="ft-bnav-it" style={{flex:1,minWidth:60,background:'rgba(167,139,250,0.08)',border:'1.5px solid rgba(167,139,250,0.2)',borderRadius:12}} onClick={()=>{setMenuOpen(false);adminOpen?setScreen('admin'):setShowPin(true);}}>
                  <span className="ft-bnav-icon">⚙️</span><span className="ft-bnav-lbl" style={{color:'#A78BFA'}}>{t('nav.admin')}</span>
                </button>
                {pwa.canShow && <button className="ft-bnav-it" style={{flex:1,minWidth:60,background:'rgba(255,230,109,0.08)',border:'1.5px solid rgba(255,230,109,0.2)',borderRadius:12}} onClick={handleInstall}>
                  <span className="ft-bnav-icon">📲</span><span className="ft-bnav-lbl" style={{color:'#FFE66D'}}>{t('nav.install')}</span>
                </button>}
              </div>
            )}
            <div className="ft-bnav" style={{'--nav-color': cc}}>
              {[{id:'tasks',icon:'✅',lbl:t('nav.tasks')},{id:'media',icon:'🎮',lbl:t('nav.media')},{id:'calendar',icon:'📅',lbl:t('nav.calendar')},{id:'fines',icon:'⚠️',lbl:t('nav.fines')},{id:'money',icon:'💶',lbl:t('nav.money')}].map(n=>(
                <button key={n.id} className={`ft-bnav-it ${childTab===n.id?'active':''}`} onClick={()=>{setChildTab(n.id);setMenuOpen(false);}}>
                  <span className="ft-bnav-icon">{n.icon}</span>
                  <span className="ft-bnav-lbl">{n.lbl}</span>
                </button>
              ))}
              <div className="ft-bnav-sep"/>
              <button className={`ft-bnav-it ${menuOpen?'active':''}`} style={{'--nav-color':'rgba(255,255,255,0.6)'}} onClick={()=>setMenuOpen(o=>!o)}>
                <span className="ft-bnav-icon">{menuOpen?'✕':'☰'}</span>
                <span className="ft-bnav-lbl">{t('nav.more')}</span>
              </button>
            </div>
          </>
        );

        // Home / Admin
        return (
          <>
            {toast && <div className="ft-fab-toast" onClick={()=>setToast(null)}><div className="ft-fab-toast-title">{toast.msg}</div>{toast.detail&&<div className="ft-fab-toast-detail">{toast.detail}</div>}</div>}
            <IosSheet/>
            <div className="ft-bnav">
              <button className="ft-bnav-it" style={{'--nav-color':'#FF6B6B'}} onClick={()=>setScreen('home')}>
                <span className="ft-bnav-icon">🏠</span><span className="ft-bnav-lbl">{t('nav.home')}</span>
              </button>
              <button className={`ft-bnav-it ${screen==='calendar'?'active':''}`} style={{'--nav-color':'#FFE66D'}} onClick={()=>{setGcalDay(todayStr());setGcalOff(0);setScreen('calendar');}}>
                <span className="ft-bnav-icon">📅</span><span className="ft-bnav-lbl">{t('nav.calendar')}</span>
              </button>
              <button className="ft-bnav-it" style={{'--nav-color':'#A78BFA'}} onClick={()=>adminOpen?setScreen('admin'):setShowPin(true)}>
                <span className="ft-bnav-icon">⚙️</span><span className="ft-bnav-lbl">{t('nav.admin')}</span>
              </button>
              <div className="ft-bnav-sep"/>
              <button className={`ft-bnav-it ${screen==='game'?'active':''}`} style={{'--nav-color':'#6BCB77'}} onClick={()=>setScreen('game')}>
                <span className="ft-bnav-icon">🎮</span><span className="ft-bnav-lbl">{t('nav.games')}</span>
              </button>
              <button className="ft-bnav-it" style={{'--nav-color':'#FF6B6B'}} onClick={()=>setScreen('audio')}>
                <span className="ft-bnav-icon">🎵</span><span className="ft-bnav-lbl">{t('nav.jukebox')}</span>
              </button>
              <button className={`ft-bnav-it ${syncCls}`} style={{'--nav-color':'#4ECDC4'}} onClick={()=>{if(syncCls!=='ft-bnav-sync-ing')window._famtaskManualSync();}}>
                <span className="ft-bnav-icon">🔄</span><span className="ft-bnav-lbl">{t('nav.sync')}</span>
              </button>
              {pwa.canShow && <>
                <div className="ft-bnav-sep"/>
                <button className="ft-bnav-it" style={{'--nav-color':'#FFE66D'}} onClick={handleInstall}>
                  <span className="ft-bnav-icon">📲</span><span className="ft-bnav-lbl">{t('nav.install')}</span>
                </button>
              </>}
            </div>
          </>
        );
      }, [screen, menuOpen, childTab, selChild, adminOpen, t]);

      const LangSwitcher=()=>{

        const [open,setOpen]=React.useState(false);
        const cur=localStorage.getItem('ft_lang')||'de';
        const langs=['de','en','es','fr','it','pt','zh','ar','hi','ru','ja','ko','tr','pl','nl','id','vi','bn','th'];
        const F={'de':<svg viewBox="0 0 30 20"><rect width="30" height="6.67" fill="#000"/><rect y="6.67" width="30" height="6.67" fill="#DD0000"/><rect y="13.33" width="30" height="6.67" fill="#FFCC00"/></svg>,'en':<svg viewBox="0 0 30 20"><rect width="30" height="20" fill="#012169"/><rect y="8" width="30" height="4" fill="#FFF"/><rect x="13" width="4" height="20" fill="#FFF"/><rect y="9.25" width="30" height="1.5" fill="#477050"/><rect x="14.25" width="1.5" height="20" fill="#477050"/><polygon points="0,0 8,0 30,14 30,20 22,20 0,6" fill="#FFF"/><polygon points="30,0 22,0 0,14 0,20 8,20 30,6" fill="#FFF"/><polygon points="0,0 4,0 30,17 30,20 26,20 0,3" fill="#C8102E"/><polygon points="30,0 26,0 0,17 0,20 4,20 30,3" fill="#C8102E"/></svg>,'es':<svg viewBox="0 0 30 20"><rect width="30" height="5" fill="#C60B1E"/><rect y="5" width="30" height="10" fill="#FFC400"/><rect y="15" width="30" height="5" fill="#C60B1E"/></svg>,'fr':<svg viewBox="0 0 30 20"><rect width="10" height="20" fill="#0055A4"/><rect x="10" width="10" height="20" fill="#FFF"/><rect x="20" width="10" height="20" fill="#EF4135"/></svg>,'it':<svg viewBox="0 0 30 20"><rect width="10" height="20" fill="#009246"/><rect x="10" width="10" height="20" fill="#FFF"/><rect x="20" width="10" height="20" fill="#CE2B37"/></svg>,'pt':<svg viewBox="0 0 30 20"><rect width="11" height="20" fill="#006600"/><rect x="11" width="19" height="20" fill="#FF0000"/><circle cx="11" cy="10" r="6" fill="#FFCC00"/></svg>,'zh':<svg viewBox="0 0 30 20"><rect width="30" height="20" fill="#DE2910"/><polygon points="5,3 6.5,7.5 11,7.5 7.5,10.5 9,15 5,12 1,15 2.5,10.5 -1,7.5 3.5,7.5" fill="#FFDE00"/><polygon points="13,4 13.8,6.2 16,6.2 14.2,7.8 15,10 13,8.5 11,10 11.8,7.8 10,6.2 12.2,6.2" fill="#FFDE00" transform="scale(0.6) translate(12,2)"/><polygon points="13,4 13.8,6.2 16,6.2 14.2,7.8 15,10 13,8.5 11,10 11.8,7.8 10,6.2 12.2,6.2" fill="#FFDE00" transform="scale(0.5) translate(24,1)"/><polygon points="13,4 13.8,6.2 16,6.2 14.2,7.8 15,10 13,8.5 11,10 11.8,7.8 10,6.2 12.2,6.2" fill="#FFDE00" transform="scale(0.5) translate(28,11)"/><polygon points="13,4 13.8,6.2 16,6.2 14.2,7.8 15,10 13,8.5 11,10 11.8,7.8 10,6.2 12.2,6.2" fill="#FFDE00" transform="scale(0.45) translate(36,8)"/></svg>,'ar':<svg viewBox="0 0 30 20"><rect width="30" height="20" fill="#006C35"/><rect y="10" width="30" height="10" fill="#FFF"/></svg>,'hi':<svg viewBox="0 0 30 20"><rect width="30" height="6.67" fill="#FF9933"/><rect y="6.67" width="30" height="6.67" fill="#FFF"/><rect y="13.33" width="30" height="6.67" fill="#138808"/><circle cx="15" cy="10" r="2.5" fill="#000080"/></svg>,'ru':<svg viewBox="0 0 30 20"><rect width="30" height="6.67" fill="#FFF"/><rect y="6.67" width="30" height="6.67" fill="#0039A6"/><rect y="13.33" width="30" height="6.67" fill="#D52B1E"/></svg>,'ja':<svg viewBox="0 0 30 20"><rect width="30" height="20" fill="#FFF"/><circle cx="15" cy="10" r="6" fill="#BC002D"/></svg>,'ko':<svg viewBox="0 0 30 20"><rect width="30" height="20" fill="#FFF"/><ellipse cx="10" cy="10" rx="3.5" ry="3.5" fill="#C60C30"/><ellipse cx="13" cy="10" rx="3.5" ry="3.5" fill="#003478"/></svg>,'tr':<svg viewBox="0 0 30 20"><rect width="30" height="20" fill="#E30A17"/><ellipse cx="15" cy="10" rx="4" ry="3.5" fill="#FFF"/><ellipse cx="16" cy="10" rx="4" ry="3.5" fill="#E30A17"/></svg>,'pl':<svg viewBox="0 0 30 20"><rect width="30" height="10" fill="#FFF"/><rect y="10" width="30" height="10" fill="#DC143C"/></svg>,'nl':<svg viewBox="0 0 30 20"><rect width="30" height="6.67" fill="#AE1C28"/><rect y="6.67" width="30" height="6.67" fill="#FFF"/><rect y="13.33" width="30" height="6.67" fill="#21468B"/></svg>,'id':<svg viewBox="0 0 30 20"><rect width="30" height="10" fill="#FF0000"/><rect y="10" width="30" height="10" fill="#FFF"/></svg>,'vi':<svg viewBox="0 0 30 20"><rect width="30" height="20" fill="#DA251D"/><polygon points="15,3 18,12 9,7 21,7 12,12" fill="#FFCC00"/></svg>,'bn':<svg viewBox="0 0 30 20"><rect width="30" height="20" fill="#006A4E"/><circle cx="15" cy="10" r="5" fill="#F42A41"/></svg>,'th':<svg viewBox="0 0 30 20"><rect width="30" height="20" fill="#241D4F"/><rect y="3" width="30" height="5.5" fill="#FFF"/><rect y="11.5" width="30" height="5.5" fill="#FFF"/><rect y="5" width="30" height="2.5" fill="#ED1C24"/><rect y="12.5" width="30" height="2.5" fill="#ED1C24"/></svg>};
        const LN={'de':'Deutsch','en':'English','es':'Español','fr':'Français','it':'Italiano','pt':'Português','zh':'中文','ar':'العربية','hi':'हिन्दी','ru':'Русский','ja':'日本語','ko':'한국어','tr':'Türkçe','pl':'Polski','nl':'Nederlands','id':'Bahasa Indonesia','vi':'Tiếng Việt','bn':'বাংলা','th':'ไทย'};
        const pick=l=>{localStorage.setItem('ft_lang',l);location.reload();};
        React.useEffect(()=>{const c=e=>{if(!e.target.closest('.ft-hdr-lang'))setOpen(false)};document.addEventListener('click',c);return ()=>document.removeEventListener('click',c);},[]);
        return <div style={{position:'relative'}}>
        <div className="ft-lang-trigger" onClick={()=>setOpen(!open)}><span className="ft-lang-flag">{F[cur]}</span><span className="ft-lang-code">{cur.toUpperCase()}</span><span className="ft-lang-arrow">{open?'▲':'▼'}</span></div>
        {open&&<div className="ft-lang-menu">{langs.map(l=><div key={l} className={'ft-lang-item'+(l===cur?' active':'')} onClick={()=>pick(l)}><span className="ft-lang-flag">{F[l]}</span><span className="ft-lang-label">{LN[l]}</span></div>)}</div>}
        </div>;

      };

      // Kontext für die stabilen Top-Level-Komponenten. Wird bei jedem Render neu
      // gebaut – das ist harmlos: React aktualisiert damit nur den Inhalt, der
      // Komponenten-Typ (und damit Fokus/State) bleibt stabil.
      const ui={
        // Geld
        moneyModal, setMoneyModal, getChild, moneyPinDigit, confirmMoneyAction,
        // Bonus-Zeit
        bonusModal, setBonusModal, bonusPinDigit, confirmBonus, adminPin,
        // Timer
        timerModal, setTimerModal, timers, custMin, setCustMin, custSec, setCustSec,
        startTimer, stopTimer, showToast,
        // Admin-Bereich
        adminTab, setAdminTab, setScreen,
        AdminChildren, AdminFines, AdminTermine, AdminMediaTypes,
        familyName, familyCode, onRename, onLogout,
        newPin1, setNewPin1, newPin2, setNewPin2, pinMsg, setPinMsg, setAdminPin,
        children, setChildren, mediaTypes, setMediaU, getMediaUsed,
        tasks, fines, setEditTask, delTask, setEditChild, delChild, setEditFine,
        // Kind-Screen (MediaTab-Eingabefelder)
        selChild, getWeekStart, getMediaUsed, getMediaUsedWeek, getWeeklyLimit,
        getMediaAllowedDays, getMediaTimer, getMediaTimerSecs, getMediaTimerPct,
        useMedia, startMediaTimer, stopMediaTimer,
        childTab, setChildTab, openBonus, getTodayTasks, isDone,
        TasksTab, MediaTab, CalendarTab, FinesTab, MoneyTab,
      };

      return(
        <div className="ft" style={{paddingBottom:130}}>
          <a className="ft-skip" href="#main-content">{t('a11y.skip')}</a>
          <audio ref={audioRef} preload="metadata" onError={(e) => console.error('Audio error:', e)} />
          {(screen==="home"||screen==="admin"||screen==="calendar")&&(
            <div className="ft-hdr">
              <div className="ft-hdr-top">
                <div className="ft-hdr-left">
                  <div className="ft-logo"><img className="ft-logo-mark" src="icons/favicon.svg" alt="" width="26" height="26"/><span className="ft-logo-text">FamTask</span></div>
                  <div id="sync-status" style={{display:'flex',alignItems:'center',gap:5}}>
                    <div id="sync-light" style={{width:10,height:10,borderRadius:'50%',background:'#FFB347',boxShadow:'0 0 6px #FFB347',flexShrink:0,transition:'background 0.4s,box-shadow 0.4s'}}/>
                    <span id="sync-label" style={{fontSize:9,fontWeight:700,color:'rgba(255,255,255,0.58)',whiteSpace:'nowrap'}}>{t('sync.connecting')}</span>
                  </div>
                </div>
                <div className="ft-hdr-right">
                  <div className="ft-hdr-lang">
                    <LangSwitcher/>
                  </div>
                  <div className="ft-hdr-clock">
                    <div id="dom-hdr-clock-t" className="ft-clock-time">{new Date().toLocaleTimeString(getLocale(),{hour:"2-digit",minute:"2-digit",second:"2-digit"})}</div>
                    <div id="dom-hdr-clock-d" className="ft-clock-date">{new Date().toLocaleDateString(getLocale(),{day:"numeric",month:"short"})}</div>
                  </div>
                </div>
              </div>
              <TimerChips/>
            </div>
          )}
          <main id="main-content">
          {screen==="home"&&<Home/>}
          {screen==="child"&&<ChildScreen ui={ui}/>}
          {screen==="admin"&&<AdminScreen ui={ui}/>}
          {screen==="calendar"&&<GlobalCalendarScreen/>}
          {screen==="audio"&&<AudioPlayerScreen/>}
          {screen==="game"&&<GameScreen onBack={()=>setScreen('home')}/>}
          </main>
          {editChild&&<ChildModal key={editChild.id||'new'} editChild={editChild} setEditChild={setEditChild} onSave={saveChild} mediaTypes={mediaTypes}/>}
          {editTask&&<TaskModal key={editTask.id||'new'} editTask={editTask} setEditTask={setEditTask} onSave={saveTask} childrenList={children}/>}
          {editFine&&<FineModal key={editFine.id||'new'} editFine={editFine} setEditFine={setEditFine} onSave={saveFine} childrenList={children}/>}
          {editTermin&&<TerminModal key={editTermin.id||'new'} editTermin={editTermin} setEditTermin={setEditTermin} onSave={saveTermin} childrenList={children}/>}
          {editMediaType&&<MediaTypeModal key={editMediaType.id||'new'} initial={editMediaType} onSave={saveMediaType} onClose={()=>setEditMediaType(null)}/>}
          {bonusModal&&<BonusModal ui={ui}/>}
            {moneyModal&&<MoneyModal ui={ui}/>}
          {showPin&&PinModal()}
          {timerModal&&<TimerModalView ui={ui}/>}
          {AlarmOverlay()}

          {/* ─── Persistente Player-Leiste ───
              Auf dem Jukebox-Screen uebernimmt der schwebende Mini-Player
              (AudioPlayerScreen), sonst waeren zwei Player gleichzeitig. */}
          {currentAudio && screen!=='audio' && (
            <div style={{
              position:'fixed', bottom:62, left:0, right:0, zIndex:999,
              background:'linear-gradient(90deg,#1a1b2e,#141526)',
              borderTop:'1.5px solid rgba(255,255,255,0.08)',
              borderBottom:'1.5px solid rgba(255,255,255,0.06)',
              padding:'8px 13px', display:'flex', flexDirection:'column', gap:5
            }}>
              <div style={{ display:'flex', alignItems:'center', gap:10 }}>
                <div style={{ display:'flex', alignItems:'center', gap:7, flex:1, minWidth:0 }}>
                  {currentAudio.cover ? (
                    <img src={currentAudio.cover} alt="" className="ft-cover-sm" />
                  ) : (
                    <div className="ft-cover-fb-sm">🎵</div>
                  )}
                  <div style={{ fontSize:12, fontWeight:800, color:'#fff', whiteSpace:'nowrap', overflow:'hidden', textOverflow:'ellipsis' }}>
                    {currentAudio.name}
                  </div>
                </div>
                <button onClick={playPrev} style={{width:28,height:28,border:'none',borderRadius:7,cursor:'pointer',background:'rgba(255,255,255,0.06)',color:'#fff',fontSize:12,display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0}}>⏮</button>
                <button
                  onClick={() => (playing ? pauseAudio() : playAudio(currentAudio))}
                  style={{
                    width:32, height:32, border:'none', borderRadius:8, cursor:'pointer',
                    background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',
                    color:'#fff', fontSize:14, display:'flex', alignItems:'center', justifyContent:'center', flexShrink:0
                  }}
                >
                  {audioLoading ? '⏳' : (playing ? '⏸' : '▶')}
                </button>
                <button onClick={playNext} style={{width:28,height:28,border:'none',borderRadius:7,cursor:'pointer',background:'rgba(255,255,255,0.06)',color:'#fff',fontSize:12,display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0}}>⏭</button>
                <div style={{ display:'flex', alignItems:'center', gap:4, flexShrink:0 }}>
                  <span style={{ fontSize:11, color:'rgba(255,255,255,0.3)' }}>🔊</span>
                  <input type="range" min="0" max="1" step="0.05" value={audioVolume}
                    onChange={(e) => setAudioVolume(parseFloat(e.target.value))}
                    style={{ width:60, height:3, borderRadius:99, cursor:'pointer' }}
                  />
                </div>
              </div>
              <div style={{ display:'flex', alignItems:'center', gap:8 }}>
                <span id="dom-player-time" style={{ fontSize:9, fontWeight:700, color:'rgba(255,255,255,0.3)', width:32, textAlign:'center', flexShrink:0 }}>
                  0:00
                </span>
                <div onClick={handleProgressClick} style={{ flex:1, height:3, background:'rgba(255,255,255,0.08)', borderRadius:99, cursor:'pointer', overflow:'hidden' }}>
                  <div id="dom-player-bar" style={{ height:'100%', background:'linear-gradient(90deg,#FF6B6B,#FFE66D)', borderRadius:99, width:'0%', transition:'width 0.1s' }} />
                </div>
                <span id="dom-player-dur" style={{ fontSize:9, fontWeight:700, color:'rgba(255,255,255,0.3)', width:32, textAlign:'center', flexShrink:0 }}>
                  –:––
                </span>
              </div>
            </div>
          )}

          <BottomNav/>
          {toast&&<div className={`ft-toast ${toast.type}`}>{toast.msg}</div>}
        </div>
      );
    }

    const root = ReactDOM.createRoot(document.getElementById('root'));

    // ── Family Login Wrapper ─────────────────────────────────────────────────
    function AppRoot() {
      const {useState: us, useEffect: ue} = React;
      const [fc, setFc] = us(null);          // null = unbekannt, '' = kein Code, 'CODE' = eingeloggt
      const [input, setInput] = us('');
      const [name, setName] = us('');
      const [err, setErr] = us('');
      const [loading, setLoading] = us(false);
      const [view, setView] = us('login');   // 'login' | 'register'

      ue(()=>{
        let saved = localStorage.getItem('ft_fc');
        if (saved === 'default') { saved = 'FAM001'; localStorage.setItem('ft_fc','FAM001'); }
        const savedName = localStorage.getItem('ft_fn');
        if (saved) { setFc(saved); if(savedName) setName(savedName); }
        else setFc('');
      },[]);

      const verify = async () => {
        const code = input.trim().toUpperCase().replace(/[^A-Z0-9]/g,'');
        if (!code) { setErr(t('auth.enter_code')); return; }
        setLoading(true); setErr('');
        try {
          const r = await fetch(`api.php?action=verify`, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code})});
          const d = await r.json();
          if (d.ok) { localStorage.setItem('ft_fc',d.code); localStorage.setItem('ft_fn',d.name); setFc(d.code); setName(d.name); }
          else setErr(d.msg||t('auth.invalid_code'));
        } catch(e) { setErr(t('auth.connection_error')); }
        setLoading(false);
      };

      const register = async () => {
        const n = name.trim() || t('auth.default_family');
        setLoading(true); setErr('');
        try {
          const r = await fetch(`api.php?action=register`, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:n})});
          const d = await r.json();
          if (d.ok) { localStorage.setItem('ft_fc',d.code); localStorage.setItem('ft_fn',d.name); setFc(d.code); setName(d.name); }
          else setErr(d.msg||t('common.error'));
        } catch(e) { setErr(t('auth.connection_error')); }
        setLoading(false);
      };

      const onRename = (newName) => { localStorage.setItem('ft_fn', newName); setName(newName); };
      const logout = () => { localStorage.removeItem('ft_fc'); localStorage.removeItem('ft_fn'); setFc(''); setInput(''); setName(''); };

      // Noch nicht geprüft
      if (fc === null) return null;

      // Eingeloggt → normale App
      if (fc) return <FamTask familyCode={fc} familyName={name} onLogout={logout} onRename={onRename}/>;

      // Login Screen
      return(
        <main style={{minHeight:'100vh',background:'#0D0E1A',display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',padding:'24px'}}>
          <div style={{marginBottom:28,textAlign:'center'}}>
            <div style={{fontFamily:"'Fredoka One',sans-serif",fontSize:36,display:'flex',alignItems:'center',justifyContent:'center',gap:10,marginBottom:8}}>
              <img src="icons/favicon.svg" alt="" width="42" height="42" style={{display:'block'}}/>
              <span style={{background:'linear-gradient(135deg,#FF6B6B,#FFE66D)',WebkitBackgroundClip:'text',WebkitTextFillColor:'transparent'}}>FamTask</span>
            </div>
            <div style={{fontSize:13,color:'rgba(255,255,255,0.62)'}}>{t('auth.tagline')}</div>
          </div>

          <div style={{background:'#161728',border:'1.5px solid rgba(255,255,255,0.08)',borderRadius:20,padding:'28px 24px',width:'100%',maxWidth:380}}>

            {view === 'login' ? (<>
              <div style={{fontSize:18,fontWeight:900,color:'#fff',marginBottom:6}}>{t('auth.login_title')}</div>
              <div style={{fontSize:13,color:'rgba(255,255,255,0.65)',marginBottom:20}}>{t('auth.login_desc')}</div>
              <input
                style={{width:'100%',background:'#0D0E1A',border:'1.5px solid rgba(255,255,255,0.1)',borderRadius:12,color:'#fff',fontSize:22,fontWeight:900,padding:'14px',outline:'none',textAlign:'center',letterSpacing:'6px',fontFamily:'monospace',marginBottom:12,boxSizing:'border-box'}}
                placeholder={t('auth.code_placeholder')}
                maxLength={6}
                value={input}
                onChange={e=>setInput(e.target.value.toUpperCase())}
                onKeyDown={e=>e.key==='Enter'&&verify()}
                autoCapitalize="characters"
                autoCorrect="off"
                spellCheck="false"
              />
              {err && <div style={{fontSize:12,color:'#FF6B6B',fontWeight:700,marginBottom:10,textAlign:'center'}}>{err}</div>}
              <button onClick={verify} disabled={loading}
                style={{width:'100%',padding:'14px',background:'linear-gradient(135deg,#FF6B6B,#FF8E53)',border:'none',borderRadius:12,color:'#fff',fontSize:15,fontWeight:900,cursor:'pointer',marginBottom:14}}>
                {loading ? t('auth.login_loading') : t('auth.login_btn')}
              </button>
              <div style={{textAlign:'center'}}>
                <button onClick={()=>{setView('register');setErr('');}}
                  style={{background:'none',border:'none',color:'rgba(78,205,196,0.8)',fontSize:13,fontWeight:700,cursor:'pointer',textDecoration:'underline'}}>
                  {t('auth.create_family')}
                </button>
              </div>
            </>) : (<>
              <div style={{fontSize:18,fontWeight:900,color:'#fff',marginBottom:6}}>{t('auth.register_title')}</div>
              <div style={{fontSize:13,color:'rgba(255,255,255,0.65)',marginBottom:20}}>{t('auth.register_desc')}</div>
              <label style={{fontSize:11,fontWeight:800,color:'rgba(255,255,255,0.65)',textTransform:'uppercase',letterSpacing:.5,display:'block',marginBottom:6}}>{t('auth.family_name')}</label>
              <input
                style={{width:'100%',background:'#0D0E1A',border:'1.5px solid rgba(255,255,255,0.1)',borderRadius:12,color:'#fff',fontSize:15,padding:'12px 14px',outline:'none',marginBottom:12,boxSizing:'border-box'}}
                placeholder={t('auth.family_name_placeholder')}
                value={name}
                onChange={e=>setName(e.target.value)}
                onKeyDown={e=>e.key==='Enter'&&register()}
              />
              {err && <div style={{fontSize:12,color:'#FF6B6B',fontWeight:700,marginBottom:10,textAlign:'center'}}>{err}</div>}
              <button onClick={register} disabled={loading}
                style={{width:'100%',padding:'14px',background:'linear-gradient(135deg,#4ECDC4,#45B7AA)',border:'none',borderRadius:12,color:'#fff',fontSize:15,fontWeight:900,cursor:'pointer',marginBottom:14}}>
                {loading ? t('auth.register_loading') : t('auth.register_btn')}
              </button>
              <div style={{textAlign:'center'}}>
                <button onClick={()=>{setView('login');setErr('');}}
                  style={{background:'none',border:'none',color:'rgba(255,255,255,0.65)',fontSize:13,fontWeight:700,cursor:'pointer',textDecoration:'underline'}}>
                  {t('auth.back_to_login')}
                </button>
              </div>
            </>)}
          </div>

          <div style={{marginTop:16,fontSize:11,color:'rgba(255,255,255,0.55)'}}>{t('auth.privacy')}</div>
        </main>
      );
    }

    root.render(<AppRoot/>);
