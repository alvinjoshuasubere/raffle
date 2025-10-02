<?php
// Handle CSV Upload
if (isset($_POST['upload_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Check if file is CSV
        if ($file_ext !== 'csv') {
            set_message('error', 'Error: Please upload a CSV file only.');
        } else {
            // Open and read CSV file
            if (($handle = fopen($file['tmp_name'], 'r')) !== FALSE) {
                // Delete all existing participants first
                $conn->query("DELETE FROM participants");
                
                $row_count = 0;
                $success_count = 0;
                $errors = [];
                
                // Skip header row
                $header = fgetcsv($handle, 1000, ',');
                
                // Prepare statement for better performance
                $stmt = $conn->prepare("INSERT INTO participants (number, name, barangay, contact_number) VALUES (?, ?, ?, ?)");
                
                while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                    $row_count++;
                    
                    // Skip empty rows
                    if (empty(array_filter($data))) {
                        continue;
                    }
                    
                    // Validate data
                    if (count($data) < 4) {
                        $errors[] = "Row {$row_count}: Incomplete data";
                        continue;
                    }
                    
                    $number = trim($data[0]);
                    $name = trim($data[1]);
                    $barangay = trim($data[2]);
                    $contact = trim($data[3]);
                    
                    // Validate required fields (Contact Number can be empty)
                    if (empty($number) || empty($name) || empty($barangay)) {
                        $errors[] = "Row {$row_count}: Missing required fields";
                        continue;
                    }
                    
                    // Insert into database
                    $stmt->bind_param("ssss", $number, $name, $barangay, $contact);
                    
                    if ($stmt->execute()) {
                        $success_count++;
                    } else {
                        if ($conn->errno == 1062) {
                            $errors[] = "Row {$row_count}: Duplicate number '{$number}'";
                        } else {
                            $errors[] = "Row {$row_count}: Database error";
                        }
                    }
                }
                
                $stmt->close();
                fclose($handle);
                
                // Display results
                if ($success_count > 0) {
                    $message = "Successfully uploaded {$success_count} participant(s).";
                    if (!empty($errors)) {
                        $message .= " " . count($errors) . " error(s) occurred.";
                    }
                    set_message('success', $message);
                } else {
                    set_message('error', 'No participants were uploaded. Please check your CSV file.');
                }
                
                // Store errors in session for display
                if (!empty($errors)) {
                    $_SESSION['upload_errors'] = $errors;
                }
            } else {
                set_message('error', 'Error: Could not read CSV file.');
            }
        }
    } else {
        set_message('error', 'Error: Please select a file to upload.');
    }
    
    header('Location: index.php?page=upload');
    exit;
}

// Handle Delete All
if (isset($_POST['delete_all'])) {
    $conn->query("DELETE FROM participants");
    set_message('success', 'All participants have been deleted.');
    header('Location: index.php?page=upload');
    exit;
}

$count_query = $conn->query("SELECT COUNT(*) as total FROM participants");
$participant_count = $count_query->fetch_assoc()['total'];

// Pagination setup
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10; // default 10
$page  = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Get total pages
$total_pages = ceil($participant_count / $limit);

// Fetch participants for current page
$participants = $conn->query("
    SELECT * FROM participants 
    ORDER BY id ASC 
    LIMIT $limit OFFSET $offset
");?>

<h1>Upload Participants</h1>

<?php display_message(); ?>

<?php
// Display upload errors if any
if (isset($_SESSION['upload_errors'])) {
    echo "<div class='alert alert-error'>";
    echo "<strong>Upload Errors:</strong><ul style='margin: 10px 0; padding-left: 20px;'>";
    foreach ($_SESSION['upload_errors'] as $error) {
        echo "<li>{$error}</li>";
    }
    echo "</ul></div>";
    unset($_SESSION['upload_errors']);
}
?>




<?php if ($participant_count > 0): ?>
<div style="margin-top: 30px;">
    <h3 style="color: #DC143C; margin-bottom: 15px;">All Participants</h3>
    <?php if ($participants->num_rows > 0): ?>
    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="background:#DC143C;">
                <th style="padding:8px; border:1px solid #ddd;">Number</th>
                <th style="padding:8px; border:1px solid #ddd;">Name</th>
                <th style="padding:8px; border:1px solid #ddd;">Barangay</th>
                <th style="padding:8px; border:1px solid #ddd;">Contact Number</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $participants->fetch_assoc()): ?>
            <tr>
                <td style="padding:8px; border:1px solid #ddd;"><?php echo htmlspecialchars($row['number']); ?></td>
                <td style="padding:8px; border:1px solid #ddd;"><?php echo htmlspecialchars($row['name']); ?></td>
                <td style="padding:8px; border:1px solid #ddd;"><?php echo htmlspecialchars($row['barangay']); ?></td>
                <td style="padding:8px; border:1px solid #ddd;"><?php echo htmlspecialchars($row['contact_number']); ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <!-- Controls Row -->
    <div style="margin-top:15px; display:flex; justify-content:space-between; align-items:center;">

        <!-- Pagination (left aligned) -->
        <div>
            <?php if ($total_pages > 1): ?>
            <div style="display:inline-flex; gap:5px;">

                <?php if ($page > 1): ?>
                <a href="index.php?page=upload&amp;p=<?php echo $page-1; ?>&amp;limit=<?php echo $limit; ?>"
                    style="padding:6px 12px; border-radius:20px; background:#f1f1f1; text-decoration:none; color:#333;">
                    ‹ Prev
                </a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                <span style="padding:6px 12px; border-radius:20px; background:#DC143C; color:#fff; font-weight:bold;">
                    <?php echo $i; ?>
                </span>
                <?php else: ?>
                <a href="index.php?page=upload&amp;p=<?php echo $i; ?>&amp;limit=<?php echo $limit; ?>"
                    style="padding:6px 12px; border-radius:20px; background:#f1f1f1; text-decoration:none; color:#333;">
                    <?php echo $i; ?>
                </a>
                <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                <a href="index.php?page=upload&amp;p=<?php echo $page+1; ?>&amp;limit=<?php echo $limit; ?>"
                    style="padding:6px 12px; border-radius:20px; background:#f1f1f1; text-decoration:none; color:#333;">
                    Next ›
                </a>
                <?php endif; ?>

            </div>
            <?php endif; ?>
        </div>

        <!-- Items per page (right aligned) -->
        <form method="get" style="margin:0; display:flex; align-items:center; gap:5px;">
            <input type="hidden" name="page" value="upload">
            <label for="limit">Page Items</label>
            <select name="limit" id="limit" onchange="this.form.submit()"
                style="padding:5px; border-radius:6px; border:1px solid #ccc;">
                <?php foreach ([5, 10, 20, 50] as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo ($limit == $opt) ? 'selected' : ''; ?>>
                    <?php echo $opt; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php else: ?>
    <p>No participants found.</p>
    <?php endif; ?>
</div>

<?php endif; ?>
<div class="upload-box">
    <h2 style="color: #DC143C; margin-bottom: 15px;">Upload CSV File</h2>
    <p>Current Participants: <strong><?php echo $participant_count; ?></strong></p>

    <form method="POST" enctype="multipart/form-data" style="margin-top: 20px;">
        <div class="form-group">
            <input type="file" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" name="upload_csv" class="btn btn-primary">Upload CSV</button>
    </form>

    <?php if ($participant_count > 0): ?>
    <form method="POST" style="margin-top: 20px;"
        onsubmit="return confirm('Are you sure you want to delete all participants? This action cannot be undone.');">
        <button type="submit" name="delete_all" class="btn btn-secondary">Delete All Participants</button>
    </form>
    <?php endif; ?>
</div>


<div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 30px;">
    <h3 style="color: #DC143C; margin-bottom: 15px;">CSV File Format</h3>
    <p style="margin-bottom: 10px;"><strong>Required Columns (in order):</strong></p>
    <ol style="padding-left: 25px; line-height: 1.8;">
        <li><strong>Number</strong> - Participant's unique number</li>
        <li><strong>Name</strong> - Participant's full name</li>
        <li><strong>Barangay</strong> - Participant's barangay</li>
        <li><strong>Contact Number</strong> - Participant's contact number</li>
    </ol>
    <p style="margin-top: 15px; color: #666;"><em>Note: First row should contain headers. Uploading new CSV will replace
            all existing participants.</em></p>
</div>