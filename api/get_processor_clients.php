<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$processorId = (int)($_GET['processor_id'] ?? 0);
if ($processorId <= 0) {
    jsonResponse(false, 'Processor not found.');
}

$db = getDb();

$stmt = $db->prepare('SELECT id, full_name FROM processors WHERE id = ?');
$stmt->execute([$processorId]);
$processor = $stmt->fetch();
if (!$processor) {
    jsonResponse(false, 'Processor not found.');
}

$stmt = $db->prepare("
  SELECT c.id, c.full_name, c.email, c.case_type, c.phone,
    SUM(CASE WHEN dr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN dr.status = 'received' THEN 1 ELSE 0 END) AS received_count,
    SUM(CASE WHEN dr.status = 'needs_revision' THEN 1 ELSE 0 END) AS revision_count,
    SUM(CASE WHEN dr.status = 'accepted' THEN 1 ELSE 0 END) AS accepted_count,
    COUNT(dr.id) AS total_count
  FROM clients c
  LEFT JOIN document_requests dr ON dr.client_id = c.id
  WHERE c.processor_id = ?
  GROUP BY c.id
  ORDER BY c.full_name ASC
");
$stmt->execute([$processorId]);
$clients = $stmt->fetchAll();

// Completion % = accepted documents out of all documents requested from
// this client. Clients with no document fields opened yet have no
// meaningful percentage, so it's left null rather than shown as 0%.
foreach ($clients as &$c) {
    $total = (int)$c['total_count'];
    $c['completion_pct'] = $total > 0 ? (int)round(((int)$c['accepted_count'] / $total) * 100) : null;
    // Rendered server-side (same helper used everywhere else a case type is
    // shown) so the side panel's colored tag always matches the Classes
    // taxonomy without duplicating that lookup logic in JS.
    $c['case_type_tag_html'] = classTagHtml($c['case_type']);
    // The broader Class category (Economic, Family, Work, Study, Visit,
    // LMIA) this client's case type falls under — the panel groups by
    // this, not by the specific case type. null means no case type is
    // set at all; 'Other' means a case type is set but isn't part of
    // the standard taxonomy (a one-off a processor typed in).
    $caseType = trim((string)($c['case_type'] ?? ''));
    $c['class_category'] = $caseType === '' ? null : (classCategoryFor($caseType) ?? 'Other');
}
unset($c);

jsonResponse(true, '', [
    'processor_name' => $processor['full_name'],
    'clients' => $clients,
    'class_category_order' => classCategoryOrder(),
]);
