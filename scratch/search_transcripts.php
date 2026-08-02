<?php
$brainDir = 'C:\Users\hudai\.gemini\antigravity\brain';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($brainDir));
$count = 0;

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'jsonl') {
        $path = $file->getRealPath();
        $content = file_get_contents($path);
        if (strpos($content, 'limit') !== false || strpos($content, 'entries') !== false) {
            // Read line by line
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                if (trim($line) === '') continue;
                $data = json_decode($line, true);
                if (isset($data['type']) && $data['type'] === 'USER_INPUT') {
                    $userText = $data['content'] ?? '';
                    if (stripos($userText, 'limit') !== false || stripos($userText, 'entries') !== false || stripos($userText, 'event') !== false) {
                        echo "File: " . basename(dirname(dirname(dirname($path)))) . " | Line: $lineNum\n";
                        echo "User: " . trim($userText) . "\n";
                        echo "----------------------------------------\n";
                        $count++;
                        if ($count > 30) break 2;
                    }
                }
            }
        }
    }
}
