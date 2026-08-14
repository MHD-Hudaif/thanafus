<?php
$pageTitle = 'Emcee Running Order Sheet (Side-by-Side)';
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

$activeEvent = get_active_musabaqa();
$pdo = $GLOBALS['musabaqa_pdo'];

// Parameter extraction
$programId1 = (int)($_GET['program_id'] ?? 0);
$programId2 = (int)($_GET['program_id2'] ?? 0);
$programName1 = trim((string)($_GET['program1'] ?? $_GET['program'] ?? ''));
$programName2 = trim((string)($_GET['program2'] ?? ''));
$participantsCount = max(1, min(50, (int)($_GET['participants'] ?? 8)));

if (!empty($_GET['program_ids']) && is_array($_GET['program_ids'])) {
    $programIds = array_values(array_filter(array_map('intval', $_GET['program_ids'])));
    if ($programId1 <= 0 && isset($programIds[0])) {
        $programId1 = $programIds[0];
    }
    if ($programId2 <= 0 && isset($programIds[1])) {
        $programId2 = $programIds[1];
    }
}

$chestNumbers1 = [];
$chestNumbers2 = [];
$eventPrograms = [];
$titleCounts = [];

$resolveChestNumber = static function (array $entry): string {
    $cNo = trim((string)($entry['chest_number'] ?? ''));
    if ($cNo === '' || $cNo === '-') {
        return '-';
    }

    // GROUP_CONCAT may return multiple chest numbers for group entries; use the first for display.
    if (str_contains($cNo, ',')) {
        $parts = array_values(array_filter(array_map('trim', explode(',', $cNo))));
        $cNo = $parts[0] ?? '';
        if ($cNo === '') {
            return '-';
        }
    }

    return is_numeric($cNo) ? str_pad((string)$cNo, 3, '0', STR_PAD_LEFT) : $cNo;
};

$loadProgramSheet = static function (PDO $pdo, int $programId, int $eventId, array $titleCounts) use ($resolveChestNumber): ?array {
    if ($programId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT p.*, ct.name AS class_type_name
        FROM musabaqa_programs p
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        WHERE p.id = ? AND p.event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$programId, $eventId]);
    $programData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$programData) {
        return null;
    }

    $rawTitle = trim((string)$programData['title']);
    $tKey = strtolower($rawTitle);
    $hasMultipleSectionsWithSameTitle = ($titleCounts[$tKey] ?? 0) > 1;

    $tier = admin_class_type_tier_from_name($programData['class_type_name'] ?? '');
    $sectionLabel = $tier ? admin_class_type_tier_label($tier) : '';

    if (!$sectionLabel && !empty($programData['allowed_sections'])) {
        $secParts = array_filter(array_map('trim', explode(',', (string)$programData['allowed_sections'])));
        if (count($secParts) === 1) {
            $sectionLabel = reset($secParts);
        }
    }

    $isGeneral = !$sectionLabel || in_array(strtolower($sectionLabel), ['general', 'all classes', 'general / multi-section'], true);
    $displayName = (!$isGeneral && $hasMultipleSectionsWithSameTitle)
        ? $rawTitle . ' - ' . $sectionLabel
        : $rawTitle;

    $entStmt = $pdo->prepare("
        SELECT pe.*,
               (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                FROM musabaqa_entry_members em
                JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND TRIM(tm.chest_number) <> '') AS chest_number,
               (SELECT tm.chest_number
                FROM musabaqa_team_members tm
                JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                WHERE tm.team_id = pe.team_id
                  AND TRIM(LOWER(s.full_name)) = TRIM(LOWER(pe.entry_name))
                  AND tm.chest_number IS NOT NULL AND TRIM(tm.chest_number) <> ''
                LIMIT 1) AS matched_chest_number
        FROM musabaqa_program_entries pe
        WHERE pe.event_id = ? AND pe.program_id = ?
        ORDER BY pe.performance_order ASC, pe.id ASC
    ");
    $entStmt->execute([$eventId, $programId]);
    $dbEntries = $entStmt->fetchAll(PDO::FETCH_ASSOC);

    $chestNumbers = [];
    foreach ($dbEntries as $entry) {
        if (empty(trim((string)($entry['chest_number'] ?? ''))) && !empty(trim((string)($entry['matched_chest_number'] ?? '')))) {
            $entry['chest_number'] = $entry['matched_chest_number'];
        }
        $chestNumbers[] = $resolveChestNumber($entry);
    }

    return [
        'name' => $displayName,
        'chest_numbers' => $chestNumbers,
    ];
};

if ($activeEvent) {
    $eventId = (int)$activeEvent['id'];
    $allEvtProgramsStmt = $pdo->prepare("
        SELECT p.id, p.title, ct.name AS class_type_name
        FROM musabaqa_programs p
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        WHERE p.event_id = ?
        ORDER BY COALESCE(p.start_time, '23:59:59') ASC, p.id ASC
    ");
    $allEvtProgramsStmt->execute([$eventId]);
    $eventPrograms = $allEvtProgramsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($eventPrograms as $ap) {
        $tKey = strtolower(trim((string)$ap['title']));
        $titleCounts[$tKey] = ($titleCounts[$tKey] ?? 0) + 1;
    }

    $loaded1 = $loadProgramSheet($pdo, $programId1, $eventId, $titleCounts);
    if ($loaded1) {
        $programName1 = $loaded1['name'];
        $chestNumbers1 = $loaded1['chest_numbers'];
    }

    $loaded2 = $loadProgramSheet($pdo, $programId2, $eventId, $titleCounts);
    if ($loaded2) {
        $programName2 = $loaded2['name'];
        $chestNumbers2 = $loaded2['chest_numbers'];
    }
}

if ($programName1 === '') {
    $programName1 = 'Left Program';
}
if ($programName2 === '') {
    $programName2 = 'Right Program';
}

$displayRowCount = max(
    count($chestNumbers1),
    count($chestNumbers2),
    ($programId1 <= 0 && $programId2 <= 0) ? $participantsCount : 0
);
if ($displayRowCount <= 0) {
    $displayRowCount = 1;
}

$rowHeight = $displayRowCount <= 5 ? 48 : ($displayRowCount <= 10 ? 36 : 24);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Emcee Running Order Sheet (Side-by-Side) - <?= e($programName1) ?></title>
    <link rel="stylesheet" href="<?= asset_url('css/event-id-cards.css') ?>">
    <script src="<?= asset_url('js/print-helpers.js') ?>" defer></script>
    <style>
        @page {
            size: A4 landscape;
            margin: 5mm 7mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'Arial', 'Helvetica Neue', system-ui, sans-serif;
            color: #000;
            background: #f8fafc;
            margin: 0;
            padding: 0;
            line-height: 1.2;
        }

        /* Screen Control Panel */
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
            max-width: 1100px;
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

        /* Side-by-Side Landscape Container */
        .sheet-container {
            background: #fff;
            margin: 20px auto;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            max-width: 1100px;
        }
        .landscape-page {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            position: relative;
            width: 100%;
            padding: 10px 14px;
            box-sizing: border-box;
        }
        .vertical-divider {
            position: absolute;
            left: 50%;
            top: 0;
            bottom: 0;
            border-left: 2px solid #000;
            transform: translateX(-50%);
        }
        .program-half {
            padding: 6px 10px;
            display: flex;
            flex-direction: column;
        }
        .sheet-header {
            margin-bottom: 10px;
        }
        .single-program-title {
            font-size: 21px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            text-align: center;
            color: #000;
            padding-bottom: 5px;
            border-bottom: 3px double #000;
        }
        .emcee-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin-top: 4px;
            border: 2px solid #000;
        }
        .emcee-table th, .emcee-table td {
            border: 2px solid #000;
            padding: 8px 10px;
            text-align: center;
            vertical-align: middle;
        }
        .emcee-table th {
            background: #f1f5f9;
            font-weight: 900;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.06em;
            padding: 9px 4px;
        }
        .order-col {
            width: 35%;
            font-size: 22px;
            font-weight: 900;
            text-align: center;
        }
        .chest-col {
            font-size: 26px;
            font-weight: 900;
            letter-spacing: 0.04em;
            text-align: center;
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
                max-width: 100%;
            }
            .emcee-table th {
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
                <i class="fa-solid fa-microphone" style="color: #38bdf8;"></i>
                <span>Side-by-Side Emcee Running Order Generator</span>
            </div>

            <form method="GET" class="controls-form">
                <?php if (!empty($eventPrograms)): ?>
                    <div>
                        <label style="font-size: 11px; color: #94a3b8; display: block; margin-bottom: 2px;">Left Program</label>
                        <select name="program_id" class="control-input" style="width: 220px;">
                            <option value="">-- Select program --</option>
                            <?php foreach ($eventPrograms as $prog): ?>
                                <option value="<?= (int)$prog['id'] ?>" <?= $programId1 === (int)$prog['id'] ? 'selected' : '' ?>>
                                    <?= e($prog['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="font-size: 11px; color: #94a3b8; display: block; margin-bottom: 2px;">Right Program</label>
                        <select name="program_id2" class="control-input" style="width: 220px;">
                            <option value="">-- Select program --</option>
                            <?php foreach ($eventPrograms as $prog): ?>
                                <option value="<?= (int)$prog['id'] ?>" <?= $programId2 === (int)$prog['id'] ? 'selected' : '' ?>>
                                    <?= e($prog['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <?php if ($programId1 > 0): ?>
                        <input type="hidden" name="program_id" value="<?= $programId1 ?>">
                    <?php endif; ?>
                    <?php if ($programId2 > 0): ?>
                        <input type="hidden" name="program_id2" value="<?= $programId2 ?>">
                    <?php endif; ?>
                <?php endif; ?>

                <div>
                    <label style="font-size: 11px; color: #94a3b8; display: block; margin-bottom: 2px;">Left Title Override</label>
                    <input type="text" name="program1" value="<?= e($programName1) ?>" class="control-input" style="width: 220px;">
                </div>

                <div>
                    <label style="font-size: 11px; color: #94a3b8; display: block; margin-bottom: 2px;">Right Title Override</label>
                    <input type="text" name="program2" value="<?= e($programName2) ?>" class="control-input" style="width: 220px;">
                </div>

                <?php if ($programId1 <= 0 && $programId2 <= 0): ?>
                    <div>
                        <label style="font-size: 11px; color: #94a3b8; display: block; margin-bottom: 2px;">Preview Rows</label>
                        <input type="number" name="participants" value="<?= $participantsCount ?>" min="1" max="50" class="control-input" style="width: 80px; text-align: center;">
                    </div>
                <?php endif; ?>

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
        <div class="landscape-page">
            <div class="vertical-divider"></div>

            <!-- LEFT HALF (PROGRAM 1) -->
            <div class="program-half">
                <div class="sheet-header">
                    <div class="single-program-title"><?= e($programName1) ?></div>
                </div>

                <table class="emcee-table">
                    <thead>
                        <tr>
                            <th class="order-col">ORDER</th>
                            <th class="chest-col">CHEST NUMBER</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($chestNumbers1)): ?>
                            <tr>
                                <td colspan="2" style="text-align: center; padding: 24px; color: #64748b; font-size: 14px;">
                                    <?= $programId1 > 0 ? 'No entries registered for this program.' : 'Select a left program to load chest numbers.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $orderIdx = 1; ?>
                            <?php foreach ($chestNumbers1 as $chest): ?>
                                <tr>
                                    <td class="order-col" style="height: <?= $rowHeight ?>px;"><?= $orderIdx++ ?></td>
                                    <td class="chest-col" style="height: <?= $rowHeight ?>px;"><?= $chest === '-' ? e($chest) : '#' . e($chest) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- RIGHT HALF (PROGRAM 2) -->
            <div class="program-half">
                <div class="sheet-header">
                    <div class="single-program-title"><?= e($programName2) ?></div>
                </div>

                <table class="emcee-table">
                    <thead>
                        <tr>
                            <th class="order-col">ORDER</th>
                            <th class="chest-col">CHEST NUMBER</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($chestNumbers2)): ?>
                            <tr>
                                <td colspan="2" style="text-align: center; padding: 24px; color: #64748b; font-size: 14px;">
                                    <?= $programId2 > 0 ? 'No entries registered for this program.' : 'Select a right program to load chest numbers.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $orderIdx = 1; ?>
                            <?php foreach ($chestNumbers2 as $chest): ?>
                                <tr>
                                    <td class="order-col" style="height: <?= $rowHeight ?>px;"><?= $orderIdx++ ?></td>
                                    <td class="chest-col" style="height: <?= $rowHeight ?>px;"><?= $chest === '-' ? e($chest) : '#' . e($chest) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
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
