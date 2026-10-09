<?php
/* ============================================================================
   PerchPi · Quarantine Delete Endpoint (Wave Q3.2)
   ============================================================================
   POST detection_ids[] (or single detection_id) -> permanently deletes
   quarantine sidecar rows AND the underlying detection rows.

   Note: Q3.1a uses NO foreign-key constraint (SQLite forbids FK to ROWID),
   so this endpoint deletes from BOTH tables explicitly.

   Marker: perchpi:quarantine-delete-v1
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

// perchpi:quarantine-review-log-hook-v1
  // Best-effort logging BEFORE destructive action. Inside the transaction
  // so atomicity is preserved; wrapped in try/catch so log failures
  // never block the user's intended action (fail-open).
  if (!function_exists('pp_log_quarantine_decisions')) {
    require_once __DIR__ . '/perchpi_helpers.php';
  }
  try {
    pp_log_quarantine_decisions($db, $ids, 'delete');
  } catch (Exception $log_e) {
    error_log('quarantine_review_log INSERT failed (continuing): ' . $log_e->getMessage());
  }


  $placeholders = implode(',', array_fill(0, count($ids), '?'));

  // 1. Delete quarantine sidecar rows
  $stmt1 = $db->prepare("DELETE FROM quarantined_detections WHERE detection_id IN ($placeholders)");
  foreach ($ids as $i => $id) {
    $stmt1->bindValue($i + 1, $id, SQLITE3_INTEGER);
  }
  $stmt1->execute();
  $sidecar_deleted = $db->changes();

  // 2. Delete actual detection rows
  $stmt2 = $db->prepare("DELETE FROM detections WHERE ROWID IN ($placeholders)");
  foreach ($ids as $i => $id) {
    $stmt2->bindValue($i + 1, $id, SQLITE3_INTEGER);
  }
  $stmt2->execute();
  $detections_deleted = $db->changes();

  $db->exec('COMMIT');
  $db->close();

  echo json_encode([
    'ok' => true,
    'deleted' => $detections_deleted,
    'sidecar_deleted' => $sidecar_deleted,
    'requested' => count($ids),
  ]);
} catch (Exception $e) {
  if (isset($db)) {
    @$db->exec('ROLLBACK');
    @$db->close();
  }
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db error: ' . $e->getMessage()]);
}
