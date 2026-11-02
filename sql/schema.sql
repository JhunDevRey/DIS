-- Duque Immigration | Document Portal Schema
-- Run this once against your MySQL database (Laragon or InfinityFree)

CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS processors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  plain_password VARCHAR(255) DEFAULT NULL,
  case_type VARCHAR(120) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  reference_number VARCHAR(100) DEFAULT NULL,
  processor_id INT DEFAULT NULL,
  dropbox_folder_path VARCHAR(500) DEFAULT NULL,
  dropbox_folder_link VARCHAR(500) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  cc_emails TEXT DEFAULT NULL,
  designated_person_name VARCHAR(150) DEFAULT NULL,
  designated_person_phone VARCHAR(50) DEFAULT NULL,
  portal_created_at DATE DEFAULT NULL,
  dropbox_added_at DATE DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_client_processor FOREIGN KEY (processor_id) REFERENCES processors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  doc_name VARCHAR(150) NOT NULL,
  instructions TEXT DEFAULT NULL,
  status ENUM('pending','received','needs_revision','accepted') NOT NULL DEFAULT 'pending',
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  due_date DATE DEFAULT NULL,
  is_review TINYINT(1) NOT NULL DEFAULT 0,
  review_status VARCHAR(20) DEFAULT NULL,
  comments_resolved TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  file_size INT DEFAULT 0,
  uploaded_by ENUM('admin','client') NOT NULL DEFAULT 'client',
  uploaded_by_name VARCHAR(150) DEFAULT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_df_request FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  author_type ENUM('admin','client') NOT NULL,
  author_name VARCHAR(150) DEFAULT NULL,
  comment_text TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_c_request FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS status_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  old_status VARCHAR(30) DEFAULT NULL,
  new_status VARCHAR(30) NOT NULL,
  changed_by VARCHAR(150) DEFAULT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sl_request FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If you already ran this schema before "uploaded_by" existed, migrate with:
--   ALTER TABLE document_files ADD COLUMN uploaded_by ENUM('admin','client') NOT NULL DEFAULT 'client' AFTER file_size;
--   ALTER TABLE document_files ADD COLUMN uploaded_by_name VARCHAR(150) DEFAULT NULL AFTER uploaded_by;
--   ALTER TABLE clients ADD COLUMN plain_password VARCHAR(255) DEFAULT NULL AFTER password_hash;
--   ALTER TABLE clients ADD COLUMN cc_emails TEXT DEFAULT NULL AFTER notes;
--   ALTER TABLE clients ADD COLUMN designated_person_name VARCHAR(150) DEFAULT NULL AFTER cc_emails;
--   ALTER TABLE clients ADD COLUMN designated_person_phone VARCHAR(50) DEFAULT NULL AFTER designated_person_name;
--   ALTER TABLE clients ADD COLUMN portal_created_at DATE DEFAULT NULL AFTER designated_person_phone;
--   ALTER TABLE clients ADD COLUMN dropbox_added_at DATE DEFAULT NULL AFTER portal_created_at;
--
-- New for the daily digest notification system:
--   CREATE TABLE IF NOT EXISTS client_notification_queue (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     client_id INT NOT NULL,
--     summary TEXT NOT NULL,
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--     sent_at TIMESTAMP NULL DEFAULT NULL,
--     CONSTRAINT fk_cnq_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--   CREATE TABLE IF NOT EXISTS processor_notification_queue (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     processor_id INT NOT NULL,
--     client_id INT DEFAULT NULL,
--     summary TEXT NOT NULL,
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--     sent_at TIMESTAMP NULL DEFAULT NULL,
--     CONSTRAINT fk_pnq_processor FOREIGN KEY (processor_id) REFERENCES processors(id) ON DELETE CASCADE
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--
-- If processor_notification_queue already exists from before (without
-- client_id — needed so processor digests can be split one-email-per-client):
--   ALTER TABLE processor_notification_queue ADD COLUMN client_id INT DEFAULT NULL AFTER processor_id;
--   ALTER TABLE processor_notification_queue ADD COLUMN event_type VARCHAR(20) DEFAULT NULL AFTER client_id;
--
-- New for real-time, threaded processor notifications (replaces the
-- queue-based daily digest for processors — the client-facing digest is
-- unaffected and still uses its own separate queue table):
--   CREATE TABLE IF NOT EXISTS processor_client_threads (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     processor_id INT NOT NULL,
--     client_id INT NOT NULL,
--     message_id VARCHAR(255) NOT NULL,
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--     UNIQUE KEY uniq_processor_client (processor_id, client_id),
--     CONSTRAINT fk_pct_processor FOREIGN KEY (processor_id) REFERENCES processors(id) ON DELETE CASCADE,
--     CONSTRAINT fk_pct_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--
-- New for processor assignment + Dropbox integration:
--   CREATE TABLE IF NOT EXISTS processors (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     full_name VARCHAR(150) NOT NULL,
--     email VARCHAR(150) NOT NULL,
--     is_active TINYINT(1) NOT NULL DEFAULT 1,
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--   ALTER TABLE clients ADD COLUMN reference_number VARCHAR(100) DEFAULT NULL AFTER phone;
--   ALTER TABLE clients ADD COLUMN processor_id INT DEFAULT NULL AFTER reference_number;
--   ALTER TABLE clients ADD COLUMN dropbox_folder_path VARCHAR(500) DEFAULT NULL AFTER processor_id;
--   ALTER TABLE clients ADD COLUMN dropbox_folder_link VARCHAR(500) DEFAULT NULL AFTER dropbox_folder_path;
--   ALTER TABLE clients ADD CONSTRAINT fk_client_processor FOREIGN KEY (processor_id) REFERENCES processors(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS client_notification_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  summary TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_cnq_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS processor_notification_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  processor_id INT NOT NULL,
  client_id INT DEFAULT NULL,
  event_type VARCHAR(20) DEFAULT NULL,
  summary TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_pnq_processor FOREIGN KEY (processor_id) REFERENCES processors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- No longer used by any code as of the real-time processor notification
-- change — processors now get an immediately-sent, threaded email per
-- event instead of a daily batched digest built from this queue. Left
-- in the schema rather than dropped, since dropping a table is a more
-- destructive step than this change warranted on its own; safe to drop
-- manually later if you're sure you don't want it kept around.

-- Tracks the root Message-ID of each processor+client email thread, so
-- every subsequent notification for that same pairing can be sent as a
-- real threaded reply (In-Reply-To / References headers) instead of a
-- new standalone email — this is what makes real email clients group
-- them into one ongoing conversation per client, not just Gmail's
-- best-effort subject-matching heuristic.
CREATE TABLE IF NOT EXISTS processor_client_threads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  processor_id INT NOT NULL,
  client_id INT NOT NULL,
  message_id VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_processor_client (processor_id, client_id),
  CONSTRAINT fk_pct_processor FOREIGN KEY (processor_id) REFERENCES processors(id) ON DELETE CASCADE,
  CONSTRAINT fk_pct_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- New for "Application Review" fields — a staff-uploaded form (e.g. a
-- filled-in IMM 5257) that the client reviews for accuracy instead of
-- uploading their own document. If you already ran this schema before
-- these columns existed, migrate with:
--   ALTER TABLE document_requests ADD COLUMN is_review TINYINT(1) NOT NULL DEFAULT 0 AFTER due_date;
--   ALTER TABLE document_requests ADD COLUMN review_status VARCHAR(20) DEFAULT NULL AFTER is_review;
-- review_status is one of: 'awaiting_client', 'confirmed', 'changes_requested' (or NULL for non-review fields).

-- New for the "Mark as resolved" comments button — tracks whether a
-- field's comment thread is considered settled. Automatically reset to 0
-- the moment anyone (staff or client) posts a new comment, so it stays
-- accurate without needing to be managed by hand. Migrate an existing
-- database with:
--   ALTER TABLE document_requests ADD COLUMN comments_resolved TINYINT(1) NOT NULL DEFAULT 0 AFTER review_status;

-- Seed one admin account: username "admin", password "ChangeMe123!"
-- CHANGE THIS PASSWORD after first login. Generate a new hash any time with:
--   php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT);"
INSERT INTO admin_users (username, password_hash, full_name) VALUES
('admin', '$2b$10$tb0HOhPe6F.4/Y1drzyOr.QoIDzD.H4NNxpCSP1kET.vkAOCCMsce', 'Jojo Duque')
ON DUPLICATE KEY UPDATE username = username;
