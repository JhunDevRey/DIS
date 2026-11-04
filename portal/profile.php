<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireClient();
$activeNav = 'profile';

$db = getDb();
$stmt = $db->prepare('SELECT id, full_name, case_type, email, phone, cc_emails, designated_person_name, designated_person_phone FROM clients WHERE id = ?');
$stmt->execute([$_SESSION['client_id']]);
$client = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Profile · Duque Immigration</title>
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
        <p>Keep your contact details up to date and manage your password.</p>
      </div>
    </div>

    <div style="max-width:480px;">
      <div class="card card-pad">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">
          <div class="avatar" style="width:52px;height:52px;font-size:18px;"><?= h(strtoupper(substr($client['full_name'], 0, 1))) ?></div>
          <div>
            <div style="font-weight:700;font-size:16px;color:var(--ink);"><?= h($client['full_name']) ?></div>
            <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px;"><?= $client['case_type'] ? classTagHtml($client['case_type']) : 'Client' ?></div>
          </div>
        </div>

        <form id="formProfile">
          <div class="field">
            <label>Full name</label>
            <input type="text" value="<?= h($client['full_name']) ?>" disabled style="background:var(--surface-alt);color:var(--text-muted);">
            <p class="help-text">Contact your case officer to correct your name.</p>
          </div>
          <div class="field">
            <label>Email</label>
            <input type="text" value="<?= h($client['email']) ?>" disabled style="background:var(--surface-alt);color:var(--text-muted);">
          </div>
          <div class="field">
            <label>Phone</label>
            <input type="text" name="phone" value="<?= h($client['phone']) ?>" placeholder="e.g. 09123456789">
          </div>
          <div class="field">
            <label>Additional emails <span style="font-weight:400;text-transform:none;">(CC)</span></label>
            <input type="text" name="cc_emails" data-email-chips value="<?= h($client['cc_emails']) ?>" placeholder="e.g. spouse@example.com">
            <p class="help-text">Type or paste an email and press Enter — you can add as many as you need. Anyone listed here will be CC'd on every email we send you — useful for a spouse, agent, or anyone else who should stay in the loop.</p>
          </div>
          <div class="field-row">
            <div class="field">
              <label>Designated person's name <span style="font-weight:400;text-transform:none;">(optional)</span></label>
              <input type="text" name="designated_person_name" value="<?= h($client['designated_person_name']) ?>" placeholder="e.g. Maria Santos">
            </div>
            <div class="field">
              <label>Their phone number</label>
              <input type="text" name="designated_person_phone" value="<?= h($client['designated_person_phone']) ?>" placeholder="e.g. 09123456789">
            </div>
          </div>
          <p class="help-text" style="margin-top:-8px;">A designated person is someone authorized to receive information about your case on your behalf.</p>
          <p class="error-text" id="profileError" style="display:none;"></p>
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
    </div>
  </main>
</div>

<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/portal.js?v=<?= assetVersion('js/portal.js') ?>"></script>
<script>
document.getElementById('formProfile').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'update_contact_info');
  const errEl = document.getElementById('profileError');
  errEl.style.display = 'none';
  try {
    const res = await fetch('api/update_client_profile.php', { method: 'POST', body: fd });
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
    const res = await fetch('api/update_client_profile.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      showToast('Password updated.');
      form.reset();
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
