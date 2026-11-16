<?php
/**
 * admin/activity.php — the activity log (owners only): sign-ins, file
 * views and downloads, password and account changes. Written by
 * auditLog() (includes/audit.php); kept for AUDIT_KEEP_DAYS.
 *   ?client_id=N  one client's history   ?actor=admin:N  one staff account
 *   ?type=signin|files|clients|staff     ?from=YYYY-MM-DD&to=YYYY-MM-DD   ?page=N
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff_security.php';
requireAdmin();
requireOwner();
$activeNav = 'activity';

$db = getDb();
auditEnsureTable($db);
$perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$where = [];
$params = [];

$clientId = (int)($_GET['client_id'] ?? 0);
if ($clientId > 0) { $where[] = 'l.client_id = ?'; $params[] = $clientId; }

$actor = (string)($_GET['actor'] ?? '');
if (preg_match('/^(admin|client):(\d+)$/', $actor, $m)) { $where[] = 'l.actor_type = ? AND l.actor_id = ?'; $params[] = $m[1]; $params[] = (int)$m[2]; }

$types = [
    'signin'  => ['Sign-ins', "(l.action LIKE '%.login%' OR l.action LIKE '%.logout' OR l.action LIKE 'staff.2fa%')"],
    'files'   => ['Files', "(l.action LIKE 'file.%' OR l.action LIKE 'message.file%' OR l.action = 'docfield.delete')"],
    'clients' => ['Client changes', "(l.action LIKE 'client.%' AND l.action NOT LIKE 'client.login%')"],
    'staff'   => ['Staff accounts', "(l.action LIKE 'staff.account%' OR l.action IN ('staff.password_reset','staff.2fa_reset','staff.password_changed','staff.devices_signed_out') OR l.action LIKE 'processor.%')"],
    'failed'  => ['Failed sign-ins', "(l.action LIKE '%login_failed' OR l.action = 'staff.login_2fa_failed')"],
];
$type = (string)($_GET['type'] ?? '');
if (isset($types[$type])) { $where[] = $types[$type][1]; }

$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'l.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'l.created_at <= ?'; $params[] = $to . ' 23:59:59'; }

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $db->prepare("SELECT COUNT(*) FROM audit_log l $sqlWhere");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $db->prepare("
  SELECT l.*, c.full_name AS client_name
  FROM audit_log l LEFT JOIN clients c ON c.id = l.client_id
  $sqlWhere
  ORDER BY l.id DESC
  LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$staff = $db->query('SELECT id, username, admin_name FROM admin_users ORDER BY admin_name')->fetchAll();
$clientName = '';
if ($clientId > 0) {
    $s = $db->prepare('SELECT full_name FROM clients WHERE id = ?');
    $s->execute([$clientId]);
    $clientName = (string)$s->fetchColumn();
}
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['client_id' => $clientId ?: null, 'actor' => $actor ?: null, 'type' => $type ?: null, 'from' => $from ?: null, 'to' => $to ?: null], $over), fn($v) => $v !== null && $v !== ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Activity · Duque Immigration Document Portal</title>
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
        <h1>Activity<?= $clientName !== '' ? ' · ' . h($clientName) : '' ?></h1>
        <p>Who signed in, opened or downloaded which files, and changed passwords or accounts. Kept for about <?= (int)round(AUDIT_KEEP_DAYS / 30) ?> months.</p>
      </div>
    </div>

    <form class="card card-pad" method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
      <?php if ($clientId): ?><input type="hidden" name="client_id" value="<?= $clientId ?>"><?php endif; ?>
      <div class="field" style="margin:0;min-width:150px;"><label>Show</label>
        <select name="type"><option value="">Everything</option><?php foreach ($types as $k => [$label]): ?><option value="<?= h($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field" style="margin:0;min-width:150px;"><label>Staff member</label>
        <select name="actor"><option value="">Anyone</option><?php foreach ($staff as $s): $v = 'admin:' . (int)$s['id']; ?><option value="<?= h($v) ?>"<?= $actor === $v ? ' selected' : '' ?>><?= h($s['admin_name'] . ' (@' . $s['username'] . ')') ?></option><?php endforeach; ?></select>
      </div>
      <div class="field" style="margin:0;"><label>From</label><input type="date" name="from" value="<?= h($from) ?>"></div>
      <div class="field" style="margin:0;"><label>To</label><input type="date" name="to" value="<?= h($to) ?>"></div>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
      <?php if ($where): ?><a class="btn btn-ghost" href="activity.php">Clear</a><?php endif; ?>
    </form>

    <div class="card">
      <table class="responsive-table">
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>Client</th><th>Details</th><th>From</th></tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6"><div class="empty-state"><div class="icon">🗂️</div>Nothing recorded for this filter yet.</div></td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r):
            $fail = str_contains($r['action'], 'failed');
            $who = $r['actor_name'] ?: ($r['actor_type'] === 'system' ? 'System' : ucfirst($r['actor_type']));
          ?>
          <tr>
            <td data-label="When" style="white-space:nowrap;"><?= localTimeSpan($r['created_at'], ['month' => 'short', 'day' => 'numeric', 'year' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit']) ?></td>
            <td data-label="Who">
              <?php if ($r['actor_type'] === 'admin' && $r['actor_id']): ?><a href="activity.php?<?= h($qs(['actor' => 'admin:' . (int)$r['actor_id'], 'page' => null])) ?>"><?= h($who) ?></a>
              <?php else: ?><?= h($who) ?><?php endif; ?>
              <div class="cu-muted"><?= $r['actor_type'] === 'admin' ? 'Staff' : ($r['actor_type'] === 'client' ? 'Client' : '') ?></div>
            </td>
            <td data-label="What"><?= $fail ? '<span class="pill status-revision">' . h(auditActionLabel($r['action'])) . '</span>' : h(auditActionLabel($r['action'])) ?></td>
            <td data-label="Client"><?php if ($r['client_id']): ?><a href="client.php?id=<?= (int)$r['client_id'] ?>"><?= h($r['client_name'] ?: '#' . $r['client_id']) ?></a> <a class="cu-muted" href="activity.php?<?= h($qs(['client_id' => (int)$r['client_id'], 'page' => null])) ?>" title="Only this client">(filter)</a><?php endif; ?></td>
            <td data-label="Details" style="max-width:320px;word-break:break-word;"><?= h($r['detail'] ?? '') ?></td>
            <td data-label="From" class="cu-muted" title="<?= h($r['user_agent'] ?? '') ?>"><?= h($r['ip'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <p style="display:flex;gap:10px;align-items:center;">
        <?php if ($page > 1): ?><a class="btn btn-ghost" href="activity.php?<?= h($qs(['page' => $page - 1])) ?>">Newer</a><?php endif; ?>
        <span class="cu-muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> entries</span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost" href="activity.php?<?= h($qs(['page' => $page + 1])) ?>">Older</a><?php endif; ?>
      </p>
    <?php endif; ?>
  </main>
</div>
<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/admin.js?v=<?= assetVersion('js/admin.js') ?>"></script>
</body>
</html>
