<?php
error_reporting(E_ERROR);
ini_set('display_errors',1);
define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__.'/scripts/common.php');

$user = get_user();
$home = get_home();
$config = get_config();
set_timezone();

ensure_authenticated();
csrf_guard_keys(['latitude', 'sendtest', 'restart_php']); // perchpi:s21a-csrf-v1

if (file_exists($home."/BirdNET-Pi/apprise.txt")) {
  $apprise_config = file_get_contents($home."/BirdNET-Pi/apprise.txt");
} else {
  $apprise_config = "";
}

if (file_exists($home."/BirdNET-Pi/body.txt")) {
  $apprise_notification_body = file_get_contents($home."/BirdNET-Pi/body.txt");
} else {
  $apprise_notification_body = "";
}

function syslog_shell_exec($cmd, $sudo_user = null) {
  if ($sudo_user) {
    $cmd = "sudo -u $sudo_user $cmd";
  }
  $output = shell_exec($cmd);

  if (strlen($output) > 0) {
    syslog(LOG_INFO, $output);
  }
}

// perchpi:config-xss-stepfix-v1 - shell-output XSS escapes + $config attribute echoes hardened + sf_thresh step fix
if(isset($_GET['threshold'])) {
  $threshold = $_GET['threshold'];
  if (!is_numeric($threshold) || $threshold < 0 || $threshold > 1) {
    die('Invalid threshold value');
  }

  // perchpi:config-shell-hardening-v1 - escapeshellarg on $threshold (is_numeric+range above is primary validation; this is defense-in-depth).
  $command = "sudo -u $user ".$home."/BirdNET-Pi/birdnet/bin/python3 ".$home."/BirdNET-Pi/scripts/species.py --threshold ".escapeshellarg($threshold)." 2>&1";

  $output = shell_exec($command);

  echo htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
  die();
}

if(isset($_GET['restart_php']) && $_GET['restart_php'] == "true") {
  shell_exec("sudo service php*-fpm restart");
  die();
}

# Basic Settings
if(isset($_GET["latitude"])){
  $latitude = $_GET["latitude"];
  $longitude = $_GET["longitude"];
  $site_name = $_GET["site_name"];
  $site_name = str_replace('"', "", $site_name);
  $site_name = str_replace('\'', "", $site_name);
  $birdweather_id = $_GET["birdweather_id"];
  $apprise_input = $_GET['apprise_input'];
  $apprise_notification_title = $_GET['apprise_notification_title'];
  $apprise_notification_body = htmlspecialchars_decode($_GET['apprise_notification_body'], ENT_QUOTES);
  $minimum_time_limit = $_GET['minimum_time_limit'];
  $language = $_GET["language"];
  $info_site = $_GET["info_site"];
  $color_scheme = $_GET["color_scheme"];
  $timezone = $_GET["timezone"];
  $model = $_GET["model"];
  $sf_thresh = $_GET["sf_thresh"];
  if(isset($_GET['data_model_version'])) {
    $data_model_version = 2;
  } else {
    $data_model_version = 1;
  }
  $only_notify_species_names = htmlspecialchars_decode($_GET['only_notify_species_names'], ENT_QUOTES);
  $only_notify_species_names_2 = htmlspecialchars_decode($_GET['only_notify_species_names_2'], ENT_QUOTES);

  if(isset($_GET['apprise_notify_each_detection'])) {
    $apprise_notify_each_detection = 1;
  } else {
    $apprise_notify_each_detection = 0;
  }
  if(isset($_GET['apprise_notify_new_species'])) {
    $apprise_notify_new_species = 1;
  } else {
    $apprise_notify_new_species = 0;
  }
  if(isset($_GET['apprise_notify_new_species_each_day'])) {
    $apprise_notify_new_species_each_day = 1;
  } else {
    $apprise_notify_new_species_each_day = 0;
  }
  if(isset($_GET['apprise_weekly_report'])) {
    $apprise_weekly_report = 1;
  } else {
    $apprise_weekly_report = 0;
  }

  if(isset($timezone) && in_array($timezone, DateTimeZone::listIdentifiers())) {
    # dpkg-reconfigure tzdata is a pain to run non-interactively, so we do it in two steps instead
    # tzlocal.get_localzone() will fail if the Debian specific /etc/timezone is not in sync
    // perchpi:s21a2-d1-v1: perchpi-time sets the timezone and keeps /etc/timezone in step.
    shell_exec("sudo /usr/local/sbin/perchpi-time tz ".escapeshellarg($timezone));
    $_SESSION['my_timezone'] = $timezone;
    date_default_timezone_set($timezone);
    echo "<script>setTimeout(
    function() {
      const xhttp = new XMLHttpRequest();
    xhttp.open(\"POST\", \"./config.php?restart_php=true\", true);
    xhttp.setRequestHeader('X-CSRF-Token', window.PP_CSRF || ''); // perchpi:s21a2-csrf-v1 (D-S21a-1)
    xhttp.send();
    }, 1000);</script>";
  }

  // logic for setting the date and time based on user inputs from the form below
  if(isset($_GET['date']) && isset($_GET['time'])) {
    // can't set the date manually if it's getting it from the internet, disable ntp
    exec("sudo /usr/local/sbin/perchpi-time ntp off"); // perchpi:s21a2-d1-v1

    // check if valid date and time
    $datetime = DateTime::createFromFormat('Y-m-d H:i', $_GET['date'] . ' ' . $_GET['time']);
    if ($datetime && $datetime->format('Y-m-d H:i') === $_GET['date'] . ' ' . $_GET['time']) {
      exec("sudo /usr/local/sbin/perchpi-time set ".escapeshellarg($datetime->format('Y-m-d H:i'))); // perchpi:s21a2-d1-v1
    }
  } else {
    // user checked 'use time from internet if available,' so make sure that's on
    if(strlen(trim(exec("timedatectl | grep \"NTP service: active\""))) == 0){
      exec("sudo /usr/local/sbin/perchpi-time ntp on"); // perchpi:s21a2-d1-v1
      sleep(3);
    }
  }

  // perchpi:conf-rewrite-hardening-v1 - validate/sanitize $_GET values
  // before they're plugged into /etc/birdnet/birdnet.conf. The conf is
  // consumed by shell scripts (source) and Python; both treat KEY=VALUE
  // lines as authoritative, so newline / shell-metachar / quote-break /
  // $(...) injection here could set unauthorized KEYs (bypassing UI
  // validation, e.g. GEMINI_API_KEY's strict regex) or trigger RCE on
  // the next service restart.
  $pp_conf_safe = function($s, $mode = 'text') {
    if (!is_string($s)) $s = (string)$s;
    if ($mode === 'numeric') return is_numeric($s) ? (string)(0+$s) : '';
    if ($mode === 'integer') return (string)(int)$s;
    if ($mode === 'token')   return preg_replace('/[^A-Za-z0-9._-]/', '', $s);
    // 'text' default: strip control chars, double-quote, backslash,
    // backtick, $. Apostrophes preserved (Wilson's Warbler, etc).
    return preg_replace('/[\x00-\x1F\x7F"\\\\`$]/', '', $s);
  };
  $site_name                   = $pp_conf_safe($site_name, 'text');
  $latitude                    = $pp_conf_safe($latitude, 'numeric');
  $longitude                   = $pp_conf_safe($longitude, 'numeric');
  $birdweather_id              = $pp_conf_safe($birdweather_id, 'token');
  $apprise_notification_title  = $pp_conf_safe($apprise_notification_title, 'text');
  $minimum_time_limit          = $pp_conf_safe($minimum_time_limit, 'integer');
  $language                    = $pp_conf_safe($language, 'token');
  $info_site                   = $pp_conf_safe($info_site, 'token');
  $color_scheme                = $pp_conf_safe($color_scheme, 'token');
  $model                       = $pp_conf_safe($model, 'token');
  $sf_thresh                   = $pp_conf_safe($sf_thresh, 'numeric');
  $only_notify_species_names   = $pp_conf_safe($only_notify_species_names, 'text');
  $only_notify_species_names_2 = $pp_conf_safe($only_notify_species_names_2, 'text');

  $contents = file_get_contents("/etc/birdnet/birdnet.conf");
  $contents = preg_replace("/SITE_NAME=.*/", "SITE_NAME=\"$site_name\"", $contents);
  $contents = preg_replace("/LATITUDE=.*/", "LATITUDE=$latitude", $contents);
  $contents = preg_replace("/LONGITUDE=.*/", "LONGITUDE=$longitude", $contents);
  $contents = preg_replace("/BIRDWEATHER_ID=.*/", "BIRDWEATHER_ID=$birdweather_id", $contents);
  $contents = preg_replace("/APPRISE_NOTIFICATION_TITLE=.*/", "APPRISE_NOTIFICATION_TITLE=\"$apprise_notification_title\"", $contents);
  $contents = preg_replace("/APPRISE_NOTIFY_EACH_DETECTION=.*/", "APPRISE_NOTIFY_EACH_DETECTION=$apprise_notify_each_detection", $contents);
  $contents = preg_replace("/APPRISE_NOTIFY_NEW_SPECIES=.*/", "APPRISE_NOTIFY_NEW_SPECIES=$apprise_notify_new_species", $contents);
  $contents = preg_replace("/APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY=.*/", "APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY=$apprise_notify_new_species_each_day", $contents);
  $contents = preg_replace("/APPRISE_WEEKLY_REPORT=.*/", "APPRISE_WEEKLY_REPORT=$apprise_weekly_report", $contents);
  if(strlen($language) == 2 || strlen($language) == 5){
    $contents = preg_replace("/DATABASE_LANG=.*/", "DATABASE_LANG=$language", $contents);
  }
  $contents = preg_replace("/INFO_SITE=.*/", "INFO_SITE=$info_site", $contents);
  $contents = preg_replace("/COLOR_SCHEME=.*/", "COLOR_SCHEME=$color_scheme", $contents);  
  $contents = preg_replace("/APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES=.*/", "APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES=$minimum_time_limit", $contents);
  $contents = preg_replace("/^MODEL=.*$/m", "MODEL=$model", $contents);
  $contents = preg_replace("/SF_THRESH=.*/", "SF_THRESH=$sf_thresh", $contents);
  // perchpi:geomodel-save-handler — preserve USE_GEOMODEL across Settings saves
  $use_geomodel_val = isset($_GET['use_geomodel']) ? '1' : '0';
  if (preg_match("/^USE_GEOMODEL=/m", $contents)) {
    $contents = preg_replace("/^USE_GEOMODEL=.*$/m", "USE_GEOMODEL=$use_geomodel_val", $contents);
  } else {
    $contents .= "\nUSE_GEOMODEL=$use_geomodel_val\n";
  }
  // perchpi:quarantine-threshold-save-handler-v1
  if (isset($_GET['quarantine_threshold'])) {
    $qt_val = floatval($_GET['quarantine_threshold']);
    if ($qt_val >= 0.001 && $qt_val <= 0.30) {
      if (preg_match("/^QUARANTINE_THRESHOLD=/m", $contents)) {
        $contents = preg_replace("/^QUARANTINE_THRESHOLD=.*$/m", "QUARANTINE_THRESHOLD=$qt_val", $contents);
      } else {
        $contents .= "\nQUARANTINE_THRESHOLD=$qt_val\n";
      }
    }
  }
  // perchpi:ai-fp-settings-v1 — AI False Positive Checker (Gemini API)
  $ai_fp_val = isset($_GET['ai_false_positive_enabled']) ? '1' : '0';
  if (preg_match("/^AI_FALSE_POSITIVE_ENABLED=/m", $contents)) {
    $contents = preg_replace("/^AI_FALSE_POSITIVE_ENABLED=.*$/m", "AI_FALSE_POSITIVE_ENABLED=$ai_fp_val", $contents);
  } else {
    $contents .= "\nAI_FALSE_POSITIVE_ENABLED=$ai_fp_val\n";
  }
  if (isset($_GET['gemini_api_key'])) {
    $key_val = trim($_GET['gemini_api_key']);
    // Blank = no change (preserve existing). To set, must match alnum/dash/underscore 20-100 chars.
    if ($key_val !== '' && preg_match('/^[A-Za-z0-9_-]{20,100}$/', $key_val)) {
      if (preg_match("/^GEMINI_API_KEY=/m", $contents)) {
        $contents = preg_replace("/^GEMINI_API_KEY=.*$/m", "GEMINI_API_KEY=\"$key_val\"", $contents);
      } else {
        $contents .= "\nGEMINI_API_KEY=\"$key_val\"\n";
      }
    }
  }

  // perchpi:e5-filter-ui-v1 — AI filter tuning numeric inputs
  if (isset($_GET['ai_fp_filter_lone_threshold'])) {
    $val = intval($_GET['ai_fp_filter_lone_threshold']);
    if ($val < 1) $val = 1;
    if ($val > 50) $val = 50;
    if (preg_match("/^AI_FP_FILTER_LONE_THRESHOLD=/m", $contents)) {
      $contents = preg_replace("/^AI_FP_FILTER_LONE_THRESHOLD=.*$/m", "AI_FP_FILTER_LONE_THRESHOLD=$val", $contents);
    } else {
      $contents .= "\nAI_FP_FILTER_LONE_THRESHOLD=$val\n";
    }
  }
  if (isset($_GET['ai_fp_filter_abundant_daily'])) {
    $val = intval($_GET['ai_fp_filter_abundant_daily']);
    if ($val < 50) $val = 50;
    if ($val > 5000) $val = 5000;
    if (preg_match("/^AI_FP_FILTER_ABUNDANT_DAILY=/m", $contents)) {
      $contents = preg_replace("/^AI_FP_FILTER_ABUNDANT_DAILY=.*$/m", "AI_FP_FILTER_ABUNDANT_DAILY=$val", $contents);
    } else {
      $contents .= "\nAI_FP_FILTER_ABUNDANT_DAILY=$val\n";
    }
  }
  if (isset($_GET['ai_fp_filter_abundant_override'])) {
    $val = intval($_GET['ai_fp_filter_abundant_override']);
    if ($val < 10) $val = 10;
    if ($val > 500) $val = 500;
    if (preg_match("/^AI_FP_FILTER_ABUNDANT_OVERRIDE=/m", $contents)) {
      $contents = preg_replace("/^AI_FP_FILTER_ABUNDANT_OVERRIDE=.*$/m", "AI_FP_FILTER_ABUNDANT_OVERRIDE=$val", $contents);
    } else {
      $contents .= "\nAI_FP_FILTER_ABUNDANT_OVERRIDE=$val\n";
    }
  }
  if (isset($_GET['ai_fp_filter_cluster_5min'])) {
    $val = intval($_GET['ai_fp_filter_cluster_5min']);
    if ($val < 3) $val = 3;
    if ($val > 200) $val = 200;
    if (preg_match("/^AI_FP_FILTER_CLUSTER_5MIN=/m", $contents)) {
      $contents = preg_replace("/^AI_FP_FILTER_CLUSTER_5MIN=.*$/m", "AI_FP_FILTER_CLUSTER_5MIN=$val", $contents);
    } else {
      $contents .= "\nAI_FP_FILTER_CLUSTER_5MIN=$val\n";
    }
  }
  if (isset($_GET['ai_fp_filter_cluster_30min'])) {
    $val = intval($_GET['ai_fp_filter_cluster_30min']);
    if ($val < 5) $val = 5;
    if ($val > 500) $val = 500;
    if (preg_match("/^AI_FP_FILTER_CLUSTER_30MIN=/m", $contents)) {
      $contents = preg_replace("/^AI_FP_FILTER_CLUSTER_30MIN=.*$/m", "AI_FP_FILTER_CLUSTER_30MIN=$val", $contents);
    } else {
      $contents .= "\nAI_FP_FILTER_CLUSTER_30MIN=$val\n";
    }
  }
  if (isset($_GET['ai_fp_rate_limit_rpm_max'])) {
    $val = intval($_GET['ai_fp_rate_limit_rpm_max']);
    if ($val < 1) $val = 1;
    if ($val > 60) $val = 60;
    if (preg_match("/^AI_FP_RATE_LIMIT_RPM_MAX=/m", $contents)) {
      $contents = preg_replace("/^AI_FP_RATE_LIMIT_RPM_MAX=.*$/m", "AI_FP_RATE_LIMIT_RPM_MAX=$val", $contents);
    } else {
      $contents .= "\nAI_FP_RATE_LIMIT_RPM_MAX=$val\n";
    }
  }
  if (isset($_GET['ai_fp_rate_limit_rpd_max'])) {
    $val = intval($_GET['ai_fp_rate_limit_rpd_max']);
    if ($val < 10) $val = 10;
    if ($val > 10000) $val = 10000;
    if (preg_match("/^AI_FP_RATE_LIMIT_RPD_MAX=/m", $contents)) {
      $contents = preg_replace("/^AI_FP_RATE_LIMIT_RPD_MAX=.*$/m", "AI_FP_RATE_LIMIT_RPD_MAX=$val", $contents);
    } else {
      $contents .= "\nAI_FP_RATE_LIMIT_RPD_MAX=$val\n";
    }
  }

  $contents = preg_replace("/DATA_MODEL_VERSION=.*/", "DATA_MODEL_VERSION=$data_model_version", $contents);
  $contents = preg_replace("/APPRISE_ONLY_NOTIFY_SPECIES_NAMES=.*/", "APPRISE_ONLY_NOTIFY_SPECIES_NAMES=\"$only_notify_species_names\"", $contents);
  $contents = preg_replace("/APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2=.*/", "APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2=\"$only_notify_species_names_2\"", $contents);

  if($site_name != $config["SITE_NAME"] || $color_scheme != $config["COLOR_SCHEME"]) {
    echo "<script>setTimeout(
    function() {
      window.parent.document.location.reload();
    }, 1000);</script>";

    shell_exec("sudo systemctl restart chart_viewer.service");
    // the sleep allows for the service to restart and image to be generated
    sleep(5);
  }

  $fh = fopen("/etc/birdnet/birdnet.conf", "w");
  fwrite($fh, $contents);

  if(isset($apprise_input)){
    $appriseconfig = fopen($home."/BirdNET-Pi/apprise.txt", "w");
    fwrite($appriseconfig, $apprise_input);
    $apprise_config = $apprise_input;
  }
  if(isset($apprise_notification_body)){
    $apprisebody = fopen($home."/BirdNET-Pi/body.txt", "w");
    fwrite($apprisebody, $apprise_notification_body);
  }
  if ($model != $config['MODEL'] || $language != $config['DATABASE_LANG']){
    if(strlen($language) == 2){
      syslog_shell_exec("$home/BirdNET-Pi/scripts/install_language_label.sh", $user);
      syslog(LOG_INFO, "Successfully changed language to '$language' and model to '$model'");
    }
  }
  syslog(LOG_INFO, "Restarting Services");
  shell_exec("restart_services.sh"); // perchpi:s21a2-d1-v1: runs as caddy; its systemctl lines are in the sudoers
}

if(isset($_GET['sendtest']) && $_GET['sendtest'] == "true") {
  $conf = $_GET['apprise_config'];
  $title = $_GET['apprise_notification_title'];
  $body = $_GET['apprise_notification_body'];

  $temp_conf = tmpfile();
  $t_conf_path = stream_get_meta_data($temp_conf)['uri'];
  chmod($t_conf_path, 0644);
  fwrite($temp_conf, $conf);

  $temp_body = tmpfile();
  $t_body_path = stream_get_meta_data($temp_body)['uri'];
  chmod($t_body_path, 0644);
  fwrite($temp_body, $body);

  $cmd = "sudo -u $user $home/BirdNET-Pi/birdnet/bin/python3 $home/BirdNET-Pi/scripts/send_test_notification.py --body $t_body_path --config $t_conf_path --title ".escapeshellarg($title)." 2>&1";
  $ret = shell_exec($cmd);
  echo "<pre class=\"bash\">".htmlspecialchars($ret, ENT_QUOTES, 'UTF-8')."</pre>";
  fclose($temp_conf);
  fclose($temp_body);

  die();
}

// have to get the config again after we change the variables, so the UI reflects the changes too
$config = get_config($force_reload=true);
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  </style>
  </head>
<div class="settings">
      <div class="brbanner"><h1>Basic Settings</h1></div><br>
    <form id="basicform" action=""  method="POST"><?php echo csrf_field(); /* perchpi:s21a-csrf-v1 */ ?>


<script>
  document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('modelsel').addEventListener('change', function() {
    if(this.value == "BirdNET_GLOBAL_6K_V2.4_Model_FP16"){ 
      document.getElementById("soft").style.display="unset";
    } else {
      document.getElementById("soft").style.display="none";
    }
  });
}, false);
function sendTestNotification(e) {
  document.getElementById("testsuccessmsg").innerHTML = "";
  e.classList.add("disabled");

  var apprise_notification_title = document.getElementsByName("apprise_notification_title")[0].value;
  var apprise_notification_body = encodeURIComponent(document.getElementsByName("apprise_notification_body")[0].value);
  var apprise_config = encodeURIComponent(document.getElementsByName("apprise_input")[0].value);

  var xmlHttp = new XMLHttpRequest();
    xmlHttp.onreadystatechange = function() { 
        if (xmlHttp.readyState == 4 && xmlHttp.status == 200) {
            document.getElementById("testsuccessmsg").innerHTML = this.responseText+" Test sent! Make sure to <b>Update Settings</b> below."
            e.classList.remove("disabled");
        }
    }
    xmlHttp.open("POST", "scripts/config.php?sendtest=true"+"&apprise_notification_body="+apprise_notification_body+"&apprise_config="+apprise_config+"&apprise_notification_title="+apprise_notification_title, true); // true for asynchronous
    xmlHttp.setRequestHeader('X-CSRF-Token', window.PP_CSRF || ''); // perchpi:s21a-csrf-v1
    xmlHttp.send(null);
}
</script>
      <table class="settingstable"><tr><td>
      <h2>Model</h2>

      <label for="model">Select a Model: </label>
      <select id="modelsel" name="model" class="testbtn">
      <?php
      $models = array("BirdNET_GLOBAL_6K_V2.4_Model_FP16", "BirdNET_6K_GLOBAL_MODEL");
      foreach($models as $modelName){
          $isSelected = "";
          if($config['MODEL'] == $modelName){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$modelName}' $isSelected>$modelName</option>";
        }
      ?>
      </select>
      <br>
      <span <?php if($config['MODEL'] == "BirdNET_6K_GLOBAL_MODEL") { ?>style="display: none"<?php } ?> id="soft">
      <input type="checkbox" name="data_model_version" <?php if($config['DATA_MODEL_VERSION'] == 2) { echo "checked"; };?> >
      <label for="data_model_version">Species range model V2.4 - V2</label>  [ <a target="_blank" href="https://github.com/kahst/BirdNET-Analyzer/discussions/234">Info here</a> ]<br>
      <!-- perchpi:config-thresh-arrows-v1 - sf_thresh/quarantine_threshold min/step/value aligned for clean arrow stepping -->
      <label for="sf_thresh">Species Occurrence Frequency Threshold [0.01, 0.99]: </label>
      <input name="sf_thresh" type="number" style="width:7em;" max="0.99" min="0.01" step="0.01" value="<?php print(sprintf('%.2f', isset($config['SF_THRESH']) && $config['SF_THRESH'] !== '' ? floatval($config['SF_THRESH']) : 0.03));?>"/> <span onclick="document.getElementById('sfhelp').style.display='unset'" style="text-decoration:underline;cursor:pointer">[more info]</span><br>
      <!-- perchpi:geomodel-toggle -->
      <input type="checkbox" name="use_geomodel" <?php if(isset($config['USE_GEOMODEL']) && $config['USE_GEOMODEL'] == 1) { echo "checked"; }; ?> >
      <label for="use_geomodel">Use BirdNET+ Geomodel V3.0.2 (location-aware spatiotemporal range model)</label>  [ <a target="_blank" href="https://github.com/birdnet-team/geomodel">Info here</a> ]<br>
      <!-- perchpi:quarantine-threshold-input-v1 -->
      <label for="quarantine_threshold">Quarantine Threshold (geomodel probability) [0.01, 0.30]: </label>
      <input name="quarantine_threshold" type="number" style="width:6em;" max="0.30" min="0.01" step="0.01" value="<?php print(htmlspecialchars(sprintf('%.2f', isset($config['QUARANTINE_THRESHOLD']) ? floatval($config['QUARANTINE_THRESHOLD']) : 0.05), ENT_QUOTES, 'UTF-8'));?>"/> <span style="color:#888;font-size:0.9em;">Detections with geomodel probability below this threshold are hidden from main views (still recoverable on the <a href="?view=Quarantine">Quarantine page</a>).</span><br>
      <!-- perchpi:ai-fp-settings-v1 -->
      <br>
      <strong>AI False Positive Checker</strong><br>
      <span style="color:#888;font-size:0.9em;">Uses Google Gemini to flag likely acoustic false positives among species the geomodel says ARE present (e.g., Eastern Screech-Owl detections that are actually wind/insect noise). Auto-quarantines flagged detections with reason='ai-flagged'. Free tier (1500 req/day) is plenty for typical detection volume.</span><br>
      <label><input name="ai_false_positive_enabled" type="checkbox" value="1" <?php print((!isset($config['AI_FALSE_POSITIVE_ENABLED']) || $config['AI_FALSE_POSITIVE_ENABLED'] == '1') ? 'checked' : '');?>/> Enable AI false-positive checking</label><br>
      <label for="gemini_api_key">Gemini API Key: </label>
      <input name="gemini_api_key" type="password" style="width:24em;" placeholder="<?php print(isset($config['GEMINI_API_KEY']) && $config['GEMINI_API_KEY'] !== '' ? '(saved — leave blank to keep, paste new to replace)' : 'paste API key here');?>" value=""/> <span style="color:#888;font-size:0.9em;">Get a free key at <a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com/apikey</a>. Stored in birdnet.conf.</span><br>
      <br>
      <!-- perchpi:e5-filter-ui-v1 -->
      <br>
      <strong>AI Filter Tuning</strong><br>
      <span style="color:#888;font-size:0.9em;">Controls when AI judgment is invoked. Defaults are tuned for typical setups; adjust if your acoustic environment differs. Service restart required for changes to take effect.</span><br>
      <br>
      <em>Cluster filter</em> <span style="color:#888;font-size:0.85em;">(decides which detections deserve AI judgment)</span><br>
      <label for="ai_fp_filter_lone_threshold">Lone detection threshold [1–50]: </label>
      <input name="ai_fp_filter_lone_threshold" id="ai_fp_filter_lone_threshold" type="number" style="width:6em;" min="1" max="50" value="<?php print(htmlspecialchars(isset($config['AI_FP_FILTER_LONE_THRESHOLD']) ? $config['AI_FP_FILTER_LONE_THRESHOLD'] : '3', ENT_QUOTES, 'UTF-8'));?>"/>
      <span style="color:#888;font-size:0.85em;">Skip AI if fewer than N same-species detections in last 30 minutes.</span><br>
      <label for="ai_fp_filter_abundant_daily">Abundant species daily threshold [50–5000]: </label>
      <input name="ai_fp_filter_abundant_daily" id="ai_fp_filter_abundant_daily" type="number" style="width:6em;" min="50" max="5000" value="<?php print(htmlspecialchars(isset($config['AI_FP_FILTER_ABUNDANT_DAILY']) ? $config['AI_FP_FILTER_ABUNDANT_DAILY'] : '300', ENT_QUOTES, 'UTF-8'));?>"/>
      <span style="color:#888;font-size:0.85em;">Species with more than N detections/day are "trusted abundant" (e.g., Cardinal at active sites).</span><br>
      <label for="ai_fp_filter_abundant_override">Abundant species cluster override [10–500]: </label>
      <input name="ai_fp_filter_abundant_override" id="ai_fp_filter_abundant_override" type="number" style="width:6em;" min="10" max="500" value="<?php print(htmlspecialchars(isset($config['AI_FP_FILTER_ABUNDANT_OVERRIDE']) ? $config['AI_FP_FILTER_ABUNDANT_OVERRIDE'] : '100', ENT_QUOTES, 'UTF-8'));?>"/>
      <span style="color:#888;font-size:0.85em;">Still check abundant species if 5-min cluster exceeds this (extreme burst that warrants AI judgment).</span><br>
      <label for="ai_fp_filter_cluster_5min">Cluster trigger — 5 min [3–200]: </label>
      <input name="ai_fp_filter_cluster_5min" id="ai_fp_filter_cluster_5min" type="number" style="width:6em;" min="3" max="200" value="<?php print(htmlspecialchars(isset($config['AI_FP_FILTER_CLUSTER_5MIN']) ? $config['AI_FP_FILTER_CLUSTER_5MIN'] : '15', ENT_QUOTES, 'UTF-8'));?>"/>
      <span style="color:#888;font-size:0.85em;">AI checks if N+ same-species detections in last 5 minutes (potential noise event).</span><br>
      <label for="ai_fp_filter_cluster_30min">Cluster trigger — 30 min [5–500]: </label>
      <input name="ai_fp_filter_cluster_30min" id="ai_fp_filter_cluster_30min" type="number" style="width:6em;" min="5" max="500" value="<?php print(htmlspecialchars(isset($config['AI_FP_FILTER_CLUSTER_30MIN']) ? $config['AI_FP_FILTER_CLUSTER_30MIN'] : '30', ENT_QUOTES, 'UTF-8'));?>"/>
      <span style="color:#888;font-size:0.85em;">AI checks if N+ same-species detections in last 30 minutes (persistent cluster).</span><br>
      <br>
      <em>Rate limit caps</em> <span style="color:#888;font-size:0.85em;">(client-side, prevents quota burn)</span><br>
      <label for="ai_fp_rate_limit_rpm_max">Max API requests per minute [1–60]: </label>
      <input name="ai_fp_rate_limit_rpm_max" id="ai_fp_rate_limit_rpm_max" type="number" style="width:6em;" min="1" max="60" value="<?php print(htmlspecialchars(isset($config['AI_FP_RATE_LIMIT_RPM_MAX']) ? $config['AI_FP_RATE_LIMIT_RPM_MAX'] : '8', ENT_QUOTES, 'UTF-8'));?>"/>
      <span style="color:#888;font-size:0.85em;">Skip AI call if hit. Gemini 2.5 Flash free tier limit is 10/min.</span><br>
      <label for="ai_fp_rate_limit_rpd_max">Max API requests per day [10–10000]: </label>
      <input name="ai_fp_rate_limit_rpd_max" id="ai_fp_rate_limit_rpd_max" type="number" style="width:6em;" min="10" max="10000" value="<?php print(htmlspecialchars(isset($config['AI_FP_RATE_LIMIT_RPD_MAX']) ? $config['AI_FP_RATE_LIMIT_RPD_MAX'] : '200', ENT_QUOTES, 'UTF-8'));?>"/>
      <span style="color:#888;font-size:0.85em;">Skip AI call if hit. Gemini 2.5 Flash free tier limit is 250/day.</span><br>
      <br>
      <!-- perchpi:photo-cache-section-v1 -->
      <strong>Photo cache</strong><br>
      <span style="color:#888;font-size:0.9em;">Manually trigger a refresh of all bird photos from Wikipedia. The system also runs this automatically every hour. Use this if photos are missing after a new species was detected and the hourly refresh hasn't run yet.</span><br>
      <button type="button" id="pp-refresh-all-photos-btn" onclick="ppRefreshAllPhotos()" style="padding:0.4em 0.9em;margin-top:0.4em;">&#8635; Refresh all photos now</button>
      <span id="pp-refresh-all-photos-status" style="margin-left:1em;color:#888;font-size:0.9em;"></span>
      <script>
      function ppRefreshAllPhotos() {
        var btn = document.getElementById('pp-refresh-all-photos-btn');
        var stat = document.getElementById('pp-refresh-all-photos-status');
        btn.disabled = true;
        stat.textContent = 'Starting...';
        fetch('refresh_all_photos.php', { method: 'POST', headers: { 'X-CSRF-Token': window.PP_CSRF || '' } }) /* perchpi:s21a-csrf-v1 */
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.ok) {
              stat.textContent = data.message || 'Started';
              setTimeout(function() { btn.disabled = false; stat.textContent = ''; }, 30000);
            } else {
              stat.textContent = 'Error: ' + (data.error || 'unknown');
              btn.disabled = false;
            }
          })
          .catch(function(err) {
            stat.textContent = 'Error: ' + err.message;
            btn.disabled = false;
          });
      }
      </script>
      <br><br>
      <!-- perchpi:geomodel-help-block-v2 -->
      <br>
      BirdNET+ Geomodel V3.0.2 (2024)
      <br><br>
      This is a location-aware spatiotemporal range model that post-filters BirdNET's predictions based on where you are and what time of year it is. When enabled, it filters out species that are unlikely to occur at your latitude/longitude during the current week of the year, dramatically reducing false positives from species that don't actually range in your area. For example, at your location in early May, it correctly identifies Black-capped Chickadee as uncommon (you're at the southern edge of its range) while expecting Carolina Chickadee as the dominant species. Recommended for installations where false-positive reduction matters more than catching every possible vagrant — most users.
      <br>
      <p id="sfhelp" style='display:none'>This value is used by the model to constrain the list of possible species that it will try to detect, given the minimum occurrence frequency. A 0.03 threshold means that for a species to be included in this list, it needs to, on average, be seen on at least 3% of historically submitted eBird checklists for your given lat/lon/current week of year. So, the lower the threshold, the rarer the species it will include.<br><img style='max-width:100%;padding-top:5px;padding-bottom:5px' alt="BirdNET-Pi new model detection flowchart" title="BirdNET-Pi new model detection flowchart" src="images/BirdNET-Pi_nm_flowchart.alpha.png">
        <br>If you'd like to tinker with this threshold value and see which species make it onto the list, <?php if($config['MODEL'] == "BirdNET_6K_GLOBAL_MODEL"){ ?>please click "Update Settings" at the very bottom of this page to install the appropriate label file, then come back here and you'll be able to use the Species List Tester.<?php } else { ?>you can use this tool: <button type="button" class="testbtn" id="openModal">Species List Tester</button><?php } ?></p>
      </span>

<script src="static/dialog-polyfill.js"></script>

<dialog id="modal">
  <div>
    <label for="threshold">Threshold:</label>
    <input type="number" id="threshold" step="0.01" min="0" max="1" value="">
    <button type="button" id="runProcess">Preview Species List</button>
  </div>
  <pre id="output"></pre>
  <button type="button" id="closeModal">Close</button>
</dialog>

<style>
#output {
  max-width: 100vw;
  word-wrap: break-word;
  white-space: pre-wrap;
}
#modal {
  max-height: 80vh;
  overflow-y: auto;
}
#modal div {
  display: flex;
  align-items: center;
}

#modal input[type="number"] {
  height: 32px;
}

#modal button {
  height: 32px;
  margin-left: 5px;
  padding: 0 10px;
  box-sizing: border-box;
}
</style>


<script>
// Get the button and modal elements
const openModalBtn = document.getElementById('openModal');
const modal = document.getElementById('modal');
dialogPolyfill.registerDialog(modal);
const output = document.getElementById('output');
const thresholdInput = document.getElementById('threshold');
const runProcessBtn = document.getElementById('runProcess');
const sfThreshInput = document.getElementsByName('sf_thresh')[0];
const closeModalBtn = document.getElementById('closeModal');


// Add an event listener to the button to open the modal
openModalBtn.addEventListener('click', () => {

  // Set the initial value of the threshold input element
  thresholdInput.value = sfThreshInput.value;

// Show the modal
  modal.showModal();
});

// Add an event listener to the "Preview Species List" button
runProcessBtn.addEventListener('click', () => {

  runProcess();
});

// Add an event listener to the "Close" button
closeModalBtn.addEventListener('click', () => {
  modal.close();
});

// Function to run the process
function runProcess() {
  // Get the value of the threshold input element
  const threshold = thresholdInput.value;

 // Set the output to "Loading..."
  output.innerHTML = "Loading...";

  // Make the AJAX request
  const xhr = new XMLHttpRequest();
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      // Handle the response
      output.innerHTML = xhr.responseText;
    }
  };
  xhr.open('GET', `scripts/config.php?threshold=${threshold}`);
  xhr.send();
}
</script>

      <dl>
      <dt>BirdNET_GLOBAL_6K_V2.4_Model_FP16 (2023)</dt>
      <br>
      <dd id="ddnewline">This is the BirdNET-Analyzer model, the most advanced BirdNET model to date. Currently it  supports over 6,000 species worldwide, giving quite good species coverage for people in most of the world.</dd>
      <br>
      <dt>BirdNET_6K_GLOBAL_MODEL (2020)</dt>
      <br>
      <dd id="ddnewline">This is the BirdNET-Lite model, with bird sound recognition for more than 6,000 species worldwide. This has generally worse performance than the newer models but is kept as a legacy option.</dd>
      <br>
      <dt>[ In-depth technical write-up on the models <a target="_blank" href="https://github.com/mcguirepr89/BirdNET-Pi/wiki/BirdNET-Pi:-some-theory-on-classification-&-some-practical-hints">here</a> ]</dt>
      </dl>
      </td></tr></table><br>

      <table class="settingstable"><tr><td>
      <h2>Location</h2>
      <table class="settingstable plaintable">
        <tr>
          <td><label for="site_name">Site Name:</label></td>
          <td><input name="site_name" type="text" value="<?php print($config['SITE_NAME']);?>"/></td>
          <td>(Optional)</td>
        </tr>
        <tr>
          <td><label for="latitude">Latitude:</label></td>
          <td><input name="latitude" type="number" style="width:12em;" /* perchpi:settings-latlong-width */ max="90" min="-90" step="0.0001" value="<?php print($config['LATITUDE']);?>" required/></td>
        </tr>
        <tr>
          <td><label for="longitude">Longitude: </label></td>
          <td><input name="longitude" type="number" style="width:12em;" max="180" min="-180" step="0.0001" value="<?php print($config['LONGITUDE']);?>" required/></td>
          <td></td>
        </tr>
      </table>
      <p>Set your Latitude and Longitude to 4 decimal places. Get your coordinates <a href="https://latlong.net" target="_blank">here</a>.</p>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2>BirdWeather</h2>
      <label for="birdweather_id">BirdWeather Token: </label>
      <!-- perchpi:birdweather-token-mask --><input id="birdweather_id" name="birdweather_id" type="password" value="<?php print($config['BIRDWEATHER_ID']);?>" /> <button type="button" id="birdweather_id_toggle" onclick="(function(){var f=document.getElementById('birdweather_id');var b=document.getElementById('birdweather_id_toggle');if(f.type==='password'){f.type='text';b.textContent='Hide';}else{f.type='password';b.textContent='Show';}})()">Show</button><br>
           <p><a href="https://app.birdweather.com" target="_blank">BirdWeather.com</a> is a weather map for bird sounds. 
        Stations around the world supply audio and video streams to BirdWeather where they are then analyzed by BirdNET 
        and compared to eBird Grid data. BirdWeather catalogues the bird audio and spectrogram visualizations so that you 
        can listen to, view, and read about birds throughout the world. <br><br> 
        To request a BirdWeather Token, You'll first need to create an account - <a href="https://app.birdweather.com/login" target="_blank">https://app.birdweather.com/</a><br>
        Once that's done - you can go to - <a href="https://app.birdweather.com/account/stations" target="_blank">https://app.birdweather.com/account/stations</a><br>
        Make sure that the Latitude and Longitude match what is in your BirdNET-Pi configuration.
        <br><br>
        <dt>NOTE - by using your BirdWeather Token - you are consenting to sharing your soundscapes and detections with BirdWeather</dt></p>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2>Notifications</h2>
      <p><a target="_blank" href="https://github.com/caronc/apprise/wiki">Apprise Notifications</a> can be setup and enabled for 90+ notification services. Each service should be on its own line.</p>
      <label for="apprise_input">Apprise Notifications Configuration: </label><br>
      <textarea placeholder="mailto://{user}:{password}@gmail.com
tgram://{bot_token}/{chat_id}
twitter://{ConsumerKey}/{ConsumerSecret}/{AccessToken}/{AccessSecret}
https://discordapp.com/api/webhooks/{WebhookID}/{WebhookToken}
..." style="vertical-align: top" class="testbtn" name="apprise_input" rows="5" type="text" ><?php print($apprise_config);?></textarea>
      <dl>
      <dt>$sciname</dt>
      <dd>Scientific Name</dd>
      <dt>$comname</dt>
      <dd>Common Name</dd>
      <dt>$confidence</dt>
      <dd>Confidence Score</dd>
      <dt>$confidencepct</dt>
      <dd>Confidence Score as a percentage (eg. 0.91 => 91)</dd>
      <dt>$listenurl</dt>
      <dd>A link to the detection</dd>
      <dt>$friendlyurl</dt>
      <dd>A masked link to the detection. Only useful for services that support Markdown (e.g. Discord). </dd>
      <dt>$date</dt>
      <dd>Date</dd>
      <dt>$time</dt>
      <dd>Time</dd>
      <dt>$week</dt>
      <dd>Week</dd>
      <dt>$latitude</dt>
      <dd>Latitude</dd>
      <dt>$longitude</dt>
      <dd>Longitude</dd>
      <dt>$cutoff</dt>
      <dd>Minimum Confidence set in "Advanced Settings"</dd>
      <dt>$sens</dt>
      <dd>Sigmoid Sensitivity set in "Advanced Settings"</dd>
      <dt>$overlap</dt>
      <dd>Overlap set in "Advanced Settings"</dd>
      <dt>$image</dt>
      <dd>An image of the detected species from a photo source, see below.</dd>
      <dt>$reason</dt>
      <dd>The reason a notification was sent</dd>
      </dl>
      <p>Use the variables defined above to customize your notification title and body.</p>
      <label for="apprise_notification_title">Notification Title: </label>
      <input name="apprise_notification_title" style="width: 100%" type="text" value="<?php print($config['APPRISE_NOTIFICATION_TITLE']);?>" /><br>
      <label for="apprise_notification_body">Notification Body: </label>
      <textarea class="testbtn" name="apprise_notification_body" rows="5" type="text" ><?php print($apprise_notification_body);?></textarea>
      <input type="checkbox" name="apprise_notify_new_species" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES'] == 1 && filesize($home."/BirdNET-Pi/apprise.txt") != 0) { echo "checked"; };?> >
      <label for="apprise_notify_new_species">Notify each new infrequent species detection (<5 visits per week)</label><br>
      <input type="checkbox" name="apprise_notify_new_species_each_day" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY'] == 1 && filesize($home."/BirdNET-Pi/apprise.txt") != 0) { echo "checked"; };?> >
      <label for="apprise_notify_new_species_each_day">Notify each species first detection of the day</label><br>
      <input type="checkbox" name="apprise_notify_each_detection" <?php if($config['APPRISE_NOTIFY_EACH_DETECTION'] == 1 && filesize($home."/BirdNET-Pi/apprise.txt") != 0) { echo "checked"; };?> >
      <label for="apprise_weekly_report">Notify each new detection</label><br>
      <input type="checkbox" name="apprise_weekly_report" <?php if($config['APPRISE_WEEKLY_REPORT'] == 1 && filesize($home."/BirdNET-Pi/apprise.txt") != 0) { echo "checked"; };?> >
      <label for="apprise_weekly_report">Send <a href="views.php?view=Weekly%20Report"> weekly report</a></label><br>

      <hr>
      <label for="minimum_time_limit">Minimum time between notifications of the same species (sec):</label>
      <input type="number" id="minimum_time_limit" name="minimum_time_limit" value="<?php echo htmlspecialchars($config['APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES'], ENT_QUOTES, 'UTF-8');?>" style="width:6em;" min="0"><br>
      <label for="only_notify_species_names">Exclude these species (comma separated common names):</label>
      <input type="text" id="only_notify_species_names" placeholder="Common names, comma-separated" /* perchpi:settings-placeholders */ name="only_notify_species_names" value="<?php echo htmlspecialchars($config['APPRISE_ONLY_NOTIFY_SPECIES_NAMES'], ENT_QUOTES, 'UTF-8');?>" size=96><br>
      <label for="only_notify_species_names_2">ONLY notify for these species (comma separated common names):</label>
      <input type="text" id="only_notify_species_names_2" placeholder="Common names, comma-separated" name="only_notify_species_names_2" value="<?php echo htmlspecialchars($config['APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2'], ENT_QUOTES, 'UTF-8');?>" size=96><br>

      <br>

      <button type="button" class="testbtn" onclick="sendTestNotification(this)">Send Test Notification</button><br>
      <span id="testsuccessmsg"></span>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2>Localization</h2>
      <label for="language">Database Language: </label>
      <select name="language" class="testbtn">
      <?php
        $langs = array(
          'not-selected' => 'Not Selected',
          "af" => "Afrikaans",
          "ar" => "Arabic",
          "ca" => "Catalan",
          "cs" => "Czech",
          "zh_CN" => "Chinese (simplified)",
          "zh_TW" => "Chinese (traditional)",
          "hr" => "Croatian",
          "da" => "Danish",
          "nl" => "Dutch",
          "en" => "English",
          "et" => "Estonian",
          "fi" => "Finnish",
          "fr" => "French",
          "de" => "German",
          "hu" => "Hungarian",
          "is" => "Icelandic",
          "id" => "Indonesia",
          "it" => "Italian",
          "ja" => "Japanese",
          "ko" => "Korean",
          "lv" => "Latvian",
          "lt" => "Lithuania",
          "no" => "Norwegian",
          "pl" => "Polish",
          "pt" => "Portuguese",
          "ro" => "Romanian",
          "ru" => "Russian",
          "sr" => "Serbian",
          "sk" => "Slovak",
          "sl" => "Slovenian",
          "es" => "Spanish",
          "sv" => "Swedish",
          "th" => "Thai",
          "tr" => "Turkish",
          "uk" => "Ukrainian",
          "vi" => "Vietnamese"
        );

        // Create options for each language
        foreach($langs as $langTag => $langName){
          $isSelected = "";
          if($config['DATABASE_LANG'] == $langTag){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$langTag}' $isSelected>$langName</option>";
        }
      ?>

      </select>
      <p>! Only modify this at initial setup !</p>
      </td></tr></table>
      <br>

      <table class="settingstable"><tr><td>
      <h2>Additional Info </h2>
      <label for="info_site">Site to pull additional species info from: </label>
      <select name="info_site" class="testbtn">
      <?php
        $info_site = array(
          'ALLABOUTBIRDS' => 'allaboutbirds.org',
          "EBIRD" => "ebird.org"
        );

        // Create options for each site
        foreach($info_site as $infoTag => $infoName){
          $isSelected = "";
          if($config['INFO_SITE'] == $infoTag){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$infoTag}' $isSelected>$infoName</option>";
        }
      ?>

      </select>
      <p>allaboutbirds.org default
      <br>ebirds.org has more European species</p>
      </td></tr></table><br>


      <table class="settingstable"><tr><td>
      <h2>Color scheme </h2>
      Note: when changing themes the daily chart may need a page refresh before updating.<br><br>
      <label for="color_scheme">Color scheme for the site : </label>
      <select name="color_scheme" class="testbtn">
      <?php
      $scheme = array("light", "dark");
      foreach($scheme as $color_scheme){
          $isSelected = "";
          if($config['COLOR_SCHEME'] == $color_scheme){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$color_scheme}' $isSelected>$color_scheme</option>";
        }
      ?>
      </td></tr></table><br>
        
      <script>
        function handleChange(checkbox) {
          // this disables the input of manual date and time if the user wants to use the internet time
          var date=document.getElementById("date");
          var time=document.getElementById("time");
          if(checkbox.checked) {
            date.setAttribute("disabled", "disabled");
            time.setAttribute("disabled", "disabled");
          } else {
            date.removeAttribute("disabled");
            time.removeAttribute("disabled");
          }
        }
      </script>
      <?php
      // if NTP service is active, show the checkboxes as checked, and disable the manual input
      $tdc = trim(exec("timedatectl | grep \"NTP service: active\""));
      if (strlen($tdc) > 0) {
        $checkedvalue = "checked";
        $disabledvalue = "disabled";
      } else {
        $checkedvalue = "";
        $disabledvalue = "";
      }
      ?>
      <table class="settingstable"><tr><td>
      <h2>Time and Date</h2>
      <span>If connected to the internet, retrieve time automatically?</span>
      <input type="checkbox" onchange='handleChange(this)' <?php echo $checkedvalue; ?> ><br>
      <?php
      $date = new DateTime('now');
      ?>
      <input onclick="this.showPicker()" type="date" id="date" name="date" value="<?php echo $date->format('Y-m-d') ?>" <?php echo $disabledvalue; ?>>
      <input onclick="this.showPicker()" type="time" id="time" name="time" value="<?php echo $date->format('H:i'); ?>" <?php echo $disabledvalue; ?>><br>
      <br>
      <label for="timezone">Select a Timezone: </label>
      <select name="timezone" class="testbtn">
      <option disabled selected>
        Select a timezone
      </option>
      <?php
      $current_timezone = trim(shell_exec("timedatectl show --value --property=Timezone"));
      $timezone_identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
        
      $n = 425;
      for($i = 0; $i < $n; $i++) {
          $isSelected = "";
          if($timezone_identifiers[$i] == $current_timezone) {
            $isSelected = 'selected="selected"';
          }
          echo "<option $isSelected value='".$timezone_identifiers[$i]."'>".$timezone_identifiers[$i]."</option>";
      }
      ?>
      </select>
      </td></tr></table><br>

      <br><br>

      <input type="hidden" name="status" value="success">
      <input type="hidden" name="submit" value="settings">
<div class="float">
      <button type="submit" id="basicformsubmit" onclick="if(document.getElementById('basicform').checkValidity()){this.innerHTML = 'Updating... please wait.';this.classList.add('disabled')}" name="view" value="Settings">
<?php
if(isset($_GET['status'])){
  echo '<script>alert("Settings successfully updated");</script>';
}
echo "Update Settings";
?>
      </button></div>
      </form>
      <form action="" method="GET">
      <div class="float">
        <button type="submit" name="view" value="Advanced">Advanced Settings</button>
      </div></form>
</div>
