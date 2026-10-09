<?php
/* ============================================================================
   species_profile.php — Species Profile (about-this-bird page)
   ============================================================================
   Reached from Best Recordings + All Species rows.
   URL: ?view=Species Profile&species=<sci_name>

   Layout:
   - Back-nav (common name / sci name)
   - Hero card: large photo + names + photo credit
   - About this bird:
       - Wikipedia summary paragraph
       - Facts strip (conservation status, taxonomy genus when available)
       - "Source: Wikipedia (CC BY-SA)" link at end
   - Your observations: total ever, first/last detection, best confidence
   - Learn more at All About Birds (Cornell) link

   Data:
   - Wikipedia summary + facts: pre-cached at
     ~/BirdSongs/Extracted/species_profiles/{Genus_species}.json
     (refreshed via refresh_species_profiles.php CLI script)
   - Detection stats: live DB query at render time
   - Cornell link: derived from common name
   ============================================================================ */

// perchpi:photo-helpers-phase3-v1 -- kiosk.php shim removed; helpers come from perchpi_helpers.php which chains in common.php
if (!function_exists('get_db')) { require_once __DIR__ . '/../scripts/common.php'; }
require_once __DIR__ . '/perchpi_helpers.php';

$sci_name = isset($_GET['species']) ? (string)$_GET['species'] : ''; // perchpi:get-post-deescape-v1
if ($sci_name === '') {
  echo '<div class="page-header"><h1>Species not specified</h1></div>';
  $pp_back = isset($_GET['from']) ? str_replace(' ', '+', $_GET['from']) : 'All+Species';
  echo '<div class="empty-state"><p><a class="btn btn--primary" href="?view='
       . htmlspecialchars($pp_back, ENT_QUOTES) . '">Back</a></p></div>';
  return;
}

$home = function_exists('get_home') ? get_home() : '/home/birder';
$db_path = __DIR__ . '/../scripts/birds.db';
if (!file_exists($db_path)) {
  $db_path = $home . '/BirdNET-Pi/scripts/birds.db';
}
$cache_dir = $home . '/BirdSongs/Extracted/species_profiles';

// ────────────────────────────────────────────────────────────────────────
// Look up most-recent detection ever (for hero photo + common name)
// ────────────────────────────────────────────────────────────────────────
$com_name = $sci_name;
/* perchpi:species-profile-com-fallback-v1 — when a species has no surviving
   detection rows (e.g. a deleted vagrant reached from Notable Sightings), fall
   back to the common name passed in the URL. A real DB Com_Name overrides below. */
if (isset($_GET['com'])) {
  $pp_com_param = trim((string)$_GET['com']);
  if ($pp_com_param !== '') { $com_name = $pp_com_param; }
}
$any_row = null;
if (file_exists($db_path)) {
  try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(1000);
    /* perchpi:species-profile-comname-bypass-v1 — Com_Name metadata
       lookup bypasses the quarantine filter so the title renders correctly
       even when 100% of detections are quarantined. Stats queries below
       (counts, first/last, best confidence) remain filtered. */
    $stmt_meta = $db->prepare(
      "SELECT Com_Name FROM detections WHERE Sci_Name = :sci " .
      "ORDER BY Date DESC, Time DESC LIMIT 1"
    );
    $stmt_meta->bindValue(':sci', $sci_name, SQLITE3_TEXT);
    $meta_row = $stmt_meta->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
    if ($meta_row && !empty($meta_row['Com_Name'])) $com_name = $meta_row['Com_Name'];
    $stmt = $db->prepare(
      "SELECT Date, Time, Com_Name, Sci_Name, Confidence, File_Name " .
      "FROM detections WHERE Sci_Name = :sci " .
      "  AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "ORDER BY Date DESC, Time DESC LIMIT 1"
    );
    $stmt->bindValue(':sci', $sci_name, SQLITE3_TEXT);
    $any_row = $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
    $db->close();
  } catch (Exception $e) {}
}

// ────────────────────────────────────────────────────────────────────────
// Detection stats: total ever, first ever, last ever, best confidence
// (excluding ebird_imports for "best confidence" since those don't have
// real audio — but include them in counts/dates for completeness)
// ────────────────────────────────────────────────────────────────────────
$total_ever = 0;
$first_date = null;
$last_date  = null;
$best_conf  = 0;
$best_conf_date = null;
if (file_exists($db_path)) {
  try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(1000);
    $stmt = $db->prepare(
      "SELECT COUNT(*) AS n, MIN(Date) AS first_d, MAX(Date) AS last_d " .
      "FROM detections WHERE Sci_Name = :sci AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */"
    );
    $stmt->bindValue(':sci', $sci_name, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($r) {
      $total_ever = (int)$r['n'];
      $first_date = $r['first_d'];
      $last_date  = $r['last_d'];
    }
    // Best confidence (real recordings only)
    $stmt2 = $db->prepare(
      "SELECT MAX(Confidence) AS best, Date AS d FROM detections " .
      "WHERE Sci_Name = :sci AND File_Name NOT LIKE 'ebird_import_%' " .
      "  AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "GROUP BY Sci_Name LIMIT 1"
    );
    $stmt2->bindValue(':sci', $sci_name, SQLITE3_TEXT);
    $r2 = $stmt2->execute()->fetchArray(SQLITE3_ASSOC);
    if ($r2) {
      $best_conf = (float)$r2['best'];
      $best_conf_date = $r2['d'];
    }
    $db->close();
  } catch (Exception $e) {}
}

// ────────────────────────────────────────────────────────────────────────
// Hero photo
// ────────────────────────────────────────────────────────────────────────
$hero_source_row = $any_row ?: ['Com_Name' => $com_name, 'Sci_Name' => $sci_name];
$info = thumbnail_info_for($hero_source_row);
$hero_thumb = $info['thumb'];
$hero_is_bird = $info['is_bird_photo'];
$hero_meta = ['artist' => '', 'license' => '', 'descriptionurl' => ''];
if ($info['meta']) $hero_meta = array_merge($hero_meta, $info['meta']);

// ────────────────────────────────────────────────────────────────────────
// Wikipedia profile cache
// ────────────────────────────────────────────────────────────────────────
$slug = str_replace([' ', '/'], '_', $sci_name);
// perchpi:user-photo-state-v1
$hero_is_user_photo = file_exists('/home/birder/BirdSongs/Extracted/bird_images/' . $slug . '.user.jpg');
$cache_file = "$cache_dir/$slug.json";
$profile = null;
if (file_exists($cache_file)) {
  $raw = @file_get_contents($cache_file);
  if ($raw) $profile = json_decode($raw, true);
}

// PerchPi: on-demand Wikipedia fetch when cache is missing.
// Uses a lock file (60s staleness check) to prevent dupe fetches.
$pp_wiki_fetch_in_progress = false;
if (!$profile) {
  $lock_file = "/tmp/pp_wiki_fetch_" . $slug . ".lock";
  $lock_fresh = (file_exists($lock_file) && (time() - filemtime($lock_file)) < 60);
  if ($lock_fresh) {
    $pp_wiki_fetch_in_progress = true;
  } else {
    @touch($lock_file);
    $refresh_script = __DIR__ . "/refresh_species_profiles.php";
    $cmd = sprintf(
      "HOME=/home/birder php %s --species %s > /dev/null 2>&1 &",
      escapeshellarg($refresh_script),
      escapeshellarg($sci_name)
    );
    @exec($cmd);
    $pp_wiki_fetch_in_progress = true;
  }
}
$pp_wiki_reload_suppress = isset($_GET['pp_wiki_reloaded']);

// ────────────────────────────────────────────────────────────────────────
// Cornell All About Birds link (derived from common name)
// Pattern: https://www.allaboutbirds.org/guide/{Common_Name_With_Underscores}/overview
// ────────────────────────────────────────────────────────────────────────
$cornell_slug = str_replace(' ', '_', $com_name);
$cornell_url = 'https://www.allaboutbirds.org/guide/' . rawurlencode($cornell_slug) . '/overview';

$com_h = htmlspecialchars($com_name, ENT_QUOTES);
$sci_h = htmlspecialchars($sci_name, ENT_QUOTES);
?>

<?php
/* perchpi:species-profile-from-fix-v2 */
$pp_from_raw = isset($_GET['from']) ? trim($_GET['from']) : 'All Species';
$pp_from_allowed = ['All Species', 'Species Stats', 'Birds Today', 'Best Recordings', 'Overview', 'Species Management', 'Quarantine', 'Notable Sightings']; // perchpi:quarantine-page-features-v2 + notable-from-v1
if (!in_array($pp_from_raw, $pp_from_allowed, true)) $pp_from_raw = 'All Species';
$pp_back_href  = '?view=' . str_replace(' ', '+', $pp_from_raw);
/* perchpi:species-hero-dedup-v1 */ pp_render_back_nav($pp_back_href, $pp_from_raw, '', '');
?>

<!-- Hero card -->
<section class="section">
  <div class="latest-card pp-species-hero">
    <div class="latest-thumb <?= $hero_is_bird ? 'thumb-tappable' : '' ?> <?= $hero_is_user_photo ? 'is-user-photo' : '' ?>" <?php /* perchpi:user-photo-thumb-class-v1 */ ?>
         data-is-bird-photo="<?= $hero_is_bird ? '1' : '0' ?>"
         data-is-user-photo="<?= $hero_is_user_photo ? '1' : '0' ?>"
         data-common="<?= $com_h ?>"
         data-sci="<?= $sci_h ?>"
         data-photo-artist="<?= htmlspecialchars($hero_meta['artist'], ENT_QUOTES) ?>"
         data-photo-license="<?= htmlspecialchars($hero_meta['license'], ENT_QUOTES) ?>"
         data-photo-source="<?= htmlspecialchars($hero_meta['descriptionurl'], ENT_QUOTES) ?>">
      <?php if ($hero_thumb): ?>
        <img src="<?= htmlspecialchars($hero_thumb, ENT_QUOTES) ?>" alt="">
      <?php else: ?>
        <div class="thumb-placeholder"></div>
      <?php endif; ?>
    </div>
    <!-- perchpi:species-hero-stack-v1 — wrap upload widget + name/sci block in vertical stack -->
    <div class="pp-hero-stack">
    <!-- perchpi:user-photo-widget-v1 -->
<div class="pp-userphoto"
     data-sci="<?= $sci_h ?>"
     data-is-user-photo="<?= $hero_is_user_photo ? '1' : '0' ?>">
  <button type="button" class="pp-userphoto-btn pp-userphoto-add" data-pp-action="upload">
    <span class="pp-userphoto-icon">📷</span>
    <span class="pp-userphoto-label">Use my own photo</span>
  </button>
  <div class="pp-userphoto-menu-wrap">
    <button type="button" class="pp-userphoto-btn pp-userphoto-menu-trigger"
            aria-haspopup="menu" aria-expanded="false" title="Personal photo options">
      <span class="pp-userphoto-icon">📷</span>
      <span class="pp-userphoto-caret">▾</span>
    </button>
    <div class="pp-userphoto-menu" role="menu" hidden>
      <button type="button" class="pp-userphoto-menu-item" data-pp-action="upload" role="menuitem">Replace photo</button>
      <button type="button" class="pp-userphoto-menu-item pp-userphoto-menu-item--danger" data-pp-action="remove" role="menuitem">Remove (use Wikipedia)</button>
    </div>
  </div>
  <input type="file" class="pp-userphoto-file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" hidden>
  <div class="pp-userphoto-modal" hidden role="dialog" aria-modal="true" aria-labelledby="pp-userphoto-modal-title">
    <div class="pp-userphoto-modal-backdrop"></div>
    <div class="pp-userphoto-modal-content">
      <h2 id="pp-userphoto-modal-title">Confirm new photo</h2>
      <div class="pp-userphoto-preview-wrap">
        <img class="pp-userphoto-preview" alt="Preview">
      </div>
      <p class="pp-userphoto-info">
        <span class="pp-userphoto-filename"></span>
        <span class="pp-userphoto-size"></span>
      </p>
      <div class="pp-userphoto-modal-actions">
        <button type="button" class="pp-userphoto-cancel">Cancel</button>
        <button type="button" class="pp-userphoto-confirm">Upload</button>
      </div>
    </div>
  </div>
</div>
<script>
/* perchpi:user-photo-widget-js-v1 */
(function() {
  var scripts = document.getElementsByTagName('script');
  var thisScript = scripts[scripts.length - 1];
  var widget = thisScript.previousElementSibling;
  while (widget && (!widget.classList || !widget.classList.contains('pp-userphoto'))) {
    widget = widget.previousElementSibling;
  }
  if (!widget) return;

  var sciName    = widget.dataset.sci;
  var fileInput  = widget.querySelector('.pp-userphoto-file');
  var addBtn     = widget.querySelector('.pp-userphoto-add');
  var menuTrig   = widget.querySelector('.pp-userphoto-menu-trigger');
  var menu       = widget.querySelector('.pp-userphoto-menu');
  var modal      = widget.querySelector('.pp-userphoto-modal');
  var preview    = widget.querySelector('.pp-userphoto-preview');
  var fileNameEl = widget.querySelector('.pp-userphoto-filename');
  var fileSizeEl = widget.querySelector('.pp-userphoto-size');
  var cancelBtn  = widget.querySelector('.pp-userphoto-cancel');
  var confirmBtn = widget.querySelector('.pp-userphoto-confirm');
  var pendingFile = null;

  function fmtBytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024*1024) return (n/1024).toFixed(1) + ' KB';
    return (n/(1024*1024)).toFixed(2) + ' MB';
  }

  function closeMenu() {
    if (menu) menu.hidden = true;
    if (menuTrig) menuTrig.setAttribute('aria-expanded', 'false');
  }
  function openMenu() {
    if (menu) menu.hidden = false;
    if (menuTrig) menuTrig.setAttribute('aria-expanded', 'true');
  }

  function openPicker() {
    closeMenu();
    fileInput.value = '';
    fileInput.click();
  }

  fileInput.addEventListener('change', function() {
    if (!fileInput.files || !fileInput.files[0]) return;
    var f = fileInput.files[0];
    if (f.size > 12 * 1024 * 1024) {
      alert('File too large (max 12 MB). Yours: ' + fmtBytes(f.size));
      return;
    }
    pendingFile = f;
    fileNameEl.textContent = f.name;
    fileSizeEl.textContent = ' \u00b7 ' + fmtBytes(f.size);
    var reader = new FileReader();
    reader.onload = function(e) {
      preview.src = e.target.result;
      modal.hidden = false;
    };
    reader.onerror = function() {
      preview.removeAttribute('src');
      preview.alt = '(no preview \u2014 HEIC files preview after upload)';
      modal.hidden = false;
    };
    reader.readAsDataURL(f);
  });

  cancelBtn.addEventListener('click', function() {
    modal.hidden = true;
    pendingFile = null;
  });

  function setBusy(btn, busy) {
    if (!btn) return;
    if (busy) {
      btn.dataset.origHtml = btn.innerHTML;
      btn.innerHTML = '<span class="pp-userphoto-spinner" aria-hidden="true"></span><span class="pp-userphoto-label">Uploading\u2026</span>';
      btn.disabled = true;
    } else {
      if (btn.dataset.origHtml) btn.innerHTML = btn.dataset.origHtml;
      btn.disabled = false;
    }
  }

  confirmBtn.addEventListener('click', function() {
    if (!pendingFile) return;
    confirmBtn.disabled = true;
    cancelBtn.disabled = true;
    var visibleBtn = widget.dataset.isUserPhoto === '1' ? menuTrig : addBtn;
    setBusy(visibleBtn, true);
    var fd = new FormData();
    fd.append('sci_name', sciName);
    fd.append('photo', pendingFile);
    fetch('/upload_user_photo.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': window.PP_CSRF || '' }  // perchpi:s21a-csrf-v1
    })
    .then(function(r) {
      return r.json().then(function(j) { return {status: r.status, body: j}; })
        .catch(function() { return {status: r.status, body: {error: 'invalid response'}}; });
    })
    .then(function(res) {
      if (res.status === 200 && res.body && res.body.ok) {
        location.reload();
      } else {
        var msg = (res.body && res.body.error) ? res.body.error : ('HTTP ' + res.status);
        alert('Upload failed: ' + msg);
        setBusy(visibleBtn, false);
        confirmBtn.disabled = false;
        cancelBtn.disabled = false;
      }
    })
    .catch(function(e) {
      alert('Upload network error: ' + e);
      setBusy(visibleBtn, false);
      confirmBtn.disabled = false;
      cancelBtn.disabled = false;
    });
  });

  if (addBtn) addBtn.addEventListener('click', openPicker);

  if (menuTrig) {
    menuTrig.addEventListener('click', function(e) {
      e.stopPropagation();
      if (menu.hidden) openMenu(); else closeMenu();
    });
    document.addEventListener('click', function(e) {
      if (!widget.contains(e.target)) closeMenu();
    });
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeMenu();
    });
  }

  widget.querySelectorAll('.pp-userphoto-menu-item').forEach(function(item) {
    item.addEventListener('click', function() {
      var action = item.dataset.ppAction;
      closeMenu();
      if (action === 'upload') {
        openPicker();
        return;
      }
      if (action === 'remove') {
        if (!confirm('Remove your custom photo for ' + sciName + '?\nThe Wikipedia photo will be used instead.')) return;
        setBusy(menuTrig, true);
        var fd = new FormData();
        fd.append('sci_name', sciName);
        fetch('/remove_user_photo.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': window.PP_CSRF || '' }  // perchpi:s21a-csrf-v1
        })
        .then(function(r) { return r.json(); })
        .then(function(j) {
          if (j.ok) location.reload();
          else { alert('Remove failed: ' + (j.error || 'unknown')); setBusy(menuTrig, false); }
        })
        .catch(function(e) { alert('Network error: ' + e); setBusy(menuTrig, false); });
      }
    });
  });
})();
</script>
<!-- /perchpi:user-photo-widget-v1 -->
<!-- perchpi:user-photo-position-v2 -->

    <div class="latest-card-body">
      <div class="latest-card-name"><?= $com_h ?><?= pp_geomodel_pill($sci_name) /* perchpi:geomodel-pill */ ?></div>
      <div class="latest-card-sci"><?= $sci_h ?></div>
    </div>
    </div><!-- /perchpi:species-hero-stack-v1 -->
  </div>


</section>

<?php pp_render_external_links($sci_name, $com_name); /* perchpi:external-links-v1 */ ?>

<!-- About this bird (Wikipedia summary + facts) -->
<?php if ($profile && !empty($profile['extract'])): ?>
  <section class="section pp-profile-about">
    <h2 class="pp-section-title">About this bird</h2>

    <?php if (!empty($profile['facts'])): ?>
      <?php
        $facts = $profile['facts'];
        $chips = [];
        if (!empty($facts['conservation_status_full'])) {
          $code = $facts['conservation_status'] ?? '';
          $chip_class = 'chip pp-fact-chip';
          // Color hint: green for LC, amber for NT/VU, red for EN/CR/EX
          if (in_array($code, ['LC'])) $chip_class .= ' pp-fact-conservation-good';
          elseif (in_array($code, ['NT', 'VU'])) $chip_class .= ' pp-fact-conservation-warn';
          elseif (in_array($code, ['EN', 'CR', 'EW', 'EX'])) $chip_class .= ' pp-fact-conservation-bad';
          $chips[] = '<span class="' . $chip_class . '">' . htmlspecialchars($facts['conservation_status_full'], ENT_QUOTES) . '</span>';
        }
        if (!empty($facts['taxonomy_genus'])) {
          $chips[] = '<span class="chip pp-fact-chip">Genus <em>' . htmlspecialchars($facts['taxonomy_genus'], ENT_QUOTES) . '</em></span>';
        }
        if (!empty($facts['taxonomy_family'])) {
          $chips[] = '<span class="chip pp-fact-chip">Family ' . htmlspecialchars($facts['taxonomy_family'], ENT_QUOTES) . '</span>';
        }
        if (!empty($facts['taxonomy_order'])) {
          $chips[] = '<span class="chip pp-fact-chip">Order ' . htmlspecialchars($facts['taxonomy_order'], ENT_QUOTES) . '</span>';
        }
        if (!empty($facts['length'])) {
          $chips[] = '<span class="chip pp-fact-chip">Length ' . htmlspecialchars($facts['length'], ENT_QUOTES) . '</span>';
        }
        if (!empty($facts['wingspan'])) {
          $chips[] = '<span class="chip pp-fact-chip">Wingspan ' . htmlspecialchars($facts['wingspan'], ENT_QUOTES) . '</span>';
        }
      ?>
      <?php if (!empty($chips)): ?>
        <div class="pp-facts-strip">
          <?= implode("\n", $chips) ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <p class="pp-profile-extract">
      <?= nl2br(htmlspecialchars($profile['extract'], ENT_QUOTES)) ?>
    </p>

    <?php if (!empty($profile['page_url'])): ?>
      <p class="pp-profile-attribution">
        Source:
        <a href="<?= htmlspecialchars($profile['page_url'], ENT_QUOTES) ?>"
           target="_blank" rel="noopener noreferrer">Wikipedia</a>
        (CC BY-SA)
      </p>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="section pp-profile-about">
    <h2 class="pp-section-title">About this bird</h2>
    <?php if ($pp_wiki_fetch_in_progress && !$pp_wiki_reload_suppress): ?>
      <p class="pp-profile-extract pp-profile-missing" id="pp-wiki-fetching">
        Fetching from Wikipedia... page will refresh in a few seconds.
      </p>
      <script>
        (function () {
          var u = new URL(window.location.href);
          u.searchParams.set('pp_wiki_reloaded', '1');
          setTimeout(function () { window.location.replace(u.toString()); }, 6000);
        })();
      </script>
    <?php else: ?>
      <p class="pp-profile-extract pp-profile-missing">
        No Wikipedia summary cached for this species yet.
      </p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<!-- Likely here this week (geomodel) -->
<?php /* perchpi:geomodel-curve */ ?>
<?php $pp_detection_counts = pp_query_detection_weekly_counts($db_path, $sci_name); ?>
<?php $pp_pheno_svg = pp_geomodel_phenology_svg($sci_name, $pp_detection_counts); ?>
<?php $pp_pheno_data = pp_geomodel_species($sci_name); ?>
<?php if ($pp_pheno_svg): ?>
  <section class="section pp-pheno-card">
    <div class="pp-pheno-card-header">
      <h2 class="pp-section-title pp-pheno-card-title">Likely here this week</h2>
      <?php if ($pp_pheno_data && in_array(($pp_pheno_data['bucket'] ?? ''), ['uncommon','rare'], true)): ?>
        <?= pp_geomodel_pill($sci_name) ?>
      <?php endif; ?>
    </div>
    <p class="pp-pheno-card-sub">
      Geomodel-predicted likelihood at your location across the year.
      The vertical marker shows the current week.
      Amber band = uncommon (&lt; 0.30), rose band = rare (&lt; 0.05).
    </p>
    <?= $pp_pheno_svg ?>
  </section>
<?php endif; ?>

<!-- Your observations -->
<section class="section pp-profile-stats">
  <h2 class="pp-section-title">Your observations</h2>
  <?php if ($total_ever > 0): ?>
    <div class="pp-stats-grid">
      <div class="pp-stat">
        <div class="pp-stat-value"><?= number_format($total_ever) ?></div>
        <div class="pp-stat-label">Total detections</div>
      </div>
      <?php if ($best_conf > 0): ?>
      <div class="pp-stat">
        <div class="pp-stat-value"><?= round($best_conf * 100) ?>%</div>
        <div class="pp-stat-label">Best confidence</div>
      </div>
      <?php endif; ?>
      <?php if ($first_date): ?>
      <div class="pp-stat">
        <div class="pp-stat-value"><?= htmlspecialchars($first_date, ENT_QUOTES) ?></div>
        <div class="pp-stat-label">First seen</div>
      </div>
      <?php endif; ?>
      <?php if ($last_date): ?>
      <div class="pp-stat">
        <div class="pp-stat-value"><?= htmlspecialchars($last_date, ENT_QUOTES) ?></div>
        <div class="pp-stat-label">Last seen</div>
      </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <p class="pp-profile-extract pp-profile-missing">
      No detections recorded yet.
    </p>
  <?php endif; ?>
</section>

<!-- Learn more (Cornell) -->
<section class="section pp-profile-learn-more">
  <a class="btn btn--primary pp-cornell-btn"
     href="<?= htmlspecialchars($cornell_url, ENT_QUOTES) ?>"
     target="_blank" rel="noopener noreferrer">
    Learn more at All About Birds ↗
  </a>
  <p class="pp-cornell-note">Cornell Lab of Ornithology · all-about-birds.org</p>
</section>
