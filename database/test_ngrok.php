<?php
$url = 'https://phosphate-upcountry-paprika.ngrok-free.dev/BreadBreak/webhook/xendit.php';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "Target URL   : $url\n";
echo "HTTP Status  : $status\n";
echo "Body         : $res\n";
if ($status === 405) {
    echo "SUCCESS: Ngrok successfully reached BreadBreak webhook/xendit.php!\n";
} else {
    echo "Unexpected status $status\n";
}

