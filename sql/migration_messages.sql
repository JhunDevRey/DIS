-- migration_messages.sql
-- Run this once against the live database before deploying the Messages
-- (staff <-> client inbox) code. Purely additive — creates two new tables
-- and two new columns, never changes or removes anything that exists.
-- Safe to run more than once.

-- One row per message. A conversation is simply "every message for one
-- client" — there's exactly one ongoing thread per client, shared by all
-- staff, so there's no separate threads table to keep in sync.
CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  sender_type ENUM('admin','client') NOT NULL,
  sender_name VARCHAR(150) DEFAULT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_messages_client (client_id, id),
  CONSTRAINT fk_msg_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Files attached to a message. Stored in UPLOAD_DIR/messages/ (outside
-- public_html, same as every other upload) and only ever served through
-- the api/*_message_file.php endpoints, which check ownership first.
CREATE TABLE IF NOT EXISTS message_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  file_size INT DEFAULT 0,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mf_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Read tracking, by message id rather than timestamp (two messages sent
-- in the same second can't be confused this way):
--   client_messages_seen_id — highest message id the CLIENT has viewed
--   staff_messages_seen_id  — highest message id STAFF have viewed for
--                             this client (shared across all staff, since
--                             everyone works from the same inbox)
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS client_messages_seen_id INT NOT NULL DEFAULT 0 AFTER review_seen_at,
  ADD COLUMN IF NOT EXISTS staff_messages_seen_id INT NOT NULL DEFAULT 0 AFTER client_messages_seen_id;
