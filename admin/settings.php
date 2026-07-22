<?php
$pageTitle = 'Settings';

require_once __DIR__ . '/../includes/admin-helpers.php';
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
        admin_redirect('/admin/settings.php');
    }

    try {
        $defaultJudgesCount = max(1, min(10, (int)($_POST['default_judges_count'] ?? 2)));
        $defaultTotalMarks = max(1, min(1000, (int)($_POST['default_total_marks'] ?? 100)));
        $defaultEntriesLimit = max(1, min(1000, (int)($_POST['default_entries_limit'] ?? 10)));
        
        $sectionLimits = [];
        if (isset($_POST['section_limits']) && is_array($_POST['section_limits'])) {
            foreach ($_POST['section_limits'] as $classTypeId => $limits) {
                $sectionLimits[(int)$classTypeId] = [
                    'on_stage' => max(0, min(100, (int)($limits['on_stage'] ?? 0))),
                    'off_stage' => max(0, min(100, (int)($limits['off_stage'] ?? 0)))
                ];
            }
        }
        
        $settings = [
            'default_judges_count' => $defaultJudgesCount,
            'default_total_marks' => $defaultTotalMarks,
            'default_entries_limit' => $defaultEntriesLimit,
            'section_limits' => $sectionLimits
        ];
        
        save_musabaqa_settings($pdo, $settings);
        
        admin_flash('success', 'Global Musabaqa settings updated successfully.');
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Operation failed.');
    }
    
    admin_redirect('/admin/settings.php');
}

$settings = get_musabaqa_settings($pdo);
$flash = admin_take_flash();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* Custom Premium Settings UI */
.settings-hero {
    background: linear-gradient(135deg, rgba(20, 184, 166, 0.14) 0%, rgba(15, 23, 42, 0.88) 50%, rgba(30, 41, 59, 0.8) 100%);
    border: 1px solid rgba(20, 184, 166, 0.28);
    border-radius: 20px;
    padding: 28px 32px;
    margin-bottom: 28px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(16px);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
}

.settings-hero-title {
    font-size: 24px;
    font-weight: 800;
    color: #ffffff;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 6px;
}

.settings-hero-subtitle {
    font-size: 14px;
    color: var(--muted);
    max-width: 600px;
}

.settings-nav-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 24px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    padding-bottom: 12px;
}

.settings-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 22px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.06);
    color: var(--muted);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.settings-tab-btn:hover {
    background: rgba(255, 255, 255, 0.07);
    color: #fff;
    border-color: rgba(20, 184, 166, 0.3);
}

.settings-tab-btn.active {
    background: linear-gradient(135deg, rgba(20, 184, 166, 0.25), rgba(46, 125, 50, 0.25));
    border-color: #14b8a6;
    color: #fff;
    box-shadow: 0 4px 16px rgba(20, 184, 166, 0.25);
}

.setting-card-v2 {
    background: linear-gradient(145deg, rgba(15, 23, 42, 0.8), rgba(30, 41, 59, 0.6));
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    padding: 24px;
    position: relative;
    transition: all 0.25s ease;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.setting-card-v2:hover {
    border-color: rgba(20, 184, 166, 0.35);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35), 0 0 15px rgba(20, 184, 166, 0.15);
    transform: translateY(-2px);
}

.setting-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(20, 184, 166, 0.15), rgba(46, 125, 50, 0.15));
    border: 1px solid rgba(20, 184, 166, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #14b8a6;
    font-size: 20px;
    margin-bottom: 14px;
}

.number-stepper {
    display: flex;
    align-items: center;
    background: rgba(0, 0, 0, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 4px;
    gap: 4px;
    margin-top: 14px;
}

.number-stepper input[type="number"] {
    width: 100%;
    text-align: center;
    border: none !important;
    background: transparent !important;
    font-size: 20px;
    font-weight: 800;
    color: #fff;
    padding: 4px 0;
    min-height: auto !important;
    box-shadow: none !important;
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
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.stepper-btn:hover {
    background: #14b8a6;
    color: #042f2e;
    border-color: #14b8a6;
}

.section-limit-card-v2 {
    background: linear-gradient(145deg, rgba(15, 23, 42, 0.75), rgba(30, 41, 59, 0.55));
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    padding: 22px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    transition: all 0.25s ease;
}

.section-limit-card-v2:hover {
    border-color: rgba(20, 184, 166, 0.4);
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35), 0 0 15px rgba(20, 184, 166, 0.15);
}

.limit-badge-box {
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 14px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.limit-badge-box.on-stage {
    border-left: 3px solid #14b8a6;
}

.limit-badge-box.off-stage {
    border-left: 3px solid #a855f7;
}

.sticky-settings-actions {
    position: sticky;
    bottom: 24px;
    z-index: 90;
    margin-top: 36px;
    background: rgba(15, 23, 42, 0.9);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(20, 184, 166, 0.35);
    border-radius: 16px;
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
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
        <div>
            <span class="badge badge-success" style="padding: 8px 14px; font-size: 13px; border-radius: 999px;">
                <i class="fa-solid fa-circle-dot" style="margin-right: 6px; font-size: 10px;"></i> Event Active
            </span>
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
        <button type="button" class="settings-tab-btn" data-tab="limits">
            <i class="fa-solid fa-users-gear"></i> Member Section Limits
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
                            <i class="fa-solid fa-list-ol"></i>
                        </div>
                        <strong style="font-size: 16px; color: #fff; display: block;">Default Program Entry Limit</strong>
                        <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 4px;">Maximum total entries permitted per program.</span>
                    </div>
                    
                    <div class="number-stepper">
                        <button type="button" class="stepper-btn btn-step-down" data-target="defaultEntriesLimit"><i class="fa-solid fa-minus"></i></button>
                        <input type="number" name="default_entries_limit" id="defaultEntriesLimit" value="<?= (int)$settings['default_entries_limit'] ?>" min="1" max="1000" required>
                        <button type="button" class="stepper-btn btn-step-up" data-target="defaultEntriesLimit"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- SECTION 2: Member Participation Limits -->
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

        <!-- Sticky Floating Action Bar -->
        <div class="sticky-settings-actions">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-shield-halved" style="color: #14b8a6; font-size: 18px;"></i>
                <span style="font-size: 13.5px; color: var(--muted);">Changes are enforced across all entry submissions.</span>
            </div>
            
            <div style="display: flex; gap: 12px; align-items: center;">
                <button type="reset" class="btn btn-secondary btn-md" style="border-radius: 10px;">
                    <i class="fa-solid fa-rotate-left" style="margin-right: 6px;"></i> Reset
                </button>
                <button class="btn btn-success btn-md" type="submit" style="padding: 10px 24px; border-radius: 10px; font-weight: 700;">
                    <i class="fa-solid fa-floppy-disk" style="margin-right: 8px;"></i> Save All Settings
                </button>
            </div>
        </div>
    </form>
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

    // Tab Navigation Handler
    const tabBtns = document.querySelectorAll('.settings-tab-btn');
    const secDefaults = document.getElementById('sectionDefaults');
    const secLimits = document.getElementById('sectionLimits');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const tab = btn.dataset.tab;
            if (tab === 'all') {
                secDefaults.style.display = 'block';
                secLimits.style.display = 'block';
            } else if (tab === 'defaults') {
                secDefaults.style.display = 'block';
                secLimits.style.display = 'none';
            } else if (tab === 'limits') {
                secDefaults.style.display = 'none';
                secLimits.style.display = 'block';
            }
        });
    });
});
</script>

<?php admin_close_page(); ?>
