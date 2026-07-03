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

function id_card_qr_filename(?string $chestNumber): ?string
{
    $chestNumber = trim((string)$chestNumber);
    if ($chestNumber === '') {
        return null;
    }

    return preg_replace('/[^A-Za-z0-9_-]+/', '-', $chestNumber) . '.png';
}

function id_card_qr_paths(int $eventId, ?string $chestNumber): array
{
    $filename = id_card_qr_filename($chestNumber);
    if ($filename === null) {
        return ['file' => '', 'web' => '', 'local' => ''];
    }

    $relativeDir = '/assets/qr/id-cards/event-' . $eventId;
    $file = public_path(ltrim($relativeDir, '/') . '/' . $filename);
    $web = app_url($relativeDir . '/' . rawurlencode($filename));

    return [
        'file' => $file,
        'web' => $web,
        'local' => realpath($file) ?: $file,
    ];
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
            s.name_arabic,
            s.place,
            s.admission_no,
            c.class_type_id,
            c.name AS section,
            ct.name AS class_type_name
        FROM musabaqa_team_members mtm
        JOIN musabaqa_teams t ON t.id = mtm.team_id
        JOIN musabaqa_events ev ON ev.id = mtm.event_id
        JOIN kauzariyya.students s ON s.id = mtm.student_id
        LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
        LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
        WHERE mtm.event_id = ?
          AND mtm.status = 'active'
        ORDER BY mtm.chest_number IS NULL ASC, CAST(mtm.chest_number AS UNSIGNED) ASC, t.team_name ASC, display_name ASC
    ");
    $stmt->execute([$eventId]);

    $members = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $member) {
        $member['category'] = id_card_category_label($member['class_type_name'] ?? null, (int)($member['class_type_id'] ?? 0));
        $member['qr_url'] = id_card_absolute_url('/member-details.php?id=' . (int)$member['member_id']);
        $member['qr_paths'] = id_card_qr_paths($eventId, $member['chest_number'] ?? null);
        $members[] = $member;
    }

    return $members;
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
            s.name_arabic,
            s.place,
            s.admission_no,
            c.class_type_id,
            c.name AS section,
            ct.name AS class_type_name
        FROM musabaqa_team_members mtm
        JOIN musabaqa_teams t ON t.id = mtm.team_id
        JOIN musabaqa_events ev ON ev.id = mtm.event_id
        JOIN kauzariyya.students s ON s.id = mtm.student_id
        LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
        LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
        WHERE mtm.id = ?
          AND mtm.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        return null;
    }

    $member['category'] = id_card_category_label($member['class_type_name'] ?? null, (int)($member['class_type_id'] ?? 0));
    $member['qr_url'] = id_card_absolute_url('/member-details.php?id=' . (int)$member['member_id']);
    $member['qr_paths'] = id_card_qr_paths((int)$member['event_id'], $member['chest_number'] ?? null);

    return $member;
}

function id_card_generate_qrs(array $members): array
{
    require_once __DIR__ . '/qr-code.php';

    $generated = 0;
    $skipped = 0;
    $logoPath = asset_path('images/thanafus-logo.png');

    foreach ($members as $member) {
        $paths = $member['qr_paths'] ?? [];
        if (empty($member['chest_number']) || empty($paths['file'])) {
            $skipped++;
            continue;
        }

        $teamColor = $member['team_color'] ?? null;
        qr_write_png((string)$member['qr_url'], (string)$paths['file'], 10, 4, $teamColor, $logoPath);
        $generated++;
    }

    return ['generated' => $generated, 'skipped' => $skipped];
}
