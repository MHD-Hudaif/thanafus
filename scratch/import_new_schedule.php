<?php
require_once __DIR__ . '/../config/database.php';

$pdo = get_musabaqa_pdo();

// Start Transaction
$pdo->beginTransaction();

try {
    echo "1. Recreating schedule sessions/sections with expanded continuous ranges...\n";
    // Delete existing sessions
    $pdo->exec("DELETE FROM musabaqa_schedule_sections WHERE event_id = 8");
    
    // Insert new sessions
    $sessions = [
        // Day 1
        ['After Zuhar', '2026-08-17', '14:30:00', '17:30:00', 1],
        ['After Asr', '2026-08-17', '17:30:00', '19:30:00', 2],
        ['After Magrib', '2026-08-17', '19:30:00', '21:20:00', 3],
        ['After Isha', '2026-08-17', '21:20:00', '23:30:00', 4],
        // Day 2
        ['After Fajr', '2026-08-18', '06:30:00', '08:00:00', 1],
        ['After Breakfast', '2026-08-18', '08:00:00', '14:35:00', 2],
        ['After Zuhar', '2026-08-18', '14:35:00', '17:30:00', 3],
        ['After Asr', '2026-08-18', '17:30:00', '19:30:00', 4],
        ['Day 2 - After Magrib', '2026-08-18', '19:30:00', '21:20:00', 5],
        ['Day 2 - After Isha', '2026-08-18', '21:20:00', '23:30:00', 6],
        // Day 3
        ['Day 3 - After Subah', '2026-08-19', '06:30:00', '08:00:00', 1],
        ['Day 3 - After Breakfast', '2026-08-19', '08:00:00', '14:35:00', 2],
        ['Day 3 - After Zuhar', '2026-08-19', '14:35:00', '17:30:00', 3],
        ['Day 3 - After Asr', '2026-08-19', '17:30:00', '19:30:00', 4],
        ['Day 3 - After Magrib', '2026-08-19', '19:30:00', '21:20:00', 5],
        ['Day 3 - After Isha', '2026-08-19', '21:20:00', '23:30:00', 6],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO musabaqa_schedule_sections (event_id, name, section_date, start_time, end_time, sort_order) VALUES (8, ?, ?, ?, ?, ?)");
    foreach ($sessions as $s) {
        $stmt->execute($s);
    }
    
    // Fetch newly created session IDs mapped by date and start/end time overlap
    $stmt = $pdo->query("SELECT id, section_date, start_time, end_time FROM musabaqa_schedule_sections WHERE event_id = 8");
    $db_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Helper to find session ID by date and time
    $find_session_id = function($date, $time_str) use ($db_sessions) {
        // Replace en-dash and split
        $time_str = str_replace(['–', '—'], '-', $time_str);
        $parts = explode('-', $time_str);
        if (count($parts) < 2) return null;
        
        $item_start = date('H:i:s', strtotime(trim($parts[0])));
        $item_end = date('H:i:s', strtotime(trim($parts[1])));
        
        foreach ($db_sessions as $ds) {
            if ($ds['section_date'] !== $date) continue;
            
            // Check if item time overlaps or is within the session
            $s_start = $ds['start_time'];
            $s_end = $ds['end_time'];
            
            if ($item_start >= $s_start && $item_start < $s_end) {
                return (int)$ds['id'];
            }
        }
        return null;
    };
    
    echo "2. Clearing previous program schedules...\n";
    $pdo->exec("UPDATE musabaqa_programs SET start_time = NULL, end_time = NULL, stage_type_id = NULL, section_id = NULL, location = NULL WHERE event_id = 8");
    
    echo "3. Deleting old breaks/extras from musabaqa_programs and musabaqa_breaks...\n";
    // Clean up any breaks/extras that were incorrectly saved in musabaqa_programs
    $pdo->exec("DELETE FROM musabaqa_programs WHERE event_id = 8 AND class_type_id IS NULL AND (allowed_sections IS NULL OR allowed_sections = '')");
    $pdo->exec("DELETE FROM musabaqa_breaks WHERE event_id = 8");
    
    echo "4. Importing new schedule...\n";
    $items = json_decode('[{"date": "2026-08-17", "time_slot": "02:30 PM \\u2013 04:45 PM", "type": "BREAK", "title": "AFTER ZUHAR", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-17", "time_slot": "02:35 PM \\u2013 02:40 PM", "type": "EXTRA", "title": "Qirath", "desc": "All Asathiza", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-17", "time_slot": "02:40 PM \\u2013 02:45 PM", "type": "EXTRA", "title": "Nath", "desc": "All Asathiza", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-17", "time_slot": "02:45 PM \\u2013 03:00 PM", "type": "EXTRA", "title": "Inauguration Speech", "desc": "All Asathiza", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-17", "time_slot": "03:00 PM \\u2013 03:30 PM", "type": "COMPETITION", "title": "Qirath Thadveer (Junior)", "desc": "All Asathiza", "stage_id": 3, "mapped_ids": [104]}, {"date": "2026-08-17", "time_slot": "03:30 PM \\u2013 04:15 PM", "type": "COMPETITION", "title": "Malayalam Conversation (Sub-Junior)", "desc": "All Asathiza", "stage_id": 3, "mapped_ids": [119]}, {"date": "2026-08-17", "time_slot": "04:15 PM \\u2013 04:45 PM", "type": "COMPETITION", "title": "Qirath Thadveer (Sub-Junior)", "desc": "All Asathiza", "stage_id": 3, "mapped_ids": [121]}, {"date": "2026-08-17", "time_slot": "04:45 PM \\u2013 05:30 PM", "type": "BREAK", "title": "ASAR PRAYER BREAK (45 Minutes)", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-17", "time_slot": "05:30 PM \\u2013 06:30 PM", "type": "COMPETITION", "title": "Calligraphy (Senior & Junior)", "desc": "Usthad Zafeerudheen / Usthad Adhil", "stage_id": 5, "mapped_ids": [124, 162]}, {"date": "2026-08-17", "time_slot": "06:30 PM \\u2013 07:30 PM", "type": "BREAK", "title": "MAGRIB PRAYER BREAK & INAUGURAL PREPARATION", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-17", "time_slot": "07:30 PM \\u2013 08:15 PM", "type": "COMPETITION", "title": "English Conversation (Sub-Junior)", "desc": "Usthad Bilal / Usthad Salih", "stage_id": 3, "mapped_ids": [118]}, {"date": "2026-08-17", "time_slot": "08:15 PM \\u2013 09:20 PM", "type": "BREAK", "title": "FOOD BREAK & ISHA PRAYER", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-17", "time_slot": "09:20 PM \\u2013 10:50 PM", "type": "COMPETITION", "title": "Musajala General (General - 1.5 Hours)", "desc": "Usthad Abid Ibrahim / Moulana Yakoob / Usthad Jamaludheen", "stage_id": 3, "mapped_ids": [120]}, {"date": "2026-08-18", "time_slot": "06:30 AM \\u2013 07:30 AM", "type": "COMPETITION", "title": "Qirath Thartheel Senior", "desc": "Qari Salman / Qari Bilal", "stage_id": 5, "mapped_ids": [106]}, {"date": "2026-08-18", "time_slot": "07:30 AM \\u2013 08:00 AM", "type": "BREAK", "title": "BREAKFAST BREAK", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-18", "time_slot": "08:30 AM \\u2013 09:00 AM", "type": "COMPETITION", "title": "Qirath Thadveer Senior", "desc": "Usthad Abdul Sathar / Usthad Haris / Usthad Rajeeb / Usthad Hassan", "stage_id": 3, "mapped_ids": [103]}, {"date": "2026-08-18", "time_slot": "09:00 AM \\u2013 10:00 AM", "type": "COMPETITION", "title": "Malayalam Speech Junior", "desc": "Usthad Abdul Sathar / Usthad Haris / Usthad Rajeeb / Usthad Hassan", "stage_id": 3, "mapped_ids": [96]}, {"date": "2026-08-18", "time_slot": "10:00 AM \\u2013 11:00 AM", "type": "COMPETITION", "title": "Qirath Thartheel Junior", "desc": "Usthad Abdul Sathar / Usthad Haris / Usthad Rajeeb / Usthad Hassan", "stage_id": 3, "mapped_ids": [105]}, {"date": "2026-08-18", "time_slot": "10:00 AM \\u2013 11:00 AM", "type": "COMPETITION", "title": "Poem Writing Senior", "desc": "Usthad Abdul Sathar / Usthad Haris / Usthad Rajeeb / Usthad Hassan", "stage_id": 4, "mapped_ids": [166]}, {"date": "2026-08-18", "time_slot": "11:00 AM \\u2013 11:45 AM", "type": "COMPETITION", "title": "English Conversation General", "desc": "Usthad Abdul Sathar / Usthad Haris / Usthad Rajeeb / Usthad Hassan", "stage_id": 3, "mapped_ids": [111]}, {"date": "2026-08-18", "time_slot": "11:45 AM \\u2013 12:45 PM", "type": "COMPETITION", "title": "Qirath Thartheel Sub-Junior", "desc": "Usthad Abdul Sathar / Usthad Haris / Usthad Rajeeb / Usthad Hassan", "stage_id": 3, "mapped_ids": [122]}, {"date": "2026-08-18", "time_slot": "11:45 AM \\u2013 12:45 PM", "type": "COMPETITION", "title": "Ibarath Reading Senior", "desc": "Usthad Abdul Sathar / Usthad Haris / Usthad Rajeeb / Usthad Hassan", "stage_id": 4, "mapped_ids": [109]}, {"date": "2026-08-18", "time_slot": "11:45 AM \\u2013 12:45 PM", "type": "COMPETITION", "title": "Poem Writing Junior", "desc": "Usthad Abdul Sathar / Usthad Haris / Usthad Rajeeb / Usthad Hassan", "stage_id": 5, "mapped_ids": [128]}, {"date": "2026-08-18", "time_slot": "12:45 PM \\u2013 02:20 PM", "type": "BREAK", "title": "REST, LUNCH & ZUHAR PRAYER BREAK", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-18", "time_slot": "02:35 PM \\u2013 03:20 PM", "type": "COMPETITION", "title": "Arabic Conversation General", "desc": "Usthad Abdul Samad / Usthad Sirajudheen", "stage_id": 3, "mapped_ids": [110]}, {"date": "2026-08-18", "time_slot": "03:20 PM \\u2013 04:10 PM", "type": "COMPETITION", "title": "Arabic Speech Junior", "desc": "Usthad Abdul Samad / Usthad Sirajudheen", "stage_id": 3, "mapped_ids": [100]}, {"date": "2026-08-18", "time_slot": "04:10 PM \\u2013 04:45 PM", "type": "COMPETITION", "title": "Urdu Song General", "desc": "Usthad Abdul Samad / Usthad Sirajudheen", "stage_id": 3, "mapped_ids": [107]}, {"date": "2026-08-18", "time_slot": "04:45 PM \\u2013 05:30 PM", "type": "BREAK", "title": "ASAR PRAYER BREAK (45 Minutes)", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-18", "time_slot": "05:30 PM \\u2013 06:30 PM", "type": "COMPETITION", "title": "Malayalam Leganam Senior & Junior", "desc": "Usthad Abdul Samad / Usthad Midlaj", "stage_id": 5, "mapped_ids": [126, 164]}, {"date": "2026-08-18", "time_slot": "06:30 PM \\u2013 07:30 PM", "type": "BREAK", "title": "MAGRIB PRAYER BREAK", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-18", "time_slot": "07:30 PM \\u2013 08:15 PM", "type": "COMPETITION", "title": "Story Telling Sub - Junior", "desc": "Usthad Fazil / Usthad Aslam", "stage_id": 3, "mapped_ids": [168]}, {"date": "2026-08-18", "time_slot": "07:30 PM \\u2013 08:15 PM", "type": "COMPETITION", "title": "Word Fight", "desc": "Usthad Bilal / Usthad Adhil", "stage_id": 4, "mapped_ids": [173]}, {"date": "2026-08-18", "time_slot": "08:15 PM \\u2013 09:20 PM", "type": "BREAK", "title": "FOOD BREAK & ISHA PRAYER (45 Mins + Break)", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-18", "time_slot": "09:20 PM \\u2013 10:45 PM", "type": "COMPETITION", "title": "Malayalam Speech Senior", "desc": "Usthad Jamaludheen / Usthad Ilyas", "stage_id": 3, "mapped_ids": [97]}, {"date": "2026-08-19", "time_slot": "06:30 AM \\u2013 07:30 AM", "type": "COMPETITION", "title": "Arabic Maqala Senior & Junior", "desc": "Moulana Yakoob / Moulana Mahmoodul Hassan", "stage_id": 5, "mapped_ids": [172, 171]}, {"date": "2026-08-19", "time_slot": "07:30 AM \\u2013 08:00 AM", "type": "BREAK", "title": "BREAKFAST BREAK", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-19", "time_slot": "08:30 AM \\u2013 09:30 AM", "type": "COMPETITION", "title": "English Speech Senior", "desc": "Usthad Siraj / Usthad Asif / Usthad Abid / Usthad Ameer", "stage_id": 3, "mapped_ids": [99]}, {"date": "2026-08-19", "time_slot": "09:30 AM \\u2013 10:20 AM", "type": "COMPETITION", "title": "Urdu Speech Junior", "desc": "Usthad Siraj / Usthad Asif / Usthad Abid / Usthad Ameer", "stage_id": 3, "mapped_ids": [117]}, {"date": "2026-08-19", "time_slot": "10:20 AM \\u2013 11:20 AM", "type": "COMPETITION", "title": "Malayalam Speech Sub-Junior", "desc": "Usthad Siraj / Usthad Asif / Usthad Abid / Usthad Ameer", "stage_id": 3, "mapped_ids": [114]}, {"date": "2026-08-19", "time_slot": "11:20 AM \\u2013 12:10 PM", "type": "COMPETITION", "title": "Arabic Speech Sub-Junior", "desc": "Usthad Siraj / Usthad Asif / Usthad Abid / Usthad Ameer", "stage_id": 3, "mapped_ids": [116]}, {"date": "2026-08-19", "time_slot": "12:10 PM \\u2013 12:50 PM", "type": "COMPETITION", "title": "English Speech Junior", "desc": "Usthad Siraj / Usthad Asif / Usthad Abid / Usthad Ameer", "stage_id": 3, "mapped_ids": [98]}, {"date": "2026-08-19", "time_slot": "12:50 PM \\u2013 02:20 PM", "type": "BREAK", "title": "REST, LUNCH & ZUHAR PRAYER BREAK", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-19", "time_slot": "02:35 PM \\u2013 03:35 PM", "type": "COMPETITION", "title": "Urdu Speech Senior", "desc": "Moulana Mahmoodul Hassan / Moulana Zafeerudheen", "stage_id": 3, "mapped_ids": [102]}, {"date": "2026-08-19", "time_slot": "03:35 PM \\u2013 04:25 PM", "type": "COMPETITION", "title": "Arabic Speech Senior", "desc": "Moulana Mahmoodul Hassan / Moulana Zafeerudheen", "stage_id": 3, "mapped_ids": [101]}, {"date": "2026-08-19", "time_slot": "04:25 PM \\u2013 04:55 PM", "type": "COMPETITION", "title": "Azan Sub-Junior", "desc": "Moulana Mahmoodul Hassan / Moulana Zafeerudheen", "stage_id": 3, "mapped_ids": [167]}, {"date": "2026-08-19", "time_slot": "04:55 PM \\u2013 05:30 PM", "type": "BREAK", "title": "ASAR PRAYER BREAK (30 Minutes)", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-19", "time_slot": "05:30 PM \\u2013 06:30 PM", "type": "COMPETITION", "title": "News Writing Senior & Junior", "desc": "Usthad Abdul Samad / Usthad Midlaj", "stage_id": 5, "mapped_ids": [165, 125]}, {"date": "2026-08-19", "time_slot": "06:30 PM \\u2013 07:30 PM", "type": "BREAK", "title": "MAGRIB PRAYER BREAK", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-19", "time_slot": "07:30 PM \\u2013 08:15 PM", "type": "COMPETITION", "title": "English Speech Sub-Junior", "desc": "Qari Bilal / Usthad Ajmal", "stage_id": 3, "mapped_ids": [115]}, {"date": "2026-08-19", "time_slot": "08:15 PM \\u2013 09:20 PM", "type": "BREAK", "title": "FOOD BREAK & ISHA PRAYER (45 Minutes)", "desc": "", "stage_id": 3, "mapped_ids": []}, {"date": "2026-08-19", "time_slot": "09:20 PM \\u2013 10:00 PM", "type": "COMPETITION", "title": "Malayalam Song Sub-Junior", "desc": "All Asathiza", "stage_id": 3, "mapped_ids": [123]}, {"date": "2026-08-19", "time_slot": "10:00 PM \\u2013 11:00 PM", "type": "EXTRA", "title": "Distribution Of Prizes , Nasiha & Dua", "desc": "All Asathiza", "stage_id": 3, "mapped_ids": []}]', true);
    
    $update_stmt = $pdo->prepare("UPDATE musabaqa_programs SET start_time = ?, end_time = ?, stage_type_id = ?, section_id = ?, location = ? WHERE id = ?");
    $insert_stmt = $pdo->prepare("INSERT INTO musabaqa_breaks (event_id, name, description, stage_type_id, start_datetime, end_datetime, section_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($items as $item) {
        $date = $item['date'];
        $time_slot = $item['time_slot'];
        
        // Parse time slot
        $time_slot_cleaned = str_replace(['–', '—'], '-', $time_slot);
        $parts = explode('-', $time_slot_cleaned);
        if (count($parts) < 2) {
            echo "Skipping invalid time slot: " . $time_slot . "\n";
            continue;
        }
        
        $start_dt = date('Y-m-d H:i:s', strtotime($date . ' ' . trim($parts[0])));
        $end_dt = date('Y-m-d H:i:s', strtotime($date . ' ' . trim($parts[1])));
        
        // Find session ID
        $session_id = $find_session_id($date, $time_slot);
        if (!$session_id) {
            echo "Warning: Could not find session for " . $item['title'] . " on " . $date . " at " . $time_slot . "\n";
        }
        
        if ($item['type'] === 'COMPETITION') {
            // Update existing program(s)
            foreach ($item['mapped_ids'] as $pid) {
                // Get stage name
                $st_name = 'Darul Qur\'an';
                if ($item['stage_id'] == 4) $st_name = 'Kauzariyya Masjid';
                elseif ($item['stage_id'] == 5) $st_name = 'Kauzariyya Library';
                
                $update_stmt->execute([
                    $start_dt,
                    $end_dt,
                    $item['stage_id'],
                    $session_id,
                    $st_name,
                    $pid
                ]);
                echo "Scheduled competition: " . $item['title'] . " (ID: $pid) -> $time_slot\n";
            }
        } else {
            // Insert break or extra as a break in musabaqa_breaks table
            $location_detail = $item['desc'] ? $item['desc'] : 'Break';
            $insert_stmt->execute([
                8, // event_id
                $item['title'], // name
                $location_detail, // description
                $item['stage_id'], // stage_type_id
                $start_dt, // start_datetime
                $end_dt, // end_datetime
                $session_id // section_id
            ]);
            echo "Scheduled " . strtolower($item['type']) . " in musabaqa_breaks: " . $item['title'] . " -> $time_slot\n";
        }
    }
    
    $pdo->commit();
    echo "\nSchedule import completed successfully!\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
