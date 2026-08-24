<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: admin.php');
    exit;
}

require_once 'config.php';

// Get active event name
$event_name = 'Raffle Event';
$ev = $conn->query("SELECT name FROM events WHERE status='Active' ORDER BY id ASC LIMIT 1");
if ($ev && $ev->num_rows > 0) {
    $event_name = $ev->fetch_assoc()['name'];
}

if (isset($_POST['login'])) {
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, display_name FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['display_name'] = $user['display_name'];
                header('Location: admin.php');
                exit;
            }
        }
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($event_name); ?> Raffle System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(900px at 85% -10%, rgba(236, 73, 153, 0.10), transparent 60%),
                radial-gradient(800px at -10% 110%, rgba(139, 92, 246, 0.10), transparent 60%),
                #f6f7fb;
            padding: 24px;
            color: #1e293b;
        }

        .shell {
            width: 100%;
            max-width: 920px;
            min-height: 560px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: #ffffff;
            border-radius: 28px;
            overflow: hidden;
            box-shadow:
                0 30px 90px rgba(15, 23, 42, 0.10),
                0 4px 18px rgba(15, 23, 42, 0.05);
            animation: riseIn 0.7s cubic-bezier(0.22, 1, 0.36, 1);
        }

        @keyframes riseIn {
            from { opacity: 0; transform: translateY(26px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Brand panel ── */
        .brand {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 46px 44px;
            background: linear-gradient(150deg, #ec4899 0%, #d946ef 55%, #8b5cf6 100%);
            color: #fff;
            overflow: hidden;
        }

        .brand::before,
        .brand::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .brand::before {
            width: 340px;
            height: 340px;
            border: 1.5px solid rgba(255, 255, 255, 0.18);
            top: -130px;
            right: -120px;
        }

        .brand::after {
            width: 260px;
            height: 260px;
            background: rgba(255, 255, 255, 0.08);
            bottom: -110px;
            left: -80px;
        }

        .brand-logo {
            width: 76px;
            height: 76px;
            object-fit: contain;
            background: rgba(255, 255, 255, 0.92);
            border-radius: 20px;
            padding: 10px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
        }

        .brand-main {
            position: relative;
            z-index: 1;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(6px);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        .brand h2 {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: -0.8px;
            line-height: 1.15;
            margin-bottom: 12px;
        }

        .brand p {
            font-size: 14.5px;
            line-height: 1.65;
            color: rgba(255, 255, 255, 0.82);
            max-width: 300px;
        }

        .brand-points {
            position: relative;
            z-index: 1;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 13px;
        }

        .brand-points li {
            display: flex;
            align-items: center;
            gap: 11px;
            font-size: 13.5px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.94);
        }

        .brand-points .dot {
            width: 22px;
            height: 22px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.18);
        }

        /* ── Form panel ── */
        .form-side {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 52px 54px;
        }

        .form-head h1 {
            font-size: 27px;
            font-weight: 800;
            letter-spacing: -0.6px;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .form-head p {
            font-size: 14.5px;
            color: #94a3b8;
            margin-bottom: 34px;
        }

        .error-msg {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 13px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 22px;
            animation: shake 0.45s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-7px); }
            40% { transform: translateX(7px); }
            60% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
        }

        .field {
            margin-bottom: 18px;
        }

        .field label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }

        .control {
            position: relative;
            display: flex;
            align-items: center;
        }

        .control > svg.lead {
            position: absolute;
            left: 16px;
            width: 19px;
            height: 19px;
            color: #cbd5e1;
            pointer-events: none;
            transition: color 0.25s ease;
        }

        .control:focus-within > svg.lead {
            color: #ec4899;
        }

        .control input {
            width: 100%;
            padding: 14.5px 48px;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            font-size: 15px;
            font-family: inherit;
            font-weight: 500;
            background: #f8fafc;
            color: #0f172a;
            outline: none;
            transition: border-color 0.25s ease, background 0.25s ease, box-shadow 0.25s ease;
        }

        .control input::placeholder {
            color: #b6c2d2;
            font-weight: 400;
        }

        .control input:hover {
            border-color: #cbd5e1;
        }

        .control input:focus {
            border-color: #ec4899;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(236, 73, 153, 0.10);
        }

        .control input:-webkit-autofill {
            -webkit-box-shadow: 0 0 0 1000px #ffffff inset !important;
            -webkit-text-fill-color: #0f172a !important;
            caret-color: #0f172a;
        }

        .toggle-pass {
            position: absolute;
            right: 8px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            color: #b6c2d2;
            transition: color 0.2s ease, background 0.2s ease;
        }

        .toggle-pass:hover {
            color: #ec4899;
            background: #fdf2f8;
        }

        .btn-login {
            width: 100%;
            margin-top: 10px;
            padding: 15.5px;
            border: none;
            border-radius: 14px;
            font-size: 15.5px;
            font-weight: 700;
            font-family: inherit;
            letter-spacing: 0.3px;
            cursor: pointer;
            color: #fff;
            background: linear-gradient(135deg, #ec4899, #8b5cf6);
            box-shadow: 0 10px 28px rgba(236, 73, 153, 0.30);
            position: relative;
            overflow: hidden;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 36px rgba(236, 73, 153, 0.42);
        }

        .btn-login:active { transform: translateY(0); }

        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 24px 0 16px;
            color: #cbd5e1;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e8edf4;
        }

        .btn-register {
            display: block;
            padding: 13.5px;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            background: #ffffff;
            color: #475569;
            text-decoration: none;
            text-align: center;
            transition: all 0.25s ease;
        }

        .btn-register:hover {
            border-color: rgba(236, 73, 153, 0.55);
            background: #fdf2f8;
            color: #db2777;
        }

        .login-footer {
            margin-top: 28px;
            text-align: center;
            color: #b6c2d2;
            font-size: 12px;
            letter-spacing: 0.4px;
        }

        @media (max-width: 820px) {
            .shell {
                grid-template-columns: 1fr;
                max-width: 460px;
                min-height: 0;
            }
            .brand {
                padding: 30px 32px;
                gap: 22px;
            }
            .brand h2 { font-size: 24px; }
            .brand p { display: none; }
            .brand-points { display: none; }
            .brand-logo { width: 58px; height: 58px; border-radius: 16px; padding: 8px; }
            .form-side { padding: 36px 32px 40px; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <aside class="brand">
            <img src="Logo.png" alt="Logo" class="brand-logo">
            <div class="brand-main">
                <span class="brand-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><path d="M12 2l2.4 7.2H22l-6 4.6 2.3 7.2-6.3-4.5L5.7 21 8 13.8 2 9.2h7.6z"/></svg>
                    Live Raffle
                </span>
                <h2><?php echo htmlspecialchars($event_name); ?></h2>
                <p>Draw winners instantly, track prizes live, and keep every ticket accounted for.</p>
            </div>
            <ul class="brand-points">
                <li>
                    <span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Instant winner draws
                </li>
                <li>
                    <span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Live prize tracking
                </li>
                <li>
                    <span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Complete participant records
                </li>
            </ul>
        </aside>

        <main class="form-side">
            <div class="form-head">
                <h1>Welcome back</h1>
                <p>Sign in to manage the raffle event.</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="error-msg">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="field">
                    <label for="username">Username</label>
                    <div class="control">
                        <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="control">
                        <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                        <button type="button" class="toggle-pass" id="togglePass" aria-label="Show password">
                            <svg id="eyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="19" height="19"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg id="eyeClosed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="19" height="19" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" name="login" class="btn-login">Sign In</button>

                <div class="divider">or</div>

                <a href="register.php" class="btn-register">Register as Participant</a>
            </form>

        </main>
    </div>

    <script>
        const passInput = document.getElementById('password');
        const toggleBtn = document.getElementById('togglePass');
        const eyeOpen = document.getElementById('eyeOpen');
        const eyeClosed = document.getElementById('eyeClosed');

        toggleBtn.addEventListener('click', function() {
            const show = passInput.type === 'password';
            passInput.type = show ? 'text' : 'password';
            eyeOpen.style.display = show ? 'none' : '';
            eyeClosed.style.display = show ? '' : 'none';
            passInput.focus();
        });
    </script>
</body>
</html>
