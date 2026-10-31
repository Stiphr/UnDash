/* UnDash UI: one page (overview, alerts, favourites, metrics, containers, VMs, storage, services) plus a settings drawer.
   Plain JS, no libraries. Every string from the server or an app is inserted as text, never as HTML.
   One live request per refresh (never two at once); widgets of other apps refresh on their own, slower loop. */
(function () {
  'use strict';
  var API = window.UND_API || '/plugins/undash/include/api.php';
  var UNRAID = window.UND_UNRAID || '';           // set on the standalone port: where Unraid's own web UI is
  var root = document.getElementById('und');
  if (!root) return;
  var S = {
    nat: {},
    boot: null, L: null, live: null, lastOk: 0, conn: 'wait', fails: 0, paused: false, inflight: false, timer: null, waitVisible: false,
    svc: {}, svcTimer: null, svcRun: false, storageAt: 0, busy: {}, q: '', fd: 'all', showHidden: false,
    full: false, drawer: null, menu: null, power: null
  };
  var LS = { get: function (k, d) { try { var v = localStorage.getItem('undash.' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
             set: function (k, v) { try { localStorage.setItem('undash.' + k, JSON.stringify(v)); } catch (e) { /* private window */ } } };

  /* ---------- DOM helpers ---------- */
  function h(tag, p) {
    var e = document.createElement(tag); p = p || {};
    for (var k in p) {
      var v = p[k];
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') e.className = v;
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
      else if (k === 'value') e.value = v;
      else if (k === 'checked' || k === 'disabled' || k === 'selected') e[k] = !!v;
      else if (k === 'style' && typeof v === 'object') { for (var s in v) e.style.setProperty(s, v[s]); }
      else e.setAttribute(k, v === true ? '' : v);
    }
    for (var i = 2; i < arguments.length; i++) add(e, arguments[i]);
    return e;
  }
  function add(e, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(e, x); }); return; }
    e.appendChild(c.nodeType ? c : document.createTextNode(String(c)));
  }
  function clear(e) { while (e && e.firstChild) e.removeChild(e.firstChild); return e; }
  function txt(e, t) { if (e && e.textContent !== t) e.textContent = t; }
  var SVGNS = 'http://www.w3.org/2000/svg';
  var IC = {
    cpu: 'M9 3v2M15 3v2M9 19v2M15 19v2M3 9h2M3 15h2M19 9h2M19 15h2M7 5h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM9 9h6v6H9z',
    mem: 'M3 7h18v10H3zM7 7v10M11 7v10M15 7v10M5 17v3M19 17v3', disk: 'M4 15l2-9h12l2 9M4 15h16v4H4zM17 17h.01',
    net: 'M7 17V5M3 9l4-4 4 4M17 7v12M13 15l4 4 4-4', temp: 'M14 14.8V5a2 2 0 0 0-4 0v9.8a4 4 0 1 0 4 0zM12 9v8',
    clock: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2', load: 'M3 17l5-6 4 3 5-7 4 5', server: 'M4 4h16v6H4zM4 14h16v6H4zM8 7h.01M8 17h.01',
    box: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8', vm: 'M3 4h18v12H3zM8 20h8M12 16v4', bolt: 'M13 2L4 14h7l-1 8 9-12h-7z',
    array: 'M4 6h16M4 12h16M4 18h16', swap: 'M7 4v16M3 16l4 4 4-4M17 20V4M13 8l4-4 4 4', tag: 'M3 12V3h9l9 9-9 9zM7.5 7.5h.01',
    play: 'M7 4l13 8-13 8z', pause: 'M7 4h4v16H7zM14 4h4v16h-4z', stop: 'M6 6h12v12H6z',
    restart: 'M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7', logs: 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h5', info: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 11v5M12 8h.01',
    up: 'M12 19V5M5 12l7-7 7 7', open: 'M7 17L17 7M8 7h9v9', web: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
    sun: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
    moon: 'M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z', check: 'M5 12l4 4 10-10', power: 'M12 3v8M6.3 6.3a8 8 0 1 0 11.4 0',
    undo: 'M9 7L4 12l5 5M4 12h11a5 5 0 0 1 0 10h-2', redo: 'M15 7l5 5-5 5M20 12H9a5 5 0 0 0 0 10h2', fit: 'M12 3v18M8 7l4-4 4 4M8 17l4 4 4-4', full: 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5', screen: 'M3 4h18v12H3zM8 20h8M12 16v4', edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
    trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13', upar: 'M12 6l-6 6h12z', dnar: 'M12 18l6-6H6z', plus: 'M12 5v14M5 12h14', x: 'M6 6l12 12M18 6L6 18',
    gear: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
    dots: 'M5 12h.01M12 12h.01M19 12h.01', star: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z',
    eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z', eyeoff: 'M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.1 3.9M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.6 9.6 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2',
    search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-4-4', list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z', chev: 'M6 9l6 6 6-6', history: 'M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 2',
    download: 'M12 4v12M6 10l6 6 6-6M4 20h16', warn: 'M12 3l10 18H2zM12 10v5M12 18h.01', bell: 'M6 16v-5a6 6 0 0 1 12 0v5l2 2H4zM10 21h4', mute: 'M9 18h6M6 15V10a6 6 0 0 1 9.5-4.9M18 10v5l1.5 2H8M3 3l18 18'
  };
  function ic(n, fill) {
    var s = document.createElementNS(SVGNS, 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', '1.8'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false');
    var p = document.createElementNS(SVGNS, 'path'); p.setAttribute('d', IC[n] || '');
    if (fill || n === 'play' || n === 'pause' || n === 'stop') p.setAttribute('fill', 'currentColor');
    s.appendChild(p); return s;
  }
  function btn(label, onclick, cls, icon, title) {
    return h('button', { type: 'button', class: 'btn ' + (cls || '') + (label ? '' : ' ic'), onclick: onclick, title: title || null, 'aria-label': !label && title ? title : null }, icon ? ic(icon) : null, label || null);
  }

  /* ---------- formatting ---------- */
  function num(v) { return v === null || v === undefined || v === '' ? '-' : (typeof v === 'number' ? v.toLocaleString() : (isFinite(+v) && String(v).trim() !== '' ? (+v).toLocaleString() : String(v))); }
  function bytes(b, dec) {
    if (b === null || b === undefined || isNaN(b)) return '-';
    var u = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'], i = 0; b = +b;
    while (Math.abs(b) >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i === 0 ? Math.round(b) : b.toFixed(dec === undefined ? (b >= 100 ? 0 : b >= 10 ? 1 : 2) : dec)) + ' ' + u[i];
  }
  function rate(b) { if (b === null || b === undefined) return '-'; return b < 1 ? '0 B/s' : bytes(b, 1).replace('KiB', 'kiB') + '/s'; }
  function dur(s) { s = Math.max(0, Math.round(s || 0)); var hh = Math.floor(s / 3600), mm = Math.floor(s % 3600 / 60), ss = s % 60; return (hh ? hh + ':' + (mm < 10 ? '0' : '') : '') + mm + ':' + (ss < 10 ? '0' : '') + ss; }
  function upt(s) { var d = Math.floor(s / 86400), hh = Math.floor(s % 86400 / 3600), mm = Math.floor(s % 3600 / 60); return d ? d + 'd ' + hh + 'h' : hh ? hh + 'h ' + mm + 'm' : mm + 'm'; }
  function pct(v) { return v === null || v === undefined ? '-' : (Math.round(v * 10) / 10) + '%'; }
  function fmt(v, f) {
    if (f === 'bytes') return bytes(v); if (f === 'rate') return rate(v); if (f === 'ms') return v === null || v === undefined || v === '' || !isFinite(+v) ? num(v) : num(+v >= 10 ? Math.round(+v) : Math.round(+v * 10) / 10) + ' ms';
    if (f === 'pct') return pct(v); if (f === 'dur') return dur(v);
    if (f === 'text') return v === null || v === undefined || v === '' ? '-' : (typeof v === 'number' ? v.toLocaleString() : String(v));
    return num(v);
  }
  var RTF = window.Intl && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat('en-GB', { numeric: 'auto', style: 'narrow' }) : null;
  function rel(ts) {
    if (!ts) return ''; var d = ts - Date.now() / 1000, a = Math.abs(d);
    var u = a < 60 ? ['second', 1] : a < 3600 ? ['minute', 60] : a < 86400 ? ['hour', 3600] : a < 1209600 ? ['day', 86400] : a < 2629800 ? ['week', 604800] : a < 31557600 ? ['month', 2629800] : ['year', 31557600];
    var n = Math.round(d / u[1]);
    return RTF ? RTF.format(n, u[0]).replace(/\./g, '') : Math.abs(n) + ' ' + u[0] + (Math.abs(n) === 1 ? '' : 's') + (n < 0 ? ' ago' : '');
  }
  function day(ts) { return new Date(ts * 1000).toLocaleDateString([], { month: 'short', day: 'numeric' }); }
  function clock(ts) { return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
  function iconUrl(i) {
    if (!i) return '';
    if (/^https?:\/\//.test(i)) return i;
    if (i[0] === '/') return (UNRAID && !/^\/(state|plugins\/dynamix\.vm\.manager)\//.test(i) ? UNRAID : '') + i;
    var m;
    if ((m = /^sh-(.+?)(\.(svg|png|webp))?$/.exec(i))) return 'https://cdn.jsdelivr.net/gh/selfhst/icons@main/' + (m[3] || 'svg') + '/' + m[1] + '.' + (m[3] || 'svg');
    if (/^(mdi|si)-/.test(i)) return '';
    if ((m = /^(.+)\.(png|svg|webp)$/.exec(i))) return 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/' + m[2] + '/' + i;
    return 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/' + i + '.png';
  }
  /* src may be a list: when one fails to load the next is tried, and after the last the generic icon takes its place */
  function img(src, cls, fallback) {
    var list = (Array.isArray(src) ? src : [src]).filter(Boolean);
    var ph = function () { return fallback ? h('span', { class: 'ph' + (cls ? ' ' + cls : '') }, ic(fallback)) : null; };
    if (!list.length) return ph();
    var i = 0;
    return h('img', { class: cls || 'ico', src: list[0], alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', onerror: function () {
      if (++i < list.length) { this.src = list[i]; return; }
      var r = ph(); if (r && this.parentNode) this.parentNode.replaceChild(r, this); else this.style.visibility = 'hidden';
    } });
  }

  /* Container and VM icons from selfh.st, by name (Settings > Theme > Icons): "binhex-qbittorrentvpn" and
     "lscr.io/linuxserver/radarr:latest" become qbittorrentvpn / qbittorrent and radarr. The server says which names exist
     (and in which forms), so the browser does not try URLs that are not there; Unraid's own icon is the next choice. */
  var ICON_PRE = /^(binhex|linuxserver|lscr|hotio|ich777|official)[-_]/;
  function iconSlugs(name, image) {
    var out = [];
    var add = function (v) { if (v && out.indexOf(v) < 0) out.push(v); };
    [name, image].forEach(function (v) {
      if (!v) return;
      v = String(v).toLowerCase().replace(/@sha256:.*$/, '').split('/').pop().replace(/:[^:]*$/, '');
      v = v.replace(ICON_PRE, '').replace(/^arch-/, '').replace(/[\s_.]+/g, '-').replace(/[^a-z0-9-]/g, '').replace(/^-+|-+$/g, '');
      var bare = v.replace(/-v?\d+(-\d+)*$/, '');                        // a version or number at the end: windows-11, app-v2
      [v, bare, bare.replace(/-?vpn$/, ''), bare.replace(/-(server|app|web|docker)$/, '')].forEach(add);
    });
    return out;
  }
  var ICO = { map: null, want: {}, t: 0 };
  function icoMap() {
    if (!ICO.map) { var c = LS.get('icoSlugs', null); ICO.map = c && c.at > Date.now() - 86400000 ? c : { at: Date.now(), m: {}, no: {} }; }
    return ICO.map;
  }
  function icoAsk(slugs) {
    var m = icoMap(), n = 0;
    slugs.forEach(function (x) { if (!(x in m.m) && !m.no[x] && !ICO.want[x]) { ICO.want[x] = 1; n++; } });
    if (!n || ICO.t) return;
    ICO.t = setTimeout(function () {
      var ask = Object.keys(ICO.want); ICO.want = {}; ICO.t = 0;
      api('icon_slugs', { slugs: ask }).then(function (j) {
        if (j.error) return;                                            // offline: Unraid's icons stay
        var got = 0; ask.forEach(function (x) { if (j.icons[x]) { m.m[x] = j.icons[x]; got++; } else m.no[x] = 1; });
        LS.set('icoSlugs', m);
        if (got && !root.querySelector('.drawer,.modal-bg')) shell();
      });
    }, 300);
  }
  function objIcon(name, image, own, cls, fb) {
    var mode = (S.L && S.L.appearance.icon_mode) || 'color';
    if (mode === 'off') return img(iconUrl(own), cls, fb);
    var slugs = iconSlugs(name, image), m = icoMap().m, hit = null;
    icoAsk(slugs);
    slugs.some(function (x) { if (m[x]) { hit = x; return true; } return false; });
    if (!hit) return img(iconUrl(own), cls, fb);
    var f = m[hit].split(','), base = 'https://cdn.jsdelivr.net/gh/selfhst/icons@main/';
    var dark = (root.getAttribute('data-mode') || 'dark') === 'dark';
    var v = mode === 'light' ? 'light' : mode === 'dark' ? 'dark' : mode === 'auto' ? (dark ? 'light' : 'dark') : '';
    return img([v && f.indexOf(v) >= 0 ? base + 'svg/' + hit + '-' + v + '.svg' : '', f.indexOf('svg') >= 0 ? base + 'svg/' + hit + '.svg' : '',
      f.indexOf('png') >= 0 ? base + 'png/' + hit + '.png' : '', iconUrl(own)], cls, fb);
  }
  /* an icon picked in Settings always wins */
  function cIcon(c, p, cls) { return p.icon ? img(iconUrl(p.icon), cls, 'box') : objIcon(c.name, c.image, c.icon, cls, 'box'); }
  function vIcon(v, p, cls) { return p.icon ? img(iconUrl(p.icon), cls, 'vm') : objIcon(v.name, '', v.icon, cls, 'vm'); }
  function meterCls(p) { return p > 90 ? 'bad' : p > 75 ? 'warn' : ''; }

  var toastBox = null;
  /* undo: optional [label, fn], a button in the toast (it stays up longer so there is time to press it) */
  function toastHost() {
    if (!toastBox || !toastBox.parentNode) { toastBox = h('div', { class: 'toasts', 'aria-live': 'polite' }); root.appendChild(toastBox); }
    return toastBox;
  }
  function toast(msg, bad, undo) {
    toastHost();
    var t = h('div', { class: 'toast' + (bad ? ' bad' : '') + (undo ? ' act' : ''), role: bad ? 'alert' : 'status' }, h('span', null, msg),
      undo ? h('button', { type: 'button', class: 'btn sm', onclick: function () { if (t.parentNode) t.parentNode.removeChild(t); undo[1](); } }, undo[0]) : null);
    toastBox.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, undo ? 10000 : bad ? 8000 : 3500);
  }

  /* ---------- API ---------- */
  function api(action, data, timeoutMs) {
    if (S.drawer && S.drawer.previewing) {
      if (action === 'prefs') return Promise.resolve({ error: 'This is a preview. Go back to Settings or save first.' });
      // an app added in the preview has nothing on the server yet
      var known = function (id) { return S.drawer.saved.groups.some(function (g) { return g.services.some(function (x) { return x.id === id; }); }); };
      if (/^(dl|arr|service)$/.test(action) && data && data.id && !known(data.id)) return Promise.resolve({ error: 'Loads once you save.', preview: true, unconfigured: true, online: false });
    }
    if (BG.test(action)) return new Promise(function (done) { BGQ.push(function () { return send(action, data, timeoutMs).then(done); }); bgNext(); });
    return send(action, data, timeoutMs);
  }
  /* a browser opens at most 6 connections to one server: app and media reads share 4 of them, so the live refresh and clicks never queue behind a slow app */
  var BG = /^(media|arr|dl|service|playing|requests|health|self_update|alert_history|history|storage|os_status)$/, BGQ = [], bgRun = 0;
  function bgNext() { while (bgRun < 4 && BGQ.length) { bgRun++; BGQ.shift()().then(function () { bgRun--; bgNext(); }); } }
  /* UnDash was updated or restarted, so this page's session is gone: it loads again by itself, unless Settings has edits open */
  function staleSession() {
    var at = 0; try { at = +sessionStorage.getItem('undReload') || 0; } catch (e) { /* no storage: reload anyway */ }
    if (!S.drawer && Date.now() - at > 30000) {
      try { sessionStorage.setItem('undReload', Date.now()); } catch (e) { /* see above */ }
      location.reload();
    }
    return { error: 'UnDash restarted. Reload the page.', auth: true };
  }
  function send(action, data, timeoutMs) {
    var fd = new URLSearchParams(); fd.append('action', action);   // urlencoded: multipart POSTs hang on Unraid's web server
    fd.append('csrf_token', window.UND_CSRF || '');
    for (var k in (data || {})) fd.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]);
    var ctl = window.AbortController ? new AbortController() : null;
    var to = setTimeout(function () { if (ctl) ctl.abort(); }, timeoutMs || 30000);
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': window.UND_CSRF || '' }, signal: ctl ? ctl.signal : undefined }).then(function (r) {
      clearTimeout(to);
      if (r.status === 401) return { error: 'Signed out. Reload the page to sign in again.', auth: true };
      if (r.status === 403) return r.clone().text().then(function (t) { return /CSRF/.test(t) ? staleSession() : r.json().catch(function () { return { error: t || 'Refused (HTTP 403).' }; }); });
      return r.json().catch(function () { return { error: 'Unexpected answer from the server (HTTP ' + r.status + ').' }; }).then(function (j) { if (!r.ok && !j.error) j.error = 'Request failed (HTTP ' + r.status + ').'; return j; });
    }).catch(function (e) { clearTimeout(to); return { error: e && e.name === 'AbortError' ? 'The server took too long to answer.' : 'Could not reach the server.', offline: true }; });
  }
  function act(action, data, okMsg) { return api(action, data, 130000).then(function (j) { if (j.error) toast(j.error, true); else if (okMsg) toast(okMsg); return j; }); }

  /* ---------- dialogs and menus ---------- */
  function modal(title, body, foot, wide) {
    var last = document.activeElement;
    var bg = h('div', { class: 'modal-bg', onmousedown: function (e) { if (e.target === bg) close(); } });
    var hid = 'und-m' + Math.random().toString(36).slice(2, 8);
    var m = h('div', { class: 'modal' + (wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': hid, tabindex: '-1' }, h('h3', { id: hid }, title), body, foot ? h('div', { class: 'foot' }, foot) : null);
    function close() { if (!bg.parentNode) return; bg.parentNode.removeChild(bg); document.removeEventListener('keydown', key, true); if (m.onclose) m.onclose(); if (last && last.focus) try { last.focus(); } catch (e) { /* gone */ } }
    function key(e) {
      var all = root.querySelectorAll('.modal-bg');
      if (all[all.length - 1] !== bg) return;          // only the top-most dialog reacts
      if (e.key === 'Escape') { e.stopPropagation(); close(); return; }
      if (e.key !== 'Tab') return;
      var f = m.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select,textarea,[tabindex="0"]');
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
    document.addEventListener('keydown', key, true);
    bg.appendChild(m); root.appendChild(bg); m.close = close;
    setTimeout(function () { var f = m.querySelector('input,select,textarea,.foot .btn.pri,.foot .btn'); (f || m).focus(); }, 0);
    return m;
  }
  function confirmBox(title, text, okLabel, danger) {
    return new Promise(function (res) {
      var done = false, m;
      var ok = btn(okLabel || 'OK', function () { done = true; m.close(); res(true); }, danger ? 'danger solid' : 'pri');
      m = modal(title, typeof text === 'string' ? h('p', null, text) : text, [btn('Cancel', function () { m.close(); }), ok]);
      m.onclose = function () { if (!done) res(false); };
    });
  }
  function closeMenu(keepFocus) { if (S.menu) { var m = S.menu; S.menu = null; if (m.parentNode) m.parentNode.removeChild(m); document.removeEventListener('mousedown', menuOut, true); document.removeEventListener('keydown', menuKey, true); if (!keepFocus && m._from && document.body.contains(m._from)) try { m._from.focus(); } catch (e) { /* gone */ } } }
  function menuOut(e) { if (S.menu && !S.menu.contains(e.target) && !S.menu._from.contains(e.target)) closeMenu(true); }
  function menuKey(e) {
    if (!S.menu) return;
    var items = [].slice.call(S.menu.querySelectorAll('button:not([disabled])')), i = items.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.preventDefault(); closeMenu(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); (items[i + 1] || items[0]).focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
    else if (e.key === 'Tab') closeMenu(true);
  }
  /* items: [label, icon, fn, cls, disabled] or 'hr' */
  function menu(from, items) {
    if (S.menu && S.menu._from === from) { closeMenu(); return; }
    closeMenu(true);
    var m = h('div', { class: 'menu', role: 'menu' });
    items.forEach(function (x) {
      if (!x) return;
      if (x === 'hr') { if (m.lastChild && m.lastChild.tagName !== 'HR') m.appendChild(h('hr')); return; }
      m.appendChild(h('button', { type: 'button', role: 'menuitem', class: x[3] || '', disabled: !!x[4], onclick: function () { closeMenu(true); x[2](); } }, ic(x[1]), x[0]));
    });
    if (m.lastChild && m.lastChild.tagName === 'HR') m.removeChild(m.lastChild);
    m._from = from; S.menu = m; root.appendChild(m);
    var r = from.getBoundingClientRect(), mw = m.offsetWidth, mh = m.offsetHeight;
    var left = Math.min(window.innerWidth - mw - 8, Math.max(8, r.right - mw)), top = r.bottom + 4;
    if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 4);
    m.style.left = left + 'px'; m.style.top = top + 'px';
    document.addEventListener('mousedown', menuOut, true); document.addEventListener('keydown', menuKey, true);
    var f = m.querySelector('button:not([disabled])'); if (f) f.focus();
  }
  window.addEventListener('scroll', function () { closeMenu(true); }, true);

  /* ---------- appearance ---------- */
  function hexRgb(hx) { var m = /^#([0-9a-f]{6})$/i.exec(hx || ''); if (!m) return [241, 90, 44]; var n = parseInt(m[1], 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; }
  function mix(c, t, f) { return 'rgb(' + c.map(function (v, i) { return Math.round(v + (t[i] - v) * f); }).join(',') + ')'; }
  function lum(c) { var a = c.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2]; }
  /* Colour themes. Each has a dark and (most) a light palette; the mode setting picks one (system = follow the OS).
     Palettes come from the themes' public, MIT-licensed definitions (Kanagawa, Catppuccin, Nord, Tokyo Night) and the
     shadcn neutral / zinc scales; Fluent Dark is a frosted-glass look.
     keys: bg panel p2 (inner panel) bar (strip / hover) field line l2 (strong line) text muted faint title accent
           [ok warn bad info] (status colours; when missing, the standard ones for dark or light are used) */
  var THEMES = {
    default: { name: 'Golden Gate', dark: { bg: '#1d1b1b', panel: '#232121', p2: '#1b1919', bar: '#2a2828', field: '#181616', line: '#343131', l2: '#454141', text: '#ece9e9', muted: '#a19c9c', faint: '#7f7977', title: '#ffffff', accent: '#f15a2c' },
      light: { bg: '#f3f4f6', panel: '#ffffff', p2: '#f6f7f9', bar: '#f0f1f4', field: '#ffffff', line: '#e2e3e8', l2: '#cdd0d6', text: '#1d1f24', muted: '#5b606b', faint: '#80858f', title: '#111318', accent: '#e0491b' } },
    amoled: { name: 'Midnight', dark: { bg: '#000000', panel: '#0b0b0b', p2: '#070707', bar: '#141414', field: '#050505', line: '#202020', l2: '#2e2e2e', text: '#f2f2f2', muted: '#a8a8a8', faint: '#767676', title: '#ffffff', accent: '#f15a2c' },
      light: { bg: '#ffffff', panel: '#ffffff', p2: '#f7f7f7', bar: '#f0f0f0', field: '#ffffff', line: '#e3e3e3', l2: '#c9c9c9', text: '#000000', muted: '#4a4a4a', faint: '#777777', title: '#000000', accent: '#e0491b' } },
    minimal: { name: 'Mono', dark: { bg: '#0b0b0c', panel: '#18181a', p2: '#131315', bar: '#232326', field: '#101012', line: '#2a2a2d', l2: '#3b3b3f', text: '#f5f5f7', muted: '#a1a1a6', faint: '#6e6e73', title: '#ffffff', accent: '#f5f5f7', ok: '#8fd6a6', warn: '#e8c46a', bad: '#ff7b72', info: '#b6c2d2' },
      light: { bg: '#f5f5f7', panel: '#ffffff', p2: '#f2f2f4', bar: '#e8e8ed', field: '#ffffff', line: '#e3e3e8', l2: '#d2d2d7', text: '#1d1d1f', muted: '#6e6e73', faint: '#8e8e93', title: '#000000', accent: '#1d1d1f' } },
    wave: { name: 'Kanagawa', dark: { bg: '#1f1f28', panel: '#2a2a37', p2: '#232330', bar: '#363646', field: '#1a1a22', line: '#363646', l2: '#54546d', text: '#dcd7ba', muted: '#a8a48f', faint: '#727169', title: '#ece7cb', accent: '#7e9cd8', ok: '#98bb6c', warn: '#e6c384', bad: '#e46876', info: '#7fb4ca' },
      light: { bg: '#e5ddb0', panel: '#f2ecbc', p2: '#ece5b6', bar: '#e7dba0', field: '#f7f1c8', line: '#d5cea3', l2: '#c7bf93', text: '#545464', muted: '#6f6e66', faint: '#8a8980', title: '#43436c', accent: '#4d699b', ok: '#6f894e', warn: '#836f4a', bad: '#c84053', info: '#4e8ca2' } },
    catppuccin: { name: 'Catppuccin', dark: { bg: '#11111b', panel: '#1e1e2e', p2: '#181825', bar: '#313244', field: '#181825', line: '#313244', l2: '#45475a', text: '#cdd6f4', muted: '#a6adc8', faint: '#7f849c', title: '#e6ebff', accent: '#cba6f7', ok: '#a6e3a1', warn: '#f9e2af', bad: '#f38ba8', info: '#89b4fa' },
      light: { bg: '#dce0e8', panel: '#eff1f5', p2: '#e6e9ef', bar: '#ccd0da', field: '#e6e9ef', line: '#ccd0da', l2: '#bcc0cc', text: '#4c4f69', muted: '#6c6f85', faint: '#8c8fa1', title: '#4c4f69', accent: '#8839ef', ok: '#40a02b', warn: '#b76d0b', bad: '#d20f39', info: '#1e66f5' } },
    nord: { name: 'Nord', dark: { bg: '#2e3440', panel: '#3b4252', p2: '#353b49', bar: '#434c5e', field: '#2b303b', line: '#434c5e', l2: '#4c566a', text: '#e5e9f0', muted: '#aeb6c4', faint: '#7b88a1', title: '#eceff4', accent: '#88c0d0', ok: '#a3be8c', warn: '#ebcb8b', bad: '#bf616a', info: '#81a1c1' },
      light: { bg: '#e5e9f0', panel: '#eceff4', p2: '#e8ecf2', bar: '#d8dee9', field: '#f2f4f8', line: '#d8dee9', l2: '#c2cad8', text: '#2e3440', muted: '#4c566a', faint: '#7b88a1', title: '#2e3440', accent: '#5e81ac', ok: '#5c7d43', warn: '#9a7417', bad: '#bf616a', info: '#5e81ac' } },
    autobrr: { name: 'Autobrr', dark: { bg: '#14151b', panel: '#1e1f27', p2: '#191a21', bar: '#2a2c36', field: '#121318', line: '#2c2e38', l2: '#40424f', text: '#e9eaf0', muted: '#a2a4b3', faint: '#737586', title: '#ffffff', accent: '#4f8dff' },
      light: { bg: '#eef0f6', panel: '#ffffff', p2: '#f4f5f9', bar: '#e7e9f1', field: '#ffffff', line: '#dfe1ea', l2: '#c8cbd8', text: '#1b1c24', muted: '#5c5f70', faint: '#868899', title: '#0d0e14', accent: '#2563eb' } },
    kyle: { name: 'The Kyle', dark: { bg: '#10121f', panel: '#1b1a33', p2: '#161529', bar: '#2a2747', field: '#0e0f1b', line: '#2e2b4d', l2: '#46426c', text: '#f1eff8', muted: '#b0abc6', faint: '#7f7a99', title: '#ffffff', accent: '#ec3f9d' },
      light: { bg: '#fbf0f6', panel: '#ffffff', p2: '#fbf4f8', bar: '#f6e4ee', field: '#ffffff', line: '#f0dde8', l2: '#e0c6d5', text: '#201a2b', muted: '#6c5f74', faint: '#958999', title: '#14101c', accent: '#c81e7e' } },
    napster: { name: 'Napster', dark: { bg: '#0d1433', panel: '#17204a', p2: '#121a3e', bar: '#232e62', field: '#0b112b', line: '#27336b', l2: '#3d4a8c', text: '#eef0fa', muted: '#aab1d4', faint: '#7a82a8', title: '#ffffff', accent: '#f2d23d' },
      light: { bg: '#c9c9cd', panel: '#f4f4f5', p2: '#e9e9eb', bar: '#dcdcdf', field: '#ffffff', line: '#b9b9bf', l2: '#9c9ca5', text: '#14151c', muted: '#4a4b57', faint: '#6d6e7a', title: '#0a0c24', accent: '#1f2f8f' } },
    nightwalker: { name: 'Nightwalker', dark: { bg: '#1c2130', panel: '#262c3d', p2: '#212636', bar: '#323a4f', field: '#191d2a', line: '#333b50', l2: '#4a5470', text: '#e4e9f4', muted: '#a7b0c6', faint: '#7a84a0', title: '#f6f8ff', accent: '#6ea8ff' },
      light: { bg: '#e6ebf4', panel: '#fbfcfe', p2: '#f1f4f9', bar: '#e2e8f2', field: '#ffffff', line: '#d9e0ec', l2: '#c2cbdc', text: '#1a2133', muted: '#566079', faint: '#7f89a1', title: '#0e1424', accent: '#2f6fd8' } },
    swizzin: { name: 'Swizzin', dark: { bg: '#1a1b1c', panel: '#26282a', p2: '#202223', bar: '#323436', field: '#171819', line: '#343638', l2: '#4a4d50', text: '#f1f2f2', muted: '#a9adb0', faint: '#7b7f82', title: '#ffffff', accent: '#2fc4a0' },
      light: { bg: '#eff1f1', panel: '#ffffff', p2: '#f5f6f6', bar: '#e8ebeb', field: '#ffffff', line: '#dfe3e3', l2: '#c7cdcd', text: '#26292b', muted: '#5e6467', faint: '#878d90', title: '#141617', accent: '#2d6a86' } }
  };
  var STATUS = { dark: { ok: '#4ec38b', warn: '#e8b33c', bad: '#ff6b5e', info: '#79a0ee', off: '#8a8484' }, light: { ok: '#14804a', warn: '#8a5a00', bad: '#c4332a', info: '#2457c5', off: '#6f7480' } };
  /* each theme's background: a soft mesh of its own colours. Themes made in Settings get one from their background and accent. */
  var MESH = {
    default: { dark: 'radial-gradient(95% 72% at 84% 110%,#e88b42 0%,#c95a2b 30%,transparent 70%),radial-gradient(72% 56% at 16% 108%,#a53c5e 0%,transparent 72%),radial-gradient(62% 46% at 60% 64%,rgba(124,58,112,.6),transparent 74%),radial-gradient(72% 52% at 8% -4%,#27467a,transparent 72%),linear-gradient(176deg,#0e1628,#191830 48%,#2c1a33)',
      light: 'radial-gradient(95% 72% at 86% 108%,#ffb878,transparent 70%),radial-gradient(72% 56% at 10% 104%,#f7a3ad,transparent 72%),radial-gradient(72% 52% at 12% -4%,#a8c9f2,transparent 72%),linear-gradient(176deg,#dde9f6,#f4eaeb 55%,#fce4d4)' },
    amoled: { dark: 'radial-gradient(62% 46% at 86% -4%,#14346a,transparent 72%),radial-gradient(54% 42% at 12% 106%,#3d1156,transparent 72%),radial-gradient(44% 32% at 64% 112%,#0c4e5c,transparent 72%),#000',
      light: 'radial-gradient(62% 46% at 86% -4%,#dbe7ff,transparent 72%),radial-gradient(54% 42% at 12% 106%,#efdcfb,transparent 72%),radial-gradient(44% 32% at 64% 112%,#d4f1f4,transparent 72%),#fff' },
    minimal: { dark: 'radial-gradient(100% 62% at 50% -14%,#5a5a60,transparent 70%),radial-gradient(60% 46% at 100% 104%,#2e2e32,transparent 72%),radial-gradient(60% 46% at 0% 104%,#232326,transparent 72%),linear-gradient(180deg,#101012,#0a0a0b)',
      light: 'radial-gradient(100% 62% at 50% -14%,#fff,transparent 70%),radial-gradient(60% 46% at 100% 104%,#d3d3d9,transparent 72%),radial-gradient(60% 46% at 0% 104%,#dcdce1,transparent 72%),linear-gradient(180deg,#f5f5f7,#e9e9ee)' },
    wave: { dark: 'radial-gradient(84% 62% at 86% 108%,#2d5a78,transparent 72%),radial-gradient(62% 50% at 8% 98%,rgba(149,127,184,.5),transparent 72%),radial-gradient(54% 42% at 76% -4%,#223249,transparent 72%),radial-gradient(26% 22% at 24% 10%,rgba(195,64,67,.28),transparent 74%),linear-gradient(170deg,#16161d,#1f1f28 50%,#1a2330)',
      light: 'radial-gradient(84% 62% at 86% 108%,#a5c9d6,transparent 72%),radial-gradient(62% 50% at 8% 98%,#d8c6e0,transparent 72%),radial-gradient(26% 22% at 24% 10%,#efb6a6,transparent 74%),linear-gradient(170deg,#f2ecbc,#e8dfb4 60%,#dfe0c0)' },
    catppuccin: { dark: 'radial-gradient(72% 60% at 86% -2%,rgba(203,166,247,.55),transparent 72%),radial-gradient(72% 60% at 6% 104%,rgba(137,180,250,.46),transparent 72%),radial-gradient(52% 42% at 78% 106%,rgba(245,194,231,.36),transparent 72%),linear-gradient(165deg,#11111b,#1e1e2e)',
      light: 'radial-gradient(72% 60% at 86% -2%,#d9c2fa,transparent 72%),radial-gradient(72% 60% at 6% 104%,#b8d0fb,transparent 72%),radial-gradient(52% 42% at 78% 106%,#f9cfe6,transparent 72%),linear-gradient(165deg,#eff1f5,#dce0e8)' },
    nord: { dark: 'radial-gradient(82% 56% at 82% -4%,rgba(136,192,208,.48),transparent 72%),radial-gradient(62% 50% at 12% 104%,rgba(180,142,173,.42),transparent 72%),radial-gradient(62% 46% at 92% 106%,rgba(163,190,140,.26),transparent 72%),radial-gradient(70% 52% at 6% 6%,rgba(94,129,172,.5),transparent 72%),linear-gradient(170deg,#242933,#2e3440)',
      light: 'radial-gradient(82% 56% at 82% -4%,#b6dbe6,transparent 72%),radial-gradient(62% 50% at 12% 104%,#e2d0df,transparent 72%),radial-gradient(62% 46% at 92% 106%,#d2e2c6,transparent 72%),linear-gradient(170deg,#eceff4,#dbe2ec)' },
    autobrr: { dark: 'radial-gradient(80% 62% at 88% -6%,rgba(79,141,255,.5),transparent 72%),radial-gradient(70% 56% at 4% 106%,rgba(46,84,210,.42),transparent 72%),radial-gradient(44% 34% at 62% 108%,rgba(96,60,220,.24),transparent 72%),linear-gradient(170deg,#101116,#191a22)',
      light: 'radial-gradient(80% 62% at 88% -6%,#b5cdfd,transparent 72%),radial-gradient(70% 56% at 4% 106%,#c4d0fa,transparent 72%),linear-gradient(170deg,#f3f5fa,#e4e8f3)' },
    kyle: { dark: 'radial-gradient(84% 62% at 90% 106%,rgba(236,63,157,.62),transparent 72%),radial-gradient(66% 54% at 6% 0%,rgba(92,60,214,.5),transparent 72%),radial-gradient(44% 36% at 22% 104%,rgba(170,40,170,.3),transparent 72%),linear-gradient(170deg,#0d0f1c,#171430)',
      light: 'radial-gradient(84% 62% at 90% 106%,#f9b4d8,transparent 72%),radial-gradient(66% 54% at 6% 0%,#cfc3f7,transparent 72%),linear-gradient(170deg,#fdf3f8,#f6e6f0)' },
    napster: { dark: 'radial-gradient(70% 54% at 88% -4%,rgba(242,210,61,.34),transparent 70%),radial-gradient(84% 60% at 4% 106%,rgba(40,70,190,.6),transparent 72%),linear-gradient(170deg,#0a1029,#121b45)',
      light: 'radial-gradient(70% 54% at 88% -4%,#f4e596,transparent 70%),radial-gradient(84% 60% at 4% 106%,#aab6e2,transparent 72%),linear-gradient(170deg,#d6d6da,#bdbdc4)' },
    nightwalker: { dark: 'radial-gradient(70% 56% at 76% -6%,rgba(110,168,255,.42),transparent 72%),radial-gradient(70% 54% at 4% 104%,rgba(70,92,160,.5),transparent 72%),radial-gradient(40% 30% at 96% 104%,rgba(90,210,220,.2),transparent 72%),linear-gradient(170deg,#171b28,#20263a)',
      light: 'radial-gradient(70% 56% at 76% -6%,#bcd4f8,transparent 72%),radial-gradient(70% 54% at 4% 104%,#c9d3ea,transparent 72%),linear-gradient(170deg,#eef2f8,#dee5f1)' },
    swizzin: { dark: 'radial-gradient(76% 58% at 88% 106%,rgba(47,196,160,.46),transparent 72%),radial-gradient(60% 46% at 4% -4%,rgba(60,120,140,.34),transparent 72%),linear-gradient(170deg,#161718,#212324)',
      light: 'radial-gradient(76% 58% at 88% 106%,#b0e3d5,transparent 72%),radial-gradient(60% 46% at 4% -4%,#bcd6e2,transparent 72%),linear-gradient(170deg,#f3f5f5,#e4e8e8)' }
  };
  function meshCss(id, mode) {
    var t = THEMES[id] || THEMES.default;
    if (!t.custom) return (MESH[id] || MESH.default)[mode];
    var c = t.custom.colors, ac = hexRgb(c.accent), tint = function (o) { return 'rgba(' + ac.join(',') + ',' + o + ')'; };
    return 'radial-gradient(86% 66% at 84% 108%,' + tint(0.62) + ',transparent 72%),radial-gradient(66% 56% at 8% -4%,' + tint(0.26) + ',transparent 72%),linear-gradient(170deg,' + c.bg + ',' + mix(hexRgb(c.bg), ac, 0.16) + ')';
  }
  var DARK_OS = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;
  /* the six colours of any theme, as plain hex, for the theme maker and the export file */
  function applyBase(p, mode, a) {
    var d = mode === 'light' ? BOARD_LIGHT : BOARD_DARK, q = a.theme === 'default' && S.L && boardOn() ? d : p, hx = function (k) { return /^#[0-9a-f]{6}$/i.test(q[k]) ? q[k] : d[k]; };
    return { bg: hx('bg'), panel: hx('panel'), text: hx('text'), muted: hx('muted'), line: hx('line'), accent: a.accent || hx('accent') };
  }
  function themeName(t, mode) { return typeof t.name === 'string' ? t.name : t.name[mode] || t.name.dark; }
  /* themes made or imported in Settings: six colours are enough, the rest is worked out from them */
  function customPalette(t) {
    var c = t.colors, bg = hexRgb(c.bg), pn = hexRgb(c.panel), tx = hexRgb(c.text), ln = hexRgb(c.line), m = t.mode === 'light' ? [0, 0, 0] : [255, 255, 255];
    return { bg: c.bg, panel: c.panel, p2: mix(pn, bg, 0.5), bar: mix(pn, m, 0.06), field: mix(bg, pn, 0.3), line: c.line, l2: mix(ln, tx, 0.18), text: c.text, muted: c.muted, faint: mix(hexRgb(c.muted), bg, 0.3), title: c.text, accent: c.accent };
  }
  function syncThemes(a) {
    Object.keys(THEMES).forEach(function (k) { if (k.indexOf('c-') === 0) delete THEMES[k]; });
    (a.custom_themes || []).forEach(function (t) { var p = customPalette(t); THEMES[t.id] = { name: t.name, dark: p, light: p, force: t.mode, custom: t }; });
  }
  function lookMode(a) {
    var t = THEMES[a.theme] || THEMES.default;
    if (t.force) return t.force;
    if (!t.light) return 'dark';
    if (a.mode === 'system') return DARK_OS && DARK_OS.matches ? 'light' : 'dark';
    return a.mode === 'light' ? 'light' : 'dark';
  }
  function themeAccent(a) { var t = THEMES[a.theme] || THEMES.default; return (t[lookMode(a)] || t.dark).accent; }
  function applyLook(a) {
    a = a || S.L.appearance;
    syncThemes(a);
    var t = THEMES[a.theme] || THEMES.default, mode = lookMode(a), p = t[mode] || t.dark, st = STATUS[mode], light = mode === 'light';
    root.setAttribute('data-theme', THEMES[a.theme] ? a.theme : 'default');
    root.setAttribute('data-mode', mode);
    root.style.colorScheme = mode;          // native controls, dropdown lists and scrollbars follow the theme
    root.setAttribute('data-density', a.density);
    root.classList.toggle('no-sec-names', a.sec_names === false);
    root.classList.toggle('translucent', !!a.translucent);
    root.classList.toggle('mesh', a.gradient !== false);
    root.style.setProperty('--mesh', meshCss(a.theme, mode));
    root.setAttribute('data-motion', a.motion || 'full');
    var set = function (k, v) { root.style.setProperty(k, v); };
    var look = function (k, d) { return a[k] === undefined ? d : a[k]; };
    set('--g-bl', look('glass_blur', 26) + 'px'); set('--g-op', look('glass_opacity', 42) + '%'); set('--g-sat', look('glass_saturation', 170) + '%'); set('--g-exp', look('glass_exposure', 100) / 100); set('--g-con', look('glass_contrast', 100) / 100);
    if (S.L && boardOn() && a.theme === 'default') p = Object.assign({}, p, light ? BOARD_LIGHT : BOARD_DARK);
    if (S.L && boardOn()) set('--radius', Math.max(0, Math.min(28, a.radius === undefined ? 8 : +a.radius || 0)) + 'px'); else root.style.removeProperty('--radius');
    [['--bg', 'bg'], ['--panel', 'panel'], ['--panel-2', 'p2'], ['--bar', 'bar'], ['--field', 'field'], ['--line', 'line'], ['--line-2', 'l2'], ['--text', 'text'], ['--muted', 'muted'], ['--faint', 'faint'], ['--title', 'title']]
      .forEach(function (x) { set(x[0], p[x[1]]); });
    ['ok', 'warn', 'bad', 'info', 'off'].forEach(function (k) {
      var v = p[k] || st[k]; set('--' + k, v);
      if (k !== 'info' && k !== 'off') set('--' + k + '-soft', 'rgba(' + hexRgb(v).join(',') + ',' + (light ? 0.12 : 0.14) + ')');
    });
    set('--shadow', light ? '0 1px 2px rgba(16,24,40,.06)' : a.theme === 'amoled' ? 'none' : '0 1px 2px rgba(0,0,0,.25)');
    var acc = a.accent || p.accent, c = hexRgb(acc), L1 = lum(c);
    set('--accent', acc);
    set('--accent-btn', mix(c, [0, 0, 0], light ? 0.06 : 0.12));
    set('--accent-ink', L1 > 0.45 ? '#111' : '#fff');
    set('--accent-soft', 'rgba(' + c.join(',') + ',' + (light ? 0.12 : 0.16) + ')');
    // link text must stay readable on the panel colour: darken on light, lighten on dark
    set('--accent-text', light ? (L1 > 0.25 ? mix(c, [0, 0, 0], 0.35) : acc) : (L1 < 0.2 ? mix(c, [255, 255, 255], 0.35) : mix(c, [255, 255, 255], 0.12)));
    if (window.UND_STANDALONE) document.body.style.background = p.bg;
    var wall = a.daily && a.daily.on && S.wall && S.wall.url ? S.wall.url : a.background;
    root.classList.toggle('has-bg', !!wall);
    // wallpaper blur: the whole picture, or only what shows through the (then see-through) cards
    // the margin around the dashboard: automatic, or what Settings > Theme says
    ['x', 'y'].forEach(function (k) { var v = a['safe_' + k]; if (v === undefined || v < 0) root.style.removeProperty('--safe-' + k); else root.style.setProperty('--safe-' + k, v + 'px'); });
    var glass = !!wall && a.blur_target === 'cards';
    root.classList.toggle('glass', glass);
    set('--glass-blur', (glass ? a.blur : 0) + 'px');
    root.classList.toggle('full', S.full && !window.UND_STANDALONE);
    var bg = null;
    for (var i = 0; i < root.children.length; i++) if (root.children[i].classList.contains('und-bg')) bg = root.children[i];
    wallCredit(wall && wall === (S.wall || {}).url ? S.wall : null);
    if (wall) {
      if (!bg) { bg = h('div', { class: 'und-bg' }); root.insertBefore(bg, root.firstChild); }
      bg.style.backgroundImage = 'url("' + wall.replace(/["\\()]/g, '') + '")';
      bg.style.filter = (glass ? '' : 'blur(' + a.blur + 'px) ') + 'brightness(' + a.brightness + '%) saturate(' + (a.wall_saturation === undefined ? 100 : a.wall_saturation) + '%) contrast(' + (a.wall_contrast === undefined ? 100 : a.wall_contrast) + '%)';
      bg.style.opacity = (a.wall_opacity === undefined ? 100 : a.wall_opacity) / 100;
      bg.style.inset = glass ? '0' : '-' + (a.blur * 2) + 'px';
    } else if (bg) bg.parentNode.removeChild(bg);
  }
  /* these photos are free to use when the author and licence are named, with a link back */
  function wallCredit(w) {
    var old = root.querySelector('.wcredit'); if (old) old.remove();
    if (w) root.appendChild(h('div', { class: 'wcredit' }, h('a', { href: w.link, target: '_blank', rel: 'noopener' }, 'Photo' + (w.by ? ' by ' + w.by : '')), (w.license ? ' · ' + w.license : '') + ' · Wikimedia Commons'));
  }
  /* today's photo; the server asks Wikimedia Commons at most once a day, the page looks again every hour for the new day */
  function loadWall() {
    clearTimeout(S.wallT);
    if (!S.L.appearance.daily || !S.L.appearance.daily.on) { if (S.wall) { S.wall = null; applyLook(); } return; }
    api('wallpaper').then(function (j) {
      S.wall = j.url ? j : null; S.wallErr = j.error || '';
      if (!S.drawer) applyLook();
      S.wallT = setTimeout(loadWall, 3600000);
    });
  }
  // "system" follows the OS switching between light and dark while the page is open
  if (DARK_OS) { var osChange = function () { if (S.L && S.L.appearance && S.L.appearance.mode === 'system' && !S.drawer) applyLook(); }; if (DARK_OS.addEventListener) DARK_OS.addEventListener('change', osChange); else DARK_OS.addListener(osChange); }
  /* custom CSS is nested under .und (CSS nesting), so it styles UnDash only; it is text, never markup */
  function applyCss(css) {
    var ap = S.L && (S.drawer && S.drawer.cfg ? S.drawer.cfg.appearance : S.L.appearance), ct = ap && THEMES[ap.theme] && THEMES[ap.theme].custom;
    if (ct && ct.css) css = ct.css + '\n' + (css || '');
    var st = document.getElementById('und-custom-css');
    if (!css) { if (st) st.parentNode.removeChild(st); return; }
    if (!st) { st = document.createElement('style'); st.id = 'und-custom-css'; document.head.appendChild(st); }
    st.textContent = '.und{\n' + String(css).replace(/<\/?style/gi, '') + '\n}';
  }

  /* ================= shell ================= */
  function logo() {
    var s = document.createElementNS(SVGNS, 'svg'); s.setAttribute('viewBox', '0 0 64 64'); s.setAttribute('class', 'logo'); s.setAttribute('aria-hidden', 'true');
    [['0', '0', '64', '64', '14', 'var(--accent)', '1'], ['12', '12', '18', '24', '4', 'var(--accent-ink)', '1'], ['34', '12', '18', '12', '4', 'var(--accent-ink)', '.85'], ['34', '28', '18', '24', '4', 'var(--accent-ink)', '1'], ['12', '40', '18', '12', '4', 'var(--accent-ink)', '.85']].forEach(function (r) {
      var e = document.createElementNS(SVGNS, 'rect'); e.setAttribute('x', r[0]); e.setAttribute('y', r[1]); e.setAttribute('width', r[2]); e.setAttribute('height', r[3]); e.setAttribute('rx', r[4]); e.setAttribute('fill', r[5]); e.setAttribute('opacity', r[6]); s.appendChild(e);
    });
    return s;
  }

  var R = {};      // references to live parts of the page
  function shell() {
    closeMenu(true);
    alertClose(); clear(root); R = {}; toastBox = null;
    applyLook();
    applyCss(S.boot.css);
    var g = S.L.general;
    R.live = h('div', { class: 'live', role: 'status' }, h('span', { class: 'dot' }), R.liveTxt = h('span', null, 'Connecting…'),
      R.pause = btn('', togglePause, 'ghost sm', 'pause', 'Pause live refresh'));
    var ph = searchPlaceholder();
    R.search = ph ? h('input', { type: 'search', placeholder: ph + '  ( / )', 'aria-label': ph, value: S.q, role: 'combobox', 'aria-controls': 'und-sbox', 'aria-expanded': 'false', 'aria-autocomplete': 'list', autocomplete: 'off',
      oninput: function () { S.q = R.search.value; searchRun(); },
      onfocus: function () { if (S.q.trim() && R.sbox.hidden) searchRun(); },
      onkeydown: searchKey }) : null;
    R.bell = boardOn() ? h('button', { type: 'button', class: 'bell', hidden: true, 'aria-haspopup': 'dialog', 'aria-expanded': 'false', onclick: alertPop }, ic('bell'), R.bellT = h('span', { class: 'bt' })) : null;
    var acts = h('div', { class: 'acts' }, R.bell, R.live,
      R.srvBtn = btn('', function (e) { serverMenu(e.currentTarget); }, '', 'server', 'Server actions'),
      window.UND_STANDALONE ? null : btn('', toggleFull, 'focus' + (S.full ? ' on' : ''), 'full', S.full ? 'Exit focus mode' : 'Focus mode (hide Unraid\'s menu)'),
      R.priv = btn('', togglePrivacy, 'priv', 'eye', 'Blur titles, posters, torrents and the server name'),
      boardOn() ? btn('', boardEdit, 'bedit', 'edit', 'Edit layout') : null,
      btn('', openSettings, '', 'gear', 'Settings'));
    R.srvBtn.setAttribute('aria-haspopup', 'menu');
    paintPrivacy();
    root.appendChild(h('header', { class: 'top' },
      h('div', { class: 'brand' }, logo(), h('div', { class: 'ell' }, h('h1', { class: 'und-name' }, g.title || 'UnDash'),
        R.sub = h('div', { class: 'und-sub' }))),
      R.search ? h('div', { class: 'search' }, ic('search'), R.search, R.sbox = h('div', { class: 'sbox', id: 'und-sbox', hidden: true, role: 'listbox', 'aria-label': 'Search results' })) : h('span', { class: 'grow' }), acts));
    var board = boardOn(), want = board ? boardWants() : null;
    root.classList.toggle('bui', board);
    if (board) boardScale();
    R.sections = h('main', { class: 'sections' + (board ? ' host' : '') }); R.noMatch = null;
    root.appendChild(R.sections);
    S.L.dashboard.sections.forEach(function (sec) {
      if (board) sec.collapsed = false;                  // a card has no collapsed state; the saved one is kept for the classic layout
      if (sec.visible && (!board || want[sec.id])) buildSection(sec);
    });
    if (!R.sections.childNodes.length) R.sections.appendChild(h('div', { class: 'sec card empty' }, 'Every section is hidden. Turn some on in Settings > Sections & apps.'));
    if (R.bell) R.bell.hidden = !R.alerts;
    if (R.bell && R.alerts && R.health) R.alerts.tools.insertBefore(btn('Endpoints', function () { alertClose(); endpointsDialog(); }, 'ghost sm', 'web', 'Every address UnDash checks, and how it answered'), R.alerts.tools.firstChild);
    if (board && (window.UND_STANDALONE || S.full)) root.appendChild(h('div', { class: 'edgef', 'aria-hidden': 'true' }));
    if (board) boardMount(); else packHalves();
    paintAll(); paintLive(); paintIdent();
    if (!S.selfT) { S.selfT = 1; checkSelf(); }
    if (S.wallFor !== JSON.stringify(S.L.appearance.daily)) { S.wallFor = JSON.stringify(S.L.appearance.daily); loadWall(); }
  }

  /* privacy: blurs what you may not want on a screen share or a screenshot, on this browser only */
  function paintPrivacy() {
    var on = !!LS.get('privacy', false);
    root.classList.toggle('privacy', on);
    if (!R.priv) return;
    R.priv.classList.toggle('on', on); R.priv.setAttribute('aria-pressed', on ? 'true' : 'false');
    var t = on ? 'Show sensitive details' : 'Blur titles, posters, torrents and the server name';
    R.priv.title = t; R.priv.setAttribute('aria-label', t);
    clear(R.priv).appendChild(ic(on ? 'eyeoff' : 'eye'));
  }
  /* what scrolls away fades out under the header */
  function edgeScroll() { root.classList.toggle('scrolled', window.scrollY > 6); }
  window.addEventListener('scroll', edgeScroll, { passive: true });
  function togglePrivacy() { LS.set('privacy', !LS.get('privacy', false)); paintPrivacy(); }

  /* ---- title bar: server, Unraid version and UnDash version, each with a badge when a newer one exists ---- */
  function checkSelf() { api('self_update').then(function (j) { if (!j.error || j.latest) { S.self = j; paintIdent(); } setTimeout(checkSelf, 6 * 3600 * 1000); }); }
  function paintIdent() {
    if (!R.sub) return;
    var y = S.live || {}, s = y.system && !y.system.error ? y.system : null, b = S.boot.server || {};
    var name = (s && s.name) || b.name || '', ip = (s && s.ip) || b.ip || '', os = (s && s.os) || b.os || '', ou = (s && s.os_update) || {};
    var me = S.self || {};
    var sig = JSON.stringify([name, ip, os, ou.available, ou.latest, ou.staged, me.newer, me.latest]);
    if (R.sub._sig === sig) return; R.sub._sig = sig;
    clear(R.sub);
    if (name) R.sub.appendChild(h('span', { class: 'vchip', title: ip && ip !== name ? ip : null }, name));
    if (os) {
      var osUp = ou.staged ? ['restart for ' + ou.staged, 'Unraid ' + ou.staged + ' is installed; it becomes active after a restart'] : ou.available ? [ou.latest + ' available', 'Unraid ' + ou.latest + ' is available'] : null;
      R.sub.appendChild(h('span', { class: 'vchip' }, 'Unraid ' + os, osUp ? h('button', { type: 'button', class: 'upd', title: osUp[1], onclick: function () { if (S.boot.caps.os_update) osDialog(); else window.open((UNRAID || '') + '/Tools/Update', '_blank', 'noopener'); } }, ic('up'), osUp[0]) : null));
    }
    R.sub.appendChild(h('span', { class: 'vchip' }, 'UnDash ' + S.boot.version, me.newer ? h('a', { class: 'upd', href: me.url, target: '_blank', rel: 'noopener',
      title: 'UnDash ' + me.latest + ' is available. ' + (S.boot.caps.mode === 'api' ? 'Update the UnDash container in Unraid\'s Docker tab.' : 'Update it under Plugins in Unraid.') }, ic('up'), me.latest + ' available') : null));
  }

  /* ---- the card board: every card can be moved and resized in Edit layout. The sections are built as always and their parts
     moved into cards, so the live refresh paints them wherever they are. Grid: 24 columns (12 on a tablet), a row every 20 px;
     phones stack the cards in order. Removed cards stay in dashboard.board.hidden, so a new app still shows up by itself. ---- */
  var BOARD_ROW = 20, BOARD_GAP = 10, BOARD_COLS = { lg: 24, md: 12 };
  var BOARD_DARK = { bg: '#101114', panel: '#1b1c20', p2: '#141518', bar: '#26272c', field: '#131417', line: '#2c2d33', l2: '#3c3d44', text: '#f2f2f4', muted: '#a3a4ad', faint: '#74757f', accent: '#ec6a35' };
  var BOARD_LIGHT = { bg: '#eceef1', panel: '#ffffff', p2: '#f4f5f7', bar: '#ebedf0', field: '#ffffff', line: '#dcdfe4', l2: '#c8ccd3', text: '#1d1d1f', muted: '#5f6168', faint: '#86878f', accent: '#d9531e' };
  var BOARD_TABS = [['home', 'Home', 'server'], ['media', 'Media', 'play'], ['apps', 'Apps', 'grid'], ['docker', 'Docker', 'box']];
  function boardTab(s) { return { media: 'media', arr: 'apps', downloads: 'apps', apps: 'apps', docker: 'docker', vms: 'docker' }[s] || 'home'; }
  var MD = window.matchMedia ? window.matchMedia('(max-width:1100px)') : null, SM = window.matchMedia ? window.matchMedia('(max-width:700px)') : null;
  function boardOn() { return !!(S.L.dashboard.board && S.L.dashboard.board.on); }
  function boardMode() { return SM && SM.matches ? 'sm' : MD && MD.matches ? 'md' : 'lg'; }
  /* every card that exists now, in the classic order */
  function boardCards() {
    var out = [], d = S.L.dashboard, api = S.boot.caps.mode === 'api';
    d.sections.forEach(function (sec) {
      if (!sec.visible || sec.id === 'alerts' || sec.id === 'health') return;     // alerts live in the header, and a dead endpoint is an alert
      if (sec.id === 'overview') d.metrics.forEach(function (m) { if (m.visible && MET[m.id] && !((m.id === 'load' || m.id === 'path') && api)) out.push({ i: 'm:' + m.id, t: MET[m.id][0], s: 'overview', w: 3, h: 5, minw: 2, minh: 3 }); });
      else if (sec.id === 'media') { var vis = {}; S.L.media.widgets.forEach(function (w) { vis[w.id] = w.visible; }); MEDIA_ORDER.forEach(function (id) { if (vis[id] !== false) out.push({ i: 'media:' + id, t: MEDIA[id][0], s: 'media', w: 6, h: 18, minw: 3, minh: 6 }); }); }
      else if (sec.id === 'apps') S.L.groups.forEach(function (g) { g.services.forEach(function (x) {
        if (x.zone !== 'apps' || x.hidden || (x.type === 'link' && !S.L.apps.links)) return;
        out.push({ i: 'app:' + x.id, t: x.name, s: 'apps', link: x.type === 'link', w: x.type === 'link' ? 4 : 4, h: x.type === 'link' ? 3 : 7, minw: 2, minh: 3 });
      }); });
      else {
        var size = { favorites: [24, 8], arr: [12, 20], downloads: [12, 16], playing: [12, 10], requests: [12, 16], docker: [24, 24], vms: [12, 10] }[sec.id] || [12, 10];
        out.push({ i: sec.id, t: TITLES[sec.id], s: sec.id, w: size[0], h: size[1], minw: 4, minh: 3, maxh: sec.id === 'docker' ? 100 : 60 });
      }
    });
    return out;
  }
  /* which sections to build: System and Apps always (their parts are painted even off the board), the others when a card needs them */
  function boardWants() {
    var bd = S.L.dashboard.board, gone = {}, want = { overview: 1, alerts: 1, health: 1 }; (bd.hidden || []).forEach(function (i) { gone[i] = 1; });
    boardCards().forEach(function (c) { if (c.s === 'apps' || !gone[c.i]) want[c.s] = 1; });
    return want;
  }
  function gHit(a, b) { return a.i !== b.i && a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h; }
  function gCopy(l) { return l.map(function (c) { return { i: c.i, x: c.x, y: c.y, w: c.w, h: c.h, a: !!c.a }; }); }
  /* the first free spot, top left first */
  function gPlace(list, c, cols) {
    c.w = Math.min(c.w, cols);
    for (var y = 0; y < 6000; y++) for (var x = 0; x + c.w <= cols; x++) {
      c.x = x; c.y = y;
      if (!list.some(function (o) { return gHit(c, o); })) { list.push(c); return c; }
    }
    return c;
  }
  /* cards rise until something stops them; on equal rows the one in hand ($first) goes first */
  function gCompact(list, first) {
    var done = [];
    list.slice().sort(function (a, b) { return a.y - b.y || (a.i === first ? -1 : b.i === first ? 1 : a.x - b.x); }).forEach(function (c) {
      while (done.some(function (o) { return gHit(c, o); })) c.y++;       // a card that grew may sit on the next one
      while (c.y > 0) { c.y--; if (done.some(function (o) { return gHit(c, o); })) { c.y++; break; } }
      done.push(c);
    });
    return list;
  }
  /* every gap goes to the cards around it: first sideways, then down, so a row always ends at the edge and neighbours end level */
  function gStretch(list, cols) {
    var band = function (a, b) { return a.y < b.y + b.h && b.y < a.y + a.h; }, over = function (a, b) { return a.x < b.x + b.w && b.x < a.x + a.w; };
    for (var pass = 0; pass < 2; pass++) {
    list.slice().sort(function (a, b) { return b.x - a.x; }).forEach(function (c) {
      var right = cols; list.forEach(function (o) { if (o !== c && band(c, o) && o.x >= c.x + c.w) right = Math.min(right, o.x); });
      c.w = right - c.x;
    });
    list.slice().sort(function (a, b) { return a.x - b.x; }).forEach(function (c) {
      var left = 0; list.forEach(function (o) { if (o !== c && band(c, o) && o.x + o.w <= c.x) left = Math.max(left, o.x + o.w); });
      c.w += c.x - left; c.x = left;
    });
    var bottom = list.reduce(function (m, c) { return Math.max(m, c.y + c.h); }, 0);
    list.slice().sort(function (a, b) { return b.y - a.y; }).forEach(function (c) {
      var next = bottom; list.forEach(function (o) { if (o !== c && over(c, o) && o.y >= c.y + c.h) next = Math.min(next, o.y); });
      c.h = next - c.y;
    });
    }
    return list;
  }
  /* the card goes where asked; the card it covers most swaps into the spot just left ($from) when it fits there, anything else moves down */
  function gMove(list, id, to, compact, cols, from) {
    var c = list.filter(function (x) { return x.i === id; })[0]; if (!c) return list;
    c.w = Math.max(1, Math.min(cols, to.w)); c.h = Math.max(1, to.h); c.x = Math.max(0, Math.min(cols - c.w, to.x)); c.y = Math.max(0, to.y);
    var hits = list.filter(function (o) { return gHit(c, o); });
    if (from && hits.length) {
      var area = function (z) { return (Math.min(c.x + c.w, z.x + z.w) - Math.max(c.x, z.x)) * (Math.min(c.y + c.h, z.y + z.h) - Math.max(c.y, z.y)); };
      var o = hits.reduce(function (m, z) { return area(z) > area(m) ? z : m; }), was = { x: o.x, y: o.y };
      o.x = Math.min(from.x, cols - o.w); o.y = from.y;
      if (gHit(o, c) || list.some(function (z) { return gHit(o, z); })) { o.x = was.x; o.y = was.y; }
    }
    var queue = [c], n = 0;
    while (queue.length && n++ < 4000) {
      var a = queue.shift();
      list.forEach(function (o) { if (o !== c && gHit(a, o)) { o.y = a.y + a.h; queue.push(o); } });
    }
    return compact ? gCompact(list, id) : list;
  }
  /* Smart arrange: the classic dashboard's order, from the same settings. Sections in their saved order at their saved width; half-width
     ones go into the shorter of two columns, which then end level; System tiles, Media lists and apps fill even rows. Heights are the
     measured ones. A board left like this is worked out again whenever cards appear or change size. */
  function boardSmart(cards, cols) {
    var by = {}, out = [], H = function (c) { return S.nat[c.i] || c.h; }, half = cols / 2, tight = cols === 12 && window.innerWidth < 900;
    cards.forEach(function (c) { (by[c.s] = by[c.s] || []).push(c); });
    /* rows of at most k, shared out evenly: seven apps are rows of 4 and 3, never 6 and one stretched across the board */
    function rows(list, k, x0, width, y) {
      var n = 0, count = Math.ceil(list.length / k);
      for (var r = 0; r < count; r++) {
        var size = Math.ceil((list.length - n) / (count - r)), row = list.slice(n, n + size), each = Math.floor(width / size), extra = width - each * size, x = x0;
        var tall = Math.max.apply(null, row.map(H));
        row.forEach(function (c, j) { var w = each + (j < extra ? 1 : 0); out.push({ i: c.i, x: x, y: y, w: w, h: tall, a: true }); x += w; });
        y += tall; n += size;
      }
      return y;
    }
    function block(id, x0, width, y) {
      var list = by[id], wide = width === cols;
      if (id === 'overview') return rows(list, cols === 24 ? 8 : tight ? 4 : 6, x0, width, y);
      if (id === 'media') return rows(list, wide && !tight ? 4 : 2, x0, width, y);
      if (id === 'apps') {
        var tiles = list.filter(function (c) { return !c.link; }), links = list.filter(function (c) { return c.link; });
        return rows(links, wide ? (cols === 24 ? 6 : 3) : 2, x0, width, rows(tiles, wide ? (cols === 24 ? 6 : 3) : 2, x0, width, y));
      }
      return rows(list, 1, x0, width, y);
    }
    var secs = S.L.dashboard.sections.filter(function (x) { return by[x.id]; }), y = 0, i = 0;
    while (i < secs.length) {
      if (secs[i].width !== 'half' || tight) { y = block(secs[i].id, 0, cols, y); i++; continue; }
      var col = [y, y], first = out.length;
      for (; i < secs.length && secs[i].width === 'half'; i++) { var c = col[0] <= col[1] ? 0 : 1; col[c] = block(secs[i].id, c * half, half, col[c]); }
      // the shorter column's last row takes up the difference
      var low = col[0] < col[1] ? 0 : 1, end = Math.max(col[0], col[1]);
      out.slice(first).forEach(function (o) { if (o.x >= low * half && o.x < (low + 1) * half && o.y + o.h === col[low]) o.h += end - col[low]; });
      y = end;
    }
    return out;
  }
  function boardSeed(cards, cols) { return boardSmart(cards, cols); }
  /* the saved layout for this screen size, without cards that no longer exist and with new ones added */
  function boardLayout(mode, hidden) {
    var bd = S.L.dashboard.board, cols = BOARD_COLS[mode === 'sm' ? 'lg' : mode], cards = boardCards(), have = {}, gone = {};
    cards.forEach(function (c) { have[c.i] = c; }); hidden.forEach(function (i) { gone[i] = 1; });
    var src = bd[mode === 'sm' ? 'lg' : mode] || [];
    if (!src.length && mode === 'md' && (bd.lg || []).length) {
      var half = []; gCopy(bd.lg).sort(function (a, b) { return a.y - b.y || a.x - b.x; }).forEach(function (c) {
        c.w = Math.max(2, Math.ceil(c.w / 2)); gPlace(half, c, cols);
      });
      src = half;
    }
    var list = gCopy(src).filter(function (c) { return have[c.i] && !gone[c.i]; }), on = {};
    if (!list.length) return boardSeed(cards.filter(function (c) { return !gone[c.i]; }), cols);
    list.forEach(function (c) { on[c.i] = 1; c.w = Math.min(c.w, cols); c.x = Math.min(c.x, cols - c.w); });
    cards.forEach(function (c) { if (!on[c.i] && !gone[c.i]) gPlace(list, { i: c.i, x: 0, y: 0, w: Math.min(c.w, cols), h: c.h, a: true }, cols); });
    return list;
  }
  function boardPart(c) {
    var p = c.i.split(':'), r = R[c.s];
    if (p[0] === 'm') return R.overview && R.overview.tiles[p[1]] ? R.overview.tiles[p[1]].el : null;
    if (p[0] === 'media') return r && r.w[p[1]] ? r.w[p[1]].el : null;
    if (p[0] === 'app') return R.sections.querySelector('[data-sid="' + p[1] + '"]');
    return r ? r.el : null;
  }
  function boardMount() {
    var bd = S.L.dashboard.board, mode = boardMode(), E = S.edit;
    R.packs = []; R.cards = {};
    if (mode === 'sm' && E) { S.edit = E = null; }
    var hidden = E ? E.hidden : (bd.hidden || []).slice(), list = E ? E.list : boardLayout(mode, hidden);
    R.grid = list;
    R.board = h('div', { class: 'board' + (mode === 'sm' ? ' flow' : '') + (E ? ' edit' : '') });
    R.board.style.setProperty('--cols', String(BOARD_COLS[mode === 'sm' ? 'lg' : mode]));
    if (mode === 'sm') list = gCopy(list).sort(function (a, b) { return a.y - b.y || a.x - b.x; });
    var cards = {}; boardCards().forEach(function (c) { cards[c.i] = c; });
    if (R.overview) { R.overview.open = ''; R.overview.detail.hidden = true; }
    list.forEach(function (it) {
      var c = cards[it.i], part = c && boardPart(c); if (!part) return;
      var el = h('div', { class: 'gc gc-' + it.i.split(':')[0] + (part.classList.contains('alink') ? ' gc-l' : ''), 'data-i': it.i, 'data-tab': boardTab(c.s) }, h('div', { class: 'gc-c' }, part));
      if (part.classList && part.classList.contains('sec')) part.classList.add('fill');
      R.cards[it.i] = { el: el, part: part, c: c };
      if (E) boardChrome(el, it, c);
      R.board.appendChild(el);
    });
    R.boardWrap = h('div', { class: 'bwrap' }, E ? boardBar() : null, R.overview ? R.overview.stale : null, R.board);
    root.insertBefore(R.boardWrap, R.sections);
    S.boardHide = ''; S.boardNat = '';
    if (mode === 'sm') boardNav();
    boardPaint();
    boardAutoSoon();
    boardEnter();
  }
  /* the cards arrive one after the other, once per page load; any click or key skips it */
  function boardEnter() {
    var m = S.L.appearance.motion || 'full';
    if (S.entered || m === 'off' || m === 'nointro') { S.entered = true; return; }
    S.entered = true;
    var b = R.board; b.classList.add('enter');
    [].forEach.call(b.querySelectorAll('.gc'), function (e, i) { e.style.setProperty('--i', Math.min(i, 24)); });
    var done = function () { b.classList.remove('enter'); document.removeEventListener('pointerdown', done, true); document.removeEventListener('keydown', done, true); };
    document.addEventListener('pointerdown', done, true); document.addEventListener('keydown', done, true);
    setTimeout(done, 1100);
  }
  /* phones: one tab at a time, not one very long page */
  function boardNav() {
    var have = {}; Object.keys(R.cards).forEach(function (i) { have[R.cards[i].el.getAttribute('data-tab')] = 1; });
    var tabs = BOARD_TABS.filter(function (t) { return have[t[0]]; }); if (!tabs.length) return;
    if (!have[S.tab]) S.tab = have[LS.get('tab', '')] ? LS.get('tab', '') : tabs[0][0];
    R.nav = {};
    R.boardWrap.appendChild(h('nav', { class: 'mnav', 'aria-label': 'Sections' }, tabs.map(function (t) {
      return R.nav[t[0]] = h('button', { type: 'button', onclick: function () { boardTabSet(t[0]); window.scrollTo(0, 0); } }, ic(t[2]), h('span', null, t[1]));
    })));
    boardTabSet(S.tab);
  }
  function boardTabSet(id) {
    S.tab = id; LS.set('tab', id);
    Object.keys(R.nav || {}).forEach(function (k) { R.nav[k].classList.toggle('on', k === id); R.nav[k].setAttribute('aria-current', k === id ? 'page' : 'false'); });
    Object.keys(R.cards).forEach(function (i) { var e = R.cards[i].el; e.classList.toggle('on', e.getAttribute('data-tab') === id); });
    boardFlow();
  }
  /* an odd last tile in a pair of columns takes the whole row */
  function boardFlow() {
    if (!R.nav) return;
    ['.gc-m', '.gc-l'].forEach(function (k) {
      var on = [].filter.call(R.board.querySelectorAll(k), function (e) { e.classList.remove('wide'); return e.classList.contains('on') && !e.hidden; });
      if (on.length % 2) on[on.length - 1].classList.add('wide');
    });
  }
  /* search results and shortcuts jump to a section: on a phone it may be on another tab */
  function boardReveal(id) { if (R.nav && R.cards[id]) boardTabSet(R.cards[id].el.getAttribute('data-tab')); }
  /* text follows the screen: the whole board is scaled as one, so a 1440p monitor is not all tiny text and a laptop not all cramped.
     Sizes read from the page are screen pixels and go through boardTs() to become board pixels. */
  function boardTs() { return parseFloat(root.style.getPropertyValue('--ts')) || 1; }
  function boardScale() {
    var w = window.innerWidth, m = boardMode(), cl = function (v, a, b) { return Math.max(a, Math.min(b, v)); };
    var auto = m === 'lg' ? cl(w / 1600, 0.86, 1.35) : m === 'md' ? cl(w / 1000, 0.9, 1.05) : 1;
    root.style.setProperty('--ts', (auto * ({ s: 0.9, l: 1.12 }[S.L.appearance.text_size] || 1)).toFixed(3));
  }
  /* cards marked automatic are as tall as their content: measured together in one go, after data arrives or the screen changes */
  function boardAuto() {
    if (!R.cards || !R.board || S.edit || S.dragging || boardMode() === 'sm') return;
    var keep = [].slice.call(R.board.querySelectorAll('.mlist,.aiss')), tops = keep.map(function (l) { return l.scrollTop; }), sig = [], ts = boardTs();
    R.board.classList.add('measuring');
    R.grid.forEach(function (c) {
      var x = R.cards[c.i]; if (!c.a || !x || x.el.hidden) return;
      var n = Math.max(x.c.minh, Math.min(x.c.maxh || 60, Math.ceil((x.el.getBoundingClientRect().height / ts + BOARD_GAP) / BOARD_ROW)));
      S.nat[c.i] = n; sig.push(n);
    });
    R.board.classList.remove('measuring');
    keep.forEach(function (l, i) { if (l.scrollTop !== tops[i]) l.scrollTop = tops[i]; });
    sig = sig.join();
    if (sig !== S.boardNat) { S.boardNat = sig; boardPaint(); }
  }
  function boardAutoSoon() { clearTimeout(S.autoT); S.autoT = setTimeout(boardAuto, 250); }
  if (window.ResizeObserver) new ResizeObserver(function () { if (!R.cards) return; boardScale(); boardAutoSoon(); if (R.overview && R.overview.open) boardDetail(); }).observe(document.documentElement);
  function boardQuiet(p) { return p.hidden || p.style.display === 'none'; }
  /* a card with nothing to say (no alerts) leaves the board for now, and the ones under it close the gap */
  function boardSync() {
    var sig = Object.keys(R.cards).filter(function (i) { return boardQuiet(R.cards[i].part); }).join();
    if (sig !== S.boardHide) { S.boardHide = sig; boardPaint(); }
    if (Date.now() - (S.autoAt || 0) > 4000) { S.autoAt = Date.now(); boardAutoSoon(); }
  }
  function boardPaint() {
    var E = S.edit, list = R.grid, compact = E ? E.compact : S.L.dashboard.board.compact;
    if (!E) {
      list = gCopy(R.grid).filter(function (c) { return !(R.cards[c.i] && boardQuiet(R.cards[c.i].part)); });
      if (compact) {
        list.forEach(function (c) { if (c.a && S.nat[c.i]) c.h = S.nat[c.i]; });
        // a board left to Smart arrange is worked out again with the real heights, so it has no holes
        var bd = S.L.dashboard.board, mode = boardMode();
        if (mode !== 'sm' && !(bd[mode] || []).length && !(bd.lg || []).length) {
          var on = {}; list.forEach(function (c) { on[c.i] = 1; });
          list = boardSmart(boardCards().filter(function (c) { return on[c.i]; }), BOARD_COLS[mode]);
        } else if (mode !== 'sm') gStretch(gCompact(list), BOARD_COLS[mode]);
      }
    }
    var seen = {}, bottom = 0;
    list.forEach(function (c) {
      var x = R.cards[c.i]; if (!x) return; seen[c.i] = 1;
      x.el.hidden = false;
      x.el.style.setProperty('--x', c.x); x.el.style.setProperty('--y', c.y); x.el.style.setProperty('--w', c.w); x.el.style.setProperty('--h', c.h);
      x.el.setAttribute('aria-label', x.c.t + (E ? ', column ' + (c.x + 1) + ', row ' + (c.y + 1) + ', ' + c.w + ' by ' + c.h : ''));
      bottom = Math.max(bottom, c.y + c.h);
    });
    Object.keys(R.cards).forEach(function (i) { if (!seen[i]) R.cards[i].el.hidden = true; });
    R.board.style.setProperty('--rows', bottom);
    if (E) boardTools(); else boardFlow();
  }
  /* the System tiles are cards, so a tile's details open under it */
  function boardDetail() {
    var r = R.overview, d = r.detail, t = r.tiles[r.open];
    if (d.parentNode !== R.boardWrap) { d.classList.add('bdetail'); R.boardWrap.appendChild(d); }
    if (!r.open || !t) return;
    if (boardMode() === 'sm') { d.style.top = d.style.left = d.style.width = ''; return; }
    var ts = boardTs(), w = R.boardWrap.getBoundingClientRect(), b = t.el.getBoundingClientRect(), wide = Math.min(760, w.width / ts);
    d.style.top = (b.bottom - w.top) / ts + 6 + 'px';
    d.style.left = Math.max(0, Math.min((b.left - w.left) / ts, w.width / ts - wide)) + 'px';
    d.style.width = wide + 'px';
  }
  document.addEventListener('pointerdown', function (e) {
    var r = R.overview; if (!R.cards || !r || !r.open || !e.target.closest) return;
    if (!e.target.closest('.bdetail') && !e.target.closest('.gc-m')) toggleDetail(r.open);
  });

  /* ---- Edit layout: drag a card, drag its edges, or use the keyboard; nothing is saved until Save ---- */
  function boardEdit() {
    if (boardMode() === 'sm') { toast('Edit the layout on a larger screen. A phone shows the cards in that order.', true); return; }
    var bd = S.L.dashboard.board;
    S.edit = { mode: boardMode(), hidden: (bd.hidden || []).slice(), list: null, compact: bd.compact, undo: [], redo: [], dirty: false, smart: !(bd[boardMode()] || []).length && !(bd.lg || []).length };
    S.edit.list = boardLayout(S.edit.mode, S.edit.hidden);
    S.edit.list.forEach(function (c) { if (c.a && S.nat[c.i]) c.h = S.nat[c.i]; });
    if (S.edit.compact) gCompact(S.edit.list);
    boardRemount();
  }
  function boardRemount() {
    if (R.boardWrap && R.boardWrap.parentNode) {
      // the parts go back first: they stay in the page's own host while their cards are rebuilt
      Object.keys(R.cards).forEach(function (i) { var p = R.cards[i].part; if (p) R.sections.appendChild(p); });
      if (R.overview) R.sections.appendChild(R.overview.stale);
      if (R.overview) R.sections.appendChild(R.overview.detail);
      R.boardWrap.parentNode.removeChild(R.boardWrap);
    }
    boardMount(); paintAll();
  }
  function boardCols() { return BOARD_COLS[S.edit.mode]; }
  function boardMark() { var E = S.edit; E.undo.push({ list: gCopy(E.list), hidden: E.hidden.slice(), smart: E.smart }); if (E.undo.length > 60) E.undo.shift(); E.redo = []; E.dirty = true; E.smart = false; }
  function boardStep(from, to) {
    var E = S.edit, st = from.pop(); if (!st) return;
    to.push({ list: gCopy(E.list), hidden: E.hidden.slice(), smart: E.smart });
    var rebuild = st.hidden.join() !== E.hidden.join();
    E.list = st.list; E.hidden = st.hidden; E.smart = st.smart; E.dirty = true;
    if (rebuild) boardRemount(); else { R.grid = E.list; boardPaint(); }
  }
  function boardBar() {
    var E = S.edit;
    R.bUndo = btn('', function () { boardStep(E.undo, E.redo); }, 'sm', 'undo', 'Undo (Ctrl+Z)');
    R.bRedo = btn('', function () { boardStep(E.redo, E.undo); }, 'sm', 'redo', 'Redo (Ctrl+Shift+Z)');
    R.bAdd = btn('Add card', boardAdd, 'sm', 'plus', 'Put a removed card back');
    var snap = h('input', { type: 'checkbox', checked: E.compact, onchange: function (e) { boardMark(); E.compact = e.target.checked; if (E.compact) gCompact(E.list); boardPaint(); } });
    return h('div', { class: 'bbar', role: 'toolbar', 'aria-label': 'Edit layout' }, h('b', null, 'Edit layout'), h('span', { class: 'muted xs bhint' }, 'Drag cards and their edges. Arrow keys move, Shift + arrows resize.'), h('span', { class: 'grow' }),
      R.bAdd, R.bUndo, R.bRedo, h('label', { class: 'switch', title: 'Cards rise to fill the space above them. Off: they stay exactly where you put them.' }, snap, 'Snap up'),
      btn('Smart arrange', boardArrange, 'sm pri', 'grid', 'Arrange every card in the classic dashboard\'s order, and keep it arranged as cards change'),
      btn('Fit all', boardFitAll, 'sm', 'fit', 'Size every card to its content'),
      btn('Reset', boardReset, 'sm', 'restart', 'Back to the standard layout, with every card'),
      btn('Cancel', boardCancel, 'sm'), btn('Save', boardSave, 'sm pri'));
  }
  function boardTools() {
    var E = S.edit; if (!R.bUndo) return;
    R.bUndo.disabled = !E.undo.length; R.bRedo.disabled = !E.redo.length; R.bAdd.disabled = !E.hidden.length;
  }
  function boardChrome(el, it, c) {
    el.tabIndex = 0;
    el.appendChild(h('div', { class: 'gc-t' }, h('span', { class: 'ell' }, c.t),
      it.i.indexOf('app:') === 0 ? btn('', function () { boardIcon(it.i.slice(4), c.t); }, 'sm ghost', 'tag', 'Change the icon') : null,
      btn('', function () { boardFit([it.i]); }, 'sm ghost', 'fit', 'Fit the height to the content'),
      btn('', function () { boardRemove(it.i); }, 'sm ghost', 'x', 'Remove from the board')));
    ['e', 's', 'se'].forEach(function (d) { el.appendChild(h('span', { class: 'rh ' + d, 'data-rh': d, 'aria-hidden': 'true' })); });
    el.addEventListener('pointerdown', boardPointer);
    el.addEventListener('keydown', boardKey);
  }
  function boardIcon(id, name) {
    iconPicker(name, function (v) {
      api('prefs', { kind: 'service', key: id, value: { icon: v } }).then(function (j) { if (j.error) { toast(j.error, true); return; } S.L = j.layout; shell(); });
    });
  }
  function boardItem(i) { return S.edit.list.filter(function (c) { return c.i === i; })[0]; }
  function boardRemove(i) {
    var E = S.edit; boardMark();
    E.list = E.list.filter(function (c) { return c.i !== i; }); E.hidden.push(i);
    if (E.compact) gCompact(E.list);
    boardRemount();
  }
  function boardAdd() {
    var E = S.edit, all = {}, m; boardCards().forEach(function (c) { all[c.i] = c; });
    var rows = E.hidden.filter(function (i) { return all[i]; }).map(function (i) {
      return h('button', { type: 'button', class: 'badd', onclick: function () {
        m.close(); boardMark();
        E.hidden = E.hidden.filter(function (x) { return x !== i; });
        gPlace(E.list, { i: i, x: 0, y: 0, w: Math.min(all[i].w, boardCols()), h: all[i].h }, boardCols());
        boardRemount();
        var x = R.cards[i]; if (x) { x.el.scrollIntoView({ block: 'center', behavior: 'smooth' }); x.el.focus({ preventScroll: true }); }
      } }, ic('plus'), all[i].t);
    });
    m = modal('Add a card', rows.length ? h('div', { class: 'badds' }, rows) : h('div', { class: 'empty' }, 'Every card is on the board.'), [btn('Close', function () { m.close(); })]);
  }
  /* the height the card's content wants, in rows */
  function boardNatural(i) {
    var x = R.cards[i]; if (!x) return 0;
    R.board.classList.add('measuring'); var px = x.el.getBoundingClientRect().height / boardTs(); R.board.classList.remove('measuring');
    return Math.max(x.c.minh, Math.min(x.c.maxh || 60, Math.ceil((px + BOARD_GAP) / BOARD_ROW)));
  }
  function boardFit(ids) {
    var E = S.edit; boardMark();
    ids.forEach(function (i) { var c = boardItem(i), n = boardNatural(i); if (c && n) { c.a = true; gMove(E.list, i, { x: c.x, y: c.y, w: c.w, h: n }, E.compact, boardCols()); } });
    boardPaint();
  }
  function boardFitAll() { boardFit(Object.keys(R.cards).filter(function (i) { return i.indexOf('m:') !== 0; })); }
  function boardArrange() {
    var E = S.edit, gone = {}; boardMark(); E.hidden.forEach(function (i) { gone[i] = 1; });
    E.list = boardSmart(boardCards().filter(function (c) { return !gone[c.i]; }), boardCols()); E.smart = true; E.compact = true;
    boardRemount();
  }
  function boardReset() {
    var E = S.edit; boardMark();
    E.hidden = []; E.list = boardSeed(boardCards(), boardCols()); E.smart = true;
    boardRemount();
  }
  function boardCancel() {
    var done = function () { S.edit = null; boardRemount(); };
    if (!S.edit.dirty) return done();
    confirmBox('Discard your layout changes?', 'The board goes back to how it was saved.', 'Discard', true).then(function (y) { if (y) done(); });
  }
  function boardSave() {
    var E = S.edit, v = { hidden: E.hidden, compact: E.compact }; v[E.mode] = E.list;
    if (E.smart) { v.lg = []; v.md = []; }              // an empty layout means: keep arranging it automatically
    api('prefs', { kind: 'board', value: v }).then(function (j) {
      if (j.error) { toast(j.error, true); return; }
      S.L = j.layout; S.edit = null; shell(); toast('Layout saved');
    });
  }
  function boardKey(e) {
    var d = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key], i = e.currentTarget.getAttribute('data-i');
    if (e.target !== e.currentTarget) return;
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); boardRemove(i); return; }
    if (!d) return;
    e.preventDefault(); boardMark();
    var c = boardItem(i), rows = e.shiftKey ? 1 : 2, to = e.shiftKey ? { x: c.x, y: c.y, w: Math.max(R.cards[i].c.minw, c.w + d[0]), h: Math.max(R.cards[i].c.minh, c.h + d[1]) }
      : { x: c.x + d[0], y: c.y + d[1] * rows, w: c.w, h: c.h };
    gMove(S.edit.list, i, to, S.edit.compact, boardCols());
    if (e.shiftKey && d[1]) c.a = false;                // a height set by hand stays
    boardPaint();
  }
  document.addEventListener('keydown', function (e) {
    if (!S.edit || !(e.ctrlKey || e.metaKey) || (e.key || '').toLowerCase() !== 'z' || root.querySelector('.modal-bg')) return;
    e.preventDefault();
    if (e.shiftKey) boardStep(S.edit.redo, S.edit.undo); else boardStep(S.edit.undo, S.edit.redo);
  });
  /* one drag: the list is worked out again from where it started, so cards pushed aside return when the card moves on */
  function boardPointer(e) {
    if (e.button || e.target.closest('button')) return;
    var E = S.edit, el = e.currentTarget, i = el.getAttribute('data-i'), it = boardItem(i), rh = e.target.getAttribute('data-rh');
    if (!it) return;
    var start = gCopy(E.list), c0 = { x: it.x, y: it.y, w: it.w, h: it.h }, card = R.cards[i].c, cols = boardCols();
    var ts = boardTs(), cw = (R.board.getBoundingClientRect().width / ts + BOARD_GAP) / cols;
    var sx = e.clientX, sy = e.clientY, sy0 = window.scrollY, moved = false, last = '', px = 0, py = 0, tm = 0, ph = null;
    el.setPointerCapture(e.pointerId);
    function calc() {
      var dx = (px - sx) / ts, dy = (py - sy + window.scrollY - sy0) / ts, to = { x: c0.x, y: c0.y, w: c0.w, h: c0.h };
      if (rh) {
        if (rh !== 's') to.w = Math.max(card.minw, Math.min(cols - c0.x, c0.w + Math.round(dx / cw)));
        if (rh !== 'e') to.h = Math.max(card.minh, c0.h + Math.round(dy / BOARD_ROW));
      } else { to.x = c0.x + Math.round(dx / cw); to.y = c0.y + Math.round(dy / BOARD_ROW); }
      if (!rh) el.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
      var sig = [to.x, to.y, to.w, to.h].join();
      if (sig === last) return; last = sig;
      E.list = gCopy(start); R.grid = E.list;
      gMove(E.list, i, to, E.compact, cols, rh ? null : c0);
      if (rh && to.h !== c0.h) boardItem(i).a = false;
      boardPaint();
      var now = boardItem(i);
      ['x', 'y', 'w', 'h'].forEach(function (k) { ph.style.setProperty('--' + k, now[k]); });
      if (!rh) { var f = R.cards[i].el; f.style.setProperty('--x', c0.x); f.style.setProperty('--y', c0.y); }
    }
    function edge() {
      var v = py < 70 ? -14 : py > window.innerHeight - 70 ? 14 : 0;
      if (v) { window.scrollBy(0, v); calc(); }
      tm = requestAnimationFrame(edge);
    }
    function move(ev) {
      px = ev.clientX; py = ev.clientY;
      if (!moved) {
        if (Math.abs(px - sx) + Math.abs(py - sy) < 5) return;
        moved = true; S.dragging = true; boardMark(); E.undo[E.undo.length - 1].list = gCopy(start);
        ph = h('div', { class: 'gph' }); R.board.appendChild(ph);
        el.classList.add('drag'); root.classList.add('dragging'); tm = requestAnimationFrame(edge);
      }
      calc();
    }
    function up(ev) {
      el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', up);
      cancelAnimationFrame(tm); root.classList.remove('dragging'); S.dragging = false;
      if (!moved) return;
      if (ph && ph.parentNode) ph.parentNode.removeChild(ph);
      el.classList.remove('drag'); el.style.transform = '';
      if (ev.type === 'pointercancel') { E.undo.pop(); E.list = start; R.grid = start; }
      boardPaint();
    }
    el.addEventListener('pointermove', move); el.addEventListener('pointerup', up); el.addEventListener('pointercancel', up);
  }
  if (MD) [MD, SM].forEach(function (q) { if (q.addEventListener) q.addEventListener('change', function () { if (R.cards && S.L) { if (S.edit && boardMode() === S.edit.mode) return; S.edit = null; boardRemount(); } }); });

  /* Half-width sections next to each other go into two columns, each section into the shorter one (in order), so a short
     section does not leave a hole beside a tall one. Re-balanced when content loads or a section collapses. */
  var NARROW = window.matchMedia ? window.matchMedia('(max-width:1100px)') : null;
  function packHalves() {
    if (S.packRO) S.packRO.disconnect();
    R.packs = [];
    var kids = [].slice.call(R.sections.children), run = [];
    function flush() {
      if (run.length >= 2) {
        var w = h('div', { class: 'hcols' }), c0 = h('div', { class: 'hcol' }), c1 = h('div', { class: 'hcol' });
        R.sections.insertBefore(w, run[0]); w.appendChild(c0); w.appendChild(c1);
        run.forEach(function (el, i) { (i % 2 ? c1 : c0).appendChild(el); });
        R.packs.push({ w: w, cols: [c0, c1], items: run.slice() });
      }
      run = [];
    }
    kids.forEach(function (el) { if (el.classList.contains('half')) run.push(el); else flush(); });
    flush();
    if (!R.packs.length || !window.ResizeObserver) return;
    S.packRO = new ResizeObserver(function () { clearTimeout(S.packT); S.packT = setTimeout(balanceHalves, 150); });
    R.packs.forEach(function (p) { p.items.forEach(function (el) { S.packRO.observe(el); }); });
    balanceHalves();
  }
  function balanceHalves() {
    (R.packs || []).forEach(function (p) {
      // measuring resets the media lists for a moment; keep where the user scrolled them
      var lists = [].slice.call(p.w.querySelectorAll('.mlist')), tops = lists.map(function (l) { return l.scrollTop; });
      balancePack(p);
      lists.forEach(function (l, i) { if (l.scrollTop !== tops[i]) l.scrollTop = tops[i]; });
    });
  }
  function balancePack(p) {
    var one = NARROW && NARROW.matches, gap = 16, hh = [0, 0], want = [];
    p.w.classList.toggle('one', !!one);
    unlevel(p);                                           // measure the natural heights, not the stretched ones
    var now = p.items.map(function (el) { return el.parentNode === p.cols[1] ? 1 : 0; });
    // sections hidden by the filter take no room and stay where they are
    p.items.forEach(function (el, i) { var h0 = el.offsetHeight; if (!h0) { want.push(now[i]); return; } var c = one ? 0 : hh[0] <= hh[1] ? 0 : 1; want.push(c); hh[c] += h0 + gap; });
    var move = want.join() !== now.join();
    if (move && !one) {
      // only move when it clearly helps, so sections do not jump back and forth
      var cur = [0, 0]; p.items.forEach(function (el, i) { cur[now[i]] += el.offsetHeight + gap; });
      if (Math.max(hh[0], hh[1]) > Math.max(cur[0], cur[1]) - 40) move = false;
    }
    if (move) p.items.forEach(function (el, i) { p.cols[want[i]].appendChild(el); });
    if (!one) { pairTop(p); level(p); }
  }
  /* The first sections of the two columns sit side by side (Media beside Arr Stack, say): the shorter one grows to the
     taller one's height, when that does not stretch it out of shape (media lists can always show more). */
  function pairTop(p) {
    p.paired = null;
    var a = firstOpen(p.cols[0]), b = firstOpen(p.cols[1]); if (!a || !b) return;
    p.paired = a.getAttribute('data-sec') === 'media' ? a : b.getAttribute('data-sec') === 'media' ? b : null;
    var ha = a.offsetHeight, hb = b.offsetHeight, short = ha < hb ? a : b, tall = Math.max(ha, hb), diff = Math.abs(ha - hb);
    if (diff < 4) return;
    if (short.getAttribute('data-sec') === 'media') { if (growMedia(short, diff)) return; }
    if (short.offsetHeight < tall * 0.55) { p.paired = null; return; }
    short.classList.add('fill'); short.style.minHeight = tall + 'px';
  }
  function firstOpen(col) { return [].filter.call(col.children, function (el) { return el.offsetHeight > 0; })[0]; }
  /* every row of media columns gets its share of the extra height; lists in a row share one height */
  function growMedia(media, diff) {
    var rows = {}, lists = [].slice.call(media.querySelectorAll('.mlist'));
    lists.forEach(function (l) { var t = Math.round(l.getBoundingClientRect().top); (rows[t] = rows[t] || []).push(l); });
    var keys = Object.keys(rows); if (!keys.length) return false;
    var add = diff / keys.length;
    keys.forEach(function (k) {
      var tall = Math.max.apply(null, rows[k].map(function (l) { return l.offsetHeight; }));
      rows[k].forEach(function (l) { l.style.maxHeight = 'none'; l.style.height = Math.round(tall + add) + 'px'; });
    });
    return true;
  }
  /* Smart height: the shorter column ends level with the taller one. Media in the shorter column shows more items
     (its lists grow); otherwise the column's last box stretches. */
  function unlevel(p) {
    p.items.forEach(function (el) {
      el.classList.remove('fill'); el.style.minHeight = '';
      [].forEach.call(el.querySelectorAll('.mlist'), function (l) { l.style.height = ''; l.style.maxHeight = ''; });
    });
  }
  function level(p) {
    var h0 = p.cols[0].offsetHeight, h1 = p.cols[1].offsetHeight, diff = Math.abs(h0 - h1);
    var short = p.cols[h0 < h1 ? 0 : 1], kids = [].slice.call(short.children);
    if (diff < 4 || !kids.length) return;
    // media matched to the section beside it keeps that height; the column's last box takes the rest
    var media = kids.filter(function (el) { return el.getAttribute('data-sec') === 'media' && !el.classList.contains('closed') && p.paired !== el; })[0];
    if (media && growMedia(media, diff)) return;
    var last = kids.filter(function (el) { return !el.classList.contains('closed'); }).pop();
    if (last) { last.classList.add('fill'); last.style.minHeight = last.offsetHeight + diff + 'px'; }
  }
  if (NARROW) (NARROW.addEventListener ? NARROW.addEventListener('change', function () { balanceHalves(); }) : NARROW.addListener(balanceHalves));

  /* ---- search box: a list under it with the apps, containers and VMs that match, a web search and movies and series to request
     (Overseerr / Jellyseerr). What it searches is set in Settings > Refresh & display; the dashboard itself stays as it is. ---- */
  var ENGINES = { duckduckgo: ['DuckDuckGo', 'https://duckduckgo.com/?q='], google: ['Google', 'https://www.google.com/search?q='], bing: ['Bing', 'https://www.bing.com/search?q='],
    startpage: ['Startpage', 'https://www.startpage.com/do/search?q='], brave: ['Brave Search', 'https://search.brave.com/search?q='] };
  function hasSeerr() { var f = null; S.L.groups.forEach(function (g) { g.services.forEach(function (s) { if (!f && s.type === 'overseerr') f = s; }); }); return f; }
  function searchIn(k) { var g = S.L.general; return (g.search_in || []).indexOf(k) >= 0 && (k !== 'web' || !!ENGINES[g.search]) && (k !== 'seerr' || !!hasSeerr()); }
  function searchPlaceholder() {
    var p = [searchIn('apps') ? 'apps' : '', searchIn('seerr') ? 'movies' : '', searchIn('web') ? 'the web' : ''].filter(Boolean);
    return p.length ? 'Search ' + p.join(', ').replace(/, ([^,]+)$/, ' or $1') : '';
  }
  function searchClose(reset) {
    if (!R.sbox) return;
    R.sbox.hidden = true; R.search.setAttribute('aria-expanded', 'false');
    if (reset) { R.search.value = ''; S.q = ''; clear(R.sbox); S.seerrSeq = (S.seerrSeq || 0) + 1; }
  }
  function searchRun() {
    var box = R.sbox, q = S.q.trim();
    clearTimeout(S.seerrT); S.seerrSeq = (S.seerrSeq || 0) + 1;
    if (!q) { searchClose(true); return; }
    clear(box);
    if (searchIn('apps')) {
      var hits = localHits(q);
      box.appendChild(h('div', { class: 'sb-h' }, 'Apps, containers and VMs'));
      if (hits.length) hits.forEach(function (x) { box.appendChild(x); }); else box.appendChild(h('div', { class: 'empty' }, 'Nothing called "' + q + '".'));
    }
    if (searchIn('web')) {
      var E = ENGINES[S.L.general.search];
      box.appendChild(sbItem(h('span', { class: 'aico' }, ic('web')), ['Search the web for "' + q + '"', E[0]], function () { window.open(E[1] + encodeURIComponent(q), '_blank', 'noopener'); }));
    }
    if (searchIn('seerr') && q.length >= 2) {
      var seq = S.seerrSeq, part = h('div', null, h('div', { class: 'sb-h' }, 'Searching ' + hasSeerr().name + '…'));
      box.appendChild(part);
      S.seerrT = setTimeout(function () { seerrSearch(q, seq, part); }, 400);
    }
    box.hidden = false; R.search.setAttribute('aria-expanded', 'true');
    searchSel(box.querySelector('.sb-i'));
  }
  /* apps by name (best matches first), then containers and VMs; hidden ones are left out */
  function localHits(q) {
    var lq = q.toLowerCase(), y = S.live || {}, out = [];
    var score = function (n) { n = String(n || '').toLowerCase(); var i = n.indexOf(lq); return i < 0 ? -1 : i === 0 ? 0 : 1; };
    var put = function (n, el) { var sc = score(n); if (sc >= 0) out.push([sc, out.length, el]); };
    S.L.groups.forEach(function (g) { g.services.forEach(function (s) {
      if (s.hidden || score(s.name) < 0) return;
      var href = s.href_resolved || '';
      put(s.name, sbItem(img(iconUrl(s.icon), 'aico', 'web'), [s.name, (TITLES[s.zone] || 'Apps') + (href ? ' · ' + href.replace(/^https?:\/\//, '').replace(/\/$/, '') : '')],
        function () { if (href) window.open(href, '_blank', 'noopener'); else sbJump(s.zone, s.name, 'w-' + s.id); }, href ? null : sbBtn('Show on the dashboard', 'eye', function () { sbJump(s.zone, s.name, 'w-' + s.id); })));
    }); });
    ((y.docker && y.docker.containers) || []).forEach(function (c) {
      var p = pref('docker', c.name), nm = p.label || c.name, web = dWeb(c);
      if (p.hidden || (score(nm) < 0 && score(c.name) < 0)) return;
      put(score(nm) >= 0 ? nm : c.name, sbItem(cIcon(c, p, 'aico'), [nm, 'Container · ' + c.state],
        function () { if (web) window.open(web, '_blank', 'noopener'); else inspect(c.name); },
        h('span', { class: 'sb-acts' }, web ? sbBtn('Open web UI', 'web', function () { window.open(web, '_blank', 'noopener'); }) : null,
          c.state === 'running' ? sbBtn('Stop', 'stop', function () { dAct(c, 'stop'); }) : c.state === 'paused' ? null : sbBtn('Start', 'play', function () { dAct(c, 'start'); }),
          c.state !== 'paused' ? sbBtn('Restart', 'restart', function () { dAct(c, 'restart'); }) : null,
          sbBtn('Logs', 'logs', function () { logs(c.name); }), sbBtn('Details', 'info', function () { inspect(c.name); }))));
    });
    ((y.vms && y.vms.vms) || []).forEach(function (v) {
      var p = pref('vms', v.name), nm = p.label || v.name, web = vWeb(v);
      if (p.hidden || (score(nm) < 0 && score(v.name) < 0)) return;
      put(score(nm) >= 0 ? nm : v.name, sbItem(vIcon(v, p, 'aico'), [nm, 'VM · ' + v.state],
        function () { if (web) window.open(web, '_blank', 'noopener'); else sbJump('vms', v.name); },
        h('span', { class: 'sb-acts' }, web ? sbBtn(vConsole(web) ? 'Open console (VNC)' : 'Open Unraid\'s VMs page', 'screen', function () { vOpen(web); }) : null,
          ['start', 'resume', 'stop', 'restart'].filter(function (o) { return (v.ops || []).indexOf(o) >= 0 && VMOPS[o]; }).map(function (o) { return sbBtn(VMOPS[o][0], VMOPS[o][1], function () { vAct(v, o); }); }))));
    });
    return out.sort(function (a, c) { return a[0] - c[0] || a[1] - c[1]; }).slice(0, 8).map(function (x) { return x[2]; });
  }
  /* one result: click or Enter runs go(); buttons in it do their own thing */
  function sbItem(pic, lines, go, extra) {
    var el = h('div', { class: 'sb-r sb-i', role: 'option', 'aria-selected': 'false',
      onclick: function (e) { if (e.target.closest('button,a')) return; searchClose(true); go(); },
      onmousemove: function () { if (!el.classList.contains('sel')) searchSel(el); } },
      pic, h('div', { class: 'rqt' }, h('b', { class: 'ell', title: lines[0] }, lines[0]), lines.slice(1).map(function (l) { return typeof l === 'string' ? h('span', { class: 'muted xs ell' }, l) : l; })), extra || null);
    el._go = go;
    return el;
  }
  function sbBtn(title, icon, fn) { return btn('', function () { searchClose(true); fn(); }, 'sm ghost', icon, title); }
  function searchSel(el) {
    if (!R.sbox) return;
    [].forEach.call(R.sbox.querySelectorAll('.sb-i.sel'), function (x) { x.classList.remove('sel'); x.setAttribute('aria-selected', 'false'); });
    if (!el) return;
    el.classList.add('sel'); el.setAttribute('aria-selected', 'true');
    var b = R.sbox.getBoundingClientRect(), r = el.getBoundingClientRect();
    if (r.top < b.top || r.bottom > b.bottom) el.scrollIntoView({ block: 'nearest' });
  }
  function searchKey(e) {
    var box = R.sbox;
    if (e.key === 'Escape') { if (!box.hidden) { e.preventDefault(); searchClose(); } else searchClose(true); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (box.hidden) { if (S.q.trim()) searchRun(); return; }
      var all = [].slice.call(box.querySelectorAll('.sb-i')), i = all.indexOf(box.querySelector('.sb-i.sel'));
      if (all.length) searchSel(all[(i + (e.key === 'ArrowDown' ? 1 : all.length - 1)) % all.length]);
      return;
    }
    if (e.key === 'Enter') {
      var sel = !box.hidden && box.querySelector('.sb-i.sel');
      if (sel) { e.preventDefault(); searchClose(true); sel._go(); }
    }
  }
  /* bring an app, container or VM on the dashboard into view and highlight it for a moment */
  function sbJump(zone, name, id) {
    var sec = R.sections && R.sections.querySelector('section.sec[data-sec="' + zone + '"]');
    var el = (id && document.getElementById(id)) || (sec && [].filter.call(sec.querySelectorAll('[data-name]'), function (x) { return x.getAttribute('data-name') === name; })[0]) || sec;
    if (!el) { toast(name + ' is not on the dashboard (its section is hidden).'); return; }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash');
    setTimeout(function () { el.classList.remove('flash'); }, 1800);
  }
  function seerrSearch(q, seq, part) {
    api('seerr_search', { q: q }, 20000).then(function (j) {
      if (seq !== S.seerrSeq || !part.isConnected) return;            // a newer search is on its way
      clear(part).appendChild(h('div', { class: 'sb-h' }, (j.app ? j.app.name : 'Seerr') + ': movies and series to request'));
      if (j.error) { part.appendChild(h('div', { class: 'empty err-t' }, j.error)); return; }
      if (!j.items.length) { part.appendChild(h('div', { class: 'empty' }, 'No movie or series found.')); return; }
      j.items.forEach(function (x) { part.appendChild(seerrRow(j.app, x)); });
      if (!R.sbox.querySelector('.sb-i.sel')) searchSel(part.querySelector('.sb-i'));
    });
  }
  function seerrRow(app, x) {
    var title = x.title + (x.year ? ' (' + x.year + ')' : '');
    var action = x.status === 'none'
      ? btn('Request', function (e) {
          var b = e.currentTarget; b.disabled = true; txt(b, 'Requesting…');
          api('seerr_request', { app: app.id, type: x.type, id: x.id }).then(function (j) {
            if (j.error) { toast(j.error, true); b.disabled = false; clear(b).appendChild(ic('plus')); b.appendChild(document.createTextNode('Request')); return; }
            toast('Requested: ' + title + (x.type === 'tv' ? ' (all seasons)' : ''));
            b.replaceWith(h('span', { class: 'badge info' }, 'Requested'));
            pollKick('requests');
          });
        }, 'sm pri', 'plus', x.type === 'tv' ? 'Request every season of ' + title : 'Request ' + title)
      : h('span', { class: 'badge ' + (x.status === 'available' || x.status === 'partial' ? 'ok' : 'info') }, x.status_label);
    return sbItem(h('span', { class: 'rqp' }, x.poster ? h('img', { src: x.poster, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', onerror: function () { this.remove(); } }) : null),
      [title, x.type === 'tv' ? 'Series' : 'Movie', x.overview ? h('span', { class: 'muted xs sb-o' }, x.overview) : null].filter(Boolean),
      function () { if (x.href) window.open(x.href, '_blank', 'noopener'); }, action);
  }
  document.addEventListener('mousedown', function (e) { if (R.sbox && !R.sbox.hidden && !R.sbox.contains(e.target) && e.target !== R.search) searchClose(); });

  function toggleFull() { S.full = !S.full; LS.set('full', S.full); shell(); }

  var TITLES = { overview: 'System', playing: 'Now playing', requests: 'Requests', health: 'Endpoint health', alerts: 'Alerts', favorites: 'Favorites', media: 'Media', arr: 'Arr Stack', downloads: 'Downloads', docker: 'Containers', vms: 'Virtual machines', apps: 'Apps' };
  function buildSection(sec) {
    var tools = h('div', { class: 'tools' });
    var count = h('span', { class: 'count' });
    var body = h('div', { class: 'sec-b', id: 'und-sec-' + sec.id });
    var el = h('section', { class: 'sec' + (sec.width === 'half' ? ' half' : '') + (sec.collapsed ? ' closed' : ''), 'data-sec': sec.id, 'aria-label': TITLES[sec.id] },
      h('div', { class: 'sec-h' }, h('h2', null, h('button', { type: 'button', 'aria-expanded': sec.collapsed ? 'false' : 'true', 'aria-controls': 'und-sec-' + sec.id, onclick: function (e) {
        if (R.cards) return;
        sec.collapsed = !sec.collapsed; el.classList.toggle('closed', sec.collapsed); e.currentTarget.setAttribute('aria-expanded', sec.collapsed ? 'false' : 'true');
        api('prefs', { kind: 'section', key: sec.id, value: { collapsed: sec.collapsed } });
        if (!sec.collapsed) paintAll();
      } }, ic('chev'), TITLES[sec.id]), count), tools), body);
    R[sec.id] = { el: el, body: body, tools: tools, count: count, sec: sec };
    R.sections.appendChild(el);
    var b = BUILD[sec.id]; if (b) b(R[sec.id]);
  }
  function open(id) { return R[id] && !R[id].sec.collapsed; }

  /* which parts the live request needs, so hidden sections cost nothing */
  function parts() {
    var p = ['system'];
    if (R.docker || R.favorites || R.alerts || tileOn('docker')) p.push('docker');
    if (R.vms || R.favorites || tileOn('vms')) p.push('vms');
    if (tileOn('ups')) p.push('ups');
    return p.join(',');
  }
  function tileOn(id) { return !!(R.overview && R.overview.tiles && R.overview.tiles[id]); }

  /* ================= live refresh ================= */
  function schedule(ms) { clearTimeout(S.timer); S.timer = setTimeout(tick, ms); }
  function interval() {
    var base = Math.max(1, S.L.general.refresh) * 1000;
    if (S.fails) return Math.min(30000, base * Math.pow(2, Math.min(S.fails, 5)));
    if (document.hidden && S.L.general.background_poll === 'slow') return Math.max(30000, base);
    return base;
  }
  function tick() {
    if (S.paused || S.inflight || S.power) return;
    if (document.hidden && S.L.general.background_poll === 'pause') { S.waitVisible = true; return; }
    S.inflight = true;
    api('live', { parts: parts() }, 20000).then(function (r) {
      S.inflight = false;
      if (r.error) {
        S.fails++;
        S.conn = r.auth ? 'auth' : S.fails >= 3 ? 'down' : 'retry';
        S.connErr = r.error;
        if (R.alerts) paintAlerts(S.live || {});
      } else {
        S.fails = 0; S.conn = 'ok'; S.lastOk = Date.now(); S.live = r; resumeUpdates(); paintIdent();
        if (r.power && !S.power) powerOverlay(r.power);
        paintAll();
      }
      paintLive();
      if (!S.paused && S.conn !== 'auth') schedule(interval());
    });
  }
  function refreshNow() { if (!S.inflight && !S.paused) { clearTimeout(S.timer); tick(); } }
  function togglePause() {
    S.paused = !S.paused; LS.set('paused', S.paused);
    if (!S.paused) { refreshNow(); if (S.dlStale) loadDl(); } else clearTimeout(S.timer);
    paintLive();
  }
  function paintLive() {
    if (!R.live) return;
    R.live.className = 'live ' + (S.paused ? 'paused' : S.conn === 'ok' ? '' : S.conn === 'wait' || S.conn === 'retry' ? 'retry' : 'down');
    var t = S.paused ? 'Paused' + (S.lastOk ? ' · ' + clock(S.lastOk) : '')
      : S.conn === 'ok' ? 'Live · ' + clock(S.lastOk)
      : S.conn === 'wait' ? 'Connecting…'
      : S.conn === 'auth' ? 'Signed out'
      : S.conn === 'retry' ? 'Reconnecting…' : 'Offline · retrying';
    txt(R.liveTxt, t);
    R.live.title = (S.conn !== 'ok' && S.connErr ? S.connErr + ' ' : '') + 'Refreshes every ' + S.L.general.refresh + ' s' + (S.lastOk ? '; last update ' + clock(S.lastOk) : '');
    clear(R.pause).appendChild(ic(S.paused ? 'play' : 'pause'));
    R.pause.title = S.paused ? 'Resume live refresh' : 'Pause live refresh';
    R.pause.setAttribute('aria-label', R.pause.title);
  }
  document.addEventListener('visibilitychange', function () {
    if (!S.L) return;
    if (!document.hidden && (S.waitVisible || S.L.general.background_poll !== 'normal')) { S.waitVisible = false; refreshNow(); }
    if (!document.hidden && !S.svcRun) pollServices();
    if (!document.hidden && S.mediaStale) loadMedia();
    if (!document.hidden && S.arrStale) loadArr();
    if (!document.hidden && S.dlStale) loadDl();
    if (!document.hidden) Object.keys(POLL).forEach(function (k) { if (POLL[k].stale) pollRun(k); });
  });

  function paintAll() {
    var y = S.live || {};
    if (R.overview && open('overview')) paintOverview(y);
    if (R.alerts) paintAlerts(y);
    if (R.favorites && open('favorites')) paintFavs(y);
    if (R.docker && open('docker')) paintDocker(y);
    if (R.vms && open('vms')) paintVms(y);
    paintCounts(y);
    if (R.cards) boardSync();
    // ResizeObserver does not run in a background tab; the live refresh catches up instead
    if (R.packs && R.packs.length) balanceHalves();
  }
  function paintCounts(y) {
    if (R.docker && y.docker && y.docker.containers) {
      var c = y.docker.containers;
      txt(R.docker.count, c.filter(function (x) { return x.state === 'running'; }).length + ' of ' + c.length + ' running');
    }
    if (R.vms && y.vms && y.vms.vms) txt(R.vms.count, y.vms.vms.filter(function (v) { return v.state === 'running'; }).length + ' of ' + y.vms.vms.length + ' running');
  }

  /* ================= sections ================= */
  var BUILD = {};

  /* ---- overview: one compact stats bar; a tile opens its details underneath ---- */
  var MET = {
    cpu: ['CPU', 'cpu'], memory: ['Memory', 'mem'], storage: ['Storage', 'array'], network: ['Network', 'net'], health: ['Array health', 'server'], disks: ['Disk temps', 'temp'],
    uptime: ['Uptime', 'clock'], ups: ['UPS', 'bolt'], cores: ['CPU cores', 'cpu'], swap: ['Swap', 'swap'], load: ['Load', 'load'], path: ['Disk', 'disk'], docker: ['Containers', 'box'], vms: ['VMs', 'vm']
  };
  BUILD.overview = function (r) {
    r.bar = h('div', { class: 'stats', role: 'list' }); r.tiles = {};
    S.L.dashboard.metrics.forEach(function (m) {
      if (!m.visible || !MET[m.id]) return;
      if ((m.id === 'load' || m.id === 'path') && S.boot.caps.mode === 'api') return;     // not offered by the Unraid API
      var t = { id: m.id, v: h('span', { class: 'v' }, '–'), s: h('span', { class: 's' }), m: h('i'), sp: h('span', { class: 'sp', 'aria-hidden': 'true' }) };
      t.el = h('button', { type: 'button', class: 'stat', role: 'listitem', 'aria-expanded': 'false', onclick: function () { toggleDetail(m.id); } },
        t.sp, h('span', { class: 'k' }, ic(MET[m.id][1]), MET[m.id][0]), t.v, t.s, h('span', { class: 'bar', 'aria-hidden': 'true' }, t.m));
      r.tiles[m.id] = t; r.bar.appendChild(t.el);
    });
    r.detail = h('div', { class: 'detail', hidden: true });
    r.stale = h('div', { class: 'stale', hidden: true, role: 'status' });
    r.body.appendChild(r.stale); r.body.appendChild(r.bar); r.body.appendChild(r.detail);
    if (!r.bar.childNodes.length) r.bar.appendChild(h('div', { class: 'empty' }, 'No metrics are turned on (Settings > Sections & apps).'));
    r.open = LS.get('detail', '');
  };
  function toggleDetail(id) {
    var r = R.overview; r.open = r.open === id ? '' : id; LS.set('detail', r.open);
    for (var k in r.tiles) r.tiles[k].el.setAttribute('aria-expanded', k === r.open ? 'true' : 'false');
    r.detailSig = null; paintDetail(S.live || {});
    if (R.cards) boardDetail();
  }
  function tile(id, val, sub, p, lvl) {
    var t = R.overview.tiles[id]; if (!t) return;
    txt(t.v, val); txt(t.s, sub || '');
    t.m.style.width = (p === null || p === undefined ? 0 : Math.max(0, Math.min(100, p))) + '%';
    t.el.className = 'stat ' + (lvl || '') + (R.overview.open === id ? ' on' : '') + (t.sp.firstChild ? ' has-sp' : '');
  }
  /* a tile's history as an area chart behind the number (Grafana-style); the plain bar stays for tiles without history */
  /* hi null = scale to the data, never below floor (so a quiet 3% CPU still shows its shape without looking busy) */
  function sparkTile(id, vals, lo, hi, floor) {
    var t = R.overview.tiles[id]; if (!t) return;
    vals = vals.filter(function (v) { return v !== null && v !== undefined && isFinite(v); });
    var sig = vals.length + ':' + (vals[vals.length - 1] || 0) + ':' + lo + ':' + hi;
    if (t.spSig === sig) return; t.spSig = sig;
    clear(t.sp); t.el.classList.toggle('has-sp', vals.length > 1);
    if (vals.length < 2) return;
    if (hi === null) hi = Math.max(Math.max.apply(null, vals) * 1.25, floor || 1);
    var n = vals.length, sc = function (v) { return (40 - Math.max(0, Math.min(1, (v - lo) / ((hi - lo) || 1))) * 36).toFixed(2); };
    var d = 'M' + vals.map(function (v, i) { return (i / (n - 1) * 100).toFixed(2) + ',' + sc(v); }).join(' L');
    var sv = document.createElementNS(SVGNS, 'svg'); sv.setAttribute('viewBox', '0 0 100 40'); sv.setAttribute('preserveAspectRatio', 'none');
    var a = document.createElementNS(SVGNS, 'path'); a.setAttribute('class', 'a'); a.setAttribute('d', d + ' L100,40 L0,40 Z');
    var l = document.createElementNS(SVGNS, 'path'); l.setAttribute('class', 'l'); l.setAttribute('d', d); l.setAttribute('vector-effect', 'non-scaling-stroke');
    sv.appendChild(a); sv.appendChild(l); t.sp.appendChild(sv);
  }
  function lvl(p, w, b) { return p === null || p === undefined ? 'na' : p >= b ? 'bad' : p >= w ? 'warn' : 'ok'; }
  function paintOverview(y) {
    var r = R.overview, s = y.system;
    var stale = S.lastOk && Date.now() - S.lastOk > Math.max(10000, S.L.general.refresh * 3000);
    r.stale.hidden = !stale && S.conn === 'ok';
    // the first load: a moving line and placeholders on the board, words only when something is wrong
    var waiting = !S.lastOk && !S.connErr && !!R.cards;
    root.classList.toggle('waiting', waiting);
    if (waiting) r.stale.hidden = true;
    if (!r.stale.hidden) txt(r.stale, S.lastOk ? 'Stale: last update ' + clock(S.lastOk) + (S.connErr ? ' · ' + S.connErr : '') : (S.connErr || 'Waiting for data…'));
    r.bar.classList.toggle('is-stale', !!stale);
    if (!s) return;
    if (s.error) { r.stale.hidden = false; txt(r.stale, s.error); return; }
    var a = s.array || {}, pools = (a.pools || []).filter(function (p) { return p.size > 0; }), dk = dockerStats(y), vm = vmStats(y);
    var c = s.cpu.pct;
    tile('cpu', pct(c), (s.cpu.temp !== null && s.cpu.temp !== undefined ? Math.round(s.cpu.temp) + '°C · ' : '') + (s.cpu.threads ? s.cpu.threads + ' threads' : ''), c, s.cpu.temp >= 85 ? 'bad' : s.cpu.temp >= 75 ? 'warn' : lvl(c, 85, 97));
    tile('memory', pct(s.mem.pct), bytes(s.mem.free) + ' free of ' + bytes(s.mem.total), s.mem.pct, lvl(s.mem.pct, 85, 95));
    var st = a.size ? [a.size, a.free] : pools.length ? pools.reduce(function (acc, p) { return [acc[0] + p.size, acc[1] + p.free]; }, [0, 0]) : null;
    if (st) { var sp = 100 * (st[0] - st[1]) / st[0]; tile('storage', bytes(st[1]) + ' free', pct(sp) + ' of ' + bytes(st[0]) + (a.size ? ' · array' : ' · ' + pools.length + ' pools'), sp, lvl(sp, 85, 95)); }
    else tile('storage', 'n/a', a.state === 'STARTED' ? 'No sizes reported' : 'Array ' + (a.state || 'unknown').toLowerCase(), null, 'na');
    tile('network', s.net.rx === null ? 'n/a' : rate(s.net.rx) + ' ↓', s.net.tx === null ? (s.net.iface || '') : rate(s.net.tx) + ' ↑ · ' + s.net.iface, null, s.net.rx === null ? 'na' : '');
    var hl = a.state !== 'STARTED' ? 'warn' : a.bad || a.errors ? 'bad' : 'ok';
    tile('health', a.state !== 'STARTED' ? (a.state || 'unknown').toLowerCase() : a.bad ? a.bad + ' disk problem' + (a.bad > 1 ? 's' : '') : a.errors ? a.errors + ' errors' : 'Healthy',
      a.resync ? (a.resync_action || 'Parity check') + (a.resync_pct !== null ? ' ' + a.resync_pct + '%' : '') : (a.disks || 0) + ' disks · ' + (a.parity || 0) + ' parity · ' + pools.length + ' pools', a.resync_pct, hl);
    var temps = [], rows = a._rows ? [].concat(a._rows.parity, a._rows.array, a._rows.pools) : null;
    (rows || pools).forEach(function (d) { if (d.temp !== null && d.temp !== undefined) temps.push(d.temp); });
    if (S.storage) [].concat(S.storage.parity || [], S.storage.array || [], S.storage.pools || []).forEach(function (d) { if (d.temp !== null && d.temp !== undefined) temps.push(d.temp); });
    var hot = temps.length ? Math.max.apply(null, temps) : null;
    tile('disks', hot === null ? 'n/a' : hot + '°C', hot === null ? 'No disk temperatures (spun down?)' : 'hottest · ' + (a.hot ? a.hot + ' at 50°C+' : 'all below 50°C'), hot === null ? null : Math.min(100, hot / 60 * 100), hot === null ? 'na' : hot >= 55 ? 'bad' : hot >= 50 ? 'warn' : 'ok');
    tile('uptime', upt(s.uptime), s.uptime ? 'since ' + new Date((s.now - s.uptime) * 1000).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '', null, '');
    var u = y.ups;
    if (R.overview.tiles.ups) { R.overview.tiles.ups.el.hidden = !u; if (u) tile('ups', pct(u.charge), u.status + (u.load !== null ? ' · load ' + pct(u.load) + (u.watts !== null && u.watts !== undefined ? ' (' + u.watts + ' W)' : '') : u.watts ? ' · ' + u.watts + ' W' : '') + (u.runtime ? ' · ' + Math.round(u.runtime / 60) + ' min' : ''), u.charge, u.charge < 30 ? 'bad' : u.charge < 60 || !/online/i.test(u.status) ? 'warn' : 'ok'); }
    var cores = s.cpu.cores || [];
    tile('cores', cores.length ? pct(Math.max.apply(null, cores)) : 'n/a', cores.length ? 'busiest of ' + cores.length + ' cores' : '', cores.length ? Math.max.apply(null, cores) : null, cores.length ? lvl(Math.max.apply(null, cores), 90, 99) : 'na');
    tile('swap', s.mem.swap_total ? pct(100 * s.mem.swap_used / s.mem.swap_total) : 'None', s.mem.swap_total ? bytes(s.mem.swap_used) + ' of ' + bytes(s.mem.swap_total) : 'No swap configured', s.mem.swap_total ? 100 * s.mem.swap_used / s.mem.swap_total : null, s.mem.swap_total ? '' : 'na');
    if (s.cpu.load) tile('load', s.cpu.load[0].toFixed(2), '5 min ' + s.cpu.load[1].toFixed(2) + ' · 15 min ' + s.cpu.load[2].toFixed(2), 100 * s.cpu.load[0] / (s.cpu.threads || 1), '');
    if (s.disk) tile('path', s.disk.total ? bytes(s.disk.free) + ' free' : 'n/a', s.disk.total ? s.disk.path + ' · ' + pct(s.disk.pct) : s.disk.path + ' reports no size', s.disk.pct, s.disk.total ? lvl(s.disk.pct, 85, 95) : 'na');
    if (dk) tile('docker', dk.running + ' / ' + dk.total, 'running' + (dk.updates ? ' · ' + dk.updates + ' updates' : ''), 100 * dk.running / (dk.total || 1), dk.unhealthy ? 'bad' : '');
    if (vm) tile('vms', vm.running + ' / ' + vm.total, 'running', 100 * vm.running / (vm.total || 1), '');
    var hs = (s.hist || []).slice(-60);
    sparkTile('cpu', hs.map(function (x) { return x[1]; }), 0, null, 25);
    if (s.mem.total) sparkTile('memory', hs.map(function (x) { return x[2]; }), 0, s.mem.total);
    sparkTile('network', hs.map(function (x) { return (x[3] || 0) + (x[4] || 0); }), 0, null, 131072);
    // disk temperatures are not in the server history: keep the last few minutes here
    if (hot !== null && S.tempAt !== y.at) { S.tempAt = y.at; (S.tempHist = S.tempHist || []).push(hot); if (S.tempHist.length > 60) S.tempHist.shift(); }
    if (S.tempHist) sparkTile('disks', S.tempHist, 20, 60);
    paintDetail(y);
  }
  function paintDetail(y) {
    var r = R.overview, s = y.system, id = r.open;
    r.detail.hidden = !id || !s || !r.tiles[id];
    if (r.detail.hidden) { r.detailSig = null; return; }
    var hist = s.hist || [];
    if (id === 'storage' || id === 'health' || id === 'disks') {
      if (Date.now() - S.storageAt > 30000) loadStorage();
      var sig = JSON.stringify(S.storage); if (r.detailSig === sig) return; r.detailSig = sig;
      clear(r.detail).appendChild(diskTable());
      return;
    }
    clear(r.detail); r.detailSig = null;
    if (id === 'cpu' || id === 'cores') {
      var cores = s.cpu.cores || [];
      r.detail.appendChild(h('div', { class: 'dgrid' },
        h('div', null, h('div', { class: 'dk' }, 'CPU history'), sparkBox(hist.map(function (x) { return x[1]; }), 100)),
        h('div', null, h('div', { class: 'dk' }, cores.length ? 'Per core' : 'Per core (measuring…)'), h('div', { class: 'cores' }, cores.map(function (c, i) { return h('i', { title: 'Core ' + i + ': ' + c + '%', style: { height: Math.max(3, c) + '%' } }); }))),
        h('div', { class: 'kv2 sm' }, h('span', null, 'Model'), h('span', null, s.cpu.model || '-'), h('span', null, 'Threads'), h('span', null, String(s.cpu.threads || '-')),
          h('span', null, 'Temperature'), h('span', null, s.cpu.temp === null || s.cpu.temp === undefined ? 'no sensor' : Math.round(s.cpu.temp) + '°C'),
          s.cpu.load ? [h('span', null, 'Load'), h('span', null, s.cpu.load.join(' / '))] : null, h('span', null, 'Kernel'), h('span', null, s.kernel || '-'))));
    } else if (id === 'memory' || id === 'swap') {
      r.detail.appendChild(h('div', { class: 'dgrid' }, h('div', null, h('div', { class: 'dk' }, 'Memory used'), sparkBox(hist.map(function (x) { return x[2]; }), s.mem.total)),
        h('div', { class: 'kv2 sm' }, h('span', null, 'Used'), h('span', null, bytes(s.mem.used)), h('span', null, 'Available'), h('span', null, bytes(s.mem.free)), h('span', null, 'Total'), h('span', null, bytes(s.mem.total)),
          h('span', null, 'Swap'), h('span', null, s.mem.swap_total ? bytes(s.mem.swap_used) + ' of ' + bytes(s.mem.swap_total) : 'none'))));
    } else if (id === 'network') {
      var rx = hist.map(function (x) { return x[3] || 0; });
      r.detail.appendChild(h('div', { class: 'dgrid' }, h('div', null, h('div', { class: 'dk' }, 'Download, last ' + Math.round(hist.length * 2 / 60) + ' min'), sparkBox(rx, Math.max.apply(null, rx.concat([1024])))),
        h('div', { class: 'kv2 sm' }, h('span', null, 'Interface'), h('span', null, s.net.iface || '-'), h('span', null, 'Address'), h('span', null, s.ip || '-'), h('span', null, 'Down'), h('span', null, rate(s.net.rx)), h('span', null, 'Up'), h('span', null, rate(s.net.tx)))));
    } else {
      var o = s.os_update || {};
      r.detail.appendChild(h('div', { class: 'kv2 sm' }, h('span', null, 'Server'), h('span', null, s.name + ' · ' + s.ip), h('span', null, 'Unraid'), h('span', null, (s.os || '-') + (o.available ? ' · ' + o.latest + ' available' : '')),
        h('span', null, 'Uptime'), h('span', null, upt(s.uptime) + ' · since ' + new Date((s.now - s.uptime) * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })),
        y.ups ? [h('span', null, 'UPS'), h('span', null, (y.ups.model || '') + ' · ' + y.ups.status + ' · ' + pct(y.ups.charge) + (y.ups.watts ? ' · ' + y.ups.watts + ' W' + (y.ups.nominal ? ' of ' + y.ups.nominal + ' W' : '') : '') + (y.ups.runtime ? ' · ' + Math.round(y.ups.runtime / 60) + ' min runtime' : ''))] : null));
    }
  }
  function sparkBox(vals, max) {
    var s = document.createElementNS(SVGNS, 'svg'); s.setAttribute('class', 'spark2'); s.setAttribute('viewBox', '0 0 100 40'); s.setAttribute('preserveAspectRatio', 'none'); s.setAttribute('role', 'img');
    s.setAttribute('aria-label', vals.length ? 'Last ' + vals.length + ' samples' : 'No history yet');
    if (vals.length > 1) {
      var n = vals.length, d = 'M' + vals.map(function (v, i) { return (i / (n - 1) * 100).toFixed(2) + ',' + (40 - Math.max(0, Math.min(1, (v || 0) / (max || 1))) * 38).toFixed(2); }).join(' L');
      var a = document.createElementNS(SVGNS, 'path'); a.setAttribute('class', 'a'); a.setAttribute('d', d + ' L100,40 L0,40 Z');
      var l = document.createElementNS(SVGNS, 'path'); l.setAttribute('class', 'l'); l.setAttribute('d', d); l.setAttribute('vector-effect', 'non-scaling-stroke');
      s.appendChild(a); s.appendChild(l);
    }
    return s;
  }
  function diskTable() {
    var st = S.storage;
    if (!st) return h('div', { class: 'empty' }, 'Loading disks…');
    if (st.error) return h('div', { class: 'empty' }, st.error);
    var rows = [].concat((st.parity || []).map(function (d) { d.k = 'Parity'; return d; }), (st.array || []).map(function (d) { d.k = 'Array'; return d; }), (st.pools || []).map(function (d) { d.k = 'Pool'; return d; }));
    if (!rows.length) return h('div', { class: 'empty' }, 'No disk information. Is the array started?');
    return h('div', { class: 'tw' }, h('table', { class: 't' }, h('thead', null, h('tr', null, h('th', null, 'Disk'), h('th', null, 'Health'), h('th', null, 'Temp'), h('th', null, 'Usage'))),
      h('tbody', null, rows.map(function (d) {
        var p = d.size ? 100 * (d.size - d.free) / d.size : 0;
        return h('tr', null, h('td', null, h('b', null, d.name), h('span', { class: 'muted xs' }, ' ' + d.k + (d.device ? ' · ' + d.device : '') + (d.fs ? ' · ' + d.fs : ''))),
          h('td', null, h('span', { class: 'badge ' + (d.health === 'ok' ? 'ok' : d.health === 'warn' ? 'warn' : 'bad'), title: d.status }, d.health === 'ok' ? 'ok' : String(d.status).replace('DISK_', '').toLowerCase()), d.errors ? h('span', { class: 'badge bad' }, d.errors + ' errors') : null),
          h('td', { class: 'num' }, d.temp === null ? h('span', { class: 'muted' }, d.spun ? '-' : 'spun down') : h('span', { class: d.temp >= 55 ? 't-bad' : d.temp >= 50 ? 't-warn' : '' }, d.temp + '°C')),
          h('td', { style: { 'min-width': '160px' } }, d.size ? [h('div', { class: 'xs num' }, bytes(d.size - d.free) + ' / ' + bytes(d.size)), h('div', { class: 'meter' }, h('i', { class: meterCls(p), style: { width: p.toFixed(1) + '%' } }))] : h('span', { class: 'muted' }, '-')));
      }))));
  }
  function loadStorage() {
    if (S.storageBusy) return; S.storageBusy = true; S.storageAt = Date.now();
    api('storage').then(function (j) { S.storageBusy = false; S.storage = j; if (R.overview) { R.overview.detailSig = null; paintDetail(S.live || {}); } });
  }
  function portLink(cls) {
    if (window.UND_STANDALONE) return UNRAID ? h('a', { class: 'btn ' + cls, href: UNRAID + '/Dashboard', target: '_blank', rel: 'noopener' }, ic('web'), 'Open Unraid') : null;
    if (!S.boot.port_enabled) return null;
    return h('a', { class: 'btn ' + cls, href: '/plugins/undash/handoff.php', target: '_blank', rel: 'noopener', title: 'UnDash on its own page (port ' + S.boot.port + '), without Unraid\'s menu' }, ic('web'), 'Open on port ' + S.boot.port);
  }
  function serverMenu(from) {
    var dk = dockerStats(S.live || {}), caps = S.boot.caps;
    var pl = portLink('');
    menu(from, [
      ['Refresh now', 'restart', refreshNow, '', S.paused], [S.paused ? 'Resume live refresh' : 'Pause live refresh', S.paused ? 'play' : 'pause', togglePause], 'hr',
      caps.docker_check ? ['Check container updates', 'box', checkUpdates, '', !!S.busy.dcheck] : null, dk && dk.updates ? ['Update ' + dk.updates + ' container' + (dk.updates > 1 ? 's' : ''), 'up', function () { updateMany(dk.updList); }] : null,
      caps.os_update ? ['Unraid OS update', 'up', osDialog] : null, ['Recent operations', 'history', historyDialog], ['Keyboard shortcuts  ?', 'grid', keysHelp],
      pl ? ['hr'][0] : null, pl ? [pl.textContent, 'web', function () { window.open(pl.href, '_blank', 'noopener'); }] : null,
      window.UND_STANDALONE && S.boot.auth !== 'open' ? ['Sign out', 'x', function () { location.href = '/logout'; }] : null,
      caps.power ? 'hr' : null, caps.power ? ['Restart server…', 'restart', function () { powerDialog('reboot'); }, 'danger'] : null, caps.power ? ['Shut down server…', 'power', function () { powerDialog('shutdown'); }, 'danger'] : null]);
  }

  /* ---- alerts ---- */
  BUILD.alerts = function (r) {
    r.list = h('div', { class: 'alerts' }); r.body.appendChild(r.list);
    r.ign = h('button', { type: 'button', class: 'btn ghost sm', hidden: true, onclick: function () { alertClose(); openSettings('ignored'); } });
    r.tools.appendChild(r.ign);
    r.tools.appendChild(btn('History', function () { alertClose(); alertHistory(); }, 'ghost sm', 'clock', 'When each problem started and ended'));
    if (R.bell) { r.pop = h('div', { class: 'anc', hidden: true, role: 'dialog', 'aria-label': 'Alerts' }, h('div', { class: 'anc-h' }, h('b', null, 'Alerts'), r.tools), r.list); root.appendChild(r.pop); }
  };
  /* on the board alerts are a button in the header that names the problem and opens a list; nothing takes up a card */
  function alertClose() {
    var p = R.alerts && R.alerts.pop; if (!p || p.hidden) return;
    p.hidden = true; R.bell.setAttribute('aria-expanded', 'false');
    document.removeEventListener('mousedown', S.ancOut, true); document.removeEventListener('keydown', S.ancKey);
  }
  function alertPop() {
    var r = R.alerts, p = r && r.pop; if (!p) return;
    if (!p.hidden) { alertClose(); return; }
    var b = R.bell.getBoundingClientRect(), ts = boardTs();
    p.style.top = (R.bell.closest('.top').getBoundingClientRect().bottom / ts + 8) + 'px';
    p.style.right = Math.max(8, (window.innerWidth - b.right) / ts) + 'px';
    p.hidden = false; R.bell.setAttribute('aria-expanded', 'true');
    S.ancOut = function (e) { if (!p.contains(e.target) && !R.bell.contains(e.target) && !(e.target.closest && e.target.closest('.menu,.modal,.modal-bg'))) alertClose(); };
    S.ancKey = function (e) { if (e.key === 'Escape') { alertClose(); R.bell.focus(); } };
    document.addEventListener('mousedown', S.ancOut, true); document.addEventListener('keydown', S.ancKey);
  }
  function paintBell(out) {
    if (!R.bell) return;
    var n = out.length, lv = function (k) { return out.some(function (x) { return x.lv === k; }); }, sev = lv('bad') ? ' bad' : lv('warn') ? ' warn' : n ? ' info' : '';
    R.bell.className = 'bell' + sev;
    txt(R.bellT, n === 1 ? ALERT_CAT[out[0].cat] || '1 alert' : n ? n + ' alerts' : '');
    var say = n === 1 ? out[0].msg : n ? n + ' alerts' : 'No alerts';
    R.bell.title = say; R.bell.setAttribute('aria-label', 'Alerts: ' + say);
  }
  /* Alert ids are "<category>:<resource>" (see include/ignores.php); an alert without a category cannot be ignored. */
  var ALERT_CAT = {
    'docker.unhealthy': 'Container unhealthy', 'docker.restarting': 'Container restarting', 'docker.exited': 'Container stopped with an error', 'docker.update_failed': 'Container update failed',
    'array.state': 'Array not started', 'array.resync': 'Parity operation running', 'disk.bad': 'Disk disabled or missing', 'disk.errors': 'Disk read/write errors', 'disk.hot': 'Disk temperature', 'disk.smart': 'Disk failed SMART (Scrutiny)',
    'auth.open': 'No dashboard password', 'health.down': 'Endpoint not answering', 'os.staged': 'Unraid update waiting for restart', 'os.available': 'Unraid update available', 'os.check_failed': 'Unraid update check failed', 'api.error': 'Unraid API problem'
  };
  /* the rules in force, by the server's clock (live.at), plus this tab's own changes until the next refresh confirms them */
  function ignRules(y) {
    var list = (y.ignores || []).slice(), t = Date.now();
    // a refresh that was already on its way when the rule changed would undo the change for a moment
    S.ignHold = (S.ignHold || []).filter(function (c) { return c.exp > t; });
    S.ignHold.forEach(function (c) {
      if (c.add) list = list.filter(function (z) { return z.id !== c.add.id && !(z.kind === c.add.kind && z.key === c.add.key); }).concat([c.add]);
      else list = list.filter(function (z) { return c.del.indexOf(z.id) < 0; });
    });
    return list;
  }
  function ignoredBy(y, cat, res) {
    var now = y.at || Date.now() / 1000, key = cat + ':' + res;
    return ignRules(y).filter(function (g) { return (g.until === null || g.until > now) && (g.kind === 'type' ? g.key === cat : g.key === key); })[0] || null;
  }
  /* the alerts come from the server (include/alerts.php); only the connection ones are the browser's own */
  var ALERT_ACT = {
    settings: function () { return ['Settings', function () { openSettings('config'); }]; },
    restart: function () { return ['Restart…', function () { powerDialog('reboot'); }]; },
    os: function () { return ['Details', osDialog]; },
    logs: function (res) { return ['Logs', function () { logs(res); }]; }
  };
  function alertList(y) {
    var out = [];
    if (S.conn === 'down') out.push({ lv: 'bad', msg: 'The server is not answering (' + (S.connErr || 'no answer') + '). Retrying.', btn: null, cat: '', res: '' });
    if (S.conn === 'auth') out.push({ lv: 'bad', msg: 'Your session ended. Reload the page to sign in again.', btn: null, cat: '', res: '' });
    // the most important one: always first, red and highlighted
    if (S.boot && S.boot.auth === 'open') out.unshift({ lv: 'bad', crit: true, msg: 'No dashboard password: anyone who can open this address can see and control your containers and VMs. Set one now.', btn: ['Set password', function () { openSettings('config'); }], cat: 'auth.open', res: 'dashboard' });
    if (R.bell) (S.down || []).forEach(function (x) { out.push({ lv: 'warn', msg: x.name + ' is not answering (' + x.text + ').', btn: x.url ? ['Open', function () { window.open(x.url, '_blank', 'noopener'); }] : null, cat: 'health.down', res: x.name }); });
    (y.alerts || []).forEach(function (a) { out.push({ lv: a.lv, msg: a.msg, btn: ALERT_ACT[a.act] ? ALERT_ACT[a.act](a.res) : null, cat: a.cat, res: a.res }); });
    return out;
  }
  function alertHistory() {
    var box = h('div', { class: 'ahist' }, h('div', { class: 'progress' }, h('i')));
    modal('Alert history', box, null, true);
    api('alert_history').then(function (j) {
      clear(box);
      if (j.error) { box.appendChild(h('div', { class: 'empty' }, j.error)); return; }
      var dur = function (sec) { sec = Math.max(0, sec); return sec < 90 ? Math.round(sec) + ' s' : sec < 5400 ? Math.round(sec / 60) + ' min' : sec < 172800 ? (sec / 3600).toFixed(1).replace(/\.0$/, '') + ' h' : Math.round(sec / 86400) + ' days'; };
      var when = function (t) { return new Date(t * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); };
      var row = function (e, open) {
        return h('div', { class: 'ah ' + e.lv }, h('span', { class: 'sdot ' + (e.lv === 'bad' ? 'bad' : e.lv === 'warn' ? 'warn' : 'ok'), role: 'img', 'aria-label': e.lv }),
          h('span', { class: 'grow' }, h('span', { class: 'am' }, e.msg), h('span', { class: 'muted xs' }, (ALERT_CAT[e.cat] || e.cat) + ' · ' + when(e.start) + (open ? '' : ' → ' + when(e.end)))),
          h('span', { class: 'badge ' + (open ? (e.lv === 'bad' ? 'bad' : 'warn') : 'plain') }, open ? 'ongoing · ' + dur(j.now - e.start) : dur(e.end - e.start)));
      };
      box.appendChild(h('p', { class: 'muted sm' }, 'Checked once a minute in the background, and on every refresh while the dashboard is open. Ignored alerts are still recorded. Kept for 30 days.'));
      box.appendChild(h('h4', { class: 'ign-h' }, 'Ongoing (' + j.active.length + ')'));
      if (!j.active.length) box.appendChild(h('div', { class: 'empty' }, 'Nothing is wrong right now.'));
      j.active.forEach(function (e) { box.appendChild(row(e, true)); });
      box.appendChild(h('h4', { class: 'ign-h' }, 'Ended (' + j.log.length + ')'));
      if (!j.log.length) box.appendChild(h('div', { class: 'empty' }, 'No ended alerts in the last 30 days.'));
      j.log.forEach(function (e) { box.appendChild(row(e, false)); });
    });
  }
  function paintAlerts(y) {
    var r = R.alerts, all = alertList(y), hidden = 0;
    var out = all.filter(function (x) { if (x.cat && ignoredBy(y, x.cat, x.res)) { hidden++; return false; } return true; });
    var sig = JSON.stringify([!!y.system, hidden, out.map(function (x) { return [x.lv, x.msg, x.cat, x.res, !!x.btn]; })]);
    if (r.sig === sig) return; r.sig = sig;
    clear(r.list);
    txt(r.count, out.length ? String(out.length) : '');
    r.ign.hidden = !hidden; clear(r.ign); r.ign.appendChild(ic('mute')); r.ign.appendChild(document.createTextNode(hidden + ' ignored'));
    r.ign.title = 'Alerts hidden by ignore rules. Open Settings > Ignored alerts.';
    // nothing wrong: the whole section steps aside (ignored alerts and the history are under Settings > Ignored alerts)
    r.el.hidden = !out.length;
    paintBell(out);
    if (!out.length) { if (r.pop) r.list.appendChild(h('div', { class: 'empty' }, 'All clear. Nothing needs your attention.')); return; }
    out.forEach(function (x) {
      var mb = x.cat ? h('button', { type: 'button', class: 'btn ghost sm', 'aria-haspopup': 'menu', title: 'Ignore this alert', 'aria-label': 'Ignore: ' + x.msg, onclick: function (e) { ignoreMenu(e.currentTarget, x); } }, ic('mute'), 'Ignore') : null;
      r.list.appendChild(h('div', { class: 'alert ' + x.lv + (x.crit ? ' crit' : ''), role: x.crit ? 'alert' : null, 'data-alert': x.cat ? x.cat + ':' + x.res : null },
        x.crit || R.cards ? ic(x.lv === 'info' ? 'info' : 'warn') : null, h('span', { class: 'grow' }, x.crit ? h('b', null, x.msg) : x.msg), x.btn ? btn(x.btn[0], function () { alertClose(); x.btn[1](); }, x.crit ? 'sm danger solid' : 'sm') : null, mb));
    });
  }
  function durText(sec) { return sec === 'permanent' ? 'permanently' : sec % 86400 === 0 ? 'for ' + sec / 86400 + ' day' + (sec > 86400 ? 's' : '') : sec % 3600 === 0 ? 'for ' + sec / 3600 + ' hour' + (sec > 3600 ? 's' : '') : 'for ' + Math.round(sec / 60) + ' minutes'; }
  function ignoreMenu(from, x) {
    var one = function (d) { return function () { ignoreAdd('instance', x, d); }; };
    menu(from, [['Ignore for 1 hour', 'clock', one(3600)], ['Ignore for 24 hours', 'clock', one(86400)], ['Ignore for 7 days', 'clock', one(604800)],
      ['Ignore for…', 'clock', function () { customDur('Ignore this alert', x.msg, function (d) { ignoreAdd('instance', x, d); }); }],
      ['Ignore permanently', 'mute', one('permanent')], 'hr',
      ['Ignore all “' + ALERT_CAT[x.cat] + '” alerts…', 'mute', function () { ignoreTypeDialog(x); }]]);
  }
  /* duration picker: the presets, or a number of minutes / hours / days (5 minutes to a year) */
  function durField(withPresets) {
    var sel = h('select', { 'aria-label': 'How long' }, (withPresets ? [['3600', '1 hour'], ['86400', '24 hours'], ['604800', '7 days'], ['custom', 'Custom…'], ['permanent', 'Permanently']] : [])
      .map(function (o) { return h('option', { value: o[0] }, o[1]); }));
    var n = h('input', { type: 'number', min: '1', max: '366', value: '2', 'aria-label': 'Amount', style: { width: '80px' } });
    var u = h('select', { 'aria-label': 'Unit' }, [['60', 'minutes'], ['3600', 'hours'], ['86400', 'days']].map(function (o) { return h('option', { value: o[0], selected: o[0] === '3600' }, o[1]); }));
    var cust = h('span', { class: 'ign-dur', hidden: withPresets }, n, u);
    sel.onchange = function () { cust.hidden = sel.value !== 'custom'; };
    return { el: h('div', { class: 'ign-dur' }, withPresets ? sel : null, cust), value: function () {
      if (withPresets && sel.value !== 'custom') return sel.value === 'permanent' ? 'permanent' : +sel.value;
      var v = Math.round(+n.value * +u.value);
      return isFinite(v) && v >= 300 && v <= 366 * 86400 ? v : null;
    } };
  }
  function customDur(title, text, done) {
    var f = durField(false), err = h('div', { class: 'muted sm', role: 'alert' }), m;
    m = modal(title, h('div', null, h('p', null, text), h('label', { class: 'muted sm' }, 'Ignore for'), f.el, err),
      [btn('Cancel', function () { m.close(); }), btn('Ignore', function () { var v = f.value(); if (v === null) { txt(err, 'Choose between 5 minutes and 366 days.'); return; } m.close(); done(v); }, 'pri')]);
  }
  function ignoreTypeDialog(x) {
    var f = durField(true), err = h('div', { class: 'muted sm', role: 'alert' }), m, lab = ALERT_CAT[x.cat];
    m = modal('Ignore all “' + lab + '” alerts', h('div', null,
      h('p', null, 'This hides every “' + lab + '” alert, for every ' + (x.cat.indexOf('docker.') === 0 ? 'container' : x.cat.indexOf('disk.') === 0 ? 'disk' : 'occurrence') + ', including ones that appear later, not just this one.'),
      h('p', { class: 'muted sm' }, 'UnDash keeps checking; the alerts come back when you restore them in Settings > Ignored alerts or the time runs out.'),
      h('label', { class: 'muted sm' }, 'Ignore'), f.el, err),
      [btn('Cancel', function () { m.close(); }), btn('Ignore all', function () { var v = f.value(); if (v === null) { txt(err, 'Choose between 5 minutes and 366 days.'); return; } m.close(); ignoreAdd('type', x, v); }, 'danger solid')]);
  }
  function ignoreAdd(kind, x, dur) {
    api('ignore_add', { kind: kind, category: x.cat, resource: kind === 'type' ? '' : x.res, duration: dur, title: kind === 'type' ? ALERT_CAT[x.cat] : x.msg, severity: x.lv }).then(function (j) {
      if (j.error) { toast(j.error, true); return; }
      var g = j.rule;
      ignHold({ add: { id: g.id, kind: g.kind, key: g.key, until: g.until } });
      toast((kind === 'type' ? 'All “' + ALERT_CAT[x.cat] + '” alerts' : 'Alert') + ' ignored ' + durText(dur) + '.', false, ['Undo', function () { ignoreRemove([g.id], 'Alert restored.'); }]);
    });
  }
  function ignHold(c) { c.exp = Date.now() + 8000; (S.ignHold = S.ignHold || []).push(c); if (R.alerts) paintAlerts(S.live || {}); }
  function ignoreRemove(ids, okMsg) {
    return api('ignore_remove', { ids: ids }).then(function (j) {
      if (j.error) { toast(j.error, true); return j; }
      ignHold({ del: ids });
      if (okMsg) toast(okMsg);
      return j;
    });
  }

  /* ---- favorites ---- */
  BUILD.favorites = function (r) { r.list = h('div', { class: 'favs' }); r.body.appendChild(r.list); };
  function paintFavs(y) {
    var r = R.favorites, items = [];
    ((y.docker && y.docker.containers) || []).forEach(function (c) { if (pref('docker', c.name).favorite) items.push(['d', c]); });
    ((y.vms && y.vms.vms) || []).forEach(function (v) { if (pref('vms', v.name).favorite) items.push(['v', v]); });
    S.L.groups.forEach(function (g) { g.services.forEach(function (s) { if (s.favorite && !s.hidden) items.push(['s', s]); }); });
    var sig = JSON.stringify(items.map(function (x) { return [x[0], x[1].name, x[1].state, x[1].health, x[1].webui, x[1].ops, S.busy[(x[0] === 'd' ? 'd:' : 'v:') + x[1].name], x[0] === 's' ? null : pref(x[0] === 'd' ? 'docker' : 'vms', x[1].name)]; }));
    if (r.sig === sig) return; r.sig = sig;
    clear(r.list);
    if (!items.length) { r.list.appendChild(h('div', { class: 'card empty', style: { 'grid-column': '1/-1' } }, 'Star containers, VMs or services (gear menu or Settings) to pin them here.')); return; }
    items.forEach(function (x) {
      var it = x[1], el;
      if (x[0] === 'd') {
        var run = it.state === 'running';
        var fw = dWeb(it), ft = fw ? 'Open the web UI of ' + it.name : it.name;
        el = h('div', { class: 'fav' }, webA(fw, ft, cIcon(it, pref('docker', it.name), '')), h('div', { class: 't' }, webA(fw, ft, h('b', null, label('docker', it.name))), dStateBadge(it)),
          dActions(it, pref('docker', it.name)));
      } else if (x[0] === 'v') {
        var vw = vWeb(it), vt = vTip(it, vw, it.name);
        el = h('div', { class: 'fav' }, webA(vw, vt, vIcon(it, pref('vms', it.name), '')), h('div', { class: 't' }, webA(vw, vt, h('b', null, label('vms', it.name))), vStateBadge(it.state, S.busy['v:' + it.name])),
          vActions(it, pref('vms', it.name)));
      } else {
        var href = it.href_resolved;
        el = h('div', { class: 'fav' }, webA(href, 'Open ' + it.name, img(iconUrl(it.icon), '', 'tag')), h('div', { class: 't' }, webA(href, 'Open ' + it.name, h('b', null, it.name)), h('span', { class: 'muted xs' }, it.description || (S.boot.types[it.type] || [''])[0])));
      }
      r.list.appendChild(el);
    });
  }

  /* ---------- preferences for discovered containers and VMs ---------- */
  function pref(kind, name) { var it = S.L[kind] && S.L[kind].items; return (it && !Array.isArray(it) && it[name]) || {}; }
  function label(kind, name) { return pref(kind, name).label || name; }
  function savePref(kind, name, val) {
    if (!S.L[kind].items || Array.isArray(S.L[kind].items)) S.L[kind].items = {};
    S.L[kind].items[name] = Object.assign({}, S.L[kind].items[name] || {}, val);
    paintAll();
    return api('prefs', { kind: kind, key: name, value: val }).then(function (j) { if (j.error) toast(j.error, true); else { S.L = j.layout; paintAll(); } });
  }
  function customize(kind, it) {
    var p = Object.assign({ label: '', icon: '', description: '', favorite: false, hidden: false }, pref(kind, it.name));
    var f = function (lab, k, ph) { return F(lab, h('input', { type: 'text', value: p[k], placeholder: ph || '', oninput: function (e) { p[k] = e.target.value; } })); };
    var m = modal('Customize ' + it.name, h('div', null,
      f('Display name', 'label', it.name), f('Icon', 'icon', 'Empty = the template icon. sh-NAME, NAME.png or a URL'), f('Description', 'description', 'Optional note'),
      h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: p.favorite, onchange: function (e) { p.favorite = e.target.checked; } }), 'Favorite (shown in Favorites)'),
      h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: p.hidden, onchange: function (e) { p.hidden = e.target.checked; } }), 'Hide from the dashboard'),
      h('p', { class: 'muted sm' }, 'These are kept by name, so they survive container updates and re-creation.')),
      [btn('Cancel', function () { m.close(); }), btn('Save', function () { m.close(); savePref(kind, it.name, p); }, 'pri')]);
  }

  /* ---- Docker ---- */
  function dockerStats(y) {
    var c = y.docker && y.docker.containers; if (!c) return null;
    var s = { running: 0, stopped: 0, paused: 0, updates: 0, unhealthy: 0, total: c.length, updList: [] };
    c.forEach(function (x) {
      if (x.state === 'running') s.running++; else if (x.state === 'paused') s.paused++; else s.stopped++;
      if (updOn(x) && !pref('docker', x.name).hidden) { s.updates++; s.updList.push(x.name); }
      if (x.health === 'unhealthy') s.unhealthy++;
    });
    return s;
  }
  function dStateBadge(c) {
    var b = S.busy['d:' + c.name];
    if (b) return h('span', { class: 'badge info' }, b);
    if (c.job === 'updating') return h('span', { class: 'badge info' }, 'updating');
    if (c.state === 'running' && c.health === 'unhealthy') return h('span', { class: 'badge bad' }, 'unhealthy');
    if (c.state === 'running' && c.health === 'starting') return h('span', { class: 'badge warn' }, 'starting');
    var m = { running: ['running', 'ok'], exited: ['stopped', 'off'], paused: ['paused', 'warn'], restarting: ['restarting', 'warn'], created: ['created', 'off'], dead: ['dead', 'bad'], removing: ['removing', 'warn'] }[c.state] || [c.state, 'off'];
    return h('span', { class: 'badge ' + m[1] }, m[0]);
  }
  /* an update counts unless update checks are off (Settings > Sections & apps > Containers), for all or for this container */
  function updOn(c) { return c.update === 'available' && S.L.docker.update_check !== false && !pref('docker', c.name).no_update; }
  function dUpdBadge(c) {
    if (c.job === 'update_failed') return h('span', { class: 'badge bad', title: 'The last update failed; see the logs' }, 'update failed');
    if (S.L.docker.update_check === false || pref('docker', c.name).no_update) return h('span', { class: 'badge plain' }, 'not checked');
    if (c.update === 'available') return h('span', { class: 'muted sm upd-t' }, 'update available');
    if (c.update === 'current') return h('span', { class: 'badge ok' }, 'up to date');
    return h('span', { class: 'badge plain', title: c.managed ? 'Unraid has not checked this image yet, or the check failed: unknown, not "up to date"' : 'Not created from an Unraid template, so Unraid cannot check it' }, c.managed ? 'not checked' : 'no update check');
  }
  /* "134 hours" -> "134h", "About an hour" -> "1h", "3 days" -> "3d" (the grid has little room) */
  function shortUp(t) { return String(t).replace(/^about an? /i, '1 ').replace(/^less than a second/i, '0s').replace(/ ?(second|minute|hour|day|week|month|year)s?\b/gi, function (m, u) { return u === 'month' ? 'mo' : u.charAt(0).toLowerCase(); }); }
  function uptimeText(c) { return c.state === 'running' ? String(c.status || '').replace(/\s*\(.*\)\s*$/, '').replace(/^Up\s+/, '') : (String(c.status || '').replace(/^Exited \(\d+\)\s*/, '') || '-'); }

  /* containers: a compact multi-column grid by default, showing the running ones (or the ones you picked) */
  var DFILTERS = [['selected', 'Selected'], ['running', 'Running'], ['all', 'All'], ['stopped', 'Stopped'], ['updates', 'Updates'], ['problems', 'Problems']];
  BUILD.docker = function (r) {
    var D = S.L.docker;
    if (!S.fdSet) S.fd = D.filter === 'selected' && !D.selected.length ? 'running' : D.filter;
    r.filters = h('div', { class: 'seg', role: 'group', 'aria-label': 'Show containers' });
    DFILTERS.forEach(function (f) {
      if (f[0] === 'selected' && !D.selected.length) return;
      var b = h('button', { type: 'button', class: S.fd === f[0] ? 'on' : '', 'aria-pressed': S.fd === f[0] ? 'true' : 'false', title: f[0] === 'selected' ? 'The containers you picked (gear > Show in "Selected")' : null, onclick: function () {
        S.fd = f[0]; S.fdSet = true; [].forEach.call(r.filters.childNodes, function (x) { x.className = x === b ? 'on' : ''; x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); r.fSig = null; paintDocker(S.live || {});
      } }, f[1]);
      r.filters.appendChild(b);
    });
    var sort = h('select', { 'aria-label': 'Sort containers', onchange: function () { D.sort = sort.value; api('prefs', { kind: 'docker_view', value: { sort: D.sort } }); paintDocker(S.live || {}); } },
      [['name', 'Name'], ['status', 'Status'], ['cpu', 'CPU'], ['memory', 'Memory']].map(function (o) { return h('option', { value: o[0], selected: D.sort === o[0] }, 'Sort: ' + o[1]); }));
    var lay = h('div', { class: 'seg', role: 'group', 'aria-label': 'Layout' },
      [['grid', 'grid', 'Compact grid'], ['list', 'list', 'Table'], ['cards', 'box', 'Cards']].map(function (l) {
        return h('button', { type: 'button', class: D.layout === l[0] ? 'on' : '', title: l[2], 'aria-label': l[2], 'aria-pressed': D.layout === l[0] ? 'true' : 'false', onclick: function () { setLayout('docker', l[0]); } }, ic(l[1]));
      }));
    r.updAll = h('span');
    var view = h('button', { type: 'button', class: 'btn sm ghost', title: D.view === 'compact' ? 'Show image, uptime and restarts' : 'Compact rows', 'aria-pressed': D.view === 'expanded' ? 'true' : 'false',
      onclick: function () { D.view = D.view === 'compact' ? 'expanded' : 'compact'; api('prefs', { kind: 'docker_view', value: { view: D.view } }); shell(); } }, D.view === 'compact' ? 'Expanded' : 'Compact');
    r.tools.appendChild(r.filters); r.tools.appendChild(sort); r.tools.appendChild(lay); if (D.layout === 'list') r.tools.appendChild(view); r.tools.appendChild(r.updAll);
    var cmp = D.view === 'compact';
    if (D.layout === 'grid') {
      r.list = r.rows = h('div', { class: 'cgrid', role: 'list', style: D.columns ? { '--cc': String(D.columns) } : null });
      if (D.columns) r.list.classList.add('fixed');
    } else {
      r.list = D.layout === 'cards' ? h('div', { class: 'tiles', role: 'list' }) : h('div', { class: 'panel' + (cmp ? ' compact' : '') }, cmp
        ? h('div', { class: 'rowh c', 'aria-hidden': 'true' }, h('span', null, 'Container'), h('span', null, 'Status'), h('span', { class: 'c-cpu' }, 'CPU'), h('span', { class: 'c-mem' }, 'Memory'), h('span', null, ''), h('span', null, ''))
        : h('div', { class: 'rowh', 'aria-hidden': 'true' }, h('span', null, 'Container'), h('span', null, 'Status'), h('span', { class: 'c-res' }, 'CPU / Memory'), h('span', { class: 'c-up' }, 'Uptime'), h('span', { class: 'c-upd' }, 'Version'), h('span', null, 'Actions')));
      r.rows = D.layout === 'cards' ? r.list : h('div', { role: 'list' });
      if (D.layout !== 'cards') r.list.appendChild(r.rows);
    }
    r.foot = h('div', { class: 'more' });
    r.body.appendChild(r.list);
    // on the board it sits beside the count, so it costs no row of its own
    if (boardOn()) r.el.querySelector('.sec-h').insertBefore(r.foot, r.tools); else r.body.appendChild(r.foot);
    r.map = {};
  };
  function toggleSelected(name) {
    var D = S.L.docker, i = D.selected.indexOf(name), first = !D.selected.length;
    if (i >= 0) D.selected.splice(i, 1); else D.selected.push(name);
    api('prefs', { kind: 'docker_view', value: { selected: D.selected } }).then(function (j) { if (j.error) toast(j.error, true); });
    toast(i >= 0 ? name + ' removed from "Selected"' : name + ' added to "Selected"' + (first ? '. Pick "Selected" as the default view in Settings > Sections & apps > Containers.' : ''));
    shell();
  }
  function setLayout(kind, l) { S.L[kind].layout = l; api('prefs', { kind: kind + '_view', value: { layout: l } }); shell(); }
  function paintDocker(y) {
    var r = R.docker, D = S.L.docker, d = y.docker;
    var empty = r.rows.querySelector(':scope > .empty');
    if (!d) { if (!r.rows.firstChild) r.rows.appendChild(h('div', { class: 'empty' }, 'Loading containers…')); return; }
    if (d.error) { r.map = {}; clear(r.rows).appendChild(h('div', { class: 'empty' }, d.error)); return; }
    if (empty) empty.remove();
    var hiddenN = 0;
    var list = d.containers.filter(function (c) {
      var p = pref('docker', c.name);
      if (p.hidden && !S.showHidden) { hiddenN++; return false; }
      if (S.fd === 'selected') return D.selected.indexOf(c.name) >= 0;
      if (S.fd === 'running') return c.state === 'running';
      if (S.fd === 'stopped') return c.state !== 'running';
      if (S.fd === 'updates') return updOn(c);
      if (S.fd === 'problems') return c.health === 'unhealthy' || c.state === 'restarting' || c.job === 'update_failed' || (c.state === 'exited' && c.exit && c.autostart);
      if (S.fd === 'favorites') return !!p.favorite;
      if (D.hide_stopped && c.state !== 'running') { hiddenN++; return false; }
      return true;
    });
    var order = { running: 0, restarting: 1, paused: 2, created: 3, exited: 4, dead: 5 };
    list.sort(function (a, b) {
      var fa = pref('docker', a.name).favorite ? 0 : 1, fb = pref('docker', b.name).favorite ? 0 : 1;
      if (fa !== fb) return fa - fb;
      if (D.sort === 'status') return (order[a.state] === undefined ? 9 : order[a.state]) - (order[b.state] === undefined ? 9 : order[b.state]) || a.name.localeCompare(b.name);
      if (D.sort === 'cpu') return (b.cpu || 0) - (a.cpu || 0) || a.name.localeCompare(b.name);
      if (D.sort === 'memory') return (b.mem || 0) - (a.mem || 0) || a.name.localeCompare(b.name);
      return label('docker', a.name).localeCompare(label('docker', b.name));
    });
    var seen = {};
    list.forEach(function (c, i) {
      var p = pref('docker', c.name);
      // rows are only rebuilt when something structural changes, so menus and focus survive the 2-second refresh
      var sig = JSON.stringify([c.state, c.health, c.update, c.job, c.webui, c.managed, S.busy['d:' + c.name] || '', p, c.icon, c.image, D.layout, D.view, D.show, D.update_check, D.selected.indexOf(c.name) >= 0]);
      var row = r.map[c.name];
      if (!row || row._sig !== sig) {
        var had = row && row.contains(document.activeElement) ? document.activeElement.getAttribute('aria-label') : null;
        var nrow = D.layout === 'grid' ? dCell(c, p) : D.layout === 'cards' ? dTile(c, p) : D.view === 'compact' ? dRowC(c, p) : dRow(c, p);
        nrow._sig = sig;
        if (row && row.parentNode) row.parentNode.replaceChild(nrow, row);
        r.map[c.name] = row = nrow;
        if (had) { var f = row.querySelector('[aria-label="' + had.replace(/"/g, '') + '"]'); if (f && !f.disabled) f.focus(); }
      }
      dVolatile(row, c);
      if (r.rows.childNodes[i] !== row) r.rows.insertBefore(row, r.rows.childNodes[i] || null);
      seen[c.name] = 1;
    });
    Object.keys(r.map).forEach(function (n) { if (!seen[n]) { var x = r.map[n]; if (x.parentNode) x.parentNode.removeChild(x); delete r.map[n]; } });
    if (!list.length) r.rows.appendChild(h('div', { class: 'empty' }, S.fd === 'selected' ? 'None of your selected containers exist any more.' : d.containers.length ? 'No containers in this view.' : 'No containers yet.'));
    var dk = dockerStats(y), fs = JSON.stringify([hiddenN, S.showHidden, dk.updates, S.fd, list.length, d.containers.length]);
    if (r.fSig !== fs) {
      r.fSig = fs; clear(r.foot); clear(r.updAll);
      if (S.fd !== 'all' && list.length < d.containers.length) r.foot.appendChild(h('button', { type: 'button', class: 'allb', title: 'Show every container', onclick: function () { S.fd = 'all'; S.fdSet = true; shell(); } }, 'Show all ' + d.containers.length, ic('chev')));
      if (hiddenN || S.showHidden) r.foot.appendChild(btn(S.showHidden ? 'Hide hidden containers' : 'Show ' + hiddenN + ' hidden', function () { S.showHidden = !S.showHidden; r.fSig = null; if (R.vms) R.vms.sig = null; paintAll(); }, 'sm ghost', S.showHidden ? 'eyeoff' : 'eye'));
      if (dk.updates) r.updAll.appendChild(btn('Update all (' + dk.updates + ')', function () { updateMany(dockerStats(S.live).updList); }, 'sm pri', 'up', 'Update every container that has an update'));
    }
  }
  /* every container action sits behind one gear button; the web UI opens from the icon or the name */
  function dWeb(c) { return c.state === 'running' && c.webui ? c.webui : ''; }
  function webA(url, title, kids) { return url ? h('a', { class: 'wl', href: url, target: '_blank', rel: 'noopener', title: title }, kids) : h('span', { class: 'wl' }, kids); }
  function gear(name, items, upd) {
    var g = btn('', function () { menu(g, items()); }, 'sm gear' + (upd ? ' has-upd' : ''), 'gear', 'Actions for ' + name + (upd ? ' · an update is available' : ''));
    g.setAttribute('aria-haspopup', 'menu');
    return g;
  }
  function dActions(c, p) {
    var run = c.state === 'running', web = dWeb(c);
    var a = h('div', { class: 'acts c-acts' });
    a.appendChild(gear(c.name, function () {
      var busy = !!S.busy['d:' + c.name] || c.job === 'updating';
      return [
        web ? ['Open web UI', 'web', function () { window.open(web, '_blank', 'noopener'); }] : null,
        web ? 'hr' : null,
        c.state === 'paused' ? ['Resume', 'play', function () { dAct(c, 'unpause'); }, '', busy]
          : run || c.state === 'restarting' ? ['Stop', 'stop', function () { dAct(c, 'stop'); }, 'danger', busy]
          : ['Start', 'play', function () { dAct(c, 'start'); }, '', busy],
        c.state !== 'paused' ? ['Restart', 'restart', function () { dAct(c, 'restart'); }, '', busy] : null,
        run ? ['Pause', 'pause', function () { dAct(c, 'pause'); }, '', busy] : null,
        [updOn(c) ? 'Update (new version available)' : 'Update (force)', 'up', function () { updateMany([c.name]); }, updOn(c) ? 'upd' : '', busy],
        'hr', ['Logs', 'logs', function () { logs(c.name); }], ['Details', 'info', function () { inspect(c.name); }], 'hr',
        [p.favorite ? 'Remove from favorites' : 'Add to favorites', 'star', function () { savePref('docker', c.name, { favorite: !p.favorite }); }],
        [S.L.docker.selected.indexOf(c.name) >= 0 ? 'Remove from "Selected"' : 'Show in "Selected"', 'check', function () { toggleSelected(c.name); }],
        ['Change icon…', 'tag', function () { iconPicker(p.label || c.name, function (v) { savePref('docker', c.name, { icon: v }); }, p.icon || '-'); }],
        [p.hidden ? 'Show on dashboard' : 'Hide from dashboard', p.hidden ? 'eye' : 'eyeoff', function () { savePref('docker', c.name, { hidden: !p.hidden }); }],
        S.L.docker.update_check !== false ? [p.no_update ? 'Check this container for updates' : 'Don\'t check this container for updates', 'up', function () { savePref('docker', c.name, { no_update: !p.no_update }); }] : null,
        ['Customize…', 'edit', function () { customize('docker', c); }]];
    }, updOn(c)));
    return a;
  }
  function dWho(c, p) {
    var web = dWeb(c), t = web ? 'Open the web UI of ' + c.name : c.name;
    return h('div', { class: 'who c-who' }, webA(web, t, cIcon(c, p, '')),
      h('div', { class: 'ell' }, h('div', { class: 'nm' }, webA(web, t, h('span', { class: 'ell', title: c.name }, p.label || c.name)), p.favorite ? ic('star', true) : null),
        h('div', { class: 'im ell', title: c.image }, p.description || c.image)));
  }
  function dRow(c, p) {
    var row = h('div', { class: 'rowi' + (S.busy['d:' + c.name] ? ' busy' : ''), role: 'listitem', 'data-name': c.name });
    row._cpu = h('span'); row._mem = h('span', { class: 'muted' }); row._m = h('div', { class: 'meter', 'aria-hidden': 'true' }, h('i')); row._up = h('span', { class: 'c-up num muted sm' }); row._mob = h('span', { class: 'mob muted sm num' });
    row.appendChild(dWho(c, p));
    row.appendChild(h('div', { class: 'c-st' }, dStateBadge(c), c.job === 'update_failed' ? h('span', { class: 'mob' }, dUpdBadge(c)) : null, row._mob));
    row.appendChild(h('div', { class: 'res c-res' }, h('div', { class: 'inl', style: { gap: '6px' } }, row._cpu, row._mem), row._m));
    row.appendChild(row._up);
    row.appendChild(h('div', { class: 'c-upd' }, dUpdBadge(c)));
    row.appendChild(dActions(c, p));
    return row;
  }
  /* compact row: icon, name, state, CPU, memory, an update badge only when there is one, and the actions */
  function dRowC(c, p) {
    var row = h('div', { class: 'rowi c' + (S.busy['d:' + c.name] ? ' busy' : ''), role: 'listitem', 'data-name': c.name, title: c.image });
    row._cpu = h('span', { class: 'c-cpu num' }); row._mem = h('span', { class: 'c-mem num muted' });
    var web = dWeb(c), wt = web ? 'Open the web UI of ' + c.name : c.name;
    row.appendChild(h('div', { class: 'who c-who' }, webA(web, wt, cIcon(c, p, '')), webA(web, wt, h('span', { class: 'nm ell' }, p.label || c.name, p.favorite ? ic('star', true) : null))));
    row.appendChild(h('div', { class: 'c-st' }, dStateBadge(c)));
    row.appendChild(row._cpu); row.appendChild(row._mem);
    row.appendChild(h('div', { class: 'c-upd' }, c.job === 'update_failed' ? dUpdBadge(c) : null));
    row.appendChild(dActions(c, p));
    return row;
  }
  /* grid cell: icon, name, state dot, CPU / memory, update badge only when there is one, and the main actions */
  function dCell(c, p) {
    var D = S.L.docker, sh = function (k) { return D.show.indexOf(k) >= 0; };
    var cell = h('div', { class: 'ci' + (S.busy['d:' + c.name] ? ' busy' : '') + ' st-' + c.state, role: 'listitem', 'data-name': c.name });
    var st = dStateBadge(c), dot = h('span', { class: 'sdot ' + ({ ok: 'ok', warn: 'warn', bad: 'bad', info: 'warn' }[(st.className.match(/badge (\w+)/) || [])[1]] || 'na'), role: 'img', 'aria-label': st.textContent, title: st.textContent });
    cell._cpu = h('span', { class: 'num' }); cell._mem = h('span', { class: 'num' }); cell._up = sh('uptime') ? h('span') : null;
    var sub = h('span', { class: 'cs' });
    if (sh('cpu') && S.boot.caps.docker_stats) sub.appendChild(cell._cpu);
    if (sh('memory') && S.boot.caps.docker_stats) sub.appendChild(cell._mem);
    if (cell._up) sub.appendChild(cell._up);
    if (sh('image')) sub.appendChild(h('span', { class: 'ell' }, c.image));
    if (!sub.childNodes.length || c.state !== 'running') {
      clear(sub); cell._cpu = h('span'); cell._mem = h('span'); cell._up = null;
      if (!/^(running|stopped|created)$/.test(st.textContent)) sub.appendChild(h('span', null, st.textContent));   // the dot says running / stopped
    }
    var web = dWeb(c), wt = web ? 'Open the web UI of ' + c.name : c.name + ' · ' + c.image;
    cell.appendChild(webA(web, wt, cIcon(c, p, 'cico')));
    cell.appendChild(h('div', { class: 'cn' }, h('span', { class: 'cname ell' }, dot, webA(web, wt, p.label || c.name), p.favorite ? ic('star', true) : null), sub));
    if (c.job === 'update_failed') cell.appendChild(dUpdBadge(c));
    cell.appendChild(dActions(c, p));
    return cell;
  }
  function dTile(c, p) {
    var t = h('div', { class: 'tile' + (S.busy['d:' + c.name] ? ' busy' : ''), role: 'listitem', 'data-name': c.name });
    t._cpu = h('span'); t._mem = h('span'); t._up = h('span'); t._m = h('div', { class: 'meter', 'aria-hidden': 'true' }, h('i'));
    t.appendChild(dWho(c, p));
    t.appendChild(h('div', { class: 'chips' }, dStateBadge(c), dUpdBadge(c)));
    t.appendChild(h('div', { class: 'stats' }, t._cpu, t._mem, t._up));
    t.appendChild(t._m);
    t.appendChild(dActions(c, p));
    return t;
  }
  function dVolatile(row, c) {
    var run = c.state === 'running';
    var na = !S.boot.caps.docker_stats;      // the Unraid API offers no per-container CPU / memory over HTTP
    txt(row._cpu, na ? '' : run && c.cpu !== null ? c.cpu.toFixed(1) + '%' : run ? '…' : '-');
    txt(row._mem, na ? '' : run && c.mem !== null ? bytes(c.mem) : '');
    row._cpu.title = na ? '' : 'CPU: 100% = one core (as in Unraid\'s Docker tab)';
    if (row._m) { var i = row._m.firstChild; i.style.width = (run && c.cpu !== null ? Math.min(100, c.cpu) : 0) + '%'; i.className = 'acc'; }
    if (row._up) txt(row._up, row.classList.contains('ci') ? shortUp(uptimeText(c)) : uptimeText(c) + (c.restarts ? ' · ' + c.restarts + ' restarts' : ''));
    if (row._mob) txt(row._mob, run ? (c.cpu !== null ? c.cpu.toFixed(1) + '% · ' : '') + (c.mem !== null ? bytes(c.mem) + ' · ' : '') + uptimeText(c) : uptimeText(c));
  }
  function dAct(c, op) {
    var key = 'd:' + c.name;
    if (S.busy[key]) return;
    var nm = label('docker', c.name);
    var run = function () {
      S.busy[key] = { start: 'starting', stop: 'stopping', restart: 'restarting', pause: 'pausing', unpause: 'resuming' }[op] || op;
      paintAll();
      api('docker_action', { name: c.name, op: op }, 130000).then(function (j) {
        delete S.busy[key];
        if (j.error) toast(nm + ': ' + j.error, true); else toast(nm + ': ' + ({ start: 'started', stop: 'stopped', restart: 'restarted', pause: 'paused', unpause: 'resumed' }[op] || op + ' done'));
        if (S.paused) { S.paused = false; togglePause(); }
        refreshNow();
      });
    };
    if (op === 'stop' || op === 'restart') confirmBox((op === 'stop' ? 'Stop ' : 'Restart ') + nm + '?', op === 'stop' ? 'The container stops; its service is unavailable until it is started again.' : 'The container is stopped and started again; its service is briefly unavailable.', op === 'stop' ? 'Stop' : 'Restart', op === 'stop').then(function (y) { if (y) run(); });
    else run();
  }
  function checkUpdates() {
    if (S.busy.dcheck) return;
    S.busy.dcheck = true; paintAll();
    act('docker_check', {}, 'Checking every container for updates…').then(function (j) {
      if (j.error) { delete S.busy.dcheck; paintAll(); return; }
      var n = 0;
      (function poll() {
        api('docker_job', {}).then(function (k) {
          if ((k.state === 'running' || k.error) && ++n < 200) { setTimeout(poll, 3000); return; }
          delete S.busy.dcheck; toast('Container update check finished'); refreshNow();
        });
      })();
    });
  }
  /* updates run one at a time, each through Unraid's update_container */
  var updBar = null;
  function updateMany(names) {
    names = (names || []).filter(function (n) { return !S.busy['d:' + n]; });
    if (!names.length) return;
    var list = h('ul', null, names.slice(0, 30).map(function (n) { return h('li', null, label('docker', n)); }), names.length > 30 ? h('li', null, '… and ' + (names.length - 30) + ' more') : null);
    confirmBox(names.length === 1 ? 'Update ' + label('docker', names[0]) + '?' : 'Update ' + names.length + ' containers?',
      h('div', null, h('p', null, 'Each container is stopped, its newest image is pulled and it is recreated from its Unraid template (the same as Unraid\'s Docker tab). Its service is unavailable meanwhile. They are updated one after the other.'), list),
      names.length === 1 ? 'Update' : 'Update all ' + names.length).then(function (y) { if (y) runUpdates(names); });
  }
  /* a small status bar at the bottom right; the page stays usable. resume = jobs already running on the server (page was reloaded). */
  function runUpdates(names, resume) {
    if (updBar) { updBar.add(names); return; }
    var running = resume ? names.slice() : [];
    var title = h('b', { class: 'ell' }), status = h('div', { class: 'muted xs ell', role: 'status', 'aria-live': 'polite' }), prog = h('div', { class: 'progress' }, h('i'));
    var pre = h('pre', { class: 'log wrap', hidden: true }), results = h('div', { class: 'chips' });
    var stop = false, i = 0, out = '', ok = 0, bad = 0, gone = 0;
    var stopB = btn('Stop after this one', function () { stop = true; stopB.disabled = true; }, 'sm');
    var moreB = btn('Details', function () { pre.hidden = !pre.hidden; moreB.textContent = pre.hidden ? 'Details' : 'Hide details'; if (!pre.hidden) pre.scrollTop = pre.scrollHeight; }, 'sm');
    var closeB = btn('', closeBar, 'sm', 'x', 'Close'); closeB.hidden = true;
    var bar = h('div', { class: 'upbar', role: 'group', 'aria-label': 'Container updates' }, h('div', { class: 'upbar-h' }, title, closeB), status, prog, results, pre, h('div', { class: 'upbar-f' }, stopB, moreB));
    function closeBar() { if (bar.parentNode) bar.parentNode.removeChild(bar); }
    names = names.slice();
    updBar = { add: function (more) { more.forEach(function (n) { if (names.indexOf(n) < 0) { names.push(n); S.busy['d:' + n] = 'queued'; } }); updBar.paint(); paintAll(); }, paint: function () {
      var cur = names[Math.max(0, i - 1)];
      title.textContent = names.length === 1 ? 'Updating ' + label('docker', cur) : 'Updating ' + label('docker', cur) + ' (' + Math.max(1, i) + ' of ' + names.length + ')';
      stopB.hidden = names.length < 2;
    } };
    toastHost().insertBefore(bar, toastBox.firstChild);
    names.forEach(function (n) { S.busy['d:' + n] = 'queued'; });
    paintAll();
    function result(n, good, text) {
      delete S.busy['d:' + n]; gone = 0;
      if (good) ok++; else { bad++; results.appendChild(h('span', { class: 'badge bad', title: text || '' }, label('docker', n))); }
      next();
    }
    function next() {
      if (i >= names.length || stop) {
        names.slice(i).forEach(function (n) { delete S.busy['d:' + n]; });
        updBar = null;
        bar.classList.add(bad ? 'bad' : 'ok'); prog.hidden = true; stopB.hidden = true; closeB.hidden = false;
        title.textContent = bad ? (names.length === 1 ? 'Update failed' : 'Updates finished with errors') : names.length === 1 ? label('docker', names[0]) + ' updated' : 'Updates finished';
        status.textContent = ok + ' updated' + (bad ? ', ' + bad + ' failed' : '') + (stop && i < names.length ? ', ' + (names.length - i) + ' not started' : '');
        if (bad) { pre.hidden = false; moreB.textContent = 'Hide details'; pre.scrollTop = pre.scrollHeight; } else setTimeout(function () { if (pre.hidden) closeBar(); }, 6000);
        refreshNow(); return;
      }
      var n = names[i++], nice = label('docker', n);
      S.busy['d:' + n] = 'updating'; updBar.paint(); paintAll();
      status.textContent = 'Pulling the new image and recreating the container…';
      function fail(msg) { out += '[' + n + '] ' + msg + '\n\n'; pre.textContent = out; result(n, false, msg); }
      function poll() {
        api('docker_job', { name: n }).then(function (k) {
          if (k.error) { if (++gone > 80) return fail('UnDash lost contact with this update for two minutes. Check the container in Unraid\'s Docker tab.'); setTimeout(poll, 1500); return; }
          gone = 0;
          pre.textContent = out + '[' + nice + ']\n' + (k.out || ''); pre.scrollTop = pre.scrollHeight;
          if (k.state === 'running') { var l = (k.out || '').trim().split('\n').pop(); if (l) status.textContent = l; setTimeout(poll, 1500); return; }
          if (k.state === 'idle') return fail('UnDash has no record of this update (it may have restarted). Check the container in Unraid\'s Docker tab.');
          out = pre.textContent + '\n'; result(n, !!k.ok, (k.out || '').trim().split('\n').pop());
        });
      }
      if (running.indexOf(n) >= 0) { poll(); return; }
      api('docker_action', { name: n, op: 'update' }, 30000).then(function (j) { if (j.error) fail(j.error); else poll(); });
    }
    next();
  }
  /* an update keeps running on the server when the page is reloaded: show its bar again */
  function resumeUpdates() {
    if (updBar || !S.live || !S.live.docker) return;
    var names = (S.live.docker.containers || []).filter(function (c) { return c.job === 'updating' && !S.busy['d:' + c.name]; }).map(function (c) { return c.name; });
    if (names.length) runUpdates(names, true);
  }

  function logs(name) {
    var lines = h('select', { 'aria-label': 'Number of lines' }, [100, 300, 1000, 5000].map(function (n) { return h('option', { value: n, selected: n === 300 }, n + ' lines'); }));
    var find = h('input', { type: 'search', placeholder: 'Highlight…', 'aria-label': 'Highlight text in the log' });
    var follow = h('input', { type: 'checkbox', checked: true }), wrap = h('input', { type: 'checkbox', checked: LS.get('wrap', true) });
    var pre = h('pre', { class: 'log' + (wrap.checked ? ' wrap' : ''), tabindex: '0', 'aria-label': 'Log of ' + name }, 'Loading…');
    var text = '', busy = false;
    function paint() {
      var atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
      clear(pre); var q = find.value.trim();
      if (!q) pre.textContent = text || '(empty log)';
      else {
        var lc = q.toLowerCase();
        text.split('\n').forEach(function (l, i) { if (i) pre.appendChild(document.createTextNode('\n')); var k = l.toLowerCase().indexOf(lc); if (k < 0) { pre.appendChild(document.createTextNode(l)); return; } pre.appendChild(document.createTextNode(l.slice(0, k))); pre.appendChild(h('mark', null, l.slice(k, k + q.length))); pre.appendChild(document.createTextNode(l.slice(k + q.length))); });
      }
      if (atEnd || follow.checked) pre.scrollTop = pre.scrollHeight;
    }
    function load() { if (busy) return; busy = true; api('docker_logs', { name: name, lines: lines.value }).then(function (j) { busy = false; text = j.error ? 'Error: ' + j.error : j.log; paint(); }); }
    lines.onchange = load; find.oninput = paint; wrap.onchange = function () { LS.set('wrap', wrap.checked); pre.classList.toggle('wrap', wrap.checked); };
    var dl = btn('Download', function () { var a = h('a', { href: URL.createObjectURL(new Blob([text], { type: 'text/plain' })), download: name + '.log' }); root.appendChild(a); a.click(); root.removeChild(a); }, 'sm', 'download');
    var m = modal('Logs · ' + label('docker', name), h('div', null,
      h('div', { class: 'ftr', style: { 'margin-bottom': '8px' } }, lines, find, h('label', { class: 'switch' }, follow, 'Live'), h('label', { class: 'switch' }, wrap, 'Wrap'), h('span', { class: 'grow' }), btn('Refresh', load, 'sm', 'restart'), dl), pre),
      [btn('Details', function () { m.close(); inspect(name); }, '', 'info'), btn('Close', function () { m.close(); })], true);
    var t = setInterval(function () { if (follow.checked && !document.hidden) load(); }, 3000);
    m.onclose = function () { clearInterval(t); };
    load();
  }
  function inspect(name) {
    var box = h('div', null, h('div', { class: 'progress' }, h('i')));
    var m = modal('Details · ' + label('docker', name), box, [btn('Logs', function () { m.close(); logs(name); }, '', 'logs'), btn('Close', function () { m.close(); })], true);
    api('docker_inspect', { name: name }).then(function (j) {
      clear(box);
      if (j.error) { box.appendChild(h('div', { class: 'banner bad' }, j.error)); return; }
      var kv = function (rows) { return h('div', { class: 'kv2' }, rows.filter(Boolean).map(function (r) { return [h('span', null, r[0]), h('span', null, r[1])]; })); };
      var sub = function (t) { return h('h4', { class: 'muted sm', style: { margin: '14px 0 6px' } }, t); };
      box.appendChild(kv([['Image', j.image + ' (' + j.image_id + ')'], ['Container id', j.id], ['State', j.state + (j.exit ? ' · exit code ' + j.exit : '') + (j.oom ? ' · killed: out of memory' : '')],
        j.state_error ? ['Error', j.state_error] : null, ['Created', j.created ? new Date(j.created * 1000).toLocaleString() : '-'], ['Started', j.started ? new Date(j.started * 1000).toLocaleString() : '-'],
        ['Restarts', j.restarts + (j.restart_policy ? ' · policy ' + j.restart_policy : '')], ['Network', j.network_mode + (j.networks.length ? ' · ' + j.networks.map(function (n) { return n.name + (n.ip ? ' ' + n.ip : ''); }).join(', ') : '')],
        ['Ports', j.ports.join(', ') || 'none'], j.privileged ? ['Privileged', 'yes'] : null]));
      if (j.health.length) { box.appendChild(sub('Health checks')); j.health.forEach(function (x) { box.appendChild(h('div', { class: 'sm', style: { 'margin-bottom': '4px' } }, h('span', { class: 'badge ' + (x.code ? 'bad' : 'ok') }, x.code ? 'failed' : 'ok'), ' ', x.at ? rel(x.at) : '', ' ', h('span', { class: 'muted' }, x.out))); }); }
      box.appendChild(sub('Volumes'));
      box.appendChild(j.mounts.length ? h('div', { class: 'tw' }, h('table', { class: 't' }, h('thead', null, h('tr', null, h('th', null, 'Host path'), h('th', null, 'Container path'), h('th', null, 'Mode'))),
        h('tbody', null, j.mounts.map(function (x) { return h('tr', null, h('td', { class: 'mono' }, x.src), h('td', { class: 'mono' }, x.dst), h('td', null, x.rw ? 'read-write' : 'read-only')); })))) : h('div', { class: 'muted sm' }, 'none'));
      if (j.note) box.appendChild(h('p', { class: 'muted sm', style: { 'margin-top': '12px' } }, j.note));
      else { box.appendChild(sub('Environment variables (names only: values often hold passwords)')); box.appendChild(h('div', { class: 'mono muted' }, j.env.join(', ') || 'none')); }
    });
  }

  /* ---- VMs ---- */
  function vmStats(y) {
    var v = y.vms && y.vms.vms; if (!v) return null;
    var s = { running: 0, stopped: 0, paused: 0, total: v.length };
    v.forEach(function (x) { if (x.state === 'running') s.running++; else if (x.state === 'paused' || x.state === 'pmsuspended') s.paused++; else s.stopped++; });
    return s;
  }
  function vStateBadge(st, busy) {
    if (busy) return h('span', { class: 'badge info' }, busy);
    var m = { running: ['running', 'ok'], 'shut off': ['stopped', 'off'], paused: ['paused', 'warn'], pmsuspended: ['hibernated', 'warn'], 'in shutdown': ['shutting down', 'warn'], crashed: ['crashed', 'bad'] }[st] || [st, 'off'];
    return h('span', { class: 'badge ' + m[1] }, m[0]);
  }
  BUILD.vms = function (r) {
    var V = S.L.vms;
    if (boardOn()) { r.list = r.rows = h('div', { class: 'cgrid', role: 'list' }); r.body.appendChild(r.list); return; }
    r.tools.appendChild(h('div', { class: 'seg', role: 'group', 'aria-label': 'Layout' },
      h('button', { type: 'button', class: V.layout === 'list' ? 'on' : '', title: 'List', 'aria-label': 'List layout', 'aria-pressed': V.layout === 'list' ? 'true' : 'false', onclick: function () { setLayout('vms', 'list'); } }, ic('list')),
      h('button', { type: 'button', class: V.layout === 'cards' ? 'on' : '', title: 'Cards', 'aria-label': 'Card layout', 'aria-pressed': V.layout === 'cards' ? 'true' : 'false', onclick: function () { setLayout('vms', 'cards'); } }, ic('grid'))));
    r.list = V.layout === 'cards' ? h('div', { class: 'tiles', role: 'list' }) : h('div', { class: 'panel compact' + (S.boot.caps.vm_details ? '' : ' slim') }, h('div', { class: 'rowh c', 'aria-hidden': 'true' }, h('span', null, 'Virtual machine'), h('span', null, 'Status'), h('span', { class: 'c-cpu' }, 'vCPUs'), h('span', { class: 'c-mem' }, 'Memory'), h('span', { class: 'c-upd' }, S.boot.caps.vm_details ? 'Guest agent' : ''), h('span', null, '')));
    r.rows = V.layout === 'cards' ? r.list : h('div', { role: 'list' });
    if (V.layout !== 'cards') r.list.appendChild(r.rows);
    r.body.appendChild(r.list);
  };
  var VMOPS = { start: ['Start', 'play', 'okc'], resume: ['Resume', 'play', 'okc'], stop: ['Shut down', 'stop', 'danger'], restart: ['Restart', 'restart', ''], pause: ['Pause', 'pause', ''], hibernate: ['Hibernate', 'moon', ''], kill: ['Force stop', 'power', 'danger'] };
  function paintVms(y) {
    var r = R.vms, v = y.vms;
    if (!v) { if (!r.rows.firstChild) r.rows.appendChild(h('div', { class: 'empty' }, 'Loading VMs…')); return; }
    var hidden = 0;
    var list = v.error ? [] : v.vms.filter(function (x) { var p = pref('vms', x.name); if (p.hidden && !S.showHidden) { hidden++; return false; } return true; });
    var busy = {}; list.forEach(function (x) { busy[x.name] = S.busy['v:' + x.name] || ''; });
    var sig = JSON.stringify([v.error, list, hidden, S.showHidden, S.L.vms, busy]);
    if (r.sig === sig) return; r.sig = sig;
    var focus = r.rows.contains(document.activeElement) ? document.activeElement.getAttribute('aria-label') : null;
    clear(r.rows);
    if (v.error) { r.rows.appendChild(h('div', { class: 'empty' }, v.error)); return; }
    if (!list.length) r.rows.appendChild(h('div', { class: 'empty' }, v.vms.length ? 'All VMs are hidden.' : 'No virtual machines.'));
    list.forEach(function (x) {
      var p = pref('vms', x.name), b = busy[x.name];
      var a = vActions(x, p), vw = vWeb(x), vt = vTip(x, vw, x.os || x.name);
      if (R.cards) { r.rows.appendChild(vCell(x, p, a, vw, vt, b)); return; }
      var who = S.L.vms.layout === 'cards' ? h('div', { class: 'who c-who' }, vA(vw, vt, vIcon(x, p, '')), h('div', { class: 'ell' }, h('div', { class: 'nm' }, vA(vw, vt, h('span', { class: 'ell' }, p.label || x.name)), p.favorite ? ic('star', true) : null), h('div', { class: 'im ell' }, p.description || x.os || '')))
        : h('div', { class: 'who c-who' }, vA(vw, vt, vIcon(x, p, '')), vA(vw, vt, h('span', { class: 'nm ell' }, p.label || x.name, p.favorite ? ic('star', true) : null)));
      var res = x.cpus ? x.cpus + ' vCPU · ' + bytes(x.memory) : '';
      if (S.L.vms.layout === 'cards') r.rows.appendChild(h('div', { class: 'tile' + (b ? ' busy' : ''), role: 'listitem' }, who, h('div', { class: 'chips' }, vStateBadge(x.state, b), x.autostart ? h('span', { class: 'badge plain' }, 'autostart') : null), h('div', { class: 'stats' }, h('span', null, res), h('span', null, x.agent ? 'guest agent' : 'no guest agent')), a));
      else r.rows.appendChild(h('div', { class: 'rowi c' + (b ? ' busy' : ''), role: 'listitem' }, who, h('div', { class: 'c-st' }, vStateBadge(x.state, b)),
        h('span', { class: 'c-cpu num' }, x.cpus ? String(x.cpus) : ''), h('span', { class: 'c-mem num muted' }, x.memory ? bytes(x.memory) : ''),
        h('div', { class: 'c-upd' }, !S.boot.caps.vm_details ? null : x.agent ? h('span', { class: 'badge ok', title: 'Hibernate is available' }, 'agent') : h('span', { class: 'badge plain', title: 'No QEMU guest agent channel: hibernate is not available' }, 'no agent')), a));
    });
    if (hidden || S.showHidden) r.rows.appendChild(h('div', { class: 'more' }, btn(S.showHidden ? 'Hide hidden VMs' : 'Show ' + hidden + ' hidden', function () { S.showHidden = !S.showHidden; if (R.docker) R.docker.fSig = null; r.sig = null; paintAll(); }, 'sm ghost', 'eye')));
    if (focus) { var f = r.rows.querySelector('[aria-label="' + focus.replace(/"/g, '') + '"]'); if (f && !f.disabled) f.focus(); }
  }
  /* the VM's "web UI" is its VNC console */
  /* a VM on the board: a row like a container's. Its icon and name open the console */
  function vCell(x, p, a, vw, vt, b) {
    var on = x.state === 'running', st = vStateBadge(x.state, b);
    if (vw) a.insertBefore(btn(vConsole(vw) ? 'Console' : 'VMs page', function () { vOpen(vw); }, 'sm ghost', 'screen', vt), a.firstChild);
    return h('div', { class: 'ci st-' + (on ? 'running' : 'exited') + (b ? ' busy' : ''), role: 'listitem', 'data-name': x.name }, vA(vw, vt, vIcon(x, p, 'cico')),
      h('div', { class: 'cn' }, h('span', { class: 'cname ell' }, h('span', { class: 'sdot ' + (on ? 'ok' : 'na'), 'aria-hidden': 'true' }), vA(vw, vt, p.label || x.name), p.favorite ? ic('star', true) : null),
        h('span', { class: 'cs' }, h('span', null, st.textContent), x.cpus ? h('span', null, x.cpus + ' vCPU') : null, x.memory ? h('span', null, bytes(x.memory)) : null)), a);
  }
  /* what clicking a VM's icon or name does, said in its tooltip */
  function vTip(x, web, other) {
    if (web) return vConsole(web) ? 'Open the console of ' + x.name + ' (VNC)' : 'Open Unraid\'s VMs page to reach the console of ' + x.name;
    if (x.state !== 'running') return x.name + ' is ' + (x.state || 'stopped') + ': start it from its menu to open the console';
    return other + (S.boot.caps.mode === 'api' ? ' · the console opens from Unraid\'s VMs page' : ' · this VM has no VNC console');
  }
  /* The Unraid API gives no VNC port. Unraid hands out websocket ports from 5700, so a lone running VM has that one; with several
     there is no telling which is which, and Unraid's VMs page is the safe place to go. */
  function vPath(x) {
    if (x.vnc || S.boot.caps.mode !== 'api') return x.vnc;
    var run = (((S.live || {}).vms || {}).vms || []).filter(function (v) { return v.state === 'running'; });
    return run.length === 1 ? '/wsproxy/5700/' : '';
  }
  function vWeb(x) {
    if (x.state !== 'running') return '';
    var path = vPath(x), host = location.hostname;
    if (S.boot.caps.mode === 'api' && !UNRAID) return '';
    if (!path) return S.boot.caps.mode === 'api' ? UNRAID + '/VMs' : '';
    try { if (UNRAID) host = new URL(UNRAID).hostname; } catch (e) { /* keep this page's host */ }
    return UNRAID + '/plugins/dynamix.vm.manager/vnc.html?autoconnect=true&resize=scale&host=' + encodeURIComponent(host) + '&port=&path=' + encodeURIComponent(path);
  }
  function vConsole(url) { return url.indexOf('vnc.html') >= 0; }
  /* the console gets a window of its own */
  function vOpen(url) { if (vConsole(url)) window.open(url, '_blank', 'popup,width=1280,height=800,noopener'); else window.open(url, '_blank', 'noopener'); }
  function vA(url, title, kids) { var a = webA(url, title, kids); if (url) a.onclick = function (e) { e.preventDefault(); vOpen(url); }; return a; }
  function vActions(x, p) {
    var a = h('div', { class: 'acts c-acts' }), web = vWeb(x);
    a.appendChild(gear(x.name, function () {
      var b = !!S.busy['v:' + x.name];
      return [web ? [vConsole(web) ? 'Open console (VNC)' : 'Open Unraid\'s VMs page (console)', 'screen', function () { vOpen(web); }] : null, web ? 'hr' : null]
        .concat(x.ops.map(function (o) { return [VMOPS[o][0] + (o === 'kill' || o === 'hibernate' || o === 'restart' ? '…' : ''), VMOPS[o][1], function () { vAct(x, o); }, o === 'kill' || o === 'stop' ? 'danger' : '', b]; }))
        .concat([x.state === 'running' && !x.agent ? ['Hibernate (needs the QEMU guest agent)', 'moon', function () {}, '', true] : null, 'hr',
          [p.favorite ? 'Remove from favorites' : 'Add to favorites', 'star', function () { savePref('vms', x.name, { favorite: !p.favorite }); }],
          ['Change icon…', 'tag', function () { iconPicker(p.label || x.name, function (v) { savePref('vms', x.name, { icon: v }); }, p.icon || '-'); }],
          [p.hidden ? 'Show on dashboard' : 'Hide from dashboard', p.hidden ? 'eye' : 'eyeoff', function () { savePref('vms', x.name, { hidden: !p.hidden }); }],
          ['Customize…', 'edit', function () { customize('vms', x); }]]);
    }));
    return a;
  }
  function vAct(x, op) {
    var key = 'v:' + x.name; if (S.busy[key]) return;
    var nm = label('vms', x.name);
    var ask = { stop: ['Shut down ' + nm + '?', 'The VM is asked to shut down, like pressing its power button. The guest decides how long that takes.', 'Shut down', true],
      kill: ['Force stop ' + nm + '?', 'This cuts the VM\'s power immediately. Unsaved work inside it is lost and its file system may need repair.', 'Force stop', true],
      hibernate: ['Hibernate ' + nm + '?', 'The VM saves its memory to disk and powers off; Resume continues where it left off. This needs the QEMU guest agent running inside the VM.', 'Hibernate', false],
      restart: ['Restart ' + nm + '?', 'The VM is asked to reboot.', 'Restart', false] }[op];
    var run = function () {
      S.busy[key] = { start: 'starting', stop: 'shutting down', kill: 'stopping', restart: 'restarting', pause: 'pausing', resume: 'resuming', hibernate: 'hibernating' }[op];
      paintAll();
      api('vm_action', { name: x.name, op: op }, 100000).then(function (j) {
        delete S.busy[key];
        if (j.error) toast(nm + ': ' + j.error, true); else toast(nm + ': ' + (op === 'stop' ? 'shutdown requested' : VMOPS[op][0].toLowerCase() + ' done'));
        if (R.vms) R.vms.sig = null;
        refreshNow();
      });
    };
    if (ask) confirmBox(ask[0], ask[1], ask[2], ask[3]).then(function (y) { if (y) run(); }); else run();
  }

  /* ---- media: four equal columns (4 / 2 / 1 by the section's width), each a compact list refreshed on its own ---- */
  var MEDIA = { upcoming_movies: ['Upcoming movies', 'Nothing scheduled in the coming weeks.'], upcoming_episodes: ['Upcoming episodes', 'No episodes airing soon.'],
                recent_movies: ['Recent movies', 'No movies imported recently.'], recent_episodes: ['Recent episodes', 'No episodes imported recently.'] };
  var MEDIA_ORDER = ['upcoming_movies', 'upcoming_episodes', 'recent_movies', 'recent_episodes'];
  BUILD.media = function (r) {
    r.grid = h('div', { class: 'media' + (S.L.media.style === 'text' ? ' text' : '') }); r.w = {};
    var vis = {}; S.L.media.widgets.forEach(function (w) { vis[w.id] = w.visible; });
    MEDIA_ORDER.forEach(function (id) {
      if (vis[id] === false) return;
      var x = { link: h('span'), row: h('div', { class: 'mlist', role: 'list', 'aria-label': MEDIA[id][0], tabindex: '0' }, h('div', { class: 'empty' }, 'Loading…')), count: h('span', { class: 'count' }) };
      x.el = h('div', { class: 'mw card' }, h('div', { class: 'mh' }, h('h3', null, MEDIA[id][0]), x.count, h('span', { class: 'grow' }), x.link), x.row);
      r.w[id] = x; r.grid.appendChild(x.el);
    });
    if (!r.grid.childNodes.length) r.grid.appendChild(h('div', { class: 'card empty' }, 'All media columns are hidden (Settings > Sections & apps > Media).'));
    r.body.classList.add('mbody');
    r.body.appendChild(r.grid);
    clearTimeout(S.mediaTimer); loadMedia(true);
  };
  /* the first load always happens; later refreshes wait while the tab is hidden (visibilitychange catches up) */
  function loadMedia(first) {
    var r = R.media; if (!r) return;
    if (document.hidden && first !== true) { S.mediaStale = true; return; }
    S.mediaStale = false; S.mediaAt = Date.now();
    var ids = Object.keys(r.w), left = ids.length;
    ids.forEach(function (k) {
      api('media', { kind: k }, 30000).then(function (j) { paintMedia(k, j); if (--left === 0) { clearTimeout(S.mediaTimer); S.mediaTimer = setTimeout(loadMedia, 300000); } });
    });
  }
  function paintMedia(k, j) {
    var x = R.media && R.media.w[k]; if (!x) return;
    x.last = j;
    var sig = JSON.stringify(j); if (x.sig === sig) return; x.sig = sig;
    clear(x.row); clear(x.link);
    var mt = x.el.querySelector('h3');
    if (R.cards) { clear(mt); mt.appendChild(j.app && j.app.href ? h('a', { class: 'mhl', href: j.app.href, target: '_blank', rel: 'noopener', title: 'Open ' + j.app.name }, MEDIA[k][0], ic('open')) : document.createTextNode(MEDIA[k][0])); }
    else if (j.app && j.app.href) x.link.appendChild(h('a', { class: 'btn sm ghost', href: j.app.href, target: '_blank', rel: 'noopener', title: 'Open ' + j.app.name, 'aria-label': 'Open ' + j.app.name }, h('span', { class: 'lbl' }, j.app.name), ic('web')));
    if (j.error && !(j.items || []).length) { x.row.appendChild(h('div', { class: 'empty ' + (j.unconfigured ? '' : 'err-t') }, j.error)); txt(x.count, ''); return; }
    var items = k.indexOf('episodes') > 0 ? groupEps(j.items || [], k.indexOf('upcoming') === 0) : j.items || [];
    txt(x.count, String(j.items.length));
    if (!j.items.length) { x.row.appendChild(h('div', { class: 'empty' }, MEDIA[k][1])); return; }
    var upcoming = k.indexOf('upcoming') === 0, text = S.L.media.style === 'text';
    items.forEach(function (it) {
      if (text) { x.row.appendChild(mediaLine(it, upcoming)); return; }
      var d = it.ts ? new Date(it.ts * 1000) : null;
      var when = !d ? '' : upcoming ? (it.when === 'Airs' ? d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString([], { month: 'short', day: 'numeric' })) : rel(it.ts);
      // only what Radarr / Sonarr reported: no year, episode or quality line when they did not send one
      var sub = it.sub || (it.year ? String(it.year) : '');
      var tags = [it.n ? h('span', { class: 'badge plain' }, it.n + ' eps') : null, upcoming && it.when && it.when !== 'Airs' ? h('span', { class: 'badge plain' }, it.when) : null, it.quality ? h('span', { class: 'badge plain' }, it.quality) : null,
        it.done ? h('span', { class: 'badge plain', title: 'Already downloaded' }, 'Downloaded') : null].filter(Boolean);
      x.row.appendChild(h(it.href ? 'a' : 'div', { class: 'mi', role: 'listitem', href: it.href || null, target: it.href ? '_blank' : null, rel: it.href ? 'noopener' : null,
          title: it.title + (it.year ? ' (' + it.year + ')' : '') + (it.list ? '\n' + it.list.join('\n') : it.sub ? '\n' + it.sub : '') + (d ? '\n' + (upcoming ? (it.when === 'Airs' ? 'Airs ' : it.when + ' ') : 'Added ') + d.toLocaleString() : '') },
        h('span', { class: 'mimg' }, it.poster ? h('img', { src: it.poster, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', onerror: function () { this.remove(); } }) : null),
        h('span', { class: 'mt' }, h('span', { class: 'pt' }, it.title), sub ? h('span', { class: 'ps' }, sub) : null,
          h('span', { class: 'pm' }, when ? h('span', { class: 'pd' }, when) : null, tags))));
    });
  }

  /* Episodes of one show next to each other that air at the same time (upcoming) or were added the same day (recent) become
     one row: "S01E08–E12" and an "5 eps" badge, instead of the show five times. */
  function groupEps(items, upcoming) {
    var out = [];
    var day = function (ts) { var d = new Date(ts * 1000); return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); };
    items.forEach(function (it) {
      var g = out[out.length - 1];
      if (g && g.title === it.title && it.ts && g.ts && (upcoming ? g.ts === it.ts : day(g.ts) === day(it.ts))) { g.eps.push(it); return; }
      out.push(Object.assign({}, it, { eps: [it] }));
    });
    return out.map(function (g) {
      if (g.eps.length < 2) return g;
      var codes = g.eps.map(function (e) { var m = /^S(\d+)E(\d+)/.exec(e.sub || ''); return m ? [+m[1], +m[2], m[0]] : null; });
      var ok = codes.every(Boolean) && codes.every(function (c) { return c[0] === codes[0][0]; });
      var nums = ok ? codes.map(function (c) { return c[1]; }).sort(function (a, b) { return a - b; }) : [];
      var pad = function (n) { return (n < 10 ? '0' : '') + n; };
      return Object.assign(g, { n: g.eps.length, sub: ok ? 'S' + pad(codes[0][0]) + 'E' + pad(nums[0]) + '–E' + pad(nums[nums.length - 1]) : g.eps.length + ' episodes',
        done: g.eps.every(function (e) { return e.done; }), quality: g.eps.every(function (e) { return e.quality === g.quality; }) ? g.quality : '',
        list: g.eps.map(function (e) { return e.sub; }).filter(Boolean) });
    });
  }

  /* text only: one line per item, the title with its episode or kind, the date on the right */
  function mediaLine(it, upcoming) {
    var d = it.ts ? new Date(it.ts * 1000) : null;
    var when = !d ? '' : !upcoming ? rel(it.ts) : it.when === 'Airs' ? d.toLocaleDateString([], { weekday: 'short' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    var sub = [it.sub || '', it.n ? it.n + ' eps' : '', upcoming && it.when && it.when !== 'Airs' ? it.when : '', !upcoming && it.quality ? it.quality : ''].filter(Boolean).join(' · ');
    return h(it.href ? 'a' : 'div', { class: 'mi ml', role: 'listitem', href: it.href || null, target: it.href ? '_blank' : null, rel: it.href ? 'noopener' : null,
        title: it.title + (it.year ? ' (' + it.year + ')' : '') + (it.list ? '\n' + it.list.join('\n') : sub ? '\n' + sub : '') + (d ? '\n' + (upcoming ? (it.when === 'Airs' ? 'Airs ' : it.when + ' ') : 'Added ') + d.toLocaleString() : '') + (it.done ? '\nAlready downloaded' : '') },
      h('span', { class: 'pt' }, it.title), sub ? h('span', { class: 'ps' }, sub) : null, h('span', { class: 'grow' }),
      it.done ? h('span', { class: 'ok-t', 'aria-label': 'Downloaded' }, ic('check')) : null, when ? h('span', { class: 'pd' }, when) : null);
  }

  /* ---- sections that load their own data on a timer (paused while the browser tab is hidden) ---- */
  var POLL = {};
  function poller(key, action, every, paint, data) {
    clearTimeout((POLL[key] || {}).t);
    POLL[key] = { action: action, every: every, paint: paint, data: data || null, stale: false, t: null };
    pollRun(key, true);
  }
  function pollRun(key, first, extra) {
    var p = POLL[key]; if (!p || !R[key]) return;
    clearTimeout(p.t);
    if ((document.hidden || S.paused) && !first) { p.stale = true; return; }
    p.stale = false;
    api(p.action, Object.assign({}, p.data || {}, extra || {}), 30000).then(function (j) {
      if (POLL[key] !== p) return;                              // the section was rebuilt meanwhile
      p.last = j; p.paint(j);
      p.t = setTimeout(function () { pollRun(key); }, p.every);
    });
  }
  function pollKick(key, extra) { if (POLL[key]) pollRun(key, true, extra); }

  /* ---- Now playing ---- */
  var METHOD = { 'direct play': ['Direct play', 'ok'], 'direct stream': ['Direct stream', 'info'], transcode: ['Transcode', 'warn'] };
  BUILD.playing = function (r) {
    r.list = h('div', { class: 'plist', role: 'list' }, h('div', { class: 'empty' }, 'Loading…'));
    r.sum = h('span', { class: 'muted sm' }); r.tools.appendChild(r.sum);
    r.body.appendChild(r.list);
    poller('playing', 'playing', 10000, paintPlaying);
  };
  function paintPlaying(j) {
    var r = R.playing; if (!r) return;
    var sig = JSON.stringify(j); if (r.sig === sig) return; r.sig = sig;
    clear(r.list); clear(r.sum);
    if (j.error) { r.list.appendChild(h('div', { class: 'empty err-t' }, j.error)); return; }
    if (j.unconfigured) { r.list.appendChild(h('div', { class: 'empty' }, 'Add Plex, Emby, Jellyfin or Tautulli in Settings > Sections & apps to see who is watching.')); txt(r.count, ''); return; }
    var bw = 0, tc = 0;
    j.streams.forEach(function (x) { if (x.bandwidth) bw += x.bandwidth; if (x.method === 'transcode') tc++; });
    txt(r.count, j.streams.length ? String(j.streams.length) : '');
    var who = {}; j.streams.forEach(function (x) { if (x.user) who[x.user] = 1; }); var people = Object.keys(who).length;
    if (j.streams.length) r.sum.appendChild(document.createTextNode([people > 1 ? people + ' people' : '', tc ? tc + ' transcoding' : '', bw ? rate(bw / 8) : ''].filter(Boolean).join(' · ')));
    (j.apps || []).filter(function (a) { return a.error; }).forEach(function (a) { r.list.appendChild(h('div', { class: 'empty err-t' }, a.name + ': ' + a.error)); });
    if (!j.streams.length) { r.list.appendChild(h('div', { class: 'empty' }, 'Nothing is playing right now.')); return; }
    j.streams.forEach(function (x) { r.list.appendChild(playRow(x, true)); });
  }
  /* one stream, as in Now playing; app cards use it without the app icon. Custom API streams may only have title, user, pos and dur. */
  function userChip(name) {
    var n = 0; for (var i = 0; i < name.length; i++) n = (n * 31 + name.charCodeAt(i)) % 360;
    return h('span', { class: 'pu', title: name }, h('i', { style: { background: 'hsl(' + n + ' 55% 42%)' }, 'aria-hidden': 'true' }, name.charAt(0).toUpperCase()), h('span', { class: 'ell' }, name));
  }
  function playRow(x, icon) {
    var m = METHOD[x.method], p = x.dur ? Math.min(100, 100 * x.pos / x.dur) : 0;
    return h('div', { class: 'pl' + (icon ? '' : ' in') + (x.state === 'paused' ? ' paused' : ''), role: 'listitem', 'data-name': x.title },
      icon ? img(iconUrl(x.icon), 'aico', 'play') : null,
      h('div', { class: 'pt' },
        h('div', { class: 'pl0' }, h('div', { class: 'pl1' }, h('b', { class: 'ell', title: x.title + (x.sub ? ' · ' + x.sub : '') }, x.title), x.sub ? h('span', { class: 'muted sm ell' }, x.sub) : null), icon && x.user ? userChip(x.user) : null),
        h('div', { class: 'pl2 muted xs' }, ic(x.state === 'paused' ? 'pause' : 'play'), h('span', { class: 'ell' }, [icon ? '' : x.user, x.device, x.app].filter(Boolean).join(' · '))),
        x.dur ? h('div', { class: 'meter', 'aria-hidden': 'true' }, h('i', { class: 'acc', style: { width: p + '%' } })) : null,
        h('div', { class: 'pl3 xs' }, x.dur ? h('span', { class: 'num muted' }, dur(x.pos) + ' / ' + dur(x.dur)) : null,
          m ? h('span', { class: 'badge ' + m[1] }, m[0]) : null, x.quality ? h('span', { class: 'badge plain' }, x.quality) : null,
          x.bandwidth ? h('span', { class: 'num muted' }, rate(x.bandwidth / 8)) : null, x.location === 'wan' ? h('span', { class: 'badge plain', title: 'Streaming outside your network' }, 'remote') : null)));
  }

  /* ---- Requests (Overseerr / Jellyseerr) ---- */
  BUILD.requests = function (r) {
    r.list = h('div', { class: 'rqlist', role: 'list' }, h('div', { class: 'empty' }, 'Loading…'));
    r.link = h('span'); r.tools.appendChild(r.link);
    r.body.appendChild(r.list);
    poller('requests', 'requests', 60000, paintRequests);
  };
  function paintRequests(j) {
    var r = R.requests; if (!r) return;
    var sig = JSON.stringify([j, S.busy]); if (r.sig === sig) return; r.sig = sig;
    clear(r.list); clear(r.link);
    if (j.error) { r.list.appendChild(h('div', { class: 'empty err-t' }, j.error)); return; }
    if (j.unconfigured) { r.list.appendChild(h('div', { class: 'empty' }, 'Add Overseerr or Jellyseerr in Settings > Sections & apps to see and approve requests here.')); txt(r.count, ''); return; }
    txt(r.count, j.pending ? j.pending + ' pending' : '');
    (j.apps || []).forEach(function (a) {
      if (a.error) r.list.appendChild(h('div', { class: 'empty err-t' }, a.name + ': ' + a.error));
      else if (a.href && j.apps.length === 1 && R.cards) r.link.appendChild(h('a', { class: 'btn ic sm ghost', href: a.href, target: '_blank', rel: 'noopener', title: 'Open ' + a.name, 'aria-label': 'Open ' + a.name }, ic('open')));
      else if (a.href && j.apps.length === 1) r.link.appendChild(h('a', { class: 'btn sm ghost', href: a.href, target: '_blank', rel: 'noopener', title: 'Open ' + a.name }, h('span', { class: 'lbl' }, a.name), ic('web')));
    });
    if (!j.items.length) { r.list.appendChild(h('div', { class: 'empty' }, 'No requests yet.')); return; }
    var text = (S.L.requests || {}).style === 'text';
    r.list.classList.toggle('text', text);
    j.items.forEach(function (x) {
      var key = 'rq:' + x.app + ':' + x.id, busy = !!S.busy[key];
      var title = x.title + (x.year ? ' (' + x.year + ')' : '');
      var what = (x.type === 'tv' ? 'Series' + (x.seasons.length ? ' · season ' + x.seasons.join(', ') : '') : 'Movie') + (x.is4k ? ' · 4K' : '');
      var meta = [what, x.user, x.at ? rel(x.at) : ''].filter(Boolean).join(' · ');
      r.list.appendChild(h('div', { class: 'rq' + (x.status === 'pending' ? ' pend' : '') + (text ? ' line' : ''), role: 'listitem', 'data-name': x.title },
        text ? null : h(x.href ? 'a' : 'span', { class: 'rqp', href: x.href || null, target: x.href ? '_blank' : null, rel: x.href ? 'noopener' : null, title: title },
          x.poster ? h('img', { src: x.poster, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', onerror: function () { this.remove(); } }) : null),
        text ? h('div', { class: 'rqt' }, h(x.href ? 'a' : 'b', { class: 'ell', href: x.href || null, target: x.href ? '_blank' : null, rel: x.href ? 'noopener' : null, title: title + '\n' + meta }, title), h('span', { class: 'muted xs ell' }, meta))
          : h('div', { class: 'rqt' }, h('b', { class: 'ell', title: title }, title), h('span', { class: 'muted xs ell' }, meta)),
        x.status === 'pending' ? h('div', { class: 'rqa' },
          btn('Approve', function () { reqAct(x, 'approve'); }, 'sm pri', 'check', 'Approve ' + title),
          btn('', function () { confirmBox('Decline ' + title + '?', (x.user || 'The requester') + ' asked for it ' + (x.at ? rel(x.at) : '') + '. Declining tells them in ' + x.app_name + ' and cannot be undone here.', 'Decline', true).then(function (y) { if (y) reqAct(x, 'decline'); }); }, 'sm danger', 'x', 'Decline ' + title))
          : h('span', { class: 'badge ' + x.level }, x.status_label)));
      if (busy) [].forEach.call(r.list.lastChild.querySelectorAll('button'), function (b) { b.disabled = true; });
    });
  }
  function reqAct(x, op) {
    var key = 'rq:' + x.app + ':' + x.id; if (S.busy[key]) return;
    S.busy[key] = op; if (POLL.requests && POLL.requests.last) { R.requests.sig = null; paintRequests(POLL.requests.last); }
    act('request_action', { app: x.app, id: x.id, op: op }, (op === 'approve' ? 'Approved: ' : 'Declined: ') + x.title).then(function () { delete S.busy[key]; pollKick('requests'); });
  }

  /* ---- Endpoint health ---- */
  BUILD.health = function (r) {
    r.sum = h('span', { class: 'muted sm' });
    r.tools.appendChild(r.sum);
    r.tools.appendChild(btn('Re-probe', function () { txt(r.sum, 'Probing…'); pollKick('health', { force: '1' }); }, 'ghost sm', 'restart', 'Probe every endpoint again now'));
    r.table = h('div', { class: 'panel htab', role: 'table', 'aria-label': 'Endpoint health' },
      h('div', { class: 'hrow hh', role: 'row' }, h('span', { role: 'columnheader' }, 'Service'), h('span', { role: 'columnheader' }, 'Address'), h('span', { role: 'columnheader' }, 'Response'), h('span', { role: 'columnheader', class: 'r' }, 'Latency')),
      r.rows = h('div', { role: 'rowgroup' }, h('div', { class: 'empty' }, 'Probing…')));
    r.body.appendChild(r.table);
    poller('health', 'health', 60000, paintHealth);
  };
  /* the full list, for when you want to look: on the board it has no card of its own */
  function endpointsDialog() {
    var r = R.health, m;
    m = modal('Endpoints', h('div', null, h('p', { class: 'muted sm' }, r.sum), r.table), [btn('Check again', function () { txt(r.sum, 'Checking…'); pollKick('health', { force: '1' }); }, '', 'restart'), btn('Close', function () { m.close(); })], true);
  }
  function paintHealth(j) {
    var r = R.health; if (!r) return;
    if (j.error) { clear(r.rows).appendChild(h('div', { class: 'empty err-t' }, j.error)); return; }
    S.down = (j.rows || []).filter(function (x) { return x.level === 'bad'; });
    if (R.alerts) paintAlerts(S.live || {});
    var bad = j.total - j.up;
    clear(r.sum);
    r.sum.appendChild(h('span', { class: bad ? 'bad-t' : 'ok-t' }, j.up + '/' + j.total + ' reachable'));
    r.sum.appendChild(document.createTextNode(' · checked ' + rel(j.at)));
    txt(r.count, j.total ? String(j.total) : '');
    var sig = JSON.stringify(j.rows); if (r.sig === sig) return; r.sig = sig;
    clear(r.rows);
    if (!j.rows.length) { r.rows.appendChild(h('div', { class: 'empty' }, 'Nothing to probe yet: add links to your apps (Settings > Sections & apps) or give containers a web UI.')); return; }
    j.rows.forEach(function (x) {
      r.rows.appendChild(h('div', { class: 'hrow ' + x.level, role: 'row', 'data-name': x.name },
        h('span', { class: 'hn', role: 'cell' }, h('span', { class: 'sdot ' + x.level, role: 'img', 'aria-label': x.level }), img(iconUrl(x.icon), 'aico', x.kind === 'container' ? 'box' : 'tag'),
          h('b', { class: 'ell' }, x.name), h('span', { class: 'muted xs ell' }, x.kind === 'container' ? 'container' : x.group)),
        h('span', { role: 'cell', class: 'ell' }, h('a', { href: x.url, target: '_blank', rel: 'noopener', class: 'num' }, x.hostport)),
        h('span', { role: 'cell' }, h('span', { class: 'badge ' + x.level }, x.text)),
        h('span', { role: 'cell', class: 'r num' }, x.ms === null ? '–' : x.ms + ' ms')));
    });
    if (!r.rows.childNodes.length) r.rows.appendChild(h('div', { class: 'empty' }, 'No endpoint matches the filter.'));
  }

  /* ---- Arr Stack: one compact row per app, details on demand ---- */
  function zoneApps(zone) { var out = []; S.L.groups.forEach(function (g) { g.services.forEach(function (s) { if (s.zone === zone && !s.hidden) out.push(s); }); }); return out; }
  BUILD.arr = function (r) {
    var apps = zoneApps('arr');
    r.list = h('div', { class: 'panel arr', role: 'list' }); r.rows = {};
    if (!apps.length) r.list.appendChild(h('div', { class: 'empty' }, 'No Arr apps yet. Add Radarr, Sonarr, Prowlarr… in Settings > Sections & apps.'));
    apps.forEach(function (s) {
      var x = { s: s, dot: h('span', { class: 'sdot na', role: 'img', 'aria-label': 'checking' }), state: h('span', { class: 'ell' }, 'Checking…'), info: h('span', { class: 'ai' }), chev: h('span', { class: 'achev', hidden: true }, ic('chev')), det: h('div', { class: 'adet', hidden: true }) };
      var tog = h('button', { type: 'button', class: 'arow', 'aria-expanded': 'false', onclick: function () { if (x.chev.hidden) return; x.det.hidden = !x.det.hidden; tog.setAttribute('aria-expanded', x.det.hidden ? 'false' : 'true'); if (R.cards) boardAutoSoon(); } },
        img(iconUrl(s.icon), 'aico', 'tag'), h('span', { class: 'ahd' }, h('span', { class: 'an ell' }, s.name), h('span', { class: 'asub' }, x.dot, x.state)), x.info, x.chev);
      x.tog = tog;
      x.el = h('div', { class: 'aitem', role: 'listitem', 'data-name': s.name }, h('div', { class: 'aline' }, tog, s.href_resolved ? h('a', { class: 'btn ic sm ghost', href: s.href_resolved, target: '_blank', rel: 'noopener', title: 'Open ' + s.name, 'aria-label': 'Open ' + s.name }, ic('open')) : null), x.det);
      r.rows[s.id] = x; r.list.appendChild(x.el);
    });
    r.body.appendChild(r.list);
    txt(r.count, apps.length ? String(apps.length) : '');
    clearTimeout(S.arrTimer); loadArr(true);
  };
  function loadArr(first) {
    var r = R.arr; if (!r) return;
    var ids = Object.keys(r.rows);
    if (!ids.length) return;
    if (document.hidden && first !== true) { S.arrStale = true; return; }
    S.arrStale = false;
    var left = ids.length, i = 0;
    function next() {
      if (i >= ids.length) return;
      var id = ids[i++];
      api('arr', { id: id }, 30000).then(function (j) { paintArr(id, j); next(); if (--left === 0) { clearTimeout(S.arrTimer); S.arrTimer = setTimeout(loadArr, Math.max(30, S.L.general.refresh_services) * 1000); } });
    }
    for (var k = 0; k < 3; k++) next();
  }
  function paintArr(id, j) {
    var x = R.arr && R.arr.rows[id]; if (!x) return;
    var sig = JSON.stringify(j); if (x.sig === sig) return; x.sig = sig;
    var issues = (j.health || []).length + (j.issues || []).length, bad = (j.health || []).concat(j.issues || []).some(function (z) { return z.level === 'bad'; });
    var state = !j.online ? (j.unconfigured ? 'na' : 'bad') : issues ? (bad ? 'bad' : 'warn') : 'ok';
    x.dot.className = 'sdot ' + state; x.dot.setAttribute('aria-label', !j.online ? (j.unconfigured ? 'not configured' : 'offline') : issues ? issues + ' issues' : 'online');
    clear(x.info);
    x.state.className = 'ell ' + (!j.online ? (j.unconfigured ? 'muted' : 't-bad') : issues ? (bad ? 't-bad' : 't-warn') : '');
    txt(x.state, !j.online ? (j.preview ? 'Loads once you save' : j.unconfigured ? 'Not configured' : 'Offline') : issues ? issues + (issues === 1 ? ' issue' : ' issues') : j.version ? 'v' + j.version : 'Healthy');
    var list = j.online ? j.blocks || [] : [];
    list.slice(0, 3).forEach(function (b) { x.info.appendChild(h('span', { class: 'st', title: b.label + ': ' + fmt(b.value, b.fmt) }, h('b', null, blkVal(b)), h('span', { class: 'ell' }, b.label))); });
    clear(x.det);
    if (j.error && !j.preview) x.det.appendChild(h('div', { class: 'sm t-bad' }, j.error));
    // the row shows three numbers: the rest of them and the problems open under it
    if (list.length > 3) x.det.appendChild(h('div', { class: 'amore' }, list.slice(3).map(function (b) { return h('span', { class: 'st' }, h('b', null, blkVal(b)), ' ' + b.label.toLowerCase()); })));
    if (issues) {
      var box = h('div', { class: 'aiss', role: 'list', tabindex: '0', 'aria-label': 'Problems' });
      var rows = (j.health || []).map(function (z) { return [z.level, z.tag || z.source, '', z.msg, 0, z.source ? z.source + ': ' + z.msg : z.msg]; })
        .concat((j.issues || []).map(function (z) { return [z.level, z.tag, z.title, z.msg, z.count, z.title + ' · ' + z.msg + (z.releases && z.releases.length ? '\n\n' + z.releases.join('\n') + (z.count > z.releases.length ? '\n…' : '') : '')]; }));
      rows.filter(function (z) { return z[0] === 'bad'; }).concat(rows.filter(function (z) { return z[0] !== 'bad'; })).forEach(function (z) { box.appendChild(arrIssue.apply(null, z)); });
      x.det.appendChild(box);
    }
    x.chev.hidden = !x.det.childNodes.length;
    if (x.chev.hidden) { x.det.hidden = true; x.tog.setAttribute('aria-expanded', 'false'); }
  }

  /* a problem row: what is wrong (one line, click to show it whole), its kind, ×count */
  function arrIssue(level, tag, what, msg, count, tip) {
    var row = h('div', { class: 'ais ' + level, role: 'listitem', title: tip });
    row.appendChild(h('span', { class: 'sdot ' + level, 'aria-hidden': 'true' }));
    var t = h('span', { class: 'ais-t', tabindex: '0', role: 'button', 'aria-expanded': 'false', onclick: function () { var o = row.classList.toggle('open'); t.setAttribute('aria-expanded', o ? 'true' : 'false'); },
      onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); t.click(); } } },
      what ? h('b', null, what) : null, what && msg && msg !== tag ? ' · ' : null, msg && msg !== tag ? h('span', { class: 'muted' }, msg) : null);
    row.appendChild(t);
    row.appendChild(h('span', { class: 'ais-k' }, tag || (level === 'bad' ? 'Error' : 'Warning')));
    if (count > 1) row.appendChild(h('span', { class: 'muted xs num', 'aria-label': count + ' items' }, '×' + count));
    return row;
  }

  /* ---- download clients ---- */
  BUILD.downloads = function (r) {
    var apps = zoneApps('downloads');
    r.list = h('div', { class: 'dls' }); r.c = {};
    if (!apps.length) r.list.appendChild(h('div', { class: 'card empty' }, 'No download clients yet. Add qBittorrent, qui or SABnzbd in Settings > Sections & apps.'));
    apps.forEach(function (s) {
      var x = { s: s, head: h('div', { class: 'dlh' }), body: h('div', { class: 'dlb' }, h('div', { class: 'muted sm' }, 'Loading…')) };
      x.el = h('div', { class: 'card dl', 'data-name': s.name }, x.head, x.body);
      r.c[s.id] = x; r.list.appendChild(x.el);
    });
    r.body.appendChild(r.list);
    clearTimeout(S.dlTimer); loadDl(true);
  };
  function loadDl(first) {
    var r = R.downloads; if (!r) return;
    var ids = Object.keys(r.c);
    if (!ids.length) return;
    if ((document.hidden || S.paused) && first !== true) { S.dlStale = true; return; }
    S.dlStale = false;
    var left = ids.length;
    ids.forEach(function (id) { api('dl', { id: id }, 20000).then(function (j) { paintDl(id, j); if (--left === 0) { clearTimeout(S.dlTimer); S.dlTimer = setTimeout(loadDl, Math.max(3, S.L.general.refresh) * 1000 * 2); } }); });
  }
  var DLSTATE = { downloading: ['Downloading', 'ok'], stalled: ['Stalled', 'warn'], paused: ['Paused', 'off'], idle: ['Idle', 'off'], disconnected: ['Disconnected', 'bad'] };
  function paintDl(id, j) {
    var x = R.downloads && R.downloads.c[id]; if (!x) return;
    var busy = S.busy['dl:' + id] || '';
    var sig = JSON.stringify([j, busy, S.L.downloads.items]); if (x.sig === sig) return; x.sig = sig;
    var s = x.s, st = j.preview ? ['Not saved yet', 'off'] : j.error ? ['Unreachable', 'bad'] : (DLSTATE[j.state] || [j.state, 'off']);
    clear(x.head); clear(x.body);
    var paused = j.state === 'paused' || j.paused;
    x.head.appendChild(img(iconUrl(s.icon), 'aico', 'download'));
    x.head.appendChild(h('span', { class: 'an' }, s.name));
    x.head.appendChild(h('span', { class: 'badge ' + st[1] }, busy || (j.unconfigured && !j.preview ? 'Not configured' : st[0])));
    x.head.appendChild(h('span', { class: 'grow' }));
    if (j.online && j.can_pause) x.head.appendChild(btn('', function () { dlAct(s, id, paused ? 'resume' : 'pause'); }, 'sm' + (paused ? ' okc' : ''), paused ? 'play' : 'pause', (paused ? 'Resume all in ' : 'Pause all in ') + s.name));
    if (s.href_resolved) x.head.appendChild(h('a', { class: 'btn ic sm ghost', href: s.href_resolved, target: '_blank', rel: 'noopener', title: 'Open ' + s.name, 'aria-label': 'Open ' + s.name }, ic('open')));
    if (busy) x.head.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    if (j.error) { x.body.appendChild(h('div', { class: j.preview ? 'sm muted' : 'sm t-bad' }, j.error)); return; }
    var c = j.counts || {};
    x.body.appendChild(h('div', { class: 'dlspeed' }, h('span', null, h('b', null, rate(j.down)), ' ↓'), j.up !== null && j.up !== undefined ? h('span', null, h('b', null, rate(j.up)), ' ↑') : null, j.eta && j.eta !== '0:00:00' ? h('span', { class: 'muted' }, j.eta + ' left') : null));
    var chips = [['downloading', 'active', ''], ['stalled', 'stalled', 'warn'], ['queued', 'queued', ''], ['paused', 'paused', 'off'], ['seeding', 'seeding', ''], ['idle', 'idle', 'off'], ['crossseed', 'cross-seed', ''],
      ['checking', 'checking', ''], ['completed', 'done', ''], ['tracker', 'tracker issues', 'warn'], ['error', 'errors', 'bad']];
    if (j.type === 'qui') chips = chips.filter(function (z) { return z[0] !== 'completed'; });   // in qui nearly everything is complete: seeding says more
    x.body.appendChild(h('div', { class: 'chips' }, chips.filter(function (z) { return c[z[0]]; }).map(function (z) { return h('span', { class: 'badge plain ' + z[2] }, h('b', null, c[z[0]].toLocaleString()), ' ' + z[1]); })));
    if (j.note) x.body.appendChild(h('div', { class: 'xs t-warn' }, j.note));
    // qui: one line per qBittorrent instance
    if ((j.instances || []).length) x.body.appendChild(h('div', { class: 'dl-inst' }, j.instances.map(function (i) {
      return h('div', null, h('span', { class: 'sdot ' + (i.online ? 'ok' : 'bad'), role: 'img', 'aria-label': i.online ? 'connected' : 'not connected' }), h('span', { class: 'ell' }, i.name),
        i.online ? h('span', { class: 'muted num' }, rate(i.down) + ' ↓ · ' + rate(i.up) + ' ↑ · ' + (i.counts.seeding || 0).toLocaleString() + ' seeding') : h('span', { class: 't-bad xs ell' }, i.error || 'not connected'));
    })));
    (j.items || []).slice(0, S.L.downloads.items).forEach(function (it) {
      x.body.appendChild(h('div', { class: 'dli', title: it.name },
        h('div', { class: 'dln' }, h('span', { class: 'ell' }, it.name), h('span', { class: 'muted xs num' }, it.progress.toFixed(it.progress < 10 ? 1 : 0) + '%' + (it.eta ? ' · ' + dur(it.eta) : '') + (it.speed ? ' · ' + rate(it.speed) : '') + (it.state !== 'downloading' ? ' · ' + it.state : ''))),
        h('div', { class: 'meter' }, h('i', { class: it.state === 'stalled' ? 'warn' : it.state === 'paused' ? 'off' : 'acc', style: { width: Math.min(100, it.progress) + '%' } }))));
    });
  }
  function dlAct(s, id, op) {
    var key = 'dl:' + id; if (S.busy[key]) return;
    confirmBox((op === 'pause' ? 'Pause' : 'Resume') + ' all downloads in ' + s.name + '?', op === 'pause' ? 'Every download and upload in ' + s.name + ' is paused until you resume it.' : 'Everything paused in ' + s.name + ' starts again.', op === 'pause' ? 'Pause all' : 'Resume all').then(function (y) {
      if (!y) return;
      S.busy[key] = op === 'pause' ? 'pausing' : 'resuming'; R.downloads.c[id].sig = null;
      act('dl_action', { id: id, op: op }, s.name + ': ' + (op === 'pause' ? 'paused' : 'resumed')).then(function () { delete S.busy[key]; clearTimeout(S.dlTimer); loadDl(); });
    });
  }

  /* ---- apps: everything that is not Arr, a download client, media or already in the overview ---- */
  var NATIVE = { system_info: 1, cpu: 1, memory: 1, link: 1 };
  /* apps: one flat grid of compact cards (group titles and shortcut links only when turned on in Settings) */
  BUILD.apps = function (r) {
    var A = S.L.apps, closed = LS.get('closed', {});
    var groups = S.L.groups.map(function (g) { return { g: g, s: g.services.filter(function (s) { return s.zone === 'apps' && !s.hidden && (A.links || s.type !== 'link'); }) }; }).filter(function (x) { return x.s.length; });
    if (!groups.length) { r.body.appendChild(h('div', { class: 'card empty' }, 'No other apps to show. Add Plex, Immich, AdGuard… in ', h('a', { href: '#', onclick: function (e) { e.preventDefault(); openSettings('services'); } }, 'Settings > Sections & apps'), '.')); return; }
    if (groups.length > 1) r.tools.appendChild(h('div', { class: 'seg', role: 'group', 'aria-label': 'Grouping' },
      h('button', { type: 'button', class: A.headers ? 'on' : '', title: 'Apps in their groups, with the group names', 'aria-pressed': A.headers ? 'true' : 'false', onclick: function () { setAppGroups(true); } }, 'Groups'),
      h('button', { type: 'button', class: A.headers ? '' : 'on', title: 'All apps in one grid', 'aria-pressed': A.headers ? 'false' : 'true', onclick: function () { setAppGroups(false); } }, 'One grid')));
    var grid = function () { var g = h('div', { class: 'agrid' + (A.columns ? ' fixed' : ''), role: 'list' }); if (A.columns) g.style.setProperty('--ac', String(A.columns)); return g; };
    if (!A.headers || boardOn()) {
      var all = grid(), links = h('div', { class: 'alinks' });
      groups.forEach(function (x) { x.s.forEach(function (s) { (s.type === 'link' ? links : all).appendChild(widget(s)); }); });
      if (all.childNodes.length) r.body.appendChild(all);
      if (links.childNodes.length) r.body.appendChild(links);
    } else groups.forEach(function (x) {
      var gr = x.g, shut = gr.header && closed[gr.id], wrap = h('div', { class: 'grp' + (shut ? ' closed' : '') }), gd = grid(), links = h('div', { class: 'alinks' });
      if (gr.header) wrap.appendChild(h('h3', null, h('button', { type: 'button', class: 'gt', 'aria-expanded': shut ? 'false' : 'true', onclick: function (e) { closed[gr.id] = !closed[gr.id]; LS.set('closed', closed); wrap.classList.toggle('closed'); e.currentTarget.setAttribute('aria-expanded', closed[gr.id] ? 'false' : 'true'); } }, ic('chev'), gr.name),
        gear('group ' + gr.name, function () { return [['Rename group…', 'edit', function () { renameGroup(gr); }], ['Hide the group name', 'eyeoff', function () { groupPref(gr, { header: false }, 'Group name hidden. Show it again in Settings > Sections & apps > Apps.'); }], 'hr', ['Show all apps in one grid', 'grid', function () { setAppGroups(false); }]]; })));
      x.s.forEach(function (s) { (s.type === 'link' ? links : gd).appendChild(widget(s)); });
      if (gd.childNodes.length) wrap.appendChild(gd);
      if (links.childNodes.length) wrap.appendChild(links);
      r.body.appendChild(wrap);
    });
    txt(r.count, String(groups.reduce(function (n, x) { return n + x.s.length; }, 0)));
    clearTimeout(S.svcTimer); S.svcRun = false;
    setTimeout(pollServices, 0);
  };
  function setAppGroups(on) {
    if (!!S.L.apps.headers === on) return;
    S.L.apps.headers = on; shell();
    api('prefs', { kind: 'apps_view', value: { headers: on } }).then(function (j) { if (j.error) toast(j.error, true); });
  }
  function groupPref(gr, val, msg) {
    Object.assign(gr, val); shell();
    return api('prefs', { kind: 'group', key: gr.id, value: val }).then(function (j) { if (j.error) { toast(j.error, true); return; } S.L = j.layout; shell(); if (msg) toast(msg); });
  }
  function renameGroup(gr) {
    var inp = h('input', { type: 'text', value: gr.name, maxlength: 60, 'aria-label': 'Group name', onkeydown: function (e) { if (e.key === 'Enter') ok(); } }), m;
    var ok = function () { var n = inp.value.trim(); if (!n) { inp.focus(); return; } m.close(); if (n !== gr.name) groupPref(gr, { name: n }); };
    m = modal('Rename group', h('div', null, F('Name', inp)), [btn('Cancel', function () { m.close(); }), btn('Save', ok, 'pri')]);
    setTimeout(function () { inp.focus(); inp.select(); }, 0);
  }
  function widget(s) {
    var u = iconUrl(s.icon), href = s.href_resolved || '';
    if (s.type === 'link') {
      return h(href ? 'a' : 'span', { class: 'alink', 'data-sid': s.id, href: href || null, target: href ? '_blank' : null, rel: href ? 'noopener' : null, title: s.description || href, 'data-name': s.name }, img(u, 'aico', 'tag'), s.name);
    }
    var nm = href ? h('a', { class: 'nm', href: href, target: '_blank', rel: 'noopener' }, s.name) : h('span', { class: 'nm' }, s.name);
    var c = h('div', { class: 'card w ac wait' + (s.span === 2 ? ' span2' : ''), id: 'w-' + s.id, 'data-sid': s.id, 'data-name': s.name, role: 'listitem' },
      h('div', { class: 'ch' }, img(u, 'aico', 'tag'), nm, h('span', { class: 'sdot na', role: 'img', 'aria-label': 'loading' }), h('span', { class: 'sub' }),
        href ? h('a', { class: 'btn ic sm ghost', href: href, target: '_blank', rel: 'noopener', title: 'Open ' + s.name, 'aria-label': 'Open ' + s.name }, ic('open')) : null),
      s.description ? h('div', { class: 'desc' }, s.description) : null,
      h('div', { class: 'cb' }, NATIVE[s.type] ? h('div', { class: 'muted sm' }, 'Shown in the overview now; you can remove this widget.') : h('div', { class: 'muted sm' }, 'Loading…')));
    var prev = S.svc[s.id]; if (prev) setTimeout(function () { paintService(s, prev); }, 0);
    return c;
  }
  /* app widgets: at most 4 requests at a time; the next round starts only after the last answer */
  function pollServices() {
    if (S.svcRun || !R.apps || S.power || (document.hidden && S.L.general.background_poll !== 'normal')) return;
    var list = [];
    S.L.groups.forEach(function (g) { g.services.forEach(function (s) { if (s.zone === 'apps' && !NATIVE[s.type] && !s.hidden) list.push(s); }); });
    if (!list.length) return;
    S.svcRun = true;
    var i = 0, running = 0;
    function next() {
      if (i >= list.length) { if (running === 0) { S.svcRun = false; clearTimeout(S.svcTimer); S.svcTimer = setTimeout(pollServices, S.L.general.refresh_services * 1000); } return; }
      var s = list[i++]; running++;
      api('service', { id: s.id }, 25000).then(function (r) { running--; S.svc[s.id] = r; paintService(s, r); next(); });
    }
    for (var k = 0; k < 4; k++) next();
  }

  /* stat blocks: big numbers are shortened (45.3K) so they fit, the exact value is in the tooltip */
  var CMP = window.Intl ? new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }) : null;
  function blkVal(x) {
    var n = typeof x.value === 'number' ? x.value : (typeof x.value === 'string' && /^-?\d+(\.\d+)?$/.test(x.value) ? +x.value : null);
    if (CMP && n !== null && Math.abs(n) >= 10000 && (!x.fmt || x.fmt === 'n' || x.fmt === 'text')) return CMP.format(n);
    if (x.fmt === 'bytes') return bytes(x.value).replace(/^(\d{2,})\.\d+ /, '$1 ');      // 54 GiB, not 54.0 GiB
    return fmt(x.value, x.fmt);
  }
  function blk(x) { var full = fmt(x.value, x.fmt); return h('div', { class: 'blk', title: x.label + ': ' + full }, h('div', { class: 'v' }, blkVal(x)), h('div', { class: 'l' }, x.label)); }
  function blocks(list) { return h('div', { class: 'blocks' + (list.length === 4 ? ' even' : '') }, list.map(blk)); }
  function paintService(s, r) {
    var c = document.getElementById('w-' + s.id); if (!c) return;
    c.classList.remove('wait');
    var dot = c.querySelector('.ch .sdot');
    if (dot) { dot.className = 'sdot ' + (r.error ? (r.unconfigured ? 'na' : 'bad') : 'ok'); dot.setAttribute('aria-label', r.error ? (r.unconfigured ? 'not configured' : 'error') : 'online'); }
    var cb = clear(c.querySelector('.cb'));
    if (r.error) {
      var e = h('button', { type: 'button', class: 'err' + (r.unconfigured ? ' cfg' : ''), 'aria-expanded': 'false', onclick: function () { e.classList.toggle('open'); e.setAttribute('aria-expanded', e.classList.contains('open') ? 'true' : 'false'); } }, r.preview ? 'Loads once you save' : r.unconfigured ? 'Not configured' : r.offline ? 'Unreachable' : 'API error', h('div', { class: 'x' }, r.error));
      cb.appendChild(e); return;
    }
    if (r.blocks && r.blocks.length) cb.appendChild(blocks(r.blocks));
    if (r.list) {
      var l = h('div', { class: 'lst' });
      r.list.forEach(function (x) {
        var tt = x.title + (x.sub ? ' · ' + x.sub : ''), body = [x.title, x.sub ? h('span', { class: 'muted' }, ' ' + x.sub) : null];
        l.appendChild(h('div', { class: 'r' }, x.label ? h('span', { class: 'lab' }, x.label) : null,
          /^https?:\/\//i.test(x.href || '') ? h('a', { class: 't', href: x.href, target: '_blank', rel: 'noopener noreferrer', title: tt }, body) : h('span', { class: 't', title: tt }, body), x.ts ? h('span', { class: 'when' }, rel(x.ts)) : (x.right ? h('span', { class: 'when' }, x.right) : null))); });
      if (!r.list.length) l.appendChild(h('div', { class: 'muted sm' }, r.empty || 'Nothing yet'));
      cb.appendChild(l);
    }
    if (r.calendar) {
      if (!r.calendar.length) cb.appendChild(h('div', { class: 'muted sm' }, r.empty || 'Nothing coming up'));
      else {
        var cal = h('div', { class: 'cal' }), last = '';
        r.calendar.forEach(function (x) {
          var d = day(x.ts);
          cal.appendChild(h('span', { class: 'd' }, d === last ? '' : d)); last = d;
          cal.appendChild(h('span', { class: 'ti', title: x.title + (x.sub ? ' ' + x.sub : '') }, x.title));
          cal.appendChild(h('span', { class: 'ok', title: x.done ? 'Downloaded' : '' }, x.done ? ic('check') : ''));
        });
        cb.appendChild(cal);
      }
    }
    if (r.streams && !R.playing) {                       // with the Now playing section streams are shown there, once
      if (!r.streams.length) cb.appendChild(h('div', { class: 'muted sm', style: { 'margin-top': '6px' } }, r.empty || 'No active streams'));
      else cb.appendChild(h('div', { class: 'plist in', role: 'list' }, r.streams.map(function (x) { return playRow(x, false); })));
    }
    if (r.bars) cb.appendChild(h('div', { class: 'bars' }, r.bars.map(function (x) { var p = x.size ? 100 * x.used / x.size : 0; return h('div', null, h('div', { class: 'bt' }, h('span', null, x.title, x.sub ? h('span', { class: 'muted' }, ' · ' + x.sub) : null), h('span', null, bytes(x.size - x.used) + ' free')), h('div', { class: 'meter' }, h('i', { class: meterCls(p), style: { width: p.toFixed(1) + '%' } }))); })));
  }

  /* ================= server: OS update, history, power ================= */
  function osDialog() {
    var box = h('div', null, h('div', { class: 'progress' }, h('i')));
    var m = modal('Unraid OS', box, [btn('Close', function () { m.close(); })]);
    var t = null;
    function load() {
      api('os_status').then(function (o) {
        if (!box.parentNode) return;
        clear(box);
        if (o.error && !o.installed) { box.appendChild(h('div', { class: 'banner bad' }, o.error)); return; }
        var running = o.job === 'running';
        box.appendChild(h('div', { class: 'kv2' }, h('span', null, 'Installed'), h('span', null, o.installed || '-'), h('span', null, 'Latest'), h('span', null, o.latest || (o.error ? '-' : 'not checked yet')), h('span', null, 'Last check'), h('span', null, o.checked ? rel(o.checked) : 'never')));
        if (o.error) box.appendChild(h('div', { class: 'banner bad', style: { 'margin-top': '10px' } }, 'The update check failed: ' + o.error));
        if (o.staged) box.appendChild(h('div', { class: 'banner ok', style: { 'margin-top': '10px' } }, 'Unraid ' + o.staged + ' is installed on the flash drive and becomes active after a restart. Tools > Update OS > Restore in Unraid returns to ' + o.installed + ' if needed.'));
        else if (o.available && !running) box.appendChild(h('div', { class: 'banner info', style: { 'margin-top': '10px' } }, 'Unraid ' + o.latest + ' is available. Read the release notes and make a flash backup first. The update is installed on the flash drive and becomes active after a restart.'));
        var row = h('div', { class: 'ftr', style: { 'margin-top': '12px' } },
          btn('Check now', function (e) { e.currentTarget.disabled = true; act('os_check', {}).then(function () { load(); refreshNow(); }); }, 'sm', 'restart'),
          o.available && !o.staged ? btn(running ? 'Installing…' : 'Install ' + o.latest, function () {
            confirmBox('Install Unraid ' + o.latest + '?', 'Unraid\'s own updater (the one Tools > Update OS uses) downloads and installs the release on the flash drive. Nothing changes until you restart the server.', 'Install').then(function (y) { if (y) act('os_update', {}, 'Unraid update started').then(load); });
          }, 'sm pri', 'up') : null,
          o.staged ? btn('Restart server…', function () { m.close(); powerDialog('reboot'); }, 'sm', 'restart') : null,
          h('a', { class: 'btn sm', href: UNRAID + '/Tools/Update', target: '_blank', rel: 'noopener' }, 'Release notes in Unraid'));
        if (running) [].forEach.call(row.querySelectorAll('button'), function (b) { b.disabled = true; });
        box.appendChild(row);
        if (o.log && o.log.state !== 'idle') {
          box.appendChild(h('h4', { class: 'muted sm', style: { margin: '14px 0 6px' } }, o.log.state === 'running' ? 'Installing…' : 'Last install output (exit code ' + o.log.code + ')'));
          var pre = h('pre', { class: 'log wrap', style: { 'max-height': '300px', 'min-height': '0' } }, o.log.out || '…'); box.appendChild(pre); setTimeout(function () { pre.scrollTop = pre.scrollHeight; }, 0);
          if (running) t = setTimeout(load, 2500);
        }
      });
    }
    m.onclose = function () { clearTimeout(t); };
    load();
  }
  function historyDialog() {
    var box = h('div', { class: 'hist' }, h('div', { class: 'progress' }, h('i')));
    var m = modal('Recent operations', box, [btn('Close', function () { m.close(); })]);
    api('history').then(function (j) {
      clear(box);
      if (j.error) { box.appendChild(h('div', { class: 'banner bad' }, j.error)); return; }
      if (!j.history || !j.history.length) { box.appendChild(h('div', { class: 'empty' }, 'Nothing yet. Start, stop, update and power actions are listed here until the server restarts.')); return; }
      j.history.forEach(function (x) {
        box.appendChild(h('div', { class: 'hi' }, h('span', { class: 'badge ' + (x.ok ? 'ok' : 'bad') }, x.ok ? 'ok' : 'failed'), h('b', null, x.target), h('span', null, x.op), h('span', { class: 'muted sm grow' }, x.msg), h('span', { class: 'muted sm nowrap', title: new Date(x.at * 1000).toLocaleString() }, rel(x.at))));
      });
    });
  }
  /* server restart / shutdown: the server issues a one-time confirmation, the user types the server's name, then the command runs */
  function powerDialog(op) {
    if (S.power || S.powerOpen) return;
    S.powerOpen = true;
    var word = op === 'reboot' ? 'Restart' : 'Shut down';
    var box = h('div', null, h('div', { class: 'progress' }, h('i')));
    var go = btn(word + ' server', null, 'danger solid', op === 'reboot' ? 'restart' : 'power');
    go.disabled = true;
    var m = modal(word + ' the whole server?', box, [btn('Cancel', function () { m.close(); }), go]);
    m.onclose = function () { S.powerOpen = false; };
    api('power_prepare', { op: op }).then(function (j) {
      clear(box);
      if (j.error) { box.appendChild(h('div', { class: 'banner bad' }, j.error)); return; }
      var inp = h('input', { type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: j.name, id: 'und-pw',
        oninput: function () { go.disabled = inp.value.trim().toLowerCase() !== j.name.toLowerCase(); }, onkeydown: function (e) { if (e.key === 'Enter' && !go.disabled) go.click(); } });
      box.appendChild(h('p', null, op === 'reboot'
        ? 'This restarts the entire Unraid server ' + j.name + ', not just UnDash. The array is stopped cleanly, every container, VM and share goes offline, and this dashboard is unavailable until the server is back (usually a few minutes).'
        : 'This powers off the entire Unraid server ' + j.name + ', not just UnDash. The array is stopped cleanly and every container, VM and share goes offline. It stays off until someone turns it on again.'));
      j.warnings.forEach(function (w) { box.appendChild(h('div', { class: 'banner' }, w)); });
      box.appendChild(h('div', { class: 'fld' }, h('label', { for: 'und-pw' }, 'Type ', h('b', null, j.name), ' to confirm'), inp));
      setTimeout(function () { inp.focus(); }, 0);
      go.onclick = function () {
        go.disabled = true; inp.disabled = true; clear(go).appendChild(document.createTextNode('Sending…'));
        api('power', { op: op, nonce: j.nonce, confirm: inp.value.trim() }).then(function (k) {
          m.close();
          if (k.error) { toast(k.error, true); return; }
          powerOverlay(op);
        });
      };
    });
  }
  function powerOverlay(op) {
    if (S.power) return;
    S.power = op; clearTimeout(S.timer); clearTimeout(S.svcTimer); closeMenu(true);
    var st = h('p', { class: 'sm', style: { opacity: '.8' } }, 'The command was accepted. Waiting for the server to go offline…');
    root.appendChild(h('div', { class: 'overlay', role: 'alertdialog', 'aria-live': 'assertive', 'aria-label': 'Server power' }, h('div', { class: 'box' },
      h('h2', null, (op === 'reboot' ? 'Restarting ' : 'Shutting down ') + S.boot.server.name),
      h('p', null, op === 'reboot' ? 'This page reconnects by itself when the server is back.' : 'You can close this page.'), st)));
    var down = false, t0 = Date.now();
    (function poll() {
      api('live', { parts: 'system' }, 5000).then(function (r) {
        if (r.error) {
          if (!down) { down = true; st.textContent = op === 'reboot' ? 'Offline. Waiting for it to come back…' : 'The server is off.'; }
          if (op === 'shutdown') return;
        } else if (down && !r.power) { st.textContent = 'Back online. Reloading…'; setTimeout(function () { location.reload(); }, 1500); return; }
        if (Date.now() - t0 > 900000) { st.textContent = 'This is taking longer than expected. Check the server.'; return; }
        setTimeout(poll, 3000);
      });
    })();
  }

  /* ================= settings drawer ================= */
  var FIELDS = {
    radarr: 'wanted, missing, queued, movies', sonarr: 'wanted, queued, series', lidarr: 'wanted, queued, artists', readarr: 'wanted, queued, books',
    bazarr: 'missingEpisodes, missingMovies', prowlarr: 'numberOfGrabs, numberOfQueries, numberOfFailGrabs, numberOfFailQueries', overseerr: 'pending, approved, processing, completed, available',
    autobrr: 'approvedPushes, rejectedPushes, filters, indexers', wizarr: 'users, invites, pending, expired', qbittorrent: 'leech, download, seed, upload', qui: 'downloading, seeding, idle, crossseed, download, upload, instances', sabnzbd: 'rate, queue, timeleft',
    emby: 'movies, series, episodes, songs', jellyfin: 'movies, series, episodes, songs', plex: 'streams, albums, movies, tv', adguard: 'queries, blocked, filtered, latency',
    pihole: 'queries, blocked, blocked_percent, gravity', npm: 'enabled, disabled, total', immich: 'users, photos, videos, storage', peanut: 'battery_charge, ups_load, ups_status',
    ups: 'battery_charge, ups_load, ups_status, runtime',
    scrutiny: 'passed, failed, unknown, hottest', tdarr: 'queue, processed, errored, saved', unmanic: 'active, workers, pending', uptimekuma: 'up, down, uptime',
    speedtest: 'download, upload, ping', weather: 'now, today, humidity, wind', gluetun: 'public_ip, country, region, city, port', docker_summary: 'running, stopped, paused, updates', vm_summary: 'running, stopped, paused, total'
  };
  var SEC_DESC = { overview: 'CPU, memory, storage, network, health, disks, UPS', playing: 'Active Plex, Emby, Jellyfin and Tautulli streams', requests: 'Overseerr / Jellyseerr requests, approve or decline', health: 'Every app and container web UI: status and latency', alerts: 'Problems that need attention', favorites: 'Starred containers, VMs and apps',
    media: 'Upcoming and recently added movies and episodes', arr: 'Radarr, Sonarr, Prowlarr… status and issues', downloads: 'qBittorrent and SABnzbd activity',
    docker: 'Every container, found automatically', vms: 'Every VM, found automatically', apps: 'Plex, Immich, AdGuard, links and other apps' };
  var MET_DESC = { cpu: 'Usage and temperature', memory: 'RAM used and available', storage: 'Array (or pools) capacity', network: 'Download and upload speed', health: 'Array state, disk problems, parity checks',
    disks: 'Hottest disk', uptime: 'Time since boot', ups: 'UPS battery', cores: 'Busiest core', swap: 'Swap usage', load: 'Load average (plugin only)', path: 'Free space of one path (plugin only)',
    docker: 'Running containers', vms: 'Running VMs' };
  var MEDIA_DESC = { upcoming_movies: 'Radarr calendar', upcoming_episodes: 'Sonarr calendar', recent_movies: 'Radarr imports', recent_episodes: 'Sonarr imports' };

  function openSettings(tab) {
    if (S.drawer) { if (S.drawer.previewing) S.drawer.back(); return; }
    closeMenu(true);
    var al = SET_ALIAS[tab], D = { tab: al ? al[0] : SET_PAGES[tab] ? tab : 'dashboard', dirty: false, open: {} };
    if (al && al[1]) D.open[al[1]] = true;
    S.drawer = D;
    var last = document.activeElement;
    var bodyEl = h('div', { class: 'db', role: 'tabpanel' });
    var saveB = btn('Save', save, 'pri'); saveB.disabled = true;
    var prevB = btn('Preview', preview, '', 'eye', 'See the dashboard with these settings, without saving'); prevB.disabled = true;
    var status = h('span', { class: 'muted sm grow', role: 'status' }, 'Loading…');
    var tabs = h('nav', { class: 'dt', role: 'tablist', 'aria-label': 'Settings', 'aria-orientation': 'vertical' });
    SET_NAV.forEach(function (g) {
      tabs.appendChild(h('div', { class: 'dg' }, g[0]));
      g[1].forEach(function (id) {
        var pg = SET_PAGES[id];
        tabs.appendChild(h('button', { type: 'button', role: 'tab', 'data-t': id, onclick: function () { D.tab = id; paintTabs(); render(); } }, ic(pg[1]), pg[0]));
      });
    });
    var drawer = h('aside', { class: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Settings' },
      h('div', { class: 'dh' }, ic('gear'), h('h2', null, 'Settings'), settingsSearch(D, function (x) { goTo(x); }), btn('', function () { close(); }, 'ghost', 'x', 'Close settings')),
      h('div', { class: 'dmain' }, tabs, bodyEl),
      h('div', { class: 'df' }, saveB, prevB, btn('Discard', function () { if (!D.dirty) close(true); else confirmBox('Discard your changes?', 'Everything you changed since opening Settings is undone.', 'Discard', true).then(function (y) { if (y) close(true); }); }), status));
    var bg = h('div', { class: 'drawer-bg', onclick: function () { close(); } });
    root.appendChild(bg); root.appendChild(drawer);
    function key(e) {
      if (root.querySelector('.modal-bg')) return;
      if (D.previewing) { if (e.key === 'Escape') back(); return; }
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') {
        var f = drawer.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select,textarea');
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
      }
    }
    document.addEventListener('keydown', key);
    function paintTabs() { [].forEach.call(tabs.querySelectorAll('button'), function (b) { var on = b.getAttribute('data-t') === D.tab; b.className = on ? 'on' : ''; b.setAttribute('aria-selected', on ? 'true' : 'false'); }); }
    function dirty() { D.dirty = true; saveB.disabled = false; status.textContent = 'Unsaved changes'; }
    /* Preview: the dashboard drawn from the unsaved settings, with a bar to go back, save or discard */
    var pbar = null;
    function preview() {
      prevB.disabled = true;
      api('preview', { config: D.cfg }).then(function (j) {
        prevB.disabled = false;
        if (j.error) { toast(j.error, true); return; }
        if (!D.saved) D.saved = S.L;
        D.previewing = true; S.L = j.layout;
        shell();                     // this takes the drawer off the page; back() puts it back as it was
        applyCss(D.cfg.appearance.css_enabled ? D.css : '');
        pbar = h('div', { class: 'pv-bar', role: 'region', 'aria-label': 'Preview of unsaved settings' }, ic('eye'),
          h('span', { class: 'grow' }, h('b', null, 'Preview'), h('span', { class: 'muted sm' }, ' · not saved yet. New apps load their data once saved.')),
          btn('Back to settings', back, 'sm'), btn('Discard', function () { confirmBox('Discard your changes?', 'Everything you changed since opening Settings is undone.', 'Discard', true).then(function (y) { if (y) close(true); }); }, 'sm ghost'), btn('Save', save, 'pri sm'));
        root.appendChild(pbar);
        refreshNow();
        setTimeout(function () { var f = pbar && pbar.querySelector('.btn'); if (f) f.focus(); }, 0);
      });
    }
    function back() {
      if (!D.previewing) return;
      D.previewing = false; if (pbar) pbar.remove(); pbar = null;
      root.appendChild(bg); root.appendChild(drawer);
      setTimeout(function () { prevB.focus(); }, 0);
    }
    D.back = back;
    function close(force) {
      if (force !== true && D.dirty) { confirmBox('Close without saving?', 'Your changes in Settings are not saved yet.', 'Close without saving', true).then(function (y) { if (y) close(true); }); return; }
      document.removeEventListener('keydown', key);
      bg.remove(); drawer.remove(); if (pbar) pbar.remove(); S.drawer = null;
      if (D.saved) { S.L = D.saved; D.saved = null; D.previewing = false; shell(); refreshNow(); }
      if (D.dirty) { applyLook(); applyCss(S.boot.css); }
      if (last && last.focus && document.body.contains(last)) try { last.focus(); } catch (e) { /* gone */ }
    }
    D.close = close;
    function save() {
      saveB.disabled = true; status.textContent = 'Saving…';
      var data = { config: D.cfg, creds: D.creds, shared: sharedOut(D) };
      if (D.cssChanged) data.css = D.css;
      api('save', data).then(function (j) {
        if (j.error) { saveB.disabled = false; status.textContent = j.error; toast(j.error, true); return; }
        D.dirty = false; D.saved = null; toast('Settings saved');
        close(true); reload();
      });
    }
    function render() {
      clear(bodyEl);
      if (!D.cfg) { bodyEl.appendChild(h('div', { class: 'progress' }, h('i'))); return; }
      bodyEl.appendChild(h('h2', { class: 'dtitle' }, SET_PAGES[D.tab][0]));
      SET_PAGES[D.tab][2](bodyEl, D, dirty);
      bodyEl.scrollTop = 0;
    }
    D.render = render;
    /* a search result: its page, its folded panel opened, then scrolled to and marked */
    function goTo(x) {
      D.tab = x.tab; paintTabs(); render();
      var els = bodyEl.querySelectorAll('.set>h3,.fld>label,.switch,.oi b,.oi .t,.oi span');
      var el = [].filter.call(els, function (e) { return setText(e) === x.label; })[0]; if (!el) return;
      for (var p = el.parentNode; p && p !== bodyEl; p = p.parentNode) if (p.hidden && p.previousElementSibling) {
        var b = p.previousElementSibling.querySelector('.sec-opt'); if (b) b.click();
      }
      var box = el.closest('.fld,.oi,.switch,.set') || el;
      box.scrollIntoView({ block: 'center' });
      box.classList.remove('flash'); void box.offsetWidth; box.classList.add('flash');
      var f = box.querySelector('input,select,textarea'); if (f && f.focus) f.focus({ preventScroll: true });
    }
    D.pages = function () {
      // every page drawn off screen, so the search knows exactly what Settings holds
      var out = [], noop = function () {};
      SET_NAV.forEach(function (g) { g[1].forEach(function (id) { var t = h('div'); SET_PAGES[id][2](t, D, noop); out.push({ tab: id, el: t }); }); });
      render();
      return out;
    };
    paintTabs(); render();
    api('settings').then(function (j) {
      if (j.error) { status.textContent = j.error; return; }
      D.cfg = j.config; D.creds = Array.isArray(j.creds) ? {} : j.creds; D.shared = j.shared || []; D.zones = j.zones || {}; D.types = j.types; prevB.disabled = false; D.keep = j.keep; D.css = j.css; D.loc = j.loc; D.store = j.store; D.flash = j.flash;
      ['docker', 'vms'].forEach(function (k) { if (Array.isArray(D.cfg[k].items)) D.cfg[k].items = {}; });
      status.textContent = 'Changes apply after saving.';
      render();
      setTimeout(function () { var f = tabs.querySelector('.on'); if (f) f.focus(); }, 0);
    });
  }
  /* the search in Settings: an index of everything on its pages, with suggestions under the box */
  function setText(e) {
    var c = e.cloneNode(true); [].forEach.call(c.querySelectorAll('input,select,textarea,button,svg'), function (x) { x.remove(); });
    return c.textContent.replace(/\s+/g, ' ').trim();
  }
  var SET_WORDS = { 'corner radius': 'round rounded edges', 'text size': 'font zoom scale bigger', density: 'compact spacing', accent: 'colour color', colours: 'theme themes dark light mode color make new import export', 'card board (beta)': 'drag grid layout classic',
    wallpaper: 'background image picture blur', 'daily wallpaper': 'wikimedia commons nature city cars random photo background', animations: 'motion effects transitions skip speed reduce smooth', gradient: 'background colours mesh plain', 'translucent effect': 'glass frosted apple transparent blur see through liquid', 'card blur': 'frosted glass', 'card opacity': 'transparency glass', 'card exposure': 'brightness', exposure: 'brightness wallpaper', 'background opacity': 'wallpaper fade transparency', 'custom css': 'style stylesheet', 'dashboard password': 'login auth sign in security', 'unraid connection': 'api key address graphql', 'backup': 'export import restore',
    'ignored alerts': 'mute silence hide notifications', 'section names': 'titles headers', 'container and vm icons': 'selfh.st logo images' };
  var SET_POPULAR = ['Translucent Effect', 'Corner radius', 'Text size', 'Daily wallpaper', 'Animations', 'Dashboard password'];
  function settingsIndex(D) {
    var out = [], seen = {};
    function add(tab, label, where, hint) {
      if (!label || label.length > 70 || seen[tab + label]) return; seen[tab + label] = 1;
      var k = (SET_WORDS[label.toLowerCase()] || '') + ' ' + (hint || '');
      out.push({ tab: tab, label: label, where: where, l: label.toLowerCase(), k: (where + ' ' + k).toLowerCase() });
    }
    D.pages().forEach(function (p) {
      var page = SET_PAGES[p.tab][0];
      add(p.tab, page, 'Page');
      [].forEach.call(p.el.querySelectorAll('.set'), function (b) {
        var title = b.querySelector('h3'), name = title ? title.textContent.trim() : '', hint = b.querySelector('.hint');
        add(p.tab, name, page, hint ? hint.textContent : '');
        [].forEach.call(b.querySelectorAll('.fld>label'), function (l) { var c = l.parentNode.querySelector('.hint'); add(p.tab, setText(l), page + ' › ' + name, c ? c.textContent : ''); });
        [].forEach.call(b.querySelectorAll('.switch'), function (l) { add(p.tab, setText(l), page + ' › ' + name); });
      });
    });
    return out;
  }
  function settingsSearch(D, go) {
    var list = h('div', { class: 'ss', id: 'und-ss', role: 'listbox', 'aria-label': 'Settings found', hidden: true }), index = null, rows = [], sel = -1;
    var inp = h('input', { type: 'search', placeholder: 'Search settings', 'aria-label': 'Search settings', role: 'combobox', 'aria-controls': 'und-ss', 'aria-expanded': 'false', 'aria-autocomplete': 'list', autocomplete: 'off',
      oninput: show, onfocus: show, onkeydown: function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); mark(sel + (e.key === 'ArrowDown' ? 1 : -1)); }
        else if (e.key === 'Enter') { e.preventDefault(); if (rows[Math.max(0, sel)]) pick(rows[Math.max(0, sel)]); }
        else if (e.key === 'Escape' && !list.hidden) { e.stopPropagation(); hide(); }
      } });
    function hide() { list.hidden = true; inp.setAttribute('aria-expanded', 'false'); }
    function mark(i) {
      if (!rows.length) return; sel = (i + rows.length) % rows.length;
      [].forEach.call(list.querySelectorAll('.ss-i'), function (e, n) { e.classList.toggle('sel', n === sel); e.setAttribute('aria-selected', n === sel ? 'true' : 'false'); if (n === sel) e.scrollIntoView({ block: 'nearest' }); });
    }
    function pick(x) {
      var rec = LS.get('setRecent', []).filter(function (l) { return l !== x.label; }); rec.unshift(x.label); LS.set('setRecent', rec.slice(0, 3));
      inp.value = ''; hide(); go(x);
    }
    function score(x, q, words) {
      if (x.l === q) return 0;
      if (x.l.indexOf(q) === 0) return 1;
      if ((' ' + x.l).indexOf(' ' + q) >= 0) return 2;
      var all = ' ' + x.l + ' ' + x.k;
      if (words.every(function (w) { return all.indexOf(' ' + w) >= 0; })) return 3;      // whole words first: "round" is not "background"
      if (x.l.indexOf(q) >= 0) return 4;
      return words.every(function (w) { return all.indexOf(w) >= 0; }) ? 5 : -1;
    }
    function show() {
      if (!D.cfg) return;
      if (!index) { index = settingsIndex(D); }
      var q = inp.value.trim().toLowerCase(), head = '';
      if (q) {
        var words = q.split(/\s+/);
        rows = index.map(function (x) { return [score(x, q, words), x]; }).filter(function (p) { return p[0] >= 0; }).sort(function (a, b) { return a[0] - b[0] || a[1].l.length - b[1].l.length; }).slice(0, 8).map(function (p) { return p[1]; });
      } else {
        var rec = LS.get('setRecent', []), names = rec.concat(SET_POPULAR.filter(function (n) { return rec.indexOf(n) < 0; })).slice(0, 6);
        rows = names.map(function (n) { return index.filter(function (x) { return x.label === n; })[0]; }).filter(Boolean);
        head = rec.length ? 'Recent and suggested' : 'Suggested';
      }
      sel = q && rows.length ? 0 : -1;
      clear(list);
      if (head) list.appendChild(h('div', { class: 'ss-h' }, head));
      if (!rows.length) list.appendChild(h('div', { class: 'empty' }, 'No setting matches.'));
      rows.forEach(function (x, i) {
        list.appendChild(h('button', { type: 'button', class: 'ss-i' + (i === sel ? ' sel' : ''), role: 'option', 'aria-selected': i === sel ? 'true' : 'false', onmousedown: function (e) { e.preventDefault(); pick(x); } },
          ic(SET_PAGES[x.tab][1]), h('span', { class: 'ell' }, h('b', { class: 'ell' }, x.label.split(' · ')[0]), h('span', { class: 'muted xs ell' }, x.where))));
      });
      list.hidden = false; inp.setAttribute('aria-expanded', 'true');
    }
    inp.addEventListener('blur', function () { setTimeout(hide, 120); });
    return h('div', { class: 'ssb' }, ic('search'), inp, list);
  }
  /* small form helpers */
  function F(label, input, hint) { return h('div', { class: 'fld' }, h('label', null, label), input, hint ? h('div', { class: 'hint' }, hint) : null); }
  function tIn(o, k, dirty, ph, type, attrs) { return h('input', Object.assign({ type: type || 'text', value: o[k] === undefined || o[k] === null ? '' : o[k], placeholder: ph || '', oninput: function (e) { o[k] = type === 'number' ? +e.target.value : e.target.value; dirty(); } }, attrs || {})); }
  function cIn(o, k, dirty, lab) { return h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!o[k], onchange: function (e) { o[k] = e.target.checked; dirty(); } }), lab); }
  function sIn(o, k, dirty, opts, onch) { return h('select', { onchange: function (e) { o[k] = e.target.value; dirty(); if (onch) onch(); } }, opts.map(function (x) { return h('option', { value: x[0], selected: String(o[k]) === String(x[0]) }, x[1]); })); }
  function move(arr, i, d) { var j = i + d; if (j < 0 || j >= arr.length) return false; var x = arr[i]; arr[i] = arr[j]; arr[j] = x; return true; }
  function rid() { var a = new Uint8Array(4); window.crypto.getRandomValues(a); return [].map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  /* Settings pages: [title, icon, builder]. Builders that share an older all-in-one tab take their blocks by title. */
  function pick(build, titles) {
    return function (b, D, dirty) {
      var tmp = h('div'); build(tmp, D, dirty);
      var kids = [].slice.call(tmp.children);
      titles.forEach(function (want) { kids.forEach(function (el) { var t = el.querySelector('h3'); if (t && t.textContent === want) b.appendChild(el); }); });
    };
  }
  var SET_PAGES = {
    dashboard: ['Sections & apps', 'grid', tabDashboard],
    appearance: ['Theme', 'sun', pick(tabAppearance, ['Colours', 'Cards', 'Background', 'Daily wallpaper', 'Spacing'])],
    css: ['Custom CSS', 'edit', pick(tabAppearance, ['Custom CSS'])],
    system: ['Refresh & display', 'clock', tabSystem],
    ignored: ['Ignored alerts', 'mute', tabIgnored],
    config: ['Connection', 'server', function (b, D) { if (S.boot.caps.mode === 'api') { unraidConn(b); dashPass(b); } else locPort(b, D); }],
    backup: ['Backup & import', 'download', configCommon]
  };
  var SET_NAV = [['Dashboard', ['dashboard', 'appearance', 'css']], ['Behaviour', ['system', 'ignored']], ['Server', ['config', 'backup']]];
  /* older page names (links in messages) open Sections & apps with that part unfolded */
  var SET_ALIAS = { layout: ['dashboard'], media: ['dashboard', 'media'], containers: ['dashboard', 'docker'], services: ['dashboard', 'apps'] };
  function set(title, hint) { var b = h('section', { class: 'set' }, h('h3', null, title), hint ? h('p', { class: 'hint' }, hint) : null); for (var i = 2; i < arguments.length; i++) add(b, arguments[i]); return b; }
  /* reorderable list: drag the handle (mouse) or use the arrow buttons (keyboard, touch); below(x) can add a panel under a row */
  function orderList(arr, row, dirty, redraw, below) {
    var from = null;
    return h('div', { class: 'olist' }, arr.map(function (x, i) {
      var el = h('div', { class: 'oi', draggable: 'true',
        ondragstart: function (e) { from = i; el.classList.add('drag'); try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(i)); } catch (z) { /* old browser */ } },
        ondragend: function () { el.classList.remove('drag'); },
        ondragover: function (e) { if (from !== null) { e.preventDefault(); el.classList.add('over'); } },
        ondragleave: function () { el.classList.remove('over'); },
        ondrop: function (e) { e.preventDefault(); el.classList.remove('over'); if (from === null || from === i) return; var it = arr.splice(from, 1)[0]; arr.splice(i, 0, it); from = null; dirty(); redraw(); } },
        h('span', { class: 'grip', 'aria-hidden': 'true', title: 'Drag to reorder' }, '⋮⋮'), row(x, i),
        btn('', function () { if (move(arr, i, -1)) { dirty(); redraw(); } }, 'sm ghost', 'upar', 'Move up'), btn('', function () { if (move(arr, i, 1)) { dirty(); redraw(); } }, 'sm ghost', 'dnar', 'Move down'));
      var more = below && below(x);
      return more ? h('div', { class: 'oi-w' }, el, more) : el;
    }));
  }

  /* Settings > Sections & apps: the dashboard's sections in order, each with its own options folded under it.
     Apps are listed in the section they show in; "Find an app" opens every section that has a match. */
  var SEC_OPTS = { overview: 'Tiles and apps', media: 'Columns and apps', arr: 'Apps', downloads: 'Clients', requests: 'View', apps: 'Groups and apps', docker: 'Containers and view', vms: 'VMs and view' };
  function tabDashboard(b, D, dirty) {
    var c = D.cfg, open = D.open || (D.open = {}), panels = {}, chevs = {};
    var ed = appsEditor(D, dirty, function () { paint(); });
    var filter = h('input', { type: 'search', placeholder: 'Find an app…', 'aria-label': 'Find an app', value: ed.q, oninput: function () { ed.q = filter.value.trim().toLowerCase(); ed.draw(); } });
    function paint() {
      Object.keys(panels).forEach(function (id) {
        var on = ed.q ? ed.hits(id) > 0 : !!open[id];
        panels[id].hidden = !on; chevs[id].setAttribute('aria-expanded', on ? 'true' : 'false'); chevs[id].classList.toggle('on', on);
      });
    }
    var opts = {
      overview: function () { return [sub('Tiles', 'In this order. Click a tile on the dashboard for its details. A metric the server cannot measure says so instead of showing a made-up number.'),
        orderList(c.dashboard.metrics, function (m) { return h('label', { class: 'switch t', style: { margin: '0' } }, h('input', { type: 'checkbox', checked: m.visible, onchange: function (e) { m.visible = e.target.checked; dirty(); } }), h('span', null, h('b', null, MET[m.id][0]), h('span', { class: 'muted sm' }, ' · ' + MET_DESC[m.id]))); }, dirty, D.render),
        ed.zone('overview')]; },
      media: function () {
        var radarrs = [['', 'First Radarr app']], sonarrs = [['', 'First Sonarr app']];
        c.groups.forEach(function (g) { g.services.forEach(function (x) { if (x.type === 'radarr') radarrs.push([x.id, x.name]); if (x.type === 'sonarr') sonarrs.push([x.id, x.name]); }); });
        return [sub('Columns', 'Always in this order, with dates from Radarr and Sonarr (and posters, unless Show as is Text only). Each column scrolls on its own.'),
          h('div', { class: 'olist' }, c.media.widgets.map(function (w) {
            return h('div', { class: 'oi' }, h('label', { class: 'switch t', style: { margin: '0' } }, h('input', { type: 'checkbox', checked: w.visible, onchange: function (e) { w.visible = e.target.checked; dirty(); } }),
              h('span', null, h('b', null, MEDIA[w.id][0]), h('span', { class: 'muted sm' }, ' · ' + MEDIA_DESC[w.id]))),
              h('label', { class: 'muted sm nowrap' }, 'Items ', tIn(w, 'count', dirty, '15', 'number', { min: 1, max: 50, style: 'width:70px', 'aria-label': 'Items in ' + MEDIA[w.id][0] })));
          })),
          h('div', { class: 'grid2', style: { 'margin-top': '12px' } }, F('Radarr', sIn(c.media, 'radarr', dirty, radarrs)), F('Sonarr', sIn(c.media, 'sonarr', dirty, sonarrs)),
            F('Days ahead', tIn(c.media, 'days_ahead', dirty, '30', 'number', { min: 7, max: 120 }), 'How far the two Upcoming columns look ahead.'),
            F('Show as', sIn(c.media, 'style', dirty, [['art', 'Posters'], ['text', 'Text only']]), 'Text only fits many more items in the same height.')),
          ed.zone('media')];
      },
      requests: function () { return [h('div', { class: 'grid2' }, F('Show as', sIn(c.requests, 'style', dirty, [['art', 'Posters'], ['text', 'Text only']]), 'Text only puts each request on one short line.')), ed.zone('requests')]; },
      arr: function () { return [ed.zone('arr')]; },
      downloads: function () { return [h('div', { class: 'grid2' }, F('Active items shown per client', tIn(c.downloads, 'items', dirty, '4', 'number', { min: 0, max: 20 }), '0 shows the totals only.')), ed.zone('downloads')]; },
      apps: function () {
        var A2 = c.apps;
        return [h('div', { class: 'grid2' }, F('Columns', sIn(A2, 'columns', dirty, [[0, 'Automatic'], [1, '1'], [2, '2'], [3, '3'], [4, '4'], [5, '5'], [6, '6']]))),
          cIn(A2, 'headers', dirty, 'Show apps in their groups, with the group names'), cIn(A2, 'links', dirty, 'Show shortcut links (the old Services links) as small buttons'),
          h('div', { class: 'hint', style: { 'margin-bottom': '10px' } }, 'Rename a group in its name box. "Show name" hides one group\'s name and keeps its apps together. On the dashboard, the gear next to a group name does the same.'),
          ed.groups()];
      },
      docker: function () { return [dockerOpts(c, dirty), sub('Containers on the dashboard', 'Tick the containers to show; new ones show until you untick them. Kept by name, so they survive updates and re-created containers. Star = favorite, arrow = checked for updates.'), namesList(c, dirty, 'docker')]; },
      vms: function () { return [boardOn() ? null : h('div', { class: 'grid2' }, F('Layout', sIn(c.vms, 'layout', dirty, [['list', 'List'], ['cards', 'Cards']]))), sub('VMs on the dashboard', 'Tick the VMs to show; new ones show until you untick them. Star = favorite.'), namesList(c, dirty, 'vms')]; }
    };
    function sub(t, hint) { return h('div', { class: 'sub-h' }, h('b', null, t), hint ? h('span', { class: 'hint' }, hint) : null); }
    var board = boardOn(), foundBox = h('div');
    if (!board) { b.appendChild(h('div', { class: 'ae-bar' }, filter, h('span', { class: 'grow' }),
      btn('Add app', function () { addApp(D, null, null, function () { ed.draw(); }, dirty); }, 'pri sm', 'plus'))); b.appendChild(foundBox); }
    ed.draws.push(function () {
      var fs = foundApps(D), others = otherApps(D);
      clear(foundBox);
      if (ed.q || D.hideFound) return;
      var again = btn('Refresh', function () { rescan(D, again, function () { ed.draw(); }); }, 'sm ghost', 'restart', 'Look for containers added since this page opened');
      foundBox.appendChild(h('div', { class: 'found-bar' },
        h('div', { class: 'ae-h' }, h('b', null, 'Found on your server'), h('span', { class: 'muted sm' }, fs.length ? fs.length + ' container' + (fs.length > 1 ? 's' : '') + ' UnDash can show live stats for. Pick one to connect it.' : 'Nothing new to connect.'),
          h('span', { class: 'grow' }), again, btn('', function () { D.hideFound = true; ed.draw(); }, 'sm ghost', 'x', 'Hide this list until Settings is opened again')),
        !fs.length ? null : h('div', { class: 'tpick found' }, fs.map(function (f) { return foundBtn(D, f, function () { addFound(D, f, null, function () { ed.draw(); }, dirty); }); })),
        !others.length ? null : h('button', { type: 'button', class: 'allb', 'aria-expanded': D.showOther ? 'true' : 'false', onclick: function () { D.showOther = !D.showOther; ed.draw(); } }, (D.showOther ? 'Hide ' : 'Show ') + others.length + ' more. One click adds a shortcut', ic('chev')),
        others.length && D.showOther ? h('div', { class: 'tpick found' }, others.map(function (f) { return foundBtn(D, f, function () { addLink(D, f, dirty, function () { ed.draw(); }); }); })) : null));
    });
    if (board) { boardSettings(b, D, dirty, ed, opts, foundBox); return; }
    b.appendChild(set('Sections', 'Tick the sections to show, drag to reorder, and open one for its options and apps. Half-width sections sit side by side on wide screens. Keys and passwords stay in .env on the server, never in the browser.',
      orderList(c.dashboard.sections, function (s) {
        var o = SEC_OPTS[s.id];
        if (o) chevs[s.id] = h('button', { type: 'button', class: 'btn sm ghost sec-opt', 'aria-controls': 'und-so-' + s.id, onclick: function () { open[s.id] = !open[s.id]; if (ed.q) { ed.q = ''; filter.value = ''; ed.draw(); } else paint(); } }, h('span', { class: 'lbl' }, o), ic('chev'));
        return [h('label', { class: 'switch t sec-sw', style: { margin: '0' } }, h('input', { type: 'checkbox', checked: s.visible, onchange: function (e) { s.visible = e.target.checked; dirty(); } }), h('span', null, h('b', null, TITLES[s.id]), h('span', { class: 'muted sm' }, ' · ' + SEC_DESC[s.id]))),
          sIn(s, 'width', dirty, [['full', 'Full width'], ['half', 'Half width']]), chevs[s.id] || h('span', { class: 'sec-opt-x' })];
      }, dirty, D.render, function (s) {
        if (!opts[s.id]) return null;
        return (panels[s.id] = h('div', { class: 'sec-opts', id: 'und-so-' + s.id, hidden: true }, opts[s.id]()));
      })));
    sharedValues(b, D, dirty);
    ed.draw();
  }
  /* the board's version, laid out like iPhone Settings: all the apps first, then the cards, and a page of its own for each card's options.
     Moving and resizing happens on the dashboard itself. */
  var SEC_ICON = { overview: 'server', favorites: 'star', media: 'play', arr: 'tag', downloads: 'download', playing: 'play', requests: 'plus', apps: 'grid', docker: 'box', vms: 'vm' };
  function boardSettings(b, D, dirty, ed, opts, foundSet) {
    var c = D.cfg, bd = c.dashboard.board, top = { alerts: 1, health: 1 };
    var list = c.dashboard.sections.filter(function (s) { return !top[s.id]; }), rest = c.dashboard.sections.filter(function (s) { return top[s.id]; });
    var sync = function () { c.dashboard.sections = list.concat(rest); dirty(); };
    var shown = function (s) { return s.visible && (bd.hidden || []).indexOf(s.id) < 0; };
    var sw = function (s, label) { return h('label', { class: 'switch cw', title: 'Show ' + label }, h('input', { type: 'checkbox', checked: shown(s), 'aria-label': 'Show ' + label, onchange: function (e) { s.visible = e.target.checked; if (s.visible) bd.hidden = (bd.hidden || []).filter(function (i) { return i !== s.id; }); dirty(); } })); };
    var apps = function (id) { var n = 0; c.groups.forEach(function (g) { g.services.forEach(function (x) { if (appZone(D, x) === id) n++; }); }); return n; };
    var page = list.filter(function (s) { return s.id === D.sub; })[0];
    if (page) {
      txt(b.querySelector('.dtitle'), TITLES[page.id]);
      b.insertBefore(h('button', { type: 'button', class: 'sback', onclick: function () { D.sub = null; D.render(); } }, ic('chev'), 'Sections & apps'), b.querySelector('.dtitle'));
      b.appendChild(set('On the dashboard', SEC_DESC[page.id],
        F('Show this card', h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: shown(page), onchange: function (e) { page.visible = e.target.checked; dirty(); } }), TITLES[page.id])),
        F('Width in Smart arrange', sIn(page, 'width', dirty, [['full', 'Full width'], ['half', 'Half width']]))));
      if (opts[page.id]) b.appendChild(h('section', { class: 'set sub' }, opts[page.id]()));
      ed.draw();
      return;
    }
    var chips = h('div', { class: 'seg', role: 'group', 'aria-label': 'Show apps from' });
    var zones = [['', 'All']].concat(['arr', 'downloads', 'media', 'overview', 'apps'].filter(function (z) { return apps(z); }).map(function (z) { return [z, ZONE_NAME[z].replace(' overview', '')]; }));
    var paintChips = function () { clear(chips); zones.forEach(function (z) { chips.appendChild(h('button', { type: 'button', class: (ed.zoneF || '') === z[0] ? 'on' : '', 'aria-pressed': (ed.zoneF || '') === z[0] ? 'true' : 'false', onclick: function () { ed.zoneF = z[0]; D.appz = z[0]; paintChips(); ed.draw(); } }, z[1])); }); };
    ed.zoneF = D.appz || ''; paintChips();
    var find = h('input', { type: 'search', placeholder: 'Find an app…', 'aria-label': 'Find an app', value: ed.q, oninput: function () { ed.q = find.value.trim().toLowerCase(); ed.draw(); } });
    b.appendChild(h('section', { class: 'set' }, h('div', { class: 'seth' }, h('h3', null, 'Apps'), h('span', { class: 'grow' }), btn('Add app', function () { addApp(D, null, null, function () { ed.draw(); }, dirty); }, 'pri sm', 'plus')),
      h('div', { class: 'ae-bar' }, find, chips), ed.grid(), foundSet));
    b.appendChild(h('section', { class: 'set' }, h('div', { class: 'seth' }, h('h3', null, 'Cards'), h('span', { class: 'grow' }),
      btn('Edit layout', function () { D.close(); setTimeout(function () { if (!S.drawer) boardEdit(); }, 60); }, 'sm', 'edit', 'Move and resize the cards on the dashboard')),
      h('p', { class: 'hint' }, 'Turn on what you want on your dashboard. Open a card to choose what it shows.'),
      h('div', { class: 'cardl' }, orderList(list, function (s) {
        var n = apps(s.id);
        return [h('button', { type: 'button', class: 'crow', title: 'Options for ' + TITLES[s.id], onclick: function () { D.sub = s.id; D.render(); } },
          h('span', { class: 'cri' }, ic(SEC_ICON[s.id] || 'grid')), h('span', { class: 'crt' }, h('b', null, TITLES[s.id]), h('span', { class: 'muted xs ell' }, SEC_DESC[s.id])),
          n ? h('span', { class: 'muted xs nowrap' }, n + (n === 1 ? ' app' : ' apps')) : null, ic('chev')), sw(s, TITLES[s.id])];
      }, sync, D.render))));
    b.appendChild(set('Alerts', 'No card needed: the button at the top tells you when something is wrong.',
      h('div', { class: 'cardl' }, h('div', { class: 'olist' }, rest.map(function (s) {
        return h('div', { class: 'oi' }, h('span', { class: 'crow still' }, h('span', { class: 'cri' }, ic(s.id === 'alerts' ? 'bell' : 'web')), h('span', { class: 'crt' }, h('b', null, s.id === 'alerts' ? 'Alerts' : 'Endpoint checks'), h('span', { class: 'muted xs ell' }, s.id === 'alerts' ? SEC_DESC.alerts : 'Tells you when an app\'s address stops answering'))), sw(s, TITLES[s.id]));
      })))));
    var adv = h('div');
    sharedValues(adv, D, dirty);
    if (adv.childNodes.length) b.appendChild(h('details', { class: 'adv' }, h('summary', null, 'Advanced'), adv));
    ed.draw();
  }
  function dockerOpts(c, dirty) {
    var D2 = c.docker;
    var shows = [['cpu', 'CPU'], ['memory', 'Memory'], ['uptime', 'Uptime'], ['image', 'Image']];
    var showBox = h('div', { class: 'inl' }, shows.map(function (x) {
      return h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: D2.show.indexOf(x[0]) >= 0, onchange: function (e) {
        D2.show = D2.show.filter(function (k) { return k !== x[0]; }); if (e.target.checked) D2.show.push(x[0]); dirty(); } }), x[1]);
    }));
    return h('div', null,
      h('div', { class: 'grid2' },
        F('Show by default', sIn(D2, 'filter', dirty, [['running', 'Running containers'], ['selected', 'Selected containers'], ['all', 'All containers']]), 'Selected: gear > Show in "Selected" on the dashboard.'),
        F('Layout', sIn(D2, 'layout', dirty, [['grid', 'Compact grid'], ['list', 'Table'], ['cards', 'Cards']])),
        F('Grid columns', sIn(D2, 'columns', dirty, [[0, 'Automatic (fits the width)'], [1, '1'], [2, '2'], [3, '3'], [4, '4']])),
        F('Sort by', sIn(D2, 'sort', dirty, [['name', 'Name'], ['status', 'Status'], ['cpu', 'CPU'], ['memory', 'Memory']]))),
      cIn(D2, 'update_check', dirty, 'Check for container updates: the gear turns orange when a container has one'),
      F('Details on each container', showBox, S.boot.caps.docker_stats ? null : 'CPU and memory are not available through the Unraid API (Docker version).'));
  }
  /* which containers (or VMs) the dashboard shows, plus display name, icon, favorite and update check, kept by name */
  function namesList(c, dirty, kind) {
    var y = S.live || {}, dk = kind === 'docker', items = dk ? ((y.docker && y.docker.containers) || []) : ((y.vms && y.vms.vms) || []);
    var what = dk ? 'containers' : 'VMs';
    var filter = h('input', { type: 'search', placeholder: 'Filter ' + what + '…', 'aria-label': 'Filter ' + what, oninput: function () { draw(); } });
    var list = h('div', { class: 'olist pick-list' }), count = h('span', { class: 'muted sm' });
    var bag = function () { return c[kind].items; };
    var get = function (n) { return bag()[n] || { label: '', icon: '', description: '', favorite: false, hidden: false }; };
    var counted = function () { txt(count, items.filter(function (it) { return !get(it.name).hidden; }).length + ' of ' + items.length + ' shown'); };
    function listed() { var q = filter.value.toLowerCase(); return items.filter(function (it) { return !q || it.name.toLowerCase().indexOf(q) >= 0 || (get(it.name).label || '').toLowerCase().indexOf(q) >= 0; }); }
    // the bulk buttons act on what the filter lists
    function setAll(test) { listed().forEach(function (it) { var p = get(it.name); p.hidden = !test(it); bag()[it.name] = p; }); dirty(); draw(); }
    function draw() {
      clear(list); counted();
      listed().slice(0, 300).forEach(function (it) {
        var p = get(it.name), row;
        var put = function () { bag()[it.name] = p; dirty(); };
        row = h('div', { class: 'oi' + (p.hidden ? ' off' : '') },
          h('label', { class: 'switch', title: 'Show on the dashboard' }, h('input', { type: 'checkbox', checked: !p.hidden, 'aria-label': 'Show ' + it.name + ' on the dashboard', onchange: function (e) {
            p.hidden = !e.target.checked; row.classList.toggle('off', p.hidden); put(); counted(); } })),
          h('button', { type: 'button', class: 'ico-btn', title: 'Pick an icon for ' + it.name, 'aria-label': 'Pick an icon for ' + it.name, onclick: function () { iconPicker(p.label || it.name, function (v) { p.icon = v; put(); draw(); }); } },
            (dk ? cIcon : vIcon)(it, p, '')),
          h('span', { class: 't' }, h('span', { class: 'sdot ' + (it.state === 'running' ? 'ok' : 'na'), title: it.state || '' }), h('b', null, it.name)),
          h('input', { type: 'text', value: p.label || '', placeholder: 'Display name', 'aria-label': 'Display name for ' + it.name, oninput: function (e) { p.label = e.target.value; put(); } }),
          h('input', { type: 'text', value: p.icon || '', placeholder: 'Icon', 'aria-label': 'Icon for ' + it.name, oninput: function (e) { p.icon = e.target.value; put(); } }),
          h('label', { class: 'switch ico-t', title: 'Favorite' }, h('input', { type: 'checkbox', checked: !!p.favorite, 'aria-label': 'Favorite ' + it.name, onchange: function (e) { p.favorite = e.target.checked; put(); } }), ic('star')),
          dk ? h('label', { class: 'switch ico-t', title: 'Check for updates' }, h('input', { type: 'checkbox', checked: !p.no_update, 'aria-label': 'Check ' + it.name + ' for updates', onchange: function (e) { p.no_update = !e.target.checked; put(); } }), ic('up')) : null);
        list.appendChild(row);
      });
      if (!list.childNodes.length) list.appendChild(h('div', { class: 'empty' }, items.length ? 'Nothing matches.'
        : ((dk ? y.docker : y.vms) || {}).error || ('No ' + what + ' found yet.' + (S.boot.caps.mode === 'api' ? ' They are listed here once the Unraid API is connected (Server > Connection).' : ''))));
    }
    draw();
    return h('div', null,
      items.length ? h('div', { class: 'inl pick-bar' }, filter, count, h('span', { class: 'grow' }), h('span', { class: 'pick-btns' },
        btn('Select all', function () { setAll(function () { return true; }); }, 'sm'),
        btn('Running only', function () { setAll(function (it) { return it.state === 'running'; }); }, 'sm'),
        btn('None', function () { setAll(function () { return false; }); }, 'sm'))) : null,
      list);
  }

  function tabSystem(b, D, dirty) {
    var g = D.cfg.general;
    b.appendChild(set('Live refresh', 'How often system metrics, containers and VMs update without reloading. Each refresh is one light request; heavier details are cached on the server.', h('div', { class: 'grid2' },
      F('Refresh every (seconds)', tIn(g, 'refresh', dirty, '2', 'number', { min: 1, max: 300 }), 'Default 2. You can pause it from the dashboard.'),
      F('When the browser tab is in the background', sIn(g, 'background_poll', dirty, [['slow', 'Slow down (every 30 s)'], ['pause', 'Pause'], ['normal', 'Keep the same speed']])),
      F('App widgets refresh every (seconds)', tIn(g, 'refresh_services', dirty, '15', 'number', { min: 5, max: 3600 }), 'Radarr, Plex and the other app widgets.'),
      F('Check for Unraid updates every (hours)', tIn(g, 'os_check_hours', dirty, '6', 'number', { min: 1, max: 168 })))));
    b.appendChild(set('Display', null, h('div', { class: 'grid2' },
      F('Dashboard title', tIn(g, 'title', dirty, 'UnDash')),
      F('Path for the Disk metric', tIn(g, 'disk_path', dirty, '/mnt/user'), 'Any path under /mnt, e.g. /mnt/user, /mnt/cache or /mnt/disk3.'),
      F('UPS name (NUT)', tIn(g, 'ups_name', dirty, 'ups'), 'NUT is tried first, then apcupsd. In the Docker version a NUT UPS is read from the server (port 3493); use NAME@HOST for another NUT server.'),
      F('UPS rated power (W)', tIn(g, 'ups_watts', dirty, 'from the UPS', 'number', { min: 0, max: 20000 }), 'For the power draw on the UPS tile (load × rated power). Leave it at 0 to use what the UPS reports (apcupsd NOMPOWER, NUT, the Unraid API); fill it in when your UPS only reports its load. The UPS label or manual gives it in watts, not VA.'))));
    if (!Array.isArray(g.search_in)) g.search_in = [];
    var src = function (k, lab, hint) {
      return h('label', { class: 'switch t' }, h('input', { type: 'checkbox', checked: g.search_in.indexOf(k) >= 0, onchange: function (e) {
        g.search_in = g.search_in.filter(function (x) { return x !== k; }); if (e.target.checked) g.search_in.push(k); dirty(); } }),
        h('span', null, h('b', null, lab), h('span', { class: 'muted sm' }, ' · ' + hint)));
    };
    var seerr = null; D.cfg.groups.forEach(function (gr) { gr.services.forEach(function (x) { if (!seerr && x.type === 'overseerr') seerr = x; }); });
    b.appendChild(set('Search', 'The search box at the top (press / to jump to it) lists what it finds underneath; the dashboard itself is never filtered. Turn everything off to hide the box.',
      h('div', { class: 'olist' },
        h('div', { class: 'oi' }, src('apps', 'Apps and containers', 'your apps, containers and VMs by name; opens their web page')),
        h('div', { class: 'oi' }, src('web', 'Web search', 'one line to search the web with the engine below'),
          sIn(g, 'search', dirty, [['duckduckgo', 'DuckDuckGo'], ['google', 'Google'], ['bing', 'Bing'], ['startpage', 'Startpage'], ['brave', 'Brave Search']])),
        h('div', { class: 'oi' }, src('seerr', 'Movies and series', seerr ? 'found in ' + seerr.name + ', with a Request button' : 'needs Overseerr or Jellyseerr in your apps')))));
  }

  /* Ignored alerts: changes apply at once (no Save needed); restoring only removes the rule, the next refresh shows the alert again if it still applies */
  function tabIgnored(b) {
    var list = h('div', { class: 'olist ign-list', 'aria-live': 'polite' }), V = { q: '', sev: '', rules: null, now: 0, cats: {} };
    var q = h('input', { type: 'search', placeholder: 'Filter by title, resource or category', 'aria-label': 'Filter ignored alerts', oninput: function () { V.q = q.value.toLowerCase(); paint(); } });
    var sev = h('select', { 'aria-label': 'Severity', onchange: function () { V.sev = sev.value; paint(); } }, [['', 'All severities'], ['bad', 'Critical'], ['warn', 'Warning'], ['info', 'Info']].map(function (o) { return h('option', { value: o[0] }, o[1]); }));
    var purge = btn('Delete expired', function () { act('ignore_purge_expired', {}).then(function (j) { if (!j.error) { toast(j.removed ? j.removed + ' expired rule' + (j.removed > 1 ? 's' : '') + ' deleted.' : 'Nothing had expired.'); load(); } }); }, 'sm', 'trash');
    var all = btn('Restore all', function () {
      confirmBox('Restore every ignored alert?', (V.rules.length > 1 ? 'All ' + V.rules.length + ' ignore rules are' : 'The ignore rule is') + ' removed. Alerts whose condition still exists show up again on the next refresh.', 'Restore all', true).then(function (y) {
        if (y) act('ignore_restore_all', { confirm: 'yes' }).then(function (j) { if (!j.error) { toast('All ignored alerts restored.'); S.ignHold = []; refreshNow(); load(); } });
      });
    }, 'sm danger', 'restart');
    b.appendChild(set('Ignored alerts', 'Ignoring only hides an alert on the dashboard. UnDash keeps checking, and an alert comes back when its rule is restored or runs out while the problem is still there.',
      h('div', null, h('div', { class: 'ign-bar' }, q, sev, h('span', { class: 'grow' }), btn('History', alertHistory, 'sm', 'clock', 'When each problem started and ended'), purge, all), list)));
    function when(t) { return new Date(t * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); }
    function row(r) {
      var expired = r.until !== null && r.until <= V.now;
      return h('div', { class: 'oi ign' + (expired ? ' expired' : '') },
        h('span', { class: 'badge ' + (r.severity === 'bad' ? 'bad' : r.severity === 'info' ? 'plain' : 'warn') }, r.severity === 'bad' ? 'critical' : r.severity === 'info' ? 'info' : 'warning'),
        h('span', { class: 't' }, h('b', null, r.title || V.cats[r.category] || r.category),
          h('span', { class: 'muted sm' }, [V.cats[r.category] || r.category, r.kind === 'type' ? 'every ' + (r.category.indexOf('docker.') === 0 ? 'container' : r.category.indexOf('disk.') === 0 ? 'disk' : 'occurrence') : r.resource,
            r.kind === 'type' ? 'scope: alert type' : 'scope: this alert', 'ignored ' + when(r.created),
            r.until === null ? 'never expires' : (expired ? 'expired ' : 'until ') + when(r.until)].join(' · '))),
        btn('Restore', function () { ignoreRemove([r.id], 'Alert restored.').then(function (j) { if (!j.error) load(); }); }, 'sm'));
    }
    function paint() {
      clear(list);
      if (!V.rules) { list.appendChild(h('div', { class: 'progress' }, h('i'))); return; }
      all.disabled = !V.rules.length;
      purge.disabled = !V.rules.some(function (r) { return r.until !== null && r.until <= V.now; });
      if (!V.rules.length) { list.appendChild(h('div', { class: 'empty' }, 'No ignored alerts. Use Ignore on an alert to hide it for a while or for good.')); return; }
      var rs = V.rules.filter(function (r) {
        if (V.sev && r.severity !== V.sev) return false;
        return !V.q || [r.title, r.resource, r.category, V.cats[r.category] || ''].join(' ').toLowerCase().indexOf(V.q) >= 0;
      });
      if (!rs.length) { list.appendChild(h('div', { class: 'empty' }, 'No ignored alerts match the filter.')); return; }
      [['Temporarily ignored', function (r) { return r.kind === 'instance' && r.until !== null; }], ['Permanently ignored', function (r) { return r.kind === 'instance' && r.until === null; }],
       ['Ignored alert types', function (r) { return r.kind === 'type'; }]].forEach(function (g) {
        var items = rs.filter(g[1]);
        if (!items.length) return;
        items.sort(function (a, c) { return c.created - a.created; });
        list.appendChild(h('h4', { class: 'ign-h' }, g[0] + ' (' + items.length + ')'));
        items.forEach(function (r) { list.appendChild(row(r)); });
      });
    }
    function load() { api('ignores_list').then(function (j) { if (j.error) { clear(list); list.appendChild(h('div', { class: 'empty' }, j.error)); return; } V.rules = j.rules; V.now = j.now; V.cats = j.categories || {}; paint(); }); }
    paint(); load();
  }

  function tabAppearance(b, D, dirty) {
    var a = D.cfg.appearance;
    var preview = function () { applyLook(a); };
    var modeSeg = h('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'Mode' });
    [['light', 'Light', 'sun'], ['dark', 'Dark', 'moon'], ['system', 'System', 'screen']].forEach(function (m) {
      modeSeg.appendChild(h('button', { type: 'button', role: 'radio', class: a.mode === m[0] ? 'on' : '', 'aria-checked': a.mode === m[0] ? 'true' : 'false', onclick: function () { a.mode = m[0]; dirty(); preview(); D.render(); } }, ic(m[2]), m[1]));
    });
    var tw = h('div', { class: 'themes', role: 'radiogroup', 'aria-label': 'Theme' });
    syncThemes(a);
    Object.keys(THEMES).forEach(function (id) {
      var t = THEMES[id], p = t[lookMode({ theme: id, mode: a.mode })] || t.dark;
      tw.appendChild(h('button', { type: 'button', class: 'theme' + (a.theme === id ? ' on' : ''), role: 'radio', 'aria-checked': a.theme === id ? 'true' : 'false',
        title: t.light ? 'Light and dark' : 'Dark only',
        onclick: function () { a.theme = id; a.accent = ''; if (t.custom) Object.assign(a, t.custom.look); hex.value = col.value = themeAccent(a); dirty(); preview(); applyCss(a.css_enabled ? D.css : ''); D.render(); } },
        h('span', { class: 'pv', style: { background: a.gradient === false ? p.bg : meshCss(id, lookMode({ theme: id, mode: a.mode })) } },
          h('i', { style: { background: p.panel, border: '1px solid ' + p.line } }), h('i', { style: { background: p.panel, border: '1px solid ' + p.line } }), h('i', { style: { background: p.accent, flex: '0 0 18%' } })),
        h('span', { class: 'tn' }, h('b', null, themeName(t, lookMode({ theme: id, mode: a.mode }))), t.custom ? h('span', { class: 'muted xs' }, 'yours') : t.light ? null : h('span', { class: 'muted xs' }, 'dark only'))));
    });
    function chosen() { var t = THEMES[a.theme] || THEMES.default; return t.custom ? t : null; }
    function fileName(n) { return (n || 'theme').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'theme'; }
    function getFile(accept, done) {
      var f = h('input', { type: 'file', accept: accept, style: { display: 'none' } });
      f.onchange = function () { var x = f.files[0]; if (!x) return; if (x.size > 400000) { toast('That file is too big.', true); return; } var r = new FileReader(); r.onload = function () { done(String(r.result), x.name); }; r.readAsText(x); };
      root.appendChild(f); f.click(); setTimeout(function () { f.remove(); }, 60000);
    }
    function saveFile(name, text, type) { var l = h('a', { href: URL.createObjectURL(new Blob([text], { type: type })), download: name }); root.appendChild(l); l.click(); l.remove(); }
    function lookNow() { var o = { translucent: !!a.translucent, gradient: a.gradient !== false }; ['radius', 'glass_blur', 'glass_opacity', 'glass_saturation', 'glass_exposure', 'glass_contrast', 'wall_saturation', 'wall_contrast', 'wall_opacity'].forEach(function (k) { o[k] = a[k]; }); return o; }
    function exportTheme() {
      var c = chosen(), m = lookMode(a), p = (THEMES[a.theme] || THEMES.default)[m], base = applyBase(p, m, a);
      var t = c ? c.custom : { name: themeName(THEMES[a.theme] || THEMES.default, m), mode: m, colors: base, css: '' };
      saveFile(fileName(t.name) + '.undash-theme.json', JSON.stringify({ undash_theme: 1, name: t.name, mode: t.mode, colors: t.colors, look: lookNow(), css: t.css || '' }, null, 2), 'application/json');
    }
    function addTheme(t) {
      a.custom_themes = (a.custom_themes || []).filter(function (x) { return x.id !== t.id; }).concat([t]);
      a.theme = t.id; a.accent = ''; Object.assign(a, t.look || {}); syncThemes(a); dirty(); preview(); applyCss(a.css_enabled ? D.css : ''); D.render();
    }
    function importTheme() {
      getFile('.json,application/json', function (text) {
        var j = null; try { j = JSON.parse(text); } catch (e) { /* reported below */ }
        if (!j || j.undash_theme !== 1 || !j.colors) { toast('That is not an UnDash theme file.', true); return; }
        var ok = function () { addTheme({ id: 'c-' + rid(), name: String(j.name || 'Imported theme').slice(0, 40), mode: j.mode === 'light' ? 'light' : 'dark', colors: j.colors, look: j.look || {}, css: String(j.css || '') }); toast('Theme imported. Save to keep it.'); };
        if (j.css) confirmBox('Import "' + String(j.name || 'theme').slice(0, 40) + '"?', 'This theme includes custom CSS, which can change how the dashboard looks. Only import themes you trust.', 'Import').then(function (y) { if (y) ok(); }); else ok();
      });
    }
    function editTheme(t0) {
      var nw = !t0, t = nw ? { id: 'c-' + rid(), name: 'My theme', mode: lookMode(a), colors: applyBase((THEMES[a.theme] || THEMES.default)[lookMode(a)], lookMode(a), a), look: lookNow(), css: '' } : JSON.parse(JSON.stringify(t0)), m;
      var keep = { theme: a.theme, list: a.custom_themes || [] };
      function live() { a.custom_themes = keep.list.filter(function (x) { return x.id !== t.id; }).concat([t]); a.theme = t.id; syncThemes(a); applyLook(a); }
      var rows = [['bg', 'Background'], ['panel', 'Cards'], ['text', 'Text'], ['muted', 'Secondary text'], ['line', 'Lines'], ['accent', 'Accent']].map(function (x) {
        return F(x[1], h('input', { type: 'color', value: t.colors[x[0]], 'aria-label': x[1], oninput: function (e) { t.colors[x[0]] = e.target.value; live(); } }));
      });
      var css = h('textarea', { rows: 7, spellcheck: 'false', 'aria-label': 'Theme CSS', placeholder: '/* Optional: CSS that comes with this theme */' }); css.value = t.css || '';
      var back = function () { a.custom_themes = keep.list; a.theme = keep.theme; syncThemes(a); applyLook(a); };
      m = modal(nw ? 'New theme' : 'Edit theme', h('div', null, F('Name', tIn(t, 'name', function () {}, 'My theme')),
        F('Type', sIn(t, 'mode', live, [['dark', 'Dark'], ['light', 'Light']])), h('div', { class: 'tcols' }, rows), F('CSS', css, 'Saved with the theme and exported with it. Your own Custom CSS still applies on top.')),
        [t0 ? btn('Delete', function () { m.close(); a.custom_themes = keep.list.filter(function (x) { return x.id !== t.id; }); a.theme = keep.theme === t.id ? 'default' : keep.theme; syncThemes(a); dirty(); preview(); D.render(); }, 'danger') : null,
         btn('Cancel', function () { m.close(); back(); }), btn(nw ? 'Create' : 'Save', function () { m.close(); t.css = css.value; t.name = (t.name || '').trim() || 'My theme'; a.custom_themes = keep.list; addTheme(t); }, 'pri')].filter(Boolean));
      live();
    }
    var col, hex, cur = a.accent || themeAccent(a);
    hex = h('input', { type: 'text', value: cur, 'aria-label': 'Accent colour (hex)', maxlength: 7, style: { width: '110px' }, oninput: function (e) { var v = e.target.value.trim(); if (/^#[0-9a-f]{6}$/i.test(v)) { a.accent = v.toLowerCase(); col.value = a.accent; e.target.style.borderColor = ''; dirty(); preview(); } else e.target.style.borderColor = 'var(--bad)'; } });
    col = h('input', { type: 'color', value: cur, 'aria-label': 'Accent colour', oninput: function (e) { a.accent = e.target.value; hex.value = a.accent; dirty(); preview(); } });
    b.appendChild(set('Colours', 'Pick a colour theme and whether it is light, dark or follows your system; every theme has both. Status colours (green, amber, red) stay readable in every theme.',
      h('div', { class: 'inl', style: { 'margin-bottom': '12px' } }, h('span', { class: 'muted sm' }, 'Mode'), modeSeg), tw,
      h('div', { class: 'inl', style: { 'margin-top': '12px' } }, btn('New theme', function () { editTheme(null); }, 'sm', 'plus', 'Start from the theme you are using'),
        chosen() ? btn('Edit theme', function () { editTheme(chosen().custom); }, 'sm', 'edit') : null, h('span', { class: 'grow' }),
        btn('Import', importTheme, 'sm', 'up', 'Load a theme file'), btn('Export', exportTheme, 'sm', 'download', 'Save the current theme and look as a file')),
      h('div', { class: 'inl', style: { 'margin-top': '14px' } }, h('span', { class: 'muted sm' }, 'Accent'), col, hex,
        btn(a.accent ? 'Use the theme accent' : 'Theme accent in use', function () { a.accent = ''; hex.value = col.value = themeAccent(a); dirty(); preview(); D.render(); }, 'sm ghost')),
      null));
    var margin = function (k, lab) {
      var auto = a[k] < 0, out = h('span', { class: 'num muted sm', style: { 'min-width': '48px', 'text-align': 'right' } }, auto ? 'auto' : a[k] + ' px');
      var r = h('input', { type: 'range', min: 0, max: 120, value: auto ? (k === 'safe_x' ? 32 : 16) : a[k], disabled: auto, 'aria-label': lab, style: { flex: '1' },
        oninput: function (e) { a[k] = +e.target.value; txt(out, a[k] + ' px'); dirty(); preview(); } });
      return F(lab, h('div', { class: 'inl' }, h('label', { class: 'switch', style: { margin: 0 } }, h('input', { type: 'checkbox', checked: auto, onchange: function (e) {
        a[k] = e.target.checked ? -1 : +r.value; r.disabled = e.target.checked; txt(out, e.target.checked ? 'auto' : a[k] + ' px'); dirty(); preview(); } }), 'Auto'), r, out));
    };
    b.appendChild(set('Spacing', 'How dense the dashboard is, and the safe margin around it (the same in every theme). Auto adapts to the screen.', h('div', { class: 'grid2' },
      F('Density', sIn(a, 'density', dirty, [['compact', 'Compact'], ['comfortable', 'Comfortable'], ['expanded', 'Expanded']], preview)),
      F('Animations', sIn(a, 'motion', dirty, [['full', 'Full'], ['fast', 'Fast'], ['nointro', 'Skip the opening'], ['off', 'Off']], preview), 'Skip the opening keeps the small transitions and shows the dashboard at once. Pressing anything also skips it.'),
      F('Corner radius', rng('radius', 0, 28, ' px', 'Corner radius'), 'How round the cards are on the card board, from square to 28 px.'),
      F('Text size', sIn(a, 'text_size', dirty, [['s', 'Small'], ['m', 'Normal'], ['l', 'Large']]), 'On the card board. Text also follows the size of your screen.'),
      F('Card board (beta)', h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: D.cfg.dashboard.board.on, onchange: function (e) { D.cfg.dashboard.board.on = e.target.checked; dirty(); } }), 'Draggable cards'), 'Off: the classic sections. Your card layout is kept.'),
      F('Section names', h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: a.sec_names !== false, onchange: function (e) { a.sec_names = e.target.checked; dirty(); preview(); } }), 'Show System, Media, Arr Stack…'),
        'Off hides the names above the sections. Their buttons (History, Overseerr…) stay, and a collapsed section still shows its name.'),
      F('Container and VM icons', sIn(a, 'icon_mode', function () { dirty(); }, [['color', 'selfh.st, in colour'], ['auto', 'selfh.st, light or dark to suit the theme'], ['light', 'selfh.st, light'], ['dark', 'selfh.st, dark'], ['off', 'Unraid\'s own icons']], preview),
        'Found by the container\'s name or image (binhex-, linuxserver and similar prefixes are ignored). When selfh.st has none, Unraid\'s icon is used. An icon picked for a container in Sections & apps always wins.'),
      margin('safe_x', 'Side margin'), margin('safe_y', 'Top and bottom margin'))));
    var pd = function () { dirty(); preview(); };
    function rng(k, lo, hi, unit, lab) {
      var out = h('span', { class: 'num muted sm', style: { 'min-width': '44px', 'text-align': 'right' } }, a[k] + unit);
      return h('div', { class: 'inl' }, h('input', { type: 'range', min: lo, max: hi, value: a[k], 'aria-label': lab, style: { flex: '1' },
        oninput: function (e) { a[k] = +e.target.value; txt(out, a[k] + unit); dirty(); preview(); } }), out);
    }
    function resetTo(vals) { return btn('Reset', function () { Object.keys(vals).forEach(function (k) { a[k] = vals[k]; }); dirty(); preview(); D.render(); }, 'sm', 'restart', 'Back to the standard values'); }
    b.appendChild(set('Cards', 'How the cards look over what is behind them. These are separate from the background below.',
      F('Translucent Effect', h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!a.translucent, onchange: function (e) { a.translucent = e.target.checked; dirty(); preview(); } }), 'See-through glass cards'), 'The other card controls need this on. It uses more graphics power on older devices.'),
      h('div', { class: 'grid2' }, F('Card blur', rng('glass_blur', 0, 60, ' px', 'Card blur')), F('Card opacity', rng('glass_opacity', 10, 95, ' %', 'Card opacity'), 'Lower shows more of what is behind.'),
        F('Card exposure', rng('glass_exposure', 40, 160, ' %', 'Card exposure')), F('Card saturation', rng('glass_saturation', 100, 250, ' %', 'Card saturation')), F('Card contrast', rng('glass_contrast', 50, 150, ' %', 'Card contrast'))),
      resetTo({ glass_blur: 26, glass_opacity: 42, glass_saturation: 170, glass_exposure: 100, glass_contrast: 100 })));
    var wd = a.daily, wCustom = F('Your words', tIn(wd, 'custom', dirty, 'autumn forest'), 'What the photo should show. A word or two works best.');
    wCustom.hidden = wd.topic !== 'custom';
    b.appendChild(set('Daily wallpaper', 'A new photo every day from Wikimedia Commons, no key or account needed. It replaces the image below while this is on. Blur and brightness apply to it too.',
      h('div', { class: 'grid2' },
        F('New photo every day', h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: wd.on, onchange: function (e) { wd.on = e.target.checked; dirty(); } }), 'Daily wallpaper')),
        F('Subject', sIn(wd, 'topic', dirty, [['nature', 'Nature'], ['city', 'Cityscape'], ['cars', 'Cars'], ['mountains', 'Mountains'], ['ocean', 'Ocean'], ['space', 'Night sky'], ['custom', 'Custom…']], function () { wCustom.hidden = wd.topic !== 'custom'; })),
        wCustom),
      S.wallErr ? h('div', { class: 'banner bad' }, S.wallErr) : null));
    b.appendChild(set('Background', 'Optional image behind the dashboard (https:// or a path served by Unraid).',
      F('Gradient', h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: a.gradient !== false, onchange: function (e) { a.gradient = e.target.checked; dirty(); preview(); D.render(); } }), 'A gradient in the theme\'s colours'), 'Off gives a plain colour. An image or daily wallpaper takes its place.'),
      h('div', { class: 'grid2' },
      F('Image URL', tIn(a, 'background', pd, 'https://…')),
      F('Blur', sIn(a, 'blur_target', dirty, [['wallpaper', 'The whole wallpaper'], ['cards', 'Only behind the cards (frosted glass)']], preview), 'Frosted glass keeps the picture sharp and blurs it only where cards sit on it.'),
      F('Background blur', rng('blur', 0, 40, ' px', 'Background blur')),
      F('Saturation', rng('wall_saturation', 0, 200, ' %', 'Background saturation'), '0 is black and white.'),
      F('Contrast', rng('wall_contrast', 50, 150, ' %', 'Background contrast')),
      F('Opacity', rng('wall_opacity', 10, 100, ' %', 'Background opacity'), 'Lower fades it into the theme\'s background.'),
      F('Exposure', rng('brightness', 0, 100, ' %', 'Background exposure'), '0% is black, 100% the picture as it is.')), resetTo({ blur: 4, brightness: 75, wall_saturation: 100, wall_contrast: 100, wall_opacity: 100 })));
    var ta = h('textarea', { rows: 14, spellcheck: 'false', 'aria-label': 'Custom CSS', placeholder: '/* Scoped to UnDash automatically. Examples: */\n.card { border-radius: 12px; }\n.metric .mv { font-size: 26px; }\n--radius: 12px;' });
    ta.value = D.css || '';
    var msg = h('div', { class: 'sm muted', role: 'status' });
    var live = h('input', { type: 'checkbox', checked: true });
    function check() {
      var css = ta.value, depth = 0, bad = false;
      css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/g, '').split('').forEach(function (ch) { if (ch === '{') depth++; else if (ch === '}') { depth--; if (depth < 0) bad = true; } });
      if (bad || depth !== 0) { msg.textContent = 'Unbalanced { } braces: this CSS will not apply correctly.'; msg.style.color = 'var(--bad)'; return; }
      var n = null;
      try { var sh = new CSSStyleSheet(); sh.replaceSync('.und{' + css + '}'); n = sh.cssRules.length && sh.cssRules[0].cssRules ? sh.cssRules[0].cssRules.length : null; } catch (e) { /* older browser */ }
      msg.style.color = '';
      msg.textContent = css.trim() ? 'Parsed' + (n !== null ? ': ' + n + ' rule' + (n === 1 ? '' : 's') : '') + '. Invalid declarations are ignored by the browser; @import and scripts are removed when saved.' : 'No custom CSS.';
    }
    ta.addEventListener('input', function () { D.css = ta.value; D.cssChanged = true; dirty(); check(); if (live.checked && a.css_enabled) applyCss(ta.value); });
    ta.addEventListener('keydown', function (e) { if (e.key === 'Tab' && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); ta.setRangeText('  ', ta.selectionStart, ta.selectionEnd, 'end'); ta.dispatchEvent(new Event('input')); } });
    live.addEventListener('change', function () { applyCss(live.checked && a.css_enabled ? ta.value : S.boot.css); });
    b.appendChild(set('Custom CSS', 'Your own CSS on top of the theme. It only styles UnDash (it is nested under .und), can be switched off without losing it, and is never run as code. Tab indents; Esc then Tab leaves the editor.',
      h('div', { class: 'inl', style: { 'margin-bottom': '8px' } },
        h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: a.css_enabled, onchange: function (e) { a.css_enabled = e.target.checked; dirty(); applyCss(a.css_enabled ? ta.value : ''); } }), 'Enabled'),
        h('label', { class: 'switch' }, live, 'Live preview'), h('span', { class: 'grow' }),
        btn('Import', function () { var f = h('input', { type: 'file', accept: '.css,text/css,text/plain', style: { display: 'none' } }); f.onchange = function () { var x = f.files[0]; if (!x) return; if (x.size > 200000) { toast('That file is too big.', true); return; } var r = new FileReader(); r.onload = function () { ta.value = String(r.result); ta.dispatchEvent(new Event('input')); toast('CSS imported. Save to keep it.'); }; r.readAsText(x); }; f.click(); }, 'sm', 'up', 'Load a .css file into the editor'),
        btn('Export', function () { var l = h('a', { href: URL.createObjectURL(new Blob([ta.value], { type: 'text/css' })), download: 'undash-custom.css' }); root.appendChild(l); l.click(); l.remove(); }, 'sm', 'download', 'Save this CSS as a file'),
        btn('Reset', function () { confirmBox('Clear the custom CSS?', 'The editor is emptied; the theme is not affected. Save to keep the change.', 'Clear', true).then(function (y) { if (y) { ta.value = ''; ta.dispatchEvent(new Event('input')); } }); }, 'sm danger', 'trash')),
      ta, h('div', { style: { 'margin-top': '6px' } }, msg)));
    check();
  }

  /* ---- Apps: every app is added, changed, hidden and removed here. Addresses go to config.yaml, keys and passwords to .env (server side) ---- */
  var APP_CATS = [['Arr Stack', ['radarr', 'sonarr', 'lidarr', 'readarr', 'prowlarr', 'bazarr', 'autobrr', 'overseerr', 'wizarr']],
    ['Downloads', ['qbittorrent', 'qui', 'sabnzbd']], ['Media', ['plex', 'jellyfin', 'emby', 'tautulli', 'tautulli_recent', 'calendar', 'tdarr', 'unmanic']],
    ['Network and tools', ['adguard', 'pihole', 'npm', 'gluetun', 'immich', 'homeassistant', 'peanut']], ['Monitoring', ['scrutiny', 'uptimekuma', 'speedtest']], ['Server', ['storage', 'docker_summary', 'vm_summary', 'ups']], ['Widgets', ['weather', 'releases', 'bookmarks']], ['Other', ['customapi', 'link']]];
  var APP_PORT = { radarr: 7878, sonarr: 8989, lidarr: 8686, readarr: 8787, prowlarr: 9696, bazarr: 6767, autobrr: 7474, overseerr: 5055, wizarr: 5690, qbittorrent: 8080, qui: 7476, sabnzbd: 8080,
    plex: 32400, jellyfin: 8096, emby: 8096, tautulli: 8181, tautulli_recent: 8181, adguard: 3000, pihole: 80, npm: 81, immich: 2283, peanut: 8080, customapi: 80,
    scrutiny: 8080, tdarr: 8265, unmanic: 8888, uptimekuma: 3001, speedtest: 80, gluetun: 8000, homeassistant: 8123 };
  var APP_ICON = { weather: '', releases: 'github', bookmarks: '', tdarr: 'tdarr.png', unmanic: 'unmanic.png', uptimekuma: 'uptime-kuma', speedtest: 'speedtest-tracker', homeassistant: 'home-assistant', adguard: 'adguard-home', pihole: 'pi-hole', npm: 'nginx-proxy-manager', tautulli_recent: 'tautulli', storage: 'unraid', docker_summary: 'docker', vm_summary: 'qemu', ups: 'nut', customapi: '', link: '', calendar: '' };
  var KEY_WHERE = { radarr: 'Radarr: Settings > General', sonarr: 'Sonarr: Settings > General', lidarr: 'Lidarr: Settings > General', readarr: 'Readarr: Settings > General', prowlarr: 'Prowlarr: Settings > General',
    bazarr: 'Bazarr: Settings > General', overseerr: 'Overseerr / Jellyseerr: Settings > General', sabnzbd: 'SABnzbd: Config > General', qui: 'qui: Settings > API Keys', autobrr: 'autobrr: Settings > API Keys',
    tautulli: 'Tautulli: Settings > Web Interface', tautulli_recent: 'Tautulli: Settings > Web Interface', emby: 'Emby: Dashboard > Advanced > API Keys', jellyfin: 'Jellyfin: Dashboard > API Keys', immich: 'Immich: Account Settings > API Keys',
    tdarr: 'Tdarr: Tools > API keys (only when its login is on; else leave it empty)', speedtest: 'Speedtest Tracker: API Tokens (v1 and later; older versions need none)',
    gluetun: 'Gluetun\'s control server settings (only if you set one; else leave it empty)', homeassistant: 'Home Assistant: your profile > Security > Long-lived access tokens',
    releases: 'GitHub: Settings > Developer settings > Personal access tokens, with no scopes (optional: without one GitHub allows 60 checks an hour)' };
  var KEY_OPT = { peanut: 1, customapi: 1, tdarr: 1, speedtest: 1, gluetun: 1, releases: 1 };     // apps that work without a key
  var ZONE_NAME = { arr: 'Arr Stack', downloads: 'Downloads', media: 'Media', overview: 'System overview', apps: 'Apps' };
  function appIcon(t) { var n = APP_ICON[t] !== undefined ? APP_ICON[t] : t; return n ? 'sh-' + n : ''; }
  function appZone(D, s) { return s.type === 'customapi' && /get_recently_added/.test(s.url || '') ? 'media' : (D.zones || {})[s.type] || 'apps'; }
  function credLabel(t, k) { return { key: t === 'plex' ? 'Plex token' : t === 'peanut' ? 'Device key' : t === 'pihole' ? 'Password / app password' : t === 'homeassistant' ? 'Access token' : t === 'speedtest' ? 'API token' : t === 'releases' ? 'GitHub token' : 'API key', username: 'Username', password: 'Password' }[k]; }

  /* the app lists in Settings > Sections & apps: one per section (zone), plus the groups of the Apps section.
     Every list redraws together, so moving or finding an app updates all of them. */
  function appsEditor(D, dirty, after) {
    var E = { q: '', draws: [] }, redraw = function () { E.draw(); };
    E.draw = function () { E.draws.forEach(function (f) { f(); }); if (after) after(); };
    function entries() { var out = []; D.cfg.groups.forEach(function (g) { g.services.forEach(function (s) { out.push({ s: s, g: g }); }); }); return out; }
    function hit(x) { return !E.q || [x.s.name, (D.types[x.s.type] || [''])[0], x.g.name].join(' ').toLowerCase().indexOf(E.q) >= 0; }
    E.hits = function (z) { return entries().filter(function (x) { return appZone(D, x.s) === z && hit(x); }).length; };
    function swap(a, c) { var ia = a.g.services.indexOf(a.s), ic2 = c.g.services.indexOf(c.s); a.g.services[ia] = c.s; c.g.services[ic2] = a.s; dirty(); redraw(); }
    function row(x, list) {
      var s = x.s, T = D.types[s.type] || [s.type, [], false], c = D.creds[s.id] || {}, i = list.indexOf(x);
      var warn = T[2] && !String(s.url || '').trim() ? 'no address' : (T[1] || []).indexOf('key') >= 0 && !KEY_OPT[s.type] && !c.key ? 'no ' + credLabel(s.type, 'key').toLowerCase() : '';
      var edit = function () { editService(D, s, x.g, false, redraw, dirty); };
      return h('div', { class: 'oi ae-row' + (s.hidden ? ' off' : '') },
        img(iconUrl(s.icon), '', 'web'),
        h('button', { type: 'button', class: 't ae-name', onclick: edit, title: 'Edit ' + s.name }, h('b', null, s.name || T[0]), h('span', { class: 'muted sm' }, ' · ' + T[0] + (s.hidden ? ' · hidden' : ''))),
        warn ? h('span', { class: 'badge warn' }, warn) : null,
        btn('', function () { if (i > 0) swap(x, list[i - 1]); }, 'sm ghost', 'upar', 'Move ' + s.name + ' up'),
        btn('', function () { if (i < list.length - 1) swap(x, list[i + 1]); }, 'sm ghost', 'dnar', 'Move ' + s.name + ' down'),
        btn('', function () { s.hidden = !s.hidden; dirty(); redraw(); }, 'sm ghost', s.hidden ? 'eyeoff' : 'eye', (s.hidden ? 'Show ' : 'Hide ') + s.name),
        btn('', edit, 'sm', 'edit', 'Edit ' + s.name),
        btn('', function () {
          confirmBox('Remove ' + s.name + '?', 'It leaves the dashboard when you save. Its stored key and password are deleted with it.', 'Remove', true).then(function (y) {
            if (!y) return;
            x.g.services.splice(x.g.services.indexOf(s), 1); delete D.creds[s.id]; dirty(); redraw();
          });
        }, 'sm ghost danger', 'trash', 'Remove ' + s.name));
    }
    /* every app at a glance, on the first page of Sections & apps */
    E.grid = function () {
      var box = h('div', { class: 'appg', role: 'list' });
      E.draws.push(function () {
        var needs = function (x) { var T = D.types[x.s.type] || [x.s.type, [], false], k = (D.creds[x.s.id] || {}).key; return T[2] && !String(x.s.url || '').trim() ? 'No address yet' : (T[1] || []).indexOf('key') >= 0 && !KEY_OPT[x.s.type] && !k ? 'Needs its ' + credLabel(x.s.type, 'key').toLowerCase() : ''; };
        var list = entries().filter(function (x) { return hit(x) && (!E.zoneF || appZone(D, x.s) === E.zoneF); });
        list = list.filter(needs).concat(list.filter(function (x) { return !needs(x); }));       // what still needs setting up comes first
        clear(box);
        if (!list.length) { box.appendChild(h('div', { class: 'empty' }, E.q ? 'No app matches.' : 'No apps yet. Add one, or pick from what was found below.')); return; }
        list.forEach(function (x) {
          var s = x.s, T = D.types[s.type] || [s.type, [], false], warn = needs(x), edit = function () { editService(D, s, x.g, false, redraw, dirty); };
          var more = btn('', function () {
            menu(more, [['Edit…', 'edit', edit], [s.hidden ? 'Show on dashboard' : 'Hide from dashboard', s.hidden ? 'eye' : 'eyeoff', function () { s.hidden = !s.hidden; dirty(); redraw(); }], 'hr',
              ['Remove…', 'trash', function () { confirmBox('Remove ' + s.name + '?', 'It leaves the dashboard when you save. Its stored key and password are deleted with it.', 'Remove', true).then(function (y) { if (!y) return; x.g.services.splice(x.g.services.indexOf(s), 1); delete D.creds[s.id]; dirty(); redraw(); }); }, 'danger']]);
          }, 'sm ghost', 'gear', 'More for ' + s.name);
          box.appendChild(h('div', { class: 'appt' + (s.hidden ? ' off' : ''), role: 'listitem' },
            h('button', { type: 'button', class: 'appb', onclick: edit, title: 'Edit ' + s.name }, img(iconUrl(s.icon), '', 'web'),
              h('span', { class: 'crt' }, h('b', { class: 'ell' }, s.name || T[0]), h('span', { class: 'xs ell ' + (warn ? 't-warn' : 'muted') }, warn || (s.hidden ? 'Hidden' : T[0].replace(/ \(.*\)$/, ''))))), more));
        });
      });
      return box;
    };
    /* the apps of one section other than Apps */
    E.zone = function (z) {
      var box = h('div', { class: 'ae-sec' });
      E.draws.push(function () {
        var list = entries().filter(function (x) { return appZone(D, x.s) === z; }), shown = list.filter(hit);
        clear(box).appendChild(h('div', { class: 'ae-h' }, h('b', null, 'Apps'), h('span', { class: 'muted sm' }, String(list.length)), h('span', { class: 'grow' }),
          btn('Add', function () { addApp(D, z, null, redraw, dirty); }, 'sm ghost', 'plus', 'Add an app to ' + ZONE_NAME[z])));
        box.appendChild(h('div', { class: 'olist' }, shown.length ? shown.map(function (x) { return row(x, list); })
          : h('div', { class: 'empty' }, E.q ? 'No app here matches.' : { arr: 'Add Radarr, Sonarr, Prowlarr, Overseerr…', downloads: 'Add qBittorrent, SABnzbd, Transmission…', media: 'Media uses your Radarr and Sonarr; apps added here show recent items.', overview: 'Apps such as a UPS (PeaNUT) show as tiles here.' }[z] || 'None yet.')));
      });
      return box;
    };
    /* the groups of the Apps section: name, show name, order, apps */
    E.groups = function () {
      var box = h('div');
      E.draws.push(function () {
        clear(box);
        var all = entries(), holders = [];
        D.cfg.groups.forEach(function (g, gi) {
          var list = all.filter(function (x) { return x.g === g && appZone(D, x.s) === 'apps'; }), shown = list.filter(hit);
          var others = g.services.filter(function (s) { return appZone(D, s) !== 'apps'; });
          if (!list.length && others.length) { holders.push(g.name); return; }     // only Arr, download … apps: nothing of it shows in Apps
          if (E.q && !shown.length) return;
          box.appendChild(h('div', { class: 'ae-sec' + (g.header ? '' : ' noname') }, h('div', { class: 'ae-h' },
            h('input', { type: 'text', value: g.name, maxlength: 60, 'aria-label': 'Group name', title: 'Group name', oninput: function (e) { g.name = e.target.value; dirty(); } }),
            h('label', { class: 'switch sm', title: 'Show this group\'s name on the dashboard' }, h('input', { type: 'checkbox', checked: g.header, onchange: function (e) { g.header = e.target.checked; dirty(); e.target.closest('.ae-sec').classList.toggle('noname', !g.header); } }), 'Show name'),
            h('span', { class: 'muted sm' }, String(list.length)), h('span', { class: 'grow' }),
            btn('', function () { if (move(D.cfg.groups, gi, -1)) { dirty(); redraw(); } }, 'sm ghost', 'upar', 'Move group ' + g.name + ' up'),
            btn('', function () { if (move(D.cfg.groups, gi, 1)) { dirty(); redraw(); } }, 'sm ghost', 'dnar', 'Move group ' + g.name + ' down'),
            btn('Add', function () { addApp(D, 'apps', g, redraw, dirty); }, 'sm ghost', 'plus', 'Add an app to ' + g.name),
            D.cfg.groups.length > 1 ? btn('', function () {
              var n = g.services.length - others.length;
              confirmBox('Delete the group "' + g.name + '"?', n ? 'Its ' + n + ' app' + (n > 1 ? 's are' : ' is') + ' removed too when you save.' : 'It has no apps.', 'Delete', true).then(function (y) {
                if (!y) return;
                var to = D.cfg.groups[gi ? 0 : 1];
                others.forEach(function (s) { to.services.push(s); });          // Arr and download apps kept in this group stay
                g.services.forEach(function (s) { if (others.indexOf(s) < 0) delete D.creds[s.id]; });
                D.cfg.groups.splice(gi, 1); dirty(); redraw();
              });
            }, 'sm ghost danger', 'trash', 'Delete group ' + g.name) : null),
            h('div', { class: 'olist' }, shown.length ? shown.map(function (x) { return row(x, list); }) : h('div', { class: 'empty' }, 'No apps in this group yet.'))));
        });
        if (holders.length && !E.q) box.appendChild(h('div', { class: 'hint', style: { 'margin-bottom': '10px' } }, 'Not shown here: ' + holders.join(', ') + ' (their apps are all in other sections).'));
        if (!E.q) box.appendChild(h('div', null, btn('Add group', function () { D.cfg.groups.push({ id: rid(), name: 'New group', columns: 4, header: true, services: [] }); dirty(); redraw(); }, 'sm', 'plus')));
        else if (!box.childNodes.length) box.appendChild(h('div', { class: 'empty' }, 'No app in the Apps section matches.'));
      });
      return box;
    };
    return E;
  }

  /* the icon selector: browse or search selfh.st and dashboard-icons (the server keeps their name lists); onpick gets the Icon field value.
     cur (optional, '-' for none set) adds "Use the automatic icon". Pages load as you scroll. */
  function iconPicker(q0, onpick, cur) {
    var grid = h('div', { class: 'ipick' }), note = h('div', { class: 'muted sm', role: 'status' }), t = null, seq = 0, m, st = { src: '', style: '', off: 0, more: false, busy: false }, PAGE = 90;
    var find = h('input', { type: 'search', value: q0 || '', placeholder: 'Search about 5,000 icons…', 'aria-label': 'Search icons', oninput: function () { clearTimeout(t); t = setTimeout(function () { look(true); }, 220); } });
    function seg(k, opts, label) {
      var box = h('div', { class: 'seg', role: 'group', 'aria-label': label }), bs = opts.map(function (o) {
        return h('button', { type: 'button', class: st[k] === o[0] ? 'on' : '', 'aria-pressed': st[k] === o[0] ? 'true' : 'false', onclick: function () {
          st[k] = o[0]; bs.forEach(function (b, i) { var on = opts[i][0] === o[0]; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
          look(true);
        } }, o[1]);
      });
      bs.forEach(function (b) { box.appendChild(b); });
      return box;
    }
    function look(fresh) {
      if (fresh) { st.off = 0; clear(grid); grid.scrollTop = 0; }
      var my = ++seq; st.busy = true;
      if (fresh) txt(note, 'Loading…');
      api('icons', { q: find.value.trim(), max: PAGE, offset: st.off, src: st.src, style: st.style, browse: 1 }, 25000).then(function (r) {
        if (my !== seq) return;
        st.busy = false;
        if (r.error) { txt(note, r.error); return; }
        st.more = !!r.more; st.off += r.icons.length;
        txt(note, r.total ? r.total.toLocaleString() + ' icon' + (r.total > 1 ? 's' : '') : 'No icon matches. An image address works too.');
        r.icons.forEach(function (x) {
          grid.appendChild(h('button', { type: 'button', title: x.n + ' (' + x.s + ')', onclick: function () { m.close(); onpick(x.v); } }, img(iconUrl(x.v), '', 'web'), h('span', { class: 'ell' }, x.n)));
        });
        if (st.more && grid.scrollHeight <= grid.clientHeight + 40) look(false);
      });
    }
    grid.addEventListener('scroll', function () { if (st.more && !st.busy && grid.scrollTop + grid.clientHeight > grid.scrollHeight - 300) look(false); });
    var foot = [btn('Cancel', function () { m.close(); })];
    if (cur !== undefined) foot.unshift(btn('Use the automatic icon', function () { m.close(); onpick(''); }, '', 'restart'));
    m = modal('Choose an icon', h('div', { class: 'ipk' }, find, h('div', { class: 'ipf' }, seg('src', [['', 'All'], ['selfhst', 'selfh.st'], ['di', 'Dashboard Icons']], 'Source'),
      seg('style', [['', 'Colour'], ['light', 'Light'], ['dark', 'Dark']], 'Version'), h('span', { class: 'grow' }), note), grid), foot, true);
    look(true);
    setTimeout(function () { find.focus(); find.select(); }, 0);
  }

  /* the releases widget: tick containers to follow the GitHub repository each comes from (its image's source label) */
  function followPicker(cur, done) {
    var have = {}, picked = {}, m, list = h('div', { class: 'olist pick-list' }, h('div', { class: 'progress' }, h('i')));
    cur.split(/[\s,]+/).forEach(function (x) { if (x) have[x.replace(/^(https?:\/\/)?(www\.)?github\.com\//i, '').replace(/\/$/, '').toLowerCase()] = 1; });
    api('container_sources').then(function (r) {
      clear(list);
      if (r.error) { list.appendChild(h('div', { class: 'banner bad' }, r.error)); return; }
      r.containers.forEach(function (c) {
        var on = !!have[c.repo.toLowerCase()];
        list.appendChild(h('div', { class: 'oi' + (c.repo ? '' : ' off') },
          h('label', { class: 'switch t' }, h('input', { type: 'checkbox', checked: on, disabled: !c.repo || on, onchange: function (e) { if (e.target.checked) picked[c.repo] = 1; else delete picked[c.repo]; } }),
            h('span', null, h('b', null, c.name), h('span', { class: 'muted sm' }, ' · ' + (c.repo ? c.repo + (c.guess ? ' (from the image name)' : '') + (on ? ' · already followed' : '') : 'no GitHub source in the image'))))));
      });
      if (!r.containers.length) list.appendChild(h('div', { class: 'empty' }, 'No containers found.'));
    });
    m = modal('Follow the apps you run', h('div', null, h('p', { class: 'muted sm' }, 'The repository comes from each image\'s source label. For linuxserver.io images that is their Docker repository (docker-radarr), whose releases follow the app\'s.'), list),
      [btn('Cancel', function () { m.close(); }), btn('Add', function () {
        var add = Object.keys(picked);
        m.close(); if (add.length) done((cur.trim() ? cur.replace(/\s+$/, '') + '\n' : '') + add.join('\n'));
      }, 'pri')], true);
  }

  /* containers that look like an app type UnDash reads, and are not added yet. Matched on the image name
     (lscr.io/linuxserver/radarr, binhex/arch-qbittorrentvpn …), then on the container name */
  var FOUND_RE = [['overseerr', /^(overseerr|jellyseerr|seerr)$/], ['plex', /^(plex|pms-docker|plex-media-server|plexmediaserver)$/], ['emby', /^(emby|embyserver)$/],
    ['immich', /^immich(-server)?$/], ['adguard', /^adguard-?home$/], ['pihole', /^pi-?hole$/], ['npm', /^nginx-proxy-manager$/], ['peanut', /^peanut$/],
    ['qbittorrent', /^qbittorrent$/], ['qui', /^qui$/], ['sabnzbd', /^sabnzbd$/], ['jellyfin', /^jellyfin$/], ['tautulli', /^tautulli$/], ['radarr', /^radarr$/],
    ['sonarr', /^sonarr$/], ['scrutiny', /^scrutiny(-web)?$/], ['tdarr', /^tdarr$/], ['unmanic', /^unmanic$/], ['uptimekuma', /^uptime-?kuma$/],
    ['speedtest', /^speedtest-tracker$/], ['gluetun', /^gluetun$/], ['homeassistant', /^home-?assistant$/], ['lidarr', /^lidarr$/], ['readarr', /^readarr$/], ['bazarr', /^bazarr$/], ['prowlarr', /^prowlarr$/], ['autobrr', /^autobrr$/], ['wizarr', /^wizarr$/]];
  function foundType(c) {
    var norm = function (v) { return String(v || '').toLowerCase().replace(/^(arch-|docker-)/, '').replace(/-?vpn$/, ''); };
    var img0 = norm(String(c.image || '').split('@')[0].split('/').pop().split(':')[0]), nm = norm(c.name);
    for (var p = 0; p < 2; p++) for (var i = 0; i < FOUND_RE.length; i++) if (FOUND_RE[i][1].test(p ? nm : img0)) return FOUND_RE[i][0];
    return '';
  }
  function origin(u) { var m = /^(https?:\/\/[^\/?#]+)/i.exec(String(u || '').trim()); return m ? m[1].toLowerCase() : ''; }
  function portOf(u) { var o = origin(u), m = /:(\d+)$/.exec(o); return m ? +m[1] : o ? (o.indexOf('https') === 0 ? 443 : 80) : -1; }
  /* containers added since the page loaded: ask the server now instead of waiting for the next refresh to be drawn */
  function rescan(D, b, done) {
    var seen = function () { return foundApps(D).length + otherApps(D).length; }, before = seen();
    b.disabled = true;
    api('live', { parts: 'docker' }, 20000).then(function (j) {
      b.disabled = false;
      var err = j.error || (j.docker && j.docker.error);
      if (err) { toast(err, true); return; }
      S.live = Object.assign({}, S.live, { docker: j.docker });
      var n = seen() - before, all = (j.docker.containers || []).length;
      toast('Checked ' + all + ' containers: ' + (n > 0 ? n + ' new.' : 'nothing new.'));
      done();
    });
  }
  /* the rest: running containers with a web page that UnDash has no reader for. They can still be added, as a link */
  var NOT_WEB = { 3306: 1, 5432: 1, 6379: 1, 27017: 1, 1433: 1, 11211: 1 };
  function otherApps(D) {
    var have = [];
    D.cfg.groups.forEach(function (g) { g.services.forEach(function (s) { have.push(s); }); });
    return (((S.live || {}).docker || {}).containers || []).map(function (c) {
      if (c.state !== 'running' || foundType(c) || /^undash(-|$)/i.test(c.name)) return null;
      var url = origin(c.webui), hp = 0;
      if (!url) { Object.keys(c.ports || {}).forEach(function (k) { if (!hp && !NOT_WEB[parseInt(k, 10)]) hp = c.ports[k]; }); if (hp) url = 'http://' + location.hostname + ':' + hp; }
      if (!url || have.some(function (s) { return origin(s.url || s.href) === url || portOf(s.url || s.href) === portOf(url) || String(s.name).toLowerCase() === c.name.toLowerCase(); })) return null;
      return { c: c, t: 'link', name: c.name, url: url, href: url, icon: c.icon || 'sh-' + c.name.toLowerCase() };
    }).filter(Boolean).sort(function (a, b) { return a.name.localeCompare(b.name); });
  }
  function foundApps(D) {
    var have = [];
    D.cfg.groups.forEach(function (g) { g.services.forEach(function (s) { have.push(s); }); });
    return (((S.live || {}).docker || {}).containers || []).map(function (c) {
      var t = foundType(c);
      if (!t || !D.types[t]) return null;
      // the address UnDash reads it at: the web UI's origin, else the server and the published port
      var url = origin(c.webui), ports = c.ports || {};
      if (!url) {
        var hp = 0;
        // Gluetun publishes the ports of the apps behind it: only its control server port is its own
        Object.keys(ports).forEach(function (k) { if (parseInt(k, 10) === APP_PORT[t] || (!hp && t !== 'gluetun')) hp = ports[k]; });
        if (hp) url = 'http://' + location.hostname + ':' + hp;
      }
      var label = D.types[t][0].replace(/ \(.*\)$/, '');
      // already added: same address, same port (the app may be added by host name) or same name
      if (have.some(function (s) { return s.type === t && (url && (origin(s.url) === url || portOf(s.url) === portOf(url)) || String(s.name).toLowerCase() === c.name.toLowerCase()); })) return null;
      var more = have.some(function (s) { return s.type === t; });
      return { c: c, t: t, url: url, href: c.webui && c.webui.replace(/\/$/, '') !== url ? c.webui : '', name: more ? c.name : label };
    }).filter(Boolean);
  }
  function addFound(D, f, group, after, dirty) {
    var g = group || D.cfg.groups.filter(function (x) { return appZone(D, { type: f.t }) !== 'apps' || x.services.some(function (s) { return appZone(D, s) === 'apps'; }); })[0] || D.cfg.groups[0];
    if (!g) { g = { id: rid(), name: 'Apps', columns: 4, header: true, services: [] }; D.cfg.groups.push(g); }
    editService(D, { id: rid(), name: f.name, type: f.t, icon: f.icon || appIcon(f.t), href: f.href, url: f.url, description: '', hidden: false, favorite: false, span: 1, fields: [], options: {} }, g, true, after, dirty);
  }
  /* a container with no reader needs nothing filled in: one click puts it on the dashboard as a shortcut */
  function addLink(D, f, dirty, redraw) {
    var g = D.cfg.groups.filter(function (x) { return x.services.some(function (s) { return appZone(D, s) === 'apps'; }); })[0] || D.cfg.groups[0];
    if (!g) { g = { id: rid(), name: 'Apps', columns: 4, header: true, services: [] }; D.cfg.groups.push(g); }
    g.services.push({ id: rid(), name: f.name, type: 'link', icon: f.icon, href: f.href, url: f.url, description: '', hidden: false, favorite: false, span: 1, fields: [], options: {} });
    dirty(); redraw(); toast(f.name + ' added. Save to keep it.');
  }
  function foundBtn(D, f, onpick) {
    return h('button', { type: 'button', title: 'Add ' + f.c.name + (f.url ? ' (' + f.url + ')' : ''), onclick: onpick },
      img(iconUrl(f.icon || appIcon(f.t)), '', 'web'), h('span', { class: 'ell' }, f.name), f.name.toLowerCase() === f.c.name.toLowerCase() ? null : h('span', { class: 'muted sm ell' }, f.c.name));
  }

  /* pick a type, then fill in the details */
  function addApp(D, zone, group, after, dirty) {
    var qq = '', grid = h('div');
    var find = h('input', { type: 'search', placeholder: 'Search apps…', 'aria-label': 'Search app types', oninput: function () { qq = find.value.toLowerCase(); paint(); } });
    var order = APP_CATS.slice().sort(function (a, c) { var za = appZone(D, { type: a[1][0] }) === zone, zc = appZone(D, { type: c[1][0] }) === zone; return za === zc ? 0 : za ? -1 : 1; });
    var m;
    var found = foundApps(D);
    function paint() {
      clear(grid);
      var fs = found.filter(function (f) { return !qq || (f.name + ' ' + f.c.name + ' ' + f.t).toLowerCase().indexOf(qq) >= 0; });
      if (fs.length || !qq) {
        var again = btn('Refresh', function () { rescan(D, again, function () { found = foundApps(D); paint(); }); }, 'sm ghost', 'restart', 'Look for containers added since this page opened');
        grid.appendChild(h('div', { class: 'tp-h tp-r' }, 'Found on your server', again));
        if (!fs.length) grid.appendChild(h('div', { class: 'muted sm' }, 'No new containers UnDash can read.'));
        grid.appendChild(h('div', { class: 'tpick found' }, fs.map(function (f) { return foundBtn(D, f, function () { m.close(); addFound(D, f, group, after, dirty); }); })));
      }
      order.forEach(function (cat) {
        var ts = cat[1].filter(function (t) { return D.types[t] && (!qq || (D.types[t][0] + ' ' + t).toLowerCase().indexOf(qq) >= 0); });
        if (!ts.length) return;
        grid.appendChild(h('div', { class: 'tp-h' }, cat[0]));
        grid.appendChild(h('div', { class: 'tpick' }, ts.map(function (t) {
          return h('button', { type: 'button', title: D.types[t][0], onclick: function () { m.close(); pickType(t); } }, img(iconUrl(appIcon(t)), '', 'web'), h('span', { class: 'ell' }, D.types[t][0].replace(/ \(.*\)$/, '')));
        })));
      });
      if (!grid.childNodes.length) grid.appendChild(h('div', { class: 'empty' }, 'No app type matches. Custom API (JSON) can read most apps, Link only just opens a page.'));
    }
    function pickType(t) {
      var g = group || D.cfg.groups[0];
      if (!g) { g = { id: rid(), name: 'Apps', columns: 4, header: true, services: [] }; D.cfg.groups.push(g); }
      editService(D, { id: rid(), name: D.types[t][0].replace(/ \(.*\)$/, ''), type: t, icon: appIcon(t), href: '', url: '', description: '', hidden: false, favorite: false, span: 1, fields: [], options: {} }, g, true, after, dirty);
    }
    paint();
    m = modal('Add an app', h('div', null, h('div', { style: { 'margin-bottom': '10px' } }, find), grid), [btn('Cancel', function () { m.close(); })], true);
  }

  function editService(D, orig, group, isNew, after, dirtyOuter) {
    var s = JSON.parse(JSON.stringify(orig)), c0 = D.creds[s.id] || {};
    var cr = { key: c0.key || '', username: c0.username || '', password: c0.password || '' }, stored = { key: c0.key === D.keep, password: c0.password === D.keep, username: false };
    if (!s.options || Array.isArray(s.options)) s.options = {};
    var gid = group.id, form = h('div', { class: 'ae-form' }), noop = function () {};
    var draw = function () {
      clear(form);
      var T = D.types[s.type] || ['', [], false], z = appZone(D, s), port = APP_PORT[s.type] || 80;
      var typeSel = h('select', { onchange: function (e) { var was = s.type; s.type = e.target.value; s.fields = []; s.options = {}; if (!s.icon || s.icon === appIcon(was)) s.icon = appIcon(s.type); draw(); } },
        Object.keys(D.types).map(function (k) { return h('option', { value: k, selected: k === s.type }, D.types[k][0]); }));
      var icoPrev = h('span', { class: 'ae-ico' }, img(iconUrl(s.icon), '', 'web'));
      var icoBtn = btn('Browse', function () { iconPicker(s.name || T[0].replace(/ \(.*\)$/, ''), function (v) { s.icon = v; draw(); }); }, 'sm', 'search', 'Search icons for ' + (s.name || 'this app'));
      form.appendChild(h('div', { class: 'grid2' }, F('Name', tIn(s, 'name', noop, T[0])), F('Type', typeSel, 'Shows in ' + (z === 'apps' ? 'Apps' : ZONE_NAME[z]) + '.')));
      if (T[2]) form.appendChild(F('Address', tIn(s, 'url', noop, 'http://192.168.1.10:' + port), 'Where UnDash reaches ' + T[0].replace(/ \(.*\)$/, '') + ', from the server: the IP or host name and the port, e.g. http://192.168.1.10:' + port + '.'));
      if ((T[1] || []).length) {
        var creds = h('div', { class: 'grid2' });
        T[1].forEach(function (k) {
          var inp = h('input', { type: k === 'username' ? 'text' : 'password', autocomplete: k === 'username' ? 'off' : 'new-password', value: stored[k] ? '' : cr[k], spellcheck: 'false',
            placeholder: stored[k] ? 'Saved. Type to replace it' : (k === 'key' ? 'Paste the ' + credLabel(s.type, k).replace(/^[A-Z][a-z]/, function (x) { return x.toLowerCase(); }) : ''),
            oninput: function (e) { cr[k] = e.target.value === '' && stored[k] ? D.keep : e.target.value; } });
          var ref = c0[k + '_ref'];
          creds.appendChild(F(credLabel(s.type, k), h('div', { class: 'sec-in' }, inp, stored[k] ? btn('Remove', function () { cr[k] = ''; stored[k] = false; draw(); }, 'sm', null, 'Remove the saved ' + credLabel(s.type, k).toLowerCase()) : null),
            ref ? 'Kept in .env as ' + ref + '.' : (k === 'key' ? (KEY_WHERE[s.type] ? 'In ' + KEY_WHERE[s.type] + '. ' : '') + 'Kept in .env on the server.' : null)));
        });
        form.appendChild(h('div', { class: 'ae-sub' }, 'Login'));
        form.appendChild(creds);
      }
      form.appendChild(h('div', { class: 'ae-sub' }, 'On the dashboard'));
      form.appendChild(h('div', { class: 'grid2' },
        F('Link (opens on click)', tIn(s, 'href', noop, T[2] ? 'Same as the address' : 'https://…'), T[2] ? 'Leave empty to open the address. Use it for a public or HTTPS address.' : null),
        F('Icon', h('div', { class: 'sec-in' }, icoPrev, tIn(s, 'icon', function () { clear(icoPrev).appendChild(img(iconUrl(s.icon), '', 'web')); }, 'sh-radarr'), icoBtn), 'Browse to search, or type sh-NAME (selfh.st icons), NAME.png (dashboard-icons) or an image URL.'),
        z === 'apps' && D.cfg.groups.length > 1 ? F('Group', h('select', { onchange: function (e) { gid = e.target.value; } }, D.cfg.groups.map(function (g) { return h('option', { value: g.id, selected: g.id === gid }, g.name); }))) : null,
        z === 'apps' ? F('Description', tIn(s, 'description', noop, 'Optional')) : null,
        z === 'apps' ? F('Card width', sIn(s, 'span', noop, [[1, 'Normal'], [2, 'Double']])) : null));
      form.appendChild(h('div', { class: 'inl' }, cIn(s, 'favorite', noop, 'Favorite'), cIn(s, 'hidden', noop, 'Hidden')));
      var adv = h('div');
      if (FIELDS[s.type]) adv.appendChild(F('Numbers to show', h('input', { type: 'text', value: (s.fields || []).join(', '), placeholder: 'all', oninput: function (e) { s.fields = e.target.value.split(/[\s,]+/).filter(Boolean); } }), 'Available: ' + FIELDS[s.type] + '. Empty shows all.'));
      if (s.type === 'calendar') {
        s.options.integrations = s.options.integrations || [];
        var cbox = h('div');
        D.cfg.groups.forEach(function (g) { g.services.forEach(function (x) {
          if (x.type !== 'radarr' && x.type !== 'sonarr') return;
          var on = s.options.integrations.filter(function (i) { return i.service === x.id; })[0];
          cbox.appendChild(h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!on, onchange: function (e) {
            s.options.integrations = s.options.integrations.filter(function (i) { return i.service !== x.id; });
            if (e.target.checked) s.options.integrations.push({ service: x.id, unmonitored: true });
          } }), x.name));
        }); });
        if (!cbox.childNodes.length) cbox.appendChild(h('div', { class: 'muted sm' }, 'Add Radarr or Sonarr first.'));
        form.appendChild(F('Sources', cbox));
        form.appendChild(h('div', { class: 'grid2' }, F('Max events', tIn(s.options, 'max_events', noop, '8')), F('Days ahead', tIn(s.options, 'days_ahead', noop, '30')), F('Days back', tIn(s.options, 'previous_days', noop, '0'))));
      }
      if (s.type === 'tautulli_recent') adv.appendChild(h('div', { class: 'grid2' }, F('Media type', tIn(s.options, 'media_type', noop, 'movie, episode, track…')), F('Library section id', tIn(s.options, 'section_id', noop, 'optional')), F('Count', tIn(s.options, 'count', noop, '6'))));
      if (s.type === 'ups') form.appendChild(F('UPS name', tIn(s.options, 'ups', noop, D.cfg.general.ups_name)));
      if (s.type === 'weather') form.appendChild(h('div', { class: 'grid2' }, F('Place', tIn(s.options, 'location', noop, 'Amsterdam'), 'A town or city, or latitude, longitude. From Open-Meteo, no key needed.'),
        F('Units', sIn(s.options, 'units', noop, [['metric', '°C, km/h'], ['imperial', '°F, mph']]))));
      var lines = function (k, ph, lab, hint) { return F(lab, h('textarea', { rows: 5, spellcheck: 'false', placeholder: ph, oninput: function (e) { s.options[k] = e.target.value; } }, s.options[k] || ''), hint); };
      if (s.type === 'releases') {
        form.appendChild(lines('repos', 'immich-app/immich\nautobrr/qui', 'Repositories', 'One per line: owner/repo or its GitHub address, up to 20. Each is checked at most once an hour.'));
        form.appendChild(h('div', { style: { 'margin-top': '-4px', 'margin-bottom': '10px' } }, btn('Add from my containers', function () {
          followPicker(s.options.repos || '', function (txt) { s.options.repos = txt; draw(); });
        }, 'sm', 'box')));
      }
      if (s.type === 'bookmarks') form.appendChild(lines('links', 'Router | http://192.168.1.1\nhttps://selfh.st', 'Links', 'One per line: Title | https://… or just the address. Only http and https links are kept.'));
      if (s.type === 'uptimekuma') form.appendChild(F('Status page slug', tIn(s.options, 'slug', noop, 'home'), 'Uptime Kuma reads a status page: the end of its address, /status/NAME. Make one under Status Pages first.'));
      if (s.type === 'homeassistant') form.appendChild(F('Entities', h('textarea', { rows: 3, spellcheck: 'false', placeholder: 'sensor.living_room_temperature\nlight.kitchen', oninput: function (e) { s.options.entities = e.target.value; } }, s.options.entities || ''),
        'Entity ids, one per line, up to 8 (Developer tools > States lists them). Empty shows people home, lights on and switches on.'));
      if (s.type === 'emby' || s.type === 'jellyfin') adv.appendChild(cIn(s.options, 'hide_blocks', noop, 'Only show the streams (hide the library counts)'));
      if (s.type === 'customapi') {
        var js = function (k, def, lab) { var t = h('textarea', { rows: 6, spellcheck: 'false', 'aria-label': lab, oninput: function (e) { try { s.options[k] = JSON.parse(e.target.value || def); e.target.style.borderColor = ''; } catch (x) { e.target.style.borderColor = 'var(--bad)'; } } }); t.value = JSON.stringify(s.options[k] || JSON.parse(def), null, 2); return t; };
        form.appendChild(h('div', { class: 'grid2' }, F('Display', sIn(s.options, 'display', noop, [['block', 'Numbers (blocks)'], ['items', 'A row for each item of a list'], ['list', 'Fixed rows']], draw)), F('Method', tIn(s.options, 'method', noop, 'GET'))));
        form.appendChild(F('Headers (JSON)', js('headers', '{}', 'Headers'), '{{KEY}} is replaced by the API key above, e.g. {"X-Api-Key": "{{KEY}}"}.'));
        if (s.options.display === 'items') {
          form.appendChild(h('div', { class: 'grid2' },
            F('List in the answer', tIn(s.options, 'items', noop, 'data.results'), 'Where the list is, as a path. Empty: the answer itself is the list.'),
            F('Rows', tIn(s.options, 'limit', noop, '10', 'number', { min: 1, max: 50 })),
            F('Title', tIn(s.options, 'title', noop, '{name}')), F('Second line', tIn(s.options, 'sub', noop, '{status} · {size|bytes}')),
            F('Right side', tIn(s.options, 'right', noop, '{progress|percent}'), 'Not shown when there is a date.'), F('Date field', tIn(s.options, 'date', noop, 'added'), 'Shown as "2 h ago". ISO dates, seconds or milliseconds.'),
            F('Link', tIn(s.options, 'link', noop, 'https://example.com/item/{id}'), 'Optional; opens when the row is clicked.')));
          form.appendChild(h('div', { class: 'hint' }, 'In the boxes, {field} is replaced by that field of each item (a path like {user.name} works). Add a format after a bar: {size|bytes}, {speed|rate}, {done|percent}, {count|number}, {value|round}, {when|date}, {state|upper}.'));
        } else form.appendChild(F('Mappings (JSON)', js('mappings', '[]', 'Mappings'), '[{"field": "data.users", "label": "Users", "format": "number"}] or a template: {"template": "{data.up} / {data.total}", "label": "Up"}. Formats: number, text, bytes, rate, percent; fixed rows can add "additional": {"field": "...", "format": "relativeDate", "scale": 1000}.'));
      }
      if (adv.childNodes.length) form.appendChild(h('details', { class: 'ae-adv' }, h('summary', null, 'More options'), adv));
      var res = h('div', { role: 'status' });
      if (T[2] || /^(calendar|customapi|ups|weather|releases|bookmarks)$/.test(s.type)) form.appendChild(h('div', { class: 'inl', style: { 'margin-top': '6px' } }, btn('Test', function () {
        clear(res).appendChild(h('div', { class: 'progress' }, h('i')));
        var cfg = JSON.parse(JSON.stringify(D.cfg)), creds = JSON.parse(JSON.stringify(D.creds));
        var gg = cfg.groups.filter(function (x) { return x.id === group.id; })[0] || cfg.groups[0];
        var k = gg.services.map(function (x) { return x.id; }).indexOf(s.id); if (k < 0) gg.services.push(s); else gg.services[k] = s;
        creds[s.id] = cr;
        api('test', { config: cfg, creds: creds, shared: sharedOut(D), id: s.id }).then(function (r) {
          clear(res);
          if (r.error) res.appendChild(h('div', { class: 'banner bad' }, r.error));
          else res.appendChild(h('div', { class: 'banner ok' }, 'It works: ', r.blocks ? r.blocks.map(function (x) { return x.label + ' ' + fmt(x.value, x.fmt); }).join(' · ') : r.list ? r.list.length + ' rows' + (r.list.length ? ': ' + r.list.slice(0, 3).map(function (x) { return x.title + (x.sub ? ' (' + x.sub + ')' : ''); }).join(', ') + (r.list.length > 3 ? '…' : '') : '') : r.calendar ? r.calendar.length + ' events' : r.streams ? r.streams.length + ' streams' : 'ok'));
        });
      }, '', 'check'), h('span', { class: 'muted sm' }, 'Tries it with what is filled in here; nothing is saved.')));
      form.appendChild(res);
    };
    draw();
    var m = modal(isNew ? 'Add ' + (D.types[s.type] || [''])[0].replace(/ \(.*\)$/, '') : 'Edit ' + orig.name, form, [btn('Cancel', function () { m.close(); }), btn(isNew ? 'Add app' : 'Apply', function () {
      if (!String(s.name).trim()) s.name = (D.types[s.type] || [''])[0].replace(/ \(.*\)$/, '');
      s.span = +s.span === 2 ? 2 : 1;
      var to = D.cfg.groups.filter(function (g) { return g.id === gid; })[0] || group;
      if (isNew) to.services.push(s);
      else {
        for (var k in s) orig[k] = s[k];
        if (to !== group) { group.services.splice(group.services.indexOf(orig), 1); to.services.push(orig); }
      }
      D.creds[s.id] = Object.assign({}, c0, cr); m.close(); dirtyOuter(); after();
    }, 'pri')], true);
  }

  /* values several apps share ({{NAME}}): advanced, most setups have none */
  function sharedOut(D) { return D.shared.filter(function (v) { return v.name; }).map(function (v) { return { name: v.name, value: v.value }; }); }
  function sharedValues(b, D, dirty) {
    var list = h('div', { class: 'olist' });
    var draw = function () {
      clear(list);
      D.shared.forEach(function (v, i) {
        var keep = v.value === D.keep;
        list.appendChild(h('div', { class: 'oi' },
          h('input', { type: 'text', value: v.name, placeholder: 'NAME', 'aria-label': 'Name', spellcheck: 'false', style: { flex: '1 1 150px' }, oninput: function (e) { v.name = e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'); v.secret = /KEY|TOKEN|PASS|SECRET|AUTH|COOKIE|SESSION|CREDENTIAL/.test(v.name); dirty(); } }),
          h('input', { type: v.secret ? 'password' : 'text', value: keep ? '' : v.value, placeholder: keep ? 'Saved. Type to replace it' : 'value', autocomplete: 'new-password', 'aria-label': 'Value of ' + (v.name || 'the shared value'), style: { flex: '2 1 200px' },
            oninput: function (e) { v.value = e.target.value === '' && keep ? D.keep : e.target.value; dirty(); } }),
          h('span', { class: 'badge ' + (v.used ? 'plain' : 'off') }, v.used ? 'in use' : 'unused'),
          btn('', function () { D.shared.splice(i, 1); dirty(); draw(); }, 'sm ghost danger', 'trash', 'Remove ' + (v.name || 'this value'))));
      });
      if (!D.shared.length) list.appendChild(h('div', { class: 'empty' }, 'None. You only need these to reuse one value in several apps.'));
    };
    draw();
    b.appendChild(h('details', { class: 'set adv' }, h('summary', null, h('b', null, 'Shared values'), h('span', { class: 'muted sm' }, ' · advanced' + (D.shared.length ? ', ' + D.shared.length : ''))),
      h('p', { class: 'hint' }, 'A value used by several apps, such as one server address. Type {{NAME}} in an address, link or key field to use it. Kept in .env with the keys; names with KEY, TOKEN, PASS or SECRET are hidden once saved. {{UNRAID_IP}} is built in.'),
      list, h('div', { style: { 'margin-top': '8px' } }, btn('Add shared value', function () { D.shared.push({ name: '', value: '', secret: false, used: false }); dirty(); draw(); }, 'sm', 'plus'))));
  }

  /* Docker version: where the Unraid API is and its key (the key is never shown again once saved) */
  /* Docker version: the dashboard's own password (it can be skipped at setup and added here later) */
  function dashPass(b) {
    var box = h('div', null, h('div', { class: 'progress' }, h('i')));
    b.appendChild(set('Dashboard password', 'The Docker version has its own password (the plugin uses your Unraid login).', box));
    function paint(kind) {
      clear(box);
      S.boot.auth = kind;
      if (kind === 'env') { box.appendChild(h('div', { class: 'banner' }, 'Set by UNDASH_PASSWORD in the container settings (Docker tab > UnDash > Edit). Change or remove it there.')); return; }
      var v = { current: '', password: '', password2: '' }, msg = h('div', { class: 'sm', role: 'status', style: { 'margin-top': '8px' } });
      var pw = function (k, lab, ac) { return F(lab, h('input', { type: 'password', autocomplete: ac, value: '', oninput: function (e) { v[k] = e.target.value; } })); };
      if (kind !== 'file') box.appendChild(h('div', { class: 'banner warn', style: { 'margin-bottom': '10px' } }, 'No password is set: anyone who can open this address can see and control your containers and VMs. Setting one is recommended.'));
      else box.appendChild(h('p', { class: 'muted sm' }, 'A password is set. Changing it signs out every other browser.'));
      box.appendChild(h('div', { class: 'grid2' }, kind === 'file' ? pw('current', 'Current password', 'current-password') : null,
        pw('password', kind === 'file' ? 'New password (10+ characters)' : 'Password (10+ characters)', 'new-password'), pw('password2', 'Repeat it', 'new-password')));
      var done = function (j, ok) { if (j.error) { msg.style.color = 'var(--bad)'; txt(msg, j.error); return; } toast(ok); if (R.alerts) { R.alerts.sig = null; paintAlerts(S.live || {}); } paint(j.kind); };
      box.appendChild(h('div', { class: 'inl', style: { 'margin-top': '8px' } },
        btn(kind === 'file' ? 'Change password' : 'Set password', function () {
          if (v.password.length < 10) { msg.style.color = 'var(--bad)'; txt(msg, 'Use at least 10 characters.'); return; }
          if (v.password !== v.password2) { msg.style.color = 'var(--bad)'; txt(msg, 'The passwords do not match.'); return; }
          api('password_set', v).then(function (j) { done(j, kind === 'file' ? 'Password changed' : 'Password set'); });
        }, 'pri', 'check'),
        kind === 'file' ? btn('Remove password…', function () {
          if (!v.current) { msg.style.color = 'var(--bad)'; txt(msg, 'Enter the current password first.'); return; }
          confirmBox('Go without a password?', 'Anyone who can open this address will be able to see and control your containers and VMs, without signing in. You can set a password again here at any time.', 'Remove password', true)
            .then(function (y) { if (y) api('password_remove', { current: v.current, confirm: 'yes' }).then(function (j) { done(j, 'Password removed'); }); });
        }, 'danger', 'trash') : null), msg);
    }
    api('auth_status').then(function (j) { if (j.error) { clear(box).appendChild(h('div', { class: 'banner bad' }, j.error)); return; } paint(j.kind); });
  }
  function unraidConn(b) {
    var box = h('div', null, h('div', { class: 'progress' }, h('i')));
    b.appendChild(set('Unraid connection', 'The Docker version reads the server through the official Unraid API. Create a key in Unraid under Settings > Management Access > API Keys with read access to Info, Array, Disk, Docker and VMs, plus update access to Docker and VMs for the buttons. Read access to UPS is optional: it is only needed for a UPS that Unraid\'s own UPS service runs.', box));
    api('unraid_conf').then(function (c) {
      clear(box);
      var u = { url: c.url || '', key: c.key_set ? S.KEEP || '__KEEP__' : '' };
      var res = h('div', { role: 'status', style: { 'margin-top': '10px' } });
      box.appendChild(h('div', { class: 'grid2' },
        F('API address', h('input', { type: 'url', value: u.url, placeholder: 'http://192.168.1.10/graphql', disabled: c.from_env.url, oninput: function (e) { u.url = e.target.value; } }), c.from_env.url ? 'Set by UNRAID_API_URL in the container settings.' : 'Unraid\'s address followed by /graphql.'),
        F('API key', h('input', { type: 'password', autocomplete: 'off', value: '', placeholder: c.key_set ? 'stored (leave empty to keep)' : 'paste the key', disabled: c.from_env.key, oninput: function (e) { u.key = e.target.value || (c.key_set ? '__KEEP__' : ''); } }), c.from_env.key ? 'Set by UNRAID_API_KEY in the container settings.' : 'Kept on the server only.')));
      box.appendChild(h('div', { class: 'inl', style: { 'margin-top': '8px' } },
        c.from_env.url && c.from_env.key ? null : btn('Save', function () { act('unraid_save', u, 'Saved').then(function (j) { if (!j.error) test(); }); }, 'pri'),
        btn('Test connection', test, '', 'check')));
      box.appendChild(res);
      function test() {
        clear(res).appendChild(h('div', { class: 'progress' }, h('i')));
        api('unraid_test', {}, 60000).then(function (t) {
          clear(res);
          if (!t.ok) { res.appendChild(h('div', { class: 'banner bad' }, t.error || 'The connection failed.')); return; }
          res.appendChild(h('div', { class: 'banner ok' }, 'Connected to ' + t.hostname + ' (Unraid ' + t.unraid + ', API ' + t.api + ').'));
          res.appendChild(h('div', { class: 'kv2 sm' }, Object.keys(t.access).map(function (k) { var y = t.access[k] === 'ok', opt = !y && (t.optional || {})[k]; return [h('span', null, k), h('span', { class: y ? 't-ok' : opt ? 'muted' : 't-bad', title: opt ? t.access[k] : null }, y ? 'readable' : opt || t.access[k])]; })));
        });
      }
    });
  }
  function locPort(b, D) {
    var L = Object.assign({}, D.loc);
    var dir = h('input', { type: 'text', value: L.CONFIG_DIR, oninput: function (e) { L.CONFIG_DIR = e.target.value; } });
    var port = h('input', { type: 'number', min: 1024, max: 65535, value: L.PORT, oninput: function (e) { L.PORT = e.target.value; } });
    var bind = h('input', { type: 'text', value: L.BIND, oninput: function (e) { L.BIND = e.target.value; } });
    var on = h('input', { type: 'checkbox', checked: L.STANDALONE === 'yes', onchange: function (e) { L.STANDALONE = e.target.checked ? 'yes' : 'no'; } });
    var usingFlash = D.store !== String(L.CONFIG_DIR).replace(/\/$/, '');
    b.appendChild(set('Location and port', 'Settings are saved in the configuration directory and mirrored to the flash drive, so UnDash keeps working while the array is stopped.',
      usingFlash ? h('div', { class: 'banner' }, 'The configuration directory is not available right now (is the array started?). Settings are read from and saved to ' + D.flash + ' until it is.') : h('div', { class: 'banner ok' }, 'Using ' + D.store + ' (mirrored to ' + D.flash + ').'),
      h('div', { class: 'grid2' },
        F('Configuration directory', dir, 'Default /mnt/user/appdata/undash. Existing files are copied in the first time; nothing is overwritten.'),
        F('Standalone port', port, 'Default 9099: UnDash on its own page at http://SERVER:PORT, for browsers signed in to Unraid.'),
        F('Listen address', bind, '0.0.0.0 = every interface, or one address such as your LAN IP.')),
      h('label', { class: 'switch' }, on, 'Serve UnDash on the standalone port'),
      h('div', { style: { 'margin-top': '8px' } }, btn('Apply location and port', function () {
        act('loc_save', { dir: L.CONFIG_DIR, port: L.PORT, bind: L.BIND, standalone: L.STANDALONE }, 'Saved. The standalone port restarts.').then(function (j) { if (!j.error) { D.loc = j.loc; D.store = j.store; S.boot.port = +j.loc.PORT; S.boot.port_enabled = j.loc.STANDALONE === 'yes'; D.render(); } });
      }, 'pri'))));
  }
  function configCommon(b, D) {
    var dl = function (kind) {
      api('export', { kind: kind }).then(function (j) {
        if (j.error) { toast(j.error, true); return; }
        var a = h('a', { href: URL.createObjectURL(new Blob([j.text], { type: 'text/plain' })), download: j.name });
        root.appendChild(a); a.click(); root.removeChild(a);
      });
    };
    b.appendChild(set('Export', 'UnDash keeps two files in its configuration folder: config.yaml with everything you set here, and .env with the keys and passwords.',
      h('div', { class: 'exp' },
        h('div', { class: 'exp-row' }, h('div', { class: 't' }, h('b', null, 'config.yaml'), h('div', { class: 'muted sm' }, 'Apps, layout, theme and preferences. No keys, passwords or logins in it, so it is safe to share.')),
          btn('Download', function () { dl('config'); }, '', 'download', 'Download config.yaml')),
        h('div', { class: 'exp-row' }, h('div', { class: 't' }, h('b', null, '.env'), h('div', { class: 'muted sm' }, 'API keys, user names and passwords, and the Unraid API key. Keep it private.')),
          btn('Download', function () {
            confirmBox('Download your keys and passwords?', 'The file has every API key and password in plain text. Keep it somewhere private.', 'Download').then(function (y) { if (y) dl('env'); });
          }, '', 'download', 'Download .env')))));
    var info = h('div', { style: { 'margin-top': '10px' } });
    var file = h('input', { type: 'file', 'aria-label': 'Settings file to import', onchange: function () {
      var f = file.files[0]; clear(info); if (!f) return;
      if (f.size > 2097152) { info.appendChild(h('div', { class: 'banner bad' }, 'That file is larger than 2 MB, so it is not an UnDash settings file.')); return; }
      var rd = new FileReader();
      rd.onload = function () {
        var raw = String(rd.result);
        api('import_check', { file: raw }).then(function (j) {
          clear(info);
          if (j.error) { info.appendChild(h('div', { class: 'banner bad' }, j.error)); return; }
          var s = j.summary, env = s.kind === 'env';
          info.appendChild(env ? h('div', { class: 'kv2 sm' }, h('span', null, 'File'), h('span', null, '.env (keys and passwords)'), h('span', null, 'Values'), h('span', null, s.values + (s.names.length ? ': ' + s.names.join(', ') : '')))
            : h('div', { class: 'kv2 sm' },
              h('span', null, 'File'), h('span', null, s.legacy ? 'Settings export of an older UnDash (converted)' : 'config.yaml'),
              h('span', null, 'Apps'), h('span', null, s.widgets + ' in ' + s.groups + ' group' + (s.groups === 1 ? '' : 's') + (s.skipped ? ' (' + s.skipped + ' of an unknown type skipped)' : '')),
              h('span', null, 'Look'), h('span', null, s.theme + ' theme, refresh every ' + s.refresh + ' s'),
              h('span', null, 'Custom CSS'), h('span', null, s.css === null ? 'not in the file (yours is kept)' : s.css + ' characters'),
              h('span', null, 'Keys and passwords'), h('span', null, s.values ? s.values + ' in the file, added to .env' : 'none in the file; the ones you saved are kept')));
          info.appendChild(h('div', { style: { 'margin-top': '10px' } }, btn(env ? 'Add these values' : 'Replace my settings', function () {
            confirmBox(env ? 'Add these keys and passwords?' : 'Replace your settings?', env ? 'They are added to .env; a value with the same name replaces the saved one. A backup is taken first.'
              : 'Apps, layout and look are replaced by the file. Your saved keys and passwords stay. A backup is taken first, so you can go back below.', env ? 'Add' : 'Replace', !env).then(function (y) {
              if (y) act('import_file', { file: raw }, 'Imported').then(function (k) { if (!k.error) location.reload(); });
            });
          }, 'pri')));
        });
      };
      rd.readAsText(f);
    } });
    b.appendChild(set('Import', 'Choose a config.yaml or .env exported from UnDash (exports of older versions work too). You see what is in it before anything changes.', file, info));
    hpImport(b, D);
    var vbox = h('div', { role: 'status' });
    var bbox = h('div', { class: 'olist' }, h('div', { class: 'empty' }, 'Loading…'));
    b.appendChild(set('Check configuration', 'Looks for apps without an address, unknown {{NAMES}} and calendar sources that were removed.',
      btn('Check', function () {
        clear(vbox).appendChild(h('div', { class: 'progress' }, h('i')));
        api('validate').then(function (j) { clear(vbox); if (j.error) { vbox.appendChild(h('div', { class: 'banner bad' }, j.error)); return; } if (!j.issues.length) vbox.appendChild(h('div', { class: 'banner ok', style: { 'margin-top': '10px' } }, 'No problems found.')); j.issues.forEach(function (x) { vbox.appendChild(h('div', { class: 'banner ' + (x.level === 'bad' ? 'bad' : ''), style: { 'margin-top': '8px' } }, x.msg)); }); });
      }, '', 'check'), vbox));
    b.appendChild(set('Backups', 'Every time you save settings, both files are backed up automatically (and before an import, restore or reset). The last 7 different versions are kept in data/backups of the configuration folder.', bbox,
      h('div', { style: { 'margin-top': '10px' } }, btn('Reset to defaults…', function () {
        confirmBox('Reset the dashboard?', 'Apps, sections and preferences go back to the defaults. Your keys and passwords stay in .env, and a backup is taken first.', 'Reset', true).then(function (y) { if (y) act('reset', {}, 'Reset').then(function (j) { if (!j.error) location.reload(); }); });
      }, 'danger', 'trash'))));
    api('backups').then(function (j) {
      clear(bbox);
      if (!j.backups || !j.backups.length) { bbox.appendChild(h('div', { class: 'empty' }, 'No backups yet.')); return; }
      j.backups.forEach(function (x) {
        var when = new Date(x.created * 1000).toLocaleString();
        bbox.appendChild(h('div', { class: 'oi' }, h('span', { class: 't' }, h('b', null, when), h('span', { class: 'muted sm' }, ' · ' + (x.reason || ''))),
          btn('Restore', function () { confirmBox('Restore this backup?', 'config.yaml and .env go back to how they were on ' + when + '. The current state is backed up first.', 'Restore', true).then(function (y) { if (y) act('restore', { id: x.id }, 'Restored').then(function (k) { if (!k.error) location.reload(); }); }); }, 'sm', null, 'Restore the backup from ' + when)));
      });
    });
  }
  /* the one place that talks about Homepage: its config files are converted into UnDash's own */
  function hpImport(b, D) {
    var ta = function (ph, lab) { return h('textarea', { rows: 6, placeholder: ph, spellcheck: 'false', 'aria-label': lab }); };
    var ySvc = ta('Paste services.yaml here', 'services.yaml'), ySet = ta('Optional: settings.yaml (columns and group titles)', 'settings.yaml'), yEnv = ta('Optional: the .env with your HOMEPAGE_VAR_… values', '.env values');
    var prev = h('div', { style: { 'margin-top': '10px' } });
    var data = function () { return { services: ySvc.value, settings: ySet.value, env: yEnv.value }; };
    b.appendChild(h('details', { class: 'set adv' }, h('summary', null, h('b', null, 'Coming from Homepage?')),
      h('p', { class: 'hint' }, 'You can import your Homepage dashboard configs too. Paste services.yaml (and optionally settings.yaml and .env): UnDash turns the groups, apps, icons and links into its own settings, and moves the keys into .env. Only your apps are replaced; a backup is taken first.'),
      h('div', { class: 'grid2' }, ySvc, h('div', null, ySet, h('div', { style: { height: '8px' } }), yEnv)),
      h('div', { style: { 'margin-top': '8px' } }, btn('Preview', function () {
        if (D.dirty) { toast('Save or discard your other changes first.', true); return; }
        api('import_preview', data()).then(function (j) {
          clear(prev);
          if (j.error) { prev.appendChild(h('div', { class: 'banner bad' }, j.error)); return; }
          var missing = j.vars.filter(function (v) { return !v.set && !v.stored; });
          prev.appendChild(h('div', { class: 'banner ok' }, j.groups + ' groups and ' + j.services + ' apps found.'));
          j.warnings.forEach(function (w) { prev.appendChild(h('div', { class: 'banner' }, w)); });
          if (missing.length) prev.appendChild(h('div', { class: 'banner' }, missing.length + ' value' + (missing.length > 1 ? 's are' : ' is') + ' not in the pasted .env: ' + missing.map(function (v) { return v.name; }).join(', ') + '. Paste the .env too, or fill in those addresses and keys in Settings > Sections & apps afterwards.'));
          prev.appendChild(h('div', { class: 'kv2 sm' }, j.layout.map(function (g) { return [h('span', null, g.name), h('span', null, g.services.join(', '))]; })));
          prev.appendChild(h('div', { style: { 'margin-top': '10px' } }, btn('Import and replace my apps', function () {
            confirmBox('Replace your apps?', 'Your current apps and groups are replaced. Theme, sections, container and VM preferences and saved keys stay. A backup is taken first.', 'Import', true).then(function (y) {
              if (y) act('import_apply', data(), 'Imported').then(function (k) { if (!k.error) { D.close(true); reload().then(function () { openSettings('services'); }); } });
            });
          }, 'pri')));
        });
      }, '', 'check')), prev));
  }

  function reload() {
    return api('boot').then(function (j) { if (j.error) { toast(j.error, true); return; } S.boot = j; S.L = j.layout; S.svc = {}; shell(); refreshNow(); });
  }

  /* ---------- keyboard ---------- */
  // g then a letter jumps to a section (it opens a collapsed one); ? lists everything
  var GOTO = { o: 'overview', a: 'alerts', f: 'favorites', m: 'media', s: 'arr', d: 'downloads', n: 'playing', q: 'requests', p: 'apps', c: 'docker', v: 'vms', h: 'health' };
  function jumpTo(id) {
    var r = R[id]; if (!r) { toast((TITLES[id] || id) + ' is hidden (Settings > Sections & apps).', true); return; }
    if (r.pop) { alertPop(); return; }
    if (R.cards) boardReveal(id);
    if (r.sec.collapsed) r.el.querySelector('.sec-h h2 button').click();
    r.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    var b = r.el.querySelector('.sec-h h2 button'); if (b) try { b.focus({ preventScroll: true }); } catch (x) { b.focus(); }
  }
  function refreshAll() {
    refreshNow();
    if (R.media) loadMedia(true); if (R.arr) loadArr(true); if (R.downloads) loadDl(true);
    Object.keys(POLL).forEach(function (k) { pollKick(k); });
    toast('Refreshing everything');
  }
  function keysHelp() {
    var row = function (k, what) { return h('div', { class: 'kh' }, h('span', null, k.split(' ').map(function (x) { return h('kbd', null, x); })), h('span', null, what)); };
    var go = Object.keys(GOTO).map(function (k) { return row('g ' + k, TITLES[GOTO[k]]); });
    modal('Keyboard shortcuts', h('div', { class: 'keys' },
      h('div', null, h('h4', { class: 'ign-h' }, 'Anywhere'), row('/', 'Search'), row('Ctrl K', 'Search (⌘ K on a Mac), also while typing'), row('r', 'Refresh everything now'), row('p', 'Pause or resume live refresh'),
        row(',', 'Settings'), row('?', 'This list'), row('Esc', 'Close a dialog, menu or the filter')),
      h('div', null, h('h4', { class: 'ign-h' }, 'Go to a section'), go)), null, true);
  }
  document.addEventListener('keydown', function (e) {
    // Ctrl+K / Cmd+K: the search, which also starts, stops and opens containers, VMs and apps (works while typing too)
    if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key || '').toLowerCase() === 'k' && R.search && S.L && !root.querySelector('.modal-bg,.drawer')) {
      e.preventDefault(); R.search.focus(); R.search.select(); return;
    }
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '') || (document.activeElement && document.activeElement.isContentEditable);
    if (typing || e.ctrlKey || e.metaKey || e.altKey || !S.L || root.querySelector('.modal-bg,.drawer') || S.menu) { S.gKey = 0; return; }
    var k = e.key;
    if (S.gKey && Date.now() - S.gKey < 1500) {
      S.gKey = 0;
      if (GOTO[k.toLowerCase()]) { e.preventDefault(); jumpTo(GOTO[k.toLowerCase()]); }
      return;
    }
    if (k === '/' && R.search) { e.preventDefault(); R.search.focus(); }
    else if (k === 'g') { S.gKey = Date.now(); }
    else if (k === '?') { e.preventDefault(); keysHelp(); }
    else if (k === 'r') { e.preventDefault(); refreshAll(); }
    else if (k === 'p') { e.preventDefault(); togglePause(); toast(S.paused ? 'Live refresh paused' : 'Live refresh resumed'); }
    else if (k === ',') { e.preventDefault(); openSettings(); }
  });
  window.addEventListener('beforeunload', function (e) { if (S.drawer && S.drawer.dirty) { e.preventDefault(); e.returnValue = ''; } });

  /* ---------- start ---------- */
  S.full = LS.get('full', false);
  S.paused = LS.get('paused', false);
  api('boot').then(function (j) {
    if (j.error) { clear(root).appendChild(h('div', { class: 'und-loading' }, 'UnDash could not start: ' + j.error)); return; }
    S.boot = j; S.L = j.layout;
    shell();
    if (S.paused) api('live', { parts: parts() }).then(function (r) { if (!r.error) { S.live = r; S.lastOk = Date.now(); S.conn = 'ok'; paintAll(); paintLive(); } });
    else tick();
    if (location.hash === '#settings') openSettings();
  });
})();
