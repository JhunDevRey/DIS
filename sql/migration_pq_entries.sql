-- Local copy of Cognito Forms PQ entries for the Front Desk "PQ Forms" card.
-- MariaDB (live). Safe to run twice. Run BEFORE deploying the code that uses it.
--
-- Filled by:
--   api/cognito_pq_webhook.php  - Cognito "Post JSON Data" (submit / update /
--                                 delete) + Make "PQ BOT - Sender" (create)
--   cron/pq_backfill.php        - one-time backfill / manual resync (uses the API)
-- The Front Desk card reads ONLY this table; it never calls Cognito.
--
-- form_id      = the form's internal name as in COGNITO_PQ_FORM (e.g. 'General')
-- entry_id     = the entry number as text (unique per form; Cognito's own
--                entry Id is kept inside raw_json)
-- status       = Cognito's status text ('Incomplete', 'Submitted', ...)
-- stage        = 'waiting' (not submitted yet) or 'submitted'
-- follow_up    = 1 when waiting 3+ days (refreshed whenever the card loads)
-- emails       = JSON array of every email found in the entry (client matching)
-- All dates are UTC.

CREATE TABLE IF NOT EXISTS pq_entries (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id         VARCHAR(64)  NOT NULL,
  form_name       VARCHAR(100) NOT NULL DEFAULT '',
  entry_id        VARCHAR(64)  NOT NULL,
  entry_number    INT UNSIGNED NOT NULL,
  client_name     VARCHAR(255) NOT NULL DEFAULT '',
  email           VARCHAR(255) NULL,
  emails          TEXT NULL,
  status          VARCHAR(40)  NOT NULL DEFAULT 'Incomplete',
  stage           ENUM('waiting','submitted') NOT NULL DEFAULT 'waiting',
  follow_up       TINYINT(1)   NOT NULL DEFAULT 0,
  admin_url       VARCHAR(500) NULL,
  date_created    DATETIME     NULL,
  date_submitted  DATETIME     NULL,
  date_updated    DATETIME     NULL,
  raw_json        MEDIUMTEXT   NULL,
  source          VARCHAR(20)  NOT NULL DEFAULT 'webhook',
  received_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  synced_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pq_form_entry (form_id, entry_id),
  KEY idx_pq_stage_created (stage, date_created),
  KEY idx_pq_form_number (form_id, entry_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
