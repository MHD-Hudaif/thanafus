<?php
$pageTitle = 'Settings';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function admin_backup_database_sql(PDO $pdo): string {
    $tables = [];
    $result = $pdo->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    $output = "-- Kauzariyya Musabaqa Database Backup\n";
    $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    
    foreach ($tables as $table) {
        // Create Table statement
        $createRow = $pdo->query("SHOW CREATE TABLE `" . $table . "`")->fetch(PDO::FETCH_NUM);
        $output .= "\n\n" . $createRow[1] . ";\n\n";
        
        // Insert statement
        $rows = $pdo->query("SELECT * FROM `" . $table . "`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $output .= "INSERT INTO `" . $table . "` VALUES\n";
            $inserts = [];
            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $values[] = "NULL";
                    } else {
                        $values[] = $pdo->quote($val);
                    }
                }
                $inserts[] = "(" . implode(", ", $values) . ")";
            }
            $output .= implode(",\n", $inserts) . ";\n";
        }
    }
    
    $output .= "\n\nSET FOREIGN_KEY_CHECKS=1;\n";
    return $output;
}

// Check for download backup action
if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    if (!is_admin()) {
        admin_flash('error', 'Unauthorized.');
        admin_redirect('/admin/event-manager/settings.php');
    }
    try {
        $sql = admin_backup_database_sql($pdo);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="musabaqa_backup_' . date('Ymd_His') . '.sql"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit;
    } catch (Throwable $e) {
        admin_flash('error', 'Backup failed: ' . $e->getMessage());
        admin_redirect('/admin/event-manager/settings.php?tab=database');
    }
}

// Helper functions for settings
function get_musabaqa_settings($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'global_musabaqa_settings' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    
    $defaults = [
        'default_judges_count' => 2,
        'default_total_marks' => 100,
        'default_entries_limit' => 10,
        'first_place_points' => 10,
        'second_place_points' => 7,
        'third_place_points' => 5,
        'grade_85_plus_bonus_points' => 3,
        'grade_85_plus_threshold' => 85,
        'tied_rank_mode' => 'shared_full',
        'active_sections' => [],
        'section_limits' => []
    ];
    
    if ($row) {
        $data = json_decode($row['setting_value'], true);
        if (is_array($data)) {
            return array_merge($defaults, $data);
        }
    }
    
    return $defaults;
}

if (!function_exists('save_musabaqa_settings')) {
    function save_musabaqa_settings($pdo, $settings) {
        if (function_exists('admin_save_settings') && is_array($settings)) {
            admin_save_settings($pdo, $settings);
            return;
        }
        $value = json_encode($settings);
        $stmt = $pdo->prepare("
            INSERT INTO musabaqa_settings (setting_key, setting_value)
            VALUES ('global_musabaqa_settings', ?)
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        $stmt->execute([$value, $value]);
    }
}

$classTypes = $dashboardPdo->query('SELECT id, name FROM class_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/event-manager/settings.php');
    }

    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'bulk_modify_programs') {
            $filterSection = (int)($_POST['filter_section'] ?? 0);
            $filterType = (string)($_POST['filter_type'] ?? 'all');
            $filterSpecial = (string)($_POST['filter_special'] ?? 'all');

            $updateJudges = isset($_POST['update_judges']);
            $updateMarks = isset($_POST['update_marks']);
            $updateLimit = isset($_POST['update_limit']);
            $updateTeamOnly = isset($_POST['update_team_only']);

            if (!$updateJudges && !$updateMarks && !$updateLimit && !$updateTeamOnly) {
                throw new RuntimeException('Please check at least one parameter to apply.');
            }

            $valJudges = max(1, min(10, (int)($_POST['val_judges'] ?? 2)));
            $valMarks = max(1, min(1000, (int)($_POST['val_marks'] ?? 100)));
            $valLimit = max(1, min(1000, (int)($_POST['val_limit'] ?? 10)));
            $valTeamOnly = (int)($_POST['val_team_only'] ?? 0);

            // Build SQL
            $setClauses = [];
            $params = [];

            if ($updateJudges) {
                $setClauses[] = "judges_count = ?";
                $params[] = $valJudges;
            }
            if ($updateMarks) {
                $setClauses[] = "total_marks = ?";
                $params[] = $valMarks;
            }
            if ($updateLimit) {
                $setClauses[] = "entries_limit = ?";
                $params[] = $valLimit;
            }
            if ($updateTeamOnly) {
                $setClauses[] = "only_team_marks = ?";
                $params[] = $valTeamOnly;
            }

            $sql = "UPDATE musabaqa_programs SET " . implode(', ', $setClauses) . " WHERE event_id = ?";
            $params[] = $activeEventId;

            if ($filterSection > 0) {
                $sql .= " AND class_type_id = ?";
                $params[] = $filterSection;
            }
            if ($filterType === 'individual' || $filterType === 'group') {
                $sql .= " AND program_type = ?";
                $params[] = $filterType;
            }
            if ($filterSpecial === 'special') {
                $sql .= " AND is_special = 1";
            } elseif ($filterSpecial === 'regular') {
                $sql .= " AND is_special = 0";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $count = $stmt->rowCount();

            admin_flash('success', "Successfully modified {$count} program(s) to the specified settings.");
            admin_redirect('/admin/event-manager/settings.php?tab=bulk-modify');
        } elseif ($action === 'reset_scores') {
            if (!is_admin()) {
                throw new RuntimeException('Unauthorized.');
            }
            admin_db_transaction($pdo, function ($pdo) use ($activeEventId) {
                // Clear scores
                $pdo->prepare("DELETE FROM musabaqa_member_scores WHERE program_id IN (SELECT id FROM musabaqa_programs WHERE event_id = ?)")->execute([$activeEventId]);
                $pdo->prepare("DELETE FROM musabaqa_category_scores WHERE program_id IN (SELECT id FROM musabaqa_programs WHERE event_id = ?)")->execute([$activeEventId]);
                $pdo->prepare("DELETE FROM musabaqa_score_sheets WHERE program_id IN (SELECT id FROM musabaqa_programs WHERE event_id = ?)")->execute([$activeEventId]);
                $pdo->prepare("DELETE FROM musabaqa_scores WHERE program_id IN (SELECT id FROM musabaqa_programs WHERE event_id = ?)")->execute([$activeEventId]);
                
                // Reset entries to scoring status
                $pdo->prepare("UPDATE musabaqa_program_entries SET final_score = 0, final_rank = NULL, team_score = 0, status = 'scoring' WHERE event_id = ?")->execute([$activeEventId]);
                
                // Recalculate team totals (will be 0)
                admin_recalculate_team_totals($pdo, $activeEventId);
            });
            admin_flash('success', 'All entered scores and standings cleared successfully.');
            admin_redirect('/admin/event-manager/settings.php?tab=database');
        } elseif ($action === 'reset_whole_event') {
            if (!is_admin()) {
                throw new RuntimeException('Unauthorized.');
            }
            admin_db_transaction($pdo, function ($pdo) use ($activeEventId) {
                $tablesToDelete = [
                    'musabaqa_member_scores',
                    'musabaqa_category_scores',
                    'musabaqa_score_sheets',
                    'musabaqa_scores',
                    'musabaqa_entry_members',
                    'musabaqa_program_entries',
                    'musabaqa_program_categories',
                    'musabaqa_programs',
                    'musabaqa_team_members',
                    'musabaqa_teams',
                    'musabaqa_breaks',
                    'musabaqa_schedule_sections',
                    'musabaqa_manual_scoreboard'
                ];

                foreach ($tablesToDelete as $table) {
                    try {
                        if ($table === 'musabaqa_member_scores' || $table === 'musabaqa_program_categories') {
                            $pdo->prepare("DELETE FROM {$table} WHERE program_id IN (SELECT id FROM musabaqa_programs WHERE event_id = ?)")->execute([$activeEventId]);
                        } elseif ($table === 'musabaqa_category_scores' || $table === 'musabaqa_score_sheets') {
                            $pdo->prepare("DELETE FROM {$table} WHERE program_id IN (SELECT id FROM musabaqa_programs WHERE event_id = ?)")->execute([$activeEventId]);
                        } elseif ($table === 'musabaqa_entry_members') {
                            $pdo->prepare("DELETE FROM {$table} WHERE entry_id IN (SELECT id FROM musabaqa_program_entries WHERE event_id = ?)")->execute([$activeEventId]);
                        } else {
                            $pdo->prepare("DELETE FROM {$table} WHERE event_id = ?")->execute([$activeEventId]);
                        }
                    } catch (Throwable $e) {
                        // Ignore
                    }
                }
            });
            admin_flash('success', 'All event configuration, teams, programs, entries, and scores were wiped successfully.');
            admin_redirect('/admin/event-manager/settings.php?tab=database');
        }

        $defaultJudgesCount = max(1, min(10, (int)($_POST['default_judges_count'] ?? 2)));
        $maxJudgesCount = max(1, min(10, (int)($_POST['max_judges_count'] ?? $defaultJudgesCount)));
        $defaultTotalMarks = max(1, min(1000, (int)($_POST['default_total_marks'] ?? 100)));
        $defaultEntriesLimit = max(1, min(1000, (int)($_POST['default_entries_limit'] ?? 10)));
        
        $activeSections = [];
        if (isset($_POST['active_sections']) && is_array($_POST['active_sections'])) {
            foreach ($_POST['active_sections'] as $ctId) {
                $activeSections[] = (int)$ctId;
            }
        }
        
        $sectionLimits = [];
        if (isset($_POST['section_limits']) && is_array($_POST['section_limits'])) {
            foreach ($_POST['section_limits'] as $classTypeId => $limits) {
                $sectionLimits[(int)$classTypeId] = [
                    'on_stage' => max(0, min(100, (int)($limits['on_stage'] ?? 0))),
                    'off_stage' => max(0, min(100, (int)($limits['off_stage'] ?? 0)))
                ];
            }
        }
        
        $firstPlacePoints = max(0, min(1000, (int)($_POST['first_place_points'] ?? 10)));
        $secondPlacePoints = max(0, min(1000, (int)($_POST['second_place_points'] ?? 7)));
        $thirdPlacePoints = max(0, min(1000, (int)($_POST['third_place_points'] ?? 5)));
        $grade85BonusPoints = max(0, min(100, (int)($_POST['grade_85_plus_bonus_points'] ?? 3)));
        $grade85Threshold = max(0, min(100, (int)($_POST['grade_85_plus_threshold'] ?? 85)));
        $tiedRankMode = isset($_POST['tied_rank_mode']) && in_array($_POST['tied_rank_mode'], ['shared_full', 'shared_split', 'shared_sequential', 'tie_breaker'], true)
            ? $_POST['tied_rank_mode']
            : ($settings['tied_rank_mode'] ?? 'shared_full');
        
        $judgePasskeys = [];
        if (isset($_POST['judge_passkeys']) && is_array($_POST['judge_passkeys'])) {
            foreach ($_POST['judge_passkeys'] as $jNo => $pin) {
                $cleanPin = trim((string)$pin);
                if ($cleanPin !== '') {
                    $judgePasskeys[(int)$jNo] = $cleanPin;
                }
            }
        }
        if (empty($judgePasskeys)) {
            $judgePasskeys = $settings['judge_passkeys'] ?? [1 => '1111', 2 => '2222', 3 => '3333', 4 => '4444', 5 => '5555'];
        }

        $settings = [
            'default_judges_count' => $defaultJudgesCount,
            'max_judges_count' => $maxJudgesCount,
            'default_total_marks' => $defaultTotalMarks,
            'default_entries_limit' => $defaultEntriesLimit,
            'first_place_points' => $firstPlacePoints,
            'second_place_points' => $secondPlacePoints,
            'third_place_points' => $thirdPlacePoints,
            'grade_85_plus_bonus_points' => $grade85BonusPoints,
            'grade_85_plus_threshold' => $grade85Threshold,
            'tied_rank_mode' => $tiedRankMode,
            'active_sections' => $activeSections,
            'section_limits' => $sectionLimits,
            'judge_passkeys' => $judgePasskeys
        ];
        
        save_musabaqa_settings($pdo, $settings);
        
        $tab = (string)($_GET['tab'] ?? 'defaults');
        admin_flash('success', 'Global Musabaqa settings updated successfully.');
        admin_redirect('/admin/event-manager/settings.php', ['tab' => $tab]);
    } catch (Throwable $e) {
        $tab = (string)($_GET['tab'] ?? 'defaults');
        admin_flash('error', $e->getMessage() ?: 'Unable to update settings.');
        admin_redirect('/admin/event-manager/settings.php', ['tab' => $tab]);
    }
}

$settings = get_musabaqa_settings($pdo);
$flash = admin_take_flash();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>


<div class="main-content">
    <div class="settings-hero">
        <div>
            <div class="settings-hero-title">
                <i class="fa-solid fa-sliders" style="color: #14b8a6;"></i>
                Musabaqa Global Settings
            </div>
            <div class="settings-hero-subtitle">
                Configure default program values, judge mark limits, and section-wise member participation caps for the active event.
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <span class="badge badge-success" style="padding: 8px 14px; font-size: 13px; border-radius: 999px;">
                <i class="fa-solid fa-circle-dot" style="margin-right: 6px; font-size: 10px;"></i> Event Active
            </span>
            <button type="submit" form="settingsForm" class="btn btn-success btn-md" id="topSaveSettingsBtn" style="background: linear-gradient(135deg, #14b8a6, #0d9488); border: none; padding: 10px 22px; font-weight: 700; font-size: 14px; box-shadow: 0 4px 14px rgba(20, 184, 166, 0.4); cursor: pointer;">
                <i class="fa-solid fa-floppy-disk mr-2"></i> Save Settings
            </button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom: 24px;">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="margin-right: 8px;"></i>
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="agy-settings-layout">
        <!-- Sub-sidebar on the left -->
        <div class="settings-sub-sidebar">
            <button type="button" class="settings-sub-tab-btn active" data-tab="defaults">
                <i class="fa-solid fa-wand-magic-sparkles"></i> <span>Defaults</span>
            </button>
            <button type="button" class="settings-sub-tab-btn" data-tab="points">
                <i class="fa-solid fa-award"></i> <span>Placement Points</span>
            </button>
            <button type="button" class="settings-sub-tab-btn" data-tab="tied-mode">
                <i class="fa-solid fa-scale-balanced"></i> <span>Tied Rank Mode</span>
            </button>
            <button type="button" class="settings-sub-tab-btn" data-tab="sections">
                <i class="fa-solid fa-graduation-cap"></i> <span>Active Sections</span>
            </button>
            <button type="button" class="settings-sub-tab-btn" data-tab="limits">
                <i class="fa-solid fa-users-gear"></i> <span>Section Limits</span>
            </button>
            <button type="button" class="settings-sub-tab-btn" data-tab="bulk-modify">
                <i class="fa-solid fa-toolbox"></i> <span>Bulk Operations</span>
            </button>
            <button type="button" class="settings-sub-tab-btn" data-tab="judge-passkeys">
                <i class="fa-solid fa-key"></i> <span>Judge Passkeys</span>
            </button>
            <button type="button" class="settings-sub-tab-btn" data-tab="database">
                <i class="fa-solid fa-database"></i> <span>Database &amp; Reset</span>
            </button>
        </div>

        <!-- Details pane on the right -->
        <div class="settings-details-pane">
            <form method="POST" id="settingsForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                
                <!-- SECTION 1: Program Defaults -->
                <div class="settings-section-block mb-6" id="sectionDefaults">
                    <div class="panel-header" style="margin-bottom: 20px;">
                        <h3 class="panel-title" style="font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-wand-magic-sparkles" style="color: #14b8a6;"></i> 
                            Program Global Defaults
                        </h3>
                        <p style="font-size: 13px; color: var(--muted); margin-top: 4px;">These values populate automatically when adding a program in Default Mode.</p>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;">
                        <!-- Card 1: Judges Count -->
                        <div class="setting-card-v2">
                            <div>
                                <div class="setting-card-icon">
                                    <i class="fa-solid fa-gavel"></i>
                                </div>
                                <strong style="font-size: 16px; color: #fff; display: block;">Default Judges Count</strong>
                                <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 4px;">Number of judge scorecards generated per program.</span>
                            </div>
                            
                            <div class="number-stepper">
                                <button type="button" class="stepper-btn btn-step-down" data-target="defaultJudgesCount"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" name="default_judges_count" id="defaultJudgesCount" value="<?= (int)$settings['default_judges_count'] ?>" min="1" max="10" required>
                                <button type="button" class="stepper-btn btn-step-up" data-target="defaultJudgesCount"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>

                        <!-- Card 2: Total Marks -->
                        <div class="setting-card-v2">
                            <div>
                                <div class="setting-card-icon">
                                    <i class="fa-solid fa-scale-balanced"></i>
                                </div>
                                <strong style="font-size: 16px; color: #fff; display: block;">Default Total Marks</strong>
                                <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 4px;">Maximum total mark score for each judge.</span>
                            </div>
                            
                            <div class="number-stepper">
                                <button type="button" class="stepper-btn btn-step-down" data-target="defaultTotalMarks" data-step="10"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" name="default_total_marks" id="defaultTotalMarks" value="<?= (int)$settings['default_total_marks'] ?>" min="1" max="1000" required>
                                <button type="button" class="stepper-btn btn-step-up" data-target="defaultTotalMarks" data-step="10"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>

                        <!-- Card 3: Entries Limit -->
                        <div class="setting-card-v2">
                            <div>
                                <div class="setting-card-icon">
                                    <i class="fa-solid fa-user-gear"></i>
                                </div>
                                <strong style="font-size: 16px; color: #fff; display: block;">Default Entries Limit per Team</strong>
                                <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 4px;">Maximum entries allowed for each team per program.</span>
                            </div>
                            
                            <div class="number-stepper">
                                <button type="button" class="stepper-btn btn-step-down" data-target="defaultEntriesLimit"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" name="default_entries_limit" id="defaultEntriesLimit" value="<?= (int)$settings['default_entries_limit'] ?>" min="1" max="1000" required>
                                <button type="button" class="stepper-btn btn-step-up" data-target="defaultEntriesLimit"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: Team Placement Points -->
                <div class="settings-section-block mb-6" id="sectionTeamPoints" style="display: none;">
                    <div class="panel-header" style="margin-bottom: 20px;">
                        <h3 class="panel-title" style="font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-award" style="color: #14b8a6;"></i> 
                            Team Placement Points
                        </h3>
                        <p style="font-size: 13px; color: var(--muted); margin-top: 4px;">Define the points awarded to the team of the 1st, 2nd, and 3rd placed students.</p>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;">
                        <!-- Card 1: First Place Points -->
                        <div class="setting-card-v2">
                            <div>
                                <div class="setting-card-icon">
                                    <i class="fa-solid fa-trophy"></i>
                                </div>
                                <strong style="font-size: 16px; color: #fff; display: block;">1st Place Points</strong>
                                <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 4px;">Points awarded to the team of the 1st placer.</span>
                            </div>
                            
                            <div class="number-stepper">
                                <button type="button" class="stepper-btn btn-step-down" data-target="firstPlacePoints"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" name="first_place_points" id="firstPlacePoints" value="<?= (int)($settings['first_place_points'] ?? 10) ?>" min="0" max="1000" required>
                                <button type="button" class="stepper-btn btn-step-up" data-target="firstPlacePoints"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>

                        <!-- Card 2: Second Place Points -->
                        <div class="setting-card-v2">
                            <div>
                                <div class="setting-card-icon">
                                    <i class="fa-solid fa-medal" style="color: #cbd5e1;"></i>
                                </div>
                                <strong style="font-size: 16px; color: #fff; display: block;">2nd Place Points</strong>
                                <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 4px;">Points awarded to the team of the 2nd placer.</span>
                            </div>
                            
                            <div class="number-stepper">
                                <button type="button" class="stepper-btn btn-step-down" data-target="secondPlacePoints"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" name="second_place_points" id="secondPlacePoints" value="<?= (int)($settings['second_place_points'] ?? 7) ?>" min="0" max="1000" required>
                                <button type="button" class="stepper-btn btn-step-up" data-target="secondPlacePoints"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>

                        <!-- Card 3: Third Place Points -->
                        <div class="setting-card-v2">
                            <div>
                                <div class="setting-card-icon">
                                    <i class="fa-solid fa-medal" style="color: #b45309;"></i>
                                </div>
                                <strong style="font-size: 16px; color: #fff; display: block;">3rd Place Points</strong>
                                <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 4px;">Points awarded to the team of the 3rd placer.</span>
                            </div>
                            
                            <div class="number-stepper">
                                <button type="button" class="stepper-btn btn-step-down" data-target="thirdPlacePoints"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" name="third_place_points" id="thirdPlacePoints" value="<?= (int)($settings['third_place_points'] ?? 5) ?>" min="0" max="1000" required>
                                <button type="button" class="stepper-btn btn-step-up" data-target="thirdPlacePoints"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>

                        <!-- Card 4: 85+ Marks Extra Bonus Points -->
                        <div class="setting-card-v2">
                            <div>
                                <div class="setting-card-icon">
                                    <i class="fa-solid fa-star" style="color: #10b981;"></i>
                                </div>
                                <strong style="font-size: 16px; color: #fff; display: block;">85+ Marks Bonus Points</strong>
                                <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 4px;">Extra points awarded to team for scoring 85+ marks in mark-based programs.</span>
                            </div>
                            
                            <div class="number-stepper">
                                <button type="button" class="stepper-btn btn-step-down" data-target="grade85PlusBonusPoints"><i class="fa-solid fa-minus"></i></button>
                                <input type="number" name="grade_85_plus_bonus_points" id="grade85PlusBonusPoints" value="<?= (int)($settings['grade_85_plus_bonus_points'] ?? 3) ?>" min="0" max="100" required>
                                <button type="button" class="stepper-btn btn-step-up" data-target="grade85PlusBonusPoints"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Tied Ranking Mode -->
                <div class="settings-section-block mb-6" id="sectionTiedMode" style="display: none;">
                    <div class="panel-header" style="margin-bottom: 20px;">
                        <h3 class="panel-title" style="font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-scale-balanced" style="color: #14b8a6;"></i> 
                            Tied Ranking Mode
                        </h3>
                        <p style="font-size: 13px; color: var(--muted); margin-top: 4px;">Choose how points are divided or sequential placements are calculated when participants tie for a rank.</p>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <?php 
                        $tiedRankMode = $settings['tied_rank_mode'] ?? 'shared_full';
                        ?>
                        <!-- Mode 1: Full Points (Shared) -->
                        <label class="tied-rank-card <?= $tiedRankMode === 'shared_full' ? 'is-active' : '' ?>" id="tiedCard_shared_full">
                            <input type="radio" name="tied_rank_mode" value="shared_full" <?= $tiedRankMode === 'shared_full' ? 'checked' : '' ?>>
                            <div class="tied-rank-card-info">
                                <span class="tied-rank-card-title">Shared Full Points (Default)</span>
                                <span class="tied-rank-card-desc">All tied participants receive the full points defined for that rank. For example, if two tie for 1st place, both receive 10 points. The next participant gets 3rd place (skipped 2nd).</span>
                            </div>
                        </label>

                        <!-- Mode 2: Split Points (Average) -->
                        <label class="tied-rank-card <?= $tiedRankMode === 'shared_split' ? 'is-active' : '' ?>" id="tiedCard_shared_split">
                            <input type="radio" name="tied_rank_mode" value="shared_split" <?= $tiedRankMode === 'shared_split' ? 'checked' : '' ?>>
                            <div class="tied-rank-card-info">
                                <span class="tied-rank-card-title">Split Ranks (Average Points)</span>
                                <span class="tied-rank-card-desc">Points of the tied positions are combined and split equally. For example, if two tie for 1st place, they share the 1st and 2nd place points, receiving average points ((10 + 7) / 2 = 8.5 points each).</span>
                            </div>
                        </label>

                        <!-- Mode 3: Sequential Ranks -->
                        <label class="tied-rank-card <?= $tiedRankMode === 'shared_sequential' ? 'is-active' : '' ?>" id="tiedCard_shared_sequential">
                            <input type="radio" name="tied_rank_mode" value="shared_sequential" <?= $tiedRankMode === 'shared_sequential' ? 'checked' : '' ?>>
                            <div class="tied-rank-card-info">
                                <span class="tied-rank-card-title">Sequential Ranks (No skips)</span>
                                <span class="tied-rank-card-desc">Tied participants receive the same rank points, and the immediately following participant gets the next sequential rank points (e.g. two tie for 1st, getting 10 points. The next person gets 2nd place, receiving 7 points).</span>
                            </div>
                        </label>

                        <!-- Mode 4: Tie Breaker (Breakdown) -->
                        <label class="tied-rank-card <?= $tiedRankMode === 'tie_breaker' ? 'is-active' : '' ?>" id="tiedCard_tie_breaker">
                            <input type="radio" name="tied_rank_mode" value="tie_breaker" <?= $tiedRankMode === 'tie_breaker' ? 'checked' : '' ?>>
                            <div class="tied-rank-card-info">
                                <span class="tied-rank-card-title">Strict Tie Breaker (Breakdown Check)</span>
                                <span class="tied-rank-card-desc">Ties are strictly resolved by checking sub-category score breakdowns or internal criteria. Points are awarded sequentially based on the final breaker sorted list.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- SECTION 4: Active Musabaqa Sections -->
                <div class="settings-section-block mb-6" id="sectionActiveSections" style="display: none;">
                    <div class="panel-header" style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <h3 class="panel-title" style="font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-graduation-cap" style="color: #14b8a6;"></i> 
                                Active Musabaqa Sections
                            </h3>
                            <p style="font-size: 13px; color: var(--muted); margin-top: 4px;">Choose which sections (class categories) are participating in this Musabaqa event.</p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="btn btn-sm btn-secondary" id="selectAllSectionsBtn">
                                <i class="fa-solid fa-check-double"></i> Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" id="deselectAllSectionsBtn">
                                <i class="fa-solid fa-xmark"></i> Deselect All
                            </button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
                        <?php 
                        $configuredActiveSections = $settings['active_sections'] ?? [];
                        $allActiveByDefault = empty($configuredActiveSections);
                        
                        foreach ($classTypes as $type): 
                            $classTypeId = (int)$type['id'];
                            $sectionName = admin_class_type_display($type['name'] ?? null, $classTypeId);
                            $isSectionActive = $allActiveByDefault || in_array($classTypeId, $configuredActiveSections, true);
                            ?>
                            <label class="section-toggle-card <?= $isSectionActive ? 'is-active' : '' ?>" id="sectionToggleCard_<?= $classTypeId ?>" style="display: flex; align-items: center; justify-content: space-between; padding: 18px 20px; background: rgba(15, 23, 42, 0.6); border: 1px solid <?= $isSectionActive ? 'rgba(20, 184, 166, 0.4)' : 'rgba(255, 255, 255, 0.08)' ?>; border-radius: 16px; cursor: pointer; transition: all 0.25s ease;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div class="section-icon-box" style="width: 40px; height: 40px; border-radius: 10px; background: <?= $isSectionActive ? 'rgba(20, 184, 166, 0.18)' : 'rgba(255, 255, 255, 0.04)' ?>; border: 1px solid <?= $isSectionActive ? 'rgba(20, 184, 166, 0.35)' : 'rgba(255, 255, 255, 0.08)' ?>; display: flex; align-items: center; justify-content: center; color: <?= $isSectionActive ? '#14b8a6' : 'var(--muted)' ?>; font-size: 18px;">
                                        <i class="fa-solid fa-graduation-cap"></i>
                                    </div>
                                    <div>
                                        <strong style="font-size: 15.5px; color: #fff; display: block;"><?= e($sectionName) ?></strong>
                                        <span style="font-size: 11.5px; color: var(--muted);">Section #<?= $classTypeId ?></span>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label class="agy-switch">
                                        <input type="checkbox" name="active_sections[]" value="<?= $classTypeId ?>" class="section-checkbox" id="secCheck_<?= $classTypeId ?>" <?= $isSectionActive ? 'checked' : '' ?>>
                                        <span class="agy-slider"></span>
                                    </label>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- SECTION 5: Member Participation Limits -->
                <div class="settings-section-block mb-6" id="sectionLimits" style="display: none;">
                    <div class="panel-header" style="margin-bottom: 20px;">
                        <h3 class="panel-title" style="font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-users-gear" style="color: #14b8a6;"></i> 
                            Section Participation Limits
                        </h3>
                        <p style="font-size: 13px; color: var(--muted); margin-top: 4px;">Enforce maximum program entry limits for individual members based on their section (Class Type).</p>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        <?php foreach ($classTypes as $type): 
                            $classTypeId = (int)$type['id'];
                            $onStage = (int)($settings['section_limits'][$classTypeId]['on_stage'] ?? 2);
                            $offStage = (int)($settings['section_limits'][$classTypeId]['off_stage'] ?? 3);
                            $sectionName = admin_class_type_display($type['name'] ?? null, $classTypeId);
                            ?>
                            <div class="section-limit-card-v2">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(20, 184, 166, 0.12); border: 1px solid rgba(20, 184, 166, 0.25); display: flex; align-items: center; justify-content: center; color: #14b8a6; font-size: 18px;">
                                            <i class="fa-solid fa-graduation-cap"></i>
                                        </div>
                                        <div>
                                            <strong style="font-size: 16px; color: #fff; display: block;"><?= e($sectionName) ?></strong>
                                            <span style="font-size: 11.5px; color: var(--muted);">Section #<?= $classTypeId ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <!-- On Stage Limit Box -->
                                    <div class="limit-badge-box on-stage">
                                        <span style="font-size: 11px; font-weight: 700; color: #14b8a6; text-transform: uppercase; letter-spacing: 0.05em;">
                                            <i class="fa-solid fa-masks-theater" style="margin-right: 4px;"></i> On-Stage
                                        </span>
                                        <div class="number-stepper" style="margin-top: 4px;">
                                            <button type="button" class="stepper-btn btn-step-down" data-target="on_stage_<?= $classTypeId ?>"><i class="fa-solid fa-minus"></i></button>
                                            <input type="number" name="section_limits[<?= $classTypeId ?>][on_stage]" id="on_stage_<?= $classTypeId ?>" value="<?= $onStage ?>" min="0" max="100" required>
                                            <button type="button" class="stepper-btn btn-step-up" data-target="on_stage_<?= $classTypeId ?>"><i class="fa-solid fa-plus"></i></button>
                                        </div>
                                    </div>

                                    <!-- Off Stage Limit Box -->
                                    <div class="limit-badge-box off-stage">
                                        <span style="font-size: 11px; font-weight: 700; color: #c084fc; text-transform: uppercase; letter-spacing: 0.05em;">
                                            <i class="fa-solid fa-pen-ruler" style="margin-right: 4px;"></i> Off-Stage
                                        </span>
                                        <div class="number-stepper" style="margin-top: 4px;">
                                            <button type="button" class="stepper-btn btn-step-down" data-target="off_stage_<?= $classTypeId ?>"><i class="fa-solid fa-minus"></i></button>
                                            <input type="number" name="section_limits[<?= $classTypeId ?>][off_stage]" id="off_stage_<?= $classTypeId ?>" value="<?= $offStage ?>" min="0" max="100" required>
                                            <button type="button" class="stepper-btn btn-step-up" data-target="off_stage_<?= $classTypeId ?>"><i class="fa-solid fa-plus"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Sticky Save Settings Bar -->
                <div class="sticky-settings-actions mb-6" id="stickySettingsBar" style="margin-top: 24px;">
                    <div>
                        <strong style="color: #fff; font-size: 15px; display: block;"><i class="fa-solid fa-sliders mr-2" style="color:#14b8a6;"></i> Save Musabaqa Settings</strong>
                        <span style="color: var(--muted); font-size: 12.5px;">Applies default program limits, place points, active sections, and section limits to active event.</span>
                    </div>
                    <button type="submit" class="btn btn-success btn-md" style="background: linear-gradient(135deg, #14b8a6, #0d9488); border: none; padding: 10px 24px; font-weight: 700; font-size: 14px; box-shadow: 0 4px 14px rgba(20, 184, 166, 0.4); cursor: pointer;">
                        <i class="fa-solid fa-floppy-disk mr-2"></i> Save Settings
                    </button>
                </div>
            </form>

            <!-- SECTION 6: Bulk Edit Programs -->
            <div class="settings-section-block mb-6" id="sectionBulkModify" style="display: none;">
                <div class="panel-header" style="margin-bottom: 20px;">
                    <h3 class="panel-title" style="font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-toolbox" style="color: #14b8a6;"></i> 
                        Bulk Edit &amp; Reset Programs
                    </h3>
                    <p style="font-size: 13px; color: var(--muted); margin-top: 4px;">Update multiple programs' settings (judges count, total marks, entry limits, team-only scoring, and place points) to defaults or custom values in a single action.</p>
                </div>
                
                <form method="POST" id="bulkModifyForm" style="display: flex; flex-direction: column; gap: 24px;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="bulk_modify_programs">

                    <!-- 1. Filters Panel -->
                    <div class="panel" style="padding: 20px; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px;">
                        <strong style="color: #14b8a6; font-size: 15px; display: block; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 8px; margin-bottom: 16px;">
                            <i class="fa-solid fa-filter mr-2"></i> Step 1: Filter Programs
                        </strong>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                            <div>
                                <label style="display: block; font-size: 13px; color: var(--muted); font-weight: 600; margin-bottom: 6px;">Filter by Section (Class Type)</label>
                                <select name="filter_section" class="form-input" style="width: 100%; height: 38px; background: rgba(0,0,0,0.25); border-radius: 6px; color: #fff; padding: 0 10px;">
                                    <option value="0">All Sections</option>
                                    <?php foreach ($classTypes as $type): ?>
                                        <option value="<?= (int)$type['id'] ?>"><?= e(admin_class_type_display($type['name'] ?? null, (int)$type['id'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 13px; color: var(--muted); font-weight: 600; margin-bottom: 6px;">Filter by Type</label>
                                <select name="filter_type" class="form-input" style="width: 100%; height: 38px; background: rgba(0,0,0,0.25); border-radius: 6px; color: #fff; padding: 0 10px;">
                                    <option value="all">All Types (Individual &amp; Group)</option>
                                    <option value="individual">Individual Programs Only</option>
                                    <option value="group">Group Programs Only</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 13px; color: var(--muted); font-weight: 600; margin-bottom: 6px;">Filter by Status</label>
                                <select name="filter_special" class="form-input" style="width: 100%; height: 38px; background: rgba(0,0,0,0.25); border-radius: 6px; color: #fff; padding: 0 10px;">
                                    <option value="all">All (Regular &amp; Special)</option>
                                    <option value="regular">Regular Programs Only</option>
                                    <option value="special">Special Programs Only</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Modifications Panel -->
                    <div class="panel" style="padding: 20px; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 8px; margin-bottom: 16px;">
                            <strong style="color: #14b8a6; font-size: 15px;">
                                <i class="fa-solid fa-pen mr-2"></i> Step 2: Apply Parameters
                            </strong>
                            <button type="button" class="btn btn-xs btn-secondary" id="btnCopyDefaults" style="padding: 6px 12px; font-size: 11.5px; border-radius: 6px;">
                                <i class="fa-solid fa-copy mr-1"></i> Load Global Defaults
                            </button>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
                            <!-- Judges Count -->
                            <div style="border: 1px solid rgba(255,255,255,0.06); padding: 14px; border-radius: 10px; background: rgba(0,0,0,0.15);">
                                <label style="display: flex; align-items: center; gap: 8px; color: #fff; font-weight: 700; font-size: 13.5px; margin-bottom: 8px; cursor: pointer;">
                                    <input type="checkbox" name="update_judges" id="chkUpdateJudges" style="width:16px; height:16px; accent-color: #14b8a6;">
                                    Update Judges Count
                                </label>
                                <input type="number" name="val_judges" id="valJudges" class="form-input" value="<?= (int)$settings['default_judges_count'] ?>" min="1" max="10" style="width: 100%; height: 38px; background: rgba(0,0,0,0.25); border-radius: 6px; color: #fff;">
                            </div>

                            <!-- Total Marks -->
                            <div style="border: 1px solid rgba(255,255,255,0.06); padding: 14px; border-radius: 10px; background: rgba(0,0,0,0.15);">
                                <label style="display: flex; align-items: center; gap: 8px; color: #fff; font-weight: 700; font-size: 13.5px; margin-bottom: 8px; cursor: pointer;">
                                    <input type="checkbox" name="update_marks" id="chkUpdateMarks" style="width:16px; height:16px; accent-color: #14b8a6;">
                                    Update Max Marks
                                </label>
                                <input type="number" name="val_marks" id="valMarks" class="form-input" value="<?= (int)$settings['default_total_marks'] ?>" min="1" max="1000" style="width: 100%; height: 38px; background: rgba(0,0,0,0.25); border-radius: 6px; color: #fff;">
                            </div>

                            <!-- Entries Limit -->
                            <div style="border: 1px solid rgba(255,255,255,0.06); padding: 14px; border-radius: 10px; background: rgba(0,0,0,0.15);">
                                <label style="display: flex; align-items: center; gap: 8px; color: #fff; font-weight: 700; font-size: 13.5px; margin-bottom: 8px; cursor: pointer;">
                                    <input type="checkbox" name="update_limit" id="chkUpdateLimit" style="width:16px; height:16px; accent-color: #14b8a6;">
                                    Update Entry Limit
                                </label>
                                <input type="number" name="val_limit" id="valLimit" class="form-input" value="<?= (int)$settings['default_entries_limit'] ?>" min="1" max="1000" style="width: 100%; height: 38px; background: rgba(0,0,0,0.25); border-radius: 6px; color: #fff;">
                            </div>

                            <!-- Only Team Marks -->
                            <div style="border: 1px solid rgba(255,255,255,0.06); padding: 14px; border-radius: 10px; background: rgba(0,0,0,0.15);">
                                <label style="display: flex; align-items: center; gap: 8px; color: #fff; font-weight: 700; font-size: 13.5px; margin-bottom: 8px; cursor: pointer;">
                                    <input type="checkbox" name="update_team_only" id="chkUpdateTeamOnly" style="width:16px; height:16px; accent-color: #14b8a6;">
                                    Update "Only Team Marks"
                                </label>
                                <select name="val_team_only" id="valTeamOnly" class="form-input" style="width: 100%; height: 38px; background: rgba(0,0,0,0.25); border-radius: 6px; color: #fff; padding: 0 10px;">
                                    <option value="0">No (Include Individual Marks)</option>
                                    <option value="1">Yes (Team Marks Only, No Individual Marks)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-bottom: 40px;">
                        <button type="submit" class="btn btn-glow-success btn-md" style="padding: 12px 32px; border-radius: 10px; font-weight: 700;" onclick="return confirm('Are you sure you want to apply these settings in bulk to all matched programs? This action cannot be undone.');">
                            <i class="fa-solid fa-circle-check mr-1"></i> Apply Settings in Bulk
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION: Judge Passkeys -->
            <div class="settings-section-block mb-6" id="sectionJudgePasskeys" style="display: none; margin-bottom: 40px;">
                <div class="panel-header" style="margin-bottom: 20px;">
                    <h3 class="panel-title" style="font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-key" style="color: #14b8a6;"></i> 
                        Judge Passkey PIN Configurations
                    </h3>
                    <p style="font-size: 13px; color: var(--muted); margin-top: 4px;">Set 4-digit security passkey PINs for each judge identity (Judge 1, Judge 2, Judge 3, etc.). Judges use these passkeys to unlock criteria mark entries on the Judges Marking Portal.</p>
                </div>

                <div class="grid grid-2 gap-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                    <?php 
                    $currentPasskeys = $settings['judge_passkeys'] ?? [1 => '1111', 2 => '2222', 3 => '3333', 4 => '4444', 5 => '5555'];
                    for ($j = 1; $j <= 5; $j++): 
                        $pinVal = $currentPasskeys[$j] ?? ($j * 1111);
                    ?>
                        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); padding: 18px; border-radius: 14px;">
                            <label class="form-label" style="display: flex; align-items: center; justify-content: space-between; font-weight: 700; color: #fff; margin-bottom: 8px;">
                                <span><i class="fa-solid fa-gavel mr-2" style="color: #14b8a6;"></i> Judge <?= $j ?> Passkey</span>
                                <span class="badge badge-neutral" style="font-size: 10px;">PIN</span>
                            </label>
                            <input type="text" name="judge_passkeys[<?= $j ?>]" value="<?= e($pinVal) ?>" class="form-control" placeholder="1111" maxlength="8" style="background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.1); color: #34d399; font-weight: 700; font-size: 18px; letter-spacing: 3px; text-align: center;">
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- SECTION 7: Database & Reset Utilities -->
            <div class="settings-section-block mb-6" id="sectionDatabase" style="display: none; margin-bottom: 40px;">
                <div class="panel-header" style="margin-bottom: 20px;">
                    <h3 class="panel-title" style="font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-database" style="color: #14b8a6;"></i> 
                        Database &amp; Maintenance Utilities
                    </h3>
                    <p style="font-size: 13px; color: var(--muted); margin-top: 4px;">Perform maintenance operations, reset program databases, or download system SQL dumps.</p>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
                    <!-- Card 1: Download SQL Backup -->
                    <div class="panel" style="padding: 24px; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 16px;">
                        <div>
                            <strong style="color: #fff; font-size: 16px; display: block; margin-bottom: 8px;">
                                <i class="fa-solid fa-file-export" style="color: #14b8a6; margin-right: 8px;"></i> Export SQL Backup
                            </strong>
                            <span style="font-size: 12.5px; color: var(--muted); display: block; line-height: 1.5;">
                                Generate and download a complete SQL database dump containing all tables, event data, teams, and scores. Keep this file as a safe recovery backup.
                            </span>
                        </div>
                        <a href="?action=backup" class="btn btn-secondary btn-md" style="width: 100%; text-align: center; font-weight: 700; border-radius: 10px; padding: 12px; background: rgba(255,255,255,0.05); color: #fff; display: block;" data-ajax-ignore>
                            <i class="fa-solid fa-download mr-1"></i> Download Backup (.sql)
                        </a>
                    </div>

                    <!-- Card 2: Reset Scores & Standings -->
                    <div class="panel" style="padding: 24px; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(239, 68, 68, 0.15); border-radius: 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 16px;">
                        <div>
                            <strong style="color: #fca5a5; font-size: 16px; display: block; margin-bottom: 8px;">
                                <i class="fa-solid fa-eraser" style="color: #ef4444; margin-right: 8px;"></i> Clear Scores Only
                            </strong>
                            <span style="font-size: 12.5px; color: var(--muted); display: block; line-height: 1.5;">
                                Deletes all entered judge scores, mark sheets, and audit approvals. All programs, teams, and registered participants are retained. <strong>Recommended before beginning scoring.</strong>
                            </span>
                        </div>
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('WARNING: This will permanently delete all entered scorecards, judges\' sheets, and standings for this active event. This action CANNOT be undone. Are you absolutely sure?');">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <input type="hidden" name="action" value="reset_scores">
                            <button type="submit" class="btn btn-danger btn-md" style="width: 100%; font-weight: 700; border-radius: 10px; padding: 12px; background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.3); color: #fca5a5; cursor: pointer;">
                                <i class="fa-solid fa-trash-can mr-1"></i> Clear Scores
                            </button>
                        </form>
                    </div>

                    <!-- Card 3: Reset Whole Event -->
                    <div class="panel" style="padding: 24px; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 16px;">
                        <div>
                            <strong style="color: #fca5a5; font-size: 16px; display: block; margin-bottom: 8px;">
                                <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444; margin-right: 8px;"></i> Reset Event Configuration
                            </strong>
                            <span style="font-size: 12.5px; color: var(--muted); display: block; line-height: 1.5;">
                                Completely deletes all programs, teams, registered members, entries, schedule blocks, and scores for this event. Wipes the canvas clean.
                            </span>
                        </div>
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('CRITICAL WARNING: This will completely WIPE all programs, teams, members, schedule blocks, and scores for the active event. It will reset the event to an empty template. There is no going back. Are you absolutely sure you want to proceed?');">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <input type="hidden" name="action" value="reset_whole_event">
                            <button type="submit" class="btn btn-danger btn-md" style="width: 100%; font-weight: 700; border-radius: 10px; padding: 12px; background: rgba(239,68,68,0.22); border-color: rgba(239,68,68,0.5); color: #ef4444; cursor: pointer;">
                                <i class="fa-solid fa-skull-crossbones mr-1"></i> Wipe Event Data
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Stepper Button Handler
    document.querySelectorAll('.number-stepper .stepper-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.target;
            const step = Number(btn.dataset.step || 1);
            const input = document.getElementById(targetId);
            if (!input) return;

            let val = Number(input.value || 0);
            const min = input.hasAttribute('min') ? Number(input.min) : 0;
            const max = input.hasAttribute('max') ? Number(input.max) : 1000;

            if (btn.classList.contains('btn-step-up')) {
                val = Math.min(max, val + step);
            } else if (btn.classList.contains('btn-step-down')) {
                val = Math.max(min, val - step);
            }

            input.value = val;
            input.dispatchEvent(new Event('change'));
        });
    });

    // Active Sections Select All / Deselect All
    const checkboxes = document.querySelectorAll('.section-checkbox');
    const updateCardVisual = (cb) => {
        const card = cb.closest('.section-toggle-card');
        const iconBox = card ? card.querySelector('.section-icon-box') : null;
        if (cb.checked) {
            if (card) {
                card.style.borderColor = 'rgba(20, 184, 166, 0.4)';
                card.classList.add('is-active');
            }
            if (iconBox) {
                iconBox.style.background = 'rgba(20, 184, 166, 0.18)';
                iconBox.style.borderColor = 'rgba(20, 184, 166, 0.35)';
                iconBox.style.color = '#14b8a6';
            }
        } else {
            if (card) {
                card.style.borderColor = 'rgba(255, 255, 255, 0.08)';
                card.classList.remove('is-active');
            }
            if (iconBox) {
                iconBox.style.background = 'rgba(255, 255, 255, 0.04)';
                iconBox.style.borderColor = 'rgba(255, 255, 255, 0.08)';
                iconBox.style.color = 'var(--muted)';
            }
        }
    };

    checkboxes.forEach(cb => {
        cb.addEventListener('change', () => updateCardVisual(cb));
    });

    const selectAllBtn = document.getElementById('selectAllSectionsBtn');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', () => {
            checkboxes.forEach(cb => {
                cb.checked = true;
                updateCardVisual(cb);
            });
        });
    }

    const deselectAllBtn = document.getElementById('deselectAllSectionsBtn');
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', () => {
            checkboxes.forEach(cb => {
                cb.checked = false;
                updateCardVisual(cb);
            });
        });
    }

    // Tied Rank Card Selection change listener
    const tiedRadios = document.querySelectorAll('input[name="tied_rank_mode"]');
    tiedRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            document.querySelectorAll('.tied-rank-card').forEach(c => c.classList.remove('is-active'));
            const card = radio.closest('.tied-rank-card');
            if (card) {
                card.classList.add('is-active');
            }
        });
    });

    // Sub-tab Navigation Handler (Antigravity Style)
    const tabBtns = document.querySelectorAll('.settings-sub-tab-btn');
    const secDefaults = document.getElementById('sectionDefaults');
    const secTeamPoints = document.getElementById('sectionTeamPoints');
    const secTiedMode = document.getElementById('sectionTiedMode');
    const secActiveSections = document.getElementById('sectionActiveSections');
    const secLimits = document.getElementById('sectionLimits');
    const secBulkModify = document.getElementById('sectionBulkModify');
    const secJudgePasskeys = document.getElementById('sectionJudgePasskeys');
    const secDatabase = document.getElementById('sectionDatabase');
    const stickyBar = document.getElementById('stickySettingsBar');
    const topSaveBtn = document.getElementById('topSaveSettingsBtn');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const tab = btn.dataset.tab;
            if (secDefaults) secDefaults.style.display = (tab === 'defaults') ? 'block' : 'none';
            if (secTeamPoints) secTeamPoints.style.display = (tab === 'points') ? 'block' : 'none';
            if (secTiedMode) secTiedMode.style.display = (tab === 'tied-mode') ? 'block' : 'none';
            if (secActiveSections) secActiveSections.style.display = (tab === 'sections') ? 'block' : 'none';
            if (secLimits) secLimits.style.display = (tab === 'limits') ? 'block' : 'none';
            if (secBulkModify) secBulkModify.style.display = (tab === 'bulk-modify') ? 'block' : 'none';
            if (secJudgePasskeys) secJudgePasskeys.style.display = (tab === 'judge-passkeys') ? 'block' : 'none';
            if (secDatabase) secDatabase.style.display = (tab === 'database') ? 'block' : 'none';

            // Dynamically update browser URL parameter without reloading
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            window.history.replaceState({ ajaxUrl: url.href }, '', url.href);

            // Hide save button toolbar for non-form utility tabs
            if (tab === 'bulk-modify' || tab === 'database') {
                if (stickyBar) stickyBar.style.display = 'none';
                if (topSaveBtn) topSaveBtn.style.display = 'none';
            } else {
                if (stickyBar) stickyBar.style.display = 'flex';
                if (topSaveBtn) topSaveBtn.style.display = 'inline-flex';
            }
        });
    });

    // Bulk edit: Pre-fill defaults
    const btnCopyDefaults = document.getElementById('btnCopyDefaults');
    if (btnCopyDefaults) {
        btnCopyDefaults.addEventListener('click', () => {
            document.getElementById('chkUpdateJudges').checked = true;
            document.getElementById('chkUpdateMarks').checked = true;
            document.getElementById('chkUpdateLimit').checked = true;

            document.getElementById('valJudges').value = '<?= (int)$settings['default_judges_count'] ?>';
            document.getElementById('valMarks').value = '<?= (int)$settings['default_total_marks'] ?>';
            document.getElementById('valLimit').value = '<?= (int)$settings['default_entries_limit'] ?>';
        });
    }

    // Direct tab select by URL hash/param
    const urlParams = new URLSearchParams(window.location.search);
    const initialTab = urlParams.get('tab') || 'defaults';
    const targetBtn = document.querySelector(`.settings-sub-tab-btn[data-tab="${initialTab}"]`);
    if (targetBtn) {
        targetBtn.click();
    }
});
</script>

<?php admin_close_page(); ?>
