<?php
require_once 'config.php';
if (!isset($current_event_id)) {
    $current_event_id = isset($_SESSION['event_id']) ? intval($_SESSION['event_id']) : 1;
}

// Handle Draw Winner (find by number)
if (isset($_POST['draw_winner'])) {
    $drawn_number = trim($_POST['drawn_number']);
    $drawn_number = ltrim($drawn_number, '0');
    if ($drawn_number === '') $drawn_number = '0';
    $drawn_number = (int)$drawn_number;

    if (empty($drawn_number)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a number.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM participants WHERE number = ? AND event_id = ?");
    $stmt->bind_param("ii", $drawn_number, $current_event_id);
    $stmt->execute();
    $participant_query = $stmt->get_result();

    if ($participant_query->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Number not found in participants list.']);
        exit;
    }

    $participant = $participant_query->fetch_assoc();

    if ($participant['status'] === 'winner') {
        echo json_encode(['success' => false, 'message' => 'This participant has already won and cannot be selected again.']);
        exit;
    }
    if ($participant['status'] === 'removed') {
        echo json_encode(['success' => false, 'message' => 'This participant has been removed from the list.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'winner' => [
            'number' => $participant['number'],
            'name' => $participant['name'],
            'barangay' => $participant['barangay'],
            'participant_id' => $participant['id']
        ]
    ]);
    exit;
}

if (isset($_POST['search_participant_prefix'])) {
    $prefix = ltrim($_POST['number_prefix'], '0');
    if ($prefix === '') $prefix = '0';

    $stmt = $conn->prepare("SELECT number, name FROM participants WHERE event_id = ? AND (status IS NULL OR status = '')");
    $stmt->bind_param("i", $current_event_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $participants = [];
    while ($row = $result->fetch_assoc()) {
        $numValue = ltrim($row['number'], '0');
        if ($numValue === '') $numValue = '0';

        if (strpos($numValue, $prefix) === 0) {
            $participants[] = [
                'number' => $row['number'],
                'name' => $row['name']
            ];
        }

        if (count($participants) >= 10) break;
    }

    echo json_encode(['success' => true, 'results' => $participants]);
    exit;
}

// Handle Confirm Winner
if (isset($_POST['confirm_winner'])) {
    $participant_id = intval($_POST['participant_id']);
    $number = intval($_POST['number']);
    $name = sanitize_input($_POST['name']);
    $barangay = sanitize_input($_POST['barangay']);
    $prize_id = intval($_POST['prize_id'] ?? 0);
    $prize_name = isset($_POST['prize_name']) ? sanitize_input($_POST['prize_name']) : '';
    $prize_type = isset($_POST['prize_type']) ? sanitize_input($_POST['prize_type']) : '';

    if ($prize_id > 0) {
        $upd = $conn->prepare("UPDATE prizes SET claimed = claimed + 1, enabled = IF(claimed + 1 >= quantity, 0, 1) WHERE id = ? AND event_id = ?");
        $upd->bind_param("ii", $prize_id, $current_event_id);
        $upd->execute();
        $upd->close();
    }

    $stmt = $conn->prepare("INSERT INTO winners (event_id, participant_id, prize_id, number, name, barangay, prize_name, prize_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiisssss", $current_event_id, $participant_id, $prize_id, $number, $name, $barangay, $prize_name, $prize_type);

    if ($stmt->execute()) {
        $upd_status = $conn->prepare("UPDATE participants SET status = 'winner' WHERE id = ? AND event_id = ?");
        $upd_status->bind_param("ii", $participant_id, $current_event_id);
        $upd_status->execute();
        $upd_status->close();
        echo json_encode(['success' => true, 'message' => 'Winner confirmed successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to confirm winner.']);
    }
    $stmt->close();
    exit;
}

// Handle Remove from List (tag status only, keep record)
if (isset($_POST['remove_participant'])) {
    $participant_id = intval($_POST['participant_id']);
    $stmt = $conn->prepare("UPDATE participants SET status = 'removed' WHERE id = ? AND event_id = ?");
    $stmt->bind_param("ii", $participant_id, $current_event_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Participant removed from the draw list. Record kept.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to remove participant.']);
    }
    $stmt->close();
    exit;
}

// Fetch available prizes
$prizes_stmt = $conn->prepare("SELECT * FROM prizes WHERE event_id = ? AND enabled = 1 AND claimed < quantity ORDER BY (type = 'Major') DESC, id ASC");
$prizes_stmt->bind_param("i", $current_event_id);
$prizes_stmt->execute();
$prizes_result = $prizes_stmt->get_result();
$prizes = [];
while ($p = $prizes_result->fetch_assoc()) {
    $prizes[] = $p;
}

$no_prizes = empty($prizes);

// Get past winners
$stmt_pw = $conn->prepare("SELECT number, name, barangay, prize_name, won_at FROM winners WHERE event_id = ? ORDER BY won_at DESC LIMIT 10");
$stmt_pw->bind_param("i", $current_event_id);
$stmt_pw->execute();
$past_winners = $stmt_pw->get_result();
?>

<?php display_message(); ?>

<div class="container1">
  <div class="draw-panel">
    <div class="draw-panel-inner">

      <div class="draw-header-area">
        <div class="draw-header-icon">🎯</div>
        <div>
          <h2 class="draw-heading">Draw Entry</h2>
          <p class="draw-subtitle">Enter ticket number to find winner</p>
        </div>
      </div>

      <?php if ($no_prizes): ?>
      <div class="no-prize-notice">
        ⚠️ No prizes available yet. <a href="admin.php?page=prizes" style="color:#ec4899; font-weight:700; text-decoration:none;">Add prizes</a> before drawing.
      </div>
      <?php endif; ?>

      <div class="draw-prize-select">
        <label class="draw-label">Prize for this draw</label>
        <select id="prize_select" class="prize-select" <?php echo $no_prizes ? 'disabled' : ''; ?>>
          <option value="0">— No Prize Selected —</option>
          <?php foreach ($prizes as $p): ?>
          <?php $remaining = $p['quantity'] - $p['claimed']; ?>
          <option value="<?php echo $p['id']; ?>"
                  data-name="<?php echo htmlspecialchars($p['name']); ?>"
                  data-type="<?php echo $p['type']; ?>"
                  data-image="<?php echo htmlspecialchars($p['image']); ?>">
            <?php echo htmlspecialchars($p['name']); ?> (<?php echo $remaining; ?> left)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="draw-number-section">
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

<!-- Winner Modal -->
<div id="winnerModal" class="modal">
    <div class="modal-overlay"></div>
    <div class="modal-content winner-modal">
        <span class="close">&times;</span>

        <div class="wm-congrats">Congratulations!</div>

        <div class="winner-number" id="winner_number"></div>

        <div class="winner-name" id="winner_name"></div>

        <div class="winner-barangay" id="winner_barangay"></div>

        <div class="winner-prize" id="winner_prize"></div>

        <div class="winner-actions">
            <button type="button" id="confirm_btn" class="btn btn-confirm">Confirm Winner</button>
            <button type="button" id="remove_btn" class="btn btn-remove">Remove from List</button>
            <button type="button" class="btn btn-cancel close-modal">Cancel</button>
        </div>
    </div>
</div>

<script>
let currentWinner = null;
let nameCheckTimeout = null;

function getSelectedPrize() {
    const sel = document.getElementById('prize_select');
    const opt = sel.options[sel.selectedIndex];
    return {
        id: parseInt(opt.value || '0', 10),
        name: opt.getAttribute('data-name') || '',
        type: opt.getAttribute('data-type') || '',
        image: opt.getAttribute('data-image') || ''
    };
}

document.addEventListener('DOMContentLoaded', function() {
    const drawBtn = document.getElementById('draw_btn');
    const resetBtn = document.getElementById('reset_drawn_number');
    const prizeSelect = document.getElementById('prize_select');

    if (prizeSelect && prizeSelect.options.length > 1) {
        prizeSelect.selectedIndex = 1;
    }

    if (drawBtn) {
        drawBtn.addEventListener('click', function() {
            const drawnNumber = document.getElementById('drawn_number').value.trim();
            const prizeSelect = document.getElementById('prize_select');

            if (!prizeSelect.value || prizeSelect.value === '0') {
                showToast('Please select a prize first.', 'error');
                return;
            }

            if (!drawnNumber) {
                showToast('Please enter a number.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('draw_winner', '1');
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
                .catch(() => {
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
    document.getElementById('winner_name').textContent = winner.name;
    document.getElementById('winner_number').textContent = 'Ticket No. ' + winner.number;
    document.getElementById('winner_barangay').textContent = 'Barangay ' + winner.barangay;

    const prize = getSelectedPrize();
    const prizeEl = document.getElementById('winner_prize');
    if (prize.id > 0) {
        prizeEl.textContent = 'Prize: ' + prize.name;
        prizeEl.style.display = '';
    } else {
        prizeEl.textContent = '';
        prizeEl.style.display = 'none';
    }

    document.getElementById('winnerModal').classList.add('show');
    if (typeof startConfetti === 'function') startConfetti();
}

function confirmWinner(winner) {
    const prize = getSelectedPrize();
    const formData = new FormData();
    formData.append('confirm_winner', '1');
    formData.append('participant_id', winner.participant_id);
    formData.append('number', winner.number);
    formData.append('name', winner.name);
    formData.append('barangay', winner.barangay);
    formData.append('prize_id', prize.id);
    formData.append('prize_name', prize.name);
    formData.append('prize_type', prize.type);

    fetch('draw.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Winner confirmed successfully!', 'success');
                document.getElementById('winnerModal').classList.remove('show');
                if (typeof stopConfetti === 'function') stopConfetti();
                currentWinner = null;
                document.getElementById('drawn_number').value = '';
                document.getElementById('participant_name_hint').innerHTML = '';
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(() => {
            showToast('An error occurred. Please try again.', 'error');
        });
}

document.getElementById('confirm_btn').addEventListener('click', function() {
    if (!currentWinner) return;
    confirmWinner(currentWinner);
});

function removeFromList(winner) {
    if (!confirm('Remove this participant from the draw list?\n\nThe record will be kept, but they can no longer be drawn.')) return;

    const formData = new FormData();
    formData.append('remove_participant', '1');
    formData.append('participant_id', winner.participant_id);

    fetch('draw.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                document.getElementById('winnerModal').classList.remove('show');
                if (typeof stopConfetti === 'function') stopConfetti();
                currentWinner = null;
                document.getElementById('drawn_number').value = '';
                document.getElementById('participant_name_hint').innerHTML = '';
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(() => {
            showToast('An error occurred. Please try again.', 'error');
        });
}

document.getElementById('remove_btn').addEventListener('click', function() {
    if (!currentWinner) return;
    removeFromList(currentWinner);
});

document.querySelectorAll('.close, .close-modal').forEach(element => {
    element.addEventListener('click', function() {
        document.getElementById('winnerModal').classList.remove('show');
        if (typeof stopConfetti === 'function') stopConfetti();
        currentWinner = null;
        document.getElementById('drawn_number').value = '';
        document.getElementById('participant_name_hint').textContent = '';
    });
});

document.getElementById('winnerModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.classList.remove('show');
        if (typeof stopConfetti === 'function') stopConfetti();
        currentWinner = null;
        document.getElementById('drawn_number').value = '';
        document.getElementById('participant_name_hint').textContent = '';
    }
});

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
</script>
