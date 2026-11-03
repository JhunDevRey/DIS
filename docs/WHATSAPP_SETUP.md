# WhatsApp → DIS Messages: setup checklist

This links the office WhatsApp Business number to DIS through Meta's
WhatsApp Business Platform in **Coexistence** mode. Staff keep using the
WhatsApp Business app on the phone, and every chat also appears in the DIS
Inbox and in the client's portal Messages.

Meta changes its screens often, so button names below may differ slightly.

## What you need on the day
- Remote session on the boss's Mac (Chrome Remote Desktop), logged in to the
  Facebook account that owns the business.
- The boss's phone with the office **WhatsApp Business** app, updated to the
  latest version, nearby and unlocked. It has to approve the link.
- About 30–60 minutes.

## Part 1: DIS first (Jhun + Claude, before the Meta session)
1. Back up the live database.
2. Run `sql/migration_whatsapp.sql` on the live database. It only adds columns
   and tables and never changes existing data. The new code needs these
   columns, so this must happen **before** step 3.
3. Deploy the code. WhatsApp stays switched off until step 12, so staff see no
   change except that threads are sorted by time sent.

## Part 2: Meta (boss's Mac + phone)
4. **Business portfolio.** Go to business.facebook.com and confirm the
   Duque Immigration business portfolio exists. Business verification
   (Security Center) is recommended and lifts sending limits.
5. **Meta app.** Go to developers.facebook.com → My Apps → Create app →
   type **Business** → link it to the Duque Immigration portfolio → add
   the **WhatsApp** product.
6. **Connect the existing number (Coexistence).** In the WhatsApp setup, choose
   to add a phone number and pick the option to **connect an existing
   WhatsApp Business app** number. Enter the office number and follow the
   prompts on the phone (scan the QR code or approve in the app).
   - When asked about sharing **chat history**, say yes to import up to
     6 months of past chats into DIS (only possible within 24 hours of
     connecting).
   - Do **not** delete or re-register WhatsApp on the phone.
   - If Meta's own screens don't offer the "existing app" option, we'll
     use a provider (360dialog / YCloud / Twilio) for this step instead.
7. **Permanent token.** Business settings → Users → **System users** → Add
   (role Admin) → Assign assets: the app and the WhatsApp account (full
   control) → **Generate token** with `whatsapp_business_messaging` and
   `whatsapp_business_management`, set to never expire. Copy it straight into
   the server config (step 12). Don't paste it into chat or email.
8. **App secret.** App settings → Basic → App secret → Show. This goes straight
   into the server config as well.
9. **Phone number ID.** WhatsApp → API Setup. Copy the **Phone number ID**
   (a long number) and the office number in international format.
10. **Notice template** (for replies sent more than 24h after the client last
    wrote). WhatsApp Manager → Message templates → Create:
    - Category: **Utility**, Name: `portal_message_notice`, Language: English
    - Body: `Hi {{1}}, you have a new message from Duque Immigration. Please log in to your client portal to read it: https://portal.duqueimmigration.ca`
    - Sample for {{1}}: `Maria`
    - Submit and wait for approval (usually minutes, sometimes a day).
      DIS works without it; such replies just stay portal-only until it's
      approved.

## Part 3: Connect them
11. Claude generates a random **verify token** for you.
12. Fill these into the server's `config.php` (Jhun edits it directly):
    ```php
    define('WA_PHONE_NUMBER_ID', '…');        // step 9
    define('WA_BUSINESS_NUMBER', '1204…');     // office number, digits only
    define('WA_ACCESS_TOKEN', '…');            // step 7
    define('WA_APP_SECRET', '…');              // step 8
    define('WA_VERIFY_TOKEN', '…');            // step 11
    define('WA_GRAPH_VERSION', 'v23.0');
    define('WA_NOTICE_TEMPLATE', 'portal_message_notice');  // once approved
    define('WA_NOTICE_TEMPLATE_LANG', 'en');
    ```
13. **Webhook.** In the Meta app → WhatsApp → Configuration → Webhook:
    - Callback URL: `https://admin.duqueimmigration.ca/api/whatsapp_webhook.php`
    - Verify token: the one from step 11 → **Verify and save**
    - Subscribe to the fields **messages**, **smb_message_echoes** and **history**.

## Part 4: Test
14. From a staff member's personal phone, send a WhatsApp message to the office
    number. It should appear in the DIS Inbox within seconds, either in that
    person's thread if their phone is on a client record, or under
    **Unmatched WhatsApp numbers**.
15. Reply from DIS with **Send via → WhatsApp**. It should arrive on the phone,
    and ticks should appear in DIS.
16. Reply from the office phone's WhatsApp app. It should show up in DIS as
    "WhatsApp (office phone)".

## Good to know
- Client matching is strict on purpose. A number links automatically only if
  it matches exactly one client's phone, and the phone needs a country code
  or must be a 10-digit Canada/US number. Everything else waits under
  **Unmatched WhatsApp numbers** for staff to link once. Families sharing one
  phone always need a manual pick.
- Replies within 24h of the client's last WhatsApp are free. Older ones send
  the paid notice template instead (Meta charges per template message by
  country).
- Raw webhook calls are kept 30 days in `whatsapp_webhook_log` for
  troubleshooting.
