<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff_security.php';
requireAdmin();
$activeNav = 'profile';

$db = getDb();
$stmt = $db->prepare('SELECT * FROM admin_users WHERE id = ?');
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();
$thisDeviceRemembered = adminRememberThisDevice((int)$_SESSION['admin_id']);
$otherDevices = adminRememberOtherDeviceCount((int)$_SESSION['admin_id']);
$twoFaOn = adminUses2fa($admin);
$twoFaPossible = twoFactorAvailable();
$mustChangePw = (int)($admin['must_change_password'] ?? 0) === 1;
$must2fa = adminRequire2fa() && !$twoFaOn;
$recoveryLeft = $twoFaOn ? adminRecoveryCodesLeft($admin) : 0;
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main">
    <div class="main-header">
      <div>
        <h1>My Profile</h1>
        <p>Your name, password and two-step sign-in.</p>
      </div>
    </div>

    <div style="max-width:480px;">
      <?php if ($mustChangePw): ?>
      <div class="card card-pad" role="alert" style="border:1px solid var(--gold);">
        <h3 style="font-family:var(--font-display);font-size:16px;margin:0 0 6px;color:var(--ink);"><i class="fa-solid fa-key" style="color:var(--gold);"></i> Choose your own password</h3>
        <p style="margin:0;font-size:13.5px;">You signed in with a temporary password. Choose a new one below (at least <?= STAFF_PASSWORD_MIN ?> characters) to continue.</p>
      </div>
      <?php elseif ($must2fa): ?>
      <div class="card card-pad" role="alert" style="border:1px solid var(--gold);">
        <h3 style="font-family:var(--font-display);font-size:16px;margin:0 0 6px;color:var(--ink);"><i class="fa-solid fa-shield-halved" style="color:var(--gold);"></i> Turn on two-step sign-in</h3>
        <p style="margin:0;font-size:13.5px;">Every staff account needs two-step sign-in. Set it up below to continue. It takes about a minute.</p>
      </div>
      <?php elseif ($twoFaOn && $recoveryLeft <= 3): ?>
      <div class="card card-pad" role="alert" style="border:1px solid var(--gold);">
        <p style="margin:0;font-size:13.5px;"><i class="fa-solid fa-triangle-exclamation" style="color:var(--gold);"></i> Only <strong><?= $recoveryLeft ?></strong> recovery code<?= $recoveryLeft === 1 ? '' : 's' ?> left. Make new ones below.</p>
      </div>
      <?php endif; ?>
      <div class="card card-pad">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">
          <div class="avatar" style="width:52px;height:52px;font-size:18px;"><?= h(strtoupper(substr($admin['admin_name'], 0, 1))) ?></div>
          <div>
            <div style="font-weight:700;font-size:16px;color:var(--ink);"><?= h($admin['admin_name']) ?></div>
            <div style="font-size:12.5px;color:var(--text-muted);">@<?= h($admin['username']) ?> · <?= adminIsOwner($admin) ? 'Owner' : 'Staff' ?></div>
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
            <p class="help-text">An owner can change usernames on the Staff accounts page.</p>
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
            <input type="password" name="current_password" required autocomplete="current-password">
          </div>
          <div class="field">
            <label>New password</label>
            <input type="password" name="new_password" required minlength="<?= STAFF_PASSWORD_MIN ?>" autocomplete="new-password">
            <p class="help-text">At least <?= STAFF_PASSWORD_MIN ?> characters. A short sentence is easy to remember and hard to guess.</p>
          </div>
          <div class="field">
            <label>Confirm new password</label>
            <input type="password" name="confirm_password" required minlength="<?= STAFF_PASSWORD_MIN ?>" autocomplete="new-password">
          </div>
          <p class="error-text" id="passwordError" style="display:none;"></p>
          <button type="submit" class="btn btn-primary">Update password</button>
        </form>
      </div>

      <div class="card card-pad" id="twoFaCard">
        <h3 style="font-family:var(--font-display);font-size:16px;margin:0 0 6px;color:var(--ink);"><i class="fa-solid fa-shield-halved"></i> Two-step sign-in</h3>
        <?php if (!$twoFaPossible): ?>
          <p class="help-text" style="margin:0;">Not available yet: SECURITY_KEY must be added to the server's config.php.</p>
        <?php elseif ($twoFaOn): ?>
          <p style="margin:0 0 12px;font-size:13.5px;"><i class="fa-solid fa-circle-check" style="color:var(--accepted);"></i> <strong>On.</strong> Signing in asks for a code from your authenticator app. <?= $recoveryLeft ?> unused recovery code<?= $recoveryLeft === 1 ? '' : 's' ?>.</p>
          <form id="form2faRecovery" class="field" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <div style="flex:1;min-width:140px;"><label>Current code</label><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required placeholder="123 456"></div>
            <button type="submit" class="btn btn-ghost"><i class="fa-solid fa-rotate"></i> New recovery codes</button>
          </form>
          <?php if (!adminRequire2fa()): ?>
          <details style="margin-top:6px;"><summary style="cursor:pointer;font-size:13px;color:var(--text-muted);">Turn off two-step sign-in</summary>
            <form id="form2faDisable" style="margin-top:10px;">
              <div class="field"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
              <div class="field"><label>Current code</label><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required></div>
              <button type="submit" class="btn btn-ghost">Turn off</button>
            </form>
          </details>
          <?php endif; ?>
        <?php else: ?>
          <p style="margin:0 0 12px;font-size:13.5px;">Protects your account even if someone learns your password: signing in also needs a 6-digit code from an app on your phone (Google Authenticator, Microsoft Authenticator, Authy or 1Password).</p>
          <form id="form2faStart">
            <div class="field"><label>Your password</label><input type="password" name="password" required autocomplete="current-password"></div>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-qrcode"></i> Set up two-step sign-in</button>
          </form>
          <div id="twoFaSetup" hidden style="margin-top:14px;">
            <p style="font-size:13.5px;margin:0 0 10px;"><strong>1.</strong> In your authenticator app, add an account and scan this code:</p>
            <div id="twoFaQr" style="background:#fff;padding:12px;border-radius:8px;display:inline-block;"></div>
            <p class="help-text" style="margin:8px 0 14px;">Can't scan? Enter this key instead: <code id="twoFaSecret" style="user-select:all;"></code></p>
            <form id="form2faConfirm">
              <div class="field"><label><strong>2.</strong> Type the 6-digit code the app shows</label><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required placeholder="123 456"></div>
              <button type="submit" class="btn btn-primary">Turn on</button>
            </form>
          </div>
        <?php endif; ?>
        <div id="twoFaCodes" hidden style="margin-top:14px;">
          <p style="font-size:13.5px;margin:0 0 8px;"><strong>Your recovery codes.</strong> Save them somewhere safe (print them or keep them in a password manager). Each works once if you lose your phone. They won't be shown again.</p>
          <pre id="twoFaCodeList" style="font-size:15px;line-height:1.7;background:var(--surface-alt);padding:12px;border-radius:8px;user-select:all;"></pre>
          <button type="button" class="btn btn-ghost" id="twoFaDone">I've saved them</button>
        </div>
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
          <?= $otherDevices ? 'This account is also remembered on <strong>' . $otherDevices . '</strong> other device' . ($otherDevices === 1 ? '' : 's') . '.' : 'Not signed in on any other device.' ?>
        </p>
        <button type="button" class="btn btn-ghost" id="btnForgetDevices"><i class="fa-solid fa-right-from-bracket"></i> Sign out of all other devices</button>
        <p class="help-text" style="margin-top:10px;">Signs this account out on every other computer and phone, including ones that are open right now. Use it if a device is lost. Changing your password does the same.</p>
      </div>
    </div>
  </main>
</div>

<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/admin.js?v=<?= assetVersion('js/admin.js') ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
  const post = async (fields) => {
    const fd = new FormData();
    Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch('api/staff_2fa.php', { method: 'POST', body: fd });
    return res.json();
  };
  const showCodes = (codes) => {
    document.getElementById('twoFaCodeList').textContent = codes.join('\n');
    document.getElementById('twoFaCodes').hidden = false;
  };
  const done = document.getElementById('twoFaDone');
  if (done) done.addEventListener('click', () => location.reload());

  const start = document.getElementById('form2faStart');
  if (start) start.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const data = await post({ action: 'start', password: start.password.value });
      if (!data.success) { showToast(data.message || 'Could not start setup.', true); return; }
      start.hidden = true;
      document.getElementById('twoFaSetup').hidden = false;
      document.getElementById('twoFaSecret').textContent = data.secret;
      const qr = document.getElementById('twoFaQr');
      qr.textContent = '';
      if (window.QRCode) new QRCode(qr, { text: data.uri, width: 184, height: 184, correctLevel: QRCode.CorrectLevel.M });
      else qr.textContent = 'QR code could not load: enter the key below instead.';
      document.querySelector('#form2faConfirm [name=code]').focus();
    } catch (err) { showToast('Network error. Please try again.', true); }
  });

  const confirmForm = document.getElementById('form2faConfirm');
  if (confirmForm) confirmForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const data = await post({ action: 'confirm', code: confirmForm.code.value });
      if (!data.success) { showToast(data.message || 'That code did not match.', true); return; }
      document.getElementById('twoFaSetup').hidden = true;
      showToast(data.message);
      showCodes(data.recovery_codes);
    } catch (err) { showToast('Network error. Please try again.', true); }
  });

  const recovery = document.getElementById('form2faRecovery');
  if (recovery) recovery.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const data = await post({ action: 'recovery', code: recovery.code.value });
      showToast(data.message || 'Done.', !data.success);
      if (data.success) { recovery.hidden = true; showCodes(data.recovery_codes); }
    } catch (err) { showToast('Network error. Please try again.', true); }
  });

  const disable = document.getElementById('form2faDisable');
  if (disable) disable.addEventListener('submit', async (e) => {
    e.preventDefault();
    const ok = await showConfirm('Turn off two-step sign-in? Anyone who learns your password could then sign in as you.', { title: 'Turn off two-step sign-in', confirmLabel: 'Turn off' });
    if (!ok) return;
    try {
      const data = await post({ action: 'disable', password: disable.password.value, code: disable.code.value });
      showToast(data.message || 'Done.', !data.success);
      if (data.success) setTimeout(() => location.reload(), 800);
    } catch (err) { showToast('Network error. Please try again.', true); }
  });
})();
</script>
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
      <?php if ($mustChangePw): ?>setTimeout(() => { location.href = 'frontdesk.php'; }, 900); return;<?php endif; ?>
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
  const ok = await showConfirm('Sign this account out on every other computer and phone? They will need the password (and code) to sign back in. This device stays signed in.', { title: 'Sign out other devices', confirmLabel: 'Sign them out' });
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
