<?php
require_once __DIR__ . '/../config/bootstrap.php';

$sheetsCount = $musabaqa_pdo->query("SELECT COUNT(*) FROM musabaqa_score_sheets")->fetchColumn();
$catScoresCount = $musabaqa_pdo->query("SELECT COUNT(*) FROM musabaqa_category_scores")->fetchColumn();

echo "Total score sheets: $sheetsCount\n";
echo "Total category scores: $catScoresCount\n";
