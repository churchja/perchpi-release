<?php
/* ============================================================================
   PerchPi · Refresh One Photo Endpoint (Stage 4)
   ============================================================================
   POST sci_name=<scientific name> → forces a refresh of just that one
   species. Used by the per-species "↻" button on cards where the photo
   is missing. Synchronous — waits for fetch to complete (typically 3-5s).

   Marker: perchpi:refresh-one-photo-endpoint-v1
   ============================================================================ */

require_once __DIR__ . '/../scripts/common.php';

ensure_authenticated(); /* perchpi:s21a-auth-plain-call-v1 -- SHIP-WITH #7: fail closed, no function_exists() guard */
csrf_check(); // perchpi:s21a-csrf-v1 (stage-2 §4)

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'POST only']);
  exit;
}

$sci_name = trim($_POST['sci_name'] ?? '');
if ($sci_name === '' || !preg_match('/^[A-Za-z][A-Za-z\s\-\.]+$/', $sci_name)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid sci_name']);
  exit;
}

// Evict stale .miss before refresh so the script will retry.
$cache_dir = '/home/birder/BirdSongs/Extracted/bird_images';
$slug = preg_replace('/[^A-Za-z\s]/', '', $sci_name);
$slug = str_replace(' ', '_', trim($slug));
foreach (['miss', 'transient'] as $kind) {
  $sentinel = "$cache_dir/$slug.wikipedia.$kind";
  if (file_exists($sentinel)) { @unlink($sentinel); }
}

// Run synchronously as the birder-owned cron user via sudo.
$cmd = 'sudo -u birder /usr/bin/php /home/birder/BirdNET-Pi/homepage/refresh_bird_photos.php --species ' . escapeshellarg($sci_name) . ' 2>&1';
exec($cmd, $output, $rc);

$jpg_path = "$cache_dir/$slug.wikipedia.jpg";
$success = file_exists($jpg_path);

echo json_encode([
  'ok' => $success,
  'sci_name' => $sci_name,
  'photo_url' => $success ? "/bird_images/$slug.wikipedia.jpg?t=" . time() : null,
  'log_tail' => array_slice($output, -3),
]);
