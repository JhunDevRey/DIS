// js/activity.js — Activity log pager (admin/activity.php).
// Shows as many rows as fit the window: it measures the rendered rows,
// works out how many fit between the table's top and the bottom of the
// screen, and asks admin/activity.php?format=json for exactly that many.
// It refits when the window is resized, keeping the first row on screen,
// and keeps offset/limit in the address so reload and Back work.
(function () {
  'use strict';

  const pager = document.getElementById('actPager');
  const tbody = document.getElementById('actRows');
  const card = document.getElementById('actCard');
  if (!pager || !tbody || !card) return;

  const MIN_ROWS = 3;
  const MAX_ROWS = 200;
  const baseQs = pager.dataset.qs;
  let total = Number(pager.dataset.total) || 0;
  let offset = Number(pager.dataset.offset) || 0;
  let limit = Number(pager.dataset.limit) || 25;
  let queue = Promise.resolve();   // loads run one after another, in order
  let fitting = false;
  let fitAgain = false;

  const fmt = (n) => n.toLocaleString();
  const lastOffset = () => Math.max(0, Math.floor(Math.max(0, total - 1) / limit) * limit);

  // How many rows fit: space from the first row to the bottom of the
  // window (less the pager under the table), divided by the average
  // height of the rows on screen now.
  function rowsThatFit() {
    const rows = [...tbody.querySelectorAll('tr:not(.act-empty)')];
    if (!rows.length) return limit;
    const first = rows[0].getBoundingClientRect();
    const last = rows[rows.length - 1].getBoundingClientRect();
    const avg = (last.bottom - first.top) / rows.length;
    if (!(avg > 0)) return limit;
    const rowsTop = first.top + window.scrollY;           // where the rows start on the page
    const below = (pager.hidden ? 40 : pager.offsetHeight) + 28; // pager + breathing room under it
    const space = window.innerHeight - rowsTop - below;
    return Math.min(MAX_ROWS, Math.max(MIN_ROWS, Math.floor(space / avg)));
  }

  function updatePager() {
    pager.dataset.offset = offset;
    pager.dataset.limit = limit;
    document.getElementById('actRange').textContent = total
      ? `${fmt(offset + 1)}–${fmt(Math.min(total, offset + limit))} of ${fmt(total)}`
      : '0 entries';
    const set = (go, off, disabled) => {
      const a = pager.querySelector(`[data-go="${go}"]`);
      a.href = `activity.php?${baseQs ? baseQs + '&' : ''}offset=${off}&limit=${limit}`;
      a.classList.toggle('disabled', disabled);
      a.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      a.tabIndex = disabled ? -1 : 0;
    };
    set('first', 0, offset === 0);
    set('prev', Math.max(0, offset - limit), offset === 0);
    set('next', offset + limit, offset + limit >= total);
    set('last', lastOffset(), offset >= lastOffset());
    pager.hidden = total <= limit && offset === 0;
  }

  function load(newOffset, newLimit, push = false) {
    queue = queue.then(() => fetchRows(newOffset, newLimit, push));
    return queue;
  }

  async function fetchRows(newOffset, newLimit, push) {
    card.classList.add('is-loading');
    try {
      const qs = `${baseQs ? baseQs + '&' : ''}offset=${newOffset}&limit=${newLimit}`;
      const res = await fetch(`activity.php?${qs}&format=json`, { headers: { Accept: 'application/json' } });
      const out = await res.json();
      if (!out.success) throw new Error(out.message || 'failed');
      tbody.innerHTML = out.html;
      if (typeof applyAutoLocalTimes === 'function') applyAutoLocalTimes(tbody);
      total = out.total; offset = out.offset; limit = out.limit;
      updatePager();
      const url = `activity.php?${baseQs ? baseQs + '&' : ''}offset=${offset}&limit=${limit}`;
      try { history[push ? 'pushState' : 'replaceState']({ offset, limit }, '', url); } catch (e) {}
    } catch (e) {
      if (typeof showToast === 'function') showToast('Could not load the activity log. Check your connection.', true);
    } finally {
      card.classList.remove('is-loading');
    }
  }

  // Refit to the window; keeps the first row that's showing on screen.
  // Rows differ in height (long details wrap), so after loading it checks
  // again and trims until the pager sits inside the window.
  // One refit at a time; a resize during one queues another.
  async function refit() {
    if (fitting) { fitAgain = true; return; }
    fitting = true;
    try {
      for (let i = 0; i < 4; i++) {
        let fit = rowsThatFit();
        const overflow = pager.getBoundingClientRect().bottom + 12 - window.innerHeight;
        if (fit === limit && overflow > 0 && limit > MIN_ROWS && window.scrollY === 0) fit = limit - 1;
        if (fit === limit) break;
        await load(Math.floor(offset / fit) * fit, fit);
      }
    } finally {
      fitting = false;
      if (fitAgain) { fitAgain = false; refit(); }
    }
  }

  pager.addEventListener('click', (e) => {
    const a = e.target.closest('[data-go]');
    if (!a) return;
    e.preventDefault();
    if (a.classList.contains('disabled')) return;
    const go = a.dataset.go;
    const next = go === 'first' ? 0 : go === 'prev' ? Math.max(0, offset - limit)
      : go === 'next' ? offset + limit : lastOffset();
    load(next, limit, true);
  });

  // Arrow keys page through (when not typing in the filters).
  document.addEventListener('keydown', (e) => {
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '')) return;
    if (e.key === 'ArrowRight' && offset + limit < total) load(offset + limit, limit, true);
    if (e.key === 'ArrowLeft' && offset > 0) load(Math.max(0, offset - limit), limit, true);
  });

  window.addEventListener('popstate', (e) => {
    if (e.state && typeof e.state.offset === 'number') load(e.state.offset, e.state.limit);
  });

  let resizeTimer = 0;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(refit, 250);
  });

  updatePager();
  refit();
  // Rows change height after the fact (web fonts swapping in, dates turned
  // into local time), so refit whenever the table's size changes.
  if ('ResizeObserver' in window) {
    let lastH = 0;
    let t = 0;
    new ResizeObserver(([entry]) => {
      const h = Math.round(entry.contentRect.height);
      if (h === lastH) return;
      lastH = h;
      clearTimeout(t);
      t = setTimeout(refit, 150);
    }).observe(card);
  }
})();
