<?php
/**
 * eBird Life List Importer for BirdNET-Pi
 * --------------------------------------------------------------------
 * Allows users to upload an eBird "My eBird" CSV export and merge those
 * species into the BirdNET-Pi detections database, so the kiosk and
 * original UI show a unified lifetime species list.
 *
 * Workflow:
 *   1. GET   /views.php?view=eBird+Import  -> upload form
 *   2. POST with $_POST['action']='preview' + uploaded file -> preview
 *   3. POST with $_POST['action']='commit'  + cached preview -> insert
 *
 * Storage strategy (per design discussion):
 *   - Insert ONE row per new species into the existing `detections` table
 *   - Date = earliest eBird date for that species
 *   - File_Name = "ebird_import_<SubID>" so imports are identifiable
 *   - Confidence = 1.0
 *   - Dedup via Sci_Name (canonical), skip species already in detections
 *   - Re-import safe: rerunning skips species already imported
 *
 * Authentication: relies on ensure_authenticated() from the parent
 * views.php scope, which is invoked by the route entry.
 */

// Load BirdNET-Pi config helpers
require_once __DIR__ . '/common.php';

/* perchpi:s21a-auth-plain-call-v1 -- SHIP-WITH #7: the old function_exists() guard ran before
   common.php was loaded, so a direct /scripts/ebird_import.php request skipped
   the PHP check. Load common.php first, then always check (fail closed). */
ensure_authenticated();

$home    = "/home/birder";
$db_path = $home . '/BirdNET-Pi/scripts/birds.db';
$config  = get_config();

$lat        = isset($config['LATITUDE'])   ? floatval($config['LATITUDE'])   : 0.0;
$lon        = isset($config['LONGITUDE'])  ? floatval($config['LONGITUDE'])  : 0.0;
$cutoff     = isset($config['CONFIDENCE']) ? floatval($config['CONFIDENCE']) : 0.5;
$sens       = isset($config['SENSITIVITY']) ? floatval($config['SENSITIVITY']) : 1.25;
$overlap    = isset($config['OVERLAP'])    ? floatval($config['OVERLAP'])    : 0.0;

// Path for the temporary preview cache (between preview and commit)
$session_dir = sys_get_temp_dir() . '/birdnet_ebird_import';
if (!is_dir($session_dir)) @mkdir($session_dir, 0700, true);

// Use the session's PHP session id (or fallback to a cookie) to keep
// preview state per-user across the two requests.
if (session_status() === PHP_SESSION_NONE) @session_start();
$session_token = session_id() ?: bin2hex(random_bytes(8));
$preview_cache = $session_dir . '/' . preg_replace('/[^a-zA-Z0-9]/', '', $session_token) . '.json';

// ---------- Helper functions ----------

/**
 * Parse an eBird "My eBird" CSV.
 * Expected columns: Row #, Taxon Order, Category, Common Name, Scientific Name,
 *                   Count, Location, S/P, Date, LocID, SubID, Exotic, Countable
 * Returns an array of associative rows.
 *
 * Tolerant to extra/missing columns and BOM at start of file.
 */
function parse_ebird_csv($path) {
    $fh = @fopen($path, 'r');
    if (!$fh) return ['error' => 'Cannot open uploaded file.'];
    // Strip UTF-8 BOM if present
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    $header = fgetcsv($fh, 0, ",", "\"", "");
    if (!$header) {
        fclose($fh);
        return ['error' => 'CSV appears to be empty.'];
    }
    $idx = [];
    foreach ($header as $i => $h) {
        $idx[strtolower(trim($h))] = $i;
    }
    $required = ['common name', 'scientific name', 'date'];
    foreach ($required as $r) {
        if (!isset($idx[$r])) {
            fclose($fh);
            return ['error' => "Missing required column: '$r'. Make sure this is an eBird 'My eBird Life List' export."];
        }
    }
    $rows = [];
    while (($row = fgetcsv($fh, 0, ",", "\"", "")) !== false) {
        if (count($row) === 1 && trim($row[0]) === '') continue; // skip blank lines
        $rec = [
            'common'   => trim($row[$idx['common name']]       ?? ''),
            'sci'      => trim($row[$idx['scientific name']]   ?? ''),
            'date_raw' => trim($row[$idx['date']]              ?? ''),
            'subid'    => isset($idx['subid'])    ? trim($row[$idx['subid']]    ?? '') : '',
            'category' => isset($idx['category']) ? trim($row[$idx['category']] ?? '') : '',
            'sp'       => isset($idx['s/p'])      ? trim($row[$idx['s/p']]      ?? '') : '',
        ];
        // Skip non-species categories (slashes, hybrids, spuhs, domestics)
        if ($rec['category'] && strtolower($rec['category']) !== 'species') continue;
        if ($rec['sci'] === '') continue;
        $rows[] = $rec;
    }
    fclose($fh);
    return ['rows' => $rows];
}

/**
 * eBird CSV dates look like "25 Jun 2025". Normalize to YYYY-MM-DD,
 * or return null on parse failure.
 */
function normalize_ebird_date($s) {
    if (!$s) return null;
    // perchpi:utc-mig-ebird-import-v1
    // eBird CSV dates carry no timezone; we treat the user's local
    // calendar date as the canonical day and store it under the UTC
    // convention (noon-UTC sentinel applied at INSERT). Use DateTime
    // with an explicit UTC zone so behavior does not depend on PHP's
    // date.timezone ini setting.
    try {
        $dt = new DateTime($s, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
    return $dt->format('Y-m-d');
}

/**
 * Connect to the BirdNET-Pi sqlite DB. Returns PDO or null.
 */
function open_birds_db($db_path) {
    try {
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Build a preview from parsed rows: per-species earliest date, flag whether
 * already present in the detections table. Returns array describing the
 * planned import.
 */
function build_preview($rows, $pdo) {
    // Collapse to one entry per scientific name, keeping earliest date
    $by_sci = [];
    foreach ($rows as $r) {
        $sci = $r['sci'];
        if ($sci === '') continue;
        $date = normalize_ebird_date($r['date_raw']);
        if (!isset($by_sci[$sci]) || ($date && $date < $by_sci[$sci]['date'])) {
            $by_sci[$sci] = [
                'sci'    => $sci,
                'common' => $r['common'],
                'date'   => $date ?: '1970-01-01',
                'subid'  => $r['subid'],
            ];
        }
    }
    // Look up which sci names already have detections
    $existing = [];
    if ($pdo && !empty($by_sci)) {
        $names = array_keys($by_sci);
        // SQLite has a per-statement parameter limit; chunk to be safe
        foreach (array_chunk($names, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT Sci_Name FROM detections WHERE Sci_Name IN ($placeholders)");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $existing[$name] = true;
            }
        }
    }
    $to_add  = [];
    $skipped = [];
    foreach ($by_sci as $sci => $entry) {
        if (isset($existing[$sci])) {
            $skipped[] = $entry;
        } else {
            $to_add[] = $entry;
        }
    }
    usort($to_add,  function ($a, $b) { return strcmp($a['common'], $b['common']); });
    usort($skipped, function ($a, $b) { return strcmp($a['common'], $b['common']); });
    return [
        'total_csv_rows' => count($rows),
        'unique_species' => count($by_sci),
        'to_add'         => $to_add,
        'skipped'        => $skipped,
    ];
}

/**
 * Insert the previewed species into the detections table.
 */
function commit_import($to_add, $pdo, $lat, $lon, $cutoff, $sens, $overlap) {
    $inserted = 0;
    $errors   = [];
    $sql = "INSERT INTO detections
            (Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name)
            VALUES
            (:Date, :Time, :Sci, :Com, :Conf, :Lat, :Lon, :Cutoff, :Week, :Sens, :Overlap, :File)";
    $stmt = $pdo->prepare($sql);
    $pdo->beginTransaction();
    try {
        foreach ($to_add as $entry) {
            // ISO week of detection date, computed in UTC to match the stored
            // Date convention (perchpi:utc-mig-ebird-import-v1). The '!' format
            // modifier resets unspecified time fields to the Unix epoch so the
            // result depends only on the date string.
            $dtw = DateTime::createFromFormat('!Y-m-d', $entry['date'], new DateTimeZone('UTC'));
            $week = $dtw ? intval($dtw->format('W')) : 0;
            $file_name = 'ebird_import_' . ($entry['subid'] !== '' ? $entry['subid'] : 'no_subid_' . substr(md5($entry['sci']), 0, 8));
            $stmt->execute([
                ':Date'    => $entry['date'],
                ':Time'    => '12:00:00',
                ':Sci'     => $entry['sci'],
                ':Com'     => $entry['common'],
                ':Conf'    => 1.0,
                ':Lat'     => $lat,
                ':Lon'     => $lon,
                ':Cutoff'  => $cutoff,
                ':Week'    => $week,
                ':Sens'    => $sens,
                ':Overlap' => $overlap,
                ':File'    => $file_name,
            ]);
            $inserted++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = $e->getMessage();
        $inserted = 0;
    }
    return ['inserted' => $inserted, 'errors' => $errors];
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// ---------- Request handling ----------
$action = isset($_POST['action']) ? $_POST['action'] : '';
if ($action !== '') { csrf_check(); } // perchpi:s21a-csrf-v1
$message = null;
$message_kind = null;  // 'ok' | 'error'
$preview = null;
$result = null;

if ($action === 'preview') {
    if (!isset($_FILES['ebird_csv']) || $_FILES['ebird_csv']['error'] !== UPLOAD_ERR_OK) {
        $message = 'No file uploaded or upload failed. Please choose a CSV file and try again.';
        $message_kind = 'error';
    } else {
        $up = $_FILES['ebird_csv'];
        // Validation: size (5MB), extension, basic content sniff
        if ($up['size'] > 5 * 1024 * 1024) {
            $message = 'File too large. eBird life lists are typically under 1MB. Limit is 5MB.';
            $message_kind = 'error';
        } elseif (strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $message = 'File must have a .csv extension.';
            $message_kind = 'error';
        } else {
            $parsed = parse_ebird_csv($up['tmp_name']);
            if (isset($parsed['error'])) {
                $message = $parsed['error'];
                $message_kind = 'error';
            } else {
                $pdo = open_birds_db($db_path);
                if (!$pdo) {
                    $message = 'Could not open BirdNET-Pi database.';
                    $message_kind = 'error';
                } else {
                    $preview = build_preview($parsed['rows'], $pdo);
                    @file_put_contents($preview_cache, json_encode($preview));
                    if (empty($preview['to_add'])) {
                        $message = 'No new species to import — every species in the CSV is already in your BirdNET-Pi database.';
                        $message_kind = 'ok';
                    }
                }
            }
        }
    }
} elseif ($action === 'commit') {
    if (!file_exists($preview_cache)) {
        $message = 'Preview expired or missing. Please re-upload the CSV.';
        $message_kind = 'error';
    } else {
        $cached = json_decode(@file_get_contents($preview_cache), true);
        @unlink($preview_cache);
        if (!$cached || empty($cached['to_add'])) {
            $message = 'Nothing to import.';
            $message_kind = 'ok';
        } else {
            $pdo = open_birds_db($db_path);
            if (!$pdo) {
                $message = 'Could not open BirdNET-Pi database for writing.';
                $message_kind = 'error';
            } else {
                $result = commit_import($cached['to_add'], $pdo, $lat, $lon, $cutoff, $sens, $overlap);
                if (!empty($result['errors'])) {
                    $message = 'Import failed: ' . implode('; ', $result['errors']);
                    $message_kind = 'error';
                } else {
                    $message = 'Imported ' . $result['inserted'] . ' new ' . ($result['inserted'] === 1 ? 'species' : 'species') . '.';
                    $message_kind = 'ok';
                }
            }
        }
    }
}

// ---------- Render ----------
?>
<style>
  .ebird-import-wrap {
    max-width: 820px;
    margin: 20px auto;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #222;
  }
  .ebird-import-wrap h2 { margin-top: 0; }
  .ebird-import-wrap p.lead { color: #555; }
  .ebird-import-card {
    background: #fff;
    border: 1px solid #d8d8d8;
    border-radius: 8px;
    padding: 22px;
    margin-bottom: 18px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  }
  .ebird-msg {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-weight: 500;
  }
  .ebird-msg.ok    { background: #e7f6e8; color: #1e6f2c; border: 1px solid #b8e0bc; }
  .ebird-msg.error { background: #fbe9e9; color: #8c1f1f; border: 1px solid #f1baba; }
  .ebird-import-form input[type="file"] { display: block; margin: 12px 0 18px 0; font-size: 14px; }
  .ebird-import-form button,
  .ebird-import-actions button {
    background: #2e7d32;
    color: #fff;
    border: none;
    padding: 10px 18px;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    font-size: 14px;
  }
  .ebird-import-form button.secondary,
  .ebird-import-actions button.secondary {
    background: #757575;
  }
  .ebird-import-actions { display: flex; gap: 10px; margin-top: 18px; }
  .ebird-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin: 16px 0;
  }
  .ebird-stat {
    background: #f3f3f3;
    border-radius: 6px;
    padding: 14px;
    text-align: center;
  }
  .ebird-stat-num { font-size: 28px; font-weight: 600; color: #2e7d32; }
  .ebird-stat-lbl { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.04em; }
  .ebird-list { max-height: 320px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 6px; padding: 6px; background: #fafafa; }
  .ebird-list .row { padding: 6px 10px; display: flex; justify-content: space-between; gap: 12px; font-size: 13px; }
  .ebird-list .row:nth-child(even) { background: #f0f0f0; }
  .ebird-list .com  { font-weight: 500; }
  .ebird-list .sci  { font-style: italic; color: #777; }
  .ebird-list .date { color: #888; font-variant-numeric: tabular-nums; }
  details > summary { cursor: pointer; font-weight: 500; padding: 6px 0; }
  .ebird-help { font-size: 13px; color: #666; line-height: 1.5; }
  .ebird-help code { background: #f0f0f0; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
</style>

<div class="ebird-import-wrap">
  <h2>Import eBird Life List</h2>
  <p class="lead">
    Upload your eBird "My eBird" CSV export to merge it with your BirdNET-Pi
    detection history. Species you've recorded in eBird but never on BirdNET-Pi
    will be added so the kiosk and species list show your full lifetime tally.
  </p>

  <?php if ($message): ?>
    <div class="ebird-msg <?= h($message_kind) ?>"><?= h($message) ?></div>
  <?php endif; ?>

  <?php if ($result && $result['inserted'] > 0): ?>
    <div class="ebird-import-card">
      <h3>Done!</h3>
      <p>Imported <strong><?= intval($result['inserted']) ?></strong> new species into your BirdNET-Pi database.</p>
      <p class="ebird-help">
        These appear in your detection history with the original eBird date,
        a confidence of 1.0, and a file name starting with <code>ebird_import_</code>.
        You can re-run this import any time with a fresh eBird CSV export — already-known species are automatically skipped.
      </p>
    </div>
  <?php endif; ?>

  <?php if ($preview && !empty($preview['to_add'])): ?>
    <div class="ebird-import-card">
      <h3>Preview</h3>
      <div class="ebird-stats">
        <div class="ebird-stat">
          <div class="ebird-stat-num"><?= intval($preview['total_csv_rows']) ?></div>
          <div class="ebird-stat-lbl">CSV rows</div>
        </div>
        <div class="ebird-stat">
          <div class="ebird-stat-num"><?= intval($preview['unique_species']) ?></div>
          <div class="ebird-stat-lbl">Unique species</div>
        </div>
        <div class="ebird-stat">
          <div class="ebird-stat-num"><?= count($preview['to_add']) ?></div>
          <div class="ebird-stat-lbl">New to add</div>
        </div>
      </div>
      <details open>
        <summary>New species (<?= count($preview['to_add']) ?>) that will be added</summary>
        <div class="ebird-list">
          <?php foreach ($preview['to_add'] as $e): ?>
            <div class="row">
              <span><span class="com"><?= h($e['common']) ?></span> &nbsp; <span class="sci"><?= h($e['sci']) ?></span></span>
              <span class="date"><?= h($e['date']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
      <?php if (!empty($preview['skipped'])): ?>
        <details>
          <summary>Already in BirdNET-Pi (<?= count($preview['skipped']) ?>) — will be skipped</summary>
          <div class="ebird-list">
            <?php foreach ($preview['skipped'] as $e): ?>
              <div class="row">
                <span><span class="com"><?= h($e['common']) ?></span> &nbsp; <span class="sci"><?= h($e['sci']) ?></span></span>
                <span class="date"><?= h($e['date']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>
      <form method="POST" action="views.php?view=eBird+Import" class="ebird-import-actions"><?php echo csrf_field(); /* perchpi:s21a-csrf-v1 */ ?>
        <input type="hidden" name="action" value="commit">
        <button type="submit">Import <?= count($preview['to_add']) ?> species</button>
        <a href="views.php?view=eBird+Import"><button type="button" class="secondary">Cancel</button></a>
      </form>
    </div>
  <?php endif; ?>

  <?php if (!$preview || empty($preview['to_add'])): ?>
    <div class="ebird-import-card">
      <h3>Step 1: Choose your eBird CSV</h3>
      <form method="POST" action="views.php?view=eBird+Import" enctype="multipart/form-data" class="ebird-import-form"><?php echo csrf_field(); /* perchpi:s21a-csrf-v1 */ ?>
        <input type="hidden" name="action" value="preview">
        <input type="file" name="ebird_csv" accept=".csv" required>
        <button type="submit">Preview Import</button>
      </form>
      <details>
        <summary>How to get your eBird CSV</summary>
        <p class="ebird-help">
          1. Visit <a href="https://ebird.org/myebird" target="_blank" rel="noopener">ebird.org/myebird</a> and sign in.<br>
          2. Click <strong>"Life List"</strong> (or any list you want to import).<br>
          3. Click the <strong>"Download"</strong> button to get a CSV file.<br>
          4. Upload it here.<br><br>
          Alternatively, <a href="https://ebird.org/downloadMyData" target="_blank" rel="noopener">download all your eBird data</a>
          for the most complete picture (includes every checklist).
        </p>
      </details>
      <details>
        <summary>What this does</summary>
        <p class="ebird-help">
          The import adds one row per <em>new</em> species into BirdNET-Pi's detection database.
          Each new row uses the earliest eBird date for that species, confidence = 1.0,
          and a marker file name (<code>ebird_import_&lt;eBird-checklist-id&gt;</code>) so imports can be
          identified later. Species already detected by BirdNET or already imported from a previous
          eBird CSV are skipped — no duplicates.
        </p>
      </details>
    </div>
  <?php endif; ?>
</div>
