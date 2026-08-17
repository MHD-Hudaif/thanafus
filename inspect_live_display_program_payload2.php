<?php
$file = 'c:/laragon/www/kauzariyya-musabaqa/web/live-display/includes/functions.php';
$content = file_get_contents($file);
$pos = strpos($content, 'stmtResults');
if ($pos !== false) {
    echo substr($content, $pos, 2000) . "\n";
} else {
    echo "stmtResults not found.\n";
}
