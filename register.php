<?php
require_once __DIR__ . '/config.php';

$json_file = __DIR__ . '/participants.json';

// Get active event from DB
$ev = $conn->query("SELECT id, name, registration_start_at, registration_end_at FROM events WHERE status='Active' ORDER BY id ASC LIMIT 1");
$current_event_name = 'Raffle Event';
$current_event_id = 1;
$registration_open = true;
$reg_start = null;
$reg_end = null;
$reg_reason = '';
if ($ev && $ev->num_rows > 0) {
    $row = $ev->fetch_assoc();
    $current_event_id = (int)$row['id'];
    $current_event_name = $row['name'];
    $reg_start = $row['registration_start_at'];
    $reg_end = $row['registration_end_at'];
    $now = time();
    if ($reg_start && $now < strtotime($reg_start)) {
        $registration_open = false;
        $reg_reason = 'not_started';
    } elseif ($reg_end && $now > strtotime($reg_end)) {
        $registration_open = false;
        $reg_reason = 'ended';
    }
}

// Redirect to closed page if registration is not open
if (!$registration_open) {
    header('Location: closed.php?reason=' . $reg_reason);
    exit;
}

// Sync JSON file with active event
if (!file_exists($json_file)) {
    file_put_contents($json_file, json_encode([
        'event_name' => $current_event_name,
        'participants' => [],
    ], JSON_PRETTY_PRINT));
} else {
    $data = json_decode(file_get_contents($json_file), true) ?? ['event_name' => $current_event_name, 'participants' => []];
    if ($data['event_name'] !== $current_event_name) {
        // Different event selected — reset JSON
        $data = ['event_name' => $current_event_name, 'participants' => []];
        file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT));
    }
}

$success = null;
$error = null;
$error_step = 1;
$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    foreach (['lastname','firstname','middlename','birthdate','barangay','purok','contact_number'] as $f) {
        $submitted[$f] = strtoupper(trim($_POST[$f] ?? ''));
    }
    $lastname = $submitted['lastname'];
    $firstname = $submitted['firstname'];
    $middlename = $submitted['middlename'];
    $birthdate = $submitted['birthdate'];
    $province = 'South Cotabato';
    $city = 'City of Koronadal';
    $barangay = $submitted['barangay'];
    $purok = $submitted['purok'];
    $contact = $submitted['contact_number'];

    $photo_data = null;
    $reg_attachment = null;

    if (!empty($_FILES['photo_data']['tmp_name'])) {
        if ($_FILES['photo_data']['size'] <= 5 * 1024 * 1024) {
            $photo_data = file_get_contents($_FILES['photo_data']['tmp_name']);
        } else {
            $error = 'Profile image must be 5MB or less.';
            $error_step = 2;
        }
    }
    if (!$error && !empty($_FILES['registration_attachment']['tmp_name'])) {
        if ($_FILES['registration_attachment']['size'] <= 10 * 1024 * 1024) {
            $reg_attachment = file_get_contents($_FILES['registration_attachment']['tmp_name']);
        } else {
            $error = 'Attachment must be 10MB or less.';
            $error_step = 2;
        }
    }

    if (!$error && (empty($lastname) || empty($firstname) || empty($middlename) || empty($birthdate) || empty($barangay) || empty($contact))) {
        $error = 'Please fill in all required fields.';
    } elseif (!$error) {
        // Load JSON data for duplicate check
        $fp = fopen($json_file, 'c+');
        if (flock($fp, LOCK_EX)) {
            $raw = fread($fp, filesize($json_file) ?: 1);
            $jdata = json_decode($raw, true) ?? ['event_name' => $current_event_name, 'participants' => []];

            $dup_js = preg_grep("/^$lastname\|.*\|$barangay$/i", array_map(function($p) {
                return $p['lastname'].'|'.$p['firstname'].'|'.$p['barangay'];
            }, $jdata['participants'] ?? []));
            if ($dup_js) {
                $error = 'A participant with the same name and barangay is already registered.';
            }
            // Also check MySQL
            if (!$error) {
                $dup = $conn->prepare("SELECT id FROM participants WHERE event_id=? AND LOWER(lastname)=LOWER(?) AND LOWER(firstname)=LOWER(?) AND LOWER(barangay)=LOWER(?) LIMIT 1");
                $dup->bind_param("isss", $current_event_id, $lastname, $firstname, $barangay);
                $dup->execute();
                if ($dup->get_result()->num_rows > 0) {
                    $error = 'A participant with the same name and barangay is already registered.';
                }
                $dup->close();
            }

            if (!$error) {
                $max = 0;
                foreach ($jdata['participants'] as $p) {
                    if ((int)$p['number'] > $max) $max = (int)$p['number'];
                }
                $max_q = $conn->prepare("SELECT MAX(CAST(number AS UNSIGNED)) as max_num FROM participants WHERE event_id = ?");
                $max_q->bind_param("i", $current_event_id);
                $max_q->execute();
                $db_max = (int)($max_q->get_result()->fetch_assoc()['max_num'] ?? 0);
                $max_q->close();
                $number = max($max, $db_max) + 1;

                $fullname = $firstname . ' ' . $middlename . ' ' . $lastname;
                $jdata['participants'][] = [
                    'id' => $number,
                    'number' => (string)$number,
                    'lastname' => $lastname,
                    'firstname' => $firstname,
                    'middlename' => $middlename,
                    'name' => $fullname,
                    'birthdate' => $birthdate,
                    'province' => $province,
                    'city' => $city,
                    'barangay' => $barangay,
                    'purok' => $purok,
                    'contact_number' => $contact,
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($jdata, JSON_PRETTY_PRINT));

                $ins = $conn->prepare("INSERT INTO participants (event_id, number, lastname, firstname, middlename, name, birthdate, province, city, barangay, purok, contact_number, photo_data, registration_attachment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param("isssssssssssbb", $current_event_id, $number, $lastname, $firstname, $middlename, $fullname, $birthdate, $province, $city, $barangay, $purok, $contact, $photo_data, $reg_attachment);
                $ins->execute();
                $ins->close();

                $success = [
                    'name' => $firstname,
                    'number' => $number,
                ];
                $submitted = [];
            }
            flock($fp, LOCK_UN);
            fclose($fp);
        } else {
            $error = 'Could not save registration. Please try again.';
            fclose($fp);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Raffle System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --pink-400: #f472b6;
            --pink-500: #ec4899;
            --gray-50: #faf5f7;
            --gray-100: #f5f0f2;
            --gray-200: #e5dce0;
            --gray-300: #c4b5c0;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4a4a6a;
            --gray-900: #1a1a2e;
        }

        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
            background: var(--gray-50);
            color: var(--gray-900);
            -webkit-font-smoothing: antialiased;
        }

        .ambient { position:fixed; inset:0; z-index:0; overflow:hidden; pointer-events:none; }
        .ambient .orb { position:absolute; border-radius:50%; }
        .ambient .orb:nth-child(1) {
            width:600px; height:600px;
            background:radial-gradient(circle at 30% 30%, rgba(236,73,153,.1), transparent 70%);
            top:-240px; right:-180px;
        }
        .ambient .orb:nth-child(2) {
            width:420px; height:420px;
            background:radial-gradient(circle at 70% 70%, rgba(244,114,182,.07), transparent 70%);
            bottom:-140px; left:-100px;
        }

        /* ── Top bar ── */
        .top {
            position:relative; z-index:2;
            display:flex; align-items:center; justify-content:space-between;
            padding:0 32px; height:64px;
            background:rgba(255,255,255,.7); backdrop-filter:blur(20px);
            border-bottom:1px solid rgba(0,0,0,.04);
        }
        .top .brand { display:flex; align-items:center; gap:10px; }
        .top .brand img { height:34px; }
        .top .brand span { font-weight:800; font-size:16px; color:var(--gray-900); letter-spacing:-.3px; }
        .top .right { display:flex; align-items:center; gap:16px; }
        .top .badge {
            padding:5px 14px; border-radius:100px;
            font-size:11px; font-weight:700; letter-spacing:.3px;
            background:var(--pink-50,#fdf2f8); color:var(--pink-500);
            border:1px solid var(--pink-200,#fbcfe8);
        }
        .top .admin {
            font-size:13px; font-weight:600; color:var(--pink-400);
            text-decoration:none;
            display:flex; align-items:center; gap:4px;
        }
        .top .admin:hover { color:var(--pink-500); }
        .top .back {
            font-size:13px; font-weight:600; color:var(--gray-400);
            text-decoration:none;
        }
        .top .back:hover { color:var(--gray-600); }

        /* ── Hero ── */
        .hero {
            position:relative; z-index:1;
            text-align:center; padding:52px 24px 8px;
        }
        .hero h1 { font-size:28px; font-weight:900; letter-spacing:-.5px; color:var(--gray-900); }
        .hero p { font-size:15px; color:var(--gray-400); margin-top:6px; }

        /* ── Card ── */
        .wrap {
            position:relative; z-index:1;
            max-width:720px; margin:24px auto 48px; padding:0 20px;
        }
        .card {
            background:#fff; border-radius:24px; padding:44px 44px 36px;
            border:1px solid rgba(0,0,0,.04);
            box-shadow:0 20px 60px rgba(0,0,0,.04);
        }
        @media (max-width:640px) {
            .card { padding:28px 18px; border-radius:20px; }
            .top { padding:0 16px; }
            .hero { padding-top:36px; }
            .hero h1 { font-size:24px; }
            .wrap { padding:0 14px; }
        }

        /* ── Form grid ── */
        .grid {
            display:grid; grid-template-columns:1fr 1fr; gap:18px 24px;
        }
        .grid .span2 { grid-column:1/-1; }
        @media (max-width:640px) { .grid { grid-template-columns:1fr; gap:14px; } }

        .fld label {
            display:flex; align-items:center; gap:4px;
            font-size:11px; font-weight:700; letter-spacing:.8px;
            text-transform:uppercase; color:var(--gray-500);
            margin-bottom:6px;
        }
        .fld label .star { color:#ef4444; font-size:12px; }

        .fld .ctrl { position:relative; }

        .fld input, .fld select {
            width:100%;
            padding:13px 14px;
            border:1.5px solid var(--gray-200);
            border-radius:10px;
            font-size:14px; font-weight:500;
            font-family:inherit; color:var(--gray-900);
            background:#fff;
            transition:all .2s ease;
            outline:none;
        }

        .fld input:hover, .fld select:hover { border-color:var(--gray-300); }
        .fld input:focus, .fld select:focus {
            border-color:var(--pink-400);
            box-shadow:0 0 0 3px rgba(244,114,182,.1);
        }
        .fld input::placeholder { color:var(--gray-300); font-weight:400; }
        .fld input[type="file"] {
            padding:10px 14px;
            font-size:13px;
            font-weight:500;
            color:var(--gray-500);
        }
        .fld select:disabled { opacity:.4; cursor:not-allowed; background:var(--gray-50); }

        /* ── Button ── */
        .btn {
            width:100%; margin-top:10px; padding:16px 24px;
            border:none; border-radius:12px;
            font-size:15px; font-weight:700; font-family:inherit;
            cursor:pointer; color:#fff;
            background:linear-gradient(135deg, var(--pink-500), var(--pink-400));
            transition:all .25s ease;
            box-shadow:0 4px 18px rgba(236,73,153,.2);
        }
        .btn:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(236,73,153,.3); }

        /* ── Messages ── */
        .msg {
            padding:13px 18px; border-radius:12px;
            font-size:14px; font-weight:500; text-align:center;
            margin-bottom:24px;
        }
        .msg-ok { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
        .msg-err { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }

        /* ── Success overlay ── */
        .overlay {
            display:none; position:fixed; inset:0; z-index:999;
            background:rgba(0,0,0,.5); backdrop-filter:blur(10px);
            align-items:center; justify-content:center; padding:20px;
        }
        .overlay.show { display:flex; animation:fadeIn .3s ease; }

        .overlay .sheet {
            background:linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-radius:24px; padding:48px 36px 36px;
            max-width:380px; width:100%; text-align:center;
            box-shadow:0 30px 80px rgba(0,0,0,.3), 0 0 0 1px rgba(255,255,255,.06);
            position:relative; overflow:hidden;
        }
        .overlay .sheet::before {
            content:''; position:absolute; top:0; left:0; right:0; height:2px;
            background:linear-gradient(90deg, transparent, rgba(244,114,182,.6), rgba(236,73,153,.8), rgba(244,114,182,.6), transparent);
        }

        .overlay .sheet .check {
            width:56px; height:56px; border-radius:50%;
            background:linear-gradient(135deg, #ec4899, #f472b6);
            display:flex; align-items:center; justify-content:center;
            margin:0 auto 16px; font-size:26px; color:#fff;
            box-shadow:0 8px 24px rgba(236,73,153,.3);
            animation:popIn .5s cubic-bezier(.17,.67,.35,1.2);
        }
        @keyframes popIn {
            0% { transform:scale(0); opacity:0; }
            100% { transform:scale(1); opacity:1; }
        }
        @keyframes fadeIn {
            from { opacity:0; }
            to { opacity:1; }
        }

        .overlay .sheet .success-badge {
            display:inline-block;
            font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:1.5px; color:rgba(244,114,182,.8);
            background:rgba(244,114,182,.12);
            padding:5px 14px; border-radius:100px; margin-bottom:12px;
        }

        .overlay .sheet .name {
            font-size:24px; font-weight:700; color:#fff;
            margin-bottom:6px; letter-spacing:-.3px;
        }
        .overlay .sheet .label {
            font-size:14px; color:rgba(255,255,255,.45); margin-bottom:32px;
        }
        .overlay .sheet .ok {
            padding:13px 36px; display:inline-block;
            border:none; border-radius:100px;
            font-size:13px; font-weight:600; font-family:inherit;
            cursor:pointer;
            background:linear-gradient(135deg, #ec4899, #f472b6);
            color:#fff; text-decoration:none;
            transition:all .3s ease;
            box-shadow:0 4px 16px rgba(236,73,153,.25);
        }
        .overlay .sheet .ok:hover {
            transform:translateY(-2px);
            box-shadow:0 8px 28px rgba(236,73,153,.4);
        }

        .ftr {
            text-align:center; padding:0 20px 40px;
            font-size:12px; color:var(--gray-300); letter-spacing:.3px;
            position:relative; z-index:1;
        }

        /* ── Stepper ── */
        .stepper {
            display:flex; align-items:center; justify-content:center;
            gap:0; margin-bottom:28px; padding:0 10px;
        }
        .step-item {
            display:flex; align-items:center; gap:8px;
        }
        .step-circle {
            width:32px; height:32px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:13px; font-weight:700;
            background:var(--gray-200); color:var(--gray-400);
            transition:all .3s ease;
        }
        .step-item.active .step-circle {
            background:linear-gradient(135deg, var(--pink-500), var(--pink-400));
            color:#fff;
            box-shadow:0 4px 14px rgba(236,73,153,.3);
        }
        .step-item.done .step-circle {
            background:#10b981; color:#fff;
        }
        .step-label {
            font-size:12px; font-weight:600; color:var(--gray-400);
            transition:color .3s ease;
        }
        .step-item.active .step-label,
        .step-item.done .step-label { color:var(--gray-900); }
        .step-line {
            width:60px; height:2px; margin:0 8px;
            background:var(--gray-200);
            transition:background .3s ease;
        }
        .step-line.done { background:#10b981; }

        .step-content { display:none; }
        .step-content.active { display:block; animation:fadeIn .3s ease; }

        .btn-row {
            display:flex; gap:12px; margin-top:10px;
        }
        .btn-row .btn { flex:1; }
        .btn-secondary {
            background:var(--gray-200); color:var(--gray-600);
            box-shadow:none;
        }
        .btn-secondary:hover {
            background:var(--gray-300); transform:translateY(-2px);
            box-shadow:none;
        }

        .file-drop {
            border:2px dashed var(--gray-200); border-radius:14px;
            padding:32px 20px; text-align:center;
            cursor:pointer; transition:all .25s ease;
        }
        .file-drop:hover,
        .file-drop.dragover {
            border-color:var(--pink-400);
            background:rgba(244,114,182,.03);
        }
        .file-drop input[type="file"] { display:none; }
        .file-drop .drop-icon {
            font-size:36px; margin-bottom:8px;
        }
        .file-drop .drop-label {
            font-size:14px; font-weight:600; color:var(--gray-600);
        }
        .file-drop .drop-hint {
            font-size:12px; color:var(--gray-400); margin-top:4px;
        }
        .file-drop .file-name {
            font-size:13px; font-weight:600; color:var(--pink-500);
            margin-top:8px; word-break:break-all;
        }

        #confetti { position:fixed; inset:0; pointer-events:none; z-index:1000; }
    </style>
</head>
<body>

<div class="ambient"><div class="orb"></div><div class="orb"></div><div class="orb"></div></div>

<!-- Top bar -->
<div class="top">
    <div class="brand">
        <img src="Logo.png" alt="">
        <span>Raffle System</span>
    </div>
    <div class="right">
        <span class="badge"><?php echo htmlspecialchars($current_event_name); ?></span>
        <a href="index.php" class="back">Back to Home</a>
        <a href="login.php" class="admin">Admin</a>
    </div>
</div>

<!-- Hero -->
<div class="hero">
    <h1>Participant Registration</h1>
    <p>Register for <strong style="color:var(--pink-500);"><?php echo htmlspecialchars($current_event_name); ?></strong></p>
</div>

<!-- Form card -->
    <div class="wrap">
        <div class="card">

            <?php if ($reg_start || $reg_end): ?>
            <div style="text-align:center; padding:10px 0 6px; font-size:12px; color:#6b7280;">
                <?php if ($reg_start): ?><span style="color:#10b981;">Opens <?php echo date('M j, Y g:i A', strtotime($reg_start)); ?></span><?php endif; ?>
                <?php if ($reg_end): ?><?php if ($reg_start): ?> &nbsp;|&nbsp; <?php endif; ?><span style="color:#dc2626;">Closes <?php echo date('M j, Y g:i A', strtotime($reg_end)); ?></span><?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="msg msg-err"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form id="regForm" method="POST" autocomplete="off" novalidate enctype="multipart/form-data">

                <!-- Stepper -->
                <div class="stepper">
                    <div class="step-item active" id="stepItem1">
                        <div class="step-circle">1</div>
                        <span class="step-label">Personal Info</span>
                    </div>
                    <div class="step-line" id="stepLine1"></div>
                    <div class="step-item" id="stepItem2">
                        <div class="step-circle">2</div>
                        <span class="step-label">Documents</span>
                    </div>
                </div>

                <!-- Step 1: Personal Info -->
                <div class="step-content active" id="step1">
                    <div class="grid">

                        <div class="fld">
                            <label for="lastname">Lastname <span class="star">*</span></label>
                            <div class="ctrl">
                                <input type="text" name="lastname" id="lastname" placeholder="e.g. Santos" value="<?php echo htmlspecialchars($submitted['lastname'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="fld">
                            <label for="firstname">Firstname <span class="star">*</span></label>
                            <div class="ctrl">
                                <input type="text" name="firstname" id="firstname" placeholder="e.g. Maria" value="<?php echo htmlspecialchars($submitted['firstname'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="fld">
                            <label for="middlename">Middlename <span class="star">*</span></label>
                            <div class="ctrl">
                                <input type="text" name="middlename" id="middlename" placeholder="e.g. Reyes" value="<?php echo htmlspecialchars($submitted['middlename'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="fld">
                            <label for="birthdate">Birthdate <span class="star">*</span></label>
                            <div class="ctrl">
                                <input type="date" name="birthdate" id="birthdate" value="<?php echo htmlspecialchars($submitted['birthdate'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="fld">
                            <label for="province">Province</label>
                            <select name="province" id="province" disabled>
                                <option value="South Cotabato" selected>South Cotabato</option>
                            </select>
                        </div>

                        <div class="fld">
                            <label for="city">City / Municipality</label>
                            <select name="city" id="city" disabled>
                                <option value="City of Koronadal" selected>City of Koronadal</option>
                            </select>
                        </div>

                        <div class="fld">
                            <label for="barangay">Barangay <span class="star">*</span></label>
                            <select name="barangay" id="barangay" required>
                                <option value="">— Select Barangay —</option>
                                <option value="Assumption">Assumption</option>
                                <option value="Avanceña">Avanceña</option>
                                <option value="Cacub">Cacub</option>
                                <option value="Caloocan">Caloocan</option>
                                <option value="Carpenter Hill">Carpenter Hill</option>
                                <option value="Concepcion">Concepcion</option>
                                <option value="Esperanza">Esperanza</option>
                                <option value="General Paulino Santos">General Paulino Santos</option>
                                <option value="Mabini">Mabini</option>
                                <option value="Magsaysay">Magsaysay</option>
                                <option value="Mambucal">Mambucal</option>
                                <option value="Morales">Morales</option>
                                <option value="Namnama">Namnama</option>
                                <option value="New Pangasinan">New Pangasinan</option>
                                <option value="Paraiso">Paraiso</option>
                                <option value="Rotonda">Rotonda</option>
                                <option value="San Isidro">San Isidro</option>
                                <option value="San Jose">San Jose</option>
                                <option value="San Roque">San Roque</option>
                                <option value="Santa Cruz">Santa Cruz</option>
                                <option value="Santo Niño">Santo Niño</option>
                                <option value="Saravia">Saravia</option>
                                <option value="Topland">Topland</option>
                                <option value="Zone I (Pob.)">Zone I (Pob.)</option>
                                <option value="Zone II (Pob.)">Zone II (Pob.)</option>
                                <option value="Zone III (Pob.)">Zone III (Pob.)</option>
                                <option value="Zone IV (Pob.)">Zone IV (Pob.)</option>
                            </select>
                        </div>

                        <div class="fld">
                            <label for="purok">Purok <span style="color:var(--gray-300);font-size:11px;font-weight:400;">(optional)</span></label>
                            <div class="ctrl">
                                <input type="text" name="purok" id="purok" placeholder="e.g. Purok 3" value="<?php echo htmlspecialchars($submitted['purok'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="fld span2">
                            <label for="contact_number">Contact Number <span class="star">*</span></label>
                            <div class="ctrl">
                                <input type="text" name="contact_number" id="contact_number" placeholder="e.g. 0917 123 4567" value="<?php echo htmlspecialchars($submitted['contact_number'] ?? ''); ?>" required>
                            </div>
                        </div>

                    </div>
                    <div class="btn-row">
                        <button type="button" id="toStep2" class="btn">Next &rarr;</button>
                    </div>
                </div>

                <!-- Step 2: Documents -->
                <div class="step-content" id="step2">
                    <div class="grid">
                        <div class="fld span2">
                            <label for="photo_data">Profile Image <span style="color:var(--gray-300);font-size:11px;font-weight:400;">(optional)</span></label>
                            <div class="file-drop" id="photoDrop">
                                <input type="file" name="photo_data" id="photo_data" accept="image/*">
                                <div class="drop-icon">📷</div>
                                <div class="drop-label">Tap to upload profile image</div>
                                <div class="drop-hint">JPG, PNG &mdash; max 5MB</div>
                                <div class="file-name" id="photoFileName"></div>
                            </div>
                        </div>

                        <div class="fld span2">
                            <label for="registration_attachment">Registration Attachment <span style="color:var(--gray-300);font-size:11px;font-weight:400;">(optional)</span></label>
                            <div class="file-drop" id="attachDrop">
                                <input type="file" name="registration_attachment" id="registration_attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                <div class="drop-icon">📎</div>
                                <div class="drop-label">Tap to upload attachment</div>
                                <div class="drop-hint">PDF, DOC, DOCX, JPG, PNG &mdash; max 10MB</div>
                                <div class="file-name" id="attachFileName"></div>
                            </div>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="button" id="backToStep1" class="btn btn-secondary">&larr; Back</button>
                        <button type="submit" name="register" class="btn">Register Now</button>
                    </div>
                </div>

            </form>
        </div>
    </div>

<div class="ftr">Raffle System &mdash; Raffle Draw Management</div>

<!-- Success overlay -->
        <div class="overlay" id="successOverlay">
            <div class="sheet">
                <div class="check">&#10003;</div>
                <div class="success-badge">Registration Successful</div>
                <div class="name" id="sName"></div>
                <div class="label">is now registered</div>
                <a href="register.php" class="ok">Register Another</a>
            </div>
        </div>

<?php if ($success): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    document.getElementById('sName').textContent = '<?php echo addslashes($success['name']); ?>';
    document.getElementById('successOverlay').classList.add('show');
    startConfetti();
});
</script>
<?php endif; ?>

<!-- Confetti canvas -->
<canvas id="confetti"></canvas>
<script>
(function(){
    var c = document.getElementById('confetti'), ctx = c.getContext('2d');
    var W, H, pieces = [], running = false, frame;
    var colors = ['#ec4899','#f472b6','#f9a8d4','#34d399','#60a5fa','#fbbf24','#a78bfa'];

    function resize(){ W=c.width=window.innerWidth; H=c.height=window.innerHeight; }
    window.addEventListener('resize', resize); resize();

    function Piece(){
        this.x = Math.random()*W; this.y = Math.random()*H-H;
        this.w = 8+Math.random()*6; this.h = 5+Math.random()*4;
        this.c = colors[Math.floor(Math.random()*colors.length)];
        this.vx = (Math.random()-.5)*1.2; this.vy = 2+Math.random()*3;
        this.r = Math.random()*360; this.dr = (Math.random()-.5)*6;
    }

    function startConfetti(){
        if(running) return;
        pieces = []; running = true;
        for(var i=0;i<180;i++) pieces.push(new Piece());
        frame = requestAnimationFrame(draw);
    }
    window.startConfetti = startConfetti;

    function stopConfetti(){ running=false; cancelAnimationFrame(frame); ctx.clearRect(0,0,W,H); }
    window.stopConfetti = stopConfetti;

    function draw(){
        ctx.clearRect(0,0,W,H);
        for(var i=0;i<pieces.length;i++){
            var p=pieces[i];
            p.x+=p.vx; p.y+=p.vy; p.r+=p.dr;
            ctx.save(); ctx.translate(p.x,p.y); ctx.rotate(p.r*Math.PI/180);
            ctx.fillStyle=p.c; ctx.fillRect(-p.w/2,-p.h/2,p.w,p.h); ctx.restore();
            if(p.y>H+30){ p.y=-20; p.x=Math.random()*W; p.vy=2+Math.random()*3; }
        }
        if(running) frame = requestAnimationFrame(draw);
    }
})();

/* ── Stepper Logic ── */
(function(){
    var step1 = document.getElementById('step1');
    var step2 = document.getElementById('step2');
    var item1 = document.getElementById('stepItem1');
    var item2 = document.getElementById('stepItem2');
    var line1 = document.getElementById('stepLine1');
    var toStep2 = document.getElementById('toStep2');
    var backToStep1 = document.getElementById('backToStep1');

    function goToStep(n) {
        if (n === 2) {
            step1.classList.remove('active');
            step2.classList.add('active');
            item1.classList.remove('active');
            item1.classList.add('done');
            line1.classList.add('done');
            item2.classList.add('active');
        } else {
            step2.classList.remove('active');
            step1.classList.add('active');
            item2.classList.remove('active');
            line1.classList.remove('done');
            item1.classList.remove('done');
            item1.classList.add('active');
        }
    }

    toStep2.addEventListener('click', function() {
        var fields = [
            { el: document.getElementById('lastname'), name: 'Lastname' },
            { el: document.getElementById('firstname'), name: 'Firstname' },
            { el: document.getElementById('middlename'), name: 'Middlename' },
            { el: document.getElementById('birthdate'), name: 'Birthdate' },
            { el: document.getElementById('barangay'), name: 'Barangay' },
            { el: document.getElementById('contact_number'), name: 'Contact Number' }
        ];
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].el.value.trim()) {
                fields[i].el.focus();
                fields[i].el.style.borderColor = '#ef4444';
                setTimeout(function(){ fields[i].el.style.borderColor = ''; }.bind(fields[i]), 2000);
                showToast('Please fill in ' + fields[i].name + '.', 'error');
                return;
            }
        }
        goToStep(2);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    backToStep1.addEventListener('click', function() {
        goToStep(1);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    /* ── File Drop Zones ── */
    function setupDrop(dropId, inputId, nameId) {
        var drop = document.getElementById(dropId);
        var input = document.getElementById(inputId);
        var nameEl = document.getElementById(nameId);
        if (!drop || !input) return;

        drop.addEventListener('click', function() { input.click(); });
        drop.addEventListener('dragover', function(e) { e.preventDefault(); drop.classList.add('dragover'); });
        drop.addEventListener('dragleave', function() { drop.classList.remove('dragover'); });
        drop.addEventListener('drop', function(e) {
            e.preventDefault();
            drop.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                nameEl.textContent = e.dataTransfer.files[0].name;
            }
        });
        input.addEventListener('change', function() {
            nameEl.textContent = input.files.length ? input.files[0].name : '';
        });
    }
    setupDrop('photoDrop', 'photo_data', 'photoFileName');
    setupDrop('attachDrop', 'registration_attachment', 'attachFileName');

    <?php if ($error && $error_step === 2): ?>
    goToStep(2);
    <?php endif; ?>
})();
</script>

</body>
</html>