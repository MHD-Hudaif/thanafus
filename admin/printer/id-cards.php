<?php
$pageTitle = 'Print ID Cards';

define('EVENT_AUTHORITY_SCOPE', 'members-info');
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/id-card-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// Filter options
$filterTeamId = isset($_GET['team_id']) && $_GET['team_id'] !== '' ? (int)$_GET['team_id'] : null;
$selectedMemberIds = isset($_GET['member_ids']) ? array_filter(array_map('intval', explode(',', $_GET['member_ids']))) : [];

$params = [$activeEventId];
$whereClauses = ["mtm.event_id = ?", "mtm.status = 'active'"];

if ($filterTeamId) {
    $whereClauses[] = "mtm.team_id = ?";
    $params[] = $filterTeamId;
}

if (!empty($selectedMemberIds)) {
    $inClause = implode(',', array_fill(0, count($selectedMemberIds), '?'));
    $whereClauses[] = "mtm.id IN ($inClause)";
    foreach ($selectedMemberIds as $id) {
        $params[] = $id;
    }
}

$whereSql = implode(' AND ', $whereClauses);

$stmt = $pdo->prepare("
    SELECT 
        mtm.id AS member_id,
        mtm.student_id,
        mtm.chest_number,
        t.id AS team_id,
        t.team_name,
        t.team_color,
        ev.title AS event_title,
        COALESCE(NULLIF(s.display_name, ''), s.full_name) AS display_name,
        s.full_name,
        s.name_arabic
    FROM musabaqa_team_members mtm
    JOIN musabaqa_teams t ON t.id = mtm.team_id
    JOIN musabaqa_events ev ON ev.id = mtm.event_id
    JOIN " . DB_MAIN_NAME . ".students s ON s.id = mtm.student_id
    WHERE {$whereSql}
    ORDER BY NULLIF(mtm.chest_number, '') IS NULL ASC, 
             CAST(mtm.chest_number AS UNSIGNED) ASC, 
             t.team_name ASC, 
             display_name ASC
");
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch templates for each team in event (and default)
$teamTemplates = [];
$defaultTemplate = id_card_get_template($pdo, $activeEventId, null);

$stmtTeams = $pdo->prepare('SELECT id FROM musabaqa_teams WHERE event_id = ?');
$stmtTeams->execute([$activeEventId]);
foreach ($stmtTeams->fetchAll(PDO::FETCH_COLUMN) as $tId) {
    $teamTemplates[$tId] = id_card_get_template($pdo, $activeEventId, (int)$tId);
}

// Fetch all teams for filter
$allTeams = $pdo->query("SELECT id, team_name, team_color FROM musabaqa_teams WHERE event_id = {$activeEventId} ORDER BY team_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ID Cards Print View - <?= e($activeEvent['title']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            padding: 20px;
        }
        .no-print-bar {
            background: #1e293b;
            border: 1px solid #334155;
            padding: 16px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-secondary { background: #334155; color: #f1f5f9; }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            justify-items: center;
        }

        .id-card-wrapper {
            position: relative;
            width: 320px;
            height: 506px; /* Aspect ratio 600x950 */
            border-radius: 12px;
            overflow: hidden;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-color: #ffffff;
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .card-el {
            position: absolute;
            white-space: nowrap;
            box-sizing: border-box;
        }

        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding: 0 !important;
            }
            .no-print-bar {
                display: none !important;
            }
            .cards-grid {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 15mm !important;
                justify-content: flex-start !important;
            }
            .id-card-wrapper {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                width: 75mm !important;
                height: 118mm !important;
            }
            @page {
                size: A4 portrait;
                margin: 10mm;
            }
        }
    </style>
</head>
<body>

    <div class="no-print-bar">
        <div>
            <h2 style="font-size: 18px; color: #fff;"><i class="fa-solid fa-id-card text-primary"></i> ID Card Print Hub</h2>
            <div style="font-size: 13px; color: #94a3b8;">Total <?= count($members) ?> cards ready to print</div>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <select onchange="location.href='?team_id=' + this.value" style="padding: 8px 12px; border-radius: 8px; background: #0f172a; color: #fff; border: 1px solid #334155;">
                <option value="">All Teams (<?= count($members) ?> Cards)</option>
                <?php foreach ($allTeams as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $filterTeamId === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= e($t['team_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <a href="<?= app_url('/admin/printer/id-card-designer.php') ?>" class="btn btn-secondary">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Customize Layout
            </a>

            <a href="<?= app_url('/admin/printer/id-cards-search.php') ?>" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left"></i> Back to Search
            </a>

            <button onclick="window.print()" class="btn btn-success">
                <i class="fa-solid fa-print"></i> Print Cards (Ctrl+P)
            </button>
        </div>
    </div>

    <div class="cards-grid">
        <?php if (empty($members)): ?>
            <div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: #94a3b8;">
                No active team members found to print.
            </div>
        <?php else: ?>
            <?php foreach ($members as $m): 
                $teamId = (int)$m['team_id'];
                $tpl = $teamTemplates[$teamId] ?? $defaultTemplate;
                $cfg = $tpl['layout_config'] ?? id_card_default_layout();

                $bgPath = !empty($tpl['background_image']) ? $tpl['background_image'] : ($defaultTemplate['background_image'] ?? null);
                $bgUrl = $bgPath ? app_url('/' . ltrim($bgPath, '/')) : '';
                $teamColor = $m['team_color'] ?: '#3b82f6';
            ?>
                <div class="id-card-wrapper" style="<?= $bgUrl ? "background-image: url('{$bgUrl}');" : "background-color: #f8fafc;" ?>">
                    <?php foreach ($cfg as $key => $el): 
                        if (isset($el['visible']) && $el['visible'] === false) {
                            continue;
                        }

                        $posX = floatval($el['x'] ?? 50);
                        $posY = floatval($el['y'] ?? 50);
                        $align = $el['align'] ?? 'center';

                        $transform = 'translate(-50%, -50%)';
                        if ($align === 'left') $transform = 'translate(0, -50%)';
                        if ($align === 'right') $transform = 'translate(-100%, -50%)';

                        if ($key === 'student_photo'):
                            $w = intval($el['width'] ?? 120);
                            $h = intval($el['height'] ?? 120);
                            $r = intval($el['border_radius'] ?? 60);
                            $bw = intval($el['border_width'] ?? 3);
                            $bc = $el['border_color'] ?? '#ffffff';

                            // Scale to wrapper size (320px width vs 600px base width = ~0.533 scale)
                            $scale = 320 / floatval($tpl['card_width'] ?: 600);
                            $scaledW = round($w * $scale);
                            $scaledH = round($h * $scale);
                            $scaledR = round($r * $scale);
                            $scaledBw = max(1, round($bw * $scale));

                            $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($m['display_name']) . "&background=3b82f6&color=fff";
                        ?>
                            <div class="card-el" style="
                                left: <?= $posX ?>%; 
                                top: <?= $posY ?>%; 
                                transform: <?= $transform ?>;
                                width: <?= $scaledW ?>px;
                                height: <?= $scaledH ?>px;
                                border-radius: <?= $scaledR ?>px;
                                border: <?= $scaledBw ?>px solid <?= e($bc) ?>;
                                background: url('<?= $avatarUrl ?>') center/cover;
                            "></div>
                        <?php else:
                            $fontSize = intval($el['font_size'] ?? 18);
                            $scale = 320 / floatval($tpl['card_width'] ?: 600);
                            $scaledFont = max(10, round($fontSize * $scale));

                            $color = $el['color'] ?? '#000000';
                            if ($color === 'auto' || !empty($el['use_team_color'])) {
                                $color = $teamColor;
                            }

                            $prefix = $el['prefix'] ?? $el['label'] ?? '';
                            $val = '';
                            if ($key === 'display_name') $val = $m['display_name'];
                            elseif ($key === 'name_arabic') $val = $m['name_arabic'] ?? '';
                            elseif ($key === 'chest_number') $val = ($prefix ?: '#') . ($m['chest_number'] ?: '-');
                            elseif ($key === 'team_name') $val = $prefix . $m['team_name'];

                            if (trim((string)$val) === '') continue;
                        ?>
                            <div class="card-el" style="
                                left: <?= $posX ?>%; 
                                top: <?= $posY ?>%; 
                                transform: <?= $transform ?>;
                                font-size: <?= $scaledFont ?>px;
                                font-weight: <?= e($el['font_weight'] ?? '600') ?>;
                                text-align: <?= e($align) ?>;
                                text-transform: <?= e($el['text_transform'] ?? 'none') ?>;
                                color: <?= e($color) ?>;
                            ">
                                <?= e($val) ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>
</html>
