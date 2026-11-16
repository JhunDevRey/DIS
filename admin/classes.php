<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$activeNav = 'classes';

$db = getDb();
$clients = $db->query("SELECT id, full_name, case_type FROM clients ORDER BY full_name ASC")->fetchAll();

// Group clients by their (lowercased, trimmed) case type so each taxonomy
// row can look itself up in O(1). Anything that doesn't match a known
// class stays keyed by its own text and gets swept into "Other" below —
// so a client with a custom/one-off case type is never silently hidden
// from this page, just shown separately from the standard taxonomy.
$taxonomy = classTaxonomy();
$knownKeys = [];
foreach ($taxonomy as $items) {
    foreach ($items as $item) {
        $knownKeys[mb_strtolower($item)] = true;
    }
}

$clientsByKey = [];
foreach ($clients as $c) {
    $ct = trim((string)$c['case_type']);
    if ($ct === '') continue;
    $clientsByKey[mb_strtolower($ct)][] = $c;
}

$otherKeys = array_filter(array_keys($clientsByKey), fn($k) => !isset($knownKeys[$k]));
sort($otherKeys);

$totalTagged = 0;
foreach ($clientsByKey as $list) { $totalTagged += count($list); }
$totalUntagged = count($clients) - $totalTagged;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Classes · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main">
    <div class="main-header">
      <div>
        <h1>Classes</h1>
        <p>Every case type we handle, grouped by category, with who's currently assigned to each.</p>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-box stat-box-ink">
        <div class="stat-icon tone-ink"><i class="fa-solid fa-layer-group"></i></div>
        <div>
          <div class="stat-value"><?= array_sum(array_map('count', $taxonomy)) ?></div>
          <div class="stat-label">Classes</div>
        </div>
      </div>
      <div class="stat-box stat-box-accepted">
        <div class="stat-icon tone-accepted"><i class="fa-solid fa-user-check"></i></div>
        <div>
          <div class="stat-value"><?= $totalTagged ?></div>
          <div class="stat-label">Clients tagged</div>
        </div>
      </div>
      <div class="stat-box stat-box-pending">
        <div class="stat-icon tone-pending"><i class="fa-solid fa-circle-question"></i></div>
        <div>
          <div class="stat-value"><?= max(0, $totalUntagged) ?></div>
          <div class="stat-label">No case type set</div>
        </div>
      </div>
    </div>

    <?php foreach ($taxonomy as $category => $items):
      $slug = classCategorySlug($category);
      $categoryClientCount = 0;
      foreach ($items as $item) { $categoryClientCount += count($clientsByKey[mb_strtolower($item)] ?? []); }
    ?>
    <details class="class-group" open>
      <summary class="section-title">
        <span><i class="fa-solid fa-chevron-right chevron"></i> <?= h($category) ?> <span style="font-weight:400;color:var(--text-muted);text-transform:none;letter-spacing:0;">· <?= count($items) ?> class<?= count($items) === 1 ? '' : 'es' ?></span></span>
        <span class="category-count"><?= $categoryClientCount ?> client<?= $categoryClientCount === 1 ? '' : 's' ?></span>
      </summary>
      <div class="card">
        <table class="responsive-table">
          <thead>
            <tr>
              <th style="width:38%;">Class</th>
              <th>Clients</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): $matches = $clientsByKey[mb_strtolower($item)] ?? []; ?>
              <tr>
                <td data-label="Class"><span class="class-tag class-tag-<?= $slug ?>"><?= h($item) ?></span></td>
                <td data-label="Clients">
                  <?php if ($matches): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                      <?php foreach ($matches as $m): ?>
                        <a href="client.php?id=<?= (int)$m['id'] ?>" class="chip-link">
                          <span class="chip-avatar"><?= h(strtoupper(substr($m['full_name'], 0, 1))) ?></span>
                          <?= h($m['full_name']) ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span style="font-size:12.5px;color:var(--text-muted);">No clients yet</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <?php endforeach; ?>

    <?php if ($otherKeys): ?>
    <details class="class-group" open>
      <summary class="section-title">
        <span><i class="fa-solid fa-chevron-right chevron"></i> Other <span style="font-weight:400;color:var(--text-muted);text-transform:none;letter-spacing:0;">· case types outside the standard list</span></span>
        <span class="category-count"><?= array_sum(array_map(fn($k) => count($clientsByKey[$k]), $otherKeys)) ?> client<?= array_sum(array_map(fn($k) => count($clientsByKey[$k]), $otherKeys)) === 1 ? '' : 's' ?></span>
      </summary>
      <div class="card">
        <table class="responsive-table">
          <thead>
            <tr>
              <th style="width:38%;">Class</th>
              <th>Clients</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($otherKeys as $key): $matches = $clientsByKey[$key]; $label = $matches[0]['case_type']; ?>
              <tr>
                <td data-label="Class"><span class="class-tag class-tag-other"><?= h($label) ?></span></td>
                <td data-label="Clients">
                  <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                    <?php foreach ($matches as $m): ?>
                      <a href="client.php?id=<?= (int)$m['id'] ?>" class="chip-link">
                        <span class="chip-avatar"><?= h(strtoupper(substr($m['full_name'], 0, 1))) ?></span>
                        <?= h($m['full_name']) ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <?php endif; ?>

  </main>
</div>
<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
</body>
</html>
