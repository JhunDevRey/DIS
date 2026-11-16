-- Erases the readable copy of every client password (clients.plain_password).
-- IRREVERSIBLE: after this nobody, staff included, can look a password up.
-- Clients keep signing in with their current passwords (those are stored as
-- hashes in password_hash and are not touched). Staff use "Send reset link"
-- or "Set password" instead of reading a password.
-- Run only after the code from migration_client_passwords.sql is live, and
-- only with the owner's go-ahead. Take a database backup first.

UPDATE clients SET plain_password = NULL WHERE plain_password IS NOT NULL;

-- Later, once nothing has used the column for a while:
-- ALTER TABLE clients DROP COLUMN plain_password;
