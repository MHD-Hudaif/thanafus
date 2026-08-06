<?php
$pageTitle = 'Real-Time Analytics & Performance Insights';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

$activeEvent = get_active_musabaqa();
$pdo = $GLOBALS['musabaqa_pdo'];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
.analytics-dashboard-grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 22px;
    margin-top: 24px;
}
.col-12 { grid-column: span 12; }
.col-8 { grid-column: span 8; }
.col-6 { grid-column: span 6; }
.col-4 { grid-column: span 4; }

@media (max-width: 1024px) {
    .col-8, .col-6, .col-4 { grid-column: span 12; }
}

.chart-card-panel {
    background: var(--surface-card-strong);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 22px;
    padding: 24px;
    box-shadow: 0 18px 44px rgba(0, 0, 0, 0.34);
    display: flex;
    flex-direction: column;
    height: 100%;
}

.chart-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.chart-card-title {
    font-size: 16px;
    font-weight: 700;
    color: #ffffff;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-card-title i {
    color: var(--accent-light, #34d399);
}

.chart-container-box {
    position: relative;
    width: 100%;
    flex: 1;
    min-height: 280px;
}

.metric-cards-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 18px;
    margin-top: 20px;
}

.analytics-stat-card {
    background: linear-gradient(135deg, rgba(19, 23, 30, 0.82), rgba(9, 12, 18, 0.72));
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 16px 34px rgba(0, 0, 0, 0.26);
    transition: transform 0.2s ease, border-color 0.2s ease;
}

.analytics-stat-card:hover {
    transform: translateY(-2px);
    border-color: rgba(var(--accent-light-rgb), 0.26);
}

.analytics-stat-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}

.analytics-stat-info {
    display: flex;
    flex-direction: column;
}

.analytics-stat-value {
    font-size: 24px;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.1;
}

.analytics-stat-label {
    font-size: 12.5px;
    color: var(--muted, #94a3b8);
    font-weight: 500;
    margin-top: 4px;
}

.live-pulse-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 20px;
    background: rgba(var(--accent-rgb), 0.14);
    color: #dfffe4;
    border: 1px solid rgba(var(--accent-light-rgb), 0.24);
}

.live-pulse-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--accent-light);
    box-shadow: 0 0 10px rgba(var(--accent-light-rgb), 0.9);
    animation: pulseGlow 1.8s infinite;
}

.analytics-header-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 6px;
}

.analytics-header-icon,
.analytics-kpi-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
}

.analytics-header-icon {
    width: 42px;
    height: 42px;
    background: rgba(var(--accent-rgb), 0.14);
    color: var(--accent-light);
    border: 1px solid rgba(var(--accent-light-rgb), 0.2);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
}

.analytics-kpi-icon {
    width: 52px;
    height: 52px;
}

.analytics-kpi-icon.is-accent {
    background: rgba(var(--accent-rgb), 0.16);
    color: var(--accent-light);
}

.analytics-kpi-icon.is-violet {
    background: rgba(var(--violet-rgb), 0.16);
    color: var(--violet);
}

.analytics-kpi-icon.is-warning {
    background: rgba(var(--warning-rgb), 0.16);
    color: var(--warning);
}

.analytics-kpi-icon.is-rose {
    background: rgba(var(--rose-rgb), 0.15);
    color: var(--rose);
}

.analytics-meta-text {
    font-size: 12px;
    color: var(--muted);
}

@keyframes pulseGlow {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.3); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}

.analytics-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.analytics-table th, 
.analytics-table td {
    padding: 12px 14px;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 13.5px;
}

.analytics-table th {
    color: var(--muted, #94a3b8);
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.analytics-table tr:last-child td {
    border-bottom: none;
}
</style>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <div class="analytics-header-row">
                <span class="analytics-header-icon"><i class="fa-solid fa-chart-line"></i></span>
                <h1>Analytics & Insights</h1>
                <span class="live-pulse-indicator">
                    <span class="live-pulse-dot"></span> Live Updates
                </span>
            </div>
            <p>Real-time team standings, score distributions, and program statistics</p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <span id="lastUpdatedBadge" class="analytics-meta-text" style="margin-right: 6px;">Updated just now</span>
            <button type="button" id="refreshAnalyticsBtn" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-rotate-right"></i> Refresh
            </button>
            <a href="<?= app_url('/admin/event-manager/index.php') ?>" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-arrow-left"></i> Back to Hub
            </a>
        </div>
    </div>

    <?php if (!$activeEvent): ?>
        <?php render_no_active_event_guard(); ?>
    <?php else: ?>
        <!-- KPI METRIC CARDS -->
        <div class="metric-cards-row">
            <div class="analytics-stat-card">
                <div class="analytics-stat-icon analytics-kpi-icon is-accent">
                    <i class="fa-solid fa-trophy"></i>
                </div>
                <div class="analytics-stat-info">
                    <div class="analytics-stat-value" id="kpiTotalPoints">0</div>
                    <div class="analytics-stat-label">Total Points Awarded</div>
                </div>
            </div>

            <div class="analytics-stat-card">
                <div class="analytics-stat-icon analytics-kpi-icon is-violet">
                    <i class="fa-solid fa-crown"></i>
                </div>
                <div class="analytics-stat-info">
                    <div class="analytics-stat-value" id="kpiTopTeam" style="font-size: 18px; white-space: nowrap;">-</div>
                    <div class="analytics-stat-label">Top Performing Team</div>
                </div>
            </div>

            <div class="analytics-stat-card">
                <div class="analytics-stat-icon analytics-kpi-icon is-warning">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div class="analytics-stat-info">
                    <div class="analytics-stat-value" id="kpiCompletionRate">0%</div>
                    <div class="analytics-stat-label">Program Completion</div>
                </div>
            </div>

            <div class="analytics-stat-card">
                <div class="analytics-stat-icon analytics-kpi-icon is-rose">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div class="analytics-stat-info">
                    <div class="analytics-stat-value" id="kpiTotalPrograms">0</div>
                    <div class="analytics-stat-label">Total Programs</div>
                </div>
            </div>
        </div>

        <!-- MAIN CHARTS ROW -->
        <div class="analytics-dashboard-grid">
            <!-- Team Standings Bar Chart -->
            <div class="col-8">
                <div class="chart-card-panel">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-chart-column"></i> Team Standings & Total Points
                        </div>
                        <span class="analytics-meta-text">Points by Team</span>
                    </div>
                    <div class="chart-container-box">
                        <canvas id="teamStandingsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Program Status Donut Chart -->
            <div class="col-4">
                <div class="chart-card-panel">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-chart-pie"></i> Program Status
                        </div>
                        <span class="analytics-meta-text">Distribution</span>
                    </div>
                    <div class="chart-container-box">
                        <canvas id="programStatusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Performing High Scorers Table -->
            <div class="col-7">
                <div class="chart-card-panel">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-star"></i> Top Scoring Entries
                        </div>
                        <span class="analytics-meta-text">Highest Individual Scores</span>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Chest #</th>
                                    <th>Participant / Entry</th>
                                    <th>Program</th>
                                    <th>Team</th>
                                    <th style="text-align: right;">Score</th>
                                </tr>
                            </thead>
                            <tbody id="topEntriesTableBody">
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--muted); padding: 24px;">Loading top entries...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Category Performance Radar Chart -->
            <div class="col-5">
                <div class="chart-card-panel">
                    <div class="chart-card-header">
                        <div class="chart-card-title">
                            <i class="fa-solid fa-shapes"></i> Category Score Stats
                        </div>
                        <span class="analytics-meta-text">Average by Category</span>
                    </div>
                    <div class="chart-container-box">
                        <canvas id="categoryStatsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let teamChartInstance = null;
    let programChartInstance = null;
    let categoryChartInstance = null;
    let autoRefreshInterval = null;

    const apiUrl = <?= json_encode(app_url('/admin/api/analytics-data.php')) ?>;

    async function loadAnalyticsData() {
        const refreshBtn = document.getElementById('refreshAnalyticsBtn');
        if (refreshBtn) refreshBtn.classList.add('loading');

        try {
            const res = await fetch(apiUrl, { cache: 'no-cache' });
            const data = await res.json();

            if (!data.success) {
                console.error('Analytics API error:', data.message);
                return;
            }

            // Update KPI Cards
            document.getElementById('kpiTotalPoints').textContent = data.metrics.total_points || '0';
            document.getElementById('kpiTotalPrograms').textContent = data.metrics.total_programs || '0';
            document.getElementById('kpiCompletionRate').textContent = (data.metrics.completion_rate || 0) + '%';
            
            const topTeamEl = document.getElementById('kpiTopTeam');
            if (topTeamEl) {
                topTeamEl.textContent = data.metrics.top_team || 'N/A';
                topTeamEl.style.color = data.metrics.top_team_color || 'var(--violet)';
            }

            document.getElementById('lastUpdatedBadge').textContent = 'Updated ' + (data.timestamp || 'just now');

            // Render Charts
            renderTeamChart(data.team_standings || []);
            renderProgramChart(data.program_status || {});
            renderCategoryChart(data.category_stats || []);
            renderTopEntriesTable(data.top_entries || []);

        } catch (err) {
            console.error('Failed to load analytics:', err);
        } finally {
            if (refreshBtn) refreshBtn.classList.remove('loading');
        }
    }

    function renderTeamChart(teams) {
        const ctx = document.getElementById('teamStandingsChart')?.getContext('2d');
        if (!ctx) return;

        const labels = teams.map(t => t.team_name);
        const scores = teams.map(t => t.total_score);
        const backgroundColors = teams.map(t => t.team_color || '#34d399');

        if (teamChartInstance) teamChartInstance.destroy();

        teamChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Score Points',
                    data: scores,
                    backgroundColor: backgroundColors,
                    borderRadius: 8,
                    borderWidth: 1,
                    borderColor: 'rgba(255, 255, 255, 0.1)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleColor: '#ffffff',
                        bodyColor: '#dfffe4',
                        borderColor: 'rgba(var(--accent-light-rgb), 0.3)',
                        borderWidth: 1,
                        padding: 12
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#b6beca', font: { family: 'Inter', size: 12 } },
                        grid: { display: false }
                    },
                    y: {
                        ticks: { color: '#b6beca', font: { family: 'Inter', size: 12 } },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    }
                }
            }
        });
    }

    function renderProgramChart(statusMap) {
        const ctx = document.getElementById('programStatusChart')?.getContext('2d');
        if (!ctx) return;

        const labels = ['Approved', 'Submitted', 'Scoring', 'Scheduled'];
        const values = [
            statusMap.approved || 0,
            statusMap.submitted || 0,
            statusMap.scoring || 0,
            statusMap.scheduled || 0
        ];

        if (programChartInstance) programChartInstance.destroy();

        programChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: ['#2db24a', '#a184ff', '#f2b238', '#7d8796'],
                    borderWidth: 2,
                    borderColor: '#0b1017'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#94a3b8', padding: 16, font: { family: 'Inter', size: 11 } }
                    }
                },
                cutout: '70%'
            }
        });
    }

    function renderCategoryChart(categoryStats) {
        const ctx = document.getElementById('categoryStatsChart')?.getContext('2d');
        if (!ctx) return;

        const labels = categoryStats.map(c => c.category_name);
        const avgScores = categoryStats.map(c => c.avg_score);

        if (categoryChartInstance) categoryChartInstance.destroy();

        categoryChartInstance = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: labels.length > 0 ? labels : ['No Data'],
                datasets: [{
                    label: 'Avg Score',
                    data: avgScores.length > 0 ? avgScores : [0],
                    backgroundColor: 'rgba(45, 178, 74, 0.18)',
                    borderColor: '#4fda70',
                    pointBackgroundColor: '#4fda70',
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: '#4fda70'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    r: {
                        angleLines: { color: 'rgba(255, 255, 255, 0.08)' },
                        grid: { color: 'rgba(255, 255, 255, 0.08)' },
                        pointLabels: { color: '#b6beca', font: { family: 'Inter', size: 11 } },
                        ticks: { display: false }
                    }
                }
            }
        });
    }

    function renderTopEntriesTable(entries) {
        const tbody = document.getElementById('topEntriesTableBody');
        if (!tbody) return;

        if (entries.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--muted); padding: 24px;">No score entries recorded yet.</td></tr>';
            return;
        }

        tbody.innerHTML = entries.map(item => `
            <tr>
                <td><strong>#${item.entry_number}</strong></td>
                <td><strong style="color: #ffffff;">${item.entry_name}</strong></td>
                <td><span style="font-size: 12.5px; color: var(--muted);">${item.program_title}</span></td>
                <td>
                    <span class="team-color-pill" style="background: ${item.team_color}22; color: #ffffff; border: 1px solid ${item.team_color}44;">
                        ${item.team_name}
                    </span>
                </td>
                <td style="text-align: right;"><strong style="color: #dfffe4; font-size: 15px;">${item.final_total}</strong></td>
            </tr>
        `).join('');
    }

    // Event Listeners
    document.getElementById('refreshAnalyticsBtn')?.addEventListener('click', loadAnalyticsData);

    // Initial Load
    loadAnalyticsData();

    // Auto-refresh every 12 seconds
    autoRefreshInterval = setInterval(loadAnalyticsData, 12000);

    // The admin uses AJAX navigation, so this page can disappear without a
    // browser unload. Release its polling timer and canvas resources first.
    window.addEventListener('admin:before-content-swap', () => {
        clearInterval(autoRefreshInterval);
        teamChartInstance?.destroy();
        programChartInstance?.destroy();
        categoryChartInstance?.destroy();
    }, { once: true });
});
</script>

<?php admin_close_page(); ?>
