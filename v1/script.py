# I will create the PHP system code along with CSS styling and HTML templates all in one package.
# This will cover database connection, CSV upload, prize management, drawing winners, and displaying winners.
# I will also provide a sample Excel template as CSV content.

php_code = '''
<?php
// db.php - Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "raffle_system";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

// Function to delete all participants entries (Reset)
function resetParticipants($pdo) {
    $pdo->exec("DELETE FROM participants");
}

// Function to delete all winners entries (Reset)
function resetWinners($pdo) {
    $pdo->exec("DELETE FROM winners");
}

// Function to reset prizes claimed count
function resetPrizes($pdo) {
    $stmt = $pdo->prepare("UPDATE prizes SET claimed = 0, enabled = 1");
    $stmt->execute();
}

// Process upload CSV
if (isset($_POST['upload_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $filename = $_FILES['csv_file']['tmp_name'];
        if ($_FILES['csv_file']['type'] == 'text/csv' || pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION) == 'csv') {
            resetParticipants($pdo);
            resetWinners($pdo);
            resetPrizes($pdo);

            $file = fopen($filename, 'r');
            $row = 0;
            $insertStmt = $pdo->prepare("INSERT INTO participants (number, name, barangay, contact_number) VALUES (?, ?, ?, ?)");
            while (($data = fgetcsv($file, 1000, ',')) !== FALSE) {
                if ($row == 0) { $row++; continue; } // Skip header
                if(count($data) >= 4) {
                    $insertStmt->execute([$data[0], $data[1], $data[2], $data[3]]);
                }
                $row++;
            }
            fclose($file);
            $uploadSuccess = true;
        } else {
            $uploadError = "Please upload a valid CSV file.";
        }
    } else {
        $uploadError = "Error uploading file.";
    }
}

// Process add prize
if (isset($_POST['add_prize'])) {
    $prize_name = $_POST['prize_name'] ?? '';
    $quantity = intval($_POST['quantity'] ?? 0);
    $type = $_POST['type'] ?? 'Minor';
    $image_path = '';

    if ($prize_name != '' && $quantity > 0 && ($type == 'Major' || $type == 'Minor')) {
        // Handle image upload if exists
        if (isset($_FILES['prize_image']) && $_FILES['prize_image']['error'] == 0) {
            $target_dir = "uploads/prizes/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $imageName = time() . '_' . basename($_FILES['prize_image']['name']);
            $target_file = $target_dir . $imageName;
            move_uploaded_file($_FILES['prize_image']['tmp_name'], $target_file);
            $image_path = $target_file;
        }
        $stmt = $pdo->prepare("INSERT INTO prizes (name, image, quantity, type, claimed, enabled) VALUES (?, ?, ?, ?, 0, 1)");
        $stmt->execute([$prize_name, $image_path, $quantity, $type]);
        $prizeSuccess = true;
    } else {
        $prizeError = "Invalid prize data.";
    }
}

// Fetch prizes for display and drawing
$prizes = $pdo->query("SELECT * FROM prizes WHERE enabled=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch winners list
$winners = $pdo->query(
"SELECT w.id, p.number, p.name, p.barangay, pr.name as prize_name, w.prize_type, pr.type as prize_type_db 
FROM winners w
JOIN participants p ON w.participant_id = p.id
JOIN prizes pr ON w.prize_id = pr.id
ORDER BY w.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch participants with number only (used for draw number validation etc.)
$participants = $pdo->query("SELECT * FROM participants")->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function hasWonMinor($pdo, $participant_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM winners w JOIN prizes pr ON w.prize_id = pr.id WHERE w.participant_id = ? AND pr.type = 'Minor'");
    $stmt->execute([$participant_id]);
    return $stmt->fetchColumn() > 0;
}

function hasWonPrize($pdo, $participant_id, $prize_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM winners WHERE participant_id = ? AND prize_id = ?");
    $stmt->execute([$participant_id, $prize_id]);
    return $stmt->fetchColumn() > 0;
}

// Process Draw Winner
$drawResult = '';
if (isset($_POST['draw_winner'])) {
    $selected_prize_id = intval($_POST['prize_id'] ?? 0);
    $input_number = trim($_POST['number'] ?? '');

    if ($selected_prize_id > 0 && $input_number != '') {
        // Find participant by number
        $partStmt = $pdo->prepare("SELECT * FROM participants WHERE number = ?");
        $partStmt->execute([$input_number]);
        $participant = $partStmt->fetch(PDO::FETCH_ASSOC);

        if ($participant) {
            // Get prize details
            $prizeStmt = $pdo->prepare("SELECT * FROM prizes WHERE id = ? AND enabled = 1");
            $prizeStmt->execute([$selected_prize_id]);
            $prize = $prizeStmt->fetch(PDO::FETCH_ASSOC);

            if ($prize) {
                $major = $prize['type'] == 'Major';
                $alreadyWonMinor = hasWonMinor($pdo, $participant['id']);
                $alreadyWonPrize = hasWonPrize($pdo, $participant['id'], $prize['id']);
                $canWin = (
                    $major || // Major prize can win multiple times
                    (!$major && !$alreadyWonMinor && !$alreadyWonPrize) // Minor prize not already won
                );

                if ($canWin) {
                    // Insert winner
                    $stmt = $pdo->prepare("INSERT INTO winners (participant_id, prize_id, prize_type) VALUES (?, ?, ?)");
                    $stmt->execute([$participant['id'], $prize['id'], $prize['type']]);

                    // Update prizes claimed count
                    $updateQty = $prize['claimed'] + 1;
                    $enabled = 1;
                    if ($updateQty >= $prize['quantity']) {
                        $enabled = 0; // Disable prize if all claimed
                    }
                    $updateStmt = $pdo->prepare("UPDATE prizes SET claimed = ?, enabled = ? WHERE id = ?");
                    $updateStmt->execute([$updateQty, $enabled, $prize['id']]);

                    $drawResult = "Winner: " . htmlspecialchars($participant['name']) . " (#" . htmlspecialchars($participant['number']) . ") for prize: " . htmlspecialchars($prize['name']);
                } else {
                    $drawResult = "This participant already won a Minor prize or this prize.";
                }
            } else {
                $drawResult = "Selected prize is not available or fully claimed.";
            }
        } else {
            $drawResult = "Participant number not found.";
        }
    } else {
        $drawResult = "Please select a prize and enter a participant number.";
    }
}

// Process remove quantity from prize (only for Minor)
if (isset($_POST['remove_quantity'])) {
    $prize_id = intval($_POST['prize_id']);
    $winner_id = intval($_POST['winner_id']);

    $winnerStmt = $pdo->prepare("SELECT * FROM winners WHERE id = ?");
    $winnerStmt->execute([$winner_id]);
    $winner = $winnerStmt->fetch(PDO::FETCH_ASSOC);

    if ($winner) {
        // Get prize to check type
        $prizeStmt = $pdo->prepare("SELECT * FROM prizes WHERE id = ?");
        $prizeStmt->execute([$prize_id]);
        $prize = $prizeStmt->fetch(PDO::FETCH_ASSOC);

        if ($prize && $prize['type'] == 'Minor') {
            // Delete winner entry
            $delStmt = $pdo->prepare("DELETE FROM winners WHERE id = ?");
            $delStmt->execute([$winner_id]);

            // Decrease claimed count and enable prize
            $newClaimed = max(0, $prize['claimed'] - 1);
            $updateStmt = $pdo->prepare("UPDATE prizes SET claimed = ?, enabled = 1 WHERE id = ?");
            $updateStmt->execute([$newClaimed, $prize['id']]);
            $drawResult = "Removed prize quantity for winner.";
        }
    }
}

// HTML and CSS for the user interface with navbar and dark light brown/red palette and animations
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Raffle System</title>
<style>
    @import url('https://fonts.googleapis.com/css?family=Roboto');
    body {
        margin: 0;
        font-family: 'Roboto', sans-serif;
        background: url('https://images.unsplash.com/photo-1522098543979-ffc7f79d5820?auto=format&fit=crop&w=1470&q=80') no-repeat center center fixed;
        background-size: cover;
        color: #f4eee3;
    }
    .overlay {
        background-color: rgba(82, 44, 17, 0.85);
        min-height: 100vh;
        backdrop-filter: blur(3px);
        padding: 20px;
        display: flex;
        flex-direction: column;
    }
    nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background-color: #5b3a13;
        border-bottom: 3px solid #ee4c4c;
        padding: 10px 20px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.5);
    }
    nav .brand {
        font-size: 1.25rem;
        font-weight: bold;
        color: #f5e9dc;
        letter-spacing: 1.2px;
    }
    nav ul {
        list-style: none;
        display: flex;
        padding-left: 0;
        margin: 0;
    }
    nav ul li {
        margin-left: 20px;
        position: relative;
    }
    nav ul li a {
        color: #ee4c4c;
        text-decoration: none;
        font-weight: 600;
        padding: 5px 10px;
        border-radius: 5px;
        transition: background-color 0.3s ease;
    }
    nav ul li a:hover {
        background-color: #ee4c4c;
        color: #f4eee3;
    }
    nav .logo {
        width: 50px;
        height: 50px;
        background: url('https://upload.wikimedia.org/wikipedia/commons/thumb/6/6b/Windows_icon_-_2021.svg/2048px-Windows_icon_-_2021.svg.png') no-repeat center center;
        background-size: contain;
        cursor: pointer;
    }
    main {
        flex-grow: 1;
        margin-top: 20px;
        color: #f4eee3;
    }
    section {
        background-color: rgba(110, 76, 44, 0.75);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 40px;
        box-shadow: 2px 2px 10px rgba(0,0,0,0.7);
        animation: fadeIn 1s ease forwards;
    }
    h2 {
        color: #ee4c4c;
        margin-top: 0;
    }
    input[type=text], input[type=number], select, input[type=file], button {
        padding: 8px;
        margin: 10px 0;
        border-radius: 4px;
        border: none;
        font-size: 1rem;
        box-sizing: border-box;
    }
    input[type=text], input[type=number], select {
        width: 100%;
    }
    input[type=file] {
        background-color: #5b3a13;
        color: #ee4c4c;
    }
    button {
        background-color: #ee4c4c;
        color: #f4eee3;
        cursor: pointer;
        font-weight: 700;
        transition: background-color 0.4s ease;
        border: none;
    }
    button:hover {
        background-color: #d33a3a;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        color: #f4eee3;
    }
    th, td {
        padding: 12px;
        border: 1px solid #bb7e3a;
        text-align: left;
    }
    th {
        background-color: #823612;
    }
    .modal {
        display: none;
        position: fixed;
        z-index: 10;
        padding-top: 100px;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.7);
        animation: fadeIn 0.5s forwards;
    }
    .modal-content {
        background-color: #5b3a13;
        margin: auto;
        padding: 20px;
        border-radius: 8px;
        width: 80%;
        max-width: 400px;
        box-shadow: 0 0 15px #ee4c4c;
        position: relative;
    }
    @keyframes fadeIn {
        from {opacity: 0; transform: translateY(20px);}
        to {opacity: 1; transform: translateY(0);}
    }
    .close {
        color: #ee4c4c;
        position: absolute;
        top: 10px;
        right: 15px;
        font-size: 1.5em;
        cursor: pointer;
    }
</style>
<script>
function openModal(message) {
    var modal = document.getElementById('winnerModal');
    document.getElementById('modalText').innerText = message;
    modal.style.display = 'block';
}
function closeModal() {
    var modal = document.getElementById('winnerModal');
    modal.style.display = 'none';
}
window.onclick = function(event) {
    var modal = document.getElementById('winnerModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>
</head>
<body>
<div class="overlay">
<nav>
    <div class="brand">Raffle System</div>
    <ul>
        <li><a href="#upload">Upload</a></li>
        <li><a href="#draw">Draw</a></li>
        <li><a href="#prize">Prize</a></li>
        <li><a href="#winners">Winners</a></li>
    </ul>
    <div class="logo" title="Logo"></div>
</nav>
<main>

<section id="upload">
    <h2>Upload CSV</h2>
    <?php if (isset($uploadSuccess)) echo '<p style="color:lightgreen;">CSV uploaded successfully.</p>';?>
    <?php if (isset($uploadError)) echo '<p style="color:#f44336;">'.htmlspecialchars($uploadError).'</p>';?>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="csv_file" accept=".csv" required />
        <button type="submit" name="upload_csv">Upload</button>
    </form>
    <p>CSV Template: number,name,barangay,contact_number</p>
</section>

<section id="draw">
    <h2>Draw Winner</h2>
    <?php if ($drawResult != '') echo '<p style="font-weight:bold;">' . htmlspecialchars($drawResult) . '</p>';?>
    <form method="post">
        <label for="prize_id">Select Prize:</label>
        <select name="prize_id" id="prize_id" required>
            <option value="">-- Select Prize --</option>
            <?php foreach ($prizes as $prize): ?>
            <option value="<?php echo $prize['id']; ?>"><?php echo htmlspecialchars($prize['name']) . " (" . $prize['type'] . ", Remaining: " . ($prize['quantity'] - $prize['claimed']) . ")"; ?></option>
            <?php endforeach; ?>
        </select>
        <label for="number">Enter Number:</label>
        <input type="text" name="number" id="number" placeholder="Enter participant number" required />
        <button type="submit" name="draw_winner">Select Winner</button>
    </form>
</section>

<section id="prize">
    <h2>Prize Management</h2>
    <?php if (isset($prizeSuccess)) echo '<p style="color:lightgreen;">Prize added successfully.</p>';?>
    <?php if (isset($prizeError)) echo '<p style="color:#f44336;">'.htmlspecialchars($prizeError).'</p>';?>
    <form method="post" enctype="multipart/form-data">
        <label for="prize_name">Prize Name:</label>
        <input type="text" name="prize_name" id="prize_name" required />
        <label for="prize_image">Prize Image: (optional)</label>
        <input type="file" name="prize_image" id="prize_image" accept="image/*" />
        <label for="quantity">Quantity:</label>
        <input type="number" name="quantity" id="quantity" min="1" required />
        <label for="type">Type:</label>
        <select name="type" id="type" required>
            <option value="Minor">Minor</option>
            <option value="Major">Major</option>
        </select>
        <button type="submit" name="add_prize">Add Prize</button>
    </form>
    <h3>Prize List</h3>
    <table>
        <thead>
            <tr><th>Name</th><th>Type</th><th>Quantity</th><th>Claimed</th><th>Status</th><th>Image</th></tr>
        </thead>
        <tbody>
            <?php foreach ($prizes as $prize): ?>
            <tr>
                <td><?php echo htmlspecialchars($prize['name']); ?></td>
                <td><?php echo $prize['type']; ?></td>
                <td><?php echo $prize['quantity']; ?></td>
                <td><?php echo $prize['claimed']; ?></td>
                <td><?php echo $prize['enabled'] ? 'Available' : 'Disabled'; ?></td>
                <td><?php if ($prize['image']) echo '<img src="' . htmlspecialchars($prize['image']) . '" alt="Prize image" style="height:40px;">'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section id="winners">
    <h2>Winners</h2>
    <table>
        <thead>
            <tr><th>Number</th><th>Name</th><th>Barangay</th><th>Prize</th><th>Type</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php foreach ($winners as $winner): ?>
            <tr>
                <td><?php echo htmlspecialchars($winner['number']); ?></td>
                <td><?php echo htmlspecialchars($winner['name']); ?></td>
                <td><?php echo htmlspecialchars($winner['barangay']); ?></td>
                <td><?php echo htmlspecialchars($winner['prize_name']); ?></td>
                <td><?php echo htmlspecialchars($winner['prize_type']); ?></td>
                <td>
                <?php if ($winner['prize_type'] == 'Minor') : ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="remove_quantity" value="1" />
                    <input type="hidden" name="prize_id" value="<?php echo $winner['prize_id'] ?? 0; ?>" />
                    <input type="hidden" name="winner_id" value="<?php echo $winner['id']; ?>" />
                    <button type="submit">Remove</button>
                </form>
                <?php else: ?>
                N/A
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

</main>

<!-- Modal for winner display -->
<div id="winnerModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <p id="modalText"></p>
    </div>
</div>

<?php
if ($drawResult != '') {
    echo "<script>openModal('" . addslashes($drawResult) . "');</script>";
}
?>

</div>
</body>
</html>
'''

# Save the PHP file
with open('raffle_system.php', 'w') as f:
    f.write(php_code)

# Generate sample CSV template content
csv_template = "number,name,barangay,contact_number\n001,Juan Dela Cruz,Barangay 1,09171234567\n002,Maria Clara,Barangay 2,09981234567\n" 

# Save CSV template
with open('raffle_template.csv', 'w') as f:
    f.write(csv_template)

"PHP raffle system code and CSV template saved."