-- Individual staff accounts, two-step sign-in (authenticator app codes),
-- and "sign out everywhere" versions. MariaDB (live). Safe to run twice.
-- Run BEFORE deploying the code that uses it.

ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS email VARCHAR(150) DEFAULT NULL,
  -- 0 = can't sign in; every session and remembered device ends at once.
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1,
  -- Owners manage staff accounts and read the activity log.
  ADD COLUMN IF NOT EXISTS is_owner TINYINT(1) NOT NULL DEFAULT 0,
  -- The processor this person is; their messages are sent under that name.
  ADD COLUMN IF NOT EXISTS processor_id INT DEFAULT NULL,
  -- Authenticator secret, encrypted with SECURITY_KEY from config.php.
  ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  -- Last 30-second code step used, so a code can't be replayed.
  ADD COLUMN IF NOT EXISTS totp_last_step BIGINT DEFAULT NULL,
  -- JSON list of password_hash()es of one-time recovery codes.
  ADD COLUMN IF NOT EXISTS totp_recovery TEXT DEFAULT NULL,
  -- 1 = password set by an owner; must be replaced at next sign-in.
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  -- Bumped on password change / 2FA reset / deactivation: older sessions end.
  ADD COLUMN IF NOT EXISTS auth_version INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_login_at DATETIME DEFAULT NULL;

-- Bumped when staff set a client's password or the client resets it:
-- the client's other open sessions end.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS auth_version INT NOT NULL DEFAULT 0;

-- The existing (shared) account becomes the first owner, if there is none yet.
UPDATE admin_users a
  JOIN (SELECT MIN(id) AS id FROM admin_users) first_account ON first_account.id = a.id
  SET a.is_owner = 1
  WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM admin_users WHERE is_owner = 1) owners);
