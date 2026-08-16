<?php
declare(strict_types=1);

if (!defined('LIVE_DISPLAY_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'score-reveal';
    $settings['slides']['score-reveal']['enabled'] = true;
    $settings['slides']['score-reveal']['duration'] = 999999;
    $tvBodyClass = 'tv-score-reveal-only';
    $tvBootstrapData = tv_bootstrap_data();
    $tvBootstrapData['settings']['mode'] = 'manual';
    $tvBootstrapData['settings']['active_slide'] = 'score-reveal';
    $tvBootstrapData['settings']['slides']['score-reveal']['enabled'] = true;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-score-reveal" data-slide="score-reveal" style="opacity: 1; visibility: visible; transform: scale(1);">';
    echo '<script>window.TV_FORCE_REVEAL_ONLY = true;</script>';
}

$leaderboard = tv_leaderboard((int)($event['id'] ?? 0));
$teams = !empty($leaderboard) ? $leaderboard : [];
$firstTeam = !empty($teams) ? $teams[0] : null;
$firstTeamColor = !empty($firstTeam['team_color']) ? live_display_color($firstTeam['team_color']) : '#10b981';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800;900&family=Plus+Jakarta+Sans:wght@700;800;900&display=swap');

#slide-score-reveal {
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden;
    background: transparent !important;
    font-family: 'Outfit', 'Plus Jakarta Sans', system-ui, sans-serif;
    color: #ffffff;
    width: 100% !important;
    height: 100% !important;
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute !important;
    inset: 0 !important;
}

/* Controls Floating HUD Bar */
.control-dock {
    position: absolute;
    bottom: 28px;
    z-index: 100;
    display: flex;
    align-items: center;
    gap: 14px;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(24px);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 99px;
    padding: 10px 24px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
}

.btn-action {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
    border: none;
    padding: 10px 24px;
    border-radius: 99px;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    cursor: pointer;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-action:hover {
    transform: scale(1.05);
    box-shadow: 0 10px 30px rgba(16, 185, 129, 0.6);
}

.btn-reset {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: rgba(255, 255, 255, 0.85);
}

.btn-reset:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

/* PHASE 1: COUNTDOWN CONTAINER */
.countdown-stage {
    position: absolute;
    z-index: 20;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    transition: all 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}

.countdown-eyebrow {
    font-size: 14px;
    font-weight: 900;
    letter-spacing: 0.28em;
    text-transform: uppercase;
    color: var(--theme-color, #10b981);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    text-shadow: 0 0 20px var(--theme-color, #10b981);
}

.countdown-ring-wrap {
    position: relative;
    width: 320px;
    height: 320px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ring-svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
}

.ring-bg {
    fill: none;
    stroke: rgba(255, 255, 255, 0.08);
    stroke-width: 10;
}

.ring-progress {
    fill: none;
    stroke: var(--theme-color, #10b981);
    stroke-width: 12;
    stroke-linecap: round;
    stroke-dasharray: 942;
    stroke-dashoffset: 0;
    transition: stroke-dashoffset 0.9s linear, stroke 0.4s ease;
    filter: drop-shadow(0 0 16px var(--theme-color, #10b981));
}

.countdown-number {
    font-size: 140px;
    font-weight: 900;
    font-family: 'Plus Jakarta Sans', monospace;
    color: #ffffff;
    line-height: 1;
    text-shadow: 0 0 40px var(--theme-color, #10b981);
    z-index: 5;
    user-select: none;
}

.countdown-caption {
    margin-top: 24px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.15em;
    color: rgba(255, 255, 255, 0.5);
    text-transform: uppercase;
}

/* PHASE 2: SCORE REVEAL STAGE */
.reveal-stage {
    position: relative;
    width: 1100px;
    height: 820px;
    max-width: 96%;
    max-height: 94%;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 30;
    opacity: 0;
    visibility: hidden;
    transform: scale(0.85);
    transition: opacity 0.8s ease, transform 0.8s ease;
}

.reveal-stage.active {
    opacity: 1;
    visibility: visible;
    transform: scale(1);
}
</style>

<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

<!-- PHASE 1: COUNTDOWN STAGE -->
<div class="countdown-stage" id="countdownStage">
    <div class="countdown-eyebrow">
        <i class="fa-solid fa-trophy"></i> GRAND FINALE SCORE REVEAL
    </div>

    <div class="countdown-ring-wrap">
        <svg class="ring-svg" viewBox="0 0 320 320">
            <circle class="ring-bg" cx="160" cy="160" r="150" />
            <circle class="ring-progress" id="ringProgress" cx="160" cy="160" r="150" />
        </svg>
        <div class="countdown-number" id="countdownNum">10</div>
    </div>

    <div class="countdown-caption">GET READY FOR FINAL STANDINGS</div>
</div>

<!-- PHASE 2: SCORE REVEAL STAGE -->
<div class="reveal-stage" id="revealStage">
    <!-- Rendered dynamically by TV_BOOTSTRAP_DATA -->
</div>

<!-- Control Dock -->
<div class="control-dock">
    <button type="button" class="btn-action" id="btnStart">
        <i class="fa-solid fa-play"></i> START 10s COUNTDOWN
    </button>
    <button type="button" class="btn-action btn-reset" id="btnReset">
        <i class="fa-solid fa-rotate-left"></i> RESET STAGE
    </button>
</div>

<script>
(function initScoreRevealPage() {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    let audioCtx = null;

    function playBeep(freq = 600, duration = 0.12, type = 'sine') {
        try {
            if (!audioCtx) audioCtx = new AudioCtx();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = type;
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.35, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {}
    }

    function playBoomDrop() {
        try {
            if (!audioCtx) audioCtx = new AudioCtx();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(150, audioCtx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(30, audioCtx.currentTime + 1.2);
            gain.gain.setValueAtTime(0.8, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 1.2);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 1.2);
        } catch (e) {}
    }

    let countdownVal = 10;
    let countdownTimer = null;
    const totalDash = 942;

    const countdownNum = document.getElementById('countdownNum');
    const ringProgress = document.getElementById('ringProgress');
    const countdownStage = document.getElementById('countdownStage');
    const revealStage = document.getElementById('revealStage');

    function startCountdown() {
        if (countdownTimer) clearInterval(countdownTimer);
        countdownVal = 10;
        revealStage.classList.remove('active');
        if (typeof gsap !== 'undefined') gsap.set(countdownStage, { opacity: 1, scale: 1 });

        updateCountdownStep();

        countdownTimer = setInterval(() => {
            countdownVal--;
            if (countdownVal > 0) {
                updateCountdownStep();
            } else if (countdownVal === 0) {
                clearInterval(countdownTimer);
                triggerGrandReveal();
            }
        }, 1000);
    }

    function updateCountdownStep() {
        if (countdownNum) countdownNum.textContent = countdownVal;
        const progress = (10 - countdownVal) / 10;
        const offset = totalDash * progress;
        if (ringProgress) ringProgress.style.strokeDashoffset = offset;

        let themeColor = '#10b981';
        let freq = 520;
        if (countdownVal <= 6 && countdownVal > 3) {
            themeColor = '#f59e0b';
            freq = 680;
        } else if (countdownVal <= 3) {
            themeColor = '#f43f5e';
            freq = 880;
        }

        document.documentElement.style.setProperty('--theme-color', themeColor);
        if (typeof syncBackdropVideo === 'function') syncBackdropVideo(themeColor);

        if (typeof gsap !== 'undefined' && countdownNum) {
            gsap.fromTo(countdownNum, { scale: 1.35, opacity: 0.6 }, { scale: 1, opacity: 1, duration: 0.4, ease: 'back.out(2)' });
        }

        playBeep(freq, 0.12, countdownVal <= 3 ? 'square' : 'sine');
    }

    function triggerGrandReveal() {
        playBoomDrop();
        if (typeof confetti === 'function') {
            confetti({ particleCount: 140, spread: 90, origin: { y: 0.5 } });
        }

        const teams = window.TV_BOOTSTRAP_DATA?.leaderboard || window.TV_BOOT?.initial?.leaderboard || [];
        if (typeof renderLeaderboardStageInto === 'function') {
            renderLeaderboardStageInto(revealStage, teams);
        }

        if (typeof gsap !== 'undefined') {
            gsap.to(countdownStage, {
                opacity: 0,
                scale: 1.4,
                duration: 0.7,
                ease: 'power3.in',
                onComplete: () => {
                    revealStage.classList.add('active');
                }
            });
        } else {
            countdownStage.style.opacity = '0';
            revealStage.classList.add('active');
        }
    }

    function resetStage() {
        if (countdownTimer) clearInterval(countdownTimer);
        countdownVal = 10;
        if (countdownNum) countdownNum.textContent = '10';
        if (ringProgress) ringProgress.style.strokeDashoffset = '0';
        document.documentElement.style.setProperty('--theme-color', '#10b981');
        revealStage.classList.remove('active');
        if (typeof gsap !== 'undefined') gsap.set(countdownStage, { opacity: 1, scale: 1 });
    }

    document.getElementById('btnStart')?.addEventListener('click', startCountdown);
    document.getElementById('btnReset')?.addEventListener('click', resetStage);
})();
</script>

<?php
if (!defined('LIVE_DISPLAY_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
