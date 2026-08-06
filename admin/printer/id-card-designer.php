<?php
$pageTitle = 'ID Card Designer & Customizer';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/id-card-helpers.php';
require_login();
$_SESSION['active_workspace'] = 'printer';

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// Fetch all teams for team selector
$stmt = $pdo->prepare('SELECT id, team_name, team_color FROM musabaqa_teams WHERE event_id = ? ORDER BY team_name ASC');
$stmt->execute([$activeEventId]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<style>
.designer-grid {
    display: grid;
    grid-template-columns: 320px 1fr 340px;
    gap: 20px;
    align-items: start;
}
@media (max-width: 1200px) {
    .designer-grid {
        grid-template-columns: 1fr;
    }
}
.designer-card {
    background: var(--surface-card, rgba(15, 23, 42, 0.6));
    border: 1px solid var(--border-color, rgba(255, 255, 255, 0.1));
    border-radius: 14px;
    padding: 20px;
    backdrop-filter: blur(12px);
}
.canvas-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 30px;
    background: rgba(0, 0, 0, 0.3);
    border: 1px dashed rgba(255, 255, 255, 0.2);
    border-radius: 16px;
    position: relative;
    min-height: 600px;
}
.id-card-canvas {
    position: relative;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    overflow: hidden;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    user-select: none;
    transition: transform 0.2s ease, width 0.2s ease, height 0.2s ease;
}
.card-element {
    position: absolute;
    cursor: move;
    border: 1.5px dashed transparent;
    padding: 2px 6px;
    border-radius: 4px;
    transition: border-color 0.15s ease, background-color 0.15s ease;
    white-space: nowrap;
    box-sizing: border-box;
}
.card-element:hover {
    border-color: rgba(59, 130, 246, 0.6);
    background: rgba(59, 130, 246, 0.1);
}
.card-element.active {
    border-color: #3b82f6 !important;
    background: rgba(59, 130, 246, 0.2) !important;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.4);
    z-index: 100 !important;
}
.card-element.hidden-element {
    opacity: 0.35;
    filter: grayscale(1);
}
.element-badge {
    position: absolute;
    top: -18px;
    left: 0;
    font-size: 9.5px;
    background: #3b82f6;
    color: #fff;
    padding: 1px 5px;
    border-radius: 3px;
    font-weight: 600;
    pointer-events: none;
    display: none;
}
.card-element.active .element-badge {
    display: block;
}
.form-group-sm {
    margin-bottom: 12px;
}
.form-group-sm label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted, #94a3b8);
    margin-bottom: 4px;
}
.form-control-sm {
    width: 100%;
    padding: 6px 10px;
    font-size: 13px;
    background: rgba(15, 23, 42, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 6px;
    color: #fff;
}
.form-control-sm:focus {
    border-color: #3b82f6;
    outline: none;
}
.element-selector-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
    max-height: 240px;
    overflow-y: auto;
}
.element-item-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    color: #e2e8f0;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.element-item-btn:hover {
    background: rgba(59, 130, 246, 0.15);
    border-color: rgba(59, 130, 246, 0.3);
}
.element-item-btn.active {
    background: rgba(59, 130, 246, 0.25);
    border-color: #3b82f6;
    color: #fff;
    font-weight: 600;
}
.dropzone {
    border: 2px dashed rgba(59, 130, 246, 0.4);
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    background: rgba(59, 130, 246, 0.05);
    transition: all 0.2s ease;
}
.dropzone:hover {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.12);
}
</style>

<div class="main-content">
    <!-- Topbar -->
    <div class="topbar">
        <div>
            <div class="page-title"><i class="fa-solid fa-wand-magic-sparkles text-primary mr-2"></i> ID Card Layout Designer</div>
            <div class="page-subtitle"><?= e($activeEvent['title']) ?> — Drag components & upload custom card backgrounds</div>
        </div>
        <div class="flex gap-2 flex-wrap align-items-center">
            <a href="<?= app_url('/admin/printer/id-cards-search.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-id-card"></i> Card Manager
            </a>
            <a href="<?= app_url('/admin/printer/id-cards.php') ?>" target="_blank" class="btn btn-success btn-md">
                <i class="fa-solid fa-print"></i> Print Cards View
            </a>
        </div>
    </div>

    <!-- Toolbar: Team Scope Switcher & Action buttons -->
    <div class="designer-card mb-4">
        <div class="flex-between flex-wrap gap-3 align-items-center">
            <div class="flex align-items-center gap-3">
                <label style="font-weight: 700; font-size: 14px; color: #cbd5e1;" class="m-0">
                    <i class="fa-solid fa-layer-group text-accent mr-1"></i> Target Template:
                </label>
                <select id="teamScopeSelect" class="form-control-sm" style="width: 260px; font-weight: 600;">
                    <option value="">🌟 Event Default (All Teams Baseline)</option>
                    <optgroup label="Team-Specific Color Matched Cards">
                        <?php foreach ($teams as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" data-color="<?= e($t['team_color']) ?>">
                                🎨 <?= e($t['team_name']) ?> Template
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <span id="scopeBadge" class="badge badge-info" style="font-size: 12px;">Event Default</span>
            </div>

            <div class="flex gap-2">
                <button type="button" id="resetLayoutBtn" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-rotate-left mr-1"></i> Reset Baseline
                </button>
                <button type="button" id="saveLayoutBtn" class="btn btn-primary btn-md">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Layout & Background
                </button>
            </div>
        </div>
    </div>

    <!-- Main Designer 3-Column Grid -->
    <div class="designer-grid">

        <!-- Column 1: Background Upload & Canvas Properties -->
        <div class="designer-card">
            <h3 class="mb-3" style="font-size: 15px;"><i class="fa-solid fa-image text-accent mr-2"></i> Card Background</h3>
            
            <div class="dropzone mb-3" id="dropzoneBox">
                <i class="fa-solid fa-cloud-arrow-up fa-2x text-primary mb-2"></i>
                <div style="font-size: 13px; font-weight: 600;">Upload Raw Card Image</div>
                <div style="font-size: 11px; color: #94a3b8;" class="mt-1">PNG, JPG, WEBP or SVG</div>
                <input type="file" id="bgFileInput" accept="image/*" style="display: none;">
            </div>

            <div id="currentBgPreview" class="mb-3" style="display: none;">
                <div style="font-size: 11.5px; color: #94a3b8; margin-bottom: 4px;">Active Background:</div>
                <div style="display: flex; align-items: center; justify-content: space-between; background: rgba(0,0,0,0.3); padding: 8px 12px; border-radius: 8px;">
                    <span id="bgFileName" style="font-size: 12px; font-weight: 600; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; max-width: 180px;"></span>
                    <button type="button" id="removeBgBtn" class="btn btn-danger btn-xs" title="Remove background"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>

            <hr style="border-color: rgba(255,255,255,0.08); margin: 20px 0;">

            <h3 class="mb-3" style="font-size: 15px;"><i class="fa-solid fa-ruler-combined text-accent mr-2"></i> Canvas Settings</h3>
            
            <div class="form-group-sm">
                <label>Orientation</label>
                <select id="cardOrientation" class="form-control-sm">
                    <option value="portrait">Portrait (Vertical)</option>
                    <option value="landscape">Landscape (Horizontal)</option>
                </select>
            </div>

            <div class="grid grid-2 gap-2">
                <div class="form-group-sm">
                    <label>Canvas Width (px)</label>
                    <input type="number" id="cardWidthInput" class="form-control-sm" value="600" min="300" max="2000">
                </div>
                <div class="form-group-sm">
                    <label>Canvas Height (px)</label>
                    <input type="number" id="cardHeightInput" class="form-control-sm" value="950" min="300" max="2000">
                </div>
            </div>

            <div class="form-group-sm mt-3">
                <label>Sample Student Preview</label>
                <select id="sampleStudentSelect" class="form-control-sm">
                    <option value="1">Ahmed Mohammed (#101 - Senior A)</option>
                    <option value="2">Fatima Zahra (#205 - Junior B)</option>
                    <option value="3">Abdul Rahman (#312 - Sub Junior)</option>
                </select>
            </div>
        </div>

        <!-- Column 2: Live Drag & Drop Canvas -->
        <div class="designer-card flex-center flex-column">
            <div style="width: 100%; display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 12px; font-weight: 600; color: #94a3b8;">
                    <i class="fa-solid fa-hand-pointer mr-1"></i> Click element on card or drag to move
                </span>
                <span id="canvasDimensionsTag" class="badge badge-neutral" style="font-size: 11px;">600 x 950 px</span>
            </div>

            <div class="canvas-wrapper" id="canvasWrapper">
                <div id="idCardCanvas" class="id-card-canvas" style="width: 400px; height: 633px;">
                    <!-- Elements will be dynamically rendered here -->
                </div>
            </div>
        </div>

        <!-- Column 3: Component Inspector & Styling -->
        <div class="designer-card">
            <h3 class="mb-3" style="font-size: 15px;"><i class="fa-solid fa-list-check text-accent mr-2"></i> Card Components</h3>
            
            <div class="element-selector-list" id="elementSelectorList">
                <!-- Dynamically populated element buttons -->
            </div>

            <div id="inspectorPanel" style="background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 14px;">
                <div class="flex-between align-items-center mb-3">
                    <strong id="inspectorTitle" style="font-size: 14px; color: #3b82f6;">Select Component</strong>
                    <label class="flex align-items-center gap-1 cursor-pointer" style="font-size: 12px; color: #e2e8f0;">
                        <input type="checkbox" id="propVisible" checked> Visible
                    </label>
                </div>

                <div class="grid grid-2 gap-2">
                    <div class="form-group-sm">
                        <label>Position X (%)</label>
                        <input type="number" id="propX" class="form-control-sm" min="0" max="100" step="0.5">
                    </div>
                    <div class="form-group-sm">
                        <label>Position Y (%)</label>
                        <input type="number" id="propY" class="form-control-sm" min="0" max="100" step="0.5">
                    </div>
                </div>

                <div class="grid grid-2 gap-2 text-props">
                    <div class="form-group-sm">
                        <label>Font Size (px)</label>
                        <input type="number" id="propFontSize" class="form-control-sm" min="8" max="120">
                    </div>
                    <div class="form-group-sm">
                        <label>Font Weight</label>
                        <select id="propFontWeight" class="form-control-sm">
                            <option value="400">Normal (400)</option>
                            <option value="500">Medium (500)</option>
                            <option value="600">SemiBold (600)</option>
                            <option value="700">Bold (700)</option>
                            <option value="800">Extra Bold (800)</option>
                            <option value="900">Black (900)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-2 gap-2 text-props">
                    <div class="form-group-sm">
                        <label>Text Align</label>
                        <select id="propAlign" class="form-control-sm">
                            <option value="center">Center</option>
                            <option value="left">Left</option>
                            <option value="right">Right</option>
                        </select>
                    </div>
                    <div class="form-group-sm">
                        <label>Text Transform</label>
                        <select id="propTextTransform" class="form-control-sm">
                            <option value="none">None</option>
                            <option value="capitalize">Capitalize</option>
                            <option value="uppercase">UPPERCASE</option>
                        </select>
                    </div>
                </div>

                <div class="form-group-sm text-props">
                    <label>Text Color</label>
                    <div class="flex gap-2 align-items-center">
                        <input type="color" id="propColorPicker" class="form-control-sm" style="width: 44px; height: 32px; padding: 2px; cursor: pointer;">
                        <input type="text" id="propColorText" class="form-control-sm" placeholder="#000000" style="flex: 1;">
                        <label class="flex align-items-center gap-1 cursor-pointer" style="font-size: 11px; white-space: nowrap;">
                            <input type="checkbox" id="propUseTeamColor"> Match Team Color
                        </label>
                    </div>
                </div>

                <div class="form-group-sm text-props" id="prefixGroup">
                    <label>Prefix / Label Text</label>
                    <input type="text" id="propPrefix" class="form-control-sm" placeholder="e.g. # or Section: ">
                </div>

                <!-- Photo Specific Properties -->
                <div id="photoProps" style="display: none;">
                    <div class="grid grid-2 gap-2">
                        <div class="form-group-sm">
                            <label>Width (px)</label>
                            <input type="number" id="propPhotoWidth" class="form-control-sm" min="30" max="400">
                        </div>
                        <div class="form-group-sm">
                            <label>Height (px)</label>
                            <input type="number" id="propPhotoHeight" class="form-control-sm" min="30" max="400">
                        </div>
                    </div>
                    <div class="grid grid-2 gap-2">
                        <div class="form-group-sm">
                            <label>Border Radius (px)</label>
                            <input type="number" id="propPhotoRadius" class="form-control-sm" min="0" max="200">
                        </div>
                        <div class="form-group-sm">
                            <label>Border Width (px)</label>
                            <input type="number" id="propPhotoBorderWidth" class="form-control-sm" min="0" max="20">
                        </div>
                    </div>
                    <div class="form-group-sm">
                        <label>Border Color</label>
                        <input type="color" id="propPhotoBorderColor" class="form-control-sm" style="width: 100%; height: 32px; padding: 2px; cursor: pointer;">
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = '<?= generate_csrf_token() ?>';
    const apiUrl = '<?= app_url('/admin/printer/id-card-designer-api.php') ?>';

    const elementLabels = {
        'student_photo': 'Student Photo / Avatar',
        'chest_number': 'Chest Number',
        'display_name': 'Student Name (English)',
        'name_arabic': 'Student Name (Arabic)',
        'team_name': 'Team Name'
    };

    const sampleStudents = {
        '1': { chest_number: '101', display_name: 'Ahmed Mohammed', name_arabic: 'أحمد محمد', team_name: 'Red Falcons', team_color: '#ef4444' },
        '2': { chest_number: '205', display_name: 'Fatima Zahra', name_arabic: 'فاطمة الزهراء', team_name: 'Blue Warriors', team_color: '#3b82f6' },
        '3': { chest_number: '312', display_name: 'Abdul Rahman', name_arabic: 'عبد الرحمن', team_name: 'Green Knights', team_color: '#10b981' }
    };

    let activeTeamId = null;
    let currentTemplate = null;
    let activeElementKey = 'display_name';
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };

    const teamScopeSelect = document.getElementById('teamScopeSelect');
    const scopeBadge = document.getElementById('scopeBadge');
    const idCardCanvas = document.getElementById('idCardCanvas');
    const elementSelectorList = document.getElementById('elementSelectorList');
    const bgFileInput = document.getElementById('bgFileInput');
    const dropzoneBox = document.getElementById('dropzoneBox');
    const currentBgPreview = document.getElementById('currentBgPreview');
    const bgFileName = document.getElementById('bgFileName');
    const removeBgBtn = document.getElementById('removeBgBtn');

    // Controls
    const cardOrientation = document.getElementById('cardOrientation');
    const cardWidthInput = document.getElementById('cardWidthInput');
    const cardHeightInput = document.getElementById('cardHeightInput');
    const sampleStudentSelect = document.getElementById('sampleStudentSelect');
    const canvasDimensionsTag = document.getElementById('canvasDimensionsTag');

    // Inspector
    const inspectorTitle = document.getElementById('inspectorTitle');
    const propVisible = document.getElementById('propVisible');
    const propX = document.getElementById('propX');
    const propY = document.getElementById('propY');
    const propFontSize = document.getElementById('propFontSize');
    const propFontWeight = document.getElementById('propFontWeight');
    const propAlign = document.getElementById('propAlign');
    const propTextTransform = document.getElementById('propTextTransform');
    const propColorPicker = document.getElementById('propColorPicker');
    const propColorText = document.getElementById('propColorText');
    const propUseTeamColor = document.getElementById('propUseTeamColor');
    const propPrefix = document.getElementById('propPrefix');
    const photoProps = document.getElementById('photoProps');
    const propPhotoWidth = document.getElementById('propPhotoWidth');
    const propPhotoHeight = document.getElementById('propPhotoHeight');
    const propPhotoRadius = document.getElementById('propPhotoRadius');
    const propPhotoBorderWidth = document.getElementById('propPhotoBorderWidth');
    const propPhotoBorderColor = document.getElementById('propPhotoBorderColor');

    // Action buttons
    const saveLayoutBtn = document.getElementById('saveLayoutBtn');
    const resetLayoutBtn = document.getElementById('resetLayoutBtn');

    // Load template data from API
    function loadTemplate(teamId) {
        activeTeamId = teamId;
        const selectedOpt = teamScopeSelect.querySelector(`option[value="${teamId || ''}"]`);
        scopeBadge.textContent = teamId ? selectedOpt.textContent.trim() : 'Event Default';
        scopeBadge.className = teamId ? 'badge badge-warning' : 'badge badge-info';

        fetch(`${apiUrl}?action=get_template&team_id=${teamId || ''}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    currentTemplate = data.template;
                    renderCanvasDimensions();
                    renderBackground();
                    renderElementButtons();
                    renderCanvasElements();
                    selectElement(activeElementKey);
                } else {
                    alert('Failed to load template: ' + data.message);
                }
            })
            .catch(err => console.error(err));
    }

    function getSelectedSample() {
        const key = sampleStudentSelect.value || '1';
        return sampleStudents[key] || sampleStudents['1'];
    }

    function renderCanvasDimensions() {
        if (!currentTemplate) return;
        const isLandscape = (currentTemplate.orientation || 'portrait') === 'landscape';
        cardOrientation.value = currentTemplate.orientation || 'portrait';

        const baseWidth = parseInt(currentTemplate.card_width || (isLandscape ? 950 : 600));
        const baseHeight = parseInt(currentTemplate.card_height || (isLandscape ? 600 : 950));

        cardWidthInput.value = baseWidth;
        cardHeightInput.value = baseHeight;

        // Scale canvas down visually to fit screen (~400px width for portrait)
        const displayWidth = isLandscape ? 580 : 380;
        const displayHeight = Math.round(displayWidth * (baseHeight / baseWidth));

        idCardCanvas.style.width = displayWidth + 'px';
        idCardCanvas.style.height = displayHeight + 'px';
        canvasDimensionsTag.textContent = `${baseWidth} x ${baseHeight} px (${cardOrientation.value})`;
    }

    function renderBackground() {
        if (currentTemplate && currentTemplate.background_image) {
            const bgUrl = '<?= app_url('/') ?>' + currentTemplate.background_image.replace(/^\//, '');
            idCardCanvas.style.backgroundImage = `url("${bgUrl}")`;
            currentBgPreview.style.display = 'block';
            bgFileName.textContent = currentTemplate.background_image.split('/').pop();
        } else {
            idCardCanvas.style.backgroundImage = 'none';
            idCardCanvas.style.backgroundColor = '#1e293b';
            currentBgPreview.style.display = 'none';
        }
    }

    function renderElementButtons() {
        elementSelectorList.innerHTML = '';
        const cfg = currentTemplate.layout_config || {};

        Object.keys(elementLabels).forEach(key => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `element-item-btn ${key === activeElementKey ? 'active' : ''}`;
            const itemConf = cfg[key] || {};

            btn.innerHTML = `
                <span>${elementLabels[key]}</span>
                <i class="fa-solid ${itemConf.visible !== false ? 'fa-eye text-primary' : 'fa-eye-slash text-muted'}"></i>
            `;
            btn.addEventListener('click', () => selectElement(key));
            elementSelectorList.appendChild(btn);
        });
    }

    function renderCanvasElements() {
        idCardCanvas.innerHTML = '';
        const cfg = currentTemplate.layout_config || {};
        const sample = getSelectedSample();
        const activeTeamOpt = teamScopeSelect.querySelector(`option[value="${activeTeamId || ''}"]`);
        const teamColor = sample.team_color;

        Object.keys(elementLabels).forEach(key => {
            const elConf = cfg[key] || {};
            if (elConf.visible === false && key !== activeElementKey) {
                return; // Hide invisible items unless active
            }

            const elBox = document.createElement('div');
            elBox.className = `card-element ${key === activeElementKey ? 'active' : ''} ${elConf.visible === false ? 'hidden-element' : ''}`;
            elBox.dataset.key = key;

            // Position percentages
            const posX = parseFloat(elConf.x ?? 50);
            const posY = parseFloat(elConf.y ?? 50);

            elBox.style.left = posX + '%';
            elBox.style.top = posY + '%';

            // Alignment transform offset
            const align = elConf.align || 'center';
            if (align === 'center') {
                elBox.style.transform = 'translate(-50%, -50%)';
            } else if (align === 'right') {
                elBox.style.transform = 'translate(-100%, -50%)';
            } else {
                elBox.style.transform = 'translate(0, -50%)';
            }

            if (key === 'student_photo') {
                const w = parseInt(elConf.width || 120);
                const h = parseInt(elConf.height || 120);
                const r = parseInt(elConf.border_radius || 60);
                const bw = parseInt(elConf.border_width || 3);
                const bc = elConf.border_color || '#ffffff';

                // Scaled photo for canvas view
                const scale = parseFloat(idCardCanvas.style.width) / parseInt(cardWidthInput.value || 600);
                const scaledW = Math.round(w * scale);
                const scaledH = Math.round(h * scale);

                elBox.style.width = scaledW + 'px';
                elBox.style.height = scaledH + 'px';
                elBox.style.borderRadius = Math.round(r * scale) + 'px';
                elBox.style.border = `${Math.max(1, Math.round(bw * scale))}px solid ${bc}`;
                elBox.style.background = `url("https://ui-avatars.com/api/?name=${encodeURIComponent(sample.display_name)}&background=3b82f6&color=fff") center/cover`;
            } else {
                // Text Element
                const fontSize = parseInt(elConf.font_size || 18);
                const scale = parseFloat(idCardCanvas.style.width) / parseInt(cardWidthInput.value || 600);
                const scaledFont = Math.round(fontSize * scale);

                elBox.style.fontSize = Math.max(10, scaledFont) + 'px';
                elBox.style.fontWeight = elConf.font_weight || '600';
                elBox.style.textAlign = align;
                elBox.style.textTransform = elConf.text_transform || 'none';

                let color = elConf.color || '#000000';
                if (color === 'auto' || elConf.use_team_color) {
                    color = teamColor;
                }
                elBox.style.color = color;

                const prefix = elConf.prefix || elConf.label || '';
                let val = sample[key] || elementLabels[key];
                if (key === 'chest_number') val = (prefix || '#') + val;
                else if (prefix) val = prefix + val;

                elBox.textContent = val;
            }

            // Element Badge tag on hover/active
            const badge = document.createElement('span');
            badge.className = 'element-badge';
            badge.textContent = elementLabels[key];
            elBox.appendChild(badge);

            // Drag listeners
            elBox.addEventListener('mousedown', startDrag);
            elBox.addEventListener('click', (e) => {
                e.stopPropagation();
                selectElement(key);
            });

            idCardCanvas.appendChild(elBox);
        });
    }

    function selectElement(key) {
        activeElementKey = key;
        inspectorTitle.textContent = elementLabels[key] || key;

        const cfg = (currentTemplate && currentTemplate.layout_config && currentTemplate.layout_config[key]) || {};

        propVisible.checked = cfg.visible !== false;
        propX.value = parseFloat(cfg.x ?? 50);
        propY.value = parseFloat(cfg.y ?? 50);

        if (key === 'student_photo') {
            document.querySelectorAll('.text-props').forEach(el => el.style.display = 'none');
            photoProps.style.display = 'block';

            propPhotoWidth.value = parseInt(cfg.width || 120);
            propPhotoHeight.value = parseInt(cfg.height || 120);
            propPhotoRadius.value = parseInt(cfg.border_radius || 60);
            propPhotoBorderWidth.value = parseInt(cfg.border_width || 3);
            propPhotoBorderColor.value = cfg.border_color || '#ffffff';
        } else {
            document.querySelectorAll('.text-props').forEach(el => el.style.display = 'block');
            photoProps.style.display = 'none';

            propFontSize.value = parseInt(cfg.font_size || 18);
            propFontWeight.value = cfg.font_weight || '600';
            propAlign.value = cfg.align || 'center';
            propTextTransform.value = cfg.text_transform || 'none';

            const useTeam = cfg.color === 'auto' || cfg.use_team_color;
            propUseTeamColor.checked = useTeam;

            const hexColor = (cfg.color && cfg.color !== 'auto') ? cfg.color : '#000000';
            propColorPicker.value = hexColor;
            propColorText.value = hexColor;
            propColorPicker.disabled = useTeam;
            propColorText.disabled = useTeam;

            propPrefix.value = cfg.prefix || cfg.label || '';
        }

        renderElementButtons();
        renderCanvasElements();
    }

    // Update inspector properties live back into template layout config
    function updateActiveElementConfig() {
        if (!currentTemplate || !currentTemplate.layout_config) return;
        if (!currentTemplate.layout_config[activeElementKey]) {
            currentTemplate.layout_config[activeElementKey] = {};
        }

        const cfg = currentTemplate.layout_config[activeElementKey];
        cfg.visible = propVisible.checked;
        cfg.x = parseFloat(propX.value || 50);
        cfg.y = parseFloat(propY.value || 50);

        if (activeElementKey === 'student_photo') {
            cfg.width = parseInt(propPhotoWidth.value || 120);
            cfg.height = parseInt(propPhotoHeight.value || 120);
            cfg.border_radius = parseInt(propPhotoRadius.value || 60);
            cfg.border_width = parseInt(propPhotoBorderWidth.value || 3);
            cfg.border_color = propPhotoBorderColor.value;
        } else {
            cfg.font_size = parseInt(propFontSize.value || 18);
            cfg.font_weight = propFontWeight.value;
            cfg.align = propAlign.value;
            cfg.text_transform = propTextTransform.value;

            if (propUseTeamColor.checked) {
                cfg.color = 'auto';
                cfg.use_team_color = true;
            } else {
                cfg.color = propColorText.value || '#000000';
                cfg.use_team_color = false;
            }

            if (activeElementKey === 'chest_number') {
                cfg.prefix = propPrefix.value;
            } else {
                cfg.label = propPrefix.value;
            }
        }

        renderCanvasElements();
    }

    // Dragging elements on canvas
    function startDrag(e) {
        const key = e.currentTarget.dataset.key;
        selectElement(key);

        isDragging = true;
        const rect = idCardCanvas.getBoundingClientRect();
        dragOffset = {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };

        document.addEventListener('mousemove', onDrag);
        document.addEventListener('mouseup', stopDrag);
    }

    function onDrag(e) {
        if (!isDragging) return;
        const rect = idCardCanvas.getBoundingClientRect();
        let mouseX = e.clientX - rect.left;
        let mouseY = e.clientY - rect.top;

        mouseX = Math.max(0, Math.min(rect.width, mouseX));
        mouseY = Math.max(0, Math.min(rect.height, mouseY));

        const pctX = Math.round((mouseX / rect.width) * 1000) / 10;
        const pctY = Math.round((mouseY / rect.height) * 1000) / 10;

        propX.value = pctX;
        propY.value = pctY;
        updateActiveElementConfig();
    }

    function stopDrag() {
        isDragging = false;
        document.removeEventListener('mousemove', onDrag);
        document.removeEventListener('mouseup', stopDrag);
    }

    // Event listeners
    teamScopeSelect.addEventListener('change', () => {
        loadTemplate(teamScopeSelect.value ? parseInt(teamScopeSelect.value) : null);
    });

    sampleStudentSelect.addEventListener('change', renderCanvasElements);

    cardOrientation.addEventListener('change', () => {
        if (!currentTemplate) return;
        currentTemplate.orientation = cardOrientation.value;
        if (cardOrientation.value === 'landscape') {
            currentTemplate.card_width = 950;
            currentTemplate.card_height = 600;
        } else {
            currentTemplate.card_width = 600;
            currentTemplate.card_height = 950;
        }
        renderCanvasDimensions();
        renderCanvasElements();
    });

    cardWidthInput.addEventListener('change', () => {
        if (currentTemplate) {
            currentTemplate.card_width = parseInt(cardWidthInput.value);
            renderCanvasDimensions();
            renderCanvasElements();
        }
    });

    cardHeightInput.addEventListener('change', () => {
        if (currentTemplate) {
            currentTemplate.card_height = parseInt(cardHeightInput.value);
            renderCanvasDimensions();
            renderCanvasElements();
        }
    });

    // Property input listeners
    [propVisible, propX, propY, propFontSize, propFontWeight, propAlign, propTextTransform, propPrefix, propPhotoWidth, propPhotoHeight, propPhotoRadius, propPhotoBorderWidth, propPhotoBorderColor].forEach(input => {
        input.addEventListener('input', updateActiveElementConfig);
        input.addEventListener('change', updateActiveElementConfig);
    });

    propColorPicker.addEventListener('input', () => {
        propColorText.value = propColorPicker.value;
        updateActiveElementConfig();
    });
    propColorText.addEventListener('input', () => {
        if (/^#[0-9A-F]{6}$/i.test(propColorText.value)) {
            propColorPicker.value = propColorText.value;
        }
        updateActiveElementConfig();
    });
    propUseTeamColor.addEventListener('change', () => {
        propColorPicker.disabled = propUseTeamColor.checked;
        propColorText.disabled = propUseTeamColor.checked;
        updateActiveElementConfig();
    });

    // Background Image Upload Dropzone
    dropzoneBox.addEventListener('click', () => bgFileInput.click());
    bgFileInput.addEventListener('change', function () {
        if (!bgFileInput.files.length) return;

        const formData = new FormData();
        formData.append('action', 'upload_background');
        formData.append('csrf_token', csrfToken);
        if (activeTeamId) formData.append('team_id', activeTeamId);
        formData.append('background', bgFileInput.files[0]);

        saveLayoutBtn.disabled = true;
        saveLayoutBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Uploading...';

        fetch(apiUrl, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                saveLayoutBtn.disabled = false;
                saveLayoutBtn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i> Save Layout & Background';

                if (data.success) {
                    currentTemplate.background_image = data.background_path;
                    renderBackground();
                    alert('Background image uploaded successfully!');
                } else {
                    alert('Upload failed: ' + data.message);
                }
            })
            .catch(err => {
                saveLayoutBtn.disabled = false;
                saveLayoutBtn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i> Save Layout & Background';
                alert('Error uploading file.');
            });
    });

    removeBgBtn.addEventListener('click', () => {
        if (currentTemplate) {
            currentTemplate.background_image = null;
            renderBackground();
        }
    });

    // Save All Layout & Background
    saveLayoutBtn.addEventListener('click', () => {
        if (!currentTemplate) return;

        const formData = new FormData();
        formData.append('action', 'save_layout');
        formData.append('csrf_token', csrfToken);
        if (activeTeamId) formData.append('team_id', activeTeamId);
        formData.append('orientation', currentTemplate.orientation || 'portrait');
        formData.append('card_width', currentTemplate.card_width || 600);
        formData.append('card_height', currentTemplate.card_height || 950);
        formData.append('background_path', currentTemplate.background_image || '');
        formData.append('layout_config', JSON.stringify(currentTemplate.layout_config || {}));

        saveLayoutBtn.disabled = true;
        saveLayoutBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';

        fetch(apiUrl, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                saveLayoutBtn.disabled = false;
                saveLayoutBtn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i> Save Layout & Background';

                if (data.success) {
                    alert(data.message);
                } else {
                    alert('Save failed: ' + data.message);
                }
            })
            .catch(err => {
                saveLayoutBtn.disabled = false;
                saveLayoutBtn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i> Save Layout & Background';
                alert('Error saving layout.');
            });
    });

    // Reset Baseline Layout
    resetLayoutBtn.addEventListener('click', () => {
        if (!confirm('Are you sure you want to reset component positions to baseline defaults?')) return;

        const formData = new FormData();
        formData.append('action', 'reset_layout');
        formData.append('csrf_token', csrfToken);
        if (activeTeamId) formData.append('team_id', activeTeamId);

        fetch(apiUrl, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    currentTemplate.layout_config = data.layout_config;
                    renderCanvasElements();
                    selectElement(activeElementKey);
                    alert(data.message);
                } else {
                    alert('Reset failed: ' + data.message);
                }
            });
    });

    // Initial Load
    loadTemplate(null);
});
</script>

<?php admin_close_page(); ?>
