# WhatsApp → DIS Messages: setup checklist (360dialog)

This links the office WhatsApp Business number to DIS in **Coexistence**
mode, through **360dialog** (a Meta-approved WhatsApp provider). Staff keep
using the WhatsApp Business app on the phone, and every chat also appears
in the DIS Inbox (WhatsApp tab), on the Front Desk, and in the client's
portal Messages.

No developers.facebook.com app is needed — 360dialog runs Meta's sign-up
for us. Screens change often, so button names may differ slightly.

## Before the day
- The office number has been using the **WhatsApp Business app** for at
  least 7 days, and the app is updated to the latest version.
- The **Meta Business Portfolio** for Duque Immigration is complete:
  legal name, address, website and business phone (business.facebook.com →
  Settings → Business info). Verification there lifts sending limits.
- 360dialog is a paid service (from about €49 per number per month, plus
  Meta's per-message fees for messages DIS sends). Have a card ready.

## Part 1: DIS first (Jhun + Claude)
1. Back up the live database.
2. Run `sql/migration_whatsapp.sql` on the live database. It only adds
   columns and tables and never changes existing data. It must run
   **before** step 3.
3. Deploy the code. WhatsApp stays switched off (no `WA_API_KEY`), so staff
   see no change except that threads are sorted by time sent.

## Part 2: Connect the number (boss's Mac + phone, ~30 min)
Do Parts 2 and 3 **on the same day**: chat history is only shared during
the first 24 hours after connecting, and DIS only receives it once step 9
is done.

4. Sign up at **hub.360dialog.com** with the business email, and add a
   payment method.
5. Click **Add channel** (or **Connect number**). Meta's sign-up opens in a
   pop-up: log in with the Facebook account that manages the Duque
   Immigration business portfolio, pick the portfolio, and choose to
   connect the **existing WhatsApp Business app** number (Coexistence).
6. Enter the office number. On the phone, WhatsApp Business shows a message
   to connect to the Business Platform — open it, turn on **share chat
   history** (up to 6 months) and scan the QR code shown on the Mac.
   - Do **not** delete or re-register WhatsApp on the phone.
   - The app must be opened at least once every 13 days afterwards, or the
     link goes inactive.
7. In the 360dialog Hub, open the number → **Generate API key**. Copy it
   straight into the server config (step 8). Don't paste it into chat or
   email — it can send messages as the office.

## Part 3: Switch DIS on
8. Claude generates a random **webhook token**. Jhun puts both values into
   the server's `config.php`:
   ```php
   define('WA_API_KEY', '…');               // step 7
   define('WA_BUSINESS_NUMBER', '1204…');    // office number, digits only
   define('WA_WEBHOOK_TOKEN', '…');          // from Claude, 32+ characters
   define('WA_NOTICE_TEMPLATE', '');         // fill in once step 10 is approved
   define('WA_NOTICE_TEMPLATE_LANG', 'en');
   ```
9. Register the webhook (Claude, over SSH, right away):
   `php cron/wa_set_webhook.php`
   This tells 360dialog to send all WhatsApp events for the number to
   `https://admin.duqueimmigration.ca/api/whatsapp_webhook.php`, with the
   webhook token in a header so DIS can reject anything else.
10. **Notice template** (for replies sent more than 24h after the client last
    wrote). In the 360dialog Hub → Templates → Create:
    - Category: **Utility**, Name: `portal_message_notice`, Language: English
    - Body: `Hi {{1}}, you have a new message from Duque Immigration. Please log in to your client portal to read it: https://portal.duqueimmigration.ca`
    - Sample for {{1}}: `Maria`
    - Wait for approval (minutes to a day), then set
      `WA_NOTICE_TEMPLATE` to `portal_message_notice`. Until then, such
      replies just stay portal-only.

## Part 4: Test
11. From a staff member's personal phone, send a WhatsApp message to the
    office number. It should appear in DIS within seconds, either in that
    person's thread (Inbox → WhatsApp tab) if their phone is on a client
    record, or under **Unmatched WhatsApp numbers**.
12. Reply from DIS with **Send via → WhatsApp** (Inbox) or the WhatsApp
    choice in the Front Desk quick reply. It should arrive on the phone,
    and ticks should appear in DIS.
13. Reply from the office phone's WhatsApp app. It should show up in DIS as
    "WhatsApp (office phone)".
14. Check that past chats arrived (history import). If not, it's still
    possible within 24h of step 6 by re-sharing from the 360dialog Hub.

## Good to know
- Client matching is strict on purpose. A number links automatically only
  if it matches exactly one client's phone, and the phone needs a country
  code or must be a 10-digit Canada/US number. Everything else waits under
  **Unmatched WhatsApp numbers** for staff to link once. Families sharing
  one phone always need a manual pick.
- Replies within 24h of the client's last WhatsApp are free-form. Older ones
  send the notice template instead (Meta charges per template message).
  Messages typed on the office phone's app stay free.
- Raw webhook calls are kept 30 days in `whatsapp_webhook_log` for
  troubleshooting.
- To switch WhatsApp off in DIS, empty `WA_API_KEY` in `config.php`. The
  phone keeps working as before.
