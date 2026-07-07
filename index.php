<?php
require_once 'config.php';
require_once 'auth_check.php';

// Get current page
$page = isset($_GET['page']) ? $_GET['page'] : 'events';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raffle System - <?php echo htmlspecialchars($current_event_name); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .toast-container { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); z-index:99999; display:flex; flex-direction:column-reverse; gap:10px; pointer-events:none; align-items:center; }
        .toast { pointer-events:auto; padding:14px 22px; border-radius:12px; font-size:14px; font-weight:600; color:#fff; box-shadow:0 8px 30px rgba(0,0,0,0.12); animation:toastIn .4s cubic-bezier(.34,1.56,.64,1); max-width:380px; display:flex; align-items:center; gap:10px; }
        .toast-success { background:linear-gradient(135deg,#10b981,#059669); }
        .toast-error { background:linear-gradient(135deg,#ef4444,#dc2626); }
        .toast-info { background:linear-gradient(135deg,#ec4899,#f472b6); }
        @keyframes toastIn { 0%{opacity:0;transform:translateX(100%) scale(.8)} 100%{opacity:1;transform:translateX(0) scale(1)} }
        .toast-out { animation:toastOut .3s ease forwards; }
        @keyframes toastOut { 0%{opacity:1;transform:translateX(0)} 100%{opacity:0;transform:translateX(100%)} }
        .event-badge {
            background: #fdf2f8;
            color: #ec4899;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            border: 1px solid #fbcfe8;
            margin-left: 10px;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-left: 1px solid rgba(0,0,0,0.06);
        }
        .user-info span {
            font-size: 13px;
            color: #4a4a6a;
            font-weight: 600;
        }
        .user-info a {
            font-size: 12px;
            color: #9ca3af;
            text-decoration: none;
            font-weight: 600;
        }
        .user-info a:hover {
            color: #ec4899;
        }
    </style>
    <?php if ($page === 'draw' && file_exists('uploads/bg/custom_bg.jpg')): ?>
    <style>
        .container1::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 520px;
            height: 100%;
            background-image: url('uploads/bg/custom_bg.jpg?v=<?php echo filemtime('uploads/bg/custom_bg.jpg'); ?>');
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            z-index: 0;
        }
    </style>
    <?php endif; ?>
</head>

<body>

    <!-- Header with Logo + Navigation -->
    <div class="header">
        <img src="Logo.png" alt="Logo" class="logo"><span class="systemName" style="margin-left:5px">
            Raffle
            System</span>
        <span class="event-badge"><?php echo htmlspecialchars($current_event_name); ?></span>

        <nav class="nav">
            <a href="index.php?page=events" class="<?php echo $page === 'events' ? 'active' : ''; ?>">Events</a>
            <a href="index.php?page=upload" class="<?php echo $page === 'upload' ? 'active' : ''; ?>">Home</a>
            <a href="index.php?page=draw" class="<?php echo $page === 'draw' ? 'active' : ''; ?>">Draw</a>
            <a href="index.php?page=prize" class="<?php echo $page === 'prize' ? 'active' : ''; ?>">Prize</a>
            <a href="index.php?page=winners" class="<?php echo $page === 'winners' ? 'active' : ''; ?>">Winners</a>
        </nav>

        <div class="user-info">
            <span><?php echo htmlspecialchars($_SESSION['display_name']); ?></span>
            <a href="logout.php">Logout</a>
        </div>
    </div>


    <!-- Main Content -->
    <div class="container">
        <div class="content <?php echo $page === 'draw' ? 'fullscreen' : ''; ?>">
            <?php
            // Include appropriate page
            switch($page) {
                case 'events':
                    include 'events.php';
                    break;
                case 'upload':
                    include 'upload.php';
                    break;
                case 'draw':
                    include 'draw.php';
                    break;
                case 'prize':
                    include 'prize.php';
                    break;
                case 'winners':
                    include 'winners.php';
                    break;
                default:
                    include 'events.php';
            }
            ?>
        </div>
    </div>

    <canvas id="confetti-canvas"></canvas>
    <script src="confetti.js"></script>
    <div class="toast-container" id="toastContainer"></div>
    <script>
    function showToast(message, type) {
        type = type || 'info';
        var container = document.getElementById('toastContainer');
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        var icons = { success: '✓', error: '✕', info: 'ℹ' };
        toast.innerHTML = '<span style="font-size:18px;font-weight:900;line-height:1">' + (icons[type] || 'ℹ') + '</span> ' + message;
        container.appendChild(toast);
        setTimeout(function() {
            toast.classList.add('toast-out');
            setTimeout(function() { toast.remove(); }, 300);
        }, 4000);
    }
    </script>
</body>

</html>