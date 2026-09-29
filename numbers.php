<?php
require_once 'config.php';
if (!isset($current_event_id)) {
    $current_event_id = get_active_event_id($conn);
}

// ---- Settings for this module ----
if (isset($_POST['save_number_cfg'])) {
    $total_numbers = max(1, min(100000, intval($_POST['total_numbers'] ?? 100)));
    $min_number    = max(0, min(999999, intval($_POST['min_number'] ?? 1)));
    $spin_secs     = max(1, min(60, intval($_POST['spin_seconds'] ?? 3)));
    $delay_secs    = max(0, min(120, intval($_POST['modal_delay_seconds'] ?? 0)));
    set_setting($conn, 'num_total_numbers',      (string)$total_numbers);
    set_setting($conn, 'num_min_number',         (string)$min_number);
    set_setting($conn, 'num_spin_seconds',       (string)$spin_secs);
    set_setting($conn, 'num_modal_delay_seconds', (string)$delay_secs);
    set_message('success', "Number generator updated: {$total_numbers} numbers.");
    header('Location: admin?page=numbers');
    exit;
}

$total_numbers = max(1, min(100000, intval(get_setting($conn, 'num_total_numbers', '100'))));
$min_number    = max(0, min(999999, intval(get_setting($conn, 'num_min_number', '1'))));
$spin_secs     = max(1, min(60, intval(get_setting($conn, 'num_spin_seconds', '3'))));
$delay_secs    = max(0, min(120, intval(get_setting($conn, 'num_modal_delay_seconds', '0'))));
$max_number    = $min_number + $total_numbers - 1;
?>
<?php display_message(); ?>

<div class="container1">
  <!-- SLOT MACHINE STAGE -->
  <div class="slot-hero">
    <div class="slot-machine" id="numMachine">

      <div class="slot-topper">
        <span class="star">&#9733;</span><span class="star">&#9733;</span><span class="star">&#9733;</span>
        <span class="topper-text">LUCKY NUMBER</span>
        <span class="star">&#9733;</span><span class="star">&#9733;</span><span class="star">&#9733;</span>
      </div>

      <div class="slot-cabinet">
        <div class="slot-lights" id="numLights"></div>
        <div class="slot-window">
          <div class="slot-reel" id="numReel"><!-- cells injected by JS --></div>
          <div class="slot-shade"></div>
          <div class="slot-frame"></div>
        </div>
      </div>

      <div class="slot-plate">WINNING NUMBER</div>
    </div>
  </div>

  <!-- CONTROL PANEL -->
  <div class="draw-panel">
    <div class="draw-panel-inner">

      <div class="draw-header-area">
        <div class="draw-header-icon">&#127919;</div>
        <div>
          <h2 class="draw-heading">Draw a Number</h2>
          <p class="draw-subtitle">Spin the reels to draw a random number</p>
        </div>
      </div>

      <div class="wheel-controls">
        <div class="num-current-range">
          Range: <strong><?php echo number_format($min_number); ?></strong> &ndash;
          <strong><?php echo number_format($max_number); ?></strong>
          &middot; <strong><?php echo number_format($total_numbers); ?></strong> tickets
        </div>

        <div class="spin-wrap">
          <button type="button" id="numSpinBtn" class="btn-spin-big">
            <span class="spin-icon-chip">&#127919;</span>
            <span class="spin-text">SPIN</span>
            <span class="spin-arrow">&rarr;</span>
          </button>
        </div>

        <form method="POST" class="slot-timing" id="numCfgForm">
          <input type="hidden" name="save_number_cfg" value="1">
          <div class="slot-timing-title">Ticket Range</div>
          <div class="slot-timing-row">
            <label>Total Numbers
              <input type="number" name="total_numbers" min="1" max="100000" step="1"
                     value="<?php echo $total_numbers; ?>" required>
            </label>
            <label>Start At
              <input type="number" name="min_number" min="0" max="999999" step="1"
                     value="<?php echo $min_number; ?>" required>
            </label>
          </div>
          <div class="slot-timing-row">
            <label>Spin (sec)
              <input type="number" name="spin_seconds" min="1" max="60" step="1"
                     value="<?php echo $spin_secs; ?>" required>
            </label>
            <label>Countdown
              <input type="number" name="modal_delay_seconds" min="0" max="120" step="1"
                     value="<?php echo $delay_secs; ?>" required>
            </label>
          </div>
          <button type="submit" class="btn btn-primary slot-timing-save">Save</button>
          <div class="slot-timing-hint">Spin cannot be 0. Countdown 0 = show the number immediately.</div>
        </form>

        <div class="slot-countdown" id="numCountdown"></div>
      </div>

    </div>
  </div>
</div>

<!-- Full-page intense countdown overlay -->
<div class="page-count-overlay" id="numCountOverlay">
  <div class="pc-vignette"></div>
  <span class="pc-num" id="numCountNum"></span>
  <span class="pc-label">SHOWING NUMBER...</span>
</div>

<!-- WINNER MODAL (shows ONLY the number) -->
<div id="numWinnerModal" class="modal">
  <div class="modal-overlay"></div>
  <div class="modal-content num-winner-modal">
    <div class="num-winner-label">WINNING NUMBER</div>
    <div class="num-winner-value" id="numWinnerValue"></div>
    <div class="num-winner-sub" id="numWinnerSub"></div>
    <button type="button" class="btn btn-confirm num-ok" id="numWinnerClose">Close</button>
  </div>
</div>

<script>
(function () {
  const MIN = <?php echo $min_number; ?>;
  const TOTAL = <?php echo $total_numbers; ?>;
  const MAX = MIN + TOTAL - 1;
  const SPIN_MS = <?php echo $spin_secs * 1000; ?>;
  const MODAL_DELAY_MS = <?php echo $delay_secs * 1000; ?>;
  const MAX_STRIP_CELLS = 800;   // hard cap on rendered cells (same as the wheel)
  const MIN_STRIP_CELLS = 400;   // never build a strip so short the roll looks stubby
  const STRIP_JITTER = 80;       // vary run length so back-to-back spins differ
  const FAST_PHASE = 0.90;
  const BOUNCE_MS = 240;
  const OVERSHOOT_PX = 22;

  const reel = document.getElementById('numReel');
  const spinBtn = document.getElementById('numSpinBtn');
  let spinning = false;

  function el(id) { return document.getElementById(id); }

  function buildLights() {
    const c = el('numLights');
    if (!c || c.childElementCount > 0) return;
    for (let i = 0; i < 22; i++) {
      const d = document.createElement('div');
      d.className = 'slot-light-dot';
      d.style.animationDelay = ((i * 70) % 900) + 'ms';
      c.appendChild(d);
    }
  }

  // Build an odometer strip: numbers run in order from a random start, so the
  // same number never comes back around while spinning. The range only wraps if
  // there are fewer numbers than cells. The winner (when given) is placed on the
  // landing cell by deriving the start offset, which keeps the strip sequential
  // and duplicate-free instead of splicing values in.
  function buildReel(winner) {
    reel.innerHTML = '';
    if (TOTAL <= 0) return -1;

    const jitter = Math.floor(Math.random() * STRIP_JITTER);
    const stripLen = Math.min(MAX_STRIP_CELLS, Math.max(MIN_STRIP_CELLS, TOTAL)) - jitter;
    const landIndex = stripLen - 2;

    const start = winner == null
      ? Math.floor(Math.random() * TOTAL)
      : (((winner - MIN - landIndex) % TOTAL) + TOTAL) % TOTAL;

    const frag = document.createDocumentFragment();
    for (let i = 0; i < stripLen; i++) {
      const div = document.createElement('div');
      div.className = 'slot-cell';
      div.textContent = String(MIN + ((start + i) % TOTAL));
      if (i === landIndex) div.dataset.land = '1';
      frag.appendChild(div);
    }
    reel.appendChild(frag);
    return landIndex;
  }

  function cellHeight() {
    const c = document.querySelector('.slot-cell');
    return c ? c.offsetHeight : 120;
  }

  function spin() {
    if (spinning) return;
    if (TOTAL <= 0) return;
    spinning = true;
    spinBtn.disabled = true;
    spinBtn.classList.add('spinning');
    el('numLights').classList.add('lights-on');

    const winner = MIN + Math.floor(Math.random() * TOTAL);
    const slot = buildReel(winner);
    reel.style.transform = 'translateY(0)';
    void reel.offsetHeight;

    const ch = cellHeight();
    const target = -(slot - 1) * ch;
    const start = performance.now();

    function frame(now) {
      const t = Math.min((now - start) / SPIN_MS, 1);
      let eased, extra = 0;
      if (t < FAST_PHASE) {
        eased = (t / FAST_PHASE) * 0.90;
      } else {
        const u = (t - FAST_PHASE) / (1 - FAST_PHASE);
        eased = 0.90 + (1 - Math.pow(1 - u, 2)) * 0.10;
      }

      const tb = (now - start - SPIN_MS) / BOUNCE_MS;
      if (tb >= 0) {
        extra = -OVERSHOOT_PX * Math.exp(-4.5 * tb) * Math.cos(10 * tb);
      }

      reel.style.transform = 'translateY(' + (target * eased + extra) + 'px)';
      reel.classList.toggle('fast', t < FAST_PHASE);

      if (tb < 0 || t < 1 || (now - start) < SPIN_MS + BOUNCE_MS) {
        requestAnimationFrame(frame);
      } else {
        spinning = false;
        spinBtn.disabled = false;
        spinBtn.classList.remove('spinning');
        reel.classList.remove('fast');
        const landed = reel.querySelector('[data-land]');
        if (landed) landed.classList.add('is-winner');
        showCountdown(winner);
      }
    }
    requestAnimationFrame(frame);
  }

  function showCountdown(winner) {
    if (MODAL_DELAY_MS > 0) {
      const ovEl = el('numCountOverlay');
      const numEl = el('numCountNum');
      let remaining = Math.round(MODAL_DELAY_MS / 1000);
      const total = remaining;
      ovEl.classList.remove('mid', 'final');
      numEl.textContent = remaining;
      ovEl.classList.add('show');
      numEl.classList.remove('tick'); void numEl.offsetWidth; numEl.classList.add('tick');
      const tick = setInterval(function () {
        remaining--;
        if (remaining > 0) {
          if (remaining <= Math.ceil(total / 2)) ovEl.classList.add('mid');
          numEl.textContent = remaining;
          numEl.classList.remove('tick'); void numEl.offsetWidth; numEl.classList.add('tick');
        } else {
          clearInterval(tick);
          ovEl.classList.add('final');
          numEl.textContent = '';
          setTimeout(function () {
            ovEl.classList.remove('show', 'mid', 'final');
            showWinner(winner);
          }, 650);
        }
      }, 1000);
    } else {
      showWinner(winner);
    }
  }

  function showWinner(num) {
    document.getElementById('numWinnerValue').textContent = String(num).padStart(String(TOTAL - 1).length, '0');
    document.getElementById('numWinnerSub').textContent = 'Ticket # ' + num + ' of ' + MAX;
    document.getElementById('numWinnerModal').classList.add('show');
    if (typeof startConfetti === 'function') startConfetti();
  }

  spinBtn.addEventListener('click', spin);
  document.getElementById('numWinnerClose').addEventListener('click', function () {
    document.getElementById('numWinnerModal').classList.remove('show');
    if (typeof stopConfetti === 'function') stopConfetti();
  });
  document.getElementById('numWinnerModal').addEventListener('click', function (e) {
    if (e.target === this) {
      this.classList.remove('show');
      if (typeof stopConfetti === 'function') stopConfetti();
    }
  });

  buildLights();
  buildReel(null);
})();
</script>

<style>
  .num-current-range {
    text-align: center;
    font-size: 14px;
    color: #64748b;
    line-height: 1.6;
    font-weight: 600;
    margin-bottom: 22px;
  }
  .num-current-range strong { color: #1e293b; font-weight: 800; }

  /* Winner modal - number only */
  .num-winner-modal { text-align: center; }
  .num-winner-label {
    font-size: 13px; font-weight: 800; letter-spacing: 4px;
    text-transform: uppercase; color: #f9a8d4; margin-bottom: 12px;
  }
  .num-winner-value {
    font-size: clamp(70px, 14vw, 150px); font-weight: 900; line-height: 1.05;
    color: #ffffff; font-variant-numeric: tabular-nums;
    letter-spacing: 2px; margin: 10px 0 6px;
    text-shadow:
      0 8px 30px rgba(0, 0, 0, 0.35),
      0 0 50px rgba(236, 73, 153, 0.55);
  }
  .num-winner-sub {
    font-size: 15px; color: #cbd5e1; margin-bottom: 30px;
    letter-spacing: 0.4px;
  }
  .num-ok {
    min-width: 180px;
    background: linear-gradient(135deg, #ec4899, #f472b6) !important;
    border: none !important;
    color: #fff !important;
    border-radius: 12px !important;
    font-weight: 800;
    letter-spacing: 1px;
  }
  .num-ok:hover { filter: brightness(1.07); }
</style>