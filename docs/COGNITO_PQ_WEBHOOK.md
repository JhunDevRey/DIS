# PQ Forms: Cognito webhook + local cache

The Front Desk **PQ Forms** card reads only the MySQL table `pq_entries`.
It never calls the Cognito API on page load, Refresh or auto-reload.

| How an entry gets into `pq_entries` | Cognito API calls |
|---|---|
| Client submits / edits / deletes → Cognito "Post JSON Data" → `api/cognito_pq_webhook.php` | 0 |
| PQ BOT creates a pre-filled entry → Make posts `{id, number}` to the webhook → server fetches that one entry | 1 per PQ sent |
| One-time backfill: `php cron/pq_backfill.php` | ~100 once |
| Resync (owner button on the card, or `php cron/pq_backfill.php --resync`), max once an hour | ~4 per form + 1 per Waiting entry |
| "Download PDF" uses the stored Document link; fetches the entry only if that link fails | 0 (sometimes 1) |

## 1. Setup (server)

1. Back up the DB, then run `sql/migration_pq_entries.sql` (safe to run twice).
2. Add a secret to the server's `config.php`:
   `define('COGNITO_WEBHOOK_TOKEN', '<48 hex chars>');`
   (generate: `php -r 'echo bin2hex(random_bytes(24)), "\n";'`)
3. Deploy the code, then run the backfill once over SSH:
   `php cron/pq_backfill.php` → prints the number of API calls made.

## 2. Cognito: one URL per form, three events

For each PQ form (General, Spousal, Child, Minor, Employer, Lmia, Pgp, Renewal):
**Form → Settings → Post JSON Data to a Website** → enable, then fill:

| Field | URL |
|---|---|
| Submit Entry Endpoint | `https://admin.duqueimmigration.ca/api/cognito_pq_webhook.php?form=General&event=submit&token=TOKEN` |
| Update Entry Endpoint | `…?form=General&event=update&token=TOKEN` |
| Delete Entry Endpoint | `…?form=General&event=delete&token=TOKEN` |

Replace `General` with the form's internal name (as in `COGNITO_PQ_FORM`) and
`TOKEN` with `COGNITO_WEBHOOK_TOKEN`. Cognito can't send custom headers, so
the token goes in the query string; the endpoint also accepts it as an
`X-DIS-Webhook-Token` header.

## 3. Make: "PQ BOT - Sender" (scenario 2929471)

Cognito sends nothing for entries created through its API, so add after
**Cognito Forms → Create Entry** (module 59), inside the same route:

- **HTTP → Make a request**
  - URL: `https://admin.duqueimmigration.ca/api/cognito_pq_webhook.php?form={{74.whichPq}}&event=create`
  - Method: POST, header `X-DIS-Webhook-Token: TOKEN`
  - Body (raw, JSON): `{"id": "{{59.id}}", "number": "{{59.Entry.Number}}"}`
  - Error handler: **Ignore**, so sending a PQ never fails because of the portal.

## 4. Replies and log

200 stored / nothing to do · 400 bad input (unknown form, no entry number,
not JSON) · 401 bad token · 500 our error or Cognito busy (sender retries).
One line per call, no client details, in
`UPLOAD_DIR/logs/cognito_pq_webhook.log` (rotates at 1 MB).

## 5. Test with one form first

1. Set up only **General** in Cognito (step 2).
2. Submit a test entry on General → the log shows `200 submit General #N inserted`,
   and the entry appears in the card's Submitted tab within a minute.
3. Edit it in Cognito → `200 update … updated`. Delete it → `200 delete … soft-deleted`.
4. Send a test PQ through the bot → `200 create General #N inserted (fetched, 1 API call)`.
5. Then repeat step 2 for the other seven forms.

## Rollback

`define('COGNITO_PQ_LIVE_API', true);` in config.php brings back the old
live-API card (costs API requests again). Nothing else needs changing.

## Safe to remove once this has run well for a couple of weeks

In `includes/cognito.php`: `cognitoPqEntries()`, `cognitoSyncForm()`,
`cognitoRecheckForm()`, `cognitoFetchEntries()`, the constants
`COGNITO_CACHE_SECONDS`, `COGNITO_RECHECK_*`, `COGNITO_FIND_MAX_COST`,
`COGNITO_REQ_PER_MIN`, the `CognitoLater` class, the `COGNITO_PQ_LIVE_API`
branch in `api/frontdesk_pq.php`, and the state file
`UPLOAD_DIR/cache/cognito_pq.json` (pq_backfill only reads it for the
newest entry numbers). Keep: `cognitoEnabled`, `cognitoForms`,
`cognitoFormLabel`, `cognitoEntriesUrl`, `cognitoBase`, `cognitoCurl`,
`cognitoBudget`, `cognitoGetMany`, `cognitoFindMax`, the entry parsing
helpers and the PDF functions — the new code uses them.
