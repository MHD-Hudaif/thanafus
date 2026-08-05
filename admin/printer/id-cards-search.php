<?php
require_once __DIR__ . '/../../includes/id-card-helpers.php';
require_login();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/printer/id-cards-search.php');
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_chest') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $chestNumber = trim((string)($_POST['chest_number'] ?? ''));

        // Check if chest number is already assigned to someone else in this event
        if ($chestNumber !== '') {
            $checkStmt = $pdo->prepare("SELECT id FROM musabaqa_team_members WHERE event_id = ? AND chest_number = ? AND id != ? AND status = 'active' LIMIT 1");
            $checkStmt->execute([$activeEventId, $chestNumber, $memberId]);
            if ($checkStmt->fetch()) {
                admin_flash('error', 'Chest number ' . htmlspecialchars($chestNumber) . ' is already assigned to another member.');
                admin_redirect('/admin/printer/id-cards-search.php');
            }
        }

        $val = $chestNumber === '' ? null : $chestNumber;
        $updateStmt = $pdo->prepare('UPDATE musabaqa_team_members SET chest_number = ? WHERE id = ? AND event_id = ?');
        $updateStmt->execute([$val, $memberId, $activeEventId]);

        admin_flash('success', 'Chest number updated successfully.');
        admin_redirect('/admin/printer/id-cards-search.php');
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

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
        )
    ";
    $like = '%' . $search . '%';
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
    JOIN " . DB_MAIN_NAME . ".students s ON s.id = mtm.student_id
    WHERE mtm.event_id = ?
      AND mtm.status = 'active'
      {$searchQuery}
");
$stmt->execute($queryParams);
$allMatching = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalMembers = count($allMatching);
$missingChest = 0;

foreach ($allMatching as $member) {
    $chest = trim((string)($member['chest_number'] ?? ''));
    if ($chest === '') {
        $missingChest++;
    }
}

$totalPages = (int)ceil($totalMembers / $limit);
if ($totalPages > 0 && $page > $totalPages) {
    $page = $totalPages;
}
if ($page < 1) {
    $page = 1;
}
$offset = ($page - 1) * $limit;

// Fetch exactly the page's members
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
        s.name_arabic
    FROM musabaqa_team_members mtm
    JOIN musabaqa_teams t ON t.id = mtm.team_id
    JOIN musabaqa_events ev ON ev.id = mtm.event_id
    JOIN " . DB_MAIN_NAME . ".students s ON s.id = mtm.student_id
    WHERE mtm.event_id = ?
      AND mtm.status = 'active'
      {$searchQuery}
    ORDER BY NULLIF(mtm.chest_number, '') IS NULL ASC, CAST(mtm.chest_number AS UNSIGNED) ASC, t.team_name ASC, display_name ASC
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
");
$stmt->execute($queryParams);
$paginatedMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate HTML for Table Rows
$html = '';
if (empty($paginatedMembers)) {
    $html .= '<tr><td colspan="4" class="empty-state-row" style="text-align: center; padding: 30px; color: var(--muted);"><div class="empty-title">No Members Found</div></td></tr>';
} else {
    foreach ($paginatedMembers as $member) {
        $chestLabel = trim((string)($member['chest_number'] ?? '')) !== '' ? '#' . htmlspecialchars((string)$member['chest_number'], ENT_QUOTES, 'UTF-8') : '-';
        $displayName = htmlspecialchars($member['display_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $teamColor = htmlspecialchars($member['team_color'] ?: '#14b8a6', ENT_QUOTES, 'UTF-8');
        $teamName = htmlspecialchars($member['team_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $memberJson = htmlspecialchars(json_encode($member, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');

        $html .= '<tr>';
        $html .= '<td><strong>' . $chestLabel . '</strong></td>';
        $html .= '<td>' . $displayName . '</td>';
        $html .= '<td><span class="team-color-pill" style="background: ' . $teamColor . '22; color:#fff;"><span class="team-color-dot" style="width:12px;height:12px;background:' . $teamColor . ';"></span>' . $teamName . '</span></td>';
        $html .= '<td style="text-align: right;">';
        $html .= '<button class="btn btn-secondary btn-sm" type="button" data-edit-member=\'' . $memberJson . '\' title="Edit Chest Number"><i class="fa-solid fa-pen"></i> Edit</button>';
        $html .= '</td>';
        $html .= '</tr>';
    }
}

// Generate HTML for Pagination
$paginationHtml = '';
if ($totalMembers > 0) {
    $showingStart = $offset + 1;
    $showingEnd = min($offset + $limit, $totalMembers);
    
    $paginationHtml .= '<div class="flex-between pagination-bar mt-4" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">';
    $paginationHtml .= '<div class="text-muted text-sm" style="display: flex; align-items: center; gap: 8px;">';
    $paginationHtml .= 'Showing ' . $showingStart . ' to ' . $showingEnd . ' of ' . $totalMembers . ' entries';
    foreach ([10, 15, 30, 5000] as $lOpt) {
        $btnClass = $limit === $lOpt ? 'btn-primary' : 'btn-secondary';
        $label = $lOpt === 5000 ? 'All' : $lOpt;
        $paginationHtml .= '<button type="button" class="btn ' . $btnClass . ' btn-xs limit-btn" data-limit="' . $lOpt . '" style="padding: 2px 6px; font-size: 11px;">' . $label . '</button>';
    }
    $paginationHtml .= '</div>';
    $paginationHtml .= '</div>';
    $paginationHtml .= '</div>'; // End left side
    
    $paginationHtml .= '<div class="flex gap-2" style="display: flex; align-items: center; gap: 12px;">';
    // Page buttons
    $paginationHtml .= '<div class="flex gap-1" style="display: flex; gap: 4px;">';
    if ($page > 1) {
        $paginationHtml .= '<button type="button" data-page="' . ($page - 1) . '" class="btn btn-secondary btn-sm ajax-page-btn" style="padding: 4px 8px;"><i class="fa-solid fa-angle-left"></i> Previous</button>';
    }
    
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);
    for ($i = $startPage; $i <= $endPage; $i++) {
        $btnClass = $i === $page ? 'btn-primary' : 'btn-secondary';
        $paginationHtml .= '<button type="button" data-page="' . $i . '" class="btn ' . $btnClass . ' btn-sm ajax-page-btn" style="padding: 4px 8px;">' . $i . '</button>';
    }
    
    if ($page < $totalPages) {
        $paginationHtml .= '<button type="button" data-page="' . ($page + 1) . '" class="btn btn-secondary btn-sm ajax-page-btn" style="padding: 4px 8px;">Next <i class="fa-solid fa-angle-right"></i></button>';
    }
    
    $paginationHtml .= '</div>';
    $paginationHtml .= '</div>';
    $paginationHtml .= '</div>';
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'html' => $html,
        'pagination' => $paginationHtml,
        'stats' => [
            'total' => $totalMembers,
            'missing' => $missingChest,
        ]
    ]);
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
$flash = admin_take_flash();
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Print ID Cards</div>
            <div class="page-subtitle"><?= e($activeEvent['title']) ?></div>
        </div>
        <div class="flex gap-2 flex-wrap">
            <a href="<?= app_url('/admin/printer/index.php') ?>" class="btn btn-secondary btn-md"><i class="fa-solid fa-arrow-left"></i> Back to Hub</a>
            <a href="<?= app_url('/admin/printer/id-card-designer.php') ?>" class="btn btn-primary btn-md"><i class="fa-solid fa-wand-magic-sparkles"></i> Customize ID Cards Layout</a>
            <a href="<?= app_url('/admin/printer/id-cards.php') ?>" target="_blank" class="btn btn-success btn-md"><i class="fa-solid fa-print"></i> Print All ID Cards</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-error') ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="stats-grid mb-6" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-users" style="color: var(--accent);"></i></div>
            <div class="stat-value" id="stat-total"><?= number_format($totalMembers) ?></div>
            <div class="stat-label">Total Members</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-hashtag" style="color: var(--accent);"></i></div>
            <div class="stat-value" id="stat-assigned"><?= number_format($totalMembers - $missingChest) ?></div>
            <div class="stat-label">Assigned Chest #</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-circle-exclamation" style="color: #ef4444;"></i></div>
            <div class="stat-value" id="stat-missing"><?= number_format($missingChest) ?></div>
            <div class="stat-label">Missing Chest #</div>
        </div>
    </div>

    <div class="panel mb-6">
        <form method="get" class="form-grid" id="search-form" autocomplete="off" style="grid-template-columns: 1fr;">
            <div class="field">
                <label class="field-label">Search Members</label>
                <div class="search-input-wrapper" style="position: relative; display: flex; align-items: center; width: 100%;">
                    <i class="fa-solid fa-search" style="position: absolute; left: 16px; color: var(--muted); pointer-events: none;"></i>
                    <input
                        type="text"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Search chest number, name, team, section or category..."
                        style="padding-left: 45px; width: 100%; border-radius: 8px; background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(139, 92, 246, 0.2); color: #fff; transition: all 0.2s;"
                    >
                </div>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Chest #</th>
                        <th>Display Name</th>
                        <th>Team</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?= $html ?>
                </tbody>
            </table>
        </div>
        <div id="pagination-container">
            <?= $paginationHtml ?>
        </div>
    </div>
</div>

<!-- Manual Assign/Edit Chest Number Modal -->
<div class="modal-overlay" id="manualEditModal" style="display: none;">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Assign / Edit Chest Number</div>
            <button type="button" class="modal-close" data-close="manualEditModal">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="update_chest">
            <input type="hidden" name="member_id" id="modal-member-id">
            
            <div class="modal-body">
                <div class="field mb-4">
                    <label class="field-label">Student Name</label>
                    <input type="text" id="modal-display-name" disabled style="background: rgba(255,255,255,0.05); cursor: not-allowed; opacity: 0.7;">
                </div>
                <div class="field mb-4">
                    <label class="field-label">Chest Number</label>
                    <input type="text" name="chest_number" id="modal-chest-number" placeholder="Enter chest number (leave empty to clear)">
                </div>
            </div>
            <div class="modal-footer flex justify-end gap-2">
                <button type="button" class="btn btn-secondary btn-md" data-close="manualEditModal">Cancel</button>
                <button type="submit" class="btn btn-success btn-md">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const tableBody = document.getElementById('table-body');
    const paginationContainer = document.getElementById('pagination-container');
    const searchForm = document.getElementById('search-form');
    
    if (!tableBody || !paginationContainer) return;

    let currentPage = new URLSearchParams(window.location.search).get('page') || 1;
    let currentLimit = new URLSearchParams(window.location.search).get('limit') || '';
    
    function fetchResults(page, limit) {
        const url = new URL(window.location.href);
        url.searchParams.set('ajax', '1');
        if (page) url.searchParams.set('page', page);
        if (limit) url.searchParams.set('limit', limit);
        
        if (searchForm) {
            const formData = new FormData(searchForm);
            for (const [key, value] of formData.entries()) {
                url.searchParams.set(key, value);
            }
        }
        
        tableBody.style.opacity = '0.5';
        
        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    tableBody.innerHTML = data.html;
                    paginationContainer.innerHTML = data.pagination;
                    
                    // Update stats
                    if (data.stats) {
                        const totalEl = document.getElementById('stat-total');
                        const assignedEl = document.getElementById('stat-assigned');
                        const missingEl = document.getElementById('stat-missing');
                        
                        const totalVal = Number(data.stats.total) || 0;
                        const missingVal = Number(data.stats.missing) || 0;
                        
                        if (totalEl) totalEl.textContent = totalVal.toLocaleString();
                        if (assignedEl) assignedEl.textContent = (totalVal - missingVal).toLocaleString();
                        if (missingEl) missingEl.textContent = missingVal.toLocaleString();
                    }
                    
                    const newUrl = new URL(window.location.href);
                    if (page) newUrl.searchParams.set('page', page);
                    if (limit) newUrl.searchParams.set('limit', limit);
                    
                    if (searchForm) {
                        const formData = new FormData(searchForm);
                        for (const [key, value] of formData.entries()) {
                            if (value) {
                                newUrl.searchParams.set(key, value);
                            } else {
                                newUrl.searchParams.delete(key);
                            }
                        }
                    }
                    
                    window.history.pushState({}, '', newUrl);
                }
            })
            .catch(err => console.error(err))
            .finally(() => {
                tableBody.style.opacity = '1';
            });
    }

    window.adminAjaxPaginationFetch = fetchResults;

    if (searchForm) {
        searchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            currentPage = 1;
            fetchResults(currentPage, currentLimit);
        });
        
        const searchInputs = searchForm.querySelectorAll('input[type="text"], input[type="search"]');
        let timeout = null;
        searchInputs.forEach(input => {
            input.addEventListener('input', () => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    currentPage = 1;
                    fetchResults(currentPage, currentLimit);
                }, 400);
            });
        });
    }

    // Modal behavior
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-edit-member]');
        if (btn) {
            try {
                const member = JSON.parse(btn.dataset.editMember);
                document.getElementById('modal-member-id').value = member.member_id || member.id;
                document.getElementById('modal-display-name').value = member.display_name;
                document.getElementById('modal-chest-number').value = member.chest_number || '';
                
                const modal = document.getElementById('manualEditModal');
                if (modal) {
                    modal.style.display = 'flex';
                    document.body.classList.add('modal-open');
                }
            } catch (err) {
                console.error(err);
            }
        }
    });

    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modalId = btn.getAttribute('data-close');
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });
    });
})();
</script>

<?php
admin_close_page();
