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
        <link rel="stylesheet" href="<?= asset_url('css/event-id-cards.css') ?>">
        <script src="<?= asset_url('js/print-helpers.js') ?>" defer></script>
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                color: #000;
                background: #f8fafc;
                margin: 0;
                padding: 16px;
                line-height: 1.3;
            }
            .judge-full-sheet {
                border: 1px solid #cbd5e1;
                padding: 16px 20px;
                border-radius: 8px;
                background: #fff;
                width: 100%;
                margin-bottom: 16px;
            }
            .judge-half-sheet {
                border: 1px dashed #94a3b8;
                padding: 10px 12px;
                border-radius: 8px;
                background: #fff;
                box-sizing: border-box;
            }
            .page-break {
                page-break-before: always !important;
                break-before: page !important;
            }
            .print-header {
                border-bottom: 1.5px solid #000;
                padding-bottom: 5px;
                margin-bottom: 6px;
            }
            .program-title-banner {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
            }
            .program-title {
                font-size: 15px;
                font-weight: 800;
                color: #000;
            }
            .judge-badge {
                background: #0f172a;
                color: #fff;
                font-size: 11px;
                font-weight: 800;
                padding: 3px 8px;
                border-radius: 4px;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            .sheet-table {
                width: 100%;
                table-layout: fixed;
                border-collapse: collapse;
                margin-bottom: 4px;
            }
            .sheet-table th, .sheet-table td {
                border: 1.5px solid #000;
                padding: 5px 6px;
                text-align: center;
                vertical-align: middle;
                font-size: 12px;
            }
            .sheet-table th {
                background: #f1f5f9;
                font-weight: 700;
                text-transform: uppercase;
                font-size: 10.5px;
                padding: 6px 4px;
            }
            .chest-col {
                width: 75px;
                font-size: 14px;
                font-weight: 800;
            }
            @media print {
                body { padding: 0; background: #fff !important; }
                .judge-half-sheet { border-color: #cbd5e1; }
            }
        </style>
    </head>
    <body data-print-orientation="portrait">
        <div class="no-print-bar" style="background: #0f172a; padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; color: #fff; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <h3 style="margin:0; font-size: 16px; color:#fff;"><i class="fa-solid fa-file-invoice" style="color:#60a5fa;"></i> Judges Score Sheets</h3>
                <small style="color:#94a3b8;"><?= count($orderedPrograms) ?> Program(s) ready to print</small>
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <select data-print-select="orientation" style="padding: 6px 12px; border-radius: 6px; background: #1e293b; color: #fff; border: 1px solid #334155;">
                    <option value="portrait">📄 Portrait</option>
                    <option value="landscape">🖼️ Landscape</option>
                </select>
                <select data-print-select="scale" style="padding: 6px 12px; border-radius: 6px; background: #1e293b; color: #fff; border: 1px solid #334155;">
                    <option value="1">100% Fit</option>
                    <option value="0.9">90% Fit</option>
                    <option value="0.8">80% Fit</option>
                </select>
                <a href="<?= app_url('/admin/printer/score-sheets.php') ?>" class="btn btn-secondary" style="padding: 6px 14px; text-decoration: none; color: #fff; background: #334155; border-radius: 6px; font-size: 13px; font-weight: 600;">
                    <i class="fa-solid fa-arrow-left"></i> Selection
                </a>
                <button data-print-action="print" class="btn btn-success" style="padding: 6px 16px; background: #10b981; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer;">
                    <i class="fa-solid fa-print"></i> Print Sheets (Ctrl+P)
                </button>
            </div>
        </div>

        <?php
        $totalProgs = count($orderedPrograms);
        $prevProgType = null;

        foreach ($orderedPrograms as $pIdx => $program):
            $pId = (int)$program['id'];
            $pType = strtolower((string)($program['program_type'] ?? 'individual'));
            $categories = $categoriesByProgram[$pId] ?? [];
            $entriesForPrint = $entriesByProgram[$pId] ?? [];
            $entryCount = count($entriesForPrint);
            $useSeparatePages = $entryCount > 10;
            $rowHeight = $useSeparatePages ? 30 : ($entryCount <= 5 ? 36 : 26);

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

            $isLastProgram = ($pIdx === $totalProgs - 1);
            // Force page break when transitioning from individual to group programs
            $isTypeTransition = ($prevProgType !== null && $prevProgType !== $pType);
            $prevProgType = $pType;
        ?>
            <?php if ($useSeparatePages): ?>
                <?php for ($j = 1; $j <= 2; $j++): ?>
                    <div class="judge-full-sheet <?= ($pIdx > 0 || $j > 1) ? 'page-break' : '' ?>">
                        <div class="print-header">
                            <div class="program-title-banner">
                                <span class="program-title"><?= e($programHeading) ?> (<?= ucfirst($pType) ?>)</span>
                                <span class="judge-badge">JUDGE <?= $j ?> SCORE SHEET</span>
                            </div>
                        </div>

                        <table class="sheet-table">
                            <thead>
                                <tr>
                                    <th class="chest-col">Chest #</th>
                                    <?php foreach ($categories as $cat): ?>
                                        <th class="score-col">
                                            <?= e($cat['name']) ?><br>
                                            <small style="font-weight:600; font-size:9.5px;">(Max <?= number_format($cat['max_marks'], 0) ?>)</small>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($entriesForPrint)): ?>
                                    <tr>
                                        <td colspan="<?= 1 + count($categories) ?>" style="padding: 20px; color: #666;">No entries registered for this program.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($entriesForPrint as $entry): ?>
                                        <?php
                                            $chestNo = !empty($entry['chest_number']) ? $entry['chest_number'] : $entry['entry_number'];
                                            $formattedChest = is_numeric($chestNo) ? str_pad((string)$chestNo, 3, '0', STR_PAD_LEFT) : (string)$chestNo;
                                        ?>
                                        <tr>
                                            <td class="chest-col" style="font-weight: 800; font-size: 13.5px; height: <?= $rowHeight ?>px;">
                                                #<?= e($formattedChest) ?>
                                            </td>
                                            <?php foreach ($categories as $cat): ?>
                                                <td style="height: <?= $rowHeight ?>px;"></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endfor; ?>
            <?php else: ?>
                <div class="<?= ($pIdx > 0 || $isTypeTransition) ? 'page-break' : '' ?>">
                    <?php for ($j = 1; $j <= 2; $j++): ?>
                        <?php if ($j === 2): ?>
                            <div style="margin: 12px 0; border-top: 1px dashed #cbd5e1;"></div>
                        <?php endif; ?>

                        <div class="judge-half-sheet">
                            <div class="print-header">
                                <div class="program-title-banner">
                                    <span class="program-title"><?= e($programHeading) ?> (<?= ucfirst($pType) ?>)</span>
                                    <span class="judge-badge">JUDGE <?= $j ?> SCORE SHEET</span>
                                </div>
                            </div>

                            <table class="sheet-table">
                                <thead>
                                    <tr>
                                        <th class="chest-col">Chest #</th>
                                        <?php foreach ($categories as $cat): ?>
                                            <th class="score-col">
                                                <?= e($cat['name']) ?><br>
                                                <small style="font-weight:600; font-size:9.5px;">(Max <?= number_format($cat['max_marks'], 0) ?>)</small>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($entriesForPrint)): ?>
                                        <tr>
                                            <td colspan="<?= 1 + count($categories) ?>" style="padding: 20px; color: #666;">No entries registered for this program.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($entriesForPrint as $entry): ?>
                                            <?php
                                                $chestNo = !empty($entry['chest_number']) ? $entry['chest_number'] : $entry['entry_number'];
                                                $formattedChest = is_numeric($chestNo) ? str_pad((string)$chestNo, 3, '0', STR_PAD_LEFT) : (string)$chestNo;
                                            ?>
                                            <tr>
                                                <td class="chest-col" style="font-weight: 800; font-size: 13.5px; height: <?= $rowHeight ?>px;">
                                                    #<?= e($formattedChest) ?>
                                                </td>
                                                <?php foreach ($categories as $cat): ?>
                                                    <td style="height: <?= $rowHeight ?>px;"></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
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
            <div class="flex-between mb-6" style="border-bottom: 1px solid var(--border); padding-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                <h3 style="margin: 0; color: #fff;"><i class="fa-solid fa-list-check mr-2" style="color: #60a5fa;"></i> Event Programs Selection (Schedule Order)</h3>
                
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div class="btn-group" style="display: flex; gap: 6px; background: rgba(0,0,0,0.25); padding: 3px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                        <button type="button" class="btn btn-xs btn-primary filter-tab active" data-type="all">All Programs</button>
                        <button type="button" class="btn btn-xs btn-secondary filter-tab" data-type="individual"><i class="fa-solid fa-user mr-1"></i> Individual Only</button>
                        <button type="button" class="btn btn-xs btn-secondary filter-tab" data-type="group"><i class="fa-solid fa-users mr-1"></i> Group Only</button>
                    </div>

                    <input type="text" id="programSearch" class="form-input" placeholder="Search programs..." style="width: 200px; height: 34px; font-size: 13px;">
                    <button class="btn btn-secondary btn-sm" id="btnSelectAll" type="button">Select All</button>
                    <button class="btn btn-secondary btn-sm" id="btnDeselectAll" type="button">Deselect All</button>
                </div>
            </div>

            <form action="<?= app_url('/admin/printer/score-sheets.php') ?>" method="GET" target="_blank" id="printForm">
                <input type="hidden" name="action" value="print">
                
                <div style="margin-bottom: 20px; display: flex; gap: 16px; align-items: center; background: rgba(15, 23, 42, 0.6); padding: 14px 18px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.08);">
                    <strong style="color: #fff; font-size: 14px;"><i class="fa-solid fa-print mr-2" style="color: #a855f7;"></i> Select Print Target:</strong>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; color: #cbd5e1; font-weight: 600; font-size: 13px;">
                        <input type="radio" name="print_type" value="scores" checked style="accent-color: #a855f7; width: 16px; height: 16px;">
                        Judges Score Sheets
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; color: #cbd5e1; font-weight: 600; font-size: 13px; margin-left: 12px;">
                        <input type="radio" name="print_type" value="emcee" style="accent-color: #38bdf8; width: 16px; height: 16px;">
                        Emcee Stage Sheets
                    </label>
                </div>
                
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;"><input type="checkbox" id="headerCheckbox" style="width:16px; height:16px; accent-color:#3b82f6;"></th>
                                <th style="width: 80px;">Sched Order</th>
                                <th>Program Title</th>
                                <th>Category / Tier</th>
                                <th>Type</th>
                                <th>Entries Count</th>
                                <th style="width: 220px; text-align: right;">Action</th>
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
                                            <input type="checkbox" name="program_ids[]" value="<?= $pId ?>" class="program-checkbox" style="width:16px; height:16px; accent-color:#3b82f6;">
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
                                            <div class="flex gap-2" style="justify-content: flex-end;">
                                                <a href="<?= app_url('/admin/printer/score-sheets.php') ?>?action=print&print_type=emcee&program_ids[]=<?= $pId ?>" target="_blank" class="btn btn-secondary btn-xs">
                                                    <i class="fa-solid fa-microphone mr-1"></i> MC Sheet
                                                </a>
                                                <a href="<?= app_url('/admin/printer/score-sheets.php') ?>?action=print&print_type=scores&program_ids[]=<?= $pId ?>" target="_blank" class="btn btn-primary btn-xs">
                                                    <i class="fa-solid fa-print mr-1"></i> Score Sheet
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="submit" class="btn btn-primary btn-md">
                        <i class="fa-solid fa-print mr-1"></i> Batch Print Selected (Separated by Type)
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

            const matchesSearch = title.includes(term) || cls.includes(term);
            const matchesType = (currentTypeFilter === 'all') || (type === currentTypeFilter);

            if (matchesSearch && matchesType) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });
    }

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
