<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_auth.php';

$me = sales_guard();

$ticketId = (int) ($_GET['id'] ?? 0);

if ($ticketId <= 0) {
    http_response_code(400);
    exit('Invalid ticket');
}

$db = getDB();

$stmt = $db->prepare("
    SELECT
        attachment_data,
        attachment_name,
        attachment_type,
        vertical
    FROM crm_tickets
    WHERE id = ?
      AND attachment_data IS NOT NULL
    LIMIT 1
");

$stmt->execute([$ticketId]);

$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    http_response_code(404);
    exit('Attachment not found');
}

/*
 * Add your existing role/vertical authorization check here.
 * Do not allow an authenticated employee from an unrelated
 * vertical to fetch arbitrary ticket attachments by changing id.
 */

$mime = $file['attachment_type']
    ?: 'application/octet-stream';

$name = $file['attachment_name']
    ?: 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($file['attachment_data']));
header(
    'Content-Disposition: inline; filename="' .
    addcslashes($name, "\"\\") .
    '"'
);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

echo $file['attachment_data'];
exit;