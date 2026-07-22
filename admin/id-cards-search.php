<?php
require_once __DIR__ . '/../includes/id-card-helpers.php';
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
    ORDER BY NULLIF(mtm.chest_number, '') IS NULL ASC, CAST(mtm.chest_number AS UNSIGNED) ASC, t.team_name ASC, display_name ASC
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
");
$stmt->execute($queryParams);
$paginatedRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$paginatedMembers = [];
foreach ($paginatedRaw as $member) {
    $member['category'] = id_card_category_label($member['class_type_name'] ?? null, (int)($member['class_type_id'] ?? 0));
    $paginatedMembers[] = $member;
}

// Generate HTML for Table Rows
$html = '';
if (empty($paginatedMembers)) {
    $html .= '<tr><td colspan="6" class="empty-state-row" style="text-align: center; padding: 30px; color: var(--muted);"><div class="empty-title">No Members Found</div></td></tr>';
} else {
    foreach ($paginatedMembers as $member) {
        $chestLabel = trim((string)($member['chest_number'] ?? '')) !== '' ? '#' . htmlspecialchars((string)$member['chest_number'], ENT_QUOTES, 'UTF-8') : '-';
        $displayName = htmlspecialchars($member['display_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $teamColor = htmlspecialchars($member['team_color'] ?: '#14b8a6', ENT_QUOTES, 'UTF-8');
        $teamName = htmlspecialchars($member['team_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $section = htmlspecialchars($member['section'] ?: '-', ENT_QUOTES, 'UTF-8');
        $category = htmlspecialchars($member['category'] ?: '-', ENT_QUOTES, 'UTF-8');
        $memberJson = htmlspecialchars(json_encode($member, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');

        $html .= '<tr>';
        $html .= '<td><strong>' . $chestLabel . '</strong></td>';
        $html .= '<td>' . $displayName . '</td>';
        $html .= '<td><span class="team-color-pill" style="background: ' . $teamColor . '22; color:#fff;"><span class="team-color-dot" style="width:12px;height:12px;background:' . $teamColor . ';"></span>' . $teamName . '</span></td>';
        $html .= '<td>' . $section . '</td>';
        $html .= '<td><span class="badge badge-info">' . $category . '</span></td>';
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
