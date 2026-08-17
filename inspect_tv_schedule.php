<?php
$file = 'c:/laragon/www/kauzariyya-musabaqa/web/live-display/includes/functions.php';
$content = file_get_contents($file);
$pos = strpos($content, 'function tv_schedule');
if ($pos !== false) {
    echo substr($content, $pos, 1500) . "\n";
} else {
    echo "function tv_schedule not found.\n";
}
