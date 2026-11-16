-- Client passwords: forced change of staff-set passwords + self-service reset links.
-- MariaDB (live). Safe to run twice. Run BEFORE deploying the code that uses it.

-- 1 = the current password was set by staff (or is a temporary one) and the
-- client must choose their own before using the portal.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

-- One row per "reset your password" link. The link carries selector:validator;
-- only a SHA-256 of the validator is stored, so a database copy can't be used
-- to reset anyone's password. Links work once and expire (1 hour; 7 days for
-- links staff send on purpose).
CREATE TABLE IF NOT EXISTS client_password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  requested_by VARCHAR(10) NOT NULL DEFAULT 'client',
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cpr_selector (selector),
  KEY idx_cpr_client (client_id),
  CONSTRAINT fk_cpr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
