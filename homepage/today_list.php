<?php
/* ============================================================================
   today_list.php — Today's Detections (chronological, newest first)
   v2.1: fetches all detections (no 500 cap), shows "N detections · M species"
         subtitle, adds id="today-list" for the consecutive-cluster collapse JS.
         Restored defensive kiosk.php include pattern from v1.
   ============================================================================ */

// Pull in kiosk.php's data functions (fetch_recent_detections, etc.) without
// triggering its HTML render. Match the pattern in v1: only include if the
// functions aren't already loaded; set $EMBEDDED + capture output buffer.
// perchpi:photo-helpers-phase3-v1 -- kiosk.php shim removed; helpers come from perchpi_helpers.php which chains in common.php
if (!function_exists('get_db')) { require_once __DIR__ . '/../scripts/common.php'; }

require_once __DIR__ . '/perchpi_helpers.php';

// Fetch all of today's detections (newest first)
$detections = fetch_recent_detections(100000);  // effectively "all"
$total      = count($detections);

// Count distinct species among today's detections
$species_set = [];
foreach ($detections as $d) {
  $s = $d['Sci_Name'] ?? '';
  if ($s !== '') $species_set[$s] = true;
}
$species_count = count($species_set);

$detection_word = $total === 1 ? 'detection' : 'detections';
$subtitle = number_format($total) . " $detection_word · " .
            number_format($species_count) . ' species';

pp_render_back_nav('?view=Overview', 'Overview', "Today's Detections", $subtitle);
?>

<section class="pp-content">
  <div class="today-list" id="today-list">
    <?php if (empty($detections)): ?>
      <div class="empty">No detections today yet.</div>
    <?php else: ?>
      <?php foreach ($detections as $row) {
        pp_render_today_item($row);
      } ?>
    <?php endif; ?>
  </div>
</section>
