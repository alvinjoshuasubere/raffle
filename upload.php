<?php
// Handle Remove Participant (mark as removed)
if (isset($_POST['remove_participant'])) {
    $remove_id = intval($_POST['participant_id']);
    $upd = $conn->prepare("UPDATE participants SET status = 'removed' WHERE id = ? AND event_id = ?");
    $upd->bind_param("ii", $remove_id, $current_event_id);
    $upd->execute();
    $upd->close();
    set_message('success', 'Participant has been removed from the draw list.');
    header('Location: admin.php?page=upload');
    exit;
}

// Handle CSV Upload
if (isset($_POST['upload_csv'])) {
    // Ensure DB connection is UTF-8
    $conn->set_charset('utf8mb4');
    mysqli_query($conn, "SET NAMES 'utf8mb4'");
    mysqli_query($conn, "SET CHARACTER SET utf8mb4");
    mysqli_query($conn, "SET SESSION collation_connection = 'utf8mb4_general_ci'");

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file_ext !== 'csv') {
            set_message('error', 'Error: Please upload a CSV file only.');
        } else {
            // Detect file encoding
            $csv_content = file_get_contents($file['tmp_name']);
            $encoding = mb_detect_encoding($csv_content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
            
            // Convert file content to UTF-8
            $utf8_content = mb_convert_encoding($csv_content, 'UTF-8', $encoding);
            
            // Write the UTF-8 version to a temporary file
            $temp_file = tmpfile();
            $meta = stream_get_meta_data($temp_file);
            fwrite($temp_file, $utf8_content);
            rewind($temp_file);

            if (($handle = $temp_file) !== FALSE) {
                // Delete all existing participants for this event
                $stmt_del = $conn->prepare("DELETE FROM participants WHERE event_id = ?");
                $stmt_del->bind_param("i", $current_event_id);
                $stmt_del->execute();
                $stmt_del->close();

                $row_count = 0;
                $success_count = 0;
                $errors = [];

                // Get the max number for auto-increment
                $max_q = $conn->prepare("SELECT MAX(CAST(number AS UNSIGNED)) as max_num FROM participants WHERE event_id = ?");
                $max_q->bind_param("i", $current_event_id);
                $max_q->execute();
                $max_result = $max_q->get_result()->fetch_assoc();
                $next_number = ($max_result['max_num'] ?? 0) + 1;
                $max_q->close();

                // Read header row and map columns by name (order-independent)
                $header = fgetcsv($handle, 1000, ',');
                $map = [];
                foreach ($header ?: [] as $i => $h) {
                    $key = strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string)$h)));
                    switch ($key) {
                        case 'lastname':   case 'surname':      $map['lastname']   = $i; break;
                        case 'firstname':  case 'givenname':    $map['firstname']  = $i; break;
                        case 'middlename': case 'middlenamemi': $map['middlename'] = $i; break;
                        case 'birthdate':  case 'birthday':     case 'dateofbirth': $map['birthdate'] = $i; break;
                        case 'barangay':   case 'brgy':         $map['barangay']   = $i; break;
                        case 'purok':                            $map['purok']      = $i; break;
                        case 'contactnumber': case 'contact': case 'phonenumber': case 'mobilenumber':
                                                                 $map['contact']    = $i; break;
                        case 'name':       case 'fullname':     $map['fullname']   = $i; break;
                    }
                }

                $has_split_names = isset($map['lastname'], $map['firstname']);

                $stmt = $conn->prepare("INSERT INTO participants (event_id, number, lastname, firstname, middlename, name, birthdate, province, city, barangay, purok, contact_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                    $row_count++;

                    // Skip empty rows
                    if (empty(array_filter($data))) continue;

                    $get = function($key) use ($map, $data) {
                        return isset($map[$key]) ? strtoupper(trim($data[$map[$key]] ?? '')) : '';
                    };

                    if ($has_split_names) {
                        // New detailed format
                        $lastname   = $get('lastname');
                        $firstname  = $get('firstname');
                        $middlename = $get('middlename');
                        $birthdate  = $get('birthdate');
                        $barangay   = $get('barangay');
                        $purok      = $get('purok');
                        $contact    = $get('contact');

                        if (empty($lastname) || empty($firstname) || empty($barangay)) {
                            $errors[] = "Row {$row_count}: Missing required fields (Lastname, Firstname, Barangay)";
                            continue;
                        }

                        // Normalize birthdate to Y-m-d when parsable
                        if ($birthdate !== '') {
                            $ts = strtotime($birthdate);
                            if ($ts) $birthdate = date('Y-m-d', $ts);
                        }

                        $name = strtoupper(trim($firstname . ' ' . $middlename . ' ' . $lastname));
                    } elseif (isset($map['fullname'])) {
                        // Full name single column format
                        $name       = $get('fullname');
                        $lastname   = '';
                        $firstname  = '';
                        $middlename = '';
                        $birthdate  = $get('birthdate');
                        $barangay   = $get('barangay');
                        $purok      = $get('purok');
                        $contact    = $get('contact');

                        if (empty($name) || empty($barangay)) {
                            $errors[] = "Row {$row_count}: Missing required fields (Name, Barangay)";
                            continue;
                        }
                    } else {
                        // Legacy positional format: Name, Barangay, Contact
                        if (count($data) < 3) {
                            $errors[] = "Row {$row_count}: Incomplete data";
                            continue;
                        }
                        $name       = strtoupper(trim($data[0]));
                        $lastname   = '';
                        $firstname  = '';
                        $middlename = '';
                        $birthdate  = '';
                        $barangay   = strtoupper(trim($data[1]));
                        $purok      = '';
                        $contact    = strtoupper(trim($data[2] ?? ''));

                        if (empty($name) || empty($barangay)) {
                            $errors[] = "Row {$row_count}: Missing required fields";
                            continue;
                        }
                    }

                    $province = 'South Cotabato';
                    $city     = 'City of Koronadal';
                    $number   = (string)$next_number++;

                    $stmt->bind_param("isssssssssss", $current_event_id, $number, $lastname, $firstname, $middlename, $name, $birthdate, $province, $city, $barangay, $purok, $contact);

                    if ($stmt->execute()) {
                        $success_count++;
                    } else {
                        $errors[] = "Row {$row_count}: Database error ({$conn->error})";
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

    header('Location: admin.php?page=upload');
    exit;
}

// Handle Delete All
if (isset($_POST['delete_all'])) {
    $stmt_del = $conn->prepare("DELETE FROM participants WHERE event_id = ?");
    $stmt_del->bind_param("i", $current_event_id);
    $stmt_del->execute();
    $stmt_del->close();
    set_message('success', 'All participants have been deleted.');
    header('Location: admin.php?page=upload');
    exit;
}

// Handle Background Upload
if (isset($_POST['upload_background'])) {
    if (isset($_FILES['bg_image']) && $_FILES['bg_image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $file = $_FILES['bg_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            set_message('error', 'Only JPG, PNG, and GIF files are allowed.');
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            set_message('error', 'File size exceeds 5MB limit.');
        } else {
            $bg_dir = 'uploads/bg';
            if (!file_exists($bg_dir)) {
                mkdir($bg_dir, 0777, true);
            }
            $dest = $bg_dir . '/custom_bg.jpg';
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                set_message('success', 'Background image updated successfully.');
            } else {
                set_message('error', 'Failed to save background image.');
            }
        }
    } else {
        set_message('error', 'Please select an image file to upload.');
    }
    header('Location: admin.php?page=upload');
    exit;
}

if (isset($_POST['remove_background'])) {
    $bg_file = 'uploads/bg/custom_bg.jpg';
    if (file_exists($bg_file)) {
        unlink($bg_file);
        set_message('success', 'Background has been reset to default.');
    } else {
        set_message('error', 'No custom background found.');
    }
    header('Location: admin.php?page=upload');
    exit;
}


$count_query = $conn->prepare("SELECT COUNT(*) as total FROM participants WHERE event_id = ?");
$count_query->bind_param("i", $current_event_id);
$count_query->execute();
$participant_count = $count_query->get_result()->fetch_assoc()['total'];

// Pagination setup
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10; // default 10
$page  = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Get total pages
$total_pages = ceil($participant_count / $limit);

// Fetch participants for current page (blob columns excluded; flags only)
$participants = $conn->prepare("
    SELECT id, number, name, barangay, contact_number, status,
           (photo_data IS NOT NULL AND photo_data <> '') AS has_photo,
           (registration_attachment IS NOT NULL AND registration_attachment <> '') AS has_attachment
    FROM participants 
    WHERE event_id = ?
    ORDER BY id ASC 
    LIMIT $limit OFFSET $offset
");
$participants->bind_param("i", $current_event_id);
$participants->execute();
$participants = $participants->get_result();

// Event name (for the printed backup list)
$ev_stmt = $conn->prepare("SELECT name FROM events WHERE id = ?");
$ev_stmt->bind_param("i", $current_event_id);
$ev_stmt->execute();
$event_name = $ev_stmt->get_result()->fetch_assoc()['name'] ?? 'Raffle Event';
$ev_stmt->close();

// Full eligible list for printing (excludes winners/removed)
$print_stmt = $conn->prepare("
    SELECT number, name, barangay
    FROM participants
    WHERE event_id = ? AND (status IS NULL OR status = '')
    ORDER BY CAST(number AS UNSIGNED) ASC
");
$print_stmt->bind_param("i", $current_event_id);
$print_stmt->execute();
$print_list = $print_stmt->get_result();
?>

<style>
    #printArea { display: none; }

    .pavatar {
        width: 42px; height: 42px; border-radius: 50%;
        object-fit: cover; display: block; margin: 0 auto;
        border: 2px solid #fbcfe8; cursor: zoom-in;
        transition: transform .15s ease;
    }
    .pavatar:hover { transform: scale(1.1); }
    .attach-link {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: 12px; font-weight: 600; color: #ec4899;
        text-decoration: none;
    }
    .attach-link:hover { text-decoration: underline; }

    @media print {
        body * {
            visibility: hidden !important;
        }
        #printArea,
        #printArea * {
            visibility: visible !important;
        }
        #printArea {
            display: block !important;
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            padding: 8mm 10mm;
            color: #000;
            font-family: Arial, Helvetica, sans-serif;
        }
        .print-head {
            text-align: center;
            margin-bottom: 6mm;
            border-bottom: 2px solid #000;
            padding-bottom: 4mm;
        }
        .print-head h1 {
            font-size: 20pt;
            margin-bottom: 2mm;
            letter-spacing: 1px;
        }
        .print-head p {
            font-size: 10pt;
            margin: 1mm 0;
        }
        #printArea table {
            width: 100%;
            border-collapse: collapse;
        }
        #printArea th,
        #printArea td {
            border: 1px solid #000;
            padding: 7px 10px;
            font-size: 11pt;
            text-align: left;
            word-break: break-word;
        }
        #printArea th {
            background: #eee;
            font-weight: 700;
        }
        #printArea td.num {
            width: 70px;
            font-weight: 700;
            text-align: center;
        }
        #printArea tr {
            page-break-inside: avoid;
        }
        #printArea thead {
            display: table-header-group;
        }
        .print-foot {
            margin-top: 5mm;
            font-size: 9pt;
            text-align: center;
        }
    }
</style>

<!-- Printable backup list -->
<div id="printArea">
    <div class="print-head">
        <h1><?php echo htmlspecialchars($event_name); ?></h1>
        <p><strong>OFFICIAL PARTICIPANT LIST</strong></p>
        <p>Generated: <?php echo date('F j, Y &\m\d\a\sh; g:i A'); ?> &nbsp;&bull;&nbsp; Total: <?php echo $print_list->num_rows; ?> participant(s)</p>
    </div>
    <table>
        <thead>
            <tr>
                <th class="num">Ticket No.</th>
                <th>Name</th>
                <th>Barangay</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($p = $print_list->fetch_assoc()): ?>
            <tr>
                <td class="num"><?php echo htmlspecialchars($p['number']); ?></td>
                <td><?php echo strtoupper(htmlspecialchars($p['name'])); ?></td>
                <td><?php echo strtoupper(htmlspecialchars($p['barangay'])); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <div class="print-foot">&mdash; End of List &mdash;</div>
</div>

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
    <h3 style="color: #ec4899; margin-bottom: 15px;">All Participants</h3>
    <?php if ($participants->num_rows > 0): ?>
    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="background:#ec4899;">
                <th style="padding:8px; border:1px solid #ddd;">Photo</th>
                <th style="padding:8px; border:1px solid #ddd;">Number</th>
                <th style="padding:8px; border:1px solid #ddd;">Name</th>
                <th style="padding:8px; border:1px solid #ddd;">Barangay</th>
                <th style="padding:8px; border:1px solid #ddd;">Contact Number</th>
                <th style="padding:8px; border:1px solid #ddd;">Attachment</th>
                <th style="padding:8px; border:1px solid #ddd;">Status</th>
                <th style="padding:8px; border:1px solid #ddd;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $participants->fetch_assoc()): ?>
            <tr style="<?php echo ($row['status'] === 'winner' || $row['status'] === 'removed') ? 'opacity:0.5;' : ''; ?>">
                <td style="padding:8px; border:1px solid #ddd; text-align:center;">
                    <?php if (!empty($row['has_photo'])): ?>
                    <img src="media.php?id=<?php echo $row['id']; ?>&amp;type=photo" class="pavatar" alt="" onclick="viewPhoto(<?php echo $row['id']; ?>)">
                    <?php else: ?>
                    <span style="color:#c4b5c0;">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td style="padding:8px; border:1px solid #ddd;"><?php echo htmlspecialchars($row['number']); ?></td>
                <td style="padding:8px; border:1px solid #ddd;"><?php echo htmlspecialchars($row['name']); ?></td>
                <td style="padding:8px; border:1px solid #ddd;"><?php echo htmlspecialchars($row['barangay']); ?></td>
                <td style="padding:8px; border:1px solid #ddd;"><?php echo htmlspecialchars($row['contact_number']); ?></td>
                <td style="padding:8px; border:1px solid #ddd; text-align:center;">
                    <?php if (!empty($row['has_attachment'])): ?>
                    <a class="attach-link" href="media.php?id=<?php echo $row['id']; ?>&amp;type=attachment">&#128206; Download</a>
                    <?php else: ?>
                    <span style="color:#c4b5c0;">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td style="padding:8px; border:1px solid #ddd;">
                    <?php if ($row['status'] === 'winner'): ?>
                        <span style="color:#10b981; font-weight:700;">Winner</span>
                    <?php elseif ($row['status'] === 'removed'): ?>
                        <span style="color:#ef4444; font-weight:700;">Removed</span>
                    <?php else: ?>
                        <span style="color:#6b7280;">Active</span>
                    <?php endif; ?>
                </td>
                <td style="padding:8px; border:1px solid #ddd; text-align:center;">
                    <?php if ($row['status'] !== 'winner' && $row['status'] !== 'removed'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this participant from the draw list?');">
                        <input type="hidden" name="participant_id" value="<?php echo $row['id']; ?>">
                        <button type="submit" name="remove_participant" style="background:#ef4444; color:#fff; border:none; padding:4px 12px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600;">Remove</button>
                    </form>
                    <?php else: ?>
                        <span style="color:#c4b5c0; font-size:12px;">—</span>
                    <?php endif; ?>
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
                <a href="admin.php?page=upload&amp;p=<?php echo $page-1; ?>&amp;limit=<?php echo $limit; ?>"
                    style="padding:6px 12px; border-radius:20px; background:#f1f1f1; text-decoration:none; color:#333;">
                    ‹ Prev
                </a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                <span style="padding:6px 12px; border-radius:20px; background:#ec4899; color:#fff; font-weight:bold;">
                    <?php echo $i; ?>
                </span>
                <?php else: ?>
                <a href="admin.php?page=upload&amp;p=<?php echo $i; ?>&amp;limit=<?php echo $limit; ?>"
                    style="padding:6px 12px; border-radius:20px; background:#f1f1f1; text-decoration:none; color:#333;">
                    <?php echo $i; ?>
                </a>
                <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                <a href="admin.php?page=upload&amp;p=<?php echo $page+1; ?>&amp;limit=<?php echo $limit; ?>"
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
    <h2 style="color: #f472b6; margin-bottom: 15px;">Upload CSV File</h2>
    <p>Current Participants: <strong><?php echo $participant_count; ?></strong></p>

    <form method="POST" enctype="multipart/form-data" style="margin-top:20px; text-align:center;">
        <div style="display:flex; flex-direction:column; align-items:center; gap:16px;">
            <div class="form-group">
                <input type="file" name="csv_file" accept=".csv" required>
            </div>
            <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                <button type="submit" name="upload_csv" class="btn btn-primary">Upload CSV</button>
                <a href="csv_template.php" class="btn btn-secondary" style="text-decoration:none; display:inline-flex; align-items:center;">&#11015; Download CSV Template</a>
                <button type="button" class="btn btn-secondary" onclick="window.print()" <?php echo $print_list->num_rows === 0 ? 'disabled' : ''; ?>>&#128424; Print Participant List</button>
                <button type="button" id="showDeleteModalBtn" class="btn btn-secondary">Delete All Participants</button>
            </div>
        </div>
    </form>
    <form id="deleteAllForm" method="POST" style="display:none;">
        <input type="hidden" name="delete_all" value="1">
    </form>
</div>


<div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 30px;">
    <h3 style="color: #f472b6; margin-bottom: 15px;">CSV File Format</h3>
    <p style="margin-bottom: 10px;"><strong>Columns (order doesn't matter &mdash; matched by header name):</strong></p>
    <ol style="padding-left: 25px; line-height: 1.8;">
        <li><strong>Lastname</strong> <span style="color:#ef4444;">*</span> &mdash; Participant's last name</li>
        <li><strong>Firstname</strong> <span style="color:#ef4444;">*</span> &mdash; Participant's first name</li>
        <li><strong>Middlename</strong> &mdash; optional</li>
        <li><strong>Birthdate</strong> &mdash; optional (e.g. 1990-05-14)</li>
        <li><strong>Barangay</strong> <span style="color:#ef4444;">*</span> &mdash; Participant's barangay</li>
        <li><strong>Purok</strong> &mdash; optional</li>
        <li><strong>Contact Number</strong> &mdash; optional</li>
    </ol>
    <p style="margin-top: 10px; color: #666;">Province and City are set automatically (South Cotabato, City of Koronadal). Old 3-column files (<em>Name, Barangay, Contact</em>) are still accepted.</p>
    <p style="margin-top: 15px; color: #666;"><em>Note: Numbers are auto-generated sequentially. First row must contain headers. Uploading a new CSV will replace all existing participants.</em></p>
</div>

<!-- Modal for delete confirmation -->
<div id="deleteModal"
    style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100vw; height:100vh; background:rgba(0,0,0,0.35);">
    <div
        style="background:#ffffff; max-width:350px; margin:120px auto; padding:30px 20px 20px 20px; border-radius:16px; box-shadow:0 8px 40px rgba(0,0,0,0.08); text-align:center; position:relative; border:1px solid rgba(0,0,0,0.04);">
        <h3 style="color:#ec4899; margin-bottom:18px;">Are you sure you want to delete all participants?</h3>
        <p style="color:#6b7280; margin-bottom:24px;">This action cannot be undone.</p>
        <button id="confirmDeleteBtn" class="btn btn-danger" style="margin-right:10px;">YES, Delete All</button>
        <br>
        <br>
        <button id="cancelDeleteBtn" class="btn btn-secondary">NO, Cancel</button>
    </div>
</div>

<script>
document.getElementById('showDeleteModalBtn').onclick = function() {
    document.getElementById('deleteModal').style.display = 'block';
};
document.getElementById('cancelDeleteBtn').onclick = function() {
    document.getElementById('deleteModal').style.display = 'none';
};
document.getElementById('confirmDeleteBtn').onclick = function() {
    document.getElementById('deleteAllForm').submit();
};
window.onclick = function(event) {
    var modal = document.getElementById('deleteModal');
    if (event.target === modal) {
        modal.style.display = "none";
    }
};
</script>

<!-- Photo lightbox -->
<div id="photoModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100vw; height:100vh; background:rgba(15,23,42,0.75);">
    <div style="position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); background:#fff; border-radius:20px; padding:16px; width:min(92vw,420px); text-align:center;">
        <img id="photoModalImg" src="" alt="" style="width:100%; max-height:70vh; object-fit:contain; border-radius:14px;">
        <button class="btn btn-secondary" style="margin-top:12px;" onclick="document.getElementById('photoModal').style.display='none'">Close</button>
    </div>
</div>

<script>
function viewPhoto(id) {
    document.getElementById('photoModalImg').src = 'media.php?id=' + id + '&type=photo';
    document.getElementById('photoModal').style.display = 'block';
}
document.getElementById('photoModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<!-- Background Management -->
<div style="background: #ffffff; border: 1px solid rgba(0,0,0,0.04); border-radius: 16px; padding: 30px; margin-bottom: 30px;">
    <h2 style="color: #ec4899; margin-bottom: 20px;">Background Image</h2>
    <p style="color: #6b7280; margin-bottom: 15px;">This background will only show on the Draw page.</p>

    <?php $bg_path = 'uploads/bg/custom_bg.jpg'; ?>
    <?php if (file_exists($bg_path)): ?>
    <div style="margin-bottom: 20px;">
        <p style="color: #6b7280; margin-bottom: 10px;">Current Background:</p>
        <img src="<?php echo $bg_path . '?v=' . filemtime($bg_path); ?>"
             style="max-width: 100%; max-height: 200px; border-radius: 8px; border: 2px solid rgba(0,0,0,0.06);">
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" style="margin-bottom: 15px;">
        <div class="form-group">
            <label for="bg_image">Upload New Background (JPG, PNG, GIF — max 5MB)</label>
            <input type="file" name="bg_image" id="bg_image" accept=".jpg,.jpeg,.png,.gif">
        </div>
        <button type="submit" name="upload_background" class="btn btn-primary">Upload Background</button>
    </form>

    <?php if (file_exists($bg_path)): ?>
    <form method="POST" style="margin-top: 10px;">
        <button type="submit" name="remove_background" class="btn btn-secondary">Remove Background</button>
    </form>
    <?php endif; ?>
</div>