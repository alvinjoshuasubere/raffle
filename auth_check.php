<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get current event from session or default
if (!isset($_SESSION['event_id'])) {
    $default = $conn->query("SELECT id FROM events WHERE status='Active' ORDER BY id ASC LIMIT 1");
    if ($default && $default->num_rows > 0) {
        $_SESSION['event_id'] = $default->fetch_assoc()['id'];
    } else {
        $_SESSION['event_id'] = 1;
    }
}

$current_event_id = intval($_SESSION['event_id']);

// Switch event if requested
if (isset($_GET['set_event'])) {
    $eid = intval($_GET['set_event']);
    $check = $conn->query("SELECT id FROM events WHERE id = $eid");
    if ($check && $check->num_rows > 0) {
        $_SESSION['event_id'] = $eid;
        $current_event_id = $eid;
    }
    $page_clean = isset($_GET['page']) ? $_GET['page'] : 'upload';
    header("Location: index.php?page=$page_clean");
    exit;
}

// Get current event name
$event_row = $conn->query("SELECT name FROM events WHERE id = $current_event_id");
$current_event_name = ($event_row && $event_row->num_rows > 0) 
    ? $event_row->fetch_assoc()['name'] 
    : 'Unknown Event';
