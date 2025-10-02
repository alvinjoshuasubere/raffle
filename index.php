<?php
require_once 'config.php';

// Get current page
$page = isset($_GET['page']) ? $_GET['page'] : 'upload';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raffle System</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

    <!-- Header with Logo + Navigation -->
    <div class="header">
        <img src="Logo.png" alt="Logo" class="logo"><span class="systemName" style="margin-left:5px">Charter Anniversary
            Raffle
            System</span>

        <nav class="nav">
            <a href="index.php?page=upload" class="<?php echo $page === 'upload' ? 'active' : ''; ?>">Home</a>
            <a href="index.php?page=draw" class="<?php echo $page === 'draw' ? 'active' : ''; ?>">Draw</a>
            <a href="index.php?page=prize" class="<?php echo $page === 'prize' ? 'active' : ''; ?>">Prize</a>
            <a href="index.php?page=winners" class="<?php echo $page === 'winners' ? 'active' : ''; ?>">Winners</a>
        </nav>
    </div>


    <!-- Main Content -->
    <div class="container">
        <div class="content">
            <?php
            // Include appropriate page
            switch($page) {
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
                    include 'upload.php';
            }
            ?>
        </div>
    </div>

    <canvas id="confetti-canvas"></canvas>
    <script src="confetti.js"></script>
</body>

</html>