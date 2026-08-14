<?php
$pageTitle = 'Judge Chest Number Sheet';
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

$activeEvent = get_active_musabaqa();
$pdo = $GLOBALS['musabaqa_pdo'];

// Param extraction with sensible defaults
$programId = (int)($_GET['program_id'] ?? 0);
$programName = trim((string)($_GET['program'] ?? ''));
$participantsCount = max(1, min(50, (int)($_GET['participants'] ?? 8)));
$judgesCount = max(1, min(6, (int)($_GET['judges'] ?? 2)));

$cat1 = trim((string)($_GET['cat1'] ?? 'Category 1'));
$cat2 = trim((string)($_GET['cat2'] ?? 'Category 2'));
$cat3 = trim((string)($_GET['cat3'] ?? 'Category 3'));
$cat4 = trim((string)($_GET['cat4'] ?? 'Category 4'));

$chestNumbers = [];

if ($programId > 0 && $activeEvent) {
    // Fetch actual program details if program_id passed
    $stmt = $pdo->prepare("
        SELECT p.*, ct.name AS class_type_name
        FROM musabaqa_programs p
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        WHERE p.id = ? AND p.event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$programId, (int)$activeEvent['id']]);
    $programData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($programData) {
        if (empty($programName)) {
            $tier = admin_class_type_tier_from_name($programData['class_type_name'] ?? '');
            $secLabel = $tier ? admin_class_type_tier_label($tier) : '';
            if ($secLabel && !in_array(strtolower($secLabel), ['general', 'all classes'], true)) {
                $programName = $programData['title'] . ' - ' . $secLabel;
            } else {
                $programName = $programData['title'];
            }
        }

        // Fetch categories if available
        $catStmt = $pdo->prepare("SELECT name FROM musabaqa_program_categories WHERE program_id = ? ORDER BY sort_order ASC, id ASC LIMIT 4");
        $catStmt->execute([$programId]);
        $fetchedCats = $catStmt->fetchAll(PDO::FETCH_COLUMN);
        if (isset($fetchedCats[0])) $cat1 = $fetchedCats[0];
        if (isset($fetchedCats[1])) $cat2 = $fetchedCats[1];
        if (isset($fetchedCats[2])) $cat3 = $fetchedCats[2];
        if (isset($fetchedCats[3])) $cat4 = $fetchedCats[3];

        // Fetch registered entries
        $entStmt = $pdo->prepare("
            SELECT pe.*,
                   " . admin_entry_chest_number_subquery() . "
            FROM musabaqa_program_entries pe
            WHERE pe.event_id = ? AND pe.program_id = ?
            ORDER BY pe.performance_order ASC, pe.id ASC
        ");
        $entStmt->execute([(int)$activeEvent['id'], $programId]);
        $dbEntries = $entStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($dbEntries)) {
            $participantsCount = count($dbEntries);
            foreach ($dbEntries as $ent) {
                $cNo = !empty($ent['chest_number']) ? $ent['chest_number'] : $ent['entry_number'];
                $chestNumbers[] = is_numeric($cNo) ? str_pad((string)$cNo, 3, '0', STR_PAD_LEFT) : (string)$cNo;
            }
        }
    }
}

if (empty($programName)) {
    $programName = 'Urdu Speech - Sub Junior';
}

if (empty($chestNumbers)) {
    for ($i = 1; $i <= $participantsCount; $i++) {
        $chestNumbers[] = str_pad((string)(100 + $i), 3, '0', STR_PAD_LEFT);
    }
}

$pageSize = 'A4 landscape';
$rowHeight = ($participantsCount <= 6) ? 40 : (($participantsCount <= 12) ? 32 : 26);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Judge Chest Number Sheet - <?= e($programName) ?></title>
    <link rel="stylesheet" href="<?= asset_url('css/event-id-cards.css') ?>">
    <script src="<?= asset_url('js/print-helpers.js') ?>" defer></script>
    <style>
        @page {
            size: A4 landscape;
            margin: 6mm 8mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', Arial, sans-serif;
            color: #000;
            background: #f8fafc;
            margin: 0;
            padding: 0;
            line-height: 1.3;
        }

        /* Screen Interactive Control Bar */
        .no-print-controls {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            background: #0f172a;
            color: #fff;
            padding: 14px 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
            z-index: 10000;
            font-family: 'Inter', system-ui, sans-serif;
        }
        .controls-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .controls-title {
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .controls-form {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .control-input {
            background: #1e293b;
            border: 1px solid #334155;
            color: #fff;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
        }
        .control-input::placeholder {
            color: #94a3b8;
        }
        .layout-indicator-badge {
            background: #2563eb;
            color: #fff;
            font-size: 11.5px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .print-trigger-btn {
            background: #10b981;
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(16,185,129,0.3);
        }
        .print-trigger-btn:hover {
            background: #059669;
        }

        /* Sheet Document Styles (Clean Black & White Print Layout) */
        .sheet-container {
            background: #fff;
            margin: 20px auto;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .sheet-card {
            background: #fff;
            color: #000;
            padding: 16px 20px;
            box-sizing: border-box;
            width: 100%;
            min-height: calc(100vh - 120px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Printable Header Structure */
        .sheet-header {
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 10px;
            flex-shrink: 0;
        }
        .brand-title {
            font-size: 18px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            text-align: center;
            line-height: 1.1;
        }
        .sheet-subtitle {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
            margin-top: 3px;
            letter-spacing: 0.05em;
        }
        .header-meta-lines {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 13px;
        }
        .meta-line {
            display: flex;
            align-items: baseline;
            gap: 6px;
            flex: 1;
        }
        .meta-label {
            font-weight: 800;
            text-transform: uppercase;
            font-size: 12px;
        }
        .meta-underline {
            border-bottom: 1.5px solid #000;
            flex-grow: 1;
            padding-bottom: 2px;
            font-weight: 700;
            font-size: 13.5px;
        }

        .sheet-table-wrapper {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            margin-top: 6px;
            margin-bottom: 8px;
            min-height: 0;
        }

        /* Printable Table Structure */
        .judge-table {
            width: 100%;
            height: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }
        .judge-table th, .judge-table td {
            border: 1.5px solid #000;
            text-align: center;
            vertical-align: middle;
        }
        .judge-table th {
            background: #f1f5f9;
            font-weight: 800;
            text-transform: uppercase;
        }
        .chest-col {
            width: 95px;
            font-weight: 900;
            text-align: center !important;
        }

        /* Cut Line styling for Landscape 2-Half Layout */
        .landscape-halves-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            position: relative;
            width: 100%;
        }
        .cut-line-divider {
            position: absolute;
            left: 50%;
            top: 0;
            bottom: 0;
            border-left: 2px dashed #000;
            transform: translateX(-50%);
        }
        .cut-line-indicator {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-90deg);
            background: #fff;
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 800;
            color: #000;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .page-break {
            page-break-before: always !important;
            break-before: page !important;
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 15mm 20mm;
            }
            html, body {
                height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
            .no-print-controls {
                display: none !important;
            }
            .sheet-container {
                box-shadow: none !important;
                margin: 0 !important;
            }
            .sheet-card {
                padding: 4mm 6mm !important;
                margin: 0 !important;
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
            .sheet-card:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .sheet-header {
                flex-shrink: 0 !important;
            }
            .sheet-table-wrapper {
                flex: 1 1 auto !important;
                display: flex !important;
                flex-direction: column !important;
                margin: 4px 0 !important;
                min-height: 0 !important;
            }
            .judge-table {
                height: 100% !important;
            }
            .judge-table tbody {
                height: 100% !important;
            }
            .judge-table th {
                background: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body data-print-orientation="landscape">

    <!-- SCREEN-ONLY CONTROL PANEL -->
    <div class="no-print-controls">
        <div class="controls-wrapper">
            <div class="controls-title">
                <i class="fa-solid fa-clipboard-check" style="color: #38bdf8;"></i>
                <span>Judge Chest Number Sheet</span>
            </div>

            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="<?= app_url('/admin/printer/score-sheets.php') ?>" class="control-input" style="padding: 7px 14px; text-decoration: none; color: #fff; background: #334155; border-radius: 6px; font-size: 13px; font-weight: 600;">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Selection
                </a>
                <button type="button" onclick="exportAllTablesToExcel('table')" class="control-input" style="padding: 7px 14px; text-decoration: none; color: #fff; background: #16a34a; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                    <i class="fa-solid fa-file-excel"></i> Copy for Excel
                </button>
                <button type="button" onclick="window.print()" class="print-trigger-btn">
                    <i class="fa-solid fa-print"></i> Print Sheet (Ctrl+P)
                </button>
            </div>
        </div>
    </div>

    <!-- DOCUMENT PRINT CONTAINER -->
    <div class="sheet-container">
        <?php
        $entryCount = max(1, count($chestNumbers));
        if ($entryCount <= 8) {
            $cellPadding = '12px 10px';
            $chestFontSize = '22px';
            $thFontSize = '17px';
        } elseif ($entryCount <= 14) {
            $cellPadding = '8px 8px';
            $chestFontSize = '19px';
            $thFontSize = '15px';
        } else {
            $cellPadding = '5px 6px';
            $chestFontSize = '16px';
            $thFontSize = '13px';
        }
        ?>
        <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
            <div class="sheet-card <?= ($j > 1) ? 'page-break' : '' ?>">
                <div class="sheet-header" style="border-bottom: 2.5px solid #000; padding-bottom: 8px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 28px; font-weight: 900; letter-spacing: -0.02em; color: #000;">
                        <?= e($programName) ?>
                    </div>
                    <div style="background: #0f172a; color: #fff; font-size: 16px; font-weight: 900; padding: 8px 22px; border-radius: 6px; text-transform: uppercase;">
                        JUDGE <?= $j ?> SCORE SHEET
                    </div>
                </div>

                <div class="sheet-table-wrapper">
                    <table class="judge-table">
                        <thead>
                            <tr>
                                <th class="chest-col" style="font-size: <?= $thFontSize ?>; font-weight: 900; padding: 10px 8px; width: 130px;">Chest #</th>
                                <th style="font-size: <?= $thFontSize ?>; font-weight: 900; padding: 10px 8px;"><?= e($cat1) ?></th>
                                <th style="font-size: <?= $thFontSize ?>; font-weight: 900; padding: 10px 8px;"><?= e($cat2) ?></th>
                                <th style="font-size: <?= $thFontSize ?>; font-weight: 900; padding: 10px 8px;"><?= e($cat3) ?></th>
                                <th style="font-size: <?= $thFontSize ?>; font-weight: 900; padding: 10px 8px;"><?= e($cat4) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($chestNumbers as $chest): ?>
                                <tr>
                                    <td class="chest-col" style="font-weight: 900; font-size: <?= $chestFontSize ?>; padding: <?= $cellPadding ?>;">#<?= e($chest) ?></td>
                                    <td style="padding: <?= $cellPadding ?>;"></td>
                                    <td style="padding: <?= $cellPadding ?>;"></td>
                                    <td style="padding: <?= $cellPadding ?>;"></td>
                                    <td style="padding: <?= $cellPadding ?>;"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endfor; ?>
    </div>

    <script>
        // Auto print trigger if requested via URL param ?auto_print=1
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto_print') === '1') {
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    window.print();
                }, 400);
            });
        }
    </script>
</body>
</html>
