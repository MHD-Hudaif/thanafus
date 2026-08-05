<?php

require_once __DIR__ . '/admin-helpers.php';

function id_card_category_label(?string $classTypeName, ?int $classTypeId = null): string
{
    $tier = admin_class_type_tier_from_name($classTypeName);
    if ($tier) {
        return admin_class_type_tier_label($tier);
    }

    if ($classTypeId === 1) {
        return 'Senior';
    }
    if ($classTypeId === 2) {
        return 'Junior';
    }
    if ($classTypeId === 3) {
        return 'Sub Junior';
    }

    $name = trim((string)$classTypeName);
    if ($name === '') {
        return '-';
    }

    return $name;
}

function id_card_absolute_url(string $path): string
{
    return app_absolute_url($path);
}

function id_card_members(PDO $pdo, int $eventId): array
{
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
        ORDER BY NULLIF(mtm.chest_number, '') IS NULL ASC,
                 CAST(mtm.chest_number AS UNSIGNED) ASC, t.team_name ASC, display_name ASC
    ");
    $stmt->execute([$eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function id_card_member(PDO $pdo, int $memberId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            mtm.id AS member_id,
            mtm.student_id,
            mtm.event_id,
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
        WHERE mtm.id = ?
          AND mtm.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$memberId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function id_card_ensure_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `musabaqa_id_card_templates` (
          `id` INT NOT NULL AUTO_INCREMENT,
          `event_id` INT NOT NULL,
          `team_id` INT NULL DEFAULT NULL,
          `background_image` VARCHAR(255) NULL DEFAULT NULL,
          `orientation` VARCHAR(20) NOT NULL DEFAULT 'portrait',
          `card_width` INT NOT NULL DEFAULT 600,
          `card_height` INT NOT NULL DEFAULT 950,
          `layout_config` LONGTEXT NULL,
          `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_event_team` (`event_id`, `team_id`),
          KEY `idx_event_id` (`event_id`),
          KEY `idx_team_id` (`team_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $checked = true;
}

function id_card_default_layout(): array
{
    return [
        'student_photo' => [
            'visible' => true,
            'x' => 50,
            'y' => 16,
            'width' => 120,
            'height' => 120,
            'border_radius' => 60,
            'border_color' => '#ffffff',
            'border_width' => 3,
        ],
        'chest_number' => [
            'visible' => true,
            'x' => 50,
            'y' => 32,
            'font_size' => 44,
            'font_weight' => '800',
            'color' => '#e11d48',
            'align' => 'center',
            'text_transform' => 'none',
            'prefix' => '#',
        ],
        'display_name' => [
            'visible' => true,
            'x' => 50,
            'y' => 42,
            'font_size' => 24,
            'font_weight' => '700',
            'color' => '#0f172a',
            'align' => 'center',
            'text_transform' => 'capitalize',
        ],
        'name_arabic' => [
            'visible' => true,
            'x' => 50,
            'y' => 48,
            'font_size' => 20,
            'font_weight' => '500',
            'color' => '#334155',
            'align' => 'center',
            'text_transform' => 'none',
        ],
        'team_name' => [
            'visible' => true,
            'x' => 50,
            'y' => 58,
            'font_size' => 22,
            'font_weight' => '700',
            'color' => 'auto',
            'align' => 'center',
            'text_transform' => 'uppercase',
        ],
    ];
}

function id_card_get_template(PDO $pdo, int $eventId, ?int $teamId = null): array
{
    id_card_ensure_table($pdo);

    $sql = 'SELECT * FROM musabaqa_id_card_templates WHERE event_id = ? AND ';
    $params = [$eventId];

    if ($teamId !== null && $teamId > 0) {
        $sql .= 'team_id = ?';
        $params[] = $teamId;
    } else {
        $sql .= 'team_id IS NULL';
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    $defaultLayout = id_card_default_layout();

    // Fallback: If requested a specific team layout and it's missing, try event default
    if (!$template && $teamId !== null && $teamId > 0) {
        $stmtDef = $pdo->prepare('SELECT * FROM musabaqa_id_card_templates WHERE event_id = ? AND team_id IS NULL LIMIT 1');
        $stmtDef->execute([$eventId]);
        $template = $stmtDef->fetch(PDO::FETCH_ASSOC);
    }

    if (!$template) {
        return [
            'id' => null,
            'event_id' => $eventId,
            'team_id' => $teamId,
            'background_image' => null,
            'orientation' => 'portrait',
            'card_width' => 600,
            'card_height' => 950,
            'layout_config' => $defaultLayout,
            'is_default_fallback' => true,
        ];
    }

    $decoded = !empty($template['layout_config']) ? json_decode($template['layout_config'], true) : [];
    if (!is_array($decoded)) {
        $decoded = [];
    }

    // Merge missing keys with default layout settings
    foreach ($defaultLayout as $key => $conf) {
        if (!isset($decoded[$key])) {
            $decoded[$key] = $conf;
        } else {
            $decoded[$key] = array_merge($conf, $decoded[$key]);
        }
    }

    $template['layout_config'] = $decoded;
    $template['is_default_fallback'] = false;
    return $template;
}

function id_card_save_template(PDO $pdo, int $eventId, ?int $teamId, ?string $bgPath, array $layoutConfig, string $orientation = 'portrait', int $width = 600, int $height = 950): bool
{
    id_card_ensure_table($pdo);

    $jsonConfig = json_encode($layoutConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $teamVal = ($teamId !== null && $teamId > 0) ? $teamId : null;

    // Check if entry exists
    $sql = 'SELECT id, background_image FROM musabaqa_id_card_templates WHERE event_id = ? AND ';
    $params = [$eventId];
    if ($teamVal !== null) {
        $sql .= 'team_id = ?';
        $params[] = $teamVal;
    } else {
        $sql .= 'team_id IS NULL';
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $finalBg = ($bgPath !== null) ? $bgPath : $existing['background_image'];
        $upd = $pdo->prepare('
            UPDATE musabaqa_id_card_templates 
            SET background_image = ?, orientation = ?, card_width = ?, card_height = ?, layout_config = ?, updated_at = NOW() 
            WHERE id = ?
        ');
        return $upd->execute([$finalBg, $orientation, $width, $height, $jsonConfig, $existing['id']]);
    } else {
        $ins = $pdo->prepare('
            INSERT INTO musabaqa_id_card_templates (event_id, team_id, background_image, orientation, card_width, card_height, layout_config, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        return $ins->execute([$eventId, $teamVal, $bgPath, $orientation, $width, $height, $jsonConfig]);
    }
}

