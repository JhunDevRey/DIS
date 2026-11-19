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
  // Contracts from the Notion 🖊️ Contracts database (via
  // api/frontdesk_contracts.php, cached ~60s): "Client signed" (waiting for
  // JD) and "Fully signed". Clicking a name opens a preview of the contract's
  // Notion record with a Download button (one-page PDF summary from
  // api/frontdesk_contract_summary.php) and a link to the Notion page.
  const conWin = document.getElementById('fdContracts');
  if (conWin && conWin.dataset.enabled === '1') {
    const CON_KEY = 'duqueFrontDeskCon';
    const CON_POLL_MS = 120000;
    const CON_ROWS = 5;
    const STAGES = ['jd', 'done'];
    const conList = document.getElementById('fdConList');
    const conCount = document.getElementById('fdConCount');
    const conTabs = document.getElementById('fdConTabs');
    let conStage = 'jd';
    let conAll = false;
    let conQuery = '';
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
      const tags = [
        `<span class="fd-inq-tag">${c.eng ? 'Engagement' : 'PCAIC'}</span>`,
        ...(c.case_types || []).slice(0, 3).map(t => `<span class="fd-inq-tag">${esc(t)}</span>`),
        c.client_no && `<span class="fd-inq-tag">#${esc(c.client_no)}</span>`,
        isPaid(c) && '<span class="fd-inq-tag app">Paid</span>',
        money(c.amount_due) && `<span class="fd-inq-tag emp">${esc(money(c.amount_due))} due</span>`,
      ].filter(Boolean).join('');
      const email = firstEmail(c.email) || firstEmail(c.alt_email);
      const contact = [
        c.client_id && `<span class="fd-inq-link" role="link" tabindex="0" data-href="client.php?id=${c.client_id}"><i class="fa-regular fa-folder-open"></i> Client file</span>`,
        c.client_id && `<span class="fd-inq-link" role="link" tabindex="0" data-href="inbox.php?client_id=${c.client_id}"><i class="fa-regular fa-comment"></i> Message</span>`,
        c.phone && `<span class="fd-inq-link" role="link" tabindex="0" data-href="tel:${esc(c.phone)}"><i class="fa-solid fa-phone"></i> ${esc(c.phone)}</span>`,
        email && `<span class="fd-inq-link" role="link" tabindex="0" data-href="mailto:${esc(email)}"><i class="fa-regular fa-envelope"></i> ${esc(email)}</span>`,
      ].filter(Boolean).join('');
      // The name opens the contract preview.
      const nameEl = `<button type="button" class="fd-name fd-con-open" data-con-open="${esc(c.id)}" title="Preview contract">${esc(c.name)}</button>`;
      // Owners only (the API sets can_sign): sign JD's part without leaving the portal.
      const signBtn = c.can_sign
        ? `<span class="fd-con-actions"><button type="button" class="btn btn-primary btn-sm" data-con-sign="${esc(c.id)}"><i class="fa-solid fa-signature"></i> Sign as JD</button></span>` : '';
      return `<li><div class="fd-inq">
        <span class="fd-inq-top">${nameEl}<span class="fd-time" title="Created ${esc(new Date(c.created_at).toLocaleDateString())}">${esc(ageText(c.created_at))}</span></span>
        <span class="fd-inq-tags">${tags}</span>
        ${c.note ? `<span class="fd-inq-msg">${esc(c.note)}</span>` : ''}
        ${contact ? `<span class="fd-inq-contact">${contact}</span>` : ''}
        ${signBtn}
      </div></li>`;
    }

    // Preview dialog: the contract's Notion record laid out as a sheet, with
    // Download (one-page PDF summary) and Open in Notion. Owners also get
    // "Sign as JD" on client-signed contracts: it swaps the sheet for JD's
    // Documenso signing page (api/frontdesk_contract_sign.php).
    let conDialog = null;
    let conSigned = false;
    function closeConDialog() {
      if (!conDialog) return;
      const d = conDialog; conDialog = null;
      d.classList.remove('open');
      setTimeout(() => d.remove(), 180);
      if (conMsgHandler) { window.removeEventListener('message', conMsgHandler); conMsgHandler = null; }
      // Closed before the signing page reported completion (e.g. signed in a
      // new tab): keep checking (every 10s, up to 3 min) until it moves.
      if (conSigned) { const id = conSigned; conSigned = false; watchSigned(id); }
    }
    let conMsgHandler = null;
    // Ask Documenso (through our server) whether JD's signature is in. If it
    // is, the server remembers it and the contract shows as Fully signed
    // straight away — no waiting for Make → Notion.
    async function signedInDocumenso(id) {
      try {
        const res = await fetch('api/frontdesk_contract_sign.php', {
          method: 'POST',
          headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'check=1&id=' + encodeURIComponent(id),
        });
        const out = await res.json();
        return !!(out.success && out.completed);
      } catch (err) { return false; }
    }
    function markSigned(id) {
      const mine = (contracts || []).find(x => x.id === id);
      if (mine) { mine.stage = 'done'; mine.can_sign = false; mine.updated_at = new Date().toISOString(); }
      renderContracts();
      showToast('Signed — moved to Fully signed.');
    }
    // The signing page said "completed": confirm with Documenso, then close.
    async function finishSigning(c) {
      if (!(await signedInDocumenso(c.id))) return;
      conSigned = false;
      closeConDialog();
      markSigned(c.id);
    }
    function watchSigned(id) {
      let tries = 0;
      const tick = async () => {
        tries++;
        if (await signedInDocumenso(id)) { markSigned(id); return; }
        await loadContracts(true);
        const c = (contracts || []).find(x => x.id === id);
        if (!c || c.stage !== 'jd') { if (c) showToast('Contract moved to Fully signed.'); return; }
        if (tries < 18) setTimeout(tick, 10000);
      };
      setTimeout(tick, 1000);
    }
    function openConDialog(c, startSigning) {
      closeConDialog();
      conSigned = false;
      const when = (iso) => iso ? new Date(iso).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' }) : '';
      const emails = [firstEmail(c.email), firstEmail(c.alt_email)].filter(Boolean);
      const row = (label, html) => html ? `<div class="fd-con-row"><dt>${label}</dt><dd>${html}</dd></div>` : '';
      const status = c.stage === 'done'
        ? '<span class="fd-inq-tag app">Signed by the client and JD</span>'
        : '<span class="fd-inq-tag follow">Client signed — waiting for JD</span>';
      const rows = [
        row('Signing', status),
        row('Contract', c.eng ? 'Engagement letter' : 'PCAIC contract'),
        row('Case type', (c.case_types || []).map(t => `<span class="fd-inq-tag">${esc(t)}</span>`).join(' ')),
        row('Client no.', c.client_no ? '#' + esc(c.client_no) : ''),
        row('Email', emails.map(esc).join('<br>')),
        row('Phone', esc(c.phone || '')),
        row('Amount due', money(c.amount_due) ? esc(money(c.amount_due)) + ' CAD' : ''),
        row('Paid & signed', (c.paid_signed || []).map(t => `<span class="fd-inq-tag${isPaid({ paid_signed: [t] }) ? ' app' : ''}">${esc(t)}</span>`).join(' ')),
        row('Case status', esc(c.status || '')),
        row('Created', esc(when(c.created_at))),
        row('Last updated', esc(when(c.updated_at))),
        row('Notes', esc(c.note || '')),
      ].join('');
      const d = document.createElement('div');
      d.className = 'modal-backdrop fd-con-backdrop';
      d.innerHTML = `<div class="modal fd-con-modal" role="dialog" aria-modal="true" aria-label="Contract preview">
        <h3>${esc(c.name)}<button type="button" class="modal-close-btn" data-x aria-label="Close">&times;</button></h3>
        <dl class="fd-con-sheet">${rows}</dl>
        <div class="fd-con-frame" hidden><iframe title="Sign contract"></iframe></div>
        <p class="fd-con-hint" hidden></p>
        <div class="modal-actions fd-con-bar">
          <a class="btn btn-ghost" data-dl href="api/frontdesk_contract_summary.php?id=${encodeURIComponent(c.id)}"><i class="fa-solid fa-download"></i> Download</a>
          <a class="btn btn-ghost" data-notion href="${esc(c.url)}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open in Notion</a>
          <span class="fd-con-spacer"></span>
          ${c.can_sign ? '<button type="button" class="btn btn-primary" data-sign><i class="fa-solid fa-signature"></i> Sign as JD</button>' : ''}
          <button type="button" class="btn btn-ghost" data-x>Close</button>
        </div>
      </div>`;
      document.body.appendChild(d);
      conDialog = d;
      requestAnimationFrame(() => d.classList.add('open'));
      d.addEventListener('click', (e) => { if (e.target === d || e.target.closest('[data-x]')) closeConDialog(); });
      const onKey = (e) => { if (e.key === 'Escape') { document.removeEventListener('keydown', onKey); closeConDialog(); } };
      document.addEventListener('keydown', onKey);

      const signBtn = d.querySelector('[data-sign]');
      if (signBtn) signBtn.addEventListener('click', async () => {
        signBtn.disabled = true;
        try {
          const res = await fetch('api/frontdesk_contract_sign.php', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(c.id),
          });
          const out = await res.json();
          if (!out.success) { showToast(out.message || 'Could not start signing.', true); signBtn.disabled = false; return; }
          conSigned = c.id;
          d.querySelector('.fd-con-sheet').hidden = true;
          d.querySelector('.fd-con-frame').hidden = false;
          const frame = d.querySelector('.fd-con-frame iframe');
          // Documenso's embed signing page tells its parent when the contract
          // is completed (postMessage {action: 'document-completed'}).
          let signOrigin = '';
          try { signOrigin = new URL(out.signing_url).origin; } catch (e) {}
          if (conMsgHandler) window.removeEventListener('message', conMsgHandler);
          conMsgHandler = (e) => {
            if (e.origin !== signOrigin || e.source !== frame.contentWindow) return;
            let msg = e.data;
            if (typeof msg === 'string') { try { msg = JSON.parse(msg); } catch (err) { return; } }
            const action = msg && (msg.action || msg.type);
            if (action === 'document-completed') finishSigning(c);
          };
          window.addEventListener('message', conMsgHandler);
          frame.src = out.signing_url;
          const hint = d.querySelector('.fd-con-hint');
          hint.hidden = false;
          hint.innerHTML = 'Click your signature field (your saved signature goes in), then press Complete — this window closes by itself. If the page stays blank, <a href="' + esc(out.signing_url) + '" target="_blank" rel="noopener">open the signing page in a new tab</a>.';
          d.querySelector('[data-dl]').style.display = 'none';
          d.querySelector('[data-notion]').style.display = 'none';
          signBtn.style.display = 'none';
        } catch (err) {
          showToast('Could not start signing. Check your connection.', true);
          signBtn.disabled = false;
        }
      });
      if (startSigning && signBtn) signBtn.click();
    }

    function renderContracts() {
      conTabs.querySelectorAll('[data-con]').forEach(b => b.classList.toggle('on', b.dataset.con === conStage));
      if (!contracts) return;
      const q = conQuery.trim().toLowerCase();
      const hay = (c) => [c.name, c.email, c.alt_email, c.phone, c.client_no, (c.case_types || []).join(' '), c.note]
        .filter(Boolean).join(' ').toLowerCase();
      const phoneDigits = q.replace(/\D/g, '');
      const matches = (c) => !q || hay(c).includes(q)
        || (phoneDigits.length >= 3 && String(c.phone || '').replace(/\D/g, '').includes(phoneDigits));
      const by = {};
      // Most recently updated first: a contract that was just signed lands at the top.
      const touched = (c) => new Date(c.updated_at || c.created_at).getTime();
      STAGES.forEach(s => { by[s] = contracts.filter(c => c.stage === s && matches(c)).sort((a, b) => touched(b) - touched(a)); });
      STAGES.forEach(s => { conTabs.querySelector(`[data-con-count="${s}"]`).textContent = by[s].length || ''; });
      const waiting = contracts.filter(c => c.stage === 'jd').length;
      conCount.textContent = q ? `${by.jd.length + by.done.length} found`
        : waiting ? `${waiting} for JD to sign` : (contracts.length ? 'All signed' : 'Nothing yet');
      const rows = by[conStage];
      if (!rows.length) {
        const other = STAGES.find(s => s !== conStage && by[s].length);
        conList.innerHTML = q
          ? `<li class="fd-empty"><i class="fa-solid fa-magnifying-glass"></i>No match in this tab${other ? ` — ${by[other].length} in “${other === 'jd' ? 'Client signed' : 'Fully signed'}”` : ''}.</li>`
          : `<li class="fd-empty"><i class="fa-solid fa-check"></i>${
            conStage === 'jd' ? 'Nothing waiting for JD to sign.' : 'No fully signed contracts in the last 30 days.'}</li>`;
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
      const hit = e.target.closest('[data-con-open], [data-con-sign]');
      if (hit) {
        const sign = hit.hasAttribute('data-con-sign');
        const c = (contracts || []).find(x => x.id === (sign ? hit.dataset.conSign : hit.dataset.conOpen));
        if (c) openConDialog(c, sign);
        return;
      }
      // Client file / message / phone / email (spans, since links can't
      // nest in the row link) go there instead of opening Notion.
      const link = e.target.closest('[data-href]');
      if (link) { e.preventDefault(); if (!(window.ClientPanel && ClientPanel.handle(link.dataset.href, e))) location.href = link.dataset.href; }
    });
    conList.addEventListener('keydown', (e) => {
      const link = e.key === 'Enter' && e.target.closest('[data-href]');
      if (link) { e.preventDefault(); if (!(window.ClientPanel && ClientPanel.handle(link.dataset.href, null))) location.href = link.dataset.href; }
    });
    const conSearch = document.getElementById('fdConSearch');
    conSearch.addEventListener('input', () => {
      conQuery = conSearch.value;
      conAll = false;
      renderContracts();
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

  // ---- PQ Forms window ----
  // Entries on the Cognito Forms PQ form (via api/frontdesk_pq.php, cached
  // ~60s): Waiting = not submitted yet (oldest first, so the longest wait is
  // chased first), Submitted = in the last 14 days (newest first).
  const pqWin = document.getElementById('fdPq');
  if (pqWin && pqWin.dataset.enabled === '1') {
    const PQ_KEY = 'duqueFrontDeskPq';
    const PQ_POLL_MS = 120000;
    const PQ_ROWS = 5;
    const PQ_DONE_DAYS = 14;
    const PQ_CHASE_DAYS = 3;    // waiting this long gets a "Follow up" tag
    const PQ_STAGES = ['waiting', 'submitted'];
    const pqList = document.getElementById('fdPqList');
    const pqCount = document.getElementById('fdPqCount');
    const pqTabs = document.getElementById('fdPqTabs');
    let pqStage = 'waiting';
    let pqAll = false;
    let pqQuery = '';
    let pqEntries = null;
    let pqPartialTries = 0;
    try {
      const saved = JSON.parse(localStorage.getItem(PQ_KEY) || '{}');
      if (PQ_STAGES.includes(saved.stage)) pqStage = saved.stage;
    } catch (e) {}
    const savePq = () => { try { localStorage.setItem(PQ_KEY, JSON.stringify({ stage: pqStage })); } catch (e) {} };

    const pqDays = (iso) => Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86400000));
    const pqAge = (iso) => { const d = pqDays(iso); return d === 0 ? 'Today' : d === 1 ? '1 day' : `${d} days`; };

    function pqHtml(p) {
      const when = p.stage === 'submitted' ? p.submitted_at : p.created_at;
      const tags = [
        p.stage === 'waiting' && pqDays(p.created_at) >= PQ_CHASE_DAYS && '<span class="fd-inq-tag follow">Follow up</span>',
        p.stage === 'waiting' && `<span class="fd-inq-tag">${esc(p.status)}</span>`,
        p.stage === 'submitted' && '<span class="fd-inq-tag app">Submitted</span>',
        p.form && `<span class="fd-inq-tag web">${esc(p.form)}</span>`,
        p.number != null && `<span class="fd-inq-tag">#${esc(p.number)}</span>`,
      ].filter(Boolean).join('');
      const contact = [
        p.client_id && `<span class="fd-inq-link" role="link" tabindex="0" data-href="client.php?id=${p.client_id}"><i class="fa-regular fa-folder-open"></i> Client file</span>`,
        p.client_id && `<span class="fd-inq-link" role="link" tabindex="0" data-href="inbox.php?client_id=${p.client_id}"><i class="fa-regular fa-comment"></i> Message</span>`,
        // Cognito's generated PDF, fetched by our server (api/frontdesk_pq_file.php).
        p.stage === 'submitted' && p.form && p.number != null && `<span class="fd-inq-link" role="link" tabindex="0" data-pq-dl="${esc(p.id)}"><i class="fa-solid fa-download"></i> Download PDF</span>`,
      ].filter(Boolean).join('');
      const inner = `<span class="fd-inq-top"><span class="fd-name">${esc(p.name)}</span><span class="fd-time" title="${p.stage === 'submitted' ? 'Submitted' : 'Started'} ${esc(new Date(when).toLocaleDateString())}">${esc(pqAge(when))}</span></span>
        <span class="fd-inq-tags">${tags}</span>
        ${contact ? `<span class="fd-inq-contact">${contact}</span>` : ''}`;
      return p.url
        ? `<li><a class="fd-inq" href="${esc(p.url)}" target="_blank" rel="noopener">${inner}</a></li>`
        : `<li><div class="fd-inq">${inner}</div></li>`;
    }

    function renderPq() {
      pqTabs.querySelectorAll('[data-pq]').forEach(b => b.classList.toggle('on', b.dataset.pq === pqStage));
      if (!pqEntries) return;
      const q = pqQuery.trim().toLowerCase();
      const matches = (p) => !q || [p.name, p.form, p.number, p.status].filter(x => x != null && x !== '').join(' ').toLowerCase().includes(q);
      const by = {
        waiting: pqEntries.filter(p => p.stage === 'waiting' && matches(p)).sort((a, b) => new Date(a.created_at) - new Date(b.created_at)),
        submitted: pqEntries.filter(p => p.stage === 'submitted' && pqDays(p.submitted_at) <= PQ_DONE_DAYS && matches(p))
          .sort((a, b) => new Date(b.submitted_at) - new Date(a.submitted_at)),
      };
      PQ_STAGES.forEach(s => { pqTabs.querySelector(`[data-pq-count="${s}"]`).textContent = by[s].length || ''; });
      const allWaiting = pqEntries.filter(p => p.stage === 'waiting');
      const chase = allWaiting.filter(p => pqDays(p.created_at) >= PQ_CHASE_DAYS).length;
      pqCount.textContent = q ? `${by.waiting.length + by.submitted.length} found`
        : allWaiting.length ? `${allWaiting.length} waiting${chase ? ` · ${chase} to follow up` : ''}` : 'All caught up';
      const rows = by[pqStage];
      if (!rows.length) {
        const other = PQ_STAGES.find(s => s !== pqStage && by[s].length);
        pqList.innerHTML = q
          ? `<li class="fd-empty"><i class="fa-solid fa-magnifying-glass"></i>No match in this tab${other ? ` — ${by[other].length} in “${other === 'waiting' ? 'Waiting' : 'Submitted'}”` : ''}.</li>`
          : `<li class="fd-empty"><i class="fa-solid fa-check"></i>${
            pqStage === 'waiting' ? 'No PQ forms waiting on anyone.' : `Nothing submitted in the last ${PQ_DONE_DAYS} days.`}</li>`;
        return;
      }
      const shown = pqAll ? rows : rows.slice(0, PQ_ROWS);
      pqList.innerHTML = shown.map(pqHtml).join('') +
        (rows.length > shown.length ? `<li><button type="button" class="fd-inq-more" data-pq-more>Show ${rows.length - shown.length} more</button></li>` : '');
    }

    async function loadPq(fresh = false) {
      try {
        const res = await fetch('api/frontdesk_pq.php' + (fresh ? '?fresh=1' : ''), { headers: { Accept: 'application/json' } });
        const out = await res.json();
        if (!out.success) {
          if (!pqEntries) pqList.innerHTML = `<li class="fd-empty fd-inq-error">${esc(out.message || 'Could not load PQ forms.')}</li>`;
          if (fresh) showToast(out.message || 'Could not load PQ forms.', true);
          return;
        }
        pqEntries = out.entries || [];
        renderPq();
        // First load of a big backlog is finished over a few quick refreshes.
        if (out.partial && pqPartialTries < 30) { pqPartialTries++; setTimeout(() => loadPq(), Math.max(3, out.wait || 3) * 1000); }
        else if (!out.partial) pqPartialTries = 0;
      } catch (e) {
        if (!pqEntries) pqList.innerHTML = '<li class="fd-empty fd-inq-error">Could not load PQ forms. Check your connection.</li>';
      }
    }

    pqTabs.addEventListener('click', (e) => {
      const b = e.target.closest('[data-pq]');
      if (!b) return;
      pqStage = b.dataset.pq;
      pqAll = false;
      savePq();
      renderPq();
    });
    // Download the PQ's PDF in the background so a failure shows a message
    // instead of replacing the page with an error.
    async function downloadPq(btn) {
      if (btn.dataset.busy) return;
      btn.dataset.busy = '1';
      const label = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing…';
      try {
        const res = await fetch('api/frontdesk_pq_file.php?id=' + encodeURIComponent(btn.dataset.pqDl));
        if (!res.ok) { showToast((await res.text()).slice(0, 160) || 'Could not download the PQ.', true); return; }
        const blob = await res.blob();
        const cd = res.headers.get('Content-Disposition') || '';
        const star = /filename\*=UTF-8''([^;]+)/i.exec(cd);
        const plain = /filename="([^"]+)"/i.exec(cd);
        let fname = 'PQ.pdf';
        try { fname = star ? decodeURIComponent(star[1]) : (plain ? plain[1] : fname); } catch (e) {}
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = fname;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      } catch (err) {
        showToast('Could not download the PQ. Check your connection.', true);
      } finally {
        btn.innerHTML = label;
        delete btn.dataset.busy;
      }
    }
    pqList.addEventListener('click', (e) => {
      if (e.target.closest('[data-pq-more]')) { pqAll = true; renderPq(); return; }
      const dl = e.target.closest('[data-pq-dl]');
      if (dl) { e.preventDefault(); downloadPq(dl); return; }
      const link = e.target.closest('[data-href]');
      if (link) { e.preventDefault(); if (!(window.ClientPanel && ClientPanel.handle(link.dataset.href, e))) location.href = link.dataset.href; }
    });
    pqList.addEventListener('keydown', (e) => {
      const dl = e.key === 'Enter' && e.target.closest('[data-pq-dl]');
      if (dl) { e.preventDefault(); downloadPq(dl); return; }
      const link = e.key === 'Enter' && e.target.closest('[data-href]');
      if (link) { e.preventDefault(); if (!(window.ClientPanel && ClientPanel.handle(link.dataset.href, null))) location.href = link.dataset.href; }
    });
    const pqSearch = document.getElementById('fdPqSearch');
    pqSearch.addEventListener('input', () => {
      pqQuery = pqSearch.value;
      pqAll = false;
      renderPq();
    });
    const pqRefresh = document.getElementById('fdPqRefresh');
    pqRefresh.addEventListener('click', async () => {
      pqRefresh.disabled = true;
      await loadPq(true);
      pqRefresh.disabled = false;
    });

    renderPq();
    loadPq();
    setInterval(() => { if (!document.hidden) loadPq(); }, PQ_POLL_MS);
  }

})();
