<?php
$pageTitle = 'Database Studio';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();
require_role('admin');

/** @return array{pdo: PDO, label: string} */
function dbstudio_connection(string $key): array
{
    $connections = [
        'musabaqa' => ['pdo' => $GLOBALS['musabaqa_pdo'], 'label' => 'Musabaqa'],
        'dashboard' => ['pdo' => $GLOBALS['dashboard_pdo'], 'label' => 'Dashboard & Users'],
    ];

    if (!isset($connections[$key])) {
        throw new InvalidArgumentException('Unknown database connection.');
    }

    return $connections[$key];
}

function dbstudio_identifier(string $identifier): string
{
    if ($identifier === '' || strlen($identifier) > 64 || !preg_match('/^[A-Za-z0-9_$-]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid table name.');
    }

    return '`' . str_replace('`', '``', $identifier) . '`';
}

function dbstudio_tables(PDO $pdo): array
{
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION, TABLE_COMMENT
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME'
    );
    $stmt->execute([$database]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dbstudio_table_exists(PDO $pdo, string $table): bool
{
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([$database, $table]);

    return (int)$stmt->fetchColumn() === 1;
}

function dbstudio_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function dbstudio_value(mixed $value): mixed
{
    if (is_resource($value)) {
        return '[binary resource]';
    }

    if (is_string($value) && strlen($value) > 12000) {
        return substr($value, 0, 12000) . "\n… [truncated]";
    }

    return $value;
}

function dbstudio_read_only_sql(string $sql): bool
{
    $withoutComments = preg_replace('/\A(?:\s+|--[^\r\n]*(?:\r?\n|\z)|#[^\r\n]*(?:\r?\n|\z)|\/\*.*?\*\/)+/s', '', $sql);
    $keyword = strtoupper((string)strtok(ltrim((string)$withoutComments), " \t\r\n("));

    // WITH is intentionally omitted: a CTE may lead into an UPDATE or DELETE.
    // Read-only CTEs can still run after the admin confirmation step.
    return in_array($keyword, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true);
}

$connectionKey = (string)($_REQUEST['db'] ?? 'musabaqa');

try {
    $connection = dbstudio_connection($connectionKey);
    $pdo = $connection['pdo'];
} catch (Throwable $error) {
    if (isset($_GET['api']) || isset($_POST['api'])) {
        dbstudio_json(['success' => false, 'error' => $error->getMessage()], 400);
    }
    $connectionKey = 'musabaqa';
    $connection = dbstudio_connection($connectionKey);
    $pdo = $connection['pdo'];
}

if (($_GET['export'] ?? '') === 'table') {
    $table = (string)($_GET['table'] ?? '');
    if (!dbstudio_table_exists($pdo, $table)) {
        http_response_code(404);
        exit('Table not found.');
    }

    $quotedTable = dbstudio_identifier($table);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $table) . '-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');

    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    $stmt = $pdo->query("SELECT * FROM {$quotedTable}");
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($first) {
            fputcsv($output, array_keys($row));
            $first = false;
        }
        fputcsv($output, array_values($row));
    }
    fclose($output);
    exit;
}

$api = (string)($_REQUEST['api'] ?? '');

if ($api !== '') {
    try {
        if ($api === 'overview') {
            $tables = dbstudio_tables($pdo);
            $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $size = array_sum(array_map(static fn(array $row): int => (int)$row['DATA_LENGTH'] + (int)$row['INDEX_LENGTH'], $tables));
            dbstudio_json([
                'success' => true,
                'database' => $database,
                'label' => $connection['label'],
                'version' => $version,
                'size' => $size,
                'tables' => $tables,
            ]);
        }

        if ($api === 'table') {
            $table = (string)($_GET['table'] ?? '');
            if (!dbstudio_table_exists($pdo, $table)) {
                throw new RuntimeException('Table not found.');
            }

            $quotedTable = dbstudio_identifier($table);
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            $total = (int)$pdo->query("SELECT COUNT(*) FROM {$quotedTable}")->fetchColumn();
            $stmt = $pdo->query("SELECT * FROM {$quotedTable} LIMIT {$limit} OFFSET {$offset}");
            $rows = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = array_map('dbstudio_value', $row);
            }

            $columns = $pdo->query("SHOW FULL COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
            $indexes = $pdo->query("SHOW INDEX FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
            dbstudio_json([
                'success' => true,
                'table' => $table,
                'columns' => $columns,
                'indexes' => $indexes,
                'rows' => $rows,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, (int)ceil($total / $limit)),
            ]);
        }

        if ($api === 'query') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                dbstudio_json(['success' => false, 'error' => 'POST required.'], 405);
            }
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                dbstudio_json(['success' => false, 'error' => 'Your session token expired. Refresh and try again.'], 419);
            }

            $sql = trim((string)($_POST['sql'] ?? ''));
            if ($sql === '') {
                throw new InvalidArgumentException('Enter a SQL statement.');
            }
            if (strlen($sql) > 100000) {
                throw new InvalidArgumentException('Query is too large (100 KB maximum).');
            }

            $readOnly = dbstudio_read_only_sql($sql);
            if (!$readOnly && (string)($_POST['confirm_danger'] ?? '') !== 'RUN') {
                dbstudio_json([
                    'success' => false,
                    'requires_confirmation' => true,
                    'error' => 'This statement can change the database. Type RUN to confirm.',
                ], 409);
            }

            $started = microtime(true);
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $duration = round((microtime(true) - $started) * 1000, 2);
            $affectedRows = $stmt->rowCount();
            $columns = [];
            $rows = [];
            $truncated = false;

            if ($stmt->columnCount() > 0) {
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $columns[] = $meta['name'] ?? 'column_' . ($i + 1);
                }
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (count($rows) >= 500) {
                        $truncated = true;
                        break;
                    }
                    $rows[] = array_map('dbstudio_value', $row);
                }
            }

            $_SESSION['dbstudio_history'] = array_slice(array_merge([[
                'db' => $connectionKey,
                'sql' => $sql,
                'at' => date('Y-m-d H:i:s'),
                'duration' => $duration,
                'affected' => $affectedRows,
            ]], $_SESSION['dbstudio_history'] ?? []), 0, 20);

            if (!$readOnly) {
                try {
                    $currentUser = current_user();
                    admin_log_activity(
                        $GLOBALS['musabaqa_pdo'],
                        isset($currentUser['id']) ? (int)$currentUser['id'] : null,
                        isset($_SESSION['selected_event_id']) ? (int)$_SESSION['selected_event_id'] : null,
                        'database_query',
                        'database_studio',
                        null,
                        sprintf(
                            'Admin SQL on %s (%s), %d affected row(s), fingerprint %s',
                            $connectionKey,
                            strtoupper((string)strtok(ltrim($sql), " \t\r\n(")),
                            $affectedRows,
                            substr(hash('sha256', $sql), 0, 16)
                        )
                    );
                } catch (Throwable) {
                    // The requested query already succeeded; an unavailable audit
                    // table must not cause it to be presented as a failed query.
                }
            }

            dbstudio_json([
                'success' => true,
                'read_only' => $readOnly,
                'duration_ms' => $duration,
                'affected' => $affectedRows,
                'columns' => $columns,
                'rows' => $rows,
                'truncated' => $truncated,
            ]);
        }

        dbstudio_json(['success' => false, 'error' => 'Unknown operation.'], 404);
    } catch (Throwable $error) {
        dbstudio_json(['success' => false, 'error' => $error->getMessage()], 400);
    }
}

$history = is_array($_SESSION['dbstudio_history'] ?? null) ? $_SESSION['dbstudio_history'] : [];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<main class="main-content dbstudio" id="dbStudio" data-endpoint="<?= e($_SERVER['SCRIPT_NAME']) ?>">
    <div class="topbar">
        <div>
            <h1 class="page-title">Database Studio</h1>
            <p class="page-subtitle">Inspect schemas, browse records and run SQL without leaving Musabaqa.</p>
        </div>
        <div class="dbstudio-connection">
            <span class="dbstudio-live-dot"></span>
            <select id="dbConnection" aria-label="Database connection">
                <option value="musabaqa" <?= $connectionKey === 'musabaqa' ? 'selected' : '' ?>>Musabaqa database</option>
                <option value="dashboard" <?= $connectionKey === 'dashboard' ? 'selected' : '' ?>>Dashboard & users</option>
            </select>
        </div>
    </div>

    <div class="dbstudio-stats" id="dbStats" aria-live="polite">
        <div class="stat-card"><span>Database</span><strong id="dbName">Loading…</strong></div>
        <div class="stat-card"><span>Tables</span><strong id="dbTableCount">—</strong></div>
        <div class="stat-card"><span>Approx. size</span><strong id="dbSize">—</strong></div>
        <div class="stat-card"><span>Server</span><strong id="dbVersion">—</strong></div>
    </div>

    <div class="dbstudio-layout">
        <aside class="panel dbstudio-explorer">
            <div class="dbstudio-panel-head">
                <div><strong>Explorer</strong><small id="tableCountLabel">Tables</small></div>
                <button type="button" class="dbstudio-icon-btn" id="refreshDatabase" title="Refresh database"><i class="fa-solid fa-rotate"></i></button>
            </div>
            <label class="dbstudio-search"><i class="fa-solid fa-magnifying-glass"></i><input id="tableSearch" type="search" placeholder="Find a table…" autocomplete="off"></label>
            <div class="dbstudio-table-list" id="dbTableList"><div class="dbstudio-empty">Loading tables…</div></div>
        </aside>

        <section class="panel dbstudio-workspace">
            <div class="dbstudio-tabs" role="tablist">
                <button type="button" class="active" data-db-tab="browse"><i class="fa-solid fa-table"></i> Browse</button>
                <button type="button" data-db-tab="structure"><i class="fa-solid fa-diagram-project"></i> Structure</button>
                <button type="button" data-db-tab="sql"><i class="fa-solid fa-terminal"></i> SQL</button>
                <button type="button" data-db-tab="history"><i class="fa-solid fa-clock-rotate-left"></i> History</button>
            </div>

            <div class="dbstudio-tab active" data-db-panel="browse">
                <div class="dbstudio-toolbar">
                    <div><strong id="browseTitle">Select a table</strong><small id="browseMeta">Choose one from the explorer</small></div>
                    <div class="dbstudio-actions">
                        <select id="rowLimit" aria-label="Rows per page"><option>25</option><option selected>50</option><option>100</option><option>200</option></select>
                        <a class="btn btn-secondary btn-sm disabled" id="exportTable" href="#" data-ajax-ignore><i class="fa-solid fa-file-csv"></i> CSV</a>
                    </div>
                </div>
                <div class="dbstudio-grid-wrap" id="browseGrid"><div class="dbstudio-empty"><i class="fa-solid fa-table"></i><span>Select a table to browse its records.</span></div></div>
                <div class="dbstudio-pagination" id="browsePagination"></div>
            </div>

            <div class="dbstudio-tab" data-db-panel="structure">
                <div class="dbstudio-toolbar"><div><strong id="structureTitle">Table structure</strong><small>Columns and indexes</small></div></div>
                <div id="structureGrid"><div class="dbstudio-empty">Select a table to inspect its schema.</div></div>
            </div>

            <div class="dbstudio-tab" data-db-panel="sql">
                <div class="dbstudio-editor-head">
                    <div><strong>SQL editor</strong><small>Ctrl + Enter to execute · result limit 500 rows</small></div>
                    <button type="button" class="btn btn-primary btn-sm" id="runSql"><i class="fa-solid fa-play"></i> Run query</button>
                </div>
                <textarea id="sqlEditor" class="dbstudio-editor" spellcheck="false" aria-label="SQL query">SELECT * FROM musabaqa_events ORDER BY id DESC LIMIT 25;</textarea>
                <div class="dbstudio-danger" id="sqlDanger" hidden>
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>This query can modify data. Type <strong>RUN</strong> to confirm:</span>
                    <input id="dangerConfirm" type="text" maxlength="3" autocomplete="off" placeholder="RUN">
                </div>
                <div class="dbstudio-query-status" id="queryStatus">Ready</div>
                <div class="dbstudio-grid-wrap" id="queryResults"><div class="dbstudio-empty">Query results will appear here.</div></div>
            </div>

            <div class="dbstudio-tab" data-db-panel="history">
                <div class="dbstudio-toolbar"><div><strong>Recent queries</strong><small>Stored only in your current session</small></div></div>
                <div class="dbstudio-history" id="queryHistory">
                    <?php if (!$history): ?>
                        <div class="dbstudio-empty">No queries in this session yet.</div>
                    <?php else: foreach ($history as $item): ?>
                        <button type="button" class="dbstudio-history-item" data-history-sql="<?= e((string)($item['sql'] ?? '')) ?>" data-history-db="<?= e((string)($item['db'] ?? 'musabaqa')) ?>">
                            <code><?= e((string)($item['sql'] ?? '')) ?></code>
                            <span><?= e((string)($item['at'] ?? '')) ?> · <?= e((string)($item['duration'] ?? 0)) ?> ms</span>
                        </button>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </section>
    </div>

    <input type="hidden" id="dbCsrfToken" value="<?= e(generate_csrf_token()) ?>">
</main>

<style>
.dbstudio{--db-accent:#14b8a6;--db-line:rgba(148,163,184,.16)}
.dbstudio-connection{display:flex;align-items:center;gap:9px;padding:8px 12px;border:1px solid var(--db-line);border-radius:12px;background:rgba(15,23,42,.7)}
.dbstudio-connection select,.dbstudio select{background:#0f172a;color:#e2e8f0;border:1px solid var(--db-line);border-radius:8px;padding:7px 10px}
.dbstudio-live-dot{width:9px;height:9px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 5px rgba(34,197,94,.12)}
.dbstudio-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.dbstudio-stats .stat-card{min-height:92px;display:flex;flex-direction:column;justify-content:center}.dbstudio-stats span{color:#94a3b8;font-size:.78rem;text-transform:uppercase;letter-spacing:.08em}.dbstudio-stats strong{font-size:1.05rem;margin-top:7px;overflow:hidden;text-overflow:ellipsis}
.dbstudio-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:16px;min-height:610px}.dbstudio-explorer,.dbstudio-workspace{margin:0;min-width:0}.dbstudio-explorer{padding:0;overflow:hidden}.dbstudio-panel-head,.dbstudio-toolbar,.dbstudio-editor-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px;border-bottom:1px solid var(--db-line)}.dbstudio-panel-head div,.dbstudio-toolbar>div,.dbstudio-editor-head>div{display:flex;flex-direction:column;gap:3px}.dbstudio small{color:#94a3b8}.dbstudio-icon-btn{border:0;background:transparent;color:#94a3b8;width:34px;height:34px;border-radius:8px;cursor:pointer}.dbstudio-icon-btn:hover{background:rgba(20,184,166,.12);color:#5eead4}
.dbstudio-search{display:flex;align-items:center;gap:8px;margin:12px;padding:9px 10px;border:1px solid var(--db-line);border-radius:9px;color:#64748b}.dbstudio-search:focus-within{border-color:var(--db-accent)}.dbstudio-search input{width:100%;background:none;border:0;outline:0;color:#e2e8f0}.dbstudio-table-list{height:510px;overflow:auto;padding:0 8px 12px}.dbstudio-table-item{display:flex;width:100%;align-items:center;gap:9px;text-align:left;border:0;background:transparent;color:#cbd5e1;padding:9px 10px;border-radius:8px;cursor:pointer}.dbstudio-table-item:hover,.dbstudio-table-item.active{background:rgba(20,184,166,.12);color:#5eead4}.dbstudio-table-item span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dbstudio-table-item small{margin-left:auto;font-variant-numeric:tabular-nums}
.dbstudio-workspace{padding:0;overflow:hidden}.dbstudio-tabs{display:flex;gap:4px;padding:10px 12px;border-bottom:1px solid var(--db-line);overflow:auto}.dbstudio-tabs button{border:0;background:transparent;color:#94a3b8;padding:9px 13px;border-radius:8px;cursor:pointer;white-space:nowrap}.dbstudio-tabs button.active{background:rgba(20,184,166,.14);color:#5eead4}.dbstudio-tab{display:none}.dbstudio-tab.active{display:block}.dbstudio-actions{display:flex!important;flex-direction:row!important;align-items:center}.dbstudio .disabled{opacity:.45;pointer-events:none}.dbstudio-grid-wrap{overflow:auto;min-height:390px;max-height:520px}.dbstudio-grid{border-collapse:separate;border-spacing:0;width:100%;font-size:.82rem}.dbstudio-grid th{position:sticky;top:0;z-index:1;background:#111c2f;color:#94a3b8;text-align:left;padding:10px 12px;border-bottom:1px solid var(--db-line);white-space:nowrap}.dbstudio-grid td{padding:9px 12px;border-bottom:1px solid rgba(148,163,184,.09);max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#cbd5e1}.dbstudio-grid tr:hover td{background:rgba(20,184,166,.035)}.dbstudio-null{color:#64748b;font-style:italic}.dbstudio-empty{min-height:240px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:#64748b;text-align:center;padding:30px}.dbstudio-empty i{font-size:2rem}.dbstudio-pagination{display:flex;justify-content:flex-end;align-items:center;gap:8px;padding:12px 16px;border-top:1px solid var(--db-line)}
.dbstudio-editor-head{border-bottom:0}.dbstudio-editor{display:block;width:calc(100% - 32px);height:210px;margin:0 16px;padding:16px;resize:vertical;border:1px solid var(--db-line);border-radius:10px;background:#07111f;color:#d1fae5;font:13px/1.65 Consolas,'Courier New',monospace;tab-size:4;outline:none}.dbstudio-editor:focus{border-color:var(--db-accent);box-shadow:0 0 0 3px rgba(20,184,166,.1)}.dbstudio-danger{margin:12px 16px 0;padding:11px 13px;border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.09);border-radius:9px;color:#fbbf24;display:flex;align-items:center;gap:10px}.dbstudio-danger[hidden]{display:none}.dbstudio-danger input{width:70px;margin-left:auto;background:#0f172a;border:1px solid #f59e0b;color:#fff;padding:7px;border-radius:7px;text-transform:uppercase}.dbstudio-query-status{margin:12px 16px;color:#94a3b8;font-size:.82rem}.dbstudio-query-status.error{color:#f87171}.dbstudio-query-status.success{color:#34d399}.dbstudio-history{padding:12px}.dbstudio-history-item{display:flex;flex-direction:column;gap:7px;width:100%;text-align:left;padding:12px;margin-bottom:8px;border:1px solid var(--db-line);border-radius:9px;background:rgba(15,23,42,.45);color:#cbd5e1;cursor:pointer}.dbstudio-history-item:hover{border-color:rgba(20,184,166,.45)}.dbstudio-history-item code{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dbstudio-history-item span{font-size:.76rem;color:#64748b}
@media(max-width:1050px){.dbstudio-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.dbstudio-layout{grid-template-columns:1fr}.dbstudio-table-list{height:240px}.dbstudio-workspace{min-height:600px}}
@media(max-width:620px){.dbstudio-stats{grid-template-columns:1fr}.dbstudio-panel-head,.dbstudio-toolbar,.dbstudio-editor-head{align-items:flex-start}.dbstudio-toolbar{flex-direction:column}.dbstudio-actions{width:100%;justify-content:space-between}.dbstudio-danger{align-items:flex-start;flex-wrap:wrap}.dbstudio-danger input{margin-left:0}}
</style>

<script>
(() => {
    const root = document.getElementById('dbStudio');
    if (!root) return;

    const endpoint = root.dataset.endpoint;
    const connection = document.getElementById('dbConnection');
    const csrf = document.getElementById('dbCsrfToken').value;
    const state = { tables: [], table: '', page: 1, tableData: null };
    const $ = (id) => document.getElementById(id);

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
    const formatBytes = (bytes) => {
        const value = Number(bytes || 0);
        if (!value) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const unit = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        return `${(value / (1024 ** unit)).toFixed(unit ? 1 : 0)} ${units[unit]}`;
    };
    const apiUrl = (api, params = {}) => {
        const url = new URL(endpoint, location.origin);
        url.searchParams.set('api', api);
        url.searchParams.set('db', connection.value);
        Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
        return url;
    };
    const jsonFetch = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
        const data = await response.json().catch(() => ({ success: false, error: 'The server returned an invalid response.' }));
        if (!response.ok || !data.success) {
            const error = new Error(data.error || `Request failed (${response.status})`);
            error.data = data;
            throw error;
        }
        return data;
    };
    const renderValue = (value) => {
        if (value === null) return '<span class="dbstudio-null">NULL</span>';
        if (typeof value === 'object') return escapeHtml(JSON.stringify(value));
        return escapeHtml(value);
    };
    const renderGrid = (columns, rows) => {
        if (!columns.length) return '<div class="dbstudio-empty">The query completed without a result set.</div>';
        const head = columns.map(column => `<th>${escapeHtml(column)}</th>`).join('');
        const body = rows.length
            ? rows.map(row => `<tr>${columns.map(column => `<td title="${escapeHtml(row[column] ?? '')}">${renderValue(row[column])}</td>`).join('')}</tr>`).join('')
            : `<tr><td colspan="${columns.length}"><div class="dbstudio-empty">No rows found.</div></td></tr>`;
        return `<table class="dbstudio-grid"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
    };

    async function loadOverview() {
        $('dbTableList').innerHTML = '<div class="dbstudio-empty">Loading tables…</div>';
        try {
            const data = await jsonFetch(apiUrl('overview'));
            state.tables = data.tables;
            $('dbName').textContent = data.database;
            $('dbTableCount').textContent = data.tables.length.toLocaleString();
            $('dbSize').textContent = formatBytes(data.size);
            $('dbVersion').textContent = `MySQL ${data.version}`;
            $('tableCountLabel').textContent = `${data.tables.length} tables`;
            renderTableList();
        } catch (error) {
            $('dbTableList').innerHTML = `<div class="dbstudio-empty">${escapeHtml(error.message)}</div>`;
        }
    }

    function renderTableList() {
        const search = $('tableSearch').value.trim().toLowerCase();
        const tables = state.tables.filter(table => table.TABLE_NAME.toLowerCase().includes(search));
        $('dbTableList').innerHTML = tables.length ? tables.map(table => `
            <button type="button" class="dbstudio-table-item ${state.table === table.TABLE_NAME ? 'active' : ''}" data-table="${escapeHtml(table.TABLE_NAME)}">
                <i class="fa-solid fa-table"></i><span>${escapeHtml(table.TABLE_NAME)}</span><small>${Number(table.TABLE_ROWS || 0).toLocaleString()}</small>
            </button>`).join('') : '<div class="dbstudio-empty">No matching tables.</div>';
    }

    async function loadTable(table, page = 1) {
        state.table = table;
        state.page = page;
        renderTableList();
        $('browseTitle').textContent = table;
        $('browseMeta').textContent = 'Loading records…';
        $('browseGrid').innerHTML = '<div class="dbstudio-empty">Loading records…</div>';
        $('exportTable').classList.remove('disabled');
        const exportUrl = new URL(endpoint, location.origin);
        exportUrl.searchParams.set('export', 'table');
        exportUrl.searchParams.set('db', connection.value);
        exportUrl.searchParams.set('table', table);
        $('exportTable').href = exportUrl;
        try {
            const data = await jsonFetch(apiUrl('table', { table, page, limit: $('rowLimit').value }));
            state.tableData = data;
            const columnNames = data.columns.map(column => column.Field);
            $('browseMeta').textContent = `${Number(data.total).toLocaleString()} rows · page ${data.page} of ${data.pages}`;
            $('browseGrid').innerHTML = renderGrid(columnNames, data.rows);
            renderStructure(data);
            renderPagination(data);
        } catch (error) {
            $('browseMeta').textContent = 'Could not load table';
            $('browseGrid').innerHTML = `<div class="dbstudio-empty">${escapeHtml(error.message)}</div>`;
        }
    }

    function renderStructure(data) {
        $('structureTitle').textContent = `${data.table} structure`;
        const columns = data.columns.map(column => ({
            Column: column.Field, Type: column.Type, Nullable: column.Null,
            Key: column.Key || '—', Default: column.Default, Extra: column.Extra || '—',
        }));
        const indexes = data.indexes.map(index => ({
            Index: index.Key_name, Column: index.Column_name, Unique: Number(index.Non_unique) ? 'No' : 'Yes',
            Sequence: index.Seq_in_index, Type: index.Index_type,
        }));
        $('structureGrid').innerHTML = `<div class="dbstudio-grid-wrap">${renderGrid(['Column', 'Type', 'Nullable', 'Key', 'Default', 'Extra'], columns)}</div>
            <div class="dbstudio-toolbar"><div><strong>Indexes</strong><small>${indexes.length} index columns</small></div></div>
            <div class="dbstudio-grid-wrap" style="min-height:160px;max-height:280px">${renderGrid(['Index', 'Column', 'Unique', 'Sequence', 'Type'], indexes)}</div>`;
    }

    function renderPagination(data) {
        const previous = Math.max(1, data.page - 1);
        const next = Math.min(data.pages, data.page + 1);
        $('browsePagination').innerHTML = `<span>Page ${data.page} / ${data.pages}</span>
            <button type="button" class="btn btn-secondary btn-sm" data-db-page="${previous}" ${data.page <= 1 ? 'disabled' : ''}><i class="fa-solid fa-angle-left"></i></button>
            <button type="button" class="btn btn-secondary btn-sm" data-db-page="${next}" ${data.page >= data.pages ? 'disabled' : ''}><i class="fa-solid fa-angle-right"></i></button>`;
    }

    function showTab(tab) {
        document.querySelectorAll('[data-db-tab]').forEach(button => button.classList.toggle('active', button.dataset.dbTab === tab));
        document.querySelectorAll('[data-db-panel]').forEach(panel => panel.classList.toggle('active', panel.dataset.dbPanel === tab));
    }

    async function runQuery(force = false) {
        const sql = $('sqlEditor').value.trim();
        if (!sql) return;
        $('runSql').disabled = true;
        $('queryStatus').className = 'dbstudio-query-status';
        $('queryStatus').textContent = 'Executing…';
        const body = new FormData();
        body.set('api', 'query');
        body.set('db', connection.value);
        body.set('csrf_token', csrf);
        body.set('sql', sql);
        if (force) body.set('confirm_danger', $('dangerConfirm').value.trim().toUpperCase());
        try {
            const data = await jsonFetch(endpoint, { method: 'POST', body });
            $('sqlDanger').hidden = true;
            $('dangerConfirm').value = '';
            $('queryStatus').className = 'dbstudio-query-status success';
            $('queryStatus').textContent = `Completed in ${data.duration_ms} ms · ${data.columns.length ? `${data.rows.length} rows returned` : `${data.affected} rows affected`}${data.truncated ? ' · limited to 500 rows' : ''}`;
            $('queryResults').innerHTML = renderGrid(data.columns, data.rows);
            if (!data.read_only) await loadOverview();
        } catch (error) {
            if (error.data?.requires_confirmation) {
                $('sqlDanger').hidden = false;
                $('dangerConfirm').focus();
                $('queryStatus').textContent = error.message;
            } else {
                $('queryStatus').className = 'dbstudio-query-status error';
                $('queryStatus').textContent = error.message;
                $('queryResults').innerHTML = `<div class="dbstudio-empty">${escapeHtml(error.message)}</div>`;
            }
        } finally {
            $('runSql').disabled = false;
        }
    }

    root.addEventListener('click', event => {
        const tableButton = event.target.closest('[data-table]');
        if (tableButton) { loadTable(tableButton.dataset.table); return; }
        const tabButton = event.target.closest('[data-db-tab]');
        if (tabButton) { showTab(tabButton.dataset.dbTab); return; }
        const pageButton = event.target.closest('[data-db-page]');
        if (pageButton && state.table) { loadTable(state.table, Number(pageButton.dataset.dbPage)); return; }
        const historyButton = event.target.closest('[data-history-sql]');
        if (historyButton) {
            connection.value = historyButton.dataset.historyDb;
            $('sqlEditor').value = historyButton.dataset.historySql;
            showTab('sql');
            loadOverview();
        }
    });
    $('tableSearch').addEventListener('input', renderTableList);
    $('refreshDatabase').addEventListener('click', () => { loadOverview(); if (state.table) loadTable(state.table, state.page); });
    $('rowLimit').addEventListener('change', () => state.table && loadTable(state.table, 1));
    connection.addEventListener('change', () => {
        state.table = '';
        state.tableData = null;
        $('browseTitle').textContent = 'Select a table';
        $('browseMeta').textContent = 'Choose one from the explorer';
        $('browseGrid').innerHTML = '<div class="dbstudio-empty"><i class="fa-solid fa-table"></i><span>Select a table to browse its records.</span></div>';
        $('browsePagination').innerHTML = '';
        $('exportTable').classList.add('disabled');
        loadOverview();
    });
    $('runSql').addEventListener('click', () => runQuery(!$('sqlDanger').hidden));
    $('dangerConfirm').addEventListener('input', () => $('runSql').disabled = $('dangerConfirm').value.trim().toUpperCase() !== 'RUN');
    $('sqlEditor').addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); runQuery(!$('sqlDanger').hidden); }
        if (event.key === 'Tab') {
            event.preventDefault();
            const start = event.target.selectionStart;
            event.target.value = event.target.value.slice(0, start) + '    ' + event.target.value.slice(event.target.selectionEnd);
            event.target.selectionStart = event.target.selectionEnd = start + 4;
        }
    });

    loadOverview();
})();
</script>

<?php admin_close_page(); ?>
