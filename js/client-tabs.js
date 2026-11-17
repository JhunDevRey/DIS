/* Client tabs (admin/client.php).
   Shell mode: a tab bar; each open client is its own iframe that stays
   loaded while hidden, so unsent text and open documents survive a switch.
   Embed mode (body.client-embed, inside a frame): sends admin-page links to
   the top window so they don't open inside the small frame. */
(function () {
  'use strict';

  if (document.body.classList.contains('client-embed')) {
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a || a.target || a.hasAttribute('download') || e.defaultPrevented) return;
      if (/^[a-z_]+\.php([?#]|$)/i.test(a.getAttribute('href') || '')) a.target = '_top';
    }, true);
    return;
  }

  var dataEl = document.getElementById('ctData');
  if (!dataEl) return;
  var data = JSON.parse(dataEl.textContent);
  var KEY = 'clientTabs', MAX = 12;
  var tabsEl = document.getElementById('ctTabs');
  var framesEl = document.getElementById('ctFrames');
  var addBtn = document.getElementById('ctAdd');
  var pop = document.getElementById('ctPop');
  var search = document.getElementById('ctSearch');
  var results = document.getElementById('ctResults');
  var suffix = ' · Duque Immigration Document Portal';

  function load() {
    try {
      var v = JSON.parse(localStorage.getItem(KEY) || '[]');
      return (Array.isArray(v) ? v : []).map(function (t) {
        return { id: parseInt(t && t.id, 10), name: String((t && t.name) || '').slice(0, 120) };
      }).filter(function (t) { return t.id > 0; });
    } catch (e) { return []; }
  }
  function save() { try { localStorage.setItem(KEY, JSON.stringify(tabs)); } catch (e) {} }

  var tabs = load();
  var active = data.current.id;
  var frames = {};

  function find(id) { return tabs.filter(function (t) { return t.id === id; })[0]; }

  // Make sure the client in the URL is a tab; cap the list, dropping the
  // oldest tab that isn't the active one.
  (function ensure() {
    var t = find(active);
    if (t) t.name = data.current.name;
    else tabs.push({ id: active, name: data.current.name });
    while (tabs.length > MAX) {
      var i = tabs.findIndex(function (x) { return x.id !== active; });
      tabs.splice(i, 1);
    }
    save();
  })();

  function render() {
    tabsEl.textContent = '';
    tabs.forEach(function (t) {
      var tab = document.createElement('div');
      tab.className = 'ct-tab' + (t.id === active ? ' on' : '');
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-selected', t.id === active ? 'true' : 'false');
      tab.tabIndex = 0;
      tab.title = t.name || ('Client #' + t.id);
      tab.dataset.id = t.id;
      var label = document.createElement('span');
      label.className = 'ct-name';
      label.textContent = t.name || ('Client #' + t.id);
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'ct-x';
      x.title = 'Close tab';
      x.setAttribute('aria-label', 'Close ' + (t.name || 'tab'));
      x.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      x.addEventListener('click', function (e) { e.stopPropagation(); close(t.id); });
      tab.appendChild(label);
      tab.appendChild(x);
      tab.addEventListener('click', function () { show(t.id); });
      tab.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(t.id); }
      });
      tab.addEventListener('auxclick', function (e) { if (e.button === 1) { e.preventDefault(); close(t.id); } });
      tabsEl.appendChild(tab);
    });
  }

  function frameFor(id) {
    if (frames[id]) return frames[id];
    var f = document.createElement('iframe');
    f.className = 'ct-frame';
    f.title = 'Client file';
    f.src = 'client.php?id=' + id + '&embed=1';
    f.hidden = true;
    f.addEventListener('load', function () {
      try {
        var path = f.contentWindow.location.pathname;
        if (/\/client\.php$/.test(path)) {
          var h1 = f.contentDocument.querySelector('.client-hero-id h1');
          var t = find(id);
          if (h1 && t && h1.textContent.trim() && t.name !== h1.textContent.trim()) {
            t.name = h1.textContent.trim();
            save(); render();
            if (id === active) document.title = t.name + suffix;
          }
        } else if (/\/clients\.php$/.test(path)) {
          close(id); // client no longer exists
        }
      } catch (e) { /* navigating: ignore */ }
    });
    framesEl.appendChild(f);
    frames[id] = f;
    return f;
  }

  function show(id) {
    var t = find(id);
    if (!t) return;
    active = id;
    Object.keys(frames).forEach(function (k) { frames[k].hidden = true; });
    frameFor(id).hidden = false;
    document.title = (t.name || 'Client') + suffix;
    try { history.replaceState(null, '', 'client.php?id=' + id); } catch (e) {}
    render();
    save();
  }

  function close(id) {
    var i = tabs.findIndex(function (t) { return t.id === id; });
    if (i < 0) return;
    tabs.splice(i, 1);
    if (frames[id]) { frames[id].remove(); delete frames[id]; }
    save();
    if (!tabs.length) { location.href = 'clients.php'; return; }
    if (id === active) show(tabs[Math.min(i, tabs.length - 1)].id);
    else render();
  }

  function openClient(id, name) {
    if (!find(id)) {
      if (tabs.length >= MAX) {
        var j = tabs.findIndex(function (x) { return x.id !== active; });
        var gone = tabs.splice(j, 1)[0];
        if (frames[gone.id]) { frames[gone.id].remove(); delete frames[gone.id]; }
      }
      tabs.push({ id: id, name: name });
    }
    show(id);
  }

  // ---- "+" picker ----
  function renderResults() {
    var q = search.value.trim().toLowerCase();
    var list = data.directory.filter(function (c) {
      return !q || (c.name + ' ' + c.email + ' ' + c.ref + ' ' + c.case).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 30);
    results.textContent = '';
    if (!list.length) {
      var li = document.createElement('li');
      li.className = 'ct-none';
      li.textContent = 'No clients found';
      results.appendChild(li);
      return;
    }
    list.forEach(function (c) {
      var li = document.createElement('li');
      var b = document.createElement('button');
      b.type = 'button';
      var n = document.createElement('strong');
      n.textContent = c.name;
      var sub = document.createElement('span');
      sub.textContent = [c.ref ? '#' + c.ref : '', c.case, find(c.id) ? 'open' : ''].filter(Boolean).join(' · ');
      b.appendChild(n); b.appendChild(sub);
      b.addEventListener('click', function () { closePop(); openClient(c.id, c.name); });
      li.appendChild(b);
      results.appendChild(li);
    });
  }
  function openPop() {
    // Anchor the popover under the + button, kept inside the window.
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

  show(active);
})();
