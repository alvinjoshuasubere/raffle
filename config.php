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

// Ensure key-value settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(64) PRIMARY KEY,
    svalue VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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