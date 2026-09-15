<?php
// No upstream video request: this checks Apache, PHP and the local dispatcher.
$request = curl_init('http://127.0.0.1/short_videos/sv2.php');
curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
$body = curl_exec($request);
$status = curl_getinfo($request, CURLINFO_HTTP_CODE);
curl_close($request);
$data = is_string($body) ? json_decode($body, true) : null;
exit($status === 400 && ($data['code'] ?? null) === 400 && ($data['source'] ?? null) === 'local' ? 0 : 1);
