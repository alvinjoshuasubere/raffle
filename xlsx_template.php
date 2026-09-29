<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

$event_id   = get_active_event_id($conn);
$event_name = get_event_name($conn, $event_id);
$columns    = get_upload_columns($conn, $event_id);

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Excel export unavailable on this server.');
}

function xe($s) {
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xl_col($i) { // 0-based index -> A, B, ... AA
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

// Shared strings for every literal we write
$strings = [];
$str_idx = function($text) use (&$strings) {
    $text = (string)$text;
    if (!isset($strings[$text])) $strings[$text] = count($strings);
    return $strings[$text];
};

// Header cells: label + "*" appended to required ones
$header_cells = '';
$col_letters  = [];
foreach ($columns as $i => $col) {
    $letter = xl_col($i);
    $col_letters[$col['key']] = $letter;
    $label  = $col['label'] . ($col['required'] ? ' *' : '');
    $header_cells .= '<c r="' . $letter . '1" t="s" s="1"><v>' . $str_idx($label) . '</v></c>';
}

// One example row so the expected format is obvious
$example_cells = '';
foreach ($columns as $i => $col) {
    $letter = xl_col($i);
    $val    = upload_column_example($col);
    if ($val === '') {
        $example_cells .= '<c r="' . $letter . '2" s="3"/>';
    } else {
        $example_cells .= '<c r="' . $letter . '2" t="s" s="3"><v>' . $str_idx($val) . '</v></c>';
    }
}

// Dropdown validation for Barangay
$validations = '';
if (isset($col_letters['barangay'])) {
    $list = array_map(function($b) { return '"' . str_replace('"', '', $b) . '"'; }, $koronadal_barangays);
    $validations = '<dataValidations count="1">'
        . '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
        . ' sqref="' . $col_letters['barangay'] . '2:' . $col_letters['barangay'] . '10000">'
        . '<formula1>' . xe(implode(',', $list)) . '</formula1>'
        . '</dataValidation></dataValidations>';
}

// Column widths
$cols_xml = '<cols>';
foreach ($columns as $i => $col) {
    $cols_xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$col['width'] . '" customWidth="1"/>';
}
$cols_xml .= '</cols>';

$sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView workbookViewId="0">'
    . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
    . '</sheetView></sheetViews>'
    . $cols_xml
    . '<sheetData>'
    . '<row r="1">' . $header_cells . '</row>'
    . '<row r="2">' . $example_cells . '</row>'
    . '</sheetData>'
    . $validations
    . '</worksheet>';

// sharedStrings.xml
$ss_items = '';
foreach (array_keys($strings) as $s) {
    $ss_items .= '<si><t xml:space="preserve">' . xe($s) . '</t></si>';
}
$shared_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">'
    . $ss_items . '</sst>';

// styles.xml: 0=normal 1=header(pink,bold) 2=note 3=example(grey italic)
$styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="3">'
    . '<font><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
    . '<font><i/><sz val="11"/><color rgb="FF808080"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="3">'
    . '<fill><patternFill patternType="none"/></fill>'
    . '<fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFEC4899"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="4">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '</cellXfs>'
    . '</styleSheet>';

$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
    . '</Types>';

$root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Participants" sheetId="1" r:id="rId1"/></sheets></workbook>';

$wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
    . '</Relationships>';

$filename = 'participant-template-' . preg_replace('/[^a-z0-9]+/i', '-', $event_name) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$tmp = tempnam(sys_get_temp_dir(), 'xlsx');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', $content_types);
$zip->addFromString('_rels/.rels', $root_rels);
$zip->addFromString('xl/workbook.xml', $workbook_xml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
$zip->addFromString('xl/styles.xml', $styles_xml);
$zip->addFromString('xl/sharedStrings.xml', $shared_xml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
$zip->close();

readfile($tmp);
unlink($tmp);
exit;
