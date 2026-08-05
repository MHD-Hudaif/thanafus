<?php
header('Content-Type: text/plain');
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'not set') . "\n";
echo "Current File: " . __FILE__ . "\n";
echo "Dirname: " . dirname(__FILE__) . "\n";
if (file_exists('.env')) {
    echo ".env size: " . filesize('.env') . " bytes\n";
    $env = file_get_contents('.env');
    if (preg_match('/APP_BASE_URL=(.*)/i', $env, $matches)) {
        echo "APP_BASE_URL in .env: " . trim($matches[1]) . "\n";
    } else {
        echo "APP_BASE_URL not found in .env\n";
    }
} else {
    echo ".env file not found\n";
}
?>
