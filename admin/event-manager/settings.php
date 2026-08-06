<?php
$pageTitle = 'Settings';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

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

function save_musabaqa_settings($pdo, $settings) {
    $value = json_encode($settings);
    $stmt = $pdo->prepare("
        INSERT INTO musabaqa_settings (setting_key, setting_value)
        VALUES ('global_musabaqa_settings', ?)
        ON DUPLICATE KEY UPDATE setting_value = ?
    ");
    $stmt->execute([$value, $value]);
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
        }

        $defaultJudgesCount = max(1, min(10, (int)($_POST['default_judges_count'] ?? 2)));
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
        $tiedRankMode = isset($_POST['tied_rank_mode']) && in_array($_POST['tied_rank_mode'], ['shared_full', 'shared_split', 'shared_sequential', 'tie_breaker'], true)
            ? $_POST['tied_rank_mode']
            : ($settings['tied_rank_mode'] ?? 'shared_full');
        
        $settings = [
            'default_judges_count' => $defaultJudgesCount,
            'default_total_marks' => $defaultTotalMarks,
            'default_entries_limit' => $defaultEntriesLimit,
            'first_place_points' => $firstPlacePoints,
            'second_place_points' => $secondPlacePoints,
            'third_place_points' => $thirdPlacePoints,
            'tied_rank_mode' => $tiedRankMode,
            'active_sections' => $activeSections,
            'section_limits' => $sectionLimits
        ];
        
        save_musabaqa_settings($pdo, $settings);
        
        admin_flash('success', 'Global Musabaqa settings updated successfully.');
        admin_redirect('/admin/event-manager/settings.php');
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to update settings.');
        admin_redirect('/admin/event-manager/settings.php');
    }
}

$settings = get_musabaqa_settings($pdo);
$flash = admin_take_flash();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
.settings-hero {
    background: linear-gradient(135deg, rgba(20, 184, 166, 0.15) 0%, rgba(13, 148, 136, 0.05) 100%);
    border: 1px solid rgba(20, 184, 166, 0.25);
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    backdrop-filter: blur(12px);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.settings-hero-title {
    font-size: 24px;
    font-weight: 800;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 12px;
}

.settings-hero-subtitle {
    color: var(--muted);
    font-size: 13.5px;
    margin-top: 4px;
}

.settings-nav-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    margin-bottom: 24px;
    overflow-x: auto;
    padding-bottom: 4px;
}

.settings-tab-btn {
    padding: 10px 18px;
    border-radius: 10px 10px 0 0;
    background: transparent;
    border: 1px solid transparent;
    color: var(--muted);
    font-weight: 600;
    font-size: 13.5px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}

.settings-tab-btn:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.04);
}

.settings-tab-btn.active {
    color: #14b8a6;
    background: rgba(20, 184, 166, 0.1);
    border-color: rgba(20, 184, 166, 0.3) rgba(20, 184, 166, 0.3) transparent rgba(20, 184, 166, 0.3);
}

.settings-section-block {
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 16px;
    padding: 24px;
    backdrop-filter: blur(16px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

.setting-card-v2 {
    background: rgba(30, 41, 59, 0.45);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 16px;
    transition: border-color 0.2s ease;
}

.setting-card-v2:hover {
    border-color: rgba(20, 184, 166, 0.3);
}

.setting-card-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(20, 184, 166, 0.12);
    border: 1px solid rgba(20, 184, 166, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #14b8a6;
    font-size: 18px;
    margin-bottom: 12px;
}

.number-stepper {
    display: flex;
    align-items: center;
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 10px;
    overflow: hidden;
}

.number-stepper input[type="number"] {
    width: 100%;
    text-align: center;
    background: transparent;
    border: none;
    color: #fff;
    font-weight: 700;
    font-size: 16px;
    padding: 8px 0;
    -moz-appearance: textfield;
}

.number-stepper input[type="number"]::-webkit-outer-spin-button,
.number-stepper input[type="number"]::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.stepper-btn {
    width: 38px;
    height: 38px;
    background: rgba(255, 255, 255, 0.05);
    border: none;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s ease;
    flex-shrink: 0;
}

.stepper-btn:hover {
    background: rgba(20, 184, 166, 0.25);
    color: #14b8a6;
}

.section-limit-card-v2 {
    background: rgba(30, 41, 59, 0.45);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.limit-badge-box {
    border-radius: 10px;
    padding: 12px;
    background: rgba(0, 0, 0, 0.25);
    border: 1px solid rgba(255, 255, 255, 0.05);
}

.limit-badge-box.on-stage {
    border-left: 3px solid #14b8a6;
}

.limit-badge-box.off-stage {
    border-left: 3px solid #c084fc;
}

.sticky-settings-actions {
    position: sticky;
    bottom: 20px;
    z-index: 50;
    background: rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(20, 184, 166, 0.35);
    border-radius: 16px;
    padding: 16px 24px;
    backdrop-filter: blur(16px);
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.6), 0 0 20px rgba(20, 184, 166, 0.15);
}

@media (max-width: 640px) {
    .settings-hero { padding: 20px; }
    .sticky-settings-actions { flex-direction: column; gap: 12px; align-items: stretch; text-align: center; }
}
</style>

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

    <div class="settings-nav-tabs">
        <button type="button" class="settings-tab-btn active" data-tab="all">
            <i class="fa-solid fa-layer-group"></i> All Settings
        </button>
        <button type="button" class="settings-tab-btn" data-tab="defaults">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Program Defaults
        </button>
        <button type="button" class="settings-tab-btn" data-tab="points">
            <i class="fa-solid fa-award"></i> Team Points
        </button>
        <button type="button" class="settings-tab-btn" data-tab="sections">
            <i class="fa-solid fa-graduation-cap"></i> Active Sections
        </button>
        <button type="button" class="settings-tab-btn" data-tab="limits">
            <i class="fa-solid fa-users-gear"></i> Member Section Limits
        </button>
        <button type="button" class="settings-tab-btn" data-tab="bulk-modify">
            <i class="fa-solid fa-toolbox"></i> Bulk Edit Programs
        </button>
    </div>

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

        <!-- SECTION: Team Placement Points -->
        <div class="settings-section-block mb-6" id="sectionTeamPoints">
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
            </div>
        </div>

        <!-- SECTION 2: Active Musabaqa Sections -->
        <div class="settings-section-block mb-6" id="sectionActiveSections">
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
                            <input type="checkbox" name="active_sections[]" value="<?= $classTypeId ?>" class="section-checkbox" id="secCheck_<?= $classTypeId ?>" <?= $isSectionActive ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: #14b8a6; cursor: pointer;">
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- SECTION 3: Member Participation Limits -->
        <div class="settings-section-block mb-6" id="sectionLimits">
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
        <div class="sticky-settings-actions mb-6" id="stickySettingsBar">
            <div>
                <strong style="color: #fff; font-size: 15px; display: block;"><i class="fa-solid fa-sliders mr-2" style="color:#14b8a6;"></i> Save Musabaqa Settings</strong>
                <span style="color: var(--muted); font-size: 12.5px;">Applies default program limits, place points, active sections, and section limits to active event.</span>
            </div>
            <button type="submit" class="btn btn-success btn-md" style="background: linear-gradient(135deg, #14b8a6, #0d9488); border: none; padding: 10px 24px; font-weight: 700; font-size: 14px; box-shadow: 0 4px 14px rgba(20, 184, 166, 0.4); cursor: pointer;">
                <i class="fa-solid fa-floppy-disk mr-2"></i> Save Settings
            </button>
        </div>

    </form>

    <!-- SECTION 4: Bulk Edit Programs -->
    <div class="settings-section-block mb-6" id="sectionBulkModify" style="display: none;">
        <div class="panel-header" style="margin-bottom: 20px;">
            <h3 class="panel-title" style="font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-toolbox" style="color: #14b8a6;"></i> 
                Bulk Edit & Reset Programs
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
                        <select name="filter_section" class="form-input" style="width: 100%; height: 42px; background: rgba(0,0,0,0.3); border-radius: 8px; border-color: rgba(255,255,255,0.08); color: #fff; padding: 0 10px;">
                            <option value="0">All Sections</option>
                            <?php foreach ($classTypes as $type): ?>
                                <option value="<?= (int)$type['id'] ?>"><?= e(admin_class_type_display($type['name'] ?? null, (int)$type['id'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; color: var(--muted); font-weight: 600; margin-bottom: 6px;">Filter by Program Type</label>
                        <select name="filter_type" class="form-input" style="width: 100%; height: 42px; background: rgba(0,0,0,0.3); border-radius: 8px; border-color: rgba(255,255,255,0.08); color: #fff; padding: 0 10px;">
                            <option value="all">All Types (Individual & Group)</option>
                            <option value="individual">Individual Programs only</option>
                            <option value="group">Group Programs only</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; color: var(--muted); font-weight: 600; margin-bottom: 6px;">Filter by Special Status</label>
                        <select name="filter_special" class="form-input" style="width: 100%; height: 42px; background: rgba(0,0,0,0.3); border-radius: 8px; border-color: rgba(255,255,255,0.08); color: #fff; padding: 0 10px;">
                            <option value="all">All Programs</option>
                            <option value="regular">Regular Programs only</option>
                            <option value="special">Special Programs only</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 2. Settings Panel -->
            <div class="panel" style="padding: 20px; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 8px; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
                    <strong style="color: #14b8a6; font-size: 15px;">
                        <i class="fa-solid fa-sliders mr-2"></i> Step 2: Settings to Apply
                    </strong>
                    <button type="button" class="btn btn-secondary btn-sm" id="btnCopyDefaults" style="padding: 6px 12px; font-size: 12px; border-radius: 6px;">
                        <i class="fa-solid fa-copy mr-1"></i> Pre-fill Event Defaults
                    </button>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 16px;">
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
                            Update Total Marks
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
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Stepper Button Handler
    document.querySelectorAll('.stepper-btn').forEach(btn => {
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

    // Tab Navigation Handler
    const tabBtns = document.querySelectorAll('.settings-tab-btn');
    const secDefaults = document.getElementById('sectionDefaults');
    const secTeamPoints = document.getElementById('sectionTeamPoints');
    const secActiveSections = document.getElementById('sectionActiveSections');
    const secLimits = document.getElementById('sectionLimits');
    const secBulkModify = document.getElementById('sectionBulkModify');
    const stickyBar = document.getElementById('stickySettingsBar');
    const topSaveBtn = document.getElementById('topSaveSettingsBtn');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const tab = btn.dataset.tab;
            if (secDefaults) secDefaults.style.display = (tab === 'all' || tab === 'defaults') ? 'block' : 'none';
            if (secTeamPoints) secTeamPoints.style.display = (tab === 'all' || tab === 'points') ? 'block' : 'none';
            if (secActiveSections) secActiveSections.style.display = (tab === 'all' || tab === 'sections') ? 'block' : 'none';
            if (secLimits) secLimits.style.display = (tab === 'all' || tab === 'limits') ? 'block' : 'none';
            if (secBulkModify) secBulkModify.style.display = (tab === 'bulk-modify') ? 'block' : 'none';

            if (tab === 'bulk-modify') {
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
    const initialTab = urlParams.get('tab');
    if (initialTab) {
        const targetBtn = document.querySelector(`.settings-tab-btn[data-tab="${initialTab}"]`);
        if (targetBtn) {
            targetBtn.click();
        }
    }
});
</script>

<?php admin_close_page(); ?>
