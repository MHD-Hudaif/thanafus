<?php
declare(strict_types=1);

if (!defined('TV_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'current-program';
    $settings['slides']['current-program']['enabled'] = true;
    $settings['slides']['current-program']['duration'] = 999999;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-current-program" data-slide="current-program" style="opacity: 1; visibility: visible; transform: scale(1);">';
}
?>
<div class="tv-now">
    <div class="tv-now-main">
        <div class="tv-now-brow">
            <div class="tv-kicker" data-current-stage>Main Stage</div>
            <span class="tv-stage-chip" data-current-status>Break</span>
        </div>
        <h1 data-current-title>Break Time</h1>
        <div class="tv-now-performer" data-current-performer>No active performer</div>
        <div class="tv-team-badge" data-current-team>Awaiting next program</div>

        <!-- Ambient Voice Wave Visualizer Widget & Page Element Styles -->
        <style>
            .tv-now-brow {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 24px;
            }
            .tv-kicker {
                font-size: 13px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.15em;
                color: var(--turquoise);
                text-shadow: 0 0 10px rgba(0, 255, 216, 0.3);
            }
            .tv-stage-chip {
                padding: 6px 14px;
                border-radius: 99px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                background: rgba(31, 224, 138, 0.1);
                color: var(--emerald);
                border: 1px solid rgba(31, 224, 138, 0.2);
                box-shadow: 0 0 15px rgba(31, 224, 138, 0.1);
            }
            .voice-wave-widget {
                margin-top: 32px;
                position: relative;
                width: 100%;
                height: 90px;
                background: rgba(255, 255, 255, 0.01);
                border: 1px solid rgba(255, 255, 255, 0.05);
                border-radius: 18px;
                overflow: hidden;
                backdrop-filter: blur(12px);
                box-shadow: inset 0 0 25px rgba(0, 0, 0, 0.3);
                cursor: pointer;
                transition: all 0.3s ease;
            }
            .voice-wave-widget:hover {
                border-color: rgba(255, 255, 255, 0.1);
                background: rgba(255, 255, 255, 0.02);
            }
            .wave-canvas {
                width: 100%;
                height: 100%;
                display: block;
            }
            .wave-overlay-msg {
                position: absolute;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                color: rgba(255, 255, 255, 0.55);
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                background: rgba(0, 0, 0, 0.45);
                transition: opacity 0.3s ease;
                pointer-events: none;
            }
            .wave-overlay-msg.hidden {
                opacity: 0;
            }
            .wave-overlay-msg i {
                font-size: 15px;
                color: var(--turquoise);
                animation: mic-pulse 1.5s infinite;
            }
            @keyframes mic-pulse {
                0% { opacity: 0.5; transform: scale(0.95); }
                50% { opacity: 1; transform: scale(1.05); }
                100% { opacity: 0.5; transform: scale(0.95); }
            }
            .tv-now-progress {
                margin-top: 36px;
            }
            .tv-now-progress-head {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px;
                font-size: 13px;
            }
            .tv-now-progress-head span {
                color: var(--muted);
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                opacity: 0.75;
            }
            .tv-now-progress-head strong {
                color: var(--emerald);
                font-weight: 700;
            }
            .tv-now-progress-track {
                height: 8px;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 99px;
                overflow: hidden;
                border: 1px solid rgba(255, 255, 255, 0.02);
            }
            .tv-now-progress-track span {
                display: block;
                height: 100%;
                width: 0%;
                background: linear-gradient(90deg, var(--emerald), var(--turquoise));
                border-radius: 99px;
                box-shadow: 0 0 12px rgba(31, 224, 138, 0.4);
                transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .tv-now-meta-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 24px;
                margin-top: 40px;
                border-top: 1px solid rgba(255, 255, 255, 0.08);
                padding-top: 30px;
            }
            .tv-now-meta-grid > div {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .tv-now-meta-grid span {
                font-size: 11px;
                text-transform: uppercase;
                color: var(--muted);
                opacity: 0.5;
                letter-spacing: 0.1em;
                font-weight: 600;
            }
            .tv-now-meta-grid strong {
                font-size: clamp(20px, 2vw, 28px);
                font-weight: 700;
                color: var(--text);
            }
        </style>

        <div class="voice-wave-widget" id="voiceWaveWidget" title="Click to activate voice wave">
            <canvas id="waveCanvas" class="wave-canvas"></canvas>
            <div id="waveOverlayMsg" class="wave-overlay-msg">
                <i class="fas fa-microphone"></i> Click to activate voice visualizer
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const widget = document.getElementById('voiceWaveWidget');
                const overlayMsg = document.getElementById('waveOverlayMsg');
                const canvas = document.getElementById('waveCanvas');
                const canvasCtx = canvas.getContext('2d');
                
                let audioCtx;
                let analyser;
                let source;
                let stream;
                let isInitialized = false;
                let animationId;
                let bufferLength = 0;
                let dataArray;
                
                // Ambient animation parameters
                let phase = 0;

                function resizeCanvas() {
                    canvas.width = canvas.clientWidth * window.devicePixelRatio;
                    canvas.height = canvas.clientHeight * window.devicePixelRatio;
                }
                
                resizeCanvas();
                window.addEventListener('resize', resizeCanvas);

                // Start ambient idle waves
                function drawIdle() {
                    if (isInitialized) return;
                    animationId = requestAnimationFrame(drawIdle);
                    
                    canvasCtx.clearRect(0, 0, canvas.width, canvas.height);
                    
                    // Clear background
                    canvasCtx.fillStyle = 'rgba(20, 20, 20, 0.1)';
                    canvasCtx.fillRect(0, 0, canvas.width, canvas.height);
                    
                    phase += 0.04;
                    drawSiriWaves(0.04); // Idle small amplitude
                }
                drawIdle();

                async function initVisualizer() {
                    if (isInitialized) return;
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        
                        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                        analyser = audioCtx.createAnalyser();
                        analyser.fftSize = 256;
                        analyser.smoothingTimeConstant = 0.8;
                        
                        source = audioCtx.createMediaStreamSource(stream);
                        source.connect(analyser);
                        
                        bufferLength = analyser.frequencyBinCount;
                        dataArray = new Uint8Array(bufferLength);
                        
                        isInitialized = true;
                        overlayMsg.classList.add('hidden');
                        
                        cancelAnimationFrame(animationId);
                        drawLive();
                    } catch (err) {
                        console.error('Microphone access denied:', err);
                        overlayMsg.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Microphone permission denied';
                    }
                }

                widget.addEventListener('click', initVisualizer);
                // Also trigger if they click anywhere on the page
                document.addEventListener('click', () => {
                    if (!isInitialized) initVisualizer();
                }, { once: true });

                function drawLive() {
                    animationId = requestAnimationFrame(drawLive);
                    
                    analyser.getByteFrequencyData(dataArray);
                    
                    // Calculate overall volume level
                    let sum = 0;
                    for (let i = 0; i < bufferLength; i++) {
                        sum += dataArray[i];
                    }
                    const averageVolume = sum / bufferLength;
                    const volumeNorm = Math.min(1, averageVolume / 128.0); // 0 to 1 scale
                    
                    canvasCtx.clearRect(0, 0, canvas.width, canvas.height);
                    canvasCtx.fillStyle = 'rgba(20, 20, 20, 0.15)';
                    canvasCtx.fillRect(0, 0, canvas.width, canvas.height);
                    
                    // 1. Draw Sound Graph (Equalizer Bars) at the bottom
                    drawEqualizerBars();

                    // 2. Draw Siri Waves on top
                    phase += 0.1 + (volumeNorm * 0.1);
                    drawSiriWaves(0.1 + (volumeNorm * 0.8));
                }

                function drawEqualizerBars() {
                    const style = getComputedStyle(document.documentElement);
                    const primaryColor = style.getPropertyValue('--turquoise').trim() || '#00ffd8';
                    const secondaryColor = style.getPropertyValue('--emerald').trim() || '#1fe08a';

                    const barCount = 32;
                    const barWidth = (canvas.width / barCount);
                    const spacing = 4;
                    const activeWidth = barWidth - spacing;

                    // Draw symmetrical bars from center or standard left-to-right
                    for (let i = 0; i < barCount; i++) {
                        // Map frequency bins to the bar count
                        const binIndex = Math.floor((i / barCount) * (bufferLength * 0.6));
                        const rawValue = dataArray[binIndex] || 0;
                        const valueNorm = rawValue / 255.0;
                        
                        // Minimum height of 4px for ambient movement, max 75% height
                        const minHeight = 4 * window.devicePixelRatio;
                        const barHeight = Math.max(minHeight, valueNorm * canvas.height * 0.65);
                        
                        const x = (i * barWidth) + (spacing / 2);
                        const y = canvas.height - barHeight;

                        // Create gradient for the bar
                        const gradient = canvasCtx.createLinearGradient(x, y, x, canvas.height);
                        gradient.addColorStop(0, primaryColor);
                        gradient.addColorStop(1, `color-mix(in srgb, ${secondaryColor} 20%, transparent)`);
                        
                        canvasCtx.fillStyle = gradient;
                        // Rounded top corners for bars
                        canvasCtx.beginPath();
                        canvasCtx.roundRect(x, y, activeWidth, barHeight, [4 * window.devicePixelRatio, 4 * window.devicePixelRatio, 0, 0]);
                        canvasCtx.fill();
                    }
                }

                function drawSiriWaves(ampScale) {
                    const waveCount = 4;
                    const style = getComputedStyle(document.documentElement);
                    const primaryColor = style.getPropertyValue('--turquoise').trim() || '#00ffd8';
                    const secondaryColor = style.getPropertyValue('--emerald').trim() || '#1fe08a';
                    
                    const colors = [
                        `color-mix(in srgb, ${primaryColor} 80%, transparent)`,
                        `color-mix(in srgb, ${secondaryColor} 50%, transparent)`,
                        `color-mix(in srgb, ${primaryColor} 25%, transparent)`,
                        `color-mix(in srgb, ${secondaryColor} 12%, transparent)`
                    ];
                    
                    for (let w = 0; w < waveCount; w++) {
                        canvasCtx.beginPath();
                        canvasCtx.lineWidth = w === 0 ? 3 : 1.5;
                        canvasCtx.strokeStyle = colors[w];
                        
                        // Vary parameters per wave layer
                        const layerPhase = phase + (w * Math.PI / 4);
                        const frequency = 0.015 + (w * 0.005);
                        const amplitude = (canvas.height * 0.35) * ampScale * (1 - (w * 0.22));
                        
                        for (let x = 0; x < canvas.width; x++) {
                            // Siri style tapering: zero amplitude at edges, max in the middle
                            const taper = Math.sin((x / canvas.width) * Math.PI);
                            const y = (canvas.height / 2) + Math.sin(x * frequency + layerPhase) * amplitude * taper;
                            
                            if (x === 0) {
                                canvasCtx.moveTo(x, y);
                            } else {
                                canvasCtx.lineTo(x, y);
                            }
                        }
                        canvasCtx.stroke();
                    }
                }
            });
        </script>

        <div class="tv-now-progress">
            <div class="tv-now-progress-head">
                <span>Entry Progress</span>
                <strong data-current-progress-label>Waiting for entries</strong>
            </div>
            <div class="tv-now-progress-track"><span data-current-progress-fill></span></div>
        </div>

        <div class="tv-now-meta-grid">
            <div>
                <span>Category</span>
                <strong data-current-category>All Classes</strong>
            </div>
            <div>
                <span>Entries</span>
                <strong data-current-entry-count>0</strong>
            </div>
            <div>
                <span>Room</span>
                <strong data-current-room>Main Hall</strong>
            </div>
        </div>
    </div>
    <div class="tv-now-side">
        <div class="tv-card">
            <div class="tv-card-label">Next Performer</div>
            <div class="tv-card-value" data-next-performer style="margin-bottom: 8px;">Queued automatically</div>
            <div class="tv-card-sub" data-next-team>Team details pending</div>
        </div>
        <div class="tv-card">
            <div class="tv-card-label">Judges</div>
            <div class="tv-card-value" data-judges style="font-size: 20px; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px;">Panel pending</div>
        </div>
        <div class="tv-card">
            <div class="tv-card-label">Next Program</div>
            <div class="tv-card-value" data-next-program style="font-size: 22px;">Schedule pending</div>
        </div>
    </div>
</div>
<?php
if (!defined('TV_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
