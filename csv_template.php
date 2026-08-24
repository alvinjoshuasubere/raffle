<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="participant-template.csv"');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel renders characters correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Lastname', 'Firstname', 'Middlename', 'Birthdate', 'Barangay', 'Purok', 'Contact Number']);
fputcsv($out, ['SANTOS', 'MARIA', 'REYES', '05/14/1990', 'Assumption', 'Purok 3', '0917 123 4567']);
fputcsv($out, ['DELA CRUZ', 'JUAN', '', '11/02/1985', 'Carpenter Hill', 'Purok 1', '0998 765 4321']);

fclose($out);
exit;
