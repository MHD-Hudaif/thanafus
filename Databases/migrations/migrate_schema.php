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

    echo "Migration completed successfully!\n";
} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
