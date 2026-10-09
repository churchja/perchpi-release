<?php
/* perchpi:weekly-report-restyle */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'scripts/common.php';

/* ============================================================================
   weekly_report.php — perchpi-styled weekly report
   Renders Total Detections, Unique Species (with WoW deltas), a
   "Most Detected" panel, and a "New Species" panel based on a
   qualifying-new algorithm (3+ detections within 2 weeks of first sighting).
   The ?ascii= branch produces a simple text output for email digests
   (legacy behavior, minimally fixed).
   ============================================================================ */

/* ---------- date range setup ------------------------------------------------ */
$startdate = strtotime('last sunday') - (7 * 86400);
$enddate   = strtotime('last sunday') - (1 * 86400);
$start_ymd = date('Y-m-d', $startdate);
$end_ymd   = date('Y-m-d', $enddate);
$prior_start_ymd = date('Y-m-d', $startdate - (7 * 86400));
$prior_end_ymd   = date('Y-m-d', $enddate - (7 * 86400));

/* ---------- percentage helper ----------------------------------------------- *
 * Returns:
 *   - integer percentage (rounded) when prior > 0
 *   - PHP null sentinel when prior == 0 and current > 0   (caller renders "NEW" / "first week")
 *   - 0 when both are 0
 * Replaces the old INF behavior.
 */
function safe_percentage($count, $prior_count) {
  if ($prior_count > 0) {
    return (int)round((($count - $prior_count) / $prior_count) * 100);
  }
  if ($count > 0) {
    return null;  // NEW / first-week sentinel
  }
  return 0;
}

/* ---------- DB setup -------------------------------------------------------- */
$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

/* ---------- per-species this-week + WoW + life-list summary ---------------- */
$statement1 = $db->prepare(
  'SELECT Sci_Name, Com_Name, COUNT(*) AS week_count
   FROM detections
   WHERE Date BETWEEN :s AND :e
     AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
   GROUP BY Sci_Name
   ORDER BY week_count DESC'
);
$statement1->bindValue(':s', $start_ymd);
$statement1->bindValue(':e', $end_ymd);
ensure_db_ok($statement1);
$result1 = $statement1->execute();

$detections = [];
while ($row = $result1->fetchArray(SQLITE3_ASSOC)) {
  $com_name = $row['Com_Name'];
  $sci_name = $row['Sci_Name'];
  $scount   = $row['week_count'];

  // Prior-week count (for WoW % per species)
  $statement2 = $db->prepare(
    'SELECT COUNT(*) AS c FROM detections
     WHERE Sci_Name = :n AND Date BETWEEN :ps AND :pe
       AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */'
  );
  $statement2->bindValue(':n',  $sci_name);
  $statement2->bindValue(':ps', $prior_start_ymd);
  $statement2->bindValue(':pe', $prior_end_ymd);
  ensure_db_ok($statement2);
  $priorweekcount = $statement2->execute()->fetchArray(SQLITE3_ASSOC)['c'];

  $percentagediff = safe_percentage($scount, $priorweekcount);

  $detections[$com_name] = [
    'count'           => $scount,
    'sci_name'        => $sci_name,
    'percentagediff'  => $percentagediff,
    'priorweekcount'  => $priorweekcount,
  ];
}

/* ---------- qualifying-new species algorithm -------------------------------- *
 * A species qualifies as "new" if it has 3+ detections within 14 days of its
 * very first detection (filters single-noise false positives).
 * We compute the qualifying set across the whole life of the installation,
 * then:
 *   - Show top 10 most recent qualifying-new species in the "New Species" panel
 *   - Flag any of this week's Top 10 (Most Detected) with "NEW" badge IF
 *     their first-detection falls within this report's week AND they qualify
 */
$qualifying_new = [];  // sci_name => ['com_name'=>..., 'first_seen'=>'YYYY-MM-DD HH:MM:SS', 'count'=>N]

$first_seen_q = $db->prepare(
  "SELECT Sci_Name, Com_Name, MIN(Date || ' ' || Time) AS first_seen
   FROM detections
   WHERE detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
   GROUP BY Sci_Name"
);
ensure_db_ok($first_seen_q);
$first_seen_res = $first_seen_q->execute();

while ($r = $first_seen_res->fetchArray(SQLITE3_ASSOC)) {
  $sn       = $r['Sci_Name'];
  $cn       = $r['Com_Name'];
  $first    = $r['first_seen']; // "YYYY-MM-DD HH:MM:SS"
  $first_d  = substr($first, 0, 10);

  // Window: [first_d, first_d + 14 days]
  $window_end = date('Y-m-d', strtotime($first_d) + (14 * 86400));

  $cnt_q = $db->prepare(
    'SELECT COUNT(*) AS c FROM detections
     WHERE Sci_Name = :n AND Date BETWEEN :a AND :b
       AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */'
  );
  $cnt_q->bindValue(':n', $sn);
  $cnt_q->bindValue(':a', $first_d);
  $cnt_q->bindValue(':b', $window_end);
  ensure_db_ok($cnt_q);
  $window_count = $cnt_q->execute()->fetchArray(SQLITE3_ASSOC)['c'];

  if ($window_count >= 3) {
    $qualifying_new[$sn] = [
      'com_name'   => $cn,
      'first_seen' => $first,
      'window_count' => $window_count,
    ];
  }
}

// Sort qualifying-new by first_seen DESC, take top 10
$qualifying_new_sorted = $qualifying_new;
uasort($qualifying_new_sorted, function($a, $b) {
  return strcmp($b['first_seen'], $a['first_seen']);
});
$recent_new_species = array_slice($qualifying_new_sorted, 0, 10, true);

// Set of sci_names whose first detection was THIS week (for NEW badge)
$new_this_week = [];
foreach ($qualifying_new as $sn => $info) {
  $first_d = substr($info['first_seen'], 0, 10);
  if ($first_d >= $start_ymd && $first_d <= $end_ymd) {
    $new_this_week[$sn] = true;
  }
}

/* ---------- totals + WoW deltas --------------------------------------------- */
$tot_q = $db->prepare(
  'SELECT COUNT(*) AS c FROM detections WHERE Date BETWEEN :s AND :e AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */'
);
$tot_q->bindValue(':s', $start_ymd); $tot_q->bindValue(':e', $end_ymd);
ensure_db_ok($tot_q);
$totalcount = $tot_q->execute()->fetchArray(SQLITE3_ASSOC)['c'];

$ptot_q = $db->prepare(
  'SELECT COUNT(*) AS c FROM detections WHERE Date BETWEEN :s AND :e AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */'
);
$ptot_q->bindValue(':s', $prior_start_ymd); $ptot_q->bindValue(':e', $prior_end_ymd);
ensure_db_ok($ptot_q);
$priortotalcount = $ptot_q->execute()->fetchArray(SQLITE3_ASSOC)['c'];

$spec_q = $db->prepare(
  'SELECT COUNT(DISTINCT Sci_Name) AS c FROM detections WHERE Date BETWEEN :s AND :e AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */'
);
$spec_q->bindValue(':s', $start_ymd); $spec_q->bindValue(':e', $end_ymd);
ensure_db_ok($spec_q);
$totalspeciestally = $spec_q->execute()->fetchArray(SQLITE3_ASSOC)['c'];

$pspec_q = $db->prepare(
  'SELECT COUNT(DISTINCT Sci_Name) AS c FROM detections WHERE Date BETWEEN :s AND :e AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */'
);
$pspec_q->bindValue(':s', $prior_start_ymd); $pspec_q->bindValue(':e', $prior_end_ymd);
ensure_db_ok($pspec_q);
$priortotalspeciestally = $pspec_q->execute()->fetchArray(SQLITE3_ASSOC)['c'];

$percentagedifftotal           = safe_percentage($totalcount, $priortotalcount);
$percentagedifftotalspecies    = safe_percentage($totalspeciestally, $priortotalspeciestally);

/* ============================================================================
   ASCII/email digest branch (legacy, minimally bug-fixed)
   ============================================================================ */
if (isset($_GET['ascii'])) {
  function ascii_pct_span($pct) {
    if ($pct === null) return "<span style='color:green;font-size:small'>(NEW)</span>";
    if ($pct > 0)      return "<span style='color:green;font-size:small'>+{$pct}%</span>";
    if ($pct < 0)      return "<span style='color:red;font-size:small'>".$pct."%</span>";
    return "<span style='color:gray;font-size:small'>0%</span>";
  }

  echo "# BirdNET-Pi: Week ".date('W', $enddate)." Report\n";
  echo "Total Detections: <b>".$totalcount."</b> (".ascii_pct_span($percentagedifftotal).")<br>";
  echo "Unique Species Detected: <b>".$totalspeciestally."</b> (".ascii_pct_span($percentagedifftotalspecies).")<br><br>";
  echo "= <b>Top 10 Species</b> =<br>";

  $i = 0;
  foreach ($detections as $com_name => $stats) {
    $i++;
    if ($i > 10) break;
    echo $com_name." - ".$stats['count']." (".ascii_pct_span($stats['percentagediff']).")<br>";
  }

  echo "<br>= <b>Species Detected for the First Time</b> =<br>";
  $newcount = 0;
  foreach ($detections as $com_name => $stats) {
    // ASCII branch keeps the legacy "first-time-ever" heuristic for backward
    // compat; we only fix the $scount bug.
    $sn = $stats['sci_name'];
    $check = $db->prepare(
      'SELECT COUNT(*) AS c FROM detections
       WHERE Sci_Name = :n AND Date NOT BETWEEN :s AND :e
         AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */'
    );
    $check->bindValue(':n', $sn);
    $check->bindValue(':s', $start_ymd);
    $check->bindValue(':e', $end_ymd);
    ensure_db_ok($check);
    $other = $check->execute()->fetchArray(SQLITE3_ASSOC)['c'];

    if ($other === 0) {
      $newcount++;
      echo $com_name." - ".$stats['count']."<br>";  // bug fix: was $scount
    }
  }
  if ($newcount === 0) echo "No new species were seen this week.";

  $prevweek = (int)date('W', $enddate) - 1;
  if ($prevweek < 1) $prevweek = 52;
  echo "<hr><span style='font-size:small'>* data from ".$start_ymd." — ".$end_ymd.".</span><br>";
  echo "<span style='font-size:small'>* percentages are calculated relative to week ".$prevweek.".</span>";
  die();
}

/* ============================================================================
   HTML rendering (perchpi shell)
   ============================================================================ */

$pp_standalone = (get_included_files()[0] === __FILE__);

if ($pp_standalone) {
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>Weekly Report</title></head><body>';
}

/* Standard perchpi back-nav header */
$wknum = date('W', $enddate);
$dr_short = date('M j', $startdate) . " – " . date('M j, Y', $enddate);
if (!$pp_standalone && function_exists('pp_render_back_nav')) {
  pp_render_back_nav('?view=Overview', 'Overview', "Weekly Report", "Week {$wknum} · {$dr_short}");
}

/* Render the percentage span helper for HTML stat-cards (richer than ASCII) */
function pp_pct_chip($pct, $context = 'wow') {
  // $context: 'wow' = total/species cards (says "first week" on null)
  //           'species' = species rows (says "NEW" on null)
  if ($pct === null) {
    $label = ($context === 'species') ? 'NEW' : 'first week';
    return "<span class=\"pp-chip pp-chip-new\">{$label}</span>";
  }
  if ($pct > 0)  return "<span class=\"pp-chip pp-chip-up\">+{$pct}%</span>";
  if ($pct < 0)  return "<span class=\"pp-chip pp-chip-down\">{$pct}%</span>";
  return "<span class=\"pp-chip pp-chip-flat\">0%</span>";
}
?>
<style>
.pp-wr-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-3, 12px);
  margin-bottom: var(--space-4, 16px);
}
@media (max-width: 600px) {
  .pp-wr-stats { grid-template-columns: 1fr; }
}
.pp-stat-card {
  background: var(--pp-surface, #1a1a1a);
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-lg, 12px);
  padding: var(--space-4, 16px) var(--space-5, 20px);
}
.pp-stat-card-label {
  font-size: 0.85em;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--pp-text-muted, #888);
  margin-bottom: var(--space-2, 8px);
}
.pp-stat-card-value {
  font-size: 2em;
  font-weight: 700;
  color: var(--pp-text, #e6e6e6);
  display: flex;
  align-items: baseline;
  gap: var(--space-2, 8px);
}
.pp-chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 0.45em;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  vertical-align: middle;
}
.pp-chip-up    { background: rgba(74, 222, 128, 0.15); color: #4ade80; }
.pp-chip-down  { background: rgba(248, 113, 113, 0.15); color: #f87171; }
.pp-chip-flat  { background: rgba(160, 160, 160, 0.12); color: #aaa; }
.pp-chip-new   { background: rgba(74, 222, 128, 0.20); color: #4ade80; }
.pp-wr-panel {
  background: var(--pp-surface, #1a1a1a);
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-lg, 12px);
  padding: var(--space-4, 16px);
  margin-bottom: var(--space-4, 16px);
}
.pp-wr-panel-title {
  font-size: 0.85em;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--pp-text-muted, #888);
  margin-bottom: var(--space-3, 12px);
}
.pp-wr-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3, 12px);
  padding: var(--space-3, 12px) 0;
  border-bottom: 1px solid var(--pp-border, #2a2a2a);
}
.pp-wr-row:last-child { border-bottom: 0; }
.pp-wr-row-name {
  color: var(--pp-text, #e6e6e6);
  font-weight: 600;
}
.pp-wr-row-meta {
  color: var(--pp-text-muted, #888);
  font-size: 0.9em;
  font-variant-numeric: tabular-nums;
  display: flex;
  align-items: baseline;
  gap: var(--space-2, 8px);
}
.pp-wr-row-meta .pp-chip { font-size: 0.75em; }
.pp-wr-empty {
  color: var(--pp-text-muted, #888);
  text-align: center;
  padding: var(--space-4, 16px);
  font-style: italic;
}
.pp-wr-footer {
  text-align: center;
  color: var(--pp-text-muted, #888);
  font-size: 0.85em;
  margin-top: var(--space-4, 16px);
  padding-top: var(--space-3, 12px);
  border-top: 1px solid var(--pp-border, #2a2a2a);
}
</style>
<section class="pp-content">

<!-- Stat cards: totals -->
<div class="pp-wr-stats">
  <div class="pp-stat-card">
    <div class="pp-stat-card-label">Total Detections</div>
    <div class="pp-stat-card-value">
      <?= number_format($totalcount) ?>
      <?= pp_pct_chip($percentagedifftotal, 'wow') ?>
    </div>
  </div>
  <div class="pp-stat-card">
    <div class="pp-stat-card-label">Unique Species</div>
    <div class="pp-stat-card-value">
      <?= number_format($totalspeciestally) ?>
      <?= pp_pct_chip($percentagedifftotalspecies, 'wow') ?>
    </div>
  </div>
</div>

<!-- Most Detected -->
<div class="pp-wr-panel">
  <div class="pp-wr-panel-title">Most Detected</div>
  <?php
  $i = 0;
  foreach ($detections as $com_name => $stats) {
    $i++;
    if ($i > 10) break;
    $sn = $stats['sci_name'];
    $is_new_this_week = isset($new_this_week[$sn]);
    $pct_for_display = $is_new_this_week ? null : $stats['percentagediff'];
    ?>
    <div class="pp-wr-row">
      <div class="pp-wr-row-name"><?= htmlspecialchars($com_name) ?></div>
      <div class="pp-wr-row-meta">
        <span><?= number_format($stats['count']) ?></span>
        <?= pp_pct_chip($pct_for_display, 'species') ?>
      </div>
    </div>
    <?php
  }
  if ($i === 0) {
    echo '<div class="pp-wr-empty">No detections this week.</div>';
  }
  ?>
</div>

<!-- New Species -->
<div class="pp-wr-panel">
  <div class="pp-wr-panel-title">New Species</div>
  <?php
  if (count($recent_new_species) === 0) {
    echo '<div class="pp-wr-empty">No qualifying new species yet.</div>';
  } else {
    foreach ($recent_new_species as $sn => $info) {
      $first_ts = strtotime($info['first_seen']);
      $first_label = date('M j, Y · g:i A', $first_ts);
      ?>
      <div class="pp-wr-row">
        <div class="pp-wr-row-name"><?= htmlspecialchars($info['com_name']) ?></div>
        <div class="pp-wr-row-meta">
          <span>first heard <?= $first_label ?></span>
        </div>
      </div>
      <?php
    }
  }
  ?>
</div>

<div class="pp-wr-footer">
  Percentages compared to Week <?= ((int)$wknum > 1) ? ($wknum - 1) : 52 ?>
  · New Species qualified by 3+ detections within 14 days of first sighting
</div>

</section>
<?php
if ($pp_standalone) echo '</body></html>';
?>
