<?php
$reason = $_GET['reason'] ?? 'ended';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Closed — Raffle System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            min-height:100vh; display:flex; align-items:center; justify-content:center;
            background:#faf5f7; color:#1a1a2e; padding:20px;
        }
        .card {
            background:#fff; border-radius:24px; padding:56px 44px 44px;
            max-width:420px; width:100%; text-align:center;
            box-shadow:0 20px 60px rgba(0,0,0,.06), 0 0 0 1px rgba(0,0,0,.02);
            animation:fadeIn .5s ease;
        }
        @keyframes fadeIn {
            from { opacity:0; transform:translateY(16px); }
            to { opacity:1; transform:translateY(0); }
        }
        .icon { font-size:52px; margin-bottom:16px; }
        h1 { font-size:22px; font-weight:800; margin-bottom:8px; letter-spacing:-.3px; }
        p { color:#6b7280; font-size:14px; line-height:1.6; margin-bottom:28px; }
        .btn {
            display:inline-block; padding:14px 36px; border-radius:100px;
            background:linear-gradient(135deg, #ec4899, #f472b6);
            color:#fff; text-decoration:none; font-size:14px; font-weight:600;
            font-family:inherit; border:none; cursor:pointer;
            transition:all .3s ease; box-shadow:0 4px 16px rgba(236,73,153,.2);
        }
        .btn:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(236,73,153,.35); }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($reason === 'not_started'): ?>
        <div class="icon">⏰</div>
        <h1>Registration Not Yet Open</h1>
        <p>Registration for this event hasn't started yet. Please check back when the registration period begins.</p>
        <?php else: ?>
        <div class="icon">🔒</div>
        <h1>Registration Closed</h1>
        <p>The registration period for this event has ended. Stay tuned for future events!</p>
        <?php endif; ?>
        <a href="index.php" class="btn">Back to Home</a>
    </div>
</body>
</html>
