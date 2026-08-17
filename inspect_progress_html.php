<?php
$file = 'c:/laragon/www/kauzariyya-musabaqa/web/admin/event-manager/progress.php';
$content = file_get_contents($file);
$lines = file($file);
foreach ($lines as $i => $line) {
    if (strpos($line, 'judges_count') !== false) {
        echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
    }
}
