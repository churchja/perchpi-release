<?php
/* ============================================================================
   birds_today.php — Birds Today (species ranked list, today)
   ============================================================================
   Reached from "Birds Today" sidebar item OR "Species" stat tile on Overview.

   Each row:
   - rank
   - photo (tap → bird image lightbox)
   - common name + scientific name
   - last detection time (small line under sci name)
   - count today (large number, right side)
   - small play button on far right (plays most recent audio sample)

   Tapping the row body (not photo, not play) drills into species_detail.php.

   Build: Phase C-2.2.3
   ============================================================================ */

// perchpi:photo-helpers-phase3-v1 -- kiosk.php shim removed; helpers come from perchpi_helpers.php which chains in common.php
if (!function_exists('get_db')) { require_once __DIR__ . '/../scripts/common.php'; }

require_once __DIR__ . '/perchpi_helpers.php';

// Pull all top species today — kiosk's helper covers this
$top = fetch_top_species_today(500);
$count = count($top);
$subtitle = $count === 1 ? '1 species' : $count . ' species';

pp_render_back_nav('?view=Overview', 'Overview', 'Birds Today', $subtitle);
?>

<!-- perchpi:birds-today-clientside-v1 — sort + filter via shared list-controls module -->
<?php pp_render_list_controls([
  'storage_key'   => 'perchpi-birds-today',
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
    'confidence' => [
      'default_dir' => 'desc',
      'label_asc'   => 'Lowest confidence',
      'label_desc'  => 'Highest confidence',
      'row_attr'    => 'conf',
      'type'        => 'number',
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

<section class="section">
  <div class="species-list">
    <?php if ($count === 0): ?>
      <div class="empty">No species detected yet today.</div>
    <?php else: ?>
      <?php foreach ($top as $i => $ts):
        $row_for_thumb = [
          'Com_Name'  => $ts['Com_Name'],
          'Sci_Name'  => $ts['Sci_Name'],
          'Date'      => $ts['Last_Date'],
          'File_Name' => $ts['Last_File'],
        ];
        $info = $ts['Last_File']
          ? thumbnail_info_for($row_for_thumb)
          : ['thumb' => null, 'is_bird_photo' => false, 'meta' => null];

        $thumb   = $info['thumb'];
        $com     = htmlspecialchars($ts['Com_Name'],  ENT_QUOTES);
        $sci     = htmlspecialchars($ts['Sci_Name'],  ENT_QUOTES);
        $sci_url = rawurlencode($ts['Sci_Name']);
        $cnt     = (int)$ts['Cnt'];
        $best    = round($ts['MaxConf'] * 100);
        $last_t  = isset($ts['Last_Time']) ? pp_format_time_compact($ts['Last_Time']) : '';
        $audio   = $ts['Last_File'] ? audio_url_for($row_for_thumb) : '';
        $is_bird = $info['is_bird_photo'] ? '1' : '0';
        $artist  = htmlspecialchars($info['meta']['artist']         ?? '', ENT_QUOTES);
        $license = htmlspecialchars($info['meta']['license']        ?? '', ENT_QUOTES);
        $source  = htmlspecialchars($info['meta']['descriptionurl'] ?? '', ENT_QUOTES);

        $thumb_class = 'species-thumb' . ($info['is_bird_photo'] ? ' thumb-tappable' : '');
      ?>
        <div class="species-item pp-species-row"
             data-species-sci="<?= $sci ?>"
             data-species-link="?view=Species Detail&species=<?= $sci_url ?>"
             data-audio="<?= htmlspecialchars($audio, ENT_QUOTES) ?>"
             data-pp-com="<?= $com ?>"
             data-pp-sci="<?= $sci ?>"
             data-pp-cnt="<?= $cnt ?>"
             data-pp-conf="<?= (float)$ts['MaxConf'] ?>"
             data-pp-last="<?= htmlspecialchars($ts['Last_Time'] ?? '', ENT_QUOTES) ?>">
          <div class="species-rank"><?= $i + 1 ?></div>
          <div class="<?= $thumb_class ?>"
               data-is-bird-photo="<?= $is_bird ?>"
               data-common="<?= $com ?>"
               data-sci="<?= $sci ?>"
               data-photo-artist="<?= $artist ?>"
               data-photo-license="<?= $license ?>"
               data-photo-source="<?= $source ?>">
            <?php if ($thumb): ?>
              <img src="<?= htmlspecialchars($thumb, ENT_QUOTES) ?>" alt="">
            <?php else: ?>
              <div class="thumb-placeholder-md"></div>
            <?php endif; ?>
          </div>
          <div class="species-body pp-species-body" role="button" tabindex="0">
            <div class="species-name"><?= $com ?><?= pp_geomodel_pill($ts['Sci_Name']) ?></div>
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
            <div class="species-count-lbl">today</div>
          </div>
          <?php if ($audio): ?>
            <button class="play-btn play-btn-md pp-species-play" aria-label="Play sample">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
<?php /* perchpi:phase2a-pills */ ?>
