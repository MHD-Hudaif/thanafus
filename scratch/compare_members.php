<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Check if team members for event 8 and 13 are the same students
$members8 = $musabaqa_pdo->query("SELECT student_id, team_id FROM musabaqa_team_members WHERE event_id = 8")->fetchAll();
$members13 = $musabaqa_pdo->query("SELECT student_id, team_id FROM musabaqa_team_members WHERE event_id = 13")->fetchAll();

echo "Event 8 distinct students: " . count(array_unique(array_column($members8, 'student_id'))) . "\n";
echo "Event 13 distinct students: " . count(array_unique(array_column($members13, 'student_id'))) . "\n";

// Print some mappings
$map8 = [];
foreach ($members8 as $m) {
    $map8[$m['student_id']] = $m['team_id'];
}
$map13 = [];
foreach ($members13 as $m) {
    $map13[$m['student_id']] = $m['team_id'];
}

$same = 0;
$diff = 0;
foreach ($map8 as $sid => $tid) {
    if (isset($map13[$sid])) {
        if ($map13[$sid] == $tid) {
            $same++;
        } else {
            $diff++;
        }
    }
}
echo "Same team assignment: $same | Different: $diff\n";
