<?php

define('__ROOT__', dirname(dirname(__FILE__)));

if (session_status() !== PHP_SESSION_ACTIVE)
  session_start();

function ensure_db_ok($sql_stmt) {
  if ($sql_stmt == False) {
    echo "Database is busy";
    header("refresh:1;");
    exit;
  }
}

function set_timezone() {
  if (!isset($_SESSION['my_timezone'])) {
    $_SESSION['my_timezone'] = trim(shell_exec('timedatectl show --value --property=Timezone'));
  }
  date_default_timezone_set($_SESSION['my_timezone']);
}

function get_config($force_reload = false) {
  // perchpi:get-config-loud-v1 — louder error reporting on parse failure
  $mtime = @stat('/etc/birdnet/birdnet.conf')['mtime'] ?? 0;
  if (isset($_SESSION['my_config_version']) && $_SESSION['my_config_version'] !== $mtime) {
    $force_reload = true;
  }
  if (!isset($_SESSION['my_config']) || $force_reload) {
    $contents = @file_get_contents('/etc/birdnet/birdnet.conf');
    if ($contents === false) {
      $msg = 'Cannot read /etc/birdnet/birdnet.conf';
      syslog(LOG_ERR, "PerchPi config: $msg");
      @error_log("[" . date('c') . "] PerchPi: $msg\n", 3, '/var/log/perchpi.log');
      $_SESSION['my_config_error'] = $msg;
      if (!isset($_SESSION['my_config'])) $_SESSION['my_config'] = [];
      return $_SESSION['my_config'];
    }
    $source = preg_replace("~^#+.*$~m", "", $contents);
    $my_config = @parse_ini_string($source);
    if ($my_config) {
      $_SESSION['my_config'] = $my_config;
      $_SESSION['my_config_error'] = null;
    } else {
      $err = error_get_last();
      $msg = $err['message'] ?? 'parse_ini_string returned false';
      syslog(LOG_ERR, "PerchPi config parse failed: $msg");
      @error_log("[" . date('c') . "] PerchPi: birdnet.conf parse failed — $msg\n", 3, '/var/log/perchpi.log');
      $_SESSION['my_config_error'] = $msg;
      if (!isset($_SESSION['my_config'])) $_SESSION['my_config'] = [];
    }
    $_SESSION['my_config_version'] = $mtime;
  }
  return $_SESSION['my_config'];
}

// perchpi:get-config-loud-v1 — helper to render the parse-error banner.
// Call from any view by adding this line to its template: perchpi_render_config_error_banner();
// Banner stays hidden when config is valid.
function perchpi_render_config_error_banner() {
  if (!empty($_SESSION['my_config_error'])) {
    echo '<div style="margin: var(--space-4, 16px); padding: var(--space-3, 12px); background: #c62828; color: #fff; border-radius: 6px; text-align: center; font-weight: 500;">'
       . '⚠ <strong>birdnet.conf parse error:</strong> '
       . htmlspecialchars($_SESSION['my_config_error'])
       . '<br><small style="opacity: 0.85;">PerchPi is using stale or default config values. Check <code>/var/log/perchpi.log</code> and run <code>birdnet_conf_edit</code> to fix.</small>'
       . '</div>';
  }
}

function get_user() {
  $config = get_config();
  $user = $config['BIRDNET_USER'];
  return $user;
}

function get_home() {
  $home = '/home/' . get_user();
  return $home;
}

function get_sitename() {
  $config = get_config();

  if ($config["SITE_NAME"] == "") {
    $site_name = "BirdNET-Pi";
  } else {
    $site_name = $config['SITE_NAME'];
  }
  return $site_name;
}

function get_service_mount_name() {
  $home = get_home();
  $service_mount = trim(shell_exec("systemd-escape -p --suffix=mount " . $home . "/BirdSongs/StreamData"));
  return $service_mount;
}

function is_authenticated() {
  $ret = false;
  if (isset($_SERVER['PHP_AUTH_USER'])) {
    $config = get_config();
    /* STAGE-2 S2-1 (perchpi:installer-s19a-auth-fail-closed-v1): a conf PHP can't parse
       leaves CADDY_PWD unset, and '' == null let an EMPTY password in. Fail closed:
       the stored password must be non-empty, and compare in constant time. */
    $pwd = isset($config['CADDY_PWD']) ? (string)$config['CADDY_PWD'] : '';
    $ret = ($pwd !== '' && $_SERVER['PHP_AUTH_USER'] === 'birdnet'
            && hash_equals($pwd, (string)($_SERVER['PHP_AUTH_PW'] ?? '')));
  }
  return $ret;
}

function ensure_authenticated($error_message = 'You cannot edit the settings for this installation') {
  if (!is_authenticated()) {
    header('WWW-Authenticate: Basic realm="My Realm"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<table><tr><td>' . $error_message . '</td></tr></table>';
    exit;
  }
}

/* perchpi:s21a-csrf-v1 -- CSRF (stage-2 §4). One token per session. Every state change is a
   POST carrying it: forms use csrf_field(), fetch()/XHR send the X-CSRF-Token header from
   window.PP_CSRF. Basic auth has no SameSite protection, so without this any page the owner
   visits could fire these requests with the browser's cached password. */
function csrf_token() {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  if (empty($_SESSION['pp_csrf']) || !is_string($_SESSION['pp_csrf'])) {
    $_SESSION['pp_csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['pp_csrf'];
}

function csrf_field() {
  return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_fail($why) {
  if (!headers_sent()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
  }
  echo 'Forbidden: ' . $why . ' Reload the page and try again.';
  exit;
}

function csrf_check() {
  $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
  if ($origin !== '') {
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    if ($host === '' || (strcasecmp($origin, 'http://' . $host) !== 0
                         && strcasecmp($origin, 'https://' . $host) !== 0)) {
      csrf_fail('cross-site request (CSRF).');
    }
  }
  $want = isset($_SESSION['pp_csrf']) ? (string)$_SESSION['pp_csrf'] : '';
  $sent = isset($_POST['csrf']) ? $_POST['csrf']
        : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
  if ($want === '' || !is_string($sent) || !hash_equals($want, $sent)) {
    csrf_fail('missing or invalid CSRF token.');
  }
}

/* Legacy handlers read $_GET. A request carrying any of $keys must be a POST with a valid
   token; after the check the POST fields are merged into $_GET, so the handler is unchanged. */
function csrf_guard_keys(array $keys) {
  foreach ($keys as $k) {
    if (isset($_GET[$k]) || isset($_POST[$k])) {
      if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        if (!headers_sent()) {
          http_response_code(405);
          header('Allow: POST');
        }
        echo 'This action needs a POST with a CSRF token.';
        exit;
      }
      csrf_check();
      $_GET = array_merge($_GET, $_POST);
      return;
    }
  }
}

/* perchpi:s21a2-d1-v1 (D1): change the web password through the root-owned helper. The
   password goes on stdin, never argv. True only when Caddy took it (validated + reloaded). */
function pp_caddy_pwd($pwd) {
  $p = proc_open(['/usr/bin/sudo', '-n', '/usr/local/sbin/perchpi-caddy-pwd'],
                 [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($p)) {
    return false;
  }
  fwrite($pipes[0], $pwd . "\n");
  fclose($pipes[0]);
  stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  $err = stream_get_contents($pipes[2]);
  fclose($pipes[2]);
  $rc = proc_close($p);
  if ($rc !== 0) {
    syslog(LOG_ERR, 'perchpi-caddy-pwd failed (rc ' . $rc . '): ' . trim((string)$err));
  }
  return $rc === 0;
}

function debug_log($message) {
  if (is_bool($message)) {
    $message = $message ? 'true' : 'false';
  }
  error_log($message . "\n", 3, $_SERVER['DOCUMENT_ROOT'] . "/debug_log.log");
}

function get_com_en_name($sci_name) {
  if (!isset($_labels_flickr)) {
    $_labels_flickr = json_decode(file_get_contents(get_home() . "/BirdNET-Pi/model/l18n/labels_en.json"), true);
  }
  $engname = $_labels_flickr[$sci_name];
  return $engname;
}

function get_label($record, $sort_by, $date=null) {
  $name = $record["Com_Name"];
  if ($sort_by == "confidence") {
    $ret = $name . ' (' . round($record['MaxConfidence'] * 100) . '%)';
  } elseif ($sort_by == "occurrences") {
    $valuescount = $record['Count'];
    if ($valuescount >= 1000) {
      $ret = $name . ' (' . round($valuescount / 1000, 1) . 'k)';
    } else {
      $ret = $name . ' (' . $valuescount . ')';
    }
  } elseif (($sort_by == "date") && !isset($date)) {
    $ret = $name . ' (' . $record['Date'] . ')';
  } elseif (($sort_by == "date") && isset($date)) {
    $ret = $name . ' (' . $record['Time'] . ')';
  } else {
    $ret = $name;
  }
  return $ret;
}

function get_db() {
  if (!isset($_db)) {
    $_db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
    $_db->busyTimeout(1000);
  }
  return $_db;
}

function fetch_species_array($sort_by, $date=null) {
  $db = get_db();
  // perchpi:quarantine-filter-v1 -- always exclude quarantined detections from per-species lists
  $quarantine_filter = "detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections)";
  $where = (isset($date))
    ? "WHERE Date == :date AND $quarantine_filter"
    : "WHERE $quarantine_filter";
  if ($sort_by === "occurrences") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY COUNT(*) DESC");
  } elseif ($sort_by === "confidence") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY MAX(Confidence) DESC");
  } elseif ($sort_by === "date") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY MIN(Date) DESC, Time DESC");
  } else {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY Com_Name ASC");
  }
  ensure_db_ok($statement);
  if (isset($date)) { $statement->bindValue(':date', $date, SQLITE3_TEXT); }
  $result = $statement->execute();
  return $result;
}

function fetch_best_detection($com_name) {
  $db = get_db();
  $statement = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*), MAX(Confidence), File_Name, Date, Time from detections WHERE Com_Name = :com AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */");
  ensure_db_ok($statement);
  $statement->bindValue(':com', $com_name, SQLITE3_TEXT);
  $result = $statement->execute();
  return $result;
}

function fetch_all_detections($sci_name, $sort_by, $date=null) {
  $db = get_db();
  // perchpi:quarantine-filter-v1
  $filter = (isset($date))
    ? "AND Date == :date AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections)"
    : "AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections)";
  if ($sort_by === "occurrences") {
    $statement = $db->prepare("SELECT * FROM detections WHERE Sci_Name == :sci $filter ORDER BY COUNT(*) DESC");
  } elseif ($sort_by === "confidence") {
    $statement = $db->prepare("SELECT * FROM detections WHERE Sci_Name == :sci $filter ORDER BY Confidence DESC");
  } else {
    $order = (isset($date)) ? "Time DESC" : "Date DESC, Time DESC";
    $statement = $db->prepare("SELECT * FROM detections where Sci_Name == :sci $filter ORDER BY $order");
  }
  ensure_db_ok($statement);
  $statement->bindValue(':sci', $sci_name, SQLITE3_TEXT);
  if (isset($date)) { $statement->bindValue(':date', $date, SQLITE3_TEXT); }
  $result = $statement->execute();
  return $result;
}

function get_summary() {
  $db = get_db();
  $statement = $db->prepare('SELECT COUNT(*) FROM detections');
  ensure_db_ok($statement);
  $result = $statement->execute();
  $totalcount = $result->fetchArray(SQLITE3_ASSOC);

  $statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')');
  ensure_db_ok($statement2);
  $result2 = $statement2->execute();
  $todaycount = $result2->fetchArray(SQLITE3_ASSOC);

  $statement3 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == Date(\'now\', \'localtime\') AND TIME >= TIME(\'now\', \'localtime\', \'-1 hour\')');
  ensure_db_ok($statement3);
  $result3 = $statement3->execute();
  $hourcount = $result3->fetchArray(SQLITE3_ASSOC);

  $statement5 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE Date == Date(\'now\',\'localtime\')');
  ensure_db_ok($statement5);
  $result5 = $statement5->execute();
  $todayspeciestally = $result5->fetchArray(SQLITE3_ASSOC);

  $statement6 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections');
  ensure_db_ok($statement6);
  $result6 = $statement6->execute();
  $totalspeciestally = $result6->fetchArray(SQLITE3_ASSOC);

  $ret = [
    'totalcount' => $totalcount['COUNT(*)'],
    'todaycount' => $todaycount['COUNT(*)'],
    'hourcount' => $hourcount['COUNT(*)'],
    'speciestally' => $todayspeciestally['COUNT(DISTINCT(Sci_Name))'],
    'totalspeciestally' => $totalspeciestally['COUNT(DISTINCT(Sci_Name))']
  ];
  return $ret;
}

class ImageProvider {

  protected $db = null;
  protected $db_path = null;
  protected $db_reset = false;
  protected $context = null;

  public function __construct() {
    $this->set_db();
    $opts = ['http' => ['header' => "User-Agent: BirdNET-Pi"]];
    $this->context = stream_context_create($opts);
  }

  public function get_image($sci_name) {
    $image = $this->get_image_from_db($sci_name);
    if ($image !== false) {
      $now = new DateTime();
      $datetime = DateTime::createFromFormat("Y-m-d", $image['date_created']);
      $interval = $now->diff($datetime);
      $expire_days = rand(15, 25);
      if ($interval->days > $expire_days) {
        $image = false;
      }
    }
    if ($image === false) {
      $this->get_from_source($sci_name);
      $image = $this->get_image_from_db($sci_name);
    }
    return $image;
  }

  public function is_reset() {
    return $this->db_reset;
  }

  protected function get_json($url) {
    return json_decode(file_get_contents($url, false, $this->context), true);
  }

  protected function set_db() {
    try {
      if ($this->db === null) {
        $db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE);
        $this->db = $db;
      }
    } catch (Exception $ex) {
      $this->create_tables();
    }
    $this->db->busyTimeout(1000);
  }

  protected function create_tables() {
    $tbl_def = "CREATE TABLE images (sci_name VARCHAR(63) NOT NULL PRIMARY KEY, com_en_name VARCHAR(63) NOT NULL, image_url TEXT NOT NULL, title TEXT NOT NULL, id TEXT NOT NULL UNIQUE, author_url TEXT NOT NULL, license_url TEXT NOT NULL, date_created DATE)";
    $db = new SQLite3($this->db_path);
    $db->exec($tbl_def);
    $db->exec('CREATE TABLE source (ID INTEGER PRIMARY KEY, email VARCHAR(63), uid VARCHAR(63), date_created DATE)');
    $this->db_reset = true;
    $this->db = $db;
  }

  protected function delete_image_from_db($sci_name) {
    $statement0 = $this->db->prepare('DELETE FROM images WHERE sci_name == :sci_name');
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->execute();
  }

  protected function get_image_from_db($sci_name) {
    $statement0 = $this->db->prepare('SELECT sci_name, com_en_name, image_url, title, id, author_url, license_url, date_created FROM images WHERE sci_name == :sci_name');
    $statement0->bindValue(':sci_name', $sci_name);
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  protected function set_image_in_db($sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url) {
    $statement0 = $this->db->prepare("INSERT OR REPLACE INTO images VALUES (:sci_name, :com_en_name, :image_url, :title, :id, :author_url, :license_url, DATE(\"now\"))");
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->bindValue(':com_en_name', $com_en_name);
    $statement0->bindValue(':image_url', $image_url);
    $statement0->bindValue(':title', $title);
    $statement0->bindValue(':id', $id);
    $statement0->bindValue(':author_url', $author_url);
    $statement0->bindValue(':license_url', $license_url);
    $statement0->execute();
  }
}

// perchpi:flickr-strip-wave2-v1 — Flickr class removed. Wikipedia is the
// only image provider in PerchPi. This stub catches any unreached call site
// with a loud error instead of "Class not found". Safe to delete entirely
// once we have confidence no code path instantiates it.
class Flickr extends ImageProvider {
  public function __construct() {
    throw new Exception("Flickr provider removed in PerchPi (perchpi:flickr-strip-wave2-v1). Set IMAGE_PROVIDER=WIKIPEDIA in /etc/birdnet/birdnet.conf");
  }
  protected function get_from_source($sci_name) {}
}

class Wikipedia extends ImageProvider {

  protected $db_path = __ROOT__ . '/scripts/wikipedia.db';

  protected function get_from_source($sci_name) {
    $page_title = str_replace(' ', '_', $sci_name);
    $data = $this->get_json("https://en.wikipedia.org/api/rest_v1/page/summary/$page_title");
    if ($data == false or !isset($data['originalimage']))
      return;

    $image_name = substr($data['originalimage']['source'], strrpos($data['originalimage']['source'], '/') + 1);
    $metadata = $this->get_json("https://commons.wikimedia.org/w/api.php?action=query&titles=File:$image_name&prop=imageinfo&iiprop=extmetadata|size&format=json");
    if ($metadata == false or !isset($metadata['query']['pages']))
      return;

    $image_url = $data['originalimage']['source'];
    $title = $data['title'];

    foreach ($metadata['query']['pages'] as $page) {
      $details = $page['imageinfo']['0']['extmetadata'];
      $author = $details['Artist']['value'];
      $matches = [];
      if (preg_match('/href="(http\S*)"/', $author, $matches)) {
        $author_url = $matches[1];
      } else {
        $author_url = $this->get_external_link($image_url);
      }
      if (isset($details['LicenseUrl'])) {
        $license_url = $details['LicenseUrl']['value'];
      } else {
        $license_url = $this->get_external_link($image_url);
      }
      if ($page["imageinfo"][0]["width"] > 1280) {
        $image_url = preg_replace('#/commons/#', '/commons/thumb/', $image_url) . '/1280px-'. $image_name;
      }
    }

    $engname = get_com_en_name($sci_name);

    //                     $sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url
    $this->set_image_in_db($sci_name, $engname, $image_url, $title, $sci_name, $author_url, $license_url);
  }

  public function get_image($sci_name) {
    $image = parent::get_image($sci_name);
    if ($image === false)
      return false;

    $image['photos_url'] = $this->get_external_link($image['image_url']);
    return $image;
  }

  private function get_external_link($image_url) {
    if (strpos($image_url, '/commons/thumb/') !== false) {
      $parts = explode('/', $image_url);
      $image_name = $parts[count($parts) - 2];
    } else {
      $image_name = substr($image_url, strrpos($image_url, '/') + 1);
    }
    $photo_url = "https://en.wikipedia.org/wiki/File:$image_name";
    return $photo_url;
  }
}

function get_info_url($sciname){
  $engname = get_com_en_name($sciname);
  $config = get_config();
  if ($config['INFO_SITE'] === 'EBIRD'){
    require 'scripts/ebird.php';
    $ebird = $ebirds[$sciname];
    $language = $config['DATABASE_LANG'];
    $url = "https://ebird.org/species/$ebird?siteLanguage=$language";
    $url_title = "eBirds";
  } else {
    $engname_url = str_replace("'", '', str_replace(' ', '_', $engname));
    $url = "https://allaboutbirds.org/guide/$engname_url";
    $url_title = "All About Birds";
  }
  $ret = array(
      'URL' => $url,
      'TITLE' => $url_title
          );
  return $ret;
}

function get_color_scheme(){
  $config = get_config();
  if (strtolower($config['COLOR_SCHEME']) === 'dark'){
    return 'static/dark-style.css';
  } else {
    return 'style.css';
  }
}

// perchpi:nn-archived-badge-v1 ── archived-state badge helper
// Returns an HTML <span> with a hover tooltip when the row has been
// mirrored to a long-term archive (archived_at column set), else "".
function pp_render_archived_badge($row) {
  if (empty($row['archived_at'])) return '';
  $when  = htmlspecialchars((string)$row['archived_at'], ENT_QUOTES);
  $where = (string)($row['archive_location'] ?? '');
  $where_label = $where === 'unas' ? 'UNAS' : ($where === '' ? 'archive' : strtoupper($where));
  $title = "Archived to {$where_label} on {$when}";
  $title_attr = htmlspecialchars($title, ENT_QUOTES);
  return '<span class="pp-archived-badge" title="' . $title_attr
       . '" aria-label="' . $title_attr . '">📦</span>';
}
