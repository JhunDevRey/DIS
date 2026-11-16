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
  let channel = 'all';
  const STATE_KEY = 'duqueFrontDeskMsg';
  const SEND_AS_KEY = 'duqueMsgSendAs'; // shared with the Inbox composer
  const MESSAGE_MAX_LENGTH = 5000;
  const senders = JSON.parse(document.getElementById('fdSenders').textContent || '[]');
  // Quick reply: the conversation whose reply box is open, and its draft
  // (kept so a tab switch or list refresh doesn't lose what was typed).
  let replyFor = 0;
  let replyDraft = '';

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
  const tabs = document.getElementById('fdChannelTabs');
  const unreadOnly = document.getElementById('fdUnreadOnly');
  const onChannel = (conv, ch) => ch === 'all' || (conv.channels || ['portal']).includes(ch);

  // Remember the tab + Unread toggle on this device.
  try {
    const saved = JSON.parse(localStorage.getItem(STATE_KEY) || '{}');
    if (saved.ch && tabs.querySelector(`[data-channel="${saved.ch}"]`)) channel = saved.ch;
    unreadOnly.checked = !!saved.unread;
  } catch (e) { /* storage blocked — defaults */ }
  const saveState = () => {
    try { localStorage.setItem(STATE_KEY, JSON.stringify({ ch: channel, unread: unreadOnly.checked })); } catch (e) {}
  };

  function clientById(id) {
    return data.clients.find(c => c.client_id === id) || null;
  }

  function renderMessages() {
    const unread = data.conversations.filter(c => c.unread_count > 0);
    countEl.textContent = unread.length ? `${unread.length} unread` : 'All caught up';

    tabs.querySelectorAll('[data-channel]').forEach(btn => {
      const ch = btn.dataset.channel;
      btn.classList.toggle('on', ch === channel);
      btn.setAttribute('aria-selected', ch === channel ? 'true' : 'false');
      btn.classList.toggle('off', btn.dataset.connected === '0');
      btn.querySelector('[data-channel-count]').textContent = unread.filter(c => onChannel(c, ch)).length || '';
    });

    const tab = tabs.querySelector(`[data-channel="${channel}"]`);
    if (tab && tab.dataset.connected === '0') {
      list.innerHTML = `<li class="fd-empty fd-not-connected">
        <i class="${esc(tab.querySelector('i').className)}"></i>
        <strong>${esc(tab.dataset.label)} isn't connected yet</strong>
        <span>Once it is, ${esc(tab.dataset.label)} chats will show up here.</span>
      </li>`;
      return;
    }

    const rows = data.conversations
      .filter(c => onChannel(c, channel) && (!unreadOnly.checked || c.unread_count > 0))
      .slice(0, MAX_ROWS);
    if (!rows.length) {
      list.innerHTML = `<li class="fd-empty">${unreadOnly.checked
        ? '<i class="fa-solid fa-circle-check"></i> No unread messages.'
        : 'No conversations yet.'}</li>`;
      return;
    }
    list.innerHTML = rows.map(conv => {
      const isUnread = conv.unread_count > 0;
      return `<li class="fd-row" data-client-id="${conv.client_id}"><a class="fd-item${isUnread ? ' unread' : ''}" href="inbox.php?client_id=${conv.client_id}">
        <span class="fd-dot"${isUnread ? '' : ' hidden'}></span>
        <span class="avatar">${esc(initial(conv.full_name))}</span>
        <span class="fd-main">
          <span class="fd-name">${esc(conv.full_name)} ${classTag(clientById(conv.client_id))}${conv.unread_count > 1 ? `<span class="fd-n">${conv.unread_count}</span>` : ''}</span>
          <span class="fd-sub">${esc(conv.preview)}</span>
        </span>
        <span class="fd-time">${esc(timeAgo(conv.last_at))}</span>
      </a><button type="button" class="fd-reply-btn" data-reply="${conv.client_id}" title="Quick reply to ${esc(conv.full_name)}" aria-label="Quick reply to ${esc(conv.full_name)}"><i class="fa-solid fa-reply"></i></button></li>`;
    }).join('');
    if (replyFor) {
      if (list.querySelector(`.fd-row[data-client-id="${replyFor}"]`)) openReply(replyFor, false);
      else { replyFor = 0; replyDraft = ''; }
    }
  }

  // ---- Quick reply ----
  function senderOptions() {
    let saved = '';
    try { saved = localStorage.getItem(SEND_AS_KEY) || ''; } catch (e) {}
    const locked = document.getElementById('fdSenders').dataset.locked === '1';
    return (locked ? '' : `<option value="">Duque Immigration (team)</option>`) + senders.map(s =>
      `<option value="${s.id}"${String(s.id) === saved ? ' selected' : ''}>${esc(s.name)}</option>`).join('');
  }

  function closeReply() {
    replyFor = 0;
    replyDraft = '';
    list.querySelectorAll('.fd-reply').forEach(el => el.remove());
    list.querySelectorAll('.fd-row.replying').forEach(el => el.classList.remove('replying'));
  }

  function openReply(clientId, focus = true) {
    list.querySelectorAll('.fd-reply').forEach(el => el.remove());
    list.querySelectorAll('.fd-row.replying').forEach(el => el.classList.remove('replying'));
    const row = list.querySelector(`.fd-row[data-client-id="${clientId}"]`);
    const conv = data.conversations.find(c => c.client_id === clientId);
    if (!row || !conv) return;
    replyFor = clientId;
    row.classList.add('replying');
    const first = String(conv.full_name).trim().split(/\s+/)[0];
    // WhatsApp on: reply on the channel the client last wrote on (staff
    // can switch). The server falls back to the portal when it can't send
    // on WhatsApp, and says so in the toast.
    const waOn = list.dataset.wa === '1';
    const via = waOn && conv.reply_channel === 'whatsapp' ? 'whatsapp' : 'portal';
    const viaHtml = waOn ? `<label class="fd-reply-via"><select aria-label="Send via">
          <option value="portal"${via === 'portal' ? ' selected' : ''}>Portal</option>
          <option value="whatsapp"${via === 'whatsapp' ? ' selected' : ''}>WhatsApp</option>
        </select></label>` : '';
    const box = document.createElement('form');
    box.className = 'fd-reply';
    box.innerHTML = `
      <textarea rows="2" maxlength="${MESSAGE_MAX_LENGTH}" placeholder="Reply to ${esc(first)}…" aria-label="Reply to ${esc(conv.full_name)}"></textarea>
      <div class="fd-reply-bar">
        <label class="fd-reply-as"><i class="fa-solid fa-user-pen"></i><select aria-label="Sending as">${senderOptions()}</select></label>
        ${viaHtml}
        <span class="fd-reply-actions">
          <button type="button" class="btn btn-ghost btn-sm" data-cancel>Cancel</button>
          <button type="submit" class="btn btn-gold btn-sm"><i class="fa-solid fa-paper-plane"></i> Send</button>
        </span>
      </div>
      <div class="fd-reply-note"><span class="fd-reply-hint">Ctrl + Enter to send · </span><span data-via-note></span></div>`;
    row.appendChild(box);
    const ta = box.querySelector('textarea');
    const sel = box.querySelector('.fd-reply-as select');
    const viaSel = box.querySelector('.fd-reply-via select');
    const note = box.querySelector('[data-via-note]');
    const setNote = () => {
      note.textContent = viaSel && viaSel.value === 'whatsapp'
        ? 'Goes to their WhatsApp (within 24h of their last WhatsApp; otherwise portal + a WhatsApp notice).'
        : 'Goes to their portal Messages; they get an email notice to log in.';
    };
    setNote();
    if (viaSel) viaSel.addEventListener('change', setNote);
    ta.value = replyDraft;
    const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 180) + 'px'; };
    grow();
    ta.addEventListener('input', () => { replyDraft = ta.value; grow(); });
    ta.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); box.requestSubmit(); }
      else if (e.key === 'Escape') { e.preventDefault(); closeReply(); }
    });
    sel.addEventListener('change', () => { try { localStorage.setItem(SEND_AS_KEY, sel.value); } catch (e) {} });
    box.querySelector('[data-cancel]').addEventListener('click', closeReply);
    box.addEventListener('submit', (e) => { e.preventDefault(); sendReply(box, conv); });
    if (focus) { ta.focus(); box.scrollIntoView({ block: 'nearest' }); }
  }

  async function sendReply(box, conv) {
    const ta = box.querySelector('textarea');
    const text = ta.value.trim();
    if (!text) { ta.focus(); return; }
    const btn = box.querySelector('[type=submit]');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('client_id', conv.client_id);
    fd.append('body', text);
    const sel = box.querySelector('.fd-reply-as select');
    if (sel.value) fd.append('sender_id', sel.value);
    const viaSel = box.querySelector('.fd-reply-via select');
    if (viaSel) fd.append('channel', viaSel.value);
    try {
      const res = await fetch('api/admin_messages.php', { method: 'POST', body: fd });
      const out = await res.json();
      if (!out.success) { showToast(out.message || 'Could not send the message.', true); btn.disabled = false; return; }
      // Replying answers everything before it, so the conversation is read.
      const sent = out.messages && out.messages[out.messages.length - 1];
      if (sent && conv.unread_count) {
        const seen = new FormData();
        seen.append('action', 'seen');
        seen.append('client_id', conv.client_id);
        seen.append('up_to_id', sent.id);
        await fetch('api/admin_messages.php', { method: 'POST', body: seen }).catch(() => {});
      }
      closeReply();
      // WhatsApp sends explain what happened (sent / fell back to portal).
      showToast(viaSel && out.message && out.message !== 'Message sent.' ? out.message : `Sent to ${conv.full_name}.`);
      refresh();
    } catch (err) {
      showToast('Could not send — check your connection and try again.', true);
      btn.disabled = false;
    }
  }

  list.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-reply]');
    if (!btn) return;
    const id = Number(btn.dataset.reply);
    if (replyFor === id) closeReply();
    else { replyDraft = ''; openReply(id); }
  });

  tabs.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-channel]');
    if (!btn) return;
    channel = btn.dataset.channel;
    saveState();
    renderMessages();
  });
  unreadOnly.addEventListener('change', () => { saveState(); renderMessages(); });

  async function refresh() {
    try {
      const res = await fetch('api/admin_messages.php?list=1', { headers: { Accept: 'application/json' } });
      const fresh = await res.json();
      if (!fresh.success) return;
      data = fresh;
      const typing = replyFor && list.contains(document.activeElement) && document.activeElement.closest('.fd-reply');
      if (!typing) renderMessages();
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

  // ---- Notion · Inquiries window ----
  // Read-only list of recent inquiries from the Notion 📬 Inquiries database
  // (via api/frontdesk_inquiries.php, cached ~60s on the server). Clicking a
  // row opens the inquiry in Notion; email/phone are one tap away.
  const inqWin = document.getElementById('fdInquiries');
  if (inqWin && inqWin.dataset.enabled === '1') {
    const INQ_KEY = 'duqueFrontDeskInq';
    const INQ_POLL_MS = 120000;
    const INQ_ROWS = 5;
    const inqList = document.getElementById('fdInqList');
    const inqCount = document.getElementById('fdInqCount');
    const inqTabs = document.getElementById('fdInqTabs');
    const inqWebsite = document.getElementById('fdInqWebsite');
    let inqRange = 'today';
    let inqAll = false;
    let inquiries = null;
    try {
      const saved = JSON.parse(localStorage.getItem(INQ_KEY) || '{}');
      if (saved.range === 'week') inqRange = 'week';
      inqWebsite.checked = !!saved.website;
    } catch (e) {}
    const saveInq = () => { try { localStorage.setItem(INQ_KEY, JSON.stringify({ range: inqRange, website: inqWebsite.checked })); } catch (e) {} };

    const isToday = (iso) => new Date(iso).toDateString() === new Date().toDateString();
    const inqWhen = (iso) => {
      const d = new Date(iso);
      return isToday(iso) ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
        : d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
    };

    function inqHtml(q) {
      const tags = [
        q.inquiring_as && `<span class="fd-inq-tag ${q.inquiring_as === 'Canadian Employer' ? 'emp' : 'app'}">${esc(q.inquiring_as)}</span>`,
        q.service && `<span class="fd-inq-tag">${esc(q.service)}</span>`,
        ...(q.case_types || []).slice(0, 3).map(c => `<span class="fd-inq-tag">${esc(c)}</span>`),
        q.from_website ? '<span class="fd-inq-tag web">Website</span>' : (q.heard_via && `<span class="fd-inq-tag">${esc(q.heard_via)}</span>`),
        q.country && `<span class="fd-inq-tag">${esc(q.country)}</span>`,
      ].filter(Boolean).join('');
      const contact = [
        q.phone && `<span class="fd-inq-link" role="link" tabindex="0" data-href="tel:${esc(q.phone)}"><i class="fa-solid fa-phone"></i> ${esc(q.phone)}</span>`,
        q.email && `<span class="fd-inq-link" role="link" tabindex="0" data-href="mailto:${esc(q.email)}"><i class="fa-regular fa-envelope"></i> ${esc(q.email)}</span>`,
      ].filter(Boolean).join('');
      return `<li><a class="fd-inq" href="${esc(q.url)}" target="_blank" rel="noopener">
        <span class="fd-inq-top"><span class="fd-name">${esc(q.name)}</span><span class="fd-time">${esc(inqWhen(q.created_at))}</span></span>
        ${tags ? `<span class="fd-inq-tags">${tags}</span>` : ''}
        ${q.message ? `<span class="fd-inq-msg">${esc(q.message)}</span>` : ''}
        ${contact ? `<span class="fd-inq-contact">${contact}</span>` : ''}
      </a></li>`;
    }

    function renderInquiries() {
      inqTabs.querySelectorAll('[data-inq]').forEach(b => b.classList.toggle('on', b.dataset.inq === inqRange));
      if (!inquiries) return;
      const base = inquiries.filter(q => !inqWebsite.checked || q.from_website);
      const today = base.filter(q => isToday(q.created_at));
      inqTabs.querySelector('[data-inq-count="today"]').textContent = today.length || '';
      inqTabs.querySelector('[data-inq-count="week"]').textContent = base.length || '';
      const todayAll = inquiries.filter(q => isToday(q.created_at)).length;
      inqCount.textContent = todayAll ? `${todayAll} new today` : 'None today yet';
      const rows = inqRange === 'today' ? today : base;
      if (!rows.length) {
        inqList.innerHTML = `<li class="fd-empty">${inqRange === 'today' ? 'No new inquiries today yet.' : 'No inquiries in the last 7 days.'}</li>`;
        return;
      }
      const shown = inqAll ? rows : rows.slice(0, INQ_ROWS);
      inqList.innerHTML = shown.map(inqHtml).join('') +
        (rows.length > shown.length ? `<li><button type="button" class="fd-inq-more" data-inq-more>Show ${rows.length - shown.length} more</button></li>` : '');
    }

    async function loadInquiries(fresh = false) {
      try {
        const res = await fetch('api/frontdesk_inquiries.php' + (fresh ? '?fresh=1' : ''), { headers: { Accept: 'application/json' } });
        const out = await res.json();
        if (!out.success) {
          if (!inquiries) inqList.innerHTML = `<li class="fd-empty fd-inq-error">${esc(out.message || 'Could not load inquiries.')}</li>`;
          if (fresh) showToast(out.message || 'Could not load inquiries.', true);
          return;
        }
        inquiries = out.inquiries || [];
        renderInquiries();
      } catch (e) {
        if (!inquiries) inqList.innerHTML = '<li class="fd-empty fd-inq-error">Could not load inquiries. Check your connection.</li>';
      }
    }

    inqTabs.addEventListener('click', (e) => {
      const b = e.target.closest('[data-inq]');
      if (!b) return;
      inqRange = b.dataset.inq;
      inqAll = false;
      saveInq();
      renderInquiries();
    });
    inqWebsite.addEventListener('change', () => { inqAll = false; saveInq(); renderInquiries(); });
    inqList.addEventListener('click', (e) => {
      if (e.target.closest('[data-inq-more]')) { inqAll = true; renderInquiries(); return; }
      // Phone / email (spans, since links can't nest in the row link) call
      // or email instead of opening Notion.
      const contact = e.target.closest('[data-href]');
      if (contact) { e.preventDefault(); location.href = contact.dataset.href; }
    });
    const refreshBtn = document.getElementById('fdInqRefresh');
    refreshBtn.addEventListener('click', async () => {
      refreshBtn.disabled = true;
      await loadInquiries(true);
      refreshBtn.disabled = false;
    });

    renderInquiries();
    loadInquiries();
    setInterval(() => { if (!document.hidden) loadInquiries(); }, INQ_POLL_MS);
  }

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
