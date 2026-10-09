<?php
/* recordings.php - PerchPi C-2.4 batch 3 (v2: Com_Name path fix + lazy thumbs)
   Paginated, filterable recordings browser. Included by views.php when $view == "Recordings".
   FIX: audio + spectrogram dir segment is com_to_slug(Com_Name), NOT Sci_Name.
   Files live at By_Date/{Date}/{Com_Name_slug}/{File_Name}; spectrogram = {File_Name}.png.
   Thumbs use data-src + IntersectionObserver (pp-rec-lazy in perchpi.js). NO sox at render. */

@include_once __DIR__ . '/../homepage/perchpi_helpers.php';

function pp_rec_slug($com) {
  if (function_exists('com_to_slug')) return com_to_slug($com);
  $s = str_replace("'", "", (string)$com);   /* apostrophes stripped */
  return str_replace(" ", "_", $s);            /* spaces -> underscores */
}
function pp_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Build a web path under the Caddy root (BirdSongs/Extracted) for a detection's
   audio file, URL-encoding each segment so colons in the timestamp survive. */
function pp_rec_audio_url($date, $com, $file_name) {
  $seg = function($s) { return rawurlencode((string)$s); };
  return '/By_Date/' . $seg($date) . '/' . $seg(pp_rec_slug($com)) . '/' . $seg($file_name);
}

$db_path = '/home/birder/BirdNET-Pi/scripts/birds.db';
try {
  $db = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $db->setAttribute(PDO::ATTR_TIMEOUT, 5);
} catch (Exception $e) {
  echo "<section class='section'><p class='pp-empty'>Database unavailable.</p></section>"; return;
}

/* --- filters from $_GET --- */
$species = isset($_GET['species']) ? trim($_GET['species']) : '';
$date    = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : '';
$minc    = isset($_GET['minc']) ? (int)$_GET['minc'] : 0;
$page    = isset($_GET['pg']) ? max(1, (int)$_GET['pg']) : 1;
$per     = 50;

$where = ["File_Name NOT LIKE 'ebird_import_%'"];
$args  = [];
if ($species !== '') { $where[] = "Com_Name = ?"; $args[] = $species; }
if ($date !== '')    { $where[] = "Date = ?";     $args[] = $date; }
if ($minc > 0)       { $where[] = "Confidence >= ?"; $args[] = $minc / 100.0; }
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* total for pagination */
$cstmt = $db->prepare("SELECT COUNT(*) FROM detections $wsql");
$cstmt->execute($args);
$nrows = (int)$cstmt->fetchColumn();
$npages = max(1, (int)ceil($nrows / $per));
if ($page > $npages) $page = $npages;
$off   = ($page - 1) * $per;

/* page of rows */
$q = "SELECT Com_Name, Sci_Name, Date, Time, Confidence, File_Name, ROWID AS pp_rid /* perchpi:quarantine-hide-v1 */
      FROM detections $wsql
      ORDER BY Date DESC, Time DESC
      LIMIT $per OFFSET $off";
$stmt = $db->prepare($q);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* species list for dropdown */
$splist = $db->query("SELECT DISTINCT Com_Name FROM detections WHERE File_Name NOT LIKE 'ebird_import_%' ORDER BY Com_Name");
$spopts = $splist ? $splist->fetchAll(PDO::FETCH_COLUMN) : [];

/* back nav */
if (function_exists('pp_render_back_nav')) {
  pp_render_back_nav('?view=Overview', 'Overview', 'Recordings', $nrows . ' recordings');
} else {
  echo "<h1 class='pp-title'>Recordings</h1>";
}

function pp_qs(array $override) {
  $base = ['view'=>'Recordings'];
  foreach (['species','date','minc','pg'] as $k) if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
  foreach ($override as $k=>$v) { if ($v === null) unset($base[$k]); else $base[$k] = $v; }
  return '?' . http_build_query($base);
}
?>
<section class="section">
  <form class="pp-rec-filters" method="get" action="">
    <input type="hidden" name="view" value="Recordings">
    <select name="species" onchange="this.form.submit()">
      <option value="">All species</option>
    <?php foreach ($spopts as $sn): ?>
      <option value="<?=pp_h($sn)?>" <?= $species===$sn?'selected':''?>><?=pp_h($sn)?></option>
    <?php endforeach; ?>
    </select>
    <input type="date" name="date" value="<?=pp_h($date)?>" onchange="this.form.submit()">
    <label class="pp-minc">min conf
      <input type="number" name="minc" min="0" max="100" step="5" value="<?=pp_h($minc)?>" onchange="this.form.submit()">%
    </label>
    <a class="pp-clear" href="?view=Recordings">Clear</a>
  </form>
</section>

<section class="section">
<?php if (!count($rows)): ?>
  <p class="pp-empty">No recordings match these filters.</p>
<?php else: ?>
  <ul class="pp-rec-list">
  <?php foreach ($rows as $r):
    $com = $r['Com_Name']; $sci = $r['Sci_Name'];
    $dt  = $r['Date']; $tm = $r['Time'];
    $cp  = round(((float)$r['Confidence']) * 100);
    $fn  = $r['File_Name'];
    $audio = pp_rec_audio_url($dt, $com, $fn);
    $spec  = $audio . '.png';   /* BirdNET writes spectrogram next to audio */
  ?>
    <li class="pp-rec-row" data-rid="<?=pp_h($r['pp_rid'])?>" data-species-link="?#view=Species%20Profile&sci=<?=pp_h($sci)?>" data-species-sci="<?=pp_h($sci)?>"> <?php /* perchpi:quarantine-hide-v1 */ ?>
      <img class="pp-rec-spec" data-src="<?=pp_h($spec)?>" alt="" onerror="this.style.visibility='hidden'">
      <div class="pp-rec-meta">
        <span class="pp-rec-name"><?=pp_h($com)?></span>
        <span class="pp-rec-sub"><?=pp_h($dt)?> at <?=pp_h($tm)?> &middot; <?=pp_h($cp)?>%</span>
      </div>
      <button class="play-btn" data-audio="<?=pp_h($audio)?>" aria-label="Play">&#9654;</button>
      <?php /* perchpi:quarantine-hide-v1 — hide (soft-delete to Quarantine) */ ?>
      <button class="detection-hide" data-rid="<?=pp_h($r['pp_rid'])?>" aria-label="Hide this detection" title="Hide (recoverable on the Quarantine page)">&#128683;</button>
    </li>
  <?php endforeach; ?>
  </ul>

<?php if ($npages > 1): ?>
  <nav class="pp-pager">
    <?php if ($page > 1): ?><a href="<?=pp_qs(['pg'=>$page-1])?>">&#8592; Prev</a><?php endif; ?>
    <span class="pp-pager-at">Page <?=$page?> of <?=$npages?></span>
    <?php if ($page < $npages): ?><a href="<?=pp_qs(['pg'=>$page+1])?>">Next &#8594;</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
</section>

<script>
/* perchpi:quarantine-hide-v1 — Recordings (scripts/recordings.php) hide handler */
(function () {
  document.querySelectorAll('.pp-rec-row .detection-hide').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var rid = btn.getAttribute('data-rid');
      if (!rid) return;
      if (!confirm('Hide this detection? It moves to Quarantine and can be restored there.')) return;
      btn.disabled = true;
      var body = new URLSearchParams();
      body.append('detection_ids[]', rid);
      fetch('quarantine_hide.php', { method: 'POST', body: body, credentials: 'same-origin',
                                     headers: { 'X-CSRF-Token': window.PP_CSRF || '' } })  // perchpi:s21a-csrf-v1
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) {
            var row = btn.closest('.pp-rec-row');
            if (row) row.remove();
          } else {
            btn.disabled = false;
            alert('Hide failed: ' + (j && j.error ? j.error : 'unknown'));
          }
        })
        .catch(function (err) {
          btn.disabled = false;
          alert('Network error: ' + err);
        });
    });
  });
})();
</script>
