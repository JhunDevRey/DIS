// js/frontdesk.js — staff Front Desk (admin/frontdesk.php).
// Greeting, the client quick-search, and the live Messaging window. The
// window re-polls the same endpoint the Inbox list uses, so both always
// agree on what's unread.
(function () {
  'use strict';

  const LIST_POLL_MS = 20000;
  const MAX_ROWS = 6;
  const MAX_RESULTS = 8;

  let data = JSON.parse(document.getElementById('fdData').textContent);
  let filter = 'all';

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const initial = (name) => (String(name || '?').trim()[0] || '?').toUpperCase();
  const classTag = (c) => c && c.case_type
    ? `<span class="class-tag class-tag-${esc(c.class_slug || 'other')}">${esc(c.case_type)}</span>` : '';

  // MySQL datetimes are stored in UTC.
  function timeAgo(mysql) {
    const t = Date.parse(String(mysql).replace(' ', 'T') + 'Z');
    if (isNaN(t)) return '';
    const s = Math.max(0, (Date.now() - t) / 1000);
    if (s < 60) return 'now';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    if (s < 86400) return Math.floor(s / 3600) + 'h';
    if (s < 172800) return 'Yesterday';
    return new Date(t).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  // ---- Greeting (viewer's local time) ----
  const hour = new Date().getHours();
  document.getElementById('fdGreeting').textContent =
    hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
  document.getElementById('fdDate').textContent =
    new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }) + ' · ';

  // ---- Messaging window ----
  const list = document.getElementById('fdMsgList');
  const countEl = document.getElementById('fdMsgCount');
  const unreadTab = document.getElementById('fdUnreadTab');

  function clientById(id) {
    return data.clients.find(c => c.client_id === id) || null;
  }

  function renderMessages() {
    const unread = data.conversations.filter(c => c.unread_count > 0);
    countEl.textContent = unread.length ? `${unread.length} unread` : 'All caught up';
    unreadTab.textContent = unread.length || '';

    const rows = (filter === 'unread' ? unread : data.conversations).slice(0, MAX_ROWS);
    if (!rows.length) {
      list.innerHTML = `<li class="fd-empty">${filter === 'unread'
        ? '<i class="fa-solid fa-circle-check"></i> No unread messages.'
        : 'No conversations yet.'}</li>`;
      return;
    }
    list.innerHTML = rows.map(conv => {
      const isUnread = conv.unread_count > 0;
      return `<li><a class="fd-item${isUnread ? ' unread' : ''}" href="inbox.php?client_id=${conv.client_id}">
        <span class="fd-dot"${isUnread ? '' : ' hidden'}></span>
        <span class="avatar">${esc(initial(conv.full_name))}</span>
        <span class="fd-main">
          <span class="fd-name">${esc(conv.full_name)} ${classTag(clientById(conv.client_id))}${conv.unread_count > 1 ? `<span class="fd-n">${conv.unread_count}</span>` : ''}</span>
          <span class="fd-sub">${esc(conv.preview)}</span>
        </span>
        <span class="fd-time">${esc(timeAgo(conv.last_at))}</span>
      </a></li>`;
    }).join('');
  }

  document.querySelectorAll('[data-fd-filter]').forEach(btn => btn.addEventListener('click', () => {
    filter = btn.dataset.fdFilter;
    document.querySelectorAll('[data-fd-filter]').forEach(b => b.classList.toggle('on', b === btn));
    renderMessages();
  }));

  async function refresh() {
    try {
      const res = await fetch('api/admin_messages.php?list=1', { headers: { Accept: 'application/json' } });
      const fresh = await res.json();
      if (!fresh.success) return;
      data = fresh;
      renderMessages();
      const badge = document.getElementById('navInboxBadge');
      if (badge) {
        badge.textContent = fresh.unread_total;
        badge.hidden = fresh.unread_total === 0;
      }
    } catch (e) { /* next poll retries */ }
  }

  renderMessages();
  setInterval(() => { if (!document.hidden) refresh(); }, LIST_POLL_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

  // ---- Client quick-search ----
  const input = document.getElementById('fdSearchInput');
  const results = document.getElementById('fdSearchResults');
  let active = 0;

  function matches(q) {
    const words = q.toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return [];
    return data.clients.filter(c => {
      const hay = [c.full_name, c.email, c.case_type, c.class, c.ref, c.processor].join(' ').toLowerCase();
      return words.every(w => hay.includes(w));
    }).slice(0, MAX_RESULTS);
  }

  function renderResults() {
    const found = matches(input.value);
    if (!input.value.trim()) { results.hidden = true; return; }
    active = Math.min(active, Math.max(0, found.length - 1));
    results.innerHTML = found.length ? found.map((c, i) => `
      <div class="fd-result${i === active ? ' active' : ''}">
        <a class="fd-result-main" href="client.php?id=${c.client_id}">
          <span class="avatar">${esc(initial(c.full_name))}</span>
          <span class="fd-main">
            <span class="fd-name">${esc(c.full_name)} ${classTag(c)}</span>
            <span class="fd-sub">${esc([c.ref && '#' + c.ref, c.email, c.processor].filter(Boolean).join(' · '))}</span>
          </span>
        </a>
        <a class="fd-result-msg" href="inbox.php?client_id=${c.client_id}" title="Message ${esc(c.full_name)}" aria-label="Message ${esc(c.full_name)}"><i class="fa-regular fa-comment"></i></a>
      </div>`).join('')
      : '<div class="fd-result-none">No clients match.</div>';
    results.hidden = false;
  }

  input.addEventListener('input', () => { active = 0; renderResults(); });
  input.addEventListener('focus', renderResults);
  input.addEventListener('keydown', (e) => {
    const rows = results.querySelectorAll('.fd-result');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!rows.length) return;
      e.preventDefault();
      active = (active + (e.key === 'ArrowDown' ? 1 : rows.length - 1)) % rows.length;
      renderResults();
    } else if (e.key === 'Enter') {
      const link = rows[active] && rows[active].querySelector('.fd-result-main');
      if (link) { e.preventDefault(); location.href = link.href; }
    } else if (e.key === 'Escape') {
      results.hidden = true;
      input.blur();
    }
  });
  document.addEventListener('click', (e) => {
    if (!document.getElementById('fdSearch').contains(e.target)) results.hidden = true;
  });
  // "/" jumps to the search box from anywhere on the page.
  document.addEventListener('keydown', (e) => {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
    const tag = (document.activeElement && document.activeElement.tagName) || '';
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || document.activeElement.isContentEditable) return;
    e.preventDefault();
    input.focus();
  });
})();
