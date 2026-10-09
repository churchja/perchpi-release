<?php
/* ============================================================================
   PerchPi · Quarantine Hide Endpoint
   ============================================================================
   POST detection_ids[] (or single detection_id) -> INSERT into
   quarantined_detections, hiding detections from main views. Reversible
   via the Quarantine page (quarantine_unhide.php) or permanently removable
   there (quarantine_delete.php).

   Mirrors quarantine_unhide.php: same auth (HTTP Basic via
   ensure_authenticated), same POST-only guard, same id collection. The only
   substantive difference is INSERT OR IGNORE instead of DELETE.

   Marker: perchpi:quarantine-hide-v1
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

/* ---- Collect ids ---- */
$ids = [];
if (isset($_POST['detection_ids']) && is_array($_POST['detection_ids'])) {
  $ids = $_POST['detection_ids'];
} elseif (isset($_POST['detection_id'])) {
  $ids = [$_POST['detection_id']];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($i) { return $i > 0; })));

if (empty($ids)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'no valid detection_ids']);
  exit;
}

/* ---- DB ---- */
$home = function_exists('get_home') ? get_home() : '/home/birder';
$db_path = $home . '/BirdNET-Pi/scripts/birds.db';

try {
  $db = new SQLite3($db_path);
  $db->busyTimeout(5000);
  $db->exec('BEGIN IMMEDIATE');

  // perchpi:quarantine-hide-v1 — INSERT OR IGNORE so re-hiding an already
  // quarantined row is a harmless no-op. reason='user_hidden' distinguishes
  // human action from geomodel/ai auto-quarantine. geomodel_* columns stay
  // NULL, which is correct for a manual hide.
  $stmt = $db->prepare(
    "INSERT OR IGNORE INTO quarantined_detections (detection_id, quarantined_at, reason) " .
    "VALUES (:id, datetime('now'), 'user_hidden')"
  );
  $inserted = 0;
  foreach ($ids as $id) {
    $stmt->reset();
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $inserted += $db->changes();
  }

  $db->exec('COMMIT');
  $db->close();

  echo json_encode(['ok' => true, 'inserted' => $inserted, 'requested' => count($ids)]);
} catch (Exception $e) {
  if (isset($db)) {
    @$db->exec('ROLLBACK');
    @$db->close();
  }
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db error: ' . $e->getMessage()]);
}
