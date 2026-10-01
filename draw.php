<?php
require_once 'config.php';
if (!isset($current_event_id)) {
    $current_event_id = get_active_event_id($conn);
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
            'barangay' => isset($participant['barangay']) ? trim((string)$participant['barangay']) : '',
            'purok' => isset($participant['purok']) ? trim((string)$participant['purok']) : '',
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
    $barangay = isset($_POST['barangay']) ? sanitize_input($_POST['barangay']) : '';
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

// Get past winners
$stmt_pw = $conn->prepare("SELECT number, name, barangay, prize_name, won_at FROM winners WHERE event_id = ? ORDER BY won_at DESC LIMIT 10");
$stmt_pw->bind_param("i", $current_event_id);
$stmt_pw->execute();
$past_winners = $stmt_pw->get_result();
?>

<?php display_message(); ?><style>
/* ===== DRAW PREMIUM UI ===== */
.container1{width:min(1000px,calc(100% - 32px));margin:40px auto 70px;position:relative;z-index:2}
.draw-panel{border-radius:30px;background:rgba(255,255,255,.96);border:1px solid #e2e8f0;box-shadow:0 25px 70px rgba(15,23,42,.12);overflow:hidden}
.draw-panel-inner{padding:42px}
.draw-header-area{display:flex;align-items:center;justify-content:center;gap:15px;text-align:left;margin-bottom:34px}.draw-header-icon{width:58px;height:58px;border-radius:18px;display:grid;place-items:center;background:#eff6ff;color:#2563eb;font-size:27px;box-shadow:0 8px 22px rgba(37,99,235,.12)}.draw-heading{margin:0;color:#0f172a;font-size:26px;font-weight:900}.draw-subtitle{margin:5px 0 0;color:#64748b;font-size:14px}
.draw-number-section{text-align:center}.number-display-bg{padding:22px;border-radius:28px;background:linear-gradient(135deg,#eff6ff,#f8fafc);border:1px solid #dbeafe}.number-display-inner{background:#020617;border-radius:22px;padding:12px;box-shadow:inset 0 0 30px rgba(0,0,0,.7)}.draw-number-input{width:100%;height:150px;border:2px solid #1e40af;border-radius:16px;background:linear-gradient(#0f172a,#111827);color:#fff;text-align:center;font:900 clamp(62px,12vw,110px)/1 Inter,system-ui;letter-spacing:12px;outline:none;text-shadow:0 0 30px rgba(96,165,250,.55);caret-color:#60a5fa}.draw-number-input:focus{border-color:#60a5fa;box-shadow:0 0 35px rgba(37,99,235,.25)}.draw-number-input::placeholder{color:#334155}.participant-hint{min-height:24px;margin-top:10px;color:#64748b;font-size:13px;font-weight:700}
.draw-actions{display:flex;justify-content:center;gap:12px;margin-top:24px}.btn-draw-find{min-width:260px;height:58px;border:0;border-radius:16px;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;font:800 15px Inter,system-ui;box-shadow:0 12px 25px rgba(37,99,235,.25);cursor:pointer;transition:.2s}.btn-draw-find:hover{transform:translateY(-2px);box-shadow:0 16px 32px rgba(37,99,235,.32)}.btn-draw-clear{width:58px;height:58px;border:1px solid #cbd5e1;border-radius:16px;background:#fff;color:#475569;font-size:22px;cursor:pointer}.draw-help-wrap{text-align:center;margin-top:15px}.draw-help{color:#94a3b8;font-size:11px}.draw-help kbd{padding:4px 7px;border:1px solid #cbd5e1;border-bottom-width:2px;border-radius:6px;background:#f8fafc;color:#475569}
.winner-modal{border-radius:30px!important;border:1px solid #dbeafe!important;box-shadow:0 30px 100px rgba(2,6,23,.32)!important;padding:45px 30px!important}.wm-congrats{font-size:13px;text-transform:uppercase;letter-spacing:3px;font-weight:900;color:#2563eb}.winner-name{font-size:clamp(32px,7vw,60px)!important;line-height:1.05!important;font-weight:900!important;color:#0f172a!important;margin-top:12px}.winner-barangay{margin-top:12px!important;color:#64748b!important;font-size:17px!important;font-weight:700}.winner-actions{margin-top:30px!important;display:flex;flex-wrap:wrap;justify-content:center;gap:10px}
@media(max-width:600px){.container1{width:calc(100% - 16px);margin-top:15px}.draw-panel-inner{padding:22px 16px}.draw-header-area{justify-content:flex-start}.draw-heading{font-size:21px}.draw-number-input{height:125px;letter-spacing:7px}.number-display-bg{padding:12px}.draw-actions{gap:8px}.btn-draw-find{min-width:0;flex:1}.btn-draw-clear{flex:0 0 58px}.winner-actions .btn{width:100%}}
</style>

<div class="container1">
  <div class="draw-panel">
    <div class="draw-panel-inner">

      <div class="draw-header-area">
        <div class="draw-header-icon">&#127904;</div>
        <div>
          <h2 class="draw-heading">Draw Entry</h2>
          <p class="draw-subtitle">Enter ticket number to find winner</p>
        </div>
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

        <div class="winner-name" id="winner_name"></div>

        <div class="winner-barangay" id="winner_purok"></div>

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

document.addEventListener('DOMContentLoaded', function() {
    const drawBtn = document.getElementById('draw_btn');
    const resetBtn = document.getElementById('reset_drawn_number');

    if (drawBtn) {
        drawBtn.addEventListener('click', function() {
            const drawnNumber = document.getElementById('drawn_number').value.trim();

            if (!drawnNumber) {
                showToast('Please enter a number.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('draw_winner', '1');
            formData.append('drawn_number', drawnNumber);

            fetch('draw', { method: 'POST', body: formData })
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
    const barangay = (winner.barangay || '').trim();
    const purok = (winner.purok || '').trim();
    const locationText = barangay !== '' ? barangay : (purok !== '' ? 'Purok ' + purok : '');
    document.getElementById('winner_purok').textContent = locationText;

    document.getElementById('winnerModal').classList.add('show');
    if (typeof startConfetti === 'function') startConfetti();
}

function confirmWinner(winner) {
    const formData = new FormData();
    formData.append('confirm_winner', '1');
    formData.append('participant_id', winner.participant_id);
    formData.append('number', winner.number);
    formData.append('name', winner.name);
    formData.append('barangay', winner.barangay || '');

    fetch('draw', { method: 'POST', body: formData })
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

    fetch('draw', { method: 'POST', body: formData })
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
