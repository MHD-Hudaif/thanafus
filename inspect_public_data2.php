<?php
$file = 'c:/laragon/www/kauzariyya-musabaqa/web/includes/public-data.php';
$content = file_get_contents($file);
$pos = strpos($content, 'final_score');
if ($pos !== false) {
    echo substr($content, $pos, 2000) . "\n";
} else {
    echo "final_score not found.\n";
}
