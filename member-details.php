<?php
require_once __DIR__ . '/includes/id-card-helpers.php';

$pdo = $GLOBALS['musabaqa_pdo'];
$memberId = (int)($_GET['id'] ?? 0);
$member = $memberId > 0 ? id_card_member($pdo, $memberId) : null;

if (!$member) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $member ? e($member['display_name']) : 'Member Not Found' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #070b12;
    --surface: #0d1420;
    --border: rgba(255,255,255,.11);
    --text: #f8fafc;
    --muted: #94a3b8;
    --primary: #14b8a6;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 24px;
    font-family: Cairo, Arial, sans-serif;
    color: var(--text);
    background: linear-gradient(180deg, #070b12, #0a101a);
}
.member-card {
    width: min(520px, 100%);
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
}
.member-accent { height: 8px; background: var(--team-color, var(--primary)); }
.member-body { padding: 26px; }
.event { color: var(--muted); font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
.name { margin-top: 8px; font-size: clamp(28px, 8vw, 42px); line-height: 1.05; font-weight: 900; letter-spacing: 0; }
.chest {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    margin-top: 18px;
    padding: 8px 14px;
    border-radius: 8px;
    background: color-mix(in srgb, var(--team-color, var(--primary)) 20%, transparent);
    border: 1px solid color-mix(in srgb, var(--team-color, var(--primary)) 36%, transparent);
    font-size: 24px;
    font-weight: 900;
}
.details { display: grid; gap: 12px; margin-top: 24px; }
.detail {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 13px 0;
    border-top: 1px solid var(--border);
}
.label { color: var(--muted); font-weight: 800; }
.value { text-align: right; font-weight: 800; }
.not-found { text-align: center; }
.not-found h1 { margin: 0 0 8px; font-size: 32px; }
.not-found p { margin: 0; color: var(--muted); }
@media (max-width: 520px) {
    body { padding: 16px; }
    .member-body { padding: 20px; }
    .detail { display: grid; gap: 4px; }
    .value { text-align: left; }
}
</style>
</head>
<body>
<?php if (!$member): ?>
    <div class="not-found">
        <h1>Member Not Found</h1>
        <p>The scanned ID card link is not available.</p>
    </div>
<?php else: ?>
    <main class="member-card" style="--team-color: <?= e($member['team_color'] ?: '#14b8a6') ?>;">
        <div class="member-accent"></div>
        <div class="member-body">
            <div class="event"><?= e($member['event_title'] ?? 'Kauzariyya Musabaqa') ?></div>
            <div class="name"><?= e($member['display_name'] ?? '') ?></div>
            <div class="chest">#<?= e($member['chest_number'] ?: '-') ?></div>
            <div class="details">
                <div class="detail"><div class="label">Team</div><div class="value"><?= e($member['team_name'] ?? '-') ?></div></div>
                <div class="detail"><div class="label">Section</div><div class="value"><?= e($member['section'] ?: '-') ?></div></div>
                <div class="detail"><div class="label">Category</div><div class="value"><?= e($member['category'] ?: '-') ?></div></div>
                <div class="detail"><div class="label">Place</div><div class="value"><?= e($member['place'] ?: '-') ?></div></div>
            </div>
        </div>
    </main>
<?php endif; ?>
</body>
</html>
