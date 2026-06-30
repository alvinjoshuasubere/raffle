<?php
require_once 'config.php';
// Handle Draw Winner
if (isset($_POST['draw_winner'])) {
    $prize_id = intval($_POST['prize_id']);

    $drawn_number = trim($_POST['drawn_number']);
    $drawn_number = ltrim($drawn_number, '0');
    if ($drawn_number === '') {
        $drawn_number = '0';
    }
    $drawn_number = (int)$drawn_number;

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
        echo json_encode(['success ' => false, 'message' => 'Prize not found.']);
        exit;
    }

    $prize = $prize_query->fetch_assoc();

    if ($prize['quantity'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'This prize has no remaining quantity.']);
        exit;
    }

    // Check if participant exists
    $stmt = $conn->prepare("SELECT * FROM participants WHERE number = ?");
    $stmt->bind_param("i", $drawn_number);
    $stmt->execute();
    $participant_query = $stmt->get_result();

    if ($participant_query->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Number not found in participants list.']);
        exit;
    }

    $participant = $participant_query->fetch_assoc();

   if ($prize['type'] == 'Minor') {
        $stmt = $conn->prepare("SELECT * FROM winners WHERE number = ? AND prize_type = 'Minor'");
        $stmt->bind_param("i", $drawn_number);
        $stmt->execute();
        $check_winner = $stmt->get_result();

        if ($check_winner->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'This number has already won a Minor prize.']);
            exit;
        }
    }

    if ($prize['type'] == 'Major') {
        $stmt = $conn->prepare("SELECT * FROM winners WHERE number = ? AND prize_type = 'Major'");
        $stmt->bind_param("i", $drawn_number);
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

if (isset($_POST['search_participant_prefix'])) {
    $prefix = ltrim($_POST['number_prefix'], '0'); // remove leading zeros
    if ($prefix === '') $prefix = '0';

    // We'll fetch all possible numbers, then filter by numeric prefix
    $stmt = $conn->prepare("SELECT number, name FROM participants");
    $stmt->execute();
    $result = $stmt->get_result();

    $participants = [];
    while ($row = $result->fetch_assoc()) {
        // Remove leading zeros for comparison
        $numValue = ltrim($row['number'], '0');
        if ($numValue === '') $numValue = '0';

        // Check if the number starts with the prefix
        if (strpos($numValue, $prefix) === 0) {
            $participants[] = [
                'number' => $row['number'],
                'name' => $row['name']
            ];
        }

        // Stop after 10 matches
        if (count($participants) >= 10) break;
    }

    echo json_encode(['success' => true, 'results' => $participants]);
    exit;
}




// Handle Confirm Winner
if (isset($_POST['confirm_winner'])) {
    $prize_id = intval($_POST['prize_id']);
    $participant_id = intval($_POST['participant_id']);
    $number = intval($_POST['number']);
    $name = sanitize_input($_POST['name']);
    $barangay = sanitize_input($_POST['barangay']);
    $prize_name = sanitize_input($_POST['prize_name']);
    $prize_type = sanitize_input($_POST['prize_type']);
    
    // Insert into winners table
    $stmt = $conn->prepare("INSERT INTO winners (participant_id, prize_id, number, name, barangay, prize_name, prize_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiissss", $participant_id, $prize_id, $number, $name, $barangay, $prize_name, $prize_type);
    
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

// Get past winners (most recent first)
$past_winners = $conn->query("SELECT w.number, w.name, w.barangay, w.prize_name, w.prize_type, w.won_at 
                              FROM winners w 
                              ORDER BY w.won_at DESC 
                              LIMIT 10");
?>

<?php display_message(); ?>

<?php if ($active_prizes->num_rows == 0): ?>
<div style="text-align: center; padding: 80px 40px;">
  <div style="font-size: 64px; margin-bottom: 24px; opacity: 0.5;">🎯</div>
  <h2 style="color: #4a4a6a; margin-bottom: 15px; font-size: 28px;">No Active Prize</h2>
  <p style="color: #6b7280; margin-bottom: 40px; font-size: 16px;">Please add prizes in the Prize section before drawing winners.</p>
  <a href="index.php?page=prize" class="btn btn-primary" style="display:inline-block; margin-top:20px;">Go to Prizes</a>
</div>
<?php else: ?>
<div class="container1">
  <div class="draw-panel">
    <div class="draw-panel-inner">
      <div class="draw-header-area">
        <div class="draw-header-icon">🎯</div>
        <div>
          <h2 class="draw-heading">Draw Entry</h2>
          <p class="draw-subtitle">Select a prize &amp; enter ticket number</p>
        </div>
      </div>

      <div class="draw-prize-select">
        <label class="draw-label">Select Prize</label>
        <select id="prize_select" class="prize-select" required>
          <option value="">Choose a prize...</option>
          <?php while ($prize = $active_prizes->fetch_assoc()): ?>
          <option value="<?php echo $prize['id']; ?>" data-type="<?php echo $prize['type']; ?>">
            <?php echo htmlspecialchars($prize['prize_name']); ?>
            (<?php echo $prize['type']; ?> - <?php echo $prize['quantity']; ?> left)
          </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="draw-number-section">
        <label class="draw-label">Enter Ticket Number</label>
        <div class="number-display-bg">
          <div class="number-display-inner">
            <input type="text" autofocus id="drawn_number" class="draw-number-input" maxlength="5" placeholder="—" />
          </div>
        </div>
        <div id="participant_name_hint" class="participant-hint"></div>
      </div>

      <div class="draw-actions">
        <button type="button" id="draw_btn" class="btn-draw-find">
          <span class="btn-icon">🔍</span>
          <span class="btn-text">Find Winner</span>
        </button>
        <button type="button" id="reset_drawn_number" class="btn-draw-clear">
          <span class="btn-icon">↺</span>
        </button>
      </div>

      <div class="draw-help-wrap">
        <span class="draw-help">Press <kbd>Enter</kbd> to search</span>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Winner Modal -->
<div id="winnerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header" style="<?php if (file_exists('uploads/bg/custom_bg.jpg')): ?>background:linear-gradient(135deg, rgba(236,73,153,0.85), rgba(244,114,182,0.7)), url('uploads/bg/custom_bg.jpg?v=<?php echo filemtime('uploads/bg/custom_bg.jpg'); ?>') center/cover;<?php endif; ?>">
            <span class="close">&times;</span>
            <h2>We have a winner!</h2>
        </div>
        <div class="modal-body">
           
            <div class="winner-info">
                <div class="winner-number" id="winner_name"></div>
                <h4 class="winner_barangay">Barangay&nbsp;<span id="winner_barangay"></span></h4>
                <p style="display:none;"><strong>Contact:</strong> <span id="winner_contact"></span></p>

                <p style="display:none;"><strong>Type:</strong> <span id="winner_type"></span></p>
            </div>
             <h6 class="winner_ticket"><small>Ticket #</small>&nbsp;<span id="winner_number"></span></h6>
            <h6 class="winner-prize">Prize:&nbsp;<span id="winner_prize"></span></h6>
        </div>
        <div class="modal-footer">
            <button type="button" id="confirm_btn" class="btn btn-success">Confirm Winner</button>
            <button type="button" class="btn btn-secondary close-modal">Cancel</button>
        </div>
    </div>
</div>

<script>
let currentWinner = null;
let nameCheckTimeout = null;

// Draw button click
document.addEventListener('DOMContentLoaded', function() {
    const drawBtn = document.getElementById('draw_btn');
    const resetBtn = document.getElementById('reset_drawn_number');

    if (drawBtn) {
        drawBtn.addEventListener('click', function() {
            const prizeId = document.getElementById('prize_select').value;
            const drawnNumber = document.getElementById('drawn_number').value.trim();

            if (!prizeId) {
                showToast('Please select a prize.', 'error');
                return;
            }
            if (!drawnNumber) {
                showToast('Please enter a number.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('draw_winner', '1');
            formData.append('prize_id', prizeId);
            formData.append('drawn_number', drawnNumber);

            fetch('draw.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentWinner = data.winner;
                        showWinnerModal(data.winner);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('An error occurred. Please try again.', 'error');
                });
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            document.getElementById('drawn_number').value = '';
            document.getElementById('drawn_number').focus();
        });
    }
});

function showWinnerModal(winner) {
    document.getElementById('winner_number').textContent = winner.number;
    document.getElementById('winner_name').textContent = winner.name;
    document.getElementById('winner_barangay').textContent = winner.barangay;
    document.getElementById('winner_contact').textContent = winner.contact;
    document.getElementById('winner_prize').textContent = winner.prize_name;
    document.getElementById('winner_type').textContent = winner.prize_type;
    document.getElementById('winnerModal').style.display = 'block';
    if (typeof startConfetti === 'function') startConfetti();
}

function confirmWinner(winner) {
    const formData = new FormData();
    formData.append('confirm_winner', '1');
    formData.append('prize_id', winner.prize_id);
    formData.append('participant_id', winner.participant_id);
    formData.append('number', winner.number);
    formData.append('name', winner.name);
    formData.append('barangay', winner.barangay);
    formData.append('prize_name', winner.prize_name);
    formData.append('prize_type', winner.prize_type);

    fetch('draw.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Winner confirmed successfully!', 'success');
                document.getElementById('winnerModal').style.display = 'none';
                if (typeof stopConfetti === 'function') stopConfetti();
                currentWinner = null;
                document.getElementById('drawn_number').value = '';
                document.getElementById('participant_name_hint').innerHTML = '';
                updatePrizeDropdown(winner.prize_id);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            showToast('An error occurred. Please try again.', 'error');
        });
}

function updatePrizeDropdown(prizeId) {
    const prizeSelect = document.getElementById('prize_select');
    const selectedOption = prizeSelect.querySelector(`option[value="${prizeId}"]`);
    if (selectedOption) {
        const match = selectedOption.textContent.match(/\(([^-]+)-\s*(\d+)\s*left\)/);
        if (match) {
            let qty = parseInt(match[2], 10) - 1;
            if (qty <= 0) {
                selectedOption.remove();
                prizeSelect.selectedIndex = 0;
            } else {
                selectedOption.textContent = selectedOption.textContent.replace(
                    /\(\s*([^-]+)-\s*\d+\s*left\)/, `(${match[1]}- ${qty} left)`);
            }
        }
    }
}

document.getElementById('confirm_btn').addEventListener('click', function() {
    if (!currentWinner) return;
    confirmWinner(currentWinner);
});

document.querySelectorAll('.close, .close-modal').forEach(element => {
    element.addEventListener('click', function() {
        document.getElementById('winnerModal').style.display = 'none';
        if (typeof stopConfetti === 'function') stopConfetti();
        currentWinner = null;
        document.getElementById('drawn_number').value = '';
        document.getElementById('participant_name_hint').textContent = '';
    });
});

window.onclick = function(event) {
    const modal = document.getElementById('winnerModal');
    if (event.target == modal) {
        modal.style.display = 'none';
        if (typeof stopConfetti === 'function') stopConfetti();
        currentWinner = null;
        document.getElementById('drawn_number').value = '';
        document.getElementById('participant_name_hint').textContent = '';
    }
}

document.getElementById('drawn_number').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('draw_btn').click();
    }
});

const drawnInput = document.getElementById('drawn_number');

drawnInput.addEventListener('beforeinput', function(e) {
    e.preventDefault();
    let currentDigits = this.value.replace(/\D/g, '').replace(/^0+/, '');

    if (e.inputType === 'deleteContentBackward' || e.inputType === 'deleteContentForward') {
        currentDigits = currentDigits.slice(0, -1);
        this.value = currentDigits || '';
        return;
    }

    if (e.data && /^\d$/.test(e.data)) {
        if (!currentDigits) {
            currentDigits = e.data;
        } else {
            currentDigits = currentDigits + e.data;
        }
        if (currentDigits.length > 5) {
            currentDigits = currentDigits.slice(-5);
        }
        this.value = currentDigits;
    }
});

drawnInput.addEventListener('paste', function(e) {
    e.preventDefault();
});

drawnInput.value = '';

document.getElementById('reset_drawn_number').addEventListener('click', function() {
    document.getElementById('drawn_number').value = '';
    document.getElementById('drawn_number').focus();
});

function checkParticipantName(number) {
    const hintDiv = document.getElementById('participant_name_hint');
    if (nameCheckTimeout) clearTimeout(nameCheckTimeout);
    number = number.trim();
    if (!number) { hintDiv.innerHTML = ''; return; }
    hintDiv.innerHTML = '<span style="color: #6b7280;">Loading...</span>';
    nameCheckTimeout = setTimeout(() => {
        fetch('draw.php', {
                method: 'POST',
                body: new URLSearchParams({ search_participant_prefix: '1', number_prefix: number })
            })
            .then(res => res.json())
            .then(data => {
                hintDiv.innerHTML = '';
                if (data.success && Array.isArray(data.results) && data.results.length > 0) {
                    if (data.results.length === 1) {
                        const title = document.createElement('h4');
                        title.textContent = '🎯 Possible Winner';
                        title.className = 'winner-title';
                        hintDiv.appendChild(title);
                        const slotMachine = document.createElement('div');
                        slotMachine.className = 'slot-machine';
                        const ul = document.createElement('ul');
                        ul.className = 'winner-list';
                        const textLi = document.createElement('li');
                        textLi.textContent = '🎉 And the Winner is...';
                        ul.appendChild(textLi);
                        slotMachine.appendChild(ul);
                        hintDiv.appendChild(slotMachine);
                    } else {
                        const title = document.createElement('h4');
                        title.textContent = '🎯 Possible Winners';
                        title.className = 'winner-title';
                        hintDiv.appendChild(title);
                        const slotMachine = document.createElement('div');
                        slotMachine.className = 'slot-machine';
                        const ul = document.createElement('ul');
                        ul.className = 'winner-list';
                        const shuffled = [...data.results].sort(() => 0.5 - Math.random()).slice(0, 10);
                        shuffled.forEach(item => {
                            const li = document.createElement('li');
                            li.textContent = `Ticket # ${item.number} - ${item.name}`;
                            ul.appendChild(li);
                        });
                        slotMachine.appendChild(ul);
                        hintDiv.appendChild(slotMachine);

                    }
                } else {
                    hintDiv.innerHTML = '<span style="color:#6b7280;">No matches found</span>';
                }
            })
            .catch(() => { hintDiv.innerHTML = ''; });
    }, 300);
}
</script>