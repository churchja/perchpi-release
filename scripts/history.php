<?php

/* Prevent XSS input */
$_GET   = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
$_POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);

error_reporting(E_ALL);
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
require_once 'scripts/common.php';
$config = get_config();

if(isset($_GET['date'])){
$theDate = $_GET['date'];
} else {
$theDate = date('Y-m-d');
}
$chart = "Combo-$theDate.png";

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

$statement1 = $db->prepare("SELECT COUNT(*) FROM detections WHERE Date == \"$theDate\" AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */");
ensure_db_ok($statement1);
$result1 = $statement1->execute();
$totalcount = $result1->fetchArray(SQLITE3_ASSOC);

// perchpi:daily-charts-header-strip-v1 — yesterday count for delta calculation
$pp_today_count = intval($totalcount['COUNT(*)']);
$pp_prev_date = date('Y-m-d', strtotime($theDate . ' -1 day'));
$pp_next_date = date('Y-m-d', strtotime($theDate . ' +1 day'));
$pp_today_iso = date('Y-m-d');
$pp_is_today = ($theDate === $pp_today_iso);
$pp_is_future = ($theDate > $pp_today_iso);

$pp_stmt_prev = $db->prepare("SELECT COUNT(*) FROM detections WHERE Date == \"$pp_prev_date\" AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */");
ensure_db_ok($pp_stmt_prev);
$pp_prev_count = intval($pp_stmt_prev->execute()->fetchArray(SQLITE3_ASSOC)['COUNT(*)']);

// Delta: integer percent change. null if no comparison possible.
$pp_delta_pct = null;
$pp_delta_abs = null;
if ($pp_prev_count > 0) {
  $pp_delta_abs = $pp_today_count - $pp_prev_count;
  $pp_delta_pct = (int)round((($pp_today_count - $pp_prev_count) / $pp_prev_count) * 100);
} elseif ($pp_today_count > 0 && $pp_prev_count === 0) {
  // From zero to nonzero — show absolute, no percent
  $pp_delta_abs = $pp_today_count;
}

// Sunrise/sunset via PHP — no API call, no network dependency
$pp_lat = isset($config['LATITUDE'])  ? floatval($config['LATITUDE'])  : 0.0;
$pp_lon = isset($config['LONGITUDE']) ? floatval($config['LONGITUDE']) : 0.0;
$pp_sun = @date_sun_info(strtotime($theDate . ' 12:00:00'), $pp_lat, $pp_lon);
$pp_sunrise = ($pp_sun && isset($pp_sun['sunrise']) && is_int($pp_sun['sunrise'])) ? date('g:i a', $pp_sun['sunrise']) : null;
$pp_sunset  = ($pp_sun && isset($pp_sun['sunset'])  && is_int($pp_sun['sunset']))  ? date('g:i a', $pp_sun['sunset'])  : null;

// Human-friendly date label for the strip
$pp_date_label = date('D M j', strtotime($theDate));
$pp_prev_label_short = date('D', strtotime($pp_prev_date));


if(isset($_GET['blocation']) ) {

	header("Content-type: text/csv");
	header("Content-Disposition: attachment; filename=result_file.csv");
	header("Pragma: no-cache");
	header("Expires: 0");

	$list = array ();

	//$hrsinday = intval(($sunset-$sunrise)/60/60);
	$hrsinday = 24;
	for($i=0;$i<$hrsinday;$i++) {
		$starttime = strtotime("12 AM") + (3600*$i);

		$statement1 = $db->prepare("SELECT DISTINCT(Com_Name), COUNT(*) FROM detections WHERE Date == \"$theDate\" AND Time > '".date("H:i", $starttime)."' AND Time < '".date("H:i",$starttime + 3600)."' AND Confidence > 0.75 AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ GROUP By Com_Name ORDER BY COUNT(*) DESC");
		ensure_db_ok($statement1);
		$result1 = $statement1->execute();

		$detections = [];
		while($detection=$result1->fetchArray(SQLITE3_ASSOC))
		{
			$detections[$detection["Com_Name"]] = $detection["COUNT(*)"];
		}
		foreach($detections as $com_name=>$scount)
		{
			array_push($list, array($com_name,'','','1','',$_GET['blocation'],$config["LATITUDE"],$config["LONGITUDE"],date("m/d/Y", strtotime($theDate)), date("H:i", $starttime), $_GET['state'], $_GET['country'], $_GET['protocol'], $_GET['num_observers'], '60', 'Y', $_GET['dist_traveled'],'',$_GET['notes'] ) );
		}
	}

	$output = fopen("php://output", "w");
    foreach ($list as $row) {
        fputcsv($output, $row);
    }
    fclose($output);

	die();
}
if (get_included_files()[0] === __FILE__) {
	echo '<!DOCTYPE html>
<html lang="en">
<head>

<style>
#attribution-dialog p {
	display:inline;
}
#attribution-dialog {
	text-align: left;
}
</style>
</head>
<body>';
	}
?>
<script src="static/dialog-polyfill.js"></script>
<div class="history centered">

<dialog id="attribution-dialog">
  <p style="display:none" id="filename"></p>
  <h1 id="modalHeading">Enter Checklist Information</h1>
  <p id="modalText">Location Name:</p> <input placeholder="My house" id="blocation"><br><br>
  <p id="modalText">State (2 letter code):</p> <input maxlength="3" id="state"><br><br>
  <p id="modalText">Country (2 letter code):</p> <input maxlength="2" id="country"><br><br>
  <p id="modalText">Protocol:</p> <select id="protocol">
  <option value="casual">casual</option>
  <option value="stationary">stationary</option>
  <option value="traveling">traveling</option>
  <option value="area">area</option>
</select>
<br><br>
  <p id="modalText">Number of observers:</p> <input type="number" id="num_observers"><br><br>
  <p id="modalText">Distance traveled (miles):</p> <input type="number" id="dist_traveled"><br><br>
  <p id="modalText">Notes:</p> <input id="notes"><br><br>
  <div style="display: flex; gap: 8px; justify-content: flex-end;">
    <button type="button" onclick="closeDialog()">Cancel</button>
    <button type="button" onclick="submitID()">Submit</button>
  </div>
</dialog>
<script>
var dialog = document.querySelector('dialog');
dialogPolyfill.registerDialog(dialog);

function showDialog() {
  document.getElementById('attribution-dialog').showModal();
}

function closeDialog() {
  document.getElementById('attribution-dialog').close();
}

function submitID() {
  blocation = document.getElementById("blocation").value;
  state = document.getElementById("state").value;
  country = document.getElementById("country").value;
  protocol = document.getElementById("protocol").value;
  num_observers = document.getElementById("num_observers").value;
  dist_traveled = document.getElementById("dist_traveled").value;
  notes = document.getElementById("notes").value;

  window.open("history.php?blocation="+blocation+"&state="+state+"&country="+country+"&protocol="+protocol+"&num_observers="+num_observers+"&dist_traveled="+dist_traveled+"&notes="+notes+"&date="+"<?php echo $theDate; ?>");

  document.getElementById('attribution-dialog').innerHTML = "<h3>Success!</h3><p>Your checklist will start downloading momentarily.<br><br>Refer to <a target='_blank' href='https://ebird.org/content/eBirdCommon/docs/ebird_import_data_process.pdf'>this guide</a> for information on how to import it in eBird. The checklist file format is: 'eBird Record Format (Extended)'.<br><br><span style='font-size:small'>Note: Only detections with a confidence > 0.75 were included, and entries have been limited to 1 detection per hour per species, to comply with eBird's data quality guidelines.<br>It's always good practice to manually verify your checklist before submitting, especially for nocturnal hours.</span></p><br><br><button onclick=\"closeDialog()\">Close</button>";

}

</script>

<?php
/* perchpi:daily-charts-restyle */
$pp_standalone = (get_included_files()[0] === __FILE__);
$pp_subtitle = $theDate;
if (!$pp_standalone && function_exists('pp_format_date_friendly')) {
  $pp_subtitle = pp_format_date_friendly($theDate);
}
if (!$pp_standalone && function_exists('pp_render_back_nav')) {
  pp_render_back_nav('?view=Overview', 'Overview', 'Daily Charts', $pp_subtitle);
}
?>

<style>
.pp-charts-controls {
  /* perchpi:daily-charts-grid */
  display: grid;
  grid-template-columns: auto auto auto 1fr;
  gap: var(--space-2, 8px);
  align-items: center;
  margin-bottom: var(--space-4, 16px);
}
.pp-charts-controls form {
  display: contents;
  margin: 0;
}
.pp-charts-controls input[type="date"] {
  /* perchpi:daily-charts-date-width */
  background: var(--pp-surface, #1a1a1a);
  color: var(--pp-text, #e6e6e6);
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-md, 8px);
  padding: 8px 12px;
  font-size: 0.95em;
  min-height: 40px;
  max-width: 180px;
  flex: 0 0 auto;
}
.pp-charts-controls button {
  background: var(--pp-surface-elevated, #222);
  color: var(--pp-text, #e6e6e6);
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-md, 8px);
  padding: 8px 16px;
  font-size: 0.95em;
  min-height: 40px;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}
.pp-charts-controls button:hover {
  background: var(--pp-surface-hover, #2a2a2a);
  border-color: var(--pp-accent, #4ade80);
}
.pp-charts-controls button.primary {
  background: var(--pp-accent, #4ade80);
  color: #0a0a0a;
  border-color: var(--pp-accent, #4ade80);
}
.pp-charts-controls button.primary:hover {
  background: #5cf28d;
}
.pp-stat-card {
  background: var(--pp-surface, #1a1a1a);
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-lg, 12px);
  padding: var(--space-4, 16px) var(--space-5, 20px);
  margin-bottom: var(--space-4, 16px);
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3, 12px);
}
.pp-stat-card-label {
  font-size: 0.85em;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--pp-text-muted, #888);
}
.pp-stat-card-value {
  font-size: 2em;
  font-weight: 700;
  color: var(--pp-text, #e6e6e6);
}
.pp-chart-frame {
  background: #f5f5f5;
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-lg, 12px);
  padding: var(--space-3, 12px);
  text-align: center;
  overflow: hidden;
}
.pp-chart-frame img {
  max-width: 100%;
  height: auto;
  display: block;
  margin: 0 auto;
}
.pp-chart-empty {
  background: var(--pp-surface, #1a1a1a);
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-lg, 12px);
  padding: var(--space-5, 20px);
  text-align: center;
  color: var(--pp-text-muted, #888);
}

/* perchpi:daily-charts-header-strip-v1 — unified header strip */
.pp-charts-header {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: var(--space-5, 20px);
  align-items: center;
  background: var(--pp-surface, #1a1a1a);
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-lg, 12px);
  padding: var(--space-4, 16px) var(--space-5, 20px);
  margin-bottom: var(--space-3, 12px);
}
.pp-charts-header__nav {
  display: flex;
  align-items: center;
  gap: var(--space-2, 8px);
  flex-wrap: wrap;
}
.pp-charts-header__nav .pp-iconbtn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 40px;
  min-height: 40px;
  padding: 8px 12px;
  background: var(--pp-surface-elevated, #222);
  color: var(--pp-text, #e6e6e6);
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-md, 8px);
  font-size: 0.95em;
  text-decoration: none;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}
.pp-charts-header__nav .pp-iconbtn:hover {
  background: var(--pp-surface-hover, #2a2a2a);
  border-color: var(--pp-accent, #4ade80);
}
.pp-charts-header__nav .pp-iconbtn.is-disabled {
  opacity: 0.35;
  pointer-events: none;
  cursor: default;
}
.pp-charts-header__date-form {
  display: inline;
  margin: 0;
}
.pp-charts-header__date-form input[type="date"] {
  background: var(--pp-surface, #1a1a1a);
  color: var(--pp-text, #e6e6e6);
  border: 1px solid var(--pp-border, #2a2a2a);
  border-radius: var(--radius-md, 8px);
  padding: 8px 12px;
  font-size: 0.95em;
  min-height: 40px;
  max-width: 180px;
}
.pp-charts-header__stat {
  display: flex;
  align-items: baseline;
  gap: var(--space-2, 8px);
  flex-wrap: wrap;
  justify-content: center;
}
.pp-charts-header__count {
  font-size: 2em;
  font-weight: 700;
  color: var(--pp-text, #e6e6e6);
  line-height: 1;
}
.pp-charts-header__count-label {
  font-size: 0.85em;
  color: var(--pp-text-muted, #888);
  text-transform: lowercase;
}
.pp-charts-header__delta {
  font-size: 0.95em;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: var(--radius-md, 8px);
  background: var(--pp-surface-elevated, #222);
}
.pp-charts-header__delta.is-up { color: #4ade80; }
.pp-charts-header__delta.is-down { color: #f87171; }
.pp-charts-header__delta.is-muted { color: var(--pp-text-muted, #888); }
.pp-charts-header__delta-note {
  font-weight: 400;
  font-size: 0.85em;
  opacity: 0.85;
  margin-left: 6px;
}
.pp-charts-header__env {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  color: var(--pp-text-muted, #888);
  font-size: 0.9em;
}
.pp-charts-header__weather {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.pp-charts-header__weather-icon { font-size: 1.1em; }
.pp-charts-header__weather-temp { color: var(--pp-text, #e6e6e6); font-weight: 500; }
.pp-charts-header__sun {
  display: inline-flex;
  gap: var(--space-3, 12px);
  white-space: nowrap;
}
.pp-charts-header__actions {
  display: flex;
  justify-content: flex-end;
  margin-bottom: var(--space-3, 12px);
}
.pp-charts-header__actions .pp-link-btn {
  background: transparent;
  color: var(--pp-text-muted, #888);
  border: none;
  padding: 4px 8px;
  font-size: 0.85em;
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 3px;
}
.pp-charts-header__actions .pp-link-btn:hover {
  color: var(--pp-accent, #4ade80);
}

@media (max-width: 720px) {
  .pp-charts-header {
    grid-template-columns: 1fr;
    gap: var(--space-3, 12px);
  }
  .pp-charts-header__env { align-items: flex-start; }
  .pp-charts-header__stat { justify-content: flex-start; }
}
</style>

<script>
/* perchpi:daily-charts-header-strip-v1 — Open-Meteo weather fetch */
/* perchpi:weather-fix-domready — wrap in DOMContentLoaded so [data-pp-charts-header] exists */
document.addEventListener('DOMContentLoaded', function () {
  var host = document.querySelector('[data-pp-charts-header]');
  if (!host) return;
  var slot = host.querySelector('[data-pp-weather]');
  if (!slot) return;

  var lat = parseFloat(host.dataset.lat);
  var lon = parseFloat(host.dataset.lon);
  var date = host.dataset.date;
  var isToday = host.dataset.isToday === '1';
  var isFuture = host.dataset.isFuture === '1';

  if (!lat || !lon || isFuture) {
    slot.style.display = 'none';
    return;
  }

  // Today (or recent past within forecast window) → forecast endpoint
  // Past dates → archive endpoint
  var url;
  if (isToday) {
    url = 'https://api.open-meteo.com/v1/forecast'
        + '?latitude=' + lat + '&longitude=' + lon
        + '&daily=weather_code,temperature_2m_max,temperature_2m_min'
        + '&timezone=auto&start_date=' + date + '&end_date=' + date
        + '&temperature_unit=fahrenheit';
  } else {
    url = 'https://archive-api.open-meteo.com/v1/archive'
        + '?latitude=' + lat + '&longitude=' + lon
        + '&start_date=' + date + '&end_date=' + date
        + '&daily=weather_code,temperature_2m_max,temperature_2m_min'
        + '&timezone=auto&temperature_unit=fahrenheit';
  }

  var WMO = {
    0: '☀',  1: '🌤', 2: '⛅', 3: '☁',
    45: '🌫', 48: '🌫',
    51: '🌦', 53: '🌦', 55: '🌦',
    61: '🌧', 63: '🌧', 65: '🌧',
    71: '🌨', 73: '🌨', 75: '🌨',
    80: '🌦', 81: '🌧', 82: '🌧',
    95: '⛈',  96: '⛈',  99: '⛈'
  };

  fetch(url, { cache: 'force-cache' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (j) {
      if (!j.daily || !j.daily.temperature_2m_max || !j.daily.temperature_2m_max[0]) {
        slot.style.display = 'none';
        return;
      }
      var hi = Math.round(j.daily.temperature_2m_max[0]);
      var lo = Math.round(j.daily.temperature_2m_min[0]);
      var code = j.daily.weather_code ? j.daily.weather_code[0] : 0;
      var icon = WMO[code] || '·';
      slot.querySelector('.pp-charts-header__weather-icon').textContent = icon;
      slot.querySelector('.pp-charts-header__weather-temp').textContent = hi + '°/' + lo + '°';
    })
    .catch(function () { slot.style.display = 'none'; });
});
</script>

<section class="pp-content">

<?php /* perchpi:daily-charts-header-strip-v1 — unified header strip */ ?>
<div class="pp-charts-header" data-pp-charts-header
     data-lat="<?php echo htmlspecialchars((string)$pp_lat); ?>"
     data-lon="<?php echo htmlspecialchars((string)$pp_lon); ?>"
     data-date="<?php echo htmlspecialchars($theDate); ?>"
     data-is-today="<?php echo $pp_is_today ? '1' : '0'; ?>"
     data-is-future="<?php echo $pp_is_future ? '1' : '0'; ?>">

  <div class="pp-charts-header__nav">
    <a class="pp-iconbtn" href="?view=Daily Charts&amp;date=<?php echo urlencode($pp_prev_date); ?>"
       aria-label="Previous day" title="Previous day (<?php echo htmlspecialchars($pp_prev_date); ?>)">◄</a>

    <form class="pp-charts-header__date-form" action="" method="GET">
      <input type="hidden" name="view" value="Daily Charts">
      <input type="date" name="date"
             value="<?php echo htmlspecialchars($theDate); ?>"
             onchange="this.form.submit()"
             aria-label="Select date">
    </form>

    <a class="pp-iconbtn" href="?view=Daily Charts&amp;date=<?php echo urlencode($pp_today_iso); ?>"
       aria-label="Jump to today" title="Today">today</a>

    <a class="pp-iconbtn <?php echo $pp_is_today ? 'is-disabled' : ''; ?>"
       <?php if (!$pp_is_today): ?>href="?view=Daily Charts&amp;date=<?php echo urlencode($pp_next_date); ?>"<?php endif; ?>
       aria-label="Next day" title="Next day"><?php echo $pp_is_today ? '►' : '►'; ?></a>
  </div>

  <div class="pp-charts-header__stat">
    <div class="pp-charts-header__count"><?php echo number_format($pp_today_count); ?></div>
    <div class="pp-charts-header__count-label">detections</div>
    <?php if ($pp_delta_pct !== null): ?>
      <div class="pp-charts-header__delta <?php echo $pp_delta_pct >= 0 ? 'is-up' : 'is-down'; ?>">
        <?php echo $pp_delta_pct >= 0 ? '▲' : '▼'; ?>
        <?php echo abs($pp_delta_pct); ?>%
        <span class="pp-charts-header__delta-note">vs <?php echo htmlspecialchars($pp_prev_label_short); ?></span>
      </div>
    <?php elseif ($pp_delta_abs !== null): ?>
      <div class="pp-charts-header__delta is-up">
        ▲ <?php echo $pp_delta_abs; ?>
        <span class="pp-charts-header__delta-note">vs <?php echo htmlspecialchars($pp_prev_label_short); ?> (0)</span>
      </div>
    <?php else: ?>
      <div class="pp-charts-header__delta is-muted">—</div>
    <?php endif; ?>
  </div>

  <div class="pp-charts-header__env">
    <div class="pp-charts-header__weather" data-pp-weather>
      <span class="pp-charts-header__weather-icon">…</span>
      <span class="pp-charts-header__weather-temp"></span>
    </div>
    <?php if ($pp_sunrise && $pp_sunset): ?>
      <div class="pp-charts-header__sun">
        <span title="Sunrise">↑ <?php echo htmlspecialchars($pp_sunrise); ?></span>
        <span title="Sunset">↓ <?php echo htmlspecialchars($pp_sunset); ?></span>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="pp-charts-header__actions">
  <button type="button" class="pp-link-btn" onclick="showDialog()">Export CSV for eBird</button>
</div>
<?php
$time = time();

if (file_exists('./Charts/'.$chart)) {
  echo '<div class="pp-chart-frame">';
  echo "<img src=\"/Charts/$chart?nocache=$time\" alt=\"Detections by species and hour for $theDate\">";
  echo '</div>';
} else {
  echo '<div class="pp-chart-empty">No charts available for ' . htmlspecialchars($theDate) . '</div>';
}
echo "</div>";
echo '</section>';
if (get_included_files()[0] === __FILE__) {
	echo '</html>
</body>';
}
