-- migration_whatsapp.sql
-- Run once against the live database BEFORE deploying the WhatsApp code.
-- Purely additive (new columns + new tables, nothing changed or removed).
-- Safe to run more than once (MariaDB IF NOT EXISTS everywhere).

-- Messages keep one thread per client; WhatsApp just becomes a second
-- channel inside it.
--   channel        'portal' (typed in DIS) or 'whatsapp' (sent/received on WhatsApp)
--   wa_message_id  WhatsApp's own id — unique, so Meta's webhook retries
--                  and the history import can never insert a message twice
--   wa_status      sent / delivered / read / failed for outgoing WhatsApp
--                  messages; 'notice_sent' on a portal message whose
--                  WhatsApp notice template went out instead (24h rule)
--   wa_error       Meta's error text when a send failed (staff-only)
--   is_history     1 = imported from the phone's past chats: shown in the
--                  thread but never counted as unread on either side
ALTER TABLE messages
  ADD COLUMN IF NOT EXISTS channel ENUM('portal','whatsapp') NOT NULL DEFAULT 'portal' AFTER sender_name,
  ADD COLUMN IF NOT EXISTS wa_message_id VARCHAR(128) DEFAULT NULL AFTER channel,
  ADD COLUMN IF NOT EXISTS wa_status VARCHAR(20) DEFAULT NULL AFTER wa_message_id,
  ADD COLUMN IF NOT EXISTS wa_error VARCHAR(255) DEFAULT NULL AFTER wa_status,
  ADD COLUMN IF NOT EXISTS is_history TINYINT(1) NOT NULL DEFAULT 0 AFTER wa_error,
  ADD UNIQUE KEY IF NOT EXISTS uq_messages_wa (wa_message_id),
  ADD KEY IF NOT EXISTS idx_messages_client_time (client_id, created_at);

-- The client's WhatsApp number in international digits only (e.g.
-- 14312933949) — set when staff link a WhatsApp chat to the client, so
-- matching never has to guess from the free-text phone field again.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(20) DEFAULT NULL AFTER phone,
  ADD KEY IF NOT EXISTS idx_clients_whatsapp (whatsapp_number);

-- WhatsApp messages from numbers that don't match exactly one client.
-- They wait here (never shown to any client) until staff link the number
-- to a client in the Inbox — then they move into that client's thread.
CREATE TABLE IF NOT EXISTS whatsapp_unmatched (
  id INT AUTO_INCREMENT PRIMARY KEY,
  wa_from VARCHAR(20) NOT NULL,
  profile_name VARCHAR(150) DEFAULT NULL,
  direction ENUM('in','out') NOT NULL DEFAULT 'in',
  body TEXT NOT NULL,
  files_json TEXT DEFAULT NULL,
  wa_message_id VARCHAR(128) NOT NULL,
  is_history TINYINT(1) NOT NULL DEFAULT 0,
  ignored TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wa_unmatched_msg (wa_message_id),
  KEY idx_wa_unmatched_from (wa_from, ignored)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Raw webhook payloads, kept 30 days, for troubleshooting what Meta sent.
CREATE TABLE IF NOT EXISTS whatsapp_webhook_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payload MEDIUMTEXT NOT NULL,
  error VARCHAR(500) DEFAULT NULL,
  KEY idx_wa_log_time (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
