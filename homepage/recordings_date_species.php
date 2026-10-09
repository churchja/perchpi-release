<?php
/* perchpi:batch3-2b-fix:v2 */
/* ============================================================================
   recordings_date_species.php — Per-date species list (C-2.4 batch 3)
   ============================================================================
   Lists every species that had detections on the given date, with sort
   toggle (Most detections / Recently active / A-Z).

   2b-fix v2 notes:
     * No kiosk.php include — that page uses relative require paths that
       fatal when included from a sub-context.
     * Two-stage SQL: aggregate per species in a derived table, LEFT JOIN
       detections back for the latest file_name. Avoids the SQLite
       "misuse of aggregate function" error from nesting MAX() in a WHERE.
     * Spectrogram thumbnails only.
     * Whole row is a tappable <a href>.
   ============================================================================ */

require_once __DIR__ . '/perchpi_helpers.php';

// perchpi:photofix — bring in thumbnail_info_for() for proper bird photos.
// perchpi:photo-helpers-phase3-v1 -- kiosk.php shim removed; helpers come from perchpi_helpers.php which chains in common.php
if (!function_exists('get_db')) { require_once __DIR__ . '/../scripts/common.php'; }

// ─── Sanitize date param ──────────────────────────────────────────────────

$date_param = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_param)) {
  echo '<div class="page-header"><h1>Invalid date</h1></div>';
  echo '<div class="empty-state">';
  echo '  <h3>Date not recognized</h3>';
  echo '  <p><a class="btn btn--primary" href="?view=Recordings">Back to Recordings</a></p>';
  echo '</div>';
  return;
}

// ─── Sort param ───────────────────────────────────────────────────────────

// perchpi:recordings-date-clientside-v1 — sort is now client-side
// via the shared list-controls module (see UI below). SQL pre-orders by
// 'cnt DESC' which matches the JS default sort to minimize first-paint reorder.

// ─── Query ────────────────────────────────────────────────────────────────

$db_path = __DIR__ . '/../scripts/birds.db';
if (!file_exists($db_path)) {
  $home = function_exists('get_home') ? get_home() : ($_SERVER['HOME'] ?? '/home/birder');
  $db_path = $home . '/BirdNET-Pi/scripts/birds.db';
}

$species = [];
$total_detections = 0;
if (file_exists($db_path)) {
  try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);

    $order_sql = 'cnt DESC';  // matches JS default; client may reorder

    // Two-stage:
    //   agg = aggregate counts/maxes per species on this date
    //   d   = pick the row matching agg.last_t to get File_Name
    // GROUP BY agg.sci dedupes if two detections share the same Time.
    $sql =
      "SELECT " .
      "  agg.sci AS sci, " .
      "  agg.com AS com, " .
      "  agg.cnt AS cnt, " .
      "  agg.best AS best, " .
      "  agg.last_t AS last_t, " .
      "  MIN(d.File_Name) AS sample_file " .
      "FROM ( " .
      "  SELECT Sci_Name AS sci, " .
      "         MAX(Com_Name) AS com, " .
      "         COUNT(*) AS cnt, " .
      "         MAX(Confidence) AS best, " .
      "         MAX(Time) AS last_t " .
      "  FROM detections " .
      "  WHERE Date = :d1 AND File_Name NOT LIKE 'ebird_import_%' " .
      "    AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "  GROUP BY Sci_Name " .
      ") agg " .
      "LEFT JOIN detections d " .
      "  ON d.Sci_Name = agg.sci " .
      " AND d.Date = :d2 " .
      " AND d.Time = agg.last_t " .
      " AND d.File_Name NOT LIKE 'ebird_import_%' " .
      " AND d.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "GROUP BY agg.sci " .
      "ORDER BY $order_sql";

    $stmt = $db->prepare($sql);
    if ($stmt) {
      $stmt->bindValue(':d1', $date_param);
      $stmt->bindValue(':d2', $date_param);
      $res = $stmt->execute();
      if ($res) {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
          $species[] = $row;
          $total_detections += (int)$row['cnt'];
        }
      }
    }
    $db->close();
  } catch (Exception $e) {
    // Empty list will render empty state.
  }
}

$species_count = count($species);

// ─── Header ────────────────────────────────────────────────────────────────

$friendly = pp_format_date_friendly($date_param);
$page_title = $friendly !== '' ? $friendly : $date_param;

$detection_word = $total_detections === 1 ? 'detection' : 'detections';
$subtitle = number_format($species_count) . ' species · ' .
            number_format($total_detections) . ' ' . $detection_word;

pp_render_back_nav('?view=Recordings', 'Recordings', $page_title, $subtitle);

// ─── Sort toggle ──────────────────────────────────────────────────────────

?>

<!-- perchpi:recordings-date-clientside-v1 — uses shared list-controls module -->
<?php pp_render_list_controls([
  'storage_key'   => 'perchpi-recordings-by-date',
  'row_selector'  => '.species-item',
  'filter_fields' => ['com', 'sci'],
  'item_label'    => 'species',
  'default_sort'  => 'count',
  'sorts' => [
    'count' => [
      'default_dir' => 'desc',
      'label_asc'   => 'Least detections',
      'label_desc'  => 'Most detections',
      'row_attr'    => 'cnt',
      'type'        => 'number',
    ],
    'recent' => [
      'default_dir' => 'desc',
      'label_asc'   => 'Oldest active',
      'label_desc'  => 'Recently active',
      'row_attr'    => 'last',
      'type'        => 'string',
    ],
    'alpha' => [
      'default_dir' => 'asc',
      'label_asc'   => 'A-Z',
      'label_desc'  => 'Z-A',
      'row_attr'    => 'com',
      'type'        => 'string',
    ],
  ],
]); ?>

<section class="pp-content">
  <div class="species-list">
    <?php if (empty($species)): ?>
      <div class="empty">No detections on this date.</div>
    <?php else: ?>
      <?php foreach ($species as $i => $s):
        $com     = htmlspecialchars($s['com'] ?? $s['sci'], ENT_QUOTES);
        $sci     = htmlspecialchars($s['sci'], ENT_QUOTES);
        $sci_url = rawurlencode($s['sci']);
        $cnt     = (int)$s['cnt'];
        $best    = round(((float)$s['best']) * 100);
        $last_t  = isset($s['last_t']) ? pp_format_time_compact($s['last_t']) : '';

        // perchpi:photofix — use thumbnail_info_for() for bird photos w/ spec fallback.
        $proxy = [
          'Sci_Name'  => $s['sci'],
          'Com_Name'  => $s['com'] ?? $s['sci'],
          'Date'      => $date_param,
          'File_Name' => $s['sample_file'] ?? '',
        ];
        if ($proxy['File_Name'] !== '' && function_exists('thumbnail_info_for')) {
          $info  = thumbnail_info_for($proxy);
          $thumb = $info['thumb'] ?? '';
        } else {
          $thumb = '';
        }

        $drill_url = '?view=Recordings&date=' . rawurlencode($date_param)
                   . '&species=' . $sci_url;
      ?>
        <a class="species-item pp-species-row"
           href="<?= htmlspecialchars($drill_url, ENT_QUOTES) ?>"
           data-species-sci="<?= $sci ?>"
           data-pp-com="<?= $com ?>"
           data-pp-sci="<?= $sci ?>"
           data-pp-cnt="<?= $cnt ?>"
           data-pp-last="<?= htmlspecialchars($s['last_t'] ?? '', ENT_QUOTES) ?>">
          <div class="species-rank"><?= $i + 1 ?></div>
          <div class="species-thumb">
            <?php if ($thumb): ?>
              <img src="<?= htmlspecialchars($thumb, ENT_QUOTES) ?>"
                   alt=""
                   loading="lazy"
                   onerror="this.style.display='none'">
            <?php endif; ?>
          </div>
          <div class="species-body">
            <div class="species-name"><?= $com ?></div>
            <div class="species-sci"><?= $sci ?></div>
            <div class="species-meta">
              Best: <?= $best ?>%
              <?php if ($last_t): ?>
                · Last <?= htmlspecialchars($last_t, ENT_QUOTES) ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="species-count">
            <div class="species-count-num"><?= $cnt ?></div>
            <div class="species-count-lbl">on this date</div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<style>
.species-list a.species-item {
  text-decoration: none;
  color: inherit;
  display: flex;
  cursor: pointer;
}
.species-list a.species-item:hover {
  background: var(--surface-2);
}
</style>
