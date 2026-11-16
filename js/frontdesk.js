// js/frontdesk.js — staff Front Desk (admin/frontdesk.php).
// Greeting and the live windows. The Messaging
// window shows social channels only (WhatsApp, Messenger, Instagram,
// TikTok); portal messages stay in the Processing Inbox.
(function () {
  'use strict';

  const LIST_POLL_MS = 20000;
  const MAX_ROWS = 6;

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
  const onChannel = (conv, ch) => ch === 'all' || (conv.channels || []).includes(ch);
  const anyConnected = list.dataset.anyConnected === '1';
  const waOn = list.dataset.wa === '1';

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
    if (channel === 'all' && !anyConnected && !data.conversations.length) {
      list.innerHTML = `<li class="fd-empty fd-not-connected">
        <i class="fa-solid fa-plug"></i>
        <strong>No chat channels connected yet</strong>
        <span>WhatsApp, Messenger, Instagram and TikTok chats will show up here once they're connected.</span>
      </li>`;
      return;
    }
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
        : 'No chats yet.'}</li>`;
      return;
    }
    list.innerHTML = rows.map(conv => {
      const isUnread = conv.unread_count > 0;
      return `<li class="fd-row" data-client-id="${conv.client_id}"><a class="fd-item${isUnread ? ' unread' : ''}" href="chats.php?client_id=${conv.client_id}">
        <span class="fd-dot"${isUnread ? '' : ' hidden'}></span>
        <span class="avatar">${esc(initial(conv.full_name))}</span>
        <span class="fd-main">
          <span class="fd-name">${esc(conv.full_name)} ${classTag(clientById(conv.client_id))}${conv.unread_count > 1 ? `<span class="fd-n">${conv.unread_count}</span>` : ''}</span>
          <span class="fd-sub">${esc(conv.preview)}</span>
        </span>
        <span class="fd-time">${esc(timeAgo(conv.last_at))}</span>
      </a>${canReply(conv) ? `<button type="button" class="fd-reply-btn" data-reply="${conv.client_id}" title="Quick reply to ${esc(conv.full_name)} on WhatsApp" aria-label="Quick reply to ${esc(conv.full_name)} on WhatsApp"><i class="fa-solid fa-reply"></i></button>` : ''}</li>`;
    }).join('');
    if (replyFor) {
      if (list.querySelector(`.fd-row[data-client-id="${replyFor}"]`)) openReply(replyFor, false);
      else { replyFor = 0; replyDraft = ''; }
    }
  }

  // ---- Quick reply ----
  // WhatsApp is the only social channel that can send from here so far.
  const canReply = (conv) => waOn && (conv.channels || []).includes('whatsapp');

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
    const box = document.createElement('form');
    box.className = 'fd-reply';
    box.innerHTML = `
      <textarea rows="2" maxlength="${MESSAGE_MAX_LENGTH}" placeholder="Reply to ${esc(first)}…" aria-label="Reply to ${esc(conv.full_name)}"></textarea>
      <div class="fd-reply-bar">
        <label class="fd-reply-as"><i class="fa-solid fa-user-pen"></i><select aria-label="Sending as">${senderOptions()}</select></label>
        <span class="fd-reply-actions">
          <button type="button" class="btn btn-ghost btn-sm" data-cancel>Cancel</button>
          <button type="submit" class="btn btn-gold btn-sm"><i class="fa-solid fa-paper-plane"></i> Send</button>
        </span>
      </div>
      <div class="fd-reply-note"><span class="fd-reply-hint">Ctrl + Enter to send · </span><span data-via-note></span></div>`;
    row.appendChild(box);
    const ta = box.querySelector('textarea');
    const sel = box.querySelector('.fd-reply-as select');
    // The server can only send free text on WhatsApp within 24h of the
    // client's last WhatsApp; after that it falls back and says so.
    box.querySelector('[data-via-note]').textContent =
      'Goes to their WhatsApp (within 24h of their last WhatsApp message).';
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
    fd.append('scope', 'social');
    try {
      const res = await fetch('api/admin_messages.php', { method: 'POST', body: fd });
      const out = await res.json();
      if (!out.success) { showToast(out.message || 'Could not send the message.', true); btn.disabled = false; return; }
      // Sending marks the chat read (the Front Desk's own marker only;
      // portal messages stay unread for the Processing Inbox).
      closeReply();
      // WhatsApp sends explain what happened (sent / fell back to portal).
      showToast(out.message && out.message !== 'Message sent.' ? out.message : `Sent to ${conv.full_name}.`);
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
      const res = await fetch('api/admin_messages.php?list=1&scope=social', { headers: { Accept: 'application/json' } });
      const fresh = await res.json();
      if (!fresh.success) return;
      data = fresh;
      const typing = replyFor && list.contains(document.activeElement) && document.activeElement.closest('.fd-reply');
      if (!typing) renderMessages();
      const badge = document.getElementById('navFrontDeskBadge');
      if (badge) {
        badge.textContent = fresh.unread_total;
        badge.hidden = fresh.unread_total === 0;
      }
    } catch (e) { /* next poll retries */ }
  }

  renderMessages();
  setInterval(() => { if (!document.hidden) refresh(); }, LIST_POLL_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

  // ---- Scheduling window (Calendly) ----
  // Today's and the next 7 days' bookings from Calendly (via
  // api/frontdesk_schedule.php, cached ~60s on the server). Times are shown
  // in the office timezone (the API says which); a booker in another
  // timezone also gets their own local time. Invitees whose email matches
  // a client link straight to that client's file.
  const schWin = document.getElementById('fdSchedule');
  if (schWin && schWin.dataset.enabled === '1') {
    const SCH_KEY = 'duqueFrontDeskSch';
    const SCH_POLL_MS = 120000;
    const SCH_ROWS = 6;
    const NEW_HOURS = 24;
    const schList = document.getElementById('fdSchList');
    const schCount = document.getElementById('fdSchCount');
    const schTabs = document.getElementById('fdSchTabs');
    const schCanceled = document.getElementById('fdSchCanceled');
    let schRange = 'today';
    let schAll = false;
    let sched = null;
    try {
      const saved = JSON.parse(localStorage.getItem(SCH_KEY) || '{}');
      if (['today', 'week', 'new'].includes(saved.range)) schRange = saved.range;
      schCanceled.checked = !!saved.canceled;
    } catch (e) {}
    const saveSch = () => { try { localStorage.setItem(SCH_KEY, JSON.stringify({ range: schRange, canceled: schCanceled.checked })); } catch (e) {} };

    const fmt = (iso, tz, opts) => {
      try { return new Date(iso).toLocaleString([], Object.assign({ timeZone: tz }, opts)); }
      catch (e) { return new Date(iso).toLocaleString([], opts); }
    };
    const T = { hour: 'numeric', minute: '2-digit' };
    const dayKey = (iso, tz) => fmt(iso, tz, { year: 'numeric', month: '2-digit', day: '2-digit' });
    const tzShort = (tz) => {
      try {
        const p = new Intl.DateTimeFormat([], { timeZone: tz, timeZoneName: 'short' }).formatToParts(new Date());
        return (p.find(x => x.type === 'timeZoneName') || {}).value || '';
      } catch (e) { return ''; }
    };
    const city = (tz) => (tz || '').split('/').pop().replace(/_/g, ' ');
    const isNewBooking = (ev) => ev.booked_at && Date.now() - new Date(ev.booked_at).getTime() < NEW_HOURS * 3600000;
    const isCanceled = (ev) => ev.status === 'canceled';

    function personHtml(ev, p, tz) {
      const tags = [
        p.client && `<a class="fd-inq-tag client" href="client.php?id=${p.client.client_id}" title="Open client file"><i class="fa-regular fa-folder-open"></i> Client</a>`,
        p.rescheduled && '<span class="fd-inq-tag">Rescheduled</span>',
        p.no_show && '<span class="fd-inq-tag cancel">No-show</span>',
        p.status === 'canceled' && !isCanceled(ev) && '<span class="fd-inq-tag cancel">Canceled</span>',
        p.timezone && p.timezone !== tz && !isCanceled(ev) && fmt(ev.start, p.timezone, T) !== fmt(ev.start, tz, T) &&
          `<span class="fd-inq-tag" title="${esc(p.timezone)}">Their time ${esc(fmt(ev.start, p.timezone, T))} · ${esc(city(p.timezone))}</span>`,
      ].filter(Boolean).join('');
      const contact = [
        p.client && `<a class="fd-inq-link" href="inbox.php?client_id=${p.client.client_id}"><i class="fa-regular fa-comment"></i> Message</a>`,
        p.phone && `<a class="fd-inq-link" href="tel:${esc(p.phone)}"><i class="fa-solid fa-phone"></i> ${esc(p.phone)}</a>`,
        p.email && `<a class="fd-inq-link" href="mailto:${esc(p.email)}"><i class="fa-regular fa-envelope"></i> ${esc(p.email)}</a>`,
      ].filter(Boolean).join('');
      const qa = (p.answers || []).slice(0, 2).map(a => `<span class="fd-sch-qa"><b>${esc(a.q)}</b> ${esc(a.a)}</span>`).join('');
      return `<div class="fd-sch-person">
        <span class="fd-name">${esc(p.name)}</span>
        ${tags ? `<span class="fd-inq-tags">${tags}</span>` : ''}
        ${qa}
        ${contact ? `<span class="fd-inq-contact">${contact}</span>` : ''}
      </div>`;
    }

    function schHtml(ev, tz, now, nextId) {
      const start = new Date(ev.start).getTime();
      const end = new Date(ev.end).getTime();
      const state = isCanceled(ev) ? 'canceled'
        : (start <= now && now < end) ? 'now'
        : end <= now ? 'past'
        : ev.id === nextId ? 'next' : '';
      const people = ev.invitees.length ? ev.invitees : [{ name: '(no invitee)', answers: [] }];
      const evTags = [
        state === 'now' && '<span class="fd-inq-tag now">Now</span>',
        state === 'next' && '<span class="fd-inq-tag next">Up next</span>',
        isCanceled(ev) && `<span class="fd-inq-tag cancel">Canceled${ev.canceled_by ? ' by ' + esc(ev.canceled_by) : ''}</span>`,
        isNewBooking(ev) && !isCanceled(ev) && `<span class="fd-inq-tag web" title="Booked ${esc(fmt(ev.booked_at, tz, { weekday: 'short', hour: 'numeric', minute: '2-digit' }))}">New</span>`,
        ev.location.label && `<span class="fd-inq-tag">${esc(ev.location.label)}</span>`,
        ev.hosts.length && `<span class="fd-inq-tag"><i class="fa-regular fa-user"></i> ${esc(ev.hosts.join(', '))}</span>`,
      ].filter(Boolean).join('');
      const join = ev.location.join_url && !isCanceled(ev) && state !== 'past'
        ? `<a class="btn btn-ghost btn-sm fd-sch-join" href="${esc(ev.location.join_url)}" target="_blank" rel="noopener"><i class="fa-solid fa-video"></i> Join</a>` : '';
      return `<li class="fd-sch${state ? ' is-' + state : ''}">
        <span class="fd-sch-time"><b>${esc(fmt(ev.start, tz, T))}</b><span>${esc(fmt(ev.end, tz, T))}</span></span>
        <span class="fd-sch-body">
          <span class="fd-inq-top"><span class="fd-name fd-sch-title">${esc(ev.name)}</span>${join}</span>
          ${evTags ? `<span class="fd-inq-tags">${evTags}</span>` : ''}
          ${ev.cancel_reason ? `<span class="fd-inq-msg">“${esc(ev.cancel_reason)}”</span>` : ''}
          ${people.map(p => personHtml(ev, p, tz)).join('')}
        </span>
      </li>`;
    }

    function renderSchedule() {
      schTabs.querySelectorAll('[data-sch]').forEach(b => b.classList.toggle('on', b.dataset.sch === schRange));
      if (!sched) return;
      const tz = sched.timezone;
      const now = Date.now();
      const todayKey = dayKey(new Date().toISOString(), tz);
      const all = sched.events;
      const visible = all.filter(ev => schCanceled.checked || !isCanceled(ev));
      const today = visible.filter(ev => dayKey(ev.start, tz) === todayKey);
      const fresh = visible.filter(isNewBooking);
      schTabs.querySelector('[data-sch-count="today"]').textContent = today.length || '';
      schTabs.querySelector('[data-sch-count="week"]').textContent = visible.length || '';
      schTabs.querySelector('[data-sch-count="new"]').textContent = fresh.length || '';

      const activeToday = all.filter(ev => !isCanceled(ev) && dayKey(ev.start, tz) === todayKey);
      const left = activeToday.filter(ev => new Date(ev.end).getTime() > now).length;
      schCount.textContent = activeToday.length ? `${activeToday.length} today · ${left} left` : 'Nothing today';
      schCount.title = `Times in office time: ${tzShort(tz) || tz} (${city(tz)})`;
      const next = all.find(ev => !isCanceled(ev) && new Date(ev.start).getTime() > now);
      const nextId = next && next.id;

      const rows = schRange === 'today' ? today : schRange === 'new' ? fresh : visible;
      if (!rows.length) {
        schList.innerHTML = `<li class="fd-empty">${
          schRange === 'today' ? 'No appointments today.'
          : schRange === 'new' ? `No new bookings in the last ${NEW_HOURS} hours (for the next 7 days).`
          : 'No appointments in the next 7 days.'}</li>`;
        return;
      }
      const shown = schAll ? rows : rows.slice(0, SCH_ROWS);
      // Day headings in the 7-day and new-bookings lists.
      let lastDay = schRange === 'today' ? todayKey : null;
      schList.innerHTML = shown.map(ev => {
        const k = dayKey(ev.start, tz);
        const head = k !== lastDay
          ? `<li class="fd-sch-day">${esc(k === todayKey ? 'Today' : fmt(ev.start, tz, { weekday: 'long', month: 'short', day: 'numeric' }))}</li>` : '';
        lastDay = k;
        return head + schHtml(ev, tz, now, nextId);
      }).join('') +
        (rows.length > shown.length ? `<li><button type="button" class="fd-inq-more" data-sch-more>Show ${rows.length - shown.length} more</button></li>` : '');
    }

    async function loadSchedule(freshLoad = false) {
      try {
        const res = await fetch('api/frontdesk_schedule.php' + (freshLoad ? '?fresh=1' : ''), { headers: { Accept: 'application/json' } });
        const out = await res.json();
        if (!out.success) {
          if (!sched) schList.innerHTML = `<li class="fd-empty fd-inq-error">${esc(out.message || 'Could not load bookings.')}</li>`;
          if (freshLoad) showToast(out.message || 'Could not load bookings.', true);
          return;
        }
        sched = out;
        renderSchedule();
      } catch (e) {
        if (!sched) schList.innerHTML = '<li class="fd-empty fd-inq-error">Could not load bookings. Check your connection.</li>';
      }
    }

    schTabs.addEventListener('click', (e) => {
      const b = e.target.closest('[data-sch]');
      if (!b) return;
      schRange = b.dataset.sch;
      schAll = false;
      saveSch();
      renderSchedule();
    });
    schCanceled.addEventListener('change', () => { schAll = false; saveSch(); renderSchedule(); });
    schList.addEventListener('click', (e) => {
      if (e.target.closest('[data-sch-more]')) { schAll = true; renderSchedule(); }
    });
    const schRefresh = document.getElementById('fdSchRefresh');
    schRefresh.addEventListener('click', async () => {
      schRefresh.disabled = true;
      await loadSchedule(true);
      schRefresh.disabled = false;
    });

    renderSchedule();
    loadSchedule();
    setInterval(() => { if (!document.hidden) loadSchedule(); }, SCH_POLL_MS);
    // Re-draw every minute so "Now" / "Up next" move without a reload.
    setInterval(() => { if (!document.hidden && sched) renderSchedule(); }, 60000);
  }

  // ---- Contracts window ----
  // Open contracts from the Notion 🖊️ Contracts database (via
  // api/frontdesk_contracts.php, cached ~60s), grouped by what they're
  // waiting on. Oldest first, so whoever has waited longest gets chased
  // first. Clicking a row opens the contract in Notion.
  const conWin = document.getElementById('fdContracts');
  if (conWin && conWin.dataset.enabled === '1') {
    const CON_KEY = 'duqueFrontDeskCon';
    const CON_POLL_MS = 120000;
    const CON_ROWS = 5;
    const FOLLOW_UP_DAYS = 7;   // Documenso already sends one reminder after 2 days
    const STAGES = ['client', 'jd', 'unpaid'];
    const conList = document.getElementById('fdConList');
    const conCount = document.getElementById('fdConCount');
    const conTabs = document.getElementById('fdConTabs');
    let conStage = 'client';
    let conAll = false;
    let contracts = null;
    try {
      const saved = JSON.parse(localStorage.getItem(CON_KEY) || '{}');
      if (STAGES.includes(saved.stage)) conStage = saved.stage;
    } catch (e) {}
    const saveCon = () => { try { localStorage.setItem(CON_KEY, JSON.stringify({ stage: conStage })); } catch (e) {} };

    const daysSince = (iso) => Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86400000));
    const ageText = (iso) => { const d = daysSince(iso); return d === 0 ? 'Today' : d === 1 ? '1 day' : `${d} days`; };
    const money = (n) => (typeof n === 'number' && n > 0)
      ? n.toLocaleString('en-CA', { style: 'currency', currency: 'CAD', maximumFractionDigits: 2 }) : '';
    const firstEmail = (s) => (String(s || '').split(/[\s,;]+/).find(x => x.includes('@')) || '');
    const isPaid = (c) => (c.paid_signed || []).some(x => ['Paid', 'Paid w/ Square', 'Cheque', 'Cash'].includes(x));

    function conHtml(c) {
      const age = daysSince(c.created_at);
      const tags = [
        c.declined && '<span class="fd-inq-tag cancel">Declined</span>',
        c.stage === 'client' && !c.declined && age >= FOLLOW_UP_DAYS && '<span class="fd-inq-tag follow">Follow up</span>',
        `<span class="fd-inq-tag">${c.eng ? 'Engagement' : 'PCAIC'}</span>`,
        ...(c.case_types || []).slice(0, 3).map(t => `<span class="fd-inq-tag">${esc(t)}</span>`),
        c.client_no && `<span class="fd-inq-tag">#${esc(c.client_no)}</span>`,
        isPaid(c) && '<span class="fd-inq-tag app">Paid</span>',
        c.stage !== 'client' && money(c.amount_due) && `<span class="fd-inq-tag emp">${esc(money(c.amount_due))} due</span>`,
      ].filter(Boolean).join('');
      const email = firstEmail(c.email) || firstEmail(c.alt_email);
      const contact = [
        c.client_id && `<span class="fd-inq-link" role="link" tabindex="0" data-href="client.php?id=${c.client_id}"><i class="fa-regular fa-folder-open"></i> Client file</span>`,
        c.client_id && `<span class="fd-inq-link" role="link" tabindex="0" data-href="inbox.php?client_id=${c.client_id}"><i class="fa-regular fa-comment"></i> Message</span>`,
        c.phone && `<span class="fd-inq-link" role="link" tabindex="0" data-href="tel:${esc(c.phone)}"><i class="fa-solid fa-phone"></i> ${esc(c.phone)}</span>`,
        email && `<span class="fd-inq-link" role="link" tabindex="0" data-href="mailto:${esc(email)}"><i class="fa-regular fa-envelope"></i> ${esc(email)}</span>`,
      ].filter(Boolean).join('');
      const since = c.stage === 'client' ? 'Sent' : 'Open';
      return `<li><a class="fd-inq" href="${esc(c.url)}" target="_blank" rel="noopener">
        <span class="fd-inq-top"><span class="fd-name">${esc(c.name)}</span><span class="fd-time" title="${since} ${esc(new Date(c.created_at).toLocaleDateString())}">${esc(ageText(c.created_at))}</span></span>
        <span class="fd-inq-tags">${tags}</span>
        ${c.note ? `<span class="fd-inq-msg">${esc(c.note)}</span>` : ''}
        ${contact ? `<span class="fd-inq-contact">${contact}</span>` : ''}
      </a></li>`;
    }

    function renderContracts() {
      conTabs.querySelectorAll('[data-con]').forEach(b => b.classList.toggle('on', b.dataset.con === conStage));
      if (!contracts) return;
      const by = {};
      STAGES.forEach(s => { by[s] = contracts.filter(c => c.stage === s).sort((a, b) => new Date(a.created_at) - new Date(b.created_at)); });
      STAGES.forEach(s => { conTabs.querySelector(`[data-con-count="${s}"]`).textContent = by[s].length || ''; });
      const stale = by.client.filter(c => c.declined || daysSince(c.created_at) >= FOLLOW_UP_DAYS).length;
      conCount.textContent = contracts.length ? `${contracts.length} open${stale ? ` · ${stale} to follow up` : ''}` : 'All caught up';
      const rows = by[conStage];
      if (!rows.length) {
        conList.innerHTML = `<li class="fd-empty"><i class="fa-solid fa-check"></i>${
          conStage === 'client' ? 'No contracts waiting on a client.'
          : conStage === 'jd' ? 'Nothing waiting for JD to sign.'
          : 'Every signed contract is marked paid.'}</li>`;
        return;
      }
      const shown = conAll ? rows : rows.slice(0, CON_ROWS);
      conList.innerHTML = shown.map(conHtml).join('') +
        (rows.length > shown.length ? `<li><button type="button" class="fd-inq-more" data-con-more>Show ${rows.length - shown.length} more</button></li>` : '');
    }

    async function loadContracts(fresh = false) {
      try {
        const res = await fetch('api/frontdesk_contracts.php' + (fresh ? '?fresh=1' : ''), { headers: { Accept: 'application/json' } });
        const out = await res.json();
        if (!out.success) {
          if (!contracts) conList.innerHTML = `<li class="fd-empty fd-inq-error">${esc(out.message || 'Could not load contracts.')}</li>`;
          if (fresh) showToast(out.message || 'Could not load contracts.', true);
          return;
        }
        contracts = out.contracts || [];
        renderContracts();
      } catch (e) {
        if (!contracts) conList.innerHTML = '<li class="fd-empty fd-inq-error">Could not load contracts. Check your connection.</li>';
      }
    }

    conTabs.addEventListener('click', (e) => {
      const b = e.target.closest('[data-con]');
      if (!b) return;
      conStage = b.dataset.con;
      conAll = false;
      saveCon();
      renderContracts();
    });
    conList.addEventListener('click', (e) => {
      if (e.target.closest('[data-con-more]')) { conAll = true; renderContracts(); return; }
      // Client file / message / phone / email (spans, since links can't
      // nest in the row link) go there instead of opening Notion.
      const link = e.target.closest('[data-href]');
      if (link) { e.preventDefault(); location.href = link.dataset.href; }
    });
    conList.addEventListener('keydown', (e) => {
      const link = e.key === 'Enter' && e.target.closest('[data-href]');
      if (link) { e.preventDefault(); location.href = link.dataset.href; }
    });
    const conRefresh = document.getElementById('fdConRefresh');
    conRefresh.addEventListener('click', async () => {
      conRefresh.disabled = true;
      await loadContracts(true);
      conRefresh.disabled = false;
    });

    renderContracts();
    loadContracts();
    setInterval(() => { if (!document.hidden) loadContracts(); }, CON_POLL_MS);
  }

})();
