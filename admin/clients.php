<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$activeNav = 'clients';

$db = getDb();
$processors = $db->query('SELECT * FROM processors ORDER BY full_name ASC')->fetchAll();
$clients = $db->query("
  SELECT c.*, pr.full_name AS processor_name,
    SUM(CASE WHEN dr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN dr.status = 'received' THEN 1 ELSE 0 END) AS received_count,
    SUM(CASE WHEN dr.status = 'needs_revision' THEN 1 ELSE 0 END) AS revision_count,
    SUM(CASE WHEN dr.status = 'accepted' THEN 1 ELSE 0 END) AS accepted_count,
    COUNT(dr.id) AS total_docs
  FROM clients c
  LEFT JOIN document_requests dr ON dr.client_id = c.id
  LEFT JOIN processors pr ON pr.id = c.processor_id
  GROUP BY c.id
  ORDER BY c.created_at DESC
")->fetchAll();

// Same Class taxonomy used everywhere else (the processor panel, the
// colored case-type tags) — computed here too so the Clients list can
// offer the same "filter by Class" the processor panel already has.
// null means no case type set at all (these only ever show under "All").
foreach ($clients as &$c) {
    $caseType = trim((string)($c['case_type'] ?? ''));
    $c['class_category'] = $caseType === '' ? null : (classCategoryFor($caseType) ?? 'Other');
}
unset($c);

$classFilterCounts = ['all' => count($clients), 'other' => 0];
foreach (classCategoryOrder() as $cat) {
    $classFilterCounts[classCategorySlug($cat)] = 0;
}
foreach ($clients as $c) {
    if ($c['class_category'] === null) continue;
    $slug = classCategorySlug($c['class_category']);
    $classFilterCounts[$slug] = ($classFilterCounts[$slug] ?? 0) + 1;
}

// Client No. datalist: a straightforward 1-100 range to pick from, PLUS
// any existing numbers outside that range (rare, but possible for a
// long-running client base) — so the dropdown is genuinely useful for
// picking any number, not just ones already on file.
$existingClientNumbers = array_values(array_unique(array_filter(array_column($clients, 'reference_number'))));
$clientNoOptions = array_unique(array_merge(range(1, 100), array_map('strval', $existingClientNumbers)));
sort($clientNoOptions, SORT_NATURAL);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clients · Duque Immigration Document Portal</title>
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
        <h1>Clients</h1>
        <p>Manage document checklists, review uploads, and track status for every case.</p>
      </div>
      <button class="btn btn-gold" id="btnNewClient"><i class="fa-solid fa-user-plus"></i> Add client</button>
    </div>

    <div class="search-bar">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="clientSearch" placeholder="Search by name, email, or case type…">
    </div>

    <div class="class-filter-bar" id="classFilterBar">
      <button type="button" class="class-filter-btn active" data-class="all">All <span class="count"><?= (int)$classFilterCounts['all'] ?></span></button>
      <?php foreach (classCategoryOrder() as $cat): $slug = classCategorySlug($cat); ?>
        <button type="button" class="class-filter-btn" data-class="<?= h($slug) ?>"><?= h($cat) ?> <span class="count"><?= (int)$classFilterCounts[$slug] ?></span></button>
      <?php endforeach; ?>
      <button type="button" class="class-filter-btn" data-class="other">Other <span class="count"><?= (int)$classFilterCounts['other'] ?></span></button>
    </div>

    <div class="card">
      <table class="responsive-table">
        <thead>
          <tr>
            <th>Name of Applicant</th>
            <th>Case Type</th>
            <th>Email Address</th>
            <th>Phone Number</th>
            <th>Password</th>
          </tr>
        </thead>
        <tbody id="clientsTbody">
          <?php if (!$clients): ?>
            <tr><td colspan="5">
              <div class="empty-state">
                <div class="icon">📁</div>
                No clients yet. Add your first client to start a document checklist.
              </div>
            </td></tr>
          <?php endif; ?>
          <?php foreach ($clients as $c): ?>
            <tr data-search="<?= h(strtolower($c['full_name'] . ' ' . $c['email'] . ' ' . $c['case_type'] . ' ' . $c['reference_number'] . ' ' . $c['processor_name'])) ?>" data-class="<?= $c['class_category'] ? h(classCategorySlug($c['class_category'])) : '' ?>">
              <td>
                <a href="client.php?id=<?= (int)$c['id'] ?>" style="display:flex;align-items:center;gap:12px;color:inherit;">
                  <div class="avatar"><?= h(strtoupper(substr($c['full_name'], 0, 1))) ?></div>
                  <div>
                    <div style="font-weight:600;color:var(--ink);"><?= h($c['full_name']) ?></div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:1px;">
                      <?= $c['reference_number'] ? 'Client No. ' . h($c['reference_number']) : 'No Client No. yet' ?> · <?= $c['processor_name'] ? h($c['processor_name']) : 'Unassigned' ?>
                    </div>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:3px;">
                      <?php if ($c['pending_count']):  ?><span class="pill status-pending" style="padding:2px 7px;"><?= (int)$c['pending_count'] ?> pending</span><?php endif; ?>
                      <?php if ($c['received_count']): ?><span class="pill status-received" style="padding:2px 7px;"><?= (int)$c['received_count'] ?> received</span><?php endif; ?>
                      <?php if ($c['revision_count']): ?><span class="pill status-revision" style="padding:2px 7px;"><?= (int)$c['revision_count'] ?> revision</span><?php endif; ?>
                      <?php if ($c['accepted_count']): ?><span class="pill status-accepted" style="padding:2px 7px;"><?= (int)$c['accepted_count'] ?> accepted</span><?php endif; ?>
                      <?php if (!$c['total_docs']): ?><span style="color:var(--text-muted);font-size:12px;">No checklist yet</span><?php endif; ?>
                    </div>
                  </div>
                </a>
              </td>
              <td data-label="Case Type"><?= classTagHtml($c['case_type']) ?></td>
              <td data-label="Email Address"><?= h($c['email']) ?></td>
              <td data-label="Phone Number"><?= h($c['phone'] ?: '—') ?></td>
              <td data-label="Password">
                <div class="password-cell">
                  <span class="password-value" data-password="<?= h($c['plain_password'] ?: '') ?>" data-visible="0">••••••••</span>
                  <button type="button" class="icon-btn" data-toggle-password title="Show password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                  <button type="button" class="icon-btn" data-edit-password="<?= (int)$c['id'] ?>" title="Set a new password for <?= h($c['full_name']) ?>" aria-label="Set a new password for <?= h($c['full_name']) ?>"><i class="fa-solid fa-pen"></i></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="empty-state" id="noSearchResults" style="display:none;">
        <div class="icon">🔍</div>
        No clients match your search or filter.
      </div>
    </div>

    <div class="pagination" id="clientsPagination" style="display:none;">
      <button type="button" class="btn btn-ghost btn-sm" id="clientsPrevPage"><i class="fa-solid fa-chevron-left"></i> Prev</button>
      <span class="pagination-info" id="clientsPageInfo">Page 1 of 1</span>
      <button type="button" class="btn btn-ghost btn-sm" id="clientsNextPage">Next <i class="fa-solid fa-chevron-right"></i></button>
    </div>
  </main>
</div>

<div class="modal-backdrop" id="modalNewClient" data-no-outside-close>
  <div class="modal modal-wide">
    <h3>Add a new client<button type="button" class="modal-close-btn" onclick="closeModal('modalNewClient')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <form id="formNewClient">
      <div class="modal-body">
      <div class="modal-columns">
        <div class="modal-column">
          <p class="modal-column-heading">Client details</p>
          <div class="field">
            <label>Full name</label>
            <input type="text" name="full_name" required>
          </div>
          <div class="field">
            <label>Email</label>
            <input type="email" name="email" required>
          </div>
          <div class="field">
            <label>Additional emails <span style="font-weight:400;text-transform:none;">(CC, optional)</span></label>
            <input type="text" name="cc_emails" placeholder="e.g. spouse@example.com, agent@example.com">
            <p class="help-text">Separate multiple with commas. Anyone listed here is CC'd on every notification email sent to this client.</p>
          </div>
          <div class="field">
            <label>Case type</label>
            <div class="combo-field">
              <input type="text" name="case_type" id="caseTypeInput" placeholder="e.g. Study Permit" autocomplete="off">
              <button type="button" class="combo-toggle" id="caseTypeToggle" tabindex="-1" aria-label="Show case type suggestions"><i class="fa-solid fa-chevron-down"></i></button>
              <div class="dropdown-menu combo-dropdown" id="caseTypeDropdown">
                <?php foreach (classTaxonomy() as $category => $items): ?>
                  <div class="dropdown-group-label"><?= h($category) ?></div>
                  <?php foreach ($items as $item): ?>
                    <button type="button" class="dropdown-item" data-value="<?= h($item) ?>"><?= h($item) ?></button>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </div>
            </div>
            <p class="help-text">Pick from the list, or type your own if it's not there yet.</p>
          </div>
          <div class="field">
            <label>Phone</label>
            <input type="text" name="phone">
          </div>
          <p class="modal-column-heading" style="margin-top:20px;">Portal account</p>
          <div class="field">
            <label>Temporary password</label>
            <input type="text" name="password" required placeholder="Given to the client to log in">
          </div>
          <div class="field">
            <label>Portal account created on</label>
            <p class="help-text" style="margin-top:-4px;margin-bottom:8px;">Defaults to today — only change this when importing an existing client (e.g. from Notion) to keep their real original date instead of today's.</p>
            <div class="field-row">
              <div class="field" style="flex:0 0 70px;">
                <input type="text" name="portal_created_day" maxlength="2" placeholder="DD" value="<?= date('j') ?>">
              </div>
              <div class="field">
                <select name="portal_created_month">
                  <?php foreach (range(1, 12) as $m): ?>
                    <option value="<?= $m ?>" <?= (int)date('n') === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <select name="portal_created_year">
                  <?php foreach (range((int)date('Y'), (int)date('Y') - 15) as $y): ?>
                    <option value="<?= $y ?>" <?= (int)date('Y') === $y ? 'selected' : '' ?>><?= $y ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-column">
          <p class="modal-column-heading">Assignment &amp; Dropbox</p>
          <div class="field">
            <label>Client No. <span style="font-weight:400;text-transform:none;">(optional)</span></label>
            <div class="combo-field">
              <input type="text" name="reference_number" id="clientNoInput" placeholder="Leave blank to auto-assign" autocomplete="off">
              <button type="button" class="combo-toggle" id="clientNoToggle" tabindex="-1" aria-label="Show client number suggestions"><i class="fa-solid fa-chevron-down"></i></button>
              <div class="dropdown-menu combo-dropdown" id="clientNoDropdown">
                <?php foreach ($clientNoOptions as $num): ?>
                  <button type="button" class="dropdown-item" data-value="<?= h($num) ?>">
                    <?= h($num) ?><?= in_array((string)$num, $existingClientNumbers, true) ? ' <span class="in-use-tag">in use</span>' : '' ?>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
            <p class="help-text">Click the arrow to pick 1–100, or just type any number (including above 100). Leave blank to auto-assign the next number for this month. Only fill this in if the client already has a Client No. from your Master List — their existing Dropbox folder will be reused instead of a new one being created.</p>
          </div>
          <div class="field">
            <label>Dropbox folder added on <span style="font-weight:400;text-transform:none;">(optional)</span></label>
            <p class="help-text" style="margin-top:-4px;margin-bottom:8px;">Just for your own reference — records when this client's folder was actually added in Dropbox (useful for imported clients whose real folder predates this system). Purely informational: doesn't affect folder creation or naming in any way.</p>
            <div class="field-row">
              <div class="field">
                <select name="dropbox_added_month">
                  <option value="">— Month —</option>
                  <?php foreach (range(1, 12) as $m): ?>
                    <option value="<?= $m ?>" <?= (int)date('n') === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <select name="dropbox_added_year">
                  <option value="">— Year —</option>
                  <?php foreach (range((int)date('Y'), (int)date('Y') - 15) as $y): ?>
                    <option value="<?= $y ?>" <?= (int)date('Y') === $y ? 'selected' : '' ?>><?= $y ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="field">
            <label>Assigned processor</label>
            <select name="processor_id">
              <option value="">— Unassigned —</option>
              <?php foreach ($processors as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= h($p['full_name']) ?> (<?= h($p['email']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Existing Dropbox folder path <span style="font-weight:400;text-transform:none;">(optional)</span></label>
            <input type="text" name="existing_dropbox_path" placeholder="e.g. /1. MASTER LIST/2026/8. AUGUST 2026/1. JANE DOE - WP NEW — or paste a Dropbox share link">
            <p class="help-text">Path or a Dropbox share link — both work. Use this if the client already has a real folder in Dropbox, to link straight to it instead of creating a new one. <strong>It must resolve to a real, existing folder</strong> — if it can't be found, the client won't be created at all.</p>
          </div>
        </div>
      </div>
      <p class="error-text" id="newClientError" style="display:none;"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalNewClient')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create client</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="modalEditPassword">
  <div class="modal">
    <h3>Set a new password<button type="button" class="modal-close-btn" onclick="closeModal('modalEditPassword')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <form id="formEditPassword">
      <div class="modal-body">
      <input type="hidden" name="client_id" value="">
      <div class="field">
        <label>New password</label>
        <input type="text" name="new_password" required minlength="6" placeholder="Share this with the client">
      </div>
      <p class="error-text" id="editPasswordError" style="display:none;"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalEditPassword')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save password</button>
      </div>
    </form>
  </div>
</div>

<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/admin.js?v=<?= assetVersion('js/admin.js') ?>"></script>
<script>
document.getElementById('btnNewClient').addEventListener('click', () => openModal('modalNewClient'));

const clientNoInput = document.getElementById('clientNoInput');
const clientNoToggle = document.getElementById('clientNoToggle');
const clientNoDropdown = document.getElementById('clientNoDropdown');

clientNoToggle.addEventListener('click', (e) => {
  e.stopPropagation();
  clientNoDropdown.classList.toggle('open');
});
document.addEventListener('click', () => clientNoDropdown.classList.remove('open'));
clientNoDropdown.querySelectorAll('[data-value]').forEach(item => {
  item.addEventListener('click', (e) => {
    e.stopPropagation();
    clientNoInput.value = item.dataset.value;
    clientNoDropdown.classList.remove('open');
    clientNoInput.focus();
  });
});

const caseTypeInput = document.getElementById('caseTypeInput');
const caseTypeToggle = document.getElementById('caseTypeToggle');
const caseTypeDropdown = document.getElementById('caseTypeDropdown');

caseTypeToggle.addEventListener('click', (e) => {
  e.stopPropagation();
  caseTypeDropdown.classList.toggle('open');
});
document.addEventListener('click', () => caseTypeDropdown.classList.remove('open'));
caseTypeDropdown.querySelectorAll('[data-value]').forEach(item => {
  item.addEventListener('click', (e) => {
    e.stopPropagation();
    caseTypeInput.value = item.dataset.value;
    caseTypeDropdown.classList.remove('open');
    caseTypeInput.focus();
  });
});

document.getElementById('formNewClient').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const errEl = document.getElementById('newClientError');
  errEl.style.display = 'none';
  try {
    const res = await fetch('api/add_client.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      if (data.dropbox_warning) {
        await showNotice(data.dropbox_warning, { title: 'Client created' });
      }
      location.reload();
    } else {
      errEl.textContent = data.message || 'Could not create client.';
      errEl.style.display = 'block';
      if (data.error_type === 'dropbox_path') {
        await showNotice(data.message, { title: 'Dropbox folder not found' });
      }
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
  }
});

// Search / filter, with pagination over the (possibly filtered) result set.
// Client-side, since this reuses data already rendered into the table —
// no extra request needed just to turn a page.
const CLIENTS_PAGE_SIZE = 15;
const searchInput = document.getElementById('clientSearch');
const allClientRows = Array.from(document.querySelectorAll('#clientsTbody tr[data-search]'));
let clientsCurrentPage = 1;
let activeClassFilter = 'all';

function renderClientsTable() {
  const q = searchInput.value.trim().toLowerCase();
  const matches = allClientRows.filter(row =>
    row.dataset.search.includes(q) &&
    (activeClassFilter === 'all' || row.dataset.class === activeClassFilter)
  );
  const totalPages = Math.max(1, Math.ceil(matches.length / CLIENTS_PAGE_SIZE));
  clientsCurrentPage = Math.min(Math.max(1, clientsCurrentPage), totalPages);
  const start = (clientsCurrentPage - 1) * CLIENTS_PAGE_SIZE;
  const end = start + CLIENTS_PAGE_SIZE;
  const pageRows = matches.slice(start, end);

  allClientRows.forEach(row => { row.style.display = 'none'; });
  pageRows.forEach(row => { row.style.display = ''; });

  document.getElementById('noSearchResults').style.display = (allClientRows.length && matches.length === 0) ? 'block' : 'none';

  const pagination = document.getElementById('clientsPagination');
  if (matches.length > CLIENTS_PAGE_SIZE) {
    pagination.style.display = 'flex';
    document.getElementById('clientsPageInfo').textContent = `Page ${clientsCurrentPage} of ${totalPages}`;
    document.getElementById('clientsPrevPage').disabled = clientsCurrentPage <= 1;
    document.getElementById('clientsNextPage').disabled = clientsCurrentPage >= totalPages;
  } else {
    pagination.style.display = 'none';
  }
}

searchInput.addEventListener('input', () => {
  clientsCurrentPage = 1;
  renderClientsTable();
});

document.querySelectorAll('#classFilterBar .class-filter-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#classFilterBar .class-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeClassFilter = btn.dataset.class;
    clientsCurrentPage = 1;
    renderClientsTable();
  });
});
document.getElementById('clientsPrevPage').addEventListener('click', () => {
  clientsCurrentPage--;
  renderClientsTable();
});
document.getElementById('clientsNextPage').addEventListener('click', () => {
  clientsCurrentPage++;
  renderClientsTable();
});

renderClientsTable();

// Show/hide password
document.querySelectorAll('[data-toggle-password]').forEach(btn => {
  btn.addEventListener('click', () => {
    const valueEl = btn.closest('.password-cell').querySelector('.password-value');
    const icon = btn.querySelector('i');
    const isVisible = valueEl.dataset.visible === '1';
    if (isVisible) {
      valueEl.textContent = '••••••••';
      valueEl.dataset.visible = '0';
      icon.className = 'fa-regular fa-eye';
      btn.title = 'Show password';
      btn.setAttribute('aria-label', 'Show password');
    } else {
      valueEl.textContent = valueEl.dataset.password || '(not set)';
      valueEl.dataset.visible = '1';
      icon.className = 'fa-regular fa-eye-slash';
      btn.title = 'Hide password';
      btn.setAttribute('aria-label', 'Hide password');
    }
  });
});

// Edit / replace password
let editingClientId = null;
document.querySelectorAll('[data-edit-password]').forEach(btn => {
  btn.addEventListener('click', () => {
    editingClientId = btn.dataset.editPassword;
    document.querySelector('#formEditPassword [name="client_id"]').value = editingClientId;
    document.querySelector('#formEditPassword [name="new_password"]').value = '';
    document.getElementById('editPasswordError').style.display = 'none';
    openModal('modalEditPassword');
  });
});

document.getElementById('formEditPassword').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const errEl = document.getElementById('editPasswordError');
  errEl.style.display = 'none';
  try {
    const res = await fetch('api/admin_reset_client_password.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      const row = document.querySelector(`[data-edit-password="${editingClientId}"]`).closest('tr');
      const valueEl = row.querySelector('.password-value');
      valueEl.dataset.password = data.plain_password;
      valueEl.dataset.visible = '0';
      valueEl.textContent = '••••••••';
      row.querySelector('[data-toggle-password] i').className = 'fa-regular fa-eye';
      closeModal('modalEditPassword');
      showToast('Password updated.');
    } else {
      errEl.textContent = data.message || 'Could not update password.';
      errEl.style.display = 'block';
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
  }
});
</script>
</body>
</html>
