<?php
// EMBEDDED flag added by perchpi patch
$EMBEDDED = $EMBEDDED ?? false;
/**
 * BirdNET-Pi Kiosk UI
 * Touch-optimized single-page interface for 7" Raspberry Pi displays (1024x600).
 * Drop-in companion to the existing BirdNET-Pi web UI.
 *
 * Tabs: overview (default), today, species, spectrogram
 * Usage: http://<pi>/kiosk.php  (default = overview)
 *        http://<pi>/kiosk.php?tab=today
 */

require_once 'scripts/common.php';
require_once __DIR__ . '/perchpi_helpers.php';  // perchpi:phase2a-pills
// perchpi:photo-helpers-relocation-v1 - 12 photo/media helpers (bird_image_dir,
// sci_name_slug, bird_http_get, wikipedia_image_info, download_bird_image,
// bird_photo_provider, bird_photo_url_for, bird_photo_meta_for, spectrogram_url_for,
// thumbnail_info_for, thumbnail_url_for, audio_url_for) now live in perchpi_helpers.php.

// Sanitize GET params. FILTER_SANITIZE_STRING was deprecated in PHP 8.1 and
// removed in PHP 8.4 — use htmlspecialchars on each value instead, which is
// what the sanitizer did anyway.
$_GET = is_array($_GET) ? array_map(
  function ($v) { return is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v; },
  $_GET
) : [];

$config = get_config();
set_timezone();

$site_name = get_sitename();
$home = get_home();
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
$valid_tabs = ['overview', 'today', 'species', 'spectrogram'];
if (!in_array($tab, $valid_tabs, true)) {
  $tab = 'overview';
}

// Location for Open-Meteo, from birdnet.conf only. perchpi:s21a2-g3-v1 (G3): no built-in fallback;
// without LATITUDE/LONGITUDE the weather tile says "location not configured".
$lat = isset($config['LATITUDE']) && $config['LATITUDE'] !== '' ? floatval($config['LATITUDE']) : null;
$lon = isset($config['LONGITUDE']) && $config['LONGITUDE'] !== '' ? floatval($config['LONGITUDE']) : null;

/* perchpi:photo-helpers-phase25b-v1 -- com_to_slug relocated to perchpi_helpers.php */

/* perchpi:photo-helpers-phase25b-v1 -- detection_filepath relocated to perchpi_helpers.php */

/* perchpi:photo-helpers-phase26b-v1 -- fetch_recent_detections relocated to perchpi_helpers.php */


/**
 * Return a flat array of detection rows representing the last $species_limit
 * DISTINCT species. For each species, includes:
 *   - the most-recent detection (the "head")
 *   - any consecutive same-species detections that immediately precede the
 *     head (the "tail" — used for inline expand)
 *
 * The rows are returned in the same order they appear in the raw newest-first
 * stream, so the existing client-side collapse JS will group them naturally
 * (consecutive same-species rows → one collapsed row with +N badge).
 *
 * If a species has no consecutive predecessors (a one-off detection), it
 * appears as a single row with no badge.
 */
function fetch_recent_distinct_species($species_limit = 5) {
  // Fetch a generous window of raw detections to cover N distinct species
  // even if one species dominates. 200 is plenty for any realistic feeder.
  $raw = fetch_recent_detections(200);

  $output = [];
  $seen_species = [];   // sci names already represented in output
  $i = 0;
  $count = count($raw);

  while ($i < $count && count($seen_species) < $species_limit) {
    $head = $raw[$i];
    $sci  = $head['Sci_Name'];

    // Skip species we've already added (a non-consecutive recurrence)
    if (in_array($sci, $seen_species, true)) {
      $i++;
      continue;
    }

    // Add the head row
    $output[] = $head;
    $seen_species[] = $sci;

    // Walk forward to find consecutive same-species predecessors
    $j = $i + 1;
    while ($j < $count && $raw[$j]['Sci_Name'] === $sci) {
      $output[] = $raw[$j];
      $j++;
    }
    $i = $j;
  }

  return $output;
}

function fetch_latest_detection() {
  $db = get_db();
  $stmt = $db->prepare(
    "SELECT Com_Name, Sci_Name, Date, Time, Confidence, File_Name, Geomodel_Prob, Geomodel_Week
     FROM detections
     WHERE detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
     ORDER BY Date DESC, Time DESC
     LIMIT 1"
  );
  ensure_db_ok($stmt);
  $result = $stmt->execute();
  return $result->fetchArray(SQLITE3_ASSOC);
}

/* perchpi:photo-helpers-phase26b-v1 -- fetch_top_species_today relocated to perchpi_helpers.php */

/**
 * Try to find a spectrogram PNG next to a detection's .wav/.mp3 file.
 * Returns a URL path (under /By_Date/) if found, else null.
 */
// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - spectrogram_url_for() now in perchpi_helpers.php

/**
 * Bird photo cache.
 *
 * Images live at ~/BirdSongs/Extracted/bird_images/{sci_slug}.jpg
 * Metadata (photographer credit, source URL, license) sits next to the image
 * as {sci_slug}.json.
 *
 * Lookups are keyed by scientific name, which is stable unlike common names
 * (which vary by locale and can change between BirdNET versions).
 *
 * On a cache miss, we attempt a Wikipedia fetch. If that fails, we return
 * null and the caller falls back to the detection's spectrogram thumbnail.
 *
 * Wikimedia API is used because:
 *   - it's designed for third-party consumption (no ToS concerns)
 *   - images are CC BY-SA or public domain (attribution required)
 *   - works offline after first fetch via local cache
 */
// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - bird_image_dir() now in perchpi_helpers.php

// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - sci_name_slug() now in perchpi_helpers.php

/**
 * Low-level HTTP GET with one retry on failure. Returns response body or null.
 * Sets $is_transient_failure to true if the failure was a timeout/network error
 * (vs. a real 404/missing-page response which counts as a permanent miss).
 */
// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - bird_http_get() now in perchpi_helpers.php

/**
 * Fetch the lead image for a Wikipedia article via the action API.
 * Tries scientific name first (more reliable), then common name as fallback.
 *
 * Returns:
 *   On success: ['url' => ..., 'artist' => ..., 'license' => ..., 'descriptionurl' => ...]
 *   On miss:    ['_miss_kind' => 'permanent' | 'transient']
 *
 * 'permanent' means Wikipedia responded but had no usable image (no article,
 * no lead image). 'transient' means we couldn't reach Wikipedia at all.
 */
// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - wikipedia_image_info() now in perchpi_helpers.php

/**
 * Download a remote image into the cache. Returns:
 *   path on success, or
 *   ['_miss_kind' => 'permanent'|'transient'] on failure.
 */

// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - download_bird_image() now in perchpi_helpers.php

/**
 * Return a URL path (served by Caddy) to the cached bird photo for a species,
 * or null if unavailable. Lazily fetches from Wikipedia on first miss.
 *
 * Caching strategy:
 *   - Successful fetch:                   {slug}.jpg + {slug}.json (forever)
 *   - Permanent miss (no article found):  {slug}.miss      (24 hours)
 *   - Transient miss (network/timeout):   {slug}.transient (1 hour)
 *
 * The caller should treat a null return as "no photo — use spectrogram".
 */
/* perchpi:image-provider-aware-photos */

/**
 * Returns the user-configured image provider, lower-cased, or null.
 * null means "None" (no image lookup; show spectrogram fallback).
 * Otherwise returns 'wikipedia'. (perchpi:flickr-stripped-v1)
 *
 * Reads IMAGE_PROVIDER from /etc/birdnet/birdnet.conf via get_config().
 * (kiosk.php requires_once common.php at line 14, so get_config() is in scope.)
 */
// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - bird_photo_provider() now in perchpi_helpers.php

// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - bird_photo_url_for() now in perchpi_helpers.php

/**
 * Load attribution metadata for a cached photo, if present.
 */
// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - bird_photo_meta_for() now in perchpi_helpers.php

/**
 * Unified thumbnail resolver used by PHP rendering and the AJAX endpoint.
 *
 * Returns an array:
 *   [
 *     'thumb'  => '/bird_images/...jpg' | '/By_Date/.../...png' | null,
 *     'is_bird_photo' => bool,
 *     'meta'   => ['artist'=>..., 'license'=>..., 'descriptionurl'=>...] | null
 *   ]
 *
 * Prefers a cached bird photo; falls back to the detection's spectrogram.
 */
// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - thumbnail_info_for() now in perchpi_helpers.php

/**
 * Backwards-compat wrapper — older call sites that only need the URL.
 * Kept so existing calls still work while rendering code is updated below.
 */
// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - thumbnail_url_for() now in perchpi_helpers.php

/**
 * Read peak audio level (0-100) from the most recent BirdNET recording.
 *
 * BirdNET-Pi writes 15-second WAV files to StreamData/ continuously. Rather
 * than opening the live ALSA device (which would conflict with arecord), we
 * read the tail of the most recent WAV and compute the peak amplitude of
 * the last ~0.5 seconds of samples. Result is what BirdNET itself is hearing.
 *
 * Returns null if no WAV found or file unreadable.
 */
function get_mic_level() {
  global $home;
  $stream_dir = $home . '/BirdSongs/StreamData';
  if (!is_dir($stream_dir)) return null;

  // Find the most recent .wav file (not the one being actively written — the
  // one just before it, which is complete). We pick the newest whose size
  // suggests a complete recording.
  $wavs = glob($stream_dir . '/*.wav');
  if (!$wavs) return null;

  // Sort by mtime descending
  usort($wavs, function ($a, $b) { return filemtime($b) - filemtime($a); });

  // Try the newest few — the very newest may still be written.
  foreach (array_slice($wavs, 0, 3) as $path) {
    $size = @filesize($path);
    if ($size === false || $size < 2048) continue;

    // Parse WAV header enough to get sample format. We assume 16-bit PCM,
    // which is BirdNET-Pi's arecord default (-f S16_LE). Fall back gracefully.
    $fh = @fopen($path, 'rb');
    if (!$fh) continue;

    $header = fread($fh, 44);
    if (strlen($header) < 44 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
      fclose($fh);
      continue;
    }

    // Extract bits_per_sample from offset 34 (little-endian uint16)
    $bps = unpack('v', substr($header, 34, 2))[1];
    $channels = unpack('v', substr($header, 22, 2))[1];
    $sample_rate = unpack('V', substr($header, 24, 4))[1];
    if ($bps !== 16 || $channels < 1 || $sample_rate < 8000) {
      fclose($fh);
      continue;
    }

    // Read the last ~0.5s worth of samples, but cap at 64 KiB to stay cheap.
    // 0.5s at 48kHz stereo 16-bit = 96 KB — we'll clamp.
    $frame_bytes = 2 * $channels;
    $tail_samples = min(48000 / 2, 32768);
    $tail_bytes = intval($tail_samples) * $frame_bytes;
    $tail_bytes = min($tail_bytes, $size - 44);

    fseek($fh, $size - $tail_bytes);
    $raw = fread($fh, $tail_bytes);
    fclose($fh);
    if (!$raw || strlen($raw) < $frame_bytes) continue;

    // Walk samples and find the peak absolute value.
    // unpack 's*' gives signed 16-bit little-endian array; s is native but
    // little-endian matches our Pi, and arecord -f S16_LE writes LE anyway.
    $samples = unpack('s*', $raw);
    if (!$samples) continue;

    $peak = 0;
    foreach ($samples as $s) {
      $a = abs($s);
      if ($a > $peak) $peak = $a;
    }

    // Map 0..32767 to 0..100 using dBFS scale so the meter is perceptually
    // useful. Pure linear peak/32768 makes quiet audio look like nothing.
    if ($peak < 1) return 0;
    $dbfs = 20 * log10($peak / 32768.0);  // range: roughly -90 (silent) to 0 (clipping)
    // PerchPi: recalibrated 2026-05-01 for window-mounted Clippy EM272.
    // Original mapped -60..0 dBFS -> 0..100, which rendered real bird calls
    // (peaking around -45 dBFS at 25-35ft) as 0-25% - looked broken.
    // New map: -70..-10 dBFS -> 0..100, anchored to the useful range for this
    // install.  quiet ambient around 20-30%, active birds around 40-55%,
    // loud events 70%+, clipping danger 100%.
    $level = intval(round(($dbfs + 70) / 60 * 100));
    if ($level < 0) $level = 0;
    if ($level > 100) $level = 100;
    return $level;
  }

  return null;
}

/**
 * Lightweight system-status probes. Called from the AJAX endpoint so the
 * Overview's System card shows real values instead of placeholders.
 */
function get_system_status() {
  // Disk usage on the partition containing BirdSongs (usually /)
  $disk_pct = null;
  $df = @shell_exec("df -P /home 2>/dev/null | awk 'NR==2 {gsub(\"%\",\"\",$5); print $5}'");
  if ($df !== null && $df !== "") {
    $disk_pct = intval(trim($df));
  }

  // Analyzer service status — prefer birdnet_analysis.service
  $analyzer_ok = false;
  $status = @shell_exec("systemctl is-active birdnet_analysis.service 2>/dev/null");
  if ($status !== null && trim($status) === "active") {
    $analyzer_ok = true;
  }

  // Mic input level (0-100) — reads the tail of the most recent WAV
  $mic_level = get_mic_level();

  return [
    'disk_pct'    => $disk_pct,
    'analyzer_ok' => $analyzer_ok,
    'mic_level'   => $mic_level,
  ];
}

// perchpi:photo-helpers-relocation-v1-removed-from-kiosk - audio_url_for() now in perchpi_helpers.php

/**
 * JSON endpoint for client-side auto-refresh (used by ?ajax=1).
 */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  header('Content-Type: application/json');
  $summary = get_summary();
  $latest = fetch_latest_detection();
  $recent = fetch_recent_distinct_species(5);

  $latest_out = null;
  if ($latest) {
    $linfo = thumbnail_info_for($latest);
    $latest_out = [
      'com_name'      => $latest['Com_Name'],
      'sci_name'      => $latest['Sci_Name'],
      'date'          => $latest['Date'],
      'time'          => $latest['Time'],
      'confidence'    => round($latest['Confidence'] * 100),
      'audio'         => audio_url_for($latest),
      'spectrogram'   => spectrogram_url_for($latest),  // perchpi:dock-scrubber-h3-v1
      'thumb'         => $linfo['thumb'],
      'is_bird_photo' => $linfo['is_bird_photo'],
      'photo_artist'  => $linfo['meta']['artist']         ?? null,
      'photo_license' => $linfo['meta']['license']        ?? null,
      'photo_source'  => $linfo['meta']['descriptionurl'] ?? null,
      'pill_html'     => pp_geomodel_pill_for_row($latest),
    ];
  }
  $recent_out = [];
  foreach ($recent as $r) {
    $rinfo = thumbnail_info_for($r);
    $recent_out[] = [
      'com_name'      => $r['Com_Name'],
      'sci_name'      => $r['Sci_Name'],
      'time'          => $r['Time'],
      'confidence'    => round($r['Confidence'] * 100),
      'audio'         => audio_url_for($r),
      'spectrogram'   => spectrogram_url_for($r),  // perchpi:dock-scrubber-v1
      'thumb'         => $rinfo['thumb'],
      'is_bird_photo' => $rinfo['is_bird_photo'],
      'photo_artist'  => $rinfo['meta']['artist']         ?? null,
      'photo_license' => $rinfo['meta']['license']        ?? null,
      'photo_source'  => $rinfo['meta']['descriptionurl'] ?? null,
      'pill_html'     => pp_geomodel_pill_for_row($r),
    ];
  }
  echo json_encode([
    'latest' => $latest_out,
    'recent' => $recent_out,
    'summary' => [
      'today'   => intval($summary['todaycount']),
      'species' => intval($summary['speciestally']),
      'hour'    => intval($summary['hourcount']),
      'total'   => intval($summary['totalcount']),
    ],
    'system' => get_system_status(),
    'now' => date('c'),
  ]);
  exit;
}

// --- Data for full page render ---
$summary = get_summary();
$latest  = fetch_latest_detection();
$recent  = fetch_recent_distinct_species(5);
$system  = get_system_status();

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<?php if (!$EMBEDDED): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= h($site_name) ?> — Kiosk</title>
<meta name="viewport" content="width=1024, initial-scale=1, user-scalable=no">
<link rel="shortcut icon" href="/images/bird.png">
<link rel="stylesheet" href="kiosk.css?v=<?= @filemtime(__DIR__ . '/kiosk.css') ?: time() ?>">
<script>
  window.KIOSK = {
    tab: <?= json_encode($tab) ?>,
    lat: <?= json_encode($lat) ?>,
    lon: <?= json_encode($lon) ?>,
    refreshMs: 20000
  };
</script>
<style>
/* perchpi:kiosk-live-audio-tile-v1 — Live Audio tile styles */
.pp-sys-grid-4col { grid-template-columns: repeat(4, 1fr) !important; }
@media (max-width: 900px) {
  .pp-sys-grid-4col { grid-template-columns: repeat(2, 1fr) !important; }
}
.sys-item--live-audio { cursor: pointer; user-select: none; }
.sys-item--live-audio:hover { background: rgba(255,255,255,0.04); }
.sys-item--live-audio:active { transform: scale(0.98); }
#kla-dot {
  width: 10px; height: 10px; border-radius: 50%;
  background: #888;
  transition: background 0.3s ease;
  flex-shrink: 0;
}
#kla-dot.live {
  background: #e74c3c;
  animation: kla-pulse 1.6s ease-in-out infinite;
}
#kla-dot.buffering {
  background: #f1c40f;
  animation: kla-pulse 0.8s ease-in-out infinite;
}
#kla-dot.error {
  background: #c0392b;
}
@keyframes kla-pulse {
  0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.6); }
  50%      { opacity: 0.6; box-shadow: 0 0 0 6px rgba(231, 76, 60, 0); }
}
#kla-audio { display: none; }
</style>
</head>
<body data-tab="<?= h($tab) ?>">

<?php endif; ?>
<!-- =================== HEADER =================== -->
<?php if (!$EMBEDDED): ?>
<header class="header">
  <div class="header-left">
    <div class="site-title"><?= h($site_name) ?></div>
  </div>
  <div class="header-right">
    <div class="weather" id="weather">
      <span class="weather-icon" id="weather-icon">—</span>
      <span class="weather-temp" id="weather-temp">—</span>
      <span class="weather-meta" id="weather-meta"></span>
    </div>
    <div class="clock">
      <div class="clock-time" id="clock-time">—</div>
      <div class="clock-date" id="clock-date">—</div>
    </div>
    <a class="admin-btn" href="/index.php" title="Admin / Full interface">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
    </a>
  </div>
</header>
<?php endif; ?>

<!-- =================== CONTENT =================== -->
<?php if (!$EMBEDDED): ?>
<main class="content" id="content">
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
  <!-- ========== OVERVIEW ========== -->
  <section class="section section-latest">
    <div class="section-label pp-bw-label-row"><!-- perchpi:overview-birdweather-link-v1 -->
      <span>Latest Detection</span>
<?php $pp_bw_station = trim((string)($config['BIRDWEATHER_STATION'] ?? '')); /* perchpi:bw-station-link-v1 (D-S6-13): public station number, never BIRDWEATHER_ID (upload token) */ if (!empty($EMBEDDED) && ctype_digit($pp_bw_station)): ?><a class="pp-bw-overview-link" href="https://app.birdweather.com/stations/<?php echo $pp_bw_station; ?>" target="_blank" rel="noopener noreferrer" title="View your station on BirdWeather">View on BirdWeather &#8599;</a><?php endif; ?>
    </div>
    <?php if ($latest):
      $linfo = thumbnail_info_for($latest);
      $lt = $linfo['thumb'];
      $la = audio_url_for($latest);
      $lartist = $linfo['meta']['artist']         ?? '';
      $llicense= $linfo['meta']['license']        ?? '';
      $lsource = $linfo['meta']['descriptionurl'] ?? '';
      $lis_bird = $linfo['is_bird_photo'] ? '1' : '0';
      $lsp = spectrogram_url_for($latest);  // perchpi:dock-scrubber-h3-v1
    ?>
      <div class="latest-card" id="latest-card"
           data-audio="<?= h($la) ?>"
           data-spectrogram="<?= h($lsp ?? '') ?>"
           data-thumb="<?= h($lt) ?>">
        <div class="latest-thumb <?= $linfo['is_bird_photo'] ? 'thumb-tappable' : '' ?>"
             id="latest-thumb"
             data-is-bird-photo="<?= $lis_bird ?>"
             data-common="<?= h($latest['Com_Name']) ?>"
             data-sci="<?= h($latest['Sci_Name']) ?>"
             data-photo-artist="<?= h($lartist) ?>"
             data-photo-license="<?= h($llicense) ?>"
             data-photo-source="<?= h($lsource) ?>">
          <?php if ($lt): ?>
            <img src="<?= h($lt) ?>" alt="">
          <?php else: ?>
            <div class="thumb-placeholder"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M16 6l-6 6 6 6"/></svg></div>
          <?php endif; ?>
        </div>
        <div class="latest-body">
          <div class="latest-name" id="latest-name"><a class="latest-spp-link" href="?view=Species%20Profile&species=<?= rawurlencode($latest['Sci_Name']) ?>&from=Overview"><?= h($latest['Com_Name']) ?></a><?= pp_geomodel_pill_for_row($latest) ?></div>
          <div class="latest-sci" id="latest-sci"><a class="latest-spp-link" href="?view=Species%20Profile&species=<?= rawurlencode($latest['Sci_Name']) ?>&from=Overview"><?= h($latest['Sci_Name']) ?></a></div>
          <div class="latest-meta">
            <span class="chip chip-conf" id="latest-conf"><?= round($latest['Confidence']*100) ?>%</span>
            <span class="chip chip-time" id="latest-time"><?= h(substr($latest['Time'],0,5)) ?></span>
          </div>
          <button class="play-btn play-btn-lg" id="latest-play" aria-label="Play">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
            <span>Play</span>
          </button>
        </div>
        <?php /* perchpi:overview-hero-stats-v1 — All-time stat rail */ ?>
        <a class="latest-stat-rail" href="?view=All+Species" aria-label="All-time species and detections">
          <div class="latest-stat-block latest-stat-block--lg">
            <div class="latest-stat-num"><?= number_format(intval($summary['totalspeciestally'])) ?></div>
            <div class="latest-stat-label">Species</div>
          </div>
          <div class="latest-stat-divider" aria-hidden="true"></div>
          <div class="latest-stat-block latest-stat-block--sm">
            <div class="latest-stat-num"><?= number_format(intval($summary['totalcount'])) ?></div>
            <div class="latest-stat-label">detections</div>
          </div>
        </a>
      </div>
    <?php else: ?>
      <div class="empty">No detections yet.</div>
    <?php endif; ?>
  </section>

  <?php // perchpi:clickable-stats — stat tiles drill to dedicated views ?>
  <section class="section section-stats">
    <a class="stat stat-link" href="?view=Detections%20Today">
      <div class="stat-value" id="stat-today"><?= intval($summary['todaycount']) ?></div>
      <div class="stat-label">Detections</div>
    </a>
    <a class="stat stat-link" href="?view=Birds%20Today">
      <div class="stat-value" id="stat-species"><?= intval($summary['speciestally']) ?></div>
      <div class="stat-label">Species</div>
    </a>
    <a class="stat stat-link" href="?view=Last%20Hour">
      <div class="stat-value" id="stat-hour"><?= intval($summary['hourcount']) ?></div>
      <div class="stat-label">Last Hour</div>
    </a>
  </section>

  <?php /* perchpi:notable-banner-v2 — two-mode Notable nudge (embedded Overview only) */ ?>
  <?php if (!empty($EMBEDDED)):
    $pp_nb_helpers = __DIR__ . '/perchpi_helpers.php';
    if (is_file($pp_nb_helpers)) { require_once $pp_nb_helpers; }
    $pp_nb_sf = 0.0305;
    if (function_exists('get_config')) {
      $pp_nb_cfg = get_config();
      if (isset($pp_nb_cfg['SF_THRESH']) && $pp_nb_cfg['SF_THRESH'] !== '') {
        $pp_nb_sf = floatval($pp_nb_cfg['SF_THRESH']);
      }
    }
    $pp_nb_count = function_exists('pp_notable_count_recent')
      ? pp_notable_count_recent('/home/birder/BirdNET-Pi/scripts/birds.db', $pp_nb_sf, 7)
      : 0;
  ?>
  <div class="pp-notable-banner-wrap">
    <?php if ($pp_nb_count > 0): ?>
      <a class="pp-notable-banner pp-nb-loud" href="?view=Notable+Sightings">
        <span class="pp-notable-banner-star">⭐</span>
        <span class="pp-notable-banner-text">
          <strong><?php echo (int)$pp_nb_count; ?> notable <?php echo $pp_nb_count === 1 ? 'detection' : 'detections'; ?></strong>
          <span class="pp-notable-banner-sub">A locally rare bird was flagged in the last 7 days — tap to review.</span>
        </span>
        <span class="pp-notable-banner-chev">›</span>
      </a>
    <?php endif; ?>
    <a class="pp-notable-banner-quiet pp-nb-quiet" href="?view=Notable+Sightings"<?php echo $pp_nb_count > 0 ? ' hidden' : ''; ?>>
      <span class="pp-nb-quiet-star">⭐</span>
      <span class="pp-nb-quiet-label">Notable Detections</span>
      <span class="pp-nb-quiet-chev">›</span>
    </a>
  </div>
  <style>
  /* perchpi:notable-banner-v2 */
  .pp-notable-banner-wrap { margin: 0 0 1rem; }
  .pp-notable-banner { display: flex; align-items: center; gap: .75rem; padding: .7rem 1rem; border-radius: 8px; text-decoration: none; background: rgba(245,197,24,.08); border: 1px solid rgba(245,197,24,.35); }
  .pp-notable-banner:hover { background: rgba(245,197,24,.14); }
  .pp-notable-banner-star { font-size: 1.2rem; line-height: 1; }
  .pp-notable-banner-text { display: flex; flex-direction: column; flex: 1; min-width: 0; }
  .pp-notable-banner-text strong { color: #f5c518; font-size: .95rem; font-weight: 600; }
  .pp-notable-banner-sub { color: #9aa0a6; font-size: .8rem; margin-top: .15rem; }
  .pp-notable-banner-chev { color: #f5c518; font-size: 1.3rem; line-height: 1; margin-left: .25rem; }
  .pp-notable-banner-quiet { display: inline-flex; align-items: center; gap: .4rem; padding: .35rem .55rem; border-radius: 6px; text-decoration: none; color: #9aa0a6; font-size: .85rem; }
  .pp-notable-banner-quiet:hover { color: #d4d7db; background: rgba(255,255,255,.05); }
  .pp-nb-quiet-star { opacity: .75; }
  .pp-nb-quiet-chev { opacity: .6; font-size: 1.05rem; }
  .pp-notable-banner[hidden], .pp-notable-banner-quiet[hidden] { display: none !important; }
  </style>
  <script>
  /* perchpi:notable-banner-v2 — 24h dismiss collapses loud -> quiet */
  (function () {
    var wrap = document.querySelector('.pp-notable-banner-wrap');
    if (!wrap) return;
    var loud = wrap.querySelector('.pp-nb-loud');
    var quiet = wrap.querySelector('.pp-nb-quiet');
    var KEY = 'pp_notable_banner_dismissed', DAY = 86400000;
    function dismissed() {
      try { var t = parseInt(localStorage.getItem(KEY) || '0', 10); return t > 0 && (Date.now() - t) < DAY; }
      catch (e) { return false; }
    }
    if (loud && dismissed()) { loud.hidden = true; if (quiet) quiet.hidden = false; }
    if (loud) {
      loud.addEventListener('click', function () {
        try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
      });
    }
  })();
  </script>
  <?php endif; ?>

  <section class="section section-recent">
    <div class="section-label">Recent Detections</div>
    <div class="recent-list" id="recent-list">
      <?php foreach ($recent as $r):
        $rinfo = thumbnail_info_for($r);
        $t = $rinfo['thumb'];
        $a = audio_url_for($r);
        $sp = spectrogram_url_for($r);  // perchpi:dock-scrubber-v1
        $rartist  = $rinfo['meta']['artist']         ?? '';
        $rlicense = $rinfo['meta']['license']        ?? '';
        $rsource  = $rinfo['meta']['descriptionurl'] ?? '';
      ?>
        <div class="recent-item" data-audio="<?= h($a) ?>" data-spectrogram="<?= h($sp ?? '') ?>">
          <div class="recent-thumb <?= $rinfo['is_bird_photo'] ? 'thumb-tappable' : '' ?>"
               data-is-bird-photo="<?= $rinfo['is_bird_photo'] ? '1' : '0' ?>"
               data-common="<?= h($r['Com_Name']) ?>"
               data-sci="<?= h($r['Sci_Name']) ?>"
               data-photo-artist="<?= h($rartist) ?>"
               data-photo-license="<?= h($rlicense) ?>"
               data-photo-source="<?= h($rsource) ?>">
            <?php if ($t): ?><img src="<?= h($t) ?>" alt=""><?php else: ?><div class="thumb-placeholder-sm"></div><?php endif; ?>
          </div>
          <div class="recent-body">
            <div class="recent-name"><a class="recent-name-link" href="?view=Species%20Profile&species=<?= rawurlencode($r['Sci_Name']) ?>&from=Overview"><?= h($r['Com_Name']) ?></a><?= pp_geomodel_pill_for_row($r) ?></div>  <!-- perchpi:overview-name-link-v1 -->
            <div class="recent-sub">
              <span><?= h(substr($r['Time'],0,5)) ?></span>
              <span class="dot">·</span>
              <span><?= round($r['Confidence']*100) ?>%</span>
            </div>
          </div>
          <button class="play-btn" aria-label="Play">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
          </button>
        </div>
      <?php endforeach; ?>
      <?php if (empty($recent)): ?><div class="empty">No detections today.</div><?php endif; ?>
    </div>
  </section>

  <section class="section section-spectro">
    <div class="section-label">Live Spectrogram</div>
    <div class="spectro-wrap">
      <img id="spectro-preview" src="/spectrogram.png" alt="Live spectrogram" onerror="this.style.display='none'">
    </div>
  </section>

  <section class="section section-top">
    <div class="section-label">Top Species Today</div>
    <div class="top-list">
      <?php $top = fetch_top_species_today(5); foreach ($top as $i => $ts):
        $row_for_thumb = ['Com_Name'=>$ts['Com_Name'],'Sci_Name'=>$ts['Sci_Name'],'Date'=>$ts['Last_Date'],'File_Name'=>$ts['Last_File']];
        $tinfo = $ts['Last_File'] ? thumbnail_info_for($row_for_thumb) : ['thumb'=>null,'is_bird_photo'=>false,'meta'=>null];
        $thumb = $tinfo['thumb'];
        $tartist  = $tinfo['meta']['artist']         ?? '';
        $tlicense = $tinfo['meta']['license']        ?? '';
        $tsource  = $tinfo['meta']['descriptionurl'] ?? '';
      ?>
        <div class="top-item">
          <div class="top-rank"><?= $i+1 ?></div>
          <div class="top-thumb <?= $tinfo['is_bird_photo'] ? 'thumb-tappable' : '' ?>"
               data-is-bird-photo="<?= $tinfo['is_bird_photo'] ? '1' : '0' ?>"
               data-common="<?= h($ts['Com_Name']) ?>"
               data-sci="<?= h($ts['Sci_Name']) ?>"
               data-photo-artist="<?= h($tartist) ?>"
               data-photo-license="<?= h($tlicense) ?>"
               data-photo-source="<?= h($tsource) ?>">
            <?php if ($thumb): ?><img src="<?= h($thumb) ?>" alt=""><?php else: ?><div class="thumb-placeholder-sm"></div><?php endif; ?>
          </div>
          <div class="top-body">
            <div class="top-name"><a class="top-name-link" href="?view=Species%20Profile&species=<?= rawurlencode($ts['Sci_Name']) ?>&from=Overview"><?= h($ts['Com_Name']) ?></a><?= pp_geomodel_pill($ts['Sci_Name']) ?></div>  <!-- perchpi:overview-name-link-v1 -->
            <div class="top-sub"><?= round($ts['MaxConf']*100) ?>% best</div>
          </div>
          <div class="top-count"><?= intval($ts['Cnt']) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($top)): ?><div class="empty">No species yet today.</div><?php endif; ?>
    </div>
  </section>

  <?php /* perchpi:overview-hero-stats-v1 — 3-tile system row; All-Time tiles moved to hero rail */ ?>
  <section class="section section-system">
    <div class="section-label">System</div>
    <?php
      $init_mic = $system['mic_level'];
      $init_mic_val = $init_mic === null ? 0 : intval($init_mic);
    ?>
    <div class="sys-grid pp-sys-grid-4col" data-perchpi-kiosk-live-audio-tile-v1>
      <div class="sys-item">
        <div class="sys-dot <?= $system['analyzer_ok'] ? 'ok' : 'err' ?>" id="sys-analyzer-dot"></div>
        <div><div class="sys-name">Analyzer</div><div class="sys-val" id="sys-analyzer"><?= $system['analyzer_ok'] ? 'running' : 'stopped' ?></div></div>
      </div>
      <div class="sys-item">
        <div class="sys-icon">💾</div>
        <div><div class="sys-name">Disk</div><div class="sys-val" id="sys-disk"><?= $system['disk_pct'] !== null ? intval($system['disk_pct']) . '% used' : '—' ?></div></div>
      </div>
      <div class="sys-item sys-item--mic">
        <div class="sys-mic">
          <div class="sys-mic-label">
            <span class="sys-name">Mic Input</span>
            <span class="sys-mic-value" id="sys-mic-value"><?= $init_mic === null ? '—' : $init_mic_val . '%' ?></span>
          </div>
          <div class="sys-mic-bar">
            <div class="sys-mic-fill" id="sys-mic-fill" style="width: <?= $init_mic_val ?>%"></div>
            <div class="sys-mic-marks">
              <span style="left:33%"></span>
              <span style="left:66%"></span>
            </div>
          </div>
          <div class="sys-mic-scale"><span>quiet</span><span>good</span><span>loud</span></div>
        </div>
      </div>
      <!-- perchpi:kiosk-live-audio-tile-v1 — Live Audio toggle -->
      <div class="sys-item sys-item--live-audio" id="kla-tile" role="button" tabindex="0" aria-label="Toggle live audio">
        <div class="sys-dot" id="kla-dot"></div>
        <div>
          <div class="sys-name">🎙 Live Audio</div>
          <div class="sys-val" id="kla-val">Off</div>
        </div>
        <audio id="kla-audio" preload="none"></audio>
      </div>
    </div>
  </section>

<?php elseif ($tab === 'today'): ?>
  <!-- ========== TODAY ========== -->
  <section class="section">
    <div class="section-label">Today's Detections</div>
    <div class="today-list">
      <?php $today = fetch_recent_detections(100); foreach ($today as $r):
        $tinfo = thumbnail_info_for($r);
        $t = $tinfo['thumb'];
        $a = audio_url_for($r);
        $sp = spectrogram_url_for($r);  // perchpi:dock-scrubber-h3-v1
        $tartist  = $tinfo['meta']['artist']         ?? '';
        $tlicense = $tinfo['meta']['license']        ?? '';
        $tsource  = $tinfo['meta']['descriptionurl'] ?? '';
      ?>
        <div class="today-item" data-audio="<?= h($a) ?>" data-spectrogram="<?= h($sp ?? '') ?>">
          <div class="today-thumb <?= $tinfo['is_bird_photo'] ? 'thumb-tappable' : '' ?>"
               data-is-bird-photo="<?= $tinfo['is_bird_photo'] ? '1' : '0' ?>"
               data-common="<?= h($r['Com_Name']) ?>"
               data-sci="<?= h($r['Sci_Name']) ?>"
               data-photo-artist="<?= h($tartist) ?>"
               data-photo-license="<?= h($tlicense) ?>"
               data-photo-source="<?= h($tsource) ?>">
            <?php if ($t): ?><img src="<?= h($t) ?>" alt=""><?php else: ?><div class="thumb-placeholder-md"></div><?php endif; ?>
          </div>
          <div class="today-body">
            <div class="today-name"><a class="today-name-link" href="?view=Species%20Profile&species=<?= rawurlencode($r['Sci_Name']) ?>&from=Overview"><?= h($r['Com_Name']) ?></a></div>  <!-- perchpi:overview-name-link-v1 -->
            <div class="today-sci"><?= h($r['Sci_Name']) ?></div>
            <div class="today-meta">
              <span class="chip chip-time"><?= h(substr($r['Time'],0,5)) ?></span>
              <span class="chip chip-conf"><?= round($r['Confidence']*100) ?>%</span>
            </div>
          </div>
          <button class="play-btn play-btn-md" aria-label="Play">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
          </button>
        </div>
      <?php endforeach; ?>
      <?php if (empty($today)): ?><div class="empty">No detections today yet.</div><?php endif; ?>
    </div>
  </section>

<?php elseif ($tab === 'species'): ?>
  <!-- ========== SPECIES ========== -->
  <section class="section">
    <div class="section-label">Top Species Today</div>
    <div class="species-list">
      <?php $top = fetch_top_species_today(30); foreach ($top as $i => $ts):
        $row_for_thumb = ['Com_Name'=>$ts['Com_Name'],'Sci_Name'=>$ts['Sci_Name'],'Date'=>$ts['Last_Date'],'File_Name'=>$ts['Last_File']];
        $sinfo = $ts['Last_File'] ? thumbnail_info_for($row_for_thumb) : ['thumb'=>null,'is_bird_photo'=>false,'meta'=>null];
        $thumb = $sinfo['thumb'];
        $sartist  = $sinfo['meta']['artist']         ?? '';
        $slicense = $sinfo['meta']['license']        ?? '';
        $ssource  = $sinfo['meta']['descriptionurl'] ?? '';
      ?>
        <div class="species-item">
          <div class="species-rank"><?= $i+1 ?></div>
          <div class="species-thumb <?= $sinfo['is_bird_photo'] ? 'thumb-tappable' : '' ?>"
               data-is-bird-photo="<?= $sinfo['is_bird_photo'] ? '1' : '0' ?>"
               data-common="<?= h($ts['Com_Name']) ?>"
               data-sci="<?= h($ts['Sci_Name']) ?>"
               data-photo-artist="<?= h($sartist) ?>"
               data-photo-license="<?= h($slicense) ?>"
               data-photo-source="<?= h($ssource) ?>">
            <?php if ($thumb): ?><img src="<?= h($thumb) ?>" alt=""><?php else: ?><div class="thumb-placeholder-md"></div><?php endif; ?>
          </div>
          <div class="species-body">
            <div class="species-name"><a class="species-name-link" href="?view=Species%20Profile&species=<?= rawurlencode($ts['Sci_Name']) ?>&from=Overview"><?= h($ts['Com_Name']) ?></a></div>  <!-- perchpi:overview-name-link-v1 -->
            <div class="species-sci"><?= h($ts['Sci_Name']) ?></div>
            <div class="species-meta">Best: <?= round($ts['MaxConf']*100) ?>%</div>
          </div>
          <div class="species-count">
            <div class="species-count-num"><?= intval($ts['Cnt']) ?></div>
            <div class="species-count-lbl">today</div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($top)): ?><div class="empty">No detections today yet.</div><?php endif; ?>
    </div>
  </section>

<?php elseif ($tab === 'spectrogram'): ?>
  <!-- ========== SPECTROGRAM ========== -->
  <section class="section section-spectro-full">
    <div class="section-label">Live Spectrogram</div>
    <div class="spectro-full-wrap">
      <img id="spectro-full" src="/spectrogram.png" alt="Live spectrogram"
           onerror="this.style.display='none';document.getElementById('spectro-err').style.display='block';">
      <div id="spectro-err" class="empty" style="display:none">
        Spectrogram viewer service not available.<br>
        Enable it from the Admin panel → Services.
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if (!$EMBEDDED): ?>
</main>
<?php endif; ?>

<!-- =================== BOTTOM NAV =================== -->
<?php if (!$EMBEDDED): ?>
<!-- perchpi:tabbar-removed-v1 (legacy standalone-kiosk tabbar; sidebar nav replaces it) -->
<?php endif; ?>

<!-- =================== BIRD IMAGE OVERLAY =================== -->
<div class="bird-overlay" id="bird-overlay" aria-hidden="true">
  <div class="bird-overlay-backdrop" data-close="1"></div>
  <div class="bird-overlay-content">
    <button class="bird-overlay-close" id="bird-overlay-close" aria-label="Close">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
    </button>
    <img class="bird-overlay-img" id="bird-overlay-img" alt="">
    <div class="bird-overlay-info">
      <div class="bird-overlay-name" id="bird-overlay-name"></div>
      <div class="bird-overlay-sci" id="bird-overlay-sci"></div>
      <div class="bird-overlay-credit" id="bird-overlay-credit"></div>
      <div class="bird-overlay-hint">Tap anywhere to close</div>
    </div>
  </div>
</div>

<!-- Hidden audio element used by all play buttons -->
<!-- perchpi:dock-scrubber-v1 — docked spectrogram + audio scrubber, replaces shared <audio id="audio-player"> -->
<div class="pp-scrubber pp-dock-empty" id="pp-dock-scrubber"
     data-audio="" data-spectrogram=""></div>
<!-- perchpi:audio-normalize-bridge-v1 -->
<script src="pp-audio.js"></script>
<script src="pp_scrubber.js"></script>

<?php if (!$EMBEDDED): ?>
<script src="kiosk.js?v=<?= @filemtime(__DIR__ . '/kiosk.js') ?: time() ?>"></script>
<script src="static/hls.min.js"></script>
</body>
</html>
<?php endif; ?>


<!-- perchpi:overview-hero-link-v1 — clickable species name on Overview hero -->
<style>
  .latest-spp-link {
    color: inherit;
    text-decoration: none;
    transition: opacity 120ms ease;
  }
  .latest-spp-link:hover {
    opacity: 0.85;
    text-decoration: underline;
  }
  .latest-spp-link:focus-visible {
    outline: 2px solid var(--accent, #7be07b);
    outline-offset: 2px;
    border-radius: 2px;
  }
</style>
