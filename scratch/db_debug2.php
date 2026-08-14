<?php
require_once dirname(__DIR__) . '/config/bootstrap.php';
$stmt = $musabaqa_pdo->prepare("
    SELECT mp.id, mp.title, mp.location, mp.class_type_id, mp.stage_type_id,
           mst.category AS stage_category,
           mp.start_time AS start_at, mp.end_time AS end_at
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
    WHERE mp.title LIKE '%English Speech%'
");
$stmt->execute();
$progs = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($progs);
