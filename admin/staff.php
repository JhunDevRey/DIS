<?php
/**
 * admin/staff.php — Staff accounts (owners only).
 * One account per person, so the activity log shows who did what and a
 * departing staff member can be switched off without changing anyone
 * else's password. Actions go through api/staff_accounts.php.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff_security.php';
requireAdmin();
requireOwner();
$activeNav = 'staff';

$db = getDb();
$accounts = $db->query('
  SELECT a.*, p.full_name AS processor_name
  FROM admin_users a LEFT JOIN processors p ON p.id = a.processor_id
  ORDER BY a.is_active DESC, a.admin_name ASC
')->fetchAll();
$processors = $db->query('SELECT id, full_name FROM processors WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll();
$me = currentAdmin();
$accountsJson = array_map(fn($a) => [
    'id' => (int)$a['id'], 'username' => $a['username'], 'admin_name' => adminDisplayName($a),
    'email' => (string)($a['email'] ?? ''), 'processor_id' => (int)($a['processor_id'] ?? 0), 'is_owner' => (int)$a['is_owner'],
], $accounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Staff accounts · Duque Immigration Document Portal</title>
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
        <h1>Staff accounts</h1>
        <p>One sign-in per person. The activity log then shows who opened or changed what, and someone leaving is switched off here without changing anyone else's password.</p>
      </div>
      <button class="btn btn-gold" id="btnNewAccount"><i class="fa-solid fa-user-plus"></i> Add staff account</button>
    </div>

    <?php if (!twoFactorAvailable()): ?>
      <div class="card card-pad" role="alert" style="border:1px solid var(--gold);">
        <p style="margin:0;font-size:13.5px;"><i class="fa-solid fa-triangle-exclamation" style="color:var(--gold);"></i> Two-step sign-in is unavailable until <code>SECURITY_KEY</code> is added to the server's config.php.</p>
      </div>
    <?php endif; ?>

    <div class="card">
      <table class="responsive-table" id="staffTable">
        <thead>
          <tr><th>Name</th><th>Username</th><th>Sends messages as</th><th>Two-step</th><th>Last sign-in</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($accounts as $a): $isMe = (int)$a['id'] === (int)$me['id']; ?>
          <tr data-id="<?= (int)$a['id'] ?>"<?= (int)$a['is_active'] ? '' : ' style="opacity:.6"' ?>>
            <td data-label="Name"><strong><?= h(adminDisplayName($a)) ?></strong><?= $isMe ? ' <span class="cu-muted">(you)</span>' : '' ?>
              <?php if ((int)$a['is_owner']): ?> <span class="pill status-received" title="Can manage staff accounts and read the activity log">Owner</span><?php endif; ?>
              <?php if (!empty($a['email'])): ?><div class="cu-muted"><?= h($a['email']) ?></div><?php endif; ?>
            </td>
            <td data-label="Username">@<?= h($a['username']) ?></td>
            <td data-label="Sends as"><?= $a['processor_name'] ? h($a['processor_name']) : '<span class="cu-muted">Picks each time</span>' ?></td>
            <td data-label="Two-step"><?= (int)($a['totp_enabled'] ?? 0) ? '<span class="pill status-accepted"><i class="fa-solid fa-shield-halved"></i> On</span>' : '<span class="pill status-pending">Off</span>' ?></td>
            <td data-label="Last sign-in"><?= !empty($a['last_login_at']) ? localTimeSpan($a['last_login_at'], ['month' => 'short', 'day' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit']) : '<span class="cu-muted">Never</span>' ?></td>
            <td data-label="Status"><?php if (!(int)$a['is_active']): ?><span class="pill status-revision">Deactivated</span><?php elseif ((int)($a['must_change_password'] ?? 0)): ?><span class="pill status-pending">Temporary password</span><?php else: ?><span class="pill status-accepted">Active</span><?php endif; ?></td>
            <td data-label="" style="text-align:right;"><div style="display:inline-flex;gap:4px;flex-wrap:nowrap;">
              <button type="button" class="icon-btn" data-edit="<?= (int)$a['id'] ?>" title="Edit" aria-label="Edit <?= h(adminDisplayName($a)) ?>"><i class="fa-solid fa-pen"></i></button>
              <button type="button" class="icon-btn" data-password="<?= (int)$a['id'] ?>" data-name="<?= h(adminDisplayName($a)) ?>" title="Set a temporary password" aria-label="Set a temporary password"><i class="fa-solid fa-key"></i></button>
              <?php if ((int)($a['totp_enabled'] ?? 0)): ?>
              <button type="button" class="icon-btn" data-act="reset_2fa" data-id="<?= (int)$a['id'] ?>" data-confirm="Reset two-step sign-in for <?= h(adminDisplayName($a)) ?>? Use this when they lost their phone. They'll be signed out and set it up again." title="Reset two-step sign-in (lost phone)" aria-label="Reset two-step sign-in"><i class="fa-solid fa-mobile-screen"></i></button>
              <?php endif; ?>
              <button type="button" class="icon-btn" data-act="sign_out" data-id="<?= (int)$a['id'] ?>" data-confirm="Sign <?= h(adminDisplayName($a)) ?> out on every device right now?" title="Sign out everywhere" aria-label="Sign out everywhere"><i class="fa-solid fa-right-from-bracket"></i></button>
              <?php if (!$isMe): ?>
                <?php if ((int)$a['is_active']): ?>
                <button type="button" class="icon-btn" data-act="set_active" data-active="0" data-id="<?= (int)$a['id'] ?>" data-confirm="Deactivate <?= h(adminDisplayName($a)) ?>? They are signed out at once and can't sign in until reactivated. Nothing they did is deleted." title="Deactivate" aria-label="Deactivate"><i class="fa-solid fa-user-slash"></i></button>
                <?php else: ?>
                <button type="button" class="icon-btn" data-act="set_active" data-active="1" data-id="<?= (int)$a['id'] ?>" title="Reactivate" aria-label="Reactivate"><i class="fa-solid fa-user-check"></i></button>
                <?php endif; ?>
              <?php endif; ?>
            </div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="help-text">Tip: once everyone has their own account, deactivate the old shared login so it can't be used any more.</p>
  </main>
</div>

<div class="modal-backdrop" id="modalAccount">
  <div class="modal">
    <h3><span id="accountTitle">Add staff account</span><button type="button" class="modal-close-btn" onclick="closeModal('modalAccount')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <form id="formAccount" autocomplete="off">
      <div class="modal-body">
        <input type="hidden" name="id" value="">
        <div class="field"><label>Name</label><input type="text" name="admin_name" required maxlength="100" placeholder="e.g. Ana Reyes"></div>
        <div class="field"><label>Username</label><input type="text" name="username" required pattern="[a-zA-Z0-9._\-]{3,50}" placeholder="e.g. ana.reyes" autocapitalize="none" spellcheck="false"></div>
        <div class="field"><label>Email <span class="cu-muted">(optional)</span></label><input type="email" name="email"></div>
        <div class="field">
          <label>Sends messages as</label>
          <select name="processor_id">
            <option value="0">Picks each time (team inbox)</option>
            <?php foreach ($processors as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['full_name']) ?></option><?php endforeach; ?>
          </select>
          <p class="help-text">Linking a processor means this person's client messages always go out under that name.</p>
        </div>
        <div class="field" id="accountPasswordField">
          <label>Temporary password</label>
          <input type="text" name="password" minlength="<?= STAFF_PASSWORD_MIN ?>" autocomplete="new-password">
          <p class="help-text">At least <?= STAFF_PASSWORD_MIN ?> characters. They must choose their own at first sign-in.</p>
        </div>
        <label class="checkbox-row" style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="is_owner" value="1"> Owner (can manage staff accounts and read the activity log)</label>
        <p class="error-text" id="accountError" style="display:none;"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalAccount')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="modalStaffPassword">
  <div class="modal">
    <h3>Set a temporary password<button type="button" class="modal-close-btn" onclick="closeModal('modalStaffPassword')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <form id="formStaffPassword" autocomplete="off">
      <div class="modal-body">
        <input type="hidden" name="id" value="">
        <p style="margin:0 0 12px;font-size:13.5px;">For <strong id="staffPasswordName"></strong>. They're signed out everywhere now and must choose their own at next sign-in.</p>
        <div class="field"><label>Temporary password</label><input type="text" name="password" required minlength="<?= STAFF_PASSWORD_MIN ?>" autocomplete="new-password"></div>
        <p class="error-text" id="staffPasswordError" style="display:none;"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalStaffPassword')">Cancel</button>
        <button type="submit" class="btn btn-primary">Set password</button>
      </div>
    </form>
  </div>
</div>

<div id="toast"></div>
<script id="staffAccounts" type="application/json"><?= json_encode($accountsJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/admin.js?v=<?= assetVersion('js/admin.js') ?>"></script>
<script>
(function () {
  const accounts = JSON.parse(document.getElementById('staffAccounts').textContent);
  const api = async (fields) => {
    const fd = fields instanceof FormData ? fields : Object.entries(fields).reduce((f, [k, v]) => (f.append(k, v), f), new FormData());
    const res = await fetch('api/staff_accounts.php', { method: 'POST', body: fd });
    return res.json();
  };
  const form = document.getElementById('formAccount');
  const err = document.getElementById('accountError');

  function openAccount(acc) {
    form.reset();
    err.style.display = 'none';
    form.elements.id.value = acc ? acc.id : '';
    form.admin_name.value = acc ? acc.admin_name : '';
    form.username.value = acc ? acc.username : '';
    form.email.value = acc ? acc.email : '';
    form.processor_id.value = acc ? String(acc.processor_id) : '0';
    form.is_owner.checked = acc ? acc.is_owner === 1 : false;
    document.getElementById('accountPasswordField').hidden = !!acc;
    form.password.required = !acc;
    document.getElementById('accountTitle').textContent = acc ? 'Edit ' + acc.admin_name : 'Add staff account';
    openModal('modalAccount');
  }
  document.getElementById('btnNewAccount').addEventListener('click', () => openAccount(null));
  document.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => openAccount(accounts.find(a => a.id === Number(b.dataset.edit)))));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.style.display = 'none';
    const fd = new FormData(form);
    fd.append('action', form.elements.id.value ? 'update' : 'create');
    try {
      const data = await api(fd);
      if (data.success) { closeModal('modalAccount'); showToast(data.message || 'Saved.'); setTimeout(() => location.reload(), 1200); }
      else { err.textContent = data.message || 'Could not save.'; err.style.display = 'block'; }
    } catch (x) { err.textContent = 'Network error. Please try again.'; err.style.display = 'block'; }
  });

  const pwForm = document.getElementById('formStaffPassword');
  document.querySelectorAll('[data-password]').forEach(b => b.addEventListener('click', () => {
    pwForm.reset();
    pwForm.elements.id.value = b.dataset.password;
    document.getElementById('staffPasswordName').textContent = b.dataset.name;
    document.getElementById('staffPasswordError').style.display = 'none';
    openModal('modalStaffPassword');
  }));
  pwForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(pwForm);
    fd.append('action', 'set_password');
    const pe = document.getElementById('staffPasswordError');
    try {
      const data = await api(fd);
      if (data.success) { closeModal('modalStaffPassword'); showToast(data.message); setTimeout(() => location.reload(), 1500); }
      else { pe.textContent = data.message || 'Could not set the password.'; pe.style.display = 'block'; }
    } catch (x) { pe.textContent = 'Network error. Please try again.'; pe.style.display = 'block'; }
  });

  document.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', async () => {
    if (b.dataset.confirm && !(await showConfirm(b.dataset.confirm, { title: b.title, confirmLabel: b.title }))) return;
    const fields = { action: b.dataset.act, id: b.dataset.id };
    if (b.dataset.active !== undefined) fields.active = b.dataset.active;
    try {
      const data = await api(fields);
      showToast(data.message || 'Done.', !data.success);
      if (data.success) setTimeout(() => location.reload(), 1200);
    } catch (x) { showToast('Network error. Please try again.', true); }
  }));
})();
</script>
</body>
</html>
