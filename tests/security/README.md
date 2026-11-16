# Security tests (local only)

End-to-end checks for the hardening work: headers, CSRF, sign-in throttles,
uploads and zips, client password resets, staff accounts, two-step sign-in
and the activity log. They drive the real pages over HTTP against a
**throwaway local copy**. Never point them at the live site or database.

## The rig

`DIS_TEST_DIR` is a folder holding:

- `app/`: a copy of this repo made by `sync.sh` (never copies `config.php`)
  plus its own `app/config.php` with `DB_NAME` `sectest` on `127.0.0.1:3399`,
  `UPLOAD_DIR` `<rig>/uploads`, `ADMIN_APP_URL` `http://127.0.0.1:3401`,
  `PORTAL_APP_URL` `http://127.0.0.1:3402` and a dummy `SECURITY_KEY`.
- `me.sql`: the `CREATE TABLE message_edits` statement from
  `sql/migration_message_edits.sql`.
- `mig_b.sql` and `mig_c.sql`: `migration_client_passwords.sql` and
  `migration_staff_security.sql` with `ADD COLUMN IF NOT EXISTS` changed to
  `ADD COLUMN` (MySQL 8 lacks it; live MariaDB has it).
- `router.php` from this folder.

A MySQL 8 on port 3399 must have a `cltest` database (schema plus 2 fake
clients). `resetdb.sh` clones it into `sectest` with local-only passwords
(staff `processing` / `StaffTest#2026`, client `maria.santos@example.test` /
`ClientTest#2026`).

## Running

```sh
export DIS_TEST_DIR=/path/to/rig PHP_BIN=/path/to/php.exe
sh tests/security/sync.sh
# two servers, from inside the copies (document roots like production):
(cd "$DIS_TEST_DIR/app/admin"  && $PHP_BIN -d extension=zip -S 127.0.0.1:3401 "$DIS_TEST_DIR/router.php") &
(cd "$DIS_TEST_DIR/app/portal" && $PHP_BIN -d extension=zip -S 127.0.0.1:3402 "$DIS_TEST_DIR/router.php") &
for t in a b c; do
  sh tests/security/resetdb.sh "$DIS_TEST_DIR/mig_b.sql" "$DIS_TEST_DIR/mig_c.sql"
  $PHP_BIN -d memory_limit=2G -d extension=zip tests/security/tests_$t.php
done
node tests/security/ui_pages.mjs    # every page in headless Chrome: CSP / script errors
node tests/security/ui_2fa.mjs      # two-step setup + sign-in in a real browser
```

Result on 2026-11-17: 44 + 41 + 64 checks passed; both browser runs clean.
