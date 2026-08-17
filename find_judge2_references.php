<?php
$dir = __DIR__;

function search_judge2($directory) {
    $results = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());
            if (stripos($content, 'judge2_total') !== false || stripos($content, 'Judge 2 Total') !== false) {
                $results[] = $file->getPathname();
            }
        }
    }
    return $results;
}

$found = search_judge2($dir);
echo "Files containing references to Judge 2:\n";
print_r($found);
