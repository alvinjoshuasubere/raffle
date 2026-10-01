<?php
require_once 'config.php';
if (!isset($current_event_id)) {
    $current_event_id = get_active_event_id($conn);
}

// Tickets still in the drum: everyone not yet a winner and not removed.
function wheel_pool($conn, $event_id) {
    $stmt = $conn->prepare("SELECT id, number, name, barangay, purok FROM participants WHERE event_id = ? AND (status IS NULL OR status = '') ORDER BY CAST(number AS UNSIGNED) ASC");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Handle Confirm Winner
if (isset($_POST['confirm_winner'])) {
    $participant_id = intval($_POST['participant_id']);
    $number = intval($_POST['number']);
    $name = sanitize_input($_POST['name']);
    $barangay = sanitize_input($_POST['barangay'] ?? '');

    $stmt = $conn->prepare("INSERT INTO winners (event_id, participant_id, number, name, barangay) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $current_event_id, $participant_id, $number, $name, $barangay);

    if ($stmt->execute()) {
        $upd_status = $conn->prepare("UPDATE participants SET status = 'winner' WHERE id = ? AND event_id = ?");
        $upd_status->bind_param("ii", $participant_id, $current_event_id);
        $upd_status->execute();
        $upd_status->close();
        $stmt->close();

        // Everyone left in the drum has now won: roll straight into a fresh round
        // so the numbers return to the wheel instead of leaving it empty.
        if (count(wheel_pool($conn, $current_event_id)) === 0) {
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
<style>
/* ===== WHEEL TAB — PURE SLOT MACHINE ===== */
.wheel-page{min-height:calc(100vh - 80px);display:flex;align-items:center;justify-content:center;padding:24px 16px 50px}
.slot-machine{width:min(720px,96vw);margin:0 auto;filter:drop-shadow(0 35px 55px rgba(15,23,42,.30))}
.slot-topper{position:relative;min-height:76px;border-radius:28px 28px 12px 12px;background:linear-gradient(180deg,#e11d48 0%,#be123c 55%,#881337 100%);color:#fff;display:flex;align-items:center;justify-content:center;gap:14px;font-weight:1000;letter-spacing:5px;font-size:clamp(20px,5vw,32px);border:3px solid #fda4af;box-shadow:inset 0 2px 0 rgba(255,255,255,.35),0 8px 0 #4c0519,0 18px 30px rgba(76,5,25,.28)}
.slot-topper .star{font-size:16px;color:#fde68a;text-shadow:0 0 12px rgba(253,230,138,.9);animation:rafflePulse 1.2s infinite}
.slot-topper .star:nth-child(2n){animation-delay:.2s}.slot-topper .star:nth-child(3n){animation-delay:.4s}
.slot-cabinet{position:relative;padding:30px 28px 38px;background:linear-gradient(145deg,#fff1f2,#ffe4e6 55%,#fecdd3);border:4px solid #9f1239;border-top:0;border-radius:12px 12px 34px 34px;box-shadow:inset 0 0 0 2px rgba(255,255,255,.7),0 10px 0 #4c0519}
.slot-cabinet:before,.slot-cabinet:after{content:"";position:absolute;top:18px;bottom:18px;width:10px;border-radius:999px;background:linear-gradient(#fbbf24,#fde68a,#f59e0b);box-shadow:0 0 12px rgba(245,158,11,.6)}
.slot-cabinet:before{left:10px}.slot-cabinet:after{right:10px}
.slot-window{height:230px;position:relative;overflow:hidden;border-radius:22px;background:#09090b;border:12px solid #3f3f46;box-shadow:inset 0 0 45px rgba(0,0,0,.95),0 0 0 4px #fda4af,0 8px 20px rgba(76,5,25,.25)}
.slot-window:before,.slot-window:after{content:"";position:absolute;z-index:6;left:0;right:0;height:28px;pointer-events:none}
.slot-window:before{top:0;background:linear-gradient(#000,transparent)}
.slot-window:after{bottom:0;background:linear-gradient(transparent,#000)}
.slot-reel{will-change:transform;transform:translate3d(0,0,0);backface-visibility:hidden}
.slot-cell{height:110px;display:flex;align-items:center;justify-content:center;color:#fff;font:900 clamp(48px,9vw,82px)/1 Arial,sans-serif;letter-spacing:4px;text-shadow:0 3px 0 #27272a,0 0 22px rgba(255,255,255,.18);contain:layout paint}
.slot-cell.is-winner{color:#fde68a;text-shadow:0 0 12px #fbbf24,0 3px 0 #78350f}
.slot-frame{position:absolute;inset:0;pointer-events:none;border:3px solid rgba(251,113,133,.55);border-radius:12px;box-shadow:inset 0 0 35px rgba(244,63,94,.12);z-index:5}
.slot-shade{position:absolute;inset:0;pointer-events:none;background:linear-gradient(to bottom,rgba(0,0,0,.72),transparent 22%,transparent 78%,rgba(0,0,0,.72));z-index:4}
.slot-lights{position:absolute;inset:9px;display:flex;justify-content:space-around;pointer-events:none;z-index:7}
.slot-light-dot{width:9px;height:9px;border-radius:50%;background:#71717a;box-shadow:inset 0 0 2px #18181b}
.slot-lights.lights-on .slot-light-dot{background:#fde68a;box-shadow:0 0 16px #fbbf24}
.slot-plate{margin:-8px auto 0;width:max-content;position:relative;z-index:10;padding:13px 34px;border-radius:999px;background:linear-gradient(#fff,#ffe4e6);border:3px solid #9f1239;color:#881337;font-size:13px;font-weight:1000;letter-spacing:3px;box-shadow:0 6px 0 #4c0519,0 12px 20px rgba(76,5,25,.22)}
.slot-lever{position:absolute;right:-54px;top:42%;width:70px;height:180px;z-index:20;filter:drop-shadow(0 8px 8px rgba(0,0,0,.2))}
.lever-rail{position:absolute;right:28px;top:10px;width:14px;height:120px;border-radius:10px;background:linear-gradient(90deg,#52525b,#e4e4e7,#52525b);border:2px solid #27272a}
.lever-ball{position:absolute;right:4px;top:0;width:48px;height:48px;border-radius:50%;background:radial-gradient(circle at 30% 25%,#fb7185,#be123c 55%,#4c0519);border:3px solid #fda4af;box-shadow:0 5px 0 #4c0519;transition:top .18s ease}
.lever-base{position:absolute;right:10px;bottom:4px;width:52px;height:34px;border-radius:12px;background:linear-gradient(#e4e4e7,#71717a);border:2px solid #27272a}
.slot-lever.pull .lever-ball{top:88px}
.slot-status{margin:24px auto 0;text-align:center;color:#881337;font-weight:800;font-size:13px;min-height:20px}
.slot-spin-button{display:block;margin:18px auto 0;min-width:190px;height:54px;border:0;border-radius:16px;background:linear-gradient(180deg,#f43f5e,#be123c);color:#fff;font:900 16px Arial,sans-serif;letter-spacing:3px;cursor:pointer;border:2px solid #fda4af;box-shadow:0 6px 0 #4c0519,0 12px 20px rgba(76,5,25,.22);transition:transform .12s,box-shadow .12s}
.slot-spin-button:hover{transform:translateY(-2px)}
.slot-spin-button:active,.slot-spin-button.spinning{transform:translateY(4px);box-shadow:0 2px 0 #4c0519}
.slot-spin-button:disabled{opacity:.75;cursor:not-allowed}
@keyframes rafflePulse{50%{transform:scale(1.3);opacity:.65}}
@media(max-width:760px){
 .wheel-page{padding:12px 10px 35px}
 .slot-machine{width:min(620px,96vw)}
 .slot-topper{min-height:62px;letter-spacing:3px}
 .slot-topper .star{font-size:12px}
 .slot-cabinet{padding:22px 16px 30px}
 .slot-window{height:195px;border-width:9px}
 .slot-cell{height:92px;font-size:clamp(42px,12vw,66px)}
 .slot-lever{right:-39px;transform:scale(.75);transform-origin:top left}
 .slot-spin-button{min-width:170px}
}
@media(max-width:480px){
 .slot-machine{width:calc(100vw - 30px);margin-left:-3px}
 .slot-topper{letter-spacing:2px}
 .slot-topper .star{display:none}
 .slot-cabinet{padding:18px 12px 26px}
 .slot-window{height:175px}
 .slot-lever{right:-43px;transform:scale(.62)}
 .slot-plate{padding:10px 24px;font-size:11px}
}
</style>

<div class="wheel-page">
  <div class="slot-machine" id="slotMachine">
    <div class="slot-topper">
      <span class="star">&#9733;</span><span class="star">&#9733;</span><span class="star">&#9733;</span>
      <span>RAFFLE</span>
      <span class="star">&#9733;</span><span class="star">&#9733;</span><span class="star">&#9733;</span>
    </div>

    <div class="slot-cabinet">
      <div class="slot-lights" id="slotLights"></div>
      <div class="slot-window">
        <div class="slot-reel" id="slotReel"></div>
        <div class="slot-shade"></div>
        <div class="slot-frame"></div>
      </div>

      <div class="slot-lever" id="slotLever" aria-hidden="true">
        <div class="lever-rail"></div>
        <div class="lever-ball"></div>
        <div class="lever-base"></div>
      </div>
    </div>

    <div class="slot-plate">LUCKY NUMBER</div>
    <div class="slot-status" id="slotStatus">READY TO SPIN</div>
    <button type="button" id="spin_btn" class="slot-spin-button">SPIN</button>
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
const SLOT_DATA = <?php echo json_encode($slot_participants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

let currentWinner = null;
let slotSpinning = false;

/* ===== SLOT MACHINE ===== */
const REEL_PASSES = 8;          // optimized: small DOM, fast render
const MAX_STRIP_CELLS = 96;      // optimized cap; keeps large events lightweight
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
    if (SLOT_DATA.length === 0) return -1;

    let cells = [];
    // More passes = faster roll
    const passes = Math.min(REEL_PASSES, Math.max(6, Math.ceil(3000 / SLOT_DATA.length)));
    for (let p = 0; p < passes; p++) cells = cells.concat(shuffled(SLOT_DATA));

    // Hard cap: never render more than MAX_STRIP_CELLS DOM nodes (keeps 2k+ events fast)
    if (cells.length > MAX_STRIP_CELLS) cells = shuffled(cells).slice(0, MAX_STRIP_CELLS);

    // Landing cell: second-to-last so there is still motion after it visually settles
    const landIndex = cells.length - 2;
    const wIdx = winnerId == null ? -1 : cells.findIndex(c => c.id === winnerId);
    if (wIdx !== -1 && wIdx !== landIndex) {
        [cells[wIdx], cells[landIndex]] = [cells[landIndex], cells[wIdx]];
    } else if (wIdx === -1 && winnerId != null) {
        cells[landIndex] = SLOT_DATA.find(c => c.id === winnerId); // guarantee winner lands
    }

    const frag = document.createDocumentFragment();
    for (let i = 0; i < cells.length; i++) {
        const div = document.createElement('div');
        div.className = 'slot-cell';
        div.textContent = String(cells[i].number);
        if (i === landIndex) div.dataset.land = '1';
        frag.appendChild(div);
    }
    reel.appendChild(frag);
    return landIndex;
}

function cellHeight() {
    const cell = document.querySelector('.slot-cell');
    return cell ? cell.offsetHeight : 120;
}

// Swap the whole drum contents (used after a round reset) and rebuild the reel
function setSlotData(list) {
    const rows = Array.isArray(list) ? list : [];
    SLOT_DATA.length = 0;
    rows.forEach(p => SLOT_DATA.push(p));

    const reel = el('slotReel');
    reel.style.transform = 'translateY(0)';
    buildReel(null);

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
    const lever = el('slotLever');
    if (lever) lever.classList.add('pull');
    setSlotStatus('\u{1F3B0}', 'ROLLING...');


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
            if (lever) lever.classList.remove('pull');
            reel.classList.remove('fast');

            const landed = reel.querySelector('[data-land]');
            if (landed) landed.classList.add('is-winner');

            currentWinner = {
                participant_id: winner.id,
                number: winner.number,
                name: winner.name,
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
    const spinBtn = el('spin_btn');
    if (spinBtn) spinBtn.addEventListener('click', spinSlot);
});

/* ===== WINNER MODAL ===== */
function showWinnerModal(winner) {
    document.getElementById('winner_name').textContent = winner.name;
    const barangay = (winner.barangay || '').trim();
    const purok = (winner.purok || '').trim();
    document.getElementById('winner_purok').textContent = barangay !== '' ? barangay : (purok !== '' ? 'Purok ' + purok : '');
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

                const reel = el('slotReel');
                reel.style.transform = 'translateY(0)';
                buildReel(null);
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

                const reel = el('slotReel');
                reel.style.transform = 'translateY(0)';
                buildReel(null);
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
