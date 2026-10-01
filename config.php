<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'raffle_system');

// Create database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Function to check if user is logged in
function is_authenticated() {
    return isset($_SESSION['user_id']);
}

// Function to get current event ID from session
function get_current_event_id() {
    return isset($_SESSION['event_id']) ? intval($_SESSION['event_id']) : 1;
}

// Function to resolve the ACTIVE event (status='Active'); falls back to newest, then id 1
function get_active_event_id($conn) {
    $r = $conn->query("SELECT id FROM events WHERE status='Active' ORDER BY id ASC LIMIT 1");
    if ($r && $r->num_rows > 0) {
        return (int)$r->fetch_assoc()['id'];
    }
    $r = $conn->query("SELECT id FROM events ORDER BY id DESC LIMIT 1");
    if ($r && $r->num_rows > 0) {
        return (int)$r->fetch_assoc()['id'];
    }
    return 1;
}

// Function to get event name
function get_event_name($conn, $event_id) {
    $stmt = $conn->prepare("SELECT name FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['name'];
    }
    return 'Unknown Event';
}

// Function to sanitize input
function sanitize_input($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $conn->real_escape_string($data);
}

// Migration: add per-event registration fields column if it does not exist yet
$col_chk = $conn->query("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'events' AND column_name = 'registration_fields'");
if ($col_chk) {
    $col_row = $col_chk->fetch_assoc();
    if ((int)$col_row['c'] === 0) {
        $conn->query("ALTER TABLE events ADD COLUMN registration_fields TEXT NULL AFTER description");
    }
}

// Ensure key-value settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(64) PRIMARY KEY,
    svalue VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Configurable public registration fields (Full Name is always required).
// Key => display label. The key maps to the participants table column.
// The order of this array is the order the fields appear on the form,
// in the Excel template, and in the upload help. Do not reorder casually.
$reg_field_options = [
    'city'           => 'City',
    'barangay'       => 'Barangay',
    'purok'          => 'Purok',
    'contact_number' => 'Contact Number',
];

// Official barangays of Koronadal City (rendered as a dropdown on the form)
$koronadal_barangays = [
    'Assumption',
    'Avanceña',
    'Cacub',
    'Caloocan',
    'Carpenter Hill',
    'Concepcion',
    'Esperanza',
    'General Paulino Santos',
    'Mabini',
    'Magsaysay',
    'Mambucal',
    'Morales',
    'Namnama',
    'New Pangasinan',
    'Paraiso',
    'Rotonda',
    'San Isidro',
    'San Roque',
    'San Jose',
    'Santa Cruz',
    'Santo Niño',
    'Saravia',
    'Topland',
    'Zone 1',
    'Zone 2',
    'Zone 3',
    'Zone 4',
];

// Decode the registration_fields JSON stored on an event.
// Falls back to legacy behaviour (Purok required) for events without settings.
function get_event_reg_fields($conn, $event_id, $raw = null) {
    global $reg_field_options;
    if ($raw === null) {
        $stmt = $conn->prepare("SELECT registration_fields FROM events WHERE id = ?");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $store = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $raw = $store['registration_fields'] ?? null;
    }
    if ($raw !== null && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // Iterate $reg_field_options, not the stored JSON, so events saved
            // before an ordering change still render in the current order.
            $out = [];
            foreach (array_keys($reg_field_options) as $key) {
                if (array_key_exists($key, $decoded)) {
                    $out[$key] = is_array($decoded[$key]) ? $decoded[$key] : [];
                }
            }
            return $out;
        }
    }
    // If an event has no saved field configuration, keep only the
    // universally required Full Name rule. This allows external registration
    // exports (e.g. Google Forms) that contain Full Name + Municipality +
    // Barangay without inventing a Purok requirement.
    return [];
}

// Build the registration_fields JSON from the events form POST data.
function reg_fields_from_post() {
    global $reg_field_options;
    $enabled  = isset($_POST['reg_fields'])   && is_array($_POST['reg_fields'])   ? $_POST['reg_fields']   : [];
    $required = isset($_POST['reg_required']) && is_array($_POST['reg_required']) ? $_POST['reg_required'] : [];
    $out = [];
    foreach (array_keys($reg_field_options) as $fkey) {
        if (in_array($fkey, $enabled, true)) {
            $out[$fkey] = ['required' => in_array($fkey, $required, true)];
        }
    }
    return json_encode($out);
}

// Single source of truth for bulk-upload columns. Full Name is always present;
// the rest follow the active event's configured registration form fields, so the
// Excel template and the upload validation can never drift apart.
function get_upload_columns($conn, $event_id, $raw = null) {
    global $reg_field_options;

    $reg_fields = get_event_reg_fields($conn, $event_id, $raw);

    $cols = [
        ['key' => 'fullname', 'source' => 'fullname', 'label' => 'Full Name',   'required' => true,  'type' => 'text', 'width' => 28],
        ['key' => 'birthdate', 'source' => 'birthdate', 'label' => 'Birthdate', 'required' => false, 'type' => 'text', 'width' => 14],
    ];

    foreach ($reg_fields as $fkey => $cfg) {
        if (!isset($reg_field_options[$fkey])) continue;
        $cols[] = [
            'key'      => $fkey,
            'source'   => $fkey === 'contact_number' ? 'contact' : $fkey,
            'label'    => $reg_field_options[$fkey],
            'required' => !empty($cfg['required']),
            'type'     => $fkey === 'barangay' ? 'list' : 'text',
            'width'    => $fkey === 'barangay' ? 24 : 18,
        ];
    }

    return $cols;
}

// Example values shown in the blank template so users see the expected shape.
function upload_column_example($col) {
    switch ($col['key']) {
        case 'fullname':      return 'MARIA SANTOS REYES';
        case 'birthdate':     return '05/14/1990';
        case 'barangay':      return 'Assumption';
        case 'purok':         return 'Purok 3';
        case 'city':          return 'City of Koronadal';
        case 'contact_number':return '0917 123 4567';
    }
    return '';
}

// Read a cell's text from a sharedStrings <si> element (handles rich-text runs).
function xlsx_si_text($si) {
    if ($si === null) return '';
    if (isset($si->r)) {
        $text = '';
        foreach ($si->r as $run) $text .= isset($run->t) ? (string)$run->t : '';
        return $text;
    }
    return isset($si->t) ? (string)$si->t : '';
}

// Turn an A1-style cell reference ("BC12") into a 0-based column index.
function xlsx_col_index($ref) {
    if (!preg_match('/^([A-Z]+)/i', $ref, $m)) return 0;
    $letters = strtoupper($m[1]);
    $idx = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - 64);
    }
    return $idx - 1;
}

// Parse the first worksheet of an .xlsx into an array of rows (no library needed).
function xlsx_to_rows($path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;

    $shared = [];
    $sxml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sxml !== false && ($sx = simplexml_load_string($sxml)) !== false) {
        foreach ($sx->si as $si) $shared[] = xlsx_si_text($si);
    }

    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false || ($xml = simplexml_load_string($sheet)) === false) return false;

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        // Preserve the real Excel column positions. Do not collapse blank cells:
        // Forms exports commonly contain empty columns between useful fields.
        $cells = [];
        $max_col = -1;
        foreach ($row->c as $c) {
            $col  = xlsx_col_index((string)$c['r']);
            $type = (string)$c['t'];
            if ($type === 's') {
                $val = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = xlsx_si_text(isset($c->is) ? $c->is : null);
            } elseif ($type === 'b') {
                $val = ((string)$c->v === '1') ? 'TRUE' : 'FALSE';
            } else {
                $val = isset($c->v) ? (string)$c->v : '';
            }
            $cells[$col] = $val;
            if ($col > $max_col) $max_col = $col;
        }

        $row_values = [];
        for ($i = 0; $i <= $max_col; $i++) {
            $row_values[$i] = $cells[$i] ?? '';
        }
        $rows[] = $row_values;
    }
    return $rows;
}

// Normalise any supported upload (.csv or .xlsx) into a readable stream of rows,
// so the import loop is identical for every format.
function upload_to_csv_stream($path, $ext) {
    if ($ext === 'xlsx') {
        $rows = xlsx_to_rows($path);
        if ($rows === false) return false;
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            $cells = array_map(function($v) {
                return '"' . str_replace('"', '""', (string)$v) . '"';
            }, $row);
            fwrite($fh, implode(',', $cells) . "\n");
        }
        rewind($fh);
        return $fh;
    }

    $content = file_get_contents($path);
    $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, mb_convert_encoding($content, 'UTF-8', $encoding ?: 'UTF-8'));
    rewind($fh);
    return $fh;
}

// Get a setting value (with default)
function get_setting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT svalue FROM settings WHERE skey = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row !== null ? $row['svalue'] : $default;
}

// Save a setting value
function set_setting($conn, $key, $value) {
    $stmt = $conn->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
    $stmt->bind_param("ss", $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Start a fresh round for an event: wipe the winners log and put every winning
// ticket back into the draw. Participants tagged 'removed' stay out.
function reset_event_winners($conn, $event_id) {
    $event_id = intval($event_id);

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM winners WHERE event_id = ?");
        $del->bind_param("i", $event_id);
        $del->execute();
        $cleared = $del->affected_rows;
        $del->close();

        $upd = $conn->prepare("UPDATE participants SET status = NULL WHERE event_id = ? AND status = 'winner'");
        $upd->bind_param("i", $event_id);
        $upd->execute();
        $returned = $upd->affected_rows;
        $upd->close();

        $conn->commit();
        return ['cleared' => (int)$cleared, 'returned' => (int)$returned];
    } catch (Exception $e) {
        $conn->rollback();
        return ['cleared' => 0, 'returned' => 0];
    }
}

// Function to set flash message
function set_message($type, $message) {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

// Function to display flash message
function display_message() {
    if (isset($_SESSION['message'])) {
        $type = $_SESSION['message_type'];
        $message = $_SESSION['message'];
        $class = $type === 'success' ? 'success' : 'error';
        echo "<div class='alert alert-{$class}'>{$message}</div>";
        echo "<script>setTimeout(function(){ showToast(".json_encode($message).", ".json_encode($type)."); }, 100);</script>";
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
}

// Create uploads directory if not exists
$upload_dir = 'uploads/prizes';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}
?>