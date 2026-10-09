<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // perchpi:cli-only-guard-v1 (D-S9-1): CLI tool, never served over HTTP
/* ============================================================================
   refresh_bird_photos.php -- provider-aware photo refresher (v5)
   ============================================================================
   // perchpi:refresh-bird-photos-v5

   v5 changes from v4:
     - Reads IMAGE_PROVIDER from /etc/birdnet/birdnet.conf at startup.
     - Early-exit when provider is None (empty value).
     - Cache files provider-suffixed: <slug>.wikipedia.jpg.
     - perchpi:flickr-stripped-v1 — Flickr code paths removed.

   Run:
     php /home/birder/BirdNET-Pi/homepage/refresh_bird_photos.php
     php /home/birder/BirdNET-Pi/homepage/refresh_bird_photos.php --dry-run
   ============================================================================ */

$dry_run = in_array('--dry-run', $argv);

$home      = '/home/birder';
$db_path   = $home . '/BirdNET-Pi/scripts/birds.db';
$cache_dir = $home . '/BirdSongs/Extracted/bird_images';

// --- read IMAGE_PROVIDER ----------------------------------------------------

$config_path = '/etc/birdnet/birdnet.conf';
$image_provider = '';

$config_lines = @file($config_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($config_lines !== false) {
  foreach ($config_lines as $line) {
    if (preg_match('/^IMAGE_PROVIDER=(.*)$/', $line, $m)) {
      $image_provider = strtolower(trim(trim($m[1]), '"\''));
    }
  }
}

if ($image_provider === '') {
  echo "Image provider is None. Skipping refresh run.\n";
  exit(0);
}

if ($image_provider !== 'wikipedia') {
  fwrite(STDERR, "Unknown IMAGE_PROVIDER value '$image_provider'. Skipping.\n");
  exit(0);
}

// --- helpers ----------------------------------------------------------------

function sci_name_slug($sci_name) {
  $s = preg_replace('/[^A-Za-z ]/', '', $sci_name);
  $s = trim($s);
  return str_replace(' ', '_', $s);
}

/* JSON API calls -- file_get_contents works fine for these */
function rb_http_get_json($url, &$is_transient = false) {
  $is_transient = false;
  $ua = "BirdNET-Pi-Refresh/1.1 (https://github.com/Nachtzuster/BirdNET-Pi; PerchPi fork)";
  $ctx = stream_context_create([
    'http' => [
      'timeout'       => 12,
      'header'        => "User-Agent: $ua\r\nAccept: application/json\r\n",
      'ignore_errors' => true,
    ]
  ]);
  for ($attempt = 0; $attempt < 2; $attempt++) {
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (!empty($http_response_header) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
      $status = intval($m[1]);
    }
    if ($body !== false && $status >= 200 && $status < 300) return $body;
    // 408 (timeout) and 429 (rate limit) are explicitly transient
    if ($status === 408 || $status === 429) {
      if ($attempt === 0) { usleep(2000000); continue; }  // 2s backoff before retry
      $is_transient = true;
      return null;
    }
    if ($status >= 400 && $status < 500) return null;
    if ($attempt === 0) { usleep(500000); continue; }
  }
  $is_transient = true;
  return null;
}

/* Image downloads -- use cURL to handle redirects + send full headers */
function rb_download_image_curl($url, $target) {
  if (!function_exists('curl_init')) {
    fwrite(STDERR, "  WARNING: cURL not available, falling back to file_get_contents\n");
    $bytes = @file_get_contents($url);
    if ($bytes === false || strlen($bytes) < 512) return false;
    return @file_put_contents($target, $bytes) !== false;
  }

  $fh = @fopen($target, 'wb');
  if (!$fh) return false;

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_FILE           => $fh,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => 'BirdNET-Pi-Refresh/1.1 (https://github.com/Nachtzuster/BirdNET-Pi; PerchPi fork)',
    CURLOPT_HTTPHEADER     => [
      'Accept: image/jpeg,image/png,image/webp,image/*;q=0.9,*/*;q=0.5',
      'Accept-Encoding: identity',
      'Accept-Language: en-US,en;q=0.9',
      'Referer: https://en.wikipedia.org/',
    ],
    CURLOPT_FAILONERROR    => true,
  ]);

  $ok = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  fclose($fh);

  if (!$ok) {
    @unlink($target);
    if ($http_code >= 400 && $http_code < 500) {
      return ['_miss_kind' => 'permanent', 'http_code' => $http_code];
    }
    return ['_miss_kind' => 'transient', 'http_code' => $http_code, 'err' => $err];
  }

  if (filesize($target) < 512) {
    @unlink($target);
    return ['_miss_kind' => 'transient', 'reason' => 'too small'];
  }
  return true;
}

/* Wikipedia fetch -- preserved verbatim from v4 */
function rb_wikipedia_image($sci_name, $com_name) {
  $page_title = str_replace(' ', '_', $sci_name);
  $is_transient = false;

  $body = rb_http_get_json("https://en.wikipedia.org/api/rest_v1/page/summary/$page_title", $is_transient);
  if ($body === null) {
    return ['_miss_kind' => $is_transient ? 'transient' : 'permanent'];
  }
  $data = json_decode($body, true);
  if (!is_array($data) || !isset($data['originalimage']['source'])) {
    // perchpi:wp-empty-image-as-transient-v1
    // Treat 200-OK-but-no-image as transient (1h retry) not permanent (24h).
    // Wikipedia REST API occasionally omits originalimage even when one
    // exists; 24h lockout caused real species to get trapped (Veery,
    // Bell's Vireo, BC Night-Heron, Sciurus carolinensis all hit this).
    return ['_miss_kind' => 'transient'];
  }

  $image_url = $data['originalimage']['source'];
  $title = $data['title'] ?? $sci_name;
  $image_name = substr($image_url, strrpos($image_url, '/') + 1);

  // perchpi:refresh-bird-photos-v5.2-urlfix
  // Trust originalimage.source as-is. The v4/v5 transformation
  // (commons -> commons/thumb + 1280px prefix) produces malformed URLs
  // when the API already returns a thumb-style source URL. Net effect of
  // removing the transformation: we get whatever Wikipedia delivers.

  // Pull license / attribution from commons API.
  $artist = '';
  $license = '';
  $license_name = '';
  $description_url = '';

  $meta_body = rb_http_get_json("https://commons.wikimedia.org/w/api.php?action=query&titles=File:$image_name&prop=imageinfo&iiprop=extmetadata|url&format=json", $is_transient);
  if ($meta_body !== null) {
    $meta = json_decode($meta_body, true);
    if (is_array($meta) && isset($meta['query']['pages'])) {
      foreach ($meta['query']['pages'] as $page) {
        $info = $page['imageinfo'][0] ?? null;
        if (!$info) continue;
        $details = $info['extmetadata'] ?? [];
        if (isset($details['Artist']['value'])) {
          $artist_html = $details['Artist']['value'];
          $artist = trim(strip_tags(str_replace(['&nbsp;', "\n"], ' ', $artist_html)));
        }
        if (isset($details['LicenseShortName']['value'])) {
          $license_name = trim(strip_tags($details['LicenseShortName']['value']));
        }
        if (isset($details['LicenseUrl']['value'])) {
          $license = $details['LicenseUrl']['value'];
        }
        if (isset($info['descriptionurl'])) {
          $description_url = $info['descriptionurl'];
        }
      }
    }
  }

  if ($description_url === '') {
    $description_url = "https://en.wikipedia.org/wiki/File:" . $image_name;
  }

  return [
    'url'            => $image_url,
    'artist'         => $artist,
    'license'        => $license,
    'license_name'   => $license_name,
    'title'          => $title,
    'descriptionurl' => $description_url,
  ];
}

// --- main -------------------------------------------------------------------

if (!is_dir($cache_dir)) {
  if (!@mkdir($cache_dir, 0755, true)) {
    fwrite(STDERR, "ERROR: can't create cache dir $cache_dir\n");
    exit(1);
  }
}

$pdo = new PDO("sqlite:$db_path");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// perchpi:refresh-script-species-flag-v1 — optional --species "Sci_Name" filter
$single_species = null;
foreach ($argv as $i => $arg) {
  if ($arg === '--species' && isset($argv[$i+1])) { $single_species = $argv[$i+1]; }
}
if ($single_species !== null) {
  $stmt = $pdo->prepare("SELECT DISTINCT Sci_Name, Com_Name FROM detections WHERE Sci_Name = ? ORDER BY Sci_Name");
  $stmt->execute([$single_species]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $rows = $pdo->query("SELECT DISTINCT Sci_Name, Com_Name FROM detections ORDER BY Sci_Name")->fetchAll(PDO::FETCH_ASSOC);
}
$total = count($rows);

echo "=======================================================================\n";
echo "  Bird photo refresh (v5 -- provider-aware)\n";
echo "=======================================================================\n";
echo "  Cache dir:       $cache_dir\n";
echo "  Provider:        Wikipedia\n";
echo "  Species:         $total distinct in DB\n";
echo "  cURL:            " . (function_exists('curl_init') ? 'available' : 'NOT AVAILABLE') . "\n";
echo "  Mode:            " . ($dry_run ? 'DRY RUN' : 'LIVE') . "\n";
echo "=======================================================================\n";

$counters = ['cached' => 0, 'fetched' => 0, 'missing' => 0];
$missing_list = [];
$idx = 0;

foreach ($rows as $row) {
  $idx++;
  $sci = $row['Sci_Name'];
  $com = $row['Com_Name'] ?? $sci;
  $slug = sci_name_slug($sci);

  if ($slug === '') continue;

  // Provider-suffixed cache paths
  $jpg_path        = $cache_dir . '/' . $slug . '.' . $image_provider . '.jpg';
  $meta_path       = $cache_dir . '/' . $slug . '.' . $image_provider . '.json';
  $miss_path       = $cache_dir . '/' . $slug . '.' . $image_provider . '.miss';
  $transient_path  = $cache_dir . '/' . $slug . '.' . $image_provider . '.transient';

  // Already cached?
  if (file_exists($jpg_path)) {
    $counters['cached']++;
    continue;
  }
  // Permanent miss within 24h? But evict if we can't write to it
  // (foreign-owned sentinels from old web-render path). perchpi:force-evict-stale-sentinels-v1
  if (file_exists($miss_path) && (time() - filemtime($miss_path)) < 86400) {
    if (!is_writable($miss_path)) {
      // Foreign-owned (likely caddy from pre-readonly era). Try to evict.
      if (@unlink($miss_path)) {
        echo "[EVICT] stale foreign-owned .miss sentinel for $sci
";
      } else {
        $counters['missing']++;
        $missing_list[] = "$sci ($com)";
        continue;
      }
    } else {
      $counters['missing']++;
      $missing_list[] = "$sci ($com)";
      continue;
    }
  }
  // Transient miss within 1h? Worth retrying.
  $would_retry = file_exists($transient_path) && (time() - filemtime($transient_path)) < 3600;
  $marker = $would_retry ? 'would retry' : 'fetch';

  printf("[%3d/%-3d] %-35s %-30s ... ", $idx, $total, $sci, "($com)");

  if ($dry_run) {
    echo "$marker\n";
    continue;
  }

  // Fetch from Wikipedia (only supported provider — perchpi:flickr-stripped-v1)
  $info = rb_wikipedia_image($sci, $com);
  // perchpi:refresh-bird-photos-v5.1-throttle
  // Politeness delay between Wikipedia API hits (skipped in dry-run).
  if (!$dry_run) { usleep(500000); }

  if (isset($info['_miss_kind'])) {
    $sentinel = ($info['_miss_kind'] === 'transient') ? $transient_path : $miss_path;
    @touch($sentinel);
    @unlink($info['_miss_kind'] === 'transient' ? $miss_path : $transient_path);
    echo "[FAIL] no Wikipedia image\n";
    $counters['missing']++;
    $missing_list[] = "$sci ($com)";
    continue;
  }

  $dl = rb_download_image_curl($info['url'], $jpg_path);
  if (is_array($dl) && isset($dl['_miss_kind'])) {
    $sentinel = ($dl['_miss_kind'] === 'transient') ? $transient_path : $miss_path;
    @touch($sentinel);
    echo "[FAIL] download failed\n";
    $counters['missing']++;
    $missing_list[] = "$sci ($com)";
    continue;
  }

  // Write attribution metadata
  @file_put_contents($meta_path, json_encode([
    'artist'         => $info['artist'],
    'license'        => $info['license'],
    'license_name'   => $info['license_name'] ?? '',
    'title'          => $info['title'] ?? '',
    'descriptionurl' => $info['descriptionurl'],
    'source_url'     => $info['url'],
    'provider'       => $image_provider,
    'fetched_at'     => date('c'),
  ], JSON_PRETTY_PRINT));

  // Remove stale negative-cache markers since we succeeded
  @unlink($miss_path);
  @unlink($transient_path);

  echo "[OK] FETCHED\n";
  $counters['fetched']++;
}

echo "\n=======================================================================\n";
echo "  Summary\n";
echo "=======================================================================\n";
echo "  Already cached: " . $counters['cached']  . "\n";
if (!$dry_run) {
  echo "  Newly fetched:  " . $counters['fetched'] . "\n";
  echo "  Still missing:  " . $counters['missing'] . "\n";
}
echo "=======================================================================\n";
if (!$dry_run && !empty($missing_list)) {
  echo "\nSpecies still without photos:\n";
  foreach ($missing_list as $name) {
    echo "  - $name\n";
  }
}
echo "\nDone.\n";
/* perchpi:refresh-bird-photos-v5.3 (429-transient + 24h-miss-ttl) */
