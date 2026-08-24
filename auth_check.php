<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// The current event is ALWAYS the one marked Active in the events table,
// so the raffle (draw, wheel, prizes, winners, uploads) follows the active event.
$current_event_id = get_active_event_id($conn);
$_SESSION['event_id'] = $current_event_id;

// Get current event name
$event_row = $conn->query("SELECT name FROM events WHERE id = $current_event_id");
$current_event_name = ($event_row && $event_row->num_rows > 0)
    ? $event_row->fetch_assoc()['name']
    : 'Unknown Event';
