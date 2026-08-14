<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/public-data.php';

$pdo = $GLOBALS['musabaqa_pdo'];

// Function to fetch the Emcee Passkey securely
function get_emcee_passkey($pdo): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'global_musabaqa_settings' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['setting_value'])) {
        $settings = json_decode($row['setting_value'], true);
        if (isset($settings['emcee_passkey'])) {
            return (string)$settings['emcee_passkey'];
        }
    }
    return '8888'; // Default fallback
}

// Handle AJAX verification POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auth_emcee') {
    header('Content-Type: application/json');
    $pin = trim((string)($_POST['pin'] ?? ''));
    $emceePasskey = get_emcee_passkey($pdo);
    
    if ($pin === $emceePasskey) {
        $_SESSION['emcee_authenticated'] = true;
        echo json_encode(['success' => true, 'redirect' => 'emcee/index.php']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid Emcee Passkey!']);
    }
    exit;
}

$event = tv_active_event();
$eventTitle = trim((string)($event['title'] ?? 'Kauzariyya Musabaqa 2026-27'));
$eventTitle = $eventTitle !== '' ? $eventTitle : 'Kauzariyya Musabaqa 2026-27';
$eventStart = !empty($event['start_date']) ? (string)$event['start_date'] : '2027-05-04T09:00:00';
$eventDateFormatted = !empty($event['start_date']) 
    ? date('d F Y', strtotime((string)$event['start_date'])) 
    : '4 - 5 May 2027';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($eventTitle) ?> | Live Timer</title>
    
    <!-- CSS Dependencies -->
    <link rel="stylesheet" href="<?= asset_url('css/intro.css') ?>?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #092e1e 0%, #05160e 100%);
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            position: relative;
            overflow: hidden;
        }

        /* Ambient glow background elements */
        .ambient-glow {
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(168, 136, 58, 0.15) 0%, rgba(0, 0, 0, 0) 70%);
            filter: blur(50px);
            z-index: 0;
            pointer-events: none;
        }
        .glow-top { top: -50px; right: -50px; }
        .glow-bottom { bottom: -50px; left: -50px; }

        header {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: transparent;
            border-bottom: none;
            position: relative;
            z-index: 10;
        }

        .logo-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-text {
            font-family: 'Outfit', sans-serif;
            font-weight: 900;
            font-size: 1.4rem;
            letter-spacing: -1px;
            color: #ffffff;
        }
        .logo-dot {
            color: var(--brand-gold, #a8883a);
        }

        .key-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffd700; /* Gold */
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .key-btn:active {
            transform: scale(0.92);
            background: rgba(255, 255, 255, 0.15);
        }

        main {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 20px;
            position: relative;
            z-index: 10;
            text-align: center;
        }

        .fest-badge {
            background: rgba(168, 136, 58, 0.15);
            border: 1px solid rgba(168, 136, 58, 0.3);
            color: #ffd700;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 16px;
            display: inline-block;
        }

        .fest-title {
            font-family: 'Outfit', sans-serif;
            font-size: 2.2rem;
            font-weight: 900;
            line-height: 1.2;
            margin: 0 0 8px 0;
            color: #ffffff;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .fest-date {
            font-size: 0.95rem;
            color: #a8b2a9;
            margin-bottom: 32px;
            font-weight: 500;
        }

        /* Centered and scaled countdown layout */
        .countdown-container {
            display: flex;
            gap: 12px;
            justify-content: center;
            width: 100%;
            max-width: 360px;
            margin: 0 auto;
        }

        .countdown-box {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 14px 10px;
            flex: 1;
            min-width: 0;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        .countdown-value {
            font-family: 'Outfit', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            color: #ffd700;
            line-height: 1.1;
        }

        .countdown-label {
            font-size: 0.7rem;
            color: #a8b2a9;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 1px;
            margin-top: 4px;
        }

        footer {
            padding: 24px;
            text-align: center;
            font-size: 0.8rem;
            color: #7b887d;
            position: relative;
            z-index: 10;
        }

        /* Modal Overlay Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            padding: 20px;
        }

        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-card {
            background: rgba(20, 35, 28, 0.95);
            border: 1px solid rgba(168, 136, 58, 0.25);
            border-radius: 24px;
            width: 100%;
            max-width: 320px;
            padding: 28px;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.5);
            transform: scale(0.9);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            text-align: center;
        }

        .modal-overlay.active .modal-card {
            transform: scale(1);
        }

        .modal-icon {
            width: 60px;
            height: 60px;
            background: rgba(168, 136, 58, 0.15);
            border: 1px solid rgba(168, 136, 58, 0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #ffd700;
            margin: 0 auto 16px auto;
        }

        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 800;
            margin-bottom: 6px;
            color: #ffffff;
        }

        .modal-desc {
            font-size: 0.85rem;
            color: #a8b2a9;
            margin-bottom: 20px;
        }

        .pin-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 12px;
            color: #38bdf8;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: 6px;
            text-align: center;
            margin-bottom: 12px;
            outline: none;
            transition: all 0.3s ease;
        }

        .pin-input:focus {
            border-color: #38bdf8;
            background: rgba(0, 0, 0, 0.5);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
        }

        .error-msg {
            color: #ef4444;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 16px;
            display: none;
            text-align: center;
        }

        .modal-btn {
            width: 100%;
            padding: 12px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }

        .btn-submit {
            background: linear-gradient(135deg, #1b4332 0%, #2d6a4f 100%);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 10px;
            box-shadow: 0 4px 12px rgba(27, 67, 50, 0.3);
        }

        .btn-submit:active {
            transform: scale(0.98);
        }

        .btn-cancel {
            background: transparent;
            color: #a8b2a9;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .btn-cancel:active {
            background: rgba(255, 255, 255, 0.05);
        }
    </style>
</head>
<body>
    <div class="ambient-glow glow-top"></div>
    <div class="ambient-glow glow-bottom"></div>

    <header>
        <div class="logo-wrap">
            <span class="logo-text">THANAFUS<span class="logo-dot">.</span></span>
        </div>
        <button class="key-btn" id="openAuthBtn" aria-label="Unlock Emcee Controls">
            <i class="fa-solid fa-key"></i>
        </button>
    </header>

    <main>
        <div class="fest-badge">Annual Arts Fest</div>
        <h1 class="fest-title"><?= htmlspecialchars($eventTitle) ?></h1>
        <p class="fest-date">
            <i class="fa-solid fa-calendar-days" style="margin-right: 6px; color: var(--brand-gold);"></i> 
            <?= htmlspecialchars($eventDateFormatted) ?>
        </p>

        <!-- Countdown Container -->
        <div class="countdown-container" id="countdown" data-target-date="<?= htmlspecialchars($eventStart) ?>">
            <div class="countdown-box">
                <div class="countdown-value" id="days-val">00</div>
                <div class="countdown-label">Days</div>
            </div>
            <div class="countdown-box">
                <div class="countdown-value" id="hours-val">00</div>
                <div class="countdown-label">Hours</div>
            </div>
            <div class="countdown-box">
                <div class="countdown-value" id="minutes-val">00</div>
                <div class="countdown-label">Mins</div>
            </div>
            <div class="countdown-box">
                <div class="countdown-value" id="seconds-val">00</div>
                <div class="countdown-label">Secs</div>
            </div>
        </div>
    </main>

    <footer>
        &copy; 2026 Al Jamiathul Kauzariyya
    </footer>

    <!-- Passkey Authentication Modal -->
    <div class="modal-overlay" id="authModal">
        <div class="modal-card">
            <div class="modal-icon">
                <i class="fa-solid fa-lock"></i>
            </div>
            <h3 class="modal-title">Emcee Controls</h3>
            <p class="modal-desc">Please enter the security passkey to unlock the Stage Control Deck.</p>
            
            <input type="password" id="pinInput" class="pin-input" placeholder="••••" maxlength="8" autocomplete="off" inputmode="numeric">
            
            <div class="error-msg" id="errorMsg">Invalid Passkey PIN!</div>
            
            <button class="modal-btn btn-submit" id="submitPinBtn">Unlock Deck</button>
            <button class="modal-btn btn-cancel" id="closeAuthBtn">Cancel</button>
        </div>
    </div>

    <!-- Countdown Javascript -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Live Countdown Timer
            const countdownEl = document.getElementById('countdown');
            if (countdownEl) {
                const targetDateStr = countdownEl.getAttribute('data-target-date');
                const targetDate = targetDateStr ? new Date(targetDateStr).getTime() : new Date('2027-05-04T09:00:00').getTime();
                
                const daysVal = document.getElementById('days-val');
                const hoursVal = document.getElementById('hours-val');
                const minutesVal = document.getElementById('minutes-val');
                const secondsVal = document.getElementById('seconds-val');
                
                function updateCountdown() {
                    const now = new Date().getTime();
                    const distance = targetDate - now;
                    
                    if (distance < 0) {
                        if (daysVal) daysVal.innerText = '00';
                        if (hoursVal) hoursVal.innerText = '00';
                        if (minutesVal) minutesVal.innerText = '00';
                        if (secondsVal) secondsVal.innerText = '00';
                        return;
                    }
                    
                    const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                    
                    if (daysVal) daysVal.innerText = String(days).padStart(2, '0');
                    if (hoursVal) hoursVal.innerText = String(hours).padStart(2, '0');
                    if (minutesVal) minutesVal.innerText = String(minutes).padStart(2, '0');
                    if (secondsVal) secondsVal.innerText = String(seconds).padStart(2, '0');
                }
                
                updateCountdown();
                setInterval(updateCountdown, 1000);
            }

            // Modal Controls
            const authModal = document.getElementById('authModal');
            const openAuthBtn = document.getElementById('openAuthBtn');
            const closeAuthBtn = document.getElementById('closeAuthBtn');
            const submitPinBtn = document.getElementById('submitPinBtn');
            const pinInput = document.getElementById('pinInput');
            const errorMsg = document.getElementById('errorMsg');

            function openModal() {
                authModal.classList.add('active');
                pinInput.value = '';
                errorMsg.style.display = 'none';
                setTimeout(() => pinInput.focus(), 150);
            }

            function closeModal() {
                authModal.classList.remove('active');
            }

            openAuthBtn.addEventListener('click', openModal);
            closeAuthBtn.addEventListener('click', closeModal);

            // Handle submission
            function submitPin() {
                const pin = pinInput.value.trim();
                if (pin === '') {
                    errorMsg.textContent = 'Please enter a PIN!';
                    errorMsg.style.display = 'block';
                    return;
                }

                submitPinBtn.disabled = true;
                submitPinBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking...';
                errorMsg.style.display = 'none';

                const formData = new FormData();
                formData.append('action', 'auth_emcee');
                formData.append('pin', pin);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    submitPinBtn.disabled = false;
                    submitPinBtn.textContent = 'Unlock Deck';
                    if (data.success && data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        errorMsg.textContent = data.message || 'Invalid PIN!';
                        errorMsg.style.display = 'block';
                        pinInput.focus();
                    }
                })
                .catch(err => {
                    submitPinBtn.disabled = false;
                    submitPinBtn.textContent = 'Unlock Deck';
                    errorMsg.textContent = 'Network error, please try again.';
                    errorMsg.style.display = 'block';
                });
            }

            submitPinBtn.addEventListener('click', submitPin);
            pinInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    submitPin();
                }
            });

            // If URL has unauthorized error, show warning
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('error') === 'unauthorized') {
                alert('Access Denied: Please authenticate with the passkey to access Emcee controls.');
            }
        });
    </script>
</body>
</html>
