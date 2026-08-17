<?php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://musabaqa.kauzariyya.com/git-status.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
echo "Sending GET request to https://musabaqa.kauzariyya.com/git-status.php ...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    echo 'Error: ' . curl_error($ch) . "\n";
} else {
    echo "HTTP Status Code: $httpCode\n";
    echo "Response:\n$response\n";
}
curl_close($ch);
