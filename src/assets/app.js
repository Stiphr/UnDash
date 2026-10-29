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
    up: 'M12 19V5M5 12l7-7 7 7', web: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
    moon: 'M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z', check: 'M5 12l4 4 10-10', power: 'M12 3v8M6.3 6.3a8 8 0 1 0 11.4 0',
    full: 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5', screen: 'M3 4h18v12H3zM8 20h8M12 16v4', edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
    trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13', upar: 'M12 6l-6 6h12z', dnar: 'M12 18l6-6H6z', plus: 'M12 5v14M5 12h14', x: 'M6 6l12 12M18 6L6 18',
    gear: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
    dots: 'M5 12h.01M12 12h.01M19 12h.01', star: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z',
    eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z', eyeoff: 'M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.1 3.9M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.6 9.6 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2',
    search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-4-4', list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z', chev: 'M6 9l6 6 6-6', history: 'M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 2',
    download: 'M12 4v12M6 10l6 6 6-6M4 20h16'
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
    if (f === 'bytes') return bytes(v); if (f === 'rate') return rate(v); if (f === 'ms') return num(v) + ' ms';
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
  function img(src, cls, fallback) {
    if (!src) return fallback ? h('span', { class: 'ph' }, ic(fallback)) : null;
    return h('img', { class: cls || 'ico', src: src, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', onerror: function () { this.style.visibility = 'hidden'; } });
  }
  function meterCls(p) { return p > 90 ? 'bad' : p > 75 ? 'warn' : ''; }

  var toastBox = null;
  function toast(msg, bad) {
    if (!toastBox || !toastBox.parentNode) { toastBox = h('div', { class: 'toasts', 'aria-live': 'polite' }); root.appendChild(toastBox); }
    var t = h('div', { class: 'toast' + (bad ? ' bad' : ''), role: bad ? 'alert' : 'status' }, msg); toastBox.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, bad ? 8000 : 3500);
  }

  /* ---------- API ---------- */
  function api(action, data, timeoutMs) {
    var fd = new URLSearchParams(); fd.append('action', action);   // urlencoded: multipart POSTs hang on Unraid's web server
    fd.append('csrf_token', window.UND_CSRF || '');
    for (var k in (data || {})) fd.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]);
    var ctl = window.AbortController ? new AbortController() : null;
    var to = setTimeout(function () { if (ctl) ctl.abort(); }, timeoutMs || 30000);
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': window.UND_CSRF || '' }, signal: ctl ? ctl.signal : undefined }).then(function (r) {
      clearTimeout(to);
      if (r.status === 401) return { error: 'Signed out. Reload the page to sign in again.', auth: true };
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
  function applyLook(a) {
    a = a || S.L.appearance;
    root.setAttribute('data-theme', a.theme);
    root.setAttribute('data-density', a.density);
    var c = hexRgb(a.accent), light = a.theme === 'light', L1 = lum(c);
    root.style.setProperty('--accent', a.accent);
    root.style.setProperty('--accent-btn', mix(c, [0, 0, 0], light ? 0.06 : 0.12));
    root.style.setProperty('--accent-ink', L1 > 0.45 ? '#111' : '#fff');
    root.style.setProperty('--accent-soft', 'rgba(' + c.join(',') + ',' + (light ? 0.12 : 0.16) + ')');
    // link text must stay readable on the panel colour: darken on light, lighten on dark
    root.style.setProperty('--accent-text', light ? (L1 > 0.25 ? mix(c, [0, 0, 0], 0.35) : a.accent) : (L1 < 0.2 ? mix(c, [255, 255, 255], 0.35) : mix(c, [255, 255, 255], 0.12)));
    root.classList.toggle('has-bg', !!a.background);
    root.classList.toggle('full', S.full && !window.UND_STANDALONE);
    var bg = null;
    for (var i = 0; i < root.children.length; i++) if (root.children[i].classList.contains('und-bg')) bg = root.children[i];
    if (a.background) {
      if (!bg) { bg = h('div', { class: 'und-bg' }); root.insertBefore(bg, root.firstChild); }
      bg.style.backgroundImage = 'url("' + a.background.replace(/["\\()]/g, '') + '")';
      bg.style.filter = 'blur(' + a.blur + 'px) brightness(' + a.brightness + '%)';
      bg.style.inset = '-' + (a.blur * 2) + 'px';
    } else if (bg) bg.parentNode.removeChild(bg);
  }
  /* custom CSS is nested under .und (CSS nesting), so it styles UnDash only; it is text, never markup */
  function applyCss(css) {
    var st = document.getElementById('und-custom-css');
    if (!css) { if (st) st.parentNode.removeChild(st); return; }
    if (!st) { st = document.createElement('style'); st.id = 'und-custom-css'; document.head.appendChild(st); }
    st.textContent = '.und{\n' + String(css).replace(/<\/?style/gi, '') + '\n}';
  }

  /* ================= shell ================= */
  function logo() {
    var s = document.createElementNS(SVGNS, 'svg'); s.setAttribute('viewBox', '0 0 64 64'); s.setAttribute('class', 'logo'); s.setAttribute('aria-hidden', 'true');
    [['0', '0', '64', '64', '14', 'var(--accent)', '1'], ['12', '12', '18', '24', '4', '#fff', '1'], ['34', '12', '18', '12', '4', '#fff', '.85'], ['34', '28', '18', '24', '4', '#fff', '1'], ['12', '40', '18', '12', '4', '#fff', '.85']].forEach(function (r) {
      var e = document.createElementNS(SVGNS, 'rect'); e.setAttribute('x', r[0]); e.setAttribute('y', r[1]); e.setAttribute('width', r[2]); e.setAttribute('height', r[3]); e.setAttribute('rx', r[4]); e.setAttribute('fill', r[5]); e.setAttribute('opacity', r[6]); s.appendChild(e);
    });
    return s;
  }

  var R = {};      // references to live parts of the page
  function shell() {
    closeMenu(true);
    clear(root); R = {}; toastBox = null;
    applyLook();
    applyCss(S.boot.css);
    var g = S.L.general;
    R.live = h('div', { class: 'live', role: 'status' }, h('span', { class: 'dot' }), R.liveTxt = h('span', null, 'Connecting…'),
      R.pause = btn('', togglePause, 'ghost sm', 'pause', 'Pause live refresh'));
    R.search = h('input', { type: 'search', placeholder: 'Filter containers, VMs, services…  ( / )', 'aria-label': 'Filter the dashboard', value: S.q,
      oninput: function () { S.q = R.search.value; refreshFilters(); },
      onkeydown: function (e) {
        if (e.key === 'Escape') { R.search.value = ''; S.q = ''; refreshFilters(); }
        if (e.key === 'Enter' && g.search !== 'none' && S.q.trim() && !root.querySelector('[data-match="1"]')) webSearch(S.q.trim());
      } });
    var acts = h('div', { class: 'acts' }, R.live,
      R.srvBtn = btn('', function (e) { serverMenu(e.currentTarget); }, '', 'server', 'Server actions'),
      window.UND_STANDALONE ? null : btn('', toggleFull, 'focus' + (S.full ? ' on' : ''), 'full', S.full ? 'Exit focus mode' : 'Focus mode (hide Unraid\'s menu)'),
      btn('', openSettings, '', 'gear', 'Settings'));
    R.srvBtn.setAttribute('aria-haspopup', 'menu');
    root.appendChild(h('header', { class: 'top' },
      h('div', { class: 'brand' }, logo(), h('div', { class: 'ell' }, h('h1', { class: 'und-name' }, g.title || 'UnDash'),
        R.sub = h('div', { class: 'und-sub' }, S.boot.server.name + ' · ' + S.boot.server.ip + ' · UnDash ' + S.boot.version))),
      h('div', { class: 'search' }, ic('search'), R.search), acts));
    R.sections = h('main', { class: 'sections' });
    root.appendChild(R.sections);
    S.L.dashboard.sections.forEach(function (sec) { if (sec.visible) buildSection(sec); });
    if (!R.sections.childNodes.length) R.sections.appendChild(h('div', { class: 'sec card empty' }, 'Every section is hidden. Turn some on in Settings > Dashboard.'));
    paintAll(); paintLive();
  }

  function webSearch(q) {
    var E = { duckduckgo: 'https://duckduckgo.com/?q=', google: 'https://www.google.com/search?q=', bing: 'https://www.bing.com/search?q=', startpage: 'https://www.startpage.com/do/search?q=', brave: 'https://search.brave.com/search?q=' };
    var u = E[S.L.general.search]; if (u) window.open(u + encodeURIComponent(q), '_blank', 'noopener');
  }
  function toggleFull() { S.full = !S.full; LS.set('full', S.full); shell(); }

  var TITLES = { overview: 'System', alerts: 'Alerts', favorites: 'Favorites', media: 'Media', arr: 'Arr Stack', downloads: 'Downloads', docker: 'Containers', vms: 'Virtual machines', apps: 'Apps' };
  function buildSection(sec) {
    var tools = h('div', { class: 'tools' });
    var count = h('span', { class: 'count' });
    var body = h('div', { class: 'sec-b', id: 'und-sec-' + sec.id });
    var el = h('section', { class: 'sec' + (sec.width === 'half' ? ' half' : '') + (sec.collapsed ? ' closed' : ''), 'data-sec': sec.id, 'aria-label': TITLES[sec.id] },
      h('div', { class: 'sec-h' }, h('h2', null, h('button', { type: 'button', 'aria-expanded': sec.collapsed ? 'false' : 'true', 'aria-controls': 'und-sec-' + sec.id, onclick: function (e) {
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
        S.fails = 0; S.conn = 'ok'; S.lastOk = Date.now(); S.live = r;
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
  });

  function paintAll() {
    var y = S.live || {};
    if (R.overview && open('overview')) paintOverview(y);
    if (R.alerts) paintAlerts(y);
    if (R.favorites && open('favorites')) paintFavs(y);
    if (R.docker && open('docker')) paintDocker(y);
    if (R.vms && open('vms')) paintVms(y);
    paintCounts(y);
  }
  function paintCounts(y) {
    if (R.docker && y.docker && y.docker.containers) {
      var c = y.docker.containers;
      txt(R.docker.count, c.filter(function (x) { return x.state === 'running'; }).length + ' of ' + c.length + ' running');
    }
    if (R.vms && y.vms && y.vms.vms) txt(R.vms.count, y.vms.vms.filter(function (v) { return v.state === 'running'; }).length + ' of ' + y.vms.vms.length + ' running');
  }

  /* ---------- filtering ---------- */
  function matches(str) { var q = S.q.trim().toLowerCase(); return !q || String(str).toLowerCase().indexOf(q) >= 0; }
  function refreshFilters() {
    if (R.favorites) R.favorites.sig = null;
    if (R.vms) R.vms.sig = null;
    paintAll();
    root.querySelectorAll('.w[data-name]').forEach(function (w) { var m = matches(w.getAttribute('data-name')); w.style.display = m ? '' : 'none'; w.setAttribute('data-match', m && S.q ? '1' : '0'); });
    root.querySelectorAll('.grp').forEach(function (gr) { var any = [].some.call(gr.querySelectorAll('.w[data-name]'), function (w) { return w.style.display !== 'none'; }); gr.style.display = any || !S.q ? '' : 'none'; });
    root.querySelectorAll('.rowi[data-name],.tile[data-name]').forEach(function (r) { r.setAttribute('data-match', S.q ? '1' : '0'); });
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
      var t = { id: m.id, v: h('span', { class: 'v' }, '–'), s: h('span', { class: 's' }), m: h('i') };
      t.el = h('button', { type: 'button', class: 'stat', role: 'listitem', 'aria-expanded': 'false', onclick: function () { toggleDetail(m.id); } },
        h('span', { class: 'k' }, ic(MET[m.id][1]), MET[m.id][0]), t.v, t.s, h('span', { class: 'bar', 'aria-hidden': 'true' }, t.m));
      r.tiles[m.id] = t; r.bar.appendChild(t.el);
    });
    r.detail = h('div', { class: 'detail', hidden: true });
    r.stale = h('div', { class: 'stale', hidden: true, role: 'status' });
    r.body.appendChild(r.stale); r.body.appendChild(r.bar); r.body.appendChild(r.detail);
    if (!r.bar.childNodes.length) r.bar.appendChild(h('div', { class: 'empty' }, 'No metrics are turned on (Settings > Dashboard).'));
    r.open = LS.get('detail', '');
  };
  function toggleDetail(id) {
    var r = R.overview; r.open = r.open === id ? '' : id; LS.set('detail', r.open);
    for (var k in r.tiles) r.tiles[k].el.setAttribute('aria-expanded', k === r.open ? 'true' : 'false');
    r.detailSig = null; paintDetail(S.live || {});
  }
  function tile(id, val, sub, p, lvl) {
    var t = R.overview.tiles[id]; if (!t) return;
    txt(t.v, val); txt(t.s, sub || '');
    t.m.style.width = (p === null || p === undefined ? 0 : Math.max(0, Math.min(100, p))) + '%';
    t.el.className = 'stat ' + (lvl || '') + (R.overview.open === id ? ' on' : '');
  }
  function lvl(p, w, b) { return p === null || p === undefined ? 'na' : p >= b ? 'bad' : p >= w ? 'warn' : 'ok'; }
  function paintOverview(y) {
    var r = R.overview, s = y.system;
    var stale = S.lastOk && Date.now() - S.lastOk > Math.max(10000, S.L.general.refresh * 3000);
    r.stale.hidden = !stale && S.conn === 'ok';
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
    tile('uptime', upt(s.uptime), s.os ? 'Unraid ' + s.os : '', null, '');
    var u = y.ups;
    if (R.overview.tiles.ups) { if (!u) tile('ups', 'n/a', 'No UPS found', null, 'na'); else tile('ups', pct(u.charge), u.status + (u.load !== null ? ' · load ' + pct(u.load) : '') + (u.runtime ? ' · ' + Math.round(u.runtime / 60) + ' min' : ''), u.charge, u.charge < 30 ? 'bad' : u.charge < 60 || !/online/i.test(u.status) ? 'warn' : 'ok'); }
    var cores = s.cpu.cores || [];
    tile('cores', cores.length ? pct(Math.max.apply(null, cores)) : 'n/a', cores.length ? 'busiest of ' + cores.length + ' cores' : '', cores.length ? Math.max.apply(null, cores) : null, cores.length ? lvl(Math.max.apply(null, cores), 90, 99) : 'na');
    tile('swap', s.mem.swap_total ? pct(100 * s.mem.swap_used / s.mem.swap_total) : 'None', s.mem.swap_total ? bytes(s.mem.swap_used) + ' of ' + bytes(s.mem.swap_total) : 'No swap configured', s.mem.swap_total ? 100 * s.mem.swap_used / s.mem.swap_total : null, s.mem.swap_total ? '' : 'na');
    if (s.cpu.load) tile('load', s.cpu.load[0].toFixed(2), '5 min ' + s.cpu.load[1].toFixed(2) + ' · 15 min ' + s.cpu.load[2].toFixed(2), 100 * s.cpu.load[0] / (s.cpu.threads || 1), '');
    if (s.disk) tile('path', s.disk.total ? bytes(s.disk.free) + ' free' : 'n/a', s.disk.total ? s.disk.path + ' · ' + pct(s.disk.pct) : s.disk.path + ' reports no size', s.disk.pct, s.disk.total ? lvl(s.disk.pct, 85, 95) : 'na');
    if (dk) tile('docker', dk.running + ' / ' + dk.total, 'running' + (dk.updates ? ' · ' + dk.updates + ' updates' : ''), 100 * dk.running / (dk.total || 1), dk.unhealthy ? 'bad' : '');
    if (vm) tile('vms', vm.running + ' / ' + vm.total, 'running', 100 * vm.running / (vm.total || 1), '');
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
        y.ups ? [h('span', null, 'UPS'), h('span', null, (y.ups.model || '') + ' · ' + y.ups.status + ' · ' + pct(y.ups.charge) + (y.ups.runtime ? ' · ' + Math.round(y.ups.runtime / 60) + ' min runtime' : ''))] : null));
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
      caps.os_update ? ['Unraid OS update', 'up', osDialog] : null, ['Recent operations', 'history', historyDialog],
      pl ? ['hr'][0] : null, pl ? [pl.textContent, 'web', function () { window.open(pl.href, '_blank', 'noopener'); }] : null,
      window.UND_STANDALONE ? ['Sign out', 'x', function () { location.href = '/logout'; }] : null,
      caps.power ? 'hr' : null, caps.power ? ['Restart server…', 'restart', function () { powerDialog('reboot'); }, 'danger'] : null, caps.power ? ['Shut down server…', 'power', function () { powerDialog('shutdown'); }, 'danger'] : null]);
  }

  /* ---- alerts ---- */
  BUILD.alerts = function (r) { r.list = h('div', { class: 'alerts' }); r.body.appendChild(r.list); };
  function paintAlerts(y) {
    var r = R.alerts, s = y.system, out = [];
    if (S.conn === 'down') out.push(['bad', 'The server is not answering (' + (S.connErr || 'no answer') + '). Retrying.']);
    if (S.conn === 'auth') out.push(['bad', 'Your session ended. Reload the page to sign in again.']);
    if (s && s.error) { out.push(['bad', s.error + (s.auth ? ' Check Settings > Configuration.' : ''), ['Settings', function () { openSettings('config'); }]]); s = null; }
    if (s) {
      var a = s.array || {};
      if (a.state && a.state !== 'STARTED') out.push(['warn', 'The array is ' + a.state.toLowerCase() + '. Shares, containers and VMs on it are unavailable.']);
      if (a.resync) out.push(['info', (a.resync_action || 'A parity operation') + ' is running' + (a.resync_pct !== null ? ' (' + a.resync_pct + '%)' : '') + '.']);
      if (a.bad) out.push(['bad', a.bad + ' disk' + (a.bad > 1 ? 's are' : ' is') + ' disabled, missing or emulated. Check Main in Unraid.']);
      if (a.errors) out.push(['bad', 'Disks reported ' + a.errors + ' read/write error' + (a.errors > 1 ? 's' : '') + '.']);
      if (a.hot) out.push(['warn', a.hot + ' disk' + (a.hot > 1 ? 's are' : ' is') + ' at 50°C or more.']);
      var o = s.os_update || {};
      if (o.staged) out.push(['info', 'Unraid ' + o.staged + ' is installed and becomes active after a restart.', ['Restart…', function () { powerDialog('reboot'); }]]);
      else if (o.available) out.push(['info', 'Unraid ' + o.latest + ' is available (running ' + o.installed + ').', ['Details', osDialog]]);
      if (o.error && !o.available) out.push(['warn', 'The Unraid update check failed: ' + o.error]);
    }
    var d = y.docker && y.docker.containers;
    if (d) d.forEach(function (c) {
      if (pref('docker', c.name).hidden) return;
      var nm = label('docker', c.name), lg = ['Logs', function () { logs(c.name); }];
      if (c.health === 'unhealthy') out.push(['bad', nm + ' is unhealthy.', lg]);
      else if (c.state === 'restarting') out.push(['bad', nm + ' keeps restarting' + (c.restarts ? ' (' + c.restarts + ' restarts)' : '') + '.', lg]);
      else if (c.state === 'exited' && c.exit && c.autostart) out.push(['warn', nm + ' stopped with exit code ' + c.exit + '.', lg]);
      if (c.job === 'update_failed') out.push(['bad', 'The update of ' + nm + ' failed.', lg]);
    });
    var sig = JSON.stringify([!!s, out.map(function (x) { return [x[0], x[1]]; })]);
    if (r.sig === sig) return; r.sig = sig;
    clear(r.list);
    txt(r.count, out.length ? String(out.length) : '');
    if (!out.length) { r.list.appendChild(h('div', { class: 'alert ok' }, ic('check'), h('span', { class: 'grow' }, s ? 'No problems found.' : 'Waiting for data…'))); return; }
    out.forEach(function (x) { r.list.appendChild(h('div', { class: 'alert ' + x[0] }, h('span', { class: 'grow' }, x[1]), x[2] ? btn(x[2][0], x[2][1], 'sm') : null)); });
  }

  /* ---- favorites ---- */
  BUILD.favorites = function (r) { r.list = h('div', { class: 'favs' }); r.body.appendChild(r.list); };
  function paintFavs(y) {
    var r = R.favorites, items = [];
    ((y.docker && y.docker.containers) || []).forEach(function (c) { if (pref('docker', c.name).favorite) items.push(['d', c]); });
    ((y.vms && y.vms.vms) || []).forEach(function (v) { if (pref('vms', v.name).favorite) items.push(['v', v]); });
    S.L.groups.forEach(function (g) { g.services.forEach(function (s) { if (s.favorite && !s.hidden) items.push(['s', s]); }); });
    items = items.filter(function (x) { return matches(x[1].name + ' ' + (x[0] === 's' ? '' : label(x[0] === 'd' ? 'docker' : 'vms', x[1].name))); });
    var sig = JSON.stringify(items.map(function (x) { return [x[0], x[1].name, x[1].state, x[1].health, x[1].webui, x[1].ops, S.busy[(x[0] === 'd' ? 'd:' : 'v:') + x[1].name], x[0] === 's' ? null : pref(x[0] === 'd' ? 'docker' : 'vms', x[1].name)]; }));
    if (r.sig === sig) return; r.sig = sig;
    clear(r.list);
    if (!items.length) { r.list.appendChild(h('div', { class: 'card empty', style: { 'grid-column': '1/-1' } }, S.q ? 'No favorites match the filter.' : 'Star containers, VMs or services (⋯ menu or Settings) to pin them here.')); return; }
    items.forEach(function (x) {
      var it = x[1], el;
      if (x[0] === 'd') {
        var run = it.state === 'running';
        el = h('div', { class: 'fav' }, img(iconUrl(pref('docker', it.name).icon || it.icon), '', 'box'), h('div', { class: 't' }, h('b', null, label('docker', it.name)), dStateBadge(it)),
          run && it.webui ? h('a', { class: 'btn ic sm', href: it.webui, target: '_blank', rel: 'noopener', title: 'Open WebUI', 'aria-label': 'Open WebUI of ' + it.name }, ic('web')) : null,
          btn('', function () { dAct(it, run ? 'restart' : 'start'); }, 'sm' + (run ? '' : ' okc'), run ? 'restart' : 'play', (run ? 'Restart ' : 'Start ') + it.name));
        if (S.busy['d:' + it.name]) el.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      } else if (x[0] === 'v') {
        var op = it.ops.indexOf('start') >= 0 ? 'start' : it.ops.indexOf('resume') >= 0 ? 'resume' : null;
        el = h('div', { class: 'fav' }, img(iconUrl(pref('vms', it.name).icon || it.icon), '', 'vm'), h('div', { class: 't' }, h('b', null, label('vms', it.name)), vStateBadge(it.state, S.busy['v:' + it.name])),
          op ? btn('', function () { vAct(it, op); }, 'sm okc', 'play', VMOPS[op][0] + ' ' + it.name) : null);
        if (S.busy['v:' + it.name]) el.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      } else {
        var href = it.href_resolved;
        el = h('div', { class: 'fav' }, img(iconUrl(it.icon), '', 'tag'), h('div', { class: 't' }, h('b', null, it.name), h('span', { class: 'muted xs' }, it.description || (S.boot.types[it.type] || [''])[0])),
          href ? h('a', { class: 'btn ic sm', href: href, target: '_blank', rel: 'noopener', title: 'Open ' + it.name, 'aria-label': 'Open ' + it.name }, ic('web')) : null);
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
      if (x.update === 'available' && !pref('docker', x.name).hidden) { s.updates++; s.updList.push(x.name); }
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
  function dUpdBadge(c) {
    if (c.job === 'update_failed') return h('span', { class: 'badge bad', title: 'The last update failed; see the logs' }, 'update failed');
    if (c.update === 'available') return h('span', { class: 'badge upd' }, '↑ update');
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
      var b = h('button', { type: 'button', class: S.fd === f[0] ? 'on' : '', 'aria-pressed': S.fd === f[0] ? 'true' : 'false', title: f[0] === 'selected' ? 'The containers you picked (⋯ > Show in "Selected")' : null, onclick: function () {
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
    r.body.appendChild(r.list); r.body.appendChild(r.foot);
    r.map = {};
  };
  function toggleSelected(name) {
    var D = S.L.docker, i = D.selected.indexOf(name), first = !D.selected.length;
    if (i >= 0) D.selected.splice(i, 1); else D.selected.push(name);
    api('prefs', { kind: 'docker_view', value: { selected: D.selected } }).then(function (j) { if (j.error) toast(j.error, true); });
    toast(i >= 0 ? name + ' removed from "Selected"' : name + ' added to "Selected"' + (first ? '. Pick "Selected" as the default view in Settings > Dashboard.' : ''));
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
      if (!matches(c.name + ' ' + (p.label || '') + ' ' + c.image)) return false;
      if (S.fd === 'selected') return D.selected.indexOf(c.name) >= 0;
      if (S.fd === 'running') return c.state === 'running';
      if (S.fd === 'stopped') return c.state !== 'running';
      if (S.fd === 'updates') return c.update === 'available';
      if (S.fd === 'problems') return c.health === 'unhealthy' || c.state === 'restarting' || c.job === 'update_failed' || (c.state === 'exited' && c.exit && c.autostart);
      if (S.fd === 'favorites') return !!p.favorite;
      if (D.hide_stopped && c.state !== 'running' && !S.q) { hiddenN++; return false; }
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
      var sig = JSON.stringify([c.state, c.health, c.update, c.job, c.webui, c.managed, S.busy['d:' + c.name] || '', p, c.icon, c.image, D.layout, D.view, D.show, D.selected.indexOf(c.name) >= 0]);
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
    if (!list.length) r.rows.appendChild(h('div', { class: 'empty' }, S.q ? 'No containers match "' + S.q + '".' : S.fd === 'selected' ? 'None of your selected containers exist any more.' : d.containers.length ? 'No containers in this view.' : 'No containers yet.'));
    var dk = dockerStats(y), fs = JSON.stringify([hiddenN, S.showHidden, dk.updates, S.fd, list.length, d.containers.length]);
    if (r.fSig !== fs) {
      r.fSig = fs; clear(r.foot); clear(r.updAll);
      if (S.fd !== 'all' && !S.q && list.length < d.containers.length) r.foot.appendChild(btn('Show all ' + d.containers.length, function () { S.fd = 'all'; S.fdSet = true; shell(); }, 'sm ghost', 'eye', 'Show every container'));
      if (hiddenN || S.showHidden) r.foot.appendChild(btn(S.showHidden ? 'Hide hidden containers' : 'Show ' + hiddenN + ' hidden', function () { S.showHidden = !S.showHidden; r.fSig = null; if (R.vms) R.vms.sig = null; paintAll(); }, 'sm ghost', S.showHidden ? 'eyeoff' : 'eye'));
      if (dk.updates) r.updAll.appendChild(btn('Update all (' + dk.updates + ')', function () { updateMany(dockerStats(S.live).updList); }, 'sm pri', 'up', 'Update every container that has an update'));
    }
  }
  function dActions(c, p, inTile) {
    var run = c.state === 'running', busy = !!S.busy['d:' + c.name] || c.job === 'updating';
    var a = h('div', { class: 'acts c-acts' });
    if (run && c.webui) a.appendChild(h('a', { class: 'btn ic sm', href: c.webui, target: '_blank', rel: 'noopener', title: 'Open WebUI', 'aria-label': 'Open WebUI of ' + c.name }, ic('web')));
    if (c.state === 'paused') a.appendChild(btn('', function () { dAct(c, 'unpause'); }, 'sm okc', 'play', 'Resume ' + c.name));
    else if (run || c.state === 'restarting') a.appendChild(btn('', function () { dAct(c, 'stop'); }, 'sm danger', 'stop', 'Stop ' + c.name));
    else a.appendChild(btn('', function () { dAct(c, 'start'); }, 'sm okc', 'play', 'Start ' + c.name));
    if (c.state !== 'paused') a.appendChild(btn('', function () { dAct(c, 'restart'); }, 'sm', 'restart', 'Restart ' + c.name));
    if (c.update === 'available' && inTile) a.appendChild(btn('Update', function () { updateMany([c.name]); }, 'sm pri', 'up'));
    var more = btn('', function () {
      menu(more, [
        run ? ['Pause', 'pause', function () { dAct(c, 'pause'); }, '', busy] : null,
        ['Update' + (c.update === 'available' ? '' : ' (force)'), 'up', function () { updateMany([c.name]); }, '', busy],
        'hr', ['Logs', 'logs', function () { logs(c.name); }], ['Details', 'info', function () { inspect(c.name); }], 'hr',
        [p.favorite ? 'Remove from favorites' : 'Add to favorites', 'star', function () { savePref('docker', c.name, { favorite: !p.favorite }); }],
        [S.L.docker.selected.indexOf(c.name) >= 0 ? 'Remove from "Selected"' : 'Show in "Selected"', 'check', function () { toggleSelected(c.name); }],
        [p.hidden ? 'Show on dashboard' : 'Hide from dashboard', p.hidden ? 'eye' : 'eyeoff', function () { savePref('docker', c.name, { hidden: !p.hidden }); }],
        ['Customize…', 'edit', function () { customize('docker', c); }]]);
    }, 'sm', 'dots', 'More actions for ' + c.name);
    more.setAttribute('aria-haspopup', 'menu');
    a.appendChild(more);
    if (busy) [].forEach.call(a.querySelectorAll('button'), function (b) { if (b !== more) b.disabled = true; });
    return a;
  }
  function dWho(c, p) {
    return h('div', { class: 'who c-who' }, img(iconUrl(p.icon || c.icon), '', 'box'),
      h('div', { class: 'ell' }, h('div', { class: 'nm' }, h('span', { class: 'ell', title: c.name }, p.label || c.name), p.favorite ? ic('star', true) : null),
        h('div', { class: 'im ell', title: c.image }, p.description || c.image)));
  }
  function dRow(c, p) {
    var row = h('div', { class: 'rowi' + (S.busy['d:' + c.name] ? ' busy' : ''), role: 'listitem', 'data-name': c.name });
    row._cpu = h('span'); row._mem = h('span', { class: 'muted' }); row._m = h('div', { class: 'meter', 'aria-hidden': 'true' }, h('i')); row._up = h('span', { class: 'c-up num muted sm' }); row._mob = h('span', { class: 'mob muted sm num' });
    row.appendChild(dWho(c, p));
    row.appendChild(h('div', { class: 'c-st' }, dStateBadge(c), c.update === 'available' || c.job === 'update_failed' ? h('span', { class: 'mob' }, dUpdBadge(c)) : null, row._mob));
    row.appendChild(h('div', { class: 'res c-res' }, h('div', { class: 'inl', style: { gap: '6px' } }, row._cpu, row._mem), row._m));
    row.appendChild(row._up);
    row.appendChild(h('div', { class: 'c-upd' }, dUpdBadge(c)));
    row.appendChild(dActions(c, p, false));
    return row;
  }
  /* compact row: icon, name, state, CPU, memory, an update badge only when there is one, and the actions */
  function dRowC(c, p) {
    var row = h('div', { class: 'rowi c' + (S.busy['d:' + c.name] ? ' busy' : ''), role: 'listitem', 'data-name': c.name, title: c.image });
    row._cpu = h('span', { class: 'c-cpu num' }); row._mem = h('span', { class: 'c-mem num muted' });
    row.appendChild(h('div', { class: 'who c-who' }, img(iconUrl(p.icon || c.icon), '', 'box'), h('span', { class: 'nm ell' }, p.label || c.name, p.favorite ? ic('star', true) : null)));
    row.appendChild(h('div', { class: 'c-st' }, dStateBadge(c)));
    row.appendChild(row._cpu); row.appendChild(row._mem);
    row.appendChild(h('div', { class: 'c-upd' }, c.update === 'available' || c.job === 'update_failed' ? dUpdBadge(c) : null));
    row.appendChild(dActions(c, p, false));
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
    if (!sub.childNodes.length || c.state !== 'running') { clear(sub); sub.appendChild(h('span', null, st.textContent)); cell._cpu = h('span'); cell._mem = h('span'); cell._up = null; }
    cell.appendChild(img(iconUrl(p.icon || c.icon), 'cico', 'box'));
    cell.appendChild(h('div', { class: 'cn' }, h('span', { class: 'cname ell', title: c.name + ' · ' + c.image }, dot, p.label || c.name, p.favorite ? ic('star', true) : null), sub));
    if (sh('update') && (c.update === 'available' || c.job === 'update_failed')) cell.appendChild(dUpdBadge(c));
    cell.appendChild(dActions(c, p, false));
    return cell;
  }
  function dTile(c, p) {
    var t = h('div', { class: 'tile' + (S.busy['d:' + c.name] ? ' busy' : ''), role: 'listitem', 'data-name': c.name });
    t._cpu = h('span'); t._mem = h('span'); t._up = h('span'); t._m = h('div', { class: 'meter', 'aria-hidden': 'true' }, h('i'));
    t.appendChild(dWho(c, p));
    t.appendChild(h('div', { class: 'chips' }, dStateBadge(c), dUpdBadge(c)));
    t.appendChild(h('div', { class: 'stats' }, t._cpu, t._mem, t._up));
    t.appendChild(t._m);
    t.appendChild(dActions(c, p, true));
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
  /* updates run one at a time, each through Unraid's update_container; the dialog shows each one's output */
  function updateMany(names) {
    names = (names || []).filter(function (n) { return !S.busy['d:' + n]; });
    if (!names.length) return;
    var list = h('ul', null, names.slice(0, 30).map(function (n) { return h('li', null, label('docker', n)); }), names.length > 30 ? h('li', null, '… and ' + (names.length - 30) + ' more') : null);
    confirmBox(names.length === 1 ? 'Update ' + label('docker', names[0]) + '?' : 'Update ' + names.length + ' containers?',
      h('div', null, h('p', null, 'Each container is stopped, its newest image is pulled and it is recreated from its Unraid template (the same as Unraid\'s Docker tab). Its service is unavailable meanwhile. They are updated one after the other.'), list),
      names.length === 1 ? 'Update' : 'Update all ' + names.length).then(function (y) { if (y) runUpdates(names); });
  }
  function runUpdates(names) {
    var pre = h('pre', { class: 'log wrap' }), prog = h('div', { class: 'progress' }, h('i')), status = h('div', { class: 'sm', role: 'status', 'aria-live': 'polite' });
    var results = h('div', { class: 'chips', style: { margin: '8px 0' } });
    var stop = false;
    var stopB = btn('Stop after this one', function () { stop = true; stopB.disabled = true; });
    var m = modal('Updating ' + (names.length === 1 ? label('docker', names[0]) : names.length + ' containers'), h('div', null, status, prog, results, pre),
      [names.length > 1 ? stopB : null, btn('Close', function () { m.close(); })].filter(Boolean), true);
    var i = 0, out = '', ok = 0, bad = 0;
    names.forEach(function (n) { S.busy['d:' + n] = 'queued'; });
    paintAll();
    function next() {
      if (i >= names.length || stop) {
        names.slice(i).forEach(function (n) { delete S.busy['d:' + n]; });
        prog.style.display = 'none'; stopB.disabled = true;
        status.textContent = 'Finished: ' + ok + ' updated' + (bad ? ', ' + bad + ' failed' : '') + (stop && i < names.length ? ', ' + (names.length - i) + ' not started' : '') + '.';
        if (!m.parentNode) toast(status.textContent, bad > 0);
        refreshNow(); return;
      }
      var n = names[i++];
      S.busy['d:' + n] = 'updating'; paintAll();
      status.textContent = 'Updating ' + label('docker', n) + ' (' + i + ' of ' + names.length + ')…';
      api('docker_action', { name: n, op: 'update' }, 30000).then(function (j) {
        if (j.error) { delete S.busy['d:' + n]; bad++; results.appendChild(h('span', { class: 'badge bad' }, n)); out += '[' + n + '] ' + j.error + '\n\n'; pre.textContent = out; return next(); }
        (function poll() {
          api('docker_job', { name: n }).then(function (k) {
            if (!k.error) { pre.textContent = out + '[' + n + ']\n' + (k.out || ''); pre.scrollTop = pre.scrollHeight; }
            if (k.state === 'running' || k.error) { setTimeout(poll, 1500); return; }
            delete S.busy['d:' + n];
            if (k.ok) { ok++; results.appendChild(h('span', { class: 'badge ok' }, n)); } else { bad++; results.appendChild(h('span', { class: 'badge bad' }, n)); }
            out = pre.textContent + '\n'; next();
          });
        })();
      });
    }
    next();
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
    r.tools.appendChild(h('div', { class: 'seg', role: 'group', 'aria-label': 'Layout' },
      h('button', { type: 'button', class: V.layout === 'list' ? 'on' : '', title: 'List', 'aria-label': 'List layout', 'aria-pressed': V.layout === 'list' ? 'true' : 'false', onclick: function () { setLayout('vms', 'list'); } }, ic('list')),
      h('button', { type: 'button', class: V.layout === 'cards' ? 'on' : '', title: 'Cards', 'aria-label': 'Card layout', 'aria-pressed': V.layout === 'cards' ? 'true' : 'false', onclick: function () { setLayout('vms', 'cards'); } }, ic('grid'))));
    r.list = V.layout === 'cards' ? h('div', { class: 'tiles', role: 'list' }) : h('div', { class: 'panel compact' }, h('div', { class: 'rowh c', 'aria-hidden': 'true' }, h('span', null, 'Virtual machine'), h('span', null, 'Status'), h('span', { class: 'c-cpu' }, 'vCPUs'), h('span', { class: 'c-mem' }, 'Memory'), h('span', { class: 'c-upd' }, S.boot.caps.vm_details ? 'Guest agent' : ''), h('span', null, '')));
    r.rows = V.layout === 'cards' ? r.list : h('div', { role: 'list' });
    if (V.layout !== 'cards') r.list.appendChild(r.rows);
    r.body.appendChild(r.list);
  };
  var VMOPS = { start: ['Start', 'play', 'okc'], resume: ['Resume', 'play', 'okc'], stop: ['Shut down', 'stop', 'danger'], restart: ['Restart', 'restart', ''], pause: ['Pause', 'pause', ''], hibernate: ['Hibernate', 'moon', ''], kill: ['Force stop', 'power', 'danger'] };
  function paintVms(y) {
    var r = R.vms, v = y.vms;
    if (!v) { if (!r.rows.firstChild) r.rows.appendChild(h('div', { class: 'empty' }, 'Loading VMs…')); return; }
    var hidden = 0;
    var list = v.error ? [] : v.vms.filter(function (x) { var p = pref('vms', x.name); if (p.hidden && !S.showHidden) { hidden++; return false; } return matches(x.name + ' ' + (p.label || '') + ' ' + x.os); });
    var busy = {}; list.forEach(function (x) { busy[x.name] = S.busy['v:' + x.name] || ''; });
    var sig = JSON.stringify([v.error, list, hidden, S.showHidden, S.L.vms, busy, S.q]);
    if (r.sig === sig) return; r.sig = sig;
    var focus = r.rows.contains(document.activeElement) ? document.activeElement.getAttribute('aria-label') : null;
    clear(r.rows);
    if (v.error) { r.rows.appendChild(h('div', { class: 'empty' }, v.error)); return; }
    if (!list.length) r.rows.appendChild(h('div', { class: 'empty' }, S.q ? 'No VMs match "' + S.q + '".' : v.vms.length ? 'All VMs are hidden.' : 'No virtual machines.'));
    list.forEach(function (x) {
      var p = pref('vms', x.name), b = busy[x.name];
      var a = h('div', { class: 'acts c-acts' });
      if (x.state === 'running' && x.vnc) a.appendChild(h('a', { class: 'btn ic sm', href: UNRAID + '/plugins/dynamix.vm.manager/vnc.html?autoconnect=true&resize=scale&host=' + encodeURIComponent(location.hostname) + '&port=&path=' + encodeURIComponent(x.vnc), target: '_blank', rel: 'noopener', title: 'VNC console', 'aria-label': 'VNC console of ' + x.name }, ic('screen')));
      var main = x.ops.indexOf('start') >= 0 ? 'start' : x.ops.indexOf('resume') >= 0 ? 'resume' : x.ops.indexOf('stop') >= 0 ? 'stop' : null;
      if (main) a.appendChild(btn('', function () { vAct(x, main); }, 'sm ' + VMOPS[main][2], VMOPS[main][1], VMOPS[main][0] + ' ' + x.name));
      var rest = x.ops.filter(function (o) { return o !== main; });
      var more = btn('', function () {
        menu(more, rest.map(function (o) { return [VMOPS[o][0] + (o === 'kill' || o === 'hibernate' || o === 'restart' ? '…' : ''), VMOPS[o][1], function () { vAct(x, o); }, o === 'kill' ? 'danger' : '', !!b]; })
          .concat([x.state === 'running' && !x.agent ? ['Hibernate (needs the QEMU guest agent)', 'moon', function () {}, '', true] : null, 'hr',
            [p.favorite ? 'Remove from favorites' : 'Add to favorites', 'star', function () { savePref('vms', x.name, { favorite: !p.favorite }); }],
            [p.hidden ? 'Show on dashboard' : 'Hide from dashboard', p.hidden ? 'eye' : 'eyeoff', function () { savePref('vms', x.name, { hidden: !p.hidden }); }],
            ['Customize…', 'edit', function () { customize('vms', x); }]]));
      }, 'sm', 'dots', 'More actions for ' + x.name);
      more.setAttribute('aria-haspopup', 'menu');
      a.appendChild(more);
      if (b) [].forEach.call(a.querySelectorAll('button'), function (z) { if (z !== more) z.disabled = true; });
      var who = S.L.vms.layout === 'cards' ? h('div', { class: 'who c-who' }, img(iconUrl(p.icon || x.icon), '', 'vm'), h('div', { class: 'ell' }, h('div', { class: 'nm' }, h('span', { class: 'ell' }, p.label || x.name), p.favorite ? ic('star', true) : null), h('div', { class: 'im ell' }, p.description || x.os || '')))
        : h('div', { class: 'who c-who' }, img(iconUrl(p.icon || x.icon), '', 'vm'), h('span', { class: 'nm ell', title: x.os || '' }, p.label || x.name, p.favorite ? ic('star', true) : null));
      var res = x.cpus ? x.cpus + ' vCPU · ' + bytes(x.memory) : '';
      if (S.L.vms.layout === 'cards') r.rows.appendChild(h('div', { class: 'tile' + (b ? ' busy' : ''), role: 'listitem' }, who, h('div', { class: 'chips' }, vStateBadge(x.state, b), x.autostart ? h('span', { class: 'badge plain' }, 'autostart') : null), h('div', { class: 'stats' }, h('span', null, res), h('span', null, x.agent ? 'guest agent' : 'no guest agent')), a));
      else r.rows.appendChild(h('div', { class: 'rowi c' + (b ? ' busy' : ''), role: 'listitem' }, who, h('div', { class: 'c-st' }, vStateBadge(x.state, b)),
        h('span', { class: 'c-cpu num' }, x.cpus ? String(x.cpus) : ''), h('span', { class: 'c-mem num muted' }, x.memory ? bytes(x.memory) : ''),
        h('div', { class: 'c-upd' }, !S.boot.caps.vm_details ? null : x.agent ? h('span', { class: 'badge ok', title: 'Hibernate is available' }, 'agent') : h('span', { class: 'badge plain', title: 'No QEMU guest agent channel: hibernate is not available' }, 'no agent')), a));
    });
    if (hidden || S.showHidden) r.rows.appendChild(h('div', { class: 'more' }, btn(S.showHidden ? 'Hide hidden VMs' : 'Show ' + hidden + ' hidden', function () { S.showHidden = !S.showHidden; if (R.docker) R.docker.fSig = null; r.sig = null; paintAll(); }, 'sm ghost', 'eye')));
    if (focus) { var f = r.rows.querySelector('[aria-label="' + focus.replace(/"/g, '') + '"]'); if (f && !f.disabled) f.focus(); }
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

  /* ---- media: four poster rows, each refreshed on its own ---- */
  var MEDIA = { upcoming_movies: ['Upcoming movies', 'Nothing scheduled in the coming weeks.'], upcoming_episodes: ['Upcoming episodes', 'No episodes airing soon.'],
                recent_movies: ['Recently added movies', 'No movies imported recently.'], recent_episodes: ['Recently added episodes', 'No episodes imported recently.'] };
  BUILD.media = function (r) {
    r.grid = h('div', { class: 'media' }); r.w = {};
    S.L.media.widgets.forEach(function (w) {
      if (!w.visible || !MEDIA[w.id]) return;
      var x = { link: h('span'), row: h('div', { class: 'posters', role: 'list', 'aria-label': MEDIA[w.id][0] }, h('div', { class: 'empty' }, 'Loading…')), count: h('span', { class: 'count' }) };
      x.el = h('div', { class: 'mw card' }, h('div', { class: 'mh' }, h('h3', null, MEDIA[w.id][0]), x.count, h('span', { class: 'grow' }), x.link), x.row);
      r.w[w.id] = x; r.grid.appendChild(x.el);
    });
    if (!r.grid.childNodes.length) r.grid.appendChild(h('div', { class: 'card empty' }, 'All media widgets are hidden (Settings > Dashboard).'));
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
    var sig = JSON.stringify(j); if (x.sig === sig) return; x.sig = sig;
    clear(x.row); clear(x.link);
    if (j.app && j.app.href) x.link.appendChild(h('a', { class: 'btn sm ghost', href: j.app.href, target: '_blank', rel: 'noopener', title: 'Open ' + j.app.name }, j.app.name, ic('web')));
    if (j.error && !(j.items || []).length) { x.row.appendChild(h('div', { class: 'empty ' + (j.unconfigured ? '' : 'err-t') }, j.error)); txt(x.count, ''); return; }
    txt(x.count, String(j.items.length));
    if (!j.items.length) { x.row.appendChild(h('div', { class: 'empty' }, MEDIA[k][1])); return; }
    var upcoming = k.indexOf('upcoming') === 0;
    j.items.forEach(function (it) {
      if (!matches(it.title + ' ' + (it.sub || ''))) return;
      var d = new Date(it.ts * 1000);
      var when = upcoming ? (it.when === 'Airs' ? d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString([], { month: 'short', day: 'numeric' }))
        : rel(it.ts);
      x.row.appendChild(h('a', { class: 'poster', role: 'listitem', href: it.href || null, target: it.href ? '_blank' : null, rel: 'noopener', title: it.title + (it.year ? ' (' + it.year + ')' : '') + (it.sub ? '\n' + it.sub : '') + '\n' + (upcoming ? (it.when === 'Airs' ? 'Airs ' : it.when + ' ') : 'Added ') + d.toLocaleString() },
        h('span', { class: 'pimg' }, it.poster ? h('img', { src: it.poster, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', onerror: function () { this.remove(); } }) : null,
          it.done ? h('span', { class: 'pdone', title: 'Already downloaded' }, ic('check')) : null, upcoming && it.when !== 'Airs' ? h('span', { class: 'ptag' }, it.when) : null),
        h('span', { class: 'pt' }, it.title), h('span', { class: 'ps' }, it.sub ? it.sub : (it.year ? String(it.year) : '')), h('span', { class: 'pd' }, when)));
    });
  }

  /* ---- Arr Stack: one compact row per app, details on demand ---- */
  function zoneApps(zone) { var out = []; S.L.groups.forEach(function (g) { g.services.forEach(function (s) { if (s.zone === zone && !s.hidden) out.push(s); }); }); return out; }
  BUILD.arr = function (r) {
    var apps = zoneApps('arr');
    r.list = h('div', { class: 'panel arr', role: 'list' }); r.rows = {};
    if (!apps.length) r.list.appendChild(h('div', { class: 'empty' }, 'No Arr apps yet. Add Radarr, Sonarr, Prowlarr… in Settings > Apps.'));
    apps.forEach(function (s) {
      var x = { s: s, dot: h('span', { class: 'sdot na', role: 'img', 'aria-label': 'checking' }), info: h('span', { class: 'ai' }, 'Checking…'), extra: h('span', { class: 'ax' }), det: h('div', { class: 'adet', hidden: true }) };
      var tog = h('button', { type: 'button', class: 'arow', 'aria-expanded': 'false', onclick: function () { x.det.hidden = !x.det.hidden; tog.setAttribute('aria-expanded', x.det.hidden ? 'false' : 'true'); } },
        img(iconUrl(s.icon), 'aico', 'tag'), h('span', { class: 'an' }, s.name), x.dot, x.info, x.extra);
      x.el = h('div', { class: 'aitem', role: 'listitem', 'data-name': s.name }, h('div', { class: 'aline' }, tog, s.href_resolved ? h('a', { class: 'btn ic sm ghost', href: s.href_resolved, target: '_blank', rel: 'noopener', title: 'Open ' + s.name, 'aria-label': 'Open ' + s.name }, ic('web')) : null), x.det);
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
    clear(x.info); clear(x.extra);
    if (!j.online) x.info.appendChild(h('span', { class: j.unconfigured ? 'muted' : 't-bad' }, j.unconfigured ? 'Not configured' : 'Offline'));
    else (j.blocks || []).slice(0, 4).forEach(function (b) { x.info.appendChild(h('span', { class: 'st' }, h('b', null, fmt(b.value, b.fmt)), ' ' + b.label.toLowerCase())); });
    if (issues) x.extra.appendChild(h('span', { class: 'badge ' + (bad ? 'bad' : 'warn') }, issues + (issues === 1 ? ' issue' : ' issues')));
    else if (j.version) x.extra.appendChild(h('span', { class: 'muted xs' }, 'v' + j.version));
    clear(x.det);
    if (j.error) x.det.appendChild(h('div', { class: 'banner bad' }, j.error));
    if ((j.blocks || []).length) x.det.appendChild(h('div', { class: 'blocks' }, j.blocks.map(function (b) { return h('div', { class: 'blk' }, h('div', { class: 'v' }, fmt(b.value, b.fmt)), h('div', { class: 'l' }, b.label)); })));
    (j.health || []).forEach(function (z) { x.det.appendChild(h('div', { class: 'alert ' + z.level }, h('span', { class: 'grow' }, (z.source ? z.source + ': ' : '') + z.msg))); });
    (j.issues || []).forEach(function (z) { x.det.appendChild(h('div', { class: 'alert ' + z.level }, h('span', { class: 'grow' }, h('b', null, z.title), ' · ' + z.msg))); });
    if (j.online && !issues && !j.error) x.det.appendChild(h('div', { class: 'muted sm' }, 'No warnings' + (j.version ? ' · version ' + j.version : '') + '.'));
  }

  /* ---- download clients ---- */
  BUILD.downloads = function (r) {
    var apps = zoneApps('downloads');
    r.list = h('div', { class: 'dls' }); r.c = {};
    if (!apps.length) r.list.appendChild(h('div', { class: 'card empty' }, 'No download clients yet. Add qBittorrent or SABnzbd in Settings > Apps.'));
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
    var s = x.s, st = j.error ? ['Unreachable', 'bad'] : (DLSTATE[j.state] || [j.state, 'off']);
    clear(x.head); clear(x.body);
    var paused = j.state === 'paused' || j.paused;
    x.head.appendChild(img(iconUrl(s.icon), 'aico', 'download'));
    x.head.appendChild(h('span', { class: 'an' }, s.name));
    x.head.appendChild(h('span', { class: 'badge ' + st[1] }, busy || (j.unconfigured ? 'Not configured' : st[0])));
    x.head.appendChild(h('span', { class: 'grow' }));
    if (j.online && j.can_pause) x.head.appendChild(btn('', function () { dlAct(s, id, paused ? 'resume' : 'pause'); }, 'sm' + (paused ? ' okc' : ''), paused ? 'play' : 'pause', (paused ? 'Resume all in ' : 'Pause all in ') + s.name));
    if (s.href_resolved) x.head.appendChild(h('a', { class: 'btn ic sm ghost', href: s.href_resolved, target: '_blank', rel: 'noopener', title: 'Open ' + s.name, 'aria-label': 'Open ' + s.name }, ic('web')));
    if (busy) x.head.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    if (j.error) { x.body.appendChild(h('div', { class: 'sm t-bad' }, j.error)); return; }
    var c = j.counts || {};
    x.body.appendChild(h('div', { class: 'dlspeed' }, h('span', null, h('b', null, rate(j.down)), ' ↓'), j.up !== null && j.up !== undefined ? h('span', null, h('b', null, rate(j.up)), ' ↑') : null, j.eta && j.eta !== '0:00:00' ? h('span', { class: 'muted' }, j.eta + ' left') : null));
    var chips = [['downloading', 'active', ''], ['stalled', 'stalled', 'warn'], ['queued', 'queued', ''], ['paused', 'paused', 'off'], ['seeding', 'seeding', ''], ['completed', 'done', ''], ['error', 'errors', 'bad']];
    x.body.appendChild(h('div', { class: 'chips' }, chips.filter(function (z) { return c[z[0]]; }).map(function (z) { return h('span', { class: 'badge plain ' + z[2] }, c[z[0]].toLocaleString() + ' ' + z[1]); })));
    if (j.note) x.body.appendChild(h('div', { class: 'xs t-warn' }, j.note));
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
    if (!groups.length) { r.body.appendChild(h('div', { class: 'card empty' }, 'No other apps to show. Add Plex, Immich, AdGuard… in ', h('a', { href: '#', onclick: function (e) { e.preventDefault(); openSettings('services'); } }, 'Settings > Apps'), '.')); return; }
    var grid = function () { var g = h('div', { class: 'agrid' + (A.columns ? ' fixed' : ''), role: 'list' }); if (A.columns) g.style.setProperty('--ac', String(A.columns)); return g; };
    if (!A.headers) {
      var all = grid(), links = h('div', { class: 'alinks' });
      groups.forEach(function (x) { x.s.forEach(function (s) { (s.type === 'link' ? links : all).appendChild(widget(s)); }); });
      if (all.childNodes.length) r.body.appendChild(all);
      if (links.childNodes.length) r.body.appendChild(links);
    } else groups.forEach(function (x) {
      var gr = x.g, wrap = h('div', { class: 'grp' + (closed[gr.id] ? ' closed' : '') }), gd = grid(), links = h('div', { class: 'alinks' });
      wrap.appendChild(h('h3', null, h('button', { type: 'button', 'aria-expanded': closed[gr.id] ? 'false' : 'true', onclick: function (e) { closed[gr.id] = !closed[gr.id]; LS.set('closed', closed); wrap.classList.toggle('closed'); e.currentTarget.setAttribute('aria-expanded', closed[gr.id] ? 'false' : 'true'); } }, ic('chev'), gr.name)));
      x.s.forEach(function (s) { (s.type === 'link' ? links : gd).appendChild(widget(s)); });
      if (gd.childNodes.length) wrap.appendChild(gd);
      if (links.childNodes.length) wrap.appendChild(links);
      r.body.appendChild(wrap);
    });
    txt(r.count, String(groups.reduce(function (n, x) { return n + x.s.length; }, 0)));
    clearTimeout(S.svcTimer); S.svcRun = false;
    setTimeout(pollServices, 0);
  };
  function widget(s) {
    var u = iconUrl(s.icon), href = s.href_resolved || '';
    if (s.type === 'link') {
      return h(href ? 'a' : 'span', { class: 'alink', href: href || null, target: href ? '_blank' : null, rel: href ? 'noopener' : null, title: s.description || href, 'data-name': s.name }, img(u, 'aico', 'tag'), s.name);
    }
    var nm = href ? h('a', { class: 'nm', href: href, target: '_blank', rel: 'noopener' }, s.name) : h('span', { class: 'nm' }, s.name);
    var c = h('div', { class: 'card w ac wait' + (s.span === 2 ? ' span2' : ''), id: 'w-' + s.id, 'data-name': s.name, role: 'listitem' },
      h('div', { class: 'ch' }, img(u, 'aico', 'tag'), nm, h('span', { class: 'sdot na', role: 'img', 'aria-label': 'loading' }), h('span', { class: 'sub' }),
        href ? h('a', { class: 'btn ic sm ghost', href: href, target: '_blank', rel: 'noopener', title: 'Open ' + s.name, 'aria-label': 'Open ' + s.name }, ic('web')) : null),
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

  function blocks(list) { return h('div', { class: 'blocks' }, list.map(function (x) { return h('div', { class: 'blk' }, h('div', { class: 'v' }, fmt(x.value, x.fmt)), h('div', { class: 'l' }, x.label)); })); }
  function paintService(s, r) {
    var c = document.getElementById('w-' + s.id); if (!c) return;
    c.classList.remove('wait');
    var dot = c.querySelector('.ch .sdot');
    if (dot) { dot.className = 'sdot ' + (r.error ? (r.unconfigured ? 'na' : 'bad') : 'ok'); dot.setAttribute('aria-label', r.error ? (r.unconfigured ? 'not configured' : 'error') : 'online'); }
    var cb = clear(c.querySelector('.cb'));
    if (r.error) {
      var e = h('button', { type: 'button', class: 'err' + (r.unconfigured ? ' cfg' : ''), 'aria-expanded': 'false', onclick: function () { e.classList.toggle('open'); e.setAttribute('aria-expanded', e.classList.contains('open') ? 'true' : 'false'); } }, r.unconfigured ? 'Not configured' : r.offline ? 'Unreachable' : 'API error', h('div', { class: 'x' }, r.error));
      cb.appendChild(e); return;
    }
    if (r.blocks && r.blocks.length) cb.appendChild(blocks(r.blocks));
    if (r.list) {
      var l = h('div', { class: 'lst' });
      r.list.forEach(function (x) { l.appendChild(h('div', { class: 'r' }, x.label ? h('span', { class: 'lab' }, x.label) : null, h('span', { class: 't', title: x.title }, x.title), x.ts ? h('span', { class: 'when' }, rel(x.ts)) : (x.right ? h('span', { class: 'when' }, x.right) : null))); });
      if (!r.list.length) l.appendChild(h('div', { class: 'muted sm' }, 'Nothing yet'));
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
    if (r.streams) {
      if (!r.streams.length) cb.appendChild(h('div', { class: 'muted sm', style: { 'margin-top': '6px' } }, r.empty || 'No active streams'));
      else cb.appendChild(h('div', { class: 'streams' }, r.streams.map(function (x) {
        return h('div', { class: 'stream', title: x.title + (x.user ? ' (' + x.user + ')' : '') + (x.transcode ? ' · ' + x.transcode : '') },
          h('i', { class: 'pg', style: { width: (x.dur ? Math.min(100, 100 * x.pos / x.dur) : 0) + '%' } }), ic(x.state === 'paused' ? 'pause' : 'play'),
          h('span', { class: 'tt' }, x.title + (x.user ? ' (' + x.user + ')' : '')), h('span', { class: 'tm' }, x.dur ? dur(x.pos) + ' / ' + dur(x.dur) : ''));
      })));
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
    autobrr: 'approvedPushes, rejectedPushes, filters, indexers', wizarr: 'users, invites, pending, expired', qbittorrent: 'leech, download, seed, upload', sabnzbd: 'rate, queue, timeleft',
    emby: 'movies, series, episodes, songs', jellyfin: 'movies, series, episodes, songs', plex: 'streams, albums, movies, tv', adguard: 'queries, blocked, filtered, latency',
    pihole: 'queries, blocked, blocked_percent, gravity', npm: 'enabled, disabled, total', immich: 'users, photos, videos, storage', peanut: 'battery_charge, ups_load, ups_status',
    ups: 'battery_charge, ups_load, ups_status, runtime', docker_summary: 'running, stopped, paused, updates', vm_summary: 'running, stopped, paused, total'
  };
  var SEC_DESC = { overview: 'CPU, memory, storage, network, health, disks, UPS', alerts: 'Problems that need attention', favorites: 'Starred containers, VMs and apps',
    media: 'Upcoming and recently added movies and episodes', arr: 'Radarr, Sonarr, Prowlarr… status and issues', downloads: 'qBittorrent and SABnzbd activity',
    docker: 'Every container, found automatically', vms: 'Every VM, found automatically', apps: 'Plex, Immich, AdGuard, links and other apps' };
  var MET_DESC = { cpu: 'Usage and temperature', memory: 'RAM used and available', storage: 'Array (or pools) capacity', network: 'Download and upload speed', health: 'Array state, disk problems, parity checks',
    disks: 'Hottest disk', uptime: 'Time since boot', ups: 'UPS battery', cores: 'Busiest core', swap: 'Swap usage', load: 'Load average (plugin only)', path: 'Free space of one path (plugin only)',
    docker: 'Running containers', vms: 'Running VMs' };
  var MEDIA_DESC = { upcoming_movies: 'Radarr calendar', upcoming_episodes: 'Sonarr calendar', recent_movies: 'Radarr imports', recent_episodes: 'Sonarr imports' };

  function openSettings(tab) {
    if (S.drawer) return;
    closeMenu(true);
    var D = { tab: typeof tab === 'string' ? tab : 'dashboard', dirty: false };
    S.drawer = D;
    var last = document.activeElement;
    var bodyEl = h('div', { class: 'db', role: 'tabpanel' });
    var saveB = btn('Save', save, 'pri'); saveB.disabled = true;
    var status = h('span', { class: 'muted sm grow', role: 'status' }, 'Loading…');
    var tabs = h('div', { class: 'dt', role: 'tablist', 'aria-label': 'Settings sections' });
    [['dashboard', 'Dashboard'], ['system', 'System'], ['appearance', 'Appearance'], ['services', 'Apps'], ['config', 'Configuration']].forEach(function (t) {
      tabs.appendChild(h('button', { type: 'button', role: 'tab', 'data-t': t[0], onclick: function () { D.tab = t[0]; paintTabs(); render(); } }, t[1]));
    });
    var drawer = h('aside', { class: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Settings' },
      h('div', { class: 'dh' }, ic('gear'), h('h2', null, 'Settings'), h('span', { class: 'grow' }), btn('', function () { close(); }, 'ghost', 'x', 'Close settings')), tabs, bodyEl,
      h('div', { class: 'df' }, saveB, btn('Discard', function () { if (!D.dirty || window.confirm('Discard your changes?')) close(true); }), status));
    var bg = h('div', { class: 'drawer-bg', onclick: function () { close(); } });
    root.appendChild(bg); root.appendChild(drawer);
    function key(e) {
      if (root.querySelector('.modal-bg')) return;
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') {
        var f = drawer.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select,textarea');
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
      }
    }
    document.addEventListener('keydown', key);
    function paintTabs() { [].forEach.call(tabs.childNodes, function (b) { var on = b.getAttribute('data-t') === D.tab; b.className = on ? 'on' : ''; b.setAttribute('aria-selected', on ? 'true' : 'false'); }); }
    function dirty() { D.dirty = true; saveB.disabled = false; status.textContent = 'Unsaved changes'; }
    function close(force) {
      if (force !== true && D.dirty && !window.confirm('Close settings without saving your changes?')) return;
      document.removeEventListener('keydown', key);
      bg.remove(); drawer.remove(); S.drawer = null;
      if (D.dirty) { applyLook(); applyCss(S.boot.css); }
      if (last && last.focus && document.body.contains(last)) try { last.focus(); } catch (e) { /* gone */ }
    }
    D.close = close;
    function save() {
      saveB.disabled = true; status.textContent = 'Saving…';
      var data = { config: D.cfg, vars: D.vars.filter(function (v) { return v.name; }).map(function (v) { return { name: v.name, value: v.value }; }), svc: D.svc };
      if (D.cssChanged) data.css = D.css;
      api('save', data).then(function (j) {
        if (j.error) { saveB.disabled = false; status.textContent = j.error; toast(j.error, true); return; }
        D.dirty = false; toast('Settings saved');
        close(true); reload();
      });
    }
    function render() {
      clear(bodyEl);
      if (!D.cfg) { bodyEl.appendChild(h('div', { class: 'progress' }, h('i'))); return; }
      ({ dashboard: tabDashboard, system: tabSystem, appearance: tabAppearance, services: tabServices, config: tabConfig })[D.tab](bodyEl, D, dirty);
      bodyEl.scrollTop = 0;
    }
    D.render = render;
    paintTabs(); render();
    api('settings').then(function (j) {
      if (j.error) { status.textContent = j.error; return; }
      D.cfg = j.config; D.vars = j.secrets.vars; D.svc = Array.isArray(j.secrets.svc) ? {} : j.secrets.svc; D.types = j.types; D.keep = j.keep; D.css = j.css; D.loc = j.loc; D.store = j.store; D.flash = j.flash;
      ['docker', 'vms'].forEach(function (k) { if (Array.isArray(D.cfg[k].items)) D.cfg[k].items = {}; });
      status.textContent = 'Changes apply after saving.';
      render();
      setTimeout(function () { var f = tabs.querySelector('.on'); if (f) f.focus(); }, 0);
    });
  }
  /* small form helpers */
  function F(label, input, hint) { return h('div', { class: 'fld' }, h('label', null, label), input, hint ? h('div', { class: 'hint' }, hint) : null); }
  function tIn(o, k, dirty, ph, type, attrs) { return h('input', Object.assign({ type: type || 'text', value: o[k] === undefined || o[k] === null ? '' : o[k], placeholder: ph || '', oninput: function (e) { o[k] = type === 'number' ? +e.target.value : e.target.value; dirty(); } }, attrs || {})); }
  function cIn(o, k, dirty, lab) { return h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!o[k], onchange: function (e) { o[k] = e.target.checked; dirty(); } }), lab); }
  function sIn(o, k, dirty, opts, onch) { return h('select', { onchange: function (e) { o[k] = e.target.value; dirty(); if (onch) onch(); } }, opts.map(function (x) { return h('option', { value: x[0], selected: String(o[k]) === String(x[0]) }, x[1]); })); }
  function move(arr, i, d) { var j = i + d; if (j < 0 || j >= arr.length) return false; var x = arr[i]; arr[i] = arr[j]; arr[j] = x; return true; }
  function rid() { var a = new Uint8Array(4); window.crypto.getRandomValues(a); return [].map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function set(title, hint) { var b = h('section', { class: 'set' }, h('h3', null, title), hint ? h('p', { class: 'hint' }, hint) : null); for (var i = 2; i < arguments.length; i++) add(b, arguments[i]); return b; }
  /* reorderable list: drag the handle (mouse) or use the arrow buttons (keyboard, touch) */
  function orderList(arr, row, dirty, redraw) {
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
      return el;
    }));
  }

  function tabDashboard(b, D, dirty) {
    var c = D.cfg, redraw = function () { D.render(); };
    b.appendChild(set('Sections', 'Show, hide and order the parts of the dashboard. Half-width sections sit side by side on wide screens.',
      orderList(c.dashboard.sections, function (s) {
        return [h('label', { class: 'switch t', style: { margin: '0' } }, h('input', { type: 'checkbox', checked: s.visible, onchange: function (e) { s.visible = e.target.checked; dirty(); } }), h('span', null, h('b', null, TITLES[s.id]), h('span', { class: 'muted sm' }, ' · ' + SEC_DESC[s.id]))),
          sIn(s, 'width', dirty, [['full', 'Full width'], ['half', 'Half width']])];
      }, dirty, redraw)));
    b.appendChild(set('System overview', 'The tiles at the top, in this order. Click a tile on the dashboard to see its details. A metric the server cannot measure says so instead of showing a made-up number.',
      orderList(c.dashboard.metrics, function (m) { return h('label', { class: 'switch t', style: { margin: '0' } }, h('input', { type: 'checkbox', checked: m.visible, onchange: function (e) { m.visible = e.target.checked; dirty(); } }), h('span', null, h('b', null, MET[m.id][0]), h('span', { class: 'muted sm' }, ' · ' + MET_DESC[m.id]))); }, dirty, redraw)));
    var radarrs = [['', 'First Radarr app']], sonarrs = [['', 'First Sonarr app']];
    c.groups.forEach(function (g) { g.services.forEach(function (x) { if (x.type === 'radarr') radarrs.push([x.id, x.name]); if (x.type === 'sonarr') sonarrs.push([x.id, x.name]); }); });
    b.appendChild(set('Media', 'Posters and dates from your Radarr and Sonarr apps.',
      orderList(c.media.widgets, function (w) { return h('label', { class: 'switch t', style: { margin: '0' } }, h('input', { type: 'checkbox', checked: w.visible, onchange: function (e) { w.visible = e.target.checked; dirty(); } }), h('span', null, h('b', null, MEDIA[w.id][0]), h('span', { class: 'muted sm' }, ' · ' + MEDIA_DESC[w.id]))); }, dirty, redraw),
      h('div', { class: 'grid2', style: { 'margin-top': '12px' } }, F('Radarr', sIn(c.media, 'radarr', dirty, radarrs)), F('Sonarr', sIn(c.media, 'sonarr', dirty, sonarrs)),
        F('Items per row', tIn(c.media, 'count', dirty, '15', 'number', { min: 4, max: 40 })), F('Days ahead', tIn(c.media, 'days_ahead', dirty, '30', 'number', { min: 7, max: 120 })))));
    b.appendChild(set('Downloads', null, h('div', { class: 'grid2' }, F('Active items shown per client', tIn(c.downloads, 'items', dirty, '4', 'number', { min: 0, max: 20 }), '0 shows the totals only.'))));
    var D2 = c.docker;
    var shows = [['cpu', 'CPU'], ['memory', 'Memory'], ['uptime', 'Uptime'], ['image', 'Image'], ['update', 'Update badge']];
    var showBox = h('div', { class: 'inl' }, shows.map(function (x) {
      return h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: D2.show.indexOf(x[0]) >= 0, onchange: function (e) {
        D2.show = D2.show.filter(function (k) { return k !== x[0]; }); if (e.target.checked) D2.show.push(x[0]); dirty(); } }), x[1]);
    }));
    var all = ((S.live || {}).docker || {}).containers || [];
    var pick = h('div', { class: 'picks' }), pf = h('input', { type: 'search', placeholder: 'Filter…', 'aria-label': 'Filter containers', oninput: function () { drawPick(); } });
    function drawPick() {
      clear(pick);
      var q = pf.value.toLowerCase();
      all.filter(function (x) { return !q || x.name.toLowerCase().indexOf(q) >= 0; }).forEach(function (x) {
        pick.appendChild(h('label', { class: 'switch pk' }, h('input', { type: 'checkbox', checked: D2.selected.indexOf(x.name) >= 0, onchange: function (e) {
          D2.selected = D2.selected.filter(function (n) { return n !== x.name; }); if (e.target.checked) D2.selected.push(x.name); txt(selCount, D2.selected.length + ' selected'); dirty(); } }),
          h('span', { class: 'sdot ' + (x.state === 'running' ? 'ok' : 'na') }), h('span', { class: 'ell' }, x.name)));
      });
      if (!all.length) pick.appendChild(h('div', { class: 'muted sm' }, 'Containers appear here once the dashboard has loaded them.'));
    }
    var selCount = h('span', { class: 'muted sm' }, D2.selected.length + ' selected');
    drawPick();
    b.appendChild(set('Containers', 'Found automatically: new containers appear and removed ones disappear on their own.',
      h('div', { class: 'grid2' },
        F('Show by default', sIn(D2, 'filter', dirty, [['running', 'Running containers'], ['selected', 'Selected containers (below)'], ['all', 'All containers']])),
        F('Layout', sIn(D2, 'layout', dirty, [['grid', 'Compact grid'], ['list', 'Table'], ['cards', 'Cards']])),
        F('Grid columns', sIn(D2, 'columns', dirty, [[0, 'Automatic (fits the width)'], [1, '1'], [2, '2'], [3, '3'], [4, '4']])),
        F('Sort by', sIn(D2, 'sort', dirty, [['name', 'Name'], ['status', 'Status'], ['cpu', 'CPU'], ['memory', 'Memory']]))),
      F('Details on each container', showBox, S.boot.caps.docker_stats ? null : 'CPU and memory are not available through the Unraid API (Docker version).'),
      h('div', { class: 'fld' }, h('label', null, 'Selected containers ', selCount),
        h('div', { class: 'inl', style: { 'margin-bottom': '6px' } }, pf,
          btn('Select running', function () { D2.selected = all.filter(function (x) { return x.state === 'running'; }).map(function (x) { return x.name; }); txt(selCount, D2.selected.length + ' selected'); drawPick(); dirty(); }, 'sm'),
          btn('Clear', function () { D2.selected = []; txt(selCount, '0 selected'); drawPick(); dirty(); }, 'sm ghost')),
        pick, h('div', { class: 'hint' }, 'Also from the dashboard: ⋯ > Show in "Selected".'))));
    var A2 = c.apps;
    b.appendChild(set('Apps', 'Plex, Emby, Immich, AdGuard and your other apps, below Arr Stack and Downloads.', h('div', { class: 'grid2' },
      F('Columns', sIn(A2, 'columns', dirty, [[0, 'Automatic'], [1, '1'], [2, '2'], [3, '3'], [4, '4'], [5, '5'], [6, '6']]))),
      cIn(A2, 'headers', dirty, 'Show group titles (Streaming, Other …)'), cIn(A2, 'links', dirty, 'Show shortcut links (the old Services links) as small buttons')));
    b.appendChild(set('Virtual machines', 'VMs are found automatically through libvirt.', h('div', { class: 'grid2' }, F('Layout', sIn(c.vms, 'layout', dirty, [['list', 'List'], ['cards', 'Cards']])))));
    var y = S.live || {}, items = [];
    ((y.docker && y.docker.containers) || []).forEach(function (x) { items.push(['docker', x]); });
    ((y.vms && y.vms.vms) || []).forEach(function (x) { items.push(['vms', x]); });
    var filter = h('input', { type: 'search', placeholder: 'Filter…', 'aria-label': 'Filter containers and VMs', oninput: function () { draw(); } });
    var list = h('div', { class: 'olist' });
    function draw() {
      clear(list);
      var q = filter.value.toLowerCase();
      items.filter(function (x) { return !q || x[1].name.toLowerCase().indexOf(q) >= 0; }).slice(0, 300).forEach(function (x) {
        var kind = x[0], it = x[1], bag = c[kind].items;
        var p = bag[it.name] || { label: '', icon: '', description: '', favorite: false, hidden: false };
        var put = function () { bag[it.name] = p; dirty(); };
        list.appendChild(h('div', { class: 'oi' }, img(iconUrl(p.icon || it.icon), '', kind === 'docker' ? 'box' : 'vm'),
          h('span', { class: 't' }, h('b', null, it.name), h('span', { class: 'muted xs' }, kind === 'docker' ? ' · container' : ' · VM')),
          h('input', { type: 'text', value: p.label || '', placeholder: 'Display name', 'aria-label': 'Display name for ' + it.name, oninput: function (e) { p.label = e.target.value; put(); } }),
          h('input', { type: 'text', value: p.icon || '', placeholder: 'Icon', 'aria-label': 'Icon for ' + it.name, oninput: function (e) { p.icon = e.target.value; put(); } }),
          h('label', { class: 'switch', title: 'Favorite' }, h('input', { type: 'checkbox', checked: !!p.favorite, 'aria-label': 'Favorite ' + it.name, onchange: function (e) { p.favorite = e.target.checked; put(); } }), ic('star')),
          h('label', { class: 'switch', title: 'Hidden from the dashboard' }, h('input', { type: 'checkbox', checked: !!p.hidden, 'aria-label': 'Hide ' + it.name, onchange: function (e) { p.hidden = e.target.checked; put(); } }), ic('eyeoff'))));
      });
      if (!list.childNodes.length) list.appendChild(h('div', { class: 'empty' }, items.length ? 'Nothing matches.' : 'No containers or VMs found yet.'));
    }
    draw();
    b.appendChild(set('Favorites, names and visibility', 'Kept by name, so they survive updates and re-created containers. Star = favorite, crossed eye = hidden from the dashboard.', h('div', { style: { 'margin-bottom': '8px' } }, filter), list));
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
      F('UPS name (NUT)', tIn(g, 'ups_name', dirty, 'ups'), 'NUT (upsc) is tried first, then apcupsd.'),
      F('Web search (Enter in the filter box when nothing matches)', sIn(g, 'search', dirty, [['duckduckgo', 'DuckDuckGo'], ['google', 'Google'], ['bing', 'Bing'], ['startpage', 'Startpage'], ['brave', 'Brave'], ['none', 'Off']])))));
  }

  function tabAppearance(b, D, dirty) {
    var a = D.cfg.appearance;
    var preview = function () { applyLook(a); };
    var THEMES = [['default', 'Default', ['#1d1b1b', '#232121']], ['light', 'Light', ['#f3f4f6', '#ffffff']], ['amoled', 'AMOLED', ['#000000', '#0b0b0b']]];
    var tw = h('div', { class: 'themes', role: 'radiogroup', 'aria-label': 'Theme' });
    THEMES.forEach(function (t) {
      tw.appendChild(h('button', { type: 'button', class: 'theme' + (a.theme === t[0] ? ' on' : ''), role: 'radio', 'aria-checked': a.theme === t[0] ? 'true' : 'false', onclick: function () { a.theme = t[0]; dirty(); preview(); D.render(); } },
        h('span', { class: 'pv', style: { background: t[2][0] } }, h('i', { style: { background: t[2][1] } }), h('i', { style: { background: t[2][1] } }), h('i', { style: { background: a.accent, flex: '0 0 18%' } })), h('b', null, t[1])));
    });
    var col, hex;
    hex = h('input', { type: 'text', value: a.accent, 'aria-label': 'Accent colour (hex)', maxlength: 7, style: { width: '110px' }, oninput: function (e) { var v = e.target.value.trim(); if (/^#[0-9a-f]{6}$/i.test(v)) { a.accent = v.toLowerCase(); col.value = a.accent; e.target.style.borderColor = ''; dirty(); preview(); } else e.target.style.borderColor = 'var(--bad)'; } });
    col = h('input', { type: 'color', value: a.accent, 'aria-label': 'Accent colour', oninput: function (e) { a.accent = e.target.value; hex.value = a.accent; dirty(); preview(); } });
    b.appendChild(set('Theme', 'Three built-in themes. The accent colours buttons, links and selections only; status colours (green, amber, red) never change.', tw,
      h('div', { class: 'inl', style: { 'margin-top': '14px' } }, h('span', { class: 'muted sm' }, 'Accent'), col, hex, btn('Default orange', function () { a.accent = '#f15a2c'; hex.value = col.value = a.accent; dirty(); preview(); }, 'sm ghost')),
      h('div', { class: 'grid2', style: { 'margin-top': '12px' } }, F('Density', sIn(a, 'density', dirty, [['compact', 'Compact'], ['comfortable', 'Comfortable'], ['expanded', 'Expanded']], preview)))));
    var pd = function () { dirty(); preview(); };
    b.appendChild(set('Wallpaper', 'Optional image behind the dashboard (https:// or a path served by Unraid).', h('div', { class: 'grid2' },
      F('Image URL', tIn(a, 'background', pd, 'https://…')),
      F('Blur (px) / brightness (%)', h('div', { class: 'inl' }, tIn(a, 'blur', pd, '', 'number', { min: 0, max: 40, style: { width: '90px' } }), tIn(a, 'brightness', pd, '', 'number', { min: 10, max: 100, style: { width: '90px' } }))))));
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
        btn('Reset', function () { confirmBox('Clear the custom CSS?', 'The editor is emptied; the theme is not affected. Save to keep the change.', 'Clear', true).then(function (y) { if (y) { ta.value = ''; ta.dispatchEvent(new Event('input')); } }); }, 'sm danger', 'trash')),
      ta, h('div', { style: { 'margin-top': '6px' } }, msg)));
    check();
  }

  function tabServices(b, D, dirty) {
    var vt = h('div', { class: 'olist' });
    var drawVars = function () {
      clear(vt);
      D.vars.forEach(function (v, i) {
        if (v.value === D.keep) v.stored = true;
        vt.appendChild(h('div', { class: 'oi' },
          h('input', { type: 'text', value: v.name, placeholder: 'NAME', 'aria-label': 'Variable name', style: { flex: '1 1 160px' }, oninput: function (e) { v.name = e.target.value.toUpperCase(); v.secret = /KEY|TOKEN|PASS|SECRET|AUTH|COOKIE|SESSION|CREDENTIAL/i.test(v.name); dirty(); } }),
          h('input', { type: v.secret ? 'password' : 'text', value: v.value === D.keep ? '' : v.value, placeholder: v.stored ? 'stored (leave empty to keep)' : 'value', autocomplete: 'off', 'aria-label': 'Value of ' + (v.name || 'variable'), style: { flex: '2 1 200px' },
            oninput: function (e) { v.value = e.target.value === '' && v.stored ? D.keep : e.target.value; dirty(); } }),
          btn('', function () { D.vars.splice(i, 1); dirty(); drawVars(); }, 'sm ghost danger', 'trash', 'Remove variable ' + v.name)));
      });
      if (!D.vars.length) vt.appendChild(h('div', { class: 'empty' }, 'No variables.'));
    };
    drawVars();
    b.appendChild(set('Variables', 'Use {{NAME}} in any URL, link, key or password, like Homepage\'s HOMEPAGE_VAR_… variables. Names containing KEY, TOKEN, PASS or SECRET are secrets: never shown again and left out of redacted exports. {{UNRAID_IP}} is built in.',
      vt, h('div', { style: { 'margin-top': '8px' } }, btn('Add variable', function () { D.vars.push({ name: '', value: '', secret: false }); dirty(); drawVars(); }, 'sm', 'plus'))));
    var lay = h('div');
    var drawLayout = function () {
      clear(lay);
      D.cfg.groups.forEach(function (gr, gi) {
        var box = h('div', { class: 'lg' });
        box.appendChild(h('div', { class: 'lh' }, h('input', { type: 'text', value: gr.name, 'aria-label': 'Group name', oninput: function (e) { gr.name = e.target.value; dirty(); } }),
          h('label', { class: 'inl sm muted' }, 'Columns', h('input', { type: 'number', min: 1, max: 8, value: gr.columns, oninput: function (e) { gr.columns = +e.target.value; dirty(); } })),
          h('label', { class: 'switch sm' }, h('input', { type: 'checkbox', checked: gr.header, onchange: function (e) { gr.header = e.target.checked; dirty(); } }), 'Title'),
          btn('', function () { if (move(D.cfg.groups, gi, -1)) { dirty(); drawLayout(); } }, 'sm ghost', 'upar', 'Move group ' + gr.name + ' up'),
          btn('', function () { if (move(D.cfg.groups, gi, 1)) { dirty(); drawLayout(); } }, 'sm ghost', 'dnar', 'Move group ' + gr.name + ' down'),
          btn('Add widget', function () { editService(D, { id: rid(), name: '', type: 'radarr', icon: '', href: '', url: '', description: '', hidden: false, favorite: false, span: 1, fields: [], options: {} }, gr, true, drawLayout, dirty); }, 'sm', 'plus'),
          btn('', function () { confirmBox('Delete group "' + gr.name + '"?', 'The group and its ' + gr.services.length + ' widgets are removed when you save.', 'Delete', true).then(function (y) { if (y) { D.cfg.groups.splice(gi, 1); dirty(); drawLayout(); } }); }, 'sm ghost danger', 'trash', 'Delete group ' + gr.name)));
        gr.services.forEach(function (s, si) {
          box.appendChild(h('div', { class: 'ls' }, img(iconUrl(s.icon), ''), h('span', { class: 't' }, h('b', null, s.name), h('span', { class: 'muted sm' }, ' · ' + ((D.types[s.type] || [s.type])[0]) + (s.hidden ? ' · hidden' : '') + (s.favorite ? ' · favorite' : ''))),
            btn('', function () { if (move(gr.services, si, -1)) { dirty(); drawLayout(); } }, 'sm ghost', 'upar', 'Move ' + s.name + ' up'),
            btn('', function () { if (move(gr.services, si, 1)) { dirty(); drawLayout(); } }, 'sm ghost', 'dnar', 'Move ' + s.name + ' down'),
            D.cfg.groups.length > 1 ? btn('', function () { var to = D.cfg.groups[gi - 1] || D.cfg.groups[gi + 1]; gr.services.splice(si, 1); to.services.push(s); dirty(); drawLayout(); }, 'sm ghost', 'swap', 'Move ' + s.name + ' to the ' + (gi ? 'previous' : 'next') + ' group') : null,
            btn('', function () { s.hidden = !s.hidden; dirty(); drawLayout(); }, 'sm ghost', s.hidden ? 'eye' : 'eyeoff', (s.hidden ? 'Show ' : 'Hide ') + s.name),
            btn('', function () { editService(D, s, gr, false, drawLayout, dirty); }, 'sm', 'edit', 'Edit ' + s.name),
            btn('', function () { gr.services.splice(si, 1); delete D.svc[s.id]; dirty(); drawLayout(); }, 'sm ghost danger', 'trash', 'Remove ' + s.name)));
        });
        if (!gr.services.length) box.appendChild(h('div', { class: 'ls muted sm' }, 'No widgets in this group.'));
        lay.appendChild(box);
      });
    };
    drawLayout();
    b.appendChild(set('Widgets', 'App widgets shown in the Services section. Credentials are stored on the server and never shown again.', lay,
      btn('Add group', function () { D.cfg.groups.push({ id: rid(), name: 'New group', columns: 4, header: true, services: [] }); dirty(); drawLayout(); }, 'sm', 'plus')));
    var ta = function (ph, lab) { return h('textarea', { rows: 6, placeholder: ph, spellcheck: 'false', 'aria-label': lab }); };
    var ySvc = ta('Paste services.yaml here', 'services.yaml'), ySet = ta('Optional: settings.yaml (columns and group titles)', 'settings.yaml'), yEnv = ta('Optional: HOMEPAGE_VAR_… values from your .env (KEY=value per line)', '.env values');
    var prev = h('div', { style: { 'margin-top': '10px' } });
    b.appendChild(set('Import from Homepage', 'Brings over groups, widgets, icons, links and {{HOMEPAGE_VAR_…}} variables from a gethomepage/homepage config. Only the widgets are replaced; a backup is taken first.',
      h('div', { class: 'grid2' }, ySvc, h('div', null, ySet, h('div', { style: { height: '8px' } }), yEnv)),
      h('div', { style: { 'margin-top': '8px' } }, btn('Preview', function () {
        if (D.dirty) { toast('Save or discard your other changes first.', true); return; }
        api('import_preview', { services: ySvc.value, settings: ySet.value, env: yEnv.value }).then(function (j) {
          clear(prev);
          if (j.error) { prev.appendChild(h('div', { class: 'banner bad' }, j.error)); return; }
          var missing = j.vars.filter(function (v) { return !v.set && !v.stored; });
          prev.appendChild(h('div', { class: 'banner ok' }, j.groups + ' groups and ' + j.services + ' widgets found.'));
          j.warnings.forEach(function (w) { prev.appendChild(h('div', { class: 'banner' }, w)); });
          if (missing.length) prev.appendChild(h('div', { class: 'banner' }, missing.length + ' variables have no value yet (fill them in under Variables afterwards): ' + missing.map(function (v) { return v.name; }).join(', ')));
          prev.appendChild(h('div', { class: 'kv2 sm' }, j.layout.map(function (g) { return [h('span', null, g.name), h('span', null, g.services.join(', '))]; })));
          prev.appendChild(h('div', { style: { 'margin-top': '10px' } }, btn('Import and replace the widgets', function () {
            confirmBox('Replace the widgets?', 'Your current groups and widgets are replaced. Appearance, container and VM preferences and stored variable values are kept. A backup is taken first.', 'Import', true).then(function (y) {
              if (y) act('import_apply', { services: ySvc.value, settings: ySet.value, env: yEnv.value }, 'Imported').then(function (k) { if (!k.error) { D.close(true); reload().then(function () { openSettings('services'); }); } });
            });
          }, 'pri')));
        });
      }, '', 'check')), prev));
  }

  function editService(D, orig, group, isNew, after, dirtyOuter) {
    var s = JSON.parse(JSON.stringify(orig)), sec = JSON.parse(JSON.stringify(D.svc[s.id] || {}));
    if (!s.options || Array.isArray(s.options)) s.options = {};
    var form = h('div'), noop = function () {};
    var draw = function () {
      clear(form);
      var T = D.types[s.type] || ['', [], false];
      var typeSel = h('select', { onchange: function (e) { s.type = e.target.value; s.fields = []; s.options = {}; draw(); } }, Object.keys(D.types).map(function (k) { return h('option', { value: k, selected: k === s.type }, D.types[k][0]); }));
      form.appendChild(h('div', { class: 'grid2' }, F('Name', tIn(s, 'name', noop, T[0])), F('Type', typeSel),
        F('Icon', tIn(s, 'icon', noop, 'sh-radarr, radarr.png or https://…'), 'sh-NAME = selfh.st icons, NAME.png = dashboard-icons, or any URL.'),
        F('Link (opens on click)', tIn(s, 'href', noop, 'http://{{SERVER_IP}}:7878')),
        F('Description', tIn(s, 'description', noop, 'Optional')), F('Width', sIn(s, 'span', noop, [[1, 'Normal'], [2, 'Double']]))));
      form.appendChild(h('div', { class: 'inl' }, cIn(s, 'favorite', noop, 'Favorite'), cIn(s, 'hidden', noop, 'Hidden')));
      if (T[2] || s.type === 'customapi') form.appendChild(F('API URL', tIn(s, 'url', noop, 'http://{{SERVER_IP}}:7878'), 'The address UnDash calls from the server. {{VARIABLES}} work here.'));
      var creds = h('div', { class: 'grid2' });
      (T[1] || []).forEach(function (k) {
        var stored = sec[k] === D.keep;
        var inp = h('input', { type: k === 'username' ? 'text' : 'password', autocomplete: 'off', value: stored ? '' : (sec[k] || ''), placeholder: stored ? 'stored (leave empty to keep)' : (k === 'key' ? 'API key or {{VARIABLE}}' : ''),
          oninput: function (e) { sec[k] = e.target.value === '' && stored ? D.keep : e.target.value; } });
        creds.appendChild(F({ key: s.type === 'plex' ? 'Plex token' : s.type === 'peanut' ? 'Device key' : s.type === 'pihole' ? 'Password / app password' : 'API key', username: 'Username', password: 'Password' }[k],
          h('div', { class: 'sec-in' }, inp, stored ? btn('Clear', function () { sec[k] = ''; stored = false; inp.placeholder = ''; }, 'sm') : null)));
      });
      if (creds.childNodes.length) form.appendChild(creds);
      if (FIELDS[s.type]) form.appendChild(F('Fields to show', h('input', { type: 'text', value: (s.fields || []).join(', '), placeholder: 'all', oninput: function (e) { s.fields = e.target.value.split(/[\s,]+/).filter(Boolean); } }), 'Available: ' + FIELDS[s.type] + '. Empty shows all.'));
      if (s.type === 'calendar') {
        s.options.integrations = s.options.integrations || [];
        var box = h('div');
        D.cfg.groups.forEach(function (g) { g.services.forEach(function (x) {
          if (x.type !== 'radarr' && x.type !== 'sonarr') return;
          var on = s.options.integrations.filter(function (i) { return i.service === x.id; })[0];
          box.appendChild(h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!on, onchange: function (e) {
            s.options.integrations = s.options.integrations.filter(function (i) { return i.service !== x.id; });
            if (e.target.checked) s.options.integrations.push({ service: x.id, unmonitored: true });
          } }), x.name + ' (' + g.name + ')'));
        }); });
        if (!box.childNodes.length) box.appendChild(h('div', { class: 'muted sm' }, 'Add a Radarr or Sonarr widget first.'));
        form.appendChild(F('Sources', box));
        form.appendChild(h('div', { class: 'grid2' }, F('Max events', tIn(s.options, 'max_events', noop, '8')), F('Days ahead', tIn(s.options, 'days_ahead', noop, '30')), F('Days back', tIn(s.options, 'previous_days', noop, '0'))));
      }
      if (s.type === 'tautulli_recent') form.appendChild(h('div', { class: 'grid2' }, F('Media type', tIn(s.options, 'media_type', noop, 'movie, episode, track…')), F('Library section id', tIn(s.options, 'section_id', noop, 'optional')), F('Count', tIn(s.options, 'count', noop, '6'))));
      if (s.type === 'ups') form.appendChild(F('UPS name', tIn(s.options, 'ups', noop, D.cfg.general.ups_name)));
      if (s.type === 'emby' || s.type === 'jellyfin') form.appendChild(cIn(s.options, 'hide_blocks', noop, 'Only show the streams (hide the library counts)'));
      if (s.type === 'customapi') {
        var js = function (k, def, lab) { var t = h('textarea', { rows: 6, spellcheck: 'false', 'aria-label': lab, oninput: function (e) { try { s.options[k] = JSON.parse(e.target.value || def); e.target.style.borderColor = ''; } catch (x) { e.target.style.borderColor = 'var(--bad)'; } } }); t.value = JSON.stringify(s.options[k] || JSON.parse(def), null, 2); return t; };
        form.appendChild(h('div', { class: 'grid2' }, F('Display', sIn(s.options, 'display', noop, [['block', 'Blocks'], ['list', 'List']])), F('Method', tIn(s.options, 'method', noop, 'GET'))));
        form.appendChild(F('Headers (JSON)', js('headers', '{}', 'Headers'), '{{KEY}} is replaced by the API key above, e.g. {"X-Api-Key": "{{KEY}}"}.'));
        form.appendChild(F('Mappings (JSON)', js('mappings', '[]', 'Mappings'), '[{"field": "data.users", "label": "Users", "format": "number"}]. Formats: number, text, bytes, rate, percent; lists can add "additional": {"field": "...", "format": "relativeDate", "scale": 1000}.'));
      }
      var res = h('div', { role: 'status' });
      if (T[2] || s.type === 'calendar' || s.type === 'customapi' || s.type === 'ups') form.appendChild(h('div', { class: 'inl' }, btn('Test', function () {
        clear(res).appendChild(h('div', { class: 'progress' }, h('i')));
        var cfg = JSON.parse(JSON.stringify(D.cfg)), svc = JSON.parse(JSON.stringify(D.svc));
        var gg = cfg.groups.filter(function (x) { return x.id === group.id; })[0];
        if (gg) { var k = gg.services.map(function (x) { return x.id; }).indexOf(s.id); if (k < 0) gg.services.push(s); else gg.services[k] = s; }
        svc[s.id] = sec;
        api('test', { config: cfg, vars: D.vars.filter(function (v) { return v.name; }).map(function (v) { return { name: v.name, value: v.value }; }), svc: svc, id: s.id }).then(function (r) {
          clear(res);
          if (r.error) res.appendChild(h('div', { class: 'banner bad' }, r.error));
          else res.appendChild(h('div', { class: 'banner ok' }, 'It works: ', r.blocks ? r.blocks.map(function (x) { return x.label + ' ' + fmt(x.value, x.fmt); }).join(' · ') : r.list ? r.list.length + ' items' : r.calendar ? r.calendar.length + ' events' : r.streams ? r.streams.length + ' streams' : 'ok'));
        });
      }, '', 'check'), res));
    };
    draw();
    var m = modal(isNew ? 'Add widget to ' + group.name : 'Edit ' + orig.name, form, [btn('Cancel', function () { m.close(); }), btn(isNew ? 'Add' : 'Apply', function () {
      if (!s.name) s.name = (D.types[s.type] || [''])[0];
      s.span = +s.span === 2 ? 2 : 1;
      if (isNew) group.services.push(s); else for (var k in s) orig[k] = s[k];
      D.svc[s.id] = sec; m.close(); dirtyOuter(); after();
    }, 'pri')], true);
  }

  function tabConfig(b, D) {
    if (S.boot.caps.mode === 'api') unraidConn(b); else locPort(b, D);
    configCommon(b, D);
  }
  /* Docker version: where the Unraid API is and its key (the key is never shown again once saved) */
  function unraidConn(b) {
    var box = h('div', null, h('div', { class: 'progress' }, h('i')));
    b.appendChild(set('Unraid connection', 'The Docker version reads the server through the official Unraid API. Create a key in Unraid under Settings > Management Access > API Keys with read access to Info, Array, Disk, Docker and VMs, plus update access to Docker and VMs for the buttons.', box));
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
          res.appendChild(h('div', { class: 'kv2 sm' }, Object.keys(t.access).map(function (k) { return [h('span', null, k), h('span', { class: t.access[k] === 'ok' ? 't-ok' : 't-bad' }, t.access[k] === 'ok' ? 'readable' : t.access[k])]; })));
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
        var a = h('a', { href: URL.createObjectURL(new Blob([JSON.stringify(j, null, 2)], { type: 'application/json' })), download: 'undash-' + kind + '-' + new Date().toISOString().slice(0, 10) + '.json' });
        root.appendChild(a); a.click(); root.removeChild(a);
      });
    };
    b.appendChild(set('Export', null,
      h('div', { class: 'kv2 sm', style: { 'margin-bottom': '10px' } },
        h('span', null, 'Redacted'), h('span', null, 'Layout, sections, metrics, container and VM preferences, theme, accent, refresh settings, custom CSS and non-secret variables. API keys, passwords, tokens and secret variables are removed, and so are passwords or tokens written into URLs. Safe to share.'),
        h('span', null, 'Full'), h('span', null, 'Everything above plus every variable, API key and password: a complete backup. Keep it private.')),
      h('div', { class: 'inl' }, btn('Export redacted', function () { dl('redacted'); }, '', 'download'), btn('Export full', function () {
        confirmBox('Export with secrets?', 'The file contains your API keys and passwords in plain text. Store it somewhere private.', 'Export full').then(function (y) { if (y) dl('full'); });
      }, '', 'download'))));
    var info = h('div', { style: { 'margin-top': '10px' } });
    var file = h('input', { type: 'file', accept: '.json,application/json', 'aria-label': 'Settings file to import', onchange: function () {
      var f = file.files[0]; clear(info); if (!f) return;
      if (f.size > 2097152) { info.appendChild(h('div', { class: 'banner bad' }, 'That file is larger than 2 MB, so it is not an UnDash export.')); return; }
      var rd = new FileReader();
      rd.onload = function () {
        var raw = String(rd.result);
        api('import_check', { file: raw }).then(function (j) {
          clear(info);
          if (j.error) { info.appendChild(h('div', { class: 'banner bad' }, j.error)); return; }
          var s = j.summary;
          info.appendChild(h('div', { class: 'kv2 sm' },
            h('span', null, 'Kind'), h('span', null, s.kind === 'full' ? 'Full export (includes secrets)' : s.kind === 'redacted' ? 'Redacted export (your stored secrets are kept)' : 'Layout file (your stored secrets are kept)'),
            h('span', null, 'Created'), h('span', null, s.created ? new Date(s.created * 1000).toLocaleString() : 'unknown'),
            h('span', null, 'Widgets'), h('span', null, s.groups + ' groups, ' + s.widgets + ' widgets' + (s.skipped ? ' (' + s.skipped + ' of an unknown type skipped)' : '')),
            h('span', null, 'Appearance'), h('span', null, s.theme + ' theme, accent ' + s.accent + ', refresh ' + s.refresh + ' s'),
            h('span', null, 'Custom CSS'), h('span', null, s.css === null ? 'not included (current CSS kept)' : s.css + ' characters'),
            h('span', null, 'Variables'), h('span', null, s.vars + (s.credentials ? ', credentials for ' + s.credentials + ' widgets' : ''))));
          info.appendChild(h('div', { style: { 'margin-top': '10px' } }, btn('Apply this file', function () {
            confirmBox('Replace your settings?', 'The settings above replace the current ones. A backup is taken first; you can restore it below.', 'Apply', true).then(function (y) {
              if (y) act('import_file', { file: raw }, 'Settings imported').then(function (k) { if (!k.error) location.reload(); });
            });
          }, 'pri')));
        });
      };
      rd.readAsText(f);
    } });
    b.appendChild(set('Import', 'Load an UnDash export. You see what it contains before anything changes.', file, info));
    var vbox = h('div', { role: 'status' });
    var bbox = h('div', { class: 'olist' }, h('div', { class: 'empty' }, 'Loading…'));
    b.appendChild(set('Check configuration', 'Looks for widgets without a URL, undefined variables and calendar sources that were removed.',
      btn('Validate', function () {
        clear(vbox).appendChild(h('div', { class: 'progress' }, h('i')));
        api('validate').then(function (j) { clear(vbox); if (j.error) { vbox.appendChild(h('div', { class: 'banner bad' }, j.error)); return; } if (!j.issues.length) vbox.appendChild(h('div', { class: 'banner ok', style: { 'margin-top': '10px' } }, 'No problems found.')); j.issues.forEach(function (x) { vbox.appendChild(h('div', { class: 'banner ' + (x.level === 'bad' ? 'bad' : ''), style: { 'margin-top': '8px' } }, x.msg)); }); });
      }, '', 'check'), vbox));
    b.appendChild(set('Backups', 'Taken automatically before every save, import, restore and reset (the newest 15 are kept in backups/ of the configuration directory).', bbox,
      h('div', { style: { 'margin-top': '10px' } }, btn('Reset to defaults…', function () {
        confirmBox('Reset the dashboard?', 'Widgets, sections and preferences go back to the defaults. Variables and credentials are kept, and a backup is taken first.', 'Reset', true).then(function (y) { if (y) act('reset', {}, 'Reset').then(function (j) { if (!j.error) location.reload(); }); });
      }, 'danger', 'trash'))));
    api('backups').then(function (j) {
      clear(bbox);
      if (!j.backups || !j.backups.length) { bbox.appendChild(h('div', { class: 'empty' }, 'No backups yet.')); return; }
      j.backups.forEach(function (x) {
        var when = new Date(x.created * 1000).toLocaleString();
        bbox.appendChild(h('div', { class: 'oi' }, h('span', { class: 't' }, h('b', null, when), h('span', { class: 'muted sm' }, ' · ' + (x.reason || ''))),
          btn('Restore', function () { confirmBox('Restore this backup?', 'Settings, secrets and custom CSS go back to ' + when + '. The current state is backed up first.', 'Restore', true).then(function (y) { if (y) act('restore', { id: x.id }, 'Restored').then(function (k) { if (!k.error) location.reload(); }); }); }, 'sm', null, 'Restore the backup from ' + when)));
      });
    });
  }

  function reload() {
    return api('boot').then(function (j) { if (j.error) { toast(j.error, true); return; } S.boot = j; S.L = j.layout; S.svc = {}; shell(); refreshNow(); });
  }

  /* ---------- keyboard ---------- */
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '') && !root.querySelector('.modal-bg,.drawer') && R.search) { e.preventDefault(); R.search.focus(); }
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
