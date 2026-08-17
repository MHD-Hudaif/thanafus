<?php

require_once __DIR__ . '/../../config/database.php';

echo "Running migrations...\n";

try {
    // 1. Update musabaqa_events in musabaqa DB
    $cols = $musabaqa_pdo->query("SHOW COLUMNS FROM musabaqa_events")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('image_path', $cols, true)) {
        $musabaqa_pdo->exec("ALTER TABLE musabaqa_events ADD COLUMN image_path VARCHAR(255) NULL AFTER description");
        echo "Added image_path column to musabaqa_events.\n";
    } else {
        echo "image_path column already exists.\n";
    }

    // Change status column type to allow draft, active, scheduled, unactive, completed
    $musabaqa_pdo->exec("ALTER TABLE musabaqa_events MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'draft'");
    echo "Updated status column in musabaqa_events to VARCHAR(50).\n";

    // 2. Ensure roles table in dashboard DB has necessary columns and default roles for Musabaqa categories
    $dashboard_cols = $dashboard_pdo->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category_key', $dashboard_cols, true)) {
        $dashboard_pdo->exec("ALTER TABLE roles ADD COLUMN category_key VARCHAR(50) NULL AFTER slug");
        echo "Added category_key column to roles in dashboard DB.\n";
    }

    // Ensure default system category roles exist in roles table
    $categoryRoles = [
        ['Event Manager', 'event-manager', 'event_manager', 'Manages event settings, programs, program settings, and schedule'],
        ['Team Manager', 'team-manager', 'team_manager', 'Manages teams, members, and chest numbers'],
        ['Printer', 'printer', 'printer', 'Prints team members, ID cards, chest numbers, CSVs, and print queue updates'],
        ['Registrar', 'registrar', 'registrar', 'Manages program entries and student assignments'],
        ['Live Display Manager', 'live-display-manager', 'live_display', 'Controls TV scoreboard and live presentation screen'],
        ['Score Entry Agent', 'score-entry-agent', 'score_entry', 'Enters judge scores and submits score approval requests'],
        ['Score Update Agent', 'score-update-agent', 'score_update', 'Approves submitted scores and updates team standings']
    ];

    foreach ($categoryRoles as $r) {
        $stmt = $dashboard_pdo->prepare("SELECT id FROM roles WHERE slug = ? OR category_key = ? LIMIT 1");
        $stmt->execute([$r[1], $r[2]]);
        $existing = $stmt->fetchColumn();

        if (!$existing) {
            $ins = $dashboard_pdo->prepare("
                INSERT INTO roles (name, slug, category_key, description, event_id, is_system, created_at, updated_at)
                VALUES (?, ?, ?, ?, 1, 0, NOW(), NOW())
            ");
            $ins->execute([$r[0], $r[1], $r[2], $r[3]]);
            echo "Inserted role: {$r[0]}\n";
        } else {
            $upd = $dashboard_pdo->prepare("UPDATE roles SET category_key = ? WHERE id = ?");
            $upd->execute([$r[2], $existing]);
            echo "Updated category_key for role: {$r[0]}\n";
        }
    }

    // 3. Add team_score column to musabaqa_program_entries if it doesn't exist
    $pe_cols = $musabaqa_pdo->query("SHOW COLUMNS FROM musabaqa_program_entries")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('team_score', $pe_cols, true)) {
        $musabaqa_pdo->exec("ALTER TABLE musabaqa_program_entries ADD COLUMN team_score INT NOT NULL DEFAULT 0 AFTER final_rank");
        echo "Added team_score column to musabaqa_program_entries.\n";
    } else {
        echo "team_score column already exists.\n";
    }

    // Add performance_order column to musabaqa_program_entries if it doesn't exist
    if (!in_array('performance_order', $pe_cols, true)) {
        $musabaqa_pdo->exec("ALTER TABLE musabaqa_program_entries ADD COLUMN performance_order INT NOT NULL DEFAULT 0 AFTER team_score");
        $musabaqa_pdo->exec("UPDATE musabaqa_program_entries SET performance_order = FLOOR(1 + RAND() * 1000000)");
        echo "Added and populated performance_order column in musabaqa_program_entries.\n";
    } else {
        echo "performance_order column already exists.\n";
    }

    // 4. Backfill existing rankings
    $backfillCount = $musabaqa_pdo->exec("
        UPDATE musabaqa_program_entries
        SET team_score = CASE 
            WHEN final_rank = 1 THEN 10 
            WHEN final_rank = 2 THEN 7 
            WHEN final_rank = 3 THEN 5 
            ELSE 0 
        END
        WHERE final_rank IS NOT NULL
    ");
    echo "Backfilled $backfillCount entries with placement points.\n";

    // 5. Recalculate team totals for all events
    $eventIds = $musabaqa_pdo->query("SELECT id FROM musabaqa_events")->fetchAll(PDO::FETCH_COLUMN);
    $recalcQuery = $musabaqa_pdo->prepare("
        UPDATE musabaqa_teams t
        LEFT JOIN (
            SELECT pe.team_id, COALESCE(SUM(pe.team_score), 0) AS total_score
            FROM musabaqa_program_entries pe
            JOIN musabaqa_programs p ON p.id = pe.program_id
            WHERE pe.event_id = ?
              AND p.approval_status = 'approved'
              AND (p.redirect_to_team IS NULL OR p.redirect_to_team = 1)
              AND (p.disable_scores IS NULL OR p.disable_scores = 0)
            GROUP BY pe.team_id
        ) totals ON totals.team_id = t.id
        SET t.total_score = COALESCE(totals.total_score, 0)
        WHERE t.event_id = ?
    ");
    foreach ($eventIds as $eventId) {
        $recalcQuery->execute([$eventId, $eventId]);
        echo "Recalculated team totals for event #$eventId.\n";
    }

    // 6. Add section_date column to musabaqa_schedule_sections
    $ss_cols = $musabaqa_pdo->query("SHOW COLUMNS FROM musabaqa_schedule_sections")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('section_date', $ss_cols, true)) {
        $musabaqa_pdo->exec("ALTER TABLE musabaqa_schedule_sections ADD COLUMN section_date DATE NULL AFTER end_time");
        echo "Added section_date column to musabaqa_schedule_sections.\n";
    } else {
        echo "section_date column already exists.\n";
    }

    // 7. Add team_points_config and only_team_marks columns to musabaqa_programs
    $prog_cols = $musabaqa_pdo->query("SHOW COLUMNS FROM musabaqa_programs")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('team_points_config', $prog_cols, true)) {
        $musabaqa_pdo->exec("ALTER TABLE musabaqa_programs ADD COLUMN team_points_config TEXT NULL AFTER is_special");
        echo "Added team_points_config column to musabaqa_programs.\n";
    } else {
        echo "team_points_config column already exists.\n";
    }

    if (!in_array('only_team_marks', $prog_cols, true)) {
        $musabaqa_pdo->exec("ALTER TABLE musabaqa_programs ADD COLUMN only_team_marks TINYINT NOT NULL DEFAULT 0 AFTER team_points_config");
        echo "Added only_team_marks column to musabaqa_programs.\n";
    } else {
        echo "only_team_marks column already exists.\n";
    }

    // 8. Create musabaqa_id_card_templates table if missing
    $musabaqa_pdo->exec("
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
    echo "Ensured musabaqa_id_card_templates table exists.\n";

    // 9. Add missing performance indexes (from 002_add_performance_indexes.sql)
    $addIndexIfMissing = function ($pdo, $table, $indexName, $sql) {
        $stmt = $pdo->query("SHOW INDEX FROM `{$table}`");
        $hasIndex = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['Key_name'] === $indexName) {
                $hasIndex = true;
                break;
            }
        }
        if (!$hasIndex) {
            $pdo->exec($sql);
            echo "Added index {$indexName} to {$table}.\n";
        } else {
            echo "Index {$indexName} already exists on {$table}.\n";
        }
    };

    $addIndexIfMissing(
        $musabaqa_pdo,
        'musabaqa_scores',
        'idx_event_status_program',
        'ALTER TABLE musabaqa_scores ADD INDEX idx_event_status_program (event_id, status, program_id)'
    );

    $addIndexIfMissing(
        $musabaqa_pdo,
        'musabaqa_program_entries',
        'idx_event_program_team',
        'ALTER TABLE musabaqa_program_entries ADD INDEX idx_event_program_team (event_id, program_id, team_id)'
    );

    $addIndexIfMissing(
        $musabaqa_pdo,
        'musabaqa_entry_members',
        'idx_entry_team_member',
        'ALTER TABLE musabaqa_entry_members ADD INDEX idx_entry_team_member (entry_id, team_member_id)'
    );

    $addIndexIfMissing(
        $musabaqa_pdo,
        'musabaqa_team_members',
        'idx_event_student_team',
        'ALTER TABLE musabaqa_team_members ADD INDEX idx_event_student_team (event_id, student_id, team_id)'
    );

    // 10. Update users table schema for pending approvals and OTP codes
    $dashboard_users_cols = $dashboard_pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    
    // Update status enum to allow 'pending' if it doesn't already allow it
    $statusCol = $dashboard_pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusCol && !str_contains($statusCol['Type'], 'pending')) {
        $dashboard_pdo->exec("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','suspended','pending') DEFAULT 'pending'");
        echo "Updated status column in users to include 'pending'.\n";
    }
    
    if (!in_array('otp_code', $dashboard_users_cols, true)) {
        $dashboard_pdo->exec("ALTER TABLE users ADD COLUMN otp_code VARCHAR(10) NULL AFTER profile_photo");
        echo "Added otp_code column to users table.\n";
    }
    if (!in_array('otp_expires_at', $dashboard_users_cols, true)) {
        $dashboard_pdo->exec("ALTER TABLE users ADD COLUMN otp_expires_at DATETIME NULL AFTER otp_code");
        echo "Added otp_expires_at column to users table.\n";
    }

    // 11. Create musabaqa_visitor_logs table if missing
    $musabaqa_pdo->exec("
        CREATE TABLE IF NOT EXISTS `musabaqa_visitor_logs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `session_id` VARCHAR(255) NOT NULL,
          `ip_address` VARCHAR(45) NULL,
          `user_agent` VARCHAR(500) NULL,
          `device_type` VARCHAR(50) NULL,
          `browser` VARCHAR(100) NULL,
          `platform` VARCHAR(100) NULL,
          `page_url` VARCHAR(255) NULL,
          `referrer` VARCHAR(500) NULL,
          `is_bot` TINYINT NOT NULL DEFAULT 0,
          `visit_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `event_id` INT NULL,
          KEY `idx_visit_time` (`visit_time`),
          KEY `idx_is_bot` (`is_bot`),
          KEY `idx_session_page` (`session_id`(100), `page_url`(150))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Ensured musabaqa_visitor_logs table exists.\n";

    echo "Migration completed successfully!\n";
} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
