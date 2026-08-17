<?php
$dir = __DIR__;
function find_func($directory, $funcName) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());
            $pos = strpos($content, $funcName);
            if ($pos !== false) {
                echo "Found in: " . $file->getPathname() . "\n";
                echo substr($content, $pos, 1500) . "\n\n";
            }
        }
    }
}
find_func($dir, 'function live_display_schedule');
