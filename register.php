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

    if (!$error && (empty($lastname) || empty($firstname) || empty($middlename) || empty($birthdate) || empty($barangay))) {
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
                $ins->bind_param("isssssssssssss", $current_event_id, $number, $lastname, $firstname, $middlename, $fullname, $birthdate, $province, $city, $barangay, $purok, $contact, $photo_data, $reg_attachment);
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
            --pink-300: #f9a8d4;
            --pink-400: #f472b6;
            --pink-500: #ec4899;
            --purple-500: #8b5cf6;
            --gray-50: #fafbfe;
            --gray-100: #f1f4f9;
            --gray-200: #e5eaf2;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-900: #0f172a;
        }

        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
            background:
                radial-gradient(900px at 88% -12%, rgba(236,73,153,.10), transparent 60%),
                radial-gradient(800px at -10% 110%, rgba(139,92,246,.09), transparent 60%),
                var(--gray-50);
            color: var(--gray-900);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Toast ── */
        .toast {
            position:fixed; left:50%; bottom:-80px; transform:translateX(-50%);
            z-index:2000; padding:13px 22px; border-radius:14px;
            background:#111827; color:#fff;
            font-size:13.5px; font-weight:600;
            box-shadow:0 14px 40px rgba(0,0,0,.25);
            opacity:0; pointer-events:none;
            transition:bottom .35s cubic-bezier(.22,1,.36,1), opacity .3s ease;
        }
        .toast.show { bottom:28px; opacity:1; }
        .toast.error { background:#dc2626; }

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
            text-align:center; padding:52px 24px 10px;
        }
        .pill {
            display:inline-flex; align-items:center; gap:8px;
            font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase;
            color:var(--pink-500); background:#fff;
            border:1px solid var(--pink-200,#fbcfe8);
            padding:7px 16px; border-radius:100px;
            box-shadow:0 2px 10px rgba(236,73,153,.08);
        }
        .pill::before {
            content:''; width:7px; height:7px; border-radius:50%;
            background:linear-gradient(135deg, var(--pink-500), var(--purple-500));
            box-shadow:0 0 0 3px rgba(236,73,153,.15);
        }
        .hero h1 {
            margin-top:18px;
            font-size:clamp(28px, 5vw, 40px);
            font-weight:900; letter-spacing:-1px; line-height:1.08; color:var(--gray-900);
        }
        .hero h1 em {
            font-style:normal;
            background:linear-gradient(120deg, var(--pink-500), var(--purple-500));
            -webkit-background-clip:text; background-clip:text;
            -webkit-text-fill-color:transparent;
        }
        .hero p { font-size:15px; color:var(--gray-400); margin-top:10px; }
        .hero p strong { color:var(--gray-600); }

        /* ── Card ── */
        .wrap {
            position:relative; z-index:1;
            max-width:720px; margin:24px auto 48px; padding:0 20px;
        }
        .card {
            position:relative; overflow:hidden;
            background:#fff; border-radius:24px; padding:44px 44px 36px;
            border:1px solid rgba(0,0,0,.05);
            box-shadow:0 2px 6px rgba(15,23,42,.03), 0 24px 60px rgba(15,23,42,.06);
            animation:rise .5s cubic-bezier(.22,1,.36,1);
        }
        .card::before {
            content:''; position:absolute; top:0; left:0; right:0; height:4px;
            background:linear-gradient(90deg, var(--pink-500), var(--purple-500));
        }
        @keyframes rise {
            from { opacity:0; transform:translateY(16px); }
            to   { opacity:1; transform:none; }
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

        /* ── Section titles ── */
        .sec-title {
            display:flex; align-items:center; gap:10px;
            font-size:11px; font-weight:800; letter-spacing:1.4px;
            text-transform:uppercase; color:var(--gray-400);
            margin-top:8px;
        }
        .sec-title::before {
            content:''; width:22px; height:3px; border-radius:99px;
            background:linear-gradient(90deg, var(--pink-500), var(--purple-500));
            flex:none;
        }

        /* ── Registration window chip ── */
        .regwin {
            display:flex; justify-content:center; gap:10px; flex-wrap:wrap;
            margin-bottom:28px;
        }
        .regwin span {
            font-size:12px; font-weight:600;
            padding:7px 15px; border-radius:100px;
            background:var(--gray-100); color:var(--gray-500);
        }
        .regwin .rw-open  { color:#059669; background:#ecfdf5; }
        .regwin .rw-close { color:#dc2626; background:#fef2f2; }

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
            background:linear-gradient(135deg, var(--pink-500), var(--purple-500));
            transition:all .25s ease;
            box-shadow:0 4px 18px rgba(236,73,153,.22);
        }
        .btn:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(139,92,246,.3); }
        .btn:active { transform:none; }

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
            padding:5px 14px; border-radius:100px; margin-bottom:20px;
        }

        .overlay .sheet .tlabel {
            font-size:10.5px; font-weight:700; letter-spacing:2.5px;
            text-transform:uppercase; color:rgba(255,255,255,.4);
            margin-bottom:4px;
        }
        .overlay .sheet .tnum {
            font-size:60px; font-weight:900; line-height:1;
            letter-spacing:-1px; margin-bottom:18px;
            background:linear-gradient(135deg, #f9a8d4, #c4b5fd);
            -webkit-background-clip:text; background-clip:text;
            -webkit-text-fill-color:transparent;
            animation:popIn .55s .1s cubic-bezier(.17,.67,.35,1.2) backwards;
        }

        .overlay .sheet .name {
            font-size:22px; font-weight:700; color:#fff;
            margin-bottom:6px; letter-spacing:-.3px;
        }
        .overlay .sheet .label {
            font-size:14px; color:rgba(255,255,255,.45); margin-bottom:30px;
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
            background:#10b981; color:#fff; font-size:0;
            box-shadow:0 4px 14px rgba(16,185,129,.3);
        }
        .step-item.done .step-circle::after {
            content:'\2713'; font-size:14px;
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
            border:2px dashed var(--gray-200); border-radius:16px;
            padding:26px 20px; text-align:center;
            cursor:pointer; transition:all .25s ease;
            background:var(--gray-50);
        }
        .file-drop:hover,
        .file-drop.dragover {
            border-color:var(--pink-400);
            background:#fff;
            box-shadow:0 6px 20px rgba(236,73,153,.08);
            transform:translateY(-1px);
        }
        .file-drop input[type="file"] { display:none; }
        .file-drop .drop-icon {
            width:48px; height:48px; margin:0 auto 10px;
            border-radius:14px;
            background:linear-gradient(135deg, rgba(236,73,153,.09), rgba(139,92,246,.09));
            display:flex; align-items:center; justify-content:center;
            color:var(--pink-500);
        }
        .file-drop .drop-icon svg { width:21px; height:21px; }
        .file-drop .drop-label {
            font-size:14px; font-weight:600; color:var(--gray-600);
        }
        .file-drop .drop-hint {
            font-size:12px; color:var(--gray-400); margin-top:4px;
        }
        .file-drop .file-name {
            display:none;
            font-size:12.5px; font-weight:600; color:var(--pink-500);
            margin:10px auto 0; max-width:90%;
            background:rgba(236,73,153,.07); border-radius:100px;
            padding:5px 14px; word-break:break-all;
        }
        .file-drop.has-file .file-name { display:inline-block; }

        /* ── Camera ── */
        .cam-btn {
            margin-top:10px; width:100%; padding:12px;
            border:1.5px solid var(--gray-200); border-radius:12px;
            background:#fff; font-family:inherit;
            font-size:13px; font-weight:600; color:var(--gray-600);
            cursor:pointer; transition:all .2s ease;
            display:flex; align-items:center; justify-content:center; gap:8px;
        }
        .cam-btn svg { width:16px; height:16px; }
        .cam-btn:hover { border-color:var(--pink-400); color:var(--pink-500); }

        .overlay .cam-sheet {
            background:#fff; border-radius:24px; padding:20px;
            max-width:420px; width:100%;
            box-shadow:0 30px 80px rgba(0,0,0,.35);
        }
        .cam-sheet h3 {
            font-size:14px; font-weight:800; letter-spacing:.2px;
            color:var(--gray-900); text-align:center; margin-bottom:14px;
        }
        .cam-view {
            border-radius:16px; overflow:hidden;
            background:#0f172a; aspect-ratio:4/3;
        }
        .cam-view video {
            width:100%; height:100%; object-fit:cover; display:block;
            transform:scaleX(-1);
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
    <div class="pill">Registration Open</div>
    <h1>Join the <em>Raffle Draw</em></h1>
    <p>You are registering for <strong><?php echo htmlspecialchars($current_event_name); ?></strong></p>
</div>

<!-- Form card -->
    <div class="wrap">
        <div class="card">

            <?php if ($reg_start || $reg_end): ?>
            <div class="regwin">
                <?php if ($reg_start): ?><span class="rw-open">Opens <?php echo date('M j, Y g:i A', strtotime($reg_start)); ?></span><?php endif; ?>
                <?php if ($reg_end): ?><span class="rw-close">Closes <?php echo date('M j, Y g:i A', strtotime($reg_end)); ?></span><?php endif; ?>
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

                        <div class="sec-title span2">Personal Information</div>

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

                        <div class="sec-title span2">Address</div>

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
                            <label for="contact_number">Contact Number <span style="color:var(--gray-300);font-size:11px;font-weight:400;">(optional)</span></label>
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
                                <div class="drop-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></div>
                                <div class="drop-label">Tap to upload profile image</div>
                                <div class="drop-hint">JPG, PNG &mdash; max 5MB</div>
                                <div class="file-name" id="photoFileName"></div>
                            </div>
                            <button type="button" id="useCamera" class="cam-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                or use camera
                            </button>
                        </div>

                        <div class="fld span2">
                            <label for="registration_attachment">Registration Attachment <span style="color:var(--gray-300);font-size:11px;font-weight:400;">(optional)</span></label>
                            <div class="file-drop" id="attachDrop">
                                <input type="file" name="registration_attachment" id="registration_attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                <div class="drop-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></div>
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
                <div class="tlabel">Your Ticket No.</div>
                <div class="tnum" id="sNumber"></div>
                <div class="name" id="sName"></div>
                <div class="label">is now registered &mdash; see you at the draw!</div>
                <a href="register.php" class="ok">Register Another</a>
            </div>
        </div>

<!-- Camera modal -->
<div class="overlay" id="cameraOverlay">
    <div class="cam-sheet">
        <h3>Take Profile Photo</h3>
        <div class="cam-view"><video id="camVideo" autoplay playsinline muted></video></div>
        <div class="btn-row">
            <button type="button" id="camCancel" class="btn btn-secondary">Cancel</button>
            <button type="button" id="camCapture" class="btn">Capture Photo</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<?php if ($success): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    document.getElementById('sName').textContent = '<?php echo addslashes($success['name']); ?>';
    document.getElementById('sNumber').textContent = '<?php echo addslashes($success['number']); ?>';
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

/* ── Toast ── */
var toastTimer;
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (type === 'error' ? ' error' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function(){ t.classList.remove('show'); }, 2600);
}

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
            { el: document.getElementById('barangay'), name: 'Barangay' }
        ];
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].el.value.trim()) {
                var el = fields[i].el;
                el.focus();
                el.style.borderColor = '#ef4444';
                setTimeout(function(){ el.style.borderColor = ''; }, 2000);
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
                drop.classList.add('has-file');
            }
        });
        input.addEventListener('change', function() {
            if (input.files.length) {
                nameEl.textContent = input.files[0].name;
                drop.classList.add('has-file');
            } else {
                nameEl.textContent = '';
                drop.classList.remove('has-file');
            }
        });
    }
    setupDrop('photoDrop', 'photo_data', 'photoFileName');
    setupDrop('attachDrop', 'registration_attachment', 'attachFileName');

    /* ── Camera capture ── */
    var camStream = null,
        camOverlay = document.getElementById('cameraOverlay'),
        camVideo = document.getElementById('camVideo'),
        photoInput = document.getElementById('photo_data'),
        photoDropEl = document.getElementById('photoDrop');

    function stopCam() {
        if (camStream) {
            camStream.getTracks().forEach(function(t){ t.stop(); });
            camStream = null;
        }
        camOverlay.classList.remove('show');
    }

    document.getElementById('useCamera').addEventListener('click', function() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showToast('Camera is not supported on this browser.', 'error');
            return;
        }
        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 960 }, height: { ideal: 720 } },
            audio: false
        }).then(function(stream) {
            camStream = stream;
            camVideo.srcObject = stream;
            camOverlay.classList.add('show');
        }).catch(function() {
            showToast('Unable to access camera. Please allow permission.', 'error');
        });
    });

    document.getElementById('camCancel').addEventListener('click', stopCam);

    document.getElementById('camCapture').addEventListener('click', function() {
        var vw = camVideo.videoWidth, vh = camVideo.videoHeight;
        if (!vw || !vh) { showToast('Camera is not ready yet.', 'error'); return; }
        var size = Math.min(vw, vh);
        var canvas = document.createElement('canvas');
        canvas.width = size; canvas.height = size;
        var ctx = canvas.getContext('2d');
        ctx.translate(size, 0); ctx.scale(-1, 1); /* mirror to match preview */
        ctx.drawImage(camVideo, (vw - size) / 2, (vh - size) / 2, size, size, 0, 0, size, size);
        canvas.toBlob(function(blob) {
            if (!blob) { showToast('Could not process the photo.', 'error'); stopCam(); return; }
            try {
                var dt = new DataTransfer();
                dt.items.add(new File([blob], 'camera-photo.jpg', { type: 'image/jpeg' }));
                photoInput.files = dt.files;
            } catch (err) {}
            document.getElementById('photoFileName').textContent = 'Captured photo \u2713';
            photoDropEl.classList.add('has-file');
            stopCam();
            showToast('Photo captured!');
        }, 'image/jpeg', 0.92);
    });

    <?php if ($error && $error_step === 2): ?>
    goToStep(2);
    <?php endif; ?>
})();
</script>

</body>
</html>