<?php
/* perchpi:species-stats-restyle */
/* perchpi:species-stats-url-fixes-v7 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'scripts/common.php';
require_once __DIR__ . '/../homepage/perchpi_helpers.php';  // perchpi:species-stats-photo-resolver-v2

/* ============================================================================
   stats.php — Species Stats ("callsign explorer")
   Spectrogram-first list of all detected species with audio scrubber per row.
   Sort: most detections / recently active / highest confidence / alphabetical.
   Drills into species_profile.php for full bird info.
   ============================================================================ */

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

/* perchpi:stats-clientside-v1 — sort + filter now client-side via shared list-controls module. */
/* SQL pre-order matches the JS default (detections desc) to minimize first-paint reorder. */
$order_by = 'COUNT(*) DESC';

$species_q = $db->prepare(
  "SELECT
     Sci_Name, Com_Name,
     COUNT(*)                AS det_count,
     MAX(Confidence)         AS max_conf,
     MAX(Date || ' ' || Time) AS last_seen
   FROM detections
   WHERE detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
   GROUP BY Sci_Name
   ORDER BY {$order_by}"
);
ensure_db_ok($species_q);
$species_res = $species_q->execute();

$species = [];
while ($r = $species_res->fetchArray(SQLITE3_ASSOC)) {
  $species[] = $r;
}

/* For each species we need its "best recording" (highest confidence detection)
 * to drive the audio scrubber. Fetch in a single query.
 */
$best_by_species = [];  // sci_name => ['File_Name'=>..., 'Date'=>..., 'Time'=>...]
$best_q = $db->query(
  "SELECT d.Sci_Name, d.Com_Name, d.File_Name, d.Date, d.Time, d.Confidence
   FROM detections d
   INNER JOIN (
     SELECT Sci_Name, MAX(Confidence) AS best_conf
     FROM detections
     WHERE File_Name NOT LIKE 'ebird_import_%'
       AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
     GROUP BY Sci_Name
   ) m ON d.Sci_Name = m.Sci_Name AND d.Confidence = m.best_conf
   WHERE d.File_Name NOT LIKE 'ebird_import_%'
     AND d.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
   GROUP BY d.Sci_Name"
);
while ($r = $best_q->fetchArray(SQLITE3_ASSOC)) {
  $best_by_species[$r['Sci_Name']] = $r;
}

/* Helper: build audio + spectrogram URLs from a detection row.
 * The BirdNET-Pi convention: extracted audio lives under By_Date/<YYYY-MM-DD>/<sci_name>/<File_Name>
 * and spectrogram PNG sits next to the audio file with .png extension.
 */
function pp_ss_audio_url($row) {
  if (empty($row['File_Name']) || empty($row['Date']) || empty($row['Com_Name'])) return null;
  if (strpos($row['File_Name'], 'ebird_import_') === 0) return null;
  // On-disk dirs use kiosk.php's com_to_slug convention: spaces->underscores,
  // apostrophes stripped. Sci_Name (used by v4) matches NO directory.
  $slug = str_replace("'", '', str_replace(' ', '_', $row['Com_Name']));
  $rel = '/By_Date/' . $row['Date'] . '/' . $slug . '/' . $row['File_Name'];
  $home = function_exists('get_home') ? get_home() : '/home/birder';
  $disk = $home . '/BirdSongs/Extracted' . $rel;
  if (!file_exists($disk)) return null;
  return $rel;
}
function pp_ss_spec_url($row) {
  $audio = pp_ss_audio_url($row);
  if (!$audio) return null;
  return $audio . '.png';
}

/* ============================================================================
   HTML rendering
   ============================================================================ */

$pp_standalone = (get_included_files()[0] === __FILE__);
if ($pp_standalone) {
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>Species Stats</title></head><body>';
}

if (!$pp_standalone && function_exists('pp_render_back_nav')) {
  pp_render_back_nav('?view=Overview', 'Overview', 'Species Stats', count($species) . ' species');
}
?>
<section class="pp-content">

<!-- perchpi:stats-clientside-v1 — uses shared list-controls module -->
<?php pp_render_list_controls([
  'storage_key'   => 'perchpi-species-stats',
  'row_selector'  => '.pp-ss-row',
  'filter_fields' => ['com', 'sci'],
  'item_label'    => 'species',
  'default_sort'  => 'detections',
  'sorts' => [
    'detections' => [
      'default_dir' => 'desc',
      'label_asc'   => 'Least detections',
      'label_desc'  => 'Most detections',
      'row_attr'    => 'count',
      'type'        => 'number',
    ],
    'recent' => [
      'default_dir' => 'desc',
      'label_asc'   => 'Oldest first',
      'label_desc'  => 'Recently active',
      'row_attr'    => 'lastseen',
      'type'        => 'string',
    ],
    'confidence' => [
      'default_dir' => 'desc',
      'label_asc'   => 'Lowest confidence',
      'label_desc'  => 'Highest confidence',
      'row_attr'    => 'conf',
      'type'        => 'number',
    ],
    'alpha' => [
      'default_dir' => 'asc',
      'label_asc'   => 'Alphabetical',
      'label_desc'  => 'Reverse alphabetical',
      'row_attr'    => 'com',
      'type'        => 'string',
    ],
  ],
]); ?>

<?php if (count($species) === 0): ?>
  <div class="pp-ss-empty">No species detected yet.</div>
<?php else: ?>
  <div class="pp-ss-list">
    <?php foreach ($species as $sp):
      $sn = $sp['Sci_Name'];
      $cn = $sp['Com_Name'];
      $best = isset($best_by_species[$sn]) ? $best_by_species[$sn] : null;
      $audio_url = $best ? pp_ss_audio_url($best) : null;
      $spec_url  = $best ? pp_ss_spec_url($best) : null;

      // Last-seen friendly format
      $last_seen_label = '';
      if (!empty($sp['last_seen'])) {
        $ts = strtotime($sp['last_seen']);
        if ($ts) $last_seen_label = 'Last ' . date('Y-m-d', $ts);
      }

      // Profile drill-in URL
      $profile_url = '?view=Species%20Profile&species=' . rawurlencode($sn) . '&from=Species+Stats';
    ?>
      <div class="pp-ss-row"
           data-pp-com="<?= htmlspecialchars($cn, ENT_QUOTES) ?>"
           data-pp-sci="<?= htmlspecialchars($sn, ENT_QUOTES) ?>"
           data-pp-count="<?= (int)$sp['det_count'] ?>"
           data-pp-conf="<?= (float)$sp['max_conf'] ?>"
           data-pp-lastseen="<?= htmlspecialchars($sp['last_seen'] ?? '', ENT_QUOTES) ?>">
        <a class="pp-ss-thumb" href="<?= $profile_url ?>" title="View species profile">
          <?php
          // perchpi:species-stats-photo-resolver-v2
          // Refactored: now calls canonical bird_photo_url_for() from perchpi_helpers.php
          // Eliminates 14 lines of duplicated priority-chain logic.
          $photo_url = bird_photo_url_for($sn, $cn);
          if ($photo_url !== null) {
            echo '<img src="' . $photo_url . '" alt="" loading="lazy">';
          } else {
            echo '<div class="pp-ss-thumb-fallback">🐦</div>';
          }
          ?>
        </a>

        <div class="pp-ss-center">
          <div class="pp-ss-name">
            <a class="pp-ss-name-com" href="<?= $profile_url ?>"><?= htmlspecialchars($cn) ?></a>
            <span class="pp-ss-name-sci"><?= htmlspecialchars($sn) ?></span>
          </div>
          <?php if ($audio_url): ?>
            <div class="pp-scrubber"
                 data-audio="<?= htmlspecialchars($audio_url) ?>"
                 data-spectrogram="<?= htmlspecialchars($spec_url ?? '') ?>"></div>
          <?php else: ?>
            <div class="pp-scrubber pp-scrubber-no-spec">
              <div class="pp-scrubber-empty">no recording available</div>
            </div>
          <?php endif; ?>
        </div>

        <div class="pp-ss-meta">
          <div class="pp-ss-meta-count"><?= number_format($sp['det_count']) ?></div>
          <div class="pp-ss-meta-label">detections</div>
          <?php if ($sp['max_conf']): ?>
            <div class="pp-ss-meta-sub">Best: <?= round($sp['max_conf'] * 100) ?>%</div>
          <?php endif; ?>
          <?php if ($last_seen_label): ?>
            <div class="pp-ss-meta-sub"><?= htmlspecialchars($last_seen_label) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</section>

<script src="pp_scrubber.js"></script>
<?php
if ($pp_standalone) echo '</body></html>';
?>
