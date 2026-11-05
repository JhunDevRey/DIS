<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$activeNav = 'profile';

$db = getDb();
$stmt = $db->prepare('SELECT * FROM admin_users WHERE id = ?');
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();
$thisDeviceRemembered = adminRememberThisDevice((int)$_SESSION['admin_id']);
$otherDevices = adminRememberOtherDeviceCount((int)$_SESSION['admin_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>My Profile · Duque Immigration Document Portal</title>
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
        <h1>My Profile</h1>
        <p>Update your name or change your password.</p>
      </div>
    </div>

    <div style="max-width:480px;">
      <div class="card card-pad">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">
          <div class="avatar" style="width:52px;height:52px;font-size:18px;"><?= h(strtoupper(substr($admin['admin_name'], 0, 1))) ?></div>
          <div>
            <div style="font-weight:700;font-size:16px;color:var(--ink);"><?= h($admin['admin_name']) ?></div>
            <div style="font-size:12.5px;color:var(--text-muted);">@<?= h($admin['username']) ?> · Staff</div>
          </div>
        </div>

        <form id="formProfile">
          <div class="field">
            <label>Full name</label>
            <input type="text" name="full_name" value="<?= h($admin['admin_name']) ?>" required>
          </div>
          <div class="field">
            <label>Username</label>
            <input type="text" value="<?= h($admin['username']) ?>" disabled style="background:var(--surface-alt);color:var(--text-muted);">
            <p class="help-text">Contact another administrator to change your username.</p>
          </div>
          <p class="error-text" id="profileError" style="display:none;"></p>
          <p style="color:var(--accepted);font-size:13px;display:none;" id="profileSuccess">Saved.</p>
          <button type="submit" class="btn btn-primary">Save changes</button>
        </form>
      </div>

      <div class="card card-pad">
        <h3 style="font-family:var(--font-display);font-size:16px;margin:0 0 16px;color:var(--ink);">Change password</h3>
        <form id="formPassword">
          <div class="field">
            <label>Current password</label>
            <input type="password" name="current_password" required>
          </div>
          <div class="field">
            <label>New password</label>
            <input type="password" name="new_password" required minlength="6">
          </div>
          <div class="field">
            <label>Confirm new password</label>
            <input type="password" name="confirm_password" required minlength="6">
          </div>
          <p class="error-text" id="passwordError" style="display:none;"></p>
          <button type="submit" class="btn btn-primary">Update password</button>
        </form>
      </div>

      <div class="card card-pad">
        <h3 style="font-family:var(--font-display);font-size:16px;margin:0 0 6px;color:var(--ink);">Signed-in devices</h3>
        <p class="help-text" style="margin:0 0 14px;font-size:13px;">
          <?php if ($thisDeviceRemembered): ?>
            <i class="fa-solid fa-circle-check" style="color:var(--accepted);"></i> This device stays signed in for up to <?= ADMIN_REMEMBER_DAYS ?> days.
          <?php else: ?>
            This device will ask for the password each time. To stay signed in, sign out and tick “Keep me signed in on this device” when you sign back in.
          <?php endif; ?>
        </p>
        <p style="margin:0 0 14px;font-size:13.5px;color:var(--text);" id="otherDevicesText">
          <?= $otherDevices ? 'This staff login is also signed in on <strong>' . $otherDevices . '</strong> other device' . ($otherDevices === 1 ? '' : 's') . '.' : 'Not signed in on any other device.' ?>
        </p>
        <button type="button" class="btn btn-ghost" id="btnForgetDevices"<?= $otherDevices ? '' : ' disabled' ?>><i class="fa-solid fa-right-from-bracket"></i> Sign out of all other devices</button>
        <p class="help-text" style="margin-top:10px;">The team shares one login, so this signs out every other staff computer and phone — use it if a device is lost or a staff member leaves. They just sign in again. Changing the password does the same.</p>
      </div>
    </div>
  </main>
</div>

<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/admin.js?v=<?= assetVersion('js/admin.js') ?>"></script>
<script>
document.getElementById('formProfile').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'update_name');
  const errEl = document.getElementById('profileError');
  errEl.style.display = 'none';
  try {
    const res = await fetch('api/update_admin_profile.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      showToast('Profile updated.');
    } else {
      errEl.textContent = data.message || 'Could not save changes.';
      errEl.style.display = 'block';
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
  }
});

document.getElementById('formPassword').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const errEl = document.getElementById('passwordError');
  errEl.style.display = 'none';

  if (form.new_password.value !== form.confirm_password.value) {
    errEl.textContent = 'New passwords do not match.';
    errEl.style.display = 'block';
    return;
  }

  const fd = new FormData(form);
  fd.append('action', 'update_password');
  try {
    const res = await fetch('api/update_admin_profile.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      showToast(data.message || 'Password updated.');
      form.reset();
      if (/signed out/.test(data.message || '')) {
        document.getElementById('otherDevicesText').textContent = 'Not signed in on any other device.';
        document.getElementById('btnForgetDevices').disabled = true;
      }
    } else {
      errEl.textContent = data.message || 'Could not update password.';
      errEl.style.display = 'block';
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
  }
});

document.getElementById('btnForgetDevices').addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  const ok = await showConfirm('Sign out every other computer and phone using the staff login? Staff will need the password to sign back in. This device stays signed in.', { title: 'Sign out other devices', confirmLabel: 'Sign them out' });
  if (!ok) return;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'forget_devices');
  try {
    const res = await fetch('api/update_admin_profile.php', { method: 'POST', body: fd });
    const data = await res.json();
    showToast(data.message || 'Done.', !data.success);
    if (data.success) document.getElementById('otherDevicesText').textContent = 'Not signed in on any other device.';
    else btn.disabled = false;
  } catch (err) {
    showToast('Network error. Please try again.', true);
    btn.disabled = false;
  }
});
</script>
</body>
</html>
