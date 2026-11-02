-- migration_comment_attachments.sql
-- Run this once against your EXISTING database to add support for
-- staff/processor attaching a sample file directly to a comment.
--
-- Safe to run even if you're not sure whether it's been applied before —
-- it checks first and does nothing if the column already exists.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_files'
    AND COLUMN_NAME = 'comment_id'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE document_files ADD COLUMN comment_id INT DEFAULT NULL AFTER request_id',
  'SELECT "comment_id already exists, skipping." AS notice'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_df_comment'
);

SET @sql2 := IF(@fk_exists = 0,
  'ALTER TABLE document_files ADD CONSTRAINT fk_df_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE',
  'SELECT "fk_df_comment already exists, skipping." AS notice'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
