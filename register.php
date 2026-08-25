<?php
require_once __DIR__ . '/config.php';

// Get active event from DB
$ev = $conn->query("SELECT id, name FROM events WHERE status='Active' ORDER BY id ASC LIMIT 1");
$current_event_name = 'Raffle Event';
$current_event_id = 1;
if ($ev && $ev->num_rows > 0) {
    $row = $ev->fetch_assoc();
    $current_event_id = (int)$row['id'];
    $current_event_name = $row['name'];
}

$success = null;
$error = null;
$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $submitted['fullname'] = strtoupper(trim($_POST['fullname'] ?? ''));
    $submitted['purok']    = strtoupper(trim($_POST['purok'] ?? ''));

    $fullname = $submitted['fullname'];
    $purok    = $submitted['purok'];

    if ($fullname === '' || $purok === '') {
        $error = 'Please enter your Full Name and Purok.';
    } else {
        // Next ticket number comes from the database only
        $max_q = $conn->prepare("SELECT MAX(CAST(number AS UNSIGNED)) as max_num FROM participants WHERE event_id = ?");
        $max_q->bind_param("i", $current_event_id);
        $max_q->execute();
        $db_max = (int)($max_q->get_result()->fetch_assoc()['max_num'] ?? 0);
        $max_q->close();
        $number = $db_max + 1;

        $ins = $conn->prepare("INSERT INTO participants (event_id, number, lastname, firstname, middlename, suffix, name, barangay, purok) VALUES (?, ?, '', '', '', '', ?, '', ?)");
        $ins->bind_param("iiss", $current_event_id, $number, $fullname, $purok);
        if ($ins->execute()) {
            $success = ['name' => $fullname, 'purok' => $purok, 'number' => $number];
            $submitted = [];
        } else {
            $error = 'Could not save registration. Please try again.';
        }
        $ins->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registration &mdash; <?php echo htmlspecialchars($current_event_name); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root { --brand:#c2175b; --brand-dark:#a3124c; --brand-soft:rgba(194,23,91,.08); --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; }
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
        font-family:'Inter', system-ui, sans-serif;
        background:#fdf4f8;
        min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:24px 16px;
    }

    .card {
        width:100%; max-width:640px; background:#fff; border-radius:18px;
        border:1px solid var(--line);
        box-shadow:0 16px 44px rgba(194,23,91,.10);
        padding:48px 52px 44px;
    }

    .card-header {
        display:flex; align-items:center; gap:28px;
        padding-bottom:26px; margin-bottom:6px;
        border-bottom:1px solid var(--line);
    }
    .logo { flex-shrink:0; }
    .logo img { display:block; height:auto; width:160px; max-height:140px; object-fit:contain; }

    .headings {
        flex:1; min-width:0;
        padding-left:28px;
        border-left:1px solid var(--line);
    }
    h1 {
        font-size:12.5px; font-weight:700; color:var(--muted);
        letter-spacing:2.6px; text-transform:uppercase;
    }
    .sub { font-size:14px; color:var(--muted); margin-top:12px; line-height:1.5; }
    .sub strong {
        display:block; color:var(--brand); font-weight:800; font-size:36px;
        margin-top:4px; letter-spacing:-.5px; line-height:1.22;
    }

    label {
        display:block; font-size:13px; font-weight:700; color:var(--muted);
        letter-spacing:1.2px; text-transform:uppercase; margin:26px 0 9px;
    }
    input[type="text"] {
        width:100%; padding:17px 18px; font-size:21px; font-weight:500; color:var(--ink);
        border:2px solid var(--line); border-radius:10px; outline:none; background:#fbfcfe;
        transition:border-color .15s ease, box-shadow .15s ease;
    }
    input[type="text"]:focus { border-color:var(--brand); box-shadow:0 0 0 4px var(--brand-soft); background:#fff; }
    input::placeholder { color:#a8b3c2; font-weight:400; font-size:18px; }

    .btn-reg {
        width:100%; margin-top:32px; padding:19px; border:none; border-radius:10px; cursor:pointer;
        background:linear-gradient(135deg, #ec4899, #f472b6); color:#fff; font-family:inherit;
        font-size:17px; font-weight:700; letter-spacing:1.8px; text-transform:uppercase;
        transition:filter .15s ease;
    }
    .btn-reg:hover { filter:brightness(1.07); }

    .err {
        margin-top:18px; padding:13px 16px; border-radius:8px; font-size:15px;
        background:#fef2f2; color:#dc2626; border:1px solid #fecaca; text-align:center;
    }

    .back-btn {
        position:fixed; top:18px; left:18px; z-index:500;
        display:inline-flex; align-items:center; gap:8px;
        padding:9px 18px; border-radius:8px; text-decoration:none;
        background:#fff; border:1px solid var(--line); color:var(--muted);
        font-size:12.5px; font-weight:600;
        transition:border-color .15s ease, color .15s ease;
    }
    .back-btn:hover { border-color:var(--brand); color:var(--brand); }
    .back-btn .arr { font-size:14px; line-height:1; }

    /* Success overlay */
    .overlay {
        position:fixed; inset:0; background:rgba(88,10,38,.88);
        display:none; align-items:center; justify-content:center; z-index:1000; padding:20px;
    }
    .overlay.show { display:flex; }
    .sheet {
        background:#fff; border-radius:14px; padding:42px 40px 38px; text-align:center;
        max-width:400px; width:100%;
        animation:pop .35s cubic-bezier(.34,1.4,.64,1);
    }
    @keyframes pop { from{transform:scale(.85); opacity:0} to{transform:scale(1); opacity:1} }
    .check {
        width:64px; height:64px; margin:0 auto 18px; border-radius:50%;
        background:linear-gradient(135deg, #ec4899, #be185d); color:#fff; font-size:28px;
        display:flex; align-items:center; justify-content:center;
        box-shadow:0 10px 24px rgba(194,23,91,.30);
    }
    .badge { font-size:14px; font-weight:700; color:var(--ink); margin-bottom:18px; }
    .tlabel { font-size:11px; font-weight:600; letter-spacing:1.6px; color:var(--muted); text-transform:uppercase; }
    .tnum { font-size:56px; font-weight:700; line-height:1.25; color:var(--brand); font-variant-numeric:tabular-nums; }
    .name { font-size:16px; font-weight:600; color:var(--ink); margin-top:10px; }
    .purok { font-size:13px; font-weight:500; color:var(--muted); margin-top:3px; }
    .sub2 { color:var(--muted); font-size:13px; margin:14px 0 24px; }
    .ok {
        display:inline-block; width:100%; padding:12px; border-radius:8px; text-decoration:none;
        background:linear-gradient(135deg, #ec4899, #f472b6); color:#fff;
        font-size:13px; font-weight:600; letter-spacing:.8px;
        transition:filter .15s ease;
    }
    .ok:hover { filter:brightness(1.07); }

    @media (max-width:640px){
        .card{ padding:34px 24px 30px; }
        .card-header{
            flex-direction:column; text-align:center; gap:16px;
            padding-bottom:22px;
        }
        .logo img{ width:130px; }
        .headings{ padding-left:0; border-left:none; }
        h1{ font-size:11.5px; letter-spacing:2.2px; }
        .sub strong{ font-size:27px; }
        input[type="text"]{ font-size:18px; padding:15px 16px; }
        input::placeholder{ font-size:15px; }
        .btn-reg{ padding:17px; font-size:15px; }
    }
</style>
</head>
<body>

<a href="index.php" class="back-btn"><span class="arr">&#8592;</span> Back</a>

<div class="card">
    <div class="card-header">
        <div class="logo"><img src="Mayor_Logo.png" alt="Logo"></div>
        <div class="headings">
            <h1>Event Registration</h1>
            <div class="sub">You are registering for <strong><?php echo htmlspecialchars($current_event_name); ?></strong></div>
        </div>
    </div>

    <form method="POST" autocomplete="off" novalidate>
        <input type="hidden" name="register" value="1">

        <label for="fullname">Full Name</label>
        <input type="text" name="fullname" id="fullname" maxlength="150"
               placeholder="Enter your full name"
               value="<?php echo htmlspecialchars($submitted['fullname'] ?? ''); ?>">

        <label for="purok">Purok</label>
        <input type="text" name="purok" id="purok" maxlength="100"
               placeholder="Enter your purok"
               value="<?php echo htmlspecialchars($submitted['purok'] ?? ''); ?>">

        <button type="submit" class="btn-reg">Register</button>

        <?php if ($error): ?>
        <div class="err"><?php echo htmlspecialchars($error); ?></div>
        <script>document.getElementById('fullname').focus();</script>
        <?php endif; ?>
    </form>
</div>

<!-- Success overlay -->
<div class="overlay<?php echo $success ? ' show' : ''; ?>" id="successOverlay">
    <div class="sheet">
        <div class="check">&#10003;</div>
        <div class="badge">Registration Successful!</div>
        <div class="tlabel">Your Ticket No.</div>
        <div class="tnum"><?php echo $success ? (int)$success['number'] : ''; ?></div>
        <div class="name"><?php echo htmlspecialchars($success['name'] ?? ''); ?></div>
        <div class="purok"><?php echo htmlspecialchars($success['purok'] ?? ''); ?></div>
        <div class="sub2">See you at the draw!</div>
        <a href="register.php" class="ok">Register Another</a>
    </div>
</div>

</body>
</html>
