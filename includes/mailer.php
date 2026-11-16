<?php
/**
 * includes/mailer.php
 * Small mail-sending layer with three tiers, tried in order:
 *   1. Authenticated SMTP (PHPMailer, vendored in includes/PHPMailer/ —
 *      no Composer needed) via notification@duqueimmigration.ca — a
 *      dedicated mailbox created specifically for this, so nothing else
 *      depends on its password.
 *   2. Brevo's transactional email API, if configured instead/as well.
 *   3. Plain PHP mail() as a last resort — works, but less reliably
 *      delivered to external providers (Gmail etc.) since it sends
 *      unauthenticated; see the long comment lower in this file for why
 *      that matters.
 *
 * The PHPMailer files are only require()'d if they actually exist on
 * disk — this is deliberate: an earlier version of this file required
 * them unconditionally, and when the vendor folder didn't make it to the
 * server in one deployment, EVERY page that sends mail (including ones
 * with no visible connection to email, like "Send reminder") crashed
 * with an unrecoverable fatal error instead of failing gracefully. This
 * version can never do that again — if the PHPMailer files are missing,
 * it just quietly falls through to the next tier.
 */

require_once __DIR__ . '/../config.php';

$__phpMailerFilesExist = file_exists(__DIR__ . '/PHPMailer/PHPMailer.php')
    && file_exists(__DIR__ . '/PHPMailer/SMTP.php')
    && file_exists(__DIR__ . '/PHPMailer/Exception.php');

if ($__phpMailerFilesExist) {
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';
}

function smtpIsConfigured(): bool
{
    global $__phpMailerFilesExist;
    return $__phpMailerFilesExist
        && defined('SMTP_HOST') && SMTP_HOST !== ''
        && defined('SMTP_USERNAME') && SMTP_USERNAME !== ''
        && defined('SMTP_PASSWORD') && SMTP_PASSWORD !== '' && SMTP_PASSWORD !== 'your-mailbox-password-here';
}

function brevoIsConfigured(): bool
{
    return defined('BREVO_API_KEY') && BREVO_API_KEY !== '' && BREVO_API_KEY !== 'your-brevo-api-key-here';
}

/**
 * Sends an email. Pass $htmlBody to send a styled HTML email (with a
 * button, etc.) — $textBody is still required as a plain-text fallback
 * for mail clients that don't render HTML. If $htmlBody is omitted, sends
 * plain text only. $ccEmails is an optional list of additional addresses
 * to CC (e.g. a client's spouse/agent, set on their profile) — invalid
 * entries are silently dropped rather than failing the whole send.
 * Returns true/false; never throws, so a mail failure never blocks the
 * action (upload, status change, etc.) that triggered it.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $textBody, ?string $htmlBody = null, array $ccEmails = [], ?string $messageId = null, ?string $inReplyTo = null): bool
{
    if ($toEmail === '') {
        return false;
    }

    $ccEmails = array_values(array_filter($ccEmails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));

    if (smtpIsConfigured()) {
        return sendMailViaSmtp($toEmail, $toName, $subject, $textBody, $htmlBody, $ccEmails, $messageId, $inReplyTo);
    }
    if (brevoIsConfigured()) {
        return sendMailViaBrevo($toEmail, $toName, $subject, $textBody, $htmlBody, $ccEmails, $messageId, $inReplyTo);
    }
    return sendMailViaPhpMail($toEmail, $toName, $subject, $textBody, $htmlBody, $ccEmails, $messageId, $inReplyTo);
}

/**
 * Splits a comma-separated string of emails (as stored on a client's
 * profile) into a clean array — trims whitespace, drops anything that
 * isn't a valid email address, drops duplicates.
 */
function parseCcEmails(?string $raw): array
{
    if (!$raw) {
        return [];
    }
    // Same splitting as when the field is saved (older rows may still use
    // other separators); invalid entries are just skipped here.
    require_once __DIR__ . '/functions.php';
    return splitCcEmails($raw)[0];
}

/**
 * Sends via authenticated SMTP (PHPMailer) using the dedicated
 * notification@duqueimmigration.ca mailbox. This is the tier that
 * actually fixes the original deliverability problem: PHP's raw mail()
 * sends unauthenticated, and on shared hosting the actual envelope
 * sender often doesn't match your domain even with a correct "From"
 * header — which fails SPF checks at the receiving end even when your
 * domain's SPF/DKIM/DMARC records are all correctly set up (confirmed
 * they are here, via Hostinger's own deliverability checker). Logging
 * in as a real mailbox avoids that mismatch entirely.
 */
function sendMailViaSmtp(string $toEmail, string $toName, string $subject, string $textBody, ?string $htmlBody, array $ccEmails = [], ?string $messageId = null, ?string $inReplyTo = null): bool
{
    $mailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
    $exceptionClass = 'PHPMailer\\PHPMailer\\Exception';

    if (!class_exists($mailerClass)) {
        return sendMailViaBrevo($toEmail, $toName, $subject, $textBody, $htmlBody, $ccEmails, $messageId, $inReplyTo)
            ?: sendMailViaPhpMail($toEmail, $toName, $subject, $textBody, $htmlBody, $ccEmails, $messageId, $inReplyTo);
    }

    $mail = new $mailerClass(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = (int)SMTP_PORT === 465 ? 'ssl' : 'tls';
        $mail->CharSet    = 'UTF-8';

        // Sending "From" the same address we authenticate as keeps SPF/DKIM
        // alignment clean — mismatching them (e.g. authenticating as one
        // mailbox but claiming to send "from" a different one) can itself
        // cause deliverability problems.
        $mail->setFrom(SMTP_USERNAME, MAIL_FROM_NAME);
        $mail->addReplyTo(SMTP_USERNAME);
        $mail->addAddress($toEmail, $toName);
        foreach ($ccEmails as $cc) {
            $mail->addCC($cc);
        }

        // Real email threading (not just Gmail's subject-matching
        // heuristic) — used for processor notifications, where every
        // event for the same client should land as a reply within one
        // ongoing thread rather than a standalone email each time. If
        // $messageId is given, this email's own Message-ID header is set
        // to it explicitly (rather than left to PHPMailer's own
        // auto-generated one) so it can be referenced later; if
        // $inReplyTo is given, this email is marked as a reply within an
        // existing thread.
        if ($messageId !== null) {
            $mail->MessageID = $messageId;
        }
        if ($inReplyTo !== null) {
            $mail->addCustomHeader('In-Reply-To', $inReplyTo);
            $mail->addCustomHeader('References', $inReplyTo);
        }

        $mail->Subject = $subject;

        if ($htmlBody === null) {
            $mail->isHTML(false);
            $mail->Body = $textBody;
        } else {
            $mail->isHTML(true);
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;
        }

        return $mail->send();
    } catch (Throwable $e) {
        error_log('sendMailViaSmtp failed for ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends via Brevo's transactional email API. Returns true only on a real
 * 2xx success response — never assumes success just because the HTTP
 * request itself didn't error out.
 */
function sendMailViaBrevo(string $toEmail, string $toName, string $subject, string $textBody, ?string $htmlBody, array $ccEmails = [], ?string $messageId = null, ?string $inReplyTo = null): bool
{
    if (!brevoIsConfigured()) {
        return false;
    }

    $payload = [
        'sender' => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM_ADDRESS],
        'to' => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject' => $subject,
        'textContent' => $textBody,
    ];
    if ($htmlBody !== null) {
        $payload['htmlContent'] = $htmlBody;
    }
    if (count($ccEmails)) {
        $payload['cc'] = array_map(fn($e) => ['email' => $e], $ccEmails);
    }
    // Same real-threading purpose as the SMTP path — see the comment
    // there. Brevo's API accepts arbitrary extra headers via this field.
    $extraHeaders = [];
    if ($messageId !== null) {
        $extraHeaders['Message-ID'] = $messageId;
    }
    if ($inReplyTo !== null) {
        $extraHeaders['In-Reply-To'] = $inReplyTo;
        $extraHeaders['References'] = $inReplyTo;
    }
    if ($extraHeaders) {
        $payload['headers'] = $extraHeaders;
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $response !== false && $httpCode >= 200 && $httpCode < 300;
}

/**
 * Last-resort sender using PHP's built-in mail() — used automatically if
 * neither SMTP nor Brevo is configured. Known to be less reliable for
 * delivery to external providers (see the big comment at the top of this
 * file), but keeps the app functional rather than sending nothing.
 */
function sendMailViaPhpMail(string $toEmail, string $toName, string $subject, string $textBody, ?string $htmlBody, array $ccEmails = [], ?string $messageId = null, ?string $inReplyTo = null): bool
{
    $to = $toName !== '' ? ($toName . ' <' . $toEmail . '>') : $toEmail;
    $ccHeader = count($ccEmails) ? ["Cc: " . implode(', ', $ccEmails)] : [];
    $threadHeaders = [];
    if ($messageId !== null) {
        $threadHeaders[] = 'Message-ID: ' . $messageId;
    }
    if ($inReplyTo !== null) {
        $threadHeaders[] = 'In-Reply-To: ' . $inReplyTo;
        $threadHeaders[] = 'References: ' . $inReplyTo;
    }

    if ($htmlBody === null) {
        $headers = array_merge([
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
            'Reply-To: ' . MAIL_FROM_ADDRESS,
        ], $ccHeader, $threadHeaders, [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ]);
        try {
            return @mail($to, $subject, $textBody, implode("\r\n", $headers));
        } catch (Throwable $e) {
            error_log('sendMailViaPhpMail (plain text) failed for ' . $toEmail . ': ' . $e->getMessage());
            return false;
        }
    }

    $boundary = 'dqp_' . bin2hex(random_bytes(12));
    $headers = array_merge([
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
        'Reply-To: ' . MAIL_FROM_ADDRESS,
    ], $ccHeader, $threadHeaders, [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);
    $message = "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $textBody . "\r\n\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $htmlBody . "\r\n\r\n"
        . "--{$boundary}--";

    try {
        return @mail($to, $subject, $message, implode("\r\n", $headers));
    } catch (Throwable $e) {
        error_log('sendMailViaPhpMail (HTML) failed for ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Builds a branded HTML email with an optional button, kept deliberately
 * simple and table-based for compatibility across mail clients (Outlook
 * in particular renders modern CSS poorly, so this avoids flexbox/grid
 * and sticks to inline styles — email HTML can't reference an external
 * stylesheet, so these colors are hardcoded to match css/style.css's
 * brand palette rather than using CSS variables).
 */
function buildEmailHtml(string $greetingName, array $bodyParagraphs, ?string $buttonLabel = null, ?string $buttonUrl = null): string
{
    $paragraphsHtml = '';
    foreach ($bodyParagraphs as $p) {
        $paragraphsHtml .= '<p style="margin:0 0 16px;color:#1F1F23;font-size:14px;line-height:1.6;">' . $p . '</p>';
    }

    $buttonHtml = '';
    if ($buttonLabel && $buttonUrl) {
        $safeUrl = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8');
        $buttonHtml = '
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:8px;">
          <tr>
            <td style="background-color:#DD0000;border-radius:8px;">
              <a href="' . $safeUrl . '" target="_blank" rel="noopener"
                 style="display:inline-block;padding:12px 26px;color:#ffffff;font-weight:bold;
                        font-size:14px;text-decoration:none;font-family:Arial,Helvetica,sans-serif;">
                ' . $safeLabel . '
              </a>
            </td>
          </tr>
        </table>';
    }

    $safeName = htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background-color:#F6F6F7;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0"
               style="background-color:#ffffff;border-radius:12px;overflow:hidden;max-width:480px;width:100%;border-top:3px solid #DD0000;">
          <tr>
            <td style="background-color:#1B1B1D;padding:20px 28px;">
              <span style="color:#ffffff;font-size:16px;font-weight:bold;">Duque Immigration</span>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <p style="margin:0 0 16px;color:#1F1F23;font-size:14px;">Hi ' . $safeName . ',</p>
              ' . $paragraphsHtml . '
              ' . $buttonHtml . '
            </td>
          </tr>
          <tr>
            <td style="padding:16px 28px;background-color:#F6F6F7;">
              <span style="color:#6B6B70;font-size:12px;">&mdash; Duque Immigration Document Portal</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

/**
 * Turns a stored event_type into the clean action phrase shown in a
 * processor notification — e.g. 'comment' -> "commented on the portal".
 */
function processorEventActionPhrase(?string $eventType): string
{
    switch ($eventType) {
        case 'comment':
            return 'commented on the portal';
        case 'upload':
            return 'uploaded a file';
        case 'review_confirmed':
            return 'confirmed an application is correct';
        case 'review_flagged':
            return 'reported an issue with an application';
        case 'message':
            return 'sent a message on the portal';
        default:
            return 'made an update';
    }
}

/**
 * Formats "right now" (this is always used for a real-time notification,
 * not a stored past timestamp) as "Today at MM/DD/YYYY - HH:MM", always
 * in Winnipeg time — there's no JavaScript running in an inbox to
 * convert it to each reader's own timezone the way the live web app
 * does (see the big timezone architecture note elsewhere in this
 * codebase).
 */
function formatProcessorEventTimestamp(): string
{
    $now = new DateTime('now', new DateTimeZone('America/Winnipeg'));
    return 'Today at ' . $now->format('m/d/Y') . ' - ' . $now->format('H:i');
}

/**
 * Generates a real, spec-compliant Message-ID (RFC 5322 / RFC 2822) —
 * used both for a brand-new thread's root and for every individual
 * message sent afterward. The domain portion doesn't need to resolve to
 * anything real; it just needs to be unique and consistently formatted,
 * which is all any receiving mail server actually checks.
 */
function generateMessageId(): string
{
    return '<' . bin2hex(random_bytes(16)) . '@duqueimmigration.ca>';
}

/**
 * Finds (or creates) the root Message-ID for a given processor+client
 * pairing's email thread — this is what makes every notification about
 * the same client, sent to the same processor, land as a reply within
 * ONE ongoing conversation instead of a new standalone email each time.
 * Returns ['message_id' => ..., 'is_new_thread' => bool] — when
 * is_new_thread is true, the caller should send this exact message_id as
 * this email's own Message-ID (establishing the thread); when false, the
 * caller should send it as In-Reply-To/References instead (replying
 * within the existing thread) and generate a fresh, separate Message-ID
 * for the new email itself.
 */
function getOrCreateProcessorClientThread(int $processorId, int $clientId): array
{
    $db = getDb();

    $stmt = $db->prepare('SELECT message_id FROM processor_client_threads WHERE processor_id = ? AND client_id = ?');
    $stmt->execute([$processorId, $clientId]);
    $existing = $stmt->fetch();

    if ($existing) {
        return ['message_id' => $existing['message_id'], 'is_new_thread' => false];
    }

    $newMessageId = generateMessageId();
    try {
        $db->prepare('INSERT INTO processor_client_threads (processor_id, client_id, message_id) VALUES (?, ?, ?)')
           ->execute([$processorId, $clientId, $newMessageId]);
    } catch (Throwable $e) {
        // Rare race: two events for the same brand-new client+processor
        // pairing fired at almost the same moment, and another request
        // already created the thread a moment ago (uniq_processor_client
        // catches this). Fetch what actually got saved instead of
        // failing, so this event still threads correctly.
        $stmt = $db->prepare('SELECT message_id FROM processor_client_threads WHERE processor_id = ? AND client_id = ?');
        $stmt->execute([$processorId, $clientId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return ['message_id' => $existing['message_id'], 'is_new_thread' => false];
        }
        // If we get here, the insert failed for some reason OTHER than
        // the expected race (the recovery fetch above found nothing
        // either) — logged specifically because the code below still
        // returns a message ID as if a thread now exists, when nothing
        // was actually saved. Worth knowing about if it ever happens.
        error_log('getOrCreateProcessorClientThread: insert failed and no existing thread found — ' . $e->getMessage());
    }

    return ['message_id' => $newMessageId, 'is_new_thread' => true];
}

/**
 * Sends a processor ONE notification email, immediately, for a single
 * event — not queued for a later daily batch. Every notification for the
 * same client, to the same processor, is sent as a real threaded reply
 * (proper Message-ID / In-Reply-To / References headers — not just
 * Gmail's best-effort subject-matching) so they land as one ongoing
 * conversation per client rather than either one giant combined email or
 * a pile of unrelated standalone ones.
 */
function notifyProcessorRealtime(int $processorId, int $clientId, string $clientName, string $eventType, string $summary): bool
{
    if ($processorId <= 0 || $summary === '') {
        return false;
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT full_name, email FROM processors WHERE id = ?');
    $stmt->execute([$processorId]);
    $processor = $stmt->fetch();
    if (!$processor || empty($processor['email'])) {
        return false;
    }

    $thread = getOrCreateProcessorClientThread($processorId, $clientId);
    $action = processorEventActionPhrase($eventType);
    $when = formatProcessorEventTimestamp();

    $subject = $clientName . ' — Duque Immigration';

    $textBody = "Hi " . $processor['full_name'] . ",\n\n"
        . $clientName . ' "' . $action . '" on ' . $when . "\n\n"
        . $summary . "\n\n"
        . "Log in to the admin panel for full details:\n" . rtrim(ADMIN_APP_URL, '/') . "/login.php\n\n"
        . "— Duque Immigration Document Portal";

    $safeClientName = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
    $safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    $safeWhen = htmlspecialchars($when, ENT_QUOTES, 'UTF-8');
    $safeSummary = htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');

    $htmlBody = buildEmailHtml(
        $processor['full_name'],
        [
            '<div style="font-size:14px;color:#1F1F23;"><strong>' . $safeClientName . '</strong> ' . $safeAction . '</div>'
                . '<div style="font-size:12.5px;color:#767680;margin-top:2px;">' . $safeWhen . '</div>'
                . '<div style="font-size:13px;color:#4B4B52;margin-top:8px;">' . $safeSummary . '</div>',
        ],
        'Clients',
        rtrim(ADMIN_APP_URL, '/') . '/login.php'
    );

    if ($thread['is_new_thread']) {
        // This email establishes the thread — its own Message-ID becomes
        // the root every future notification for this client will reply to.
        return sendMail($processor['email'], $processor['full_name'], $subject, $textBody, $htmlBody, [], $thread['message_id'], null);
    }

    // Reply within the existing thread — a fresh Message-ID of its own,
    // but In-Reply-To/References point back at the thread's original root.
    return sendMail($processor['email'], $processor['full_name'], $subject, $textBody, $htmlBody, [], generateMessageId(), $thread['message_id']);
}

/**
 * Sends a newly created client their welcome email: a warm greeting plus
 * their login email and temporary password, and a link straight to the
 * portal login page. Best-effort — client creation itself never fails or
 * blocks on this.
 */
function notifyClientWelcome(array $client, string $plainPassword): bool
{
    if (empty($client['email'])) {
        return false;
    }

    $subject = 'Welcome — your Duque Immigration portal account';
    $portalLoginUrl = rtrim(PORTAL_APP_URL, '/') . '/login.php';
    $safeEmail = htmlspecialchars($client['email'], ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');

    $textBody = "Hi " . $client['full_name'] . ",\n\n"
        . "Welcome to Duque Immigration! Your client portal account is ready — this is where "
        . "you'll upload documents, track your case status, and message our team directly.\n\n"
        . "Your login details:\n"
        . "Email: " . $client['email'] . "\n"
        . "Password: " . $plainPassword . "\n\n"
        . "Log in here: " . $portalLoginUrl . "\n\n"
        . "Here's what happens next:\n\n"
        . "Next step — Document Checklist:\n"
        . "Log in and upload the documents on your checklist. You'll see exactly what's needed "
        . "and can track the status of each one as our team reviews it.\n\n"
        . "Final step — Application Review:\n"
        . "Once your documents are in, we'll prepare your application forms and post them to your "
        . "portal for you to look over — checking things like name spelling and dates — before "
        . "anything is submitted.\n\n"
        . "For your security, the first time you sign in you'll be asked to choose your own "
        . "password. This temporary one then stops working.\n\n"
        . "If you have any questions, just reply to this email or leave a comment on your portal.\n\n"
        . "— Duque Immigration";

    $credentialsBlock = '
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
               style="background-color:#F6F6F7;border:1px solid #E4E4E7;border-radius:8px;margin:4px 0 16px;">
          <tr>
            <td style="padding:14px 16px;">
              <div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:#6B6B70;margin-bottom:3px;">Email</div>
              <div style="font-family:\'SFMono-Regular\',Consolas,monospace;font-size:14px;color:#1F1F23;margin-bottom:10px;">' . $safeEmail . '</div>
              <div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:#6B6B70;margin-bottom:3px;">Password</div>
              <div style="font-family:\'SFMono-Regular\',Consolas,monospace;font-size:14px;color:#1F1F23;">' . $safePassword . '</div>
            </td>
          </tr>
        </table>';

    // A simple two-step "here's what happens next" block — Application
    // Review didn't exist as its own portal section when this email was
    // first written, so it's spelled out explicitly now rather than
    // leaving the client to discover it on their own.
    $nextStepsBlock = '
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:4px 0 16px;">
          <tr>
            <td style="padding:0 0 14px;border-left:3px solid #C9A227;padding-left:14px;">
              <div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:#C9A227;margin-bottom:4px;">Next step</div>
              <div style="font-size:14.5px;font-weight:bold;color:#1F1F23;margin-bottom:4px;">Document Checklist</div>
              <div style="font-size:13.5px;color:#3F3F44;">Log in and upload the documents on your checklist. You\'ll see exactly what\'s needed and can track each one as our team reviews it.</div>
            </td>
          </tr>
          <tr>
            <td style="padding-left:14px;border-left:3px solid #E4E4E7;">
              <div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:#6B6B70;margin-bottom:4px;">Final step</div>
              <div style="font-size:14.5px;font-weight:bold;color:#1F1F23;margin-bottom:4px;">Application Review</div>
              <div style="font-size:13.5px;color:#3F3F44;">Once your documents are in, we\'ll prepare your application forms and post them to your portal for you to look over before anything is submitted.</div>
            </td>
          </tr>
        </table>';

    $htmlBody = buildEmailHtml(
        $client['full_name'],
        [
            "Welcome to Duque Immigration! Your client portal account is ready — this is where you'll upload documents, track your case status, and message our team directly.",
            $credentialsBlock,
            '<div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:#6B6B70;margin:0 0 8px;">Here\'s what happens next</div>',
            $nextStepsBlock,
            'For your security, the first time you sign in you\'ll be asked to choose your own password. This temporary one then stops working.',
            'If you have any questions, just reply to this email or leave a comment on your portal.',
        ],
        'Log in to your portal',
        $portalLoginUrl
    );

    // To the client only — never CC'd, since it carries their portal
    // password. Additional emails get notifyCcWelcome() instead.
    return sendMail($client['email'], $client['full_name'], $subject, $textBody, $htmlBody);
}

/**
 * Welcome for someone just added to a client's "Additional emails" (a
 * spouse, agent, sponsor…). They get copies of case emails AND the
 * applicant's own portal login, so they can sign in to the applicant's
 * portal as the applicant (the office's choice: one shared login per
 * applicant, no separate contact accounts). The password shown is the
 * applicant's current one; if it's reset later, they need the new one.
 * $addedBy is 'client' or 'staff'. Best-effort.
 */
function notifyCcWelcome(string $ccEmail, array $client, string $addedBy): bool
{
    $name = trim($client['full_name']);
    $firstName = explode(' ', $name)[0];
    $loginEmail = (string)($client['email'] ?? '');
    $portalLogin = rtrim(PORTAL_APP_URL, '/') . '/login.php';

    // The applicant's current password (stored for staff to hand out).
    // Passwords are stored only as hashes, so this email can't (and
    // shouldn't) contain one: the contact asks the applicant for it.

    $subject = "You've been added to " . $name . "'s case — Duque Immigration";
    $intro = $addedBy === 'client'
        ? $name . ' has added you as an additional contact on their immigration case with Duque Immigration.'
        : 'Duque Immigration has added you as an additional contact on ' . $name . "'s immigration case.";
    $what = "You'll receive a copy of the email updates we send " . $firstName . ", and you can sign in to "
        . $firstName . "'s client portal with " . $firstName . "'s login to see documents, upload files and message the case team.";
    $private = 'This is ' . $firstName . "'s own login — please keep it private and don't share it with anyone else. For your security we never send passwords by email.";

    $textBody = "Hello,\n\n"
        . $intro . "\n\n"
        . $what . "\n\n"
        . "Portal: " . $portalLogin . "\n"
        . "Email: " . $loginEmail . "\n"
        . "Password: please ask " . $firstName . " (it is never sent by email)\n"
        . "\n" . $private . "\n\n"
        . "If you weren't expecting this, just reply to this email and we'll remove you.\n\n"
        . "— Duque Immigration";

    $safeEmail = htmlspecialchars($loginEmail, ENT_QUOTES, 'UTF-8');
    $safePassword = 'Please ask ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ' (never sent by email)';
    $credentials = '
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
               style="background-color:#F6F6F7;border:1px solid #E4E4E7;border-radius:8px;margin:4px 0 16px;">
          <tr>
            <td style="padding:14px 16px;">
              <div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:#6B6B70;margin-bottom:3px;">Email</div>
              <div style="font-family:\'SFMono-Regular\',Consolas,monospace;font-size:14px;color:#1F1F23;margin-bottom:10px;">' . $safeEmail . '</div>
              <div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:#6B6B70;margin-bottom:3px;">Password</div>
              <div style="font-family:\'SFMono-Regular\',Consolas,monospace;font-size:14px;color:#1F1F23;">' . $safePassword . '</div>
            </td>
          </tr>
        </table>';
    $htmlBody = buildEmailHtml('there', [
        htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($what, ENT_QUOTES, 'UTF-8'),
        $credentials,
        htmlspecialchars($private, ENT_QUOTES, 'UTF-8'),
        "If you weren't expecting this, just reply to this email and we'll remove you from <strong>" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</strong>'s file.",
    ], 'Sign in to the portal', $portalLogin);

    return sendMail($ccEmail, '', $subject, $textBody, $htmlBody);
}

/**
 * Sends notifyCcWelcome() to every address that's in $newRaw but wasn't in
 * $oldRaw (any letter case), so re-saving a form never re-welcomes anyone.
 * Returns how many were sent.
 */
function notifyNewCcEmails(array $client, ?string $oldRaw, ?string $newRaw, string $addedBy): int
{
    $before = array_map('strtolower', parseCcEmails($oldRaw));
    $sent = 0;
    foreach (parseCcEmails($newRaw) as $email) {
        if (in_array(strtolower($email), $before, true) || strcasecmp($email, (string)($client['email'] ?? '')) === 0) {
            continue;
        }
        try {
            if (notifyCcWelcome($email, $client, $addedBy)) $sent++;
        } catch (Throwable $e) {
            error_log('notifyCcWelcome ' . $email . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

/**
 * Tells a client they have a new message from staff in their portal —
 * sent immediately (a direct message shouldn't wait for the daily
 * digest), but deliberately WITHOUT the message text itself: case
 * details stay inside the logged-in portal rather than sitting in an
 * email inbox. The caller throttles bursts (see api/admin_messages.php),
 * so several quick replies produce one email, not several.
 */
function notifyClientNewMessage(array $client, string $staffName): bool
{
    if (empty($client['email'])) {
        return false;
    }

    $subject = 'New message from your case team — Duque Immigration';
    $portalMessagesUrl = rtrim(PORTAL_APP_URL, '/') . '/login.php?redirect=messages.php';

    $textBody = "Hi " . $client['full_name'] . ",\n\n"
        . $staffName . " from Duque Immigration sent you a new message on your client portal.\n\n"
        . "For your privacy, messages are only shown inside the portal. Log in to read and reply:\n"
        . $portalMessagesUrl . "\n\n"
        . "— Duque Immigration";

    $htmlBody = buildEmailHtml(
        $client['full_name'],
        [
            '<strong>' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . '</strong> from Duque Immigration sent you a new message on your client portal.',
            'For your privacy, messages are only shown inside the portal. Log in to read and reply.',
        ],
        'Read message',
        $portalMessagesUrl
    );

    return sendMail($client['email'], $client['full_name'], $subject, $textBody, $htmlBody, parseCcEmails($client['cc_emails'] ?? null));
}

/**
 * Emails a client a reminder covering whatever still needs their
 * attention — pending/needs-revision items from their regular document
 * checklist, and any Application Review items awaiting their
 * confirmation. Either list can be empty (but not both — the caller
 * checks that before calling this). Returns false (no-op) if there's
 * truly nothing to remind them about.
 */
function notifyClientReminder(array $client, array $pendingDocs, array $pendingReviews = []): bool
{
    if (empty($client['email']) || (!count($pendingDocs) && !count($pendingReviews))) {
        return false;
    }

    $subject = 'Reminder: your case needs a few things — Duque Immigration';
    $portalLoginUrl = rtrim(PORTAL_APP_URL, '/') . '/login.php';

    $textSections = [];
    $htmlSections = '';

    if ($pendingDocs) {
        $lines = [];
        $htmlListItems = '';
        foreach ($pendingDocs as $doc) {
            $status = $doc['status'] === 'needs_revision' ? ' (needs revision)' : '';
            $lines[] = '- ' . $doc['doc_name'] . $status;
            $htmlListItems .= '<li style="margin-bottom:4px;">' . htmlspecialchars($doc['doc_name'], ENT_QUOTES, 'UTF-8') . $status . '</li>';
        }
        $textSections[] = "Next step — Document Checklist:\n" . implode("\n", $lines);
        $htmlSections .= '<div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:#6B6B70;margin:0 0 6px;">Next step — Document Checklist</div>'
            . '<ul style="margin:0 0 18px;padding-left:20px;color:#1F1F23;font-size:14px;">' . $htmlListItems . '</ul>';
    }

    if ($pendingReviews) {
        $lines = [];
        $htmlListItems = '';
        foreach ($pendingReviews as $doc) {
            $lines[] = '- ' . $doc['doc_name'];
            $htmlListItems .= '<li style="margin-bottom:4px;">' . htmlspecialchars($doc['doc_name'], ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $textSections[] = "Final step — Application Review:\n" . implode("\n", $lines)
            . "\nPlease look these over for accuracy (name spelling, dates, and other details) before they're submitted.";
        $htmlSections .= '<div style="font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:#6B6B70;margin:0 0 6px;">Final step — Application Review</div>'
            . '<ul style="margin:0 0 8px;padding-left:20px;color:#1F1F23;font-size:14px;">' . $htmlListItems . '</ul>'
            . '<p style="margin:0 0 18px;font-size:13px;color:#6B6B70;">Please look these over for accuracy (name spelling, dates, and other details) before they\'re submitted.</p>';
    }

    $encouragement = "Have any questions or concerns about your application? Log in to your "
        . "portal any time and leave a comment on the specific item — your case officer "
        . "will see it and get back to you there.";

    $textBody = "Hi " . $client['full_name'] . ",\n\n"
        . "This is a friendly reminder about a couple of things on your case:\n\n"
        . implode("\n\n", $textSections) . "\n\n"
        . "Log in here: " . $portalLoginUrl . "\n\n"
        . $encouragement . "\n\n"
        . "— Duque Immigration";

    $htmlBody = buildEmailHtml(
        $client['full_name'],
        [
            "This is a friendly reminder about a couple of things on your case:",
            $htmlSections,
            htmlspecialchars($encouragement, ENT_QUOTES, 'UTF-8'),
        ],
        'Go to your portal',
        $portalLoginUrl
    );

    return sendMail($client['email'], $client['full_name'], $subject, $textBody, $htmlBody, parseCcEmails($client['cc_emails'] ?? null));
}

/**
 * Adds an event to a client's notification queue instead of emailing them
 * immediately — used for the "batched" event types (new documents
 * requested, checklists sent, documents removed, staff comments, status
 * changes). These all get rolled up into one daily digest email instead
 * of a separate email per event; see sendDailyDigests() and
 * cron/daily_digest.php, which is meant to run once every 24 hours via a
 * scheduled cron job.
 *
 * Deliberately NOT used for: the welcome email (sent immediately on
 * client creation, since login credentials shouldn't wait), the manual
 * "Send reminder" button (staff explicitly asked for it to go now), or
 * processor notifications (staff want prompt awareness, not a next-day
 * summary).
 */
function queueClientNotification(int $clientId, string $summary): bool
{
    if ($clientId <= 0 || $summary === '') {
        return false;
    }
    try {
        $db = getDb();
        $db->prepare('INSERT INTO client_notification_queue (client_id, summary) VALUES (?, ?)')
           ->execute([$clientId, $summary]);
        return true;
    } catch (Throwable $e) {
        error_log('queueClientNotification failed for client ' . $clientId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends every client their daily digest: one email per client who has at
 * least one unsent queued notification, listing everything that
 * happened, then marks those rows as sent. Clients with nothing queued
 * get no email at all — no "nothing happened today" noise.
 *
 * Meant to be called from cron/daily_digest.php on a schedule (once every
 * 24 hours), not from any user-facing page. Returns a small summary array
 * for the cron script to log.
 */
function sendDailyDigests(): array
{
    $db = getDb();

    $stmt = $db->query("
      SELECT DISTINCT c.id, c.full_name, c.email, c.cc_emails
      FROM client_notification_queue q
      JOIN clients c ON c.id = q.client_id
      WHERE q.sent_at IS NULL
    ");
    $clients = $stmt->fetchAll();

    $results = ['clients_notified' => 0, 'emails_failed' => 0, 'events_sent' => 0];

    foreach ($clients as $client) {
        $stmt = $db->prepare('SELECT id, summary FROM client_notification_queue WHERE client_id = ? AND sent_at IS NULL ORDER BY created_at ASC');
        $stmt->execute([$client['id']]);
        $events = $stmt->fetchAll();
        if (!$events) {
            continue;
        }

        $sent = sendClientDigestEmail($client, array_column($events, 'summary'));
        if ($sent) {
            $ids = array_column($events, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE client_notification_queue SET sent_at = NOW() WHERE id IN ($placeholders)")->execute($ids);
            $results['clients_notified']++;
            $results['events_sent'] += count($events);
        } else {
            $results['emails_failed']++;
        }
    }

    return $results;
}

/**
 * Sends ONE specific client their digest immediately, covering whatever
 * they currently have queued — the per-client "send now" bell icon on
 * their page uses this. Same underlying logic as sendDailyDigests(), just
 * scoped to a single client instead of looping over everyone.
 */
function sendClientDigestNow(int $clientId): array
{
    $db = getDb();

    $stmt = $db->prepare('SELECT id, full_name, email, cc_emails FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client) {
        return ['sent' => false, 'events_sent' => 0, 'message' => 'Client not found.'];
    }

    $stmt = $db->prepare('SELECT id, summary FROM client_notification_queue WHERE client_id = ? AND sent_at IS NULL ORDER BY created_at ASC');
    $stmt->execute([$clientId]);
    $events = $stmt->fetchAll();

    if (!$events) {
        return ['sent' => false, 'events_sent' => 0, 'message' => 'Nothing pending for this client.'];
    }

    $sent = sendClientDigestEmail($client, array_column($events, 'summary'));
    if (!$sent) {
        return ['sent' => false, 'events_sent' => 0, 'message' => 'Could not send — check mail configuration.'];
    }

    $ids = array_column($events, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE client_notification_queue SET sent_at = NOW() WHERE id IN ($placeholders)")->execute($ids);

    return ['sent' => true, 'events_sent' => count($events), 'message' => ''];
}

/**
 * Returns the most recent time this client was actually sent a digest
 * (real delivered timestamp, from the queue's own sent_at column — not
 * an estimate), or null if they've never received one. Used to show
 * "Last notification sent on ..." next to the bell icon.
 */
function getLastDigestSentAt(int $clientId): ?string
{
    $db = getDb();
    $stmt = $db->prepare('SELECT MAX(sent_at) AS last_sent FROM client_notification_queue WHERE client_id = ? AND sent_at IS NOT NULL');
    $stmt->execute([$clientId]);
    $result = $stmt->fetch();
    return $result['last_sent'] ?? null;
}

/**
 * Builds and sends one client's digest email listing every queued
 * summary line since their last digest.
 */
function sendClientDigestEmail(array $client, array $summaries): bool
{
    if (empty($client['email']) || !count($summaries)) {
        return false;
    }

    $subject = 'Your daily update — Duque Immigration';
    $portalLoginUrl = rtrim(PORTAL_APP_URL, '/') . '/login.php';

    $lines = array_map(fn($s) => '- ' . $s, $summaries);
    $htmlListItems = implode('', array_map(
        fn($s) => '<li style="margin-bottom:6px;">' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</li>',
        $summaries
    ));

    $textBody = "Hi " . $client['full_name'] . ",\n\n"
        . "Here's a summary of what happened on your case in the last 24 hours:\n\n"
        . implode("\n", $lines) . "\n\n"
        . "Log in to your portal for full details or to reply:\n" . $portalLoginUrl . "\n\n"
        . "If you have any questions, just reply to this email or leave a comment on your portal.\n\n"
        . "— Duque Immigration";

    $htmlBody = buildEmailHtml(
        $client['full_name'],
        [
            "Here's a summary of what happened on your case in the last 24 hours:",
            '<ul style="margin:0 0 16px;padding-left:20px;color:#1F1F23;font-size:14px;">' . $htmlListItems . '</ul>',
            'If you have any questions, just reply to this email or leave a comment on your portal.',
        ],
        'View my portal',
        $portalLoginUrl
    );

    return sendMail($client['email'], $client['full_name'], $subject, $textBody, $htmlBody, parseCcEmails($client['cc_emails'] ?? null));
}
