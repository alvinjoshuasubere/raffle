<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$type = (isset($_GET['type']) && $_GET['type'] === 'attachment') ? 'attachment' : 'photo';

if ($id <= 0) {
    http_response_code(404);
    exit('Not found');
}

// Whitelisted column mapping
$col = $type === 'attachment' ? 'registration_attachment' : 'photo_data';

$stmt = $conn->prepare("SELECT number, name, `$col` AS blob_data FROM participants WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || $row['blob_data'] === null || $row['blob_data'] === '') {
    http_response_code(404);
    exit('Not found');
}

$data = $row['blob_data'];

// Detect real MIME type from magic bytes
function blob_mime($d) {
    if (substr($d, 0, 3) === "\xFF\xD8\xFF") return ['image/jpeg', 'jpg'];
    if (substr($d, 0, 8) === "\x89PNG\r\n\x1a\n") return ['image/png', 'png'];
    if (substr($d, 0, 6) === 'GIF87a' || substr($d, 0, 6) === 'GIF89a') return ['image/gif', 'gif'];
    if (substr($d, 0, 4) === 'RIFF' && substr($d, 8, 4) === 'WEBP') return ['image/webp', 'webp'];
    if (substr($d, 0, 2) === 'BM') return ['image/bmp', 'bmp'];
    if (substr($d, 0, 5) === '%PDF-') return ['application/pdf', 'pdf'];
    if (substr($d, 0, 4) === "PK\x03\x04") return ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'];
    if (substr($d, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return ['application/msword', 'doc'];
    return ['application/octet-stream', 'bin'];
}

list($mime, $ext) = blob_mime($data);

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($data));
header('Cache-Control: private, max-age=300');

if ($type === 'attachment') {
    $safe = preg_replace('/[^A-Za-z0-9 _-]/', '', $row['name']);
    header('Content-Disposition: attachment; filename="Participant-' . $row['number'] . '-' . $safe . '.' . $ext . '"');
} else {
    header('Content-Disposition: inline; filename="participant-' . $row['number'] . '.' . $ext . '"');
}

echo $data;
exit;
