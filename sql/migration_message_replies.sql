-- sql/migration_message_replies.sql
-- Messenger-style replies: a message can quote an earlier message in the
-- same conversation. NULL = an ordinary message. The server only accepts a
-- reply_to_id from the same client's thread (see createMessage()).
-- Run once before deploying the code that uses it. Safe to re-run.

ALTER TABLE messages
  ADD COLUMN IF NOT EXISTS reply_to_id INT NULL DEFAULT NULL AFTER body,
  ADD KEY IF NOT EXISTS idx_messages_reply (reply_to_id);
