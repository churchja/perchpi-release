<?php
/* ============================================================================
   recordings_by_date.php — Recordings: chronological date list (C-2.4 batch 3)
   ============================================================================
   One card per date with detections, newest first. Each card shows a friendly
   date label (Today / Yesterday / Tuesday, Apr 28 / Apr 24) and a detection
   count. Tap a card to drill into the per-date species list (handled by
   recordings_date_species.php — added in script 2b).

   Excludes ebird_import_* synthetic seed files from the count, consistent
   with best_recordings.php.
   ============================================================================ */

// perchpi:photo-helpers-phase3-v1 -- kiosk.php shim removed; helpers come from perchpi_helpers.php which chains in common.php
if (!function_exists('get_db')) { require_once __DIR__ . '/../scripts/common.php'; }

require_once __DIR__ . '/perchpi_helpers.php';

// ─── Query ────────────────────────────────────────────────────────────────

$db_path = __DIR__ . '/../scripts/birds.db';
if (!file_exists($db_path)) {
  $home = function_exists('get_home') ? get_home() : ($_SERVER['HOME'] ?? '/home/birder');
  $db_path = $home . '/BirdNET-Pi/scripts/birds.db';
}

$dates = [];
if (file_exists($db_path)) {
  try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);
    $res = $db->query(
      "SELECT Date, COUNT(*) AS cnt " .
      "FROM detections " .
      "WHERE File_Name NOT LIKE 'ebird_import_%' " .
      "  AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "GROUP BY Date " .
      "ORDER BY Date DESC"
    );
    if ($res) {
      while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $dates[] = $row;
      }
    }
    $db->close();
  } catch (Exception $e) {
    // Empty list will render empty state.
  }
}

$count    = count($dates);
$subtitle = $count === 1 ? '1 date with detections' : ($count . ' dates with detections');

pp_render_back_nav('?view=Overview', 'Overview', 'Recordings', $subtitle);
?>

<section class="pp-content">
  <div class="recordings-date-list">
    <?php if (empty($dates)): ?>
      <div class="empty">No recordings yet.</div>
    <?php else: ?>
      <?php foreach ($dates as $d):
        $date_h     = htmlspecialchars($d['Date'], ENT_QUOTES);
        $label      = htmlspecialchars(pp_format_date_friendly($d['Date']), ENT_QUOTES);
        $cnt        = (int)$d['cnt'];
        $cnt_word   = $cnt === 1 ? 'detection' : 'detections';
        $href       = '?view=Recordings&date=' . urlencode($d['Date']);
      ?>
        <a class="recordings-date-card" href="<?= htmlspecialchars($href, ENT_QUOTES) ?>">
          <div class="recordings-date-label"><?= $label ?></div>
          <div class="recordings-date-meta"><?= number_format($cnt) ?> <?= $cnt_word ?></div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
