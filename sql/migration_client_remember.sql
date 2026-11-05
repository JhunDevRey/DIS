-- migration_client_remember.sql
-- Run once against the live database BEFORE deploying "Keep me signed in
-- on this device" for the client portal. Purely additive; safe to re-run.
--
-- One row per remembered device. The browser holds "selector:validator";
-- only a SHA-256 hash of the validator is stored here, so a copy of this
-- table can't be used to sign in. Each use keeps the selector but swaps
-- in a new validator (the previous one stays valid for 60 seconds, for
-- tabs that load at the same moment); an old validator after that means
-- the cookie was copied. Signing out / changing the password deletes rows.
CREATE TABLE IF NOT EXISTS client_remember_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  prev_validator_hash CHAR(64) DEFAULT NULL,
  rotated_at DATETIME DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_remember_selector (selector),
  KEY idx_remember_client (client_id),
  CONSTRAINT fk_remember_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
