// js/ui-kit.js
// Shared across admin/ and portal/ pages: toast notifications, custom
// confirm/notice dialogs (replacing the browser's native confirm()/alert(),
// which look inconsistent and block the page), modal open/close helpers,
// and the mobile hamburger nav toggle.

/**
 * CSRF protection — every state-changing fetch() call in this app is a
 * relative-URL POST to api/..., so instead of touching every single
 * call site individually (dozens of them, across many files, easy to
 * miss one), this wraps window.fetch once, globally, and attaches the
 * X-CSRF-Token header automatically to any same-origin POST/PUT/DELETE
 * request. GET requests and absolute/cross-origin URLs (Google Fonts,
 * cdnjs, Adobe's SDK, etc.) are left completely untouched — this only
 * ever adds one header to same-site write requests, never changes what
 * a request does or removes anything a caller already set.
 */
(function () {
  const tokenMeta = document.querySelector('meta[name="csrf-token"]');
  if (!tokenMeta) return; // page doesn't have one (e.g. login.php) — nothing to attach
  const token = tokenMeta.content;

  const originalFetch = window.fetch;
  window.fetch = function (input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const isRelative = url && !/^https?:\/\//i.test(url) && !url.startsWith('//');
    const method = ((init && init.method) || (typeof input !== 'string' && input && input.method) || 'GET').toUpperCase();

    if (isRelative && method !== 'GET' && method !== 'HEAD') {
      init = init || {};
      const headers = new Headers(init.headers || (typeof input !== 'string' ? input.headers : undefined) || {});
      if (!headers.has('X-CSRF-Token')) {
        headers.set('X-CSRF-Token', token);
      }
      init = { ...init, headers };
    }

    return originalFetch.call(this, input, init);
  };
})();

/**
 * Re-numbers the little "file-number" badges inside a document field's
 * file list after one is deleted client-side (without a full page
 * reload). Files are rendered newest-first, but numbered oldest-first
 * (#1 = first uploaded), so the badge on row N is (total rows - index).
 * With 0 or 1 file left, badges are removed entirely since numbering a
 * single file adds nothing.
 */
function renumberFileRows(container) {
  if (!container) return;
  const rows = container.querySelectorAll('.file-row');
  if (rows.length <= 1) {
    rows.forEach(row => row.querySelector('.file-number')?.remove());
    return;
  }
  rows.forEach((row, i) => {
    const num = rows.length - i;
    let badge = row.querySelector('.file-number');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'file-number';
      row.insertBefore(badge, row.querySelector('.file-info'));
    }
    badge.textContent = num;
    badge.title = `File ${num} of ${rows.length}`;
  });
}

let toastTimer = null;
function showToast(message, isError = false) {
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = message;
  el.classList.toggle('error', !!isError);
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 3200);
}

/**
 * Styled replacement for window.confirm(). Returns a Promise<boolean> —
 * true if the person confirmed, false if they cancelled (backdrop click,
 * Escape key, or the Cancel button).
 *   if (!(await showConfirm('Delete this file?'))) return;
 */
function showConfirm(message, opts = {}) {
  return new Promise(resolve => {
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML = `
      <div class="modal confirm-dialog">
        <h3>${opts.title ? escapeHtml(opts.title) : 'Please confirm'}</h3>
        <div class="modal-body">
          <p class="confirm-message"></p>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" data-role="cancel">${opts.cancelLabel ? escapeHtml(opts.cancelLabel) : 'Cancel'}</button>
          <button type="button" class="btn ${opts.danger ? 'btn-danger-solid' : 'btn-primary'}" data-role="ok">${opts.confirmLabel ? escapeHtml(opts.confirmLabel) : 'Confirm'}</button>
        </div>
      </div>`;
    backdrop.querySelector('.confirm-message').textContent = message;
    document.body.appendChild(backdrop);
    // Appended in its closed state first, then opened on the next frame —
    // opening it in the same tick it's created would skip the fade-in
    // transition entirely (the browser has nothing to transition FROM).
    requestAnimationFrame(() => backdrop.classList.add('open'));

    const cleanup = (result) => {
      backdrop.classList.remove('open');
      document.removeEventListener('keydown', onKey);
      setTimeout(() => backdrop.remove(), 180);
      resolve(result);
    };
    const onKey = (e) => { if (e.key === 'Escape') cleanup(false); };

    backdrop.querySelector('[data-role="cancel"]').addEventListener('click', () => cleanup(false));
    backdrop.querySelector('[data-role="ok"]').addEventListener('click', () => cleanup(true));
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) cleanup(false); });
    document.addEventListener('keydown', onKey);
    backdrop.querySelector('[data-role="ok"]').focus();
  });
}

/**
 * Styled replacement for window.alert() — for messages that need more
 * visibility than a toast (e.g. a Dropbox warning after an action mostly
 * succeeded) but shouldn't block the page the way alert() does. Returns a
 * Promise that resolves once dismissed, if the caller wants to wait.
 */
function showNotice(message, opts = {}) {
  return new Promise(resolve => {
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML = `
      <div class="modal confirm-dialog">
        <h3>${opts.title ? escapeHtml(opts.title) : 'Heads up'}</h3>
        <div class="modal-body">
          <p class="confirm-message"></p>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-primary" data-role="ok">OK</button>
        </div>
      </div>`;
    backdrop.querySelector('.confirm-message').textContent = message;
    document.body.appendChild(backdrop);
    requestAnimationFrame(() => backdrop.classList.add('open'));

    const cleanup = () => {
      backdrop.classList.remove('open');
      setTimeout(() => backdrop.remove(), 180);
      resolve();
    };
    backdrop.querySelector('[data-role="ok"]').addEventListener('click', cleanup);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) cleanup(); });
  });
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

/* ---------- Modal open/close (existing app modals, e.g. Add Client) ---------- */
function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('open');
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
}
document.addEventListener('click', (e) => {
  if (e.target.classList && e.target.classList.contains('modal-backdrop') && !e.target.hasAttribute('data-no-outside-close')) {
    e.target.classList.remove('open');
  }
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
    // Generic close for any open dropdown-menu — covers the checklist
    // dropdown and the Case Type / Client No. combo-dropdowns, which
    // each already have their own open/close + outside-click logic but
    // didn't have Escape wired in individually. One shared rule here
    // covers all of them (and any future one) instead of repeating the
    // same few lines in every dropdown's own setup code.
    document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
  }
});

/* ---------- Auto local time ---------- */
// Converts every server-rendered <span class="auto-local-time" data-utc="...">
// into the VIEWER'S OWN local timezone — this is what makes dates/times
// throughout the app adapt automatically to wherever someone is actually
// accessing it from, rather than always showing one fixed timezone to
// everyone. The server only ever sends the raw UTC instant (as an
// unambiguous ISO string) plus formatting options; the actual "what
// timezone is this person in" detection happens entirely here, via the
// browser's own built-in Intl API — deliberately not passing an explicit
// timeZone option to Intl.DateTimeFormat, since omitting it is exactly
// what makes it auto-detect the browser's own zone.
function applyAutoLocalTimes(root = document) {
  root.querySelectorAll('.auto-local-time[data-utc]').forEach(el => {
    if (el.dataset.localized === '1') return; // already converted, e.g. if this runs again after an AJAX update
    try {
      const date = new Date(el.dataset.utc);
      if (isNaN(date.getTime())) return; // leave the UTC fallback text in place if parsing ever fails
      const options = el.dataset.intlOptions ? JSON.parse(el.dataset.intlOptions) : {};
      el.textContent = new Intl.DateTimeFormat(undefined, options).format(date);
      el.dataset.localized = '1';
    } catch (err) {
      // Leave the server-rendered UTC fallback text as-is — better than a broken/blank display.
    }
  });
}
document.addEventListener('DOMContentLoaded', () => applyAutoLocalTimes());

/* ---------- Editable links (hover pencil + small "edit link" popup) ---------- */
// One shared popup element, created once and reused for every editable
// link on the page — matches the small floating-card pattern (display
// text field, link field, Apply) rather than opening a full modal for
// something this quick.
let linkEditPopup = null;
let linkEditActiveSpan = null;

function ensureLinkEditPopup() {
  if (linkEditPopup) return linkEditPopup;

  linkEditPopup = document.createElement('div');
  linkEditPopup.className = 'link-edit-popup';
  linkEditPopup.innerHTML = `
    <div class="link-edit-popup-field">
      <i class="fa-solid fa-align-left"></i>
      <input type="text" id="linkEditTextInput" placeholder="(display text here)">
    </div>
    <div class="link-edit-popup-field">
      <i class="fa-solid fa-link"></i>
      <input type="text" id="linkEditUrlInput" placeholder="(paste link here)">
    </div>
    <div class="link-edit-popup-actions">
      <button type="button" class="link-edit-popup-remove" id="linkEditRemoveBtn">Remove link</button>
      <button type="button" class="link-edit-popup-apply" id="linkEditApplyBtn">Apply</button>
    </div>
  `;
  document.body.appendChild(linkEditPopup);

  linkEditPopup.querySelector('#linkEditApplyBtn').addEventListener('click', applyLinkEdit);
  linkEditPopup.querySelector('#linkEditRemoveBtn').addEventListener('click', removeLinkEdit);
  linkEditPopup.addEventListener('click', (e) => e.stopPropagation());

  document.addEventListener('click', closeLinkEditPopup);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeLinkEditPopup(); });

  return linkEditPopup;
}

function closeLinkEditPopup() {
  if (linkEditPopup) linkEditPopup.classList.remove('open');
  linkEditActiveSpan = null;
}

function openLinkEditor(button, event) {
  if (event) event.stopPropagation();
  const span = button.closest('.editable-link');
  if (!span) return;

  const popup = ensureLinkEditPopup();
  linkEditActiveSpan = span;

  const rect = button.getBoundingClientRect();
  popup.style.top = (rect.bottom + 6) + 'px';
  popup.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 316)) + 'px';

  popup.querySelector('#linkEditTextInput').value = span.dataset.text || '';
  popup.querySelector('#linkEditUrlInput').value = span.dataset.url || '';
  popup.classList.add('open');
  popup.querySelector('#linkEditTextInput').focus();
}

async function saveLinkEdit(newText, newUrl) {
  if (!linkEditActiveSpan) return;
  const span = linkEditActiveSpan;
  const applyBtn = document.getElementById('linkEditApplyBtn');

  // "Live" links are ones typed/pasted into a not-yet-submitted comment,
  // instructions field, or checklist item — nothing to save server-side
  // yet, since the surrounding text hasn't even been posted/sent. Just
  // update the local DOM and re-sync the underlying textarea (see
  // syncLiveEditorToTextarea) — the normal submit flow picks it up from
  // there exactly like any other typed text, no separate code path
  // needed for that part.
  if (span.classList.contains('live-link')) {
    if (!newUrl) {
      span.replaceWith(document.createTextNode(newText));
    } else {
      span.querySelector('a').textContent = newText;
      span.querySelector('a').href = newUrl;
      span.dataset.text = newText;
      span.dataset.url = newUrl;
    }
    const editorEl = span.closest ? findLiveEditorRoot(span) : null;
    if (editorEl) syncLiveEditorToTextarea(editorEl);
    closeLinkEditPopup();
    return;
  }

  applyBtn.disabled = true;

  try {
    const res = await fetch('api/update_link_text.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        edit_type: span.dataset.editType,
        edit_id: span.dataset.editId,
        old_raw: span.dataset.raw,
        new_text: newText,
        new_url: newUrl,
      }),
    });
    const data = await res.json();

    if (!data.success) {
      showToast(data.message || 'Could not update the link.', true);
      return;
    }

    // Update the DOM in place — no reload needed. If the link was
    // removed entirely (newUrl empty), replace the whole span with
    // plain text; otherwise update the link and its stored data-*
    // attributes so re-editing immediately after works correctly.
    if (!newUrl) {
      span.replaceWith(document.createTextNode(newText));
    } else {
      const a = span.querySelector('a');
      a.textContent = newText;
      a.href = newUrl;
      span.dataset.text = newText;
      span.dataset.url = newUrl;
      span.dataset.raw = data.new_raw || span.dataset.raw;
    }
    showToast('Link updated.');
  } catch (err) {
    showToast('Network error.', true);
  } finally {
    applyBtn.disabled = false;
    closeLinkEditPopup();
  }
}

function applyLinkEdit() {
  const newText = document.getElementById('linkEditTextInput').value.trim();
  const newUrl = document.getElementById('linkEditUrlInput').value.trim();
  if (!newText || !newUrl) {
    showToast('Both fields are required — use "Remove link" to remove it instead.', true);
    return;
  }
  saveLinkEdit(newText, newUrl);
}

function removeLinkEdit() {
  const newText = document.getElementById('linkEditTextInput').value.trim() || (linkEditActiveSpan ? linkEditActiveSpan.dataset.text : '');
  saveLinkEdit(newText, '');
}

/* ---------- Live-while-typing link editor ---------- */
// Upgrades a plain <textarea> (comments, document/checklist instructions)
// so a pasted URL turns into a real, styled, hoverable-and-editable link
// immediately — before the surrounding comment/instructions is ever
// submitted — rather than only after the fact. The textarea itself stays
// in the DOM (hidden) as the actual source of truth for form submission:
// its .value is kept in sync with the live editor's content on every
// change, so none of the existing submit-handling code needs to change
// at all, it just keeps reading textarea.value like it always did.
//
// Deliberately conservative about WHEN auto-linking triggers — on paste,
// and as a fallback on blur (leaving the field) — rather than on every
// keystroke. Continuously rebuilding parts of a contenteditable element
// while someone is actively typing is a well-known way to fight the
// browser over cursor position; restricting it to paste (a natural,
// well-defined insertion point) and blur (focus is leaving anyway, so
// there's no cursor position left to protect) avoids that whole class of
// bug rather than trying to work around it.

const LIVE_URL_PATTERN = /(https?:\/\/[^\s<]+)/i;

function findLiveEditorRoot(node) {
  return node.closest ? node.closest('.live-link-editor') : null;
}

function buildLiveLinkSpan(text, url) {
  const span = document.createElement('span');
  span.className = 'live-link editable-link';
  // contenteditable="false" on a child of a contenteditable container
  // makes it "atomic" — the browser treats the whole span as one
  // indivisible unit for cursor movement and deletion (e.g. Backspace
  // removes the entire link in one press, never eating into it letter
  // by letter), the same technique rich editors use for things like
  // Gmail's recipient "chips."
  span.contentEditable = 'false';
  span.dataset.text = text;
  span.dataset.url = url;

  const a = document.createElement('a');
  a.href = url;
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  a.textContent = text;
  span.appendChild(a);

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'edit-link-btn';
  btn.title = 'Edit link';
  btn.setAttribute('aria-label', 'Edit link');
  btn.innerHTML = '<i class="fa-solid fa-pen"></i>';
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    e.preventDefault();
    const popup = ensureLinkEditPopup();
    linkEditActiveSpan = span;
    const rect = btn.getBoundingClientRect();
    popup.style.top = (rect.bottom + 6) + 'px';
    popup.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 316)) + 'px';
    popup.querySelector('#linkEditTextInput').value = span.dataset.text || '';
    popup.querySelector('#linkEditUrlInput').value = span.dataset.url || '';
    popup.classList.add('open');
    popup.querySelector('#linkEditTextInput').focus();
  });
  span.appendChild(btn);

  return span;
}

// Turns the live editor's actual DOM content (a mix of plain text nodes
// and .live-link spans) back into the same plain-text-with-markdown
// format the server already expects — "[display text](url)" for a
// custom-labeled link, otherwise just plain text — so the hidden
// textarea's value round-trips through exactly the same storage format
// as everything else already built for this (see linkifyText() in
// includes/functions.php).
function serializeLiveEditorContent(root) {
  let result = '';

  function walk(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      result += node.textContent;
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;

    if (node.classList && node.classList.contains('live-link')) {
      const text = node.dataset.text || node.textContent;
      const url = node.dataset.url || '';
      result += url ? `[${text}](${url})` : text;
      return; // don't descend into the span's own <a>/<button> children
    }
    if (node.tagName === 'BR') {
      result += '\n';
      return;
    }
    const isBlock = node.tagName === 'DIV' || node.tagName === 'P';
    if (isBlock && result && !result.endsWith('\n')) {
      result += '\n';
    }
    node.childNodes.forEach(walk);
  }

  root.childNodes.forEach(walk);
  return result.replace(/\n+$/, '');
}

function syncLiveEditorToTextarea(editorEl) {
  const textarea = editorEl.previousElementSibling;
  if (textarea && textarea.tagName === 'TEXTAREA') {
    textarea.value = serializeLiveEditorContent(editorEl);
  }
}

// If the given plain text is a bare URL (nothing but the URL, no
// surrounding words), returns it — used to decide whether a paste should
// become a link at all, vs. just pasting as normal text when a URL is
// only PART of a larger pasted sentence (rarer, and safer to leave as
// plain text than to guess which portion the person actually meant to
// link).
function extractPastedUrl(text) {
  const trimmed = text.trim();
  const match = trimmed.match(/^https?:\/\/\S+$/i);
  return match ? trimmed : null;
}

// Scans the editor's direct child text nodes for bare URLs and converts
// each into a live, editable link span in place — the actual conversion
// logic shared by both the blur handler below (a URL typed by hand) and
// initLiveLinkEditor() itself (a URL that was already there when the
// editor was created, e.g. from a checklist template's canned
// instructions, before the person has touched the field at all).
function scanAndLinkifyPlainUrls(editor) {
  let changed = false;
  Array.from(editor.childNodes).forEach(node => {
    if (node.nodeType !== Node.TEXT_NODE) return;
    if (!LIVE_URL_PATTERN.test(node.textContent)) return;
    const frag = document.createDocumentFragment();
    let lastIndex = 0;
    const text = node.textContent;
    const re = new RegExp(LIVE_URL_PATTERN.source, 'gi');
    let m;
    while ((m = re.exec(text)) !== null) {
      const url = m[1].replace(/[.,;:!?)]+$/, '');
      if (text.slice(lastIndex, m.index)) {
        frag.appendChild(document.createTextNode(text.slice(lastIndex, m.index)));
      }
      frag.appendChild(buildLiveLinkSpan(url, url));
      lastIndex = m.index + url.length;
      changed = true;
    }
    if (lastIndex < text.length) {
      frag.appendChild(document.createTextNode(text.slice(lastIndex)));
    }
    if (changed) node.replaceWith(frag);
  });
  return changed;
}

function initLiveLinkEditor(textarea) {
  if (!textarea || textarea.dataset.liveLinkInit === '1') return;
  textarea.dataset.liveLinkInit = '1';

  const editor = document.createElement('div');
  editor.className = 'live-link-editor';
  editor.contentEditable = 'true';
  editor.setAttribute('data-placeholder', textarea.placeholder || '');
  if (textarea.value) editor.textContent = textarea.value;

  textarea.style.display = 'none';
  textarea.after(editor);

  // Convert any URL that's already present the moment this editor is
  // created — e.g. a checklist template's canned instructions, or an
  // existing document's instructions being reopened for editing — into a
  // live, editable link immediately. Without this, a link nobody has
  // actively typed or pasted *in this session* would sit as inert plain
  // text (no hover pencil, no click-through) until the person happened to
  // click into that exact field and back out again.
  if (scanAndLinkifyPlainUrls(editor)) {
    syncLiveEditorToTextarea(editor);
  }

  editor.addEventListener('input', () => syncLiveEditorToTextarea(editor));

  editor.addEventListener('paste', (e) => {
    const pasted = (e.clipboardData || window.clipboardData).getData('text');
    const url = extractPastedUrl(pasted);
    if (!url) return; // not a bare URL — let the normal paste happen as plain text

    e.preventDefault();
    const span = buildLiveLinkSpan(url, url);
    const sel = window.getSelection();
    if (sel.rangeCount) {
      const range = sel.getRangeAt(0);
      range.deleteContents();
      range.insertNode(span);
      range.setStartAfter(span);
      range.setEndAfter(span);
      sel.removeAllRanges();
      sel.addRange(range);
    } else {
      editor.appendChild(span);
    }
    syncLiveEditorToTextarea(editor);
  });

  // Fallback for a URL that was typed out by hand rather than pasted —
  // caught once the field loses focus, since there's no cursor position
  // left to protect at that point.
  editor.addEventListener('blur', () => {
    if (scanAndLinkifyPlainUrls(editor)) {
      syncLiveEditorToTextarea(editor);
    }
  });
}

function initAllLiveLinkEditors(root = document) {
  root.querySelectorAll('textarea[data-live-links]').forEach(initLiveLinkEditor);
}
document.addEventListener('DOMContentLoaded', () => initAllLiveLinkEditors());

/* ---------- Mobile hamburger nav ---------- */
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('mobileNavToggle');
  const sidebar = document.querySelector('.sidebar');
  if (!toggle || !sidebar) return;

  toggle.addEventListener('click', () => sidebar.classList.toggle('mobile-open'));

  // Tapping the dimmed backdrop area (outside the drawer) closes it.
  sidebar.addEventListener('click', (e) => {
    if (e.target === sidebar) sidebar.classList.remove('mobile-open');
  });

  // Picking a nav link closes the drawer too, so the next page loads clean.
  sidebar.querySelectorAll('nav a').forEach(link => {
    link.addEventListener('click', () => sidebar.classList.remove('mobile-open'));
  });
});

/* ---------- Light/dark theme toggle ----------
   The actual theme (light vs dark) is set as early as possible by a small
   inline script in each page's <head> (before any CSS/JS loads), so the
   page never flashes the wrong theme. This part just wires up the switch
   in the sidebar and keeps localStorage in sync when the person flips it. */
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('themeToggle');
  if (!toggle) return;

  toggle.addEventListener('click', () => {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const next = isDark ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
  });
});
