/* Portal tabs (shell page: admin/_portal_tabs.php) — Notion-style.
   A tab strip across the top of the window. Every tab is a staff page in
   its own iframe that stays loaded while hidden, so typed text, scroll
   position and open documents survive a switch.
   - Sidebar links change the current tab's page; Ctrl/Cmd/middle-click
     opens the link in a new tab (links inside pages too).
   - "+" (or double-clicking the empty strip) opens a "New tab" page:
     search pages and clients, recent pages, all pages.
   - Drag to reorder; right-click for Duplicate / Close / Close others /
     Close to the right; middle-click a tab to close it. */
(function () {
  'use strict';

  var dataEl = document.getElementById('ctData');
  if (!dataEl) return;
  var data = JSON.parse(dataEl.textContent);
  var KEY = 'portalTabs', RECENT_KEY = 'portalRecent', MAX = 12;
  var SUFFIX = ' · Duque Immigration Document Portal';
  var bar = document.getElementById('ctBar');
  var tabsEl = document.getElementById('ctTabs');
  var framesEl = document.getElementById('ctFrames');
  var addBtn = document.getElementById('ctAdd');
  var newEl = document.getElementById('ctNew');
  var newSearch = document.getElementById('ctNewSearch');
  var newList = document.getElementById('ctNewList');
  var sidebar = document.getElementById('appSidebar');
  var pages = data.pages || [];

  var ICONS = {
    'frontdesk.php': 'fa-solid fa-bell-concierge', 'clients.php': 'fa-solid fa-users',
    'client.php': 'fa-regular fa-folder-open', 'inbox.php': 'fa-solid fa-inbox',
    'chats.php': 'fa-regular fa-comments', 'processors.php': 'fa-solid fa-user-tie',
    'staff.php': 'fa-solid fa-users-gear', 'activity.php': 'fa-solid fa-clock-rotate-left',
    'profile.php': 'fa-solid fa-id-badge', 'dashboard.php': 'fa-solid fa-gauge',
    'classes.php': 'fa-solid fa-layer-group'
  };

  // "clients.php?class=x" form: file + query, never embed. '' when it isn't
  // one of the tab-able staff pages.
  function norm(url) {
    try {
      var u = new URL(url, location.href);
      if (u.origin !== location.origin) return '';
      u.searchParams.delete('embed');
      var file = u.pathname.replace(/^.*\//, '');
      if (pages.indexOf(file) === -1) return '';
      return file + u.search;
    } catch (e) { return ''; }
  }
  function fileOf(url) { return String(url || '').split('?')[0]; }
  function iconFor(url) { return url ? (ICONS[fileOf(url)] || 'fa-regular fa-file') : 'fa-regular fa-file'; }
  function withEmbed(url) { return url + (url.indexOf('?') === -1 ? '?' : '&') + 'embed=1'; }
  function uid() { return 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
  function stripSuffix(s) { return String(s || '').replace(SUFFIX, '').trim(); }

  // ---- State (a tab with url '' is a "New tab" page) ----
  var tabs = [], active = null, frames = {};
  (function load() {
    try {
      var v = JSON.parse(localStorage.getItem(KEY) || '{}');
      (Array.isArray(v.tabs) ? v.tabs : []).forEach(function (t) {
        if (!t || !t.id) return;
        var url = t.url === '' ? '' : norm(t.url);
        if (url === '' && t.url !== '') return;
        tabs.push({ id: String(t.id).slice(0, 24), url: url, title: String(t.title || '').slice(0, 120) });
      });
      active = v.active;
    } catch (e) { tabs = []; }
  })();
  function save() { try { localStorage.setItem(KEY, JSON.stringify({ tabs: tabs, active: active })); } catch (e) {} }
  function find(id) { return tabs.filter(function (t) { return t.id === id; })[0]; }
  function indexOf(id) { return tabs.findIndex(function (t) { return t.id === id; }); }

  function recents() {
    try {
      var v = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
      return (Array.isArray(v) ? v : []).filter(function (r) { return r && norm(r.url) && r.title; }).slice(0, 8);
    } catch (e) { return []; }
  }
  function remember(url, title) {
    if (!url || !title) return;
    var list = recents().filter(function (r) { return r.url !== url; });
    list.unshift({ url: url, title: String(title).slice(0, 120) });
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 8))); } catch (e) {}
  }

  // The page in the address bar becomes (or selects) a tab.
  (function ensure() {
    var start = norm(data.start) || 'clients.php';
    var hit = find(active);
    if (!(hit && hit.url === start)) hit = tabs.filter(function (t) { return t.url === start; })[0];
    if (!hit) { hit = { id: uid(), url: start, title: '' }; tabs.push(hit); }
    active = hit.id;
    trim();
    save();
  })();

  function trim() {
    while (tabs.length > MAX) {
      var i = tabs.findIndex(function (t) { return t.id !== active; });
      dropFrames(tabs.splice(i, 1));
    }
  }
  function dropFrames(list) {
    list.forEach(function (t) {
      if (frames[t.id]) { frames[t.id].remove(); delete frames[t.id]; }
    });
  }

  function label(t) { return t.url === '' ? 'New tab' : (t.title || 'Loading…'); }

  // ---- Tab strip ----
  var dragId = null;
  function clearDrops() {
    tabsEl.querySelectorAll('.drop-before,.drop-after').forEach(function (n) { n.classList.remove('drop-before', 'drop-after'); });
  }
  function move(id, targetId, after) {
    var from = indexOf(id);
    if (from < 0) return;
    var item = tabs.splice(from, 1)[0];
    var to = indexOf(targetId);
    tabs.splice(after ? to + 1 : to, 0, item);
    dragId = null; save(); render();
  }

  function render() {
    tabsEl.textContent = '';
    tabs.forEach(function (t) {
      var tab = document.createElement('div');
      tab.className = 'ct-tab' + (t.id === active ? ' on' : '');
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-selected', t.id === active ? 'true' : 'false');
      tab.tabIndex = 0;
      tab.title = label(t);
      var ico = document.createElement('i');
      ico.className = 'ct-ico ' + iconFor(t.url);
      var name = document.createElement('span');
      name.className = 'ct-name';
      name.textContent = label(t);
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'ct-x';
      x.title = 'Close tab';
      x.setAttribute('aria-label', 'Close ' + label(t));
      x.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      x.addEventListener('click', function (e) { e.stopPropagation(); close(t.id); });
      tab.appendChild(ico);
      tab.appendChild(name);
      tab.appendChild(x);

      tab.draggable = true;
      tab.addEventListener('dragstart', function (e) {
        dragId = t.id;
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', t.id); } catch (err) {}
        tab.classList.add('dragging');
      });
      tab.addEventListener('dragend', function () { dragId = null; clearDrops(); render(); });
      tab.addEventListener('dragover', function (e) {
        if (!dragId || dragId === t.id) return;
        e.preventDefault();
        var r = tab.getBoundingClientRect();
        clearDrops();
        tab.classList.add(e.clientX > r.left + r.width / 2 ? 'drop-after' : 'drop-before');
      });
      tab.addEventListener('drop', function (e) {
        if (!dragId || dragId === t.id) return;
        e.preventDefault();
        var r = tab.getBoundingClientRect();
        move(dragId, t.id, e.clientX > r.left + r.width / 2);
      });
      tab.addEventListener('contextmenu', function (e) { e.preventDefault(); tabMenu(e.clientX, e.clientY, t.id); });
      tab.addEventListener('click', function () { show(t.id); });
      tab.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(t.id); }
      });
      tab.addEventListener('auxclick', function (e) { if (e.button === 1) { e.preventDefault(); close(t.id); } });
      tabsEl.appendChild(tab);
    });
    syncNav();
  }

  // Highlight the sidebar entry that matches the current tab.
  function syncNav() {
    if (!sidebar) return;
    var t = find(active);
    var url = t ? t.url : '';
    var file = fileOf(url);
    var cls = new URLSearchParams(url.split('?')[1] || '').get('class') || '';
    sidebar.querySelectorAll('nav a[href]').forEach(function (a) {
      var h = norm(a.getAttribute('href'));
      var on = false;
      if (h && url) {
        var hf = fileOf(h);
        var hc = new URLSearchParams(h.split('?')[1] || '').get('class') || '';
        if (hf === 'clients.php') on = (file === 'clients.php' || file === 'client.php') && hc === (file === 'clients.php' ? cls : '');
        else on = hf === file;
      }
      a.classList.toggle('active', on);
    });
  }

  // ---- Theme: the sidebar switch lives here; push it into every frame ----
  function syncTheme(f) {
    try {
      var th = document.documentElement.getAttribute('data-theme');
      if (th) f.contentDocument.documentElement.setAttribute('data-theme', th);
    } catch (e) { /* frame still navigating */ }
  }
  new MutationObserver(function () {
    Object.keys(frames).forEach(function (k) { syncTheme(frames[k]); });
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  function chrome() {
    var t = find(active);
    if (!t) return;
    document.title = label(t) + SUFFIX;
    if (t.url) { try { history.replaceState(null, '', t.url); } catch (e) {} }
  }

  // ---- Frames ----
  function frameFor(t) {
    if (frames[t.id]) return frames[t.id];
    var f = document.createElement('iframe');
    f.className = 'ct-frame';
    f.title = 'Portal page';
    f.hidden = true;
    f.addEventListener('load', function () {
      syncTheme(f);
      try {
        var loc = f.contentWindow.location;
        var file = loc.pathname.replace(/^.*\//, '');
        if (file === 'login.php' || file === 'index.php' || file === '') { top.location.href = 'login.php'; return; }
        // A page that came back as a whole tab shell (old service worker):
        // reload it once as a plain embedded page.
        if (f.contentDocument.getElementById('ctTabs')) {
          var again = norm(loc.href);
          if (again && !f.dataset.retried) { f.dataset.retried = '1'; f.src = withEmbed(again); }
          return;
        }
        var cur = find(t.id);
        if (!cur) return;
        var url = norm(loc.href);
        if (url) cur.url = url;
        var title = stripSuffix(f.contentDocument.title);
        if (title) cur.title = title;
        remember(cur.url, cur.title);
        save(); render();
        if (cur.id === active) chrome();
      } catch (e) { /* navigating: ignore */ }
    });
    f.src = withEmbed(t.url);
    framesEl.appendChild(f);
    frames[t.id] = f;
    return f;
  }

  function show(id) {
    var t = find(id);
    if (!t) return;
    active = id;
    Object.keys(frames).forEach(function (k) { frames[k].hidden = true; });
    if (t.url === '') {
      newEl.hidden = false;
      newSearch.value = '';
      renderNew();
      setTimeout(function () { newSearch.focus(); }, 0);
    } else {
      newEl.hidden = true;
      frameFor(t).hidden = false;
    }
    render(); chrome(); save();
  }

  function close(id) {
    var i = indexOf(id);
    if (i < 0) return;
    dropFrames(tabs.splice(i, 1));
    if (!tabs.length) { newTab(); return; }
    save();
    if (id === active) show(tabs[Math.min(i, tabs.length - 1)].id);
    else render();
  }

  function newTab() {
    var t = { id: uid(), url: '', title: '' };
    var i = indexOf(active);
    tabs.splice(i < 0 ? tabs.length : i + 1, 0, t);
    trim();
    show(t.id);
  }
  // A page in a new tab, next to the current one.
  function openTab(url, titleHint) {
    var n = norm(url);
    if (!n) return;
    var t = { id: uid(), url: n, title: titleHint || '' };
    var i = indexOf(active);
    tabs.splice(i < 0 ? tabs.length : i + 1, 0, t);
    trim();
    show(t.id);
  }
  // Another page in the current tab.
  function gotoHere(url, titleHint) {
    var n = norm(url);
    var t = find(active);
    if (!n || !t) return;
    t.url = n; t.title = titleHint || '';
    var f = frames[t.id];
    if (f) f.src = withEmbed(n);
    show(t.id);
  }
  window.PortalTabs = { open: function (u) { openTab(u); } };

  // ---- Right-click menu on a tab ----
  var menuEl = null;
  function closeMenu() { if (menuEl) { menuEl.remove(); menuEl = null; } }
  function tabMenu(x, y, id) {
    closeMenu();
    var i = indexOf(id);
    if (i < 0) return;
    var m = document.createElement('div');
    m.className = 'ct-menu';
    function item(icon, text, disabled, fn) {
      var b = document.createElement('button');
      b.type = 'button';
      b.disabled = !!disabled;
      var ic = document.createElement('i');
      ic.className = icon;
      b.appendChild(ic);
      b.appendChild(document.createTextNode(text));
      b.addEventListener('click', function () { closeMenu(); fn(); });
      m.appendChild(b);
    }
    item('fa-solid fa-plus', 'New tab', false, newTab);
    item('fa-regular fa-copy', 'Duplicate tab', false, function () {
      var src = find(id), n = { id: uid(), url: src.url, title: src.title };
      tabs.splice(i + 1, 0, n); trim(); show(n.id);
    });
    item('fa-solid fa-rotate-right', 'Reload tab', !find(id).url, function () {
      var f = frames[id];
      if (f) { try { f.contentWindow.location.reload(); } catch (e) { f.src = f.src; } }
    });
    item('fa-solid fa-xmark', 'Close tab', false, function () { close(id); });
    item('fa-regular fa-circle-xmark', 'Close other tabs', tabs.length < 2, function () {
      dropFrames(tabs.filter(function (t) { return t.id !== id; }));
      tabs = tabs.filter(function (t) { return t.id === id; });
      show(id);
    });
    item('fa-solid fa-angles-right', 'Close tabs to the right', i >= tabs.length - 1, function () {
      dropFrames(tabs.slice(i + 1));
      tabs = tabs.slice(0, i + 1);
      if (!find(active)) show(id); else { save(); render(); }
    });
    document.body.appendChild(m);
    m.style.left = Math.max(8, Math.min(x, window.innerWidth - m.offsetWidth - 8)) + 'px';
    m.style.top = Math.max(8, Math.min(y, window.innerHeight - m.offsetHeight - 8)) + 'px';
    menuEl = m;
  }
  document.addEventListener('click', closeMenu);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
  window.addEventListener('blur', closeMenu);

  // ---- "+" and double-click on the empty strip ----
  addBtn.addEventListener('click', function (e) { e.stopPropagation(); newTab(); });
  bar.addEventListener('dblclick', function (e) {
    if (e.target === bar || e.target === tabsEl) newTab();
  });

  // ---- Sidebar links: change this tab's page; Ctrl/Cmd/middle = new tab ----
  if (sidebar) {
    sidebar.addEventListener('click', function (e) {
      var a = e.target.closest('a[href]');
      if (!a || e.button !== 0 || e.shiftKey || e.altKey) return;
      var n = norm(a.getAttribute('href'));
      if (!n) return;
      e.preventDefault();
      if (e.ctrlKey || e.metaKey) openTab(n); else gotoHere(n);
      sidebar.classList.remove('mobile-open');
    });
    sidebar.addEventListener('auxclick', function (e) {
      var a = e.target.closest('a[href]');
      if (!a || e.button !== 1) return;
      var n = norm(a.getAttribute('href'));
      if (n) { e.preventDefault(); openTab(n); }
    });
  }

  // ---- "New tab" page: search pages and clients, recent, all pages ----
  function pageEntries() {
    var out = [];
    if (!sidebar) return out;
    sidebar.querySelectorAll('nav:not(.nav-classes) a[href]').forEach(function (a) {
      var n = norm(a.getAttribute('href'));
      var name = (a.getAttribute('title') || a.textContent || '').trim();
      if (n && name) out.push({ url: n, name: name, icon: iconFor(n) });
    });
    return out;
  }
  function section(title) {
    var h = document.createElement('div');
    h.className = 'ct-new-head';
    h.textContent = title;
    newList.appendChild(h);
    var ul = document.createElement('div');
    ul.className = 'ct-new-items';
    newList.appendChild(ul);
    return ul;
  }
  function entry(ul, icon, name, sub, url) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'ct-new-item';
    var i = document.createElement('i');
    i.className = icon;
    var n = document.createElement('span');
    n.className = 'ct-new-name';
    n.textContent = name;
    b.appendChild(i);
    b.appendChild(n);
    if (sub) { var s = document.createElement('span'); s.className = 'ct-new-sub'; s.textContent = sub; b.appendChild(s); }
    b.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey) openTab(url, name); else gotoHere(url, name);
    });
    ul.appendChild(b);
  }
  function renderNew() {
    var q = newSearch.value.trim().toLowerCase();
    newList.textContent = '';
    var ps = pageEntries();
    if (!q) {
      var rs = recents();
      if (rs.length) {
        var ru = section('Recent');
        rs.forEach(function (r) { entry(ru, iconFor(r.url), r.title, '', r.url); });
      }
      var pu = section('Pages');
      ps.forEach(function (p) { entry(pu, p.icon, p.name, '', p.url); });
      return;
    }
    var pm = ps.filter(function (p) { return p.name.toLowerCase().indexOf(q) !== -1; });
    var cm = data.directory.filter(function (c) {
      return (c.name + ' ' + c.email + ' ' + c.ref + ' ' + c.case).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 30);
    if (pm.length) {
      var pu2 = section('Pages');
      pm.forEach(function (p) { entry(pu2, p.icon, p.name, '', p.url); });
    }
    if (cm.length) {
      var cu = section('Clients');
      cm.forEach(function (c) {
        entry(cu, 'fa-regular fa-folder-open', c.name, [c.ref ? '#' + c.ref : '', c.case].filter(Boolean).join(' · '), 'client.php?id=' + c.id);
      });
    }
    if (!pm.length && !cm.length) {
      var none = document.createElement('div');
      none.className = 'ct-new-none';
      none.textContent = 'No pages or clients match “' + newSearch.value.trim() + '”';
      newList.appendChild(none);
    }
  }
  newSearch.addEventListener('input', renderNew);
  newSearch.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { var first = newList.querySelector('.ct-new-item'); if (first) first.click(); }
  });

  // ---- Sidebar unread badges (the frames have no sidebar of their own) ----
  function pollBadges() {
    if (document.hidden) return;
    [['portal', 'navInboxBadge'], ['social', 'navFrontDeskBadge']].forEach(function (p) {
      fetch('api/admin_messages.php?list=1&scope=' + p[0], { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var el = document.getElementById(p[1]);
          if (!d || !d.success || !el) return;
          el.textContent = d.unread_total;
          el.hidden = !d.unread_total;
        }).catch(function () {});
    });
  }
  setInterval(pollBadges, 30000);

  show(active);
})();
