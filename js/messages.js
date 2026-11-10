// js/messages.js
// Messages — the staff <-> client inbox. Shared by admin/inbox.php and
// portal/messages.php; everything side-specific comes from data-*
// attributes on #msgApp:
//   data-viewer         'admin' | 'client'
//   data-endpoint       api/admin_messages.php | api/client_messages.php
//   data-file-endpoint  api/admin_message_file.php | api/client_message_file.php
//   data-client-id      (admin only) the open conversation's client id
//
// New messages are picked up by polling every POLL_MS while the tab is
// visible (and immediately when it becomes visible again) — no
// websockets needed on shared hosting.
//
// Staff can edit their own messages for 2 hours after sending (the server
// sends edit_until; the server also re-checks it). Edits show as "edited"
// on both sides and reach open conversations through the same polling.
//
// Replies work like Messenger: hover a message (or swipe it sideways on a
// phone) and hit the reply arrow; the sent message carries a quote of the
// original, and clicking the quote jumps back to it.

(function () {
  const app = document.getElementById('msgApp');
  if (!app) return;

  const POLL_MS = 10000;
  const LIST_POLL_MS = 20000;
  const MAX_FILES = 5;
  const viewer = app.dataset.viewer;
  const endpoint = app.dataset.endpoint;
  const fileEndpoint = app.dataset.fileEndpoint;
  const clientId = app.dataset.clientId || '';

  const thread = document.getElementById('msgThread');
  const form = document.getElementById('msgForm');
  const textarea = document.getElementById('msgBody');
  const fileInput = document.getElementById('msgFiles');
  const chips = document.getElementById('msgFileChips');
  const sendBtn = document.getElementById('msgSendBtn');

  let lastId = 0;
  let lastSeenReported = 0;
  const byId = new Map(); // message id -> latest data, for re-rendering after an edit
  let loading = false;
  let pendingFiles = [];
  let replyTo = null; // message being replied to, while composing

  // ---------- Rendering ----------

  // ui-kit's escapeHtml() leaves quotes alone, which isn't safe inside
  // attribute values (data-name, title, aria-label) — this one is.
  function esc(str) {
    return escapeHtml(String(str)).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  // Stored times are UTC ("YYYY-MM-DD HH:MM:SS"); shown in the viewer's
  // own timezone, like the rest of the app.
  function parseUtc(mysql) {
    return new Date(mysql.replace(' ', 'T') + 'Z');
  }

  function formatTime(d) {
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function dayLabel(d) {
    const today = new Date();
    const yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return 'Today';
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
  }

  let lastDayRendered = '';
  function renderMessage(m) {
    const when = parseUtc(m.created_at);
    const day = when.toDateString();
    if (day !== lastDayRendered) {
      const sep = document.createElement('div');
      sep.className = 'msg-day';
      sep.textContent = dayLabel(when);
      thread.appendChild(sep);
      lastDayRendered = day;
    }

    const el = document.createElement('div');
    el.className = 'msg' + (m.mine ? ' mine' : '') + (m.sender_type === 'client' ? ' from-client' : '');
    el.dataset.id = m.id;
    byId.set(m.id, m);
    el.innerHTML = bubbleHtml(m);
    thread.appendChild(el);
  }

  function bubbleHtml(m) {
    const when = parseUtc(m.created_at);
    const filesHtml = m.files.map(f => `
      <div class="msg-file">
        <i class="fa-regular fa-file-lines"></i>
        <a href="${fileEndpoint}?file_id=${f.id}&mode=view" target="_blank" rel="noopener">${esc(f.name)}</a>
        <span class="msg-file-size">${formatSize(f.size)}</span>
        <a class="icon-btn" href="${fileEndpoint}?file_id=${f.id}" title="Download" aria-label="Download ${esc(f.name)}"><i class="fa-solid fa-download"></i></a>
      </div>`).join('');

    // "edited" label; staff can click it to see what was originally sent.
    const edited = m.edited_at ? (viewer === 'admin'
      ? `<button type="button" class="msg-edited" data-history title="Edited ${esc(parseUtc(m.edited_at).toLocaleString())} — click to see earlier versions">edited</button>`
      : `<span class="msg-edited" title="Edited ${esc(parseUtc(m.edited_at).toLocaleString())}">edited</span>`) : '';
    const editBtn = m.edit_until && parseUtc(m.edit_until) > new Date()
      ? `<button type="button" class="msg-edit-btn" data-edit data-edit-until="${esc(m.edit_until)}" title="Edit (possible until ${esc(formatTime(parseUtc(m.edit_until)))})" aria-label="Edit message"><i class="fa-solid fa-pen"></i></button>` : '';

    // Reply arrow beside the bubble (only where there's a composer).
    const replyBtn = form ? `<button type="button" class="msg-reply-btn" data-reply title="Reply" aria-label="Reply to this message"><i class="fa-solid fa-reply"></i></button>` : '';

    return `
      ${replyBtn}
      ${quoteHtml(m)}
      <div class="msg-head"><span class="msg-sender">${esc(m.sender)}</span>${editBtn}${edited}<span class="msg-time" title="${esc(when.toLocaleString())}">${formatTime(when)}</span></div>
      ${m.body_html ? `<div class="msg-body">${m.body_html}</div>` : ''}
      ${filesHtml ? `<div class="msg-files">${filesHtml}</div>` : ''}`;
  }

  // "you" / "yourself" for the client's own messages, otherwise the name.
  function replyName(target, from) {
    if (target === 'You') return from === 'You' ? 'yourself' : 'you';
    return target;
  }

  function snippetText(r) {
    if (r.snippet) return r.snippet;
    return r.files ? `📎 ${r.files} attachment${r.files === 1 ? '' : 's'}` : '(message)';
  }

  function quoteHtml(m) {
    const r = m.reply_to;
    if (!r) return '';
    return `
      <button type="button" class="msg-quote" data-jump="${r.id}" title="Show the original message">
        <span class="msg-quote-who"><i class="fa-solid fa-reply"></i> Replying to ${esc(replyName(r.sender, m.sender))}</span>
        <span class="msg-quote-text">${esc(snippetText(r))}</span>
      </button>`;
  }

  // Plain one-line text from a message (for quotes of messages edited
  // while the thread is open, and the "Replying to" bar).
  function plainSnippet(m) {
    const div = document.createElement('div');
    div.innerHTML = m.body_html || '';
    const text = div.textContent.replace(/\s+/g, ' ').trim();
    return text.length > 160 ? text.slice(0, 159) + '…' : text;
  }

  function jumpTo(id) {
    const el = thread.querySelector(`.msg[data-id="${id}"]`);
    if (!el) { showToast('The original message is no longer available.', true); return; }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.remove('flash');
    void el.offsetWidth; // restart the highlight animation
    el.classList.add('flash');
  }

  // Swap in edited text (from our own save, or picked up while polling).
  function applyEdits(edits) {
    for (const e of edits || []) {
      const m = byId.get(e.id);
      if (!m || m.edited_at === e.edited_at) continue;
      Object.assign(m, { body_html: e.body_html, edited_at: e.edited_at, body: e.body ?? m.body });
      const el = thread.querySelector(`.msg[data-id="${e.id}"]`);
      if (el && !el.classList.contains('editing')) el.innerHTML = bubbleHtml(m);
      // Quotes of this message in later replies show the new text too.
      const snip = plainSnippet(m);
      for (const other of byId.values()) {
        if (other.reply_to && other.reply_to.id === e.id && snip) {
          other.reply_to.snippet = snip;
          const q = thread.querySelector(`.msg[data-id="${other.id}"] .msg-quote-text`);
          if (q) q.textContent = snip;
        }
      }
      if (replyTo && replyTo.id === e.id) setReply(m);
    }
  }

  // Edit buttons disappear once their 2-hour window has passed.
  function expireEditButtons() {
    const now = new Date();
    thread.querySelectorAll('[data-edit-until]').forEach(btn => {
      if (parseUtc(btn.dataset.editUntil) <= now) btn.remove();
    });
  }

  if (thread) {
    thread.addEventListener('click', async (e) => {
      const el = e.target.closest('.msg');
      if (!el) return;
      const m = byId.get(Number(el.dataset.id));
      if (!m) return;
      const jump = e.target.closest('[data-jump]');
      if (jump) jumpTo(jump.dataset.jump);
      else if (e.target.closest('[data-reply]')) setReply(m);
      else if (viewer !== 'admin') return;
      else if (e.target.closest('[data-edit]')) startEdit(el, m);
      else if (e.target.closest('[data-history]')) showHistory(m);
    });
  }

  // ---------- Replying (Messenger-style) ----------

  let replyBar = null;
  function setReply(m) {
    if (!form) return;
    const stick = nearBottom(); // the bar takes room — stay at the latest message
    replyTo = m;
    if (!replyBar) {
      replyBar = document.createElement('div');
      replyBar.className = 'msg-replying';
      replyBar.innerHTML = `
        <button type="button" class="msg-replying-main" title="Show the original message">
          <span class="msg-replying-who"></span>
          <span class="msg-replying-text"></span>
        </button>
        <button type="button" class="msg-replying-close" title="Cancel reply (Esc)" aria-label="Cancel reply"><i class="fa-solid fa-xmark"></i></button>`;
      replyBar.querySelector('.msg-replying-close').addEventListener('click', () => { clearReply(); textarea.focus(); });
      replyBar.querySelector('.msg-replying-main').addEventListener('click', () => replyTo && jumpTo(replyTo.id));
      form.prepend(replyBar);
    }
    const name = m.sender === 'You' ? 'yourself' : m.sender;
    replyBar.querySelector('.msg-replying-who').innerHTML = `<i class="fa-solid fa-reply"></i> Replying to <strong>${esc(name)}</strong>`;
    replyBar.querySelector('.msg-replying-text').textContent =
      plainSnippet(m) || (m.files.length ? '📎 ' + m.files.map(f => f.name).join(', ') : '(message)');
    replyBar.hidden = false;
    thread.querySelectorAll('.msg.replying-to').forEach(x => x.classList.remove('replying-to'));
    thread.querySelector(`.msg[data-id="${m.id}"]`)?.classList.add('replying-to');
    if (stick) scrollToBottom();
    textarea.focus();
  }

  function clearReply() {
    replyTo = null;
    if (replyBar) replyBar.hidden = true;
    thread.querySelectorAll('.msg.replying-to').forEach(x => x.classList.remove('replying-to'));
  }

  // Phones: swipe a message toward the middle (right for theirs, left for
  // yours) to reply, like Messenger.
  if (thread && form) {
    const SWIPE_AT = 56, SWIPE_MAX = 80;
    let sw = null;
    thread.addEventListener('touchstart', (e) => {
      const el = e.target.closest('.msg');
      if (!el || el.classList.contains('editing') || e.touches.length !== 1) { sw = null; return; }
      const t = e.touches[0];
      sw = { el, x: t.clientX, y: t.clientY, d: 0, axis: null, dir: el.classList.contains('mine') ? -1 : 1 };
    }, { passive: true });
    thread.addEventListener('touchmove', (e) => {
      if (!sw) return;
      const t = e.touches[0], dx = t.clientX - sw.x, dy = t.clientY - sw.y;
      if (!sw.axis && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) sw.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
      if (sw.axis !== 'x') return;
      sw.d = Math.max(0, Math.min(SWIPE_MAX, dx * sw.dir));
      sw.el.style.transition = 'none';
      sw.el.style.transform = `translateX(${sw.d * sw.dir}px)`;
      sw.el.classList.toggle('swipe-ready', sw.d >= SWIPE_AT);
    }, { passive: true });
    const endSwipe = () => {
      if (!sw) return;
      const { el, d } = sw;
      el.style.transition = '';
      el.style.transform = '';
      el.classList.remove('swipe-ready');
      if (d >= SWIPE_AT) {
        const m = byId.get(Number(el.dataset.id));
        if (m) setReply(m);
        if (navigator.vibrate) { try { navigator.vibrate(10); } catch (err) { /* not supported */ } }
      }
      sw = null;
    };
    thread.addEventListener('touchend', endSwipe);
    thread.addEventListener('touchcancel', endSwipe);
  }

  function startEdit(el, m) {
    if (el.classList.contains('editing')) return;
    el.classList.add('editing');
    const body = el.querySelector('.msg-body');
    const box = document.createElement('div');
    box.className = 'msg-edit-box';
    box.innerHTML = `
      <textarea maxlength="5000" aria-label="Edit message"></textarea>
      <div class="msg-edit-actions">
        <span class="msg-edit-hint">Esc to cancel · Ctrl + Enter to save</span>
        <button type="button" class="btn btn-ghost btn-sm" data-cancel>Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" data-save>Save</button>
      </div>`;
    const ta = box.querySelector('textarea');
    ta.value = m.body || '';
    if (body) body.replaceWith(box); else el.querySelector('.msg-head').after(box);
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);
    const grow = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; };
    grow();
    box.scrollIntoView({ block: 'nearest' });
    ta.addEventListener('input', grow);

    const stop = () => { el.classList.remove('editing'); el.innerHTML = bubbleHtml(byId.get(m.id)); };
    const save = async () => {
      const text = ta.value.trim();
      if (text === (m.body || '').trim()) { stop(); return; }
      const saveBtn = box.querySelector('[data-save]');
      saveBtn.disabled = true;
      const fd = new FormData();
      fd.append('action', 'edit');
      fd.append('client_id', clientId);
      fd.append('message_id', m.id);
      fd.append('body', text);
      const sendAs = document.getElementById('msgSendAs');
      if (sendAs?.value) fd.append('sender_id', sendAs.value);
      try {
        const res = await fetch(endpoint, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { showToast(data.message || 'Could not save the edit.', true); saveBtn.disabled = false; return; }
        if (data.messages && data.messages[0]) byId.set(m.id, Object.assign(m, data.messages[0]));
        stop();
        showToast('Message updated.');
      } catch (err) {
        showToast('Could not save — check your connection and try again.', true);
        saveBtn.disabled = false;
      }
    };
    box.querySelector('[data-cancel]').addEventListener('click', stop);
    box.querySelector('[data-save]').addEventListener('click', save);
    ta.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { e.preventDefault(); stop(); }
      else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); }
    });
  }

  async function showHistory(m) {
    try {
      const res = await fetch(`${endpoint}?client_id=${encodeURIComponent(clientId)}&history=${m.id}`, { headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!data.success) return;
      const versions = data.versions.map((v, i) =>
        `${i === 0 ? 'Originally sent' : 'Earlier version'} (changed ${parseUtc(v.edited_at).toLocaleString()}${v.edited_by ? ' by ' + v.edited_by : ''}):\n${v.old_body || '(no text)'}`);
      versions.push(`Current (edited ${parseUtc(m.edited_at).toLocaleString()}):\n${m.body || '(no text)'}`);
      await showNotice(versions.join('\n\n'), { title: 'Edit history — staff only' });
    } catch (e) { /* not critical */ }
  }

  function appendMessages(messages) {
    let added = false;
    for (const m of messages) {
      if (m.id <= lastId) continue; // already shown (e.g. our own send + a poll racing)
      renderMessage(m);
      lastId = m.id;
      added = true;
    }
    const empty = document.getElementById('msgEmpty');
    if (added && empty) empty.remove();
    return added;
  }

  function nearBottom() {
    return thread.scrollHeight - thread.scrollTop - thread.clientHeight < 120;
  }

  function scrollToBottom() {
    thread.scrollTop = thread.scrollHeight;
  }

  // ---------- Loading / read state ----------

  function threadUrl() {
    const params = new URLSearchParams({ after_id: lastId });
    if (clientId) params.set('client_id', clientId);
    return endpoint + '?' + params.toString();
  }

  async function loadNew(initial = false) {
    if (loading) return;
    loading = true;
    try {
      const wasNearBottom = initial || nearBottom();
      const res = await fetch(threadUrl(), { headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!data.success) return;
      if (appendMessages(data.messages) && wasNearBottom) scrollToBottom();
      applyEdits(data.edits);
      expireEditButtons();
      if (initial) scrollToBottom();
      reportSeen();
    } catch (e) {
      // Network blip — next poll tries again.
    } finally {
      loading = false;
    }
  }

  async function reportSeen() {
    if (document.hidden || lastId <= lastSeenReported) return;
    lastSeenReported = lastId;
    const fd = new FormData();
    fd.append('action', 'seen');
    fd.append('up_to_id', lastId);
    if (clientId) fd.append('client_id', clientId);
    try {
      await fetch(endpoint, { method: 'POST', body: fd });
    } catch (e) { /* not critical */ }

    // Clear this conversation's unread markers in the UI straight away.
    if (viewer === 'client') {
      document.getElementById('navMessagesBadge')?.remove();
    } else {
      document.querySelector(`.conv-item[data-client-id="${clientId}"] .conv-unread`)?.remove();
      document.querySelector(`.conv-item[data-client-id="${clientId}"]`)?.classList.remove('unread');
    }
  }

  // ---------- Sending ----------
  // (absent on the staff inbox when no conversation is open)

  if (form) {

    // Staff "Sending as" picker — staff share one login, so each person
    // picks their own name once and this browser remembers it.
    const sendAs = document.getElementById('msgSendAs');
    const SEND_AS_KEY = 'duqueMsgSendAs';
    if (sendAs) {
      try {
        const saved = localStorage.getItem(SEND_AS_KEY);
        if (saved && sendAs.querySelector(`option[value="${CSS.escape(saved)}"]`)) sendAs.value = saved;
      } catch (e) { /* storage blocked — just default to the team name */ }
      sendAs.addEventListener('change', () => {
        try { localStorage.setItem(SEND_AS_KEY, sendAs.value); } catch (e) { /* not critical */ }
      });
    }

    function renderChips() {
      chips.innerHTML = pendingFiles.map((f, i) => `
        <span class="msg-chip"><i class="fa-solid fa-paperclip"></i> ${esc(f.name)}
          <button type="button" data-remove-file="${i}" aria-label="Remove ${esc(f.name)}"><i class="fa-solid fa-xmark"></i></button>
        </span>`).join('');
      chips.hidden = pendingFiles.length === 0;
    }

    fileInput.addEventListener('change', () => {
      for (const f of fileInput.files) {
        if (pendingFiles.length >= MAX_FILES) {
          showToast(`You can attach up to ${MAX_FILES} files per message.`, true);
          break;
        }
        pendingFiles.push(f);
      }
      fileInput.value = '';
      renderChips();
    });

    chips.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-remove-file]');
      if (!btn) return;
      pendingFiles.splice(Number(btn.dataset.removeFile), 1);
      renderChips();
    });

    textarea.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        form.requestSubmit();
      } else if (e.key === 'Escape' && replyTo) {
        e.preventDefault();
        clearReply();
      }
    });

    // Grow with the text, up to the CSS max-height.
    textarea.addEventListener('input', () => {
      textarea.style.height = 'auto';
      textarea.style.height = textarea.scrollHeight + 'px';
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = textarea.value.trim();
      if (!body && !pendingFiles.length) {
        textarea.focus();
        return;
      }

      const fd = new FormData();
      fd.append('action', 'send');
      fd.append('body', body);
      if (clientId) fd.append('client_id', clientId);
      if (sendAs?.value) fd.append('sender_id', sendAs.value);
      if (replyTo) fd.append('reply_to_id', replyTo.id);
      pendingFiles.forEach(f => fd.append('attachments[]', f));

      sendBtn.disabled = true;
      const originalLabel = sendBtn.innerHTML;
      sendBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending';
      try {
        const res = await fetch(endpoint, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) {
          showToast(data.message || 'Could not send the message.', true);
          return;
        }
        textarea.value = '';
        textarea.style.height = '';
        pendingFiles = [];
        renderChips();
        clearReply();
        appendMessages(data.messages);
        lastSeenReported = lastId; // sending already marked it read server-side
        scrollToBottom();
        if (viewer === 'admin') refreshList();
      } catch (err) {
        showToast('Could not send — check your connection and try again.', true);
      } finally {
        sendBtn.disabled = false;
        sendBtn.innerHTML = originalLabel;
      }
    });
  }

  // ---------- Staff conversation list + "Search clients" ----------
  // With no search or filter: the conversation list (newest first).
  // With a search or filter: every matching client — existing conversations
  // first, then clients with no messages yet (clicking one starts a thread).
  // Search words can come in any order and match name, email, case type,
  // class, client no. or processor ("john work permit", "#12", "liu").

  const convList = document.getElementById('msgConvList');
  const convSearch = document.getElementById('msgConvSearch');
  const convCount = document.getElementById('msgConvCount');
  const filterClass = document.getElementById('msgFilterClass');
  const filterProcessor = document.getElementById('msgFilterProcessor');
  const filterUnread = document.getElementById('msgFilterUnread');
  const FILTERS_KEY = 'duqueInboxFilters';

  let conversations = [];
  let clientDir = [];
  try {
    const initial = JSON.parse(document.getElementById('msgInboxData')?.textContent || '{}');
    conversations = initial.conversations || [];
    clientDir = initial.clients || [];
  } catch (e) { /* refreshList() fills these in shortly */ }

  // Keep the search/filters while moving between conversations (each one is a page load).
  if (convSearch) {
    try {
      const saved = JSON.parse(sessionStorage.getItem(FILTERS_KEY) || '{}');
      convSearch.value = saved.q || '';
      filterClass.value = saved.cls || '';
      filterProcessor.value = saved.proc || '';
      filterUnread.checked = !!saved.unread;
      if (filterClass.selectedIndex < 0) filterClass.value = '';
      if (filterProcessor.selectedIndex < 0) filterProcessor.value = '';
    } catch (e) { /* storage blocked — start unfiltered */ }
  }

  function saveFilters() {
    try {
      sessionStorage.setItem(FILTERS_KEY, JSON.stringify({
        q: convSearch.value, cls: filterClass.value, proc: filterProcessor.value, unread: filterUnread.checked,
      }));
    } catch (e) { /* not critical */ }
  }

  function haystack(c) {
    return [c.full_name, c.email, c.case_type, c.class, c.ref && '#' + c.ref, c.ref, c.processor]
      .filter(Boolean).join(' ').toLowerCase();
  }

  function matches(c, words) {
    if (filterClass.value) {
      const slug = c.class_slug || '';
      if (filterClass.value === 'other' ? !(slug === 'other' || slug === '') : slug !== filterClass.value) return false;
    }
    if (filterProcessor.value) {
      if (filterProcessor.value === '__none' ? c.processor : c.processor !== filterProcessor.value) return false;
    }
    if (!words.length) return true;
    const text = haystack(c);
    // "#12" means exactly client no. 12 (not #120, #121, …); other words match anywhere.
    return words.every(w => w.startsWith('#') && w.length > 1
      ? String(c.ref || '').toLowerCase() === w.slice(1)
      : text.includes(w));
  }

  function itemHtml(c, conv) {
    const active = String(c.client_id) === clientId;
    const unread = conv && conv.unread_count && !active;
    const when = conv ? parseUtc(conv.last_at) : null;
    const timeText = when ? (when.toDateString() === new Date().toDateString()
      ? formatTime(when) : when.toLocaleDateString([], { month: 'short', day: 'numeric' })) : '';
    const meta = [c.case_type && `<span class="class-tag class-tag-${esc(c.class_slug || 'other')}" title="${esc(c.case_type)}">${esc(c.case_type)}</span>`,
                  c.ref && `<span class="conv-meta-item">#${esc(c.ref)}</span>`,
                  c.processor && `<span class="conv-meta-item">${esc(c.processor)}</span>`].filter(Boolean).join('');
    return `
      <a class="conv-item${active ? ' active' : ''}${unread ? ' unread' : ''}${conv ? '' : ' no-thread'}" href="inbox.php?client_id=${c.client_id}"
         data-client-id="${c.client_id}">
        <div class="conv-top">
          <span class="conv-name">${esc(c.full_name)}</span>
          <span class="conv-time">${timeText}</span>
        </div>
        <div class="conv-bottom">
          <span class="conv-preview">${conv ? esc(conv.preview) : '<i class="fa-regular fa-pen-to-square"></i> No messages yet — click to start'}</span>
          ${unread ? `<span class="conv-unread">${conv.unread_count}</span>` : ''}
        </div>
        ${meta ? `<div class="conv-meta">${meta}</div>` : ''}
      </a>`;
  }

  function renderConvList() {
    if (!convList) return;
    const words = convSearch.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    const searching = words.length || filterClass.value || filterProcessor.value;
    const byId = new Map(clientDir.map(c => [c.client_id, c]));
    const convIds = new Set(conversations.map(c => c.client_id));

    // Conversations, merged with their client details (a deactivated client
    // isn't in the directory, so fall back to the name on the conversation).
    let rows = conversations.map(conv => ({ client: byId.get(conv.client_id) || { client_id: conv.client_id, full_name: conv.full_name }, conv }));
    if (searching) {
      rows = rows.filter(r => matches(r.client, words));
      if (!filterUnread.checked) {
        rows = rows.concat(clientDir.filter(c => !convIds.has(c.client_id) && matches(c, words)).map(c => ({ client: c, conv: null })));
      }
    }
    if (filterUnread.checked) rows = rows.filter(r => r.conv && r.conv.unread_count && String(r.client.client_id) !== clientId);

    if (convCount) {
      convCount.hidden = !(searching || filterUnread.checked);
      convCount.textContent = `${rows.length} client${rows.length === 1 ? '' : 's'} found`;
    }
    if (!rows.length) {
      convList.innerHTML = `<div class="conv-empty">${searching || filterUnread.checked
        ? 'No clients match. Try fewer words or clear the filters.'
        : 'No conversations yet. Search for a client above to start one.'}</div>`;
      return;
    }
    convList.innerHTML = rows.map(r => itemHtml(r.client, r.conv)).join('');
  }

  async function refreshList() {
    if (!convList) return;
    try {
      const res = await fetch(endpoint + '?list=1', { headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!data.success) return;
      conversations = data.conversations;
      clientDir = data.clients;
      renderConvList();
      const badge = document.getElementById('navInboxBadge');
      if (badge) {
        badge.textContent = data.unread_total;
        badge.hidden = data.unread_total === 0;
      }
    } catch (e) { /* next poll retries */ }
  }

  if (convSearch) {
    [convSearch, filterClass, filterProcessor, filterUnread].forEach(el =>
      el.addEventListener(el === convSearch ? 'input' : 'change', () => { saveFilters(); renderConvList(); }));
    // Enter opens the first result.
    convSearch.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      const first = convList.querySelector('.conv-item');
      if (first) { e.preventDefault(); location.href = first.href; }
    });
    renderConvList();
  }

  // Full-screen mode (phones/tablets) hides the page's top bar, so its
  // ☰ button opens the usual menu drawer.
  document.querySelectorAll('[data-open-nav]').forEach(btn =>
    btn.addEventListener('click', () => document.getElementById('mobileNavToggle')?.click()));

  // The full-screen chat is pinned to the screen, so the page can't scroll
  // the text box above the on-screen keyboard the way a normal page does.
  // Instead, size the chat to the part of the screen the keyboard leaves
  // visible (window.visualViewport) — the composer then sits right on top
  // of the keyboard, like a chat app, and the newest message stays in view.
  const fsQuery = window.matchMedia('(max-width: 1024px)');
  const vv = window.visualViewport;
  function fitToViewport() {
    if (!vv || !fsQuery.matches) {
      app.style.top = '';
      app.style.height = '';
      return;
    }
    const stick = thread ? nearBottom() : false;
    app.style.top = vv.offsetTop + 'px';
    app.style.height = vv.height + 'px';
    if (stick) scrollToBottom();
  }
  if (vv) {
    vv.addEventListener('resize', fitToViewport);
    vv.addEventListener('scroll', fitToViewport);
    fsQuery.addEventListener?.('change', fitToViewport);
    // iOS can leave the page nudged up after the keyboard closes.
    document.addEventListener('focusout', () => setTimeout(() => {
      if (fsQuery.matches && !document.activeElement?.matches?.('input, textarea, select')) window.scrollTo(0, 0);
      fitToViewport();
    }, 60));
    fitToViewport();
  }

  // ---------- Start ----------

  if (thread) {
    loadNew(true);
    setInterval(() => { if (!document.hidden) loadNew(); }, POLL_MS);
  }
  if (convList) {
    setInterval(() => { if (!document.hidden) refreshList(); }, LIST_POLL_MS);
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    if (thread) loadNew();
    refreshList();
  });
})();
