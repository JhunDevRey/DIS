-- migration_message_edits.sql
-- Run once against the live database BEFORE deploying the "edit a sent
-- message" code. Purely additive; safe to run more than once.

-- When a staff message was last edited (NULL = never). Shown as "edited"
-- on both sides of the conversation.
ALTER TABLE messages
  ADD COLUMN IF NOT EXISTS edited_at TIMESTAMP NULL DEFAULT NULL AFTER created_at;

-- Every earlier version of an edited message, so staff can always see
-- what was originally sent (clients only see the current text).
CREATE TABLE IF NOT EXISTS message_edits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  old_body TEXT NOT NULL,
  edited_by VARCHAR(150) DEFAULT NULL,
  edited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_message_edits_msg (message_id),
  CONSTRAINT fk_me_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
