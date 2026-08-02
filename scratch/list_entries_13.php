<?php
require_once __DIR__ . '/../config/bootstrap.php';

$entries = $musabaqa_pdo->query("SELECT pe.*, p.title as prog_title, t.team_name FROM musabaqa_program_entries pe JOIN musabaqa_programs p ON p.id = pe.program_id JOIN musabaqa_teams t ON t.id = pe.team_id WHERE pe.event_id = 13")->fetchAll();
foreach ($entries as $e) {
    echo "ID: {$e['id']} | Prog: {$e['prog_title']} | Team: {$e['team_name']} | Entry Name: {$e['entry_name']} | Entry Number: {$e['entry_number']}\n";
}
