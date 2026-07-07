<?php
if (isset($_POST['add_event'])) {
    $name = sanitize_input($_POST['name']);
    $description = sanitize_input($_POST['description']);

    if (empty($name)) {
        set_message('error', 'Event name is required.');
    } else {
        $stmt = $conn->prepare("INSERT INTO events (name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $description);
        if ($stmt->execute()) {
            set_message('success', 'Event created successfully!');
        } else {
            set_message('error', 'Could not create event.');
        }
        $stmt->close();
    }
    header('Location: index.php?page=events');
    exit;
}

if (isset($_GET['delete_event'])) {
    $eid = intval($_GET['delete_event']);
    if ($eid <= 1) {
        set_message('error', 'Cannot delete the default event.');
    } else {
        $conn->query("DELETE FROM events WHERE id = $eid");
        set_message('success', 'Event deleted successfully.');
        if ($current_event_id == $eid) {
            $_SESSION['event_id'] = 1;
        }
    }
    header('Location: index.php?page=events');
    exit;
}

$events = $conn->query("
    SELECT e.*,
        (SELECT COUNT(*) FROM participants p WHERE p.event_id = e.id) as participant_count,
        (SELECT COUNT(*) FROM prizes pz WHERE pz.event_id = e.id) as prize_count,
        (SELECT COUNT(*) FROM winners w WHERE w.event_id = e.id) as winner_count
    FROM events e
    ORDER BY e.created_at DESC
");
?>

<h1>Events</h1>

<?php display_message(); ?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:30px;">
    <div>
        <h3 style="color:#ec4899; margin-bottom:15px;">All Events</h3>
        <?php if ($events && $events->num_rows > 0): ?>
            <?php while ($event = $events->fetch_assoc()): ?>
            <div style="background:#faf5f7; border-radius:12px; padding:20px; margin-bottom:12px; border:2px solid <?php echo $event['id'] == $current_event_id ? '#ec4899' : 'transparent'; ?>;">
                <div style="display:flex; justify-content:space-between; align-items:start;">
                    <div>
                        <strong style="font-size:18px; color:#1a1a2e;"><?php echo htmlspecialchars($event['name']); ?></strong>
                        <?php if ($event['description']): ?>
                        <p style="color:#6b7280; font-size:13px; margin-top:4px;"><?php echo htmlspecialchars($event['description']); ?></p>
                        <?php endif; ?>
                        <div style="margin-top:8px; display:flex; gap:15px; font-size:12px; color:#6b7280;">
                            <span>Participants: <?php echo $event['participant_count']; ?></span>
                            <span>Prizes: <?php echo $event['prize_count']; ?></span>
                            <span>Winners: <?php echo $event['winner_count']; ?></span>
                        </div>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <?php if ($event['id'] != $current_event_id): ?>
                        <a href="?page=events&set_event=<?php echo $event['id']; ?>"
                           style="padding:6px 14px; border-radius:20px; background:#ec4899; color:#fff; text-decoration:none; font-size:12px; font-weight:600;">Switch</a>
                        <?php else: ?>
                        <span style="padding:6px 14px; border-radius:20px; background:#fdf2f8; color:#ec4899; font-size:12px; font-weight:600;">Active</span>
                        <?php endif; ?>
                        <?php if ($event['id'] > 1): ?>
                        <a href="?page=events&delete_event=<?php echo $event['id']; ?>"
                           onclick="return confirm('Delete this event and all its data?');"
                           style="padding:6px 14px; border-radius:20px; background:#f9fafb; color:#9ca3af; text-decoration:none; font-size:12px; font-weight:600;">Delete</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="color:#6b7280;">No events yet.</p>
        <?php endif; ?>
    </div>

    <div style="background:#faf5f7; border-radius:16px; padding:30px; border:1px solid rgba(0,0,0,0.04);">
        <h3 style="color:#ec4899; margin-bottom:20px;">Create New Event</h3>
        <form method="POST">
            <div class="form-group">
                <label>Event Name *</label>
                <input type="text" name="name" required placeholder="e.g., Mayors Night 2025">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3" style="width:100%; padding:12px 15px; border:2px solid rgba(0,0,0,0.08); border-radius:5px; font-size:15px; background:#fafafa; resize:vertical;" placeholder="Optional description"></textarea>
            </div>
            <button type="submit" name="add_event" class="btn btn-primary">Create Event</button>
        </form>
    </div>
</div>
