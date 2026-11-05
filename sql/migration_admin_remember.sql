-- migration_admin_remember.sql
-- Run once against the live database BEFORE deploying "Keep me signed in
-- on this device" for the staff admin. Purely additive; safe to re-run.
-- Same design as client_remember_tokens (see that migration and
-- includes/remember_me.php): selector kept, validator rotated on use and
-- stored only as a SHA-256 hash, previous validator valid for 60 seconds.
CREATE TABLE IF NOT EXISTS admin_remember_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  prev_validator_hash CHAR(64) DEFAULT NULL,
  rotated_at DATETIME DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_admin_remember_selector (selector),
  KEY idx_admin_remember_admin (admin_id),
  CONSTRAINT fk_admin_remember_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
