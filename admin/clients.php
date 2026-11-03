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
// ClickUp-style list: one collapsible group per Class, in taxonomy order,
// then "Other" (custom case types) and "No case type" last.
$clientGroups = [];
foreach (classCategoryOrder() as $cat) {
    $clientGroups[classCategorySlug($cat)] = ['label' => $cat, 'color' => 'var(--class-' . classCategorySlug($cat) . ')', 'rows' => []];
}
$clientGroups['other'] = ['label' => 'Other', 'color' => 'var(--slate-light)', 'rows' => []];
$clientGroups['none']  = ['label' => 'No case type', 'color' => 'var(--pending)', 'rows' => []];
foreach ($clients as $c) {
    $slug = $c['class_category'] === null ? 'none' : classCategorySlug($c['class_category']);
    $clientGroups[$slug]['rows'][] = $c;
}
$clientGroups = array_filter($clientGroups, fn($g) => $g['rows']);

// Status square next to each name — the single most actionable doc state.
function clientDocState(array $c): array
{
    if (!$c['total_docs'])      return ['none', 'No checklist yet'];
    if ($c['revision_count'])   return ['revision', 'Needs revision'];
    if ($c['received_count'])   return ['received', 'Uploads waiting for review'];
    if ($c['pending_count'])    return ['pending', 'Waiting on client'];
    return ['accepted', 'All documents accepted'];
}

// Assignee avatar: initials, with a stable color per processor name.
function processorAvatarHtml(?string $name): string
{
    if (!$name) {
        return '<span class="cu-assignee cu-assignee-empty"><span class="cu-avatar"><i class="fa-regular fa-user"></i></span>Unassigned</span>';
    }
    $palette = ['economic', 'family', 'work', 'study', 'visit', 'lmia'];
    $color = $palette[crc32($name) % count($palette)];
    $parts = preg_split('/\s+/', trim($name));
    $initials = strtoupper(substr($parts[0], 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
    return '<span class="cu-assignee" title="' . h($name) . '"><span class="cu-avatar" style="background:var(--class-' . $color . ')">' . h($initials) . '</span><span class="cu-ellipsis">' . h($name) . '</span></span>';
}

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

    <?php if (!$clients): ?>
      <div class="card">
        <div class="empty-state">
          <div class="icon">📁</div>
          No clients yet. Add your first client to start a document checklist.
        </div>
      </div>
    <?php endif; ?>

    <div class="cu-list" id="clientsList">
      <table class="cu-table">
        <colgroup>
          <col class="cu-col-name"><col style="width:72px"><col style="width:200px"><col style="width:150px">
          <col style="width:140px"><col style="width:200px"><col style="width:120px"><col style="width:150px">
        </colgroup>
        <?php foreach ($clientGroups as $slug => $g): ?>
          <tbody class="cu-group" data-group="<?= h($slug) ?>" style="--grp:<?= $g['color'] ?>">
            <tr class="cu-group-head">
              <th class="cu-sticky">
                <button type="button" class="cu-group-toggle" aria-expanded="true">
                  <i class="fa-solid fa-caret-down cu-caret"></i>
                  <span class="cu-group-pill"><?= h($g['label']) ?></span>
                  <span class="cu-group-count"><?= count($g['rows']) ?></span>
                </button>
              </th>
              <th title="Client No.">No.</th>
              <th>Case Type</th>
              <th>Processor</th>
              <th>Documents</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Password</th>
            </tr>
            <?php foreach ($g['rows'] as $c): [$state, $stateLabel] = clientDocState($c); $total = (int)$c['total_docs']; ?>
              <tr class="cu-row" data-href="client.php?id=<?= (int)$c['id'] ?>" data-search="<?= h(strtolower($c['full_name'] . ' ' . $c['email'] . ' ' . $c['case_type'] . ' ' . $c['reference_number'] . ' ' . $c['processor_name'])) ?>">
                <td class="cu-sticky">
                  <div class="cu-name">
                    <span class="cu-status cu-status-<?= $state ?>" title="<?= h($stateLabel) ?>"></span>
                    <a href="client.php?id=<?= (int)$c['id'] ?>" class="cu-ellipsis" title="<?= h($c['full_name']) ?>"><?= h($c['full_name']) ?></a>
                  </div>
                </td>
                <td class="cu-muted"><?= $c['reference_number'] ? '#' . h($c['reference_number']) : '—' ?></td>
                <td><div class="cu-ellipsis" title="<?= h($c['case_type']) ?>"><?= classTagHtml($c['case_type']) ?></div></td>
                <td><?= processorAvatarHtml($c['processor_name']) ?></td>
                <td>
                  <?php if ($total): ?>
                    <div class="cu-progress" title="<?= (int)$c['accepted_count'] ?> accepted · <?= (int)$c['received_count'] ?> received · <?= (int)$c['revision_count'] ?> revision · <?= (int)$c['pending_count'] ?> pending">
                      <div class="cu-bar">
                        <?php foreach (['accepted', 'received', 'revision', 'pending'] as $s): if ($c[$s . '_count']): ?>
                          <span class="cu-seg-<?= $s ?>" style="width:<?= round($c[$s . '_count'] / $total * 100, 2) ?>%"></span>
                        <?php endif; endforeach; ?>
                      </div>
                      <span class="cu-progress-text"><?= (int)$c['accepted_count'] ?>/<?= $total ?></span>
                    </div>
                  <?php else: ?>
                    <span class="cu-muted">No checklist</span>
                  <?php endif; ?>
                </td>
                <td><div class="cu-ellipsis" title="<?= h($c['email']) ?>"><?= h($c['email']) ?></div></td>
                <td class="<?= $c['phone'] ? '' : 'cu-muted' ?>"><div class="cu-ellipsis"><?= h($c['phone'] ?: '—') ?></div></td>
                <td>
                  <div class="password-cell">
                    <span class="password-value" data-password="<?= h($c['plain_password'] ?: '') ?>" data-visible="0">••••••••</span>
                    <button type="button" class="icon-btn" data-toggle-password title="Show password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                    <button type="button" class="icon-btn cu-hover-action" data-edit-password="<?= (int)$c['id'] ?>" title="Set a new password for <?= h($c['full_name']) ?>" aria-label="Set a new password for <?= h($c['full_name']) ?>"><i class="fa-solid fa-pen"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr class="cu-more-row" hidden><td colspan="8"><button type="button" class="cu-ghost-link" data-show-more></button></td></tr>
            <tr class="cu-add-row"><td colspan="8"><button type="button" class="cu-ghost-link" data-add-client><i class="fa-solid fa-plus"></i> Add client</button></td></tr>
          </tbody>
        <?php endforeach; ?>
      </table>
      <div class="empty-state" id="noSearchResults" style="display:none;">
        <div class="icon">🔍</div>
        No clients match your search or filter.
      </div>
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

// ClickUp-style grouped list: search + Class filter, collapsible groups
// (remembered per browser), and a "Show N more" row per group instead of
// page-by-page pagination.
const GROUP_ROW_LIMIT = 25;
const searchInput = document.getElementById('clientSearch');
const clientGroups = Array.from(document.querySelectorAll('#clientsList tbody.cu-group'));
const expandedGroups = new Set();
let activeClassFilter = 'all';
let collapsedGroups;
try { collapsedGroups = new Set(JSON.parse(localStorage.getItem('clientsCollapsedGroups') || '[]')); }
catch (e) { collapsedGroups = new Set(); }
function saveCollapsed() {
  try { localStorage.setItem('clientsCollapsedGroups', JSON.stringify([...collapsedGroups])); } catch (e) {}
}

function renderClientsTable() {
  const q = searchInput.value.trim().toLowerCase();
  let anyMatch = false;
  clientGroups.forEach(group => {
    const slug = group.dataset.group;
    const rows = Array.from(group.querySelectorAll('tr.cu-row'));
    const matches = rows.filter(row => row.dataset.search.includes(q));
    const visible = matches.length > 0 && (activeClassFilter === 'all' || activeClassFilter === slug);
    group.hidden = !visible;
    if (!visible) return;
    anyMatch = true;

    // While searching, every group with a hit is opened and fully shown.
    const collapsed = !q && collapsedGroups.has(slug);
    const limit = (q || expandedGroups.has(slug)) ? Infinity : GROUP_ROW_LIMIT;
    group.classList.toggle('collapsed', collapsed);
    group.querySelector('.cu-group-toggle').setAttribute('aria-expanded', String(!collapsed));
    group.querySelector('.cu-group-count').textContent = matches.length;
    rows.forEach(row => { row.hidden = true; });
    if (!collapsed) matches.slice(0, limit).forEach(row => { row.hidden = false; });

    const hiddenCount = matches.length - Math.min(matches.length, limit);
    const moreRow = group.querySelector('.cu-more-row');
    moreRow.hidden = collapsed || hiddenCount <= 0;
    moreRow.querySelector('[data-show-more]').innerHTML = `<i class="fa-solid fa-angle-down"></i> Show ${hiddenCount} more`;
    group.querySelector('.cu-add-row').hidden = collapsed;
  });
  document.getElementById('noSearchResults').style.display = (clientGroups.length && !anyMatch) ? 'block' : 'none';
}

searchInput.addEventListener('input', renderClientsTable);

// The sidebar's Class shortcuts link here as ?class=<slug>; chip clicks
// keep that URL and the sidebar highlight in step.
function setClassFilter(slug) {
  const btn = document.querySelector(`#classFilterBar .class-filter-btn[data-class="${CSS.escape(slug)}"]`);
  if (!btn) return;
  document.querySelectorAll('#classFilterBar .class-filter-btn').forEach(b => b.classList.toggle('active', b === btn));
  activeClassFilter = slug;
  const url = new URL(location.href);
  if (slug === 'all') url.searchParams.delete('class'); else url.searchParams.set('class', slug);
  history.replaceState(null, '', url);
  document.querySelectorAll('.sidebar [data-nav-class]').forEach(a => a.classList.toggle('active', a.dataset.navClass === slug));
  document.querySelector('.sidebar [data-nav-clients]')?.classList.toggle('active', !document.querySelector(`.sidebar [data-nav-class="${CSS.escape(slug)}"]`));
  renderClientsTable();
}
document.querySelectorAll('#classFilterBar .class-filter-btn').forEach(btn => {
  btn.addEventListener('click', () => setClassFilter(btn.dataset.class));
});
const initialClass = new URLSearchParams(location.search).get('class');
if (initialClass) setClassFilter(initialClass);

clientGroups.forEach(group => {
  const slug = group.dataset.group;
  group.querySelector('.cu-group-toggle').addEventListener('click', () => {
    if (collapsedGroups.has(slug)) collapsedGroups.delete(slug); else collapsedGroups.add(slug);
    saveCollapsed();
    renderClientsTable();
  });
  group.querySelector('[data-show-more]').addEventListener('click', () => {
    expandedGroups.add(slug);
    renderClientsTable();
  });
  group.querySelector('[data-add-client]').addEventListener('click', () => openModal('modalNewClient'));
});

// Whole row opens the client (the name is a real link, for middle-click /
// copy-link); clicks on the row's own buttons and links are left alone.
document.querySelectorAll('#clientsList tr.cu-row').forEach(row => {
  row.addEventListener('click', (e) => {
    if (e.target.closest('a, button, input')) return;
    if (window.getSelection().toString()) return; // let people select/copy text
    if (e.ctrlKey || e.metaKey) window.open(row.dataset.href, '_blank');
    else location.href = row.dataset.href;
  });
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
