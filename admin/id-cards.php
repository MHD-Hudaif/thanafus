<?php
$pageTitle = 'ID Card Export';

require_once __DIR__ . '/../includes/id-card-helpers.php';
require_login();
$flash = admin_take_flash();

if (isset($_GET['limit'])) {
    $limit = max(5, min(5000, (int)$_GET['limit']));
    $_SESSION['id_cards_limit'] = $limit;
} else {
    $limit = isset($_SESSION['id_cards_limit']) ? $_SESSION['id_cards_limit'] : 10;
}

session_write_close();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$search = trim((string)($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/id-cards.php');
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        $members = id_card_members($pdo, $activeEventId);
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

// Build search SQL segment
$searchQuery = '';
$queryParams = [$activeEventId];

if ($search !== '') {
    $searchQuery = "
        AND (
            mtm.chest_number LIKE ?
            OR s.display_name LIKE ?
            OR s.full_name LIKE ?
            OR t.team_name LIKE ?
            OR c.name LIKE ?
            OR ct.name LIKE ?
        )
    ";
    $like = '%' . $search . '%';
    $queryParams[] = $like;
    $queryParams[] = $like;
    $queryParams[] = $like;
    $queryParams[] = $like;
    $queryParams[] = $like;
    $queryParams[] = $like;
}

// Get stats & count globally matching the filter
$stmt = $pdo->prepare("
    SELECT
        mtm.chest_number
    FROM musabaqa_team_members mtm
    JOIN musabaqa_teams t ON t.id = mtm.team_id
    JOIN musabaqa_events ev ON ev.id = mtm.event_id
    JOIN kauzariyya.students s ON s.id = mtm.student_id
    LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
    LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
    WHERE mtm.event_id = ?
      AND mtm.status = 'active'
      {$searchQuery}
");
$stmt->execute($queryParams);
$allMatching = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalMembers = count($allMatching);
$missingChest = 0;
$existingQr = 0;

$existingQrFiles = [];
$dir = public_path('assets/qr/id-cards/event-' . $activeEventId);
if (is_dir($dir)) {
    $files = scandir($dir);
    if ($files !== false) {
        foreach ($files as $file) {
            if (str_ends_with($file, '.png')) {
                $chestNum = substr($file, 0, -4);
                $existingQrFiles[$chestNum] = true;
            }
        }
    }
}

foreach ($allMatching as $member) {
    $chest = trim((string)($member['chest_number'] ?? ''));
    if ($chest === '') {
        $missingChest++;
        continue;
    }
    if (isset($existingQrFiles[$chest])) {
        $existingQr++;
    }
}

$limit = isset($_GET['limit']) ? max(5, min(5000, (int)$_GET['limit'])) : 10;
$totalPages = (int)ceil($totalMembers / $limit);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($totalPages > 0 && $page > $totalPages) {
    $page = $totalPages;
}
if ($page < 1) {
    $page = 1;
}
$offset = ($page - 1) * $limit;

// Fetch exactly 10 members for the current page
$stmt = $pdo->prepare("
    SELECT
        mtm.id AS member_id,
        mtm.student_id,
        mtm.chest_number,
        mtm.status,
        t.team_name,
        t.team_color,
        ev.title AS event_title,
        COALESCE(NULLIF(s.display_name, ''), s.full_name) AS display_name,
        s.full_name,
        s.name_arabic,
        s.place,
        s.admission_no,
        c.class_type_id,
        c.name AS section,
        ct.name AS class_type_name
    FROM musabaqa_team_members mtm
    JOIN musabaqa_teams t ON t.id = mtm.team_id
    JOIN musabaqa_events ev ON ev.id = mtm.event_id
    JOIN kauzariyya.students s ON s.id = mtm.student_id
    LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
    LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
    WHERE mtm.event_id = ?
      AND mtm.status = 'active'
      {$searchQuery}
    ORDER BY mtm.chest_number IS NULL ASC, CAST(mtm.chest_number AS UNSIGNED) ASC, t.team_name ASC, display_name ASC
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
");
$stmt->execute($queryParams);
$paginatedRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$paginatedMembers = [];
foreach ($paginatedRaw as $member) {
    $member['category'] = id_card_category_label($member['class_type_name'] ?? null, (int)($member['class_type_id'] ?? 0));
    $member['qr_url'] = id_card_absolute_url('/member-details.php?id=' . (int)$member['member_id']);
    $member['qr_paths'] = id_card_qr_paths($activeEventId, $member['chest_number'] ?? null);
    $paginatedMembers[] = $member;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.limit-options-popover {
    display: flex;
    align-items: center;
    position: absolute;
    left: 100%;
    top: 50%;
    transform: translateY(-50%) scaleX(0);
    transform-origin: left center;
    margin-left: 8px;
    background: #1e293b;
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 6px;
    padding: 4px;
    gap: 4px;
    z-index: 100;
    box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    opacity: 0;
    pointer-events: none;
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.15s linear;
}
.limit-options-popover.show {
    transform: translateY(-50%) scaleX(1);
    opacity: 1;
    pointer-events: auto;
}
</style>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">ID Card Export</div>
            <div class="page-subtitle"><?= e($activeEvent['title']) ?></div>
        </div>
        <a href="<?= app_url('/admin/chest-numbers.php') ?>" class="btn btn-secondary btn-md"><i class="fa-solid fa-hashtag"></i> Chest Numbers</a>
    </div>


    <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-error') ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="stats-grid mb-6">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-users"></i></div><div class="stat-value" id="stat-total-members"><?= $totalMembers ?></div><div class="stat-label">Active Members</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-hashtag"></i></div><div class="stat-value" id="stat-missing-chest"><?= $missingChest ?></div><div class="stat-label">Missing Chest #</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-qrcode"></i></div><div class="stat-value" id="stat-existing-qr"><?= $existingQr ?></div><div class="stat-label">QR Files Ready</div></div>
    </div>

    <div class="panel mb-6">
        <div class="flex-between">
            <div>
                <div class="dashboard-heading">Photoshop Mail Merge CSV</div>
                <div class="page-subtitle">CSV columns: chest number, display name, team, team color, category, and QR image path.</div>
            </div>
            <form method="POST" class="flex gap-2 flex-wrap" data-ajax-ignore>
                <?= admin_csrf_field() ?>
                <button class="btn btn-secondary btn-md" name="action" value="generate_qr" type="submit"><i class="fa-solid fa-qrcode"></i> Generate QR Files</button>
                <button class="btn btn-success btn-md" name="action" value="download_csv" type="submit"><i class="fa-solid fa-file-csv"></i> Download CSV</button>
            </form>
        </div>
    </div>

    <div class="panel mb-6">
        <form id="search-form" class="form-grid" data-ajax-ignore>
            <div class="input-group full-width">
                <label>Search</label>
                <input type="text" id="search-input" name="search" value="<?= e($search) ?>" placeholder="Chest number, display name, team, section or category">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <button class="btn btn-secondary btn-md" type="button" id="clear-search-btn" style="display: <?= $search !== '' ? 'inline-block' : 'none' ?>;">Clear</button>
            </div>
        </form>
    </div>

    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr><th>Chest #</th><th>Display Name</th><th>Team</th><th>Section</th><th>Category</th><th>QR</th></tr>
            </thead>
            <tbody id="id-cards-table-body">
                <?php if (!$paginatedMembers): ?>
                    <tr><td colspan="6" class="empty-state-row" style="text-align: center; padding: 30px; color: var(--muted);"><div class="empty-title">No Members Found</div></td></tr>
                <?php else: ?>
                    <?php foreach ($paginatedMembers as $member): ?>
                        <?php $chest = trim((string)($member['chest_number'] ?? '')); $hasQr = $chest !== '' && isset($existingQrFiles[$chest]); ?>
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
                                <a class="btn btn-secondary btn-sm" href="<?= app_url('/member-details.php') ?>?id=<?= (int)$member['member_id'] ?>" target="_blank"><i class="fa-solid fa-address-card"></i> Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="id-cards-pagination-container">
        <?php if ($totalMembers > 0): ?>
            <div class="flex-between pagination-bar mt-4" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div class="text-muted text-sm" style="display: flex; align-items: center; gap: 8px;">
                    Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalMembers) ?> of <?= $totalMembers ?> entries
                    <span style="margin-left: 8px; color: var(--muted); font-size: 13px;">Limit:</span>
                    
                    <div class="limit-popover-container" style="position: relative; display: inline-block;">
                        <button type="button" id="active-limit-trigger" class="btn btn-secondary btn-sm" style="padding: 2px 6px; font-size: 12px; height: 24px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,0.15); background: rgba(255,255,255,0.05); color: #fff; min-width: 28px;">
                            <span><?= $limit === 5000 ? 'All' : $limit ?></span>
                        </button>
                        <div id="limit-options-popover" class="limit-options-popover">
                            <?php foreach ([10, 15, 30, 5000] as $lOpt): ?>
                               <button type="button" class="btn <?= $limit === $lOpt ? 'btn-primary' : 'btn-secondary' ?> btn-xs limit-btn" data-limit="<?= $lOpt ?>" style="padding: 2px 6px; font-size: 11px;"><?= $lOpt === 5000 ? 'All' : $lOpt ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="flex gap-2" style="display: flex; align-items: center; gap: 12px;">
                    <div class="flex gap-1" style="display: flex; gap: 4px;">
                        <?php if ($page > 1): ?>
                            <button type="button" data-page="<?= $page - 1 ?>" class="btn btn-secondary btn-sm ajax-page-btn" style="padding: 4px 8px;"><i class="fa-solid fa-angle-left"></i> Previous</button>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <button type="button" data-page="<?= $i ?>" class="btn <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?> btn-sm ajax-page-btn" style="padding: 4px 8px;"><?= $i ?></button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button type="button" data-page="<?= $page + 1 ?>" class="btn btn-secondary btn-sm ajax-page-btn" style="padding: 4px 8px;">Next <i class="fa-solid fa-angle-right"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('search-input');
    const paginationContainer = document.getElementById('id-cards-pagination-container');
    const tableBody = document.getElementById('id-cards-table-body');
    const clearSearchBtn = document.getElementById('clear-search-btn');

    let searchTimeout;
    let currentPage = 1;
    let currentLimit = <?= (int)$limit ?>;

    async function runSearch(page = 1) {
        currentPage = page;
        const query = searchInput.value.trim();
        
        if (query !== '') {
            clearSearchBtn.style.display = 'inline-block';
        } else {
            clearSearchBtn.style.display = 'none';
        }

        try {
            // Fade out current body slightly to indicate loading state
            if (window.gsap) {
                gsap.to(tableBody, { opacity: 0.3, duration: 0.15 });
            }

            const url = '<?= app_url("/admin/id-cards-search.php") ?>' + `?search=${encodeURIComponent(query)}&page=${page}&limit=${currentLimit}`;
            const res = await fetch(url);
            const data = await res.json();
            
            if (data.success) {
                if (window.gsap) {
                    gsap.killTweensOf(tableBody);
                    tableBody.style.opacity = '0';
                }

                tableBody.innerHTML = data.html;
                paginationContainer.innerHTML = data.pagination;
                
                // Update stats grid
                document.getElementById('stat-total-members').textContent = data.stats.total;
                document.getElementById('stat-missing-chest').textContent = data.stats.missing;
                document.getElementById('stat-existing-qr').textContent = data.stats.existing;

                // Stagger transition the new rows in
                if (window.gsap) {
                    gsap.to(tableBody, { opacity: 1, duration: 0.2 });
                    const rows = tableBody.querySelectorAll('tr');
                    if (rows.length > 0) {
                        gsap.fromTo(rows, 
                             { opacity: 0, y: 12 },
                             { opacity: 1, y: 0, duration: 0.35, stagger: 0.02, ease: 'power2.out' }
                        );
                    }
                } else {
                    tableBody.style.opacity = '1';
                }
            }
        } catch (e) {
            console.error('AJAX search failed:', e);
            if (window.gsap) {
                gsap.to(tableBody, { opacity: 1, duration: 0.2 });
            }
        }
    }

    // Debounced search on input typing
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                runSearch(1);
            }, 300);
        });
    }

    const searchForm = document.getElementById('search-form');
    if (searchForm) {
        searchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            runSearch(1);
        });
    }

    // Clear search button handler
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            runSearch(1);
        });
    }

    // Global click listener to close limit options popover when clicking outside
    document.addEventListener('click', (e) => {
        const popover = document.getElementById('limit-options-popover');
        const trigger = document.getElementById('active-limit-trigger');
        if (popover && trigger) {
            if (!popover.contains(e.target) && !trigger.contains(e.target)) {
                popover.classList.remove('show');
            }
        }
    });

    // Event delegation for pagination links, preset limits, and trigger button
    if (paginationContainer) {
        paginationContainer.addEventListener('click', (e) => {
            const pageBtn = e.target.closest('.ajax-page-btn');
            if (pageBtn) {
                e.preventDefault();
                const page = parseInt(pageBtn.dataset.page, 10);
                if (page > 0) {
                    runSearch(page);
                }
                return;
            }

            const limitBtn = e.target.closest('.limit-btn');
            if (limitBtn) {
                e.preventDefault();
                currentLimit = parseInt(limitBtn.dataset.limit, 10);
                runSearch(1);
                return;
            }

            const trigger = e.target.closest('#active-limit-trigger');
            if (trigger) {
                e.preventDefault();
                const popover = document.getElementById('limit-options-popover');
                if (popover) {
                    popover.classList.toggle('show');
                }
                return;
            }
        });
    }
});
</script>

<?php admin_close_page(); ?>
