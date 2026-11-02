-- Run this once against the live database before deploying the code
-- changes. Adds the two columns the "unread" nav badges depend on to
-- track when each client last viewed My Documents / Application Review.
ALTER TABLE clients
  ADD COLUMN documents_seen_at TIMESTAMP NULL DEFAULT NULL AFTER dropbox_added_at,
  ADD COLUMN review_seen_at TIMESTAMP NULL DEFAULT NULL AFTER documents_seen_at;
