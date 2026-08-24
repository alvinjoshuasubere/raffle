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
    $purok = sanitize_input($_POST['purok'] ?? '');

    $stmt = $conn->prepare("INSERT INTO winners (event_id, participant_id, number, name, barangay) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $current_event_id, $participant_id, $number, $name, $purok);

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

// Handle Slot Timing settings
if (isset($_POST['save_slot_timing'])) {
    $spin_secs  = max(1, min(60, intval($_POST['spin_seconds'] ?? 3)));
    $delay_secs = max(0, min(120, intval($_POST['modal_delay_seconds'] ?? 0)));
    set_setting($conn, 'slot_spin_seconds', (string)$spin_secs);
    set_setting($conn, 'slot_modal_delay_seconds', (string)$delay_secs);
    set_message('success', "Slot timing saved: {$spin_secs}s spin, {$delay_secs}s before modal.");
    header('Location: admin.php?page=wheel');
    exit;
}

$slot_spin_seconds  = max(1, min(60, intval(get_setting($conn, 'slot_spin_seconds', '3'))));
$slot_delay_seconds = max(0, min(120, intval(get_setting($conn, 'slot_modal_delay_seconds', '0'))));

// Fetch all participants for the slot machine
$slot_stmt = $conn->prepare("SELECT id, number, name, purok FROM participants WHERE event_id = ? AND (status IS NULL OR status = '') ORDER BY CAST(number AS UNSIGNED) ASC");
$slot_stmt->bind_param("i", $current_event_id);
$slot_stmt->execute();
$slot_participants = $slot_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<?php display_message(); ?>

<div class="container1">
  <!-- SLOT MACHINE STAGE -->
  <div class="slot-hero">
    <div class="slot-machine" id="slotMachine">

      <div class="slot-topper">
        <span class="star">&#9733;</span><span class="star">&#9733;</span><span class="star">&#9733;</span>
        <span class="topper-text">RAFFLE</span>
        <span class="star">&#9733;</span><span class="star">&#9733;</span><span class="star">&#9733;</span>
      </div>

      <div class="slot-cabinet">
        <div class="slot-lights" id="slotLights"></div>
        <div class="slot-window">
          <div class="slot-reel" id="slotReel"><!-- cells injected by JS --></div>
          <div class="slot-shade"></div>
          <div class="slot-frame"></div>
        </div>
      </div>

      <div class="slot-plate">LUCKY NUMBER</div>
    </div>
  </div>

  <!-- CONTROL PANEL -->
  <div class="draw-panel">
    <div class="draw-panel-inner">

      <div class="draw-header-area">
        <div class="draw-header-icon">&#127904;</div>
        <div>
          <h2 class="draw-heading">Spin the Slot</h2>
          <p class="draw-subtitle">Roll the numbers to pick a winner</p>
        </div>
      </div>

      <div class="wheel-controls">
        <!-- <div class="wheel-status-card">
          <div class="wheel-status-icon" id="wheelStatusIcon">&#127904;</div>
          <div class="wheel-status-text" id="wheelStatusText">Ready to spin</div>
        </div> -->

        <div class="spin-wrap">
          <button type="button" id="spin_btn" class="btn-spin-big">
            <span class="spin-icon-chip">&#127904;</span>
            <span class="spin-text">SPIN</span>
            <span class="spin-arrow">&rarr;</span>
          </button>
        </div>

        <form method="POST" class="slot-timing" id="slotTimingForm">
          <input type="hidden" name="save_slot_timing" value="1">
          <div class="slot-timing-title">Slot Timing</div>
          <div class="slot-timing-row">
            <label>Spin seconds
              <input type="number" name="spin_seconds" min="1" max="60" step="1"
                     value="<?php echo $slot_spin_seconds; ?>" required>
            </label>
            <label>Countdown
              <input type="number" name="modal_delay_seconds" min="0" max="120" step="1"
                     value="<?php echo $slot_delay_seconds; ?>" required>
            </label>
          </div>
          <button type="submit" class="btn btn-primary slot-timing-save">Save Timing</button>
          <div class="slot-timing-hint">Spin cannot be 0. Modal delay 0 = show immediately.</div>
        </form>

        <div class="slot-countdown" id="slotCountdown"></div>

        <!-- <div class="wheel-count">
          <span id="wheel_participant_count"><?php echo count($slot_participants); ?></span> ticket<?php echo count($slot_participants) === 1 ? '' : 's'; ?> in the machine
        </div>

        <div class="wheel-last-wrap">
          <span class="wheel-last" id="wheel_last_winner">Last winner: &mdash;</span>
        </div> -->
      </div>

    </div>
  </div>
</div>

<!-- Full-page intense countdown overlay -->
<div class="page-count-overlay" id="slotCountOverlay">
  <div class="pc-vignette"></div>
  <span class="pc-num" id="slotCountNum"></span>
  <span class="pc-label">SHOWING WINNER...</span>
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
const SLOT_DATA = <?php echo json_encode($slot_participants); ?>;

let currentWinner = null;
let slotSpinning = false;

/* ===== SLOT MACHINE ===== */
const REEL_PASSES = 20;         // heavy strip churn = real slot-machine speed
const SPIN_DURATION = <?php echo $slot_spin_seconds * 1000; ?>;   // admin-configurable spin seconds
const FAST_PHASE = 0.90;        // sustained top speed until 90% of the spin
const BOUNCE_MS = 240;          // mechanical kick-back after hitting the stop
const OVERSHOOT_PX = 22;        // how far past the stop it kicks before settling
const MODAL_DELAY_MS = <?php echo $slot_delay_seconds * 1000; ?>; // admin-configurable delay before modal

function el(id){ return document.getElementById(id); }

function buildSlotLights() {
    const container = el('slotLights');
    if (!container || container.childElementCount > 0) return;
    for (let i = 0; i < 22; i++) {
        const dot = document.createElement('div');
        dot.className = 'slot-light-dot';
        dot.style.animationDelay = ((i * 70) % 900) + 'ms';
        container.appendChild(dot);
    }
}

function shuffled(arr) {
    const a = arr.slice();
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

/* Build the vertical strip: N shuffled passes; winner forced into the final landing cell */
function buildReel(winnerId) {
    const reel = el('slotReel');
    reel.innerHTML = '';

    let cells = [];
    // More passes = faster roll; cap total cells so very large events stay smooth
    const passes = Math.min(REEL_PASSES, Math.max(6, Math.ceil(3000 / SLOT_DATA.length)));
    for (let p = 0; p < passes; p++) cells = cells.concat(shuffled(SLOT_DATA));

    // Landing cell: second-to-last so there is still motion after it visually settles
    const landIndex = cells.length - 2;
    if (winnerId != null && cells.length > 0) {
        const wIdx = cells.findIndex(c => c.id === winnerId);
        if (wIdx !== -1 && wIdx !== landIndex) {
            [cells[wIdx], cells[landIndex]] = [cells[landIndex], cells[wIdx]];
        }
    }

    cells.forEach((c, i) => {
        const div = document.createElement('div');
        div.className = 'slot-cell';
        div.textContent = String(c.number);
        div.dataset.id = c.id;
        if (i === landIndex) div.dataset.land = '1';
        reel.appendChild(div);
    });
    return landIndex;
}

function cellHeight() {
    const cell = document.querySelector('.slot-cell');
    return cell ? cell.offsetHeight : 120;
}

function setSlotStatus(icon, text) {
    const iconEl = el('wheelStatusIcon'), textEl = el('wheelStatusText');
    if (iconEl) iconEl.textContent = icon;
    if (textEl) textEl.textContent = text;
}

function spinSlot() {
    if (slotSpinning) return;
    if (SLOT_DATA.length === 0) {
        showToast('No participants in the machine yet.', 'error');
        return;
    }

    slotSpinning = true;
    const spinBtnEl = el('spin_btn');
    spinBtnEl.disabled = true;
    spinBtnEl.classList.add('spinning');
    el('slotLights').classList.add('lights-on');
    setSlotStatus('\u{1F3B0}', 'Rolling the numbers...');

    const winner = SLOT_DATA[Math.floor(Math.random() * SLOT_DATA.length)];
    const landIndex = buildReel(winner.id);

    const reel = el('slotReel');
    reel.style.transform = 'translateY(0)';
    void reel.offsetHeight; // force layout before animating

    const ch = cellHeight();
    const target = -(landIndex - 1) * ch;   // center the landing cell in the window
    const start = performance.now();

    function frame(now) {
        const t = Math.min((now - start) / SPIN_DURATION, 1);
        let eased, extra = 0;
        if (t < FAST_PHASE) {
            eased = (t / FAST_PHASE) * 0.90;                       // constant blur-fast roll
        } else {
            const u = (t - FAST_PHASE) / (1 - FAST_PHASE);
            eased = 0.90 + (1 - Math.pow(1 - u, 2)) * 0.10;        // hard snap to the stop
        }

        // Mechanical kick-back once the reel hits the stop
        const tb = (now - start - SPIN_DURATION) / BOUNCE_MS;
        if (tb >= 0) {
            extra = -OVERSHOOT_PX * Math.exp(-4.5 * tb) * Math.cos(10 * tb);
        }

        reel.style.transform = 'translateY(' + (target * eased + extra) + 'px)';
        reel.classList.toggle('fast', t < FAST_PHASE);

        if (tb < 0 || t < 1 || (now - start) < SPIN_DURATION + BOUNCE_MS) {
            requestAnimationFrame(frame);
        } else {
            slotSpinning = false;
            spinBtnEl.disabled = false;
            spinBtnEl.classList.remove('spinning');
            reel.classList.remove('fast');

            const landed = reel.querySelector('[data-land]');
            if (landed) landed.classList.add('is-winner');

            currentWinner = {
                participant_id: winner.id,
                number: winner.number,
                name: winner.name,
                purok: winner.purok
            };

            setSlotStatus('\u{1F3C6}', 'Winner: ' + winner.name);
            const lastEl = el('wheel_last_winner');
            if (lastEl) lastEl.textContent = 'Last winner: ' + winner.name;

            // Big intense countdown overlay on the machine before showing the winner modal
            if (MODAL_DELAY_MS > 0) {
                const ovEl = el('slotCountOverlay');
                const numEl = el('slotCountNum');
                let remaining = Math.round(MODAL_DELAY_MS / 1000);
                const total = remaining;
                ovEl.classList.remove('mid', 'final');
                numEl.textContent = remaining;
                ovEl.classList.add('show');
                numEl.classList.remove('tick'); void numEl.offsetWidth; numEl.classList.add('tick');
                const tick = setInterval(function(){
                    remaining--;
                    if (remaining > 0) {
                        if (remaining <= Math.ceil(total / 2)) ovEl.classList.add('mid');
                        numEl.textContent = remaining;
                        numEl.classList.remove('tick'); void numEl.offsetWidth; numEl.classList.add('tick');
                    } else {
                        clearInterval(tick);
                        ovEl.classList.add('final');
                        numEl.textContent = '';
                        setTimeout(function(){
                            ovEl.classList.remove('show', 'mid', 'final');
                            showWinnerModal(currentWinner);
                        }, 650);
                    }
                }, 1000);
            } else {
                showWinnerModal(currentWinner);
            }
        }
    }
    requestAnimationFrame(frame);
}

document.addEventListener('DOMContentLoaded', function() {
    buildSlotLights();
    buildReel(null);
    const spinBtn = el('spin_btn');
    if (spinBtn) spinBtn.addEventListener('click', spinSlot);
});

/* ===== WINNER MODAL ===== */
function showWinnerModal(winner) {
    document.getElementById('winner_name').textContent = winner.name;
    document.getElementById('winner_purok').textContent = 'Purok ' + winner.purok;
    document.getElementById('winnerModal').classList.add('show');
    if (typeof startConfetti === 'function') startConfetti();
}

function confirmWinner(winner) {
    const formData = new FormData();
    formData.append('confirm_winner', '1');
    formData.append('participant_id', winner.participant_id);
    formData.append('number', winner.number);
    formData.append('name', winner.name);
    formData.append('purok', winner.purok);

    fetch('wheel.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Winner confirmed successfully!', 'success');

                const idx = SLOT_DATA.findIndex(w => w.id === winner.participant_id);
                if (idx !== -1) SLOT_DATA.splice(idx, 1);
                const countEl = document.getElementById('wheel_participant_count');
                if (countEl) countEl.textContent = SLOT_DATA.length;

                const reel = el('slotReel');
                reel.style.transform = 'translateY(0)';
                buildReel(null);
                setSlotStatus('\u{1F3AF}', 'Ready to spin');
                const lastEl = document.getElementById('wheel_last_winner');
                if (lastEl) lastEl.textContent = 'Last winner: ' + winner.name;

                closeModal();
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

                const idx = SLOT_DATA.findIndex(w => w.id === winner.participant_id);
                if (idx !== -1) SLOT_DATA.splice(idx, 1);
                const countEl = document.getElementById('wheel_participant_count');
                if (countEl) countEl.textContent = SLOT_DATA.length;

                const reel = el('slotReel');
                reel.style.transform = 'translateY(0)';
                buildReel(null);
                setSlotStatus('\u{1F3AF}', 'Ready to spin');

                closeModal();
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

function closeModal(){
    document.getElementById('winnerModal').classList.remove('show');
    if (typeof stopConfetti === 'function') stopConfetti();
    currentWinner = null;
}

document.querySelectorAll('.close, .close-modal').forEach(element => {
    element.addEventListener('click', closeModal);
});

document.getElementById('winnerModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
