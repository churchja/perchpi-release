<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // perchpi:cli-only-guard-v1 (D-S9-1): CLI tool, never served over HTTP
/* ============================================================================
   import_xc_audio.php — import manually-downloaded Xeno-Canto recordings
   ============================================================================
   Scans ~/Downloads for files matching the pattern:
     XC{id} - {Common Name} - {Scientific name}.{mp3|wav|flac}

   For each file:
     1. Extract scientific name (first two words after the second " - ")
     2. Match against species in birds.db that have NO local recordings
     3. Convert audio to FLAC at ~/BirdSongs/Extracted/xc_audio/{slug}.flac
        (matching the local-recording format)
     4. Generate spectrogram PNG via sox
     5. Write JSON sidecar with attribution metadata (XC ID, license placeholder)

   Idempotent — running again with new files in Downloads adds them; existing
   cached files are skipped unless --force.

   Run:
     php /home/birder/BirdNET-Pi/homepage/import_xc_audio.php
     php /home/birder/BirdNET-Pi/homepage/import_xc_audio.php --dry-run
     php /home/birder/BirdNET-Pi/homepage/import_xc_audio.php --force
     php /home/birder/BirdNET-Pi/homepage/import_xc_audio.php --src=/path/to/dir
   ============================================================================ */

$dry_run = in_array('--dry-run', $argv);
$force   = in_array('--force', $argv);

$home      = '/home/birder';
$db_path   = $home . '/BirdNET-Pi/scripts/birds.db';
$cache_dir = $home . '/BirdSongs/Extracted/xc_audio';
$src_dir   = $home . '/Downloads';

foreach ($argv as $a) {
  if (strpos($a, '--src=') === 0) {
    $src_dir = substr($a, 6);
  }
}

// ─── Helpers ──────────────────────────────────────────────────────────

function sci_name_slug($sci_name) {
  $s = preg_replace('/[^A-Za-z ]/', '', $sci_name);
  $s = trim($s);
  return str_replace(' ', '_', $s);
}

/**
 * Parse a filename like "XC983424 - Northern Raven - Corvus corax.wav"
 * Returns ['xc_id' => '983424', 'common' => 'Northern Raven',
 *          'sci' => 'Corvus corax', 'sci_genus_species' => 'Corvus corax'].
 *
 * Handles three-part scientific names by taking just the first two tokens
 * (genus + species, dropping any subspecies). Returns null if the format
 * doesn't match.
 */
function parse_xc_filename($basename) {
  // Strip extension
  $name = preg_replace('/\.(mp3|wav|flac|ogg|m4a)$/i', '', $basename);

  // Strip browser duplicate suffix like "filename (1)" → "filename"
  $name = preg_replace('/\s*\(\d+\)$/', '', $name);

  // Pattern: XC{digits} - {Common Name} - {Scientific name}
  // CRITICAL: require whitespace around the field-separator hyphens so that
  // hyphens inside common names (e.g. "Black-billed Magpie", "Dark-eyed
  // Junco") don't get mis-parsed as field boundaries.
  if (!preg_match('/^XC(\d+)\s+-\s+(.+?)\s+-\s+(.+)$/', $name, $m)) {
    return null;
  }

  $xc_id  = $m[1];
  $common = trim($m[2]);
  $sci_raw = trim($m[3]);

  // Take just genus + species (first two words), drop subspecies
  $parts = preg_split('/\s+/', $sci_raw);
  if (count($parts) < 2) return null;
  $sci_genus_species = $parts[0] . ' ' . $parts[1];

  return [
    'xc_id'             => $xc_id,
    'common'            => $common,
    'sci'               => $sci_raw,
    'sci_genus_species' => $sci_genus_species,
  ];
}

function convert_to_flac($src_path, $dst_path) {
  // sox handles MP3/WAV/FLAC natively. Output FLAC at 44.1kHz mono to match
  // the local-recording format and keep file size reasonable.
  $cmd = sprintf(
    'sox -V1 %s -t flac -C 8 %s remix 1 rate 44100 2>/dev/null',
    escapeshellarg($src_path),
    escapeshellarg($dst_path)
  );
  $rc = 0;
  exec($cmd, $out, $rc);
  return $rc === 0 && file_exists($dst_path) && filesize($dst_path) > 1024;
}

function generate_spectrogram($audio_path, $png_path) {
  $cmd = sprintf(
    'sox -V1 %s -n remix 1 rate 24k spectrogram -c %s -o %s 2>/dev/null',
    escapeshellarg($audio_path),
    escapeshellarg('Xeno-Canto sample'),
    escapeshellarg($png_path)
  );
  $rc = 0;
  exec($cmd, $out, $rc);
  return $rc === 0 && file_exists($png_path) && filesize($png_path) > 512;
}

// ─── Load species needing XC fallback ──────────────────────────────────

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
if (!is_dir($src_dir)) {
  fwrite(STDERR, "ERROR: source dir not found: $src_dir\n");
  exit(1);
}

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
$needed_species = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  $needed_species[$row['Sci_Name']] = $row['Com_Name'];
}
$db->close();

$total_needed = count($needed_species);

// ─── Scan source dir for XC files ──────────────────────────────────────

$files = [];
foreach (glob($src_dir . '/XC*.{mp3,wav,flac,MP3,WAV,FLAC}', GLOB_BRACE) as $path) {
  $files[] = $path;
}
sort($files);
$total_files = count($files);

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Xeno-Canto manual import\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Source dir: $src_dir\n";
echo "  Cache dir:  $cache_dir\n";
echo "  Files:      $total_files XC* files in source\n";
echo "  Needed:     $total_needed species without local recordings\n";
echo "  Mode:       " . ($dry_run ? "DRY RUN" : ($force ? "FORCE (re-import all)" : "LIVE (skip already-cached)")) . "\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

$counters = [
  'imported'    => 0,
  'cached'      => 0,
  'unmatched'   => 0,
  'parse_fail'  => 0,
  'convert_fail'=> 0,
  'spectrogram_fail' => 0,
];
$matched_species = [];
$failed = [];

foreach ($files as $i => $path) {
  $basename = basename($path);
  $progress = sprintf("[%2d/%d]", $i + 1, $total_files);
  echo "$progress $basename\n";

  $parsed = parse_xc_filename($basename);
  if (!$parsed) {
    echo "        ✗ filename doesn't match XC pattern\n";
    $counters['parse_fail']++;
    $failed[] = "$basename: parse failed";
    continue;
  }

  $sci = $parsed['sci_genus_species'];
  echo "        sci: $sci, xc_id: {$parsed['xc_id']}\n";

  if (!isset($needed_species[$sci])) {
    echo "        ⚠ species not in needed list (already has local recording or unknown)\n";
    $counters['unmatched']++;
    $failed[] = "$basename: $sci not in needed-species list";
    continue;
  }

  $com  = $needed_species[$sci];
  $slug = sci_name_slug($sci);

  $flac_path = $cache_dir . '/' . $slug . '.flac';
  $png_path  = $cache_dir . '/' . $slug . '.png';
  $meta_path = $cache_dir . '/' . $slug . '.json';

  if (file_exists($flac_path) && !$force) {
    echo "        ✓ already cached (skip)\n";
    $counters['cached']++;
    $matched_species[$sci] = true;
    continue;
  }

  if ($dry_run) {
    echo "        would import → $slug.flac\n";
    $matched_species[$sci] = true;
    continue;
  }

  // Convert to FLAC
  if (!convert_to_flac($path, $flac_path)) {
    echo "        ✗ FLAC conversion failed\n";
    $counters['convert_fail']++;
    $failed[] = "$basename: FLAC conversion failed";
    continue;
  }

  // Generate spectrogram
  $spec_ok = generate_spectrogram($flac_path, $png_path);
  if (!$spec_ok) {
    echo "        ⚠ spectrogram generation failed (FLAC saved)\n";
    $counters['spectrogram_fail']++;
  }

  // Write attribution sidecar (license unknown — manual import)
  @file_put_contents($meta_path, json_encode([
    'source'         => 'xeno-canto (manual import)',
    'xc_id'          => $parsed['xc_id'],
    'recordist'      => '(not captured — see XC page)',
    'license'        => '(see XC page for license)',
    'sci_name'       => $sci,
    'com_name'       => $com,
    'common_in_file' => $parsed['common'],
    'descriptionurl' => "https://xeno-canto.org/{$parsed['xc_id']}",
    'imported_at'    => date('c'),
    'orig_filename'  => $basename,
  ], JSON_PRETTY_PRINT));

  echo "        ✓ IMPORTED → $slug.flac" . ($spec_ok ? " + spectrogram" : "") . "\n";
  $counters['imported']++;
  $matched_species[$sci] = true;
}

// ─── Identify still-missing species ─────────────────────────────────────

$missing = [];
foreach ($needed_species as $sci => $com) {
  if (!isset($matched_species[$sci])) {
    $missing[] = "$sci ($com)";
  }
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo "  Summary\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Files in source dir:    $total_files\n";
echo "  Imported:               " . $counters['imported']         . "\n";
echo "  Already cached:         " . $counters['cached']           . "\n";
echo "  Unmatched (skipped):    " . $counters['unmatched']        . "\n";
echo "  Parse failures:         " . $counters['parse_fail']       . "\n";
echo "  FLAC convert failures:  " . $counters['convert_fail']     . "\n";
echo "  Spectrogram failures:   " . $counters['spectrogram_fail'] . "\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

if (!empty($failed)) {
  echo "\nFailures:\n";
  foreach ($failed as $f) echo "  - $f\n";
}

if (!empty($missing)) {
  echo "\nSpecies still missing audio (no matching file in source):\n";
  foreach ($missing as $m) echo "  - $m\n";
}

echo "\nDone.\n";
