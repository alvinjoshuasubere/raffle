<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

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
                header('Location: index.php');
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
    <title>Login - Raffle System</title>
    <link rel="stylesheet" href="style.css">
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
            background: #faf5f7;
            position: relative;
            overflow: hidden;
        }

        /* Animated gradient background */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse at 20% 50%, rgba(236, 73, 153, 0.08) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(244, 114, 182, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 80%, rgba(249, 168, 212, 0.05) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(236, 73, 153, 0.04) 0%, transparent 50%);
            z-index: 0;
        }

        /* Floating particles */
        .particle {
            position: fixed;
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            opacity: 0.3;
        }

        .particle:nth-child(1) {
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(236, 73, 153, 0.08), transparent);
            top: -100px;
            left: -100px;
            animation: float1 8s ease-in-out infinite;
        }

        .particle:nth-child(2) {
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(244, 114, 182, 0.06), transparent);
            bottom: -50px;
            right: -50px;
            animation: float2 10s ease-in-out infinite;
        }

        .particle:nth-child(3) {
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.05), transparent);
            top: 40%;
            right: 10%;
            animation: float3 12s ease-in-out infinite;
        }

        .particle:nth-child(4) {
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(236, 73, 153, 0.05), transparent);
            bottom: 20%;
            left: 5%;
            animation: float1 9s ease-in-out infinite reverse;
        }

        @keyframes float1 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -30px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
        }

        @keyframes float2 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(-40px, -20px) scale(1.15); }
        }

        @keyframes float3 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(20px, 30px) scale(1.05); }
        }

        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            padding: 20px;
            animation: fadeIn 0.8s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-card {
            background: #ffffff;
            border-radius: 28px;
            padding: 50px 44px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow:
                0 24px 80px rgba(0, 0, 0, 0.05),
                0 4px 20px rgba(0, 0, 0, 0.02);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(244, 114, 182, 0.5), rgba(236, 73, 153, 0.7), rgba(244, 114, 182, 0.5), transparent);
            animation: shimmer 3s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { opacity: 0.4; }
            50% { opacity: 0.8; }
        }

        .login-card .logo {
            max-height: 72px;
            margin-bottom: 6px;
        }

        .login-card h1 {
            font-size: 26px;
            font-weight: 800;
            color: #1a1a2e;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }

        .login-card .subtitle {
            color: #9ca3af;
            font-size: 14px;
            font-weight: 400;
            margin-bottom: 36px;
        }

        .input-group {
            position: relative;
            margin-bottom: 16px;
            text-align: left;
        }

        .input-group label {
            display: block;
            color: #6b7280;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin-bottom: 8px;
        }

        .input-group .input-wrap {
            position: relative;
        }

        .input-group .input-wrap .icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #c4b5c0;
            font-size: 18px;
            pointer-events: none;
            transition: color 0.3s;
        }

        .input-group input:focus ~ .icon {
            color: #f472b6;
        }

        .input-group input {
            width: 100%;
            padding: 16px 18px 16px 48px;
            border: 2px solid #f0eef0;
            border-radius: 14px;
            font-size: 15px;
            font-family: inherit;
            font-weight: 500;
            background: #fafafa;
            color: #1a1a2e;
            transition: all 0.3s ease;
            outline: none;
        }

        .input-group input::placeholder {
            color: #c4b5c0;
            font-weight: 400;
        }

        .input-group input:hover {
            border-color: #e5dce0;
            background: #fff;
        }

        .input-group input:focus {
            border-color: #f472b6;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(244, 114, 182, 0.1);
        }

        .input-group input:-webkit-autofill {
            -webkit-box-shadow: 0 0 0 1000px #fafafa inset !important;
            -webkit-text-fill-color: #1a1a2e !important;
            border-color: #f0eef0;
        }

        .login-card .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            margin-top: 8px;
            background: linear-gradient(135deg, #ec4899, #f472b6);
            color: #fff;
            letter-spacing: 0.3px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 4px 24px rgba(236, 73, 153, 0.2);
        }

        .login-card .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(236, 73, 153, 0.35);
        }

        .login-card .btn:active {
            transform: translateY(0);
        }

        .login-card .btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
        }

        .login-card .btn:hover::after {
            transform: translateX(100%);
        }

        .login-card .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .error-msg {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-6px); }
            40% { transform: translateX(6px); }
            60% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
        }

        .login-footer {
            margin-top: 24px;
            color: #d1d5db;
            font-size: 12px;
            letter-spacing: 0.5px;
        }

        .login-footer a {
            color: #f472b6;
            text-decoration: none;
            transition: color 0.3s;
        }

        .login-footer a:hover {
            color: #ec4899;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 40px 28px;
                border-radius: 20px;
            }
            .login-card h1 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>

    <div class="login-wrapper">
        <div class="login-card">
            <img src="Logo.png" alt="Logo" class="logo">
            <h1>Raffle System</h1>
            <p class="subtitle">Sign in to manage your raffle events</p>
            <?php if (isset($error)): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" autocomplete="off">
                <div class="input-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <span class="icon">&#9993;</span>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                    </div>
                </div>
                <div class="input-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <span class="icon">&#128274;</span>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    </div>
                </div>
                <button type="submit" name="login" class="btn">Sign In</button>
            </form>
            <div class="login-footer">Raffle System &mdash; Raffle Draw Management</div>
        </div>
    </div>
</body>
</html>
