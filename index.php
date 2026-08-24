<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: admin.php');
    exit;
}
require_once 'config.php';

// Participant total comes straight from the database for the ACTIVE event
$total = 0;
$tc = $conn->query("SELECT COUNT(*) cnt FROM participants WHERE event_id = " . get_active_event_id($conn));
if ($tc) $total = (int)$tc->fetch_assoc()['cnt'];

$event_name = 'Charter Anniversary 2025';
$ev = $conn->query("SELECT name FROM events WHERE status='Active' ORDER BY id ASC LIMIT 1");
if ($ev && $ev->num_rows > 0) {
    $event_name = $ev->fetch_assoc()['name'];
}
// Short event name without year for display
$short_event = preg_replace('/\s*\d{4}$/', '', $event_name);

$active_event = 0;
$aec = $conn->query("SELECT COUNT(*) cnt FROM events WHERE status='Active'");
if ($aec) $active_event = $aec->fetch_assoc()['cnt'];

$winner_count = 0;
$wc = $conn->query("SELECT COUNT(DISTINCT number) cnt FROM winners");
if ($wc) $winner_count = $wc->fetch_assoc()['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($event_name); ?> Raffle Draw</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #faf5f7;
            color: #1a1a2e;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .ambient { position:fixed; inset:0; z-index:0; overflow:hidden; pointer-events:none; }
        .ambient .o { position:absolute; border-radius:50%; }
        .ambient .o:nth-child(1) { width:600px; height:600px; background:radial-gradient(circle at 30% 30%, rgba(236,73,153,.1), transparent 70%); top:-200px; right:-160px; }
        .ambient .o:nth-child(2) { width:400px; height:400px; background:radial-gradient(circle at 70% 70%, rgba(244,114,182,.08), transparent 70%); bottom:-120px; left:-80px; }

        .top {
            position:relative; z-index:2;
            display:flex; align-items:center; justify-content:space-between;
            padding:0 32px; height:64px;
            background:rgba(255,255,255,.75); backdrop-filter:blur(16px);
            border-bottom:1px solid rgba(0,0,0,.04);
        }
        .top .brand { display:flex; align-items:center; gap:10px; }
        .top .brand img { height:34px; }
        .top .brand span { font-weight:800; font-size:16px; color:#1a1a2e; letter-spacing:-.3px; }
        .top .nav-links { display:flex; align-items:center; gap:24px; }
        .top .nav-links a {
            font-size:13px; font-weight:600; color:#6b7280;
            text-decoration:none; transition:color .2s;
        }
        .top .nav-links a:hover { color:#ec4899; }
        .top .nav-links .btn-sm {
            padding:8px 20px; border-radius:100px;
            background:linear-gradient(135deg,#ec4899,#f472b6);
            color:#fff; font-weight:700; font-size:12px;
            box-shadow:0 3px 12px rgba(236,73,153,.25);
        }
        .top .nav-links .btn-sm:hover { transform:translateY(-1px); box-shadow:0 5px 18px rgba(236,73,153,.35); }

        .hero {
            position:relative; z-index:1;
            display:flex; flex-direction:column; align-items:center;
            padding:80px 24px 60px; text-align:center;
        }
        .hero .badge {
            display:inline-block; padding:6px 18px; border-radius:100px;
            background:#fdf2f8; border:1px solid #fbcfe8;
            font-size:11px; font-weight:700; color:#ec4899;
            letter-spacing:.5px; margin-bottom:20px;
        }
        .hero h1 {
            font-size:clamp(36px,7vw,64px); font-weight:900;
            letter-spacing:-1.5px; color:#1a1a2e; line-height:1.1;
            margin-bottom:16px;
        }
        .hero h1 span { color:#ec4899; }
        .hero p {
            font-size:17px; color:#6b7280; max-width:560px;
            line-height:1.6; margin-bottom:32px;
        }
        .hero .buttons { display:flex; gap:14px; flex-wrap:wrap; justify-content:center; }
        .hero .btn {
            padding:16px 36px; border-radius:14px;
            font-size:15px; font-weight:700; font-family:inherit;
            text-decoration:none; transition:all .25s ease;
            display:inline-flex; align-items:center; gap:8px;
        }
        .hero .btn-primary {
            background:linear-gradient(135deg,#ec4899,#f472b6);
            color:#fff; box-shadow:0 4px 20px rgba(236,73,153,.3);
        }
        .hero .btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(236,73,153,.4); }
        .hero .btn-outline {
            background:#fff; color:#4a4a6a;
            border:1.5px solid #e5dce0;
        }
        .hero .btn-outline:hover { border-color:#f472b6; color:#ec4899; }

        .stats {
            position:relative; z-index:1;
            display:grid; grid-template-columns:repeat(3,1fr); gap:20px;
            max-width:800px; margin:0 auto 60px; padding:0 24px;
        }
        .stat {
            background:#fff; border-radius:20px; padding:28px 20px;
            text-align:center; border:1px solid rgba(0,0,0,.04);
        }
        .stat .num { font-size:36px; font-weight:900; color:#ec4899; line-height:1; }
        .stat .label { font-size:13px; color:#6b7280; margin-top:6px; font-weight:500; }

        .info {
            position:relative; z-index:1;
            max-width:900px; margin:0 auto 80px; padding:0 24px;
            display:grid; grid-template-columns:1fr 1fr; gap:24px;
        }
        .info-card {
            background:#fff; border-radius:20px; padding:32px;
            border:1px solid rgba(0,0,0,.04);
        }
        .info-card .icon { font-size:28px; margin-bottom:12px; }
        .info-card h3 { font-size:17px; font-weight:800; color:#1a1a2e; margin-bottom:8px; }
        .info-card p { font-size:14px; color:#6b7280; line-height:1.6; }

        .ftr {
            position:relative; z-index:1;
            text-align:center; padding:20px 24px 40px;
            font-size:12px; color:#c4b5c0; letter-spacing:.3px;
        }

        @media (max-width:640px) {
            .hero { padding-top:48px; }
            .stats { grid-template-columns:1fr; max-width:360px; }
            .info { grid-template-columns:1fr; }
            .top .nav-links .btn-sm-text { display:none; }
        }
    </style>
</head>
<body>
<div class="ambient"><div class="o"></div><div class="o"></div></div>

<div class="top">
    <div class="brand">
        <img src="Logo.png" alt="">
        <span>Raffle System</span>
    </div>
    <div class="nav-links">
        <a href="#info">About</a>
        <a href="register.php" class="btn-sm">Register Now</a>
    </div>
</div>

<section class="hero">
    <div class="badge"><?php echo htmlspecialchars($event_name); ?></div>
    <h1>Koronadal <span>Raffle</span> Draw</h1>
    <p>Register your ticket for a chance to win exciting prizes at <?php echo htmlspecialchars($short_event); ?>.</p>
    <div class="buttons">
        <a href="register.php" class="btn btn-primary">Register Your Ticket</a>
        <a href="login.php" class="btn btn-outline">Admin Login</a>
    </div>
</section>

<div class="stats">
    <div class="stat">
        <div class="num"><?php echo $total; ?></div>
        <div class="label">Total Registrations</div>
    </div>
    <div class="stat">
        <div class="num">1</div>
        <div class="label">Active Event</div>
    </div>
    <div class="stat">
        <div class="num"><?php echo $winner_count; ?></div>
        <div class="label">Winners Drawn</div>
    </div>
</div>

<div class="info" id="info">
    <div class="info-card">
        <div class="icon">🎫</div>
        <h3>How to Join</h3>
        <p>Register your details online and receive a unique ticket number. Bring this number to the event for verification.</p>
    </div>
    <div class="info-card">
        <div class="icon">🏆</div>
        <h3>The Draw</h3>
        <p>Winners are selected randomly during <?php echo htmlspecialchars($short_event); ?>. Each ticket number has an equal chance to win.</p>
    </div>
    <div class="info-card">
        <div class="icon">📍</div>
        <h3>Eligibility</h3>
        <p>Open to all residents of Koronadal City. Must present valid ID and ticket number to claim prizes.</p>
    </div>
    <div class="info-card">
        <div class="icon">📅</div>
        <h3>Event Date</h3>
        <p>The raffle draw will take place during <?php echo htmlspecialchars($short_event); ?>.</p>
    </div>
</div>

<div class="ftr">City Government of Koronadal &mdash; Raffle Draw System</div>

</body>
</html>