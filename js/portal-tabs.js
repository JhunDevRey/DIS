/* Portal tabs (shell page: admin/_portal_tabs.php).
   Every tab is a staff page in its own iframe that stays loaded while
   hidden, so typed text, scroll position and open documents survive a
   switch. Sidebar links change the current tab's page; Ctrl/Cmd-click or
   middle-click opens a link in a new tab; "+" opens any page or client. */
(function () {
  'use strict';

  var dataEl = document.getElementById('ctData');
  if (!dataEl) return;
  var data = JSON.parse(dataEl.textContent);
  var KEY = 'portalTabs', MAX = 12;
  var SUFFIX = ' · Duque Immigration Document Portal';
  var tabsEl = document.getElementById('ctTabs');
  var framesEl = document.getElementById('ctFrames');
  var addBtn = document.getElementById('ctAdd');
  var pop = document.getElementById('ctPop');
  var search = document.getElementById('ctSearch');
  var results = document.getElementById('ctResults');
  var sidebar = document.getElementById('appSidebar');
  var pages = data.pages || [];

  // "clients.php?class=x" form: file + query, never embed. Returns '' if it
  // isn't one of the tab-able staff pages.
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
  function fileOf(url) { return url.split('?')[0]; }
  function withEmbed(url) { return url + (url.indexOf('?') === -1 ? '?' : '&') + 'embed=1'; }
  function uid() { return 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }

  var tabs = [], active = null, frames = {};

  (function load() {
    try {
      var v = JSON.parse(localStorage.getItem(KEY) || '{}');
      (Array.isArray(v.tabs) ? v.tabs : []).forEach(function (t) {
        var url = norm(t && t.url);
        if (url && t.id) tabs.push({ id: String(t.id).slice(0, 24), url: url, title: String(t.title || '').slice(0, 120) });
      });
      active = v.active;
    } catch (e) { tabs = []; }
  })();
  function save() { try { localStorage.setItem(KEY, JSON.stringify({ tabs: tabs, active: active })); } catch (e) {} }
  function find(id) { return tabs.filter(function (t) { return t.id === id; })[0]; }

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
      var gone = tabs.splice(i, 1)[0];
      if (frames[gone.id]) { frames[gone.id].remove(); delete frames[gone.id]; }
    }
  }

  function label(t) { return t.title || 'Loading…'; }

  var ICONS = {
    'frontdesk.php': 'fa-solid fa-bell-concierge', 'clients.php': 'fa-solid fa-users',
    'client.php': 'fa-regular fa-folder-open', 'inbox.php': 'fa-solid fa-inbox',
    'chats.php': 'fa-regular fa-comments', 'processors.php': 'fa-solid fa-user-tie',
    'staff.php': 'fa-solid fa-users-gear', 'activity.php': 'fa-solid fa-clock-rotate-left',
    'profile.php': 'fa-solid fa-id-badge', 'dashboard.php': 'fa-solid fa-gauge',
    'classes.php': 'fa-solid fa-layer-group'
  };

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
      ico.className = 'ct-ico ' + (ICONS[fileOf(t.url)] || 'fa-regular fa-file');
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
        var r = tab.getBoundingClientRect(), after = e.clientX > r.left + r.width / 2;
        clearDrops();
        tab.classList.add(after ? 'drop-after' : 'drop-before');
      });
      tab.addEventListener('drop', function (e) {
        if (!dragId || dragId === t.id) return;
        e.preventDefault();
        var r = tab.getBoundingClientRect(), after = e.clientX > r.left + r.width / 2;
        move(dragId, t.id, after);
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
      if (h) {
        var hf = fileOf(h);
        var hc = new URLSearchParams(h.split('?')[1] || '').get('class') || '';
        if (hf === 'clients.php') on = (file === 'clients.php' || file === 'client.php') && hc === (file === 'clients.php' ? cls : '');
        else on = hf === file || (hf === 'inbox.php' && file === 'inbox.php');
      }
      a.classList.toggle('active', on);
    });
  }

  function stripSuffix(s) { return String(s || '').replace(SUFFIX, '').trim(); }

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
    document.title = (t.title ? t.title + SUFFIX : SUFFIX.trim());
    try { history.replaceState(null, '', t.url); } catch (e) {}
  }

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
        // A tab page that came back as a whole tab shell (old service worker,
        // header missing): reload it as a plain embedded page.
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
        save(); render();
        if (cur.id === active) chrome();
      } catch (e) { /* navigating: ignore */ }
    });
    f.src = withEmbed(t.url);
    framesEl.appendChild(f);
    frames[t.id] = f;
    return f;
  }

  var dragId = null;
  function clearDrops() {
    tabsEl.querySelectorAll('.drop-before,.drop-after').forEach(function (n) { n.classList.remove('drop-before', 'drop-after'); });
  }
  function move(id, targetId, after) {
    var from = tabs.findIndex(function (x) { return x.id === id; });
    if (from < 0) return;
    var item = tabs.splice(from, 1)[0];
    var to = tabs.findIndex(function (x) { return x.id === targetId; });
    tabs.splice(after ? to + 1 : to, 0, item);
    dragId = null; save(); render();
  }

  // ---- Right-click menu on a tab ----
  var menuEl = null;
  function closeMenu() { if (menuEl) { menuEl.remove(); menuEl = null; } }
  function dropFrames(list) {
    list.forEach(function (t) {
      if (frames[t.id]) { frames[t.id].remove(); delete frames[t.id]; }
    });
  }
  function tabMenu(x, y, id) {
    closeMenu();
    var i = tabs.findIndex(function (t) { return t.id === id; });
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
    item('fa-regular fa-copy', 'Duplicate tab', false, function () {
      var src = find(id), n = { id: uid(), url: src.url, title: src.title };
      tabs.splice(i + 1, 0, n); trim(); show(n.id);
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

  function show(id) {
    var t = find(id);
    if (!t) return;
    active = id;
    Object.keys(frames).forEach(function (k) { frames[k].hidden = true; });
    frameFor(t).hidden = false;
    render(); chrome(); save();
  }

  function close(id) {
    var i = tabs.findIndex(function (t) { return t.id === id; });
    if (i < 0) return;
    tabs.splice(i, 1);
    if (frames[id]) { frames[id].remove(); delete frames[id]; }
    if (!tabs.length) { openTab('clients.php'); return; }
    save();
    if (id === active) show(tabs[Math.min(i, tabs.length - 1)].id);
    else render();
  }

  // New tab for a page (or select the tab already showing exactly that page).
  function openTab(url, titleHint) {
    var n = norm(url);
    if (!n) return;
    var t = tabs.filter(function (x) { return x.url === n; })[0];
    if (!t) { t = { id: uid(), url: n, title: titleHint || '' }; tabs.push(t); trim(); }
    show(t.id);
  }
  // Same tab: show another page in the active tab.
  function gotoHere(url) {
    var n = norm(url);
    var t = find(active);
    if (!n || !t) return;
    t.url = n; t.title = '';
    var f = frames[t.id];
    if (f) f.src = withEmbed(n); else frameFor(t);
    render(); chrome(); save();
  }
  window.PortalTabs = { open: function (u) { openTab(u); } };

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

  // ---- "+" picker: any page (from the sidebar) or any client ----
  function pageEntries() {
    var out = [];
    if (!sidebar) return out;
    sidebar.querySelectorAll('nav:not(.nav-classes) a[href]').forEach(function (a) {
      var n = norm(a.getAttribute('href'));
      var name = (a.getAttribute('title') || a.textContent || '').trim();
      if (n && name) out.push({ url: n, name: name, icon: (a.querySelector('i') || {}).className || '' });
    });
    return out;
  }
  function addItem(ul, name, sub, icon, onPick) {
    var li = document.createElement('li');
    var b = document.createElement('button');
    b.type = 'button';
    var n = document.createElement('strong');
    if (icon) { var i = document.createElement('i'); i.className = icon; n.appendChild(i); n.appendChild(document.createTextNode(' ')); }
    n.appendChild(document.createTextNode(name));
    b.appendChild(n);
    if (sub) { var s = document.createElement('span'); s.textContent = sub; b.appendChild(s); }
    b.addEventListener('click', function () { closePop(); onPick(); });
    li.appendChild(b);
    ul.appendChild(li);
  }
  function heading(ul, text) {
    var li = document.createElement('li');
    li.className = 'ct-head';
    li.textContent = text;
    ul.appendChild(li);
  }
  function renderResults() {
    var q = search.value.trim().toLowerCase();
    results.textContent = '';
    var ps = pageEntries().filter(function (p) { return !q || p.name.toLowerCase().indexOf(q) !== -1; });
    var cs = data.directory.filter(function (c) {
      return q && (c.name + ' ' + c.email + ' ' + c.ref + ' ' + c.case).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 25);
    if (!q) cs = data.directory.slice(0, 8);
    if (ps.length) {
      heading(results, 'Pages');
      ps.forEach(function (p) { addItem(results, p.name, '', p.icon, function () { openTab(p.url, p.name); }); });
    }
    if (cs.length) {
      heading(results, q ? 'Clients' : 'Clients (type to search)');
      cs.forEach(function (c) {
        var sub = [c.ref ? '#' + c.ref : '', c.case].filter(Boolean).join(' · ');
        addItem(results, c.name, sub, 'fa-regular fa-folder-open', function () { openTab('client.php?id=' + c.id, c.name); });
      });
    }
    if (!ps.length && !cs.length) {
      var li = document.createElement('li');
      li.className = 'ct-none';
      li.textContent = 'Nothing found';
      results.appendChild(li);
    }
  }
  function openPop() {
    var r = addBtn.getBoundingClientRect();
    pop.hidden = false;
    pop.style.top = Math.round(r.bottom + 4) + 'px';
    pop.style.left = Math.max(8, Math.min(Math.round(r.left), window.innerWidth - pop.offsetWidth - 8)) + 'px';
    addBtn.setAttribute('aria-expanded', 'true');
    search.value = '';
    renderResults();
    search.focus();
  }
  function closePop() {
    pop.hidden = true;
    addBtn.setAttribute('aria-expanded', 'false');
  }
  addBtn.addEventListener('click', function (e) { e.stopPropagation(); if (pop.hidden) openPop(); else closePop(); });
  search.addEventListener('input', renderResults);
  search.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closePop(); addBtn.focus(); }
    if (e.key === 'Enter') { var first = results.querySelector('button'); if (first) first.click(); }
  });
  document.addEventListener('click', function (e) {
    if (!pop.hidden && !pop.contains(e.target) && e.target !== addBtn) closePop();
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
