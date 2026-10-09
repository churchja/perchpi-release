<?php
/* ============================================================================
   all_species.php — All Species (lifetime detections, sortable)
   ============================================================================
   Reached via:
   - "All-Time Species" sys-item tile on Overview
   - "All Species" sidebar item under LIBRARY

   Sorts (toggle):
   - count (default) — total detections, descending
   - recent          — last detection date, most recent first
   - newest          — first detection date, newest discoveries first

   Each row shows:
   - rank, photo (lightbox), common + scientific name
   - "Best X% · Seen on N days · Last [date]"
   - total count (large), play button (audio of most recent sample)
   - clicking row body drills into Species Detail (today's view)
     [Note: Species Detail currently shows "today" buckets — that's a known
     limitation we can extend later to support all-time view of a species.]

   Build: Phase C-2.2.4
   ============================================================================ */

// perchpi:photo-helpers-phase3-v1 -- kiosk.php shim removed; helpers come from perchpi_helpers.php which chains in common.php
if (!function_exists('get_db')) { require_once __DIR__ . '/../scripts/common.php'; }

require_once __DIR__ . '/perchpi_helpers.php';

// Sort param: count | recent | newest
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'count';
// perchpi:all-species-sort-filter-v1 — added confidence + alpha
if (!in_array($sort, ['count', 'recent', 'newest', 'confidence', 'alpha'], true)) $sort = 'count';

$home = function_exists('get_home') ? get_home() : '/home/birder';
$db_path = __DIR__ . '/../scripts/birds.db';
if (!file_exists($db_path)) {
  $db_path = $home . '/BirdNET-Pi/scripts/birds.db';
}

$species = [];
if (file_exists($db_path)) {
  try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);

    // One query that produces every metric we need per species. ORDER BY
    // chosen via sort param.
    $order_sql = 'cnt DESC';
    if ($sort === 'recent')     $order_sql = 'last_dt DESC';
    if ($sort === 'newest')     $order_sql = 'first_dt DESC';
    // perchpi:all-species-sort-filter-v1 — server-side fallback for new sorts
    if ($sort === 'confidence') $order_sql = 'best DESC';
    if ($sort === 'alpha')      $order_sql = 'com_name ASC';

    $sql =
      "SELECT " .
      "  Sci_Name AS sci, " .
      "  MAX(Com_Name) AS com, " .
      "  COUNT(*) AS cnt, " .
      "  MAX(Confidence) AS best, " .
      "  COUNT(DISTINCT Date) AS days_seen, " .
      "  MAX(Date || ' ' || Time) AS last_dt, " .
      "  MIN(Date || ' ' || Time) AS first_dt " .
      "FROM detections " .
      "WHERE detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "GROUP BY Sci_Name " .
      "ORDER BY $order_sql";

    $res = $db->query($sql);
    if ($res) {
      while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $species[] = $row;
      }
    }

    // Need the most-recent file for each species too, for thumbnail + audio
    // (do as a separate query for performance — one row per species)
    $latest_files = [];
    $res2 = $db->query(
      "SELECT d.Sci_Name, d.File_Name, d.Date, d.Com_Name " .
      "FROM detections d " .
      "JOIN ( " .
      "  SELECT Sci_Name, MAX(Date || Time) AS max_dt " .
      "  FROM detections " .
      "  WHERE detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "  GROUP BY Sci_Name " .
      ") latest_per_species " .
      "  ON d.Sci_Name = latest_per_species.Sci_Name " .
      " AND d.Date || d.Time = latest_per_species.max_dt " .
      "WHERE d.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "GROUP BY d.Sci_Name"
    );
    if ($res2) {
      while ($row = $res2->fetchArray(SQLITE3_ASSOC)) {
        $latest_files[$row['Sci_Name']] = $row;
      }
    }

    $db->close();

    // Merge latest-file data
    foreach ($species as &$s) {
      $lf = $latest_files[$s['sci']] ?? null;
      $s['latest_file'] = $lf['File_Name'] ?? '';
      $s['latest_date'] = $lf['Date']      ?? '';
      // Prefer the most-recent Com_Name (some BirdNET versions update common names)
      if (!empty($lf['Com_Name'])) $s['com'] = $lf['Com_Name'];
    }
    unset($s);
  } catch (Exception $e) {
    // empty
  }
}

$count = count($species);
$subtitle = $count === 1 ? '1 species' : $count . ' species';

pp_render_back_nav('?view=Overview', 'Overview', 'All Species', $subtitle);

// Sort toggle (button group)
$base_url = '?view=' . rawurlencode('All Species');
// perchpi:all-species-sort-filter-v1 — 5 sorts, each with a desc/asc label pair.
$sorts = [
  'count'      => ['Most Detections',    'Least Detections'],
  'recent'     => ['Latest Detected',    'Oldest Detected'], // perchpi:all-species-active-rename-v1
  'newest'     => ['Newest Species',     'Oldest Species'],
  'confidence' => ['Highest Confidence', 'Lowest Confidence'],
  'alpha'      => ['Alphabetical',       'Reverse Alphabetical'],
];
?>

<div class="pp-sort-toggle pp-sort-toggle-v2" role="tablist" aria-label="Sort order">
  <?php foreach ($sorts as $key => $labels):
    $active = ($sort === $key);
    // The server-side default direction is descending for all sorts (the
    // SQL ORDER BY uses DESC, except alpha which is ASC but conceptually
    // its "default" view is A-first, which is its descending UI state).
    $href = $base_url . '&sort=' . $key;
  ?>
    <a class="pp-sort-toggle-btn<?= $active ? ' is-active' : '' ?>"
       href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"
       role="tab"
       aria-selected="<?= $active ? 'true' : 'false' ?>"
       data-sort-key="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
       data-label-desc="<?= htmlspecialchars($labels[0], ENT_QUOTES) ?>"
       data-label-asc="<?= htmlspecialchars($labels[1], ENT_QUOTES) ?>">
      <span class="pp-sort-label"><?= htmlspecialchars($labels[0], ENT_QUOTES) ?></span>
      <span class="pp-sort-arrow" aria-hidden="true">&#9660;</span>
    </a>
  <?php endforeach; ?>
</div>

<!-- perchpi:all-species-sort-filter-v1 — filter input -->
<div class="pp-species-filter">
  <input id="pp-species-filter-input" type="text"
         placeholder="Filter species... (name, scientific)"
         autocomplete="off" spellcheck="false">
  <span id="pp-species-filter-count" class="pp-species-filter-count"></span>
</div>

<section class="section">
  <div class="species-list">
    <?php if ($count === 0): ?>
      <div class="empty">No species detected yet.</div>
    <?php else: ?>
      <?php foreach ($species as $i => $s):
        $row_for_thumb = [
          'Com_Name'  => $s['com'],
          'Sci_Name'  => $s['sci'],
          'Date'      => $s['latest_date'],
          'File_Name' => $s['latest_file'],
        ];
        $info = $s['latest_file']
          ? thumbnail_info_for($row_for_thumb)
          : ['thumb' => null, 'is_bird_photo' => false, 'meta' => null];

        $thumb   = $info['thumb'];
        $com     = htmlspecialchars($s['com'], ENT_QUOTES);
        $sci     = htmlspecialchars($s['sci'], ENT_QUOTES);
        $sci_url = rawurlencode($s['sci']);
        $cnt     = (int)$s['cnt'];
        $best    = round($s['best'] * 100);
        $days    = (int)$s['days_seen'];

        // Format last_dt and first_dt for display (Y-m-d HH:MM:SS)
        $last_d = substr($s['last_dt'] ?? '', 0, 10);
        $audio  = $s['latest_file'] ? audio_url_for($row_for_thumb) : '';
        $is_bird = $info['is_bird_photo'] ? '1' : '0';
        $artist  = htmlspecialchars($info['meta']['artist']         ?? '', ENT_QUOTES);
        $license = htmlspecialchars($info['meta']['license']        ?? '', ENT_QUOTES);
        $source  = htmlspecialchars($info['meta']['descriptionurl'] ?? '', ENT_QUOTES);

        $thumb_class = 'species-thumb' . ($info['is_bird_photo'] ? ' thumb-tappable' : '');
      ?>
        <?php
          // perchpi:all-species-sort-filter-v1 — sort keys for client-side ordering.
          $pp_sort_recent = $s['last_dt']  ? (int)strtotime($s['last_dt'])  : 0;
          $pp_sort_newest = isset($s['first_dt']) && $s['first_dt'] ? (int)strtotime($s['first_dt']) : 0;
          $pp_sort_alpha  = strtolower(strip_tags($s['com']));
        ?>
        <div class="species-item pp-species-row"
             data-species-sci="<?= $sci ?>"
             data-species-link="?view=Species Profile&species=<?= $sci_url ?>&from=All+Species"
             data-audio="<?= htmlspecialchars($audio, ENT_QUOTES) ?>"
             data-sort-count="<?= $cnt ?>"
             data-sort-recent="<?= $pp_sort_recent ?>"
             data-sort-newest="<?= $pp_sort_newest ?>"
             data-sort-confidence="<?= $best ?>"
             data-sort-alpha="<?= htmlspecialchars($pp_sort_alpha, ENT_QUOTES) ?>">
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
            <div class="species-name"><?= $com ?></div>
            <div class="species-sci"><?= $sci ?></div>
            <div class="species-meta">
              Best: <?= $best ?>%
              · Seen <?= $days ?> day<?= $days === 1 ? '' : 's' ?>
              <?php if ($last_d): ?>
                · Last <?= htmlspecialchars($last_d, ENT_QUOTES) ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="species-count">
            <div class="species-count-num"><?= $cnt ?></div>
            <div class="species-count-lbl">total</div>
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

<!-- perchpi:all-species-sort-filter-v1 — sort/filter CSS + JS -->
<style>
  .pp-sort-toggle-v2 {
    flex-wrap: wrap;
    gap: 4px;
  }
  .pp-sort-toggle-v2 .pp-sort-toggle-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    cursor: pointer;
  }
  .pp-sort-arrow {
    font-size: 0.7em;
    opacity: 0.7;
    margin-left: 2px;
  }
  /* Q10=B: shrink button font on narrow viewports so all 5 fit on one row */
  @media (max-width: 720px) {
    .pp-sort-toggle-v2 .pp-sort-toggle-btn {
      font-size: 12px;
      padding: 4px 8px;
    }
    .pp-sort-arrow { font-size: 0.65em; }
  }

  .pp-species-filter {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 8px 0 12px;
    padding: 0 4px;
  }
  .pp-species-filter input {
    flex: 1;
    padding: 8px 12px;
    background: var(--surface-2, #1a1a1d);
    border: 1px solid var(--border, #2a2a2f);
    border-radius: 6px;
    color: var(--text, #fff);
    font-size: 14px;
  }
  .pp-species-filter input:focus {
    outline: none;
    border-color: var(--accent, #7be07b);
  }
  .pp-species-filter-count {
    color: var(--text-dim, #a8a8ad);
    font-size: 13px;
    min-width: 70px;
    text-align: right;
    white-space: nowrap;
  }
</style>

<script>
(function() {
  'use strict';
  // perchpi:all-species-sort-filter-v1
  // Client-side sort + filter for the All Species page. Reads data-sort-*
  // attributes baked into each .pp-species-row by the PHP loop above.

  var LS_KEY_SORT = 'ppAllSpeciesSort';      // 'count' | 'recent' | 'newest' | 'confidence' | 'alpha'
  var LS_KEY_DIR  = 'ppAllSpeciesSortDir';   // 'desc' | 'asc'
  // Per-sort default direction (mirrors server-side ORDER BY).
  // 'alpha' default is ASC (A-first); all others default DESC.
  // The UI shows ▼ for the "default-direction" view of any sort, regardless
  // of whether the underlying SQL is DESC or ASC — i.e. ▼ = "natural top",
  // ▲ = "reversed".
  var DEFAULT_DIR = {
    count:      'desc',
    recent:     'desc',
    newest:     'desc',
    confidence: 'desc',
    alpha:      'asc'
  };

  var list = document.querySelector('.species-list');
  if (!list) return;

  var rows = Array.prototype.slice.call(list.querySelectorAll('.pp-species-row'));
  var buttons = Array.prototype.slice.call(document.querySelectorAll('.pp-sort-toggle-v2 .pp-sort-toggle-btn'));
  var filterInput = document.getElementById('pp-species-filter-input');
  var filterCount = document.getElementById('pp-species-filter-count');

  // Numeric-vs-string sort dispatch keyed by attribute name.
  var NUMERIC_SORTS = { count:true, recent:true, newest:true, confidence:true };

  function cmpValues(a, b, key, dir) {
    var attr = 'data-sort-' + key;
    var av = a.getAttribute(attr);
    var bv = b.getAttribute(attr);
    var lt, gt;
    if (NUMERIC_SORTS[key]) {
      av = parseFloat(av) || 0;
      bv = parseFloat(bv) || 0;
      lt = av < bv; gt = av > bv;
    } else {
      av = av || ''; bv = bv || '';
      lt = av < bv; gt = av > bv;
    }
    if (lt) return dir === 'asc' ? -1 : 1;
    if (gt) return dir === 'asc' ? 1 : -1;
    return 0;
  }

  function isVisible(row) { return row.style.display !== 'none'; }

  function renumberRanks() {
    var rank = 1;
    Array.prototype.slice.call(list.querySelectorAll('.pp-species-row')).forEach(function(row) {
      var rankEl = row.querySelector('.species-rank');
      if (!rankEl) return;
      if (isVisible(row)) {
        rankEl.textContent = rank++;
      }
    });
  }

  function applySort(key, dir) {
    rows.sort(function(a, b) { return cmpValues(a, b, key, dir); });
    rows.forEach(function(row) { list.appendChild(row); });
    renumberRanks();
  }

  function updateButtonUI(activeKey, dir) {
    buttons.forEach(function(btn) {
      var btnKey = btn.getAttribute('data-sort-key');
      var labelEl = btn.querySelector('.pp-sort-label');
      var arrowEl = btn.querySelector('.pp-sort-arrow');
      var isActive = (btnKey === activeKey);
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
      // Determine if this button is showing its descending (default) view
      // or ascending (reversed) view. The DEFAULT_DIR for the sort tells us
      // which UI direction corresponds to ▼.
      var defaultDir = DEFAULT_DIR[btnKey] || 'desc';
      var isReversed = (dir !== defaultDir);
      if (isActive && isReversed) {
        labelEl.textContent = btn.getAttribute('data-label-asc');
        arrowEl.innerHTML = '&#9650;'; // ▲
      } else {
        labelEl.textContent = btn.getAttribute('data-label-desc');
        arrowEl.innerHTML = '&#9660;'; // ▼
      }
    });
  }

  function applyFilter() {
    var needle = (filterInput.value || '').trim().toLowerCase();
    var shown = 0;
    var total = 0;
    rows.forEach(function(row) {
      total++;
      var com = row.getAttribute('data-sort-alpha') || '';
      var sci = (row.getAttribute('data-species-sci') || '').toLowerCase();
      var visible = !needle || com.indexOf(needle) !== -1 || sci.indexOf(needle) !== -1;
      row.style.display = visible ? '' : 'none';
      if (visible) shown++;
    });
    if (filterCount) {
      filterCount.textContent = total ? (shown + ' / ' + total) : '';
    }
    renumberRanks();
  }

  function setSort(key, dir, persist) {
    applySort(key, dir);
    updateButtonUI(key, dir);
    applyFilter(); // re-applies filter + re-numbers ranks after sort
    if (persist) {
      try {
        localStorage.setItem(LS_KEY_SORT, key);
        localStorage.setItem(LS_KEY_DIR, dir);
      } catch (e) {}
    }
  }

  // Hook up button clicks (suppress the href navigation — we sort in place).
  buttons.forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      var key = btn.getAttribute('data-sort-key');
      var defaultDir = DEFAULT_DIR[key] || 'desc';
      var curKey = localStorage.getItem(LS_KEY_SORT) || 'count';
      var curDir = localStorage.getItem(LS_KEY_DIR) || (DEFAULT_DIR[curKey] || 'desc');
      var newDir;
      if (key === curKey) {
        // Same button — toggle direction.
        newDir = (curDir === defaultDir) ? (defaultDir === 'desc' ? 'asc' : 'desc') : defaultDir;
      } else {
        // Different button — reset to its default direction.
        newDir = defaultDir;
      }
      setSort(key, newDir, true);
    });
  });

  // Hook up filter input.
  if (filterInput) {
    filterInput.addEventListener('input', applyFilter);
  }

  // Boot: restore persisted sort.
  var savedKey = null, savedDir = null;
  try {
    savedKey = localStorage.getItem(LS_KEY_SORT);
    savedDir = localStorage.getItem(LS_KEY_DIR);
  } catch (e) {}
  // If nothing persisted, leave rows in the server-rendered order
  // and apply visual labels matching server's default sort (the URL ?sort=...
  // tells us which is active). Otherwise, apply persisted sort.
  if (savedKey && DEFAULT_DIR.hasOwnProperty(savedKey)) {
    var dir = (savedDir === 'asc' || savedDir === 'desc') ? savedDir : DEFAULT_DIR[savedKey];
    setSort(savedKey, dir, false);
  } else {
    // Initial state — figure out which key the server rendered and label accordingly.
    var serverKey = 'count';
    var activeBtn = document.querySelector('.pp-sort-toggle-v2 .pp-sort-toggle-btn.is-active');
    if (activeBtn) serverKey = activeBtn.getAttribute('data-sort-key') || 'count';
    updateButtonUI(serverKey, DEFAULT_DIR[serverKey] || 'desc');
    renumberRanks();
  }
})();
</script>
