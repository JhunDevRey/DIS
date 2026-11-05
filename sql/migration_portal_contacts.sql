-- migration_portal_contacts.sql
-- Run once against the live database BEFORE deploying portal access for
-- CC contacts (the client's "Additional emails"). Purely additive.
--
-- Who has access is NOT stored here: it's always the client's own
-- clients.cc_emails list. Remove an address there and that person loses
-- access immediately. This table only holds each contact's own login —
-- one per email address, so an agent or parent listed on several clients
-- has one password and picks which client to open after signing in.
CREATE TABLE IF NOT EXISTS portal_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(150) DEFAULT NULL,
  password_hash VARCHAR(255) DEFAULT NULL,      -- NULL until they set one
  setup_token_hash CHAR(64) DEFAULT NULL,       -- SHA-256 of the emailed one-time link
  setup_expires_at DATETIME DEFAULT NULL,
  last_client_id INT DEFAULT NULL,              -- which client they opened last
  last_login_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_portal_contacts_email (email),
  KEY idx_portal_contacts_setup (setup_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Keep me signed in" for contacts (same design as client_remember_tokens).
CREATE TABLE IF NOT EXISTS contact_remember_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  prev_validator_hash CHAR(64) DEFAULT NULL,
  rotated_at DATETIME DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_contact_remember_selector (selector),
  KEY idx_contact_remember_contact (contact_id),
  CONSTRAINT fk_contact_remember_contact FOREIGN KEY (contact_id) REFERENCES portal_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
