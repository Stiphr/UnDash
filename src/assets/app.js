/* UnDash UI. Plain JS, no libraries; every string from the server or an app is inserted as text, never as HTML. */
(function () {
  'use strict';
  var API = window.UND_API || '/plugins/undash/include/api.php';
  var root = document.getElementById('und');
  var S = { tab: 'dash', boot: null, sys: null, svc: {}, docker: null, stats: {}, vms: null, os: null, storage: null, set: null, dirty: false,
            timers: {}, q: '', filter: 'all', busy: {}, full: false };
  var LS = { get: function (k, d) { try { var v = localStorage.getItem('undash.' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
             set: function (k, v) { try { localStorage.setItem('undash.' + k, JSON.stringify(v)); } catch (e) { /* private window */ } } };

  /* ---------- DOM helpers ---------- */
  function h(tag, p) {
    var e = document.createElement(tag); p = p || {};
    for (var k in p) {
      if (k === 'class') e.className = p[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), p[k]);
      else if (k === 'value') e.value = p[k];
      else if (k === 'checked' || k === 'disabled' || k === 'selected') e[k] = !!p[k];
      else if (k === 'style' && typeof p[k] === 'object') { for (var s in p[k]) e.style.setProperty(s, p[k][s]); }
      else if (p[k] !== null && p[k] !== undefined && p[k] !== false) e.setAttribute(k, p[k]);
    }
    for (var i = 2; i < arguments.length; i++) add(e, arguments[i]);
    return e;
  }
  function add(e, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(e, x); }); return; }
    e.appendChild(c.nodeType ? c : document.createTextNode(String(c)));
  }
  function clear(e) { while (e.firstChild) e.removeChild(e.firstChild); return e; }
  var SVGNS = 'http://www.w3.org/2000/svg';
  /* small line icons (24x24, stroke) */
  var IC = {
    cpu: 'M9 3v2M15 3v2M9 19v2M15 19v2M3 9h2M3 15h2M19 9h2M19 15h2M7 5h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM9 9h6v6H9z',
    mem: 'M3 7h18v10H3zM7 7v10M11 7v10M15 7v10M5 17v3M19 17v3',
    disk: 'M4 15l2-9h12l2 9M4 15h16v4H4zM17 17h.01',
    net: 'M12 3v5M5 13v-2h14v2M5 13v3M19 13v3M12 8v8M3 16h4v5H3zM10 16h4v5h-4zM17 16h4v5h-4z',
    temp: 'M14 14.8V5a2 2 0 0 0-4 0v9.8a4 4 0 1 0 4 0zM12 9v8',
    clock: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2',
    play: 'M7 4l13 8-13 8z', pause: 'M7 4h4v16H7zM14 4h4v16h-4z', stop: 'M6 6h12v12H6z',
    restart: 'M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7', logs: 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h5',
    up: 'M12 19V5M5 12l7-7 7 7', web: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18',
    moon: 'M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z', check: 'M5 12l4 4 10-10', power: 'M12 3v8M6.3 6.3a8 8 0 1 0 11.4 0',
    full: 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5', screen: 'M3 4h18v12H3zM8 20h8M12 16v4', edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
    trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13', upar: 'M12 5l-6 6h12z', dnar: 'M12 19l6-6H6z', plus: 'M12 5v14M5 12h14', x: 'M6 6l12 12M18 6L6 18'
  };
  function ic(n) {
    var s = document.createElementNS(SVGNS, 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', '1.8'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
    var p = document.createElementNS(SVGNS, 'path'); p.setAttribute('d', IC[n] || ''); if (n === 'play' || n === 'pause' || n === 'stop') p.setAttribute('fill', 'currentColor');
    s.appendChild(p); return s;
  }
  function btn(label, onclick, cls, icon, title) { return h('button', { type: 'button', class: 'btn ' + (cls || ''), onclick: onclick, title: title || null }, icon ? ic(icon) : null, label); }

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
  function upt(s) { var d = Math.floor(s / 86400), hh = Math.floor(s % 86400 / 3600), mm = Math.floor(s % 3600 / 60); return d ? d + 'd' + hh + 'h' : hh ? hh + 'h' + mm + 'm' : mm + 'm'; }
  function fmt(v, f) {
    if (f === 'bytes') return bytes(v); if (f === 'rate') return rate(v); if (f === 'ms') return num(v) + ' ms';
    if (f === 'pct') return (v === null || v === undefined ? '-' : (Math.round(v * 10) / 10) + '%'); if (f === 'dur') return dur(v);
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
  function iconUrl(i) {
    if (!i) return '';
    if (/^(https?:)?\/\//.test(i) || i[0] === '/') return i;
    var m;
    if ((m = /^sh-(.+?)(\.(svg|png|webp))?$/.exec(i))) return 'https://cdn.jsdelivr.net/gh/selfhst/icons@main/' + (m[3] || 'svg') + '/' + m[1] + '.' + (m[3] || 'svg');
    if (/^(mdi|si)-/.test(i)) return '';
    if ((m = /^(.+)\.(png|svg|webp)$/.exec(i))) return 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/' + m[2] + '/' + i;
    return 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/' + i + '.png';
  }
  function img(src, cls) { return src ? h('img', { class: cls || 'ico', src: src, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', onerror: function () { this.style.visibility = 'hidden'; } }) : null; }
  function toast(msg, bad) { var t = h('div', { class: 'toast' + (bad ? ' bad' : ''), role: 'status' }, msg); root.appendChild(t); setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, bad ? 7000 : 3500); }

  /* ---------- API ---------- */
  function api(action, data) {
    var fd = new URLSearchParams(); fd.append('action', action);   // urlencoded: multipart POSTs hang on Unraid's web server
    fd.append('csrf_token', window.UND_CSRF || '');
    for (var k in (data || {})) fd.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]);
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { error: 'Unexpected response (' + r.status + ')' }; }).then(function (j) { if (!r.ok && !j.error) j.error = 'Request failed (' + r.status + ')'; return j; });
    }).catch(function () { return { error: 'Could not reach the server.' }; });
  }
  function act(action, data, okMsg) { return api(action, data).then(function (j) { if (j.error) toast(j.error, true); else if (okMsg) toast(okMsg); return j; }); }

  function modal(title, body, foot, wide) {
    var bg = h('div', { class: 'modal-bg', onclick: function (e) { if (e.target === bg) close(); } });
    var m = h('div', { class: 'modal' + (wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true' }, h('h3', null, title), body, foot ? h('div', { class: 'foot' }, foot) : null);
    function close() { if (bg.parentNode) bg.parentNode.removeChild(bg); document.removeEventListener('keydown', esc); if (m.onclose) m.onclose(); }
    function esc(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', esc);
    bg.appendChild(m); root.appendChild(bg); m.close = close; return m;
  }
  function confirmBox(title, text, okLabel, danger) {
    return new Promise(function (res) {
      var done = false, m;
      var ok = btn(okLabel || 'OK', function () { done = true; m.close(); res(true); }, danger ? 'danger' : 'pri');
      m = modal(title, h('p', null, text), [btn('Cancel', function () { m.close(); }), ok]);
      m.onclose = function () { if (!done) res(false); };
      ok.focus();
    });
  }

  /* ---------- shell ---------- */
  var TABS = [['dash', 'Dashboard'], ['docker', 'Docker'], ['vms', 'VMs'], ['system', 'System'], ['settings', 'Settings']];

  function applyLook() {
    var g = S.boot.layout.general;
    root.setAttribute('data-theme', g.theme || 'dark');
    root.classList.toggle('has-bg', !!g.background);
    root.classList.toggle('full', S.full);
  }

  function shell() {
    clear(root); applyLook();
    var g = S.boot.layout.general;
    if (g.background) root.appendChild(h('div', { class: 'und-bg', style: { 'background-image': 'url("' + g.background.replace(/["\\]/g, '') + '")', filter: 'blur(' + g.blur + 'px) brightness(' + g.brightness + '%)', inset: '-' + (g.blur * 2) + 'px' } }));
    var os = h('div', { class: 'actions', id: 'und-hd-r' });
    root.appendChild(h('div', { class: 'head' },
      h('div', { class: 'brand' }, logo(), h('div', null, h('div', { class: 'und-name' }, g.title || 'UnDash'), h('div', { class: 'ver' }, (S.boot.server.name || 'Unraid') + ' · UnDash ' + S.boot.version))),
      os));
    var tabs = h('div', { class: 'tabs', role: 'tablist' });
    TABS.forEach(function (t) { tabs.appendChild(h('button', { type: 'button', role: 'tab', class: 'tab' + (S.tab === t[0] ? ' on' : ''), 'aria-selected': S.tab === t[0] ? 'true' : 'false', onclick: function () { go(t[0]); } }, t[1])); });
    root.appendChild(tabs);
    root.appendChild(h('div', { id: 'und-body' }));
    headRight();
  }
  function logo() {
    var s = document.createElementNS(SVGNS, 'svg'); s.setAttribute('viewBox', '0 0 64 64'); s.setAttribute('class', 'logo'); s.setAttribute('aria-hidden', 'true');
    s.innerHTML = '<rect width="64" height="64" rx="14" fill="#f15a2c"/><rect x="12" y="12" width="18" height="24" rx="4" fill="#fff"/><rect x="34" y="12" width="18" height="12" rx="4" fill="#fff" opacity=".85"/><rect x="34" y="28" width="18" height="24" rx="4" fill="#fff"/><rect x="12" y="40" width="18" height="12" rx="4" fill="#fff" opacity=".85"/>';
    return s;
  }
  function headRight() {
    var el = document.getElementById('und-hd-r'); if (!el) return; clear(el);
    var o = S.sys && S.sys.os_update;
    if (o && o.staged) el.appendChild(h('span', { class: 'pill up', onclick: function () { go('system'); }, title: 'Reboot to finish the update' }, 'Unraid ' + o.staged + ' installed · reboot to finish'));
    else if (o && o.available) el.appendChild(h('span', { class: 'pill up', onclick: function () { go('system'); } }, ic('up'), 'Unraid ' + o.latest + ' available'));
    else if (o && o.installed) el.appendChild(h('span', { class: 'pill ok', title: o.checked ? 'Checked ' + rel(o.checked) : '' }, 'Unraid ' + o.installed));
    el.appendChild(btn(S.full ? 'Exit focus' : 'Focus', function () { S.full = !S.full; LS.set('full', S.full); applyLook(); headRight(); }, 'sm', 'full', 'Full-window dashboard'));
  }

  function go(tab) {
    if (S.tab === 'settings' && tab !== 'settings' && S.dirty && !window.confirm('Leave Settings without saving your changes?')) return;
    if (tab !== 'settings') { S.dirty = false; S.set = null; }
    S.tab = tab; try { history.replaceState(null, '', '#' + tab); } catch (e) { /* ignore */ }
    stopTimers(); shell(); render();
  }
  function stopTimers() { for (var k in S.timers) { clearInterval(S.timers[k]); clearTimeout(S.timers[k]); } S.timers = {}; }
  function every(name, sec, fn) { S.timers[name] = setInterval(function () { if (!document.hidden) fn(); }, Math.max(2, sec) * 1000); }
  function body() { return document.getElementById('und-body'); }

  function render() {
    var b = body(); if (!b) return; clear(b);
    if (S.tab === 'dash') return renderDash(b);
    if (S.tab === 'docker') return renderDocker(b);
    if (S.tab === 'vms') return renderVms(b);
    if (S.tab === 'system') return renderSystem(b);
    if (S.tab === 'settings') return renderSettings(b);
  }

  /* ================= Dashboard ================= */
  var NATIVE = { system_info: 1, cpu: 1, memory: 1, link: 1 };

  function renderDash(b) {
    var L = S.boot.layout, g = L.general;
    var rb = h('div', { class: 'rbar', id: 'und-rbar' }); if (g.show_resources || g.show_date || g.show_search) b.appendChild(rb);
    var closed = LS.get('closed', {});
    L.groups.forEach(function (gr) {
      var links = gr.services.length && gr.services.every(function (s) { return s.type === 'link'; });
      var grid = h('div', { class: 'grid' + (links ? ' links' : ''), style: { '--cols': String(gr.columns) } });
      var wrap = h('div', { class: 'grp' + (closed[gr.id] ? ' closed' : '') });
      if (gr.header) wrap.appendChild(h('h2', { onclick: function () { closed[gr.id] = !closed[gr.id]; LS.set('closed', closed); wrap.classList.toggle('closed'); } }, gr.name, h('span', { class: 'chev' }, '▾')));
      gr.services.forEach(function (s) { grid.appendChild(card(s)); });
      wrap.appendChild(grid); b.appendChild(wrap);
    });
    if (!L.groups.length) b.appendChild(h('div', { class: 'banner' }, 'Nothing on the dashboard yet. Add widgets or import your Homepage config in ', h('a', { href: '#settings', onclick: function (e) { e.preventDefault(); go('settings'); } }, 'Settings'), '.'));
    drawRbar(); paintNative();
    pollSys(); every('sys', g.refresh_system, pollSys);
    pollServices(); every('svc', g.refresh_services, pollServices);
  }

  function card(s) {
    var u = iconUrl(s.icon), href = s.href_resolved || '';
    if (s.type === 'link') {
      return h('div', { class: 'card link', onclick: function () { if (href) window.open(href, '_blank', 'noopener'); }, title: href },
        h('div', { class: 'ch' }, img(u), h('span', { class: 'nm' }, s.name)));
    }
    var nm = href ? h('a', { class: 'nm', href: href, target: '_blank', rel: 'noopener' }, s.name) : h('span', { class: 'nm' }, s.name);
    var c = h('div', { class: 'card' + (NATIVE[s.type] ? '' : ' wait'), id: 'w-' + s.id, 'data-type': s.type },
      h('div', { class: 'ch' }, img(u), nm, h('span', { class: 'sub' })), h('div', { class: 'cb' }));
    var prev = S.svc[s.id]; if (prev) setTimeout(function () { paintService(s, prev); }, 0);
    return c;
  }

  function pollSys() {
    return api('system').then(function (j) { if (j.error) return; S.sys = j; drawRbar(); paintNative(); headRight(); });
  }

  /* services are fetched in parallel, at most 6 at a time (the browser's per-host limit) */
  function pollServices() {
    var list = [];
    S.boot.layout.groups.forEach(function (g) { g.services.forEach(function (s) { if (!NATIVE[s.type]) list.push(s); }); });
    var i = 0;
    function next() {
      if (i >= list.length || S.tab !== 'dash') return Promise.resolve();
      var s = list[i++];
      return api('service', { id: s.id }).then(function (r) { S.svc[s.id] = r; paintService(s, r); }).then(next);
    }
    for (var k = 0; k < 6; k++) next();
  }

  function cardBody(id) { var c = document.getElementById('w-' + id); if (!c) return null; c.classList.remove('wait'); return c.querySelector('.cb'); }

  function blocks(list) {
    return h('div', { class: 'blocks' }, list.map(function (x) { return h('div', { class: 'blk' }, h('div', { class: 'v' }, fmt(x.value, x.fmt)), h('div', { class: 'l' }, x.label)); }));
  }

  function paintService(s, r) {
    var cb = cardBody(s.id); if (!cb) return; clear(cb);
    var sub = cb.parentNode.querySelector('.ch .sub'); if (sub) sub.textContent = '';
    if (r.error) {
      var e = h('div', { class: 'err' + (r.unconfigured ? ' cfg' : ''), onclick: function () { e.classList.toggle('open'); } }, r.unconfigured ? 'Not configured' : 'API error', h('div', { class: 'x' }, r.error));
      cb.appendChild(e); return;
    }
    if (r.blocks && r.blocks.length) cb.appendChild(blocks(r.blocks));
    if (r.list) {
      var l = h('div', { class: 'lst' });
      r.list.forEach(function (x) { l.appendChild(h('div', { class: 'r' }, x.label ? h('span', { class: 'lab' }, x.label) : null, h('span', { class: 't', title: x.title }, x.title), x.ts ? h('span', { class: 'w' }, rel(x.ts)) : (x.right ? h('span', { class: 'w' }, x.right) : null))); });
      if (!r.list.length) l.appendChild(h('div', { class: 'empty' }, 'Nothing yet'));
      cb.appendChild(l);
    }
    if (r.calendar) {
      var cal = h('div', { class: 'cal' }), last = '';
      r.calendar.forEach(function (x) {
        var d = day(x.ts);
        cal.appendChild(h('span', { class: 'd' }, d === last ? '' : d)); last = d;
        cal.appendChild(h('span', { class: 'ti', title: x.title + (x.sub ? ' ' + x.sub : '') }, x.title, x.sub && s.options && s.options.show_episode ? h('span', { class: 's' }, x.sub) : null));
        cal.appendChild(h('span', { class: 'ok', title: x.done ? 'Downloaded' : '' }, x.done ? ic('check') : ''));
      });
      if (!r.calendar.length) cb.appendChild(h('div', { class: 'empty' }, r.empty || 'Nothing coming up')); else cb.appendChild(cal);
    }
    if (r.streams) {
      if (!r.streams.length) cb.appendChild(h('div', { class: 'empty' }, r.empty || 'No active streams'));
      else {
        var st = h('div', { class: 'streams' });
        r.streams.forEach(function (x) {
          st.appendChild(h('div', { class: 'stream', title: x.title + (x.user ? ' (' + x.user + ')' : '') + (x.transcode ? ' · ' + x.transcode : '') },
            h('i', { class: 'pg', style: { width: (x.dur ? Math.min(100, 100 * x.pos / x.dur) : 0) + '%' } }),
            h('span', { class: 'st' }, ic(x.state === 'paused' ? 'pause' : 'play')),
            h('span', { class: 'tt' }, x.title + (x.user ? ' (' + x.user + ')' : '')),
            h('span', { class: 'tm' }, x.dur ? dur(x.pos) + ' / ' + dur(x.dur) : '')));
        });
        cb.appendChild(st);
      }
    }
    if (r.bars) {
      var bars = h('div', { class: 'bars' });
      r.bars.forEach(function (x) { var p = x.size ? 100 * x.used / x.size : 0; bars.appendChild(h('div', null, h('div', { class: 'bt' }, h('span', null, x.title, x.sub ? h('span', { class: 'muted' }, ' · ' + x.sub) : null), h('span', null, bytes(x.size - x.used) + ' free of ' + bytes(x.size))), h('div', { class: 'meter' }, h('i', { class: p > 90 ? 'bad' : p > 75 ? 'warn' : '', style: { width: p.toFixed(1) + '%' } })))); });
      cb.appendChild(bars);
    }
    if (r.link) { var a = cb.parentNode.querySelector('.ch .nm'); if (a && a.tagName !== 'A') { a.style.cursor = 'pointer'; a.onclick = function () { go(r.link); }; } }
  }

  function meter(pct, hot) { return h('div', { class: 'meter' }, h('i', { class: hot ? 'hot' : '', style: { width: Math.max(0, Math.min(100, pct || 0)).toFixed(1) + '%' } })); }
  function res(icon, val, lab, pct, title) { return h('div', { class: 'res', title: title || '' }, ic(icon), h('div', { class: 'rv' }, h('div', { class: 't' }, h('span', null, val), h('span', null, lab)), pct === null ? null : meter(pct, pct > 85))); }

  function drawRbar() {
    var rb = document.getElementById('und-rbar'); if (!rb) return; clear(rb);
    var g = S.boot.layout.general, y = S.sys;
    if (g.show_resources && y) {
      rb.appendChild(res('cpu', y.cpu.pct === null ? '-' : Math.round(y.cpu.pct) + '%', 'CPU', y.cpu.pct, y.cpu.model));
      rb.appendChild(res('mem', bytes(y.mem.free), 'Free', y.mem.pct, bytes(y.mem.used) + ' used of ' + bytes(y.mem.total)));
      if (y.disk) rb.appendChild(res('disk', bytes(y.disk.free), 'Free', y.disk.pct, y.disk.path + ': ' + bytes(y.disk.used) + ' used of ' + bytes(y.disk.total)));
      rb.appendChild(res('net', rate(y.net.tx) + ' ↑', rate(y.net.rx) + ' ↓', y.net.rx === null ? 0 : Math.min(100, 100 * Math.log10(1 + y.net.rx) / 9), y.net.iface));
      if (y.cpu.temp !== null) rb.appendChild(res('temp', Math.round(y.cpu.temp) + '°C', 'Temp', y.cpu.temp, 'CPU package temperature'));
      rb.appendChild(res('clock', upt(y.uptime), 'Up', Math.min(100, y.uptime / 86400 / 30 * 100), 'Uptime'));
    }
    if (g.show_date) rb.appendChild(h('div', { class: 'date' }, new Date().toLocaleDateString([], { dateStyle: 'long' })));
    if (g.show_search) {
      var E = { duckduckgo: 'https://duckduckgo.com/?q=', google: 'https://www.google.com/search?q=', bing: 'https://www.bing.com/search?q=', startpage: 'https://www.startpage.com/do/search?q=', brave: 'https://search.brave.com/search?q=' };
      var inp = h('input', { type: 'search', placeholder: 'Search…', 'aria-label': 'Search the web', value: S.q || '' });
      rb.appendChild(h('form', { onsubmit: function (e) { e.preventDefault(); if (inp.value.trim()) window.open(E[g.search] + encodeURIComponent(inp.value.trim()), '_blank', 'noopener'); } }, inp));
    }
  }

  function spark(vals, max) {
    var s = document.createElementNS(SVGNS, 'svg'); s.setAttribute('class', 'spark'); s.setAttribute('viewBox', '0 0 100 40'); s.setAttribute('preserveAspectRatio', 'none');
    if (vals.length < 2) return s;
    var n = vals.length, pts = vals.map(function (v, i) { return [i / (n - 1) * 100, 40 - Math.max(0, Math.min(1, v / max)) * 38]; });
    var d = 'M' + pts.map(function (p) { return p[0].toFixed(2) + ',' + p[1].toFixed(2); }).join(' L');
    s.innerHTML = '<defs><linearGradient id="und-g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f15a2c" stop-opacity=".35"/><stop offset="1" stop-color="#f15a2c" stop-opacity="0"/></linearGradient></defs>';
    var a = document.createElementNS(SVGNS, 'path'); a.setAttribute('class', 'a'); a.setAttribute('d', d + ' L100,40 L0,40 Z');
    var l = document.createElementNS(SVGNS, 'path'); l.setAttribute('class', 'l'); l.setAttribute('d', d); l.setAttribute('vector-effect', 'non-scaling-stroke');
    s.appendChild(a); s.appendChild(l); return s;
  }

  function paintNative() {
    var y = S.sys;
    S.boot.layout.groups.forEach(function (g) {
      g.services.forEach(function (s) {
        if (!NATIVE[s.type] || s.type === 'link') return;
        var cb = cardBody(s.id); if (!cb) return;
        var c = cb.parentNode, sub = c.querySelector('.ch .sub');
        if (!y) { clear(cb).appendChild(h('div', { class: 'empty' }, 'Loading…')); return; }
        clear(cb); var old = c.querySelector('.spark'); if (old) old.parentNode.removeChild(old);
        if (s.type === 'system_info') {
          sub.textContent = y.cpu.model;
          var o = y.os_update;
          cb.appendChild(h('div', { class: 'sysinfo' },
            h('div', null, h('div', null, 'Unraid OS ' + y.os), h('div', null, y.kernel), h('div', null, y.name + (y.array.state ? ' · array ' + y.array.state.toLowerCase() : '')),
              o && o.available ? h('div', null, h('a', { href: '#system', onclick: function (e) { e.preventDefault(); go('system'); } }, 'Update to ' + o.latest)) : null),
            h('div', { style: { 'text-align': 'right' } }, h('div', null, h('span', { class: 'k' }, 'CPU'), ' ', Math.round(y.cpu.pct || 0) + '%'), h('div', null, h('span', { class: 'k' }, 'Mem'), ' ', Math.round(y.mem.pct || 0) + '%'),
              y.cpu.temp !== null ? h('div', null, h('span', { class: 'k' }, 'Temp'), ' ', Math.round(y.cpu.temp) + '°C') : null)));
        } else if (s.type === 'cpu') {
          c.insertBefore(spark(y.hist.map(function (x) { return x[1]; }), 100), cb);
          cb.appendChild(h('div', { class: 'chart-lbl' }, h('span', null, y.cpu.model ? y.cpu.model.replace(/\s*@.*$/, '') : y.cpu.threads + ' threads'), h('span', null, h('b', null, Math.round(y.cpu.pct || 0) + '%'), ' Used')));
        } else if (s.type === 'memory') {
          c.insertBefore(spark(y.hist.map(function (x) { return x[2]; }), y.mem.total || 1), cb);
          cb.appendChild(h('div', { class: 'chart-lbl' }, h('span', null, bytes(y.mem.free) + ' Free', h('br'), bytes(y.mem.total) + ' Total'), h('span', null, h('b', null, bytes(y.mem.used)), ' Used')));
        }
      });
    });
  }

  /* ================= Docker ================= */
  function renderDocker(b) {
    b.appendChild(h('div', { id: 'dk-sum' }));
    var q = h('input', { type: 'search', placeholder: 'Filter containers…', value: S.q, oninput: function () { S.q = q.value; drawDocker(); } });
    var f = h('select', { onchange: function () { S.filter = f.value; drawDocker(); } }, [['all', 'All'], ['running', 'Running'], ['stopped', 'Stopped'], ['updates', 'Updates available']].map(function (o) { return h('option', { value: o[0], selected: S.filter === o[0] }, o[1]); }));
    b.appendChild(h('div', { class: 'tools' }, q, f, h('span', { class: 'grow' }),
      btn('Check for updates', checkUpdates, '', 'restart', 'Ask the registries for newer images (Unraid\'s own check)'),
      h('span', { id: 'dk-upall' })));
    b.appendChild(h('div', { id: 'dk-tbl' }, h('div', { class: 'progress' }, h('i'))));
    loadDocker(); every('docker', 10, loadDocker);
    loadStats(); every('stats', 12, loadStats);
  }
  function loadDocker() { return api('docker').then(function (j) { S.docker = j; drawDocker(); }); }
  function loadStats() { return api('docker_stats').then(function (j) { if (!j.error) { S.stats = j.stats || {}; drawDocker(); } }); }

  function drawDocker() {
    var t = document.getElementById('dk-tbl'); if (!t || !S.docker) return;
    if (S.docker.error) { clear(t).appendChild(h('div', { class: 'banner bad' }, S.docker.error)); return; }
    var all = S.docker.containers, run = 0, upd = [];
    all.forEach(function (c) { if (c.state === 'running') run++; if (c.update === 'available') upd.push(c); });
    var sum = clear(document.getElementById('dk-sum'));
    sum.appendChild(h('div', { class: 'cards' }, stat('Containers', all.length), stat('Running', run), stat('Stopped', all.length - run), stat('Updates', upd.length, upd.length > 0)));
    var ua = clear(document.getElementById('dk-upall'));
    if (upd.length) ua.appendChild(btn('Update all (' + upd.length + ')', function () { updateMany(upd.map(function (c) { return c.name; })); }, 'pri', 'up'));
    var q = (S.q || '').toLowerCase();
    var rows = all.filter(function (c) {
      if (q && (c.name + ' ' + c.image).toLowerCase().indexOf(q) < 0) return false;
      if (S.filter === 'running') return c.state === 'running';
      if (S.filter === 'stopped') return c.state !== 'running';
      if (S.filter === 'updates') return c.update === 'available';
      return true;
    });
    var tb = h('tbody');
    rows.forEach(function (c) { tb.appendChild(dockerRow(c)); });
    if (!rows.length) tb.appendChild(h('tr', null, h('td', { colspan: '6', class: 'muted' }, 'No containers match.')));
    clear(t).appendChild(h('div', { class: 'panel', style: { padding: '0', overflow: 'auto' } }, h('table', { class: 't' },
      h('thead', null, h('tr', null, h('th', null, 'Container'), h('th', null, 'State'), h('th', null, 'Version'), h('th', null, 'CPU / Memory'), h('th', null, 'Uptime'), h('th', { style: { 'text-align': 'right' } }, 'Actions'))), tb)));
  }
  function stat(k, v, hot) { return h('div', { class: 'stat' + (hot ? ' hot' : '') }, h('div', { class: 'k' }, k), h('div', { class: 'v' }, v)); }
  function stateBadge(st, health) {
    var m = { running: ['Started', 'ok'], exited: ['Stopped', 'bad'], paused: ['Paused', 'warn'], restarting: ['Restarting', 'warn'], created: ['Created', ''], dead: ['Dead', 'bad'] }[st] || [st, ''];
    return h('span', null, h('span', { class: 'badge ' + m[1] }, m[0]), health && health !== 'healthy' ? h('span', { class: 'badge ' + (health === 'unhealthy' ? 'bad' : 'warn'), style: { 'margin-left': '4px' } }, health) : null);
  }
  function dockerRow(c) {
    var busy = S.busy['d:' + c.name] || c.job === 'running';
    var st = S.stats[c.name];
    var a = h('td', { class: 'acts' });
    if (c.webui && c.state === 'running') a.appendChild(h('a', { class: 'btn ic sm', href: c.webui, target: '_blank', rel: 'noopener', title: 'Open WebUI' }, ic('web')));
    if (c.state === 'running') {
      a.appendChild(btn('', function () { dAct(c, 'restart'); }, 'ic sm', 'restart', 'Restart'));
      a.appendChild(btn('', function () { dAct(c, 'pause'); }, 'ic sm', 'pause', 'Pause'));
      a.appendChild(btn('', function () { dAct(c, 'stop'); }, 'ic sm danger', 'stop', 'Stop'));
    } else if (c.state === 'paused') {
      a.appendChild(btn('', function () { dAct(c, 'unpause'); }, 'ic sm okc', 'play', 'Resume'));
      a.appendChild(btn('', function () { dAct(c, 'stop'); }, 'ic sm danger', 'stop', 'Stop'));
    } else a.appendChild(btn('', function () { dAct(c, 'start'); }, 'ic sm okc', 'play', 'Start'));
    a.appendChild(btn('', function () { logs(c.name); }, 'ic sm', 'logs', 'Logs'));
    if (c.update === 'available' || c.job === 'running') a.appendChild(btn(c.job === 'running' ? 'Updating…' : 'Update', function () { updateOne(c.name); }, 'sm pri', 'up', 'Pull the new image and recreate the container from its Unraid template'));
    a.querySelectorAll('button').forEach(function (x) { if (busy && !/Updating/.test(x.textContent)) x.disabled = true; });
    var ver = c.update === 'available' ? h('span', { class: 'badge acc' }, 'Update available') : c.update === 'current' ? h('span', { class: 'badge ok' }, 'Up to date') : h('span', { class: 'badge', title: c.managed ? 'Not checked yet' : 'Not managed by an Unraid template' }, c.managed ? 'Not checked' : 'Not managed');
    return h('tr', null,
      h('td', { class: 'name', 'data-l': 'Container' }, h('div', { class: 'nm' }, c.icon ? img(c.icon, '') : h('span', { class: 'ph' }), c.name, c.autostart ? h('span', { class: 'badge info', title: 'Starts with the array' }, 'auto') : null), h('div', { class: 'im' }, c.image)),
      h('td', null, stateBadge(c.state, c.health)),
      h('td', null, ver),
      h('td', { class: 'num' }, st ? st.cpu.toFixed(1) + '% · ' + st.mem : c.state === 'running' ? '…' : '-'),
      h('td', { class: 'num' }, c.state === 'running' && c.started ? rel(c.started).replace(' ago', '') : '-'),
      a);
  }
  function dAct(c, op) {
    var go2 = function () {
      S.busy['d:' + c.name] = true; drawDocker();
      act('docker_action', { name: c.name, op: op }, c.name + ': ' + op + ' done').then(function () { delete S.busy['d:' + c.name]; loadDocker(); });
    };
    if (op === 'stop') confirmBox('Stop ' + c.name + '?', 'The container will be stopped.', 'Stop', true).then(function (y) { if (y) go2(); }); else go2();
  }
  function checkUpdates() {
    act('docker_check', {}, 'Checking for updates…').then(function () {
      var n = 0, t = setInterval(function () {
        api('docker_job', {}).then(function (j) { if (j.state !== 'running' || ++n > 120) { clearInterval(t); toast('Update check finished'); loadDocker(); } });
      }, 3000);
      S.timers.chk = t;
    });
  }
  function updateOne(name) { return updateMany([name]); }
  function updateMany(names) {
    var pre = h('pre', { class: 'log wrap' }), prog = h('div', { class: 'progress' }, h('i')), status = h('div', { class: 'muted sm' });
    var closeBtn = btn('Close', function () { m.close(); });
    var m = modal('Updating ' + (names.length === 1 ? names[0] : names.length + ' containers'), h('div', null, status, prog, pre), [closeBtn], true);
    var i = 0, out = '';
    function next() {
      if (i >= names.length) { prog.style.display = 'none'; status.textContent = 'Finished.'; loadDocker(); return; }
      var n = names[i++];
      status.textContent = 'Updating ' + n + ' (' + i + ' of ' + names.length + ')…';
      api('docker_action', { name: n, op: 'update' }).then(function (j) {
        if (j.error) { out += '\n[' + n + '] ' + j.error + '\n'; pre.textContent = out; return next(); }
        var t = setInterval(function () {
          api('docker_job', { name: n }).then(function (k) {
            pre.textContent = out + '[' + n + ']\n' + (k.out || ''); pre.scrollTop = pre.scrollHeight;
            if (k.state !== 'running') { clearInterval(t); out = pre.textContent + '\n'; next(); }
          });
        }, 1500);
        m.onclose = function () { clearInterval(t); loadDocker(); };
      });
    }
    next();
  }

  function logs(name) {
    var lines = h('select', null, [100, 300, 1000, 5000].map(function (n) { return h('option', { value: n, selected: n === 300 }, n + ' lines'); }));
    var find = h('input', { type: 'search', placeholder: 'Highlight…' });
    var follow = h('input', { type: 'checkbox', checked: true }), wrap = h('input', { type: 'checkbox', checked: LS.get('wrap', true) });
    var pre = h('pre', { class: 'log' + (wrap.checked ? ' wrap' : '') }, 'Loading…');
    var text = '';
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
    function load() { return api('docker_logs', { name: name, lines: lines.value }).then(function (j) { text = j.error ? 'Error: ' + j.error : j.log; paint(); }); }
    lines.onchange = load; find.oninput = paint; wrap.onchange = function () { LS.set('wrap', wrap.checked); pre.classList.toggle('wrap', wrap.checked); };
    var dl = btn('Download', function () { var a = h('a', { href: URL.createObjectURL(new Blob([text], { type: 'text/plain' })), download: name + '.log' }); root.appendChild(a); a.click(); root.removeChild(a); }, 'sm');
    var m = modal(h('span', null, 'Logs · ', name), h('div', null,
      h('div', { class: 'tools' }, lines, find, h('label', { class: 'switch' }, follow, 'Live'), h('label', { class: 'switch' }, wrap, 'Wrap'), h('span', { class: 'grow' }), btn('Refresh', load, 'sm', 'restart'), dl), pre),
      [btn('Close', function () { m.close(); })], true);
    var t = setInterval(function () { if (follow.checked && !document.hidden) load(); }, 3000);
    m.onclose = function () { clearInterval(t); };
    load();
  }

  /* ================= VMs ================= */
  function renderVms(b) {
    b.appendChild(h('div', { id: 'vm-tbl' }, h('div', { class: 'progress' }, h('i'))));
    loadVms(); every('vms', 8, loadVms);
  }
  function loadVms() { return api('vms').then(function (j) { S.vms = j; drawVms(); }); }
  function vmBadge(s) {
    var m = { running: ['Started', 'ok'], 'shut off': ['Stopped', 'bad'], paused: ['Paused', 'warn'], pmsuspended: ['Hibernated', 'warn'], 'in shutdown': ['Shutting down', 'warn'], crashed: ['Crashed', 'bad'] }[s] || [s, ''];
    return h('span', { class: 'badge ' + m[1] }, m[0]);
  }
  function drawVms() {
    var t = document.getElementById('vm-tbl'); if (!t || !S.vms) return;
    if (S.vms.error) { clear(t).appendChild(h('div', { class: 'banner bad' }, S.vms.error)); return; }
    var tb = h('tbody');
    S.vms.vms.forEach(function (v) {
      var a = h('td', { class: 'acts' }), busy = S.busy['v:' + v.name];
      if (v.state === 'running') {
        if (v.vnc) a.appendChild(h('a', { class: 'btn ic sm', href: '/plugins/dynamix.vm.manager/vnc.html?autoconnect=true&resize=scale&host=' + encodeURIComponent(location.hostname) + '&port=' + encodeURIComponent(location.port) + '&path=' + encodeURIComponent(v.vnc), target: '_blank', rel: 'noopener', title: 'VNC console' }, ic('screen')));
        a.appendChild(btn('', function () { vAct(v, 'restart'); }, 'ic sm', 'restart', 'Restart'));
        a.appendChild(btn('', function () { vAct(v, 'pause'); }, 'ic sm', 'pause', 'Pause'));
        a.appendChild(btn('', function () { vAct(v, 'hibernate'); }, 'ic sm', 'moon', 'Hibernate (needs the QEMU guest agent)'));
        a.appendChild(btn('', function () { vAct(v, 'stop'); }, 'ic sm danger', 'stop', 'Stop (ACPI shutdown)'));
        a.appendChild(btn('', function () { vAct(v, 'kill'); }, 'ic sm danger', 'power', 'Force stop'));
      } else if (v.state === 'paused' || v.state === 'pmsuspended') {
        a.appendChild(btn('', function () { vAct(v, 'resume'); }, 'ic sm okc', 'play', 'Resume'));
        a.appendChild(btn('', function () { vAct(v, 'kill'); }, 'ic sm danger', 'power', 'Force stop'));
      } else if (v.state === 'in shutdown') {
        a.appendChild(btn('', function () { vAct(v, 'kill'); }, 'ic sm danger', 'power', 'Force stop'));
      } else a.appendChild(btn('', function () { vAct(v, 'start'); }, 'ic sm okc', 'play', 'Start'));
      if (busy) a.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
      tb.appendChild(h('tr', null,
        h('td', { class: 'name' }, h('div', { class: 'nm' }, v.icon ? img(v.icon, '') : h('span', { class: 'ph' }), v.name, v.autostart ? h('span', { class: 'badge info' }, 'auto') : null), h('div', { class: 'im' }, v.os || '')),
        h('td', null, vmBadge(v.state)), h('td', { class: 'num' }, v.cpus + ' vCPU'), h('td', { class: 'num' }, bytes(v.memory)), h('td', null, v.agent ? h('span', { class: 'badge ok', title: 'QEMU guest agent channel present: hibernate is available' }, 'Agent') : h('span', { class: 'badge', title: 'No guest agent channel: hibernate will not work' }, 'No agent')), a));
    });
    if (!S.vms.vms.length) tb.appendChild(h('tr', null, h('td', { colspan: '6', class: 'muted' }, 'No virtual machines.')));
    clear(t).appendChild(h('div', { class: 'panel', style: { padding: '0', overflow: 'auto' } }, h('table', { class: 't' },
      h('thead', null, h('tr', null, h('th', null, 'Virtual machine'), h('th', null, 'State'), h('th', null, 'CPUs'), h('th', null, 'Memory'), h('th', null, 'Guest agent'), h('th', { style: { 'text-align': 'right' } }, 'Actions'))), tb)));
  }
  function vAct(v, op) {
    var ask = { stop: ['Stop ' + v.name + '?', 'The VM is asked to shut down (like pressing the power button).', 'Shut down'], kill: ['Force stop ' + v.name + '?', 'This pulls the plug: unsaved work inside the VM is lost.', 'Force stop'],
                hibernate: ['Hibernate ' + v.name + '?', 'The VM saves its memory to disk and powers off. This needs the QEMU guest agent running inside the VM.', 'Hibernate'], restart: ['Restart ' + v.name + '?', 'The VM is asked to reboot.', 'Restart'] }[op];
    var run = function () { S.busy['v:' + v.name] = true; drawVms(); act('vm_action', { name: v.name, op: op }, v.name + ': ' + op + ' sent').then(function () { delete S.busy['v:' + v.name]; setTimeout(loadVms, 1200); }); };
    if (ask) confirmBox(ask[0], ask[1], ask[2], op === 'kill' || op === 'stop').then(function (y) { if (y) run(); }); else run();
  }

  /* ================= System ================= */
  function renderSystem(b) {
    b.appendChild(h('div', { id: 'sy-os' }, h('div', { class: 'progress' }, h('i'))));
    b.appendChild(h('div', { id: 'sy-hw' }));
    b.appendChild(h('div', { id: 'sy-st' }));
    loadOs(); pollSys().then(drawHw); every('sys', 5, function () { pollSys().then(drawHw); });
    api('storage').then(function (j) { S.storage = j; drawStorage(); });
  }
  function loadOs() { return api('os_status').then(function (j) { S.os = j; drawOs(); }); }
  function drawOs() {
    var el = document.getElementById('sy-os'); if (!el || !S.os) return; clear(el);
    var o = S.os, running = o.job === 'running';
    var p = h('div', { class: 'panel' }, h('h3', null, 'Unraid OS'));
    p.appendChild(h('div', { class: 'kv' }, h('span', null, 'Installed'), h('span', null, o.installed || '-'), h('span', null, 'Latest'), h('span', null, o.latest || (o.error ? '-' : 'not checked yet')),
      h('span', null, 'Last check'), h('span', null, o.checked ? rel(o.checked) : 'never')));
    if (o.error) p.appendChild(h('div', { class: 'banner bad', style: { 'margin-top': '10px' } }, 'Update check failed: ' + o.error));
    if (o.staged) p.appendChild(h('div', { class: 'banner ok', style: { 'margin-top': '10px' } }, 'Unraid ' + o.staged + ' is installed on the flash drive. Reboot the server (Main > Array Operation > Reboot) to finish. Tools > Update OS > Restore returns to ' + o.installed + ' if needed.'));
    else if (o.available && !running) p.appendChild(h('div', { class: 'banner acc', style: { 'margin-top': '10px' } }, 'Unraid ' + o.latest + ' is available. Read the release notes before updating; the update is installed on the flash drive and becomes active after a reboot.'));
    var tools = h('div', { class: 'tools', style: { 'margin-top': '12px' } },
      btn('Check now', function (e) { e.target.disabled = true; act('os_check', {}).then(function () { loadOs(); pollSys(); }); }, '', 'restart'),
      o.available && !o.staged ? btn(running ? 'Updating…' : 'Update to ' + o.latest, osUpdate, 'pri', 'up') : null,
      h('a', { class: 'btn', href: '/Tools/Update', target: '_self' }, 'Open Tools > Update OS'));
    if (running) tools.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
    p.appendChild(tools);
    if (o.log && o.log.state !== 'idle') {
      p.appendChild(h('h4', null, o.log.state === 'running' ? 'Installing…' : 'Last update output (exit code ' + o.log.code + ')'));
      if (o.log.state === 'running') p.appendChild(h('div', { class: 'progress' }, h('i')));
      var pre = h('pre', { class: 'log wrap', style: { 'max-height': '320px', 'min-height': '0' } }, o.log.out || '…'); p.appendChild(pre); setTimeout(function () { pre.scrollTop = pre.scrollHeight; }, 0);
      if (o.log.state === 'running') S.timers.os = setTimeout(loadOs, 2500);
    }
    el.appendChild(p);
  }
  function osUpdate() {
    confirmBox('Update Unraid to ' + S.os.latest + '?', 'The new release is downloaded and installed on the flash drive with Unraid\'s own updater (the same one Tools > Update OS uses). It becomes active after you reboot. Make sure you have a flash backup.', 'Install update').then(function (y) {
      if (y) act('os_update', {}, 'Update started').then(loadOs);
    });
  }
  function drawHw() {
    var el = document.getElementById('sy-hw'); if (!el || !S.sys) return; clear(el);
    var y = S.sys;
    el.appendChild(h('div', { class: 'cards' }, stat('CPU', Math.round(y.cpu.pct || 0) + '%'), stat('Memory', Math.round(y.mem.pct || 0) + '%'), y.cpu.temp !== null ? stat('CPU temp', Math.round(y.cpu.temp) + '°C') : null,
      stat('Network', rate(y.net.rx) + ' ↓'), stat('Uptime', upt(y.uptime))));
    var arr = y.array;
    el.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Server'), h('div', { class: 'kv' },
      h('span', null, 'Name'), h('span', null, y.name), h('span', null, 'Unraid'), h('span', null, y.os), h('span', null, 'Kernel'), h('span', null, y.kernel),
      h('span', null, 'CPU'), h('span', null, y.cpu.model + ' · ' + y.cpu.threads + ' threads'), h('span', null, 'Load'), h('span', null, y.cpu.load.join(' / ')),
      h('span', null, 'Memory'), h('span', null, bytes(y.mem.used) + ' used of ' + bytes(y.mem.total)), h('span', null, 'Array'), h('span', null, (arr.state || '-') + (arr.resync ? ' · parity check / sync ' + (arr.resync_size ? Math.round(100 * arr.resync_pos / arr.resync_size) + '%' : 'running') : '')),
      h('span', null, 'Network'), h('span', null, (y.net.iface || '-') + ' · ' + rate(y.net.rx) + ' in, ' + rate(y.net.tx) + ' out'))));
  }
  function drawStorage() {
    var el = document.getElementById('sy-st'); if (!el || !S.storage) return; clear(el);
    var rows = [].concat(S.storage.parity.map(function (d) { d.k = 'Parity'; return d; }), S.storage.array.map(function (d) { d.k = 'Array'; return d; }), S.storage.pools.map(function (d) { d.k = 'Pool'; return d; }));
    var tb = h('tbody');
    rows.forEach(function (d) {
      var p = d.size ? 100 * (d.size - d.free) / d.size : 0;
      tb.appendChild(h('tr', null, h('td', null, h('b', null, d.name), h('div', { class: 'muted sm' }, d.k + (d.device ? ' · ' + d.device : ''))),
        h('td', null, h('span', { class: 'badge ' + (d.status === 'DISK_OK' ? 'ok' : 'bad') }, d.status.replace('DISK_', '').toLowerCase())),
        h('td', { class: 'num' }, d.temp === null ? (d.spun ? '-' : 'spun down') : d.temp + '°C'),
        h('td', { style: { 'min-width': '200px' } }, d.size ? [h('div', { class: 'sm' }, bytes(d.size - d.free) + ' / ' + bytes(d.size) + (d.fs ? ' · ' + d.fs : '')), h('div', { class: 'meter' }, h('i', { class: p > 90 ? 'bad' : p > 75 ? 'warn' : '', style: { width: p.toFixed(1) + '%' } }))] : h('span', { class: 'muted' }, '-'))));
    });
    if (!rows.length) tb.appendChild(h('tr', null, h('td', { colspan: '4', class: 'muted' }, 'No disk information (is the array started?).')));
    el.appendChild(h('div', { class: 'panel', style: { padding: '0', overflow: 'auto' } }, h('table', { class: 't' }, h('thead', null, h('tr', null, h('th', null, 'Disk'), h('th', null, 'Status'), h('th', null, 'Temp'), h('th', null, 'Usage'))), tb)));
  }

  /* ================= Settings ================= */
  function renderSettings(b) {
    if (!S.set) {
      b.appendChild(h('div', { class: 'progress' }, h('i')));
      api('settings').then(function (j) {
        if (j.error) { clear(b).appendChild(h('div', { class: 'banner bad' }, j.error)); return; }
        S.set = { cfg: j.config, vars: j.secrets.vars, svc: j.secrets.svc || {}, types: j.types, keep: j.keep };
        if (Array.isArray(S.set.svc)) S.set.svc = {};
        render();
      });
      return;
    }
    var D = S.set, g = D.cfg.general;
    var dirty = function () { S.dirty = true; var sb = document.getElementById('und-save'); if (sb) sb.disabled = false; };
    var txt = function (o, k, ph, type) { return h('input', { type: type || 'text', value: o[k] === undefined || o[k] === null ? '' : o[k], placeholder: ph || '', oninput: function (e) { o[k] = type === 'number' ? +e.target.value : e.target.value; dirty(); } }); };
    var chk = function (o, k, label) { return h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!o[k], onchange: function (e) { o[k] = e.target.checked; dirty(); } }), label); };
    var sel = function (o, k, opts) { return h('select', { onchange: function (e) { o[k] = e.target.value; dirty(); } }, opts.map(function (x) { return h('option', { value: x[0], selected: String(o[k]) === String(x[0]) }, x[1]); })); };
    var fld = function (label, input, hint) { return h('div', { class: 'fld' }, h('label', null, label), input, hint ? h('div', { class: 'hint' }, hint) : null); };

    b.appendChild(h('div', { class: 'panel' }, h('h3', null, 'General'), h('div', { class: 'grid2' },
      fld('Title', txt(g, 'title')),
      fld('Theme', sel(g, 'theme', [['dark', 'Dark (Swiss)'], ['midnight', 'Midnight'], ['light', 'Light']])),
      fld('Background image', txt(g, 'background', 'https://… or /boot path served by Unraid'), 'Optional wallpaper, like Homepage\'s background.'),
      fld('Background blur / brightness', h('div', { class: 'inl' }, txt(g, 'blur', '', 'number'), 'px', txt(g, 'brightness', '', 'number'), '%')),
      fld('System refresh (seconds)', txt(g, 'refresh_system', '', 'number')),
      fld('Widget refresh (seconds)', txt(g, 'refresh_services', '', 'number')),
      fld('Disk shown in the top bar', txt(g, 'disk_path', '/mnt/user'), 'Any path under /mnt, e.g. /mnt/user, /mnt/cache or /mnt/disk3.'),
      fld('Web search', sel(g, 'search', [['duckduckgo', 'DuckDuckGo'], ['google', 'Google'], ['bing', 'Bing'], ['startpage', 'Startpage'], ['brave', 'Brave']])),
      fld('UPS name (NUT)', txt(g, 'ups_name', 'ups'), 'Used by the UPS card: NUT (upsc) first, then apcupsd.'),
      fld('Check for Unraid updates every (hours)', txt(g, 'os_check_hours', '', 'number')),
      h('div', null, chk(g, 'show_resources', 'Show the resource bar'), chk(g, 'show_date', 'Show the date'), chk(g, 'show_search', 'Show web search')))));

    /* variables */
    var vt = h('tbody');
    var drawVars = function () {
      clear(vt);
      D.vars.forEach(function (v, i) {
        var val = h('input', { type: v.secret ? 'password' : 'text', value: v.value === D.keep ? '' : v.value, placeholder: v.value === D.keep ? 'stored (leave empty to keep)' : '', autocomplete: 'off',
          oninput: function (e) { v.value = e.target.value === '' && v.stored ? D.keep : e.target.value; dirty(); } });
        if (v.value === D.keep) v.stored = true;
        vt.appendChild(h('tr', null, h('td', null, h('input', { type: 'text', value: v.name, oninput: function (e) { v.name = e.target.value.toUpperCase(); v.secret = /KEY|TOKEN|PASS|SECRET/i.test(v.name); dirty(); } })),
          h('td', null, val), h('td', { class: 'acts' }, btn('', function () { D.vars.splice(i, 1); dirty(); drawVars(); }, 'ic sm danger', 'trash', 'Remove'))));
      });
    };
    drawVars();
    b.appendChild(h('div', { class: 'panel vars' }, h('h3', null, 'Variables'),
      h('p', { class: 'muted sm' }, 'Use {{NAME}} in any URL, link, key or password, like Homepage\'s HOMEPAGE_VAR_… variables. Names containing KEY, TOKEN, PASS or SECRET are stored as secrets and never shown again. {{UNRAID_IP}} is built in.'),
      h('table', { class: 't' }, h('thead', null, h('tr', null, h('th', null, 'Name'), h('th', null, 'Value'), h('th'))), vt),
      h('div', { class: 'tools', style: { 'margin-top': '8px' } }, btn('Add variable', function () { D.vars.push({ name: '', value: '', secret: false }); dirty(); drawVars(); }, 'sm', 'plus'))));

    /* layout */
    var lay = h('div');
    var drawLayout = function () {
      clear(lay);
      D.cfg.groups.forEach(function (gr, gi) {
        var box = h('div', { class: 'lg' });
        box.appendChild(h('div', { class: 'lh' }, h('input', { type: 'text', value: gr.name, 'aria-label': 'Group name', oninput: function (e) { gr.name = e.target.value; dirty(); } }),
          h('label', { class: 'inl sm muted' }, 'Columns', h('input', { type: 'number', min: 1, max: 8, value: gr.columns, oninput: function (e) { gr.columns = +e.target.value; dirty(); } })),
          h('label', { class: 'switch sm' }, h('input', { type: 'checkbox', checked: gr.header, onchange: function (e) { gr.header = e.target.checked; dirty(); } }), 'Show title'),
          btn('', function () { move(D.cfg.groups, gi, -1); dirty(); drawLayout(); }, 'ic sm', 'upar', 'Move up'),
          btn('', function () { move(D.cfg.groups, gi, 1); dirty(); drawLayout(); }, 'ic sm', 'dnar', 'Move down'),
          btn('Add widget', function () { var s = { id: rid(), name: '', type: 'radarr', icon: '', href: '', url: '', fields: [], options: {} }; editService(s, gr, true, drawLayout); }, 'sm', 'plus'),
          btn('', function () { confirmBox('Delete group "' + gr.name + '"?', 'The group and its ' + gr.services.length + ' widgets are removed when you save.', 'Delete', true).then(function (y) { if (y) { D.cfg.groups.splice(gi, 1); dirty(); drawLayout(); } }); }, 'ic sm danger', 'trash', 'Delete group')));
        gr.services.forEach(function (s, si) {
          box.appendChild(h('div', { class: 'ls' }, img(iconUrl(s.icon), ''), h('span', { class: 't' }, h('b', null, s.name), h('span', { class: 'muted sm' }, ' · ' + ((D.types[s.type] || [s.type])[0]) + (s.url ? ' · ' + s.url : ''))),
            btn('', function () { move(gr.services, si, -1); dirty(); drawLayout(); }, 'ic sm', 'upar', 'Move up'),
            btn('', function () { move(gr.services, si, 1); dirty(); drawLayout(); }, 'ic sm', 'dnar', 'Move down'),
            gi > 0 ? btn('', function () { gr.services.splice(si, 1); D.cfg.groups[gi - 1].services.push(s); dirty(); drawLayout(); }, 'ic sm', 'upar', 'Move to the group above') : null,
            btn('', function () { editService(s, gr, false, drawLayout); }, 'ic sm', 'edit', 'Edit'),
            btn('', function () { gr.services.splice(si, 1); delete D.svc[s.id]; dirty(); drawLayout(); }, 'ic sm danger', 'trash', 'Remove')));
        });
        if (!gr.services.length) box.appendChild(h('div', { class: 'ls muted sm' }, 'No widgets in this group.'));
        lay.appendChild(box);
      });
    };
    drawLayout();
    b.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Layout'), lay,
      h('div', { class: 'tools' }, btn('Add group', function () { D.cfg.groups.push({ id: rid(), name: 'New group', columns: 4, header: true, services: [] }); dirty(); drawLayout(); }, 'sm', 'plus'))));

    /* import */
    var ta = function (ph) { return h('textarea', { rows: 6, placeholder: ph, spellcheck: 'false' }); };
    var ySvc = ta('Paste services.yaml here'), ySet = ta('Optional: paste settings.yaml (for columns and group titles)'), yEnv = ta('Optional: paste the .env / HOMEPAGE_VAR_… values (KEY=value per line)');
    var prev = h('div');
    b.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Import from Homepage'),
      h('p', { class: 'muted sm' }, 'Brings over groups, widgets, icons, links and {{HOMEPAGE_VAR_…}} variables from a gethomepage/homepage config. Glances cards become UnDash\'s built-in CPU, memory and system cards. This replaces the current layout.'),
      h('div', { class: 'grid2' }, ySvc, h('div', null, ySet, h('div', { style: { height: '8px' } }), yEnv)),
      h('div', { class: 'tools', style: { 'margin-top': '8px' } },
        btn('Preview', function () {
          api('import_preview', { services: ySvc.value, settings: ySet.value, env: yEnv.value }).then(function (j) {
            clear(prev);
            if (j.error) { prev.appendChild(h('div', { class: 'banner bad' }, j.error)); return; }
            var missing = j.vars.filter(function (v) { return !v.set && !v.stored; });
            prev.appendChild(h('div', { class: 'banner ok' }, j.groups + ' groups, ' + j.services + ' widgets found.'));
            j.warnings.forEach(function (w) { prev.appendChild(h('div', { class: 'banner' }, w)); });
            if (missing.length) prev.appendChild(h('div', { class: 'banner' }, missing.length + ' variables have no value yet (fill them in under Variables after importing): ', missing.map(function (v) { return v.name; }).join(', ')));
            prev.appendChild(h('div', { class: 'kv sm' }, j.layout.map(function (g) { return [h('span', null, g.name), h('span', null, g.services.join(', '))]; })));
            prev.appendChild(h('div', { class: 'tools', style: { 'margin-top': '10px' } }, btn('Import and replace the layout', function () {
              confirmBox('Replace the layout?', 'Your current groups and widgets are replaced by the imported ones. Stored variable values are kept.', 'Import', true).then(function (y) {
                if (!y) return;
                act('import_apply', { services: ySvc.value, settings: ySet.value, env: yEnv.value }, 'Imported').then(function (k) { if (!k.error) reboot(); });
              });
            }, 'pri')));
          });
        }, '', 'check')), prev));

    /* backup */
    b.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Backup'),
      h('p', { class: 'muted sm' }, 'The layout lives in /boot/config/plugins/undash/config.json and the secrets in secrets.json on the flash drive. The export below contains the layout only (no keys or passwords).'),
      h('div', { class: 'tools' },
        btn('Export layout', function () { api('export').then(function (j) { var a = h('a', { href: URL.createObjectURL(new Blob([JSON.stringify(j.config, null, 2)], { type: 'application/json' })), download: 'undash-layout.json' }); root.appendChild(a); a.click(); root.removeChild(a); }); }, 'sm'),
        btn('Import layout…', function () {
          var f = h('input', { type: 'file', accept: '.json,application/json', onchange: function () {
            var r = new FileReader(); r.onload = function () { try { var c = JSON.parse(r.result); if (!c.groups) throw 0; D.cfg = c; dirty(); render(); toast('Layout loaded: press Save to keep it'); } catch (e) { toast('That is not an UnDash layout file.', true); } }; r.readAsText(f.files[0]);
          } }); f.click();
        }, 'sm'),
        btn('Reset to default', function () { confirmBox('Reset the layout?', 'All groups and widgets are replaced by the default system cards.', 'Reset', true).then(function (y) { if (y) act('reset', {}, 'Layout reset').then(reboot); }); }, 'sm danger'))));

    var save = h('button', { type: 'button', class: 'btn pri', id: 'und-save', disabled: !S.dirty, onclick: function () {
      save.disabled = true;
      act('save', payload(), 'Saved').then(function (j) { if (j.error) { save.disabled = false; return; } S.dirty = false; reboot(); });
    } }, 'Save');
    b.appendChild(h('div', { class: 'savebar' }, save, btn('Discard changes', function () { S.dirty = false; S.set = null; render(); }), h('span', { class: 'muted sm grow' }, 'Changes apply after saving.')));
  }

  function payload() {
    var D = S.set;
    return { config: D.cfg, vars: D.vars.filter(function (v) { return v.name; }).map(function (v) { return { name: v.name, value: v.value }; }), svc: D.svc };
  }
  function move(arr, i, d) { var j = i + d; if (j < 0 || j >= arr.length) return; var x = arr[i]; arr[i] = arr[j]; arr[j] = x; }
  function rid() { var s = ''; for (var i = 0; i < 8; i++) s += '0123456789abcdef'[Math.floor(Math.random() * 16)]; return s; }
  function reboot() { return api('boot').then(function (j) { if (!j.error) { S.boot = j; S.set = null; S.svc = {}; shell(); render(); } }); }

  /* service editor */
  var FIELDS = {
    radarr: 'wanted, missing, queued, movies', sonarr: 'wanted, queued, series', lidarr: 'wanted, queued, artists', readarr: 'wanted, queued, books',
    bazarr: 'missingEpisodes, missingMovies', prowlarr: 'numberOfGrabs, numberOfQueries, numberOfFailGrabs, numberOfFailQueries', overseerr: 'pending, approved, processing, completed, available',
    autobrr: 'approvedPushes, rejectedPushes, filters, indexers', wizarr: 'users, invites, pending, expired', qbittorrent: 'leech, download, seed, upload', sabnzbd: 'rate, queue, timeleft',
    emby: 'movies, series, episodes, songs', jellyfin: 'movies, series, episodes, songs', plex: 'streams, albums, movies, tv', adguard: 'queries, blocked, filtered, latency',
    pihole: 'queries, blocked, blocked_percent, gravity', npm: 'enabled, disabled, total', immich: 'users, photos, videos, storage', peanut: 'battery_charge, ups_load, ups_status',
    ups: 'battery_charge, ups_load, ups_status, runtime', docker_summary: 'running, stopped, paused, updates', vm_summary: 'running, stopped, paused, total'
  };
  function editService(orig, group, isNew, after) {
    var D = S.set, s = JSON.parse(JSON.stringify(orig)), sec = JSON.parse(JSON.stringify(D.svc[s.id] || {}));
    if (!s.options || Array.isArray(s.options)) s.options = {};
    var form = h('div');
    var draw = function () {
      clear(form);
      var T = D.types[s.type] || ['', [], false];
      var f = function (label, input, hint) { return h('div', { class: 'fld' }, h('label', null, label), input, hint ? h('div', { class: 'hint' }, hint) : null); };
      var t = function (o, k, ph) { return h('input', { type: 'text', value: o[k] === undefined ? '' : o[k], placeholder: ph || '', oninput: function (e) { o[k] = e.target.value; } }); };
      var typeSel = h('select', { onchange: function (e) { s.type = e.target.value; s.fields = []; s.options = {}; draw(); } },
        Object.keys(D.types).map(function (k) { return h('option', { value: k, selected: k === s.type }, D.types[k][0]); }));
      form.appendChild(h('div', { class: 'grid2' }, f('Name', t(s, 'name', T[0])), f('Type', typeSel),
        f('Icon', t(s, 'icon', 'sh-radarr, radarr.png or https://…'), 'sh-NAME = selfh.st icons, NAME.png = dashboard-icons, or any URL.'),
        f('Link (opens on click)', t(s, 'href', 'http://{{SERVER_IP}}:7878'))));
      if (T[2] || s.type === 'customapi') form.appendChild(f('API URL', t(s, 'url', 'http://{{SERVER_IP}}:7878'), 'The address UnDash calls from the server. {{VARIABLES}} work here.'));
      var creds = h('div', { class: 'grid2' });
      (T[1] || []).forEach(function (k) {
        var stored = sec[k] === D.keep;
        var inp = h('input', { type: k === 'username' ? 'text' : 'password', autocomplete: 'off', value: stored ? '' : (sec[k] || ''), placeholder: stored ? 'stored (leave empty to keep)' : (k === 'key' ? 'API key or {{VARIABLE}}' : ''),
          oninput: function (e) { sec[k] = e.target.value === '' && stored ? D.keep : e.target.value; } });
        creds.appendChild(f({ key: s.type === 'plex' ? 'Plex token' : s.type === 'peanut' ? 'Device key' : s.type === 'pihole' ? 'Password / app password' : 'API key', username: 'Username', password: 'Password' }[k],
          h('div', { class: 'sec-in' }, inp, stored ? btn('Clear', function () { sec[k] = ''; stored = false; inp.placeholder = ''; }, 'sm') : null)));
      });
      if (creds.childNodes.length) form.appendChild(creds);
      if (FIELDS[s.type]) form.appendChild(f('Fields to show', h('input', { type: 'text', value: (s.fields || []).join(', '), placeholder: 'all', oninput: function (e) { s.fields = e.target.value.split(/[\s,]+/).filter(Boolean); } }), 'Available: ' + FIELDS[s.type] + '. Empty shows all.'));
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
        form.appendChild(f('Sources', box));
        form.appendChild(h('div', { class: 'grid2' }, f('Max events', t(s.options, 'max_events', '8')), f('Days ahead', t(s.options, 'days_ahead', '30')), f('Days back', t(s.options, 'previous_days', '0'))));
      }
      if (s.type === 'tautulli_recent') form.appendChild(h('div', { class: 'grid2' }, f('Media type', t(s.options, 'media_type', 'movie, episode, track…')), f('Library section id', t(s.options, 'section_id', 'optional')), f('Count', t(s.options, 'count', '6'))));
      if (s.type === 'ups') form.appendChild(f('UPS name', t(s.options, 'ups', D.cfg.general.ups_name)));
      if (s.type === 'emby' || s.type === 'jellyfin') form.appendChild(h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!s.options.hide_blocks, onchange: function (e) { s.options.hide_blocks = e.target.checked; } }), 'Only show the streams (hide the library counts)'));
      if (s.type === 'customapi') {
        var js = function (k, def) { return h('textarea', { rows: 6, spellcheck: 'false', oninput: function (e) { try { s.options[k] = JSON.parse(e.target.value || def); e.target.style.borderColor = ''; } catch (x) { e.target.style.borderColor = 'var(--bad)'; } } }, JSON.stringify(s.options[k] || JSON.parse(def), null, 2)); };
        form.appendChild(h('div', { class: 'grid2' }, f('Display', h('select', { onchange: function (e) { s.options.display = e.target.value; } }, [['block', 'Blocks'], ['list', 'List']].map(function (o) { return h('option', { value: o[0], selected: (s.options.display || 'block') === o[0] }, o[1]); }))),
          f('Method', t(s.options, 'method', 'GET'))));
        form.appendChild(f('Headers (JSON)', js('headers', '{}'), '{{KEY}} is replaced by the API key above, e.g. {"X-Api-Key": "{{KEY}}"}.'));
        form.appendChild(f('Mappings (JSON)', js('mappings', '[]'), '[{"field": "data.users", "label": "Users", "format": "number"}]. Formats: number, text, bytes, rate, percent; lists can add "additional": {"field": "...", "format": "relativeDate", "scale": 1000}.'));
      }
      var res = h('div', { id: 'und-test' });
      if (T[2] || s.type === 'calendar' || s.type === 'customapi') form.appendChild(h('div', { class: 'tools' }, btn('Test', function () {
        clear(res).appendChild(h('div', { class: 'progress' }, h('i')));
        var p = payload(), cfg = JSON.parse(JSON.stringify(p.config)), svc = JSON.parse(JSON.stringify(p.svc));
        var gg = cfg.groups.filter(function (x) { return x.id === group.id; })[0];
        if (gg) { var k = gg.services.map(function (x) { return x.id; }).indexOf(s.id); if (k < 0) gg.services.push(s); else gg.services[k] = s; }
        svc[s.id] = sec;
        api('test', { config: cfg, vars: p.vars, svc: svc, id: s.id }).then(function (r) {
          clear(res);
          if (r.error) res.appendChild(h('div', { class: 'banner bad' }, r.error));
          else res.appendChild(h('div', { class: 'banner ok' }, 'It works: ', r.blocks ? r.blocks.map(function (x) { return x.label + ' ' + fmt(x.value, x.fmt); }).join(' · ') : r.list ? r.list.length + ' items' : r.calendar ? r.calendar.length + ' events' : r.streams ? r.streams.length + ' streams' : 'ok'));
        });
      }, '', 'check'), res));
    };
    draw();
    var m = modal(isNew ? 'Add widget to ' + group.name : 'Edit ' + orig.name, form, [btn('Cancel', function () { m.close(); }), btn(isNew ? 'Add' : 'Apply', function () {
      if (!s.name) s.name = (D.types[s.type] || [''])[0];
      if (isNew) group.services.push(s); else for (var k in s) orig[k] = s[k];
      D.svc[s.id] = sec; S.dirty = true; m.close(); after();
      var sb = document.getElementById('und-save'); if (sb) sb.disabled = false;
    }, 'pri')]);
  }

  /* ---------- start ---------- */
  S.full = LS.get('full', false);
  var h0 = (location.hash || '').replace('#', '');
  if (TABS.some(function (t) { return t[0] === h0; })) S.tab = h0;
  window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('visibilitychange', function () { if (!document.hidden && S.boot && S.tab === 'dash') { pollSys(); pollServices(); } });
  api('boot').then(function (j) {
    if (j.error) { clear(root).appendChild(h('div', { class: 'und-loading' }, 'UnDash could not start: ' + j.error)); return; }
    S.boot = j; shell(); render();
    if (S.tab !== 'dash' && S.tab !== 'system') pollSys();   // the header shows the Unraid version and update state on every tab
  });
  window.addEventListener('hashchange', function () { var t = (location.hash || '').replace('#', ''); if (t !== S.tab && TABS.some(function (x) { return x[0] === t; })) go(t); });
})();
