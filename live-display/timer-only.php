<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$event = tv_active_event();
$eventId = (int)($event['id'] ?? 0);
$settings = tv_get_settings($eventId);
$cpData = tv_current_program($eventId);

$initPerf = $cpData['performer'] ?? [];
$initProg = $cpData['program'] ?? [];
$isGroupProg = (($initProg['program_type'] ?? '') === 'group' || !empty($initProg['only_team_marks']));

if ($isGroupProg) {
    $initChest = !empty($initPerf['entry_name']) ? $initPerf['entry_name'] : '—';
} else {
    $initChest = !empty($initPerf['chest_number']) ? $initPerf['chest_number'] : (!empty($initPerf['number']) ? $initPerf['number'] : '—');
}

$initTimerRunning = (int)($settings['live_timer_running'] ?? 0);
$initTimerStartTime = (float)($settings['live_timer_start_time'] ?? 0.0);
$initTimerElapsed = (int)($settings['live_timer_elapsed'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Stage Timer Only</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            background: radial-gradient(circle at center, #0f172a 0%, #020617 100%);
            color: #ffffff;
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .timer-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 20px;
            text-align: center;
            padding: 40px;
            width: 100%;
            max-width: 900px;
        }
        .chest-badge {
            background: rgba(16, 185, 129, 0.08);
            border: 2px solid rgba(16, 185, 129, 0.35);
            padding: 12px 36px;
            border-radius: 24px;
            color: #10b981;
            font-weight: 800;
            font-size: 28px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.05);
            transition: all 0.5s ease;
        }
        .chest-number-value {
            font-size: 160px;
            font-weight: 900;
            line-height: 1.0;
            letter-spacing: -0.04em;
            background: linear-gradient(135deg, #ffffff 30%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 50px rgba(255, 255, 255, 0.1);
            transition: all 0.5s ease;
            margin-bottom: 10px;
        }
        .timer-card {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 2px solid rgba(255, 255, 255, 0.08);
            padding: 30px 60px;
            border-radius: 36px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255,255,255,0.1);
            transition: all 0.5s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        .timer-card.is-running {
            border-color: rgba(239, 68, 68, 0.4);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4), 0 0 40px rgba(239, 68, 68, 0.15);
        }
        .timer-card.is-running .pulse-indicator {
            display: inline-block;
        }
        .pulse-indicator {
            display: none;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #ef4444;
            box-shadow: 0 0 12px #ef4444;
            animation: pulse-red-dot 1.5s infinite;
        }
        .timer-display {
            font-family: 'Space Grotesk', monospace;
            font-size: 140px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.02em;
            color: #ffffff;
        }
        .timer-card.is-running .timer-display {
            color: #f87171;
            text-shadow: 0 0 35px rgba(239, 68, 68, 0.35);
        }
        .performer-name {
            font-size: 24px;
            font-weight: 600;
            color: #94a3b8;
            margin-top: 10px;
            max-width: 600px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        @keyframes pulse-red-dot {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
            }
            70% {
                transform: scale(1);
                box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
            }
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
            }
        }
        
        /* Auto Scaling */
        @media(max-width: 768px) {
            .chest-number-value { font-size: 110px; }
            .timer-display { font-size: 90px; }
            .chest-badge { font-size: 20px; }
            .timer-card { padding: 20px 40px; }
        }
    </style>
</head>
<body>
    <div class="timer-container">
        <div class="chest-badge" id="chestLabelContainer" style="display: <?= $initChest !== '—' ? 'block' : 'none' ?>;">
            <span id="chestLabel"><?= $isGroupProg ? 'GROUP' : 'CHEST' ?></span>
        </div>
        <div class="chest-number-value" id="chestDisplay"><?= htmlspecialchars($initChest) ?></div>
        
        <div class="timer-card <?= $initTimerRunning ? 'is-running' : '' ?>" id="timerCard">
            <div style="display: flex; align-items: center; gap: 14px;">
                <span class="pulse-indicator"></span>
                <span class="timer-display" id="timerDisplay">00:00</span>
            </div>
        </div>
        
        <div class="performer-name" id="performerName"><?= htmlspecialchars(!empty($initPerf['name']) ? $initPerf['name'] : '') ?></div>
    </div>

    <script>
        const API_URL = '../live-display/api/current-program';
        
        let isRunning = <?= $initTimerRunning ?>;
        let startTime = <?= $initTimerStartTime ?>;
        let elapsed = <?= $initTimerElapsed ?>;
        
        let localStartTime = 0;
        let timerInterval = null;
        let pollTimeout = null;

        function formatTime(ms) {
            if (ms < 0) ms = 0;
            const totalSec = Math.floor(ms / 1000);
            const m = Math.floor(totalSec / 60);
            const s = totalSec % 60;
            return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }

        function updateLocalClock() {
            if (!isRunning) return;
            const delta = performance.now() - localStartTime;
            const totalMs = elapsed + delta;
            document.getElementById('timerDisplay').textContent = formatTime(totalMs);
        }

        function startLocalTimer() {
            if (timerInterval) clearInterval(timerInterval);
            localStartTime = performance.now();
            timerInterval = setInterval(updateLocalClock, 100);
            document.getElementById('timerCard').classList.add('is-running');
        }

        function stopLocalTimer() {
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
            document.getElementById('timerCard').classList.remove('is-running');
            document.getElementById('timerDisplay').textContent = formatTime(elapsed);
        }

        async function syncState() {
            try {
                const response = await fetch(API_URL);
                const data = await response.json();
                
                if (data && data.success) {
                    const settings = data.settings || {};
                    const current = data.current || {};
                    const perf = current.performer || {};
                    const prog = current.program || {};
                    
                    // Sync Performer Chest
                    const isGroup = (prog.program_type === 'group' || !!prog.only_team_marks);
                    const chestVal = isGroup ? (perf.entry_name || '—') : (perf.chest_number || perf.number || '—');
                    
                    const chestDisplay = document.getElementById('chestDisplay');
                    const chestLabel = document.getElementById('chestLabel');
                    const labelBox = document.getElementById('chestLabelContainer');
                    
                    if (chestDisplay) chestDisplay.textContent = chestVal;
                    if (chestLabel) chestLabel.textContent = isGroup ? 'GROUP' : 'CHEST';
                    if (labelBox) labelBox.style.display = (chestVal !== '—') ? 'block' : 'none';
                    
                    // Sync Performer Name
                    const perfName = document.getElementById('performerName');
                    if (perfName) perfName.textContent = perf.name || '';
                    
                    // Sync Timer State
                    const sRunning = parseInt(settings.live_timer_running) || 0;
                    const sStartTime = parseFloat(settings.live_timer_start_time) || 0;
                    const sElapsed = parseInt(settings.live_timer_elapsed) || 0;
                    
                    const isSRunning = (sRunning === 1);
                    
                    let needsSync = false;
                    if (isRunning !== isSRunning) {
                        needsSync = true;
                    } else if (isSRunning) {
                        // Compare start time to check for discrepancies (threshold 1.2s)
                        const localStartUnix = (Date.now() - (performance.now() - localStartTime)) / 1000;
                        if (Math.abs(localStartUnix - sStartTime) > 1.2) {
                            needsSync = true;
                        }
                    } else {
                        if (Math.abs(elapsed - sElapsed) > 1200) {
                            needsSync = true;
                        }
                    }
                    
                    if (needsSync) {
                        isRunning = isSRunning;
                        elapsed = sElapsed;
                        startTime = sStartTime;
                        
                        if (isRunning) {
                            const elapsedSinceStart = (Date.now() / 1000 - startTime) * 1000;
                            localStartTime = performance.now();
                            elapsed = elapsedSinceStart;
                            startLocalTimer();
                        } else {
                            stopLocalTimer();
                        }
                    }
                }
            } catch (err) {
                console.error('Error syncing:', err);
            }
            
            // Poll faster (300ms) if timer is running, otherwise every 800ms
            const nextPollInterval = isRunning ? 300 : 800;
            pollTimeout = setTimeout(syncState, nextPollInterval);
        }

        // Initialize
        if (isRunning) {
            const elapsedSinceStart = (Date.now() / 1000 - startTime) * 1000;
            elapsed = elapsedSinceStart;
            startLocalTimer();
        } else {
            stopLocalTimer();
        }

        // Start syncing
        pollTimeout = setTimeout(syncState, 500);
    </script>
</body>
</html>
