# Duque Immigration — Document Portal

A small PHP/MySQL app for managing client document checklists:

- **Admin side** (`/admin`) — staff log in, add clients, open document
  "fields" for a client to fill, review uploaded files, change each
  document's status (Awaiting Upload → Received → Needs Revision /
  Accepted), and leave comments.
- **Client side** (`/portal`) — clients log in with the credentials you
  give them, see their checklist, upload files against each open field,
  and read/reply to comments.

Matches your existing DWC stack: PHP + PDO/MySQL, vanilla HTML/CSS/JS,
same Laragon ⇄ InfinityFree config-switching pattern.

## 1. Local setup (Laragon)

1. Copy this folder into `laragon/www/duque-immigration-admin`.
2. Start Laragon (Apache + MySQL).
3. Open phpMyAdmin (or HeidiSQL) and create a database named
   `duque_immigration_portal`.
4. Import `sql/schema.sql` into that database. This also seeds one admin
   login: **username `admin`, password `ChangeMe123!`** — change it after
   your first login (see below).
5. Visit `http://localhost/duque-immigration-admin/admin/login.php`.

`config.php` already detects `localhost` and points at
`duque_immigration_portal` with the default Laragon root/no-password
MySQL credentials — no edits needed for local dev.

## 2. Deploying to InfinityFree

1. In the InfinityFree control panel, create a MySQL database and note
   the host, database name, username, and password it gives you.
2. Open `config.php` and fill in the four `InfinityFree (live)` values
   (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) and `APP_URL`.
3. Upload the whole folder to `htdocs/` (or a subfolder, e.g.
   `htdocs/documents/`) via FTP.
4. Import `sql/schema.sql` through phpMyAdmin on InfinityFree.
5. Make sure the `uploads/` folder is writable (InfinityFree usually
   defaults new folders to 755, which is fine).

## 3. Changing the seeded admin password

Generate a new hash and update the row directly in `admin_users`:

```php
<?php echo password_hash('YourNewPassword', PASSWORD_DEFAULT);
```

Run that with `php -r "..."` locally, or drop it in a throwaway PHP file,
copy the output, then in phpMyAdmin:

```sql
UPDATE admin_users SET password_hash = 'PASTE_HASH_HERE' WHERE username = 'admin';
```

## 4. Adding staff or client accounts

- **Staff/admin accounts**: insert directly into `admin_users` (there's
  no self-serve staff signup by design — this stays internal).
- **Client accounts**: use the "+ Add client" button on the admin
  dashboard. You set their temporary password when creating them and
  share it with the client separately (email/phone) — there's no
  password-reset flow yet, so for now you'd manually update
  `clients.password_hash` the same way as above if someone gets locked
  out.

## 5. How the checklist works

1. Open a client's page in the admin panel and click **"+ Request a
   document"** to open a new field (e.g. "Passport bio page", with
   optional instructions).
2. That field immediately appears in the client's portal as **Awaiting
   Upload**.
3. When the client uploads a file, its status automatically flips to
   **Received**.
4. Staff review the file and set the status to **Needs Revision** (client
   sees this and can re-upload — a fresh upload automatically flips it
   back to Received) or **Accepted**.
5. Either side can leave comments on a field at any time — useful for
   "this scan is blurry, please retake" type notes.
6. Staff can also attach a file themselves — either right when opening
   the field (e.g. a blank template for the client to fill out), or any
   time after via the **"Upload for client"** control next to each
   field. This is for documents you already have (received by email,
   collected in person, etc.) so you don't have to ask the client to
   re-upload something you already hold. Every file shows a full
   attribution line — "Uploaded by [Name] on MM/DD/YYYY" — using the
   actual uploader's name captured at upload time.
7. Comments are collapsed by default under a "Comments (N)" toggle on
   each field, so a long checklist doesn't turn into a wall of text —
   click to expand/collapse per field.
8. Both sides now have a **Dashboard** (quick stats + what needs
   attention) separate from the full list/checklist view, and a
   **My Profile** page (staff: name + password; client: phone +
   password).
9. Every file row has hover-revealed **View / Download / Delete**
   buttons. Staff can delete any file; clients can only delete files
   they uploaded themselves (not ones staff attached) — if they delete
   the last file on a field, its status drops back to "Awaiting
   Upload" automatically.
10. The **Clients** list has a search box (filters by name, email, or
    case type) and shows Name of Applicant, Case Type, Email Address,
    Phone Number, and Password columns. The password is masked by
    default — click the eye icon to reveal it, or the pencil icon to
    set a new one (which also updates the client's actual login).
11. Instead of opening fields one at a time, use **"Request a
    checklist"** on a client's page to pick one of 8 predefined case
    types (WP New, WP with SOWP (minors), TRV in Canada, TRV Outside
    Canada with Dependents, PR Economic Non Express Entry, PR Economic
    with EE, PR Renewal, Spousal Sponsorship in Canada). It opens an
    editable review screen — rename any document, edit/add notes,
    toggle required, remove items, or add extra ones — and nothing is
    created until you click "Send checklist to client." The templates
    themselves live in `includes/checklist_templates.php` — edit that
    file directly to adjust the default wording/items for your actual
    checklists; whatever's there is just a starting point staff can
    always override per-client before sending.
12. **Processors** — a new "Processors" page (name + email) lets you
    keep a directory of your team. Assign one to each client (when
    creating them, or later via "Edit details" on their page), and
    they'll get an email every time that client uploads a file or
    posts a comment. The loop runs both ways — when staff post a
    comment, the client gets emailed too, with a link to log in and
    see it.
13. **Client No. + Dropbox** — if Dropbox is configured, every client
    gets a folder automatically at `MASTER LIST/{year}/{MONTH}/{Client
    Full Name}`, mirroring your existing Master List layout. Leave
    "Client No." blank when adding a client to auto-assign the next
    number for the current month (tracked in this app, not part of the
    folder name), or enter an existing one to reuse that client's
    existing folder instead of creating a new one. Any document marked
    **Accepted** is automatically copied into a subfolder named after
    that specific requirement (e.g. a `MARRIAGE CERTIFICATE` subfolder),
    inside that client's folder — not one shared folder for everything
    accepted. Full details in "Setting up Dropbox" below.
14. **Accepted images auto-convert to PDF on download.** If an accepted
    document is a JPG or PNG, clicking **Download** (not "View" — that
    still shows the original for a quick preview) serves a compressed,
    single-page PDF version instead of the raw image, generated on the
    fly with no external tools required. This does **not** apply to
    DOCX files — reliable DOCX→PDF conversion needs either LibreOffice
    installed server-side (not available on typical shared hosting) or
    a paid conversion API; DOCX files just download as-is for now.
15. **Processor notification links are login-gated, not auto-login.**
    Clicking the link in a processor's notification email takes them to
    the admin login page and, after they sign in with their own admin
    credentials, drops them directly onto the relevant client's page.
    This deliberately does not bypass authentication — a link that
    logged anyone in automatically would be a real security hole if an
    email ever got forwarded or exposed. A processor needs their own
    existing admin login for this to work; being in the Processors list
    alone doesn't grant one (there's still no self-serve staff signup,
    by design).
16. **"Send reminder" button** on each client's page emails them a list
    of whatever's still pending or needs revision, with a link to the
    portal login. Does nothing (with a clear message) if the client has
    nothing outstanding.
17. **Link to an existing Dropbox folder directly**, for clients who
    already have a real folder from before this system was in use, or
    organized in a past month that auto-provisioning wouldn't find. The
    "Existing Dropbox folder path" field (in both Add Client and Edit
    Client) validates the path is real before linking to it — it never
    creates anything at that path, so a typo just shows an error rather
    than silently creating a stray folder.
18. **Select multiple files and download them together as one ZIP —
    admin side only**, not on the client portal. When a document field
    has more than one file, a "Select all" checkbox plus a "Download
    selected" toolbar appears above them (fields with only one file are
    unaffected — the per-file hover actions work exactly as before).
    Any accepted image included in the selection still gets converted
    to PDF inside the zip, same as a single download would.
19. **"Download all documents" button** on each client's page — grabs
    every file across every one of their document fields in one ZIP,
    organized into a subfolder per field (e.g. "Passport bio
    page/scan.pdf") so a checklist with many fields stays easy to
    browse once extracted, rather than dumping everything into one
    flat folder.
20. **Full visual redesign — AdminLTE-inspired, modern dashboard look,
    built on the duqueimmigration.ca brand red (`#DD0000`).** This went
    further than a color swap:
    - **Typography**: dropped the earlier editorial serif (Fraunces)
      entirely in favor of **Sora** (headings/numbers) + **Inter**
      (body/UI) — a clean sans-serif pairing that reads as a modern
      dashboard rather than a marketing site.
    - **Density**: smaller, tighter type sizes and spacing throughout,
      closer to what real admin tools use — the previous version was
      sized more like a content site.
    - **Solid-fill accents**: stat box icons and status pills switched
      from soft pastel tints to solid color fills with white
      text/icons — the classic AdminLTE "info-box" look, and more
      legible at a glance than the softer tint-on-tint style.
    - **Colored left-border accents** on stat boxes and a red top-border
      on cards/modals, another AdminLTE-era signature, tastefully
      restrained rather than applied everywhere.
    - **Sharper corners**: border-radius pulled in from 10–14px down to
      6–12px depending on element, reading as more structured/
      administrative rather than "friendly SaaS."
    - `--maple` (a deep maroon) stays reserved for delete/danger actions
      specifically, so they never visually compete with primary red
      buttons.
    - Since the whole app already used CSS variables consistently, all
      of this came from one file, `css/style.css` — no page's HTML
      needed to change, other than adding small modifier classes
      (`stat-box-accepted`, etc.) to the dashboard stat boxes so their
      left-border color works in every browser (an earlier draft used
      the newer `:has()` CSS selector for this, which isn't supported
      in all browsers — swapped for explicit classes instead).
21. **Mobile-responsive, with the browser's native popups replaced
    everywhere.**
    - **Hamburger nav on mobile** — the sidebar collapses to a slim top
      bar with a menu button; tapping it slides out the full nav as an
      overlay (tap the dimmed backdrop, pick a link, or press Escape to
      close it). Actually tested at real phone width, not just written
      and assumed.
    - **Fixed a real bug**: file action icons (View/Download/Delete)
      only ever appeared on hover — which doesn't exist on
      touchscreens, so they were completely unreachable on any phone
      or tablet. Now always visible on touch/mobile.
    - **Tables become cards on mobile** — the Clients and Processors
      tables reflow into labeled stacked cards below ~700px instead of
      overflowing or requiring horizontal scrolling.
    - **Every `confirm()` and `alert()` in the app replaced** with
      styled dialogs matching the rest of the UI (`showConfirm()` /
      `showNotice()` in the new `js/ui-kit.js`, shared by every page).
      `showConfirm()` returns a promise (`if (!(await
      showConfirm('...'))) return;`), so it's a drop-in replacement
      wherever `confirm()` used to block the page.
    - Bigger tap targets, full-width stacked buttons, single-column
      dashboard stats, and 16px form inputs on mobile (smaller inputs
      trigger an auto-zoom on iOS that's genuinely annoying — 16px
      avoids it).
    - `js/admin.js` and the old duplicate `showToast()` in `js/portal.js`
      were consolidated into `js/ui-kit.js`, so toast/modal/confirm
      logic exists in exactly one place instead of being copy-pasted
      per file.
22. **New clients get a welcome email automatically** the moment
    they're created — warm greeting, their login email, their
    temporary password (in a clearly boxed, monospaced block), and a
    button straight to the portal login. Best-effort like every other
    email here: if it fails to send, client creation still succeeds,
    it just doesn't get a welcome email that time.

    Worth knowing: this means the temporary password travels over
    email in plain text, since there's no self-service "forgot
    password" flow yet. That's a real tradeoff, not an oversight — it
    only matters much for a system with sensitive data, and the client
    can (and is nudged to) change their password immediately after
    first login.

    With this, the full notification loop is: **welcome email** on
    signup, **client gets emailed** whenever staff open a new document
    field or send a checklist ("Request a document" / "Request a
    checklist"), **client gets emailed with a reason** whenever a
    document field is removed from their checklist, **processor gets
    emailed** when a client uploads or comments, **client gets emailed**
    when staff comments, and **client gets a reminder email** (with a
    nudge to comment if they have questions) whenever staff click "Send
    reminder."
23. **Staff Notes** on each client's page — a private textarea (with a
    visible "never shown to the client" label) for internal notes: case
    history, communication preferences, anything staff should know.
    Completely separate from **Comments**, which both sides can see —
    Notes never appear anywhere in the client portal.
24. **Linking to an existing Dropbox folder now fails safely.** If staff
    type in a path that can't actually be found in Dropbox, the whole
    action stops — no client gets created (or, when editing, nothing
    gets saved) with a broken/unlinked path. Previously this would
    silently succeed with just a warning message, which could leave a
    client's folder link quietly broken. Applies to both "Add client"
    and "Edit details."
25. **Every checklist template item now has a specific instruction** —
    all 59 items across all 8 templates (previously about a third were
    blank). E.g. "Passport bio page" now says exactly what's needed
    ("clear colour scan of the photo page, all four corners visible, no
    glare..."), not just the document name. Still fully editable by
    staff per-client before sending, same as before.
26. **Removing a document field now requires a reason**, entered in a
    popup instead of a plain yes/no confirm. The client is automatically
    emailed the name of the removed document and that exact reason, so
    they're never left wondering why something they were tracking just
    disappeared from their checklist.
27. **Staff can now edit a client's name, email, and phone** from the
    "Edit details" modal — previously only case type, phone, Client No.,
    processor, and Dropbox path were editable there. Changing the email
    changes their portal login (their password is untouched); it's
    checked against every other client's email first, so you can't
    accidentally set two clients to the same login.
28. **Additional (CC) emails per client** — a field on both Add Client
    and Edit Details for one or more extra addresses (comma-separated),
    e.g. a spouse or agent who should stay in the loop. Every
    notification email sent to that client — new documents requested,
    a document removed, staff comments, reminders, the welcome email —
    automatically CCs everyone listed. Each address is validated
    individually (a typo gets a clear error immediately, not a
    silently-broken CC later), and works across all three mail-sending
    tiers (SMTP, Brevo, plain `mail()`).
29. **A broken Dropbox path now shows a prominent popup alert**, not
    just small inline text easy to miss — on both Add Client and Edit
    Details. Combined with the earlier fix (a broken path blocks the
    whole save rather than silently succeeding with no link), staff now
    get an impossible-to-miss heads-up the moment something's wrong,
    instead of finding out later that a client has no Dropbox folder.
30. **Clients can manage their own CC emails** from their portal profile
    — the same "additional emails, CC'd on every notification" field
    staff have, now also editable by the client themselves (not just
    staff). Deliberately **not** included: letting clients change their
    own login email — that's a more sensitive, security-relevant
    decision (account takeover risk, phishing-style social engineering)
    that wasn't clearly requested, so it stayed admin-only pending an
    actual decision either way.
31. **Designated person** — clients (and staff, on their behalf) can
    record one authorized contact's name and phone number on the
    client's profile — someone allowed to receive information about
    the case. Shown on the client's page header when set, editable from
    both the client's own portal profile and admin's "Edit details."
32. **Processors → click a row → see their assigned clients**, in a
    right-side slide-in panel (name, case type, email, and status pills
    per client, each linking straight to that client's page). Includes
    a live search box (only shown once there's more than one client to
    search through) filtering by name, case type, email, or phone —
    all client-side, since the full list is already fetched when the
    panel opens. No new login system — this is purely an admin-side
    view, since processors don't have accounts of their own; if that
    ever needs to change (an actual processor login/portal), that's a
    bigger, separate decision.
33. **Client notifications batched into one daily digest email**,
    instead of a separate email per event. New documents requested,
    checklists sent, documents removed (with reason), staff comments,
    and status changes all queue into `client_notification_queue`
    instead of sending immediately, then get rolled into one "Your
    daily update" email per client, once every 24 hours, via a cron job
    (see "Setting up the daily digest cron job" — **this needs to be
    configured on the server or nothing sends at all**). The welcome
    email (login credentials) stays immediate, by design.
34. **Processor notifications batched the same way** — a client
    uploading a file or posting a comment now queues into
    `processor_notification_queue` instead of emailing the processor
    immediately, rolled into one "Your daily client activity" email per
    processor via the same cron run. Unlike the client-facing digest,
    there's **no manual "send now" button for processors** — only the
    automatic daily batch, by design.
35. **Per-client "send notifications now" bell** — moved from a single
    global Dashboard button to a bell icon on each client's own page,
    scoped to just that client. Shows "Last notif was sent on {date} at
    {time}" right on the button, using the real delivered timestamp
    (not an estimate) — updates immediately after sending, and reflects
    the true last-sent time on page load even before you click it.
    Client-only, per how this was asked for — there's deliberately no
    equivalent for the processor digest.
36. **Multiple file upload per document field** — both the client
    portal and admin's "upload on behalf of client" now accept
    selecting several files at once from the system file picker,
    uploaded one after another with progress shown ("Uploading 2 of
    3…"). Reuses the same single-file upload endpoint underneath — no
    new upload logic to trust, just a loop around the existing one.
37. **"Portal account created on" date** — a day/month/year set of
    fields (day as free text, month and year as dropdowns) on both Add
    Client and Edit Details. Defaults to today for normal new clients;
    exists specifically so a client imported from the Notion migration
    can keep their real original date instead of showing today's date.
    Validated server-side with `checkdate()` so an invalid combination
    (e.g. February 30) is rejected with a clear message rather than
    silently saved wrong.
38. **Sticky sidebar + smooth transitions throughout.** The sidebar now
    stays pinned in the viewport on desktop while the main content
    scrolls independently (`position: sticky`), instead of scrolling
    away with tall pages. Separately, went through the whole app adding
    consistent, tasteful transitions to everything that was missing
    one — cards, sidebar nav, clickable table rows, icon buttons, form
    inputs, dropdown menus, and modals. The dropdowns and modals in
    particular were refactored from an abrupt `display: none/block`
    toggle to a real fade (plus a slight scale/slide) transition. Along
    the way I caught and fixed a real bug this introduced: the
    dynamically-created confirm/notice dialogs (`showConfirm()` /
    `showNotice()` in `js/ui-kit.js`) were setting their "open" state in
    the same tick they were created, which would have skipped the
    fade-in entirely (nothing for the browser to transition from) —
    fixed with the standard `requestAnimationFrame` pattern, and added a
    proper fade-out before removal on close, which wasn't there before
    either.
39. **Client folder naming now includes case type, uppercased**:
    "{Client No.}. {FULL NAME} - {CASE TYPE}" — e.g. "21. JOHN DOE -
    SPOUSAL SPONSORSHIP" (was previously just "{Client No.}. {Name}").
    Applies automatically to new folders on both Add Client and Edit
    Details; if a client has no case type set, it falls back to just
    "{Client No.}. {FULL NAME}" rather than a trailing " - " with
    nothing after it. Existing clients' already-created folders aren't
    renamed retroactively — this only affects folders created (or
    re-provisioned) going forward.
40. **Each accepted document now gets its own Dropbox subfolder**,
    named after that specific checklist requirement — e.g. a client
    marked Accepted on "Marriage Certificate" gets a `MARRIAGE
    CERTIFICATE` subfolder inside their client folder, with the file
    copied there. Replaces the old behavior of copying every accepted
    file into one shared `APPROVED DOCUMENTS` folder — with this
    change, ten accepted documents means ten separate, clearly-labeled
    subfolders instead of ten files all mixed into one place.
41. **"Client No." is a single combobox field** (Add Client and Edit
    Details) — one text field you can type any number into directly
    (including above 100), plus a dropdown-arrow button that opens a
    styled, clickable list of 1–100 to pick from instead, with numbers
    already in use marked "in use" right in the list. This went through
    three iterations, worth knowing about in case a similar pattern is
    needed elsewhere:
    1. HTML's `<datalist>` — free typing worked, but its suggestion
       popup can't be styled at all (pure browser-native UI), which
       looked visually broken/disconnected from the field in practice.
    2. A real `<select>` next to a separate text input — looked right,
       but ended up as two visually separate fields for one concept,
       which read as cluttered rather than as a single control.
    3. **What's actually shipped**: a proper custom combobox — one
       input, with a small styled dropdown (reusing the app's existing
       `.dropdown-menu` component, the same one "Request a checklist"
       already uses) that opens on clicking the arrow and fills the
       input on selection, while the input itself stays a completely
       normal, always-editable text field the whole time. Caught and
       fixed a real layout bug while building this: the dropdown
       initially positioned itself below the *entire* field block
       (label + input + help text) instead of directly under the
       input, because the `position: relative` wrapper included the
       help text — fixed by scoping that wrapper to just the input,
       toggle button, and dropdown.
42. **"Portal account" moved to the left column**, and a new "Dropbox
    folder added on" field (Month + Year only, no day) sits directly
    below Client No. on the right — in both Add Client and Edit
    Details. Deliberately **not** wired into any actual Dropbox logic
    at all — it's pure reference metadata for staff (e.g. to record
    that an imported client's real folder predates this system),
    completely independent from "Portal account created on" and from
    folder creation/naming. Optional: leaving either dropdown blank (or
    only filling in one of the two) just leaves it unset, no validation
    error — matches how the existing Portal Account date field already
    behaves for consistency. Worth knowing: Client No. itself sits in
    different columns between the two modals (right side on Add, left
    side on Edit — an existing inconsistency from earlier work), so the
    new field ends up in different columns between them too, directly
    below wherever Client No. already was in each.
43. **The "send notifications now" bell button** on a client's page now
    shows a fixed label ("Send new comments now") instead of the
    last-sent timestamp as its own clickable text — the "Last notif was
    sent on..." line moved to plain text sitting next to the button
    instead, so the button itself always reads the same regardless of
    when it was last used.
44. **Accepted images automatically convert to PDF and compress to
    under 4MB** before being uploaded to Dropbox — not just on manual
    download like before. Actually tested this against a real
    16-megapixel synthetic photo (deliberately random-noise pixels,
    worse than any real photo for compression) — compressed from 16MB
    down to 3.67MB in about 1 second using quality reduction alone;
    also confirmed the fallback path (scaling the image down, not just
    lowering quality) kicks in correctly for more extreme cases. Worth
    knowing honestly: **this only works for images** (JPG/PNG). Files
    that are already a PDF or DOCX can't be reliably compressed without
    server tools (Ghostscript/Imagick) I haven't confirmed are
    available on this host — those still upload as-is, but if one is
    over 4MB, you'll see it called out specifically in the warning
    message rather than silently uploaded oversized with no indication.
45. **Processor notifications now send one email per client**, instead
    of one combined email covering every client a processor has
    activity on. A processor with updates on 3 different clients now
    gets 3 separate emails (each with a subject naming that specific
    client), not one email listing all 3 mixed together — actually
    tested this against a real database: a processor with activity on
    2 clients correctly received exactly 2 separate emails, each
    containing only that client's own events.
46. **Every date/time in the app now shows in each viewer's own local
    timezone, automatically** — not one fixed timezone shown to
    everyone. A client logging in from Manila and staff in Winnipeg
    each see times correctly converted to wherever *they* actually
    are, with no manual setting anywhere. This replaced an earlier,
    simpler version of this feature that just fixed everything to
    Winnipeg time for every viewer — reasonable while this was
    internal/being built, but not once the app was going live as the
    real CRM with an international client base.

    **How it works**: UTC is now the one true baseline everywhere it's
    stored or calculated — both PHP's `date_default_timezone_set()` in
    `config.php` and MySQL's own session timezone in `includes/db.php`
    (MySQL's `NOW()`/`CURRENT_TIMESTAMP` run on the *database server's*
    own timezone setting, completely separate from PHP's, which is why
    both needed to move together). The actual "what timezone should
    this person see" question is answered entirely in the browser:
    `localTimeSpan()` (`includes/functions.php`) renders a `<span>`
    carrying the raw UTC instant plus a set of `Intl.DateTimeFormat`
    options, with a plain UTC-labeled fallback shown as the initial
    text (in case JavaScript is ever disabled). On page load,
    `applyAutoLocalTimes()` in `js/ui-kit.js` finds every one of these
    spans and replaces the fallback text with the real formatted
    time — using `Intl.DateTimeFormat` *without* specifying an explicit
    timezone, which is exactly what makes it auto-detect the browser's
    own. Covers every timestamp shown anywhere in the app: file upload
    attribution ("Uploaded by ... on ..."), the "Last notif was sent
    on..." text (both on page load and after clicking the bell button),
    client creation dates on the Dashboard, and the older-than-a-week
    fallback inside the existing relative "2h ago" / "3d ago" comment
    timestamps (the relative part itself needed no change — comparing
    two Unix instants is already timezone-agnostic by definition, only
    the calendar-date fallback for older items needed converting).

    Tested the actual mechanism thoroughly, not just each piece in
    isolation: simulated viewers in Winnipeg, Manila, London, Los
    Angeles, and Sydney all viewing the identical UTC instant, and
    confirmed each one renders a genuinely different, correct local
    time — including a Manila viewer correctly seeing the *next
    calendar day* due to the ~13-hour difference, exactly the scenario
    that would have been silently wrong under the old fixed-timezone
    approach. Also verified the full PHP→HTML→JavaScript handoff
    end-to-end: generated real PHP output, confirmed the JSON options
    payload survives HTML-entity encoding correctly, and confirmed the
    resulting formatted output matches.

    **One real limitation, stated plainly rather than glossed over:
    email content cannot do this.** There's no JavaScript running in an
    inbox, so if email content ever needs to show a specific date/time
    in the future, it would necessarily be in one fixed timezone
    (Winnipeg) — not a current problem, since none of the existing
    emails actually include a formatted date/time today (checked, only
    plain event summaries), but worth knowing if that ever changes.
47. **Comments now auto-link any URLs pasted into them** — both staff
    and client comments, on both the client detail page and the client
    portal. Built as `linkifyComment()` in `includes/functions.php`,
    replacing the previous plain `nl2br(h(...))` rendering. Safety is
    the whole point of how this is built: the **entire comment is
    HTML-escaped first**, and only the already-escaped text is then
    scanned for `http(s)://` patterns to wrap in real `<a>` tags — so
    it's structurally impossible for someone to inject raw HTML or
    script through a comment, regardless of what they type. Tested this
    specifically with real XSS attempts (a `<script>` tag, a
    `javascript:` pseudo-URL, and an attribute-breakout attempt trying
    to inject `onmouseover=` by closing the `href` early) — all three
    came back fully neutralized, confirmed by tracing through exactly
    how the output renders. Links open in a new tab
    (`target="_blank" rel="noopener noreferrer"` — the `rel` attributes
    specifically prevent the opened page from being able to manipulate
    the original tab back). Also added CSS specifically for links
    inside comments (`.comment a`) — the app's global link reset
    (`a { text-decoration: none; color: inherit; }`, used intentionally
    for nav/button-style links elsewhere) would otherwise have made a
    comment link completely invisible as a clickable thing, blending
    into the surrounding plain text with no visual indication at all.
48. **Modals, tables, and the Dashboard given a more polished, "AdminLTE
    professional" look** — a pure-CSS pass, deliberately built so it
    required **zero HTML changes** across the many modal and table
    usages throughout the app, to keep the risk low on a change this
    broad:
    - **Modals** now have a real header bar (solid dark background,
      white title text) and a distinct footer bar (light gray, top
      border) — the classic AdminLTE modal look, instead of the
      previous flat white box with a thin colored top border. Achieved
      by styling the *existing* `<h3>` and `.modal-actions` elements
      with negative margins that bleed them to the modal's edges, so
      every modal in the app — Add Client, Edit Details, confirm
      dialogs, everything — picked this up automatically with no
      per-modal changes needed. Verified this holds up correctly
      across a simple confirm dialog, a form modal, and the wide
      two-column Add Client modal specifically, including confirming
      the mobile-stacked footer buttons still work correctly.
    - **Tables** now have zebra striping (alternating row backgrounds)
      and a stronger header treatment (light gray fill, heavier bottom
      border), making rows meaningfully easier to scan across,
      especially on wider tables like the Clients list.
    - **Dashboard section cards** ("Needs your review," "Recently
      added clients," etc.) now have an attached header bar directly
      on top of each card — the classic AdminLTE "box-header" — instead
      of a heading floating above with a gap. Done via a CSS
      adjacent-sibling selector (`.section-title + .card`) that only
      activates when a card genuinely follows a section title, checked
      against every actual usage in the codebase (5 places, admin and
      portal both) to confirm none would be affected unexpectedly.
49. **"Edit client details" now matches "Add a new client"'s column
    layout exactly.** Client No. and "Dropbox folder added on" used to
    sit in the left column on Edit but the right column on Add — an
    inconsistency from how these were added in separate passes. Moved
    both fields to the top of the right column on Edit, matching Add's
    order precisely. Edit's fields that Add doesn't have at all
    (Designated Person) stayed exactly where they were, at the end of
    the right column. Verified nothing was lost or duplicated in the
    move — confirmed every affected field name still appears exactly
    once in the file, and confirmed the JS combobox handlers for
    Client No. still correctly find their elements after the move
    (element IDs don't care about DOM position, only about being
    unique, which they still are).
50. **Modals no longer scroll — the checklist modal grows wider
    instead**, per explicit direction: the first version of this
    capped modal height and let the body scroll internally, which
    wasn't what was wanted; rebuilt so nothing scrolls vertically at
    all (desktop). Every modal got a `.modal-body` wrapper (needed for
    this and worth knowing about structurally), and the checklist
    modal specifically — the one modal with a genuinely unbounded,
    growing list of items — now uses CSS Grid
    (`grid-auto-flow: column`) so once a column fills up (5 items), it
    starts a new column instead of growing taller or requiring scroll,
    with the modal itself widening (up to 960px) to fit. Found and
    fixed two real bugs while building this:
    1. A CSS cascade ordering bug — `.checklist-modal`'s wider
       `max-width` was defined *earlier* in the file than the base
       `.modal` rule, so the later, equal-specificity `.modal` rule was
       silently winning and overriding it back down to 440px. Confirmed
       by checking line numbers directly, not by guessing.
    2. My first attempt used CSS multi-column text-flow
       (`columns: 280px`) rather than Grid — tested it and found it
       silently **clips extra items instead of adding more columns**
       for content that doesn't fit the columns the container's width
       already allows. Switched to CSS Grid specifically because it
       doesn't have this limitation.

    **Honest limitation on testing this one**: verifying the CSS Grid
    column-wrapping visually requires a real, modern browser engine —
    my available screenshot tool (`wkhtmltoimage`) runs a WebKit fork
    from roughly 2012–2013, predating `grid-auto-flow: column` support
    entirely, and a genuine Chromium wasn't installable in this
    sandbox (the available package is a stub requiring `snap`, which
    isn't present here). I confirmed this specific limitation directly
    — a minimal, textbook Grid example failed to wrap into columns in
    my tool the same way — rather than just assuming it was my code at
    fault. `grid-auto-flow: column` itself is a mature, universally
    supported feature in real browsers (Chrome, Firefox, Safari, Edge)
    that's been stable since around 2017, and the CSS here follows the
    standard pattern for it — but this specific visual behavior is the
    one piece in this batch I could not personally screenshot-confirm,
    and it's worth checking once deployed rather than taking on faith.
    Everything else in this change (the layout structurally holding
    together with no scrollbar, correct rendering with both few and
    many fields on the non-Grid modals) was verified directly.

    **Update**: confirmed working — a real screenshot from an actual
    Chrome browser showed the checklist correctly wrapping into a
    second column once the first filled up, exactly as designed.
51. **Fixed a real bug the no-scroll change above introduced**: on a
    shorter browser viewport, a modal with enough fields to exceed the
    visible height (Edit Client, with every optional section filled
    in, is tall enough to trigger this) would get vertically centered
    by the backdrop's flexbox layout with no way to scroll — pushing
    the header **above the top of the screen, completely unreachable**,
    worse than the scrolling that was explicitly asked to be removed.
    Confirmed this exact bug from a real screenshot (the "Edit client
    details" header was entirely absent from view, cut off above the
    visible page). Fixed by changing the backdrop from pure
    `align-items: center` (which has no fallback once content overflows
    the viewport) to top-aligned with page-level scroll — a modal now
    always starts fully visible from its header, and only needs
    scrolling (the page itself, not an internal modal-body scrollbar)
    if it's genuinely taller than the screen. This is deliberately a
    different, lower-level mechanism than the internal scrolling that
    was rejected — it's a necessary safety net for the rare case of a
    modal being taller than someone's actual screen, not a
    reintroduction of what was asked to be removed. Tested this
    specifically by reproducing the exact failing scenario (the full
    Edit Client field set at a short 650px viewport) and confirmed the
    header now appears immediately, with no scrolling needed to see it.
52. **Checklist columns now cap at 3 document pairs each**, not 5 —
    a one-line change (`grid-template-rows: repeat(3, auto)` instead of
    `repeat(5, auto)`) once the 5-per-column version was confirmed
    working but judged not the right density. Confirmed the sizing
    still holds: 3 columns fit comfortably within the modal's existing
    960px max-width (896px needed), with a 4th+ column falling back to
    a bounded horizontal scroll on just the checklist area — only for
    unusually large checklists, not the normal case.
53. **Processor notifications restructured again — back to one email
    per day per processor** (not one email per client, which is what
    the previous couple of iterations built), with each event inside
    that single email as its own clearly labeled entry: client name,
    what happened ("commented on the portal" / "uploaded a file"), and
    when ("Today"/"Yesterday" + exact `MM/DD/YYYY - HH:MM`). A
    processor with activity on 2 different clients now gets 1 email
    listing both, not 2 separate emails. New `event_type` column on
    `processor_notification_queue` (`comment` or `upload`) drives the
    clean action phrase — tracked as its own structured field rather
    than parsed back out of a sentence, so the phrasing stays exactly
    consistent regardless of what's in the underlying detail text
    (which is still shown too, as smaller supporting text under each
    entry, so nothing gets lost even though the headline format is
    deliberately terse). The date/time is always in Winnipeg time
    specifically, for the same reason noted elsewhere in this README —
    there's no JavaScript running in an inbox to convert it to each
    reader's own timezone the way the live web app does.
    "Today"/"Yesterday" are relative to when the digest is actually
    sent, the closest meaningfully achievable thing for static email
    content. Tested the actual relative-day math directly (an event
    happening right now correctly labels "Today," one 25 hours earlier
    correctly labels "Yesterday," one 3 days earlier correctly falls
    back to a real date instead) and ran the full pipeline against a
    real database reproducing the exact scenario from the request —
    confirmed one email, three correctly-ordered numbered entries,
    mixing two different clients within it exactly as asked.
54. **Add Client, Edit Client, and the checklist modal now have an
    explicit × close button** in the header's upper-right, and clicking
    outside them (on the dimmed backdrop) no longer closes them —
    these three specifically hold enough typed content that an
    accidental outside click losing it all felt worth preventing.
    **Escape and the existing Cancel button still work** — this was a
    deliberate choice, not an oversight: the request was read as "stop
    the *accidental* outside-click from closing it," not "make Cancel
    stop working," since a Cancel button that doesn't actually cancel
    would be confusing. Worth flagging in case that reading wasn't the
    intent. Implemented via a `data-no-outside-close` marker checked by
    the existing global backdrop-click handler, so only these three
    modals are affected — every other modal in the app keeps the
    original click-outside-to-close behavior unchanged. One real bug
    caught and fixed while wiring up the checklist modal specifically:
    its title text gets overwritten dynamically via JavaScript
    (`textContent`) depending on context, and the close button was
    initially placed *inside* that same element — meaning the JS title
    update would have silently deleted the button along with the old
    text. Fixed by moving the dynamic-title `id` onto an inner `<span>`
    instead, with the button as a sibling the JS never touches.
55. **Fixed a real caching bug**: `js/ui-kit.js`, `js/admin.js`, and
    `js/portal.js` were loaded with plain `<script src="js/ui-kit.js">`
    tags — no cache-busting at all, unlike `style.css` (which already
    had this fixed, much earlier in this project, via `cssVersion()`).
    This surfaced concretely: after deploying the modal close-button
    change, the outside-click-still-closes-it behavior persisted even
    though the *server* file was confirmed updated (checked directly
    over SSH) — meaning the actual browser was still running the old
    JavaScript. Hostinger's CDN caches static assets for a full week by
    default; without a versioned URL to signal "this file actually
    changed," both the CDN edge and the browser can keep serving a
    stale cached copy indefinitely, invisible to any check of the
    origin server itself. Added a general `assetVersion()` helper
    (the same pattern as `cssVersion()`, generalized to any file) and
    applied it to all three JS files across every one of the 8 pages
    that load them — confirmed `functions.php` (where the new helper
    lives) is actually required on every one of those 8 pages first,
    so this can't introduce a fatal "function not found" error anywhere.
56. **Editable links** — staff can hover any link inside document/
    checklist instructions or their own comments, click the pencil
    icon that appears, and set a custom display label in a small
    popup (matching Google Docs' own link-editing pattern) while
    keeping the actual URL underneath — e.g. a raw
    `https://sample.com/path/path` becomes a clean "Sample Link" that
    still opens the real address. Also works the other direction:
    typing a plain URL still auto-links it with no special syntax
    needed, exactly as before.
    - **Where this applies**: document/checklist instructions and
      comments, on the admin side, with the edit pencil showing only
      on **staff-authored** content — never on client-authored
      comments, and never anywhere in the client portal at all (clients
      see clean rendered links, no editing UI). This is enforced in
      two places, not just one: the pencil is never rendered for
      client content in the first place, and the backend endpoint
      (`api/update_link_text.php`) independently re-checks a comment's
      `author_type = 'admin'` before allowing any edit — never trusting
      the frontend alone for something like this.
    - **Storage**: custom-labeled links are stored as
      `[display text](url)` directly inside the existing `comment_text`
      / `instructions` text fields — a plain, well-established markdown
      convention, not a new column or separate table. `linkifyText()`
      (generalized from the old `linkifyComment()`, which now just
      calls it) parses both this format and plain raw URLs into safe
      HTML, with the same escape-first-then-detect-links ordering as
      before, so it's structurally impossible to inject raw HTML/script
      through either a comment or an instructions field.
    - **Tested against the same rigor as the original linkify feature**:
      9 cases covering plain text, both link formats, and several real
      XSS attempts (a `<script>` tag, an attribute-breakout attempt via
      the markdown label, a `javascript:` pseudo-URL) — all came back
      correctly neutralized.
    - **Two real bugs caught and fixed while building this**, worth
      knowing about:
      1. The pencil button's click was bubbling up to the
         "click outside closes the popup" listener on the same click,
         closing the popup immediately after it opened. Fixed with
         `event.stopPropagation()`, the same pattern already used
         elsewhere in this codebase for the other dropdown-style UI
         (Client No., "Request a checklist").
      2. The backend's find-and-replace initially used plain
         `preg_replace()` with a string replacement — which
         interprets `$1`/`\1`-style sequences in the replacement as
         regex backreferences. A display text containing a literal
         dollar sign (e.g. "Cost is $500") silently corrupted into
         garbage (`\0`) because of this. Caught it with a direct test,
         not by inspection, and fixed by switching to
         `preg_replace_callback()`, whose return value is used as a
         literal string with no special-character interpretation at
         all — confirmed the exact failing case now round-trips
         correctly, alongside four other realistic cases (a URL full of
         regex-special characters, replacing an already-custom-labeled
         link, removing a link entirely, and the "old text no longer
         found" failure path).
57. **Staff can delete their own comments.** A trash icon appears next
    to the timestamp on staff-authored comments (never on client
    comments), with a confirm dialog before it actually deletes. Same
    authorization boundary as editable links, enforced the same way —
    checked server-side in `api/delete_comment.php`
    (`author_type = 'admin'` required), not just hidden from the UI for
    client comments. A freshly-posted comment gets its delete button
    immediately, no page reload needed — required
    `api/add_comment_admin.php` to start returning the new comment's
    real ID, which it didn't before.
58. **Live-while-typing link editing** — pasting a URL into a comment,
    or document/checklist instructions, now turns it into a real
    styled, hoverable-and-editable link **immediately**, before the
    surrounding text is even submitted — not just after, like the
    original editable-links feature. Built as a `contenteditable`
    stand-in that visually replaces the `<textarea>` (`.live-link-editor`
    in `css/style.css`), while the actual `<textarea>` stays in the DOM
    (hidden) as the real source of truth for form submission — its
    value is kept in sync on every change, so none of the existing
    comment-posting/checklist-sending/document-request code needed to
    change at all; it just keeps reading `.value` exactly like before.
    - **Deliberately conservative about when it triggers**: on paste,
      and as a fallback on blur (leaving the field) — not on every
      keystroke. Continuously rebuilding parts of a `contenteditable`
      while someone is actively typing is a well-known way to fight the
      browser over cursor position; restricting it to paste (a
      well-defined insertion point) and blur (focus is already leaving,
      so there's no cursor position left to protect) avoids that
      entire class of bug rather than trying to work around it.
    - **Link spans are "atomic"** (`contenteditable="false"` on each
      one) — the standard technique rich editors use (e.g. Gmail's
      recipient chips) so Backspace removes a whole link in one press
      rather than eating into it character by character.
    - **Tested the actual mechanism**, not just the idea: installed a
      real `jsdom` DOM implementation and ran the literal functions
      extracted from `ui-kit.js` (not reimplemented copies) against it
      — confirmed paste-URL detection correctly distinguishes a bare
      URL from a URL embedded in a longer sentence, confirmed
      plain-text and multi-line content serializes back correctly, and
      confirmed the full loop end-to-end: simulated pasting a URL,
      simulated the edit popup changing its label, took the exact
      resulting serialized string, and fed it through the real PHP
      `linkifyText()` — it rendered back out as a clean clickable
      "Sample Link," exactly matching what typing the same thing and
      submitting it for real would produce.
    - **One known, honest limitation**: a checklist item's instructions
      field can come pre-filled from a case-type template. If that
      template text ever contains an *existing* `[text](url)` link
      (unlikely in practice — these are normally plain instructional
      text), it shows as literal bracket text on load rather than a
      rendered link, until edited again. Not fixed in this pass — flagged
      rather than glossed over.
59. **Processor notifications rearchitected again — real-time and
    genuinely threaded**, replacing the daily-batched digest from a few
    iterations ago. Two distinct changes bundled together, both from the
    same request:
    - **Real-time**: a comment or upload now emails the assigned
      processor immediately, not queued for the next cron run. The
      `processor_notification_queue`-based system (`queueProcessorNotification()`,
      `sendProcessorDailyDigests()`, `sendProcessorDigestEmail()`) is
      gone, replaced by `notifyProcessorRealtime()`, called directly from
      `api/upload_document.php` and `api/add_comment_client.php`.
      `cron/daily_digest.php` no longer touches processors at all — it
      only handles the client-facing digest now, which is unaffected by
      any of this.
    - **Real threading**: every notification about the same client, to
      the same processor, is now sent as an actual threaded reply —
      proper `Message-ID` / `In-Reply-To` / `References` headers, not
      just a consistent subject line and hoping Gmail's heuristic
      groups them (which was the honest caveat given the last time this
      was shown — a real limitation, now actually addressed). A new
      `processor_client_threads` table stores the root `Message-ID` for
      each processor+client pairing the first time they have any
      activity; every later notification for that same pairing replies
      to that same root, so real mail clients group them into one
      ongoing conversation per client — not per processor, and not one
      giant combined thread mixing every client together. Threading
      headers are wired through all three send tiers (SMTP/PHPMailer,
      Brevo's API, and the plain `mail()` fallback), not just the
      primary one, so it keeps working even if a deployment ends up on
      a different tier than expected.
    - **Tested the actual mechanism**, not just the idea: ran the real
      extracted functions (not reimplemented copies) against a real
      SQLite database through 4 sequential events — confirmed a brand
      new client+processor pairing correctly starts a fresh thread (no
      `In-Reply-To`), a second event for the *same* client correctly
      replies to that exact root, a *different* client correctly gets
      its own entirely separate thread, and a *third* event for the
      first client still correctly threads back to the original root
      rather than drifting to whatever the second email's ID was.
      Separately tested the race-condition fallback in
      `getOrCreateProcessorClientThread()` (two near-simultaneous first
      events for a brand-new pairing hitting the table's unique
      constraint) — confirmed it recovers the actual saved thread
      instead of erroring out.
    - **Content simplified accordingly**: since each email now covers
      exactly one event instead of a batched list, the layout dropped
      the numbered-list structure from the old digest — just the
      client's name, what happened, when, and the detailed summary
      underneath, the same per-entry format as before minus the
      "several entries in one email" wrapper that no longer applies.
60. **Removed the "View" (eye icon) button** from every uploaded file
    row, in both the admin client page and the client portal — checked
    the whole codebase for every occurrence of this specific button
    (not just the two obvious spots) and confirmed there were exactly
    two, both now removed. The file's own name is still clickable to
    open it directly — that's a separate, pre-existing interaction and
    wasn't part of what was asked to be removed, so it stayed. Also
    confirmed the accepted-image auto-convert-to-PDF-and-compress-to-Dropbox
    feature (from much earlier in this project) is still fully in place
    and working exactly as before — nothing needed rebuilding there,
    just verified rather than assumed.
61. **Favicon added to every page**, both admin and portal — added a
    `<link rel="icon">` referencing a new shared `images/` folder
    (following the exact same real-file-in-one-place,
    symlinked-into-both-subdomains pattern this project already uses
    for `css/`, `js/`, and `api/` — see "Deploying to Hostinger" for the
    updated symlink commands), with the same cache-busting mechanism
    already used for the stylesheet and JS files
    (`assetVersion()`, real file modification time as the version, so a
    future logo replacement shows up immediately rather than being
    stuck behind a cached copy). Checked every single page in both
    `admin/` and `portal/` individually (10 total) and confirmed the
    link landed exactly once on each, including the two login pages,
    which have a slightly different structure than the rest and were
    worth double-checking specifically rather than assuming the bulk
    edit caught them correctly too. Tested `assetVersion()` against a
    real placeholder file at the exact real-world path to confirm the
    version number tracks the file's actual modification time
    precisely, not just a rough guess.
62. **Dashboard reverted back to its original form**, per explicit
    request — the agency-level KPI/escalations/workload/activity-feed
    redesign from the previous session is fully undone: `admin/dashboard.php`
    is back to the original per-status stat cards, "Needs your review,"
    "Recently added clients," and "Recent uploads" sections. The
    `visa_expiry_date` column and its Edit Client field were removed
    along with it, back to the pre-redesign schema — reverted precisely
    using the exact original content from before that redesign, not
    reconstructed from memory.
63. **The "View" (eye icon) preview button is back**, on both the
    admin client page and the client portal — reversing its earlier
    removal, per explicit request. The backend behind it
    (`api/admin_download_file.php`, `api/client_download_file.php`) was
    never actually touched when the button was removed before, so this
    is purely restoring the frontend `<a>` element; the correct
    inline-viewing logic underneath (serving with
    `Content-Disposition: inline` instead of `attachment`, so it opens
    in the browser tab rather than downloading) was already there and
    still works exactly as before.
64. **Fixed a real cross-browser bug**: previewing a PDF via the View
    button was working correctly on Windows but silently forcing a
    download instead on Mac (Safari), while images previewed fine on
    both. Root cause (most likely, reasoned from well-documented
    cross-browser PDF behavior — genuinely not something I could
    confirm by testing actual Safari myself from this environment):
    Safari's built-in PDF renderer is markedly stricter than Chrome's
    PDF.js about the declared `Content-Length` exactly matching the
    real bytes sent — if server-level gzip compression were ever
    applied to this response after `Content-Length` was already set
    from the uncompressed file size, that mismatch alone is enough for
    a strict renderer to give up and fall back to downloading, while a
    lenient one still renders it fine regardless. Fixed by explicitly
    disabling output compression/buffering for both download endpoints
    (`apache_setenv('no-gzip', ...)`, `zlib.output_compression` off,
    clearing any active output buffers) so this mismatch can't happen
    regardless of server-level compression defaults, rather than trying
    to compute around it. Also removed the legacy
    `Content-Description: File Transfer` header for inline requests
    specifically — a signal from old force-download PHP tutorials with
    no purpose for something meant to display inline, and a safe,
    no-downside removal even without certainty it was the actual cause.
    Verified with a real HTTP-level test (PHP's built-in server +
    curl, not just reading the code): confirmed `Content-Length`
    exactly matches actual bytes received in both inline and download
    modes, confirmed `Content-Description` is now correctly absent from
    inline responses while still present for real downloads, and
    confirmed no stray output or PHP errors corrupt the response
    (checked every file in the require chain for BOM/trailing-whitespace
    issues too, and found none).

    **Worth knowing plainly: DOCX files can't be inline-previewed in
    *any* browser** — Safari, Chrome, Firefox, Edge — none of them have
    a built-in Word document renderer. That part isn't a bug this (or
    any) fix can address; a DOCX file will always download rather than
    open in-tab, on every platform, and that's a hard limitation of
    browsers themselves, not this app.
65. **Staff can now attach a sample file directly from the comment
    box** — a paperclip icon at the left end of the comment input opens
    a file picker; the attached filename shows as a small removable
    chip before posting. A comment now only needs *either* text or an
    attached file, not both — sharing a sample document with no message
    is fine. The file is stored through the exact same
    `storeUploadedDocument()` path every other upload in this app uses,
    so it shows up in the regular file list for that requirement like
    any other upload; if no comment text was given, the comment itself
    records what was attached ("📎 Attached: filename.pdf") so there's
    still a clear entry in the thread.
66. **Staff can also attach a sample file per checklist item**, via a
    paperclip icon next to each item's "Instructions / notes" label
    when building a checklist before sending it. This needed a real
    backend change, not just a UI addition: checklist submission
    switched from a single JSON body to multipart `FormData`
    (`items[i][doc_name]`, `items[i][instructions]`,
    `items[i][attachment]`, ...) — JSON can't carry binary file data,
    so this had to change to support per-item attachments at all.
    `api/add_document_checklist.php` was rewritten to match: PHP's
    `$_FILES` for array-style file inputs comes back in an unusual
    "transposed" shape (`$_FILES['items']['name'][0]['attachment']`,
    not a clean per-item structure), so a small normalization step
    reshapes each index back into the ordinary single-file shape the
    existing `validateUploadedDocument()`/`storeUploadedDocument()`
    helpers already expect — reused unchanged rather than duplicated.
    Any item sent with an attached file is created with status
    `received` (a file's already there to review) instead of the usual
    `pending`, with a matching `status_log` entry, exactly mirroring
    how the single-document "Request a document" modal's existing
    optional-attachment behavior already works. Tested end-to-end with
    a real direct execution of the actual production endpoint (not a
    reimplementation) simulating a realistic two-item submission, one
    with a file and one without — confirmed the file-bearing item
    correctly landed as `received` with its file properly linked and a
    correct `status_log` row, the file-less item correctly stayed
    `pending`, and the attached file was correctly saved to disk with
    the right name and size.

## Deploying to Hostinger (split subdomains)

This setup — `admin.duqueimmigration.ca` and `portal.duqueimmigration.ca`
as two separate subdomains sharing one database — needs a bit of care,
since each subdomain can only serve files from its own document root over
HTTP, even though `admin/` and `portal/` need to share `css/`, `js/`,
`api/`, and the uploaded files themselves. Here's the setup, using SSH
(you confirmed you have access):

**1. Upload the whole project to one place first.**
Don't split it up yet — upload the entire `duque-immigration-admin`
folder (as-is, with `admin/`, `portal/`, `css/`, `js/`, `api/`,
`includes/`, `config.php`, everything) to your home directory over
SFTP/File Manager, e.g. so it ends up at `~/duque-immigration-admin/`.

**2. Point each subdomain's document root at the matching subfolder.**
In hPanel, when you edit each subdomain (Websites → your subdomain →
document root), set:
- `admin.duqueimmigration.ca` → `duque-immigration-admin/admin`
- `portal.duqueimmigration.ca` → `duque-immigration-admin/portal`

(Not the project root — the `admin/` and `portal/` subfolders specifically.)

**3. SSH in and symlink the shared folders into both.**
```bash
cd ~/duque-immigration-admin/admin
ln -s ../css css
ln -s ../js js
ln -s ../api api
ln -s ../images images

cd ~/duque-immigration-admin/portal
ln -s ../css css
ln -s ../js js
ln -s ../api api
ln -s ../images images
```
This makes `css/`, `js/`, `api/`, and `images/` appear to exist inside
both `admin/` and `portal/` (so `https://admin.duqueimmigration.ca/css/style.css`
resolves correctly) while there's still only one real copy of each file —
edit `api/upload_document.php` once, both subdomains see the change
immediately, no syncing needed.

**Uploading the logo/favicon**: put the actual file at
`~/duque-immigration-admin/images/duqueimmigration_logo.png` (create the
`images/` folder if it doesn't exist yet, before running the symlink
commands above) — one real copy, same as `css/`/`js/`/`api/`, visible to
both subdomains through the symlink. Every page already references it
via `<link rel="icon">` with cache-busting (`assetVersion()`, the same
mechanism `style.css` and the JS files use) — nothing else to wire up,
just get the file to that exact path.

`config.php` and `includes/` do **not** need symlinks — PHP reads those
straight from disk regardless of which subdomain triggered the request,
since `require_once __DIR__ . '/../config.php'` is a filesystem path, not
a URL.

**4. Create the shared uploads folder.**
```bash
mkdir ~/duque_uploads
```
This sits outside both document roots on purpose — files are only ever
served through the download/view API endpoints now (never linked to
directly), so it doesn't need to be web-accessible at all. This is
actually a bit more secure than the original single-domain setup.

**5. Fill in `config.php` — and remember there are two copies.**
Since `admin/` and `portal/` are separate document roots, you need the
*same* `config.php` content sitting in the project root (both subdomains'
PHP reaches it via `__DIR__ . '/../config.php'` from their respective
folder, so **one copy at the project root is actually enough** — you
don't need to duplicate it, thanks to step 1–2 keeping everything under
one shared project folder). Just fill in the Hostinger branch:
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — from hPanel → Databases
- `UPLOAD_DIR` — the absolute path from step 4, e.g.
  `/home/uXXXXXXXX/duque_uploads` (find your real `uXXXXXXXX` username by
  running `pwd` right after SSHing in)

**6. Import the schema** (phpMyAdmin, same as any other environment) and
you should be able to log in at both subdomains.

**Quick troubleshooting:**
- CSS/JS missing on either subdomain → the symlinks in step 3 either
  weren't created or point the wrong direction; `ls -la` inside `admin/`
  and `portal/` should show `css`, `js`, `api` as `->` symlink entries.
- A file uploaded on one subdomain doesn't show up on the other → double
  check `UPLOAD_DIR` really is the exact same absolute path — it's easy
  to typo the username.
- "500 error" only on symlinked assets → some hosts disable
  `FollowSymLinks` in Apache by default; contact Hostinger support or ask
  about enabling it for your account if this happens.

## Setting up the daily digest cron job

Most client notifications — new documents requested, checklists sent,
documents removed, staff comments, status changes — are **queued**
instead of emailed immediately, then rolled into one "daily update"
email per client, once every 24 hours. The **welcome email** (sent on
client creation, with their login credentials) is the one deliberate
exception — that still sends immediately, since there's no reason to
make someone wait a day for their own login details. The
manually-triggered "Send reminder" button is also unaffected — it's a
deliberate on-demand action, not a passive event, so it stays immediate.

**Processors are not part of this at all** — they get a real-time,
threaded email the moment a client uploads a file or posts a comment
(see `notifyProcessorRealtime()` in `includes/mailer.php`), with no
queue, no daily batch, and no manual "send now" button (there's nothing
to trigger — it's already sent by the time you'd click it).

**This needs one thing you have to set up yourself**: a scheduled cron
job that actually runs the digest script. Nothing will send until this
is configured — client notifications will just sit in the queue
indefinitely (processor notifications are unaffected either way, since
they don't use this queue/cron system at all).

1. In hPanel, search **"Cron Jobs"**.
2. Create a new cron job:
   - **Command**: `php /home/u612178738/duque-immigration-admin/cron/daily_digest.php`
   - **Schedule**: once every 24 hours, at whatever time makes sense for
     your clients' timezone (e.g. once daily at 8:00 AM).
3. Save it.

This script only handles the client-facing digest now — processor
notifications need nothing scheduled at all, they just happen as events occur.

The script only runs from the command line (`PHP_SAPI !== 'cli'` is
checked and blocks anything else) — it's not reachable from a browser at
all, so there's no need for a secret token or login the way a normal
web-facing page would need.

### Testing it

Run it manually over SSH any time to send whatever's currently queued,
without waiting for the schedule:
```bash
php ~/duque-immigration-admin/cron/daily_digest.php
```
It prints a small summary for both the client and processor runs
(notified counts, events sent, any failures) and exits with a non-zero
code if anything failed, so cron logs or any monitoring you set up later
can flag it. I tested the actual queueing → grouping → sending →
marking-as-sent logic end-to-end against a real database before shipping
this (not just checked it parses) — for both clients and processors
separately — confirmed multiple events for the same person correctly
combine into one email, people with nothing queued get skipped entirely
(no "nothing happened" noise), and a failed send correctly leaves events
queued for the next run rather than silently losing them.

## Setting up outgoing email

`includes/mailer.php` tries **three tiers**, in order, falling through
automatically:

1. **Authenticated SMTP** via a dedicated `notification@duqueimmigration.ca`
   mailbox (not `donotreply@` — deliberately a separate mailbox created
   just for this, so nothing else depends on its password; safe to
   reset any time).
2. **Brevo's transactional email API**, if configured instead/as well.
3. **Plain PHP `mail()`** as a last resort — works, but less reliably
   delivered to external providers.

### Why this matters

PHP's raw `mail()` sends unauthenticated, and on shared hosting the
actual envelope sender often doesn't match your domain even with a
correct `From:` header — which fails SPF checks at the receiving end,
silently, even when your domain's SPF/DKIM/DMARC records are all
correctly configured. This is exactly what was happening here:
Hostinger's own deliverability checker showed all green, `mail()`
returned `true`, and mail-tester.com still reported "we didn't get your
email." Logging in as a real mailbox via SMTP avoids that mismatch
entirely, since the receiving server can verify the connection
genuinely came from that account.

### Configuration

In `config.php`, fill in the SMTP block:
```php
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_USERNAME', 'notification@duqueimmigration.ca');
define('SMTP_PASSWORD', 'your-mailbox-password-here');
```

Find the exact host/port for your mailbox in hPanel → Emails →
`notification@duqueimmigration.ca` → **Connect Devices** — it'll show
under "Server settings" (Outgoing server / SMTP). Port `465` uses SSL;
if your mailbox instead shows port `587`, `mailer.php` automatically
switches to STARTTLS instead — no code change needed, just update
`SMTP_PORT`.

**PHPMailer** (the standard library for this) is vendored directly in
`includes/PHPMailer/` — no Composer needed, just three PHP files
(`PHPMailer.php`, `SMTP.php`, `Exception.php`) fetched straight from
PHPMailer's GitHub repo and included directly. Every call site elsewhere
in the app (`notifyClientWelcome()`, `notifyProcessorOfUpload()`, etc.)
is unaffected — they all still just call `sendMail()` the same way.

**Important — this is deliberately crash-proof now.** An earlier version
of this file required the PHPMailer files unconditionally at the top,
and when that folder didn't make it to the server in one deployment,
*every* page that sends mail crashed with a fatal, uncatchable error —
including things you wouldn't expect, like "Send Reminder" showing a
generic "Network error." This version checks the PHPMailer files
actually exist on disk before requiring them (`smtpIsConfigured()`), and
falls through to Brevo or plain `mail()` instead of crashing if they're
ever missing again.

### Testing it

I verified PHPMailer itself works correctly (loads, builds valid MIME
messages, uses the correct `SMTPSecure` value for port 465 — I actually
caught and fixed a wrong value here before shipping) and that
`sendMail()` fails gracefully rather than crashing if the SMTP
connection can't be reached — but I could not test an actual end-to-end
send from where I built this, since outbound SMTP to Hostinger's mail
servers isn't reachable from my environment. **Test this for real**
before relying on it: create a test client and confirm the welcome
email actually arrives (check spam too), or run a quick manual test:
```bash
php -r '
require "includes/mailer.php";
var_dump(sendMail("your-real-email@gmail.com", "Test", "Test", "Testing SMTP delivery."));
'
```
If it returns `bool(false)`, the SMTP credentials or host/port are
likely wrong — double-check them against hPanel. If it returns `true`
but nothing arrives, check spam first, then re-run the mail-tester.com
check from scratch (a `true` result now should also come with an actual
received message, unlike the old `mail()` behavior).

## Setting up Dropbox

1. Go to [dropbox.com/developers/apps](https://www.dropbox.com/developers/apps)
   and create a new app (Scoped access; "App folder" if you want it
   contained to one folder, or "Full Dropbox" if staff need it alongside
   your existing structure).
2. Under **Permissions**, enable: `files.content.write`,
   `files.content.read`, `files.metadata.write`, `files.metadata.read`,
   `sharing.write`, `sharing.read`.
3. Generate a **refresh token** (Dropbox's OAuth2 authorization-code flow
   with `token_access_type=offline`):
   - Visit `https://www.dropbox.com/oauth2/authorize?client_id=YOUR_APP_KEY&response_type=code&token_access_type=offline`,
     approve access, copy the code shown.
   - Exchange it for a refresh token. **On Windows, use PowerShell**
     (not Command Prompt — its quoting breaks the request):
     ```powershell
     $response = Invoke-RestMethod -Uri "https://api.dropboxapi.com/oauth2/token" -Method Post -Body @{
         code = "THE_CODE_YOU_GOT"
         grant_type = "authorization_code"
         client_id = "YOUR_APP_KEY"
         client_secret = "YOUR_APP_SECRET"
     }
     $response.refresh_token
     ```
     On macOS/Linux, the equivalent is:
     ```bash
     curl https://api.dropboxapi.com/oauth2/token \
       -d code=THE_CODE_YOU_GOT \
       -d grant_type=authorization_code \
       -d client_id=YOUR_APP_KEY \
       -d client_secret=YOUR_APP_SECRET
     ```
   - Copy only the raw `refresh_token` value into `config.php` — no
     surrounding quotes, no extra whitespace. The authorization code from
     the first step is single-use and expires quickly, so if this fails,
     go back and get a fresh code rather than reusing the old one.
4. Paste `DROPBOX_APP_KEY`, `DROPBOX_APP_SECRET`, and
   `DROPBOX_REFRESH_TOKEN` into `config.php`. `DROPBOX_ROOT_FOLDER`
   defaults to `/1. MASTER LIST` — change it if your root folder is
   named differently.

### How the folder structure works

The app mirrors your existing Master List layout automatically:

```
1. MASTER LIST/2026/8. AUGUST 2026/1. JANE DOE - WP NEW/
1. MASTER LIST/2026/8. AUGUST 2026/1. JANE DOE - WP NEW/PASSPORT BIO PAGE/
1. MASTER LIST/2026/8. AUGUST 2026/2. JOHN SMITH - SPOUSAL SPONSORSHIP/
1. MASTER LIST/2026/8. AUGUST 2026/2. JOHN SMITH - SPOUSAL SPONSORSHIP/MARRIAGE CERTIFICATE/
```

- **Year and month folders are real, not placeholders** — "2026" and
  "8. AUGUST 2026" are the actual current year/month (number, period,
  space, month name uppercase, space, year again — no leading zero on
  single-digit months), created the first time they're needed. You
  don't pre-create these.
- **Client folders are named "{Client No.}. {FULL NAME} - {CASE
  TYPE}"** — e.g. "1. JANE DOE - WP NEW" (name and case type are
  uppercased; if a client has no case type set, it's just "{Client
  No.}. {FULL NAME}" with nothing trailing). If you leave the "Client
  No." field blank when adding a client, the app counts existing
  client folders in the current month and assigns the next number
  automatically (starting at 1 each month).
- **No duplicate folders for returning clients.** If a Client No.
  already belongs to another client in this system with a folder on
  file, that exact same folder is reused instead of creating a new one
  — useful when the same person comes back for a second case.
- **Two different clients that would produce the same folder name won't
  get merged.** If "1. JANE DOE - WP NEW" already exists this month for
  an unrelated client, Dropbox automatically names the new one
  "1. JANE DOE - WP NEW (1)" instead of dumping the new client's files
  into the existing folder.
- **Each accepted document gets its own subfolder**, named after that
  specific checklist requirement (uppercased) — not one shared folder
  for everything accepted. The first time a document is marked
  **Accepted**, a subfolder matching its name (e.g. `MARRIAGE
  CERTIFICATE`) is created inside the client's folder (if it doesn't
  already exist), and the file is copied there. If the same client has
  ten different accepted documents, that's ten separate subfolders,
  each easy to find by what it actually is.

### Linking to an existing folder — path or share link, either works

The "Existing Dropbox folder path" field (Add Client and Edit Details)
accepts **either**:
- The exact folder path, e.g. `/1. MASTER LIST/2026/8. AUGUST 2026/1. JANE DOE - WP NEW`
- A Dropbox share link (the URL from "Copy link"), e.g.
  `https://www.dropbox.com/scl/fo/abc123/xyz?rlkey=...&dl=0`

Whichever you paste, the app resolves it to the real folder and
validates it actually exists before saving anything — a link/path that
can't be found blocks the whole action rather than silently succeeding
with a broken link (or, worse, creating a stray new folder). This only
works for links the connected Dropbox account already has access to,
which is the normal case here (staff pasting a link from their own
team's Dropbox).

This only recognizes a returning client's existing folder *automatically*
if that client was previously added through this app (so it's on file in
this system's database via their Client No.). If someone already has a
folder from before this app was in use, paste its path or share link
into "Existing Dropbox folder path" when adding them.

5. Test it: add a client, leave Client No. blank, and confirm a folder
   named "1. {their name}" (or the next available number) appears under
   `1. MASTER LIST/2026/{current month}/` in Dropbox. Then upload a test
   file as that client and mark it Accepted — confirm it shows up inside
   a subfolder matching that document's own name (e.g. `MARRIAGE
   CERTIFICATE`), not one shared folder. Also worth testing: paste
   a real Dropbox share link (not a path) into "Existing Dropbox folder
   path" on a different client, and confirm it resolves and links
   correctly.


**I wasn't able to test this integration against a real Dropbox account**
from where I built it — I did verify the actual naming/path-building
logic and the URL-vs-path resolution logic against a mocked API layer
(confirmed the month path builds as "2026/8. AUGUST 2026", client folders
build as "{number}. {name}", and both a real path and a Dropbox share
link correctly resolve to the same folder), but the live API calls
themselves need your confirmation. Every Dropbox call fails soft with a
specific error message (shown via `alert()` in the browser) rather than
failing silently, so if something's
off it should point at what's wrong — auth, permissions, or a path issue.

A few other behaviors worth knowing:
- If Dropbox isn't configured yet (the placeholder values are still in
  `config.php`), the app just skips Dropbox silently — clients, uploads,
  and status changes all still work normally.
- Only files already uploaded to a field at the moment it's marked
  Accepted get copied to Dropbox. If more files are added later,
  re-toggling the status (e.g. to Received and back to Accepted) will
  copy those too.
- The month-folder listing call reads only the first page of results
  (no pagination) — fine for realistic month sizes, but if a single
  month's folder ever grew past a couple thousand entries, the Client
  No. count and duplicate-check would need pagination added.

## ⚠️ About the visible password column

Showing a client's actual password means the app stores it in a
readable form (`clients.plain_password`), separate from the secure
hash (`password_hash`) that's actually used to verify logins. This is
weaker than typical practice — if your database were ever exposed,
those passwords would be readable directly, and if any client reuses
that password elsewhere, that account would be at risk too. Since this
is an internal tool, the practical mitigations are: keep the number of
staff/admin accounts small, use a strong unique password for each admin
account, and treat database backups/exports as sensitive. If this ever
needs to hold real client-identifying data at scale, consider dropping
the visible-password feature in favor of a "send password reset link"
flow instead.

## File structure

```
config.php                  Environment-aware DB config (Laragon/InfinityFree)
sql/schema.sql               Database schema + seed admin account
includes/db.php              PDO connection
includes/functions.php       Shared helpers (status labels, uploader labels, escaping, etc.)
includes/admin_auth.php      requireAdmin() session guard (own session cookie)
includes/client_auth.php     requireClient() session guard (own session cookie)
includes/checklist_templates.php  The 8 predefined document checklists — edit here to customize
includes/mailer.php          Outgoing email — sends via authenticated SMTP (PHPMailer)
includes/PHPMailer/          Vendored PHPMailer library (no Composer) — do not edit these 3 files
cron/daily_digest.php        Sends queued client notifications as one email per client — needs a cron job, see README
includes/dropbox.php         Dropbox API v2 client (folders, shared links, uploads, existing-path linking)
includes/pdf_convert.php     Pure-PHP JPG/PNG → PDF conversion (no external tools needed)

admin/login.php               Staff login
admin/dashboard.php           Overview: stat boxes, needs-review queue, recent uploads
admin/clients.php             Full client list
admin/client.php              Per-client checklist, status, uploads, comments
admin/profile.php             Staff's own name/password
admin/processors.php          Manage the processor directory (name + email)
admin/_sidebar.php            Shared admin nav (Dashboard, Clients, Processors, My Profile, Logout)

portal/login.php              Client login
portal/dashboard.php          Overview: stat boxes, needs-attention list, recent comments
portal/documents.php          Full checklist + upload + comments
portal/profile.php            Client's own phone/password
portal/_sidebar.php           Shared client nav (Dashboard, My Profile, My Documents, Logout)

api/add_client.php            Admin: create client
api/add_document_request.php  Admin: open a document field (optionally with a file attached)
api/add_document_checklist.php  Admin: send an edited multi-document checklist at once
api/update_status.php         Admin: change a document's status
api/delete_document_request.php  Admin: remove a field
api/add_comment_admin.php     Admin: post a comment
api/add_comment_client.php    Client: post a comment
api/admin_upload_document.php Admin: attach a file on the client's behalf
api/upload_document.php       Client: upload a file
api/admin_download_file.php   Admin: force-download a file
api/client_download_file.php  Client: force-download own-case file
api/admin_delete_file.php     Admin: delete any file
api/client_delete_file.php    Client: delete own uploads only
api/admin_reset_client_password.php  Admin: set/replace a client's password
api/update_admin_profile.php  Admin: update own name / password
api/update_client_profile.php Client: update own phone / password
api/add_processor.php         Admin: add a processor
api/delete_processor.php      Admin: remove a processor
api/update_client_details.php Admin: edit case type/phone/reference #/processor (provisions or links a Dropbox folder if needed)
api/send_reminder.php         Admin: email a client about pending/needs-revision documents
api/admin_download_selected.php  Admin: download multiple selected files (per field) as one ZIP
api/admin_download_all_for_client.php  Admin: download every file for a client as one ZIP, organized by field

uploads/                      Stored client files (protected .htaccess)
css/style.css                 Shared design system
js/ui-kit.js                  Shared: toast, showConfirm()/showNotice() dialogs, modal helpers, mobile nav toggle
js/admin.js / js/portal.js    Page-specific front-end behaviour
```

## Admin and client sessions are fully separate

The admin panel and client portal use different session cookie names
(`duque_admin_session` vs `duque_client_session`), so you can be logged
into both at once in the same browser (e.g. testing) without one
overwriting the other — every comment, upload, and status change is
tied to whichever account is actually authenticated for that panel.

## Notes / things to revisit

- Allowed upload types and the upload size cap (30 MB) live in `config.php`
  (`ALLOWED_EXTENSIONS`, `MAX_UPLOAD_BYTES`).
- There's no email notification wired up yet (matches DWC, where Brevo
  is dormant pending a meeting) — this would be the natural next step if
  you want clients/staff pinged on status changes.
- No "forgot password" flow yet for either portal — worth adding once
  you're past pilot use.
