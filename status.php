<?php
// Simple status endpoint (JSON) for monitoring.
// Optional auth: provide STATUS_TOKEN in environment or .env; request with ?token=...

header('Content-Type: application/json');
$required = getenv('STATUS_TOKEN');
if ($required && (!isset($_GET['token']) || hash_equals($required, $_GET['token']) === false)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'unauthorized']);
    exit;
}

$frameFile = __DIR__ . '/frame.jpg';
$info = [
  'ok' => true,
  'time' => time(),
  'php_uptime' => ini_get('request_time') ? (time() - (int)ini_get('request_time')) : null,
  'frame' => [
      'exists' => is_file($frameFile),
      'size' => is_file($frameFile) ? filesize($frameFile) : 0,
      'age_sec' => is_file($frameFile) ? (time() - filemtime($frameFile)) : null,
  ],
  'env' => [
      'rtsp_user' => getenv('RTSP_USER') ?: null,
      'rtsp_host' => getenv('RTSP_HOST') ?: null,
      'rtsp_path' => getenv('RTSP_PATH') ?: null,
  ],
];
// Do not expose password.
unset($info['env']['rtsp_pass']);

echo json_encode($info, JSON_PRETTY_PRINT);
