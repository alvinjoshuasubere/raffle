<?php
require_once 'config.php';
if (!isset($current_event_id)) {
    $current_event_id = get_active_event_id($conn);
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

// Fetch all participants for the wheel
$wheel_stmt = $conn->prepare("SELECT id, number, name, barangay FROM participants WHERE event_id = ? AND (status IS NULL OR status = '') ORDER BY CAST(number AS UNSIGNED) ASC");
$wheel_stmt->bind_param("i", $current_event_id);
$wheel_stmt->execute();
$wheel_participants = $wheel_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<?php display_message(); ?>

<div class="container1">
  <!-- WHEEL STAGE -->
  <div class="wheel-hero">
    <div class="wheel-hero-inner">
      <div class="big-wheel-stage">
        <div class="wheel-lights" id="wheelLights"></div>
        <div class="big-wheel-pointer"></div>
        <canvas id="wheelCanvas" width="1000" height="1000"></canvas>
        <div class="big-wheel-hub">
          <span class="hub-icon">🎡</span>
          <span class="hub-text">Raffle</span>
        </div>
      </div>
    </div>
  </div>

  <!-- CONTROL PANEL -->
  <div class="draw-panel">
    <div class="draw-panel-inner">

      <div class="draw-header-area">
        <div class="draw-header-icon">🎡</div>
        <div>
          <h2 class="draw-heading">Spin the Wheel</h2>
          <p class="draw-subtitle">Roll the lucky wheel to pick a winner</p>
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

      <div class="wheel-controls">
        <div class="wheel-status-card">
          <div class="wheel-status-icon" id="wheelStatusIcon">🎡</div>
          <div class="wheel-status-text" id="wheelStatusText">Ready to spin the wheel</div>
        </div>

        <div class="spin-wrap">
          <button type="button" id="spin_btn" class="btn-spin-big">
            <span class="spin-icon-chip">🎡</span>
            <span class="spin-text">Spin the Wheel</span>
            <span class="spin-arrow">→</span>
          </button>
        </div>

        <div class="wheel-count">
          <span id="wheel_participant_count"><?php echo count($wheel_participants); ?></span> ticket<?php echo count($wheel_participants) === 1 ? '' : 's'; ?> on the wheel
        </div>

        <div class="wheel-last-wrap">
          <span class="wheel-last" id="wheel_last_winner">Last winner: —</span>
        </div>
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
const WHEEL_DATA = <?php echo json_encode($wheel_participants); ?>;

let currentWinner = null;
let wheelAngle = 0;
let wheelSpinning = false;
let winningSegmentIndex = null;

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
    const spinBtn = document.getElementById('spin_btn');
    const prizeSelect = document.getElementById('prize_select');

    if (prizeSelect && prizeSelect.options.length > 1) {
        prizeSelect.selectedIndex = 1;
    }

    if (spinBtn) {
        spinBtn.addEventListener('click', spinWheel);
    }
    buildWheelLights();
    drawWheel();
});

/* ===== WHEEL ===== */
const WHEEL_COLORS = ['#ec4899', '#8b5cf6', '#6366f1', '#0ea5e9', '#14b8a6', '#22c55e', '#f59e0b', '#ef4444', '#a855f7', '#06b6d4', '#84cc16', '#f97316'];

function shadeHex(hex, pct) {
    const num = parseInt(hex.replace('#', ''), 16);
    let r = (num >> 16) & 255, g = (num >> 8) & 255, b = num & 255;
    r = Math.max(0, Math.min(255, Math.round(r * (1 + pct))));
    g = Math.max(0, Math.min(255, Math.round(g * (1 + pct))));
    b = Math.max(0, Math.min(255, Math.round(b * (1 + pct))));
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

function wheelFontSize(n) {
    if (n <= 6) return 78;
    if (n <= 12) return 62;
    if (n <= 20) return 48;
    if (n <= 36) return 34;
    if (n <= 60) return 24;
    return 17;
}

function buildWheelLights() {
    const container = document.getElementById('wheelLights');
    if (!container || container.childElementCount > 0) return;
    const count = 30;
    for (let i = 0; i < count; i++) {
        const dot = document.createElement('div');
        dot.className = 'wheel-light-dot';
        dot.style.transform = 'rotate(' + (((360 / count) * i) + (360 / count) / 2) + 'deg) translateY(calc(var(--ring-r) * -1))';
        dot.style.animationDelay = ((i * 45) % 800) + 'ms';
        container.appendChild(dot);
    }
}

function roundRectPath(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

function drawWheel() {
    const canvas = document.getElementById('wheelCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const size = canvas.width;
    const cx = size / 2, cy = size / 2;
    const R = size / 2 - 28;
    const innerR = 162;
    const n = WHEEL_DATA.length;
    ctx.clearRect(0, 0, size, size);

    if (n === 0) {
        ctx.beginPath();
        ctx.arc(cx, cy, R, 0, Math.PI * 2);
        ctx.fillStyle = '#fdf2f8';
        ctx.fill();
        ctx.strokeStyle = '#f9a8d4';
        ctx.lineWidth = 8;
        ctx.stroke();
        ctx.fillStyle = '#9ca3af';
        ctx.font = 'bold 46px "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('No tickets yet', cx, cy - 12);
        ctx.font = '28px "Segoe UI", sans-serif';
        ctx.fillText('Upload participants first', cx, cy + 48);
        return;
    }

    const arc = (2 * Math.PI) / n;
    const fontSize = wheelFontSize(n);

    // metal rim
    const rim = ctx.createLinearGradient(0, cy - R - 26, 0, cy + R + 26);
    rim.addColorStop(0, '#f8fafc');
    rim.addColorStop(0.2, '#cbd5e1');
    rim.addColorStop(0.5, '#94a3b8');
    rim.addColorStop(0.82, '#64748b');
    rim.addColorStop(1, '#334155');
    ctx.beginPath();
    ctx.arc(cx, cy, R + 26, 0, Math.PI * 2);
    ctx.fillStyle = rim;
    ctx.fill();
    ctx.beginPath();
    ctx.arc(cx, cy, R + 26, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(236,73,153,0.45)';
    ctx.lineWidth = 2;
    ctx.stroke();

    // wheel face base
    ctx.beginPath();
    ctx.arc(cx, cy, R, 0, Math.PI * 2);
    ctx.fillStyle = '#ffffff';
    ctx.fill();

    for (let i = 0; i < n; i++) {
        const start = wheelAngle + i * arc;
        const end = start + arc;
        const isWinner = winningSegmentIndex === i && !wheelSpinning;

        let color = WHEEL_COLORS[i % WHEEL_COLORS.length];
        if (i % 2 === 1) color = shadeHex(color, -0.18);
        if (isWinner) color = shadeHex(color, 0.3);

        ctx.beginPath();
        ctx.arc(cx, cy, innerR, start, end);
        ctx.arc(cx, cy, R, end, start, true);
        ctx.closePath();
        ctx.fillStyle = color;
        ctx.fill();

        // subtle 3D sheen across the face
        const sheen = ctx.createRadialGradient(cx, cy, innerR, cx, cy, R);
        sheen.addColorStop(0, 'rgba(255,255,255,0.22)');
        sheen.addColorStop(0.55, 'rgba(255,255,255,0.05)');
        sheen.addColorStop(0.8, 'rgba(255,255,255,0)');
        sheen.addColorStop(1, 'rgba(0,0,0,0.16)');
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, start, end);
        ctx.arc(cx, cy, R, end, start, true);
        ctx.closePath();
        ctx.fillStyle = sheen;
        ctx.fill();

        // engraved separator
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(start);
        ctx.beginPath();
        ctx.moveTo(innerR, 0);
        ctx.lineTo(R + 2, 0);
        ctx.strokeStyle = 'rgba(0,0,0,0.16)';
        ctx.lineWidth = 3;
        ctx.stroke();
        ctx.strokeStyle = 'rgba(255,255,255,0.55)';
        ctx.lineWidth = 1.5;
        ctx.stroke();
        ctx.restore();

        // winner glow outline
        if (isWinner) {
            ctx.save();
            ctx.shadowColor = 'rgba(245,158,11,0.95)';
            ctx.shadowBlur = 40;
            ctx.beginPath();
            ctx.arc(cx, cy, innerR, start, end);
            ctx.arc(cx, cy, R, end, start, true);
            ctx.closePath();
            ctx.strokeStyle = '#fbbf24';
            ctx.lineWidth = 5;
            ctx.stroke();
            ctx.restore();
        }

        // ticket number label chip
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(start + arc / 2);
        const text = String(WHEEL_DATA[i].number);
        ctx.font = 'bold ' + fontSize + 'px "Segoe UI", sans-serif';
        if (fontSize >= 34) {
            const tw = ctx.measureText(text).width;
            const chipW = tw + 28;
            const chipH = fontSize + 20;
            const chipX = R - 24 - chipW;
            const chipY = -chipH / 2;
            roundRectPath(ctx, chipX, chipY, chipW, chipH, chipH / 2);
            ctx.fillStyle = isWinner ? 'rgba(255, 205, 90, 0.96)' : 'rgba(255, 255, 255, 0.92)';
            ctx.shadowColor = 'rgba(0, 0, 0, 0.35)';
            ctx.shadowBlur = 8;
            ctx.shadowOffsetY = 3;
            ctx.fill();
            ctx.shadowBlur = 0;
            ctx.shadowOffsetY = 0;
            ctx.fillStyle = isWinner ? '#92400e' : shadeHex(color, -0.35);
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, chipX + chipW / 2, chipY + chipH / 2 + 1);
        } else {
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = isWinner ? '#fff3cd' : '#ffffff';
            ctx.shadowColor = 'rgba(0, 0, 0, 0.5)';
            ctx.shadowBlur = 6;
            ctx.shadowOffsetY = 2;
            ctx.fillText(text, R - 20, 0);
        }
        ctx.restore();

        // outer bead
        ctx.beginPath();
        ctx.arc(cx + Math.cos(start) * (R - 10), cy + Math.sin(start) * (R - 10), 5, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255,255,255,0.95)';
        ctx.shadowColor = 'rgba(0,0,0,0.3)';
        ctx.shadowBlur = 4;
        ctx.fill();
        ctx.shadowBlur = 0;
    }

    // hub recess shadow
    ctx.beginPath();
    ctx.arc(cx, cy, innerR, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(0,0,0,0.16)';
    ctx.lineWidth = 6;
    ctx.stroke();

    // clean white ring around the hub
    ctx.beginPath();
    ctx.arc(cx, cy, innerR, 0, Math.PI * 2);
    ctx.strokeStyle = '#ffffff';
    ctx.lineWidth = 5;
    ctx.stroke();
}

function mod2Pi(a) {
    a = a % (2 * Math.PI);
    return a < 0 ? a + 2 * Math.PI : a;
}

function setWheelStatus(icon, text) {
    const iconEl = document.getElementById('wheelStatusIcon');
    const textEl = document.getElementById('wheelStatusText');
    if (iconEl) iconEl.textContent = icon;
    if (textEl) textEl.textContent = text;
}

function spinWheel() {
    if (wheelSpinning) return;
    if (WHEEL_DATA.length === 0) {
        showToast('No participants on the wheel yet.', 'error');
        return;
    }

    const prizeSelect = document.getElementById('prize_select');
    if (!prizeSelect.value || prizeSelect.value === '0') {
        showToast('Please select a prize first.', 'error');
        return;
    }

    wheelSpinning = true;
    winningSegmentIndex = null;
    const spinBtnEl = document.getElementById('spin_btn');
    spinBtnEl.disabled = true;
    spinBtnEl.classList.add('spinning');
    document.getElementById('wheelLights').classList.add('lights-on');
    setWheelStatus('🎡', 'Spinning the wheel...');

    const fullTurns = 8 + Math.floor(Math.random() * 6);
    const extraAngle = Math.random() * 2 * Math.PI;
    const targetAngle = wheelAngle + fullTurns * 2 * Math.PI + extraAngle;
    const startAngle = wheelAngle;
    const duration = 6000;
    const startTime = performance.now();
    const trembleStart = 0.78;

    function frame(now) {
        const t = Math.min((now - startTime) / duration, 1);
        let eased;
        if (t < trembleStart) {
            eased = 1 - Math.pow(1 - (t / trembleStart), 5);
        } else {
            const tt = (t - trembleStart) / (1 - trembleStart);
            const slow = 1 - Math.pow(1 - tt, 3);
            eased = (1 - Math.pow(1 - trembleStart, 5)) + slow * (1 - (1 - Math.pow(1 - trembleStart, 5)));
            const tremble = Math.sin(tt * Math.PI * 12) * (1 - tt) * 0.008;
            eased += tremble;
        }
        wheelAngle = startAngle + (targetAngle - startAngle) * eased;
        drawWheel();
        if (t < 1) {
            requestAnimationFrame(frame);
        } else {
            wheelSpinning = false;
            spinBtnEl.disabled = false;
            spinBtnEl.classList.remove('spinning');
            document.getElementById('wheelLights').classList.remove('lights-on');

            const arc = (2 * Math.PI) / WHEEL_DATA.length;
            const pointer = mod2Pi(-Math.PI / 2 - wheelAngle);
            const idx = Math.floor(pointer / arc) % WHEEL_DATA.length;
            const winner = WHEEL_DATA[idx];

            winningSegmentIndex = idx;
            drawWheel();

            const stageEl = document.querySelector('.big-wheel-stage');
            if (stageEl) {
                stageEl.classList.remove('landed');
                void stageEl.offsetWidth;
                stageEl.classList.add('landed');
            }

            currentWinner = {
                participant_id: winner.id,
                number: winner.number,
                name: winner.name,
                barangay: winner.barangay
            };

            setWheelStatus('🏆', 'Winner: Ticket #' + winner.number);
            const lastEl = document.getElementById('wheel_last_winner');
            if (lastEl) lastEl.textContent = 'Last winner: Ticket #' + winner.number;
            setTimeout(function() { showWinnerModal(currentWinner); }, 800);
        }
    }

    requestAnimationFrame(frame);
}

/* ===== WINNER MODAL ===== */
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

    fetch('wheel.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Winner confirmed successfully!', 'success');

                const idx = WHEEL_DATA.findIndex(w => w.id === winner.participant_id);
                if (idx !== -1) WHEEL_DATA.splice(idx, 1);
                const countEl = document.getElementById('wheel_participant_count');
                if (countEl) countEl.textContent = WHEEL_DATA.length;
                winningSegmentIndex = null;
                drawWheel();
                setWheelStatus('🎯', 'Ready to spin the wheel');
                const lastEl = document.getElementById('wheel_last_winner');
                if (lastEl) lastEl.textContent = 'Last winner: Ticket #' + winner.number;

                document.getElementById('winnerModal').classList.remove('show');
                if (typeof stopConfetti === 'function') stopConfetti();
                currentWinner = null;
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

    fetch('wheel.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');

                const idx = WHEEL_DATA.findIndex(w => w.id === winner.participant_id);
                if (idx !== -1) WHEEL_DATA.splice(idx, 1);
                const countEl = document.getElementById('wheel_participant_count');
                if (countEl) countEl.textContent = WHEEL_DATA.length;
                winningSegmentIndex = null;
                drawWheel();
                setWheelStatus('🎯', 'Ready to spin the wheel');

                document.getElementById('winnerModal').classList.remove('show');
                if (typeof stopConfetti === 'function') stopConfetti();
                currentWinner = null;
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
    });
});

document.getElementById('winnerModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.classList.remove('show');
        if (typeof stopConfetti === 'function') stopConfetti();
        currentWinner = null;
    }
});
</script>
