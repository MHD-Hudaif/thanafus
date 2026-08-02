<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/event-guard.php';

$activeEvent = get_active_musabaqa();
if (!$activeEvent) {
    die("Error: No active event found.\n");
}
$eventId = (int)$activeEvent['id'];
echo "Active Event ID: $eventId ({$activeEvent['title']})\n";

// Let's seed for both Event 13 ("thanafus test") and Event 8 ("Thanafus 2026-27")
$eventIds = [13, 8];

$musabaqa_pdo->beginTransaction();

try {
    foreach ($eventIds as $evId) {
        echo "\nProcessing Event ID: $evId...\n";
        
        // Fetch all programs for this event
        $stmt = $musabaqa_pdo->prepare("SELECT * FROM musabaqa_programs WHERE event_id = ?");
        $stmt->execute([$evId]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Found " . count($programs) . " programs.\n";
        
        foreach ($programs as $prog) {
            $progId = (int)$prog['id'];
            $title = $prog['title'];
            
            // Delete existing categories for this program
            $del = $musabaqa_pdo->prepare("DELETE FROM musabaqa_program_categories WHERE program_id = ?");
            $del->execute([$progId]);
            
            // Determine categories based on program title (always exactly 4 categories summing to 100)
            $categories = [];
            $lowerTitle = strtolower($title);
            
            if (str_contains($lowerTitle, 'speech') || str_contains($lowerTitle, 'prasangam')) {
                $categories = [
                    ['name' => 'Content & Subject Matter', 'max_marks' => 30.00, 'sort_order' => 1],
                    ['name' => 'Language & Grammar', 'max_marks' => 30.00, 'sort_order' => 2],
                    ['name' => 'Delivery & Fluency', 'max_marks' => 20.00, 'sort_order' => 3],
                    ['name' => 'Presentation & Body Language', 'max_marks' => 20.00, 'sort_order' => 4]
                ];
            } elseif (str_contains($lowerTitle, 'qirath') || str_contains($lowerTitle, 'hifz')) {
                $categories = [
                    ['name' => 'Thajweed Rules', 'max_marks' => 40.00, 'sort_order' => 1],
                    ['name' => 'Tune & Melody', 'max_marks' => 20.00, 'sort_order' => 2],
                    ['name' => 'Vocal Control & Rhythm', 'max_marks' => 20.00, 'sort_order' => 3],
                    ['name' => 'Presentation & Pauses', 'max_marks' => 20.00, 'sort_order' => 4]
                ];
            } elseif (str_contains($lowerTitle, 'song') || str_contains($lowerTitle, 'ganam') || str_contains($lowerTitle, 'pattu') || str_contains($lowerTitle, 'geeth')) {
                $categories = [
                    ['name' => 'Tune & Melody', 'max_marks' => 30.00, 'sort_order' => 1],
                    ['name' => 'Rhythm & Tempo', 'max_marks' => 30.00, 'sort_order' => 2],
                    ['name' => 'Vocal Quality & Control', 'max_marks' => 20.00, 'sort_order' => 3],
                    ['name' => 'Expression & Lyrics', 'max_marks' => 20.00, 'sort_order' => 4]
                ];
            } elseif (str_contains($lowerTitle, 'muhadasa') || str_contains($lowerTitle, 'conversation') || str_contains($lowerTitle, 'talk')) {
                $categories = [
                    ['name' => 'Fluency & Accent', 'max_marks' => 25.00, 'sort_order' => 1],
                    ['name' => 'Vocabulary & Expressions', 'max_marks' => 25.00, 'sort_order' => 2],
                    ['name' => 'Grammar & Structure', 'max_marks' => 25.00, 'sort_order' => 3],
                    ['name' => 'Content & Response', 'max_marks' => 25.00, 'sort_order' => 4]
                ];
            } elseif (str_contains($lowerTitle, 'musajala')) {
                $categories = [
                    ['name' => 'Recitation & Pronunciation', 'max_marks' => 25.00, 'sort_order' => 1],
                    ['name' => 'Rhythm & Melody', 'max_marks' => 25.00, 'sort_order' => 2],
                    ['name' => 'Expression & Emotion', 'max_marks' => 25.00, 'sort_order' => 3],
                    ['name' => 'Memory & Flow', 'max_marks' => 25.00, 'sort_order' => 4]
                ];
            } elseif (str_contains($lowerTitle, 'reading') || str_contains($lowerTitle, 'vayana') || str_contains($lowerTitle, 'ebarath')) {
                $categories = [
                    ['name' => 'Pronunciation (Thalaffudh)', 'max_marks' => 25.00, 'sort_order' => 1],
                    ['name' => 'Grammar & Syntax (I\'rab)', 'max_marks' => 25.00, 'sort_order' => 2],
                    ['name' => 'Reading Speed & Flow', 'max_marks' => 25.00, 'sort_order' => 3],
                    ['name' => 'Tone & Expression', 'max_marks' => 25.00, 'sort_order' => 4]
                ];
            } elseif (str_contains($lowerTitle, 'fight') || str_contains($lowerTitle, 'quiz') || str_contains($lowerTitle, 'vanchipattu') || str_contains($lowerTitle, 'chollum')) {
                $categories = [
                    ['name' => 'Accuracy / Correctness', 'max_marks' => 30.00, 'sort_order' => 1],
                    ['name' => 'Speed & Quickness', 'max_marks' => 30.00, 'sort_order' => 2],
                    ['name' => 'Vocabulary / Range', 'max_marks' => 20.00, 'sort_order' => 3],
                    ['name' => 'Presentation / Focus', 'max_marks' => 20.00, 'sort_order' => 4]
                ];
            } elseif (str_contains($lowerTitle, 'writing') || str_contains($lowerTitle, 'caligraphy') || str_contains($lowerTitle, 'drawing') || str_contains($lowerTitle, 'chithra') || str_contains($lowerTitle, 'mathrubhasha') || str_contains($lowerTitle, 'poem')) {
                $categories = [
                    ['name' => 'Creativity & Originality', 'max_marks' => 30.00, 'sort_order' => 1],
                    ['name' => 'Content Depth & Theme', 'max_marks' => 30.00, 'sort_order' => 2],
                    ['name' => 'Structure & Grammar', 'max_marks' => 20.00, 'sort_order' => 3],
                    ['name' => 'Neatness & Presentation', 'max_marks' => 20.00, 'sort_order' => 4]
                ];
            } else {
                $categories = [
                    ['name' => 'Category A', 'max_marks' => 25.00, 'sort_order' => 1],
                    ['name' => 'Category B', 'max_marks' => 25.00, 'sort_order' => 2],
                    ['name' => 'Category C', 'max_marks' => 25.00, 'sort_order' => 3],
                    ['name' => 'Category D', 'max_marks' => 25.00, 'sort_order' => 4]
                ];
            }
            
            // Insert categories
            $ins = $musabaqa_pdo->prepare("
                INSERT INTO musabaqa_program_categories (program_id, name, max_marks, sort_order)
                VALUES (?, ?, ?, ?)
            ");
            
            echo "Program: '$title' (ID: $progId) -> Seeding " . count($categories) . " categories.\n";
            foreach ($categories as $cat) {
                $ins->execute([$progId, $cat['name'], $cat['max_marks'], $cat['sort_order']]);
            }
        }
    }
    
    $musabaqa_pdo->commit();
    echo "\nSUCCESS: Program categories seeded successfully with exactly 4 categories per program!\n";
} catch (Exception $e) {
    $musabaqa_pdo->rollBack();
    echo "ERROR during seeding: " . $e->getMessage() . "\n";
}
