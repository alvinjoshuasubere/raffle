<?php
require_once 'config.php';
if (!isset($current_event_id)) {
    $current_event_id = get_active_event_id($conn);
}

// Tickets still in the drum: everyone not yet a winner and not removed.
function wheel_pool($conn, $event_id) {
    $stmt = $conn->prepare("SELECT id, number, name, city, barangay, purok FROM participants WHERE event_id = ? AND (status IS NULL OR status = '')");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function wheel_pool_count($conn, $event_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM participants WHERE event_id = ? AND (status IS NULL OR status = '')");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count;
}

// Handle Confirm Winner
if (isset($_POST['confirm_winner'])) {
    $participant_id = intval($_POST['participant_id'] ?? 0);
    if ($participant_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'A valid participant is required to confirm the winner.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        $participant_q = $conn->prepare("SELECT number, name, barangay, status FROM participants WHERE id = ? AND event_id = ? FOR UPDATE");
        $participant_q->bind_param("ii", $participant_id, $current_event_id);
        $participant_q->execute();
        $participant = $participant_q->get_result()->fetch_assoc();
        $participant_q->close();

        if (!$participant || in_array($participant['status'], ['winner', 'removed'], true)) {
            throw new RuntimeException('This participant is no longer available to confirm.');
        }

        $number = (string)($participant['number'] ?? '');
        $name = $participant['name'] ?? '';
        $barangay = $participant['barangay'] ?? '';

        $stmt = $conn->prepare("INSERT INTO winners (event_id, participant_id, number, name, barangay) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $current_event_id, $participant_id, $number, $name, $barangay);
        $stmt->execute();
        $stmt->close();

        $upd_status = $conn->prepare("UPDATE participants SET status = 'winner' WHERE id = ? AND event_id = ? AND (status IS NULL OR status = '')");
        $upd_status->bind_param("ii", $participant_id, $current_event_id);
        $upd_status->execute();
        if ($upd_status->affected_rows !== 1) {
            $upd_status->close();
            throw new RuntimeException('Could not update the participant status.');
        }
        $upd_status->close();

        $conn->commit();

        // Everyone left in the drum has now won: roll straight into a fresh round
        // so the numbers return to the wheel instead of leaving it empty.
        if (wheel_pool_count($conn, $current_event_id) === 0) {
            $reset = reset_event_winners($conn, $current_event_id);
            echo json_encode([
                'success' => true,
                'auto_reset' => true,
                'participants' => wheel_pool($conn, $current_event_id),
                'message' => 'All participants have been drawn! The wheel was reset and ' . $reset['returned'] . ' numbers are back in the machine.'
            ]);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Winner confirmed successfully!']);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Wheel winner confirmation failed: ' . $e->getMessage());
        $message = get_class($e) === RuntimeException::class ? $e->getMessage() : 'Failed to confirm winner. Please try again.';
        echo json_encode(['success' => false, 'message' => $message]);
    }
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
    header('Location: admin?page=wheel');
    exit;
}

$slot_spin_seconds  = max(1, min(60, intval(get_setting($conn, 'slot_spin_seconds', '3'))));
$slot_delay_seconds = max(0, min(120, intval(get_setting($conn, 'slot_modal_delay_seconds', '0'))));

// A previous round may have consumed every ticket (page closed before the draw
// finished, or a reset that never fired). Recover so the wheel is never stuck empty.
$slot_participants = wheel_pool($conn, $current_event_id);
if (count($slot_participants) === 0) {
    $pending = $conn->prepare("SELECT COUNT(*) AS c FROM participants WHERE event_id = ? AND status = 'winner'");
    $pending->bind_param("i", $current_event_id);
    $pending->execute();
    $winner_count = (int)$pending->get_result()->fetch_assoc()['c'];
    $pending->close();

    if ($winner_count > 0) {
        $reset = reset_event_winners($conn, $current_event_id);
        $slot_participants = wheel_pool($conn, $current_event_id);
        set_message('success', "All participants were already winners, so the round reset automatically. {$reset['returned']} numbers are back in the machine.");
    }
}
?>

<?php display_message(); ?>


<div class="container1">
  <div class="wheel-tools">
    <button type="button" id="wheelFullscreenBtn" class="wheel-tool-btn" title="Fullscreen" aria-label="Toggle fullscreen">
      <svg viewBox="0 0 24 24" id="wheelFsEnter"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
      <svg viewBox="0 0 24 24" id="wheelFsExit" style="display:none"><path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3M16 21v-3a2 2 0 0 1 2-2h3"/></svg>
    </button>
    <button type="button" id="wheelSettingsBtn" class="wheel-tool-btn" title="Wheel settings" aria-label="Wheel settings" aria-expanded="false" aria-controls="wheelSettings">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
    </button>
    <form method="POST" class="wheel-settings" id="wheelSettings">
      <input type="hidden" name="save_slot_timing" value="1">
      <h3>Wheel timing</h3>
      <div class="slot-timing-row">
        <label>Spin (sec)
          <input type="number" name="spin_seconds" min="1" max="60" step="1"
                 value="<?php echo $slot_spin_seconds; ?>" required>
        </label>
        <label>Countdown
          <input type="number" name="modal_delay_seconds" min="0" max="120" step="1"
                 value="<?php echo $slot_delay_seconds; ?>" required>
        </label>
      </div>
      <button type="submit" class="btn btn-primary slot-timing-save">Save</button>
      <div class="slot-timing-hint">Spin cannot be 0. Countdown 0 shows the winner immediately.</div>
    </form>
  </div>

  <!-- SAME SLOT MACHINE UI AS NUMBERS TAB -->
  <div class="slot-hero">
    <div class="slot-machine-wrap">
      <div class="slot-machine" id="wheelMachine">

        <div class="slot-topper">
          <span class="star">&#9733;</span><span class="star">&#9733;</span><span class="star">&#9733;</span>
          <span class="topper-text">LUCKY NUMBER</span>
          <span class="star">&#9733;</span><span class="star">&#9733;</span><span class="star">&#9733;</span>
        </div>

        <div class="slot-cabinet">
          <div class="slot-lights" id="slotLights"></div>
          <div class="slot-window" aria-label="Winning ticket number display">
            <div class="slot-reels" id="slotReels"></div>
            <div class="slot-shade"></div>
            <div class="slot-frame"></div>
          </div>
        </div>
      </div>

      <button type="button" id="spin_btn" class="slot-lever" aria-label="Pull lever to spin">
        <span class="lever-pivot"><span class="lever-arm"></span><span class="lever-knob"></span></span>
        <span class="lever-label">SPIN</span>
      </button>
    </div>
    <div class="slot-countdown" id="slotStatus">READY TO SPIN</div>

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
</div>

<script>
const SLOT_DATA = <?php echo json_encode($slot_participants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

let currentWinner = null;
let slotSpinning = false;

/* ===== SLOT MACHINE ===== */
const MAX_STRIP_CELLS = 96;
const SPIN_DURATION = Math.max(500, <?php echo $slot_spin_seconds * 1000; ?> * 0.55); // faster wheel spin while keeping timing control
const FAST_PHASE = 0.82;        // reach top speed quickly, then snap to the stop
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

function ticketDigits(participant) {
    return String(participant.number ?? '').trim();
}

function maxTicketDigits() {
    return SLOT_DATA.reduce((max, participant) => Math.max(max, ticketDigits(participant).length), 1);
}

/* Build one vertical reel for each character in the ticket number. */
function buildReel(winner) {
    const reels = el('slotReels');
    if (SLOT_DATA.length === 0) {
        reels.replaceChildren();
        reels.style.gridTemplateColumns = 'repeat(1, minmax(0, 1fr))';
        return -1;
    }

    const digitCount = Math.max(maxTicketDigits(), winner ? ticketDigits(winner).length : 1);
    const cellCount = MAX_STRIP_CELLS - (MAX_STRIP_CELLS % 10);
    const landIndex = cellCount - 2;
    const columns = document.createDocumentFragment();
    const winnerDigits = winner ? ticketDigits(winner).padStart(digitCount, ' ') : '';
    const symbolsByColumn = Array.from({ length: digitCount }, (_, columnIndex) => {
        const symbols = new Set(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']);
        SLOT_DATA.forEach(participant => {
            symbols.add(ticketDigits(participant).padStart(digitCount, ' ')[columnIndex]);
        });
        return Array.from(symbols);
    });

    for (let columnIndex = 0; columnIndex < digitCount; columnIndex++) {
        const column = document.createElement('div');
        column.className = 'slot-reel-column';
        const reel = document.createElement('div');
        reel.className = 'slot-reel';
        const cells = document.createDocumentFragment();

        for (let i = 0; i < cellCount; i++) {
            const symbol = winner && i === landIndex
                ? winnerDigits[columnIndex]
                : symbolsByColumn[columnIndex][Math.floor(Math.random() * symbolsByColumn[columnIndex].length)];
            const cell = document.createElement('div');
            cell.className = 'slot-cell';
            cell.textContent = symbol === ' ' ? '\u00a0' : symbol;
            if (i === landIndex) cell.dataset.land = '1';
            cells.appendChild(cell);
        }

        reel.appendChild(cells);
        column.appendChild(reel);
        columns.appendChild(column);
    }

    reels.style.gridTemplateColumns = 'repeat(' + digitCount + ', minmax(0, 1fr))';
    reels.replaceChildren(columns);
    return landIndex;
}

function cellHeight(reel) {
    const cell = reel.firstElementChild;
    return cell ? cell.offsetHeight : 120;
}

function resetReels() {
    el('slotReels').querySelectorAll('.slot-reel').forEach(reel => {
        reel.style.transform = 'translate3d(0, -' + cellHeight(reel) + 'px, 0)';
    });
}

// Swap the whole drum contents (used after a round reset) and rebuild the reel
function setSlotData(list) {
    const rows = Array.isArray(list) ? list : [];
    SLOT_DATA.length = 0;
    rows.forEach(p => SLOT_DATA.push(p));

    buildReel(null);
    resetReels();

    const countEl = document.getElementById('wheel_participant_count');
    if (countEl) countEl.textContent = SLOT_DATA.length;
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
    const lever = spinBtnEl;
    lever.classList.add('pull');
    window.setTimeout(() => lever.classList.remove('pull'), 240);
    setSlotStatus('\u{1F3B0}', 'ROLLING...');


    const winner = SLOT_DATA[Math.floor(Math.random() * SLOT_DATA.length)];
    const landIndex = buildReel(winner);

    const reels = Array.from(el('slotReels').querySelectorAll('.slot-reel'));
    const reelTargets = reels.map(reel => ({
        reel,
        target: -(landIndex - 1) * cellHeight(reel)
    }));
    const start = performance.now();
    const REEL_STOP_DELAY = 140;

    function frame(now) {
        const elapsed = now - start;
        const lastReelDoneAt = SPIN_DURATION + REEL_STOP_DELAY * (reelTargets.length - 1) + BOUNCE_MS;

        reelTargets.forEach(({ reel, target }, index) => {
            const reelElapsed = elapsed - REEL_STOP_DELAY * index;
            const t = Math.max(0, Math.min(reelElapsed / SPIN_DURATION, 1));
            const eased = t < FAST_PHASE
                ? (t / FAST_PHASE) * 0.90
                : 0.90 + (1 - Math.pow(1 - ((t - FAST_PHASE) / (1 - FAST_PHASE)), 2)) * 0.10;
            const bounceProgress = (reelElapsed - SPIN_DURATION) / BOUNCE_MS;
            const extra = bounceProgress >= 0 && bounceProgress <= 1
                ? -OVERSHOOT_PX * Math.exp(-4.5 * bounceProgress) * Math.cos(10 * bounceProgress)
                : 0;

            reel.style.transform = 'translate3d(0, ' + (target * eased + extra) + 'px, 0)';
            reel.classList.toggle('fast', reelElapsed >= 0 && reelElapsed < SPIN_DURATION * FAST_PHASE);
        });

        if (elapsed < lastReelDoneAt) {
            requestAnimationFrame(frame);
        } else {
            slotSpinning = false;
            spinBtnEl.disabled = false;
            spinBtnEl.classList.remove('spinning');
            if (lever) lever.classList.remove('pull');
            reelTargets.forEach(({ reel }) => reel.classList.remove('fast'));

            el('slotReels').querySelectorAll('[data-land]').forEach(cell => cell.classList.add('is-winner'));

            currentWinner = {
                participant_id: winner.id,
                number: winner.number,
                name: winner.name,
                city: winner.city || '',
                barangay: winner.barangay || '',
                purok: winner.purok || ''
            };

            const statusEl = el('slotStatus');
    if (statusEl) statusEl.textContent = 'WINNER SELECTED';

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
    resetReels();
    const spinBtn = el('spin_btn');
    if (spinBtn) spinBtn.addEventListener('click', spinSlot);

    const fullscreenBtn = el('wheelFullscreenBtn');
    const wheelStage = document.querySelector('.container1');
    if (fullscreenBtn && wheelStage) {
        fullscreenBtn.addEventListener('click', function() {
            if (!document.fullscreenElement) {
                (wheelStage.requestFullscreen ? wheelStage.requestFullscreen() : Promise.reject())
                    .catch(() => showToast('Fullscreen is not supported here.', 'error'));
            } else {
                document.exitFullscreen().catch(() => showToast('Could not exit fullscreen.', 'error'));
            }
        });
        document.addEventListener('fullscreenchange', function() {
            const isFullscreen = document.fullscreenElement === wheelStage;
            const enterIcon = el('wheelFsEnter');
            const exitIcon = el('wheelFsExit');
            if (enterIcon) enterIcon.style.display = isFullscreen ? 'none' : '';
            if (exitIcon) exitIcon.style.display = isFullscreen ? '' : 'none';

            const confettiCanvas = el('confetti-canvas');
            if (confettiCanvas) {
                if (isFullscreen) {
                    wheelStage.appendChild(confettiCanvas);
                } else {
                    document.body.appendChild(confettiCanvas);
                }
            }
        });
    }

    const settingsBtn = el('wheelSettingsBtn');
    const settingsPanel = el('wheelSettings');
    if (settingsBtn && settingsPanel) {
        settingsBtn.addEventListener('click', function(event) {
            event.stopPropagation();
            const isOpen = settingsPanel.classList.toggle('show');
            settingsBtn.setAttribute('aria-expanded', String(isOpen));
        });
        settingsPanel.addEventListener('click', event => event.stopPropagation());
        document.addEventListener('click', function() {
            settingsPanel.classList.remove('show');
            settingsBtn.setAttribute('aria-expanded', 'false');
        });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                settingsPanel.classList.remove('show');
                settingsBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }
});

/* ===== WINNER MODAL ===== */
function showWinnerModal(winner) {
    document.getElementById('winner_name').textContent = winner.name;
    const city = (winner.city || '').trim();
    const barangay = (winner.barangay || '').trim();
    const purok = (winner.purok || '').trim();
    let location = '';
    if (city !== '' && barangay !== '') {
        location = city + ', BRGY. ' + barangay;
    } else if (city !== '') {
        location = city;
    } else if (barangay !== '') {
        location = 'BRGY. ' + barangay;
    } else if (purok !== '') {
        location = 'PUROK ' + purok;
    }
    document.getElementById('winner_purok').textContent = location;
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

    fetch('wheel', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');

                if (data.auto_reset) {
                    setSlotData(data.participants);
                    setSlotStatus('\u{1F504}', 'Round reset - all numbers back');
                    closeModal();
                    return;
                }

                const idx = SLOT_DATA.findIndex(w => w.id === winner.participant_id);
                if (idx !== -1) SLOT_DATA.splice(idx, 1);
                const countEl = document.getElementById('wheel_participant_count');
                if (countEl) countEl.textContent = SLOT_DATA.length;

                buildReel(null);
                resetReels();
                const statusEl = el('slotStatus');
                if (statusEl) statusEl.textContent = 'READY TO SPIN';
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

    fetch('wheel', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');

                const idx = SLOT_DATA.findIndex(w => w.id === winner.participant_id);
                if (idx !== -1) SLOT_DATA.splice(idx, 1);
                const countEl = document.getElementById('wheel_participant_count');
                if (countEl) countEl.textContent = SLOT_DATA.length;

                buildReel(null);
                resetReels();
                const statusEl = el('slotStatus');
                if (statusEl) statusEl.textContent = 'READY TO SPIN';

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
