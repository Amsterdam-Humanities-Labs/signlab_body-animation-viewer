<?php
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload error']);
        exit;
    }

    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if ($ext !== 'glb') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Only .glb files are allowed']);
        exit;
    }

    if ($_FILES['file']['size'] === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'File is empty']);
        exit;
    }

    // Sanitize filename: keep only alphanumeric, hyphens, underscores, dots
    $basename = pathinfo($origName, PATHINFO_FILENAME);
    $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
    if ($sanitized === '' || $sanitized === '.') {
        $sanitized = 'upload_' . time();
    }
    $filename = $sanitized . '.glb';

    $dest = __DIR__ . '/s3d_files/' . $filename;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
        exit;
    }

    echo json_encode(['ok' => true, 'filename' => $filename]);
    exit;
}

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = trim($_GET['q'] ?? '');
    $limit = 100;

    if ($q === '') {
        // No search query: return empty list
        echo json_encode([]);
        exit;
    }

    // Load gloss cache for searching by gloss name
    $cacheFile = __DIR__ . '/gloss_cache.json';
    $glossMap = file_exists($cacheFile) ? (json_decode(file_get_contents($cacheFile), true) ?: []) : [];

    // Search gloss names first (most useful results)
    $qLower = mb_strtolower($q);
    $results = [];

    // Pass 1: match gloss names
    foreach ($glossMap as $file => $gloss) {
        if (mb_stripos($gloss, $qLower) !== false || mb_stripos($file, $qLower) !== false) {
            $results[$file] = true;
        }
        if (count($results) >= $limit) break;
    }

    // Pass 2: match filenames on disk (if room left)
    if (count($results) < $limit) {
        $patterns = ['M', 'CNGT', 'L'];
        foreach ($patterns as $prefix) {
            $files = glob(__DIR__ . '/s3d_files/' . $prefix . '*' . $q . '*.glb');
            foreach ($files as $f) {
                $name = basename($f);
                $results[$name] = true;
                if (count($results) >= $limit) break 2;
            }
        }
    }

    $names = array_keys($results);
    sort($names, SORT_STRING | SORT_FLAG_CASE);
    echo json_encode(array_values($names));
    exit;
}

if ($action === 'glossmap' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $cacheFile = __DIR__ . '/gloss_cache.json';
    $cacheMaxAge = 7 * 24 * 3600; // 1 week

    // Serve from cache if fresh
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
        echo file_get_contents($cacheFile);
        exit;
    }

    // Build gloss map from DB
    require '/web/mysql_config.php';
    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
        exit;
    }
    $conn->set_charset('utf8mb4');

    // Batch query 1: gloss names from form_data
    $sql = "SELECT mt.m_file, MIN(fd.glos) AS glos
            FROM matched_transcriptions mt
            JOIN form_data fd ON fd.id = mt.m_transcription
            WHERE mt.zOg IN ('Glos','labels','extern')
              AND mt.m_transcription != ''
            GROUP BY mt.m_file";
    $result = $conn->query($sql);
    $dbMap = [];
    while ($row = $result->fetch_assoc()) {
        $key = pathinfo($row['m_file'], PATHINFO_FILENAME);
        $dbMap[$key] = $row['glos'];
    }

    // Batch query 2: sentence fallback from sentences (zOg='zin')
    $sql2 = "SELECT mt.m_file, MIN(s.zinString) AS zinString
             FROM matched_transcriptions mt
             JOIN sentences s ON s.id = mt.m_transcription
             WHERE mt.zOg = 'zin'
               AND mt.m_transcription != ''
             GROUP BY mt.m_file";
    $result2 = $conn->query($sql2);
    $zinMap = [];
    while ($row = $result2->fetch_assoc()) {
        $key = pathinfo($row['m_file'], PATHINFO_FILENAME);
        $zinMap[$key] = $row['zinString'];
    }

    // Batch query 3: nmm fallback from nmm_data (zOg='nmm' or 'nmm_oc')
    $sql3 = "SELECT mt.m_file, MIN(nd.glos) AS glos
             FROM matched_transcriptions mt
             JOIN nmm_data nd ON nd.id = mt.m_transcription
             WHERE mt.zOg IN ('nmm','nmm_oc')
               AND mt.m_transcription != ''
             GROUP BY mt.m_file";
    $result3 = $conn->query($sql3);
    $nmmMap = [];
    while ($row = $result3->fetch_assoc()) {
        $key = pathinfo($row['m_file'], PATHINFO_FILENAME);
        $nmmMap[$key] = $row['glos'];
    }

    // Map disk files: prefer gloss, fall back to zinString, then nmm
    $files = glob(__DIR__ . '/s3d_files/M*.glb');
    $names = array_map('basename', $files);
    $glossMap = [];
    foreach ($names as $name) {
        $basename = pathinfo($name, PATHINFO_FILENAME);
        // Strip _anim suffix so anim files match the same DB entry
        $lookup = preg_replace('/_anim$/i', '', $basename);
        if (isset($dbMap[$lookup])) {
            $glossMap[$name] = $dbMap[$lookup];
        } elseif (isset($zinMap[$lookup])) {
            $glossMap[$name] = $zinMap[$lookup];
        } elseif (isset($nmmMap[$lookup])) {
            $glossMap[$name] = $nmmMap[$lookup];
        }
    }

    // CNGT files: label as "Corpus NGT"
    $cngtFiles = glob(__DIR__ . '/s3d_files/CNGT*.glb');
    foreach ($cngtFiles as $f) {
        $name = basename($f);
        $label = pathinfo($name, PATHINFO_FILENAME);
        $label = preg_replace('/_anim$/i', '', $label);
        $glossMap[$name] = 'Corpus NGT — ' . $label;
    }

    $conn->close();

    $json = json_encode($glossMap, JSON_UNESCAPED_UNICODE);
    file_put_contents($cacheFile, $json);
    echo $json;
    exit;
}

// Parse VTT file: merge all segments into one [start, end] in seconds
$vttDir = '/web/gebarenoverleg_media/studioFilesMini/raw/';
function parseVttSegment($animFile) {
    global $vttDir;
    $base = preg_replace('/_anim\.glb$/i', '', $animFile);
    $vttPath = $vttDir . $base . '.vtt';
    if (!file_exists($vttPath)) return null;
    $content = file_get_contents($vttPath);
    $minStart = PHP_FLOAT_MAX;
    $maxEnd = 0;
    if (preg_match_all('/(\d{2}):(\d{2}):(\d{2})\.(\d{3})\s*-->\s*(\d{2}):(\d{2}):(\d{2})\.(\d{3})/', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $start = $m[1]*3600 + $m[2]*60 + $m[3] + $m[4]/1000;
            $end = $m[5]*3600 + $m[6]*60 + $m[7] + $m[8]/1000;
            if ($start < $minStart) $minStart = $start;
            if ($end > $maxEnd) $maxEnd = $end;
        }
    }
    if ($maxEnd > 0) return [round($minStart, 3), round($maxEnd, 3)];
    return null;
}

if ($action === 'vttset' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $cacheFile = __DIR__ . '/vtt_set_cache.json';
    $cacheMaxAge = 7 * 24 * 3600; // 1 week
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
        echo file_get_contents($cacheFile);
        exit;
    }
    $animFiles = glob(__DIR__ . '/s3d_files/M*_anim.glb');
    $set = [];
    foreach ($animFiles as $f) {
        $name = basename($f);
        $seg = parseVttSegment($name);
        if ($seg) {
            $set[$name] = $seg;
        }
    }
    $json = json_encode($set, JSON_UNESCAPED_UNICODE);
    file_put_contents($cacheFile, $json);
    echo $json;
    exit;
}

if ($action === 'senseindex' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $cacheFile = __DIR__ . '/sense_index_cache.json';
    $cacheMaxAge = 7 * 24 * 3600; // 1 week

    // Serve from cache if fresh
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
        echo file_get_contents($cacheFile);
        exit;
    }

    require '/web/mysql_config.php';
    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
        exit;
    }
    $conn->set_charset('utf8mb4');

    $entries = []; // fd_id => {glos, senses, m_files[]}

    // Route 1: Glos/labels/extern → form_data directly
    $sql1 = "SELECT fd.id AS fd_id, fd.glos, fd.senses, mt.m_file
             FROM form_data fd
             JOIN matched_transcriptions mt ON mt.m_transcription = fd.id
               AND mt.zOg IN ('Glos','labels','extern')
             WHERE fd.senses IS NOT NULL AND fd.senses != '' AND fd.senses != '[]'
               AND mt.m_file IS NOT NULL AND mt.m_file != ''";
    $result1 = $conn->query($sql1);
    while ($row = $result1->fetch_assoc()) {
        $fdId = $row['fd_id'];
        if (!isset($entries[$fdId])) {
            $entries[$fdId] = ['glos' => $row['glos'], 'senses' => $row['senses'], 'm_files' => []];
        }
        $entries[$fdId]['m_files'][] = $row['m_file'];
    }

    // Route 2: nmm → nmm_data.signbank_id → form_data.signbank
    $sql2 = "SELECT fd.id AS fd_id, fd.glos, fd.senses, mt.m_file
             FROM matched_transcriptions mt
             JOIN nmm_data nd ON nd.id = mt.m_transcription
             JOIN form_data fd ON fd.signbank = nd.signbank_id
             WHERE mt.zOg IN ('nmm','nmm_oc')
               AND mt.m_file IS NOT NULL AND mt.m_file != ''
               AND fd.senses IS NOT NULL AND fd.senses != '' AND fd.senses != '[]'";
    $result2 = $conn->query($sql2);
    while ($row = $result2->fetch_assoc()) {
        $fdId = $row['fd_id'];
        if (!isset($entries[$fdId])) {
            $entries[$fdId] = ['glos' => $row['glos'], 'senses' => $row['senses'], 'm_files' => []];
        }
        $entries[$fdId]['m_files'][] = $row['m_file'];
    }

    $conn->close();

    // Build reverse index: word → [{glos, file, senses, seg?}, ...]
    $index = [];
    foreach ($entries as $fdId => $entry) {
        // Find first m_file that exists on disk as _anim.glb
        $animFile = null;
        foreach ($entry['m_files'] as $mf) {
            $base = preg_replace('/\.wav$/i', '', $mf);
            $candidate = $base . '_anim.glb';
            if (file_exists(__DIR__ . '/s3d_files/' . $candidate)) {
                $animFile = $candidate;
                break;
            }
        }
        if (!$animFile) continue;

        // Parse senses JSON array
        $senses = json_decode($entry['senses'], true);
        if (!is_array($senses)) continue;

        $glos = $entry['glos'];
        $sensesStr = implode(', ', $senses);
        $seg = parseVttSegment($animFile);
        $variant = ['glos' => $glos, 'file' => $animFile, 'senses' => $sensesStr];
        if ($seg) $variant['seg'] = $seg;

        foreach ($senses as $senseItem) {
            // Split comma-separated synonyms within each element
            $words = preg_split('/[,\s]+/', $senseItem, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($words as $word) {
                $word = mb_strtolower(trim($word));
                if ($word === '') continue;
                // Dedup by glos per word
                if (!isset($index[$word])) $index[$word] = [];
                $found = false;
                foreach ($index[$word] as $existing) {
                    if ($existing['glos'] === $glos) { $found = true; break; }
                }
                if (!$found) {
                    $index[$word][] = $variant;
                }
            }
        }
    }

    // Sort variants alphabetically per word
    foreach ($index as &$variants) {
        usort($variants, function($a, $b) { return strcasecmp($a['glos'], $b['glos']); });
    }
    unset($variants);

    $json = json_encode($index, JSON_UNESCAPED_UNICODE);
    file_put_contents($cacheFile, $json);
    echo $json;
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Invalid action. Use ?action=upload|list|glossmap|senseindex|vttset']);
