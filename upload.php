<?php
// Handle Remove Participant (mark as removed)
if (isset($_POST['remove_participant'])) {
    $remove_id = intval($_POST['participant_id']);
    $upd = $conn->prepare("UPDATE participants SET status = 'removed' WHERE id = ? AND event_id = ?");
    $upd->bind_param("ii", $remove_id, $current_event_id);
    $upd->execute();
    $upd->close();
    set_message('success', 'Participant has been removed from the draw list.');
    header('Location: admin?page=upload');
    exit;
}

// Handle CSV / Excel Upload
if (isset($_POST['upload_csv'])) {
    // Ensure DB connection is UTF-8
    $conn->set_charset('utf8mb4');
    mysqli_query($conn, "SET NAMES 'utf8mb4'");
    mysqli_query($conn, "SET CHARACTER SET utf8mb4");
    mysqli_query($conn, "SET SESSION collation_connection = 'utf8mb4_general_ci'");

    // Columns this event actually requires (drives validation and the template)
    $upload_columns = get_upload_columns($conn, $current_event_id);

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, ['csv', 'xlsx'], true)) {
            set_message('error', 'Error: Please upload an Excel (.xlsx) or CSV file.');
        } else {
            // Normalise the upload (xlsx or csv, any encoding) into a row stream
            $handle = upload_to_csv_stream($file['tmp_name'], $file_ext);

            if ($handle !== FALSE) {
                $row_count = 0;
                $success_count = 0;
                $errors = [];
                $seen_participants = [];

                // Read header row and map columns by name (order-independent)
                $header = fgetcsv($handle, 1000, ',');
                $map = [];
                foreach ($header ?: [] as $i => $h) {
                    $key = strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string)$h)));
                    $key = preg_replace('/required$/i', '', $key); // template marks required columns with " *"
                    switch ($key) {
                        case 'lastname':   case 'surname':      $map['lastname']   = $i; break;
                        case 'firstname':  case 'givenname':    $map['firstname']  = $i; break;
                        case 'middlename': case 'middlenamemi': $map['middlename'] = $i; break;
                        case 'birthdate':  case 'birthday':     case 'dateofbirth': $map['birthdate'] = $i; break;
                        case 'barangay':   case 'brgy':         $map['barangay']   = $i; break;
                        case 'purok':                            $map['purok']      = $i; break;
                        case 'city': case 'municipality': case 'town':  $map['city']       = $i; break;
                        case 'contactnumber': case 'contact': case 'phonenumber': case 'mobilenumber':
                                                                 $map['contact']    = $i; break;
                        case 'name': case 'fullname': case 'participantname': case 'participant': $map['fullname'] = $i; break;
                    }
                }

                $has_split_names = isset($map['lastname'], $map['firstname']);
                $has_fullname    = isset($map['fullname']);

                $missing_headers = [];
                if (!isset($map['fullname'])) $missing_headers[] = 'Full Name';
                if (!isset($map['city'])) $missing_headers[] = 'Municipality';
                if (!isset($map['barangay'])) $missing_headers[] = 'Barangay';
                if (!empty($missing_headers)) {
                    fclose($handle);
                    set_message('error', 'The first row is missing required header(s): ' . implode(', ', $missing_headers) . '. Ticket numbers are generated automatically.');
                    header('Location: admin?page=upload');
                    exit;
                }

                // Replace the event's participants only after the header is accepted.
                $stmt_del = $conn->prepare("DELETE FROM participants WHERE event_id = ?");
                $stmt_del->bind_param("i", $current_event_id);
                $stmt_del->execute();
                $stmt_del->close();

                $max_q = $conn->prepare("SELECT MAX(CAST(number AS UNSIGNED)) as max_num FROM participants WHERE event_id = ?");
                $max_q->bind_param("i", $current_event_id);
                $max_q->execute();
                $max_result = $max_q->get_result()->fetch_assoc();
                $next_number = ($max_result['max_num'] ?? 0) + 1;
                $max_q->close();

                // Which of this event's fields are mandatory, keyed by header token
                $required_map = [];
                foreach ($upload_columns as $uc) {
                    if (!empty($uc['required'])) $required_map[$uc['source']] = $uc['label'];
                }

                $stmt = $conn->prepare("INSERT INTO participants (event_id, number, lastname, firstname, middlename, name, birthdate, province, city, barangay, purok, contact_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                    $row_count++;

                    // Skip empty rows
                    if (empty(array_filter($data))) continue;

                    $get = function($key) use ($map, $data) {
                        return isset($map[$key]) ? strtoupper(trim($data[$map[$key]] ?? '')) : '';
                    };

                    if ($has_split_names) {
                        // Legacy detailed format: separate Lastname / Firstname columns
                        $lastname   = $get('lastname');
                        $firstname  = $get('firstname');
                        $middlename = $get('middlename');
                        $birthdate  = $get('birthdate');
                        $barangay   = $get('barangay');
                        $purok      = $get('purok');
                        $city       = $get('city');
                        $contact    = $get('contact');

                        $name = strtoupper(trim($firstname . ' ' . $middlename . ' ' . $lastname));

                        // Full Name is always required, even in the split-name format
                        $required_map['fullname'] = 'Full Name';
                    } elseif ($has_fullname) {
                        // Current format: single Full Name column
                        $name       = $get('fullname');
                        $lastname   = '';
                        $firstname  = '';
                        $middlename = '';
                        $birthdate  = $get('birthdate');
                        $barangay   = $get('barangay');
                        $purok      = $get('purok');
                        $city       = $get('city');
                        $contact    = $get('contact');
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
                        $city       = '';
                        $contact    = strtoupper(trim($data[2] ?? ''));

                        $required_map = ['fullname' => 'Full Name', 'barangay' => 'Barangay'];
                    }

                    // Reject the row when any field this event requires is blank
                    $values_by_source = [
                        'fullname'  => $name,
                        'birthdate' => $birthdate,
                        'barangay'  => $barangay,
                        'purok'     => $purok,
                        'city'      => $city,
                        'contact'   => $contact,
                    ];
                    $missing = [];
                    foreach ($required_map as $src => $label) {
                        if (trim((string)($values_by_source[$src] ?? '')) === '') $missing[] = $label;
                    }
                    if (!empty($missing)) {
                        $errors[] = "Row {$row_count}: Missing required field(s): " . implode(', ', $missing);
                        continue;
                    }

                    $duplicate_key = json_encode([
                        preg_replace('/\\s+/', ' ', trim($name)),
                        preg_replace('/\\s+/', ' ', trim($barangay)),
                    ]);
                    if (isset($seen_participants[$duplicate_key])) {
                        $errors[] = "Row {$row_count}: Duplicate Full Name and Barangay";
                        continue;
                    }

                    // Normalize birthdate (mm/dd/yyyy) to Y-m-d when valid
                    if ($birthdate !== '') {
                        $dt = false;
                        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $birthdate)) {
                            $dt = DateTime::createFromFormat('m/d/Y', $birthdate);
                            $le = DateTime::getLastErrors();
                            if ($dt && (($le['warning_count'] ?? 0) || ($le['error_count'] ?? 0))) {
                                $dt = false; // reject rollovers e.g. 13/45/2020
                            }
                        } else {
                            $ts = strtotime($birthdate); // accepts Y-m-d and other formats
                            $dt = $ts ? (new DateTime())->setTimestamp($ts) : false;
                        }
                        $birthdate = $dt ? $dt->format('Y-m-d') : '';
                    }

                    $province = 'South Cotabato';
                    $city     = $city !== '' ? $city : 'City of Koronadal';
                    $number   = (string)$next_number++;

                    if ($birthdate === '') $birthdate = null; // optional field: empty string is not a valid DATE
                    $stmt->bind_param("isssssssssss", $current_event_id, $number, $lastname, $firstname, $middlename, $name, $birthdate, $province, $city, $barangay, $purok, $contact);

                    if ($stmt->execute()) {
                        $success_count++;
                        $seen_participants[$duplicate_key] = true;
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
                    set_message('error', 'No participants were uploaded. Please check your file.');
                }

                if (!empty($errors)) {
                    $_SESSION['upload_errors'] = $errors;
                }
            } else {
                set_message('error', 'Error: Could not read the uploaded file.');
            }
        }
    } else {
        set_message('error', 'Error: Please select a file to upload.');
    }

    header('Location: admin?page=upload');
    exit;
}

// Handle Delete All
if (isset($_POST['delete_all'])) {
    $stmt_del = $conn->prepare("DELETE FROM participants WHERE event_id = ?");
    $stmt_del->bind_param("i", $current_event_id);
    $stmt_del->execute();
    $stmt_del->close();
    set_message('success', 'All participants have been deleted.');
    header('Location: admin?page=upload');
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
    header('Location: admin?page=upload');
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
    header('Location: admin?page=upload');
    exit;
}


$total_all_q = $conn->prepare("SELECT COUNT(*) as total FROM participants WHERE event_id = ?");
$total_all_q->bind_param("i", $current_event_id);
$total_all_q->execute();
$total_all = $total_all_q->get_result()->fetch_assoc()['total'];

// Search filter for the participant table
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$like = '%' . strtoupper($search) . '%';

$count_query = $conn->prepare("SELECT COUNT(*) as total FROM participants
    WHERE event_id = ?
      AND (TRIM(UPPER(name)) LIKE ? OR number LIKE ? OR TRIM(UPPER(barangay)) LIKE ?
           OR TRIM(UPPER(city)) LIKE ? OR TRIM(UPPER(purok)) LIKE ? OR TRIM(UPPER(contact_number)) LIKE ?)");
$count_query->bind_param("issssss", $current_event_id, $like, $like, $like, $like, $like, $like);
$count_query->execute();
$participant_count = $count_query->get_result()->fetch_assoc()['total'];

// Pagination setup
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10; // default 10
$page  = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Get total pages
$total_pages = max(1, ceil($participant_count / $limit));
if ($page > $total_pages) $page = $total_pages;

// Range info
$from = $participant_count > 0 ? $offset + 1 : 0;
$to   = min($offset + $limit, $participant_count);

// Fetch participants for current page (blob columns excluded; flags only)
$participants = $conn->prepare("
    SELECT id, number, name, barangay, contact_number, status,
           (photo_data IS NOT NULL AND photo_data <> '') AS has_photo,
           (registration_attachment IS NOT NULL AND registration_attachment <> '') AS has_attachment
    FROM participants 
    WHERE event_id = ?
      AND (TRIM(UPPER(name)) LIKE ? OR number LIKE ? OR TRIM(UPPER(barangay)) LIKE ?
           OR TRIM(UPPER(city)) LIKE ? OR TRIM(UPPER(purok)) LIKE ? OR TRIM(UPPER(contact_number)) LIKE ?)
    ORDER BY id ASC 
    LIMIT $limit OFFSET $offset
");
$participants->bind_param("issssss", $current_event_id, $like, $like, $like, $like, $like, $like);
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

// Smart pagination: build page numbers with a single ellipsis per gap
$delta = 2;
$pages = [];
for ($i = 1; $i <= $total_pages; $i++) {
    $near_start = $i <= 1 + $delta;
    $near_end   = $i >= $total_pages - $delta;
    $near_page  = $i >= $page - $delta && $i <= $page + $delta;
    if ($near_start || $near_end || $near_page) {
        $pages[] = $i;
    }
}
// Insert "..." wherever there's a gap between consecutive page numbers
$window = [];
foreach ($pages as $idx => $pg) {
    if ($idx > 0 && $pg - $pages[$idx - 1] > 1) {
        $window[] = '...';
    }
    $window[] = $pg;
}
$pages = $window;
$base_qs = 'page=upload&amp;limit=' . $limit . '&amp;q=' . urlencode($search);
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
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:15px; flex-wrap:wrap;">
        <h3 style="color: #ec4899; margin:0;">All Participants</h3>
        <form method="GET" action="admin" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="page" value="upload">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Search name, number, barangay, purok, city, contact..."
                   style="padding:9px 14px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:14px; min-width:260px; outline:none;"
                   onfocus="this.style.borderColor='#ec4899';" onblur="this.style.borderColor='#e2e8f0';">
            <button type="submit" class="btn btn-primary" style="padding:9px 18px;">Search</button>
            <?php if ($search !== ''): ?>
            <a href="admin?page=upload" class="btn btn-secondary" style="padding:9px 14px; text-decoration:none; display:inline-flex; align-items:center;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($search !== ''): ?>
    <p style="margin:0 0 12px; font-size:13px; color:#6b7280;">Search results for <strong>&ldquo;<?php echo htmlspecialchars($search); ?>&rdquo;</strong>: <?php echo number_format($participant_count); ?> participant(s)</p>
    <?php endif; ?>
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
                    <img src="media?id=<?php echo $row['id']; ?>&amp;type=photo" class="pavatar" alt="" onclick="viewPhoto(<?php echo $row['id']; ?>)">
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
                    <a class="attach-link" href="media?id=<?php echo $row['id']; ?>&amp;type=attachment">&#128206; Download</a>
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

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div style="margin-top:15px; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px;">

        <!-- Left: Showing entries -->
        <span style="font-size:14px; color:#6b7280;">Showing <strong style="color:#0f172a;"><?php echo number_format($from); ?></strong> to <strong style="color:#0f172a;"><?php echo number_format($to); ?></strong> of <strong style="color:#0f172a;"><?php echo number_format($participant_count); ?></strong> entries</span>

        <!-- Right: Controls -->
        <div style="display:flex; align-items:center; gap:5px;">

            <!-- First -->
            <a href="admin?<?php echo $base_qs; ?>&amp;p=1" title="First page"
                style="display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 8px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:600; color:<?php echo $page==1?'#d1d5db':'#6b7280'; ?>; background:#f3f4f6; transition:all .15s;<?php echo $page==1?'pointer-events:none;':''; ?>"
                <?php echo $page!=1?'onmouseover="this.style.background=\'#e5e7eb\';this.style.color=\'#374151\';" onmouseout="this.style.background=\'#f3f4f6\';this.style.color=\'#6b7280\';"':''; ?>>&laquo;</a>

            <!-- Previous -->
            <a href="admin?<?php echo $base_qs; ?>&amp;p=<?php echo max(1, $page-1); ?>" title="Previous page"
                style="display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 8px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:600; color:<?php echo $page==1?'#d1d5db':'#6b7280'; ?>; background:#f3f4f6; transition:all .15s;<?php echo $page==1?'pointer-events:none;':''; ?>"
                <?php echo $page!=1?'onmouseover="this.style.background=\'#e5e7eb\';this.style.color=\'#374151\';" onmouseout="this.style.background=\'#f3f4f6\';this.style.color=\'#6b7280\';"':''; ?>>&lsaquo;</a>

            <?php foreach ($pages as $p): ?>
            <?php if ($p === '...'): ?>
            <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:34px; font-size:14px; color:#9ca3af;">&hellip;</span>
            <?php elseif ($p == $page): ?>
            <span style="display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 6px; border-radius:8px; font-size:14px; font-weight:600; color:#fff; background:#ec4899;">
                <?php echo number_format($p); ?>
            </span>
            <?php else: ?>
            <a href="admin?<?php echo $base_qs; ?>&amp;p=<?php echo $p; ?>"
                style="display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 6px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:500; color:#6b7280; background:#f3f4f6; transition:all .15s;"
                onmouseover="this.style.background='#e5e7eb';this.style.color='#374151';"
                onmouseout="this.style.background='#f3f4f6';this.style.color='#6b7280';">
                <?php echo number_format($p); ?>
            </a>
            <?php endif; ?>
            <?php endforeach; ?>

            <!-- Next -->
            <a href="admin?<?php echo $base_qs; ?>&amp;p=<?php echo min($total_pages, $page+1); ?>" title="Next page"
                style="display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 8px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:600; color:<?php echo $page==$total_pages?'#d1d5db':'#6b7280'; ?>; background:#f3f4f6; transition:all .15s;<?php echo $page==$total_pages?'pointer-events:none;':''; ?>"
                <?php echo $page!=$total_pages?'onmouseover="this.style.background=\'#e5e7eb\';this.style.color=\'#374151\';" onmouseout="this.style.background=\'#f3f4f6\';this.style.color=\'#6b7280\';"':''; ?>>&rsaquo;</a>

            <!-- Last -->
            <a href="admin?<?php echo $base_qs; ?>&amp;p=<?php echo $total_pages; ?>" title="Last page"
                style="display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 8px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:600; color:<?php echo $page==$total_pages?'#d1d5db':'#6b7280'; ?>; background:#f3f4f6; transition:all .15s;<?php echo $page==$total_pages?'pointer-events:none;':''; ?>"
                <?php echo $page!=$total_pages?'onmouseover="this.style.background=\'#e5e7eb\';this.style.color=\'#374151\';" onmouseout="this.style.background=\'#f3f4f6\';this.style.color=\'#6b7280\';"':''; ?>>&raquo;</a>

        </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <p>No participants found.</p>
    <?php endif; ?>
</div>

<?php endif; ?>
<div class="upload-box">
    <h2 style="color: #f472b6; margin-bottom: 15px;">Upload Excel File</h2>
    <p>Current Participants: <strong><?php echo $total_all; ?></strong></p>

    <form method="POST" enctype="multipart/form-data" style="margin-top:20px; text-align:center;">
        <div style="display:flex; flex-direction:column; align-items:center; gap:16px;">
            <div class="form-group">
                <input type="file" name="csv_file" accept=".xlsx,.csv" required>
            </div>
            <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                <button type="submit" name="upload_csv" class="btn btn-primary">Upload Excel</button>
                <a href="xlsx_template" class="btn btn-success" style="text-decoration:none; display:inline-flex; align-items:center;">&#11015; Download Excel Template</a>
                <a href="csv_template" class="btn btn-secondary" style="text-decoration:none; display:inline-flex; align-items:center;">&#11015; Download CSV Template</a>
                <button type="button" class="btn btn-secondary" onclick="window.print()" <?php echo $print_list->num_rows === 0 ? 'disabled' : ''; ?>>&#128424; Print Participant List</button>
                <button type="button" id="showDeleteModalBtn" class="btn btn-secondary">Delete All Participants</button>
            </div>
        </div>
    </form>
    <form id="deleteAllForm" method="POST" style="display:none;">
        <input type="hidden" name="delete_all" value="1">
    </form>
</div>

<?php
// Columns for this event's upload, generated from the same spec the importer uses
$upload_cols = get_upload_columns($conn, $current_event_id);
$field_help  = [
    'fullname'      => "Participant's full name, exactly as it should appear on the ticket.",
    'birthdate'     => 'Optional, format <strong>mm/dd/yyyy</strong> (e.g. 05/14/1990).',
    'barangay'      => 'Barangay of Koronadal. The template has a dropdown for this.',
    'purok'         => "Participant's purok.",
    'city'          => 'Leave blank to use <em>City of Koronadal</em>.',
    'contact_number'=> "Participant's contact number.",
];
?>
<div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 30px;">
    <h3 style="color: #f472b6; margin-bottom: 8px;">Required Fields for This Event</h3>
    <p style="color:#666; margin-bottom:15px;">
        These are the fields configured under <strong>Events &rarr; Registration Form Fields</strong>.
        A row missing any field marked <span style="color:#ef4444;">*</span> is skipped and reported.
    </p>
    <p style="margin-bottom: 10px;"><strong>Columns (order doesn't matter &mdash; matched by header name):</strong></p>
    <ol style="padding-left: 25px; line-height: 1.8;">
        <?php foreach ($upload_cols as $uc): ?>
        <li>
            <strong><?php echo htmlspecialchars($uc['label']); ?></strong>
            <?php if (!empty($uc['required'])): ?><span style="color:#ef4444;">*</span><?php endif; ?>
            &mdash; <?php echo $field_help[$uc['key']] ?? ''; ?>
        </li>
        <?php endforeach; ?>
    </ol>
    <p style="margin-top: 10px; color: #666;">Province is set automatically (South Cotabato). Old files using separate <em>Lastname</em> / <em>Firstname</em> columns, and old 3-column files (<em>Name, Barangay, Contact</em>), are still accepted.</p>
    <p style="margin-top: 15px; color: #666;"><em>Note: Numbers are auto-generated sequentially. The first row must contain headers. Uploading a new file will replace all existing participants.</em></p>
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
    document.getElementById('photoModalImg').src = 'media?id=' + id + '&type=photo';
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