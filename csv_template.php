<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="participant-template.csv"');

$event_id = get_active_event_id($conn);
$columns  = get_upload_columns($conn, $event_id);

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel renders characters correctly
fwrite($out, "\xEF\xBB\xBF");

// Header row mirrors the Excel template, with the same required markers
$header = [];
$example = [];
foreach ($columns as $col) {
    $header[]  = $col['label'] . ($col['required'] ? ' *' : '');
    $example[] = upload_column_example($col);
}
fputcsv($out, $header);
fputcsv($out, $example);
fputcsv($out, $example);

fclose($out);
exit;
