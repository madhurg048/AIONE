<?php

require_once __DIR__ . '/_bootstrap.php';


/* =========================
   LOGIN REQUIRED
   ========================= */

require_login();


$db = db();


/* =========================
   VALIDATE TICKET ID
   ========================= */

$ticketId = (int) ($_GET['id'] ?? 0);


if ($ticketId <= 0) {

    http_response_code(400);

    exit('Invalid ticket ID');
}


/* =========================
   GET ATTACHMENT
   ========================= */

$stmt = $db->prepare("
    SELECT
        t.id,
        t.lead_id,
        t.vertical,
        t.attachment_data,
        t.attachment_name,
        t.attachment_type
    FROM crm_tickets t
    WHERE t.id = ?
      AND t.attachment_data IS NOT NULL
    LIMIT 1
");


$stmt->execute([$ticketId]);


$file = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$file) {

    http_response_code(404);

    exit('Attachment not found');
}


/* =========================
   AUTHORIZATION
   ========================= */

if (current_role() !== 'admin') {

    if (
        ($file['vertical'] ?? null)
        !== current_vertical()
    ) {

        http_response_code(403);

        exit('Not authorized');
    }
}


/* =========================
   FILE HEADERS
   ========================= */

$mime = trim(
    $file['attachment_type']
    ?? ''
);


if ($mime === '') {
    $mime = 'application/octet-stream';
}


$fileName = basename(
    $file['attachment_name']
    ?: 'attachment'
);


header(
    'Content-Type: ' . $mime
);


header(
    'Content-Length: ' .
    strlen($file['attachment_data'])
);


header(
    'Content-Disposition: inline; filename="' .
    addcslashes($fileName, "\"\\") .
    '"'
);


header(
    'X-Content-Type-Options: nosniff'
);


header(
    'Cache-Control: private, max-age=300'
);


/* =========================
   OUTPUT BLOB
   ========================= */

echo $file['attachment_data'];

exit;