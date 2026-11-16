-- Separate staff read markers for the two staff inboxes.
-- MariaDB (live). Safe to run twice. Run BEFORE deploying the code that uses it.
--
-- clients.staff_messages_seen_id  = Processing Inbox (portal messages)
-- clients.staff_social_seen_id    = Front Desk chats (WhatsApp, Messenger,
--                                   Instagram, TikTok)
-- Reading or replying in one never marks the other's messages read.

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS staff_social_seen_id INT NOT NULL DEFAULT 0 AFTER staff_messages_seen_id;

-- Start from where staff already are, so nothing old turns unread.
UPDATE clients SET staff_social_seen_id = staff_messages_seen_id WHERE staff_social_seen_id = 0;
