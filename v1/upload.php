<?php
require_once 'config.php';

// Check if functions are loaded
if (!function_exists('processExcelFile')) {
    die('Error: Configuration file not loaded properly. Please check config.php file.');
}

$message = '';
$error = '';
$uploadResult = null;

// Handle file upload
if (isset($_POST['upload'])) {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = $uploadDir . basename($_FILES['excel_file']['name']);
        
        if (move_uploaded_file($_FILES['excel_file']['tmp_name'], $fileName)) {
            $uploadResult = processExcelFile($fileName);
            
            if ($uploadResult['success']) {
                $message = "File processed successfully!";
            } else {
                $error = "Error processing file: " . $uploadResult['error'];
            }
            unlink($fileName); // Delete uploaded file after processing
        } else {
            $error = "Error uploading file.";
        }
    } else {
        $error = "Please select a valid Excel file.";
    }
}

// Handle delete all participants
if (isset($_POST['delete_all'])) {
    if (deleteAllParticipants()) {
        $message = "All participants deleted successfully!";
        $uploadResult = null;
    } else {
        $error = "Error deleting participants.";
    }
}

// Get current statistics
$totalCount = getParticipantCount();
$selectedCount = getSelectedCount();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Participants - Raffle System</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Arial', sans-serif;
        background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
        background-attachment: fixed;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        position: relative;
        overflow-x: hidden;
    }

    /* Animated background particles */
    .particles {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 1;
    }

    .particle {
        position: absolute;
        width: 4px;
        height: 4px;
        background: rgba(255, 235, 59, 0.8);
        border-radius: 50%;
        animation: float 6s infinite ease-in-out;
    }

    @keyframes float {

        0%,
        100% {
            transform: translateY(0px) rotate(0deg);
            opacity: 0.5;
        }

        50% {
            transform: translateY(-20px) rotate(180deg);
            opacity: 1;
        }
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        position: relative;
        z-index: 10;
    }

    .header {
        text-align: center;
        margin-bottom: 40px;
        padding: 20px;
        background: rgba(255, 235, 59, 0.15);
        border-radius: 20px;
        backdrop-filter: blur(10px);
        border: 2px solid rgba(255, 235, 59, 0.3);
        animation: slideDown 0.8s ease-out;
    }

    @keyframes slideDown {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .header h1 {
        color: #ffeb3b;
        font-size: 3em;
        margin-bottom: 10px;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        animation: glow 2s infinite alternate;
    }

    @keyframes glow {
        from {
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5), 0 0 10px rgba(255, 235, 59, 0.3);
        }

        to {
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5), 0 0 20px rgba(255, 235, 59, 0.6), 0 0 30px rgba(255, 235, 59, 0.4);
        }
    }

    .header p {
        color: rgba(255, 235, 59, 0.9);
        font-size: 1.2em;
    }

    .navigation {
        text-align: center;
        margin-bottom: 30px;
    }

    .nav-btn {
        background: linear-gradient(45deg, #d32f2f, #ff1744);
        color: white;
        border: none;
        padding: 15px 30px;
        border-radius: 50px;
        cursor: pointer;
        font-size: 16px;
        font-weight: bold;
        text-decoration: none;
        display: inline-block;
        margin: 0 10px;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
        position: relative;
        overflow: hidden;
    }

    .nav-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(211, 47, 47, 0.4);
    }

    .nav-btn.active {
        background: linear-gradient(45deg, #ffeb3b, #ffc107);
        color: #333;
    }

    .main-content {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }

    .card {
        background: rgba(255, 235, 59, 0.1);
        border-radius: 20px;
        padding: 30px;
        backdrop-filter: blur(10px);
        border: 2px solid rgba(255, 235, 59, 0.2);
        transition: all 0.3s ease;
        animation: fadeIn 1s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        background: rgba(255, 235, 59, 0.15);
        border-color: rgba(255, 235, 59, 0.4);
    }

    .card h2 {
        color: #ffeb3b;
        margin-bottom: 20px;
        font-size: 1.8em;
        text-align: center;
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        color: #ffeb3b;
        margin-bottom: 8px;
        font-weight: bold;
    }

    .form-group input[type="file"] {
        width: 100%;
        padding: 15px;
        border: 2px solid rgba(255, 235, 59, 0.3);
        border-radius: 10px;
        background: rgba(255, 235, 59, 0.1);
        color: white;
        font-size: 16px;
        transition: all 0.3s ease;
    }

    .form-group input:focus {
        outline: none;
        background: rgba(255, 235, 59, 0.2);
        border-color: rgba(255, 235, 59, 0.6);
        transform: scale(1.02);
    }

    .btn {
        background: linear-gradient(45d, #d32f2f, #ff1744);
        color: white;
        border: none;
        padding: 15px 30px;
        border-radius: 50px;
        cursor: pointer;
        font-size: 16px;
        font-weight: bold;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
        position: relative;
        overflow: hidden;
        width: 100%;
        margin-bottom: 10px;
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(211, 47, 47, 0.4);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn.danger {
        background: linear-gradient(45deg, #c62828, #d32f2f);
    }

    .btn.danger:hover {
        box-shadow: 0 8px 25px rgba(198, 40, 40, 0.4);
    }

    .btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s;
    }

    .btn:hover::before {
        left: 100%;
    }

    .stats-display {
        background: rgba(255, 235, 59, 0.1);
        border-radius: 20px;
        padding: 30px;
        backdrop-filter: blur(10px);
        border: 2px solid rgba(255, 235, 59, 0.2);
        text-align: center;
        animation: bounceIn 0.8s ease-out;
    }

    @keyframes bounceIn {
        0% {
            opacity: 0;
            transform: scale(0.3);
        }

        50% {
            opacity: 1;
            transform: scale(1.05);
        }

        70% {
            transform: scale(0.9);
        }

        100% {
            opacity: 1;
            transform: scale(1);
        }
    }

    .stats-display h2 {
        color: #ffeb3b;
        font-size: 2.5em;
        margin-bottom: 20px;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
    }

    .stat-item {
        background: rgba(255, 235, 59, 0.1);
        border-radius: 15px;
        padding: 20px;
        margin: 15px 0;
        border: 1px solid rgba(255, 235, 59, 0.3);
    }

    .stat-item .stat-number {
        font-size: 2.5em;
        font-weight: bold;
        color: #d32f2f;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        animation: pulse 2s infinite;
    }

    .stat-item .stat-label {
        color: #ffeb3b;
        font-size: 1.2em;
        margin-top: 10px;
    }

    @keyframes pulse {
        0% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.05);
        }

        100% {
            transform: scale(1);
        }
    }

    .alert {
        padding: 15px;
        border-radius: 10px;
        margin: 20px 0;
        animation: slideIn 0.5s ease-out;
    }

    @keyframes slideIn {
        from {
            transform: translateX(-20px);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .alert-success {
        background: rgba(76, 175, 80, 0.2);
        border: 2px solid rgba(76, 175, 80, 0.5);
        color: #4CAF50;
    }

    .alert-error {
        background: rgba(244, 67, 54, 0.2);
        border: 2px solid rgba(244, 67, 54, 0.5);
        color: #f44336;
    }

    .result-summary {
        background: rgba(255, 235, 59, 0.1);
        border-radius: 15px;
        padding: 20px;
        margin: 20px 0;
        border: 2px solid rgba(255, 235, 59, 0.3);
    }

    .result-summary h3 {
        color: #ffeb3b;
        margin-bottom: 15px;
        text-align: center;
    }

    .result-item {
        display: flex;
        justify-content: space-between;
        color: white;
        margin: 8px 0;
        padding: 8px;
        background: rgba(255, 235, 59, 0.05);
        border-radius: 8px;
    }

    .result-item .label {
        color: #ffeb3b;
    }

    .result-item .value {
        color: #d32f2f;
        font-weight: bold;
    }

    @media (max-width: 768px) {
        .main-content {
            grid-template-columns: 1fr;
        }

        .header h1 {
            font-size: 2em;
        }

        .container {
            padding: 10px;
        }

        .nav-btn {
            display: block;
            margin: 5px 0;
        }
    }
    </style>
</head>

<body>
    <!-- Animated background particles -->
    <div class="particles"></div>

    <div class="container">
        <div class="header">
            <h1>📁 Upload Participants</h1>
            <p>Upload your CSV file with participant data</p>
        </div>

        <!-- Navigation -->
        <div class="navigation">
            <a href="upload.php" class="nav-btn active">📁 Upload</a>
            <a href="draw.php" class="nav-btn">🎯 Draw Winner</a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="main-content">
            <!-- Upload Section -->
            <div class="card">
                <h2>📁 Upload CSV File</h2>
                <p style="color: rgba(255, 235, 59, 0.8); margin-bottom: 20px; text-align: center;">
                    Convert your Excel file to CSV format before uploading
                </p>
                <form method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="excel_file">Select CSV File (.csv):</label>
                        <input type="file" id="excel_file" name="excel_file" accept=".csv" required>
                        <p style="color: rgba(255, 235, 59, 0.8); font-size: 14px; margin-top: 8px;">
                            💡 <strong>Note:</strong> Please save your Excel file as CSV format. In Excel: File → Save
                            As → CSV (Comma delimited)
                        </p>
                    </div>
                    <button type="submit" name="upload" class="btn">📤 Upload & Process CSV</button>
                </form>

                <?php if ($totalCount > 0): ?>
                <form method="post"
                    onsubmit="return confirm('Are you sure you want to delete ALL participants? This action cannot be undone!');">
                    <button type="submit" name="delete_all" class="btn danger">🗑️ Delete All & Start Over</button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Statistics -->
            <div class="card">
                <h2>📊 Current Statistics</h2>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $totalCount; ?></div>
                    <div class="stat-label">Total Participants</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $selectedCount; ?></div>
                    <div class="stat-label">Already Selected</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo ($totalCount - $selectedCount); ?></div>
                    <div class="stat-label">Available for Draw</div>
                </div>
            </div>
        </div>

        <!-- Upload Results -->
        <?php if ($uploadResult && $uploadResult['success']): ?>
        <div class="result-summary">
            <h3>📋 Upload Results</h3>
            <div class="result-item">
                <span class="label">New Records Added:</span>
                <span class="value"><?php echo $uploadResult['new_records']; ?></span>
            </div>
            <div class="result-item">
                <span class="label">Duplicates Skipped:</span>
                <span class="value"><?php echo $uploadResult['duplicates']; ?></span>
            </div>
            <div class="result-item">
                <span class="label">Errors:</span>
                <span class="value"><?php echo $uploadResult['errors']; ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // Create animated particles
    function createParticles() {
        const particles = document.querySelector('.particles');
        const particleCount = 50;

        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            particle.style.left = Math.random() * 100 + '%';
            particle.style.top = Math.random() * 100 + '%';
            particle.style.animationDelay = Math.random() * 6 + 's';
            particle.style.animationDuration = (Math.random() * 3 + 3) + 's';
            particles.appendChild(particle);
        }
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        createParticles();

        // Add pulse effect to buttons
        const buttons = document.querySelectorAll('.btn');
        buttons.forEach(button => {
            button.addEventListener('mouseenter', function() {
                this.style.animation = 'pulse 0.5s';
            });

            button.addEventListener('animationend', function() {
                this.style.animation = '';
            });
        });
    });

    // Form validation
    document.querySelector('form[enctype="multipart/form-data"]').addEventListener('submit', function(e) {
        const fileInput = document.getElementById('excel_file');
        if (!fileInput.files.length) {
            e.preventDefault();
            alert('Please select a CSV file.');
            return false;
        }

        // Check file extension
        const fileName = fileInput.files[0].name;
        const fileExt = fileName.split('.').pop().toLowerCase();

        if (fileExt !== 'csv') {
            e.preventDefault();
            alert('Please select a valid CSV file (.csv). Save your Excel file as CSV first.');
            return false;
        }
    });
    </script>
</body>

</html>