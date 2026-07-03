<?php
$pageTitle = 'ID Card Export';

require_once __DIR__ . '/../includes/id-card-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$members = id_card_members($pdo, $activeEventId);
$search = trim((string)($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/id-cards.php');
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        $result = id_card_generate_qrs($members);

        if ($action === 'download_csv') {
            $filename = 'id-cards-event-' . $activeEventId . '.csv';

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['chest_number', 'display_name', 'team_name', 'team_color', 'category', 'qr']);

            foreach ($members as $member) {
                $localQr = (string)($member['qr_paths']['local'] ?? '');
                if (!empty($member['qr_paths']['file']) && file_exists((string)$member['qr_paths']['file'])) {
                    $localQr = realpath((string)$member['qr_paths']['file']) ?: $localQr;
                }

                fputcsv($output, [
                    $member['chest_number'] ?? '',
                    $member['display_name'] ?? '',
                    $member['team_name'] ?? '',
                    $member['team_color'] ?? '',
                    $member['category'] ?? '',
                    $localQr,
                ]);
            }

            fclose($output);
            exit;
        }

        $message = 'Generated ' . $result['generated'] . ' QR file(s).';
        if ($result['skipped'] > 0) {
            $message .= ' ' . $result['skipped'] . ' member(s) were skipped because chest number is empty.';
        }
        admin_flash($result['skipped'] > 0 ? 'warning' : 'success', $message);
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to generate ID card files.');
    }

    admin_redirect('/admin/id-cards.php');
}

$flash = admin_take_flash();
$filteredMembers = [];
foreach ($members as $member) {
    if (
        $search !== ''
        && stripos((string)($member['chest_number'] ?? ''), $search) === false
        && stripos((string)($member['display_name'] ?? ''), $search) === false
        && stripos((string)($member['team_name'] ?? ''), $search) === false
        && stripos((string)($member['section'] ?? ''), $search) === false
        && stripos((string)($member['category'] ?? ''), $search) === false
    ) {
        continue;
    }
    $filteredMembers[] = $member;
}
$totalMembers = count($filteredMembers);
$missingChest = 0;
$existingQr = 0;
foreach ($filteredMembers as $member) {
    if (trim((string)($member['chest_number'] ?? '')) === '') {
        $missingChest++;
        continue;
    }
    if (!empty($member['qr_paths']['file']) && file_exists((string)$member['qr_paths']['file'])) {
        $existingQr++;
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">ID Card Export</div>
            <div class="page-subtitle"><?= e($activeEvent['title']) ?></div>
        </div>
        <a href="<?= APP_URL ?>/admin/chest-numbers.php" class="btn btn-secondary btn-md"><i class="fa-solid fa-hashtag"></i> Chest Numbers</a>
    </div>

    <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-error') ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="stats-grid mb-6">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-users"></i></div><div class="stat-value"><?= $totalMembers ?></div><div class="stat-label">Active Members</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-hashtag"></i></div><div class="stat-value"><?= $missingChest ?></div><div class="stat-label">Missing Chest #</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-qrcode"></i></div><div class="stat-value"><?= $existingQr ?></div><div class="stat-label">QR Files Ready</div></div>
    </div>

    <div class="panel mb-6">
        <div class="flex-between">
            <div>
                <div class="dashboard-heading">Photoshop Mail Merge CSV</div>
                <div class="page-subtitle">CSV columns: chest number, display name, team, team color, category, and QR image path.</div>
            </div>
            <form method="POST" class="flex gap-2 flex-wrap">
                <?= admin_csrf_field() ?>
                <button class="btn btn-secondary btn-md" name="action" value="generate_qr" type="submit"><i class="fa-solid fa-qrcode"></i> Generate QR Files</button>
                <button class="btn btn-success btn-md" name="action" value="download_csv" type="submit"><i class="fa-solid fa-file-csv"></i> Download CSV</button>
            </form>
        </div>
    </div>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
            <div class="input-group full-width">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Chest number, display name, team, section or category">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <?php if ($search !== ''): ?><a href="<?= APP_URL ?>/admin/id-cards.php" class="btn btn-secondary btn-md">Clear</a><?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$filteredMembers): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-id-card"></i></div><div class="empty-title">No Members Found</div><div class="empty-subtitle"><?= $search !== '' ? 'No members match your search.' : 'Add members before exporting ID card data.' ?></div></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr><th>Chest #</th><th>Display Name</th><th>Team</th><th>Section</th><th>Category</th><th>QR</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($filteredMembers as $member): ?>
                        <?php $hasQr = !empty($member['qr_paths']['file']) && file_exists((string)$member['qr_paths']['file']); ?>
                        <tr>
                            <td><strong><?= trim((string)($member['chest_number'] ?? '')) !== '' ? '#' . e((string)$member['chest_number']) : '-' ?></strong></td>
                            <td><?= e($member['display_name'] ?? '') ?></td>
                            <td><span class="team-color-pill" style="background: <?= e($member['team_color'] ?: '#14b8a6') ?>22; color:#fff;"><span class="team-color-dot" style="width:12px;height:12px;background:<?= e($member['team_color'] ?: '#14b8a6') ?>;"></span><?= e($member['team_name'] ?? '') ?></span></td>
                            <td><?= e($member['section'] ?: '-') ?></td>
                            <td><span class="badge badge-info"><?= e($member['category'] ?: '-') ?></span></td>
                            <td>
                                <?php if ($hasQr): ?>
                                    <a class="btn btn-secondary btn-sm" href="<?= e((string)$member['qr_paths']['web']) ?>" target="_blank"><i class="fa-solid fa-eye"></i> View QR</a>
                                <?php else: ?>
                                    <span class="badge <?= empty($member['chest_number']) ? 'badge-warning' : 'badge-neutral' ?>"><?= empty($member['chest_number']) ? 'Needs chest #' : 'Not generated' ?></span>
                                <?php endif; ?>
                                <a class="btn btn-secondary btn-sm" href="<?= APP_URL ?>/member-details.php?id=<?= (int)$member['member_id'] ?>" target="_blank"><i class="fa-solid fa-address-card"></i> Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php admin_close_page(); ?>
