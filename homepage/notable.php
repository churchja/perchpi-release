<?php
/* ============================================================================
   PerchPi · Notable Sightings  (read-only)
   ============================================================================
   Surfaces labeled review decisions from quarantine_review_log that are
   NOTABLE candidates. The page SURFACES; it does NOT classify. The human ear
   is the arbiter — prob/logit/confidence only bubble candidates up.

   Notable IFF (both):
     (1) is-a-bird via genus crosswalk -> pp_is_bird(species_sci)
     (2) Geomodel_Prob < SF_THRESH  OR  prob is NULL / no_data (locally rare)

   Confidence is NOT a gate. Old-World vagrant "junk" intentionally remains in
   the candidate list and is dismissed by listening.

   Source: quarantine_review_log (durable labeled corpus; self-contained —
   both sci + common names and all axes; NO join to detections needed).

   Read-only: no pipeline change, no schema change, no mutation of the log.

   Marker: perchpi:notable-sightings-v1
   ============================================================================ */

require_once __DIR__ . '/../scripts/common.php';

/* perchpi:notable-helpers-load-v1 — ensure the shared predicate is loaded;
   this page does not otherwise pull in perchpi_helpers.php. */
$pp_helpers = __DIR__ . '/perchpi_helpers.php';
if (is_file($pp_helpers)) { require_once $pp_helpers; }

ensure_authenticated(); /* perchpi:s21a-auth-plain-call-v1 -- SHIP-WITH #7: fail closed, no function_exists() guard */

$config = function_exists('get_config') ? get_config() : [];
$home   = function_exists('get_home')   ? get_home()   : '/home/birder';

/* SF_THRESH from conf (house-style, decoupled), fall back to known runtime. */
$sf_thresh = isset($config['SF_THRESH']) && $config['SF_THRESH'] !== ''
  ? floatval($config['SF_THRESH'])
  : 0.0305;

/* ---- decision filter (default: show all) ---- */
$decision_filter = isset($_GET['decision']) ? trim($_GET['decision']) : 'all';
if (!in_array($decision_filter, ['all', 'delete', 'unhide'], true)) {
  $decision_filter = 'all';
}

/* perchpi:notable-group-v1 — view mode: 'species' (grouped, default) or 'all' */
$group_mode = isset($_GET['group']) ? trim($_GET['group']) : 'species';
if (!in_array($group_mode, ['species', 'all'], true)) { $group_mode = 'species'; }

/* ---- DB (read-only) ---- */
$db_path = $home . '/BirdNET-Pi/scripts/birds.db';
$db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
$db->busyTimeout(5000);

/* Confirm the log table exists before querying it. */
$has_log = (int)$db->querySingle(
  "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='quarantine_review_log'"
) > 0;

$rows = [];
$log_total = 0;
if ($has_log) {
  $log_total = (int)$db->querySingle("SELECT COUNT(*) FROM quarantine_review_log");

  $sql = "SELECT decision_at, decision, species_sci, species_com, confidence,
                 embedding_logit, embedding_verdict, geomodel_probability,
                 quarantine_reason, audio_file, audio_date
          FROM quarantine_review_log";
  if ($decision_filter !== 'all') {
    $sql .= " WHERE decision = :dec";
  }
  $sql .= " ORDER BY decision_at DESC LIMIT 1000";

  $stmt = $db->prepare($sql);
  if ($decision_filter !== 'all') {
    $stmt->bindValue(':dec', $decision_filter, SQLITE3_TEXT);
  }
  $res = $stmt->execute();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $r;
  }
}

/* ---- apply the 2-conjunct notable filter in PHP (uses pp_is_bird) ---- */
$notable = [];
foreach ($rows as $r) {
  /* perchpi:notable-extract-v1 — shared predicate (perchpi_helpers.php);
     this page and the Overview banner now agree by construction. */
  if (!pp_is_notable_row($r, $sf_thresh)) {
    continue;
  }

  $notable[] = $r;
}

$db->close();

/* perchpi:notable-group-v1 — aggregate $notable by species for the grouped view */
$nb_groups = [];
foreach ($notable as $r) {
  $key = (string)$r['species_sci'];
  if (!isset($nb_groups[$key])) {
    $nb_groups[$key] = [
      'sci' => (string)$r['species_sci'], 'com' => (string)$r['species_com'],
      'count' => 0, 'last' => '', 'del' => 0, 'keep' => 0,
      'best_conf' => null, 'min_prob' => null, 'has_no_data' => false,
      'best_logit' => null, 'reasons' => [],
    ];
  }
  $g =& $nb_groups[$key];
  $g['count']++;
  $da = (string)$r['decision_at'];
  if ($da > $g['last']) { $g['last'] = $da; }
  $dec = (string)$r['decision'];
  if ($dec === 'delete') { $g['del']++; }
  elseif ($dec === 'unhide') { $g['keep']++; }
  $c = $r['confidence'];
  if (is_numeric($c)) { $cf = (float)$c; if ($g['best_conf'] === null || $cf > $g['best_conf']) { $g['best_conf'] = $cf; } }
  $pp = $r['geomodel_probability'];
  if ($pp === null || $pp === '') { $g['has_no_data'] = true; }
  else { $pf = (float)$pp; if ($g['min_prob'] === null || $pf < $g['min_prob']) { $g['min_prob'] = $pf; } }
  $lg = $r['embedding_logit'];
  if ($lg !== null && $lg !== '') { $lf = (float)$lg; if ($g['best_logit'] === null || $lf > $g['best_logit']) { $g['best_logit'] = $lf; } }
  $rs = (string)$r['quarantine_reason'];
  if ($rs !== '') { $g['reasons'][$rs] = ($g['reasons'][$rs] ?? 0) + 1; }
  unset($g);
}
uasort($nb_groups, function ($a, $b) {
  if ($a['count'] !== $b['count']) { return $b['count'] - $a['count']; }
  return strcmp($b['last'], $a['last']);
});

/* ---- small display helpers ---- */
function pp_notable_h($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function pp_notable_prob($p) {
  if ($p === null || $p === '') return 'no data';
  $f = floatval($p);
  return rtrim(rtrim(sprintf('%.4f', $f), '0'), '.');
}
function pp_notable_when($s) {
  if (!$s) return '';
  $t = strtotime((string)$s);
  return $t ? date('Y-m-d H:i', $t) : pp_notable_h($s);
}
?>
<div class="pp-notable-wrap">
  <h2 class="pp-notable-title">Notable Detections</h2>
  <p class="pp-notable-blurb">
    Locally rare birds from your review history — species the geomodel did not
    expect here (probability below <?php echo pp_notable_h(rtrim(rtrim(sprintf('%.4f', $sf_thresh), '0'), '.')); ?>,
    or with no local data). This list <em>surfaces</em> candidates; your ear is
    the judge. Items you deleted may still be worth a second listen.
  </p>

  <div class="pp-notable-filters">
    <?php
      $tabs = ['all' => 'All', 'delete' => 'Deleted', 'unhide' => 'Kept (unhidden)'];
      foreach ($tabs as $key => $label):
        $active = ($decision_filter === $key) ? ' pp-notable-tab-active' : '';
    ?>
      <a class="pp-notable-tab<?php echo $active; ?>"
         href="?view=Notable+Sightings&amp;group=<?php echo pp_notable_h($group_mode); ?>&amp;decision=<?php echo pp_notable_h($key); ?>">
        <?php echo pp_notable_h($label); ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php /* perchpi:notable-group-v1 — grouped vs per-detection toggle */ ?>
  <div class="pp-notable-viewtabs">
    <?php
      $views = ['species' => 'By species', 'all' => 'All detections'];
      foreach ($views as $vkey => $vlabel):
        $vactive = ($group_mode === $vkey) ? ' pp-notable-tab-active' : '';
    ?>
      <a class="pp-notable-tab<?php echo $vactive; ?>"
         href="?view=Notable+Sightings&amp;group=<?php echo pp_notable_h($vkey); ?>&amp;decision=<?php echo pp_notable_h($decision_filter); ?>">
        <?php echo pp_notable_h($vlabel); ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$has_log): ?>
    <p class="pp-notable-empty">No review history is available yet.</p>
  <?php elseif (empty($notable)): ?>
    <p class="pp-notable-empty">
      No notable detections match this filter
      (<?php echo (int)$log_total; ?> review records scanned).
    </p>
  <?php else: ?>
    <p class="pp-notable-count">
      <?php if ($group_mode === 'species'): ?>
        <?php echo count($nb_groups); ?> species across
        <?php echo count($notable); ?> notable
        <?php echo count($notable) === 1 ? 'sighting' : 'sightings'; ?>.
      <?php else: ?>
        <?php echo count($notable); ?> notable
        <?php echo count($notable) === 1 ? 'sighting' : 'sightings'; ?>
        of <?php echo (int)$log_total; ?> review records.
      <?php endif; ?>
    </p>
    <?php
      $nb_reasons = array_values(array_unique(array_filter(array_map(
        function ($x) { return (string)$x['quarantine_reason']; }, $notable))));
      sort($nb_reasons);
    ?>
    <div class="pp-notable-toolbar">
      <label class="pp-notable-toolbar-lbl" for="pp-notable-reason-filter">Reason</label>
      <select id="pp-notable-reason-filter">
        <option value="all">All reasons</option>
        <?php foreach ($nb_reasons as $rs): ?>
          <option value="<?php echo pp_notable_h($rs); ?>"><?php echo pp_notable_h($rs); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($group_mode === 'all'): ?>
    <table class="pp-notable-table">
      <thead>
        <tr>
          <th class="pp-sort" data-key="when">When reviewed<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="species">Species<span class="pp-arrow"></span></th>
          <th class="pp-notable-listen-th">Listen</th>
          <th class="pp-sort" data-key="decision">Decision<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="conf">Confidence<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="prob">Geomodel prob<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="logit">Embed logit<span class="pp-th-sub">Higher = more bird-like</span><span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="reason">Reason<span class="pp-arrow"></span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($notable as $r):
          $dec = isset($r['decision']) ? $r['decision'] : '';
          $dec_class = ($dec === 'delete') ? 'pp-notable-del' : 'pp-notable-keep';
          $dec_label = ($dec === 'delete') ? 'Deleted'
                     : (($dec === 'unhide') ? 'Kept' : pp_notable_h($dec));
        ?>
          <?php
            $nb_sci  = (string)$r['species_sci'];
            $nb_lg   = $r['embedding_logit'];
            $nb_prob = $r['geomodel_probability'];
          ?>
          <tr class="pp-notable-row"
              data-when="<?php echo pp_notable_h((string)$r['decision_at']); ?>"
              data-species="<?php echo pp_notable_h(strtolower((string)$r['species_com'] . ' ' . $nb_sci)); ?>"
              data-decision="<?php echo pp_notable_h((string)$dec); ?>"
              data-conf="<?php echo is_numeric($r['confidence']) ? (float)$r['confidence'] : ''; ?>"
              data-prob="<?php echo ($nb_prob === null || $nb_prob === '') ? '' : (float)$nb_prob; ?>"
              data-logit="<?php echo ($nb_lg === null || $nb_lg === '') ? '' : (float)$nb_lg; ?>"
              data-reason="<?php echo pp_notable_h((string)$r['quarantine_reason']); ?>">
            <td class="pp-notable-when"><?php echo pp_notable_when($r['decision_at']); ?></td>
            <td class="pp-notable-species">
              <a class="pp-notable-spp-link" href="?view=Species%20Profile&species=<?php echo rawurlencode($nb_sci); ?>&com=<?php echo rawurlencode((string)$r['species_com']); ?>&from=Notable+Sightings">
                <span class="pp-notable-com"><?php echo pp_notable_h($r['species_com']); ?></span>
                <span class="pp-notable-sci"><?php echo pp_notable_h($nb_sci); ?></span>
              </a>
            </td>
            <td class="pp-notable-listen">
              <?php
                /* perchpi:notable-play-v1 — bare <audio>; the global pp-audio.js
                   interceptor (index.php) applies Option-B loudness on play().
                   Guarded by file_exists, mirroring play.php. */
                $nb_af = isset($r['audio_file']) ? (string)$r['audio_file'] : '';
                $nb_ad = isset($r['audio_date']) ? (string)$r['audio_date'] : '';
                $nb_rel = '';
                if ($nb_af !== '' && $nb_ad !== '' && function_exists('com_to_slug')) {
                  $nb_rel = '/By_Date/' . $nb_ad . '/' . com_to_slug((string)$r['species_com']) . '/' . $nb_af;
                }
                if ($nb_rel !== '' && is_file($home . '/BirdSongs/Extracted' . $nb_rel)):
              ?>
                <audio class="pp-notable-audio" controls preload="none" src="<?php echo pp_notable_h($nb_rel); ?>"></audio>
              <?php else: ?>
                <span class="pp-notable-nogo" title="audio no longer on disk">&mdash;</span>
              <?php endif; ?>
            </td>
            <td><span class="pp-notable-badge <?php echo $dec_class; ?>"><?php echo $dec_label; ?></span></td>
            <td><?php echo pp_notable_h($r['confidence']); ?></td>
            <td><?php echo pp_notable_h(pp_notable_prob($r['geomodel_probability'])); ?></td>
            <td><?php echo ($nb_lg === null || $nb_lg === '') ? '' : pp_notable_h(number_format((float)$nb_lg, 2)); ?></td>
            <td class="pp-notable-reason"><?php echo pp_notable_h($r['quarantine_reason']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <table class="pp-notable-table pp-notable-grouped">
      <thead>
        <tr>
          <th class="pp-sort" data-key="when">Last reviewed<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="species">Species<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="count">Count<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="decision">Decisions<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="conf">Best conf<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="prob">Geomodel prob<span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="logit">Best logit<span class="pp-th-sub">Higher = more bird-like</span><span class="pp-arrow"></span></th>
          <th class="pp-sort" data-key="reason">Reason<span class="pp-arrow"></span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($nb_groups as $g):
          $g_sci = (string)$g['sci'];
          $g_com = (string)$g['com'];
          $g_reasons = $g['reasons'];
          arsort($g_reasons);
          $g_reason = $g_reasons ? (string)array_key_first($g_reasons) : '';
          $g_prob_disp = ($g['min_prob'] !== null) ? pp_notable_prob($g['min_prob'])
                       : ($g['has_no_data'] ? 'no data' : '');
          $g_conf_disp = ($g['best_conf'] !== null) ? rtrim(rtrim(sprintf('%.4f', $g['best_conf']), '0'), '.') : '';
          $g_logit_disp = ($g['best_logit'] !== null) ? number_format((float)$g['best_logit'], 2) : '';
          $g_dom_dec = ($g['del'] >= $g['keep']) ? 'delete' : 'unhide';
        ?>
          <tr class="pp-notable-row"
              data-when="<?php echo pp_notable_h((string)$g['last']); ?>"
              data-species="<?php echo pp_notable_h(strtolower($g_com . ' ' . $g_sci)); ?>"
              data-count="<?php echo (int)$g['count']; ?>"
              data-decision="<?php echo pp_notable_h($g_dom_dec); ?>"
              data-conf="<?php echo ($g['best_conf'] !== null) ? (float)$g['best_conf'] : ''; ?>"
              data-prob="<?php echo ($g['min_prob'] !== null) ? (float)$g['min_prob'] : ''; ?>"
              data-logit="<?php echo ($g['best_logit'] !== null) ? (float)$g['best_logit'] : ''; ?>"
              data-reason="<?php echo pp_notable_h($g_reason); ?>">
            <td class="pp-notable-when"><?php echo pp_notable_when($g['last']); ?></td>
            <td class="pp-notable-species">
              <a class="pp-notable-spp-link" href="?view=Species%20Profile&species=<?php echo rawurlencode($g_sci); ?>&com=<?php echo rawurlencode($g_com); ?>&from=Notable+Sightings">
                <span class="pp-notable-com"><?php echo pp_notable_h($g_com); ?></span>
                <span class="pp-notable-sci"><?php echo pp_notable_h($g_sci); ?></span>
              </a>
            </td>
            <td><span class="pp-notable-countbadge">&times;<?php echo (int)$g['count']; ?></span></td>
            <td class="pp-notable-decisions">
              <?php if ($g['del'] > 0): ?><span class="pp-notable-badge pp-notable-del"><?php echo (int)$g['del']; ?> deleted</span><?php endif; ?>
              <?php if ($g['keep'] > 0): ?><span class="pp-notable-badge pp-notable-keep"><?php echo (int)$g['keep']; ?> kept</span><?php endif; ?>
            </td>
            <td><?php echo pp_notable_h($g_conf_disp); ?></td>
            <td><?php echo pp_notable_h($g_prob_disp); ?></td>
            <td><?php echo $g_logit_disp === '' ? '' : pp_notable_h($g_logit_disp); ?></td>
            <td class="pp-notable-reason"><?php echo pp_notable_h($g_reason); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<style>
/* perchpi:notable-sightings-v1 styles */
.pp-notable-wrap { max-width: 1100px; margin: 0 auto; padding: 1rem; }
.pp-notable-title { margin: 0 0 .25rem; }
.pp-notable-blurb { color: #555; font-size: .9rem; margin: 0 0 1rem; line-height: 1.4; }
.pp-notable-filters { margin-bottom: 1rem; }
.pp-notable-tab {
  display: inline-block; padding: .3rem .8rem; margin-right: .4rem;
  border: 1px solid #ccc; border-radius: 4px; text-decoration: none;
  color: #333; font-size: .85rem;
}
.pp-notable-tab-active { background: #2c6e49; color: #fff; border-color: #2c6e49; }
.pp-notable-count { font-size: .85rem; color: #555; margin: 0 0 .5rem; }
.pp-notable-empty { color: #777; font-style: italic; padding: 1rem 0; }
.pp-notable-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.pp-notable-table th, .pp-notable-table td {
  text-align: left; padding: .4rem .6rem; border-bottom: 1px solid #eee;
}
.pp-notable-table th { border-bottom: 2px solid #ddd; white-space: nowrap; }
.pp-notable-row:hover { background: #fafafa; }
.pp-notable-com { display: block; font-weight: 600; }
.pp-notable-sci { display: block; font-style: italic; color: #777; font-size: .8rem; }
.pp-notable-when, .pp-notable-reason { white-space: nowrap; color: #555; }
.pp-notable-badge {
  display: inline-block; padding: .1rem .5rem; border-radius: 3px;
  font-size: .78rem; font-weight: 600;
}
.pp-notable-del  { background: #f8d7da; color: #842029; }
.pp-notable-keep { background: #d1e7dd; color: #0f5132; }
</style>

<style>
/* perchpi:notable-table-ux-v1 */
.pp-notable-table th.pp-sort { cursor: pointer; user-select: none; white-space: nowrap; }
.pp-notable-table th.pp-sort:hover { color: #2c6e49; }
.pp-th-sub { display: block; font-weight: 400; font-size: .7rem; color: #888; }
.pp-arrow { display: inline-block; width: .8em; margin-left: .15em; color: #2c6e49; }
.pp-notable-toolbar { display: flex; align-items: center; gap: .4rem; margin: 0 0 .6rem; }
.pp-notable-toolbar-lbl { font-size: .8rem; color: #555; }
.pp-notable-toolbar select { font-size: .85rem; padding: .2rem .4rem; }
.pp-notable-spp-link { display: block; color: inherit; text-decoration: none; }
.pp-notable-spp-link:hover .pp-notable-com { text-decoration: underline; }
</style>
<script>
/* perchpi:notable-table-ux-v1 - client-side sort + reason filter */
(function () {
  var table = document.querySelector('.pp-notable-table');
  if (!table || !table.tBodies.length) return;
  var tbody = table.tBodies[0];
  var NUMERIC = { conf: 1, prob: 1, logit: 1, count: 1 };
  var state = { key: null, dir: 1 };

  function rows() {
    return Array.prototype.slice.call(tbody.querySelectorAll('tr.pp-notable-row'));
  }

  function sortBy(key) {
    state.dir = (state.key === key) ? -state.dir : 1;
    state.key = key;
    var rs = rows();
    rs.sort(function (a, b) {
      var av = a.dataset[key] || '', bv = b.dataset[key] || '';
      if (NUMERIC[key]) {
        var ae = (av === ''), be = (bv === '');
        if (ae && be) return 0;
        if (ae) return 1;            /* blanks / "no data" sink to the bottom */
        if (be) return -1;
        return (parseFloat(av) - parseFloat(bv)) * state.dir;
      }
      return av.localeCompare(bv) * state.dir;
    });
    rs.forEach(function (r) { tbody.appendChild(r); });
    var arrows = table.querySelectorAll('th.pp-sort .pp-arrow');
    Array.prototype.forEach.call(arrows, function (s) { s.textContent = ''; });
    var th = table.querySelector('th.pp-sort[data-key="' + key + '"] .pp-arrow');
    if (th) th.textContent = state.dir > 0 ? '▲' : '▼';
  }

  Array.prototype.forEach.call(table.querySelectorAll('th.pp-sort'), function (th) {
    th.addEventListener('click', function () { sortBy(th.dataset.key); });
  });

  var sel = document.getElementById('pp-notable-reason-filter');
  if (sel) {
    sel.addEventListener('change', function () {
      var v = sel.value;
      rows().forEach(function (r) {
        r.style.display = (v === 'all' || r.dataset.reason === v) ? '' : 'none';
      });
    });
  }
})();
</script>

<style>
/* perchpi:notable-group-v1 */
.pp-notable-viewtabs { margin: 0 0 1rem; }
.pp-notable-countbadge { display: inline-block; background: #23402f; color: #7fd1a3; padding: .05rem .55rem; border-radius: 10px; font-weight: 600; font-size: .8rem; }
.pp-notable-decisions .pp-notable-badge { margin-right: .25rem; }
.pp-notable-grouped .pp-notable-when { white-space: nowrap; color: #555; }
</style>

<style>
/* perchpi:notable-play-v1 */
.pp-notable-listen-th { white-space: nowrap; }
.pp-notable-audio { height: 32px; max-width: 230px; vertical-align: middle; }
.pp-notable-nogo { color: #999; }
</style>
