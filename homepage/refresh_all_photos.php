<?php
/* ============================================================================
   PerchPi · Refresh All Photos Endpoint (Stage 3 / Wave 1)
   ============================================================================
   POST → triggers the perchpi-refresh-bird-photos systemd service
   in non-blocking mode. Returns JSON immediately. Photo refresh runs
   as `birder` per the systemd unit, completing in 15-60 seconds.

   Marker: perchpi:refresh-all-photos-endpoint-v1
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

exec('sudo /usr/bin/systemctl start --no-block perchpi-refresh-bird-photos.service 2>&1', $output, $rc);

if ($rc !== 0) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'systemctl failed (exit ' . $rc . ')',
    'detail' => implode("\n", $output)
  ]);
  exit;
}

echo json_encode([
  'ok' => true,
  'message' => 'Refresh started — completes in 15-60 seconds'
]);
