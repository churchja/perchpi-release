<?php
/* ============================================================================
   BirdNET-Pi / PerchPi — views.php (v2.2.3)
   ============================================================================
   Adds routes for:
   - Last Hour (chronological, 60min window)
   - Birds Today (species ranked list — replaces placeholder)
   - Species Detail (drill-in with 5 time buckets)
   ============================================================================ */

$_GET  = array_map(function($v) { return is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v; }, $_GET ?: []);
$_POST = array_map(function($v) { return is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v; }, $_POST ?: []);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once 'scripts/common.php';
csrf_guard_keys(['submit', 'add', 'del', 'latitude', 'run_species_count']); // perchpi:s21a-csrf-v1
$user   = get_user();
$home   = get_home();
$config = get_config();
set_timezone();

$restore = "cat $home/BirdSongs/restore.log";

if (is_authenticated() &&
    (!isset($_SESSION['behind']) || !isset($_SESSION['behind_time']) ||
     time() > $_SESSION['behind_time'] + 86400)) {
  shell_exec("sudo -u".$user." git -C ".$home."/BirdNET-Pi fetch > /dev/null 2>/dev/null &");
  $str = trim(shell_exec("sudo -u".$user." git -C ".$home."/BirdNET-Pi status"));
  $num_commits_behind = '0';
  if (preg_match("/behind '.*?' by (\d+) commit/", $str, $matches)) {
    $num_commits_behind = $matches[1];
  }
  if (preg_match('/\b(\d+)\b and \b(\d+)\b different commits each/', $str, $matches)) {
    $num_commits_behind = ((int)$matches[1]) + ((int)$matches[2]);
  }
  if (stripos($str, "Your branch is up to date") !== false) {
    $num_commits_behind = '0';
  }
  $_SESSION['behind'] = $num_commits_behind;
  $_SESSION['behind_time'] = time();
}

function update_species_list($filename, $species, $add) {
  if ($add) {
    $str = file_get_contents($filename);
    $str = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $str);
    file_put_contents($filename, $str);
    foreach ($species as $selectedOption) {
      if (strpos($str, $selectedOption) === false) {
        file_put_contents($filename, htmlspecialchars_decode($selectedOption, ENT_QUOTES) . "\n", FILE_APPEND);
      }
    }
  } else {
    $str = file_get_contents($filename);
    $str = preg_replace('/^\h*\v+/m', '', $str);
    file_put_contents($filename, $str);
    foreach ($species as $selectedOption) {
      $content = file_get_contents($filename);
      $newcontent = str_replace($selectedOption, "", $content);
      $newcontent = str_replace(htmlspecialchars_decode($selectedOption, ENT_QUOTES), "", $newcontent);
      file_put_contents($filename, $newcontent);
    }
    $str = file_get_contents($filename);
    $str = preg_replace('/^\h*\v+/m', '', $str);
    file_put_contents($filename, $str);
  }
}

?>
<script type="text/javascript" src="static/plupload.full.min.js"></script>

<?php
$view = isset($_GET['view']) ? htmlspecialchars_decode($_GET['view'], ENT_QUOTES) : null;

if (isset($view)) {

  /* ─── ACTIVITY ───────────────────────────────────────────────────────── */

  if ($view == "Overview") {
    include __DIR__ . '/overview.php';
  }
  elseif ($view == "Last Hour") {
    include __DIR__ . '/last_hour_list.php';
  }
elseif ($view == "Recordings") {
    require __DIR__ . "/../scripts/recordings.php";
} /* perchpi:c-recordings-v1 */

  /* perchpi:detections-today-route */
  elseif ($view == "Detections Today") {
    include __DIR__ . '/today_list.php';
  }
  elseif ($view == "Birds Today" || $view == "Daily Detections") {
    // perchpi:clickable-stats — Daily Detections is a legacy URL alias for Birds Today.
    include __DIR__ . '/birds_today.php';
  }

  elseif ($view == "All Species") {
    include __DIR__ . '/all_species.php';
  }

  elseif ($view == "Species Detail") {
    include __DIR__ . '/species_detail.php';
  }
  /* /NEW */
  elseif ($view == "Spectrogram") {
    $_GET['tab'] = 'spectrogram';
    $EMBEDDED = true;
    include __DIR__ . '/kiosk.php';
  }

  /* ─── LIBRARY ────────────────────────────────────────────────────────── */

  elseif ($view == "Species Stats") {
    include __DIR__ . '/../scripts/stats.php';
  }
  /* perchpi:quarantine-route-v1 */
  elseif ($view == "Quarantine") {
    include __DIR__ . '/quarantine.php';
  }
  /* perchpi:notable-sightings-v1 */
  elseif ($view == "Notable Sightings") {
    include __DIR__ . '/notable.php';
  }
  elseif ($view == "Species Profile") {
    require __DIR__ . "/species_profile.php";
  }
  elseif ($view == "Best Recordings") {
    include __DIR__ . '/best_recordings.php';
  }
  elseif ($view == "Recordings") {
    if (isset($_GET['date']) && isset($_GET['species'])) {
      include __DIR__ . '/recordings_date_species_detections.php';
    } elseif (isset($_GET['date'])) {
      include __DIR__ . '/recordings_date_species.php';
    } else {
      include __DIR__ . '/recordings_by_date.php';
    }
  }
  elseif ($view == "Daily Charts") {
    include __DIR__ . '/../scripts/history.php';
  }
  elseif ($view == "Weekly Report") {
    include __DIR__ . '/../scripts/weekly_report.php';
  }
  elseif ($view == "Streamlit") {
    /* perchpi:streamlit-wrap */
    include __DIR__ . '/detection_patterns.php';
  }

  /* ─── LIVE ───────────────────────────────────────────────────────────── */

  // perchpi:live-audio-route-v1
  elseif ($view == "Live Audio") {
    include 'live_audio.php';
  }

  elseif ($view == "View Log") {
    echo '<div class="page-header">';
    echo '<h1>View Log</h1>';
    echo '<p class="page-header-subtitle">Live BirdNET-Pi log output</p>';
    echo '</div>';
    echo '<iframe src="log" style="width:100%; height: calc(100vh - 240px); border: 1px solid var(--border-soft); border-radius: var(--radius-sm); background: var(--surface-2);"></iframe>';
  }

  elseif ($view == "Kiosk") {
    header('Location: kiosk.php?tab=overview');
    exit;
  }

  /* ─── TOOLS / SETTINGS / ADMIN ────────────────────────────────────────── */

  elseif ($view == "Tools") {
    echo '<div class="page-header"><h1>Tools</h1>';
    echo '<p class="page-header-subtitle">Use the gear icon in the top-right to access Tools.</p></div>';
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">⚙</div>';
    echo '<h3>Tools menu moved</h3>';
    echo '<p>Tools are now in the gear icon at the top-right of the page.</p>';
    echo '<p style="margin-top: var(--space-4);"><a class="btn btn--primary" href="?view=Settings">Open Settings</a></p>';
    echo '</div>';
  }
  elseif ($view == "Settings") {
    include __DIR__ . '/../scripts/config.php';
  }
  elseif ($view == "Advanced") {
    include __DIR__ . '/../scripts/advanced.php';
  }
  elseif ($view == "System Info") {
    echo '<iframe src="phpsysinfo/index.php" title="System Info"></iframe>';
  }
  elseif ($view == "System Controls") {
    ensure_authenticated();
    include __DIR__ . '/../scripts/system_controls.php';
  }
  elseif ($view == "Services") {
    ensure_authenticated();
    include __DIR__ . '/../scripts/service_controls.php';
  }
  // perchpi:s21a-d9-v1 (D9, stage-2 S4-1/S4-3): File Manager and Adminer are not shipped.
  elseif ($view == "Webterm") {
    ensure_authenticated('You cannot access the web terminal');
    echo '<iframe src="terminal" title="Web Terminal"></iframe>';
  }
  elseif ($view == "Included") {
    ensure_authenticated();
    if (isset($_GET['species']) && (isset($_GET['add']) || isset($_GET['del']))) {
      update_species_list("./scripts/include_species_list.txt", $_GET['species'], isset($_GET['add']));
    }
    $species_list = "include";
    include __DIR__ . '/../scripts/species_list.php';
  }
  elseif ($view == "Excluded") {
    ensure_authenticated();
    if (isset($_GET['species']) && (isset($_GET['add']) || isset($_GET['del']))) {
      update_species_list("./scripts/exclude_species_list.txt", $_GET['species'], isset($_GET['add']));
    }
    $species_list = "exclude";
    include __DIR__ . '/../scripts/species_list.php';
  }
  elseif ($view == "Whitelisted") {
    ensure_authenticated();
    if (isset($_GET['species']) && (isset($_GET['add']) || isset($_GET['del']))) {
      update_species_list("./scripts/whitelist_species_list.txt", $_GET['species'], isset($_GET['add']));
    }
    $species_list = "whitelist";
    include __DIR__ . '/../scripts/species_list.php';
  }
  elseif ($view == "Species Management") {
    ensure_authenticated();
    include __DIR__ . '/../scripts/species_tools.php';
  }

  elseif ($view == "Xeno-canto Sources") {
    include 'xeno_canto_placeholder.php';
  }

  else {
    echo '<div class="page-header"><h1>View not found</h1></div>';
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">?</div>';
    echo '<h3>Unknown view: ' . htmlspecialchars($view) . '</h3>';
    echo '<p><a class="btn btn--primary" href="?view=Overview">Go to Overview</a></p>';
    echo '</div>';
  }

}

elseif (isset($_GET['submit'])) {
  ensure_authenticated();
  $allowedCommands = array(
    'sudo systemctl stop livestream.service && sudo systemctl stop icecast2.service',
    'sudo systemctl restart livestream.service && sudo systemctl restart icecast2.service',
    'sudo systemctl disable --now livestream.service && sudo systemctl disable icecast2 && sudo systemctl stop icecast2.service',
    'sudo systemctl enable icecast2 && sudo systemctl start icecast2.service && sudo systemctl enable --now livestream.service',
    'sudo systemctl stop web_terminal.service',
    'sudo systemctl restart web_terminal.service',
    'sudo systemctl disable --now web_terminal.service',
    'sudo systemctl enable --now web_terminal.service',
    'sudo systemctl stop birdnet_log.service',
    'sudo systemctl restart birdnet_log.service',
    'sudo systemctl disable --now birdnet_log.service',
    'sudo systemctl enable --now birdnet_log.service',
    'sudo systemctl stop birdnet_analysis.service',
    'sudo systemctl restart birdnet_analysis.service',
    'sudo systemctl disable --now birdnet_analysis.service',
    'sudo systemctl enable --now birdnet_analysis.service',
    'sudo systemctl stop birdnet_stats.service',
    'sudo systemctl restart birdnet_stats.service',
    'sudo systemctl disable --now birdnet_stats.service',
    'sudo systemctl enable --now birdnet_stats.service',
    'sudo systemctl stop birdnet_recording.service',
    'sudo systemctl restart birdnet_recording.service',
    'sudo systemctl disable --now birdnet_recording.service',
    'sudo systemctl enable --now birdnet_recording.service',
    'sudo systemctl stop chart_viewer.service',
    'sudo systemctl restart chart_viewer.service',
    'sudo systemctl disable --now chart_viewer.service',
    'sudo systemctl enable --now chart_viewer.service',
    'sudo systemctl stop spectrogram_viewer.service',
    'sudo systemctl restart spectrogram_viewer.service',
    'sudo systemctl disable --now spectrogram_viewer.service',
    'sudo systemctl enable --now spectrogram_viewer.service',
    'sudo systemctl enable ' . get_service_mount_name() . ' && sudo reboot',
    'sudo systemctl disable ' . get_service_mount_name() . ' && sudo reboot',
    'stop_core_services.sh',
    'restart_services.sh',
    'sudo reboot',
    // 'update_birdnet.sh' removed: perchpi:s21a-no-update-v1 (D6)
    'sudo shutdown now',
    'sudo -u ' . $user . ' clear_all_data.sh', // perchpi:s21a2-d1-v1: runs as birder
    $restore
  );

  $command = htmlspecialchars_decode($_GET['submit'], ENT_QUOTES);
  if (in_array($command, $allowedCommands)) {
    $initcommand = $command;
    if (strpos($command, "systemctl") !== false) {
      if (strpos($command, " && ") !== false) {
        $separate_commands = explode("&&", trim($command));
        $new_multiservice_status_command = "";
        foreach ($separate_commands as $indiv) {
          $tmp = explode(" ", trim($indiv));
          $new_multiservice_status_command .= " " . trim(end($tmp));
        }
        $service_names = $new_multiservice_status_command;
      } else {
        $tmp = explode(" ", trim($command));
        $service_names = end($tmp);
      }
      $command .= " & sleep 3; sudo systemctl status " . $service_names;
    }

    if ($initcommand == "update_birdnet.sh") {
      session_unset();
    }

    $results = shell_exec("$command 2>&1");
    $results = str_replace("FAILURE",         "<span style='color:var(--status-low)'>FAILURE</span>", $results);
    $results = str_replace("failed",          "<span style='color:var(--status-low)'>failed</span>", $results);
    $results = str_replace("active (running)","<span style='color:var(--status-ok)'><b>active (running)</b></span>", $results);
    $results = str_replace("Your branch is up to date", "<span style='color:var(--status-ok)'><b>Your branch is up to date</b></span>", $results);
    $results = str_replace("(+)", "(<span style='color:var(--status-ok)'><b>+</b></span>)", $results);
    $results = str_replace("(-)", "(<span style='color:var(--status-low)'><b>-</b></span>)", $results);

    $results = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $results);
    if (strlen($results) == 0) {
      $results = "This command has no output.";
    }

    echo '<div class="card" style="margin-bottom: var(--space-4);">';
    echo '<div class="card-header">';
    echo '<h3 class="card-title">Output of: <code>' . htmlspecialchars($initcommand) . '</code></h3>';
    echo '<button class="btn btn--ghost btn--sm" onclick="navigator.clipboard.writeText(this.parentElement.parentElement.querySelector(\'pre\').textContent); this.textContent=\'Copied!\'">Copy</button>';
    echo '</div>';
    echo '<pre class="bash" style="margin: 0; max-height: 60vh; overflow: auto;">' . $results . '</pre>';
    echo '</div>';
  }
  if (function_exists('ob_end_flush') && ob_get_level() > 0) ob_end_flush();
}

else {
  include __DIR__ . '/overview.php';
}

?>

<script>
function getTheDate(increment) {
  var theDate = "<?php if (isset($theDate)) echo $theDate; ?>";
  var d = new Date(theDate);
  d.setDate(d.getDate() + increment);
  var yyyy = d.getFullYear();
  var mm = d.getMonth() + 1; if (mm < 10) mm = "0" + mm;
  var dd = d.getDate();     if (dd < 10) dd = "0" + dd;
  if (document.getElementById("SwipeSpinner")) {
    document.getElementById("SwipeSpinner").hidden = false;
  }
  window.location = "/index.php?date=" + yyyy + "-" + mm + "-" + dd + "&view=Daily+Charts";
}

(function() {
  var params = new URLSearchParams(window.location.search);
  if (params.get('view') !== 'Daily Charts') return;

  document.addEventListener('keydown', function(event) {
    if (event.keyCode === 37) getTheDate(-1);
    if (event.keyCode === 39) getTheDate(+1);
  });

  let touchstartX = 0, diffX = 0, touchstartY = 0, diffY = 0;
  let startTime = 0, diffTime = 0;

  document.addEventListener('touchstart', function(e) {
    if (e.changedTouches[0].clientX < 30) return;
    touchstartX = e.changedTouches[0].screenX;
    touchstartY = e.changedTouches[0].screenY;
    startTime = Date.now();
  });

  document.addEventListener('touchend', function(e) {
    diffX = touchstartX - e.changedTouches[0].screenX;
    diffY = touchstartY - e.changedTouches[0].screenY;
    diffTime = Date.now() - startTime;
    if (Math.abs(diffX) > Math.abs(diffY) && diffTime < 350) {
      if (diffX > 60)  getTheDate(+1);
      if (diffX < -60) getTheDate(-1);
    }
  });
})();

function setLiveStreamVolume(vol) {
  document.querySelectorAll(".custom-audio-player audio").forEach(function(el) {
    if (el) el.volume = vol;
  });
}
window.onbeforeunload = function() {
  document.querySelectorAll(".custom-audio-player audio").forEach(function(el) {
    if (el) el.volume = 1;
  });
};
</script>
