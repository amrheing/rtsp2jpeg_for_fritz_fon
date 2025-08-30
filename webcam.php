<?php
// Moved the filepath comment inside PHP to avoid raw output in browser
// ***
// update für nvr
//
// rtsp://stramer:Scotti.01@172.25.10.218/Preview_05_sub
// Reolink NVR: Preview_XX_main and _sub. XX is the camera channel
// Reolink Camera: Preview_01_main and _sub
// 
// ****

// Optional .env loader (simple parser: KEY=VALUE lines, ignores comments)
// Place a .env file in the same directory with entries like:
// RTSP_USER=streamer
// RTSP_PASS=secret
// RTSP_HOST=192.168.1.10
// RTSP_PATH=/Preview_05_sub
// Lines starting with # are ignored. Only loaded if not already in environment.
($envFile = __DIR__.'/.env') && is_file($envFile) && is_readable($envFile) && (function($envFile){
    foreach (file($envFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        if (!strpos($line,'=')) continue;
        list($k,$v) = explode('=',$line,2);
        $k = trim($k); $v = trim($v);
        if ($k !== '' && getenv($k) === false) {
            putenv($k.'='.$v);
            $_ENV[$k] = $v; // make available to PHP
        }
    }
})($envFile);

// ---- RTSP CONFIG (edit these 4 values) ----
$config = [
    'rtsp_user' => getenv('RTSP_USER') ?: 'streamer',
    // Sensitive password removed; provide via environment variable RTSP_PASS (e.g. Apache SetEnv or systemd EnvironmentFile)
    'rtsp_pass' => getenv('RTSP_PASS') ?: 'CHANGE_ME',
    'rtsp_host' => getenv('RTSP_HOST') ?: '172.25.10.218',
    'rtsp_path' => getenv('RTSP_PATH') ?: '/Preview_05_sub', // include leading slash
    // Default vertical slice for plain /snapshot.jpg or /image.jpg (format=jpeg) when
    // no &slice= or &crop= provided. Format: parts:from-to (e.g. 5:3-4). Set to '' to disable.
    'default_snapshot_slice' => getenv('DEFAULT_SNAPSHOT_SLICE') ?: '5:3-4',
    // FritzFon compatibility mode settings
    'fritz_enable' => filter_var(getenv('FRITZ_ENABLE') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'fritz_user_agent_substr' => getenv('FRITZ_USER_AGENT') ?: 'AVM',   // substring to detect in UA; or use ?fritz=1
    'fritz_max_width' => (int)(getenv('FRITZ_MAX_WIDTH') ?: 640),             // scale down to at most this width
    'fritz_jpeg_quality' => (int)(getenv('FRITZ_JPEG_QUALITY') ?: 8),            // slightly higher number = lower quality (smaller size); 2..31
    'fritz_cache_ttl' => (int)(getenv('FRITZ_CACHE_TTL') ?: 3),               // seconds to reuse last snapshot for Fritz requests
    'fritz_cache_dir' => getenv('FRITZ_CACHE_DIR') ?: sys_get_temp_dir(), // directory for cached snapshot
    'fritz_default_slice' => getenv('FRITZ_DEFAULT_SLICE') ?: '5:3-4',      // default slice for /fritz.jpg if none specified
    // --- Performance / latency tuning ---
    'fast_mode' => filter_var(getenv('FAST_MODE') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'fast_frame_cache' => getenv('FAST_FRAME_CACHE') ?: '/var/www/webcam/frame.jpg', // if file exists & is fresh, serve instead of ffmpeg
    'fast_frame_max_age' => (int)(getenv('FAST_FRAME_MAX_AGE') ?: 2),            // seconds; 0 disables cache usage
    'hwaccel' => getenv('HWACCEL') ?: 'none',                  // placeholder flag to append hw acceleration options (rpi|none)
    'fast_fallback' => filter_var(getenv('FAST_FALLBACK') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'public_base_url' => getenv('PUBLIC_BASE_URL') ?: 'http://192.168.1.52', // base URL reachable from Fritz VLAN (no trailing slash)
];
// Build URL from config
$rtspUrl = sprintf(
    'rtsp://%s:%s@%s%s',
    rawurlencode($config['rtsp_user']),
    rawurlencode($config['rtsp_pass']),
    $config['rtsp_host'],
    $config['rtsp_path']
);

// Optional: allow overriding via ?rtsp= (URL-encoded) if desired
if (!empty($_GET['rtsp'])) {
    $rtspUrl = $_GET['rtsp'];
}

// Helper to locate ffmpeg once
function findFfmpeg() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = trim(shell_exec('command -v ffmpeg 2>/dev/null'));
    return $cached;
}

// Optional transport override (?transport=udp)
$rtspTransport = (isset($_GET['transport']) && $_GET['transport'] === 'udp') ? 'udp' : 'tcp';

// Debug flag (?debug=1) to output diagnostics instead of image/stream
$debug = !empty($_GET['debug']);

// -----------------------------------------------------------------------------
// Cropping & slicing parameters
// slice=parts:from-to (vertical slices, 1-based indices). Example slice=5:3-4 keeps middle two fifths.
// crop=WxH (pixels) overrides slice and produces a centered crop.
// q=2..31 quality (lower is better), default 3.
// -----------------------------------------------------------------------------
$cropFilter = '';
$cropDesc = '';
if (!empty($_GET['slice']) && preg_match('/^(\d+):(\d+)-(\d+)$/', $_GET['slice'], $sm)) {
    $parts = (int)$sm[1];
    $from = (int)$sm[2];
    $to   = (int)$sm[3];
    if ($parts > 0 && $from >= 1 && $to >= $from && $to <= $parts) {
        // width fraction = number of selected parts / total parts; left offset = (from-1)/parts
        $cropFilter = "crop=(iw*($to-$from+1)/$parts):(ih):(iw*($from-1)/$parts):0";
        $cropDesc = "slice=$parts:$from-$to";
    }
}
if (!empty($_GET['crop']) && preg_match('/^(\d+)x(\d+)$/', $_GET['crop'], $cm)) {
    $cw = (int)$cm[1];
    $ch = (int)$cm[2];
    if ($cw > 0 && $ch > 0) {
        $cropFilter = sprintf('crop=%d:%d:(iw-%d)/2:(ih-%d)/2', $cw, $ch, $cw, $ch);
        $cropDesc = "crop={$cw}x{$ch}";
    }
}
$q = 3; // default JPEG quality
if (isset($_GET['q']) && ctype_digit($_GET['q'])) {
    $qi = (int)$_GET['q'];
    if ($qi >= 2 && $qi <= 31) $q = $qi;
}

// FritzFon detection & adjustments (applies only to snapshot endpoints)
$isFritz = false;
if (!empty($config['fritz_enable'])) {
    if (!empty($_GET['fritz'])) {
        $isFritz = true;
    } elseif (!empty($_SERVER['HTTP_USER_AGENT']) && stripos($_SERVER['HTTP_USER_AGENT'], $config['fritz_user_agent_substr']) !== false) {
        $isFritz = true;
    }
}
// We'll append scale later when building filter for snapshot if needed.

// Helper: validate JPEG magic
function isJpeg($data) {
    return $data !== null && $data !== '' && strncmp($data, "\xFF\xD8\xFF", 3) === 0;
}

// Robust snapshot grabber (multi-attempt)
function grabSnapshot($ffmpeg, $rtspUrl, $preferredTransport, $debug, $quick = false) {
    global $cropFilter, $q, $isFritz, $config; // access crop filter & quality & fritz
    $tmpFile = sys_get_temp_dir() . '/webcam_snapshot_' . uniqid() . '.jpg';
    $common = '-hide_banner -nostdin';
    $redir = $debug ? '2>&1' : '2>/dev/null';
    $alt = ($preferredTransport === 'tcp') ? 'udp' : 'tcp';
    $fullFilter = $cropFilter;
    if ($isFritz && !empty($config['fritz_max_width'])) {
        // Append scale while preserving aspect ratio; only shrink if wider.
        $mw = (int)$config['fritz_max_width'];
        if ($mw > 0) {
            $scaleExpr = "scale='min(${mw},iw)':-1";
            $fullFilter = $fullFilter ? $fullFilter . ',' . $scaleExpr : $scaleExpr;
        }
    }
    // Hardware accel flag (snapshot path) if configured
    $hw = '';
    if (!empty($config['hwaccel']) && $config['hwaccel']==='rpi') {
        // For H.264 streams on Raspberry Pi use v4l2m2m decoder when available
        $hw = '-c:v h264_v4l2m2m';
    }
    $filterPart = $fullFilter ? ' -vf ' . escapeshellarg($fullFilter) . ' ' : ' ';
    // Prepend a very simple robust attempt closely mirroring the manual test but outputting JPEG directly
    // Detect whether ffmpeg supports -stimeout (cache result)
    static $supportsStimeout = null;
    if ($supportsStimeout === null) {
        $probe = shell_exec(escapeshellcmd($ffmpeg).' -hide_banner -stimeout 1000000 -version 2>&1');
        $supportsStimeout = ($probe && stripos($probe, 'Unrecognized option') === false && stripos($probe, 'Invalid') === false);
    }
    $timeoutFlag = function($micros) use ($supportsStimeout) {
        $micros = (int)$micros;
        if ($supportsStimeout) return "-stimeout $micros";
        // some builds only have -timeout
        return "-timeout $micros"; // if also unsupported, ffmpeg will ignore silently
    };

    $fast = !empty($config['fast_mode']);
    if ($fast) {
        $attempts = [
            ['label'=>"fast-prim","cmd"=>"%s $common -rtsp_transport %s ".$timeoutFlag(2000000)." -fflags nobuffer -flags low_delay $hw -i %s".$filterPart."-an -frames:v 1 -q:v $q -f mjpeg - $redir"],
            ['label'=>"fast-alt","cmd"=>"%s $common -rtsp_transport %s ".$timeoutFlag(2500000)." -fflags nobuffer -flags low_delay $hw -i %s".$filterPart."-an -frames:v 1 -q:v $q -f mjpeg - $redir", 'transport'=>$alt],
        ];
    } else {
        $attempts = [
            ['label'=>"baseline-simple","cmd"=>"%s $common -rtsp_transport %s -timeout 15000000 $hw -i %s".$filterPart."-frames:v 1 -q:v $q -f mjpeg - $redir"],
            ['label'=>"simple-$preferredTransport",
             'cmd'=>"%s $common -rtsp_transport %s ".$timeoutFlag(4000000)." $hw -i %s".$filterPart."-an -frames:v 1 -q:v $q -f mjpeg - $redir"],
            ['label'=>"preferred-$preferredTransport",
             'cmd'=>"%s $common -rtsp_transport %s ".$timeoutFlag(8000000)." $hw -i %s".$filterPart."-an -vframes 1 -q:v $q -f mjpeg - $redir"],
            ['label'=>"preferred-probe-$preferredTransport",
             'cmd'=>"%s $common -rtsp_transport %s -rtsp_flags prefer_tcp ".$timeoutFlag(12000000)." -rw_timeout 12000000 -analyzeduration 100M -probesize 100M $hw -i %s".$filterPart."-an -vframes 1 -q:v $q -f mjpeg - $redir"],
            ['label'=>"alt-$alt",
             'cmd'=>"%s $common -rtsp_transport %s ".$timeoutFlag(15000000)." $hw -i %s".$filterPart."-an -vframes 1 -q:v $q -f mjpeg - $redir",
             'transport'=>$alt],
        ];
    }
    if (!$quick) {
        $attempts[] = ['label'=>"file-preferred-$preferredTransport",
                       'cmd'=>"%s $common -rtsp_transport %s -stimeout 18000000 -i %s".$filterPart."-an -vframes 1 -q:v $q -y %s $redir",
                       'file'=>true];
    }

    $results = [];
    foreach ($attempts as $a) {
        $transport = isset($a['transport']) ? $a['transport'] : $preferredTransport;
        $cmd = sprintf(
            $a['cmd'],
            escapeshellcmd($ffmpeg),
            escapeshellarg($transport),
            escapeshellarg($rtspUrl),
            isset($a['file']) ? escapeshellarg($tmpFile) : null
        );
        $cmd = str_replace(" null $redir"," $redir",$cmd);
        $start = microtime(true);
        $data = null;
        $stderrSample = '';
        if (!empty($a['file'])) {
            @unlink($tmpFile);
            $output = shell_exec($cmd);
            if ($debug && $output) $stderrSample = substr(trim($output),0,120);
            if (is_file($tmpFile)) $data = @file_get_contents($tmpFile);
        } else {
            $output = shell_exec($cmd);
            if ($debug && $output) $stderrSample = substr(trim($output),0,160);
            $data = $output;
            if ($debug && $data && strpos($data, "\xFF\xD8\xFF") !== false) {
                $soi = strpos($data, "\xFF\xD8\xFF");
                $eoi = strrpos($data, "\xFF\xD9");
                if ($eoi !== false) {
                    $jpegCandidate = substr($data, $soi, $eoi - $soi + 2);
                    if (isJpeg($jpegCandidate)) $data = $jpegCandidate;
                }
            }
        }
        $duration = round((microtime(true)-$start)*1000);
        $ok = isJpeg($data);
        $results[] = [
            'label'=>$a['label'],'cmd'=>$cmd,
            'bytes'=>$data?strlen($data):0,
            'ok'=>$ok,'ms'=>$duration,
            'head'=>$data?bin2hex(substr($data,0,8)):'',
            'stderr'=>$stderrSample,
        ];
        if ($ok) {
            if (is_file($tmpFile)) @unlink($tmpFile);
            return [$data,$results];
        }
        if ($quick) break; // only first attempt in quick mode
    }
    if (is_file($tmpFile)) @unlink($tmpFile);
    // Fallback path if fast mode failed and fallback enabled
    if ($fast && !empty($config['fast_fallback'])) {
        $fallbackAttempts = [
            ['label'=>"fb-simple","cmd"=>"%s $common -rtsp_transport %s ".$timeoutFlag(6000000)." $hw -i %s".$filterPart."-an -frames:v 1 -q:v $q -f mjpeg - $redir"],
            ['label'=>"fb-probe","cmd"=>"%s $common -rtsp_transport %s -rtsp_flags prefer_tcp -rw_timeout 12000000 -analyzeduration 50M -probesize 50M $hw -i %s".$filterPart."-an -vframes 1 -q:v $q -f mjpeg - $redir"],
            ['label'=>"fb-alt","cmd"=>"%s $common -rtsp_transport %s ".$timeoutFlag(9000000)." $hw -i %s".$filterPart."-an -vframes 1 -q:v $q -f mjpeg - $redir", 'transport'=>$alt],
        ];
        foreach ($fallbackAttempts as $a) {
            $transport = isset($a['transport']) ? $a['transport'] : $preferredTransport;
            $cmd = sprintf(
                $a['cmd'],
                escapeshellcmd($ffmpeg),
                escapeshellarg($transport),
                escapeshellarg($rtspUrl)
            );
            $start = microtime(true);
            $data = shell_exec($cmd);
            if ($debug && $data && strpos($data, "\xFF\xD8\xFF") !== false) {
                $soi = strpos($data, "\xFF\xD8\xFF");
                $eoi = strrpos($data, "\xFF\xD9");
                if ($eoi !== false) {
                    $jpegCandidate = substr($data, $soi, $eoi - $soi + 2);
                    if (isJpeg($jpegCandidate)) $data = $jpegCandidate;
                }
            }
            $duration = round((microtime(true)-$start)*1000);
            $ok = isJpeg($data);
            $results[] = [
                'label'=>$a['label'],'cmd'=>$cmd,
                'bytes'=>$data?strlen($data):0,
                'ok'=>$ok,'ms'=>$duration,
                'head'=>$data?bin2hex(substr($data,0,8)):''
            ];
            if ($ok) {
                return [$data,$results];
            }
        }
    }
    return [null,$results];
}

// **********************************************************************************
// MJPEG Stream output using ffmpeg (mpjpeg)
// **********************************************************************************
function printStream($rtspUrl) {
    global $rtspTransport, $debug, $cropFilter, $q, $cropDesc; // include crop filter
    $ffmpeg = findFfmpeg();
    if ($ffmpeg === '') {
        if ($debug) header('Content-Type: text/plain');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'ffmpeg not found';
        return;
    }

    // Quick connectivity test: try to grab 1 frame (timeout ~5s)
    $testCmd = sprintf(
        '%s -nostdin -rtsp_transport %s -timeout 5000000 -loglevel error -i %s -frames:v 1 -f null - 2>&1',
        escapeshellcmd($ffmpeg),
        escapeshellarg($rtspTransport),
        escapeshellarg($rtspUrl)
    );
    $testOutput = shell_exec($testCmd);
    if ($testOutput !== null && stripos($testOutput, 'Output file is empty') !== false) {
        if ($debug) {
            header('Content-Type: text/plain');
            echo "RTSP test failed:\n".$testOutput;
        } else {
            header('HTTP/1.1 502 Bad Gateway');
            echo 'Camera not reachable';
        }
        return;
    }

    if ($debug) {
        header('Content-Type: text/plain');
        echo "Debug OK. Starting stream...\nCommand:\n";
        // Show the actual streaming command (without password leakage if any)
    }

    if (function_exists('ignore_user_abort')) ignore_user_abort(true);
    @set_time_limit(0);

    // Disable all output buffering
    while (ob_get_level() > 0) { @ob_end_flush(); }
    header("X-Accel-Buffering: no");

    if (!$debug) {
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: -1");
        header("Content-Type: multipart/x-mixed-replace; boundary=ffmpeg"); // added space before boundary
        header("Content-Disposition: inline; filename=\"stream.mjpeg\"");
    if (!headers_sent() && !empty($cropDesc)) header('X-Crop: ' . $cropDesc);
    }

    $vf = $cropFilter ? ' -vf ' . escapeshellarg($cropFilter) . ' ' : ' ';
    $cmd = sprintf(
        '%s -nostdin -loglevel error -rtsp_transport %s -i %s -an%s-c:v mjpeg -q:v %d -f mpjpeg - 2>/dev/null',
        escapeshellcmd($ffmpeg),
        escapeshellarg($rtspTransport),
        escapeshellarg($rtspUrl),
        $vf,
        $q
    );

    $proc = @popen($cmd, 'r');
    if (!$proc) {
        if ($debug) echo "Failed to start ffmpeg process";
        else echo "Failed to start ffmpeg";
        return;
    }
    if ($debug) echo "Streaming...\n";

    // Stream loop
    while (!feof($proc)) {
        $chunk = fread($proc, 16384);
        if ($chunk === false) break;
        echo $chunk;
        if (function_exists('flush')) flush();
        if (function_exists('ob_flush')) @ob_flush();
    }
    pclose($proc);
}

// **********************************************************************************
// Single JPEG snapshot using ffmpeg
// **********************************************************************************
function printImage($rtspUrl) {
    global $rtspTransport, $debug, $quickSnapshot, $cropDesc, $isFritz, $config, $q, $cropFilter; // include crop filter
    $ffmpeg = findFfmpeg();
    if ($ffmpeg === '') {
        if ($debug) header('Content-Type: text/plain');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'ffmpeg not found';
        return;
    }
    // Fast frame cache (shared producer) - only if no debug & cache configured
    if (!$debug && !empty($config['fast_frame_cache']) && $config['fast_frame_max_age'] > 0) {
        $f = $config['fast_frame_cache'];
        if (is_file($f)) {
            $age = time() - filemtime($f);
            if ($age <= (int)$config['fast_frame_max_age']) {
                $data = @file_get_contents($f);
                if ($data && isJpeg($data)) {
                    $transformed = false;
                    // If a crop slice or fritz scaling is required, transform cached frame using ffmpeg.
                    if ($cropFilter !== '' || ($isFritz && !empty($config['fritz_max_width']))) {
                        $ffmpeg = findFfmpeg();
                        if ($ffmpeg !== '') {
                            $fullFilter = $cropFilter;
                            if ($isFritz && !empty($config['fritz_max_width'])) {
                                $mw = (int)$config['fritz_max_width'];
                                if ($mw > 0) {
                                    $scaleExpr = "scale='min(${mw},iw)':-1";
                                    $fullFilter = $fullFilter ? $fullFilter . ',' . $scaleExpr : $scaleExpr;
                                }
                            }
                            if ($fullFilter) {
                                $cmd = sprintf('%s -hide_banner -loglevel error -i %s -vf %s -frames:v 1 -q:v %d -f mjpeg - 2>/dev/null',
                                    escapeshellcmd($ffmpeg),
                                    escapeshellarg($f),
                                    escapeshellarg($fullFilter),
                                    $q
                                );
                                $out = shell_exec($cmd);
                                if ($out && isJpeg($out)) {
                                    $data = $out;
                                    $transformed = true;
                                }
                            }
                        }
                    }
                    if (!headers_sent() && $cropDesc) header('X-Crop: '.$cropDesc);
                    if (!headers_sent()) header('X-Source: cache');
                    if ($transformed && !headers_sent()) header('X-Cache-Transformed: 1');
                    header("Cache-Control: no-store, no-cache, must-revalidate");
                    header("Pragma: no-cache");
                    header("Expires: -1");
                    header("Content-Type: image/jpeg");
                    header("Content-Disposition: inline; filename=\"snapshot.jpg\"");
                    header("Content-Length: " . strlen($data));
                    echo $data;
                    return;
                }
            }
        }
    }
    // Fritz cache: try reuse last snapshot
    $cacheFile = null; $useCache = false;
    if ($isFritz && !empty($config['fritz_cache_ttl'])) {
        $dir = rtrim($config['fritz_cache_dir'], '/');
        if (is_dir($dir) && is_writable($dir)) {
            $cacheFile = $dir . '/fritz_snapshot_cache.jpg';
            if (is_file($cacheFile) && (time() - filemtime($cacheFile)) <= (int)$config['fritz_cache_ttl']) {
                $data = @file_get_contents($cacheFile);
                if ($data && isJpeg($data)) {
                    $useCache = true;
                }
            }
        }
    }
    // Override quality for Fritz
    if ($isFritz && isset($config['fritz_jpeg_quality'])) {
        $fq = (int)$config['fritz_jpeg_quality'];
        if ($fq>=2 && $fq<=31) $q = $fq;
    }
    if ($useCache) {
        if (!headers_sent() && $cropDesc) header('X-Crop: '.$cropDesc);
        if (!headers_sent()) header('X-Cache: fritz');
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: -1");
        header("Content-Type: image/jpeg");
        header("Content-Disposition: inline; filename=\"snapshot.jpg\"");
        header("Content-Length: " . strlen($data));
        echo $data;
        return;
    }
    list($data, $attemptInfo) = grabSnapshot($ffmpeg, $rtspUrl, $rtspTransport, $debug, $quickSnapshot);

    if ($debug) {
        header('Content-Type: text/plain');
        foreach ($attemptInfo as $row) {
            echo sprintf("[%s] ok=%s bytes=%d time=%dms head=%s\n%s\n",
                $row['label'],
                $row['ok'] ? 'yes':'no',
                $row['bytes'],
                $row['ms'],
                $row['head'],
                $row['cmd']
            );
            if (!$row['ok'] && !empty($row['stderr'])) {
                echo "stderr: ". $row['stderr'] ."\n\n";
            } else {
                echo "\n";
            }
        }
        if ($data) {
            echo "Final: SUCCESS (" . strlen($data) . " bytes)\n";
        } else {
            echo "Final: FAILED (no valid JPEG)\n";
            echo "\nManual test suggestion:\n";
            echo "ffmpeg -rtsp_transport tcp -i " . escapeshellarg($rtspUrl) . " -frames:v 1 test.jpg -loglevel debug\n";
        }
        return;
    }

    if (!$data) {
        // Summarize attempts for easier troubleshooting without full debug
        if (!headers_sent()) {
            $summary = [];
            foreach ($attemptInfo as $a) { $summary[] = $a['label'].':'.($a['ok']?'ok':'fail').'/'.$a['ms'].'ms'; }
            header('X-Debug-Attempts: '.substr(implode(',', $summary),0,900));
        }
        error_log('[webcam.php] Snapshot failure attempts='.(isset($attemptInfo)?count($attemptInfo):0));
        header('HTTP/1.1 502 Bad Gateway');
        header('Content-Type: text/plain');
        echo 'Failed to capture frame (add &debug=1 for details)';
        return;
    }

    if ($data) {
        if (!headers_sent() && $cropDesc) header('X-Crop: '.$cropDesc);
        // Detect if fallback used by scanning attempt labels
        if (!headers_sent()) {
            foreach ($attemptInfo as $ai) { if (strpos($ai['label'],'fb-')===0) { header('X-Fallback: 1'); break; } }
        }
        if ($isFritz && $cacheFile && !headers_sent()) header('X-Cache: miss');
        // Store in Fritz cache if applicable
        if ($isFritz && $cacheFile) {
            @file_put_contents($cacheFile, $data);
        }
    }

    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: -1");
    header("Content-Type: image/jpeg");
    header("Content-Disposition: inline; filename=\"snapshot.jpg\"");
    header("Content-Length: " . strlen($data));
    echo $data;
}

// **********************************************************************************
// Input handling: keep format param; other legacy params ignored now
// **********************************************************************************
$format = isset($_GET["format"]) ? strtolower($_GET["format"]) : 'mjpeg';

$quickSnapshot = false; // global flag for reduced attempts

// Direct snapshot alias for FritzBox: request /image.jpg OR /snapshot.jpg
if (preg_match('~/(image|snapshot)\.jpg$~i', $_SERVER['REQUEST_URI'])) {
    $quickSnapshot = true; // speed: only first attempt
    // Apply configured default slice if neither slice nor crop specified
    if ($cropFilter === '' && empty($_GET['slice']) && empty($_GET['crop']) && !empty($config['default_snapshot_slice'])) {
        if (preg_match('/^(\d+):(\d+)-(\d+)$/', $config['default_snapshot_slice'], $dm)) {
            $parts=(int)$dm[1]; $from=(int)$dm[2]; $to=(int)$dm[3];
            if ($parts>0 && $from>=1 && $to>=$from && $to<=$parts) {
                $cropFilter = "crop=(iw*($to-$from+1)/$parts):(ih):(iw*($from-1)/$parts):0";
                $cropDesc = "default-slice=$parts:$from-$to";
            }
        }
    }
    printImage($rtspUrl);
    exit;
}

// Dedicated Fritz endpoint: /fritz.jpg (always snapshot + fritz mode + its own default slice)
if (preg_match('~/fritz\.jpg$~i', $_SERVER['REQUEST_URI'])) {
    $quickSnapshot = true;
    $isFritz = true; // force fritz mode
    if ($cropFilter === '' && empty($_GET['slice']) && empty($_GET['crop']) && !empty($config['fritz_default_slice'])) {
        if (preg_match('/^(\d+):(\d+)-(\d+)$/', $config['fritz_default_slice'], $fm)) {
            $parts=(int)$fm[1]; $from=(int)$fm[2]; $to=(int)$fm[3];
            if ($parts>0 && $from>=1 && $to>=$from && $to<=$parts) {
                $cropFilter = "crop=(iw*($to-$from+1)/$parts):(ih):(iw*($from-1)/$parts):0";
                $cropDesc = "fritz-slice=$parts:$from-$to";
            }
        }
    }
    // Mark endpoint explicitly
    if (!headers_sent()) header('X-Endpoint: fritz');
    printImage($rtspUrl);
    exit;
}

// If this script is accessed via a symlink/copy named image.jpg -> output snapshot immediately
if (basename($_SERVER['SCRIPT_NAME']) === 'image.jpg') {
    $quickSnapshot = true;
    if ($cropFilter === '' && empty($_GET['slice']) && empty($_GET['crop']) && !empty($config['default_snapshot_slice'])) {
        if (preg_match('/^(\d+):(\d+)-(\d+)$/', $config['default_snapshot_slice'], $dm)) {
            $parts=(int)$dm[1]; $from=(int)$dm[2]; $to=(int)$dm[3];
            if ($parts>0 && $from>=1 && $to>=$from && $to<=$parts) {
                $cropFilter = "crop=(iw*($to-$from+1)/$parts):(ih):(iw*($from-1)/$parts):0";
                $cropDesc = "default-slice=$parts:$from-$to";
            }
        }
    }
    printImage($rtspUrl);
    exit;
}

// Simple UI page (add ?ui=1)
if (!empty($_GET['ui'])) {
    $self = htmlspecialchars(basename(__FILE__), ENT_QUOTES, 'UTF-8');
    $base = $self . '?';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="utf-8"><title>Webcam Test</title>
    <style>
        body { font-family: Arial, sans-serif; background:#111; color:#eee; margin:20px; }
        img, video { max-width:100%; background:#000; }
        .row { display:flex; gap:20px; flex-wrap:wrap; }
        .card { flex:1 1 420px; min-width:320px; }
        a, button { color:#0bf; }
    </style>
    <meta http-equiv="Cache-Control" content="no-store" />
    </head>
    <body>
        <h1>Webcam Preview</h1>
        <div class="row">
            <div class="card">
                <h2>MJPEG Stream</h2>
                <img src="<?php echo $self; ?>?format=mjpeg" alt="Stream">
            </div>
            <div class="card">
                <h2>Snapshot</h2>
                <p><img id="shot" src="<?php echo $self; ?>?format=jpeg&ts=<?php echo time(); ?>" alt="Snapshot"></p>
                <p>
                    <button onclick="document.getElementById('shot').src='<?php echo $self; ?>?format=jpeg&ts='+(Date.now())">Refresh Snapshot</button>
                </p>
            </div>
        </div>
        <h3>Links</h3>
        <ul>
            <li>Stream: <code><?php echo $self; ?>?format=mjpeg</code></li>
            <li>Snapshot: <code><?php echo $self; ?>?format=jpeg</code></li>
            <li>Debug snapshot: <code><?php echo $self; ?>?format=jpeg&amp;debug=1</code></li>
        </ul>
    </body>
    </html>
    <?php
    exit;
}

// Route
if ($format === "jpeg") {
    printImage($rtspUrl);
} else {
    printStream($rtspUrl);
}
