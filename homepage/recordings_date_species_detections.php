<?php
/* ============================================================================
   recordings_date_species_detections.php — Per-date+species detection log
   ============================================================================
   Lists every detection of one species on one date, chronologically.
   Each row:
     · time chip (HH:MM)
     · confidence chip (XX%)
     · spectrogram thumbnail (tap to play / pause the recording)

   Sort: ascending (earliest first) by default. Single direction toggle.

   Triggered by: ?view=Recordings&date=YYYY-MM-DD&species=Sci_Name
   ============================================================================ */

require_once __DIR__ . '/perchpi_helpers.php';

// ─── Sanitize params ──────────────────────────────────────────────────────

$date_param    = $_GET['date']    ?? '';
$species_param = $_GET['species'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_param) || $species_param === '') {
  echo '<div class="page-header"><h1>Invalid request</h1></div>';
  echo '<div class="empty-state">';
  echo '  <h3>Bad parameters</h3>';
  echo '  <p><a class="btn btn--primary" href="?view=Recordings">Back to Recordings</a></p>';
  echo '</div>';
  return;
}

$dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

// ─── Query ────────────────────────────────────────────────────────────────

$db_path = __DIR__ . '/../scripts/birds.db';
if (!file_exists($db_path)) {
  $home = function_exists('get_home') ? get_home() : ($_SERVER['HOME'] ?? '/home/birder');
  $db_path = $home . '/BirdNET-Pi/scripts/birds.db';
}

$detections = [];
$common_name = $species_param;  // fallback if no rows

if (file_exists($db_path)) {
  try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);

    $order = $dir === 'desc' ? 'DESC' : 'ASC';

    $sql =
      "SELECT Date, Time, Confidence, File_Name, Com_Name, Sci_Name, archived_at, archive_location, /* perchpi:nn-archived-badge-v1 */ " .
      /* perchpi:ambiguous-timestamp-amber-v1 — live ambiguity: logit < species clearly_tp_logit (default 8.0) */
      "  detections.ROWID AS pp_rid, " .
      "  (SELECT embedding_logit FROM ai_decisions ad WHERE ad.detection_id = detections.ROWID " .
      "     AND ad.embedding_logit IS NOT NULL ORDER BY ad.id LIMIT 1) AS pp_logit, " .
      "  (SELECT clearly_tp_logit FROM species_embedding_thresholds s2 " .
      "     WHERE s2.scientific_name = detections.Sci_Name) AS pp_tp " .
      "FROM detections " .
      "WHERE Date = :d AND Sci_Name = :s AND File_Name NOT LIKE 'ebird_import_%' " .
      "  AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "ORDER BY Time $order";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':d', $date_param);
    $stmt->bindValue(':s', $species_param);
    $res = $stmt->execute();
    if ($res) {
      while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $detections[] = $row;
        if (!empty($row['Com_Name'])) $common_name = $row['Com_Name'];
      }
    }
    $db->close();
  } catch (Exception $e) {
    // Empty list will render empty state.
  }
}

$detection_count = count($detections);

// ─── Header ────────────────────────────────────────────────────────────────

$friendly = pp_format_date_friendly($date_param);
$back_href = '?view=Recordings&date=' . rawurlencode($date_param);
$back_label = $friendly !== '' ? $friendly : $date_param;

$word = $detection_count === 1 ? 'detection' : 'detections';
$subtitle = $back_label . ' · ' . number_format($detection_count) . ' ' . $word;

pp_render_back_nav($back_href, $back_label, $common_name, $subtitle);

// ─── Direction toggle ──────────────────────────────────────────────────────

$base_url = '?view=Recordings'
          . '&date=' . rawurlencode($date_param)
          . '&species=' . rawurlencode($species_param);
$dirs = [
  'asc'  => 'Earliest first',
  'desc' => 'Latest first',
];
?>

<div class="pp-sort-toggle" role="tablist" aria-label="Sort direction">
  <?php foreach ($dirs as $key => $label):
    $active = ($dir === $key);
    $href   = $base_url . '&dir=' . $key;
  ?>
    <a class="pp-sort-toggle-btn<?= $active ? ' is-active' : '' ?>"
       href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"
       role="tab"
       aria-selected="<?= $active ? 'true' : 'false' ?>">
      <?= htmlspecialchars($label, ENT_QUOTES) ?>
    </a>
  <?php endforeach; ?>
</div>

<section class="pp-content">
  <div class="detection-list">
    <?php if (empty($detections)): ?>
      <div class="empty">No detections of this species on this date.</div>
    <?php else: ?>
      <?php foreach ($detections as $d):
        $time_compact = pp_format_time_compact($d['Time']);
        $conf_pct = round(((float)$d['Confidence']) * 100);

        // Build audio + spectrogram URLs.
        // BirdNET-Pi serves recordings via /By_Date/<date>/<sci_under>/<filename>
        // and spectrograms are <filename>.png next to the audio.
        $com_under = str_replace(' ', '_', $d['Com_Name']);
        $file = $d['File_Name'];
        $audio_url = '/By_Date/' . rawurlencode($d['Date']) . '/'
                   . rawurlencode($com_under) . '/' . rawurlencode($file);
        $spec_url  = $audio_url . '.png';
      ?>
        <div class="detection-item"
             data-audio="<?= htmlspecialchars($audio_url, ENT_QUOTES) ?>">
          <?php /* perchpi:ambiguous-timestamp-amber-v1 */
            $pp_tp_eff = isset($d["pp_tp"]) && $d["pp_tp"] !== null ? (float)$d["pp_tp"] : 8.0;
            $pp_amb = (isset($d["pp_logit"]) && $d["pp_logit"] !== null && (float)$d["pp_logit"] < $pp_tp_eff);
            $pp_time_cls = "detection-time" . ($pp_amb ? " detection-time--ambiguous" : "");
          ?>
          <div class="<?= $pp_time_cls ?>"<?= $pp_amb ? ' title="Embedding-ambiguous: logit below species threshold"' : '' ?>><?= htmlspecialchars($time_compact, ENT_QUOTES) ?></div>
          <div class="detection-conf"><?= $conf_pct ?>%<?= /* perchpi:nn-archived-badge-v1 */ pp_render_archived_badge($d) ?></div>
          <button type="button"
                  class="detection-spec"
                  aria-label="Play recording from <?= htmlspecialchars($time_compact, ENT_QUOTES) ?>"
                  data-audio="<?= htmlspecialchars($audio_url, ENT_QUOTES) ?>">
            <img src="<?= htmlspecialchars($spec_url, ENT_QUOTES) ?>"
                 alt=""
                 loading="lazy"
                 onerror="this.style.display='none';this.parentElement.classList.add('detection-spec-missing');">
            <span class="detection-spec-overlay" aria-hidden="true">
              <svg class="detection-spec-play" width="32" height="32" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
              <svg class="detection-spec-pause" width="32" height="32" viewBox="0 0 24 24" fill="currentColor"><path d="M6 4h4v16H6zM14 4h4v16h-4z"/></svg>
            </span>
          </button>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
