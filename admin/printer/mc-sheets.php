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
$filterType = (string)($_GET['program_type_filter'] ?? 'all');
$includeOffstage = (bool)($_GET['include_offstage'] ?? false);

if ($action === 'print' && $activeEvent) {
    $activeEventId = (int)$activeEvent['id'];
    $programIds = $_GET['program_ids'] ?? [];

    if (!is_array($programIds)) {
        $programIds = [];
    }
    $programIds = array_filter(array_map('intval', $programIds));

    if (empty($programIds)) {
        exit('Please select at least one program to print.');
    }

    // Fetch all programs of the active event to check duplicate titles across sections
    $allEvtProgramsStmt = $pdo->prepare("SELECT id, title FROM musabaqa_programs WHERE event_id = ?");
    $allEvtProgramsStmt->execute([$activeEventId]);
    $allEvtPrograms = $allEvtProgramsStmt->fetchAll(PDO::FETCH_ASSOC);

    $titleCounts = [];
    foreach ($allEvtPrograms as $ap) {
        $tKey = strtolower(trim($ap['title']));
        $titleCounts[$tKey] = ($titleCounts[$tKey] ?? 0) + 1;
    }

    $placeholders = implode(',', array_fill(0, count($programIds), '?'));
    $stmt = $pdo->prepare("
        SELECT p.*, ct.name AS class_type_name, mst.name AS stage_type_name
        FROM musabaqa_programs p
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
        LEFT JOIN musabaqa_stage_types mst ON mst.id = p.stage_type_id
        WHERE p.id IN ($placeholders) AND p.event_id = ?
        ORDER BY COALESCE(mss.sort_order, 999) ASC, COALESCE(mss.start_time, '23:59:59') ASC, COALESCE(p.start_time, '23:59:59') ASC, p.id ASC
    ");
    $stmt->execute(array_merge($programIds, [$activeEventId]));
    $fetchedPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter out Off-Stage programs unless explicitly requested
    $programs = [];
    foreach ($fetchedPrograms as $p) {
        $stageName = strtolower(trim((string)($p['stage_type_name'] ?? '')));
        $location = strtolower(trim((string)($p['location'] ?? '')));
        $isOffStageFlag = !empty($p['is_off_stage']) || !empty($p['is_offstage']);

        $isOffstage = $isOffStageFlag || str_contains($stageName, 'off') || str_contains($location, 'off');
        if (!$isOffstage || $includeOffstage) {
            $programs[] = $p;
        }
    }

    if (empty($programs)) {
        exit('No on-stage programs found in your selection to print.');
    }

    $entriesByProgram = [];
    foreach ($programs as $p) {
        $pId = (int)$p['id'];
        $entStmt = $pdo->prepare("
            SELECT pe.*,
                   " . admin_entry_chest_number_subquery() . "
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

    $orderedPrograms = array_merge($individualPrograms, $groupPrograms);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Emcee Running Order Sheets - <?= e($activeEvent['title']) ?></title>
        <link rel="stylesheet" href="<?= asset_url('css/event-id-cards.css') ?>">
        <script src="<?= asset_url('js/print-helpers.js') ?>" defer></script>
        <style>
            @page {
                size: A4 portrait;
                margin: 10mm 12mm;
            }
            * {
                box-sizing: border-box;
            }
            body {
                font-family: 'Arial', 'Helvetica Neue', system-ui, sans-serif;
                color: #000;
                background: #f8fafc;
                margin: 0;
                padding: 20px;
                line-height: 1.2;
            }
            .portrait-mc-page {
                width: 92%;
                max-width: 92%;
                margin: 0 auto 30px auto;
                background: #fff;
                border: 1.5px solid #cbd5e1;
                padding: 24px 28px;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08);
                box-sizing: border-box;
                min-height: 88vh;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                page-break-after: always !important;
                break-after: page !important;
            }
            .portrait-mc-page:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .sheet-header {
                margin-bottom: 16px;
                text-align: center;
                flex-shrink: 0;
            }
            .single-program-title {
                font-size: 32px;
                font-weight: 900;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                text-align: center;
                color: #000;
                padding-bottom: 8px;
                border-bottom: 4px solid #000;
                line-height: 1.2;
            }
            .emcee-table {
                width: 100%;
                height: 100%;
                flex: 1 1 auto;
                table-layout: fixed;
                border-collapse: collapse;
                margin-top: 6px;
                border: 3px solid #000;
            }
            .emcee-table th, .emcee-table td {
                border: 2.5px solid #000;
                padding: 8px 12px;
                text-align: center;
                vertical-align: middle;
            }
            .emcee-table th {
                background: #ffffff;
                font-weight: 900;
                text-transform: uppercase;
                font-size: 16px;
                letter-spacing: 0.06em;
                padding: 12px 8px;
            }
            .order-col {
                width: 30%;
                font-weight: 900;
                text-align: center;
            }
            .chest-col {
                width: 70%;
                font-weight: 900;
                letter-spacing: 0.04em;
                text-align: center;
            }
            .page-break {
                page-break-before: always !important;
                break-before: page !important;
            }
            .no-print-bar {
                background: #0f172a;
                padding: 12px 20px;
                border-radius: 12px;
                margin-bottom: 20px;
                width: 92%;
                max-width: 92%;
                margin-left: auto;
                margin-right: auto;
                color: #fff;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.25);
            }
            @media print {
                @page {
                    size: A4 portrait;
                    margin: 8mm 10mm;
                }
                .no-print-bar {
                    display: none !important;
                }
                body {
                    background: #fff !important;
                    padding: 0 !important;
                }
                .portrait-mc-page {
                    border: none !important;
                    padding: 0 !important;
                    margin: 0 auto !important;
                    box-shadow: none !important;
                    border-radius: 0 !important;
                    width: 92% !important;
                    max-width: 92% !important;
                    height: 88vh !important;
                    min-height: 88vh !important;
                    max-height: 88vh !important;
                    display: flex !important;
                    flex-direction: column !important;
                    justify-content: space-between !important;
                }
                .emcee-table {
                    height: 100% !important;
                    flex: 1 1 auto !important;
                }
                .emcee-table th {
                    background: #ffffff !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        </style>
    </head>
    <body data-print-orientation="portrait">
        <div class="no-print-bar">
            <div>
                <h3 style="margin:0; font-size: 16px; color:#fff;"><i class="fa-solid fa-microphone" style="color:#38bdf8;"></i> Emcee Stage Running Order Sheets</h3>
                <small style="color:#94a3b8;">Full-Page A4 Portrait Layout (90% Page Area)</small>
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <a href="<?= app_url('/admin/printer/mc-sheets.php') ?>" class="btn btn-secondary" style="padding: 6px 14px; text-decoration: none; color: #fff; background: #334155; border-radius: 6px; font-size: 12px; font-weight: 600;">
                    <i class="fa-solid fa-arrow-left"></i> Exit
                </a>
                <button data-print-action="print" class="btn btn-success" style="padding: 6px 16px; background: #10b981; color: #fff; border: none; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                    <i class="fa-solid fa-print"></i> Print Sheets (Ctrl+P)
                </button>
            </div>
        </div>

        <?php
        $pageIdx = 0;
        foreach ($orderedPrograms as $program):
            $pId = (int)$program['id'];
            $entriesForPrint = $entriesByProgram[$pId] ?? [];
            $entryCount = count($entriesForPrint);

            if ($entryCount <= 6) {
                $rowHeight = 72;
                $chestFontSize = '52px';
                $orderFontSize = '38px';
                $thFontSize = '18px';
            } elseif ($entryCount <= 12) {
                $rowHeight = 48;
                $chestFontSize = '40px';
                $orderFontSize = '30px';
                $thFontSize = '16px';
            } elseif ($entryCount <= 20) {
                $rowHeight = 36;
                $chestFontSize = '30px';
                $orderFontSize = '22px';
                $thFontSize = '15px';
            } else {
                $rowHeight = 28;
                $chestFontSize = '24px';
                $orderFontSize = '18px';
                $thFontSize = '13px';
            }

            $rawTitle = trim($program['title']);
            $tKey = strtolower($rawTitle);
            $hasMultipleSectionsWithSameTitle = ($titleCounts[$tKey] ?? 0) > 1;

            $tier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
            $sectionLabel = $tier ? admin_class_type_tier_label($tier) : '';

            if (!$sectionLabel && !empty($program['allowed_sections'])) {
                $secParts = array_filter(array_map('trim', explode(',', $program['allowed_sections'])));
                if (count($secParts) === 1) {
                    $sectionLabel = reset($secParts);
                }
            }

            $isGeneral = !$sectionLabel || in_array(strtolower($sectionLabel), ['general', 'all classes', 'general / multi-section'], true);

            if (!$isGeneral && $hasMultipleSectionsWithSameTitle) {
                $programHeading = $rawTitle . ' - ' . $sectionLabel;
            } else {
                $programHeading = $rawTitle;
            }
        ?>
            <div class="portrait-mc-page <?= $pageIdx > 0 ? 'page-break' : '' ?>">
                <div class="sheet-header">
                    <div class="single-program-title"><?= e($programHeading) ?></div>
                </div>

                <table class="emcee-table">
                    <thead>
                        <tr>
                            <th class="order-col" style="font-size: <?= $thFontSize ?>;">ORDER</th>
                            <th class="chest-col" style="font-size: <?= $thFontSize ?>;">CHEST NUMBER</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entriesForPrint)): ?>
                            <tr>
                                <td colspan="2" style="text-align: center; padding: 40px; color: #64748b; font-size: 16px;">No entries registered for this program.</td>
                            </tr>
                        <?php else: ?>
                            <?php $orderIdx = 1; ?>
                            <?php foreach ($entriesForPrint as $entry): ?>
                                <?php
                                    $chestNo = !empty($entry['chest_number']) ? $entry['chest_number'] : $entry['entry_number'];
                                    $formattedChest = is_numeric($chestNo) ? str_pad((string)$chestNo, 3, '0', STR_PAD_LEFT) : (string)$chestNo;
                                ?>
                                <tr>
                                    <td class="order-col" style="height: <?= $rowHeight ?>px; font-size: <?= $orderFontSize ?>; font-weight: 900;"><?= $orderIdx++ ?></td>
                                    <td class="chest-col" style="height: <?= $rowHeight ?>px; font-size: <?= $chestFontSize ?>; font-weight: 900; letter-spacing: 0.04em;">#<?= e($formattedChest) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php $pageIdx++; ?>
        <?php endforeach; ?>

        <script>
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    window.print();
                }, 500);
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

$pageTitle = 'MC Stage Sheets Printer';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';

$programs = [];
if ($activeEvent) {
    $activeEventId = (int)$activeEvent['id'];
    $stmt = $pdo->prepare("
        SELECT p.*, ct.name AS class_type_name, mst.name AS stage_type_name,
               (SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = p.id AND event_id = p.event_id) AS entry_count
        FROM musabaqa_programs p
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
        LEFT JOIN musabaqa_stage_types mst ON mst.id = p.stage_type_id
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
            <h1><i class="fa-solid fa-microphone" style="color:#38bdf8;"></i> Bulk MC Stage Sheets Printer</h1>
            <p>A4 Landscape side-by-side sheets (Matching sample layout strictly)</p>
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
            <form action="<?= app_url('/admin/printer/mc-sheets.php') ?>" method="GET" target="_blank" id="printForm">
                <input type="hidden" name="action" value="print">

                <div class="flex-between mb-6" style="border-bottom: 1px solid var(--border); padding-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                    <h3 style="margin: 0; color: #fff;"><i class="fa-solid fa-list-check mr-2" style="color: #38bdf8;"></i> On-Stage Event Programs Selection</h3>
                    
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <div class="btn-group" style="display: flex; gap: 6px; background: rgba(0,0,0,0.25); padding: 3px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                            <button type="button" class="btn btn-xs btn-primary filter-tab active" data-type="all">All On-Stage</button>
                            <button type="button" class="btn btn-xs btn-secondary filter-tab" data-type="individual"><i class="fa-solid fa-user mr-1"></i> Individual Only</button>
                            <button type="button" class="btn btn-xs btn-secondary filter-tab" data-type="group"><i class="fa-solid fa-users mr-1"></i> Group Only</button>
                        </div>

                        <input type="text" id="programSearch" class="form-input" placeholder="Search programs..." style="width: 170px; height: 34px; font-size: 13px;">
                        <button class="btn btn-secondary btn-sm" id="btnSelectAll" type="button">Select All</button>
                        <button class="btn btn-secondary btn-sm" id="btnDeselectAll" type="button">Deselect All</button>
                        <button type="submit" class="btn btn-primary btn-sm" style="background: #0284c7; border-color: #0284c7; font-weight: 700;">
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
    border-color: #38bdf8 !important;
    background: rgba(56, 189, 248, 0.15) !important;
    box-shadow: 0 0 10px rgba(56, 189, 248, 0.3) !important;
}
.pro-checkbox:checked {
    background: linear-gradient(135deg, #0284c7, #38bdf8) !important;
    border-color: #38bdf8 !important;
    box-shadow: 0 0 12px rgba(56, 189, 248, 0.4) !important;
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
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.4) !important;
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
                                <th>Stage Venue</th>
                                <th>Type</th>
                                <th>Entries Count</th>
                                <th style="width: 140px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="programsTableBody">
                            <?php if (empty($programs)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: var(--muted);">No programs found for this event.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($programs as $p): ?>
                                    <?php 
                                    $pId = (int)$p['id'];
                                    $pType = strtolower((string)($p['program_type'] ?? 'individual'));
                                    $stageName = strtolower(trim((string)($p['stage_type_name'] ?? '')));
                                    $location = strtolower(trim((string)($p['location'] ?? '')));
                                    $isOffStageFlag = !empty($p['is_off_stage']) || !empty($p['is_offstage']);

                                    $isOffstage = $isOffStageFlag || str_contains($stageName, 'off') || str_contains($location, 'off');
                                    ?>
                                    <tr data-title="<?= e(strtolower($p['title'])) ?>" data-class="<?= e(strtolower($p['class_type_name'] ?? '')) ?>" data-type="<?= e($pType) ?>" data-offstage="<?= $isOffstage ? '1' : '0' ?>" style="<?= $isOffstage ? 'opacity:0.5;' : '' ?>">
                                        <td style="text-align: center;">
                                            <label class="pro-checkbox-wrap">
                                                <input type="checkbox" name="program_ids[]" value="<?= $pId ?>" class="program-checkbox pro-checkbox" <?= !$isOffstage ? 'checked' : '' ?>>
                                            </label>
                                        </td>
                                        <td><strong>#<?= (int)($p['schedule_order'] ?? $pId) ?></strong></td>
                                        <td><strong><?= e($p['title']) ?></strong></td>
                                        <td><?= e(admin_class_type_display($p['class_type_name'] ?? null, (int)($p['class_type_id'] ?? 0))) ?></td>
                                        <td>
                                            <?php if ($isOffstage): ?>
                                                <span class="badge" style="background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3);">
                                                    <i class="fa-solid fa-building-circle-xmark mr-1"></i> Off-Stage
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-success" style="background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3);">
                                                    <i class="fa-solid fa-microphone-lines mr-1"></i> <?= e($p['stage_type_name'] ?: 'On-Stage') ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $pType === 'group' ? 'badge-info' : 'badge-neutral' ?>">
                                                <i class="fa-solid <?= $pType === 'group' ? 'fa-users' : 'fa-user' ?> mr-1"></i>
                                                <?= ucfirst($pType) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-neutral" style="color: <?= $p['entry_count'] > 0 ? '#38bdf8' : 'var(--muted)' ?>;">
                                                <?= (int)$p['entry_count'] ?> entries
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="<?= app_url('/admin/printer/mc-sheets.php') ?>?action=print&program_ids[]=<?= $pId ?>" target="_blank" class="btn btn-secondary btn-xs">
                                                <i class="fa-solid fa-microphone mr-1"></i> Print MC Sheet
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
    const searchInput = document.getElementById('programSearch');
    const tableBody = document.getElementById('programsTableBody');
    const headerCheckbox = document.getElementById('headerCheckbox');
    const btnSelectAll = document.getElementById('btnSelectAll');
    const btnDeselectAll = document.getElementById('btnDeselectAll');
    const filterTabs = document.querySelectorAll('.filter-tab');
    const STORAGE_KEY = 'mc_sheets_selected_programs_<?= (int)($activeEvent['id'] ?? 0) ?>';

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
            const isOffstage = r.getAttribute('data-offstage') === '1';

            const matchesSearch = title.includes(term) || cls.includes(term);
            const matchesType = (currentTypeFilter === 'all') || (type === currentTypeFilter);

            if (matchesSearch && matchesType && !isOffstage) {
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
