<?php
// Handle Add Prize
if (isset($_POST['add_prize'])) {
    $prize_name = sanitize_input($_POST['prize_name']);
    $quantity = intval($_POST['quantity']);
    $type = sanitize_input($_POST['type']);
    
    // Validation
    if (empty($prize_name)) {
        set_message('error', 'Error: Prize name is required.');
    } elseif ($quantity < 1) {
        set_message('error', 'Error: Quantity must be at least 1.');
    } elseif (!in_array($type, ['Major', 'Minor'])) {
        set_message('error', 'Error: Invalid prize type.');
    } else {
        $image_path = null;
        
        // Handle image upload
        if (isset($_FILES['prize_image']) && $_FILES['prize_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['prize_image'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            
            if (!in_array($file['type'], $allowed_types)) {
                set_message('error', 'Error: Only JPG, PNG, and GIF images are allowed.');
                header('Location: index.php?page=prize');
                exit;
            }
            
            if ($file['size'] > 5000000) { // 5MB
                set_message('error', 'Error: Image size must be less than 5MB.');
                header('Location: index.php?page=prize');
                exit;
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $extension;
            $upload_path = 'uploads/prizes/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $image_path = $upload_path;
            }
        }
        
        // Insert prize
        $stmt = $conn->prepare("INSERT INTO prizes (prize_name, image_path, quantity, original_quantity, type) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiis", $prize_name, $image_path, $quantity, $quantity, $type);
        
        if ($stmt->execute()) {
            set_message('success', 'Prize added successfully!');
        } else {
            set_message('error', 'Error: Could not add prize.');
        }
        $stmt->close();
    }
    
    header('Location: index.php?page=prize');
    exit;
}

// Handle Delete Prize
if (isset($_GET['delete_prize'])) {
    $prize_id = intval($_GET['delete_prize']);
    
    // Get image path
    $result = $conn->query("SELECT image_path FROM prizes WHERE id = $prize_id");
    if ($result->num_rows > 0) {
        $prize = $result->fetch_assoc();
        
        // Delete image file if exists
        if ($prize['image_path'] && file_exists($prize['image_path'])) {
            unlink($prize['image_path']);
        }
        
        // Delete prize
        $conn->query("DELETE FROM prizes WHERE id = $prize_id");
        set_message('success', 'Prize deleted successfully!');
    }
    
    header('Location: index.php?page=prize');
    exit;
}

// Handle Edit Quantity
if (isset($_POST['edit_quantity'])) {
    $prize_id = intval($_POST['prize_id']);
    $new_original_quantity = intval($_POST['new_quantity']);

    // Get the current original and available quantity from the database
    $result = $conn->query("SELECT status, original_quantity, quantity FROM prizes WHERE id = $prize_id");
    $row = $result->fetch_assoc();
    $old_original_quantity = $row['original_quantity'];
    $old_quantity = $row['quantity'];
    $claimed = $old_original_quantity - $old_quantity;

    if ($new_original_quantity < $claimed) {
        set_message('error', 'Error: You cannot set the total quantity lower than the number already claimed ('.$claimed.').');
    } else {
        $new_available = $new_original_quantity - $claimed;
        // If currently disabled and new available > 0, set to Active
        if ($row && $row['status'] == 'Disabled' && $new_available > 0) {
            $conn->query("UPDATE prizes SET quantity = $new_available, original_quantity = $new_original_quantity, status = 'Active' WHERE id = $prize_id");
            set_message('success', 'Prize quantity updated and status set to Active!');
        } else {
            $conn->query("UPDATE prizes SET quantity = $new_available, original_quantity = $new_original_quantity WHERE id = $prize_id");
            set_message('success', 'Prize quantity updated!');
        }
    }
    header('Location: index.php?page=prize');
    exit;
}

// Handle Add Multiple Prizes
if (isset($_POST['add_prize_multi'])) {
    $names = $_POST['prize_name'];
    $quantities = $_POST['quantity'];
    $types = $_POST['type'];
    $images = $_FILES['prize_image'];

    $success_count = 0;
    $error_count = 0;

    for ($i = 0; $i < count($names); $i++) {
        $prize_name = sanitize_input($names[$i]);
        $quantity = intval($quantities[$i]);
        $type = sanitize_input($types[$i]);
        $image_path = null;

        // Validation
        if (empty($prize_name) || $quantity < 1 || !in_array($type, ['Major', 'Minor'])) {
            $error_count++;
            continue;
        }

        // Handle image upload for this prize
        if (isset($images['name'][$i]) && $images['error'][$i] === UPLOAD_ERR_OK) {
            $file_type = $images['type'][$i];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            if (in_array($file_type, $allowed_types) && $images['size'][$i] <= 5000000) {
                $extension = pathinfo($images['name'][$i], PATHINFO_EXTENSION);
                $filename = uniqid() . '.' . $extension;
                $upload_path = 'uploads/prizes/' . $filename;
                if (move_uploaded_file($images['tmp_name'][$i], $upload_path)) {
                    $image_path = $upload_path;
                }
            }
        }

        // Insert prize
        $stmt = $conn->prepare("INSERT INTO prizes (prize_name, image_path, quantity, original_quantity, type) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiis", $prize_name, $image_path, $quantity, $quantity, $type);
        if ($stmt->execute()) {
            $success_count++;
        } else {
            $error_count++;
        }
        $stmt->close();
    }

    if ($success_count > 0) {
        set_message('success', "$success_count prize(s) added successfully!");
    }
    if ($error_count > 0) {
        set_message('error', "$error_count prize(s) failed to add. Please check your input.");
    }

    header('Location: index.php?page=prize');
    exit;
}

// Get all prizes
$prizes = $conn->query("SELECT * FROM prizes ORDER BY type, id DESC");
?>

<h1>Prize Management</h1>

<?php display_message(); ?>

<!-- Prize Form -->
<div class="prize-form">
    <h2 style="color: #DC143C; margin-bottom: 20px;">Add New Prizes</h2>
    <form method="POST" enctype="multipart/form-data" id="multiPrizeForm">
        <div id="prizeInputs">
            <div class="prize-input-row" style="border-bottom:1px solid #eee; margin-bottom:10px; padding-bottom:10px;">
                <div class="form-row">
                    <div class="form-group">
                        <label>Prize Name *</label>
                        <input type="text" name="prize_name[]" required>
                    </div>
                    <div class="form-group">
                        <label>Prize Image</label>
                        <input type="file" name="prize_image[]" accept="image/*">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" name="quantity[]" min="1" value="1" required>
                    </div>
                    <div class="form-group">
                        <label>Type *</label>
                        <select name="type[]" required>
                            <option value="">Select Type</option>
                            <option value="Major">Major</option>
                            <option value="Minor">Minor</option>
                        </select>
                    </div>
                </div>

            </div>
        </div>
        <div style="text-align:center; margin-top:15px;">
            <button type="submit" name="add_prize_multi" class="btn btn-primary" style="display:inline-block;">
                Add Prize
            </button>
        </div>
    </form>
</div>


<!-- <h2 style="color: #DC143C; margin: 30px 0 15px 0;">Prize List</h2> -->

<?php if ($prizes->num_rows > 0): ?>
<table>
    <thead>
        <tr>
            <th>Image</th>
            <th>Prize Name</th>
            <th>Quantity</th>
            <th>Type</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($prize = $prizes->fetch_assoc()): ?>
        <tr>
            <td>
                <?php if ($prize['image_path'] && file_exists($prize['image_path'])): ?>
                <img src="<?php echo htmlspecialchars($prize['image_path']); ?>" alt="Prize"
                    style="width:50px; height:50px; object-fit:cover; border-radius:6px;">
                <?php else: ?>
                <span style="color: #999;">No Image</span>
                <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($prize['prize_name']); ?></td>
            <td>
                <?php echo $prize['quantity']; ?> / <?php echo $prize['original_quantity']; ?>

            </td>
            <td><?php echo $prize['type']; ?></td>
            <td>
                <?php if ($prize['status'] == 'Disabled' || $prize['quantity'] == 0): ?>
                <span style="color:#dc3545;">Disabled</span>
                <?php else: ?>
                <span style="color:#28a745;">Active</span>
                <?php endif; ?>
            </td>
            <td>
                <a type="button"
                    style="color:#fff; background:#0D77CB; padding:5px 12px; border-radius:4px; text-decoration:none; font-size:13px;margin-left: 3px"
                    class="edit-qty-btn" data-id="<?php echo $prize['id']; ?>"
                    data-qty="<?php echo $prize['quantity']; ?>"
                    data-original-qty="<?php echo $prize['original_quantity']; ?>"
                    style="margin-left:8px; padding:2px 8px; font-size:12px;">Edit</a>
                <a href="?page=prize&delete_prize=<?php echo $prize['id']; ?>"
                    style="color:#fff; background:black; padding:5px 12px; border-radius:4px; text-decoration:none; font-size:13px;"
                    onclick="return confirm('Are you sure you want to delete this prize?');">
                    Delete
                </a>

            </td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
<!-- Edit Quantity Modal -->
<div id="editQtyModal"
    style="display:none; position:fixed; left:0; top:0; width:100vw; height:100vh; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; z-index:9999;">
    <form method="POST"
        style="background:#fff; padding:30px 25px; border-radius:10px; min-width:280px; max-width:90vw; margin:auto; position:relative;">
        <h3 style="margin-bottom:18px;">Edit Quantity</h3>
        <input type="hidden" name="prize_id" id="editQtyPrizeId">
        <div style="margin-bottom:10px;">
            <span style="color:#555;">Old Quantity: <span id="oldQtyDisplay" style="font-weight:bold;"></span></span>
        </div>
        <div style="margin-bottom:15px;">
            <label for="editQtyInput">New Quantity:</label>
            <input type="number" name="new_quantity" id="editQtyInput" min="1" required
                style="width:80px; margin-left:10px;">
        </div>
        <div style="text-align:right;">
            <button type="button" id="cancelEditQty" class="btn btn-secondary"
                style="margin-right:10px;">Cancel</button>
            <button type="submit" name="edit_quantity" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>
<script>
document.querySelectorAll('.edit-qty-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('editQtyPrizeId').value = this.getAttribute('data-id');
        document.getElementById('editQtyInput').value = this.getAttribute('data-qty');
        document.getElementById('oldQtyDisplay').textContent = this.getAttribute('data-original-qty');
        document.getElementById('editQtyInput').min = this.getAttribute('data-original-qty');
        document.getElementById('editQtyModal').style.display = 'flex';
    });
});
document.getElementById('cancelEditQty').onclick = function() {
    document.getElementById('editQtyModal').style.display = 'none';
};
window.onclick = function(event) {
    const modal = document.getElementById('editQtyModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
};
</script>
<?php else: ?>
<p style="text-align: center; padding: 40px; color: #999;">No prizes added yet. Add your first prize above!</p>
<?php endif; ?>