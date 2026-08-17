<?php
$pageTitle = 'Visitor Traffic & Analytics';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

// Restrict access to administrators
if (!is_admin()) {
    admin_flash('error', 'Access restricted to administrators.');
    admin_redirect(get_user_default_category_url());
}

$activeEvent = get_active_musabaqa();
$pdo = $GLOBALS['musabaqa_pdo'];

// 1. Parse Filter Range
$range = $_GET['range'] ?? '7days';
$rangeCondition = "1=1";
$rangeParams = [];

switch ($range) {
    case 'today':
        $rangeCondition = "visit_time >= CURDATE()";
        break;
    case '30days':
        $rangeCondition = "visit_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        break;
    case 'all':
        $rangeCondition = "1=1";
        break;
    case '7days':
    default:
        $range = '7days';
        $rangeCondition = "visit_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        break;
}

// 2. Fetch KPI Summary Metrics
try {
    // Total Page Views (excluding bots)
    $stmtPv = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_visitor_logs WHERE is_bot = 0 AND {$rangeCondition}");
    $stmtPv->execute($rangeParams);
    $totalPageViews = (int)$stmtPv->fetchColumn();

    // Unique Visitors (excluding bots, based on session_id)
    $stmtUv = $pdo->prepare("SELECT COUNT(DISTINCT session_id) FROM musabaqa_visitor_logs WHERE is_bot = 0 AND {$rangeCondition}");
    $stmtUv->execute($rangeParams);
    $uniqueVisitors = (int)$stmtUv->fetchColumn();

    // Bot Visits
    $stmtBots = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_visitor_logs WHERE is_bot = 1 AND {$rangeCondition}");
    $stmtBots->execute($rangeParams);
    $botVisits = (int)$stmtBots->fetchColumn();

    // Avg Page Views per Session
    $avgViewsPerSession = $uniqueVisitors > 0 ? round($totalPageViews / $uniqueVisitors, 1) : 0;
} catch (Throwable $e) {
    $totalPageViews = 0;
    $uniqueVisitors = 0;
    $botVisits = 0;
    $avgViewsPerSession = 0;
}

// 3. Fetch Trend Data for Chart (Daily Breakdown)
$trendLabels = [];
$trendPageViews = [];
$trendUniqueUsers = [];
try {
    $stmtTrend = $pdo->prepare("
        SELECT 
            DATE(visit_time) as visit_date, 
            COUNT(*) as page_views, 
            COUNT(DISTINCT session_id) as unique_users 
        FROM musabaqa_visitor_logs 
        WHERE is_bot = 0 AND {$rangeCondition}
        GROUP BY DATE(visit_time) 
        ORDER BY visit_date ASC
    ");
    $stmtTrend->execute($rangeParams);
    $trendRows = $stmtTrend->fetchAll(PDO::FETCH_ASSOC);

    foreach ($trendRows as $row) {
        $trendLabels[] = date('d M', strtotime($row['visit_date']));
        $trendPageViews[] = (int)$row['page_views'];
        $trendUniqueUsers[] = (int)$row['unique_users'];
    }
} catch (Throwable $e) {}

// 4. Fetch Top Pages
$topPages = [];
try {
    $stmtPages = $pdo->prepare("
        SELECT page_url, COUNT(*) as view_count 
        FROM musabaqa_visitor_logs 
        WHERE is_bot = 0 AND {$rangeCondition}
        GROUP BY page_url 
        ORDER BY view_count DESC 
        LIMIT 8
    ");
    $stmtPages->execute($rangeParams);
    $topPages = $stmtPages->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Helper to format page URLs into pretty names
function formatPageName(string $url): string {
    $parsed = parse_url($url);
    $path = $parsed['path'] ?? '/';
    $query = $parsed['query'] ?? '';

    if ($path === '/' || $path === '/index.php') {
        return '🏠 Public Landing Page';
    }
    if (str_contains($path, 'home.php')) {
        return '📊 Live Stats Dashboard';
    }
    if (str_contains($path, 'scoreboard.php')) {
        return '🏆 Live Scoreboard';
    }
    if (str_contains($path, 'schedule.php')) {
        return '📅 Musabaqa Schedule';
    }
    if (str_contains($path, 'participants.php')) {
        return '👥 Participants Directory';
    }
    if (str_contains($path, 'review.php')) {
        return '⭐ Judge Review Section';
    }
    if (str_contains($path, '/admin/')) {
        return '⚙️ Admin: ' . basename($path);
    }
    return $path . ($query ? '?' . $query : '');
}

// 5. Fetch Referrers
$topReferrers = [];
try {
    $stmtRefs = $pdo->prepare("
        SELECT referrer, COUNT(*) as count 
        FROM musabaqa_visitor_logs 
        WHERE is_bot = 0 AND {$rangeCondition}
        GROUP BY referrer 
        ORDER BY count DESC 
        LIMIT 8
    ");
    $stmtRefs->execute($rangeParams);
    $topReferrers = $stmtRefs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Helper to format referrers
function formatReferrerName(string $ref): string {
    if (empty($ref)) {
        return 'Direct Traffic (Bookmarks / Links)';
    }
    $host = parse_url($ref, PHP_URL_HOST);
    if (!$host) {
        return $ref;
    }
    $host = preg_replace('/^www\./i', '', $host);
    return $host;
}

// Helper to get referrer icon
function getReferrerIcon(string $ref): string {
    if (empty($ref)) return '<i class="fa-solid fa-arrow-pointer text-emerald"></i>';
    $host = strtolower(parse_url($ref, PHP_URL_HOST) ?? '');
    if (str_contains($host, 'google')) return '<i class="fa-brands fa-google text-blue"></i>';
    if (str_contains($host, 'facebook') || str_contains($host, 'fb')) return '<i class="fa-brands fa-facebook text-indigo"></i>';
    if (str_contains($host, 'twitter') || str_contains($host, 't.co') || str_contains($host, 'x.com')) return '<i class="fa-brands fa-x-twitter text-white"></i>';
    if (str_contains($host, 'instagram')) return '<i class="fa-brands fa-instagram text-rose"></i>';
    if (str_contains($host, 'whatsapp')) return '<i class="fa-brands fa-whatsapp text-green"></i>';
    return '<i class="fa-solid fa-globe text-muted"></i>';
}

// 6. Fetch Devices, OS, Browsers Breakdown
$deviceBreakdown = ['Desktop' => 0, 'Mobile' => 0, 'Tablet' => 0, 'Bot' => 0];
try {
    $stmtDevices = $pdo->prepare("
        SELECT device_type, COUNT(*) as count 
        FROM musabaqa_visitor_logs 
        WHERE {$rangeCondition}
        GROUP BY device_type
    ");
    $stmtDevices->execute($rangeParams);
    foreach ($stmtDevices->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dev = $row['device_type'] ?: 'Desktop';
        $deviceBreakdown[$dev] = (int)$row['count'];
    }
} catch (Throwable $e) {}

$osBreakdown = [];
try {
    $stmtOS = $pdo->prepare("
        SELECT platform, COUNT(*) as count 
        FROM musabaqa_visitor_logs 
        WHERE is_bot = 0 AND {$rangeCondition}
        GROUP BY platform 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $stmtOS->execute($rangeParams);
    $osBreakdown = $stmtOS->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$browserBreakdown = [];
try {
    $stmtBrowsers = $pdo->prepare("
        SELECT browser, COUNT(*) as count 
        FROM musabaqa_visitor_logs 
        WHERE is_bot = 0 AND {$rangeCondition}
        GROUP BY browser 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $stmtBrowsers->execute($rangeParams);
    $browserBreakdown = $stmtBrowsers->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// 7. Recent Visits Log
$recentVisits = [];
try {
    $stmtRecent = $pdo->prepare("
        SELECT ip_address, browser, platform, device_type, page_url, referrer, visit_time, is_bot 
        FROM musabaqa_visitor_logs 
        ORDER BY visit_time DESC 
        LIMIT 15
    ");
    $stmtRecent->execute($rangeParams);
    $recentVisits = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* Premium Visual Design Accents */
    :root {
        --text-emerald: #10b981;
        --bg-emerald-light: rgba(16, 185, 129, 0.1);
        --text-blue: #3b82f6;
        --text-rose: #f43f5e;
        --text-indigo: #6366f1;
        --border-glass: rgba(255, 255, 255, 0.08);
    }
    
    .text-emerald { color: var(--text-emerald) !important; }
    .text-blue { color: var(--text-blue) !important; }
    .text-rose { color: var(--text-rose) !important; }
    .text-indigo { color: var(--text-indigo) !important; }
    
    .visitor-analytics-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .visitor-title-area {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .visitor-title-icon {
        width: 48px;
        height: 48px;
        background: var(--bg-emerald-light);
        border: 1px solid rgba(16, 185, 129, 0.2);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: var(--text-emerald);
    }
    
    .visitor-title-area h1 {
        margin: 0;
        font-size: 24px;
        font-weight: 700;
        color: #ffffff;
    }
    
    .visitor-title-area p {
        margin: 4px 0 0;
        color: #94a3b8;
        font-size: 14px;
    }
    
    /* Timeframe Filter Buttons */
    .filter-btn-group {
        display: flex;
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid var(--border-glass);
        padding: 4px;
        border-radius: 8px;
        gap: 4px;
    }
    
    .filter-btn {
        background: none;
        border: none;
        color: #94a3b8;
        padding: 6px 16px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none !important;
    }
    
    .filter-btn:hover {
        color: #ffffff;
        background: rgba(255, 255, 255, 0.05);
    }
    
    .filter-btn.active {
        color: #ffffff;
        background: var(--text-emerald);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    }
    
    /* Metric Cards Grid */
    .visitor-metrics-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .visitor-stat-card {
        background: rgba(15, 23, 42, 0.4);
        border: 1px solid var(--border-glass);
        backdrop-filter: blur(8px);
        border-radius: 12px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.3s ease;
    }
    
    .visitor-stat-card:hover {
        transform: translateY(-2px);
        border-color: rgba(16, 185, 129, 0.3);
    }
    
    .visitor-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    
    .visitor-stat-icon.is-green {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }
    
    .visitor-stat-icon.is-blue {
        background: rgba(59, 130, 246, 0.15);
        color: #3b82f6;
    }
    
    .visitor-stat-icon.is-purple {
        background: rgba(139, 92, 246, 0.15);
        color: #8b5cf6;
    }
    
    .visitor-stat-icon.is-rose {
        background: rgba(244, 63, 94, 0.15);
        color: #f43f5e;
    }
    
    .visitor-stat-info {
        display: flex;
        flex-direction: column;
    }
    
    .visitor-stat-value {
        font-size: 24px;
        font-weight: 800;
        color: #ffffff;
        line-height: 1.2;
    }
    
    .visitor-stat-label {
        font-size: 12px;
        color: #94a3b8;
        font-weight: 500;
        margin-top: 4px;
    }
    
    /* Layout Panels */
    .visitor-dashboard-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }
    
    .col-12 { grid-column: span 12; }
    .col-8 { grid-column: span 8; }
    .col-4 { grid-column: span 4; }
    .col-6 { grid-column: span 6; }
    
    @media (max-width: 992px) {
        .col-8, .col-4, .col-6 { grid-column: span 12; }
    }
    
    .visitor-panel {
        background: rgba(15, 23, 42, 0.4);
        border: 1px solid var(--border-glass);
        backdrop-filter: blur(8px);
        border-radius: 16px;
        padding: 20px;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    
    .visitor-panel-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        padding-bottom: 12px;
    }
    
    .visitor-panel-title {
        font-size: 16px;
        font-weight: 600;
        color: #ffffff;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .visitor-panel-title i {
        color: var(--text-emerald);
    }
    
    .visitor-panel-subtitle {
        font-size: 12px;
        color: #64748b;
    }
    
    .chart-container {
        position: relative;
        flex-grow: 1;
        min-height: 250px;
        width: 100%;
    }
    
    /* Lists & Progress Bars styling */
    .visitor-ranking-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .ranking-item {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .ranking-meta {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        font-weight: 500;
    }
    
    .ranking-label {
        color: #e2e8f0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 80%;
    }
    
    .ranking-count {
        color: #ffffff;
        font-weight: 700;
    }
    
    .ranking-bar-wrapper {
        width: 100%;
        height: 6px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 4px;
        overflow: hidden;
    }
    
    .ranking-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #10b981, #34d399);
        border-radius: 4px;
        width: 0;
        transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    /* Two Column Breakdown Row */
    .breakdown-row {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .breakdown-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 12px;
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.04);
        border-radius: 8px;
        font-size: 13px;
    }
    
    .breakdown-label {
        color: #cbd5e1;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .breakdown-value {
        color: #ffffff;
        font-weight: 700;
        background: rgba(255, 255, 255, 0.06);
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 11px;
    }
    
    /* Table Styling */
    .visitor-table-wrapper {
        overflow-x: auto;
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .visitor-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: 13px;
    }
    
    .visitor-table th {
        background: rgba(15, 23, 42, 0.8);
        color: #94a3b8;
        font-weight: 600;
        padding: 12px 16px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }
    
    .visitor-table td {
        padding: 12px 16px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        color: #e2e8f0;
        vertical-align: middle;
    }
    
    .visitor-table tr:hover td {
        background: rgba(255, 255, 255, 0.02);
    }
    
    .badge-bot {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.25);
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }
    
    .badge-visitor {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.25);
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }
    
    .device-icon-inline {
        margin-right: 6px;
        color: #94a3b8;
    }
</style>

<div class="main-content">
    
    <!-- Top Header -->
    <div class="visitor-analytics-header">
        <div class="visitor-title-area">
            <div class="visitor-title-icon">
                <i class="fa-solid fa-chart-bar"></i>
            </div>
            <div>
                <h1>Visitor Traffic & Analytics</h1>
                <p>Monitor public visits, browser configurations, devices, and visitor channels</p>
            </div>
        </div>
        
        <!-- Filter Controls -->
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <?php 
            $clarityId = env('CLARITY_PROJECT_ID');
            if ($clarityId): 
            ?>
                <a href="https://clarity.microsoft.com/projects" target="_blank" class="btn-clarity" style="display: inline-flex; align-items: center; gap: 8px; background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.25); color: #60a5fa; text-decoration: none; padding: 6px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; transition: all 0.2s ease;" onmouseover="this.style.background='rgba(59, 130, 246, 0.2)'; this.style.borderColor='rgba(59, 130, 246, 0.4)';" onmouseout="this.style.background='rgba(59, 130, 246, 0.12)'; this.style.borderColor='rgba(59, 130, 246, 0.25)';">
                    <i class="fa-solid fa-chart-simple" style="color: #60a5fa;"></i> Go to Clarity <i class="fa-solid fa-up-right-from-square" style="font-size: 10px; opacity: 0.8;"></i>
                </a>
            <?php endif; ?>
            <div class="filter-btn-group">
                <a href="?range=today" class="filter-btn <?= $range === 'today' ? 'active' : '' ?>">Today</a>
                <a href="?range=7days" class="filter-btn <?= $range === '7days' ? 'active' : '' ?>">Last 7 Days</a>
                <a href="?range=30days" class="filter-btn <?= $range === '30days' ? 'active' : '' ?>">30 Days</a>
                <a href="?range=all" class="filter-btn <?= $range === 'all' ? 'active' : '' ?>">All Time</a>
            </div>
        </div>
    </div>
    
    <!-- KPI SUMMARY CARDS -->
    <div class="visitor-metrics-row">
        <div class="visitor-stat-card">
            <div class="visitor-stat-icon is-green">
                <i class="fa-solid fa-eye"></i>
            </div>
            <div class="visitor-stat-info">
                <div class="visitor-stat-value"><?= number_format($totalPageViews) ?></div>
                <div class="visitor-stat-label">Page Views</div>
            </div>
        </div>

        <div class="visitor-stat-card">
            <div class="visitor-stat-icon is-blue">
                <i class="fa-solid fa-users"></i>
            </div>
            <div class="visitor-stat-info">
                <div class="visitor-stat-value"><?= number_format($uniqueVisitors) ?></div>
                <div class="visitor-stat-label">Unique Visitors</div>
            </div>
        </div>

        <div class="visitor-stat-card">
            <div class="visitor-stat-icon is-purple">
                <i class="fa-solid fa-repeat"></i>
            </div>
            <div class="visitor-stat-info">
                <div class="visitor-stat-value"><?= $avgViewsPerSession ?></div>
                <div class="visitor-stat-label">Pages / Visitor</div>
            </div>
        </div>

        <div class="visitor-stat-card">
            <div class="visitor-stat-icon is-rose">
                <i class="fa-solid fa-robot"></i>
            </div>
            <div class="visitor-stat-info">
                <div class="visitor-stat-value"><?= number_format($botVisits) ?></div>
                <div class="visitor-stat-label">Crawler Bots</div>
            </div>
        </div>
    </div>
    
    <!-- DASHBOARD PANELS GRID -->
    <div class="visitor-dashboard-grid">
        
        <!-- Main Trend Line Chart -->
        <div class="col-8">
            <div class="visitor-panel">
                <div class="visitor-panel-header">
                    <div class="visitor-panel-title">
                        <i class="fa-solid fa-chart-line"></i> Traffic Trend Over Time
                    </div>
                    <span class="visitor-panel-subtitle">Views vs Unique Visitors</span>
                </div>
                <div class="chart-container">
                    <canvas id="visitorTrendChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Devices Donut Breakdown -->
        <div class="col-4">
            <div class="visitor-panel">
                <div class="visitor-panel-header">
                    <div class="visitor-panel-title">
                        <i class="fa-solid fa-laptop-mobile"></i> Device Distribution
                    </div>
                    <span class="visitor-panel-subtitle">Access Platforms</span>
                </div>
                <div class="chart-container" style="min-height: 200px;">
                    <canvas id="deviceBreakdownChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Top Pages List -->
        <div class="col-6">
            <div class="visitor-panel">
                <div class="visitor-panel-header">
                    <div class="visitor-panel-title">
                        <i class="fa-solid fa-file-lines"></i> Most Active Pages
                    </div>
                    <span class="visitor-panel-subtitle">Top Page Views</span>
                </div>
                
                <div class="visitor-ranking-list">
                    <?php if (empty($topPages)): ?>
                        <div style="text-align: center; color: #64748b; padding: 32px 0;">No page views logged yet.</div>
                    <?php else: ?>
                        <?php 
                        $maxViews = max(array_column($topPages, 'view_count')) ?: 1;
                        foreach ($topPages as $pageData): 
                            $pct = round(($pageData['view_count'] / $maxViews) * 100);
                        ?>
                            <div class="ranking-item">
                                <div class="ranking-meta">
                                    <span class="ranking-label" title="<?= htmlspecialchars($pageData['page_url']) ?>">
                                        <?= htmlspecialchars(formatPageName($pageData['page_url'])) ?>
                                    </span>
                                    <span class="ranking-count"><?= number_format($pageData['view_count']) ?></span>
                                </div>
                                <div class="ranking-bar-wrapper">
                                    <div class="ranking-bar-fill" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Referrers / Sources List -->
        <div class="col-6">
            <div class="visitor-panel">
                <div class="visitor-panel-header">
                    <div class="visitor-panel-title">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Top Referrers & Channels
                    </div>
                    <span class="visitor-panel-subtitle">Traffic Sources</span>
                </div>
                
                <div class="visitor-ranking-list">
                    <?php if (empty($topReferrers)): ?>
                        <div style="text-align: center; color: #64748b; padding: 32px 0;">No traffic sources tracked yet.</div>
                    <?php else: ?>
                        <?php 
                        $maxRefs = max(array_column($topReferrers, 'count')) ?: 1;
                        foreach ($topReferrers as $refData): 
                            $pct = round(($refData['count'] / $maxRefs) * 100);
                        ?>
                            <div class="ranking-item">
                                <div class="ranking-meta">
                                    <span class="ranking-label" title="<?= htmlspecialchars($refData['referrer']) ?>">
                                        <?= getReferrerIcon($refData['referrer']) ?>
                                        <span style="margin-left: 6px;"><?= htmlspecialchars(formatReferrerName($refData['referrer'])) ?></span>
                                    </span>
                                    <span class="ranking-count"><?= number_format($refData['count']) ?></span>
                                </div>
                                <div class="ranking-bar-wrapper">
                                    <div class="ranking-bar-fill" style="width: <?= $pct ?>%; background: linear-gradient(90deg, #3b82f6, #60a5fa);"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Browser Breakdown -->
        <div class="col-6">
            <div class="visitor-panel">
                <div class="visitor-panel-header">
                    <div class="visitor-panel-title">
                        <i class="fa-solid fa-compass"></i> Popular Browsers
                    </div>
                    <span class="visitor-panel-subtitle">Visitor Client Setup</span>
                </div>
                
                <div class="breakdown-row">
                    <?php if (empty($browserBreakdown)): ?>
                        <div style="text-align: center; color: #64748b; padding: 32px 0;">No browser logs.</div>
                    <?php else: ?>
                        <?php foreach ($browserBreakdown as $bData): ?>
                            <div class="breakdown-item">
                                <div class="breakdown-label">
                                    <i class="fa-solid fa-window-maximize text-emerald"></i>
                                    <?= htmlspecialchars($bData['browser'] ?: 'Unknown') ?>
                                </div>
                                <div class="breakdown-value"><?= number_format($bData['count']) ?> hits</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- OS Breakdown -->
        <div class="col-6">
            <div class="visitor-panel">
                <div class="visitor-panel-header">
                    <div class="visitor-panel-title">
                        <i class="fa-solid fa-desktop"></i> Operating Systems
                    </div>
                    <span class="visitor-panel-subtitle">Visitor Device OS</span>
                </div>
                
                <div class="breakdown-row">
                    <?php if (empty($osBreakdown)): ?>
                        <div style="text-align: center; color: #64748b; padding: 32px 0;">No platform logs.</div>
                    <?php else: ?>
                        <?php foreach ($osBreakdown as $oData): ?>
                            <div class="breakdown-item">
                                <div class="breakdown-label">
                                    <i class="fa-solid fa-terminal text-blue"></i>
                                    <?= htmlspecialchars($oData['platform'] ?: 'Unknown') ?>
                                </div>
                                <div class="breakdown-value"><?= number_format($oData['count']) ?> hits</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Live Visitor Session Logs -->
        <div class="col-12">
            <div class="visitor-panel">
                <div class="visitor-panel-header">
                    <div class="visitor-panel-title">
                        <i class="fa-solid fa-clock-rotate-left"></i> Live Session Stream
                    </div>
                    <span class="visitor-panel-subtitle">Last 15 visits, updated in real time</span>
                </div>
                
                <div class="visitor-table-wrapper">
                    <table class="visitor-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Visitor / IP</th>
                                <th>Device</th>
                                <th>Browser / OS</th>
                                <th>Page Route</th>
                                <th>Referrer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentVisits)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #64748b; padding: 32px 0;">No recent activity logged.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentVisits as $visit): 
                                    $visitTimeFormatted = date('d M h:i:s A', strtotime($visit['visit_time']));
                                    
                                    // Device Icon
                                    $devIcon = '<i class="fa-solid fa-desktop device-icon-inline"></i>';
                                    if ($visit['device_type'] === 'Mobile') $devIcon = '<i class="fa-solid fa-mobile-screen-button device-icon-inline text-emerald"></i>';
                                    if ($visit['device_type'] === 'Tablet') $devIcon = '<i class="fa-solid fa-tablet-screen-button device-icon-inline text-blue"></i>';
                                    if ($visit['device_type'] === 'Bot') $devIcon = '<i class="fa-solid fa-robot device-icon-inline text-rose"></i>';
                                ?>
                                    <tr>
                                        <td style="white-space: nowrap; font-size: 12.5px;"><?= $visitTimeFormatted ?></td>
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                                <span style="font-weight: 700; color: #ffffff;"><?= htmlspecialchars($visit['ip_address']) ?></span>
                                                <span>
                                                    <?= $visit['is_bot'] 
                                                        ? '<span class="badge-bot">Bot</span>' 
                                                        : '<span class="badge-visitor">Visitor</span>' 
                                                    ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td style="white-space: nowrap; font-weight: 500;"><?= $devIcon ?> <?= htmlspecialchars($visit['device_type']) ?></td>
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: 2px; font-size: 12.5px;">
                                                <span style="color: #ffffff; font-weight: 500;"><i class="fa-regular fa-window-restore" style="font-size:11px; margin-right:4px;"></i> <?= htmlspecialchars($visit['browser'] ?: 'Unknown') ?></span>
                                                <span class="text-muted"><i class="fa-solid fa-gears" style="font-size:10px; margin-right:4px;"></i> <?= htmlspecialchars($visit['platform'] ?: 'Unknown') ?></span>
                                            </div>
                                        </td>
                                        <td style="font-weight: 600; color: #cbd5e1; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($visit['page_url']) ?>">
                                            <?= htmlspecialchars(formatPageName($visit['page_url'])) ?>
                                        </td>
                                        <td style="max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($visit['referrer']) ?>">
                                            <span class="text-muted"><?= htmlspecialchars(formatReferrerName($visit['referrer'])) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    </div>

</div>

<!-- CHARTS GENERATION SCRIPT -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Render Traffic Trend Line Chart
    const trendCtx = document.getElementById('visitorTrendChart')?.getContext('2d');
    if (trendCtx) {
        const labels = <?= json_encode($trendLabels) ?>;
        const pageViews = <?= json_encode($trendPageViews) ?>;
        const uniqueUsers = <?= json_encode($trendUniqueUsers) ?>;

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Page Views',
                        data: pageViews,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.08)',
                        borderWidth: 3,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#ffffff',
                        pointHoverRadius: 6,
                        tension: 0.35,
                        fill: true
                    },
                    {
                        label: 'Unique Visitors',
                        data: uniqueUsers,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.04)',
                        borderWidth: 2,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#ffffff',
                        pointHoverRadius: 6,
                        tension: 0.35,
                        fill: true,
                        borderDash: [5, 5]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: '#94a3b8',
                            font: { family: 'Inter', size: 12, weight: '500' },
                            padding: 16
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        borderColor: 'rgba(255, 255, 255, 0.1)',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#64748b', font: { family: 'Inter', size: 11 } },
                        grid: { display: false }
                    },
                    y: {
                        ticks: { 
                            color: '#64748b', 
                            font: { family: 'Inter', size: 11 },
                            precision: 0
                        },
                        grid: { color: 'rgba(255, 255, 255, 0.04)' }
                    }
                }
            }
        });
    }

    // 2. Render Device Breakdown Donut Chart
    const deviceCtx = document.getElementById('deviceBreakdownChart')?.getContext('2d');
    if (deviceCtx) {
        const deviceData = <?= json_encode($deviceBreakdown) ?>;
        
        new Chart(deviceCtx, {
            type: 'doughnut',
            data: {
                labels: ['Desktop', 'Mobile', 'Tablet', 'Bot'],
                datasets: [{
                    data: [
                        deviceData.Desktop || 0,
                        deviceData.Mobile || 0,
                        deviceData.Tablet || 0,
                        deviceData.Bot || 0
                    ],
                    backgroundColor: ['#4f46e5', '#10b981', '#3b82f6', '#f43f5e'],
                    borderWidth: 2,
                    borderColor: '#0f172a'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#94a3b8',
                            font: { family: 'Inter', size: 11 },
                            padding: 12,
                            boxWidth: 12
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        borderColor: 'rgba(255, 255, 255, 0.1)',
                        borderWidth: 1,
                        padding: 8
                    }
                },
                cutout: '72%'
            }
        });
    }
});
</script>

<?php admin_close_page(); ?>
