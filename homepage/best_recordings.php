<?php
/* ============================================================================
   best_recordings.php — Library: Best Recordings page (C-2.4 batch 1)
   ============================================================================
   Shows one row per species. For each species:
     - Local audio (highest-confidence non-ebird-import detection) when available
     - XC fallback audio when no local recording exists
   Each row shows: bird thumbnail · spectrogram thumbnail · common name ·
                   sci name · confidence (or "XC sample" badge) · play button
   Tap row → drill into Species Detail
   Tap photo → drill into Species Detail (per .pp-species-row rule)
   Tap play button → audio plays inline (kiosk.js's existing handler)
   ============================================================================ */

// perchpi:photo-helpers-phase3-v1 -- kiosk.php shim removed; helpers come from perchpi_helpers.php which chains in common.php
if (!function_exists('get_db')) { require_once __DIR__ . '/../scripts/common.php'; }

require_once __DIR__ . '/perchpi_helpers.php';

// ─── Build the species list ────────────────────────────────────────────

$home      = '/home/birder';
$xc_dir    = $home . '/BirdSongs/Extracted/xc_audio';

$db = get_db();

// Fetch the highest-confidence local detection per species (excluding ebird imports)
$res = $db->query("
  SELECT d.Sci_Name, d.Com_Name, d.Date, d.Time, d.Confidence, d.File_Name, d.archived_at, d.archive_location /* perchpi:nn-archived-badge-v1 */
  FROM detections d
  INNER JOIN (
    SELECT Sci_Name, MAX(Confidence) AS max_conf
    FROM detections
    WHERE File_Name NOT LIKE 'ebird_import_%'
      AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
    GROUP BY Sci_Name
  ) best ON d.Sci_Name = best.Sci_Name AND d.Confidence = best.max_conf
  WHERE d.File_Name NOT LIKE 'ebird_import_%'
    AND d.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
  GROUP BY d.Sci_Name
  ORDER BY d.Com_Name COLLATE NOCASE ASC
");
$local_best = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  $local_best[$row['Sci_Name']] = $row;
}

// Fetch all distinct species (so we know which need XC fallback)
$res = $db->query("
  SELECT DISTINCT Sci_Name, MAX(Com_Name) AS Com_Name
  FROM detections
  WHERE detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
  GROUP BY Sci_Name
  ORDER BY Com_Name COLLATE NOCASE ASC
");
$all_species = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  $all_species[] = $row;
}

$total_species = count($all_species);
$local_count   = count($local_best);
$xc_count      = $total_species - $local_count;

$subtitle = number_format($total_species) . ' species · ' .
            number_format($local_count) . ' from your mic · ' .
            number_format($xc_count) . ' from Xeno-Canto';

pp_render_back_nav('?view=Overview', 'Overview', 'Best Recordings', $subtitle);

// ─── Helper: build XC row data structure ───────────────────────────────

function pp_xc_row($sci_name, $com_name, $xc_dir) {
  $slug = str_replace(' ', '_', preg_replace('/[^A-Za-z ]/', '', trim($sci_name)));
  $flac_file = "$xc_dir/$slug.flac";
  $png_file  = "$xc_dir/$slug.png";
  $meta_file = "$xc_dir/$slug.json";

  if (!file_exists($flac_file)) return null;

  $meta = ['xc_id' => null, 'recordist' => '', 'license' => '', 'descriptionurl' => ''];
  if (file_exists($meta_file)) {
    $loaded = json_decode(@file_get_contents($meta_file), true);
    if (is_array($loaded)) $meta = array_merge($meta, $loaded);
  }

  return [
    'sci'       => $sci_name,
    'com'       => $com_name,
    'audio_url' => "/xc_audio/$slug.flac",
    'spec_url'  => file_exists($png_file) ? "/xc_audio/$slug.png" : null,
    'is_xc'     => true,
    'xc_meta'   => $meta,
  ];
}

// ─── Helper: build local-recording row data ────────────────────────────

function pp_local_row($det) {
  $audio_url = audio_url_for($det);  // /By_Date/.../...flac
  $spec_url  = $audio_url . '.png';  // BirdNET-Pi generates .flac.png alongside
  // Verify spec exists on disk
  $spec_disk = '/home/birder/BirdSongs/Extracted' . str_replace('/By_Date/', '/By_Date/', $audio_url) . '.png';
  // Cleaner check:
  $spec_disk = '/home/birder/BirdSongs/Extracted' . substr($audio_url, 0) . '.png';
  return [
    'sci'        => $det['Sci_Name'],
    'com'        => $det['Com_Name'],
    'audio_url'  => $audio_url,
    'spec_url'   => $spec_url,  // browser will fail-gracefully if missing
    'is_xc'      => false,
    'date'       => $det['Date'],
    'time'       => $det['Time'],
    'confidence' => round($det['Confidence'] * 100),
    'detection'  => $det,
  ];
}

// ─── Build unified row list ─────────────────────────────────────────────

$rows = [];
foreach ($all_species as $sp) {
  $sci = $sp['Sci_Name'];
  $com = $sp['Com_Name'];

  if (isset($local_best[$sci])) {
    $rows[] = pp_local_row($local_best[$sci]);
  } else {
    $xc = pp_xc_row($sci, $com, $xc_dir);
    if ($xc) $rows[] = $xc;
    // Species with neither local nor XC — skipped silently
  }
}
?>

<section class="pp-content">
  <!-- perchpi:bestrec-clientside-v1 — uses shared list-controls module -->
  <?php pp_render_list_controls([
    'storage_key'   => 'perchpi-bestrec',
    'row_selector'  => '.best-item',
    'filter_fields' => ['com', 'sci'],
    'item_label'    => 'species',
    'default_sort'  => 'alpha',
    'sorts' => [
      'alpha' => [
        'default_dir' => 'asc',
        'label_asc'   => 'Alphabetical',
        'label_desc'  => 'Reverse alphabetical',
        'row_attr'    => 'com',
        'type'        => 'string',
      ],
      'confidence' => [
        'default_dir' => 'desc',
        'label_asc'   => 'Lowest confidence',
        'label_desc'  => 'Highest confidence',
        'row_attr'    => 'conf',
        'type'        => 'number',
        'xc_last'     => true,
      ],
      'recent' => [
        'default_dir' => 'desc',
        'label_asc'   => 'Oldest captures',
        'label_desc'  => 'Recently captured',
        'row_attr'    => 'dt',
        'type'        => 'string',
        'xc_last'     => true,
      ],
    ],
  ]); ?>

  <div class="best-list" id="best-list">
    <?php if (empty($rows)): ?>
      <div class="empty">No recordings yet.</div>
    <?php else: ?>
      <?php foreach ($rows as $r):
        // Resolve thumbnail using kiosk's helpers — pass a synthetic detection
        // row so thumbnail_info_for() can find the species photo
        $proxy = ['Com_Name' => $r['com'], 'Sci_Name' => $r['sci']];
        $thumb_info = thumbnail_info_for($proxy);
        $thumb_url  = $thumb_info['thumb'];
        $is_bird    = $thumb_info['is_bird_photo'] ? '1' : '0';
        $artist     = htmlspecialchars($thumb_info['meta']['artist']         ?? '', ENT_QUOTES);
        $license    = htmlspecialchars($thumb_info['meta']['license']        ?? '', ENT_QUOTES);
        $source     = htmlspecialchars($thumb_info['meta']['descriptionurl'] ?? '', ENT_QUOTES);

        $com_h = htmlspecialchars($r['com'], ENT_QUOTES);
        $sci_h = htmlspecialchars($r['sci'], ENT_QUOTES);
        $audio_h = htmlspecialchars($r['audio_url'], ENT_QUOTES);
        $species_url = '?view=Species%20Profile&species=' . urlencode($r['sci']) . '&from=' . urlencode('Best Recordings'); // perchpi:bestrec-from-param-v1
      ?>
        <div class="best-item pp-species-row"
             data-audio="<?= $audio_h ?>"
             data-species-url="<?= htmlspecialchars($species_url, ENT_QUOTES) ?>"
             data-pp-com="<?= $com_h ?>"
             data-pp-sci="<?= $sci_h ?>"
             data-pp-conf="<?= $r['is_xc'] ? '' : ($r['confidence'] ?? '') ?>"
             data-pp-dt="<?= $r['is_xc'] ? '' : htmlspecialchars(($r['date'] ?? '') . ' ' . ($r['time'] ?? ''), ENT_QUOTES) ?>"
             data-pp-is-xc="<?= $r['is_xc'] ? '1' : '0' ?>">
          <div class="best-thumb thumb-tappable"
               data-is-bird-photo="<?= $is_bird ?>"
               data-common="<?= $com_h ?>"
               data-sci="<?= $sci_h ?>"
               data-photo-artist="<?= $artist ?>"
               data-photo-license="<?= $license ?>"
               data-photo-source="<?= $source ?>">
            <?php if ($thumb_url): ?>
              <img src="<?= htmlspecialchars($thumb_url, ENT_QUOTES) ?>" alt="">
            <?php else: ?>
              <div class="thumb-placeholder-md"></div>
            <?php endif; ?>
          </div>

          <div class="best-body">
            <a class="best-name" href="<?= htmlspecialchars($species_url, ENT_QUOTES) ?>"><?= $com_h ?></a>
            <div class="best-sci"><?= $sci_h ?></div>
            <div class="best-meta">
              <?php if ($r['is_xc']): ?>
                <span class="chip chip-xc" title="From Xeno-Canto (you haven't recorded this species yet)">XC sample</span>
                <?php if (!empty($r['xc_meta']['xc_id'])): ?>
                  <span class="chip chip-xc-id">XC<?= htmlspecialchars($r['xc_meta']['xc_id'], ENT_QUOTES) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="chip chip-conf"><?= $r['confidence'] ?>%</span>
                <span class="chip chip-date"><?= htmlspecialchars($r['date'], ENT_QUOTES) ?></span><?= /* perchpi:nn-archived-badge-v1 */ pp_render_archived_badge($r['detection'] ?? []) ?>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($r['spec_url']): ?>
            <div class="best-spec">
              <img src="<?= htmlspecialchars($r['spec_url'], ENT_QUOTES) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
            </div>
          <?php endif; ?>

          <button class="play-btn play-btn-md" aria-label="Play <?= $com_h ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
          </button>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
