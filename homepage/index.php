<?php
/* ============================================================================
   BirdNET-Pi / PerchPi — index.php (v2.2.2)
   ============================================================================
   Adds weather widget to header (matching the kiosk's weather widget) and
   injects PERCHPI config (lat/lon) for client-side weather fetch.

   Build: Phase C-2.2.2 — Tools menu cleanup (HIDDEN 20260503)
   Hidden 9 of 12 gear-menu items that pointed to upstream BirdNET-Pi pages
   blocked by ensure_authenticated(). Kept: System Info, File Manager,
   Database Maintenance. Parking-lot: rebuild PerchPi-native versions of
   Settings, Species Lists, etc.
   ============================================================================ */

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (strpos($requestUri, '/api/v1/') === 0) {
  include_once 'scripts/api.php';
  die();
}

$_GET  = array_map(function($v) { return is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v; }, $_GET ?: []);
$_POST = array_map(function($v) { return is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v; }, $_POST ?: []);

require_once 'scripts/common.php';
csrf_guard_keys(['submit', 'add', 'del', 'latitude', 'run_species_count']); // perchpi:s21a-csrf-v1
$config     = get_config();
$site_name  = get_sitename();
set_timezone();

$site_name_display = htmlspecialchars_decode($site_name, ENT_QUOTES);
$current_view = isset($_GET['view']) ? htmlspecialchars_decode($_GET['view'], ENT_QUOTES) : 'Overview';

// Lat/lon for weather widget — fall back to 0,0 (Null Island) if not configured.
// This makes unconfigured installs visibly broken so users know to set their coords.
$lat = isset($config['LATITUDE'])  ? (float)$config['LATITUDE']  : 0.0;
$lon = isset($config['LONGITUDE']) ? (float)$config['LONGITUDE'] : 0.0;
// perchpi:wu-weather-inject-v1 — weather source flag for header widget
$weather_source = isset($config['WEATHER_SOURCE']) ? $config['WEATHER_SOURCE'] : 'open-meteo';
?>
<!DOCTYPE html>
<html lang="en" class="theme-admin">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#000000">
  <meta name="csrf-token" content="<?php echo csrf_token(); ?>"><!-- perchpi:s21a-csrf-v1 -->
  <title><?php echo $site_name_display; ?> — BirdNET-Pi</title>
  <link rel="shortcut icon" sizes="85x85" href="images/bird.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="perchpi.css?v=<?php echo @filemtime(__DIR__ . '/perchpi.css') ?: time(); ?>">
  <link rel="stylesheet" type="text/css" href="static/dialog-polyfill.css">

  <script>
    window.PP_CSRF = <?php echo json_encode(csrf_token()); ?>; // perchpi:s21a-csrf-v1
    window.PERCHPI = {
      lat: <?php echo json_encode($lat); ?>,
      lon: <?php echo json_encode($lon); ?>,
      siteName: <?php echo json_encode($site_name_display); ?>,
      weatherSource: <?php echo json_encode($weather_source); /* perchpi:wu-weather-inject-v1 */ ?>
    };
  </script>
</head>
<body>

<div class="app">

  <?php /* perchpi:get-config-loud-v1 — visible banner if birdnet.conf parse fails */ ?>
  <?php if (function_exists('perchpi_render_config_error_banner')) perchpi_render_config_error_banner(); ?>


  <!-- ================ HEADER ================ -->
  <header class="app-header" role="banner">
    <div class="header-bar">
      <div class="header-left">
        <button class="sidebar-toggle" aria-label="Toggle navigation menu" type="button">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
        </button>
        <a href="index.php" class="header-brand">
          <span class="header-brand-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2L13.09 8.26L22 9L17 14L18.18 21L12 17.77L5.82 21L7 14L2 9L10.91 8.26L12 2Z"/>
            </svg>
          </span>
          <span>BirdNET-Pi</span>
        </a>
        <?php if (!empty($site_name_display) && $site_name_display !== 'birdnetpi'): ?>
          <span class="header-site"><?php echo $site_name_display; ?></span>
        <?php endif; ?>
      </div>

      <div class="header-meta">
        <!-- Weather widget — fetched client-side from Open-Meteo -->
        <div class="header-weather" id="header-weather">
          <span class="weather-icon" id="weather-icon">—</span>
          <span class="weather-temp" id="weather-temp">—</span>
          <span class="weather-meta" id="weather-meta"></span>
        </div>
        <div class="header-meta-item">
          <span data-clock="full">…</span>
        </div>
      </div>

      <div class="header-actions">
        <div class="dropdown">
          <button class="btn btn--secondary btn--icon" data-dropdown-trigger aria-label="Tools menu" type="button">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="3"></circle>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
          </button>
          <div class="dropdown-menu" role="menu">
            <div class="dropdown-section">System</div>
            <a href="?view=Settings" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">⚙</span><span>Settings</span>
            </a>
            <a href="?view=Advanced" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">🔬</span><span>Advanced Settings</span>
            </a>
            <a href="?view=System Info" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">📋</span><span>System Info</span>
            </a>
            <a href="?view=System Controls" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">⚡</span>
              <span>System Controls</span>
              <?php if (isset($_SESSION['behind']) && intval($_SESSION['behind']) >= 50 && ($config['SILENCE_UPDATE_INDICATOR'] != 1)): ?>
                <span class="nav-update-badge"><?php echo intval($_SESSION['behind']); ?></span>
              <?php endif; ?>
            </a>
            <a href="?view=Services" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">🔧</span><span>Services</span>
            </a>

            <div class="dropdown-divider"></div>
            <div class="dropdown-section">Tools</div>
            <!-- perchpi:s21a-d9-v1 (D9): File Manager and Adminer are not shipped. -->
            <a href="?view=Webterm" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">▣</span><span>Web Terminal</span>
            </a>

            <div class="dropdown-divider"></div>
            <div class="dropdown-section">Species</div>
            <a href="?view=Included" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">📋</span><span>Custom Species List</span>
            </a>
            <a href="?view=Excluded" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">🚫</span><span>Excluded Species List</span>
            </a>
            <a href="?view=Whitelisted" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">✅</span><span>Whitelist Species List</span>
            </a>
            <a href="?view=Species Management" class="dropdown-item" role="menuitem">
              <span class="dropdown-item-icon">🦜</span><span>Species Management</span>
            </a>
          </div>
      </div>
    </div>
  </header>

  <!-- ================ SIDEBAR ================ -->
  <div class="sidebar-backdrop" aria-hidden="true"></div>

  <aside class="app-sidebar" aria-label="Main navigation">
    <nav class="nav-sidebar">

      <div class="nav-section">Activity</div>

      <a href="?view=Overview" class="nav-item" data-nav-view="Overview">
        <span class="nav-item-icon">⌂</span>
        <span class="nav-item-label">Overview</span>
      </a>

      <a href="?view=Birds Today" class="nav-item" data-nav-view="Birds Today">
        <span class="nav-item-icon">🦜</span>
        <span class="nav-item-label">Birds Today</span>
      </a>

      <!-- perchpi:allspecies-nav-v1 -->
      <a href="?view=All Species" class="nav-item" data-nav-view="All Species">
        <span class="nav-item-icon">📋</span>
        <span class="nav-item-label">All Species</span>
      </a>

      <a href="?view=Spectrogram" class="nav-item" data-nav-view="Spectrogram">
        <span class="nav-item-icon">📊</span>
        <span class="nav-item-label">Spectrogram</span>
      </a>

      <div class="nav-section">Library</div>

      <a href="?view=Best Recordings" class="nav-item" data-nav-view="Best Recordings">
        <span class="nav-item-icon">⭐</span>
        <span class="nav-item-label">Best Recordings</span>
      </a>

      <a href="?view=Recordings" class="nav-item" data-nav-view="Recordings">
        <span class="nav-item-icon">🎵</span>
        <span class="nav-item-label">Recordings</span>
      </a>
      <a href="?view=Species Stats" class="nav-item" data-nav-view="Species Stats">
        <span class="nav-item-icon">🔍</span>
        <span class="nav-item-label">Species Stats</span>
      </a>
      <!-- perchpi:quarantine-nav-v1 -->
      <?php /* perchpi:quarantine-pulse-data-v1 */
        $pp_latest_q = '';
        try {
          $pp_q_db = new SQLite3('/home/birder/BirdNET-Pi/scripts/birds.db', SQLITE3_OPEN_READONLY);
          $pp_q_res = $pp_q_db->querySingle('SELECT MAX(quarantined_at) FROM quarantined_detections');
          if ($pp_q_res) $pp_latest_q = $pp_q_res;
          $pp_q_db->close();
        } catch (Exception $e) { /* fail open, no flash */ }
      ?>
      <a href="?view=Quarantine" class="nav-item" data-nav-view="Quarantine" data-latest-quarantine="<?= htmlspecialchars($pp_latest_q) ?>">
        <span class="nav-item-icon">🚫</span>
        <span class="nav-item-label">Quarantine</span>
      </a>
      <!-- perchpi:notable-sightings-v1 — moved to Overview banner (perchpi:notable-banner-v1) -->

      <div class="nav-group">
        <div class="nav-item" role="button" tabindex="0">
          <span class="nav-item-icon">📈</span>
          <span class="nav-item-label">Charts</span>
          <span class="nav-item-toggle">▶</span>
        </div>
        <div class="nav-children">
          <a href="?view=Daily Charts" class="nav-item" data-nav-view="Daily Charts">
            <span class="nav-item-label">Daily Charts</span>
          </a>
          <a href="?view=Weekly Report" class="nav-item" data-nav-view="Weekly Report">
            <span class="nav-item-label">Weekly Report</span>
          </a>
          <a href="?view=Streamlit" class="nav-item" data-nav-view="Detection Patterns">
            <span class="nav-item-label">Detection Patterns</span>
          </a>
        </div>
      </div>

      <div class="nav-section">Live</div>


      <!-- perchpi:live-audio-nav-v1 -->
      <a href="?view=Live Audio" class="nav-item" data-nav-view="Live Audio">
        <span class="nav-item-icon">🎙</span>
        <span class="nav-item-label">Live Audio</span>
      </a>

      <a href="?view=View Log" class="nav-item" data-nav-view="View Log">
        <span class="nav-item-icon">📜</span>
        <span class="nav-item-label">View Log</span>
      </a>

    </nav>
  </aside>

  <!-- ================ MAIN ================ -->
  <main class="app-main" role="main">
    <?php
    if (!isset($config["LATITUDE"]) || !isset($config["LONGITUDE"]) ||
        (floatval($config["LATITUDE"]) == 0.0 && floatval($config["LONGITUDE"]) == 0.0)) {
      echo '<div class="badge badge--warn" style="margin-bottom: var(--space-4); display: block; padding: var(--space-3); text-align: center;">⚠ Latitude and longitude are not set. Please update them in Tools → Settings.</div>';
    }

    include 'views.php';
    ?>
  </main>

</div>

<script src="static/dialog-polyfill.js"></script>
<!-- perchpi:audio-normalize-bridge-v1 -->
<script src="pp-audio.js?v=<?php echo @filemtime(__DIR__ . '/pp-audio.js') ?: time(); ?>"></script>
<script src="perchpi.js?v=<?php echo @filemtime(__DIR__ . '/perchpi.js') ?: time(); ?>"></script>

</body>
</html>
