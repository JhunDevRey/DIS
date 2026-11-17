<?php
/**
 * includes/calendly.php
 * Read-only access to the office Calendly account for the staff Front Desk
 * (the "Scheduling" window). Uses a Calendly personal access token
 * (CALENDLY_TOKEN in config.php). When the token belongs to an organization
 * owner/admin, every team member's bookings show; otherwise only that
 * person's own.
 *
 * Results are cached on disk for CALENDLY_CACHE_SECONDS so a room full of
 * open Front Desks doesn't hit Calendly's rate limit.
 */

require_once __DIR__ . '/db.php'; // loads config.php
require_once __DIR__ . '/functions.php';

define('CALENDLY_CACHE_SECONDS', 60);
define('CALENDLY_MAX_EVENTS', 60);   // per window load (today + next 7 days)
// Calendly works in UTC; "today" on the Front Desk means today at the office.
define('CALENDLY_DEFAULT_TZ', 'America/Winnipeg');

/**
 * The Calendly tokens in config.php. CALENDLY_TOKEN holds one token, or
 * several separated by commas when bookings live on more than one Calendly
 * account (each person's own login has its own token).
 */
function calendlyTokens(): array
{
    if (!defined('CALENDLY_TOKEN')) return [];
    $list = array_map('trim', explode(',', (string)CALENDLY_TOKEN));
    return array_values(array_unique(array_filter($list, 'strlen')));
}

/** Calendly is on only once a token is in config.php. */
function calendlyEnabled(): bool
{
    return count(calendlyTokens()) > 0;
}

/** The token requests are currently made with (set per account while fetching). */
function calendlyToken(?string $set = null): string
{
    static $current = null;
    if ($set !== null) $current = $set;
    return $current ?? (calendlyTokens()[0] ?? '');
}

function calendlyTimezone(): DateTimeZone
{
    $tz = defined('CALENDLY_TIMEZONE') && CALENDLY_TIMEZONE ? CALENDLY_TIMEZONE : CALENDLY_DEFAULT_TZ;
    try { return new DateTimeZone($tz); } catch (Throwable $e) { return new DateTimeZone(CALENDLY_DEFAULT_TZ); }
}

function calendlyBase(): string
{
    // CALENDLY_API_BASE exists only so tests can point at a local fake API.
    return defined('CALENDLY_API_BASE') && CALENDLY_API_BASE ? rtrim(CALENDLY_API_BASE, '/') : 'https://api.calendly.com';
}

function calendlyCurl(string $url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . calendlyToken(),
            'Accept: application/json',
        ],
    ]);
    return $ch;
}

/** Decodes one finished response; throws with Calendly's own message on failure. */
function calendlyDecode($raw, int $status, string $curlError): array
{
    if ($raw === false || $raw === null) {
        throw new RuntimeException('Could not reach Calendly: ' . $curlError);
    }
    $data = json_decode((string)$raw, true) ?: [];
    if ($status >= 400) {
        throw new RuntimeException($data['message'] ?? ('Calendly error ' . $status), $status);
    }
    return $data;
}

/** One GET request. $pathOrUrl may be a full Calendly URL (pagination, resource URIs). */
function calendlyGet(string $pathOrUrl, array $query = []): array
{
    $url = preg_match('~^https?://~', $pathOrUrl) ? $pathOrUrl : calendlyBase() . '/' . ltrim($pathOrUrl, '/');
    if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    $ch = calendlyCurl($url);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return calendlyDecode($raw, $status, $err);
}

/**
 * Several GETs in parallel (invitees for each event). Returns results in
 * the same order; a failed request comes back as null instead of throwing,
 * so one bad event doesn't blank the whole window.
 */
function calendlyGetMany(array $urls): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $handles[$i] = calendlyCurl($url);
        curl_multi_add_handle($mh, $handles[$i]);
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $st === CURLM_OK);

    $out = [];
    foreach ($handles as $i => $ch) {
        try {
            $out[$i] = calendlyDecode(curl_multi_getcontent($ch), (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_error($ch));
        } catch (Throwable $e) {
            error_log('calendly invitees: ' . $e->getMessage());
            $out[$i] = null;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/** Where a meeting happens, as one short label + an optional join link. */
function calendlyLocation(?array $loc): array
{
    if (!$loc) return ['label' => null, 'join_url' => null];
    $type = $loc['type'] ?? '';
    $labels = [
        'zoom'                 => 'Zoom',
        'zoom_conference'      => 'Zoom',
        'google_conference'    => 'Google Meet',
        'microsoft_teams_conference' => 'Teams',
        'webex_conference'     => 'Webex',
        'gotomeeting'          => 'GoTo Meeting',
        'outbound_call'        => 'Phone call (we call)',
        'inbound_call'         => 'Phone call (they call)',
        'physical'             => 'In person',
        'ask_invitee'          => null,
        'custom'               => null,
    ];
    $label = array_key_exists($type, $labels) ? $labels[$type] : null;
    // For in-person / custom / ask-invitee the text itself is the useful bit.
    if ($label === null || $type === 'physical') {
        $text = trim((string)($loc['location'] ?? ''));
        if ($text !== '') $label = $label ? $label . ' · ' . $text : $text;
    }
    $join = $loc['join_url'] ?? null;
    return ['label' => $label, 'join_url' => is_string($join) && preg_match('~^https://~', $join) ? $join : null];
}

/** Last path segment of a Calendly resource URI (its UUID). */
function calendlyUuid(string $uri): string
{
    return basename(parse_url($uri, PHP_URL_PATH) ?: $uri);
}

/**
 * Active clients keyed by lower-cased email (main + CC emails), so a
 * booking made by an existing client links straight to their file.
 */
function calendlyClientsByEmail(array $emails): array
{
    $emails = array_values(array_unique(array_filter(array_map(fn($e) => strtolower(trim((string)$e)), $emails))));
    if (!$emails) return [];
    $in = implode(',', array_fill(0, count($emails), '?'));
    $stmt = getDb()->prepare("SELECT id, full_name, LOWER(email) AS email FROM clients WHERE is_active = 1 AND LOWER(email) IN ($in)");
    $stmt->execute($emails);
    $map = [];
    foreach ($stmt->fetchAll() as $c) {
        $map[$c['email']] = ['client_id' => (int)$c['id'], 'full_name' => $c['full_name']];
    }
    return $map;
}

/**
 * Who the token belongs to. Returns [user URI, organization URI].
 * Cached for a day — it never changes for a given token.
 */
function calendlyMe(): array
{
    $cacheFile = UPLOAD_DIR . '/cache/calendly_me_' . substr(hash('sha256', calendlyToken()), 0, 12) . '.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 86400) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (!empty($cached['user'])) return [$cached['user'], $cached['org'] ?? ''];
    }
    $me = calendlyGet('users/me')['resource'] ?? [];
    $user = (string)($me['uri'] ?? '');
    $org = (string)($me['current_organization'] ?? '');
    if ($user === '') throw new RuntimeException('Calendly did not say who the token belongs to.');
    if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0755, true);
    @file_put_contents($cacheFile, json_encode(['user' => $user, 'org' => $org]), LOCK_EX);
    return [$user, $org];
}

/** Scheduled events between two instants, all pages (capped at CALENDLY_MAX_EVENTS). */
function calendlyEventsBetween(DateTimeInterface $from, DateTimeInterface $to): array
{
    [$user, $org] = calendlyMe();
    $query = [
        'min_start_time' => gmdate('Y-m-d\TH:i:s\Z', $from->getTimestamp()),
        'max_start_time' => gmdate('Y-m-d\TH:i:s\Z', $to->getTimestamp()),
        'sort'           => 'start_time:asc',
        'count'          => 100,
    ];
    // Whole team when the token can see it; Calendly answers 403 for
    // organization scope unless the token owner is an owner/admin.
    try {
        $page = calendlyGet('scheduled_events', $query + ['organization' => $org]);
        $scope = 'team';
    } catch (RuntimeException $e) {
        if ($org === '' || $e->getCode() !== 403) throw $e;
        $page = calendlyGet('scheduled_events', $query + ['user' => $user]);
        $scope = 'me';
    }
    $events = $page['collection'] ?? [];
    while (count($events) < CALENDLY_MAX_EVENTS && !empty($page['pagination']['next_page'])) {
        $page = calendlyGet($page['pagination']['next_page']);
        $events = array_merge($events, $page['collection'] ?? []);
    }
    return [array_slice($events, 0, CALENDLY_MAX_EVENTS), $scope];
}

/**
 * Today's and the next 7 days' bookings (canceled ones included, flagged),
 * soonest first, each with its invitees. Cached for CALENDLY_CACHE_SECONDS;
 * $fresh skips the cache (Refresh button).
 */
function calendlyUpcoming(bool $fresh = false): array
{
    $cacheDir = UPLOAD_DIR . '/cache';
    $cacheFile = $cacheDir . '/calendly_upcoming.json';
    if (!$fresh && is_file($cacheFile) && filemtime($cacheFile) > time() - CALENDLY_CACHE_SECONDS) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $tz = calendlyTimezone();
    $from = new DateTimeImmutable('today', $tz);
    $to = $from->modify('+8 days');

    // One pass per Calendly account; a failing account doesn't hide the others.
    $out = [];
    $seen = [];
    $scopes = [];
    $failed = 0;
    $lastError = null;
    foreach (calendlyTokens() as $token) {
        calendlyToken($token);
        try {
            [$list, $scope] = calendlyUpcomingFor($from, $to);
        } catch (Throwable $e) {
            $failed++;
            $lastError = $e;
            error_log('calendly account: ' . $e->getMessage());
            continue;
        }
        $scopes[$scope] = true;
        foreach ($list as $ev) {
            if (isset($seen[$ev['id']])) continue;  // same event visible from two tokens
            $seen[$ev['id']] = true;
            $out[] = $ev;
        }
    }
    if ($lastError && $failed === count(calendlyTokens())) throw $lastError;
    usort($out, fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));

    $result = [
        'events'     => $out,
        'scope'      => count($scopes) === 1 ? array_key_first($scopes) : 'mixed',
        'accounts'   => count(calendlyTokens()),
        'failed_accounts' => $failed,
        'timezone'   => $tz->getName(),
        'today'      => $from->format('Y-m-d'),
        'fetched_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    @file_put_contents($cacheFile, json_encode($result), LOCK_EX);
    return $result;
}

/** One Calendly account's bookings between two instants, shaped for the window. Returns [events, scope]. */
function calendlyUpcomingFor(DateTimeInterface $from, DateTimeInterface $to): array
{
    [$events, $scope] = calendlyEventsBetween($from, $to);

    $inviteePages = calendlyGetMany(array_map(
        fn($ev) => calendlyBase() . '/scheduled_events/' . rawurlencode(calendlyUuid((string)$ev['uri'])) . '/invitees?count=100',
        $events
    ));

    $allEmails = [];
    foreach ($inviteePages as $p) {
        foreach (($p['collection'] ?? []) as $inv) $allEmails[] = $inv['email'] ?? '';
    }
    $clients = calendlyClientsByEmail($allEmails);

    $out = [];
    foreach ($events as $i => $ev) {
        $invitees = [];
        foreach (($inviteePages[$i]['collection'] ?? []) as $inv) {
            $email = strtolower(trim((string)($inv['email'] ?? '')));
            $answers = [];
            foreach (($inv['questions_and_answers'] ?? []) as $qa) {
                $a = trim((string)($qa['answer'] ?? ''));
                if ($a !== '') $answers[] = ['q' => (string)($qa['question'] ?? ''), 'a' => mb_strimwidth($a, 0, 220, '…')];
            }
            $invitees[] = [
                'name'     => (string)($inv['name'] ?? '') ?: '(no name)',
                'email'    => $email ?: null,
                'phone'    => $inv['text_reminder_number'] ?? null,
                'status'   => (string)($inv['status'] ?? 'active'),     // active | canceled
                'no_show'  => !empty($inv['no_show']),
                'timezone' => $inv['timezone'] ?? null,
                'answers'  => array_slice($answers, 0, 4),
                'rescheduled' => !empty($inv['old_invitee']),           // booked as a reschedule
                'client'   => $email !== '' ? ($clients[$email] ?? null) : null,
            ];
        }
        $hosts = array_values(array_filter(array_map(fn($m) => $m['user_name'] ?? '', $ev['event_memberships'] ?? [])));
        $cancel = $ev['cancellation'] ?? null;
        $out[] = [
            'id'         => calendlyUuid((string)$ev['uri']),
            'name'       => (string)($ev['name'] ?? 'Meeting'),
            'status'     => (string)($ev['status'] ?? 'active'),        // active | canceled
            'start'      => $ev['start_time'] ?? null,                   // ISO 8601, UTC
            'end'        => $ev['end_time'] ?? null,
            'booked_at'  => $ev['created_at'] ?? null,
            'hosts'      => $hosts,
            'location'   => calendlyLocation($ev['location'] ?? null),
            'canceled_by'=> $cancel ? (string)($cancel['canceled_by'] ?? '') : null,
            'cancel_reason' => $cancel && !empty($cancel['reason']) ? mb_strimwidth((string)$cancel['reason'], 0, 160, '…') : null,
            'invitees'   => $invitees,
        ];
    }

    return [$out, $scope];
}
