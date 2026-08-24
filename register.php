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
    foreach (['lastname','firstname','middlename','suffix','sex','nationality','barangay','purok','contact_number'] as $f) {
        $submitted[$f] = strtoupper(trim($_POST[$f] ?? ''));
    }
    $submitted['birthdate'] = trim($_POST['birthdate'] ?? '');
    $submitted['email']     = strtolower(trim($_POST['email'] ?? ''));

    $lastname    = $submitted['lastname'];
    $firstname   = $submitted['firstname'];
    $middlename  = $submitted['middlename'];
    $suffix      = $submitted['suffix'];
    $sex         = $submitted['sex'];
    $nationality = $submitted['nationality'] !== '' ? $submitted['nationality'] : 'FILIPINO';
    $email       = $submitted['email'];
    $birthdate   = $submitted['birthdate'];
    $province    = 'South Cotabato';
    $city        = 'City of Koronadal';
    $barangay    = $submitted['barangay'];
    $purok       = $submitted['purok'];
    $contact     = $submitted['contact_number'];

    $photo_data = null;
    $reg_attachment = null;

    if (!empty($_FILES['photo_data']['tmp_name'])) {
        if ($_FILES['photo_data']['size'] <= 5 * 1024 * 1024) {
            $photo_data = file_get_contents($_FILES['photo_data']['tmp_name']);
        } else {
            $error = 'Profile image must be 5MB or less.';
            $error_step = 3;
        }
    }

    if (!$error && !empty($_FILES['registration_attachment']['tmp_name'])) {
        if ($_FILES['registration_attachment']['size'] <= 5 * 1024 * 1024) {
            $reg_attachment = file_get_contents($_FILES['registration_attachment']['tmp_name']);
        } else {
            $error = 'Supporting document must be 5MB or less.';
            $error_step = 3;
        }
    }

    if (!$error && (empty($lastname) || empty($firstname) || empty($birthdate) || empty($sex))) {
        $error = 'Please fill in all required personal details.';
        $error_step = 1;
    } elseif (!$error && (empty($barangay) || empty($contact))) {
        $error = 'Please complete your residency and contact details.';
        $error_step = 2;
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

                $fullname = trim($firstname . ' ' . $middlename . ' ' . $lastname . ($suffix !== '' ? ' ' . $suffix : ''));
                $jdata['participants'][] = [
                    'id' => $number,
                    'number' => (string)$number,
                    'lastname' => $lastname,
                    'firstname' => $firstname,
                    'middlename' => $middlename,
                    'suffix' => $suffix,
                    'name' => $fullname,
                    'birthdate' => $birthdate,
                    'sex' => $sex,
                    'nationality' => $nationality,
                    'province' => $province,
                    'city' => $city,
                    'barangay' => $barangay,
                    'purok' => $purok,
                    'contact_number' => $contact,
                    'email' => $email,
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($jdata, JSON_PRETTY_PRINT));

                $ins = $conn->prepare("INSERT INTO participants (event_id, number, lastname, firstname, middlename, suffix, name, birthdate, province, city, barangay, purok, contact_number, sex, nationality, email, photo_data, registration_attachment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param("isssssssssssssssss", $current_event_id, $number, $lastname, $firstname, $middlename, $suffix, $fullname, $birthdate, $province, $city, $barangay, $purok, $contact, $sex, $nationality, $email, $photo_data, $reg_attachment);
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root {
            --brand: #c2175b;
            --brand-dark: #a3124c;
            --brand-soft: rgba(194, 23, 91, 0.08);
            --brand-ring: rgba(194, 23, 91, 0.16);
            --emerald-text: #047857;
            --emerald-bg: rgba(16, 185, 129, 0.1);
            --emerald-border: rgba(16, 185, 129, 0.35);
            --red-text: #dc2626;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
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
                radial-gradient(900px at 88% -12%, rgba(194,23,91,.08), transparent 60%),
                radial-gradient(800px at -10% 110%, rgba(14,165,233,.06), transparent 60%),
                var(--gray-50);
            color: var(--gray-900);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Toast ── */
        .toast {
            position:fixed; left:50%; bottom:-80px; transform:translateX(-50%);
            z-index:3000; padding:13px 22px; border-radius:14px;
            background:#111827; color:#fff;
            font-size:13.5px; font-weight:600;
            box-shadow:0 14px 40px rgba(0,0,0,.25);
            opacity:0; pointer-events:none;
            transition:bottom .35s cubic-bezier(.22,1,.36,1), opacity .3s ease;
        }
        .toast.show { bottom:28px; opacity:1; }
        .toast.error { background:#dc2626; }

        /* ── Top bar ── */
        .top {
            display:flex; align-items:center; justify-content:space-between;
            padding:0 32px; height:64px;
            background:rgba(255,255,255,.7); backdrop-filter:blur(20px);
            border-bottom:1px solid rgba(0,0,0,.05);
        }
        .top .brand { display:flex; align-items:center; gap:10px; }
        .top .brand img { height:34px; }
        .top .brand span { font-weight:800; font-size:16px; color:var(--gray-900); letter-spacing:-.3px; }
        .top .right { display:flex; align-items:center; gap:16px; }
        .top .badge {
            padding:5px 14px; border-radius:100px;
            font-size:11px; font-weight:700; letter-spacing:.3px;
            background:var(--brand-soft); color:var(--brand);
            border:1px solid var(--brand-ring);
        }
        .top .admin {
            font-size:13px; font-weight:600; color:var(--brand);
            text-decoration:none; display:flex; align-items:center; gap:4px;
        }
        .top .admin:hover { color:var(--brand-dark); }
        .top .back { font-size:13px; font-weight:600; color:var(--gray-400); text-decoration:none; }
        .top .back:hover { color:var(--gray-600); }

        /* ── Hero ── */
        .hero { text-align:center; padding:48px 24px 10px; }
        .pill {
            display:inline-flex; align-items:center; gap:8px;
            font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase;
            color:var(--brand); background:#fff;
            border:1px solid var(--brand-ring);
            padding:7px 16px; border-radius:100px;
            box-shadow:0 2px 10px rgba(194,23,91,.08);
        }
        .pill::before {
            content:''; width:7px; height:7px; border-radius:50%;
            background:linear-gradient(135deg, var(--brand), #e0559b);
            box-shadow:0 0 0 3px var(--brand-ring);
        }
        .hero h1 {
            margin-top:18px;
            font-size:clamp(28px, 5vw, 40px);
            font-weight:900; letter-spacing:-1px; line-height:1.08; color:var(--gray-900);
        }
        .hero h1 em {
            font-style:normal;
            background:linear-gradient(120deg, var(--brand), #e0559b);
            -webkit-background-clip:text; background-clip:text;
            -webkit-text-fill-color:transparent;
        }
        .hero p { font-size:15px; color:var(--gray-500); margin-top:10px; }
        .hero p strong { color:var(--gray-600); }

        /* ── Card ── */
        .wrap { position:relative; z-index:1; max-width:880px; margin:24px auto 48px; padding:0 20px; }
        .card {
            background:#fff; border-radius:24px; padding:40px 44px 36px;
            border:1px solid rgba(0,0,0,.05);
            box-shadow:0 2px 6px rgba(15,23,42,.03), 0 24px 60px rgba(15,23,42,.06);
            animation:rise .5s cubic-bezier(.22,1,.36,1);
        }
        @keyframes rise {
            from { opacity:0; transform:translateY(16px); }
            to   { opacity:1; transform:none; }
        }
        @media (max-width:640px) { .card { padding:26px 18px; border-radius:20px; } }

        /* ── Registration window chips ── */
        .regwin { display:flex; justify-content:center; gap:10px; flex-wrap:wrap; margin-bottom:26px; }
        .regwin span { font-size:12px; font-weight:600; padding:7px 15px; border-radius:100px; background:var(--gray-100); color:var(--gray-500); }
        .regwin .rw-open  { color:#059669; background:#ecfdf5; }
        .regwin .rw-close { color:#dc2626; background:#fef2f2; }

        /* ── Error banner ── */
        .callout {
            display:flex; align-items:flex-start; gap:10px;
            border-radius:14px; padding:14px 16px; font-size:12.5px; line-height:1.55;
            margin-bottom:22px;
        }
        .callout i { margin-top:2px; flex-shrink:0; }
        .callout-red { background:rgba(220,38,38,.08); border:1px solid rgba(220,38,38,.25); color:var(--red-text); }

        /* ── Stepper ── */
        .stepper { display:flex; align-items:center; justify-content:center; margin:0 0 34px; }
        .step-item { display:flex; flex-direction:column; align-items:center; gap:7px; min-width:74px; }
        .step-circle {
            width:36px; height:36px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:13px; font-weight:800;
            background:var(--gray-100); color:var(--gray-400);
            border:2px solid var(--gray-200); transition:all .3s ease;
        }
        .step-item.active .step-circle {
            background:var(--brand); color:#fff; border-color:var(--brand);
            box-shadow:0 4px 14px rgba(194,23,91,.35);
        }
        .step-item.done .step-circle {
            background:#10b981; color:#fff; border-color:#10b981; font-size:0;
            box-shadow:0 4px 14px rgba(16,185,129,.3);
        }
        .step-item.done .step-circle::after { content:'\2713'; font-size:14px; }
        .step-label { font-size:11px; font-weight:600; color:var(--gray-400); text-align:center; }
        .step-item.active .step-label { color:var(--brand); }
        .step-line { flex:1; max-width:90px; height:2px; background:var(--gray-200); margin:0 6px 20px; border-radius:99px; transition:background .3s ease; }
        .step-line.done { background:#10b981; }
        @media (max-width:640px) { .step-label { display:none; } .stepper { margin-bottom:26px; } .step-line { margin-bottom:0; } }

        /* ── Steps ── */
        .step-content { display:none; animation:fadeIn .35s ease; }
        .step-content.active { display:block; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }

        .sec-head { border-bottom:1px solid var(--gray-100); padding-bottom:14px; margin-bottom:22px; }
        .sec-head h2 {
            font-size:15px; font-weight:700; color:var(--gray-900);
            display:flex; align-items:center; gap:9px;
        }
        .sec-head h2 i { color:var(--brand); font-size:14px; }
        .sec-head p { font-size:12px; color:var(--gray-500); margin-top:4px; padding-left:23px; }

        .grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:16px; }
        .grid.g3 { grid-template-columns:repeat(3, 1fr); }
        .grid.g4 { grid-template-columns:repeat(4, 1fr); }
        .span2 { grid-column:1/-1; }
        @media (max-width:720px) {
            .grid, .grid.g3, .grid.g4 { grid-template-columns:1fr; gap:14px; }
        }
        @media (min-width:721px) and (max-width:860px) {
            .grid.g4 { grid-template-columns:repeat(2, 1fr); }
        }

        .fld label {
            display:flex; align-items:center; gap:4px;
            font-size:12px; font-weight:600; color:var(--gray-600);
            margin-bottom:6px; letter-spacing:.2px;
        }
        .fld label .star { color:var(--brand); font-weight:800; }
        .fld label .opt { color:var(--gray-400); font-weight:400; font-size:11px; }

        input[type=text], input[type=date], input[type=tel], input[type=email], select {
            width:100%; padding:10px 13px;
            border:1.5px solid var(--gray-200); border-radius:10px;
            font-family:inherit; font-size:13.5px; font-weight:500; color:var(--gray-900);
            background:#fff; outline:none;
            transition:border-color .2s ease, box-shadow .2s ease;
        }
        select:disabled { background:var(--gray-100); color:var(--gray-500); cursor:not-allowed; }
        input:focus, select:focus { border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-ring); }
        ::placeholder { color:var(--gray-400); font-weight:400; }

        /* ── Brand buttons ── */
        .btn-brand {
            display:inline-flex; align-items:center; justify-content:center; gap:7px;
            background:var(--brand); color:#fff; border:none; cursor:pointer;
            padding:10px 18px; border-radius:10px;
            font-family:inherit; font-size:12px; font-weight:600;
            box-shadow:0 4px 14px rgba(194,23,91,.22);
            transition:all .2s ease; white-space:nowrap;
        }
        .btn-brand:hover { background:var(--brand-dark); transform:translateY(-1px); }
        .btn-brand:active { transform:none; }

        /* ── Verification cards (step 3) ── */
        .vgrid { display:grid; grid-template-columns:1fr; gap:20px; }
        @media (min-width:820px) { .vgrid { grid-template-columns:1fr 1fr; } }
        .vcards {
            border:1px solid var(--gray-200); background:rgba(248,250,252,.6);
            border-radius:14px; padding:16px;
            display:flex; flex-direction:column;
        }
        .vcards > label.vhead { font-size:12px; font-weight:700; color:var(--gray-800,#1e293b); display:block; margin-bottom:8px; }
        .vcards > label.vhead .star { color:var(--brand); }
        .vcards .vhint { font-size:11px; color:var(--gray-500); line-height:1.5; }
        .spacer { flex:1; }

        /* ── File dropzones ── */
        .file-drop {
            border:2px dashed var(--gray-300); border-radius:12px;
            padding:22px 16px; text-align:center; cursor:pointer;
            transition:all .25s ease; background:#fff;
        }
        .file-drop:hover, .file-drop.dragover { border-color:var(--brand); box-shadow:0 6px 20px rgba(194,23,91,.08); transform:translateY(-1px); }
        .file-drop input[type=file] { display:none; }
        .file-drop .drop-icon { font-size:22px; color:var(--gray-400); margin-bottom:6px; }
        .file-drop .drop-label { font-size:12px; font-weight:600; color:var(--gray-600); word-break:break-all; }
        .file-drop .browse-btn {
            display:inline-block; margin-top:10px; cursor:pointer;
            background:var(--brand); color:#fff;
            padding:8px 16px; border-radius:9px;
            font-size:11.5px; font-weight:600;
            box-shadow:0 2px 8px rgba(194,23,91,.2);
        }
        .file-chip {
            display:none; margin-top:12px;
            align-items:center; justify-content:space-between; gap:10px;
            background:var(--emerald-bg); border:1px solid var(--emerald-border);
            border-radius:9px; padding:8px 12px; font-size:11.5px; color:var(--emerald-text);
        }
        .file-chip.show { display:flex; }
        .file-chip .fname { display:flex; align-items:center; gap:6px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .file-chip button { background:none; border:none; color:#e11d48; font-family:inherit; font-size:11.5px; font-weight:700; cursor:pointer; white-space:nowrap; }
        .file-chip button:hover { text-decoration:underline; }

        /* ── Camera button under photo drop ── */
        .cam-btn {
            margin-top:10px; width:100%; padding:11px;
            border:1.5px solid var(--gray-200); border-radius:10px;
            background:#fff; font-family:inherit;
            font-size:12.5px; font-weight:600; color:var(--gray-600);
            cursor:pointer; transition:all .2s ease;
            display:flex; align-items:center; justify-content:center; gap:8px;
        }
        .cam-btn:hover { border-color:var(--brand); color:var(--brand); }
        .cam-note { margin-top:10px; font-size:11px; color:var(--gray-500); text-align:center; }

        /* ── Wizard navigation ── */
        .wiznav {
            margin-top:30px; padding-top:22px;
            border-top:1px solid var(--gray-100);
            display:flex; align-items:center; justify-content:space-between; gap:12px;
        }
        .wiznav .ghost {
            visibility:hidden;
            display:inline-flex; align-items:center; gap:8px;
            padding:11px 20px; border-radius:10px;
            border:1.5px solid var(--gray-300); background:#fff;
            font-family:inherit; font-size:12px; font-weight:600; color:var(--gray-600);
            cursor:pointer; transition:all .2s ease;
        }
        .wiznav .ghost.visible { visibility:visible; }
        .wiznav .ghost:hover { background:var(--gray-100); }
        .wiznav .next-btn { min-width:170px; }
        .wiznav .next-btn[disabled] { opacity:.55; cursor:not-allowed; transform:none; }
        .wiznav .next-btn i.fa-arrow-right { font-size:10px; }

        /* ── Buttons shared ── */
        .btn-secondary {
            display:inline-flex; align-items:center; justify-content:center; gap:7px;
            background:#fff; color:var(--gray-600);
            border:1.5px solid var(--gray-300); cursor:pointer;
            padding:10px 18px; border-radius:10px;
            font-family:inherit; font-size:12px; font-weight:600;
            transition:all .2s ease; text-decoration:none;
        }
        .btn-secondary:hover { background:var(--gray-100); }

        /* ── Overlays ── */
        .overlay {
            position:fixed; inset:0; z-index:1500;
            background:rgba(15,23,42,.72); backdrop-filter:blur(6px);
            display:flex; align-items:center; justify-content:center; padding:20px;
            opacity:0; pointer-events:none; transition:opacity .3s ease;
        }
        .overlay.show { opacity:1; pointer-events:auto; }

        .sheet {
            background:linear-gradient(145deg, #1e293b, #0f172a);
            border-radius:24px; padding:42px 46px; text-align:center;
            max-width:420px; width:100%;
            box-shadow:0 30px 80px rgba(0,0,0,.4);
            transform:scale(.95); transition:transform .3s cubic-bezier(.22,1,.36,1);
        }
        .overlay.show .sheet { transform:scale(1); }
        .sheet .check {
            width:64px; height:64px; margin:0 auto 18px;
            border-radius:50%; background:#10b981; color:#fff;
            display:flex; align-items:center; justify-content:center; font-size:28px;
            box-shadow:0 8px 30px rgba(16,185,129,.45);
            animation:popIn .5s cubic-bezier(.17,.67,.35,1.2);
        }
        @keyframes popIn { from{transform:scale(0)} to{transform:scale(1)} }
        .success-badge {
            display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:1.5px; color:rgba(244,114,182,.85);
            background:rgba(244,114,182,.12);
            padding:5px 14px; border-radius:100px; margin-bottom:20px;
        }
        .sheet .tlabel { font-size:10.5px; font-weight:700; letter-spacing:2.5px; text-transform:uppercase; color:rgba(255,255,255,.4); margin-bottom:4px; }
        .sheet .tnum {
            font-size:60px; font-weight:900; line-height:1; letter-spacing:-1px; margin-bottom:18px;
            background:linear-gradient(135deg, #f9a8d4, #c4b5fd);
            -webkit-background-clip:text; background-clip:text;
            -webkit-text-fill-color:transparent;
            animation:popIn .55s .1s cubic-bezier(.17,.67,.35,1.2) backwards;
        }
        .sheet .name { font-size:22px; font-weight:700; color:#fff; margin-bottom:6px; letter-spacing:-.3px; }
        .sheet .sub { font-size:14px; color:rgba(255,255,255,.45); margin-bottom:30px; }
        .sheet .ok {
            padding:13px 36px; display:inline-block;
            background:linear-gradient(135deg, var(--brand), #e0559b);
            color:#fff; text-decoration:none; border-radius:12px;
            font-size:13px; font-weight:700;
            box-shadow:0 8px 26px rgba(194,23,91,.4);
        }

        /* ── Camera modal ── */
        .cam-sheet {
            background:#fff; border-radius:20px; padding:20px;
            max-width:420px; width:100%;
            box-shadow:0 30px 80px rgba(0,0,0,.35);
        }
        .cam-sheet h3 { font-size:14px; font-weight:800; color:var(--gray-900); text-align:center; margin-bottom:14px; }
        .cam-view { border-radius:14px; overflow:hidden; background:var(--gray-900); aspect-ratio:4/3; }
        .cam-view video { width:100%; height:100%; object-fit:cover; display:block; transform:scaleX(-1); }
        .btn-row { display:flex; gap:10px; margin-top:14px; }
        .btn-row .btn-brand, .btn-row .btn-secondary { flex:1; }

        #confetti { position:fixed; inset:0; pointer-events:none; z-index:1200; }
        .ftr { text-align:center; font-size:11px; color:var(--gray-400); padding:0 20px 30px; }
    </style>
</head>
<body>

<!-- Top bar -->
<div class="top">
    <div class="brand">
        <img src="Logo.png" alt="Logo">
        <span>Raffle System</span>
    </div>
    <div class="right">
        <span class="badge">Registration Open</span>
        <a class="admin" href="login.php"><i class="fas fa-right-to-bracket"></i> Admin Login</a>
        <a class="back" href="index.php">Back to Home</a>
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
            <div class="callout callout-red"><i class="fas fa-circle-exclamation"></i><span><?php echo htmlspecialchars($error); ?></span></div>
        <?php endif; ?>

        <form id="regForm" method="POST" autocomplete="off" novalidate enctype="multipart/form-data">
            <input type="hidden" name="register" value="1">

            <!-- Stepper -->
            <div class="stepper">
                <div class="step-item active" id="stepItem1">
                    <div class="step-circle">1</div>
                    <span class="step-label">Personal Identity</span>
                </div>
                <div class="step-line" id="stepLine1"></div>
                <div class="step-item" id="stepItem2">
                    <div class="step-circle">2</div>
                    <span class="step-label">Residency &amp; Contact</span>
                </div>
                <div class="step-line" id="stepLine2"></div>
                <div class="step-item" id="stepItem3">
                    <div class="step-circle">3</div>
                    <span class="step-label">Verification</span>
                </div>
            </div>

            <!-- ══════════ STEP 1: Personal Identity ══════════ -->
            <div class="step-content active" id="step1">

                <div class="sec-head">
                    <h2><i class="fas fa-user"></i> Personal Identity</h2>
                    <p>Enter your official name as shown on government IDs.</p>
                </div>

                <div class="grid g3">
                    <div class="fld">
                        <label for="firstname">First Name <span class="star">*</span></label>
                        <input type="text" name="firstname" id="firstname" data-upper placeholder="Juan" value="<?php echo htmlspecialchars($submitted['firstname'] ?? ''); ?>" required>
                    </div>
                    <div class="fld">
                        <label for="middlename">Middle Name <span class="opt">(Optional)</span></label>
                        <input type="text" name="middlename" id="middlename" data-upper placeholder="Dela Cruz" value="<?php echo htmlspecialchars($submitted['middlename'] ?? ''); ?>">
                    </div>
                    <div class="fld">
                        <label for="lastname">Last Name <span class="star">*</span></label>
                        <input type="text" name="lastname" id="lastname" data-upper placeholder="Santos" value="<?php echo htmlspecialchars($submitted['lastname'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="grid g4" style="margin-top:16px;">
                    <div class="fld">
                        <label for="suffix">Suffix</label>
                        <select name="suffix" id="suffix">
                            <option value="">None</option>
                            <option value="JR.">Jr.</option>
                            <option value="SR.">Sr.</option>
                            <option value="I">I</option>
                            <option value="II">II</option>
                            <option value="III">III</option>
                            <option value="IV">IV</option>
                            <option value="V">V</option>
                            <option value="VI">VI</option>
                            <option value="VII">VII</option>
                            <option value="VIII">VIII</option>
                            <option value="IX">IX</option>
                            <option value="X">X</option>
                        </select>
                    </div>
                    <div class="fld">
                        <label for="birthdate">Birthdate <span class="star">*</span></label>
                        <input type="date" name="birthdate" id="birthdate" max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($submitted['birthdate'] ?? ''); ?>" required>
                    </div>
                    <div class="fld">
                        <label for="sex">Sex <span class="star">*</span></label>
                        <select name="sex" id="sex" required>
                            <option value="" disabled selected>Select</option>
                            <option value="MALE">Male</option>
                            <option value="FEMALE">Female</option>
                        </select>
                    </div>
                    <div class="fld">
                        <label for="nationality">Nationality</label>
                        <input type="text" name="nationality" id="nationality" data-upper placeholder="FILIPINO" value="<?php echo htmlspecialchars($submitted['nationality'] ?? 'FILIPINO'); ?>">
                    </div>
                </div>
            </div>

            <!-- ══════════ STEP 2: Residency & Contact ══════════ -->
            <div class="step-content" id="step2">

                <div class="sec-head">
                    <h2><i class="fas fa-location-dot"></i> Residency &amp; Contact Details</h2>
                    <p>Specify your residential address within South Cotabato.</p>
                </div>

                <div class="grid">
                    <div class="fld">
                        <label for="contact_number">Mobile Contact Number <span class="star">*</span></label>
                        <input type="tel" name="contact_number" id="contact_number" placeholder="0917XXXXXXX" value="<?php echo htmlspecialchars($submitted['contact_number'] ?? ''); ?>" required>
                    </div>
                    <div class="fld">
                        <label for="email">Email Address <span class="opt">(Optional)</span></label>
                        <input type="email" name="email" id="email" placeholder="juan.delacruz@gmail.com" value="<?php echo htmlspecialchars($submitted['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="grid" style="margin-top:16px;">
                    <div class="fld">
                        <label for="province">Province <span class="star">*</span></label>
                        <select name="province" id="province" disabled>
                            <option value="South Cotabato" selected>South Cotabato</option>
                        </select>
                    </div>
                    <div class="fld">
                        <label for="city">City / Municipality <span class="star">*</span></label>
                        <select name="city" id="city" disabled>
                            <option value="City of Koronadal" selected>City of Koronadal</option>
                        </select>
                    </div>
                </div>

                <div class="grid" style="margin-top:16px;">
                    <div class="fld">
                        <label for="barangay">Barangay <span class="star">*</span></label>
                        <select name="barangay" id="barangay" required>
                            <option value="" disabled selected>Select barangay</option>
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
                        <label for="purok">Purok / Sitio <span class="opt">(Optional)</span></label>
                        <input type="text" name="purok" id="purok" data-upper placeholder="e.g. Purok Magsaysay" value="<?php echo htmlspecialchars($submitted['purok'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- ══════════ STEP 3: Identity Verification ══════════ -->
            <div class="step-content" id="step3">

                <div class="sec-head">
                    <h2><i class="fas fa-camera"></i> Identity Verification</h2>
                    <p>Capture your ID photo and upload a supporting document.</p>
                </div>

                <div class="vgrid">
                    <!-- Citizen Facial Photo -->
                    <div class="vcards">
                        <label class="vhead">1. Citizen Facial Photo</label>
                        <p class="vhint" style="margin-bottom:12px;">Upload a clear photo of your face, or capture one using your camera.</p>
                        <div class="file-drop" id="photoDrop">
                            <input type="file" name="photo_data" id="photo_data" accept="image/*">
                            <div class="drop-icon"><i class="fas fa-camera-retro"></i></div>
                            <div class="drop-label" id="photoFileName">Tap to upload photo</div>
                        </div>
                        <button type="button" id="useCamera" class="cam-btn">
                            <i class="fas fa-video"></i> or use camera
                        </button>
                        <div class="spacer"></div>
                        <p class="cam-note">Used for the participant record.</p>
                    </div>

                    <!-- Supporting Document -->
                    <div class="vcards">
                        <label class="vhead">2. Supporting Residency Document <span class="opt">(Optional)</span></label>
                        <p class="vhint" style="margin-bottom:12px;">Upload a valid ID, Barangay Clearance, or Proof of Residency (PDF, JPG, PNG up to 5MB).</p>
                        <div class="file-drop" id="attachDrop">
                            <input type="file" name="registration_attachment" id="registration_attachment" accept=".pdf,.jpg,.jpeg,.png">
                            <div class="drop-icon"><i class="fas fa-file-arrow-up"></i></div>
                            <div class="drop-label" id="attachFileName">Click to upload document</div>
                            <span class="browse-btn"><i class="fas fa-folder-open"></i> Browse File</span>
                        </div>
                        <div class="file-chip" id="attachChip">
                            <span class="fname"><i class="fas fa-paperclip"></i> <span id="attachChipName"></span></span>
                            <button type="button" id="removeAttach">Remove</button>
                        </div>
                        <div class="spacer"></div>
                    </div>
                </div>
            </div>

            <!-- Wizard Navigation -->
            <div class="wiznav">
                <button type="button" class="ghost" id="prevBtn"><i class="fas fa-arrow-left"></i> Previous</button>
                <button type="submit" class="btn-brand next-btn" id="nextBtn">
                    <span id="nextLabel">Next Step</span>
                    <i class="fas fa-arrow-right" id="nextArrow"></i>
                    <i class="fas fa-spinner fa-spin" id="nextSpinner" style="display:none;"></i>
                </button>
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
        <div class="sub">is now registered &mdash; see you at the draw!</div>
        <a href="register.php" class="ok">Register Another</a>
    </div>
</div>

<!-- Camera modal -->
<div class="overlay" id="cameraOverlay">
    <div class="cam-sheet">
        <h3><i class="fas fa-video" style="color:var(--brand);"></i> Take Profile Photo</h3>
        <div class="cam-view"><video id="camVideo" autoplay playsinline muted></video></div>
        <div class="btn-row">
            <button type="button" id="camCancel" class="btn-secondary">Cancel</button>
            <button type="button" id="camCapture" class="btn-brand">Capture Photo</button>
        </div>
    </div>
</div>

<!-- QR scanner overlay -->
<div class="toast" id="toast"></div>

<canvas id="confetti"></canvas>

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

<script>
(function(){
    var c = document.getElementById('confetti'), ctx = c.getContext('2d');
    var W, H, pieces = [], running = false, frame;
    var colors = ['#c2175b','#e0559b','#f9a8d4','#34d399','#60a5fa','#fbbf24','#a78bfa'];

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

/* ── Uppercase inputs ── */
document.querySelectorAll('input[data-upper]').forEach(function(inp){
    inp.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });
});

/* ── Stepper Logic ── */
var TOTAL_STEPS = 3;
var currentStep = 1;

function el(id){ return document.getElementById(id); }

function goToStep(n) {
    currentStep = n;
    for (var s = 1; s <= TOTAL_STEPS; s++) {
        el('step' + s).classList.toggle('active', s === n);
        var item = el('stepItem' + s);
        item.classList.toggle('active', s === n);
        item.classList.toggle('done', s < n);
        if (s < TOTAL_STEPS) {
            el('stepLine' + s).classList.toggle('done', s < n);
        }
    }
    el('prevBtn').classList.toggle('visible', n > 1);
    el('nextLabel').textContent = n === TOTAL_STEPS ? 'Submit Registration' : 'Next Step';
    el('nextArrow').style.display = n === TOTAL_STEPS ? 'none' : '';
}

function markInvalid(elm) {
    elm.focus();
    elm.style.borderColor = '#ef4444';
    setTimeout(function(){ elm.style.borderColor = ''; }, 2000);
}

function validateStep(n) {
    var checks = [];
    if (n === 1) {
        checks = [
            { el: el('firstname'),  name: 'First Name' },
            { el: el('lastname'),   name: 'Last Name' },
            { el: el('sex'),        name: 'Sex' },
            { el: el('birthdate'),  name: 'Birthdate' }
        ];
    } else if (n === 2) {
        checks = [
            { el: el('contact_number'), name: 'Mobile Contact Number' },
            { el: el('barangay'),       name: 'Barangay' }
        ];
    } else if (n === 3) {
        return true;
    }
    for (var i = 0; i < checks.length; i++) {
        if (!String(checks[i].el.value).trim()) {
            markInvalid(checks[i].el);
            showToast('Please fill in ' + checks[i].name + '.', 'error');
            return false;
        }
    }
    return true;
}

/* Next / Submit via form submit (Enter key + button) */
var allowSubmit = false;
document.getElementById('regForm').addEventListener('submit', function(e){
    if (allowSubmit) return;
    e.preventDefault();
    if (validateStep(currentStep)) {
        if (currentStep < TOTAL_STEPS) {
            goToStep(currentStep + 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            allowSubmit = true;
            el('nextBtn').disabled = true;
            el('nextLabel').textContent = 'Submitting...';
            el('nextSpinner').style.display = '';
            e.target.submit();
        }
    }
});

document.getElementById('prevBtn').addEventListener('click', function() {
    if (currentStep > 1) {
        goToStep(currentStep - 1);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
});

/* ── File Drop Zones ── */
function setupDrop(dropId, inputId, opts) {
    opts = opts || {};
    var drop = el(dropId);
    var input = el(inputId);
    var labelEl = opts.labelEl ? el(opts.labelEl) : null;
    var defaultLabel = labelEl ? labelEl.textContent : '';
    var chip = opts.chipId ? el(opts.chipId) : null;
    var chipName = opts.chipNameId ? el(opts.chipNameId) : null;
    if (!drop || !input) return;

    function apply(file) {
        if (labelEl) labelEl.textContent = file ? file.name : defaultLabel;
        if (chip && chipName) {
            chip.classList.toggle('show', !!file);
            chipName.textContent = file ? file.name : '';
        }
    }

    drop.addEventListener('click', function() {
        input.click();
    });
    drop.addEventListener('dragover', function(e) { e.preventDefault(); drop.classList.add('dragover'); });
    drop.addEventListener('dragleave', function() { drop.classList.remove('dragover'); });
    drop.addEventListener('drop', function(e) {
        e.preventDefault();
        drop.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            apply(input.files[0]);
        }
    });
    input.addEventListener('change', function() {
        apply(input.files.length ? input.files[0] : null);
    });

    if (opts.removeBtnId) {
        el(opts.removeBtnId).addEventListener('click', function() {
            input.value = '';
            apply(null);
        });
    }
}
setupDrop('photoDrop', 'photo_data', { labelEl: 'photoFileName' });
setupDrop('attachDrop', 'registration_attachment', { labelEl: 'attachFileName', chipId: 'attachChip', chipNameId: 'attachChipName', removeBtnId: 'removeAttach' });

/* ── Camera capture ── */
var camStream = null,
    camOverlay = el('cameraOverlay'),
    camVideo = el('camVideo'),
    photoInput = el('photo_data');

function stopCam() {
    if (camStream) {
        camStream.getTracks().forEach(function(t){ t.stop(); });
        camStream = null;
    }
    camOverlay.classList.remove('show');
}

el('useCamera').addEventListener('click', function() {
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

el('camCancel').addEventListener('click', stopCam);

el('camCapture').addEventListener('click', function() {
    var vw = camVideo.videoWidth, vh = camVideo.videoHeight;
    if (!vw || !vh) { showToast('Camera is not ready yet.', 'error'); return; }
    var size = Math.min(vw, vh);
    var canvas = document.createElement('canvas');
    canvas.width = size; canvas.height = size;
    var cx = canvas.getContext('2d');
    cx.translate(size, 0); cx.scale(-1, 1);
    cx.drawImage(camVideo, (vw - size) / 2, (vh - size) / 2, size, size, 0, 0, size, size);
    canvas.toBlob(function(blob) {
        if (!blob) { showToast('Could not process the photo.', 'error'); stopCam(); return; }
        try {
            var dt = new DataTransfer();
            dt.items.add(new File([blob], 'camera-photo.jpg', { type: 'image/jpeg' }));
            photoInput.files = dt.files;
            inputChanged(photoInput);
        } catch (err) {}
        stopCam();
        showToast('Photo captured!');
    }, 'image/jpeg', 0.92);
});
function inputChanged(input){ input.dispatchEvent(new Event('change')); }

<?php if ($error): ?>
goToStep(<?php echo (int)$error_step; ?>);
window.scrollTo({ top: 0 });
<?php endif; ?>

goToStep(1);
</script>

</body>
</html>
