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

    // Function to pair programs that have the SAME participant/entry count together
    $pairByMatchingCount = function($programList) use ($entriesByProgram) {
        usort($programList, function($a, $b) use ($entriesByProgram) {
            $cntA = count($entriesByProgram[(int)$a['id']] ?? []);
            $cntB = count($entriesByProgram[(int)$b['id']] ?? []);
            if ($cntA === $cntB) {
                return (int)($a['id']) <=> (int)($b['id']);
            }
            return $cntA <=> $cntB;
        });

        return array_chunk($programList, 2);
    };

    $individualPairs = $pairByMatchingCount($individualPrograms);
    $groupPairs = $pairByMatchingCount($groupPrograms);
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
                padding: 16px;
                line-height: 1.2;
            }
            .landscape-page {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                position: relative;
                width: 100%;
                min-height: 90vh;
                box-sizing: border-box;
            }
            @media (max-width: 800px) {
                .landscape-page {
                    grid-template-columns: 1fr;
                }
                .vertical-divider {
                    display: none;
                }
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
            .page-break {
                page-break-before: always !important;
                break-before: page !important;
            }
            @media print {
                    print-color-adjust: exact;
                }
            }
        </style>
    </head>
    <body>
        <button onclick="window.print()" class="no-print-btn">
            <i class="fa-solid fa-print"></i> Print Emcee Side-by-Side Sheets
        </button>

        <?php
        function render_landscape_program_pair($pair, $entriesByProgram, $titleCounts, $isFirstPage = false) {
            ?>
            <div class="landscape-page <?= !$isFirstPage ? 'page-break' : '' ?>">
                <div class="vertical-divider"></div>

                <?php for ($idx = 0; $idx < 2; $idx++): ?>
                    <?php if (isset($pair[$idx])): ?>
                        <?php
                            $program = $pair[$idx];
                            $pId = (int)$program['id'];
                            $entriesForPrint = $entriesByProgram[$pId] ?? [];
                            $entryCount = count($entriesForPrint);
                            $rowHeight = $entryCount <= 5 ? 48 : ($entryCount <= 10 ? 36 : 24);

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
                        <div class="program-half">
                            <div class="sheet-header">
                                <div class="single-program-title"><?= e($programHeading) ?></div>
                            </div>

                            <table class="emcee-table">
                                <thead>
                                    <tr>
                                        <th class="order-col">ORDER</th>
                                        <th class="chest-col">CHEST NUMBER</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($entriesForPrint)): ?>
                                        <tr>
                                            <td colspan="2" style="text-align: center; padding: 25px; color: #64748b;">No entries registered for this program.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php $orderIdx = 1; ?>
                                        <?php foreach ($entriesForPrint as $entry): ?>
                                            <?php
                                                $chestNo = !empty($entry['chest_number']) ? $entry['chest_number'] : $entry['entry_number'];
                                                $formattedChest = is_numeric($chestNo) ? str_pad((string)$chestNo, 3, '0', STR_PAD_LEFT) : (string)$chestNo;
                                            ?>
                                            <tr>
                                                <td class="order-col" style="height: <?= $rowHeight ?>px;"><?= $orderIdx++ ?></td>
                                                <td class="chest-col" style="height: <?= $rowHeight ?>px;">#<?= e($formattedChest) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <!-- Blank placeholder if odd count -->
                        <div class="program-half"></div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php
        }

        $pageCount = 0;

        // 1. Print Individual Programs Pairs Side-by-Side First (Paired by matching entry count)
        foreach ($individualPairs as $pair) {
            render_landscape_program_pair($pair, $entriesByProgram, $titleCounts, ($pageCount === 0));
            $pageCount++;
        }

        // 2. Print Group Programs Pairs Side-by-Side Separately (Paired by matching entry count)
        foreach ($groupPairs as $pair) {
            render_landscape_program_pair($pair, $entriesByProgram, $titleCounts, ($pageCount === 0));
            $pageCount++;
        }
        ?>

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
            <div class="flex-between mb-6" style="border-bottom: 1px solid var(--border); padding-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                <h3 style="margin: 0; color: #fff;"><i class="fa-solid fa-list-check mr-2" style="color: #38bdf8;"></i> On-Stage Event Programs Selection</h3>
                
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div class="btn-group" style="display: flex; gap: 6px; background: rgba(0,0,0,0.25); padding: 3px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                        <button type="button" class="btn btn-xs btn-primary filter-tab active" data-type="all">All On-Stage</button>
                        <button type="button" class="btn btn-xs btn-secondary filter-tab" data-type="individual"><i class="fa-solid fa-user mr-1"></i> Individual Only</button>
                        <button type="button" class="btn btn-xs btn-secondary filter-tab" data-type="group"><i class="fa-solid fa-users mr-1"></i> Group Only</button>
                    </div>

                    <input type="text" id="programSearch" class="form-input" placeholder="Search programs..." style="width: 200px; height: 34px; font-size: 13px;">
                    <button class="btn btn-secondary btn-sm" id="btnSelectAll" type="button">Select All</button>
                    <button class="btn btn-secondary btn-sm" id="btnDeselectAll" type="button">Deselect All</button>
                </div>
            </div>

            <form action="<?= app_url('/admin/printer/mc-sheets.php') ?>" method="GET" target="_blank" id="printForm">
                <input type="hidden" name="action" value="print">
                
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;"><input type="checkbox" id="headerCheckbox" style="width:16px; height:16px; accent-color:#38bdf8;"></th>
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
                                            <input type="checkbox" name="program_ids[]" value="<?= $pId ?>" class="program-checkbox" <?= !$isOffstage ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:#38bdf8;">
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

                <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="submit" class="btn btn-primary btn-md" style="background: #0284c7; border-color: #0284c7;">
                        <i class="fa-solid fa-microphone mr-1"></i> Batch Print Side-by-Side
                    </button>
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

    let currentTypeFilter = 'all';

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
    }

    applyTableFilters();

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

    if (headerCheckbox) {
        headerCheckbox.addEventListener('change', () => {
            const checkboxes = tableBody.querySelectorAll('.program-checkbox');
            checkboxes.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = headerCheckbox.checked;
                }
            });
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
        });
    }

    if (btnDeselectAll) {
        btnDeselectAll.addEventListener('click', () => {
            const checkboxes = tableBody.querySelectorAll('.program-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            if (headerCheckbox) headerCheckbox.checked = false;
        });
    }
});
</script>

<?php admin_close_page(); ?>
