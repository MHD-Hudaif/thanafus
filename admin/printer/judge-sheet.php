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
                   (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                    FROM musabaqa_entry_members em
                    JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                    WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> '') AS chest_number
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

$useLandscapeHalfSheet = ($participantsCount <= 10);
$pageSize = $useLandscapeHalfSheet ? 'A4 landscape' : 'A4 portrait';
$rowHeight = $useLandscapeHalfSheet ? ($participantsCount <= 5 ? 44 : 32) : ($participantsCount <= 15 ? 36 : 28);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Judge Chest Number Sheet - <?= e($programName) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page {
            size: <?= $pageSize ?>;
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
            padding: 12px 14px;
            box-sizing: border-box;
        }

        /* Printable Header Structure */
        .sheet-header {
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 10px;
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

        /* Printable Table Structure */
        .judge-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .judge-table th, .judge-table td {
            border: 1.5px solid #000;
            padding: 6px 8px;
            text-align: center;
            vertical-align: middle;
            font-size: 12px;
        }
        .judge-table th {
            background: #f1f5f9;
            font-weight: 800;
            text-transform: uppercase;
            font-size: 11px;
            padding: 8px 4px;
        }
        .chest-col {
            width: 95px;
            font-size: 14.5px;
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
            .no-print-controls {
                display: none !important;
            }
            body {
                background: #fff;
                padding: 0;
            }
            .sheet-container {
                box-shadow: none;
                margin: 0;
            }
            .judge-table th {
                background: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <!-- SCREEN-ONLY CONTROL PANEL -->
    <div class="no-print-controls">
        <div class="controls-wrapper">
            <div class="controls-title">
                <i class="fa-solid fa-clipboard-check" style="color: #38bdf8;"></i>
                <span>Judge Chest Number Sheet Generator</span>
                <span class="layout-indicator-badge">
                    <i class="fa-solid <?= $useLandscapeHalfSheet ? 'fa-square-minus' : 'fa-copy' ?>"></i>
                    <?= $useLandscapeHalfSheet ? 'Layout 2: A4 Landscape Cut-Line (<=10)' : 'Layout 1: A4 Portrait 1-per-Judge (>10)' ?>
                </span>
            </div>

            <form method="GET" class="controls-form">
                <?php if ($programId > 0): ?>
                    <input type="hidden" name="program_id" value="<?= $programId ?>">
                <?php endif; ?>

                <div>
                    <label style="font-size: 11px; color: #94a3b8; display: block; margin-bottom: 2px;">Program Title</label>
                    <input type="text" name="program" value="<?= e($programName) ?>" class="control-input" style="width: 200px;">
                </div>

                <div>
                    <label style="font-size: 11px; color: #94a3b8; display: block; margin-bottom: 2px;">Participants</label>
                    <input type="number" name="participants" value="<?= $participantsCount ?>" min="1" max="50" class="control-input" style="width: 75px; text-align: center;">
                </div>

                <div>
                    <label style="font-size: 11px; color: #94a3b8; display: block; margin-bottom: 2px;">Judges</label>
                    <input type="number" name="judges" value="<?= $judgesCount ?>" min="1" max="6" class="control-input" style="width: 65px; text-align: center;">
                </div>

                <div>
                    <label style="font-size: 11px; color: #94a3b8; display: block; margin-bottom: 2px;">Categories</label>
                    <div style="display: flex; gap: 4px;">
                        <input type="text" name="cat1" value="<?= e($cat1) ?>" class="control-input" style="width: 90px;" placeholder="Cat 1">
                        <input type="text" name="cat2" value="<?= e($cat2) ?>" class="control-input" style="width: 90px;" placeholder="Cat 2">
                        <input type="text" name="cat3" value="<?= e($cat3) ?>" class="control-input" style="width: 90px;" placeholder="Cat 3">
                        <input type="text" name="cat4" value="<?= e($cat4) ?>" class="control-input" style="width: 90px;" placeholder="Cat 4">
                    </div>
                </div>

                <button type="submit" class="control-input" style="background: #3b82f6; cursor: pointer; border: none; font-weight: 700; margin-top: 14px;">
                    Update
                </button>

                <button type="button" onclick="window.print()" class="print-trigger-btn" style="margin-top: 14px;">
                    <i class="fa-solid fa-print"></i> Print Sheet
                </button>
            </form>
        </div>
    </div>

    <!-- DOCUMENT PRINT CONTAINER -->
    <div class="sheet-container">
        <?php if ($useLandscapeHalfSheet): ?>
            <!-- ================================================== -->
            <!-- LAYOUT 2 – 10 PARTICIPANTS OR LESS (A4 LANDSCAPE) -->
            <!-- ================================================== -->
            <div class="landscape-halves-wrapper">
                <div class="cut-line-divider">
                    <span class="cut-line-indicator">✂ CUT HERE FOR JUDGE 1 / JUDGE 2 ✂</span>
                </div>

                <?php for ($j = 1; $j <= min(2, $judgesCount); $j++): ?>
                    <div class="sheet-card">
                        <div class="sheet-header">
                            <div class="brand-title">KAUZARIYYA MUSABAQA</div>
                            <div class="sheet-subtitle">Judge Chest Number Sheet</div>

                            <div class="header-meta-lines">
                                <div class="meta-line">
                                    <span class="meta-label">Program:</span>
                                    <span class="meta-underline"><?= e($programName) ?></span>
                                </div>
                                <div style="width: 20px;"></div>
                                <div class="meta-line" style="max-width: 140px;">
                                    <span class="meta-label">Judge:</span>
                                    <span class="meta-underline">Judge <?= $j ?></span>
                                </div>
                            </div>
                        </div>

                        <table class="judge-table">
                            <thead>
                                <tr>
                                    <th class="chest-col">Chest No.</th>
                                    <th><?= e($cat1) ?></th>
                                    <th><?= e($cat2) ?></th>
                                    <th><?= e($cat3) ?></th>
                                    <th><?= e($cat4) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($chestNumbers as $chest): ?>
                                    <tr>
                                        <td class="chest-col">#<?= e($chest) ?></td>
                                        <td style="height: <?= $rowHeight ?>px;"></td>
                                        <td style="height: <?= $rowHeight ?>px;"></td>
                                        <td style="height: <?= $rowHeight ?>px;"></td>
                                        <td style="height: <?= $rowHeight ?>px;"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endfor; ?>
            </div>

        <?php else: ?>
            <!-- ================================================== -->
            <!-- LAYOUT 1 – MORE THAN 10 PARTICIPANTS (A4 PORTRAIT) -->
            <!-- ================================================== -->
            <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                <div class="sheet-card <?= ($j > 1) ? 'page-break' : '' ?>">
                    <div class="sheet-header">
                        <div class="brand-title">KAUZARIYYA MUSABAQA</div>
                        <div class="sheet-subtitle">Judge Chest Number Sheet</div>

                        <div class="header-meta-lines">
                            <div class="meta-line">
                                <span class="meta-label">Program:</span>
                                <span class="meta-underline"><?= e($programName) ?></span>
                            </div>
                            <div style="width: 40px;"></div>
                            <div class="meta-line" style="max-width: 180px;">
                                <span class="meta-label">Judge:</span>
                                <span class="meta-underline">Judge <?= $j ?></span>
                            </div>
                        </div>
                    </div>

                    <table class="judge-table">
                        <thead>
                            <tr>
                                <th class="chest-col">Chest No.</th>
                                <th><?= e($cat1) ?></th>
                                <th><?= e($cat2) ?></th>
                                <th><?= e($cat3) ?></th>
                                <th><?= e($cat4) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($chestNumbers as $chest): ?>
                                <tr>
                                    <td class="chest-col">#<?= e($chest) ?></td>
                                    <td style="height: <?= $rowHeight ?>px;"></td>
                                    <td style="height: <?= $rowHeight ?>px;"></td>
                                    <td style="height: <?= $rowHeight ?>px;"></td>
                                    <td style="height: <?= $rowHeight ?>px;"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endfor; ?>
        <?php endif; ?>
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
