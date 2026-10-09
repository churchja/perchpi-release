<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // perchpi:cli-only-guard-v1 (D-S9-1): CLI tool, never served over HTTP
/* ============================================================================
   refresh_xc_audio.php — fetch Xeno-Canto sample audio for species without
   local recordings, plus generate spectrogram PNGs for them.
   ============================================================================
   For each distinct species in birds.db that has NO real (non-ebird-import)
   detections:
     1. Query Xeno-Canto API for recordings of that species
     2. Pick the shortest A/B/C-quality "song" recording
     3. Download the MP3 to ~/BirdSongs/Extracted/xc_audio/{slug}.mp3
     4. Generate spectrogram via sox to {slug}.png
     5. Write {slug}.json sidecar with attribution metadata

   Idempotent — skips species that already have a cached MP3 unless --force.

   Run:
     php /home/birder/BirdNET-Pi/homepage/refresh_xc_audio.php
     php /home/birder/BirdNET-Pi/homepage/refresh_xc_audio.php --dry-run
     php /home/birder/BirdNET-Pi/homepage/refresh_xc_audio.php --force
   ============================================================================ */

$dry_run = in_array('--dry-run', $argv);
$force   = in_array('--force', $argv);

$home      = '/home/birder';
$db_path   = $home . '/BirdNET-Pi/scripts/birds.db';
$cache_dir = $home . '/BirdSongs/Extracted/xc_audio';

// ─── Helpers ──────────────────────────────────────────────────────────

function sci_name_slug($sci_name) {
  $s = preg_replace('/[^A-Za-z ]/', '', $sci_name);
  $s = trim($s);
  return str_replace(' ', '_', $s);
}

function xc_http_get_json($url) {
  $ua = 'BirdNET-Pi-XC-Refresh/1.0 (local home use; contact via project)';
  $ctx = stream_context_create([
    'http' => [
      'timeout' => 15,
      'header'  => "User-Agent: $ua\r\nAccept: application/json\r\n",
      'ignore_errors' => true,
    ]
  ]);
  $body = @file_get_contents($url, false, $ctx);
  if ($body === false) return null;
  return json_decode($body, true);
}

function xc_download_mp3($url, $target) {
  if (!function_exists('curl_init')) {
    fwrite(STDERR, "ERROR: PHP cURL not available\n");
    return false;
  }
  $fh = @fopen($target, 'wb');
  if (!$fh) return false;
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_FILE           => $fh,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_USERAGENT      => 'BirdNET-Pi-XC-Refresh/1.0',
    CURLOPT_HTTPHEADER     => [
      'Accept: audio/mpeg,audio/*;q=0.9,*/*;q=0.5',
      'Accept-Encoding: identity',
    ],
    CURLOPT_FAILONERROR    => true,
  ]);
  $ok = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  fclose($fh);
  if (!$ok || $code !== 200) {
    @unlink($target);
    return false;
  }
  if (filesize($target) < 1024) {
    @unlink($target);
    return false;
  }
  return true;
}

/**
 * Parse a "length" string like "0:23" or "1:45" into seconds.
 */
function xc_parse_length($len_str) {
  if (preg_match('/^(\d+):(\d+)$/', $len_str, $m)) {
    return intval($m[1]) * 60 + intval($m[2]);
  }
  if (is_numeric($len_str)) return intval($len_str);
  return 99999;  // unknown → put it last
}

/**
 * Query XC for recordings of $sci_name. Returns the best matching record
 * per the rules:
 *   - quality A preferred, fallback to B then C
 *   - "song" type preferred (call as fallback)
 *   - shortest duration within the chosen tier wins
 */
function xc_find_best($sci_name) {
  $endpoint = 'https://xeno-canto.org/api/2/recordings';
  // Quote the scientific name to match exactly
  $q = '?query=' . urlencode($sci_name);
  $data = xc_http_get_json($endpoint . $q);
  if (!$data || empty($data['recordings'])) return null;

  $recs = $data['recordings'];

  // Pre-filter: only keep recordings with file_name and a download URL
  $recs = array_filter($recs, function($r) {
    return !empty($r['file']) || !empty($r['file-name']);
  });
  if (count($recs) === 0) return null;

  // Group by quality tier
  $tiers = ['A' => [], 'B' => [], 'C' => []];
  foreach ($recs as $r) {
    $q_letter = strtoupper($r['q'] ?? '');
    if (isset($tiers[$q_letter])) $tiers[$q_letter][] = $r;
  }

  // Within each tier, prefer "song" types; sort by length ascending
  function pick_from_tier($tier_recs) {
    if (empty($tier_recs)) return null;
    $songs = array_filter($tier_recs, function($r) {
      return stripos($r['type'] ?? '', 'song') !== false;
    });
    $candidates = !empty($songs) ? $songs : $tier_recs;
    usort($candidates, function($a, $b) {
      return xc_parse_length($a['length'] ?? '99:99') - xc_parse_length($b['length'] ?? '99:99');
    });
    return $candidates[0];
  }

  foreach (['A', 'B', 'C'] as $q_letter) {
    $pick = pick_from_tier($tiers[$q_letter]);
    if ($pick) return ['record' => $pick, 'quality' => $q_letter];
  }
  return null;
}

function xc_generate_spectrogram($mp3_path, $png_path) {
  // sox can read MP3, render to PNG
  $cmd = sprintf(
    'sox -V1 %s -n remix 1 rate 24k spectrogram -c %s -o %s 2>/dev/null',
    escapeshellarg($mp3_path),
    escapeshellarg('Xeno-Canto sample'),
    escapeshellarg($png_path)
  );
  $rc = 0;
  exec($cmd, $out, $rc);
  return $rc === 0 && file_exists($png_path) && filesize($png_path) > 512;
}

// ─── Main ──────────────────────────────────────────────────────────────

if (!file_exists($db_path)) {
  fwrite(STDERR, "ERROR: birds.db not found at $db_path\n");
  exit(1);
}
if (!is_dir($cache_dir)) {
  if (!@mkdir($cache_dir, 0755, true)) {
    fwrite(STDERR, "ERROR: can't create cache dir $cache_dir\n");
    exit(1);
  }
}

// Find species WITHOUT real (non-ebird-import) recordings
$db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
$db->busyTimeout(2000);
$res = $db->query("
  SELECT DISTINCT Sci_Name, MAX(Com_Name) AS Com_Name
  FROM detections
  WHERE Sci_Name NOT IN (
    SELECT DISTINCT Sci_Name FROM detections WHERE File_Name NOT LIKE 'ebird_import_%'
  )
  GROUP BY Sci_Name
  ORDER BY Sci_Name
");
$species = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  $species[] = $row;
}
$db->close();

$total = count($species);

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Xeno-Canto audio refresh\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Cache dir:  $cache_dir\n";
echo "  Species:    $total without local recordings (xc fallback candidates)\n";
echo "  Mode:       " . ($dry_run ? "DRY RUN" : ($force ? "FORCE (re-fetch all)" : "LIVE (skip cached)")) . "\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

$counters = ['cached' => 0, 'fetched' => 0, 'no_xc_match' => 0, 'download_failed' => 0, 'spectrogram_failed' => 0];
$failed = [];

foreach ($species as $i => $sp) {
  $sci  = $sp['Sci_Name'];
  $com  = $sp['Com_Name'];
  $slug = sci_name_slug($sci);
  if ($slug === '') continue;

  $mp3_path  = $cache_dir . '/' . $slug . '.mp3';
  $png_path  = $cache_dir . '/' . $slug . '.png';
  $meta_path = $cache_dir . '/' . $slug . '.json';

  $progress = sprintf("[%2d/%d]", $i + 1, $total);
  $disp = sprintf("%-32s (%s)", $sci, $com);

  if (file_exists($mp3_path) && !$force) {
    $counters['cached']++;
    continue;
  }

  echo "$progress $disp ... ";

  if ($dry_run) {
    echo "would fetch\n";
    continue;
  }

  // Be polite to the XC API
  usleep(800 * 1000);  // 800ms

  $best = xc_find_best($sci);
  if (!$best) {
    echo "✗ no XC recording found\n";
    $counters['no_xc_match']++;
    $failed[] = "$sci ($com): no XC match";
    continue;
  }

  $rec = $best['record'];
  $url = $rec['file'] ?? null;
  if (!$url) {
    echo "✗ no download URL\n";
    $counters['no_xc_match']++;
    continue;
  }
  // XC URLs may be schemeless ("//xeno-canto.org/...") — normalize
  if (strpos($url, '//') === 0) $url = 'https:' . $url;

  if (!xc_download_mp3($url, $mp3_path)) {
    echo "✗ download failed\n";
    $counters['download_failed']++;
    $failed[] = "$sci ($com): download failed";
    continue;
  }

  if (!xc_generate_spectrogram($mp3_path, $png_path)) {
    echo "⚠ MP3 saved but spectrogram failed\n";
    $counters['spectrogram_failed']++;
  }

  // Write attribution sidecar
  @file_put_contents($meta_path, json_encode([
    'source'        => 'xeno-canto',
    'xc_id'         => $rec['id']       ?? null,
    'recordist'     => $rec['rec']      ?? '',
    'license'       => $rec['lic']      ?? '',
    'quality'       => $best['quality'],
    'type'          => $rec['type']     ?? '',
    'length'        => $rec['length']   ?? '',
    'sci_name'      => $sci,
    'com_name'      => $com,
    'descriptionurl'=> isset($rec['id']) ? "https://xeno-canto.org/{$rec['id']}" : '',
    'fetched_at'    => date('c'),
  ], JSON_PRETTY_PRINT));

  echo "✓ FETCHED (q={$best['quality']}, len={$rec['length']})\n";
  $counters['fetched']++;
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo "  Summary\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Already cached:      " . $counters['cached']             . "\n";
if (!$dry_run) {
  echo "  Newly fetched:       " . $counters['fetched']            . "\n";
  echo "  No XC match:         " . $counters['no_xc_match']        . "\n";
  echo "  Download failed:     " . $counters['download_failed']    . "\n";
  echo "  Spectrogram failed:  " . $counters['spectrogram_failed'] . "\n";
}
echo "═══════════════════════════════════════════════════════════════════════\n";

if (!empty($failed)) {
  echo "\nFailures:\n";
  foreach ($failed as $f) echo "  - $f\n";
}

echo "\nDone.\n";
