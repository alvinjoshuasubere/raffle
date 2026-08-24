<?php
if (isset($_POST['add_event'])) {
    $name = sanitize_input($_POST['name']);
    $description = sanitize_input($_POST['description']);

    if (empty($name)) {
        set_message('error', 'Event name is required.');
    } else {
        $stmt = $conn->prepare("INSERT INTO events (name, description, status) VALUES (?, ?, 'Inactive')");
        $stmt->bind_param("ss", $name, $description);
        if ($stmt->execute()) {
            set_message('success', 'Event created successfully!');
        } else {
            set_message('error', 'Could not create event.');
        }
        $stmt->close();
    }
    header('Location: admin.php?page=events');
    exit;
}

if (isset($_GET['delete_event'])) {
    $eid = intval($_GET['delete_event']);
    if ($eid <= 1) {
        set_message('error', 'Cannot delete the default event.');
    } else {
        $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
        $stmt->bind_param("i", $eid);
        $stmt->execute();
        $stmt->close();
        set_message('success', 'Event deleted successfully.');
        if ($current_event_id == $eid) {
            $_SESSION['event_id'] = get_active_event_id($conn);
        }
    }
    header('Location: admin.php?page=events');
    exit;
}

// Activate an event (single-active model: all others become Inactive)
if (isset($_GET['activate_event'])) {
    $eid = intval($_GET['activate_event']);
    $chk = $conn->prepare("SELECT id FROM events WHERE id = ?");
    $chk->bind_param("i", $eid);
    $chk->execute();
    $exists = $chk->get_result()->num_rows > 0;
    $chk->close();
    if ($exists) {
        $conn->query("UPDATE events SET status='Inactive' WHERE status='Active'");
        $stmt = $conn->prepare("UPDATE events SET status='Active' WHERE id = ?");
        $stmt->bind_param("i", $eid);
        $stmt->execute();
        $stmt->close();
        set_message('success', 'Event activated. The raffle now draws from this event.');
    } else {
        set_message('error', 'Event not found.');
    }
    header('Location: admin.php?page=events');
    exit;
}

// Deactivate an event (raffle falls back to the newest event until one is activated)
if (isset($_GET['deactivate_event'])) {
    $eid = intval($_GET['deactivate_event']);
    $stmt = $conn->prepare("UPDATE events SET status='Inactive' WHERE id = ?");
    $stmt->bind_param("i", $eid);
    $stmt->execute();
    $stmt->close();
    set_message('success', 'Event deactivated. Activate another event to run the raffle on it.');
    header('Location: admin.php?page=events');
    exit;
}

// Update an existing event
if (isset($_POST['update_event'])) {
    $eid = intval($_POST['event_id']);
    $name = sanitize_input($_POST['name']);
    $description = sanitize_input($_POST['description']);

    if (empty($name)) {
        set_message('error', 'Event name is required.');
    } else {
        $stmt = $conn->prepare("UPDATE events SET name=?, description=? WHERE id=?");
        $stmt->bind_param("ssi", $name, $description, $eid);
        if ($stmt->execute()) {
            set_message('success', 'Event updated successfully!');
        } else {
            set_message('error', 'Could not update event.');
        }
        $stmt->close();
    }
    header('Location: admin.php?page=events');
    exit;
}

// Load event for editing (prefills the form)
$editing = null;
if (isset($_GET['edit_event'])) {
    $eid = intval($_GET['edit_event']);
    $stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->bind_param("i", $eid);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$events = $conn->query("
    SELECT e.*,
        (SELECT COUNT(*) FROM participants p WHERE p.event_id = e.id) as participant_count,
        (SELECT COUNT(*) FROM winners w WHERE w.event_id = e.id) as winner_count
    FROM events e
    ORDER BY e.created_at DESC
");
?>

<h1>Events</h1>

<?php display_message(); ?>

<style>
    h1 { color:#0f172a; }
    .ev-actions { display:flex; gap:8px; align-items:center; flex-shrink:0; }
    .ev-btn {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-width:106px; padding:9px 18px; border-radius:8px;
        font-size:12.5px; font-weight:600; letter-spacing:.2px;
        text-decoration:none; cursor:pointer; text-align:center;
        border:1px solid #d1d5db; background:#fff; color:#374151;
        transition:background .15s ease, border-color .15s ease, color .15s ease;
    }
    .ev-btn:hover { background:#f9fafb; border-color:#9ca3af; color:#111827; }
    .ev-btn-primary { background:#1e293b; border-color:#1e293b; color:#fff; }
    .ev-btn-primary:hover { background:#0f172a; border-color:#0f172a; color:#fff; }
    .ev-btn-danger { color:#b91c1c; }
    .ev-btn-danger:hover { background:#fef2f2; border-color:#fca5a5; color:#991b1b; }
    .ev-badge {
        display:inline-flex; align-items:center; justify-content:center;
        min-width:96px; padding:8px 14px; border-radius:6px;
        font-size:12px; font-weight:700; letter-spacing:.4px; text-transform:uppercase;
    }
    .ev-badge-active   { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
    .ev-badge-inactive { background:#f3f4f6; color:#6b7280; border:1px solid #e5e7eb; }

    .ev-card {
        background:#fff; border:1px solid #e5e7eb; border-left:4px solid transparent;
        border-radius:10px; padding:20px 22px; margin-bottom:14px;
    }
    .ev-card-active { border-left-color:#059669; }
    .ev-name { font-size:17px; font-weight:700; color:#0f172a; }
    .ev-desc { color:#64748b; font-size:13px; margin-top:4px; }
    .ev-meta { margin-top:10px; display:flex; gap:16px; font-size:12px; color:#94a3b8; font-weight:500; }
    .ev-panel {
        background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:28px;
    }
    .ev-panel h3 { color:#0f172a; margin-bottom:18px; font-size:16px; font-weight:700; }
    .ev-hint { color:#94a3b8; font-size:12px; margin-top:4px; }
</style>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:30px;">
    <div>
        <h3 style="color:#0f172a; margin-bottom:15px; font-size:16px;">All Events</h3>
        <?php if ($events && $events->num_rows > 0): ?>
            <?php while ($event = $events->fetch_assoc()): ?>
            <div class="ev-card<?php echo $event['status'] === 'Active' ? ' ev-card-active' : ''; ?>">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:14px;">
                    <div>
                        <span class="ev-name"><?php echo htmlspecialchars($event['name']); ?></span>
                        <?php if ($event['description']): ?>
                        <p class="ev-desc"><?php echo htmlspecialchars($event['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($event['status'] === 'Active'): ?>
                    <span class="ev-badge ev-badge-active">&#9679; Active</span>
                    <?php else: ?>
                    <span class="ev-badge ev-badge-inactive">Inactive</span>
                    <?php endif; ?>
                </div>
                <div class="ev-meta">
                    <span>Participants: <?php echo $event['participant_count']; ?></span>
                    <span>Winners: <?php echo $event['winner_count']; ?></span>
                </div>
                <div class="ev-actions" style="margin-top:16px; padding-top:14px; border-top:1px solid #f1f5f9;">
                    <a href="?page=events&edit_event=<?php echo $event['id']; ?>" class="ev-btn"><i class="fas fa-pen"></i> Edit</a>
                    <?php if ($event['status'] === 'Active'): ?>
                    <a href="?page=events&deactivate_event=<?php echo $event['id']; ?>"
                       onclick="return confirm('Deactivate this event? The raffle will stop drawing from it.');"
                       class="ev-btn"><i class="fas fa-power-off"></i> Deactivate</a>
                    <?php else: ?>
                    <?php
                    $activate_msg = 'Activate "' . htmlspecialchars($event['name']) . '"? The raffle (wheel, draw, prizes, winners) will use it.';
                    if ($event['participant_count'] < 1) {
                        $activate_msg .= '\n\nWARNING: This event has no participants yet. Upload or register participants after activating.';
                    }
                    ?>
                    <a href="?page=events&activate_event=<?php echo $event['id']; ?>"
                       onclick="return confirm('<?php echo $activate_msg; ?>');"
                       class="ev-btn ev-btn-primary"><i class="fas fa-circle-check"></i> Set Active</a>
                    <?php endif; ?>
                    <?php if ($event['id'] > 1): ?>
                    <a href="?page=events&delete_event=<?php echo $event['id']; ?>"
                       onclick="return confirm('Delete this event and all its data?');"
                       class="ev-btn ev-btn-danger"><i class="fas fa-trash"></i> Delete</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="color:#6b7280;">No events yet.</p>
        <?php endif; ?>
    </div>

    <div class="ev-panel">
        <h3><?php echo $editing ? 'Edit Event: ' . htmlspecialchars($editing['name']) : 'Create New Event'; ?></h3>
        <form method="POST">
            <?php if ($editing): ?>
            <input type="hidden" name="event_id" value="<?php echo $editing['id']; ?>">
            <?php endif; ?>
            <div class="form-group">
                <label>Event Name *</label>
                <input type="text" name="name" required placeholder="e.g., Mayors Night 2025"
                       value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3" style="width:100%; padding:12px 15px; border:2px solid rgba(0,0,0,0.08); border-radius:5px; font-size:15px; background:#fafafa; resize:vertical;" placeholder="Optional description"><?php echo htmlspecialchars($editing['description'] ?? ''); ?></textarea>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <button type="submit" name="<?php echo $editing ? 'update_event' : 'add_event'; ?>"
                        class="ev-btn ev-btn-primary" style="min-width:140px;">
                    <?php echo $editing ? 'Update Event' : 'Create Event'; ?>
                </button>
                <?php if ($editing): ?>
                <a href="admin.php?page=events" class="ev-btn">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
