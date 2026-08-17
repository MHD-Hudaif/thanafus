<?php
$file = 'c:/laragon/www/kauzariyya-musabaqa/web/includes/public-data.php';
$content = file_get_contents($file);
$pos = strpos($content, 'function schedule_items');
if ($pos !== false) {
    echo substr($content, $pos, 4000) . "\n";
} else {
    echo "function schedule_items not found.\n";
}
