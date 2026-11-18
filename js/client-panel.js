/* Client side panel — shared by Clients, Front Desk, Processors and Inbox.
 *
 * A plain click on any link to a client file (client.php?id=N) opens that
 * client in a panel over the current page instead of leaving it. Ctrl/Cmd,
 * Shift or middle-click still open it in a new tab (portal tab or browser
 * tab), and links with data-no-panel are left alone.
 *
 *   ClientPanel.open(id, name)   open a client
 *   ClientPanel.close()          close it
 *   ClientPanel.handle(href, e)  open href in the panel if it's a client
 *                                file and a plain click; returns true if so
 *   ClientPanel.onChange = fn    called on close if the client page was saved
 *                                (it reloads itself after a save)
 */
(function () {
  'use strict';
  if (window.ClientPanel) return;

  var CLIENT_RE = /^(?:\.\/)?client\.php\?id=(\d+)(?:[&#].*)?$/;

  var wrap = document.createElement('div');
  wrap.className = 'side-panel-backdrop';
  wrap.id = 'clientPanel';
  wrap.innerHTML =
    '<div class="side-panel client-panel" role="dialog" aria-modal="true" aria-labelledby="clientPanelName">' +
      '<div class="client-panel-bar">' +
        '<h3 id="clientPanelName">Client</h3>' +
        '<a class="btn btn-ghost btn-sm" id="clientPanelFull" href="#" title="Open as a full page">' +
          '<i class="fa-solid fa-up-right-and-down-left-from-center"></i><span class="client-panel-full-label"> Open full page</span></a>' +
        '<button type="button" class="side-panel-close" id="clientPanelClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>' +
      '</div>' +
      '<iframe class="client-panel-frame" id="clientPanelFrame" title="Client file"></iframe>' +
    '</div>';

  var frame, nameEl, currentId = 0, loads = 0, timer = null, lastFocus = null;

  function mount() {
    if (wrap.parentNode) return;
    document.body.appendChild(wrap);
    frame = wrap.querySelector('#clientPanelFrame');
    nameEl = wrap.querySelector('#clientPanelName');

    frame.addEventListener('load', function () {
      if (!currentId) return;
      loads++;
      try {
        var doc = frame.contentDocument;
        var h1 = doc.querySelector('.client-hero-id h1');
        if (h1) nameEl.textContent = h1.textContent.trim();
        // Esc inside the client page closes the panel too (unless a dialog of its own is open).
        doc.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && !doc.querySelector('.modal-backdrop.open, .modal-backdrop.show')) close();
        });
        // The client was deleted (the page sent us back to the list).
        if (!/\/client\.php$/.test(frame.contentWindow.location.pathname)) close();
      } catch (e) { /* still loading */ }
    });
    wrap.querySelector('#clientPanelClose').addEventListener('click', close);
    wrap.addEventListener('click', function (e) { if (e.target === wrap) close(); });
    wrap.querySelector('#clientPanelFull').addEventListener('click', function (e) {
      e.preventDefault();
      var url = 'client.php?id=' + currentId;
      var tabs = tabsApi();
      if (tabs) { tabs.open(url); close(); }
      else location.href = url;
    });
  }

  function tabsApi() {
    try { return (window.top && window.top.PortalTabs) || null; } catch (e) { return null; }
  }

  function isOpen() { return wrap.classList.contains('open'); }

  function open(id, name) {
    id = parseInt(id, 10);
    if (!id) return;
    mount();
    clearTimeout(timer);
    if (!isOpen()) lastFocus = document.activeElement;
    currentId = id;
    loads = 0;
    nameEl.textContent = name || 'Client';
    frame.src = 'client.php?id=' + id + '&embed=1&panel=1';
    wrap.classList.add('open');
    try { history.replaceState(history.state, '', location.pathname + location.search + '#client=' + id); } catch (e) {}
  }

  function close() {
    if (!isOpen()) return;
    wrap.classList.remove('open');
    try { history.replaceState(history.state, '', location.pathname + location.search); } catch (e) {}
    var changed = loads > 1; // the client page reloads itself after a save
    currentId = 0;
    clearTimeout(timer);
    timer = setTimeout(function () { frame.src = 'about:blank'; }, 300);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    if (changed && typeof api.onChange === 'function') api.onChange();
  }

  function newTab(href) {
    var tabs = tabsApi();
    if (tabs) tabs.open(href.replace(/[&#].*$/, '').replace(/^\.\//, ''));
    else window.open(href, '_blank');
  }

  // Returns true if it took care of the click.
  function handle(href, e) {
    var m = CLIENT_RE.exec(href || '');
    if (!m) return false;
    if (e && (e.ctrlKey || e.metaKey)) { if (e.preventDefault) e.preventDefault(); newTab(href); return true; }
    if (e && (e.shiftKey || e.altKey || (e.button !== undefined && e.button !== 0))) return false;
    if (e && e.preventDefault) e.preventDefault();
    open(m[1], '');
    return true;
  }

  // Plain clicks on client-file links anywhere on the page.
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target || a.hasAttribute('download') || a.hasAttribute('data-no-panel')) return;
    if (a.closest('#clientPanel')) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return; // new tab handled by the browser / portal
    var href = a.getAttribute('href');
    var m = CLIENT_RE.exec(href || '');
    if (!m) return;
    e.preventDefault();
    var label = a.getAttribute('data-client-name') || (a.textContent || '').trim();
    open(m[1], label.length > 1 && label.length < 80 && !/client file/i.test(label) ? label : '');
  });

  // Esc closes the panel first (before a panel underneath it, like Processors').
  window.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !isOpen()) return;
    if (document.querySelector('.modal-backdrop.open, .modal-backdrop.show')) return;
    e.stopImmediatePropagation();
    close();
  }, true);

  // Reopen after a refresh (#client=ID).
  function reopen() {
    var m = location.hash.match(/^#client=(\d+)$/);
    if (m) open(m[1], '');
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', reopen);
  else reopen();

  var api = { open: open, close: close, handle: handle, isOpen: isOpen, onChange: null };
  window.ClientPanel = api;
})();
