<?php
/* ============================================================================
   last_hour_list.php — Last Hour's Detections (chronological, newest first)
   ============================================================================
   Reached from the "Last Hour" stat tile on Overview.

   Build: Phase C-2.2.3
   ============================================================================ */

// perchpi:photo-helpers-phase3-v1 -- kiosk.php shim removed; helpers come from perchpi_helpers.php which chains in common.php
if (!function_exists('get_db')) { require_once __DIR__ . '/../scripts/common.php'; }

require_once __DIR__ . '/perchpi_helpers.php';

// Direct DB query for last hour. We don't have a kiosk helper for this
// specific window, so query the existing scripts/birds.db.
$home = function_exists('get_home') ? get_home() : '/home/birder';
$db_path = __DIR__ . '/../scripts/birds.db';
if (!file_exists($db_path)) {
  $db_path = $home . '/BirdNET-Pi/scripts/birds.db';
}

$detections = [];
if (file_exists($db_path)) {
  try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(1000);
    // The DB stores Date as YYYY-MM-DD and Time as HH:MM:SS in local time.
    // Pull every detection in the last 60 minutes.
    $stmt = $db->prepare(
      "SELECT Date, Time, Com_Name, Sci_Name, Confidence, File_Name " .
      "FROM detections " .
      "WHERE datetime(Date || ' ' || Time) >= datetime('now', 'localtime', '-60 minutes') " .
      "  AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "ORDER BY Date DESC, Time DESC"
    );
    if ($stmt) {
      $res = $stmt->execute();
      while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $detections[] = $row;
      }
    }
    $db->close();
  } catch (Exception $e) {
    // Fall through with empty list
  }
}

$count = count($detections);
$subtitle = $count === 1 ? '1 detection' : $count . ' detections';

pp_render_back_nav('?view=Overview', 'Overview', 'Last Hour', $subtitle);
?>

<section class="section">
  <div class="today-list">
    <?php if ($count === 0): ?>
      <div class="empty">No detections in the last hour.</div>
    <?php else: ?>
      <?php foreach ($detections as $d) { pp_render_today_item($d); } ?>
    <?php endif; ?>
  </div>
</section>
