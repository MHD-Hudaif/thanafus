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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'auth_emcee') {
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
    } elseif ($_POST['action'] === 'auth_special') {
        header('Content-Type: application/json');
        $pin = trim((string)($_POST['pin'] ?? ''));
        if ($pin === '7777') {
            $_SESSION['special_authenticated'] = true;
            echo json_encode(['success' => true, 'redirect' => 'special/index.php']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid Special Passkey!']);
        }
        exit;
    }
}

$event = tv_active_event();
$eventTitle = trim((string)($event['title'] ?? 'Kauzariyya Musabaqa 2026-27'));
$eventTitle = $eventTitle !== '' ? $eventTitle : 'Kauzariyya Musabaqa 2026-27';
$eventStart = !empty($event['start_date']) ? str_replace(' ', 'T', (string)$event['start_date']) : '2027-05-04T09:00:00';
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('css/intro.css') ?>?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/mobile-app.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        window.isLowEndDevice = 
            (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) ||
            (navigator.deviceMemory && navigator.deviceMemory < 4) ||
            /SmartTV|GoogleTV|AppleTV|HbbTV|Tizen|WebOS|Android 9|Android 8|Android 7|Android 6|Android 5/i.test(navigator.userAgent) ||
            window.location.search.includes('perf=low');
        if (window.isLowEndDevice) {
            document.documentElement.classList.add('low-perf-device');
        }
    </script>
    <style>
        /* Low performance device overrides */
        .low-perf-device .ambient-glow,
        .low-perf-device .orb {
            display: none !important;
        }
        .low-perf-device .glass-card,
        .low-perf-device .site-header,
        .low-perf-device .download-card,
        .low-perf-device header {
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            background: #0f1c12 !important;
            box-shadow: none !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }
        .low-perf-device * {
            text-shadow: none !important;
            box-shadow: none !important;
            transition: none !important;
        }
    </style>
    <style>
        :root {
          --brand-green: #6f9e7a;
          --brand-green-dark: #3a5e44;
          --brand-gold: #c9a86c;
          --brand-gold-glow: rgba(201, 168, 108, 0.30);
          --bg-dark: #faf7f0;           /* warm cream base */
          --card-glass: rgba(255, 250, 240, 0.70);
          --border-glass: rgba(200, 180, 150, 0.25);
          --text-main: #2e2b27;
          --text-muted: #6b6258;
        }

        * {
          box-sizing: border-box;
          -webkit-touch-callout: none !important;
          -webkit-user-select: none !important;
          -khtml-user-select: none !important;
          -moz-user-select: none !important;
          -ms-user-select: none !important;
          user-select: none !important;
          -webkit-tap-highlight-color: transparent !important;
        }

        input, textarea, select, [contenteditable="true"] {
          -webkit-user-select: text !important;
          -moz-user-select: text !important;
          -ms-user-select: text !important;
          user-select: text !important;
        }

        body {
          display: flex;
          flex-direction: column;
          justify-content: space-between;
          min-height: 100vh;
          margin: 0;
          background: #faf7f0;
          background-image: 
            radial-gradient(at 0% 0%, rgba(180, 200, 160, 0.20) 0px, transparent 50%),
            radial-gradient(at 100% 100%, rgba(210, 185, 140, 0.20) 0px, transparent 50%),
            radial-gradient(at 50% 50%, rgba(235, 225, 210, 0.60) 0px, transparent 100%);
          color: var(--text-main);
          font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
          position: relative;
          overflow-x: hidden;
        }

        /* Ambient glowing blobs — softer, warmer */
        .ambient-glow {
          position: absolute;
          width: 360px;
          height: 360px;
          border-radius: 50%;
          filter: blur(75px);
          z-index: 0;
          pointer-events: none;
          opacity: 0.45;
          animation: blobPulse 8s infinite alternate ease-in-out;
        }

        .glow-top { 
          top: -100px; 
          right: -80px; 
          background: radial-gradient(circle, rgba(180, 200, 160, 0.35) 0%, rgba(0, 0, 0, 0) 70%);
        }

        .glow-bottom { 
          bottom: -100px; 
          left: -80px; 
          background: radial-gradient(circle, rgba(210, 185, 140, 0.30) 0%, rgba(0, 0, 0, 0) 70%);
          animation-delay: -4s;
        }

        @keyframes blobPulse {
          0% { transform: scale(1) translate(0, 0); }
          100% { transform: scale(1.15) translate(20px, 20px); }
        }

        /* Header — lighter glass */
        header {
          width: 100%;
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding: 20px 24px;
          background: rgba(255, 250, 240, 0.55);
          backdrop-filter: blur(14px);
          -webkit-backdrop-filter: blur(14px);
          border-bottom: 1px solid rgba(180, 160, 140, 0.20);
          position: relative;
          z-index: 10;
        }

        .logo-wrap {
          display: flex;
          align-items: center;
          gap: 12px;
        }

        .logo-badge {
          width: 40px;
          height: 40px;
          border-radius: 12px;
          background: linear-gradient(135deg, rgba(150, 180, 140, 0.25), rgba(200, 175, 130, 0.20));
          border: 1px solid rgba(160, 180, 140, 0.35);
          display: flex;
          align-items: center;
          justify-content: center;
          color: #5d7e5a;
          font-size: 18px;
          box-shadow: 0 4px 14px rgba(150, 170, 130, 0.15);
        }

        .logo-text-group {
          display: flex;
          flex-direction: column;
        }

        .logo-text {
          font-family: 'Outfit', sans-serif;
          font-weight: 900;
          font-size: 1.35rem;
          letter-spacing: -0.5px;
          color: #2e2b27;
          line-height: 1;
        }

        .logo-dot {
          color: #b8955e;
        }

        .logo-subtext {
          font-size: 10px;
          font-weight: 700;
          text-transform: uppercase;
          letter-spacing: 1.5px;
          color: #7b7266;
          margin-top: 3px;
        }

        .key-btn {
          background: rgba(160, 180, 145, 0.18);
          border: 1px solid rgba(150, 170, 135, 0.40);
          color: #4b6b47;
          width: 44px;
          height: 44px;
          border-radius: 14px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 18px;
          cursor: pointer;
          transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
          box-shadow: 0 4px 16px rgba(150, 170, 130, 0.15);
        }

        .key-btn:hover {
          background: rgba(160, 180, 140, 0.30);
          border-color: rgba(130, 160, 115, 0.6);
          color: #1f2e1c;
          transform: rotate(30deg) scale(1.06);
          box-shadow: 0 6px 20px rgba(150, 170, 130, 0.25);
        }

        .key-btn:active {
          transform: scale(0.92);
        }

        /* Main */
        main {
          flex-grow: 1;
          display: flex;
          flex-direction: column;
          justify-content: center;
          align-items: center;
          padding: 32px 20px;
          position: relative;
          z-index: 10;
          text-align: center;
        }

        .fest-badge {
          background: linear-gradient(135deg, rgba(200, 175, 130, 0.20) 0%, rgba(160, 185, 145, 0.15) 100%);
          border: 1px solid rgba(190, 165, 120, 0.50);
          color: #6d5f47;
          padding: 8px 20px;
          border-radius: 9999px;
          font-size: 11.5px;
          font-weight: 800;
          letter-spacing: 2px;
          text-transform: uppercase;
          margin-bottom: 20px;
          display: inline-flex;
          align-items: center;
          gap: 8px;
          box-shadow: 0 4px 18px rgba(200, 175, 135, 0.15);
        }

        .badge-pulse {
          width: 8px;
          height: 8px;
          border-radius: 50%;
          background: #b8955e;
          box-shadow: 0 0 0 0 rgba(200, 170, 120, 0.7);
          animation: badgePulseDot 1.8s infinite ease-in-out;
        }

        @keyframes badgePulseDot {
          0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(200, 170, 120, 0.7); }
          70% { transform: scale(1.2); box-shadow: 0 0 0 8px rgba(200, 170, 120, 0); }
          100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(200, 170, 120, 0); }
        }

        .fest-title {
          font-family: 'Outfit', sans-serif;
          font-size: clamp(2rem, 6vw, 2.75rem);
          font-weight: 900;
          line-height: 1.15;
          margin: 0 0 12px 0;
          background: linear-gradient(135deg, #3f3a32 0%, #6f6a5f 40%, #4f604a 100%);
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
          text-shadow: 0 2px 12px rgba(0,0,0,0.05);
          letter-spacing: -0.5px;
        }

        .fest-date {
          font-size: 0.95rem;
          color: #6b6258;
          margin-bottom: 36px;
          font-weight: 600;
          display: inline-flex;
          align-items: center;
          gap: 8px;
          background: rgba(255, 250, 240, 0.6);
          padding: 8px 18px;
          border-radius: 14px;
          border: 1px solid rgba(180, 160, 140, 0.25);
          backdrop-filter: blur(10px);
        }

        /* Countdown grid — warm glass */
        .countdown-container {
          display: grid;
          grid-template-columns: repeat(4, 1fr);
          gap: 12px;
          width: 100%;
          max-width: 380px;
          margin: 0 auto;
        }

        .countdown-box {
          background: linear-gradient(145deg, rgba(235, 225, 210, 0.55) 0%, rgba(245, 240, 230, 0.75) 100%);
          backdrop-filter: blur(12px);
          -webkit-backdrop-filter: blur(12px);
          border: 1px solid rgba(190, 175, 155, 0.30);
          border-radius: 20px;
          padding: 16px 8px;
          box-shadow: 0 10px 30px rgba(140, 120, 100, 0.12), inset 0 1px 0 rgba(255, 255, 240, 0.6);
          transition: all 0.3s ease;
          position: relative;
          overflow: hidden;
        }

        .countdown-box::before {
          content: '';
          position: absolute;
          top: 0; left: 0; right: 0;
          height: 1px;
          background: linear-gradient(90deg, transparent, rgba(180, 165, 140, 0.30), transparent);
        }

        .countdown-box:hover {
          transform: translateY(-3px);
          border-color: rgba(170, 150, 120, 0.45);
          box-shadow: 0 14px 36px rgba(160, 150, 130, 0.15);
        }

        .countdown-value {
          font-family: 'Outfit', sans-serif;
          font-size: 2.2rem;
          font-weight: 900;
          background: linear-gradient(135deg, #b8955e 0%, #9f7a47 100%);
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
          line-height: 1.05;
        }

        .countdown-label {
          font-size: 0.72rem;
          color: #7b7266;
          text-transform: uppercase;
          font-weight: 800;
          letter-spacing: 1.2px;
          margin-top: 6px;
        }

        /* Event Live! banner — cream + gold */
        .countdown-container-live {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          margin: 1.2rem 0;
          width: auto;
        }

        .event-live-banner {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 16px;
          padding: 16px 42px;
          border-radius: 9999px;
          background: radial-gradient(circle at 20% 20%, rgba(200, 180, 150, 0.35) 0%, rgba(235, 225, 210, 0.90) 80%),
                      linear-gradient(135deg, rgba(210, 195, 170, 0.25) 0%, rgba(245, 240, 230, 0.95) 100%);
          border: 1.5px solid rgba(180, 165, 130, 0.60);
          box-shadow: 0 0 35px rgba(180, 165, 130, 0.25), inset 0 1px 0 rgba(255, 255, 240, 0.7);
          backdrop-filter: blur(14px);
          -webkit-backdrop-filter: blur(14px);
          animation: eventLiveGlow 2.5s infinite ease-in-out;
        }

        .live-dot-pulse {
          width: 15px;
          height: 15px;
          border-radius: 50%;
          background: #7f9f7a;
          box-shadow: 0 0 0 0 rgba(130, 170, 120, 0.8);
          animation: livePulseDot 1.6s infinite cubic-bezier(0.66, 0, 0, 1);
          display: inline-block;
          flex-shrink: 0;
        }

        .live-text-glow {
          font-family: 'Plus Jakarta Sans', 'Ubuntu', sans-serif;
          font-size: clamp(24px, 4.5vw, 38px);
          font-weight: 900;
          letter-spacing: 0.05em;
          background: linear-gradient(135deg, #3f3a32 0%, #5a5a4a 45%, #4d6b47 100%);
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
          text-transform: uppercase;
          text-shadow: 0 2px 12px rgba(160, 180, 130, 0.20);
        }

        @keyframes livePulseDot {
          0% { transform: scale(0.92); box-shadow: 0 0 0 0 rgba(130, 170, 120, 0.8); }
          70% { transform: scale(1.18); box-shadow: 0 0 0 18px rgba(130, 170, 120, 0); }
          100% { transform: scale(0.92); box-shadow: 0 0 0 0 rgba(130, 170, 120, 0); }
        }

        @keyframes eventLiveGlow {
          0%, 100% {
            border-color: rgba(180, 165, 130, 0.60);
            box-shadow: 0 0 30px rgba(180, 165, 130, 0.20), inset 0 1px 0 rgba(255, 255, 240, 0.7);
          }
          50% {
            border-color: rgba(160, 185, 140, 0.80);
            box-shadow: 0 0 55px rgba(160, 185, 140, 0.35), inset 0 1px 0 rgba(255, 255, 240, 0.9);
          }
        }

        /* Footer — light */
        footer {
          padding: 24px;
          text-align: center;
          position: relative;
          z-index: 10;
          display: flex;
          flex-direction: column;
          align-items: center;
          gap: 8px;
          border-top: 1px solid rgba(180, 160, 140, 0.15);
          background: rgba(250, 245, 235, 0.50);
          backdrop-filter: blur(10px);
        }

        .footer-status-pill {
          font-size: 11px;
          font-weight: 700;
          color: #4d6b47;
          background: rgba(150, 180, 140, 0.15);
          border: 1px solid rgba(160, 185, 145, 0.30);
          padding: 5px 14px;
          border-radius: 9999px;
          display: inline-flex;
          align-items: center;
          gap: 6px;
        }

        .status-dot {
          width: 6px;
          height: 6px;
          border-radius: 50%;
          background: #7f9f7a;
          box-shadow: 0 0 6px #7f9f7a;
        }

        .footer-copy {
          font-size: 0.78rem;
          color: #7b7266;
          margin: 0;
        }

        /* Modal — cream / light */
        .modal-overlay {
          position: fixed;
          top: 0; left: 0; right: 0; bottom: 0;
          background: rgba(235, 225, 210, 0.75);
          backdrop-filter: blur(14px);
          -webkit-backdrop-filter: blur(14px);
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
          background: linear-gradient(145deg, rgba(255, 250, 240, 0.96) 0%, rgba(245, 240, 230, 0.98) 100%);
          border: 1px solid rgba(190, 175, 155, 0.30);
          border-radius: 28px;
          width: 100%;
          max-width: 360px;
          padding: 30px 26px;
          box-shadow: 0 30px 60px rgba(140, 120, 100, 0.15), inset 0 1px 0 rgba(255, 255, 240, 0.7);
          transform: scale(0.92) translateY(10px);
          transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
          text-align: center;
        }

        .modal-overlay.active .modal-card {
          transform: scale(1) translateY(0);
        }

        .modal-icon {
          width: 64px;
          height: 64px;
          background: linear-gradient(135deg, rgba(180, 200, 160, 0.25), rgba(210, 185, 140, 0.20));
          border: 1px solid rgba(180, 165, 135, 0.40);
          border-radius: 20px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 26px;
          color: #5d7e5a;
          margin: 0 auto 16px auto;
          box-shadow: 0 8px 24px rgba(160, 175, 140, 0.15);
        }

        .modal-title {
          font-family: 'Outfit', sans-serif;
          font-size: 1.45rem;
          font-weight: 900;
          margin: 0 0 6px 0;
          color: #2e2b27;
          letter-spacing: -0.3px;
        }

        .modal-desc {
          font-size: 0.88rem;
          color: #6b6258;
          margin: 0 0 20px 0;
          line-height: 1.4;
        }

        .dest-group {
          margin: 20px 0;
          text-align: left;
        }

        .dest-label {
          display: block;
          font-size: 11px;
          font-weight: 800;
          color: #7b7266;
          text-transform: uppercase;
          letter-spacing: 1px;
          margin-bottom: 8px;
        }

        .select-wrapper {
          position: relative;
        }

        .destination-select {
          width: 100%;
          padding: 14px 16px;
          background: rgba(250, 245, 235, 0.8);
          border: 1.5px solid rgba(180, 165, 140, 0.30);
          border-radius: 16px;
          color: #2e2b27;
          font-size: 14.5px;
          font-weight: 700;
          outline: none;
          cursor: pointer;
          appearance: none;
          -webkit-appearance: none;
          transition: all 0.3s ease;
          box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
        }

        .destination-select:focus {
          border-color: #7f9f7a;
          box-shadow: 0 0 0 3px rgba(150, 180, 140, 0.20);
        }

        .select-chevron {
          position: absolute;
          right: 16px;
          top: 50%;
          transform: translateY(-50%);
          color: #5d7e5a;
          font-size: 13px;
          pointer-events: none;
        }

        .passkey-note {
          font-size: 12px;
          font-weight: 700;
          color: #5d7e5a;
          margin: 16px 0 8px 0;
          text-align: left;
        }

        .pin-input {
          width: 100%;
          background: rgba(250, 245, 235, 0.6);
          border: 1.5px solid rgba(180, 165, 140, 0.25);
          border-radius: 14px;
          padding: 12px;
          color: #3f5a3a;
          font-size: 1.6rem;
          font-weight: 900;
          letter-spacing: 8px;
          text-align: center;
          margin-bottom: 12px;
          outline: none;
          transition: all 0.3s ease;
        }

        .pin-input:focus {
          border-color: #8aaa85;
          background: rgba(250, 245, 235, 0.8);
          box-shadow: 0 0 0 4px rgba(150, 180, 140, 0.20);
        }

        .error-msg {
          color: #b17a6a;
          background: rgba(200, 140, 120, 0.12);
          border: 1px solid rgba(200, 140, 120, 0.25);
          padding: 8px 12px;
          border-radius: 10px;
          font-size: 0.82rem;
          font-weight: 700;
          margin-bottom: 16px;
          display: none;
          text-align: center;
        }

        .modal-btn {
          width: 100%;
          padding: 14px;
          border-radius: 16px;
          font-weight: 800;
          font-size: 0.95rem;
          cursor: pointer;
          border: none;
          transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-submit {
          background: linear-gradient(135deg, #7f9f7a 0%, #5a7a55 100%);
          color: #ffffff;
          border: 1px solid rgba(255, 255, 240, 0.3);
          margin-bottom: 10px;
          box-shadow: 0 8px 24px rgba(150, 180, 140, 0.25);
        }

        .btn-submit:hover {
          transform: translateY(-2px);
          box-shadow: 0 12px 30px rgba(150, 180, 140, 0.30);
        }

        .btn-submit:active {
          transform: scale(0.98);
        }

        .btn-cancel {
          background: transparent;
          color: #6b6258;
          border: 1px solid rgba(180, 160, 140, 0.20);
        }

        .btn-cancel:hover {
          color: #2e2b27;
          background: rgba(180, 160, 140, 0.08);
        }
    </style>
</head>
<body>
    <div class="ambient-glow glow-top"></div>
    <div class="ambient-glow glow-bottom"></div>

    <header>
        <div class="logo-wrap">
            <div class="logo-badge"><i class="fa-solid fa-trophy"></i></div>
            <div class="logo-text-group">
                <span class="logo-text">THANAFUS<span class="logo-dot">.</span></span>
                <span class="logo-subtext">Musabaqa 2026</span>
            </div>
        </div>
        <button class="key-btn" id="openAuthBtn" aria-label="Settings & Navigation">
            <i class="fa-solid fa-gear"></i>
        </button>
    </header>

    <main>
        <div class="fest-badge">
            <span class="badge-pulse"></span>
            Annual Arts Festival
        </div>
        <h1 class="fest-title"><?= htmlspecialchars($eventTitle) ?></h1>
        <p class="fest-date">
            <i class="fa-solid fa-calendar-days" style="color: var(--brand-gold);"></i> 
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
        <div class="footer-status-pill">
            <span class="status-dot"></span> Official Mobile Portal
        </div>
        <p class="footer-copy">&copy; 2026 Al Jamiathul Kauzariyya · All rights reserved</p>
    </footer>

    <!-- Settings & Quick Navigation Modal -->
    <div class="modal-overlay" id="authModal">
        <div class="modal-card">
            <div class="modal-icon">
                <i class="fa-solid fa-gear"></i>
            </div>
            <h3 class="modal-title">Settings & Navigation</h3>
            <p class="modal-desc">Select your target destination below.</p>
            
            <div class="dest-group">
                <label for="targetDestination" class="dest-label">Select Destination</label>
                <div class="select-wrapper">
                    <select id="targetDestination" class="destination-select">
                        <option value="dashboard">🏠 Dashboard (Home Page)</option>
                        <option value="emcee">🎤 Emcee Controls (Stage Deck)</option>
                        <option value="special">📊 Special Dashboard</option>
                    </select>
                    <i class="fa-solid fa-chevron-down select-chevron"></i>
                </div>
            </div>

            <div id="passkeySection" style="display: none;">
                <p class="passkey-note">Security Passkey required for Emcee Deck:</p>
                <input type="password" id="pinInput" class="pin-input" placeholder="••••" maxlength="8" autocomplete="off" inputmode="numeric">
                <div class="error-msg" id="errorMsg">Invalid Passkey PIN!</div>
            </div>

            <button class="modal-btn btn-submit" id="submitPinBtn">Open Dashboard</button>
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
                        countdownEl.className = 'countdown-container-live';
                        
                        if (localStorage.getItem('program_ended') === 'true') {
                            countdownEl.innerHTML = `
                                <div class="program-ended-box" style="display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 22px 30px; background: rgba(15, 23, 42, 0.72); backdrop-filter: blur(16px); border-radius: 28px; border: 1.5px solid rgba(239, 68, 68, 0.4); box-shadow: 0 16px 40px rgba(0,0,0,0.35); width: 100%; max-width: 520px; margin: 0 auto; text-align: center;">
                                    <div style="font-size: 14px; font-weight: 900; color: #ef4444; text-transform: uppercase; letter-spacing: 1.5px; display: flex; align-items: center; gap: 8px;">
                                        <i class="fa-solid fa-flag-checkered"></i> PROGRAM ENDED
                                    </div>
                                    <div style="font-size: 13px; color: rgba(255,255,255,0.85); font-weight: 600;">
                                        The festival program has officially concluded. Thank you!
                                    </div>
                                    <button type="button" id="btnRestartProgram" style="margin-top: 4px; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25); color: #ffffff; border-radius: 20px; padding: 8px 22px; font-size: 12px; font-weight: 800; cursor: pointer; transition: all 0.2s ease;">
                                        <i class="fa-solid fa-rotate-left" style="margin-right: 6px;"></i> Restart Timer
                                    </button>
                                </div>
                            `;
                            document.getElementById('btnRestartProgram')?.addEventListener('click', () => {
                                localStorage.removeItem('program_ended');
                                updateCountdown();
                            });
                            return;
                        }

                        // Calculate Live Elapsed Program Time (counting UP)
                        const elapsedSeconds = Math.floor((now - targetDate) / 1000);
                        const hrs = Math.floor(elapsedSeconds / 3600);
                        const mins = Math.floor((elapsedSeconds % 3600) / 60);
                        const secs = elapsedSeconds % 60;

                        countdownEl.innerHTML = `
                            <div class="program-timer-box" style="display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 22px 28px; background: rgba(15, 23, 42, 0.72); backdrop-filter: blur(16px); border-radius: 28px; border: 1.5px solid rgba(16, 185, 129, 0.4); box-shadow: 0 16px 40px rgba(0,0,0,0.35), 0 0 30px rgba(16,185,129,0.15); width: 100%; max-width: 520px; margin: 0 auto; text-align: center;">
                                <div style="font-size: 13px; font-weight: 900; color: #10b981; text-transform: uppercase; letter-spacing: 1.5px; display: flex; align-items: center; gap: 8px;">
                                    <span class="live-dot-pulse" style="background: #10b981; box-shadow: 0 0 10px #10b981; display: inline-block; width: 8px; height: 8px; border-radius: 50%;"></span>
                                    PROGRAM STARTED &bull; LIVE TIMER
                                </div>
                                <div style="display: flex; align-items: center; justify-content: center; gap: 12px; font-family: 'Outfit', 'Inter', sans-serif;">
                                    <div style="display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,0.06); padding: 10px 18px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.1); min-width: 72px;">
                                        <span style="font-size: 32px; font-weight: 900; color: #ffffff; line-height: 1;">${String(hrs).padStart(2, '0')}</span>
                                        <span style="font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-top: 4px;">Hours</span>
                                    </div>
                                    <span style="font-size: 28px; font-weight: 900; color: #10b981;">:</span>
                                    <div style="display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,0.06); padding: 10px 18px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.1); min-width: 72px;">
                                        <span style="font-size: 32px; font-weight: 900; color: #ffffff; line-height: 1;">${String(mins).padStart(2, '0')}</span>
                                        <span style="font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-top: 4px;">Mins</span>
                                    </div>
                                    <span style="font-size: 28px; font-weight: 900; color: #10b981;">:</span>
                                    <div style="display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,0.06); padding: 10px 18px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.1); min-width: 72px;">
                                        <span style="font-size: 32px; font-weight: 900; color: #10b981; line-height: 1;">${String(secs).padStart(2, '0')}</span>
                                        <span style="font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-top: 4px;">Secs</span>
                                    </div>
                                </div>
                                <button type="button" id="btnEndProgram" style="margin-top: 6px; background: linear-gradient(135deg, #ef4444, #dc2626); color: #ffffff; border: none; padding: 10px 28px; border-radius: 999px; font-size: 13px; font-weight: 800; cursor: pointer; box-shadow: 0 6px 20px rgba(239, 68, 68, 0.35); transition: all 0.2s ease;">
                                    <i class="fa-solid fa-power-off" style="margin-right: 6px;"></i> End Program
                                </button>
                            </div>
                        `;

                        document.getElementById('btnEndProgram')?.addEventListener('click', () => {
                            localStorage.setItem('program_ended', 'true');
                            updateCountdown();
                        });
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
            const targetDestination = document.getElementById('targetDestination');
            const passkeySection = document.getElementById('passkeySection');

            function updateDestinationView() {
                const val = targetDestination.value;
                if (val === 'emcee') {
                    passkeySection.style.display = 'block';
                    submitPinBtn.textContent = 'Unlock Stage Deck';
                    setTimeout(() => pinInput.focus(), 150);
                } else if (val === 'special') {
                    passkeySection.style.display = 'block';
                    submitPinBtn.textContent = 'Unlock Special Dashboard';
                    setTimeout(() => pinInput.focus(), 150);
                } else {
                    passkeySection.style.display = 'none';
                    errorMsg.style.display = 'none';
                    submitPinBtn.textContent = 'Open Dashboard';
                }
            }

            targetDestination.addEventListener('change', updateDestinationView);

            function openModal() {
                authModal.classList.add('active');
                pinInput.value = '';
                errorMsg.style.display = 'none';
                updateDestinationView();
            }

            function closeModal() {
                authModal.classList.remove('active');
            }

            openAuthBtn.addEventListener('click', openModal);
            closeAuthBtn.addEventListener('click', closeModal);

            // Handle submission
            function handleSubmit() {
                const dest = targetDestination.value;
                if (dest === 'dashboard') {
                    window.location.href = '../home.php';
                    return;
                }

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
                formData.append('action', dest === 'special' ? 'auth_special' : 'auth_emcee');
                formData.append('pin', pin);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    submitPinBtn.disabled = false;
                    submitPinBtn.textContent = dest === 'special' ? 'Unlock Special Dashboard' : 'Unlock Stage Deck';
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
                    submitPinBtn.textContent = dest === 'special' ? 'Unlock Special Dashboard' : 'Unlock Stage Deck';
                    errorMsg.textContent = 'Network error, please try again.';
                    errorMsg.style.display = 'block';
                });
            }

            submitPinBtn.addEventListener('click', handleSubmit);
            pinInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    handleSubmit();
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
