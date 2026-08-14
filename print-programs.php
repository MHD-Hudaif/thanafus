<?php
declare(strict_types=1);

// Hardcoded Bluehost credentials as requested to ensure it reads from Bluehost DB
$DB_HOST = '162.214.80.164';
$DB_USER = 'ensplpmy_hudaif';
$DB_PASS = 'abd527-157';
$DB_DASHBOARD = 'ensplpmy_kauzariyya_dashboard';
$DB_MUSABAQA = 'ensplpmy_kauzariyya_musabaqa';

try {
    $pdo = new PDO("mysql:host={$DB_HOST};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
} catch (PDOException $e) {
    die("Connection to Bluehost Database failed: " . $e->getMessage());
}

function translateCategory(?string $name): string {
    if (!$name) {
        return 'General';
    }
    $name = trim($name);
    if ($name === 'العالية') {
        return 'Senior';
    }
    if ($name === 'الثانوية') {
        return 'Junior';
    }
    if ($name === 'التحصص' || $name === 'حفظ') {
        return 'Subjunior';
    }
    return $name;
}

// 1. Fetch active event from Bluehost DB
$event = null;
try {
    $stmt = $pdo->query("
        SELECT *
        FROM {$DB_MUSABAQA}.musabaqa_events
        WHERE status = 'active'
        ORDER BY COALESCE(start_date, '1900-01-01') DESC, id DESC
        LIMIT 1
    ");
    $event = $stmt->fetch();
    
    if (!$event) {
        $stmt = $pdo->query("
            SELECT *
            FROM {$DB_MUSABAQA}.musabaqa_events
            ORDER BY id DESC
            LIMIT 1
        ");
        $event = $stmt->fetch();
    }
} catch (PDOException $e) {
    die("Error fetching event: " . $e->getMessage());
}

if (!$event) {
    die("No events found in the database.");
}

$eventId = (int)$event['id'];
$eventTitle = $event['title'] ?? 'Kauzariyya Arts Festival';
$eventDate = !empty($event['start_date']) ? date('d F Y', strtotime((string)$event['start_date'])) : 'N/A';

// 2. Fetch programs list for active event
$programs = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.title,
            p.start_time,
            p.end_time,
            p.status,
            p.location,
            st.name AS stage_name,
            ct.name AS class_name
        FROM {$DB_MUSABAQA}.musabaqa_programs p
        LEFT JOIN {$DB_MUSABAQA}.musabaqa_stage_types st ON st.id = p.stage_type_id
        LEFT JOIN {$DB_DASHBOARD}.class_types ct ON ct.id = p.class_type_id
        WHERE p.event_id = ?
        ORDER BY p.start_time ASC, p.id ASC
    ");
    $stmt->execute([$eventId]);
    $programs = $stmt->fetchAll() ?: [];
} catch (PDOException $e) {
    die("Error fetching programs: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printable Program List - <?= htmlspecialchars($eventTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
            background-color: #fff;
            line-height: 1.5;
            padding: 40px;
        }
        .print-container {
            max-width: 1000px;
            margin: 0 auto;
        }
        /* Header styling */
        .print-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .brand-info h1 {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .brand-info p {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
            font-weight: 500;
        }
        .event-meta {
            text-align: right;
        }
        .event-meta .badge {
            display: inline-block;
            background-color: #10b981;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .event-meta h2 {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }
        .event-meta p {
            font-size: 13px;
            color: #475569;
            margin-top: 2px;
            font-weight: 600;
        }
        /* Print stats card */
        .stats-strip {
            display: flex;
            gap: 20px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 20px;
            margin-bottom: 24px;
        }
        .stat-item {
            font-size: 13px;
            color: #475569;
            font-weight: 500;
        }
        .stat-item strong {
            color: #0f172a;
            font-weight: 700;
        }
        /* Table styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            border-bottom: 2px solid #cbd5e1;
            text-align: left;
        }
        td {
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13.5px;
            color: #334155;
        }
        tr:nth-child(even) td {
            background-color: #f8fafc;
        }
        .sl-no {
            font-weight: 700;
            color: #64748b;
            width: 60px;
        }
        .prog-title {
            font-weight: 700;
            color: #0f172a;
        }
        .time-cell {
            font-weight: 600;
            white-space: nowrap;
        }
        .status-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 4px;
            background-color: #e2e8f0;
            color: #475569;
        }
        .status-badge.live {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-badge.completed {
            background-color: #e0f2fe;
            color: #075985;
        }
        /* Footer signatures */
        .print-footer {
            display: flex;
            justify-content: space-between;
            margin-top: 60px;
            padding-top: 20px;
            border-top: 1px dashed #cbd5e1;
        }
        .signature-line {
            width: 200px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
        }
        .signature-line div {
            border-top: 1px solid #475569;
            margin-top: 40px;
            padding-top: 6px;
        }
        /* Screen only control bar */
        .control-bar {
            background-color: #0f172a;
            color: #fff;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            margin: -40px -40px 30px -40px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .control-bar span {
            font-size: 14px;
            font-weight: 600;
        }
        .btn-print {
            background-color: #10b981;
            color: #fff;
            border: none;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 700;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-print:hover {
            background-color: #059669;
        }

        /* Print Media Styles */
        @media print {
            body {
                padding: 0;
            }
            .control-bar {
                display: none !important;
            }
            tr {
                page-break-inside: avoid !important;
            }
            thead {
                display: table-header-group !important;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <!-- Control Bar (hidden during print) -->
        <div class="control-bar">
            <span>Bluehost Production Database Connection Active</span>
            <div style="display: flex; gap: 10px;">
                <button class="btn-print" style="background-color: #10b981;" onclick="exportAllTablesToExcel('table')">📊 Copy for Excel</button>
                <button class="btn-print" onclick="window.print()">Print Program List</button>
            </div>
        </div>

        <!-- Programs List Table -->
        <table>
            <thead>
                <tr>
                    <th class="sl-no">Sl No</th>
                    <th>Program Title</th>
                    <th>Category</th>
                    <th style="width: 150px; text-align: left;">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($programs)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: #64748b; padding: 30px;">
                            No programs scheduled for this event.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($programs as $idx => $p): ?>
                        <tr>
                            <td class="sl-no"><?= $idx + 1 ?></td>
                            <td class="prog-title"><?= htmlspecialchars($p['title']) ?></td>
                            <td><?= htmlspecialchars(translateCategory($p['class_name'])) ?></td>
                            <td style="border-bottom: 1px solid #cbd5e1; height: 35px;"></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Signatures -->
        <footer class="print-footer">
            <div class="signature-line">
                <div>Program Coordinator</div>
            </div>
            <div class="signature-line">
                <div>Ameer / Principal</div>
            </div>
        </footer>
    </div>

    <script src="assets/js/print-helpers.js"></script>
    <script>
        // Automatically open the print dialog after rendering
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                window.print();
            }, 500);
        });
    </script>
</body>
</html>
