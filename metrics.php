<?php
// Prometheus-style metrics endpoint
// Optional auth via METRICS_TOKEN environment variable (?token=...)

$required = getenv('METRICS_TOKEN');
if ($required && (!isset($_GET['token']) || hash_equals($required, $_GET['token']) === false)) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo "# unauthorized\n";
    exit;
}

$frameFile = __DIR__ . '/frame.jpg';
$exists = is_file($frameFile);
$age = $exists ? (time() - filemtime($frameFile)) : -1;
$size = $exists ? filesize($frameFile) : 0;

header('Content-Type: text/plain; version=0.0.4');
echo "# HELP webcam_frame_age_seconds Age of the cached frame in seconds.\n";
echo "# TYPE webcam_frame_age_seconds gauge\n";
echo 'webcam_frame_age_seconds ' . $age . "\n";

echo "# HELP webcam_frame_size_bytes Size of the cached frame in bytes.\n";
echo "# TYPE webcam_frame_size_bytes gauge\n";
echo 'webcam_frame_size_bytes ' . $size . "\n";

echo "# HELP webcam_frame_exists Frame existence (1 exists, 0 missing).\n";
echo "# TYPE webcam_frame_exists gauge\n";
echo 'webcam_frame_exists ' . ($exists?1:0) . "\n";

// Derived metric: stale indicator (1 if age > threshold)
$staleThreshold = (int)(getenv('FRAME_STALE_THRESHOLD') ?: 10);
echo "# HELP webcam_frame_stale Stale indicator (1 if frame age > threshold).\n";
echo "# TYPE webcam_frame_stale gauge\n";
echo 'webcam_frame_stale ' . (($age > $staleThreshold && $exists)?1:0) . "\n";

echo "# HELP webcam_info Build info style static labels.\n";
echo "# TYPE webcam_info gauge\n";
$ver = '0.1.0';
echo 'webcam_info{version="' . $ver . '"} 1' . "\n";
