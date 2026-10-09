<?php
/* ============================================================================
   PerchPi · Quarantine Review (Wave Q3.2)
   ============================================================================
   Shows detections that have been hidden from main views by the geomodel
   filter (Q3.1a/b). Per-row and bulk actions allow either restoring a
   detection (un-quarantine) or permanently deleting it.

   Marker: perchpi:quarantine-page-v1
   ============================================================================ */

require_once __DIR__ . '/../scripts/common.php';

ensure_authenticated(); /* perchpi:s21a-auth-plain-call-v1 -- SHIP-WITH #7: fail closed, no function_exists() guard */

$config = function_exists('get_config') ? get_config() : [];
$home   = function_exists('get_home')   ? get_home()   : '/home/birder';

$threshold = isset($config['QUARANTINE_THRESHOLD'])
  ? floatval($config['QUARANTINE_THRESHOLD'])
  : 0.05;

$use_geomodel = isset($config['USE_GEOMODEL']) && $config['USE_GEOMODEL'] == '1';

/* ---- DB ---- */
$db_path = $home . '/BirdNET-Pi/scripts/birds.db';
$db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
$db->busyTimeout(5000);

/* ---- Total count ---- */
$total = (int)$db->querySingle("SELECT COUNT(*) FROM quarantined_detections");

/* ---- Pull rows (LIMIT 500) ---- */
$rows = [];
if ($total > 0) {
  $sql = "SELECT q.detection_id, q.quarantined_at, q.reason,
                 q.geomodel_probability, q.geomodel_week, q.threshold_at_quarantine,
                 d.Date, d.Time, d.Sci_Name, d.Com_Name, d.Confidence, d.File_Name
          FROM quarantined_detections q
          JOIN detections d ON d.ROWID = q.detection_id
          ORDER BY q.quarantined_at DESC
          LIMIT 500";
  $res = $db->query($sql);
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $r;
  }
}
$db->close();

/* ---- Helpers ---- */
function pp_q_audio_src($row, $home) {
  // perchpi:quarantine-page-features-v2 — bug fix: filesystem uses Com_Name
  // for species subdir (e.g. Northern_Cardinal/), not Sci_Name (Cardinalis_cardinalis/).
  // Sanitize: spaces -> underscores, apostrophes removed (matches recording pipeline).
  $com_us = str_replace([' ', "'"], ['_', ''], $row['Com_Name']);
  $date   = $row['Date'];
  $fname  = $row['File_Name'];
  return "By_Date/" . rawurlencode($date) . "/" . rawurlencode($com_us) . "/" . rawurlencode($fname);
}

// perchpi:quarantine-labels-v2 — pretty labels for badges
/**
 * perchpi:quarantine-local-time-v2
 * Format a UTC datetime string (as stored in quarantined_detections.quarantined_at)
 * in the page's configured local timezone, using the same "Y-m-d H:i" layout
 * the "When detected" column uses. Accepts both ISO 8601 ("...T...Z") and
 * plain "YYYY-MM-DD HH:MM:SS" forms. Falls back to the raw string on parse
 * failure so the table never breaks if the column ever contains something
 * unexpected.
 */
function pp_q_fmt_local($s) {
    if ($s === null || $s === '' ) return '—';
    try {
        // Normalize: strip trailing Z, swap T for space — works for both forms.
        $norm = str_replace(['T', 'Z'], [' ', ''], $s);
        $dt = new DateTime($norm, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i');
    } catch (Exception $e) {
        return $s; // safe fallback — show raw value if parsing failed
    }
}

/**
 * perchpi:quarantine-lag-column-v1
 * Compute the time delta between a detection (local time, from the
 * detections table: Date + Time columns) and a quarantine event (UTC,
 * from quarantined_detections.quarantined_at) and format as a compact
 * human string: "+0s", "+45s", "+5m", "+2h 30m", "+2d 4h".
 *
 * Sub-second deltas are rendered as "+0s" for consistency.
 * Negative deltas (clock skew or strange data) are abs()'d so output
 * is always positive.
 * Returns "—" if either input is null/empty/unparseable.
 */
function pp_q_fmt_lag($detected_date, $detected_time, $quarantined_utc) {
    if (!$detected_date || !$detected_time || !$quarantined_utc) return '—';
    try {
        $tz_local = new DateTimeZone(date_default_timezone_get());
        // Detection time is stored in local time (BirdNET-Pi convention)
        $dt_detect = new DateTime($detected_date . ' ' . $detected_time, $tz_local);
        // Quarantine time is UTC — accept "T...Z" or "YYYY-MM-DD HH:MM:SS"
        $norm = str_replace(['T', 'Z'], [' ', ''], $quarantined_utc);
        $dt_quarantine = new DateTime($norm, new DateTimeZone('UTC'));
        $secs = abs($dt_quarantine->getTimestamp() - $dt_detect->getTimestamp());

        if ($secs < 60)            return '+' . $secs . 's';
        if ($secs < 3600) {
            $m = (int)floor($secs / 60);
            return '+' . $m . 'm';
        }
        if ($secs < 86400) {
            $h = (int)floor($secs / 3600);
            $m = (int)floor(($secs % 3600) / 60);
            return $m ? ('+' . $h . 'h ' . $m . 'm') : ('+' . $h . 'h');
        }
        $d = (int)floor($secs / 86400);
        $h = (int)floor(($secs % 86400) / 3600);
        return $h ? ('+' . $d . 'd ' . $h . 'h') : ('+' . $d . 'd');
    } catch (Exception $e) {
        return '—';
    }
}

function pp_q_reason_label($r) {
  switch ($r) {
    case 'geomodel_rare':                   return 'Geomodel rare';
    case 'geomodel_rare_backfill':          return 'Backfilled (historical)';
    case 'geomodel_rare_post_patch_orphan': return 'Backfilled (orphan)';
    case 'geomodel_no_data':                return 'Not in geomodel';
    case 'user_hidden':                     return 'User hidden';
    case 'ai-flagged':                      return 'AI flagged';
    case 'manual':                          return 'Manual';
    default:                                return htmlspecialchars($r);
  }
}
?>
<style>
  /* perchpi:quarantine-page-styles */
  .pp-q-page { padding: var(--space-4, 16px); }
  .pp-q-meta { color: var(--text-muted, #999); margin-bottom: var(--space-4, 16px); font-size: 0.9em; }
  .pp-q-meta code { background: var(--surface-2, #2a2a2a); padding: 1px 6px; border-radius: 3px; }

  .pp-q-empty {
    background: var(--surface-2, #2a2a2a);
    border: 1px solid var(--border-soft, #3a3a3a);
    border-radius: var(--radius-md, 8px);
    padding: var(--space-6, 32px);
    text-align: center;
    color: var(--text-muted, #999);
  }
  .pp-q-empty-icon { font-size: 3em; margin-bottom: var(--space-2, 8px); }
  .pp-q-empty-title { color: var(--text, #eee); font-size: 1.2em; margin-bottom: var(--space-2, 8px); }

  .pp-q-bulk-bar {
    display: none;
    align-items: center;
    gap: var(--space-3, 12px);
    background: var(--surface-2, #2a2a2a);
    border: 1px solid var(--border-soft, #3a3a3a);
    border-radius: var(--radius-sm, 4px);
    padding: var(--space-2, 8px) var(--space-3, 12px);
    margin-bottom: var(--space-3, 12px);
    position: sticky;
    top: 0;
    z-index: 5;
  }
  .pp-q-bulk-bar.is-active { display: flex; }
  .pp-q-bulk-count { color: var(--text-muted, #999); font-size: 0.95em; }

  .pp-q-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--surface-2, #2a2a2a);
    border: 1px solid var(--border-soft, #3a3a3a);
    border-radius: var(--radius-sm, 4px);
    overflow: hidden;
  }
  .pp-q-table th, .pp-q-table td {
    padding: var(--space-2, 8px) var(--space-3, 12px);
    border-bottom: 1px solid var(--border-soft, #3a3a3a);
    text-align: left;
    font-size: 0.92em;
    vertical-align: middle;
  }
  .pp-q-table th { background: rgba(255,255,255,0.03); color: var(--text-muted, #999); font-weight: 500; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.05em; }
  .pp-q-table tr:last-child td { border-bottom: none; }
  .pp-q-table tr.is-selected { background: rgba(255,200,0,0.05); }

  .pp-q-sci { color: var(--text-muted, #999); font-style: italic; font-size: 0.85em; }
  .pp-q-prob { color: #d77; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.9em; }
  .pp-q-prob-geo { color: #f0a020; font-variant-numeric: tabular-nums; } /* perchpi:q-prob-amber-3dec-v1 */
  .pp-q-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    background: rgba(255,160,0,0.15);
    color: #f0a040;
    font-size: 0.8em;
    font-weight: 500;
    border: 1px solid rgba(255,160,0,0.3);
    white-space: nowrap;
  }
  .pp-q-when { color: var(--text-muted, #999); font-size: 0.85em; white-space: nowrap; }

  .pp-q-actions { display: flex; gap: 6px; flex-wrap: wrap; }
  .pp-q-btn {
    padding: 4px 10px;
    border-radius: 4px;
    border: 1px solid var(--border-soft, #3a3a3a);
    background: var(--surface-3, #333);
    color: var(--text, #eee);
    font-size: 0.85em;
    cursor: pointer;
    font-family: inherit;
  }
  .pp-q-btn:hover { background: var(--surface-hover, #444); }
  .pp-q-btn--danger { color: #f88; border-color: rgba(255,100,100,0.3); }
  .pp-q-btn--danger:hover { background: rgba(255,100,100,0.1); }
  .pp-q-btn--primary { color: #8c8; border-color: rgba(100,255,100,0.3); }
  .pp-q-btn--primary:hover { background: rgba(100,255,100,0.1); }

  .pp-q-audio audio { width: 220px; height: 30px; }

  .pp-q-warning {
    background: rgba(255,160,0,0.1);
    border: 1px solid rgba(255,160,0,0.3);
    border-radius: var(--radius-sm, 4px);
    padding: var(--space-2, 8px) var(--space-3, 12px);
    color: #f0a040;
    margin-bottom: var(--space-3, 12px);
    font-size: 0.9em;
  }
</style>

<div class="page-header">
  <h1>🚫 Quarantine</h1>
  <p class="page-header-subtitle">
    Detections hidden from main views by automatic filters.
    Threshold: probability &lt; <?php echo number_format($threshold, 3); ?>
  </p>
</div>

<div class="pp-q-page">

<?php if (!$use_geomodel): ?>
  <div class="pp-q-warning">
    ⚠️ Geomodel is currently <strong>disabled</strong> in Settings. New detections won't be quarantined automatically.
    Existing rows below were quarantined when the geomodel was last active.
  </div>
<?php endif; ?>

<?php if ($total === 0): ?>

  <div class="pp-q-empty">
    <div class="pp-q-empty-icon">✨</div>
    <div class="pp-q-empty-title">No quarantined detections</div>
    <p>The geomodel will tag rare species automatically as new detections come in.</p>
    <p style="margin-top: var(--space-3, 12px); font-size: 0.85em;">
      Currently filtering at <code><?php echo number_format($threshold, 3); ?></code>.
      Adjust the threshold in <a href="?view=Settings" style="color: var(--accent, #6ad);">Settings</a>.
    </p>
  </div>

<?php else: ?>

  <div class="pp-q-meta">
    Showing <?php echo count($rows); ?> of <?php echo $total; ?> quarantined detections
    <?php if ($total > count($rows)): ?>(most recent 500)<?php endif; ?>.
  </div>

  <div class="pp-q-bulk-bar" id="ppQBulkBar">
    <span class="pp-q-bulk-count"><span id="ppQSelCount">0</span> selected</span>
    <button class="pp-q-btn pp-q-btn--primary" id="ppQBulkUnhide">Unhide selected</button>
    <button class="pp-q-btn pp-q-btn--danger"  id="ppQBulkDelete">Delete selected</button>
    <button class="pp-q-btn" id="ppQBulkClear">Clear selection</button>
  </div>

  <!-- perchpi:quarantine-table-layout-v1 — horizontal-scroll container -->
  <div class="pp-q-table-wrap">
  <table class="pp-q-table" id="ppQTable">
    <?php /* perchpi:quarantine-page-features-v2 — sortable headers */ ?>
    <thead>
      <tr>
        <th style="width: 28px;"><input type="checkbox" id="ppQAll" aria-label="Select all"></th>
        <th><button type="button" class="pp-q-sort-btn" data-sort-key="when"
                    data-default-dir="desc">When detected <span class="pp-q-sort-arrow"></span></button></th>
        <th><button type="button" class="pp-q-sort-btn" data-sort-key="species"
                    data-default-dir="asc">Species <span class="pp-q-sort-arrow"></span></button></th>
        <th>Audio</th>
        <th><button type="button" class="pp-q-sort-btn" data-sort-key="prob"
                    data-default-dir="desc">Geomodel prob <span class="pp-q-sort-arrow"></span></button></th>
        <th><button type="button" class="pp-q-sort-btn" data-sort-key="reason"
                    data-default-dir="asc">Reason <span class="pp-q-sort-arrow"></span></button></th>
        <th><button type="button" class="pp-q-sort-btn" data-sort-key="qat"
                    data-default-dir="desc">Quarantine lag <span class="pp-q-sort-arrow"></span></button></th>
        <th style="width: 180px;">Actions</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr data-id="<?php echo (int)$r['detection_id']; ?>"
          data-sort-when="<?php echo htmlspecialchars($r['Date'] . ' ' . $r['Time']); ?>"
          data-sort-species="<?php echo htmlspecialchars(strtolower($r['Com_Name'])); ?>"
          data-sort-prob="<?php echo (float)($r['geomodel_probability'] ?? 0); ?>"
          data-sort-reason="<?php echo htmlspecialchars(strtolower($r['reason'] ?? '')); ?>"
          data-sort-qat="<?php echo htmlspecialchars($r['quarantined_at'] ?? ''); ?>">
        <td><input type="checkbox" class="pp-q-rowcheck" value="<?php echo (int)$r['detection_id']; ?>" aria-label="Select row"></td>
        <td class="pp-q-when"><?php echo htmlspecialchars($r['Date']); ?> <?php echo htmlspecialchars(substr($r['Time'], 0, 5)); ?></td>
        <td>
          <?php /* perchpi:quarantine-page-features-v2 — clickable species name */ ?>
          <a class="pp-q-species-link" href="?view=Species Profile&species=<?php echo rawurlencode($r['Sci_Name']); ?>&from=Quarantine">
            <strong><?php echo htmlspecialchars($r['Com_Name']); ?></strong><br>
            <span class="pp-q-sci"><?php echo htmlspecialchars($r['Sci_Name']); ?></span>
          </a>
        </td>
        <td class="pp-q-audio">
          <audio controls preload="none" src="<?php echo htmlspecialchars(pp_q_audio_src($r, $home)); ?>"></audio>
        </td>
        <td class="pp-q-prob">
          <?php
            /* perchpi:q-prob-amber-3dec-v1 */
            if ($r['geomodel_probability'] !== null) {
              printf('<span class="pp-q-prob-geo">%s</span>',
                     number_format($r['geomodel_probability'], 3));
            } else {
              echo '—';
            }
          ?>
        </td>
        <td><span class="pp-q-pill"><?php echo pp_q_reason_label($r['reason']); ?></span></td>
        <td class="pp-q-when" title="<?php echo htmlspecialchars(pp_q_fmt_local($r['quarantined_at'] ?? null)); ?>"><?php echo htmlspecialchars(pp_q_fmt_lag($r['Date'] ?? null, $r['Time'] ?? null, $r['quarantined_at'] ?? null)); ?></td>
        <td>
          <div class="pp-q-actions">
            <button class="pp-q-btn pp-q-btn--primary pp-q-unhide" data-id="<?php echo (int)$r['detection_id']; ?>" title="Restore to main views">Unhide</button>
            <button class="pp-q-btn pp-q-btn--danger  pp-q-delete" data-id="<?php echo (int)$r['detection_id']; ?>" title="Permanently delete this detection">Delete</button>
          </div>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div><!-- /perchpi:quarantine-table-layout-v1 pp-q-table-wrap -->

<?php endif; ?>

</div>

<script>
(function() {
  // perchpi:quarantine-page-js-v1
  var bulkBar  = document.getElementById('ppQBulkBar');
  var allCheck = document.getElementById('ppQAll');
  var selCount = document.getElementById('ppQSelCount');
  var rowChecks = document.querySelectorAll('.pp-q-rowcheck');

  if (!rowChecks.length) return; // empty state, nothing to wire

  function getSelected() {
    return Array.from(rowChecks).filter(c => c.checked).map(c => parseInt(c.value, 10));
  }

  function updateSelUI() {
    var ids = getSelected();
    selCount.textContent = ids.length;
    bulkBar.classList.toggle('is-active', ids.length > 0);
    rowChecks.forEach(c => {
      c.closest('tr').classList.toggle('is-selected', c.checked);
    });
    if (allCheck) {
      if (ids.length === 0) {
        allCheck.checked = false;
        allCheck.indeterminate = false;
      } else if (ids.length === rowChecks.length) {
        allCheck.checked = true;
        allCheck.indeterminate = false;
      } else {
        allCheck.indeterminate = true;
      }
    }
  }

  rowChecks.forEach(c => c.addEventListener('change', updateSelUI));

  if (allCheck) {
    allCheck.addEventListener('change', function() {
      rowChecks.forEach(c => { c.checked = allCheck.checked; });
      updateSelUI();
    });
  }

  function postIds(url, ids) {
    var fd = new FormData();
    ids.forEach(id => fd.append('detection_ids[]', id));
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin',
                        headers: { 'X-CSRF-Token': window.PP_CSRF || '' } })  // perchpi:s21a-csrf-v1
      .then(r => r.json().catch(() => ({ ok: false, error: 'bad JSON (HTTP ' + r.status + ')' })));
  }

  function actOnIds(url, confirmMsg, ids) {
    if (!ids.length) return;
    if (confirmMsg && !confirm(confirmMsg)) return;
    postIds(url, ids).then(j => {
      if (j && j.ok) {
        location.reload();
      } else {
        alert('Action failed: ' + (j && j.error ? j.error : 'unknown'));
      }
    }).catch(err => {
      alert('Network error: ' + err);
    });
  }

  document.querySelectorAll('.pp-q-unhide').forEach(btn => {
    btn.addEventListener('click', function() {
      var id = parseInt(btn.dataset.id, 10);
      actOnIds('quarantine_unhide.php', null, [id]);
    });
  });
  document.querySelectorAll('.pp-q-delete').forEach(btn => {
    btn.addEventListener('click', function() {
      var id = parseInt(btn.dataset.id, 10);
      actOnIds('quarantine_delete.php', 'Permanently delete this detection? The detection row will be removed from the database.', [id]);
    });
  });

  document.getElementById('ppQBulkUnhide').addEventListener('click', function() {
    actOnIds('quarantine_unhide.php', null, getSelected());
  });
  document.getElementById('ppQBulkDelete').addEventListener('click', function() {
    var ids = getSelected();
    actOnIds('quarantine_delete.php', 'Permanently delete ' + ids.length + ' detections? This cannot be undone.', ids);
  });
  document.getElementById('ppQBulkClear').addEventListener('click', function() {
    rowChecks.forEach(c => c.checked = false);
    if (allCheck) allCheck.checked = false;
    updateSelUI();
  });
})();
</script>


<!-- perchpi:quarantine-page-features-v2 — sortable headers + species link styling -->
<style>
  .pp-q-species-link {
    text-decoration: none;
    color: inherit;
    display: block;
    transition: opacity 120ms ease;
  }
  .pp-q-species-link:hover { opacity: 0.75; }
  .pp-q-species-link:hover strong { text-decoration: underline; }

  .pp-q-sort-btn {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    padding: 0;
    font: inherit;
    font-weight: inherit;
    text-align: left;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    width: 100%;
  }
  .pp-q-sort-btn:hover { opacity: 0.85; }
  .pp-q-sort-btn:focus-visible {
    outline: 2px solid var(--accent, #7be07b);
    outline-offset: 2px;
    border-radius: 2px;
  }
  .pp-q-sort-arrow {
    font-size: 0.7em;
    opacity: 0;
    margin-left: 2px;
  }
  .pp-q-sort-btn.is-active .pp-q-sort-arrow { opacity: 0.8; }
</style>

<script>
(function() {
  'use strict';
  // perchpi:quarantine-page-features-v2
  // Client-side sort for the Quarantine table.

  var table = document.getElementById('ppQTable');
  if (!table) return;
  var tbody = table.querySelector('tbody');
  if (!tbody) return;

  var buttons = Array.prototype.slice.call(table.querySelectorAll('.pp-q-sort-btn'));
  var NUMERIC = { prob: true };
  var curKey = null;
  var curDir = null; // 'asc' or 'desc'

  function getVal(row, key) {
    var v = row.getAttribute('data-sort-' + key);
    if (NUMERIC[key]) return parseFloat(v) || 0;
    return (v || '').toLowerCase();
  }

  function applySort(key, dir) {
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    rows.sort(function(a, b) {
      var av = getVal(a, key);
      var bv = getVal(b, key);
      if (av < bv) return dir === 'asc' ? -1 : 1;
      if (av > bv) return dir === 'asc' ? 1 : -1;
      return 0;
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
  }

  function updateButtonUI() {
    buttons.forEach(function(btn) {
      var btnKey = btn.getAttribute('data-sort-key');
      var arrowEl = btn.querySelector('.pp-q-sort-arrow');
      var isActive = (btnKey === curKey);
      btn.classList.toggle('is-active', isActive);
      if (isActive) {
        arrowEl.innerHTML = (curDir === 'asc') ? '&#9650;' : '&#9660;';
      } else {
        arrowEl.innerHTML = '';
      }
    });
  }

  buttons.forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      var key = btn.getAttribute('data-sort-key');
      var defDir = btn.getAttribute('data-default-dir') || 'desc';
      var newDir;
      if (key === curKey) {
        newDir = (curDir === defDir) ? (defDir === 'desc' ? 'asc' : 'desc') : defDir;
      } else {
        newDir = defDir;
      }
      curKey = key;
      curDir = newDir;
      applySort(curKey, curDir);
      updateButtonUI();
    });
  });
})();
</script>


<!-- perchpi:quarantine-audio-cleanup-v2 — strip volume slider only, enlarge player -->
<style>
  /* Bigger audio player on the Quarantine page only */
  .pp-q-audio audio {
    width: 400px;
    height: 50px;
  }

  /* Hide the volume slider area (mute button + slider).
     KEEP ::-webkit-media-controls-overflow-button so users still have
     Download and Playback Speed options in the three-dot menu. */
  .pp-q-audio audio::-webkit-media-controls-mute-button,
  .pp-q-audio audio::-webkit-media-controls-volume-slider,
  .pp-q-audio audio::-webkit-media-controls-volume-control-container,
  .pp-q-audio audio::-webkit-media-controls-volume-control-hover-background {
    display: none !important;
  }
</style>

<script>
(function() {
  'use strict';
  // perchpi:quarantine-audio-cleanup-v2
  // Set volume=1.0 on all Quarantine audio players. Without the slider in
  // the UI the user can't bump volume up, so ensure they start at 100%.
  document.querySelectorAll('.pp-q-audio audio').forEach(function(a) {
    try { a.volume = 1.0; } catch (e) { /* read-only on some platforms */ }
  });
})();
</script>


<!-- perchpi:quarantine-audio-compact-v1 — compact play → expanded audio -->
<script>
(function() {
  'use strict';

  function transformCells() {
    var cells = document.querySelectorAll('.pp-q-audio');
    cells.forEach(function(cell) {
      // Avoid double-init if this script runs twice for any reason
      if (cell.querySelector('.pp-q-play-toggle')) return;
      var audio = cell.querySelector('audio');
      if (!audio) return;
      audio.removeAttribute('controls');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pp-q-play-toggle';
      btn.setAttribute('aria-label', 'Play audio');
      btn.textContent = '▶';
      cell.insertBefore(btn, audio);

      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        expandCell(cell);
      });

      // When this audio ends naturally, collapse + stop (matches user spec).
      audio.addEventListener('ended', function() {
        collapseCell(cell);
      });
    });
  }

  function expandCell(cell) {
    // Collapse any other expanded cell first (Q2-main: only one at a time)
    document.querySelectorAll('.pp-q-audio.pp-q-audio--expanded').forEach(function(other) {
      if (other !== cell) collapseCell(other);
    });
    var audio = cell.querySelector('audio');
    if (!audio) return;
    audio.setAttribute('controls', '');
    cell.classList.add('pp-q-audio--expanded');
    // Auto-play (Q1: a)
    var p = audio.play();
    if (p && typeof p.catch === 'function') {
      p.catch(function() { /* ignore autoplay rejections */ });
    }
  }

  function collapseCell(cell) {
    var audio = cell.querySelector('audio');
    if (audio) {
      audio.pause();
      // Reset playhead — Q2-sub (i): stop on collapse, clean state next time
      try { audio.currentTime = 0; } catch (e) {}
      audio.removeAttribute('controls');
    }
    cell.classList.remove('pp-q-audio--expanded');
  }

  // Document-level click handler: clicks OUTSIDE the expanded cell collapse it.
  document.addEventListener('click', function(e) {
    var expanded = document.querySelector('.pp-q-audio.pp-q-audio--expanded');
    if (!expanded) return;
    if (expanded.contains(e.target)) return; // click inside the expanded cell
    collapseCell(expanded);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', transformCells);
  } else {
    transformCells();
  }
})();
</script>

