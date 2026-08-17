<?php
// Add prize
if (isset($_POST['add_prize'])) {
    $name = sanitize_input($_POST['name']);
    $type = in_array($_POST['type'] ?? '', ['Major', 'Minor']) ? $_POST['type'] : 'Minor';
    $quantity = max(1, intval($_POST['quantity'] ?? 1));
    $image = '';

    if ($name === '') {
        set_message('error', 'Prize name is required.');
    } else {
        if (isset($_FILES['prize_image']) && $_FILES['prize_image']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($_FILES['prize_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $dir = 'uploads/prizes';
                if (!file_exists($dir)) mkdir($dir, 0777, true);
                $fname = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', basename($_FILES['prize_image']['name']));
                if (move_uploaded_file($_FILES['prize_image']['tmp_name'], $dir . '/' . $fname)) {
                    $image = 'uploads/prizes/' . $fname;
                }
            }
        }

        $stmt = $conn->prepare("INSERT INTO prizes (event_id, name, image, quantity, type, enabled) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("issis", $current_event_id, $name, $image, $quantity, $type);
        if ($stmt->execute()) {
            set_message('success', 'Prize added successfully!');
        } else {
            set_message('error', 'Could not add prize.');
        }
        $stmt->close();
    }
    header('Location: admin.php?page=prizes');
    exit;
}

// Delete prize
if (isset($_GET['delete_prize'])) {
    $pid = intval($_GET['delete_prize']);
    $stmt = $conn->prepare("SELECT image FROM prizes WHERE id = ? AND event_id = ?");
    $stmt->bind_param("ii", $pid, $current_event_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        if ($row['image'] && file_exists($row['image'])) unlink($row['image']);
        $del = $conn->prepare("DELETE FROM prizes WHERE id = ? AND event_id = ?");
        $del->bind_param("ii", $pid, $current_event_id);
        $del->execute();
        set_message('success', 'Prize deleted.');
    }
    header('Location: admin.php?page=prizes');
    exit;
}

// Reset claimed count (re-enable prize)
if (isset($_GET['reset_prize'])) {
    $pid = intval($_GET['reset_prize']);
    $upd = $conn->prepare("UPDATE prizes SET claimed = 0, enabled = 1 WHERE id = ? AND event_id = ?");
    $upd->bind_param("ii", $pid, $current_event_id);
    $upd->execute();
    set_message('success', 'Prize reset (available again).');
    header('Location: admin.php?page=prizes');
    exit;
}

$prizes = $conn->prepare("SELECT * FROM prizes WHERE event_id = ? ORDER BY type = 'Major' DESC, id DESC");
$prizes->bind_param("i", $current_event_id);
$prizes->execute();
$prizes = $prizes->get_result();
?>

<h1>Prize Management</h1>

<?php display_message(); ?>

<div style="display:grid; grid-template-columns:1fr 1.4fr; gap:30px; align-items:start;">
    <div style="background:#faf5f7; border-radius:16px; padding:30px; border:1px solid rgba(0,0,0,0.04);">
        <h3 style="color:#ec4899; margin-bottom:20px;">Add New Prize</h3>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Prize Name *</label>
                <input type="text" name="name" required placeholder="e.g., 43\" Smart TV">
            </div>
            <div class="form-group">
                <label>Prize Image</label>
                <input type="file" name="prize_image" accept=".jpg,.jpeg,.png,.gif,.webp">
            </div>
            <div class="form-row" style="gap:14px;">
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type">
                        <option value="Minor">Minor</option>
                        <option value="Major">Major</option>
                    </select>
                </div>
            </div>
            <button type="submit" name="add_prize" class="btn btn-primary">Add Prize</button>
        </form>
    </div>

    <div>
        <h3 style="color:#ec4899; margin-bottom:15px;">Prize List</h3>
        <?php if ($prizes->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Prize</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th>Claimed</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($prize = $prizes->fetch_assoc()): ?>
                <?php $remaining = $prize['quantity'] - $prize['claimed']; ?>
                <tr>
                    <td>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <?php if ($prize['image'] && file_exists($prize['image'])): ?>
                            <img src="<?php echo htmlspecialchars($prize['image']); ?>" class="prize-img" alt="">
                            <?php else: ?>
                            <div style="width:52px; height:52px; border-radius:10px; background:#fdf2f8; display:flex; align-items:center; justify-content:center; font-size:20px;">🎁</div>
                            <?php endif; ?>
                            <strong style="color:#1a1a2e;"><?php echo htmlspecialchars($prize['name']); ?></strong>
                        </div>
                    </td>
                    <td><span class="badge <?php echo $prize['type'] === 'Major' ? 'badge-major' : 'badge-minor'; ?>"><?php echo $prize['type']; ?></span></td>
                    <td><?php echo $prize['quantity']; ?></td>
                    <td><?php echo $prize['claimed']; ?></td>
                    <td>
                        <?php if ($prize['enabled'] && $remaining > 0): ?>
                        <span class="badge badge-active">Available</span>
                        <?php else: ?>
                        <span class="badge badge-disabled">Fully Claimed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?page=prizes&reset_prize=<?php echo $prize['id']; ?>" title="Reset claim count"
                           style="padding:5px 12px; border-radius:20px; background:#ecfdf5; color:#059669; text-decoration:none; font-size:11px; font-weight:600;">Reset</a>
                        <a href="?page=prizes&delete_prize=<?php echo $prize['id']; ?>"
                           onclick="return confirm('Delete this prize?');"
                           style="padding:5px 12px; border-radius:20px; background:#fef2f2; color:#dc2626; text-decoration:none; font-size:11px; font-weight:600; margin-left:4px;">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div style="text-align:center; padding:40px; background:#faf5f7; border-radius:16px; border:1px solid rgba(0,0,0,0.04);">
            <p style="color:#6b7280;">No prizes yet. Add your first prize to start the draw.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
