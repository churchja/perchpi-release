<?php
/* ============================================================================
   species_detail.php — Species Detail (5 time buckets, current period first)
   v2: handles zero-detections-today gracefully (renders hero card with proper
       common name even when today has no detections of this species)
   ============================================================================
   Reached from any species row on Birds Today, All Species, Best Recordings.
   URL: ?view=Species Detail&species=<sci_name>

   Layout:
   - Hero: large photo (tappable lightbox) + common name + scientific name
   - Stats strip (when there are today's detections): count, best confidence,
     first detection, last detection
   - 5 time buckets in current-period-first order
   - When no detections today: hero card still renders with common name
     resolved from the most recent detection ever; buckets show "No detections"
   ============================================================================ */

// perchpi:photo-helpers-phase3-v1 -- kiosk.php shim removed; helpers come from perchpi_helpers.php which chains in common.php
if (!function_exists('get_db')) { require_once __DIR__ . '/../scripts/common.php'; }
require_once __DIR__ . '/perchpi_helpers.php';

$sci_name = isset($_GET['species']) ? (string)$_GET['species'] : ''; // perchpi:get-post-deescape-v1
if ($sci_name === '') {
  echo '<div class="page-header"><h1>Species not specified</h1></div>';
  echo '<div class="empty-state"><p><a class="btn btn--primary" href="?view=Birds+Today">Back to Birds Today</a></p></div>';
  return;
}

$home = function_exists('get_home') ? get_home() : '/home/birder';
$db_path = __DIR__ . '/../scripts/birds.db';
if (!file_exists($db_path)) {
  $db_path = $home . '/BirdNET-Pi/scripts/birds.db';
}

$detections = [];
$com_name = $sci_name;
$first_time = '';
$last_time = '';
$best_conf = 0;

if (file_exists($db_path)) {
  try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(1000);
    $stmt = $db->prepare(
      "SELECT Date, Time, Com_Name, Sci_Name, Confidence, File_Name " .
      "FROM detections " .
      "WHERE Sci_Name = :sci AND Date = DATE('now', 'localtime') " .
      "  AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "ORDER BY Time DESC"
    );
    $stmt->bindValue(':sci', $sci_name, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
      $detections[] = $row;
      if ($com_name === $sci_name) $com_name = $row['Com_Name'];
      if (!$first_time || $row['Time'] < $first_time) $first_time = $row['Time'];
      if (!$last_time  || $row['Time'] > $last_time)  $last_time  = $row['Time'];
      if ($row['Confidence'] > $best_conf) $best_conf = $row['Confidence'];
    }
    $db->close();
  } catch (Exception $e) {
    // empty
  }
}

// Empty-state fallback: if no detections today, look up the most recent
// detection ever for this species so we have a common name + hero photo.
$fallback_row = null;
if (count($detections) === 0 && file_exists($db_path)) {
  try {
    $db2 = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db2->busyTimeout(1000);
    $stmt2 = $db2->prepare(
      "SELECT Date, Time, Com_Name, Sci_Name, Confidence, File_Name " .
      "FROM detections " .
      "WHERE Sci_Name = :sci " .
      "  AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "ORDER BY Date DESC, Time DESC " .
      "LIMIT 1"
    );
    $stmt2->bindValue(':sci', $sci_name, SQLITE3_TEXT);
    $r2 = $stmt2->execute();
    $fallback_row = $r2->fetchArray(SQLITE3_ASSOC) ?: null;
    if ($fallback_row && $com_name === $sci_name) {
      $com_name = $fallback_row['Com_Name'];
    }
    $db2->close();
  } catch (Exception $e) {
    // empty
  }
}

$count = count($detections);

// Group by bucket
$by_bucket = pp_group_detections_by_bucket($detections);
$bucket_order = pp_ordered_bucket_ids();
$buckets_def = pp_buckets();

// Find largest bucket (for touch default-collapse, no longer used since we
// collapse all by default — kept for compatibility)
$largest_bucket_id = null;
$largest_count = 0;
foreach ($by_bucket as $bid => $items) {
  if (count($items) > $largest_count) {
    $largest_count = count($items);
    $largest_bucket_id = $bid;
  }
}
$collapse_largest_on_touch = ($largest_count > 8);

// Hero photo source row: today's first detection > fallback row > synthesized
$hero_source_row = null;
if ($count > 0) {
  $hero_source_row = $detections[0];
} elseif ($fallback_row) {
  $hero_source_row = $fallback_row;
} else {
  $hero_source_row = ['Com_Name' => $com_name, 'Sci_Name' => $sci_name];
}
$info = thumbnail_info_for($hero_source_row);
$hero_thumb = $info['thumb'];
$hero_is_bird = $info['is_bird_photo'];
$hero_meta = ['artist' => '', 'license' => '', 'descriptionurl' => ''];
if ($info['meta']) $hero_meta = array_merge($hero_meta, $info['meta']);

$com_h = htmlspecialchars($com_name, ENT_QUOTES);
$sci_h = htmlspecialchars($sci_name, ENT_QUOTES);
?>

<?php /* perchpi:species-hero-dedup-v1 */ pp_render_back_nav('?view=Birds+Today', 'Birds Today', '', ''); ?>

<!-- Hero card always renders -->
<section class="section">
  <div class="latest-card pp-species-hero">
    <div class="latest-thumb <?= $hero_is_bird ? 'thumb-tappable' : '' ?>"
         data-is-bird-photo="<?= $hero_is_bird ? '1' : '0' ?>"
         data-common="<?= $com_h ?>"
         data-sci="<?= $sci_h ?>"
         data-photo-artist="<?= htmlspecialchars($hero_meta['artist'], ENT_QUOTES) ?>"
         data-photo-license="<?= htmlspecialchars($hero_meta['license'], ENT_QUOTES) ?>"
         data-photo-source="<?= htmlspecialchars($hero_meta['descriptionurl'], ENT_QUOTES) ?>">
      <?php if ($hero_thumb): ?>
        <img src="<?= htmlspecialchars($hero_thumb, ENT_QUOTES) ?>" alt="">
      <?php else: ?>
        <div class="thumb-placeholder"></div>
      <?php endif; ?>
    </div>
    <div class="latest-card-body">
      <!-- perchpi:species-hero-name-typography-v1 — names link to Species Profile -->
      <div class="latest-card-name"><a class="latest-card-spp-link" href="?view=Species%20Profile&species=<?= rawurlencode($sci_name) ?>&from=Birds+Today"><?= $com_h ?></a></div>
      <div class="latest-card-sci"><a class="latest-card-spp-link" href="?view=Species%20Profile&species=<?= rawurlencode($sci_name) ?>&from=Birds+Today"><?= $sci_h ?></a></div>
      <?php if ($count > 0): ?>
        <div class="latest-card-stats">
          <span class="chip chip-conf"><?= round($best_conf * 100) ?>% best</span>
          <span class="chip"><?= $count ?>× today</span>
          <?php if ($first_time && $last_time && $first_time !== $last_time): ?>
            <span class="chip chip-time"><?= htmlspecialchars(substr($first_time, 0, 5), ENT_QUOTES) ?> – <?= htmlspecialchars(substr($last_time, 0, 5), ENT_QUOTES) ?></span>
          <?php elseif ($last_time): ?>
            <span class="chip chip-time"><?= htmlspecialchars(substr($last_time, 0, 5), ENT_QUOTES) ?></span>
          <?php endif; ?>
        </div>
      <?php elseif ($fallback_row): ?>
        <div class="latest-card-stats">
          <span class="chip chip-muted">Last seen <?= htmlspecialchars($fallback_row['Date'], ENT_QUOTES) ?></span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($count === 0): ?>
  <div class="empty pp-empty-today">No detections of this species today.</div>
<?php endif; ?>

<!-- Time buckets always render -->
<?php foreach ($bucket_order as $bid):
  $bucket_def = $buckets_def[$bid];
  $bucket_items = $by_bucket[$bid] ?? [];
  $bucket_count = count($bucket_items);
  $is_largest_touch = ($bid === $largest_bucket_id && $collapse_largest_on_touch);
?>
  <section class="section pp-bucket"
           data-bucket-id="<?= htmlspecialchars($bid, ENT_QUOTES) ?>"
           <?= $is_largest_touch ? 'data-collapse-touch-default="1"' : '' ?>>
    <div class="pp-bucket-header" role="button" tabindex="0" aria-expanded="true">
      <div class="pp-bucket-header-left">
        <span class="pp-bucket-toggle" aria-hidden="true">▾</span>
        <span class="pp-bucket-label"><?= htmlspecialchars($bucket_def['label'], ENT_QUOTES) ?></span>
        <span class="pp-bucket-time"><?= htmlspecialchars($bucket_def['desc'], ENT_QUOTES) ?></span>
      </div>
      <div class="pp-bucket-count">
        <?= $bucket_count ?>
      </div>
    </div>
    <div class="pp-bucket-body">
      <?php if ($bucket_count === 0): ?>
        <div class="empty pp-bucket-empty">No detections</div>
      <?php else: ?>
        <?php foreach ($bucket_items as $row) {
          pp_render_today_item($row);
        } ?>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>
