<?php
require_once 'config.php';
if (!isset($current_event_id)) {
    $current_event_id = get_active_event_id($conn);
}

// Use the uploaded draw background when available; otherwise use the bundled image.
$custom_draw_bg = 'uploads/bg/custom_bg.jpg';
$default_draw_bg = file_exists($custom_draw_bg)
    ? $custom_draw_bg . '?v=' . filemtime($custom_draw_bg)
    : 'assets/img/draw-bg.jpg';

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

<?php display_message(); ?>
<style>
/* ===== Draw stage (full-bleed banner background) ===== */
.draw-stage{
    position:fixed;
    top:57px;
    right:0;
    bottom:0;
    left:0;
  width:100vw;
    height:auto;
    min-height:0;
  background-color:#1b2a4a;
  background-position:center center;
    background-size:100% 100%;
  background-repeat:no-repeat;
  background-image:url('<?php echo htmlspecialchars($default_draw_bg, ENT_QUOTES); ?>');
  border-radius:0;
  overflow:hidden;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
}
.draw-stage:fullscreen{inset:0;width:100vw;height:100vh;border-radius:0}
#drawStage #winnerModal{position:absolute;inset:0;z-index:20;width:100%;height:100%}
#drawStage #winnerModal .modal-overlay{position:absolute;inset:0}
#drawStage #winnerModal .modal-content{width:100%;height:100%;max-width:none}

/* Top-right tool buttons */
.draw-tools{position:absolute;top:16px;right:16px;display:flex;gap:10px;z-index:5}
.draw-tool-btn{
  width:44px;height:44px;border-radius:12px;border:1px solid rgba(255,255,255,.35);
  background:rgba(15,23,42,.45);color:#fff;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
    opacity:.9;transition:opacity .2s,background .2s;
}
.draw-tool-btn:hover,.draw-tool-btn:focus-visible{opacity:1;background:rgba(15,23,42,.75);outline:none}
.draw-tool-btn svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* Settings popover */
.draw-settings{
  position:absolute;top:70px;right:16px;z-index:6;width:300px;
  background:rgba(15,23,42,.92);color:#fff;border:1px solid rgba(255,255,255,.2);
  border-radius:14px;padding:16px;display:none;
  backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
  box-shadow:0 12px 40px rgba(0,0,0,.45);font-size:.9rem;
}
.draw-settings.show{display:block}
.draw-settings h3{margin:0 0 12px;font-size:1rem;font-weight:700}
.ds-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.ds-row label{flex:1}
.ds-col{display:block;margin-bottom:14px}
.ds-col label{display:block;margin-bottom:6px}
.draw-settings select,.draw-settings input[type=text],.draw-settings input[type=number]{
  width:100%;padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.25);
  background:rgba(255,255,255,.08);color:#fff;font-size:.9rem;
}
.draw-settings select{width:auto}
.draw-settings option{color:#111}
.ds-switch{position:relative;width:44px;height:24px;flex:none}
.ds-switch input{opacity:0;position:absolute;inset:0;width:100%;height:100%;margin:0;cursor:pointer;z-index:2}
.ds-switch span{position:absolute;inset:0;background:rgba(255,255,255,.25);border-radius:24px;transition:.2s}
.ds-switch span::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s}
.ds-switch input:checked + span{background:#22c55e}
.ds-switch input:checked + span::after{transform:translateX(20px)}
.ds-reset{background:none;border:none;color:#93c5fd;cursor:pointer;padding:0;font-size:.85rem;text-decoration:underline}

/* Ticket digits */
.draw-center{display:flex;flex-direction:column;align-items:center;gap:18px;width:100%;padding:0 16px}
.draw-label{
  color:#fff;font-weight:800;letter-spacing:.35em;text-transform:uppercase;
  font-size:clamp(.7rem,1.4vw,1rem);text-shadow:0 2px 8px rgba(0,0,0,.6);text-align:center;
}
.digit-row{position:relative;display:flex;flex-direction:row-reverse;gap:clamp(8px,1.6vw,22px);justify-content:center}
.digit-box{
  width:clamp(64px,12vw,190px);height:clamp(96px,18vw,280px);
  border-radius:clamp(12px,1.8vw,24px);
  background:rgba(30,41,59,.62);
  border:1px solid rgba(255,255,255,.18);
  backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);
  display:flex;align-items:center;justify-content:center;
  font-family:"NumberFont",monospace;font-weight:900;color:#fff;line-height:1;
  font-size:clamp(3.5rem,9vw,10rem);
  text-shadow:0 4px 18px rgba(0,0,0,.5);
  transition:background .15s,border-color .15s;
}
.digit-box.filled{background:rgba(15,23,42,.78);border-color:rgba(255,255,255,.4)}
.digit-box.active{border-color:#fbbf24;box-shadow:0 0 0 3px rgba(251,191,36,.35)}
/* Real input captures keystrokes, invisible over the boxes */
.draw-number-input{
  position:absolute;inset:0;width:100%;height:100%;opacity:0;
    direction:rtl;text-align:right;font-size:16px !important;border:none;background:transparent;cursor:text;
}
.participant-hint{color:#fff;min-height:1.2em;text-shadow:0 2px 6px rgba(0,0,0,.7)}

/* Actions */
.draw-actions{display:flex;gap:12px;margin-top:6px}
.btn-draw-find,.btn-draw-clear{
  display:inline-flex;align-items:center;gap:8px;border:none;cursor:pointer;
  border-radius:12px;padding:12px 26px;font-weight:700;font-size:1rem;
  opacity:.85;transition:opacity .2s,transform .1s;
}
.btn-draw-find{background:#f59e0b;color:#1a1a2e}
.btn-draw-clear{background:rgba(15,23,42,.65);color:#fff;border:1px solid rgba(255,255,255,.3)}
.btn-draw-find:hover,.btn-draw-clear:hover{opacity:1}
.btn-draw-find:active,.btn-draw-clear:active{transform:scale(.97)}
.btn-draw-find:disabled{opacity:.5;cursor:wait}
.draw-help{color:#fff;font-size:.8rem;opacity:.75;text-shadow:0 1px 4px rgba(0,0,0,.7)}
.draw-help kbd{background:rgba(255,255,255,.2);border-radius:4px;padding:1px 6px}

/* Countdown overlay */
.countdown-overlay{
    position:absolute;inset:0;z-index:15;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;
    background:radial-gradient(120% 120% at 50% 45%,rgba(60,4,24,.82),rgba(3,0,2,.96));
    opacity:0;pointer-events:none;transition:opacity .35s ease;
}
.countdown-overlay.show{opacity:1;pointer-events:auto;animation:pcPulseBg 1s ease-in-out infinite}
@keyframes pcPulseBg{
    0%,100%{background:radial-gradient(120% 120% at 50% 45%,rgba(60,4,24,.82),rgba(3,0,2,.96))}
    50%{background:radial-gradient(120% 120% at 50% 45%,rgba(122,16,56,.88),rgba(8,1,5,.98))}
}
.countdown-vignette{position:absolute;inset:0;box-shadow:inset 0 0 220px rgba(0,0,0,.85);pointer-events:none}
.countdown-number{
    z-index:1;font-size:clamp(160px,34vh,320px);font-weight:900;line-height:1;color:#fff;font-variant-numeric:tabular-nums;
    text-shadow:0 0 30px rgba(255,255,255,.35),0 10px 80px rgba(194,23,91,.65);
}
.countdown-number.tick{animation:pcTick .95s cubic-bezier(.15,.85,.25,1.15)}
@keyframes pcTick{0%{transform:scale(2.1);opacity:.1;filter:blur(6px)}55%{transform:scale(.96);opacity:1;filter:blur(0)}75%{transform:scale(1.04)}100%{transform:scale(1)}}
.countdown-overlay.mid .countdown-number{color:#ffd166;text-shadow:0 0 34px rgba(255,209,102,.55),0 10px 90px rgba(233,78,119,.75)}
.countdown-overlay.final{animation:pcFlash .32s ease-in-out infinite}
@keyframes pcFlash{0%,100%{filter:brightness(1)}50%{filter:brightness(1.9)}}
.countdown-overlay.final .countdown-number{color:#ff2e63;text-shadow:0 0 44px rgba(255,46,99,.9),0 0 140px rgba(255,46,99,.6);animation:pcSlam .65s cubic-bezier(.2,.9,.3,1.05)}
@keyframes pcSlam{0%{transform:scale(2.6);opacity:0}40%{transform:scale(1);opacity:1}100%{transform:scale(1.12) rotate(-1.5deg);opacity:1}}
.countdown-caption{z-index:1;font-size:clamp(13px,2vh,17px);font-weight:800;letter-spacing:6px;text-transform:uppercase;color:rgba(255,215,234,.85)}
@media(prefers-reduced-motion:reduce){.countdown-overlay.show,.countdown-number.tick,.countdown-overlay.final,.countdown-overlay.final .countdown-number{animation:none}}
</style>

<div class="draw-stage" id="drawStage">

  <div class="draw-tools">
    <button type="button" id="fullscreen_btn" class="draw-tool-btn" title="Fullscreen" aria-label="Toggle fullscreen">
      <svg viewBox="0 0 24 24" id="fs_icon_enter"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
      <svg viewBox="0 0 24 24" id="fs_icon_exit" style="display:none"><path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3M16 21v-3a2 2 0 0 1 2-2h3"/></svg>
    </button>
    <button type="button" id="settings_btn" class="draw-tool-btn" title="Settings" aria-label="Draw settings">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
    </button>
  </div>

  <div class="draw-settings" id="drawSettings">
    <h3>Draw settings</h3>
    <div class="ds-row">
            <label for="set_countdown"><svg viewBox="0 0 24 24" aria-hidden="true" style="width:16px;height:16px;vertical-align:-3px;margin-right:6px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l3 2M9 2h6M12 2v3"/></svg>Countdown before showing winner</label>
      <span class="ds-switch"><input type="checkbox" id="set_countdown"><span></span></span>
    </div>
    <div class="ds-row" id="ds_seconds_row">
      <label for="set_seconds">Countdown length</label>
            <input type="number" id="set_seconds" min="0" max="60" step="1" value="3" aria-label="Countdown length in seconds; zero shows the winner immediately">
    </div>
    <div class="ds-col">
      <label for="set_bg">Background image URL</label>
      <input type="text" id="set_bg" placeholder="assets/img/draw-bg.jpg">
    </div>
    <button type="button" class="ds-reset" id="set_bg_reset">Use default background</button>
  </div>

  <div class="draw-center">
    <div class="draw-label">Enter winning ticket number</div>

    <div class="digit-row" id="digitRow">
      <div class="digit-box"></div>
      <div class="digit-box"></div>
      <div class="digit-box"></div>
      <div class="digit-box"></div>
      <div class="digit-box"></div>
      <input type="text" autofocus autocomplete="off" inputmode="numeric" id="drawn_number" class="draw-number-input" maxlength="5" aria-label="Winning ticket number" />
    </div>

    <div id="participant_name_hint" class="participant-hint"></div>

    <span class="draw-help">Press <kbd>Enter</kbd> to search</span>
  </div>

  <div class="countdown-overlay" id="countdownOverlay" aria-live="assertive">
        <div class="countdown-vignette"></div>
        <span class="countdown-number" id="countdownNumber"></span>
        <span class="countdown-caption">SHOWING WINNER...</span>
  </div>

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
let currentWinner = null;
let countdownTimer = null;

const stage = document.getElementById('drawStage');
const drawnInput = document.getElementById('drawn_number');
const digitBoxes = document.querySelectorAll('#digitRow .digit-box');

/* ---------- Settings (saved in this browser) ---------- */
const DEFAULT_BG = <?php echo json_encode($default_draw_bg); ?>;
const SETTINGS_KEY = 'drawSettings';
let settings = { countdown: false, seconds: 3, bg: '' };
try { Object.assign(settings, JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}')); } catch (e) {}
settings.seconds = Math.min(60, Math.max(0, parseInt(settings.seconds, 10) || 0));

function saveSettings() {
    try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)); } catch (e) {}
}
function applyBg() {
    const url = (settings.bg || '').trim() || DEFAULT_BG;
    stage.style.backgroundImage = "url('" + url.replace(/'/g, "%27") + "')";
}
function syncSettingsUI() {
    document.getElementById('set_countdown').checked = !!settings.countdown;
    document.getElementById('set_seconds').value = String(settings.seconds);
    document.getElementById('set_bg').value = settings.bg || '';
    document.getElementById('ds_seconds_row').style.opacity = settings.countdown ? 1 : .45;
}

const settingsPanel = document.getElementById('drawSettings');
document.getElementById('settings_btn').addEventListener('click', function(e) {
    e.stopPropagation();
    settingsPanel.classList.toggle('show');
});
settingsPanel.addEventListener('click', e => e.stopPropagation());
document.addEventListener('click', () => settingsPanel.classList.remove('show'));

document.getElementById('set_countdown').addEventListener('change', function() {
    settings.countdown = this.checked; saveSettings(); syncSettingsUI();
});
document.getElementById('set_seconds').addEventListener('change', function() {
    settings.seconds = Math.min(60, Math.max(0, parseInt(this.value, 10) || 0));
    this.value = String(settings.seconds);
    saveSettings();
});
document.getElementById('set_bg').addEventListener('change', function() {
    settings.bg = this.value.trim(); saveSettings(); applyBg();
});
document.getElementById('set_bg_reset').addEventListener('click', function() {
    settings.bg = ''; saveSettings(); syncSettingsUI(); applyBg();
});
syncSettingsUI();
applyBg();

/* ---------- Fullscreen ---------- */
const fsBtn = document.getElementById('fullscreen_btn');
fsBtn.addEventListener('click', function() {
    if (!document.fullscreenElement) {
        (stage.requestFullscreen ? stage.requestFullscreen() : Promise.reject()).catch(() => showToast('Fullscreen is not supported here.', 'error'));
    } else {
        document.exitFullscreen();
    }
});
document.addEventListener('fullscreenchange', function() {
    const on = !!document.fullscreenElement;
    document.getElementById('fs_icon_enter').style.display = on ? 'none' : '';
    document.getElementById('fs_icon_exit').style.display = on ? '' : 'none';
    const confettiCanvas = document.getElementById('confetti-canvas');
    if (confettiCanvas) {
        if (on) {
            stage.appendChild(confettiCanvas);
        } else {
            document.body.appendChild(confettiCanvas);
        }
    }
    drawnInput.focus();
});

/* ---------- Digit boxes ---------- */
function renderDigits() {
    const v = drawnInput.value;
    digitBoxes.forEach((box, i) => {
        box.textContent = v[v.length - 1 - i] || '';
        box.classList.toggle('filled', i < v.length);
        box.classList.toggle('active', i === Math.min(v.length, digitBoxes.length - 1) && document.activeElement === drawnInput);
    });
}
function setNumber(v) {
    drawnInput.value = v;
    renderDigits();
}
function resetDraw() {
    setNumber('');
    document.getElementById('participant_name_hint').textContent = '';
    drawnInput.focus();
}

drawnInput.addEventListener('beforeinput', function(e) {
    e.preventDefault();
    let currentDigits = this.value.replace(/\D/g, '').replace(/^0+/, '');

    if (e.inputType === 'deleteContentBackward' || e.inputType === 'deleteContentForward') {
        setNumber(currentDigits.slice(0, -1));
        return;
    }
    if (e.data && /^\d$/.test(e.data)) {
        currentDigits = currentDigits + e.data;
        if (currentDigits.length > 5) currentDigits = currentDigits.slice(-5);
        setNumber(currentDigits);
    }
});
drawnInput.addEventListener('paste', e => e.preventDefault());
drawnInput.addEventListener('focus', renderDigits);
drawnInput.addEventListener('blur', renderDigits);
drawnInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); findWinner(); }
});
// Clicking anywhere on the stage keeps the input focused
stage.addEventListener('click', function(e) {
    if (!e.target.closest('button, .draw-settings')) drawnInput.focus();
});
setNumber('');

/* ---------- Countdown ---------- */
function runCountdown(seconds, done) {
    const overlay = document.getElementById('countdownOverlay');
    const numEl = document.getElementById('countdownNumber');
    let n = seconds;
    const total = seconds;

    function tick() {
        numEl.textContent = n;
        numEl.classList.remove('tick');
        void numEl.offsetWidth;
        numEl.classList.add('tick');
    }
    overlay.classList.remove('mid', 'final');
    overlay.classList.add('show');
    tick();
    countdownTimer = setInterval(function() {
        n--;
        if (n > 0) {
            if (n <= Math.ceil(total / 2)) overlay.classList.add('mid');
            tick();
        } else {
            clearInterval(countdownTimer);
            countdownTimer = null;
            overlay.classList.add('final');
            numEl.textContent = '';
            setTimeout(function() {
                overlay.classList.remove('show', 'mid', 'final');
                done();
            }, 650);
        }
    }, 1000);
}
function cancelCountdown() {
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    document.getElementById('countdownOverlay').classList.remove('show', 'mid', 'final');
}

/* ---------- Find winner ---------- */
function findWinner() {
    if (countdownTimer) return;
    const drawnNumber = drawnInput.value.trim();

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
            if (!data.success) {
                showToast(data.message, 'error');
                return;
            }
            currentWinner = data.winner;
            if (settings.countdown && settings.seconds > 0) {
                runCountdown(settings.seconds, () => showWinnerModal(data.winner));
            } else {
                showWinnerModal(data.winner);
            }
        })
        .catch(() => {
            showToast('An error occurred. Please try again.', 'error');
        });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        settingsPanel.classList.remove('show');
        if (countdownTimer) { cancelCountdown(); currentWinner = null; }
    }
});

/* ---------- Winner modal ---------- */
function showWinnerModal(winner) {
    document.getElementById('winner_name').textContent = winner.name;
    const barangay = (winner.barangay || '').trim();
    const purok = (winner.purok || '').trim();
    const locationText = barangay !== '' ? barangay : (purok !== '' ? 'Purok ' + purok : '');
    document.getElementById('winner_purok').textContent = locationText;

    document.getElementById('winnerModal').classList.add('show');
    if (typeof startConfetti === 'function') startConfetti();
}

function closeWinnerModal() {
    document.getElementById('winnerModal').classList.remove('show');
    if (typeof stopConfetti === 'function') stopConfetti();
    currentWinner = null;
    resetDraw();
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
                closeWinnerModal();
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(() => showToast('An error occurred. Please try again.', 'error'));
}

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
                closeWinnerModal();
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(() => showToast('An error occurred. Please try again.', 'error'));
}

document.getElementById('confirm_btn').addEventListener('click', function() {
    if (currentWinner) confirmWinner(currentWinner);
});
document.getElementById('remove_btn').addEventListener('click', function() {
    if (currentWinner) removeFromList(currentWinner);
});
document.querySelectorAll('#winnerModal .close, #winnerModal .close-modal').forEach(el => {
    el.addEventListener('click', closeWinnerModal);
});
document.getElementById('winnerModal').addEventListener('click', function(e) {
    if (e.target === this) closeWinnerModal();
});
</script>