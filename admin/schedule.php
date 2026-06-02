<?php
$pageTitle = 'Schedule';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function schedule_redirect(int $stageTypeId = 0): void
{
    admin_redirect('/admin/schedule.php', ['stage' => $stageTypeId ?: null]);
}

function schedule_program_datetime_columns(PDO $pdo): array
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }

    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'musabaqa_programs'
    ");
    $stmt->execute();
    $available = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $start = in_array('start_datetime', $available, true) ? 'start_datetime' : 'start_time';
    $end = in_array('end_datetime', $available, true) ? 'end_datetime' : 'end_time';

    return $columns = [$start, $end];
}

function schedule_load_program(PDO $pdo, int $eventId, int $programId): ?array
{
    [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);
    $stmt = $pdo->prepare("
        SELECT id, title, stage_type_id, {$startExpr} AS start_at, {$endExpr} AS end_at
        FROM musabaqa_programs
        WHERE id = ?
          AND event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$programId, $eventId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    return $program ?: null;
}

function schedule_validate_gap(PDO $pdo, int $eventId, int $stageTypeId, int $previousProgramId, int $nextProgramId): array
{
    $previous = schedule_load_program($pdo, $eventId, $previousProgramId);
    $next = schedule_load_program($pdo, $eventId, $nextProgramId);

    if (!$previous || !$next || empty($previous['end_at']) || empty($next['start_at'])) {
        throw new RuntimeException('Selected timeline gap is invalid.');
    }
    if ((int)$previous['stage_type_id'] !== $stageTypeId || (int)$next['stage_type_id'] !== $stageTypeId) {
        throw new RuntimeException('Selected programs are not in the chosen stage.');
    }

    $start = new DateTime((string)$previous['end_at']);
    $end = new DateTime((string)$next['start_at']);

    if ($start >= $end) {
        throw new RuntimeException('There is no time gap between these programs.');
    }

    $startSql = $start->format('Y-m-d H:i:s');
    $endSql = $end->format('Y-m-d H:i:s');

    [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM musabaqa_programs
        WHERE event_id = ?
          AND stage_type_id = ?
          AND id NOT IN (?, ?)
          AND {$startExpr} < ?
          AND {$endExpr} > ?
    ");
    $stmt->execute([$eventId, $stageTypeId, $previousProgramId, $nextProgramId, $endSql, $startSql]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new RuntimeException('Break time overlaps another program.');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM musabaqa_breaks
        WHERE event_id = ?
          AND stage_type_id = ?
          AND start_datetime < ?
          AND end_datetime > ?
    ");
    $stmt->execute([$eventId, $stageTypeId, $endSql, $startSql]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new RuntimeException('A break already exists in this gap.');
    }

    return [$startSql, $endSql];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        schedule_redirect((int)($_POST['stage_type_id'] ?? 0));
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_break') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $previousProgramId = (int)($_POST['previous_program_id'] ?? 0);
            $nextProgramId = (int)($_POST['next_program_id'] ?? 0);
            $stageTypeId = (int)($_POST['stage_type_id'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Break name is required.');
            }
            if ($stageTypeId <= 0) {
                throw new RuntimeException('Stage is required.');
            }

            [$start, $end] = schedule_validate_gap($pdo, $activeEventId, $stageTypeId, $previousProgramId, $nextProgramId);

            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_breaks
                    (event_id, stage_type_id, name, description, start_datetime, end_datetime)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$activeEventId, $stageTypeId, $name, $description ?: null, $start, $end]);
            admin_flash('success', 'Break added to timeline.');
        } elseif ($action === 'delete_break') {
            $breakId = (int)($_POST['break_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM musabaqa_breaks WHERE id = ? AND event_id = ?');
            $stmt->execute([$breakId, $activeEventId]);
            admin_flash('success', 'Break removed.');
        } else {
            throw new RuntimeException('Invalid schedule action.');
        }
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to update schedule.');
    }

    schedule_redirect((int)($_POST['stage_type_id'] ?? 0));
}

$flash = admin_take_flash();
[$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);

$stageTypes = $pdo->query('SELECT id, name FROM musabaqa_stage_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$selectedStageId = (int)($_GET['stage'] ?? 0);
if ($selectedStageId <= 0 && $stageTypes) {
    $selectedStageId = (int)$stageTypes[0]['id'];
}

$stmt = $pdo->prepare("
    SELECT id, title, location, {$startExpr} AS start_at, {$endExpr} AS end_at
    FROM musabaqa_programs
    WHERE event_id = ?
      AND stage_type_id = ?
      AND {$startExpr} IS NOT NULL
      AND {$endExpr} IS NOT NULL
    ORDER BY {$startExpr} ASC, {$endExpr} ASC, id ASC
");
$stmt->execute([$activeEventId, $selectedStageId]);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT *
    FROM musabaqa_breaks
    WHERE event_id = ?
      AND stage_type_id = ?
    ORDER BY start_datetime ASC, id ASC
");
$stmt->execute([$activeEventId, $selectedStageId]);
$breaks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$breakMap = [];
foreach ($breaks as $break) {
    $breakMap[$break['start_datetime'] . '|' . $break['end_datetime']] = $break;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Schedule</div>
            <div class="page-subtitle">Programs appear chronologically; breaks fill gaps between programs</div>
        </div>
        <a href="<?= APP_URL ?>/admin/programs.php" class="btn btn-secondary btn-md"><i class="fa-solid fa-microphone-lines"></i> Programs</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
            <div class="input-group">
                <label>Stage</label>
                <select name="stage" onchange="this.form.submit()">
                    <?php foreach ($stageTypes as $stage): ?>
                        <option value="<?= (int)$stage['id'] ?>" <?= $selectedStageId === (int)$stage['id'] ? 'selected' : '' ?>>
                            <?= e($stage['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if (!$programs): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-clock"></i></div><div class="empty-title">No Timed Programs</div><div class="empty-subtitle">Add timed programs to the selected stage to build the timeline.</div></div>
    <?php else: ?>
        <div class="panel">
            <div class="dashboard-heading mb-6">Program Timeline</div>
            <div class="grid gap-4">
                <?php foreach ($programs as $index => $program): ?>
                    <div class="panel">
                        <div class="flex-between">
                            <div>
                                <div class="dashboard-heading"><?= e($program['title']) ?></div>
                                <div class="page-subtitle"><?= e($program['location'] ?: '-') ?></div>
                            </div>
                            <span class="badge badge-info"><?= e(date('h:i A', strtotime($program['start_at']))) ?> - <?= e(date('h:i A', strtotime($program['end_at']))) ?></span>
                        </div>
                    </div>

                    <?php if (isset($programs[$index + 1])): ?>
                        <?php
                        $next = $programs[$index + 1];
                        $gapStart = new DateTime((string)$program['end_at']);
                        $gapEnd = new DateTime((string)$next['start_at']);
                        $hasGap = $gapStart < $gapEnd;
                        $gapStartSql = $gapStart->format('Y-m-d H:i:s');
                        $gapEndSql = $gapEnd->format('Y-m-d H:i:s');
                        $break = $breakMap[$gapStartSql . '|' . $gapEndSql] ?? null;
                        ?>
                        <?php if ($hasGap && $break): ?>
                            <div class="panel" style="border-color: rgba(250,204,21,.28);">
                                <div class="flex-between">
                                    <div>
                                        <div class="dashboard-heading"><?= e($break['name']) ?></div>
                                        <div class="page-subtitle"><?= e($break['description'] ?: 'Break') ?></div>
                                    </div>
                                    <div class="flex gap-2 flex-wrap">
                                        <span class="badge badge-warning"><?= e(date('h:i A', strtotime($break['start_datetime']))) ?> - <?= e(date('h:i A', strtotime($break['end_datetime']))) ?></span>
                                        <form method="POST">
                                            <?= admin_csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_break">
                                            <input type="hidden" name="stage_type_id" value="<?= (int)$selectedStageId ?>">
                                            <input type="hidden" name="break_id" value="<?= (int)$break['id'] ?>">
                                            <button class="btn btn-danger btn-sm" type="submit"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($hasGap): ?>
                            <div class="flex-between panel">
                                <div>
                                    <div class="page-subtitle">Gap: <?= e(date('h:i A', strtotime($gapStartSql))) ?> - <?= e(date('h:i A', strtotime($gapEndSql))) ?></div>
                                </div>
                                <button
                                    class="btn btn-success btn-sm"
                                    type="button"
                                    data-open-break
                                    data-previous-program="<?= (int)$program['id'] ?>"
                                    data-next-program="<?= (int)$next['id'] ?>"
                                    data-gap-label="<?= e(date('h:i A', strtotime($gapStartSql)) . ' - ' . date('h:i A', strtotime($gapEndSql))) ?>"
                                >
                                    <i class="fa-solid fa-plus"></i> Add Break
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="breakModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div>
                <div class="modal-title">Add Break</div>
                <div class="page-subtitle" id="breakGapLabel"></div>
            </div>
            <button class="modal-close" type="button" data-close="breakModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="add_break">
            <input type="hidden" name="stage_type_id" value="<?= (int)$selectedStageId ?>">
            <input type="hidden" name="previous_program_id" id="previousProgramId">
            <input type="hidden" name="next_program_id" id="nextProgramId">
            <div class="form-grid">
                <div class="input-group full-width"><label>Break Name</label><input type="text" name="name" required></div>
                <div class="input-group full-width"><label>Description</label><textarea name="description" rows="4"></textarea></div>
            </div>
            <div class="form-actions"><button class="btn btn-secondary btn-md" type="button" data-close="breakModal">Cancel</button><button class="btn btn-success btn-md" type="submit">Save Break</button></div>
        </form>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id)?.classList.add('active')}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));
document.querySelectorAll('[data-open-break]').forEach(button => button.addEventListener('click', () => {
    document.getElementById('previousProgramId').value = button.dataset.previousProgram || '';
    document.getElementById('nextProgramId').value = button.dataset.nextProgram || '';
    document.getElementById('breakGapLabel').textContent = button.dataset.gapLabel || '';
    openModal('breakModal');
}));
</script>
</body>
</html>
