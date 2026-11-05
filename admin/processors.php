<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$activeNav = 'processors';

$db = getDb();
$processors = $db->query("
  SELECT p.*, COUNT(c.id) AS client_count
  FROM processors p
  LEFT JOIN clients c ON c.processor_id = p.id
  GROUP BY p.id
  ORDER BY p.full_name ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Processors · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main">
    <div class="main-header">
      <div>
        <h1>Processors</h1>
        <p>The team members clients can be assigned to. Whoever's assigned gets emailed when their client uploads a document.</p>
      </div>
      <button class="btn btn-gold" id="btnNewProcessor"><i class="fa-solid fa-user-plus"></i> Add processor</button>
    </div>

    <div class="card">
      <table class="responsive-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Assigned clients</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$processors): ?>
            <tr><td colspan="4">
              <div class="empty-state">
                <div class="icon">🧑‍💼</div>
                No processors yet. Add your team so clients can be assigned to someone.
              </div>
            </td></tr>
          <?php endif; ?>
          <?php foreach ($processors as $p): ?>
            <tr class="row-link" data-processor-row="<?= (int)$p['id'] ?>" data-processor-name="<?= h($p['full_name']) ?>">
              <td style="font-weight:600;color:var(--ink);"><?= h($p['full_name']) ?></td>
              <td data-label="Email"><?= h($p['email']) ?></td>
              <td data-label="Assigned clients"><?= (int)$p['client_count'] ?></td>
              <td style="text-align:right;">
                <button class="btn btn-danger btn-sm" data-delete-processor="<?= (int)$p['id'] ?>"><i class="fa-regular fa-trash-can"></i> Remove</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

<div class="modal-backdrop" id="modalNewProcessor">
  <div class="modal">
    <h3>Add a processor<button type="button" class="modal-close-btn" onclick="closeModal('modalNewProcessor')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <form id="formNewProcessor">
      <div class="modal-body">
      <div class="field">
        <label>Full name</label>
        <input type="text" name="full_name" required>
      </div>
      <div class="field">
        <label>Email</label>
        <input type="email" name="email" required>
      </div>
      <p class="error-text" id="newProcessorError" style="display:none;"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalNewProcessor')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add processor</button>
      </div>
    </form>
  </div>
</div>

<div class="side-panel-backdrop" id="processorClientsPanel">
  <div class="side-panel">
    <div class="side-panel-header">
      <div>
        <h3 id="panelProcessorName">Assigned clients</h3>
        <p id="panelClientCount"></p>
      </div>
      <button type="button" class="side-panel-close" id="btnClosePanel" aria-label="Close panel"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="search-bar" id="panelSearchBar" style="display:none;max-width:none;">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="panelSearchInput" placeholder="Search name, case type, email, phone…">
    </div>
    <div id="panelClientList"></div>
  </div>
</div>

<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/admin.js?v=<?= assetVersion('js/admin.js') ?>"></script>
<script>
document.getElementById('btnNewProcessor').addEventListener('click', () => openModal('modalNewProcessor'));

document.getElementById('formNewProcessor').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const errEl = document.getElementById('newProcessorError');
  errEl.style.display = 'none';
  try {
    const res = await fetch('api/add_processor.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      location.reload();
    } else {
      errEl.textContent = data.message || 'Could not add processor.';
      errEl.style.display = 'block';
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
  }
});

document.querySelectorAll('[data-delete-processor]').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!(await showConfirm('Clients assigned to them will become unassigned.', { title: 'Remove this processor?', danger: true, confirmLabel: 'Remove' }))) return;
    const id = btn.dataset.deleteProcessor;
    try {
      const res = await fetch('api/delete_processor.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `processor_id=${encodeURIComponent(id)}`
      });
      const data = await res.json();
      if (data.success) {
        location.reload();
      } else {
        showToast(data.message || 'Could not remove processor.', true);
      }
    } catch (err) {
      showToast('Network error.', true);
    }
  });
});

// Right-side panel: click a processor row to see their assigned clients
const panel = document.getElementById('processorClientsPanel');
let panelAllClients = [];
let panelClassCategoryOrder = [];

function closePanel() {
  panel.classList.remove('open');
}
document.getElementById('btnClosePanel').addEventListener('click', closePanel);
panel.addEventListener('click', (e) => { if (e.target === panel) closePanel(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePanel(); });

// completion_pct is null when a client has no document fields opened yet
// (nothing to be "complete" against), rather than 0%.
function completionCellHtml(pct) {
  if (pct === null || pct === undefined) {
    return `<span style="font-size:11.5px;color:var(--text-muted);">No documents yet</span>`;
  }
  return `
    <div style="display:flex;align-items:center;gap:8px;">
      <div class="completion-track" style="flex:1;"><div class="completion-fill" style="width:${pct}%;"></div></div>
      <span style="font-size:11.5px;font-weight:700;color:var(--ink);flex-shrink:0;">${pct}%</span>
    </div>
  `;
}

// Slugs for the fixed set of Class categories, matching the
// .class-tag-{slug} colors already defined in style.css.
const CLASS_CATEGORY_SLUGS = {
  'Economic': 'economic',
  'Family': 'family',
  'Work': 'work',
  'Study': 'study',
  'Visit': 'visit',
  'LMIA': 'lmia',
  'Other': 'other'
};

function renderPanelClients(clients) {
  const listEl = document.getElementById('panelClientList');

  if (!panelAllClients.length) {
    listEl.innerHTML = '<div class="empty-state"><div class="icon">📭</div>No clients assigned yet.</div>';
    return;
  }
  if (!clients.length) {
    listEl.innerHTML = '<div class="empty-state"><div class="icon">🔍</div>No clients match your search.</div>';
    return;
  }

  // Group by Class — the broader taxonomy category (Economic, Family,
  // Work, Study, Visit, LMIA), not the specific case type — so a
  // processor's caseload reads as a handful of practice-area groups
  // instead of one heading per individual case type. Clients with no
  // case type set at all fall into their own "No Case Type" group.
  const groups = new Map();
  clients.forEach(c => {
    const key = c.class_category || '__none__';
    if (!groups.has(key)) {
      groups.set(key, { label: c.class_category, clients: [] });
    }
    groups.get(key).clients.push(c);
  });

  // Ordered the same way the taxonomy is defined server-side (Economic
  // → Family → Work → Study → Visit → LMIA), then "Other" for a case
  // type outside the standard list, then "No Case Type" always last.
  const orderIndex = new Map(panelClassCategoryOrder.map((name, i) => [name, i]));
  const sortedGroups = Array.from(groups.values()).sort((a, b) => {
    if (!a.label) return 1;
    if (!b.label) return -1;
    const ai = orderIndex.has(a.label) ? orderIndex.get(a.label) : orderIndex.size;
    const bi = orderIndex.has(b.label) ? orderIndex.get(b.label) : orderIndex.size;
    return ai !== bi ? ai - bi : a.label.localeCompare(b.label);
  });

  const wrap = document.createElement('div');
  wrap.className = 'reveal-in';

  sortedGroups.forEach(group => {
    const section = document.createElement('div');
    section.className = 'panel-case-group';

    const heading = document.createElement('div');
    heading.className = 'panel-case-group-heading';
    const label = group.label || 'No Case Type';
    const slug = group.label ? (CLASS_CATEGORY_SLUGS[group.label] || 'other') : 'other';
    heading.innerHTML = `<span class="class-tag class-tag-${slug}">${label}</span> <span class="panel-case-group-count">${group.clients.length}</span>`;

    const rows = group.clients.map(c => `
      <tr>
        <td data-label="Client">
          <a href="client.php?id=${c.id}" style="display:flex;align-items:center;gap:8px;font-weight:600;color:var(--ink);">
            <div class="avatar" style="width:26px;height:26px;font-size:10.5px;flex-shrink:0;">${(c.full_name || '?').charAt(0).toUpperCase()}</div>
            <span style="min-width:0;word-break:break-word;">${c.full_name}</span>
          </a>
        </td>
        <td data-label="Case Type">${c.case_type_tag_html}</td>
        <td data-label="Progress">${completionCellHtml(c.completion_pct)}</td>
      </tr>
    `).join('');

    const table = document.createElement('table');
    table.className = 'responsive-table panel-clients-table';
    table.innerHTML = `
      <thead><tr><th>Client</th><th>Case Type</th><th>Progress</th></tr></thead>
      <tbody>${rows}</tbody>
    `;

    section.appendChild(heading);
    section.appendChild(table);
    wrap.appendChild(section);
  });

  listEl.replaceChildren(wrap);
}

document.getElementById('panelSearchInput').addEventListener('input', (e) => {
  const q = e.target.value.trim().toLowerCase();
  const filtered = !q ? panelAllClients : panelAllClients.filter(c =>
    [c.full_name, c.case_type, c.email, c.phone].some(v => (v || '').toLowerCase().includes(q))
  );
  renderPanelClients(filtered);
});

document.querySelectorAll('[data-processor-row]').forEach(row => {
  row.addEventListener('click', async (e) => {
    if (e.target.closest('[data-delete-processor]')) return; // don't open panel when clicking Remove

    const processorId = row.dataset.processorRow;
    const processorName = row.dataset.processorName;
    document.getElementById('panelProcessorName').textContent = processorName;
    document.getElementById('panelClientCount').textContent = '';
    document.getElementById('panelClientList').innerHTML = '<div class="loading-spinner"><div class="spinner-ring"></div><span>Loading assigned clients…</span></div>';
    document.getElementById('panelSearchBar').style.display = 'none';
    document.getElementById('panelSearchInput').value = '';
    panelAllClients = [];
    panel.classList.add('open');

    try {
      const res = await fetch('api/get_processor_clients.php?processor_id=' + encodeURIComponent(processorId));
      const data = await res.json();
      if (!data.success) {
        document.getElementById('panelClientCount').textContent = '';
        document.getElementById('panelClientList').innerHTML = `<p class="error-text">${data.message || 'Could not load clients.'}</p>`;
        return;
      }

      panelAllClients = data.clients || [];
      panelClassCategoryOrder = data.class_category_order || [];
      document.getElementById('panelClientCount').textContent = panelAllClients.length + ' assigned client' + (panelAllClients.length === 1 ? '' : 's');

      if (panelAllClients.length > 1) {
        document.getElementById('panelSearchBar').style.display = 'block';
      }

      renderPanelClients(panelAllClients);
    } catch (err) {
      document.getElementById('panelClientCount').textContent = '';
      document.getElementById('panelClientList').innerHTML = '<p class="error-text">Network error.</p>';
    }
  });
});
</script>
</body>
</html>
