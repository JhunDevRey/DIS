<?php
/**
 * includes/dropbox.php
 * Minimal Dropbox API v2 client using the refresh-token OAuth2 flow
 * (same pattern as the Google Drive integration in DWC — no service
 * account, a long-lived refresh token exchanged for short-lived access
 * tokens on demand).
 *
 * Every function fails soft: on error they return ['ok' => false, 'error' => ...]
 * instead of throwing, so a Dropbox outage never breaks the core app (folder
 * creation, status changes, etc. all keep working locally either way).
 */

require_once __DIR__ . '/../config.php';

function dropboxIsConfigured(): bool
{
    return DROPBOX_APP_KEY !== 'your-dropbox-app-key'
        && DROPBOX_REFRESH_TOKEN !== 'your-dropbox-refresh-token'
        && DROPBOX_APP_KEY !== ''
        && DROPBOX_REFRESH_TOKEN !== '';
}

/**
 * Exchanges the long-lived refresh token for a short-lived access token.
 * Cached for the lifetime of the request only (tokens are cheap to fetch;
 * no need to persist them).
 */
function dropboxGetAccessToken(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!dropboxIsConfigured()) {
        return ['ok' => false, 'error' => 'Dropbox is not configured yet (see config.php).'];
    }

    $ch = curl_init('https://api.dropbox.com/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => DROPBOX_REFRESH_TOKEN,
            'client_id' => DROPBOX_APP_KEY,
            'client_secret' => DROPBOX_APP_SECRET,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Could not reach Dropbox: ' . $curlError];
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['access_token'])) {
        return ['ok' => false, 'error' => 'Dropbox token refresh failed: ' . ($data['error_description'] ?? $response)];
    }

    $cached = ['ok' => true, 'access_token' => $data['access_token']];
    return $cached;
}

/**
 * Internal helper for JSON-body Dropbox API v2 RPC endpoints.
 */
function dropboxApiCall(string $url, array $body): array
{
    $token = dropboxGetAccessToken();
    if (!$token['ok']) {
        return $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token['access_token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Could not reach Dropbox: ' . $curlError];
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        return ['ok' => false, 'error' => $data['error_summary'] ?? $response, 'raw' => $data];
    }

    return ['ok' => true, 'data' => $data];
}

/**
 * Creates a folder at the given Dropbox path (e.g. "/1. MASTER LIST/2026/
 * 8. AUGUST 2026/1. JANE DOE - WP NEW"). If it already exists and $autorename is false, treats
 * that as success rather than an error — safe to call more than once for
 * the same exact path (used for year/month folders, which should always
 * be reused, never renamed).
 *
 * If $autorename is true, a name collision instead gets Dropbox's own
 * auto-rename treatment (e.g. "Jane Doe (1)") rather than being treated as
 * the same folder — used for client folders, so two unrelated clients who
 * happen to share a name never get silently merged into one folder.
 * Returns the actual resulting path either way, since autorename can
 * change it.
 */
function dropboxCreateFolder(string $path, bool $autorename = false): array
{
    $result = dropboxApiCall('https://api.dropboxapi.com/2/files/create_folder_v2', [
        'path' => $path,
        'autorename' => $autorename,
    ]);

    if (!$result['ok'] && strpos($result['error'] ?? '', 'path/conflict') !== false) {
        return ['ok' => true, 'already_existed' => true, 'path' => $path];
    }
    if (!$result['ok']) {
        return $result;
    }

    $actualPath = $result['data']['metadata']['path_display'] ?? $path;
    return ['ok' => true, 'already_existed' => false, 'path' => $actualPath];
}

/**
 * Gets (or creates) a shareable link for a Dropbox folder/file path.
 */
function dropboxGetOrCreateSharedLink(string $path): array
{
    $create = dropboxApiCall('https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings', [
        'path' => $path,
    ]);
    if ($create['ok']) {
        return ['ok' => true, 'url' => $create['data']['url'] ?? ''];
    }

    // A link already existing isn't a real failure — look it up instead.
    if (strpos($create['error'] ?? '', 'shared_link_already_exists') !== false) {
        $list = dropboxApiCall('https://api.dropboxapi.com/2/sharing/list_shared_links', [
            'path' => $path,
            'direct_only' => true,
        ]);
        if ($list['ok'] && !empty($list['data']['links'][0]['url'])) {
            return ['ok' => true, 'url' => $list['data']['links'][0]['url']];
        }
    }

    return $create;
}

/**
 * Lists the immediate child folder names at a Dropbox path. A missing path
 * is treated as "no folders yet" (ok=true, empty list) rather than an error,
 * since that's the normal state before the first client of a month exists.
 *
 * Note: only reads the first page of results (files/list_folder without
 * pagination) — fine for month-sized folders (a handful to a few dozen
 * clients), but would need has_more/cursor handling if a single month's
 * folder ever grew into the hundreds.
 */
function dropboxListFolderNames(string $path): array
{
    $result = dropboxApiCall('https://api.dropboxapi.com/2/files/list_folder', ['path' => $path]);

    if (!$result['ok']) {
        if (strpos($result['error'] ?? '', 'path/not_found') !== false) {
            return ['ok' => true, 'names' => []];
        }
        return $result;
    }

    $names = [];
    foreach ($result['data']['entries'] ?? [] as $entry) {
        if (($entry['.tag'] ?? '') === 'folder') {
            $names[] = $entry['name'];
        }
    }
    return ['ok' => true, 'names' => $names];
}

/**
 * Builds the real (not literal-text) year/month path for "right now", e.g.
 * DROPBOX_ROOT_FOLDER . "/2026/8. AUGUST 2026".
 */
function dropboxCurrentMonthPath(): array
{
    $year = date('Y');
    $monthName = strtoupper(date('F'));
    $monthNum = (int)date('n'); // 1-12, no leading zero
    $month = $monthNum . '. ' . $monthName . ' ' . $year; // e.g. "8. AUGUST 2026"
    $yearPath = rtrim(DROPBOX_ROOT_FOLDER, '/') . '/' . $year;
    $monthPath = $yearPath . '/' . $month;
    return ['year' => $year, 'month' => $month, 'year_path' => $yearPath, 'month_path' => $monthPath];
}

/**
 * Resolves a Dropbox share link (e.g. "https://www.dropbox.com/scl/fo/
 * abc123/xyz?rlkey=...&dl=0") to the folder's real internal path, using
 * Dropbox's sharing/get_shared_link_metadata endpoint. Only works for
 * links the connected Dropbox account actually has access to (which is
 * exactly the case this is for — staff pasting a link to a folder in
 * their own team's Dropbox).
 */
function dropboxResolveSharedLink(string $url): array
{
    $result = dropboxApiCall('https://api.dropboxapi.com/2/sharing/get_shared_link_metadata', [
        'url' => $url,
    ]);

    if (!$result['ok']) {
        return ['ok' => false, 'error' => 'Could not resolve that Dropbox link: ' . $result['error']];
    }
    if (($result['data']['.tag'] ?? '') !== 'folder') {
        return ['ok' => false, 'error' => 'That link points to a file, not a folder.'];
    }

    $path = $result['data']['path_display'] ?? $result['data']['path_lower'] ?? null;
    if (!$path) {
        return ['ok' => false, 'error' => 'That link doesn\'t expose a folder path we can use — try pasting the folder path instead (e.g. /1. MASTER LIST/2026/8. AUGUST 2026/1. JANE DOE - WP NEW).'];
    }

    return ['ok' => true, 'path' => $path];
}

/**
 * Links a client to a Dropbox folder that already exists (created before
 * this app was in use, or organized differently than the auto-provisioned
 * structure) — validates the path is real, then gets/creates a shared
 * link for it. Does NOT create anything; if the path doesn't exist, this
 * fails rather than silently creating a new folder there.
 *
 * Accepts either a raw Dropbox path ("/1. MASTER LIST/2026/8. AUGUST 2026/
 * 1. JANE DOE - WP NEW") or a pasted Dropbox share URL — detected automatically by
 * whether the input starts with http(s)://, resolved to the real path via
 * dropboxResolveSharedLink() first if so.
 */
function dropboxLinkExistingFolder(string $pathOrUrl): array
{
    $input = trim($pathOrUrl);

    if (preg_match('#^https?://#i', $input)) {
        $resolved = dropboxResolveSharedLink($input);
        if (!$resolved['ok']) {
            return $resolved;
        }
        $path = $resolved['path'];
    } else {
        $path = '/' . ltrim($input, '/');
    }

    $metadata = dropboxApiCall('https://api.dropboxapi.com/2/files/get_metadata', [
        'path' => $path,
    ]);

    if (!$metadata['ok']) {
        return ['ok' => false, 'error' => 'Could not find that folder in Dropbox: ' . $metadata['error']];
    }
    if (($metadata['data']['.tag'] ?? '') !== 'folder') {
        return ['ok' => false, 'error' => 'That path exists in Dropbox, but it\'s not a folder.'];
    }

    $actualPath = $metadata['data']['path_display'] ?? $path;
    $link = dropboxGetOrCreateSharedLink($actualPath);

    return [
        'ok' => true,
        'path' => $actualPath,
        'link' => $link['ok'] ? $link['url'] : null,
        'link_error' => $link['ok'] ? null : ($link['error'] ?? null),
    ];
}

/**
 * Creates (or reuses) a client's folder for "this month": ensures the
 * year and month folders exist, then creates a folder named
 * "{Client No.}. {FULL NAME} - {CASE TYPE}" (e.g. "21. JOHN DOE -
 * SPOUSAL SPONSORSHIP") inside this month's folder. Name and case type
 * are uppercased to match the requested format; if no case type is set
 * on the client, the " - {CASE TYPE}" part is simply left off rather
 * than leaving a trailing " - " with nothing after it.
 *
 * A running Client No. is auto-assigned by counting existing client
 * folders this month (unless $clientNumber is given) — e.g. the 3rd
 * client folder created in a month becomes "3. ...". It's used for the
 * "Client No." field in this app, and for matching a returning client to
 * their existing folder without a fresh Dropbox lookup.
 *
 * If two different clients happen to end up with the same folder name
 * (same Client No., name, and case type — shouldn't normally happen, but
 * could if a Client No. is set manually), Dropbox's autorename keeps them
 * from being merged into one folder — the second one becomes "1. JANE
 * DOE - WP NEW (1)" automatically rather than silently reusing the first
 * client's folder.
 */
function dropboxProvisionClientFolder(string $clientName, ?string $clientNumber = null, ?string $caseType = null): array
{
    $monthInfo = dropboxCurrentMonthPath();

    $yearFolder = dropboxCreateFolder($monthInfo['year_path']);
    if (!$yearFolder['ok']) {
        return $yearFolder;
    }
    $monthFolder = dropboxCreateFolder($monthInfo['month_path']);
    if (!$monthFolder['ok']) {
        return $monthFolder;
    }

    if ($clientNumber === null || $clientNumber === '') {
        $listing = dropboxListFolderNames($monthInfo['month_path']);
        if (!$listing['ok']) {
            return $listing;
        }
        $clientNumber = (string)(count($listing['names']) + 1);
    }

    $safeName = preg_replace('/[\/\\\\:*?"<>|]/', '-', trim($clientName));
    $safeNumber = preg_replace('/[\/\\\\:*?"<>|]/', '-', trim($clientNumber));
    $folderName = $safeNumber . '. ' . strtoupper($safeName);
    if ($caseType !== null && trim($caseType) !== '') {
        $safeCaseType = preg_replace('/[\/\\\\:*?"<>|]/', '-', trim($caseType));
        $folderName .= ' - ' . strtoupper($safeCaseType);
    }
    $requestedPath = $monthInfo['month_path'] . '/' . $folderName;

    // autorename=true here on purpose — see docblock above.
    $created = dropboxCreateFolder($requestedPath, true);
    if (!$created['ok']) {
        return $created;
    }
    $path = $created['path'];

    $link = dropboxGetOrCreateSharedLink($path);

    return [
        'ok' => true,
        'path' => $path,
        'link' => $link['ok'] ? $link['url'] : null,
        'link_error' => $link['ok'] ? null : ($link['error'] ?? null),
        'client_number' => $clientNumber,
        'month' => $monthInfo['month'],
        'reused_existing' => !empty($created['already_existed']),
    ];
}

/**
 * Ensures a per-document subfolder exists inside a client's Dropbox
 * folder, named after the checklist item itself (uppercased, matching
 * the client folder naming convention) — e.g. a client folder like
 * ".../21. JOHN DOE - SPOUSAL SPONSORSHIP" gets a
 * ".../MARRIAGE CERTIFICATE" subfolder for that specific requirement.
 * Used when a document is marked Accepted. Each requirement gets its own
 * folder rather than everything landing in one shared "APPROVED
 * DOCUMENTS" folder, so files are easy to find by requirement once
 * there are many accepted documents.
 */
function dropboxDocumentFolderPath(string $clientFolderPath, string $docName): array
{
    $safeDocName = preg_replace('/[\/\\\\:*?"<>|]/', '-', trim($docName));
    $path = rtrim($clientFolderPath, '/') . '/' . strtoupper($safeDocName);
    $result = dropboxCreateFolder($path);
    if (!$result['ok']) {
        return $result;
    }
    return ['ok' => true, 'path' => $path];
}

/**
 * Uploads a local file (already saved under UPLOAD_DIR) into a client's
 * Dropbox folder. Used when a document is marked "Accepted". Thin
 * wrapper around dropboxUploadContent() — just reads the file in.
 */
function dropboxUploadFile(string $localPath, string $dropboxFolderPath, string $fileName): array
{
    if (!is_file($localPath)) {
        return ['ok' => false, 'error' => 'Local file not found.'];
    }
    return dropboxUploadContent(file_get_contents($localPath), $dropboxFolderPath, $fileName);
}

/**
 * Uploads raw in-memory content (not a file on disk) into a client's
 * Dropbox folder — used for the compressed-PDF-on-approval feature,
 * where the converted file only ever exists in memory and writing it to
 * a temp file first would be unnecessary extra I/O.
 */
function dropboxUploadContent(string $content, string $dropboxFolderPath, string $fileName): array
{
    $token = dropboxGetAccessToken();
    if (!$token['ok']) {
        return $token;
    }

    $safeFileName = preg_replace('/[\/\\\\:*?"<>|]/', '-', $fileName);
    $dropboxPath = rtrim($dropboxFolderPath, '/') . '/' . $safeFileName;

    $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token['access_token'],
            'Dropbox-API-Arg: ' . json_encode([
                'path' => $dropboxPath,
                'mode' => 'add',
                'autorename' => true,
                'mute' => false,
            ]),
            'Content-Type: application/octet-stream',
        ],
        CURLOPT_POSTFIELDS => $content,
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Could not reach Dropbox: ' . $curlError];
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        return ['ok' => false, 'error' => $data['error_summary'] ?? $response];
    }

    return ['ok' => true, 'dropbox_path' => $data['path_display'] ?? $dropboxPath];
}
