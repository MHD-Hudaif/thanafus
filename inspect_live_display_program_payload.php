<?php
$file = 'c:/laragon/www/kauzariyya-musabaqa/web/live-display/includes/functions.php';
$content = file_get_contents($file);
$pos = strpos($content, 'function live_display_program_payload');
if ($pos !== false) {
    echo substr($content, $pos, 1500) . "\n";
} else {
    echo "function live_display_program_payload not found.\n";
}
