<?php
require_once 'config.php';
// Handle Draw Winner
if (isset($_POST['draw_winner'])) {
    $prize_id = intval($_POST['prize_id']);

    $drawn_number = trim($_POST['drawn_number']); // "00001"
    $drawn_number = ltrim($drawn_number, '0');    // "1"
    if ($drawn_number === '') {
        $drawn_number = '0';
    }
    $drawn_number = (int) $_POST['drawn_number'];

    // Validation
    if (empty($prize_id)) {
        echo json_encode(['success' => false, 'message' => 'Please select a prize.']);
        exit;
    }

    if (empty($drawn_number)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a number.']);
        exit;
    }

    // Check if prize exists and is active
    $stmt = $conn->prepare("SELECT * FROM prizes WHERE id = ?");
    $stmt->bind_param("i", $prize_id);
    $stmt->execute();
    $prize_query = $stmt->get_result();

    if ($prize_query->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Prize not found.']);
        exit;
    }

    $prize = $prize_query->fetch_assoc();

    if ($prize['quantity'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'This prize has no remaining quantity.']);
        exit;
    }

    // Check if participant exists
    $stmt = $conn->prepare("SELECT * FROM participants WHERE number = ?");
    $stmt->bind_param("s", $drawn_number);
    $stmt->execute();
    $participant_query = $stmt->get_result();

    if ($participant_query->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Number not found in participants list.']);
        exit;
    }

    $participant = $participant_query->fetch_assoc();

   if ($prize['type'] == 'Minor') {
        // Check if already won Minor
        $stmt = $conn->prepare("SELECT * FROM winners WHERE number = ? AND prize_type = 'Minor'");
        $stmt->bind_param("s", $drawn_number);
        $stmt->execute();
        $check_winner = $stmt->get_result();

        if ($check_winner->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'This number has already won a Minor prize.']);
            exit;
        }
    }

    if ($prize['type'] == 'Major') {
        // Check if already won Major
        $stmt = $conn->prepare("SELECT * FROM winners WHERE number = ? AND prize_type = 'Major'");
        $stmt->bind_param("s", $drawn_number);
        $stmt->execute();
        $check_winner = $stmt->get_result();

        if ($check_winner->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'This number has already won a Major prize.']);
            exit;
        }
    }

    // Return winner data for modal display
    echo json_encode([
        'success' => true,
        'winner' => [
            'number' => $participant['number'],
            'name' => $participant['name'],
            'barangay' => $participant['barangay'],
            'contact' => $participant['contact_number'],
            'prize_name' => $prize['prize_name'],
            'prize_type' => $prize['type'],
            'prize_id' => $prize['id'],
            'participant_id' => $participant['id']
        ]
    ]);
    exit;
}


// Handle Confirm Winner
if (isset($_POST['confirm_winner'])) {
    $prize_id = intval($_POST['prize_id']);
    $participant_id = intval($_POST['participant_id']);
    $number = sanitize_input($_POST['number']);
    $name = sanitize_input($_POST['name']);
    $barangay = sanitize_input($_POST['barangay']);
    $prize_name = sanitize_input($_POST['prize_name']);
    $prize_type = sanitize_input($_POST['prize_type']);
    
    // Insert into winners table
    $stmt = $conn->prepare("INSERT INTO winners (participant_id, prize_id, number, name, barangay, prize_name, prize_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssss", $participant_id, $prize_id, $number, $name, $barangay, $prize_name, $prize_type);
    
    if ($stmt->execute()) {
        // Decrease prize quantity
        $conn->query("UPDATE prizes SET quantity = quantity - 1 WHERE id = $prize_id");
        
        // Check if quantity is 0 and disable prize
        $check_qty = $conn->query("SELECT quantity FROM prizes WHERE id = $prize_id");
        $qty_data = $check_qty->fetch_assoc();
        if ($qty_data['quantity'] <= 0) {
            $conn->query("UPDATE prizes SET status = 'Disabled' WHERE id = $prize_id");
        }
        
        echo json_encode(['success' => true, 'message' => 'Winner confirmed successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to confirm winner.']);
    }
    $stmt->close();
    exit;
}

// Get active prizes
$active_prizes = $conn->query("SELECT * FROM prizes WHERE quantity > 0 ORDER BY type, prize_name");
?>


<?php display_message(); ?>

<?php if ($active_prizes->num_rows == 0): ?>
<div style="text-align: center; padding: 60px; background: #f8f9fa; border-radius: 10px;">
    <h2 style="color: #999; margin-bottom: 15px;">No Active Prize</h2>
    <p style="color: #666; margin-bottom: 40px;">Please add prizes in the Prize section before drawing winners.</p>
    <a href="index.php?page=prize" class="btn btn-primary" style="display:inline-block; margin-top:20px;">Go to
        Prizes</a>
</div>
<?php else: ?>
<div class="container1">
    <div class="draw-section">
        <br>
        <div class="form-group">
            <!-- <label>Select Prize *</label> -->
            <select id="prize_select" class="form-control prize-center" required>
                <option value="">Select a prize...</option>
                <?php while ($prize = $active_prizes->fetch_assoc()): ?>
                <option value="<?php echo $prize['id']; ?>" data-type="<?php echo $prize['type']; ?>">
                    <?php echo htmlspecialchars($prize['prize_name']); ?>
                    (<?php echo $prize['type']; ?> - <?php echo $prize['quantity']; ?> left)
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="text-center">
            <input type="text" id="drawn_number" class="number-draw" placeholder="00000" maxlength="5" required>
        </div>
        <button type="button" id="draw_btn" class="btn btn-primary"
        style="font-size: 1.2rem; font-weight: bold; padding: 12px 40px; border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); display: block; margin: 20px auto;">
        🔍 Check Winner
    </button>
    <button type="button" id="reset_drawn_number" class="btn btn-secondary"
        style="font-size: 1.2rem; font-weight: bold; padding: 12px 40px; border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); display: block; margin: 20px auto;">
        Reset</button>
    </div>
</div>


<?php endif; ?>

<!-- Winner Modal -->
<div id="winnerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close">&times;</span>
            <h2>We have a winner!</h2>
        </div>
        <div class="modal-body">
            <div class="winner-number" id="winner_number" style="display:none;"></div>
            <div class="winner-number" id="winner_name"></div>
            <div class="winner-info">
                <!-- <p><strong>Name:</strong> <span id="winner_name"></span></p> -->
                <h4 class="winner_barangay">Barangay&nbsp;<span id="winner_barangay"></span></h4>
                </h4>
                <p style="display:none;"><strong>Contact:</strong> <span id="winner_contact"></span></p>
                <h4 class="winner_prize">Prize:&nbsp;<span id="winner_prize"></span></h4>
                </h4>
                <p style="display:none;"><strong>Type:</strong> <span id="winner_type"></span></p>
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" id="confirm_btn" class="btn btn-success">Confirm Winner</button>
            <button type="button" class="btn btn-secondary close-modal">Cancel</button>
        </div>
    </div>
</div>

<script>
let currentWinner = null;

document.getElementById('draw_btn').addEventListener('click', function() {
    const prizeId = document.getElementById('prize_select').value;
    const drawnNumber = document.getElementById('drawn_number').value.trim();

    if (!prizeId) {
        alert('Please select a prize.');
        return;
    }

    if (!drawnNumber) {
        alert('Please enter a number.');
        return;
    }

    // Send AJAX request
    const formData = new FormData();
    formData.append('draw_winner', '1');
    formData.append('prize_id', prizeId);
    formData.append('drawn_number', drawnNumber);

    fetch('draw.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentWinner = data.winner;
                showWinnerModal(data.winner);
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            alert('An error occurred. Please try again.');
            console.error(error);
        });
});

function showWinnerModal(winner) {
    document.getElementById('winner_number').textContent = winner.number;
    document.getElementById('winner_name').textContent = winner.name;
    document.getElementById('winner_barangay').textContent = winner.barangay;
    document.getElementById('winner_contact').textContent = winner.contact;
    document.getElementById('winner_prize').textContent = winner.prize_name;
    document.getElementById('winner_type').textContent = winner.prize_type;

    document.getElementById('winnerModal').style.display = 'block';

    // Start confetti
    if (typeof startConfetti === 'function') {
        startConfetti();
    }
}

document.getElementById('confirm_btn').addEventListener('click', function() {
    if (!currentWinner) return;

    const formData = new FormData();
    formData.append('confirm_winner', '1');
    formData.append('prize_id', currentWinner.prize_id);
    formData.append('participant_id', currentWinner.participant_id);
    formData.append('number', currentWinner.number);
    formData.append('name', currentWinner.name);
    formData.append('barangay', currentWinner.barangay);
    formData.append('prize_name', currentWinner.prize_name);
    formData.append('prize_type', currentWinner.prize_type);

    fetch('draw.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Winner confirmed successfully!');
                document.getElementById('drawn_number').value = '';
                document.getElementById('prize_select').selectedIndex = 0;
                window.location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            alert('An error occurred. Please try again.');
            console.error(error);
        });
});

// Close modal
document.querySelectorAll('.close, .close-modal').forEach(element => {
    element.addEventListener('click', function() {
        document.getElementById('winnerModal').style.display = 'none';
        if (typeof stopConfetti === 'function') {
            stopConfetti();
        }
        currentWinner = null;
        // Reset fields
        document.getElementById('drawn_number').value = '';
        document.getElementById('prize_select').selectedIndex = 0;
    });
});

window.onclick = function(event) {
    const modal = document.getElementById('winnerModal');
    if (event.target == modal) {
        modal.style.display = 'none';
        if (typeof stopConfetti === 'function') {
            stopConfetti();
        }
        currentWinner = null;
        // Reset fields
        document.getElementById('drawn_number').value = '';
        document.getElementById('prize_select').selectedIndex = 0;
    }
}
document.getElementById('drawn_number').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault(); // stop form submission if inside a form
        document.getElementById('draw_btn').click();
    }
});

// Karaoke-style number input - build from right
const drawnInput = document.getElementById('drawn_number');

drawnInput.addEventListener('beforeinput', function(e) {
    e.preventDefault();

    let currentDigits = this.value.replace(/\D/g, '').replace(/^0+/, '') || '0';

    // Handle backspace/delete
    if (e.inputType === 'deleteContentBackward' || e.inputType === 'deleteContentForward') {
        currentDigits = currentDigits.slice(0, -1) || '0';
        this.value = currentDigits.padStart(5, '0');
        return;
    }

    // Handle number input
    if (e.data && /^\d$/.test(e.data)) {
        // Remove leading zeros, add new digit
        if (currentDigits === '0') {
            currentDigits = e.data;
        } else {
            currentDigits = currentDigits + e.data;
        }

        // Keep only last 5 digits if overflow
        if (currentDigits.length > 5) {
            currentDigits = currentDigits.slice(-5);
        }

        this.value = currentDigits.padStart(5, '0');
    }
});

// Prevent paste
drawnInput.addEventListener('paste', function(e) {
    e.preventDefault();
});

// Initialize
drawnInput.value = '00000';

document.getElementById('reset_drawn_number').addEventListener('click', function() {
    document.getElementById('drawn_number').value = '00000';
    document.getElementById('drawn_number').focus();
});
</script>