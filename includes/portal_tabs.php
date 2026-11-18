<?php
/**
 * includes/portal_tabs.php — Notion-style tabs for the whole staff portal.
 *
 * A staff page opened normally (a top-level browser navigation) is answered
 * with the tab shell (admin/_portal_tabs.php): sidebar + tab bar, with the
 * page itself loaded in a kept-alive iframe. Inside that frame the same
 * page renders "embedded" (no sidebar), so every tab keeps its own state
 * (typed text, scroll, open documents) while you switch.
 *
 * Embedded is detected from the Sec-Fetch-Dest header the browser sends
 * (so links clicked inside a frame stay embedded) or an explicit ?embed=1.
 * A browser that sends no Sec-Fetch-Dest simply gets the ordinary
 * un-tabbed pages.
 */

/** Pages that can live in a tab (script basenames without .php). */
function portalTabPages(): array
{
    return ['frontdesk', 'clients', 'client', 'inbox', 'chats', 'processors',
            'staff', 'activity', 'profile', 'dashboard', 'classes'];
}

function portalEmbedded(): bool
{
    if (isset($_GET['embed'])) return true;
    return strtolower($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe';
}

/**
 * Called at the end of requireAdmin(): answers a normal page load of a
 * tab-able staff page with the shell, then stops.
 */
function portalTabsMaybeShell(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    if (!in_array($page, portalTabPages(), true)) return;
    if (portalEmbedded()) return;
    // Only a real page navigation; never downloads, fetches or JSON views.
    if (strtolower($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') !== 'document') return;
    if (isset($_GET['format']) || isset($_GET['raw'])) return;

    // Anything going wrong while building the shell falls back to the
    // ordinary page rather than locking staff out.
    try {
        require_once __DIR__ . '/functions.php';
        require_once __DIR__ . '/messages.php';

        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        $start = $page . '.php' . ($qs !== '' ? '?' . $qs : '');
        $ptData = [
            'start' => $start,
            'pages' => array_map(fn($p) => $p . '.php', portalTabPages()),
            'directory' => array_map(fn($c) => [
                'id'    => $c['client_id'],
                'name'  => $c['full_name'],
                'email' => $c['email'],
                'ref'   => $c['ref'],
                'case'  => $c['case_type'],
            ], staffClientDirectory()),
        ];
    } catch (Throwable $e) {
        error_log('portal tabs shell: ' . $e->getMessage());
        return;
    }
    $activeNav = 'clients';
    include __DIR__ . '/../admin/_portal_tabs.php';
    exit;
}
