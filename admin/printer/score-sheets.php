<?php
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

if (!can_access_category('printer')) {
    http_response_code(403);
    exit('Access Denied: You do not have authority to access this page.');
}

$activeEvent = get_active_musabaqa();
$pdo = $GLOBALS['musabaqa_pdo'];

$action = $_GET['action'] ?? '';

if ($action === 'print' && $activeEvent) {
    $activeEventId = (int)$activeEvent['id'];
    $programIds = $_GET['program_ids'] ?? [];
    $printType = (string)($_GET['print_type'] ?? $_GET['mode'] ?? 'scores');

    if (!is_array($programIds)) {
        $programIds = [];
    }
    $programIds = array_filter(array_map('intval', $programIds));

    if (empty($programIds)) {
        exit('Please select at least one program to print.');
    }

    $placeholders = implode(',', array_fill(0, count($programIds), '?'));
    $stmt = $pdo->prepare("
        SELECT p.*, ct.name AS class_type_name
        FROM musabaqa_programs p
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
        WHERE p.id IN ($placeholders) AND p.event_id = ?
        ORDER BY COALESCE(mss.sort_order, 999) ASC, COALESCE(mss.start_time, '23:59:59') ASC, COALESCE(p.start_time, '23:59:59') ASC, p.id ASC
    ");
    $stmt->execute(array_merge($programIds, [$activeEventId]));
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categoriesByProgram = [];
    $entriesByProgram = [];
    foreach ($programs as $p) {
        $pId = (int)$p['id'];
        $catStmt = $pdo->prepare("SELECT * FROM musabaqa_program_categories WHERE program_id = ? ORDER BY sort_order ASC, id ASC");
        $catStmt->execute([$pId]);
        $categoriesByProgram[$pId] = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        $entStmt = $pdo->prepare("
            SELECT pe.*, t.team_name, t.team_color,
                   (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                    FROM musabaqa_entry_members em
                    JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                    WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> '') AS chest_number
            FROM musabaqa_program_entries pe
            JOIN musabaqa_teams t ON t.id = pe.team_id
            WHERE pe.event_id = ? AND pe.program_id = ?
            ORDER BY pe.performance_order ASC, pe.id ASC
        ");
        $entStmt->execute([$activeEventId, $pId]);
        $entriesByProgram[$pId] = $entStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Separate Individual and Group programs
    $individualPrograms = [];
    $groupPrograms = [];
    foreach ($programs as $p) {
        if (strtolower((string)($p['program_type'] ?? '')) === 'group') {
            $groupPrograms[] = $p;
        } else {
            $individualPrograms[] = $p;
        }
    }

    // Combine with Individual programs first, then Group programs
    $orderedPrograms = array_merge($individualPrograms, $groupPrograms);

    if ($printType === 'emcee') {
        header('Location: ' . app_url('/admin/printer/mc-sheets.php') . '?action=print&' . http_build_query(['program_ids' => $programIds]));
        exit;
    }

    // Default: Judges Score Sheets
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Judges Score Sheets - <?= e($activeEvent['title']) ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="<?= asset_url('css/event-id-cards.css') ?>">
        <script src="<?= asset_url('js/print-helpers.js') ?>" defer></script>
        <style>
            @page {
                size: A4 portrait;
                margin: 6mm 8mm;
            }
            * { box-sizing: border-box; }
            body {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                color: #000;
                background: #f8fafc;
                margin: 0;
                padding: 24px 32px;
                line-height: 1.3;
            }

            .no-print-bar {
                background: #0f172a;
                padding: 14px 20px;
                border-radius: 12px;
                margin: 0 auto 24px auto;
                max-width: 1200px;
                color: #fff;
                box-shadow: 0 4px 20px rgba(0,0,0,0.25);
            }
            .toolbar-inner {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 14px;
            }
            .toolbar-title-group h3 {
                margin: 0;
                font-size: 16px;
                color: #fff;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .toolbar-title-group small {
                color: #94a3b8;
                font-size: 12px;
            }
            .toolbar-controls {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            .control-select, .control-checkbox-label {
                background: #1e293b;
                color: #fff;
                border: 1px solid #334155;
                padding: 6px 12px;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                cursor: pointer;
            }
            .control-select:focus {
                outline: none;
                border-color: #3b82f6;
            }
            .control-checkbox-label input[type="checkbox"] {
                accent-color: #3b82f6;
                width: 14px;
                height: 14px;
                cursor: pointer;
            }

            .judge-landscape-sheet {
                background: #fff;
                color: #000;
                padding: 16px 20px;
                box-sizing: border-box;
                width: 194mm;
                height: 285mm;
                margin: 0 auto 32px auto;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                page-break-after: always !important;
                break-after: page !important;
            }
            .judge-landscape-sheet:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .page-break {
                page-break-before: always !important;
                break-before: page !important;
            }

            .print-header {
                border-bottom: 2.5px solid #000;
                padding-bottom: 8px;
                margin-bottom: 12px;
                flex-shrink: 0;
            }
            .event-kicker {
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: #475569;
                margin-bottom: 3px;
            }
            .program-title-banner {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 10px;
            }
            .program-title {
                font-size: 22px;
                font-weight: 900;
                color: #000;
                letter-spacing: -0.01em;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .program-meta-badges {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .meta-badge {
                border: 1px solid #000;
                font-size: 10.5px;
                font-weight: 800;
                padding: 2px 8px;
                border-radius: 4px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
            .judge-badge {
                background: #0f172a;
                color: #fff;
                font-size: 13px;
                font-weight: 900;
                padding: 6px 16px;
                border-radius: 6px;
                text-transform: uppercase;
                letter-spacing: 0.08em;
            }

            .sheet-table-wrapper {
                flex: 1 1 auto;
                display: flex;
                flex-direction: column;
                margin-top: 6px;
                margin-bottom: 8px;
                min-height: 0;
            }
            .sheet-table {
                width: 100%;
                height: 100%;
                table-layout: fixed;
                border-collapse: collapse;
            }
            .sheet-table th, .sheet-table td {
                border: 1.5px solid #000;
                text-align: center;
                vertical-align: middle;
                padding: 6px 8px;
                font-size: 13px;
            }
            .sheet-table th {
                background: #f1f5f9;
                font-weight: 900;
                text-transform: uppercase;
                font-size: 12px;
                letter-spacing: 0.03em;
                padding: 10px 8px;
            }
            .chest-col {
                width: 120px;
                font-weight: 900;
                font-size: 16px;
            }
            .participant-col {
                text-align: left !important;
                padding-left: 10px !important;
                width: 220px;
            }
            .team-indicator-dot {
                display: inline-block;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                margin-right: 6px;
                vertical-align: middle;
                border: 1px solid rgba(0,0,0,0.2);
            }
            .col-total {
                width: 150px;
                font-weight: 900;
                font-size: 16px;
                background: #fafafa;
            }
            .col-rank {
                width: 80px;
                display: none;
            }
            .col-notes {
                width: 140px;
            }

            body.hide-participant-names .participant-col {
                display: none !important;
            }
            body.hide-total-column .col-total {
                display: none !important;
            }
            body.show-rank-column .col-rank {
                display: table-cell !important;
            }
            body.hide-notes-column .col-notes {
                display: none !important;
            }
            body.hide-sheet-footer .sheet-footer {
                display: none !important;
            }

            .sheet-footer {
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1.5px solid #cbd5e1;
                font-size: 12.5px;
                font-weight: 700;
                flex-shrink: 0;
            }
            .signature-box {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .signature-label {
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                color: #475569;
            }
            .signature-line {
                border-bottom: 1.5px solid #000;
                display: inline-block;
                width: 180px;
                height: 20px;
            }
            .confidential-notice {
                font-size: 10px;
                color: #64748b;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                text-align: center;
                margin-top: 4px;
            }

            @media print {
                @page {
                    size: A4 portrait;
                    margin: 6mm 8mm;
                }
                html, body {
                    height: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    background: #fff !important;
                }
                .no-print-bar, .no-print-hide {
                    display: none !important;
                }
                .judge-landscape-sheet {
                    border: none !important;
                    padding: 4mm 6mm !important;
                    margin: 0 !important;
                    border-radius: 0 !important;
                    box-shadow: none !important;
                    width: 100% !important;
                    height: 100vh !important;
                    max-height: 100vh !important;
                    display: flex !important;
                    flex-direction: column !important;
                    justify-content: space-between !important;
                    page-break-after: always !important;
                    break-after: page !important;
                    box-sizing: border-box !important;
                }
                .judge-landscape-sheet:last-child {
                    page-break-after: auto !important;
                    break-after: auto !important;
                }
                .sheet-table-wrapper {
                    flex: 1 1 auto !important;
                    display: flex !important;
                    flex-direction: column !important;
                    margin: 4px 0 !important;
                    min-height: 0 !important;
                }
                .sheet-table {
                    height: 100% !important;
                }
                .sheet-footer {
                    flex-shrink: 0 !important;
                    margin-top: 6px !important;
                    padding-top: 6px !important;
                }
                .sheet-table th {
                    background: #f1f5f9 !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .col-total {
                    background: #fafafa !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        </style>
    </head>
    <body class="hide-participant-names hide-notes-column hide-sheet-footer" data-print-orientation="portrait">
        <div class="no-print-bar">
            <div class="toolbar-inner">
                <div class="toolbar-title-group">
                    <h3><i class="fa-solid fa-file-invoice" style="color:#60a5fa;"></i> Judges Score Sheets</h3>
                    <small><?= count($orderedPrograms) ?> Program(s) — Ready to Print</small>
                </div>
                
                <div class="toolbar-controls">
                    <a href="<?= app_url('/admin/printer/score-sheets.php') ?>" class="btn btn-secondary" style="padding: 7px 14px; text-decoration: none; color: #fff; background: #334155; border-radius: 6px; font-size: 13px; font-weight: 600;">
                        <i class="fa-solid fa-arrow-left mr-1"></i> Selection
                    </a>

                    <button type="button" onclick="exportAllTablesToExcel('table')" class="btn" style="padding: 7px 16px; background: #16a34a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; vertical-align: middle;">
                        <i class="fa-solid fa-file-excel"></i> Copy for Excel
                    </button>

                    <button type="button" onclick="window.print()" class="btn btn-success" style="padding: 7px 18px; background: #10b981; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer;">
                        <i class="fa-solid fa-print mr-1"></i> Print Sheets (Ctrl+P)
                    </button>
                </div>
            </div>
        </div>

        <?php
        $totalProgs = count($orderedPrograms);
        $judgesCount = max(1, min(6, (int)($_GET['judges_count'] ?? $_GET['judges'] ?? 2)));

        foreach ($orderedPrograms as $pIdx => $program):
            $pId = (int)$program['id'];
            $pType = strtolower((string)($program['program_type'] ?? 'individual'));
            $categories = $categoriesByProgram[$pId] ?? [];
            $entriesForPrint = $entriesByProgram[$pId] ?? [];
            $entryCount = max(1, count($entriesForPrint));

            $totalMaxMarks = 0;
            foreach ($categories as $cat) {
                $totalMaxMarks += (float)($cat['max_marks'] ?? 0);
            }

            if ($entryCount <= 8) {
                $cellPadding = '12px 10px';
                $chestFontSize = '22px';
                $thCatFontSize = '17px';
                $thMaxFontSize = '13px';
                $thChestFontSize = '16px';
            } elseif ($entryCount <= 14) {
                $cellPadding = '8px 8px';
                $chestFontSize = '19px';
                $thCatFontSize = '15px';
                $thMaxFontSize = '12px';
                $thChestFontSize = '15px';
            } else {
                $cellPadding = '5px 6px';
                $chestFontSize = '16px';
                $thCatFontSize = '13px';
                $thMaxFontSize = '11px';
                $thChestFontSize = '13px';
            }

            $tier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
            $sectionLabel = $tier ? admin_class_type_tier_label($tier) : '';

            if (!$sectionLabel && !empty($program['allowed_sections'])) {
                $secParts = array_filter(array_map('trim', explode(',', $program['allowed_sections'])));
                if (count($secParts) === 1) {
                    $sectionLabel = reset($secParts);
                }
            }

            if ($sectionLabel && !in_array(strtolower($sectionLabel), ['general', 'all classes', 'general / multi-section'], true)) {
                $programHeading = $program['title'] . ' - ' . $sectionLabel;
            } else {
                $programHeading = $program['title'];
            }
        ?>
            <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                <div class="judge-landscape-sheet <?= ($pIdx > 0 || $j > 1) ? 'page-break' : '' ?>" data-judge-number="<?= $j ?>">
                    <div class="print-header">
                        <div class="program-title-banner">
                            <span class="program-title" style="font-size: 28px; font-weight: 900; letter-spacing: -0.02em;">
                                <?= e($programHeading) ?>
                            </span>
                            <span class="judge-badge" style="font-size: 16px; font-weight: 900; padding: 8px 22px;">JUDGE <?= $j ?> SCORE SHEET</span>
                        </div>
                    </div>

                    <div class="sheet-table-wrapper">
                        <table class="sheet-table">
                            <thead>
                                <tr>
                                    <th class="chest-col" style="font-size: <?= $thChestFontSize ?>; font-weight: 900; padding: 10px 8px; width: 130px;">Chest #</th>
                                    <th class="participant-col">Participant / Team Name</th>
                                    <?php foreach ($categories as $cat): ?>
                                        <th class="score-col" style="font-size: <?= $thCatFontSize ?>; font-weight: 900; padding: 10px 8px;">
                                            <?= e($cat['name']) ?><br>
                                            <small style="font-weight: 800; font-size: <?= $thMaxFontSize ?>; text-transform: none; color: #334155;">(Max <?= number_format($cat['max_marks'], 0) ?>)</small>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="col-total" style="font-size: <?= $thCatFontSize ?>; font-weight: 900; padding: 10px 8px;">
                                        Total
                                        <?php if ($totalMaxMarks > 0): ?>
                                            <br><small style="font-weight: 800; font-size: <?= $thMaxFontSize ?>; text-transform: none; color: #334155;">(Max <?= number_format($totalMaxMarks, 0) ?>)</small>
                                        <?php endif; ?>
                                    </th>
                                    <th class="col-rank">Rank</th>
                                    <th class="col-notes">Judge Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($entriesForPrint)): ?>
                                    <tr>
                                        <td colspan="<?= 2 + count($categories) + 3 ?>" style="padding: 24px; color: #666; font-size: 14px;">No registered entries found for this program.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($entriesForPrint as $entry): ?>
                                        <?php
                                            $chestNo = !empty($entry['chest_number']) ? $entry['chest_number'] : $entry['entry_number'];
                                            $formattedChest = is_numeric($chestNo) ? str_pad((string)$chestNo, 3, '0', STR_PAD_LEFT) : (string)$chestNo;
                                            $pName = !empty($entry['participant_name']) ? $entry['participant_name'] : (!empty($entry['team_name']) ? $entry['team_name'] : '—');
                                            $teamColor = !empty($entry['team_color']) ? $entry['team_color'] : null;
                                        ?>
                                        <tr>
                                            <td class="chest-col" rowspan="2" style="font-weight: 900; font-size: <?= $chestFontSize ?>; padding: <?= $cellPadding ?>; border-bottom: 1.5px solid #000; vertical-align: middle;">
                                                #<?= e($formattedChest) ?>
                                            </td>
                                            <td class="participant-col" rowspan="2" style="font-weight: 700; font-size: 13px; height: <?= $rowHeight ?>px; border-bottom: 1.5px solid #000; vertical-align: middle;">
                                                <?php if ($teamColor): ?>
                                                    <span class="team-indicator-dot" style="background: <?= e($teamColor) ?>;"></span>
                                                <?php endif; ?>
                                                <?= e($pName) ?>
                                            </td>
                                            <?php foreach ($categories as $cat): ?>
                                                <td style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                            <?php endforeach; ?>
                                            <td class="col-total" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                            <td class="col-rank" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                            <td class="col-notes" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                        </tr>
                                        <tr class="notes-row">
                                            <?php $notesColspan = count($categories) + 1; // categories + total ?>
                                            <td colspan="<?= $notesColspan ?>" class="notes-span-cell" style="height: 42px; border-bottom: 1.5px solid #000; border-top: none; border-left: none; border-right: 1.5px solid #000; text-align: left; padding: 4px 8px; font-size: 10px; color: #888; font-style: italic; letter-spacing: 0.05em; vertical-align: top;">
                                                Notes: 
                                            </td>
                                            <td class="col-rank" style="height: 42px; border-bottom: 1.5px solid #000; border-top: none; border-left: none;"></td>
                                            <td class="col-notes" style="height: 42px; border-bottom: 1.5px solid #000; border-top: none; border-left: none;"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php for ($b = 1; $b <= 10; $b++): ?>
                                    <tr class="extra-blank-row" data-blank-index="<?= $b ?>" style="display: none;">
                                        <td class="chest-col" rowspan="2" style="height: <?= $rowHeight ?>px; border-bottom: 1.5px solid #000; vertical-align: middle;"></td>
                                        <td class="participant-col" rowspan="2" style="height: <?= $rowHeight ?>px; border-bottom: 1.5px solid #000; vertical-align: middle;"></td>
                                        <?php foreach ($categories as $cat): ?>
                                            <td style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                        <?php endforeach; ?>
                                        <td class="col-total" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                        <td class="col-rank" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                        <td class="col-notes" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                    </tr>
                                    <tr class="extra-blank-row notes-row" data-blank-index="<?= $b ?>" style="display: none;">
                                        <?php $notesColspan = count($categories) + 1; ?>
                                        <td colspan="<?= $notesColspan ?>" class="notes-span-cell" style="height: 42px; border-bottom: 1.5px solid #000; border-top: none; border-left: none; border-right: 1.5px solid #000; text-align: left; padding: 4px 8px; font-size: 10px; color: #888; font-style: italic; letter-spacing: 0.05em; vertical-align: top;">
                                            Notes:
                                        </td>
                                        <td class="col-rank" style="height: 42px; border-bottom: 1.5px solid #000; border-top: none; border-left: none;"></td>
                                        <td class="col-notes" style="height: 42px; border-bottom: 1.5px solid #000; border-top: none; border-left: none;"></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="sheet-footer">
                        <div class="signature-box">
                            <span class="signature-label">Judge Name:</span>
                            <span class="signature-line"></span>
                        </div>
                        <div class="signature-box">
                            <span class="signature-label">Judge Signature:</span>
                            <span class="signature-line"></span>
                        </div>
                        <div class="signature-box">
                            <span class="signature-label">Date & Time:</span>
                            <span class="signature-line" style="width: 140px;"></span>
                        </div>
                        <div class="signature-box">
                            <span class="signature-label">Chief Judge Sign:</span>
                            <span class="signature-line" style="width: 140px;"></span>
                        </div>
                    </div>
                    <div class="confidential-notice">
                        Official Musabaqa Score Document — Scores once written and signed are final. Please submit directly to Tabulation Control.
                    </div>
                </div>
            <?php endfor; ?>
        <?php endforeach; ?>

        <script>
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('auto_print') === '1') {
                        window.print();
                    }
                }, 400);
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

$pageTitle = 'Score Sheets Printer';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';

$programs = [];
if ($activeEvent) {
    $activeEventId = (int)$activeEvent['id'];
    $stmt = $pdo->prepare("
        SELECT p.*, ct.name AS class_type_name,
               (SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = p.id AND event_id = p.event_id) AS entry_count
        FROM musabaqa_programs p
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
        WHERE p.event_id = ?
        ORDER BY COALESCE(mss.sort_order, 999) ASC, COALESCE(mss.start_time, '23:59:59') ASC, COALESCE(p.start_time, '23:59:59') ASC, p.id ASC
    ");
    $stmt->execute([$activeEventId]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="main-content">
<div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-file-invoice" style="color:#2563eb;"></i> Bulk Score Sheets Printer</h1>
            <p>Batch print customized judges' score sheets (Individual and Group programs separated)</p>
        </div>
        <div>
            <a href="<?= app_url('/admin/printer/index.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-chevron-left mr-1"></i> Printer Space
            </a>
        </div>
    </div>

    <?php if (!$activeEvent): ?>
        <?php render_no_active_event_guard(); ?>
    <?php else: ?>
        <div class="panel">
            <form action="<?= app_url('/admin/printer/score-sheets.php') ?>" method="GET" target="_blank" id="printForm">
                <input type="hidden" name="action" value="print">
                <input type="hidden" name="print_type" value="scores">

                <div class="flex-between mb-6" style="border-bottom: 1px solid var(--border); padding-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                    <h3 style="margin: 0; color: #fff;"><i class="fa-solid fa-list-check mr-2" style="color: #60a5fa;"></i> Event Programs Selection (Schedule Order)</h3>
                    
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <div class="btn-group" style="display: flex; gap: 6px; background: rgba(0,0,0,0.25); padding: 3px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                            <button type="button" class="btn btn-xs btn-primary filter-tab active" data-type="all">All Programs</button>
                            <button type="button" class="btn btn-xs btn-secondary filter-tab" data-type="individual"><i class="fa-solid fa-user mr-1"></i> Individual Only</button>
                            <button type="button" class="btn btn-xs btn-secondary filter-tab" data-type="group"><i class="fa-solid fa-users mr-1"></i> Group Only</button>
                        </div>

                        <input type="text" id="programSearch" class="form-input" placeholder="Search programs..." style="width: 170px; height: 34px; font-size: 13px;">
                        <button class="btn btn-secondary btn-sm" id="btnSelectAll" type="button">Select All</button>
                        <button class="btn btn-secondary btn-sm" id="btnDeselectAll" type="button">Deselect All</button>
                        <button type="submit" class="btn btn-primary btn-sm" style="background: #3b82f6; border-color: #3b82f6; font-weight: 700;">
                            <i class="fa-solid fa-print mr-1"></i> Print Selected Sheets
                        </button>
                    </div>
                </div>

<style>
.pro-checkbox-wrap {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    position: relative !important;
    cursor: pointer !important;
    vertical-align: middle !important;
    width: 20px !important;
    height: 20px !important;
    min-width: 20px !important;
    max-width: 20px !important;
    min-height: 20px !important;
    max-height: 20px !important;
    flex-shrink: 0 !important;
}
.pro-checkbox {
    appearance: none !important;
    -webkit-appearance: none !important;
    width: 20px !important;
    height: 20px !important;
    min-width: 20px !important;
    max-width: 20px !important;
    min-height: 20px !important;
    max-height: 20px !important;
    border: 2px solid rgba(255, 255, 255, 0.3) !important;
    border-radius: 5px !important;
    background: rgba(15, 23, 42, 0.6) !important;
    outline: none !important;
    cursor: pointer !important;
    transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1) !important;
    position: relative !important;
    vertical-align: middle !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    flex-shrink: 0 !important;
}
.pro-checkbox:hover {
    border-color: #a855f7 !important;
    background: rgba(168, 85, 247, 0.15) !important;
    box-shadow: 0 0 10px rgba(168, 85, 247, 0.3) !important;
}
.pro-checkbox:checked {
    background: linear-gradient(135deg, #7e22ce, #a855f7) !important;
    border-color: #a855f7 !important;
    box-shadow: 0 0 12px rgba(168, 85, 247, 0.4) !important;
}
.pro-checkbox:checked::after {
    content: '' !important;
    position: absolute !important;
    top: 50% !important;
    left: 50% !important;
    width: 5px !important;
    height: 10px !important;
    border: solid #ffffff !important;
    border-width: 0 2.5px 2.5px 0 !important;
    transform: translate(-50%, -60%) rotate(45deg) !important;
}
.pro-checkbox:focus-visible {
    box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.4) !important;
}
</style>

                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 44px; text-align: center;">
                                    <label class="pro-checkbox-wrap">
                                        <input type="checkbox" id="headerCheckbox" class="pro-checkbox">
                                    </label>
                                </th>
                                <th style="width: 80px;">Sched Order</th>
                                <th>Program Title</th>
                                <th>Category / Tier</th>
                                <th>Type</th>
                                <th>Entries Count</th>
                                <th style="width: 140px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="programsTableBody">
                            <?php if (empty($programs)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: var(--muted);">No programs found for this event.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($programs as $p): ?>
                                    <?php 
                                    $pId = (int)$p['id'];
                                    $pType = strtolower((string)($p['program_type'] ?? 'individual'));
                                    ?>
                                    <tr data-title="<?= e(strtolower($p['title'])) ?>" data-class="<?= e(strtolower($p['class_type_name'] ?? '')) ?>" data-type="<?= e($pType) ?>">
                                        <td style="text-align: center;">
                                            <label class="pro-checkbox-wrap">
                                                <input type="checkbox" name="program_ids[]" value="<?= $pId ?>" class="program-checkbox pro-checkbox" checked>
                                            </label>
                                        </td>
                                        <td><strong>#<?= (int)($p['schedule_order'] ?? $pId) ?></strong></td>
                                        <td><strong><?= e($p['title']) ?></strong></td>
                                        <td><?= e(admin_class_type_display($p['class_type_name'] ?? null, (int)($p['class_type_id'] ?? 0))) ?></td>
                                        <td>
                                            <span class="badge <?= $pType === 'group' ? 'badge-info' : 'badge-neutral' ?>">
                                                <i class="fa-solid <?= $pType === 'group' ? 'fa-users' : 'fa-user' ?> mr-1"></i>
                                                <?= ucfirst($pType) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-neutral" style="color: <?= $p['entry_count'] > 0 ? '#60a5fa' : 'var(--muted)' ?>;">
                                                <?= (int)$p['entry_count'] ?> entries
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="<?= app_url('/admin/printer/score-sheets.php') ?>?action=print&print_type=scores&program_ids[]=<?= $pId ?>" target="_blank" class="btn btn-primary btn-xs">
                                                <i class="fa-solid fa-print mr-1"></i> Score Sheet
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const tableBody = document.getElementById('programsTableBody');
    const headerCheckbox = document.getElementById('headerCheckbox');
    const btnSelectAll = document.getElementById('btnSelectAll');
    const btnDeselectAll = document.getElementById('btnDeselectAll');
    const filterTabs = document.querySelectorAll('.filter-tab');
    const searchInput = document.getElementById('programSearch');
    const STORAGE_KEY = 'score_sheets_selected_programs_<?= (int)($activeEvent['id'] ?? 0) ?>';

    let currentTypeFilter = 'all';

    function saveCheckedState() {
        if (!tableBody) return;
        const checkboxes = tableBody.querySelectorAll('.program-checkbox');
        const checkedValues = [];
        checkboxes.forEach(cb => {
            if (cb.checked) {
                checkedValues.push(cb.value);
            }
        });
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(checkedValues));
        } catch(e) {}
    }

    function loadCheckedState() {
        if (!tableBody) return;
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved !== null) {
                const checkedSet = new Set(JSON.parse(saved));
                const checkboxes = tableBody.querySelectorAll('.program-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = checkedSet.has(cb.value);
                });
            }
        } catch(e) {}
        updateHeaderCheckboxState();
    }

    function updateHeaderCheckboxState() {
        if (!headerCheckbox || !tableBody) return;
        const visibleCheckboxes = Array.from(tableBody.querySelectorAll('.program-checkbox'))
            .filter(cb => cb.closest('tr').style.display !== 'none');
        if (visibleCheckboxes.length > 0 && visibleCheckboxes.every(cb => cb.checked)) {
            headerCheckbox.checked = true;
        } else {
            headerCheckbox.checked = false;
        }
    }

    function applyTableFilters() {
        const term = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const rows = tableBody.querySelectorAll('tr[data-title]');
        rows.forEach(r => {
            const title = r.getAttribute('data-title') || '';
            const cls = r.getAttribute('data-class') || '';
            const type = r.getAttribute('data-type') || 'individual';

            const matchesSearch = title.includes(term) || cls.includes(term);
            const matchesType = (currentTypeFilter === 'all') || (type === currentTypeFilter);

            if (matchesSearch && matchesType) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });
        updateHeaderCheckboxState();
    }

    applyTableFilters();
    loadCheckedState();

    if (filterTabs) {
        filterTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                filterTabs.forEach(t => {
                    t.classList.remove('active', 'btn-primary');
                    t.classList.add('btn-secondary');
                });
                tab.classList.add('active', 'btn-primary');
                tab.classList.remove('btn-secondary');
                currentTypeFilter = tab.getAttribute('data-type');
                applyTableFilters();
            });
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyTableFilters);
    }

    if (tableBody) {
        tableBody.addEventListener('change', (e) => {
            if (e.target && e.target.classList.contains('program-checkbox')) {
                updateHeaderCheckboxState();
                saveCheckedState();
            }
        });
    }

    if (headerCheckbox) {
        headerCheckbox.addEventListener('change', () => {
            const checkboxes = tableBody.querySelectorAll('.program-checkbox');
            checkboxes.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = headerCheckbox.checked;
                }
            });
            saveCheckedState();
        });
    }

    if (btnSelectAll) {
        btnSelectAll.addEventListener('click', () => {
            const checkboxes = tableBody.querySelectorAll('.program-checkbox');
            checkboxes.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = true;
                }
            });
            if (headerCheckbox) headerCheckbox.checked = true;
            saveCheckedState();
        });
    }

    if (btnDeselectAll) {
        btnDeselectAll.addEventListener('click', () => {
            const checkboxes = tableBody.querySelectorAll('.program-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            if (headerCheckbox) headerCheckbox.checked = false;
            saveCheckedState();
        });
    }
});
</script>

<?php admin_close_page(); ?>
