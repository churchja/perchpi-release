<?php
/* ============================================================================
   perchpi_helpers.php — shared PHP helpers for new list/detail pages
   ============================================================================
   - Back button rendering (with appropriate href + swipe-back data attribute)
   - Time bucket assignment (which period of day a HH:MM:SS falls into)
   - Bucket ordering by current period first, then chronological
   - Compact time formatting

   Build: Phase C-2.2.3
   ============================================================================ */

// perchpi:photo-helpers-phase26a -- ensure common.php helpers (get_db, ensure_db_ok, get_home) are available
if (file_exists(__DIR__ . '/../scripts/common.php')) { require_once __DIR__ . '/../scripts/common.php'; }

/**
 * Render a back-nav button bar at the top of a list/detail page.
 *
 * @param string $back_href  URL to navigate to on click (e.g. "?view=Overview")
 * @param string $back_label Label for the link (e.g. "Overview", "Birds Today")
 * @param string $title      Page title to show next to the back button
 * @param string $subtitle   Optional subtitle (e.g. count, time range)
 */
function pp_render_back_nav($back_href, $back_label, $title, $subtitle = '') {
  $back_href_h  = htmlspecialchars($back_href,  ENT_QUOTES, 'UTF-8');
  $back_label_h = htmlspecialchars($back_label, ENT_QUOTES, 'UTF-8');
  $title_h      = htmlspecialchars($title,      ENT_QUOTES, 'UTF-8');
  $subtitle_h   = $subtitle ? htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') : '';

  echo '<div class="page-header pp-back-nav" data-back-href="' . $back_href_h . '">';
  echo '  <a href="' . $back_href_h . '" class="pp-back-link" aria-label="Back to ' . $back_label_h . '">';
  echo '    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
  echo '      <line x1="19" y1="12" x2="5" y2="12"></line>';
  echo '      <polyline points="12 19 5 12 12 5"></polyline>';
  echo '    </svg>';
  echo '    <span>' . $back_label_h . '</span>';
  echo '  </a>';
  // perchpi:species-hero-dedup-v1 — skip title-wrap entirely when title is empty
  if ($title !== '') {
    echo '  <div class="pp-back-title-wrap">';
    echo '    <h1 class="pp-back-title">' . $title_h . '</h1>';
    if ($subtitle_h !== '') {
      echo '    <span class="pp-back-subtitle">' . $subtitle_h . '</span>';
    }
    echo '  </div>';
  }
  echo '</div>';
}

/**
 * Time bucket definitions.
 * Each bucket: id, label, start hour (inclusive), end hour (inclusive).
 * Night wraps midnight (21:00–04:59).
 */
function pp_buckets() {
  return [
    'dawn'      => ['label' => 'Dawn',      'start' =>  5, 'end' =>  7,  'desc' => '5–7:59 AM'],
    'morning'   => ['label' => 'Morning',   'start' =>  8, 'end' => 11,  'desc' => '8–11:59 AM'],
    'afternoon' => ['label' => 'Afternoon', 'start' => 12, 'end' => 16,  'desc' => '12–4:59 PM'],
    'evening'   => ['label' => 'Evening',   'start' => 17, 'end' => 20,  'desc' => '5–8:59 PM'],
    'night'     => ['label' => 'Night',     'start' => 21, 'end' =>  4,  'desc' => '9 PM–4:59 AM'],
  ];
}

/**
 * Given a 24-hour hour value (0-23), return the bucket id it belongs to.
 */
function pp_bucket_for_hour($hour) {
  $hour = (int)$hour;
  if ($hour >= 5  && $hour <= 7)   return 'dawn';
  if ($hour >= 8  && $hour <= 11)  return 'morning';
  if ($hour >= 12 && $hour <= 16)  return 'afternoon';
  if ($hour >= 17 && $hour <= 20)  return 'evening';
  return 'night'; // 21-23 or 0-4
}

/**
 * Given a HH:MM:SS time string, return the bucket id.
 */
function pp_bucket_for_time($time_str) {
  $hour = (int)substr($time_str, 0, 2);
  return pp_bucket_for_hour($hour);
}

/**
 * Return bucket ids in display order: current period first, then chronological
 * forward through the rest. Example: if current is 'afternoon', returns
 * ['afternoon', 'evening', 'night', 'dawn', 'morning'].
 */
function pp_ordered_bucket_ids() {
  $now_hour = (int)date('G');
  $current  = pp_bucket_for_hour($now_hour);
  $sequence = ['dawn', 'morning', 'afternoon', 'evening', 'night'];
  $start_idx = array_search($current, $sequence);
  if ($start_idx === false) $start_idx = 0;
  $ordered = [];
  for ($i = 0; $i < count($sequence); $i++) {
    $ordered[] = $sequence[($start_idx + $i) % count($sequence)];
  }
  return $ordered;
}

/**
 * Group an array of detection rows by bucket id.
 * Returns an associative array of bucket_id => array of detection rows.
 * Each bucket key is present even if empty (so callers can render "No detections").
 */
function pp_group_detections_by_bucket($detections) {
  $buckets = ['dawn' => [], 'morning' => [], 'afternoon' => [], 'evening' => [], 'night' => []];
  foreach ($detections as $d) {
    $bid = pp_bucket_for_time($d['Time'] ?? '00:00:00');
    $buckets[$bid][] = $d;
  }
  return $buckets;
}

/**
 * Format HH:MM:SS as "h:mm AM/PM" for display.
 */
function pp_format_time($time_str) {
  $h = (int)substr($time_str, 0, 2);
  $m = substr($time_str, 3, 2);
  $ampm = $h >= 12 ? 'PM' : 'AM';
  $h12 = $h % 12; if ($h12 === 0) $h12 = 12;
  return $h12 . ':' . $m . ' ' . $ampm;
}

/**
 * Format HH:MM:SS as "HH:MM" (24-hour, compact).
 */
function pp_format_time_compact($time_str) {
  return substr($time_str, 0, 5);
}

/**
 * Render a kiosk-style today-item row for a detection.
 * Used by today_list.php, last_hour_list.php, and species_detail.php buckets.
 */
function pp_render_today_item($row) {
  // We need thumbnail_info_for() and audio_url_for() from kiosk.php's
  // namespace. The caller must have included kiosk.php (or these functions
  // must be loaded another way).
  if (!function_exists('thumbnail_info_for')) {
    echo '<div class="empty">Internal error: thumbnail_info_for not loaded.</div>';
    return;
  }
  $info  = thumbnail_info_for($row);
  $audio = audio_url_for($row);

  $com  = htmlspecialchars($row['Com_Name'] ?? '', ENT_QUOTES);
  $sci  = htmlspecialchars($row['Sci_Name'] ?? '', ENT_QUOTES);
  $time = htmlspecialchars(pp_format_time_compact($row['Time'] ?? ''), ENT_QUOTES);
  $conf = round(($row['Confidence'] ?? 0) * 100);
  $thumb = $info['thumb'];
  $is_bird = $info['is_bird_photo'] ? '1' : '0';
  $artist  = htmlspecialchars($info['meta']['artist']         ?? '', ENT_QUOTES);
  $license = htmlspecialchars($info['meta']['license']        ?? '', ENT_QUOTES);
  $source  = htmlspecialchars($info['meta']['descriptionurl'] ?? '', ENT_QUOTES);
  $thumb_class = 'today-thumb' . ($info['is_bird_photo'] ? ' thumb-tappable' : '');

  echo '<div class="today-item" data-audio="' . htmlspecialchars($audio, ENT_QUOTES) . '">';
  echo '  <div class="' . $thumb_class . '"';
  echo '       data-is-bird-photo="' . $is_bird . '"';
  echo '       data-common="' . $com . '"';
  echo '       data-sci="' . $sci . '"';
  echo '       data-photo-artist="' . $artist . '"';
  echo '       data-photo-license="' . $license . '"';
  echo '       data-photo-source="' . $source . '">';
  if ($thumb) {
    echo '<img src="' . htmlspecialchars($thumb, ENT_QUOTES) . '" alt="">';
  } else {
    echo '<div class="thumb-placeholder-md"></div>';
  }
  echo '  </div>';
  echo '  <div class="today-body">';
  echo '    <div class="today-name">' . $com . '</div>';
  echo '    <div class="today-sci">' . $sci . '</div>';
  echo '    <div class="today-meta">';
  echo '      <span class="chip chip-time">' . $time . '</span>';
  echo '      <span class="chip chip-conf">' . $conf . '%</span>';
  echo '    </div>';
  echo '  </div>';
  echo '  <button class="play-btn play-btn-md" aria-label="Play">';
  echo '    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
  echo '  </button>';
  echo '</div>';
}

/**
 * Format a YYYY-MM-DD date as a friendly label.
 * - "Today" / "Yesterday" for those days
 * - Weekday + month + day for last 7 days     ("Tuesday, Apr 28")
 * - Month + day for current year              ("Apr 24")
 * - Month + day + year for prior years        ("Apr 24, 2025")
 *
 * @param string $date_str  YYYY-MM-DD format
 * @return string Friendly label
 */
function pp_format_date_friendly($date_str) {
  if (!$date_str) return '';
  $ts = strtotime($date_str);
  if ($ts === false) return $date_str;

  $today_ts     = strtotime(date('Y-m-d'));
  $yesterday_ts = strtotime('-1 day', $today_ts);
  $week_ago_ts  = strtotime('-7 days', $today_ts);

  if ($ts === $today_ts)     return 'Today';
  if ($ts === $yesterday_ts) return 'Yesterday';

  $current_year = (int)date('Y');
  $date_year    = (int)date('Y', $ts);

  if ($ts > $week_ago_ts && $ts < $today_ts) {
    // Within last 7 days (but not today/yesterday): include weekday.
    return date('l, M j', $ts);  // e.g. "Tuesday, Apr 28"
  }
  if ($date_year === $current_year) {
    return date('M j', $ts);     // e.g. "Apr 24"
  }
  return date('M j, Y', $ts);    // e.g. "Apr 24, 2025"
}

/* ============================================================================
   perchpi_helpers.php — geomodel addition (Session C)
   ============================================================================
   PerchPi marker: perchpi:geomodel-helpers-v1

   Public functions:
     pp_geomodel_cache()              -> array|null   (predictions.json contents)
     pp_geomodel_species($sci_name)   -> array|null   (one species' record)
     pp_geomodel_pill($sci_name)      -> string       (HTML pill or '')
     pp_geomodel_phenology_svg($sci)  -> string       (SVG curve or '')
     pp_geomodel_inject_css()         -> echo'd <style> block (idempotent)

   Cache file: $GEOMODEL_DIR/predictions.json (default
   ~/BirdNET-Pi/geomodel/predictions.json), written weekly by
   scripts/utils/predict_geomodel_weekly.py via systemd timer.
   ============================================================================ */

if (!function_exists('pp_geomodel_cache')):

/**
 * Load and cache the geomodel predictions.json file.
 * Returns the parsed array, or null if not available / unparseable.
 *
 * Cached at static-var scope: subsequent calls in the same request hit memory.
 */
function pp_geomodel_cache() {
  static $cache = false;  // sentinel for "not loaded yet"
  if ($cache !== false) return $cache;

  // Resolve geomodel dir from conf, fall back to default
  $home = function_exists('get_home') ? get_home() : '/home/birder';
  $geomodel_dir = $home . '/BirdNET-Pi/geomodel';
  $conf_path = '/etc/birdnet/birdnet.conf';
  if (is_readable($conf_path)) {
    $lines = @file($conf_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
      foreach ($lines as $line) {
        if (preg_match('/^\s*GEOMODEL_DIR\s*=\s*(.+)$/', $line, $m)) {
          $geomodel_dir = trim($m[1], "\"' \t");
          break;
        }
      }
    }
  }

  $json_path = rtrim($geomodel_dir, '/') . '/predictions.json';
  if (!is_readable($json_path)) {
    $cache = null;
    return null;
  }
  $raw = @file_get_contents($json_path);
  if ($raw === false || $raw === '') {
    $cache = null;
    return null;
  }
  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['species']) || !isset($data['metadata'])) {
    $cache = null;
    return null;
  }
  $cache = $data;
  return $cache;
}

/**
 * Return the geomodel record for one species, or null.
 *
 * Shape: ['code' => str, 'common' => str, 'current' => float,
 *         'bucket' => 'expected'|'uncommon'|'rare', 'weeks' => float[48]]
 */
function pp_geomodel_species($sci_name) {
  // perchpi:taxonomy-alias-map-v1 — BirdNET ships with older AOS taxonomy;
  // the geomodel uses post-2024 AOS taxonomy. Without these aliases, affected
  // species silently lose geomodel data (no chart, no quarantine, no pill).
  // Future AOS updates: add new mappings here AND in scripts/utils/reporting.py
  // (search for marker perchpi:taxonomy-alias-map-v1 in both files).
  static $taxonomy_aliases = [
    'Accipiter cooperii'         => 'Astur cooperii',
    'Coccothraustes vespertinus' => 'Hesperiphona vespertina',
    'Dryobates villosus'         => 'Leuconotopicus villosus',
    'Psilorhinus morio'          => 'Cyanocorax morio',
    'Apus melba'                 => 'Tachymarptis melba',
    'Basileuterus lachrymosus'   => 'Euthlypis lachrymosa',
    'Cicinnurus magnificus'      => 'Diphyllodes magnificus',
    'Dryobates borealis'         => 'Leuconotopicus borealis',
    'Ixobrychus exilis'          => 'Botaurus exilis',
    'Ixobrychus minutus'         => 'Botaurus minutus',
  ];
  $sci_name = $taxonomy_aliases[$sci_name] ?? $sci_name;

  $cache = pp_geomodel_cache();
  if (!$cache) return null;
  return $cache['species'][$sci_name] ?? null;
}

/**
 * Render a rarity pill HTML for a species. Returns empty string when no pill
 * should show: expected bucket, no geomodel data, or geomodel disabled.
 *
 * Visual idiom matches existing pp-chip-new from weekly_report.php:
 *   - expected -> no pill rendered
 *   - uncommon -> amber pill, "uncommon for now"
 *   - rare     -> rose pill, "rare for now"
 */
function pp_geomodel_pill($sci_name) {
  $sp = pp_geomodel_species($sci_name);
  if (!$sp) return '';
  $bucket = $sp['bucket'] ?? null;
  if ($bucket === 'expected' || $bucket === null) return '';

  pp_geomodel_inject_css();

  $cache = pp_geomodel_cache();
  $week  = $cache['metadata']['current_week'] ?? 0;
  $prob  = $sp['current'] ?? 0;
  $tooltip = sprintf(
    'Geomodel: %s probability at this location, week %d of 48. Click species name for the full year curve.',
    number_format((float)$prob, 3),
    (int)$week
  );

  $cls = ($bucket === 'rare')
    ? 'pp-chip pp-chip-geomodel pp-chip-rare'
    : 'pp-chip pp-chip-geomodel pp-chip-uncommon';
  $label = ($bucket === 'rare') ? 'rare for now' : 'uncommon for now';

  return '<span class="' . $cls . '" title="'
       . htmlspecialchars($tooltip, ENT_QUOTES) . '">'
       . $label . '</span>';
}

/**
 * Two-axis rarity pill for a single detection ROW (perchpi:two-axis-pill-v1).
 *
 * Unlike pp_geomodel_pill($sci_name) -- which buckets by the species' CURRENT
 * week from predictions.json -- this uses the per-detection Geomodel_Prob/Week
 * FROZEN at detection time (detections columns, since 785f7c4). For historical
 * rows (prob NULL, pre-persistence) it falls back to the species pill, so no
 * backfill is needed and old rows keep today's behavior.
 *
 * Buckets via the SAME thresholds the species pill uses (read from cache
 * metadata, not hardcoded) so current-week detections agree with the species
 * pill; only historical rows diverge (correctly -- they show frozen rarity).
 *
 * Pure display. The "notable vagrant vs FP" judgment (bird-mask) is
 * intentionally NOT here -- see perchpi:b2-mask-seam below and parking-lot
 * (cc). That decision belongs upstream, consumed by both this pill AND the
 * (bb) reporting.py veto from one home.
 *
 * @param array $row detection row with Sci_Name, Geomodel_Prob, Geomodel_Week,
 *                   Confidence (the kiosk fetchers now SELECT these).
 */
function pp_geomodel_pill_for_row($row) {
  $prob = $row['Geomodel_Prob'] ?? null;
  // Historical / unevaluated row: fall back to species-level current pill.
  if ($prob === null) {
    return pp_geomodel_pill($row['Sci_Name'] ?? '');
  }
  $prob = (float)$prob;

  // Same thresholds as predict_geomodel_weekly.py, read from cache metadata.
  $cache = pp_geomodel_cache();
  $exp_min = $cache['metadata']['buckets']['expected_min'] ?? 0.30;
  $unc_min = $cache['metadata']['buckets']['uncommon_min'] ?? 0.05;

  if ($prob >= $exp_min) return '';            // expected -> no pill
  $bucket = ($prob < $unc_min) ? 'rare' : 'uncommon';

  // perchpi:b2-mask-seam -- FUTURE (cc): consult upstream notability here to
  // distinguish low-prob BIRD (notable vagrant) from low-prob NON-bird (FP).
  // Do NOT add bird_mask logic in this display fn -- decide upstream, render here.

  pp_geomodel_inject_css();
  $week = $row['Geomodel_Week'] ?? ($cache['metadata']['current_week'] ?? 0);
  $conf = isset($row['Confidence']) ? (float)$row['Confidence'] : null;
  $tip = sprintf(
    'Geomodel: %s probability at this location, week %d of 48 (at detection time).%s'
      . ' Click species name for the full year curve.',
    number_format($prob, 3),
    (int)$week,
    $conf !== null ? sprintf(' BirdNET confidence: %s.', number_format($conf, 3)) : ''
  );
  $cls = ($bucket === 'rare')
    ? 'pp-chip pp-chip-geomodel pp-chip-rare'
    : 'pp-chip pp-chip-geomodel pp-chip-uncommon';
  $label = ($bucket === 'rare') ? 'rare for now' : 'uncommon for now';
  return '<span class="' . $cls . '" title="'
       . htmlspecialchars($tip, ENT_QUOTES) . '">' . $label . '</span>';
}

/**
 * Render a 48-week phenology curve SVG for a species, or '' if no data.
 *
 * The visualization:
 *   - X axis: 48 weeks of the year (≈ 12 month labels)
 *   - Y axis: geomodel probability 0-1, with 0.05 and 0.30 threshold lines
 *   - Background bands: rose under 0.05 (rare), amber 0.05-0.30 (uncommon)
 *   - Curve: green line with subtle area fill underneath
 *   - Today marker: vertical dashed line + green dot, with value label
 *
 * 700×200 viewBox, scales responsively via CSS.
 */
// perchpi:pheno-monotone-smooth-v1
// Fritsch-Carlson monotone cubic interpolation -> SVG cubic-Bezier path.
// Input: array of [x, y] points (already in pixel space). Output: SVG path
// string ("M ... C ...") that passes through every point with NO overshoot
// past the data values (stays pinned on flat runs, won't bow past peaks).
// Used for the geomodel line/area top edge only.
function pp_monotone_path($pts) {
  $n = count($pts);
  if ($n < 2) {
    return $n === 1 ? ('M ' . round($pts[0][0], 1) . ' ' . round($pts[0][1], 1)) : '';
  }
  // Secant slopes between consecutive points.
  $dx = array(); $dy = array(); $m = array();
  for ($i = 0; $i < $n - 1; $i++) {
    $hx = $pts[$i + 1][0] - $pts[$i][0];
    $dx[$i] = $hx;
    $dy[$i] = $pts[$i + 1][1] - $pts[$i][1];
    $m[$i]  = ($hx != 0.0) ? ($dy[$i] / $hx) : 0.0;
  }
  // Tangents at each point.
  $t = array();
  $t[0] = $m[0];
  $t[$n - 1] = $m[$n - 2];
  for ($i = 1; $i < $n - 1; $i++) {
    if ($m[$i - 1] * $m[$i] <= 0.0) {
      $t[$i] = 0.0;                       // local extremum -> flat (no overshoot)
    } else {
      $t[$i] = ($m[$i - 1] + $m[$i]) / 2.0;
    }
  }
  // Fritsch-Carlson limiter: keep tangents within the monotone region.
  for ($i = 0; $i < $n - 1; $i++) {
    if ($m[$i] == 0.0) {
      $t[$i] = 0.0; $t[$i + 1] = 0.0;
    } else {
      $a = $t[$i] / $m[$i];
      $b = $t[$i + 1] / $m[$i];
      $s = $a * $a + $b * $b;
      if ($s > 9.0) {
        $tau = 3.0 / sqrt($s);
        $t[$i]     = $tau * $a * $m[$i];
        $t[$i + 1] = $tau * $b * $m[$i];
      }
    }
  }
  // Emit cubic Beziers. Control points at 1/3 of the tangent over each interval.
  $d = 'M ' . round($pts[0][0], 1) . ' ' . round($pts[0][1], 1);
  for ($i = 0; $i < $n - 1; $i++) {
    $x0 = $pts[$i][0];     $y0 = $pts[$i][1];
    $x1 = $pts[$i + 1][0]; $y1 = $pts[$i + 1][1];
    $h  = $dx[$i];
    $c1x = $x0 + $h / 3.0;          $c1y = $y0 + ($t[$i] * $h) / 3.0;
    $c2x = $x1 - $h / 3.0;          $c2y = $y1 - ($t[$i + 1] * $h) / 3.0;
    $d .= ' C ' . round($c1x, 1) . ' ' . round($c1y, 1)
        . ' '   . round($c2x, 1) . ' ' . round($c2y, 1)
        . ' '   . round($x1, 1)  . ' ' . round($y1, 1);
  }
  return $d;
}

function pp_geomodel_phenology_svg($sci_name, $detection_counts = null) {
  $sp = pp_geomodel_species($sci_name);
  if (!$sp || empty($sp['weeks']) || count($sp['weeks']) !== 48) return '';

  pp_geomodel_inject_css();

  $cache         = pp_geomodel_cache();
  $current_week  = (int)($cache['metadata']['current_week'] ?? 1);
  $expected_min  = (float)($cache['metadata']['buckets']['expected_min'] ?? 0.30);
  $uncommon_min  = (float)($cache['metadata']['buckets']['uncommon_min'] ?? 0.05);
  $weeks         = $sp['weeks'];

  // Layout
  $W = 700; $H = 220;
  $padL = 48; $padR = 24; $padT = 44; $padB = 36;
  $plotW = $W - $padL - $padR;
  $plotH = $H - $padT - $padB;

  $x_for = function ($i) use ($padL, $plotW) {
    return $padL + ($i / 47.0) * $plotW;
  };
  $y_for = function ($p) use ($padT, $plotH) {
    $p = max(0.0, min(1.0, (float)$p));
    return $padT + (1.0 - $p) * $plotH;
  };

  // Detection overlay: gray-blue area showing the trailing-365-day detection
  // counts, bucketed by day-of-year (48 bins), normalized to species' own
  // peak. Rendered first (behind everything else). perchpi:pheno-overlay-365-v1
  $overlay_path = '';
  if (is_array($detection_counts) && count($detection_counts) === 48) {
    $max_count = max($detection_counts);
    if ($max_count > 0) {
      $overlay_d = ['M ' . round($x_for(0), 1) . ' ' . round($y_for(0), 1)];
      foreach ($detection_counts as $i => $c) {
        $norm = $c / $max_count;
        $overlay_d[] = 'L ' . round($x_for($i), 1) . ' ' . round($y_for($norm), 1);
      }
      $overlay_d[] = 'L ' . round($x_for(47), 1) . ' ' . round($y_for(0), 1) . ' Z';
      $overlay_path = '<path d="' . implode(' ', $overlay_d) . '" class="pp-pheno-detection-overlay"/>';
    }
  }

  // Curve paths
    // perchpi:pheno-monotone-smooth-v1 — smoothed geomodel line/area (monotone cubic)
  $pts = [];
  foreach ($weeks as $i => $p) {
    $pts[] = [$x_for($i), $y_for($p)];
  }
  $line_path = pp_monotone_path($pts);
  // Area = smoothed top edge, then straight down to baseline and close.
  $area_path = $line_path
    . ' L ' . round($x_for(47), 1) . ' ' . round($y_for(0), 1)
    . ' L ' . round($x_for(0), 1)  . ' ' . round($y_for(0), 1) . ' Z';

  // Threshold band rectangles
  $y_expected = $y_for($expected_min);
  $y_uncommon = $y_for($uncommon_min);
  $y_zero     = $y_for(0);

  // Today marker
  $cx = $x_for($current_week - 1);
  $cy = $y_for($weeks[$current_week - 1] ?? 0);
  $cp = $weeks[$current_week - 1] ?? 0;

  // Y-axis tick labels (0, 0.05, 0.30, 1.0)
  $y_labels = '';
  $tick = function ($val, $y, $yl) use ($padL, &$y_labels) {
    $y_labels .=
      '<line x1="' . ($padL - 3) . '" y1="' . round($y, 1)
        . '" x2="' . ($padL) . '" y2="' . round($y, 1)
        . '" class="pp-pheno-tick"/>'
      . '<text x="' . ($padL - 5) . '" y="' . round($y + 3.5, 1)
        . '" class="pp-pheno-axis-label" text-anchor="end">' . $yl . '</text>';
  };
  $tick(1.0, $y_for(1.0), '1.0');
  $tick($expected_min, $y_expected, '0.30');
  $tick($uncommon_min, $y_uncommon, '0.05');
  // $tick(0.0, $y_zero, '0');  // suppressed in v4

  // Month labels along X (4 weeks per month, approximate)
  $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  $x_labels = '';
  for ($m = 0; $m < 12; $m++) {
    $week_idx = ($m * 48) / 12;  // start of month in week-space
    $mx = $x_for($week_idx + 2);  // mid-month
    $x_labels .= '<text x="' . round($mx, 1) . '" y="' . ($H - $padB + 16)
              . '" class="pp-pheno-axis-label" text-anchor="middle">'
              . $months[$m] . '</text>';
  }

  // Today value label placement: above the dot if dot is in lower 70% of plot,
  // below the dot if dot is in upper 30% (so peak values don't crowd the top edge)
  $dot_y_norm = ($cy - $padT) / $plotH;  // 0=top, 1=bottom of plot
  if ($dot_y_norm < 0.30) {
    // Dot near top — put label below the dot
    $label_y = $cy + 22;
  } else {
    // Dot mid or low — put label above the dot
    $label_y = $cy - 14;
  }
  // Anchor right if today is on the right edge, else middle
  $label_anchor = ($current_week >= 44) ? 'end' : (($current_week <= 4) ? 'start' : 'middle');
  $week_label = sprintf('Week %d', $current_week);
  $value_label = sprintf('%.3f', $cp);
  // Week label at TOP of plot, far from the curve action
  $week_box_w = strlen($week_label) * 8 + 16;
  $week_box_h = 20;
  $week_box_x = $cx - $week_box_w / 2;
  if ($current_week >= 44) $week_box_x = $cx - $week_box_w + 4;
  elseif ($current_week <= 4) $week_box_x = $cx - 4;
  $week_box_y = 6;             // Top of SVG, above plot area entirely
  $week_label_y = $week_box_y + 14;
  // Value label near the dot (no prefix)
  $value_text_len = strlen($value_label);
  $label_box_w = $value_text_len * 8 + 16;
  $label_box_h = 20;
  if ($label_anchor === 'middle') {
    $label_box_x = $cx - $label_box_w / 2;
  } elseif ($label_anchor === 'end') {
    $label_box_x = $cx - $label_box_w + 4;
  } else {
    $label_box_x = $cx - 4;
  }
  $label_box_y = $label_y - 14;

  return
    '<svg class="pp-pheno-svg" viewBox="0 0 ' . $W . ' ' . $H
      . '" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Phenology curve">'
    // Detection overlay (rendered first so it sits behind everything else)
    . $overlay_path
    // Threshold bands
    . '<rect x="' . $padL . '" y="' . round($y_expected, 1)
        . '" width="' . $plotW . '" height="' . round($y_uncommon - $y_expected, 1)
        . '" class="pp-pheno-band-uncommon"/>'
    . '<rect x="' . $padL . '" y="' . round($y_uncommon, 1)
        . '" width="' . $plotW . '" height="' . round($y_zero - $y_uncommon, 1)
        . '" class="pp-pheno-band-rare"/>'
    // Threshold lines (subtle, on top of bands)
    . '<line x1="' . $padL . '" y1="' . round($y_expected, 1)
        . '" x2="' . ($padL + $plotW) . '" y2="' . round($y_expected, 1)
        . '" class="pp-pheno-threshold"/>'
    . '<line x1="' . $padL . '" y1="' . round($y_uncommon, 1)
        . '" x2="' . ($padL + $plotW) . '" y2="' . round($y_uncommon, 1)
        . '" class="pp-pheno-threshold"/>'
    // Area + line
    . '<path d="' . $area_path . '" class="pp-pheno-area"/>'
    . '<path d="' . $line_path . '" class="pp-pheno-line"/>'
    // Today marker
    . '<line x1="' . round($cx, 1) . '" y1="' . $padT
        . '" x2="' . round($cx, 1) . '" y2="' . ($padT + $plotH)
        . '" class="pp-pheno-today"/>'
    . '<circle cx="' . round($cx, 1) . '" cy="' . round($cy, 1)
        . '" r="4" class="pp-pheno-today-dot"/>'
    . '<rect x="' . round($week_box_x, 1) . '" y="' . $week_box_y
        . '" width="' . round($week_box_w, 1) . '" height="' . $week_box_h
        . '" rx="4" ry="4" class="pp-pheno-today-label-bg"/>'
    . '<text x="' . round($cx, 1) . '" y="' . $week_label_y
        . '" class="pp-pheno-today-label" text-anchor="' . $label_anchor . '">'
        . $week_label . '</text>'
    . '<rect x="' . round($label_box_x, 1) . '" y="' . round($label_box_y, 1)
        . '" width="' . round($label_box_w, 1) . '" height="' . $label_box_h
        . '" rx="4" ry="4" class="pp-pheno-today-label-bg"/>'
    . '<text x="' . round($cx, 1) . '" y="' . round($label_y, 1)
        . '" class="pp-pheno-today-label" text-anchor="' . $label_anchor . '">'
        . $value_label . '</text>'
    // X & Y axes
    . '<line x1="' . $padL . '" y1="' . ($padT + $plotH)
        . '" x2="' . ($padL + $plotW) . '" y2="' . ($padT + $plotH)
        . '" class="pp-pheno-axis"/>'
    . '<line x1="' . $padL . '" y1="' . $padT
        . '" x2="' . $padL . '" y2="' . ($padT + $plotH)
        . '" class="pp-pheno-axis"/>'
    // Tick + axis labels
    . $y_labels
    . $x_labels
    . '</svg>';
}

/**
 * Inject geomodel CSS once per request. Idempotent via static guard.
 *
 * Called automatically by pp_geomodel_pill() and pp_geomodel_phenology_svg(),
 * so callers don't need to invoke it explicitly.
 */
function pp_geomodel_inject_css() {
  // CSS migrated to perchpi.css on 2026-05-06 (was: inline echo of <style> block).
  // Inline injection caused the <style> tag to land mid-DOM, breaking HTML
  // structure for any pill rendered before <head> closed. Now a no-op kept
  // for backwards compatibility with existing call sites.
  // Marker: perchpi:geomodel-css-migrated-to-perchpi-css
  return;
}


/**
 * Query the detections DB for this species' weekly detection counts over
 * the last 365 days. Returns a 48-element array of integers (one per
 * BirdNET-week of year), or null on DB error / file missing.
 *
 * Cached at static-var scope per request.
 */
function pp_query_detection_weekly_counts($db_path, $sci_name) {
  static $cache = [];
  if (isset($cache[$sci_name])) return $cache[$sci_name];

  if (!file_exists($db_path)) {
    $cache[$sci_name] = null;
    return null;
  }

  // Read optional conf override that clamps the floor upward
  $conf_floor = '';
  $conf_path = '/etc/birdnet/birdnet.conf';
  if (is_readable($conf_path)) {
    foreach (file($conf_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      if (preg_match('/^\s*OVERLAY_DATA_FLOOR\s*=\s*(\d{4}-\d{2}-\d{2})/', $line, $m)) {
        $conf_floor = $m[1];
        break;
      }
    }
  }

  $counts = array_fill(0, 48, 0);
  try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(1000);

    // Earliest detection date in DB = data-availability floor
    $r0 = $db->query("SELECT MIN(Date) AS first_date FROM detections WHERE Date IS NOT NULL AND Date != '' AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */");
    $row0 = $r0 ? $r0->fetchArray(SQLITE3_ASSOC) : null;
    $db_floor = ($row0 && !empty($row0['first_date'])) ? $row0['first_date'] : '';
    if ($db_floor === '') {
      $db->close();
      $cache[$sci_name] = null;
      return null;
    }

    // Effective floor = max(db_floor, conf_floor)
    $effective_floor = ($conf_floor !== '' && strcmp($conf_floor, $db_floor) > 0)
      ? $conf_floor : $db_floor;

    // Threshold: require >= 30 days from floor before showing overlay
    $days_since_floor = (int)((time() - strtotime($effective_floor)) / 86400);
    if ($days_since_floor < 30) {
      $db->close();
      $cache[$sci_name] = null;
      return null;
    }

    $stmt = $db->prepare(
      "SELECT " .
      "  min(48, max(1, ((CAST(strftime('%j', Date) AS INTEGER) - 1) * 48 / 366) + 1)) AS bucket, " .
      "  COUNT(*) AS n " .
      "FROM detections " .
      "WHERE Sci_Name = :sci " .
      "  AND Date IS NOT NULL AND Date != '' " .
      "  AND Date >= :floor " .
      "  AND Date >= date('now', 'localtime', '-365 days') /* rolling-365 window, bounded below by floor — perchpi:pheno-overlay-365-v1 */ " .
      "  AND Date <  date('now', 'localtime', 'weekday 0', '-7 days') " .
      "  AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */ " .
      "GROUP BY bucket"
    );
    $stmt->bindValue(':sci', $sci_name, SQLITE3_TEXT);
    $stmt->bindValue(':floor', $effective_floor, SQLITE3_TEXT);
    $r = $stmt->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
      $bucket = (int)$row['bucket'];
      if ($bucket >= 1 && $bucket <= 48) {
        $counts[$bucket - 1] = (int)$row['n'];
      }
    }
    $db->close();
  } catch (Exception $e) {
    $cache[$sci_name] = null;
    return null;
  }

  $cache[$sci_name] = $counts;
  return $counts;
}

endif; /* function_exists('pp_geomodel_cache') guard */
/* End: perchpi:geomodel-helpers-v1 */
/* perchpi:geomodel-css-readability-v2 applied */
/* perchpi:geomodel-css-readability-v3 applied */
/* perchpi:geomodel-css-readability-v4 applied */
/* perchpi:geomodel-css-readability-v5 applied */
/* perchpi:detection-overlay-v1 */
/* perchpi:detection-overlay-v4 (auto-floor + threshold + label restructure) */
/* perchpi:detection-overlay-v5 (Week pill above plot, SVG height 220) */

// ════════════════════════════════════════════════════════════════════════════
// perchpi:quarantine-php-helpers-v1
// Helpers for filtering detection queries to exclude quarantined rows.
// Added by deploy-quarantine-backend-waveQ3.1a.sh.
//
// Q3.1a only ADDS these helpers (no callers yet).
// Q3.1b applies them to existing SELECT queries across the codebase.
// ════════════════════════════════════════════════════════════════════════════

/**
 * Returns a SQL fragment that filters out quarantined detection rows.
 *
 * Use as a tail-clause on any SELECT against `detections`:
 *
 *   $sql = "SELECT ... FROM detections WHERE Date = '...' "
 *        . pp_quarantine_filter_clause();
 *
 * The leading "AND " makes it composable after an existing WHERE.
 * For queries without a WHERE, use pp_quarantine_filter_clause($alias, true)
 * to get a "WHERE ..." prefix instead.
 *
 * @param string $alias Optional table alias for `detections`. Default: 'detections'.
 * @param bool   $as_where_prefix If true, returns "WHERE ..." instead of "AND ...".
 * @return string SQL fragment.
 */
function pp_quarantine_filter_clause($alias = 'detections', $as_where_prefix = false) {
  $prefix = $as_where_prefix ? ' WHERE ' : ' AND ';
  return $prefix . $alias . ".ROWID NOT IN (SELECT detection_id FROM quarantined_detections) ";
}

/**
 * Returns a SQL JOIN fragment for queries that need to filter via a LEFT JOIN
 * (for example, queries with GROUP BY where a NOT IN subquery is awkward).
 *
 * Pair with: WHERE q.detection_id IS NULL
 *
 * @param string $alias Optional table alias for `detections`. Default: 'detections'.
 * @return string SQL fragment.
 */
function pp_quarantine_join_clause($alias = 'detections') {
  return " LEFT JOIN quarantined_detections q ON q.detection_id = " . $alias . ".ROWID ";
}

/**
 * Returns the count of quarantined detections (for the count badge in the
 * Quarantine Review nav item).
 *
 * @param SQLite3|PDO $db Database handle (either type works via duck-typing).
 * @return int Count, or 0 on error.
 */
function pp_quarantine_count($db) {
  try {
    if ($db instanceof PDO) {
      $stmt = $db->query("SELECT COUNT(*) FROM quarantined_detections");
      $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
      return $row ? (int)$row[0] : 0;
    } elseif ($db instanceof SQLite3) {
      $r = $db->querySingle("SELECT COUNT(*) FROM quarantined_detections");
      return $r === false ? 0 : (int)$r;
    }
  } catch (\Throwable $e) {
    // fail open
  }
  return 0;
}

// ════════════════════════════════════════════════════════════════════════════
// end perchpi:quarantine-php-helpers-v1
// ════════════════════════════════════════════════════════════════════════════

// ============================================================================
// perchpi:photo-helpers-relocation-v1 (Phase 2 complete; doc updated v2)
// Canonical home for the 12 photo/media helpers (bird_photo_url_for and
// friends). kiosk.php's original definitions were removed in an earlier
// session, and verified absent by grep on 2026-05-11. The function_exists
// guards below are retained as defense in depth — harmless if duplicate
// definitions ever reappear via merge from upstream. perchpi:photo-helpers-doc-v2
// ============================================================================

if (!function_exists('bird_image_dir')) {
function bird_image_dir() {
  // perchpi:photo-helpers-self-contained-v1
  $home = get_home();
  return $home . "/BirdSongs/Extracted/bird_images";
}
}

if (!function_exists('sci_name_slug')) {
function sci_name_slug($sci_name) {
  // Scientific names use space between genus and species. Underscore is safer
  // in filesystem/URL paths. Strip anything unusual.
  $s = preg_replace('/[^A-Za-z ]/', '', $sci_name);
  $s = trim($s);
  return str_replace(' ', '_', $s);
}
}

if (!function_exists('bird_http_get')) {
function bird_http_get($url, &$is_transient_failure = false) {
  $is_transient_failure = false;
  $ua = "BirdNET-Pi-Kiosk/1.0 (local home use)";
  $ctx = stream_context_create([
    'http' => [
      'timeout' => 8,
      'header'  => "User-Agent: $ua\r\n",
      'ignore_errors' => true,  // so 4xx/5xx still return body for inspection
    ]
  ]);

  for ($attempt = 0; $attempt < 2; $attempt++) {
    $body = @file_get_contents($url, false, $ctx);
    // Inspect response status if available
    $status = 0;
    if (!empty($http_response_header) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
      $status = intval($m[1]);
    }
    if ($body !== false && $status >= 200 && $status < 300) {
      return $body;
    }
    // 4xx is a definitive "no" — don't retry
    if ($status >= 400 && $status < 500) {
      return null;
    }
    // 5xx, timeout, or DNS failure — wait a beat and retry once
    if ($attempt === 0) {
      usleep(400000);  // 400ms
      continue;
    }
  }
  $is_transient_failure = true;  // both attempts failed without a clean 4xx
  return null;
}
}

if (!function_exists('wikipedia_image_info')) {
function wikipedia_image_info($sci_name, $com_name = null) {
  $endpoint = "https://en.wikipedia.org/w/api.php";

  $try_titles = [];
  if ($sci_name) $try_titles[] = $sci_name;
  if ($com_name && $com_name !== $sci_name) $try_titles[] = $com_name;

  $any_transient = false;
  $any_response = false;

  foreach ($try_titles as $title) {
    // Step 1: ask for the page's 'pageimage' (the lead thumbnail filename).
    $q = http_build_query([
      'action'        => 'query',
      'format'        => 'json',
      'titles'        => $title,
      'prop'          => 'pageimages|pageprops',
      'pithumbsize'   => 600,
      'redirects'     => 1,
      'formatversion' => 2,
    ]);
    $transient = false;
    $json = bird_http_get($endpoint . "?" . $q, $transient);
    if ($json === null) {
      if ($transient) $any_transient = true;
      continue;
    }
    $any_response = true;
    $data = json_decode($json, true);
    if (!$data || empty($data['query']['pages'][0])) continue;
    $page = $data['query']['pages'][0];
    if (!empty($page['missing'])) continue;
    if (empty($page['thumbnail']['source'])) continue;

    $thumb_url = $page['thumbnail']['source'];

    // Step 2: get attribution metadata for the underlying file.
    // Failure here is non-fatal — we still return the image URL with empty attribution.
    $file_name = $page['pageimage'] ?? null;
    $artist = null;
    $license = null;
    $desc_url = null;
    if ($file_name) {
      $q2 = http_build_query([
        'action'        => 'query',
        'format'        => 'json',
        'titles'        => 'File:' . $file_name,
        'prop'          => 'imageinfo',
        'iiprop'        => 'extmetadata|url',
        'formatversion' => 2,
      ]);
      $t2 = false;
      $json2 = bird_http_get($endpoint . "?" . $q2, $t2);
      if ($json2 !== null) {
        $d2 = json_decode($json2, true);
        $ii = $d2['query']['pages'][0]['imageinfo'][0] ?? null;
        if ($ii) {
          $em = $ii['extmetadata'] ?? [];
          $artist = isset($em['Artist']['value']) ? trim(strip_tags($em['Artist']['value'])) : null;
          $license = isset($em['LicenseShortName']['value']) ? trim(strip_tags($em['LicenseShortName']['value'])) : null;
          $desc_url = $ii['descriptionurl'] ?? null;
        }
      }
      // Don't propagate metadata-call transient failure — having the image is enough
    }

    return [
      'url'            => $thumb_url,
      'artist'         => $artist ?: 'Unknown',
      'license'        => $license ?: '',
      'descriptionurl' => $desc_url ?: '',
    ];
  }

  // No title produced an image. If at least one title got a real response back
  // from Wikipedia (even if the response said "no article" or "no lead image"),
  // this is a permanent miss. If every attempt failed with a transient error
  // (timeout, DNS, 5xx), we should retry sooner.
  return ['_miss_kind' => $any_response ? 'permanent' : ($any_transient ? 'transient' : 'permanent')];
}
}

if (!function_exists('download_bird_image')) {
function download_bird_image($url, $target_path) {
  $is_transient = false;
  $bytes = bird_http_get($url, $is_transient);
  if ($bytes === null || strlen($bytes) < 512) {
    return ['_miss_kind' => $is_transient ? 'transient' : 'permanent'];
  }

  // Quick sniff: JPEG starts with FFD8FF, PNG with 89504E47, GIF with 47494638
  $sig = bin2hex(substr($bytes, 0, 4));
  $looks_image = (strncmp($sig, 'ffd8ff', 6) === 0)
              || ($sig === '89504e47')
              || (strncmp($sig, '47494638', 8) === 0);
  if (!$looks_image) {
    return ['_miss_kind' => 'permanent'];
  }

  if (@file_put_contents($target_path, $bytes) === false) {
    return ['_miss_kind' => 'transient'];  // disk failure may be temporary
  }
  return $target_path;
}
}

if (!function_exists('bird_photo_provider')) {
function bird_photo_provider() {
  $cfg = get_config();
  $raw = isset($cfg['IMAGE_PROVIDER']) ? trim($cfg['IMAGE_PROVIDER']) : '';
  if ($raw === '') return null;
  $lower = strtolower($raw);
  if ($lower === 'wikipedia') return $lower;
  // Unknown value — log a warning and treat as None to avoid surprising fetches.
  error_log("bird_photo_provider: unknown IMAGE_PROVIDER value '$raw'; treating as None.");
  return null;
}
}

if (!function_exists('bird_photo_url_for')) {
function bird_photo_url_for($sci_name, $com_name = null) {
  if (!$sci_name) return null;

  $dir = bird_image_dir();
  $slug = sci_name_slug($sci_name);
  if ($slug === '') return null;

  // perchpi:user-photo-priority-v1 — personal uploads always win, regardless of IMAGE_PROVIDER.
  $user_img_path = $dir . '/' . $slug . '.user.jpg';
  if (file_exists($user_img_path)) return '/bird_images/' . $slug . '.user.jpg';

  // perchpi:image-provider-aware-photos: provider-suffixed cache paths
  $provider = bird_photo_provider();
  if ($provider === null) return null;  // "None" — no photo, ever.

  $img_path        = $dir . '/' . $slug . '.' . $provider . '.jpg';
  $meta_path       = $dir . '/' . $slug . '.' . $provider . '.json';
  $miss_path       = $dir . '/' . $slug . '.' . $provider . '.miss';
  $transient_path  = $dir . '/' . $slug . '.' . $provider . '.transient';
  $url_path        = '/bird_images/' . $slug . '.' . $provider . '.jpg';

  $legacy_img_path = $dir . '/' . $slug . '.jpg';
  $legacy_url_path = '/bird_images/' . $slug . '.jpg';

  // Already cached
  // Already cached (provider-suffixed file)
  if (file_exists($img_path)) return $url_path;
  // Legacy unsuffixed cache (pre-patch). Only honored under Wikipedia,
  // since that's what produced them.
  if ($provider === 'wikipedia' && file_exists($legacy_img_path)) return $legacy_url_path;

  // Permanent negative cache — Wikipedia really has nothing for this slug.
  // 7 days is long enough to skip noise classes (Gun_Gun, etc.) without
  // forever excluding species that may get articles later.
  if (file_exists($miss_path) && (time() - filemtime($miss_path)) < 86400) {
    return null;
  }

  // Transient negative cache — the network failed last time we tried.
  // 1 hour is short enough that a Wikipedia outage or DNS hiccup doesn't
  // permanently mask species, but long enough that a refresh storm doesn't
  // hammer the network with retries.
  if (file_exists($transient_path) && (time() - filemtime($transient_path)) < 3600) {
    return null;
  }

  // perchpi:photo-helpers-readonly-v1 — web rendering path is READ-ONLY
  // with respect to the cache. Photo fetching is the cron's job
  // (refresh_bird_photos.php). Previously this function called Wikipedia
  // and wrote .miss/.transient sentinels owned by caddy, which silently
  // locked out the cron (running as birder). Result: new species got
  // trapped without photos for 24h+ until manual eviction.
  return null;
}
}

if (!function_exists('bird_photo_meta_for')) {
function bird_photo_meta_for($sci_name) {
  $provider = bird_photo_provider();
  if ($provider === null) return null;
  $dir = bird_image_dir();
  $slug = sci_name_slug($sci_name);
  if ($slug === '') return null;
  $meta_path        = $dir . '/' . $slug . '.' . $provider . '.json';
  $legacy_meta_path = $dir . '/' . $slug . '.json';
  $candidate = file_exists($meta_path) ? $meta_path
             : (($provider === 'wikipedia' && file_exists($legacy_meta_path)) ? $legacy_meta_path : null);
  if ($candidate === null) return null;
  $j = @file_get_contents($candidate);
  return $j ? json_decode($j, true) : null;
}
}

if (!function_exists('spectrogram_url_for')) {
function spectrogram_url_for($row) {
  // perchpi:photo-helpers-self-contained-v1
  $home = get_home();
  $slug = com_to_slug($row['Com_Name']);
  $base = $home . "/BirdSongs/Extracted/By_Date/" . $row['Date'] . "/" . $slug . "/" . $row['File_Name'];
  if (file_exists($base . ".png")) {
    return "/By_Date/" . $row['Date'] . "/" . $slug . "/" . $row['File_Name'] . ".png";
  }
  return null;
}
}

if (!function_exists('thumbnail_info_for')) {
function thumbnail_info_for($row) {
  $bird = bird_photo_url_for($row['Sci_Name'] ?? null, $row['Com_Name'] ?? null);
  if ($bird) {
    return [
      'thumb'         => $bird,
      'is_bird_photo' => true,
      'meta'          => bird_photo_meta_for($row['Sci_Name'] ?? null),
    ];
  }
  return [
    'thumb'         => spectrogram_url_for($row),
    'is_bird_photo' => false,
    'meta'          => null,
  ];
}
}

if (!function_exists('thumbnail_url_for')) {
function thumbnail_url_for($row) {
  $info = thumbnail_info_for($row);
  return $info['thumb'];
}
}

if (!function_exists('audio_url_for')) {
function audio_url_for($row) {
  return detection_filepath($row);
}
}

/* perchpi:photo-helpers-phase25a-v1 -- relocated from kiosk.php */

if (!function_exists('com_to_slug')) {
  function com_to_slug($com_name) {
    $s = preg_replace('/ /', '_', $com_name);
    $s = preg_replace('/\'/', '', $s);
    return $s;
  }
}

if (!function_exists('detection_filepath')) {
  function detection_filepath($row) {
    $slug = com_to_slug($row['Com_Name']);
    return "/By_Date/" . $row['Date'] . "/" . $slug . "/" . $row['File_Name'];
  }
}

/* perchpi:photo-helpers-phase26a-v1 -- DB query functions relocated from kiosk.php */

if (!function_exists('fetch_top_species_today')) {
  function fetch_top_species_today($limit = 10) {
    $db = get_db();
    $stmt = $db->prepare(
      "SELECT Com_Name, Sci_Name, COUNT(*) as Cnt, MAX(Confidence) as MaxConf,
              (SELECT File_Name FROM detections d2
               WHERE d2.Sci_Name = detections.Sci_Name AND d2.Date = DATE('now','localtime')
                 AND d2.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
               ORDER BY d2.Time DESC LIMIT 1) as Last_File,
              (SELECT Date FROM detections d3
               WHERE d3.Sci_Name = detections.Sci_Name AND d3.Date = DATE('now','localtime')
                 AND d3.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
               ORDER BY d3.Time DESC LIMIT 1) as Last_Date,
              (SELECT Time FROM detections d4
               WHERE d4.Sci_Name = detections.Sci_Name AND d4.Date = DATE('now','localtime')
                 AND d4.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
               ORDER BY d4.Time DESC LIMIT 1) as Last_Time  /* perchpi:bt-lasttime-v1 */
       FROM detections
       WHERE Date = DATE('now', 'localtime')
         AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
       GROUP BY Sci_Name
       ORDER BY Cnt DESC
       LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    ensure_db_ok($stmt);
    $result = $stmt->execute();
    $rows = [];
    while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
      $rows[] = $r;
    }
    return $rows;
  }
}

if (!function_exists('fetch_recent_detections')) {
  function fetch_recent_detections($limit = 10, $date = null) {
    $db = get_db();
    if ($date === null) {
      $stmt = $db->prepare(
        "SELECT Com_Name, Sci_Name, Date, Time, Confidence, File_Name, Geomodel_Prob, Geomodel_Week
         FROM detections
         WHERE Date = DATE('now', 'localtime')
           AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
         ORDER BY Time DESC
         LIMIT :lim"
      );
    } else {
      $stmt = $db->prepare(
        "SELECT Com_Name, Sci_Name, Date, Time, Confidence, File_Name, Geomodel_Prob, Geomodel_Week
         FROM detections
         WHERE Date = :d
           AND detections.ROWID NOT IN (SELECT detection_id FROM quarantined_detections) /* perchpi:quarantine-filter-v1 */
         ORDER BY Time DESC
         LIMIT :lim"
      );
      $stmt->bindValue(':d', $date);
    }
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    ensure_db_ok($stmt);
    $result = $stmt->execute();
    $rows = [];
    while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
      $rows[] = $r;
    }
    return $rows;
  }
}


/* ============================================================================
   perchpi:list-controls-module-v1
   pp_render_list_controls() — emits the sort + filter UI for list
   pages. Behavior is driven by perchpi_list_controls.js, which auto-inits
   on DOMContentLoaded.

   Pages call this once, near the top of their content area, and add
   data-pp-* attributes to their row markup (data-pp-com, data-pp-sci, plus
   whatever sort/filter fields the config references via row_attr).

   Config keys:
     storage_key         (str)   localStorage key prefix (e.g. 'perchpi-bestrec')
     row_selector        (str)   CSS selector for rows to sort/filter
     filter_fields       (array) data-pp-* attr names to filter on (default ['com','sci'])
     filter_placeholder  (str)   filter input placeholder (default 'Filter by name…')
     item_label          (str)   word for counter (default 'items'; e.g. 'species')
     default_sort        (str)   key of default sort (default: first sort in 'sorts')
     sorts               (array) [sort_key => [
         default_dir => 'asc'|'desc',
         label_asc   => 'Alphabetical',
         label_desc  => 'Reverse alphabetical',
         row_attr    => 'com',           // data-pp-<attr> on rows
         type        => 'string'|'number',
         xc_last     => bool (optional)  // true: XC rows sink to bottom
       ]]
   ============================================================================ */
function pp_render_list_controls(array $cfg) {
  $storage_key        = $cfg['storage_key']        ?? 'pp-list';
  $row_selector       = $cfg['row_selector']       ?? '.list-row';
  $filter_fields      = $cfg['filter_fields']      ?? ['com', 'sci'];
  $filter_placeholder = $cfg['filter_placeholder'] ?? 'Filter by name…';
  $item_label         = $cfg['item_label']         ?? 'items';
  $default_sort       = $cfg['default_sort']       ?? null;
  $sorts              = $cfg['sorts']              ?? [];

  if (empty($sorts)) {
    return;
  }
  if ($default_sort === null) {
    $default_sort = array_key_first($sorts);
  }

  $h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };

  echo '<div class="pp-list-controls"';
  echo ' data-pp-storage-key="'   . $h($storage_key)                      . '"';
  echo ' data-pp-row-selector="'  . $h($row_selector)                     . '"';
  echo ' data-pp-filter-fields="' . $h(implode(',', $filter_fields))      . '"';
  echo ' data-pp-item-label="'    . $h($item_label)                       . '"';
  echo ">
";

  echo '  <div class="pp-list-sort">' . "
";
  foreach ($sorts as $key => $info) {
    $is_default = ($key === $default_sort);
    $def_dir    = $info['default_dir'] ?? 'asc';
    $label_asc  = $info['label_asc']   ?? ucfirst((string)$key);
    $label_desc = $info['label_desc']  ?? ucfirst((string)$key);
    $row_attr   = $info['row_attr']    ?? $key;
    $type       = $info['type']        ?? 'string';
    $xc_last    = !empty($info['xc_last']) ? '1' : '0';

    // Initial label = label corresponding to default direction
    $initial_label = ($def_dir === 'desc') ? $label_desc : $label_asc;
    $initial_dir   = $def_dir;

    echo '    <a data-pp-sort="'        . $h($key)        . '"';
    if ($is_default) {
      echo ' data-pp-default="1"';
    }
    echo ' data-pp-dir-default="'       . $h($def_dir)    . '"';
    echo ' data-pp-label-asc="'         . $h($label_asc)  . '"';
    echo ' data-pp-label-desc="'        . $h($label_desc) . '"';
    echo ' data-pp-row-attr="'          . $h($row_attr)   . '"';
    echo ' data-pp-type="'              . $h($type)       . '"';
    echo ' data-pp-xc-last="'           . $xc_last        . '"';
    echo ' href="#sort='                . rawurlencode((string)$key)
       . '&dir=' . $h($initial_dir)     . '"';
    echo '>' . $h($initial_label) . '</a>' . "
";
  }
  echo '  </div>' . "
";

  echo '  <div class="pp-list-filter-wrap">' . "
";
  echo '    <input type="text" class="pp-list-filter-input" autocomplete="off"';
  echo ' placeholder="' . $h($filter_placeholder) . '">' . "
";
  echo '    <span class="pp-list-counter"></span>' . "
";
  echo '  </div>' . "
";

  echo '</div>' . "
";

  // Include the JS once per page, even if helper is called multiple times.
  static $script_emitted = false;
  if (!$script_emitted) {
    echo '<script src="/perchpi_list_controls.js"></script>' . "
";
    $script_emitted = true;
  }
}


// ────────────────────────────────────────────────────────────────────────
// perchpi:external-links-v1 — external cross-platform links for Species Profile
// ────────────────────────────────────────────────────────────────────────
// Provides cross-references to eBird, Xeno-Canto, Macaulay Library,
// iNaturalist, and observation.org. Data lives in ~/BirdNET-Pi/data/external_links.json,
// produced by ~/BirdNET-Pi/scripts/update_external_links.py.
// Re-run that script quarterly to refresh from BirdNET-Cornell taxonomy.

function pp_external_ids_for($sci_name) {
  static $cache = null;
  if ($cache === null) {
    $path = __DIR__ . '/../data/external_links.json';
    if (is_file($path)) {
      $raw = @file_get_contents($path);
      $decoded = $raw !== false ? json_decode($raw, true) : null;
      $cache = is_array($decoded) ? $decoded : [];
    } else {
      $cache = [];
    }
  }
  return $cache[$sci_name] ?? null;
}

function pp_render_external_links($sci_name, $com_name) {
  $ids = pp_external_ids_for($sci_name);
  // Xeno-Canto works on raw sci_name even without lookup data, but we still
  // need at least *something* to render. If no IDs at all, render nothing.
  if (!$ids) {
    // Even without IDs, we can still link XC/iNat/obs via sci_name search URLs.
    // But for consistency with the "data-backed" promise, skip the section
    // entirely if there's no taxonomy entry. Improves trust.
    return;
  }

  $com_h = htmlspecialchars($com_name, ENT_QUOTES);
  $sci_h = htmlspecialchars($sci_name, ENT_QUOTES);
  $xc_name = !empty($ids['xc_name']) ? $ids['xc_name'] : $sci_name;
  $xc_slug = str_replace(' ', '-', $xc_name);

  $buttons = [];

  // perchpi:birdweather-species-url-v1 -- first box; global species page (nearby-station detections + audio compare)
  $bw_slug = strtolower($com_name);
  $bw_slug = str_replace(array("'", "\xe2\x80\x99"), '', $bw_slug); // straight + curly apostrophe
  $bw_slug = preg_replace('/[^a-z0-9]+/', '-', $bw_slug);
  $bw_slug = trim($bw_slug, '-');
  if ($bw_slug !== '') {
    $buttons[] = [
      'url'   => 'https://app.birdweather.com/species/' . $bw_slug,
      'label' => 'BirdWeather',
      'title' => "Compare {$com_h} detections across nearby BirdWeather stations",
      'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="11" fill="#1f7a8c"/><path d="M6 14 Q9 7 15 7 Q13 9 13 11 Q16 10 18 8 Q17 13 12 15 Q8 16 6 18 Z" fill="white"/><circle cx="14.5" cy="9.5" r="0.8" fill="#1f7a8c"/></svg>',
    ];
  }

  if (!empty($ids['ebird_code'])) {
    $buttons[] = [
      'url'   => 'https://ebird.org/species/' . rawurlencode($ids['ebird_code']),
      'label' => 'eBird',
      'title' => "View {$com_h} on eBird",
      'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="11" fill="#a4123f"/><path d="M7 12.5c0-3 2.2-5 5-5 2.5 0 4.5 1.5 4.5 4 0 1.5-1 2.5-3 2.5h-4c0 1.5 1 2.5 3 2.5 1.2 0 2-.3 2.7-.8l.8 1.5c-1 .7-2 1-3.5 1-3.3 0-5.5-2-5.5-5.7zm5.5-3c-1.3 0-2.3.7-2.5 2h4.5c0-1.2-.8-2-2-2z" fill="white"/></svg>',
    ];
  }

  $buttons[] = [
    'url'   => 'https://xeno-canto.org/species/' . rawurlencode($xc_slug),
    'label' => 'Xeno-Canto',
    'title' => "Listen to {$com_h} on Xeno-Canto",
    'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="11" fill="#f97316"/><path d="M5 12h2M8 9v6M11 7v10M14 9v6M17 11v2M20 12h-1" stroke="white" stroke-width="1.8" stroke-linecap="round" fill="none"/></svg>',
  ];

  if (!empty($ids['ml_taxon_code'])) {
    $buttons[] = [
      'url'   => 'https://search.macaulaylibrary.org/catalog?taxonCode=' . rawurlencode($ids['ml_taxon_code']) . '&sort=rating_rank_desc',
      'label' => 'Macaulay Library',
      'title' => "Browse {$com_h} media on Macaulay Library",
      'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2 L20 5 V12 C20 17 16 20 12 22 C8 20 4 17 4 12 V5 Z" fill="#b31b1b"/><text x="12" y="14" text-anchor="middle" fill="white" font-family="Georgia, serif" font-size="8" font-weight="bold" font-style="italic">ML</text></svg>',
    ];
  }

  if (!empty($ids['inat_id'])) {
    $buttons[] = [
      'url'   => 'https://www.inaturalist.org/taxa/' . (int)$ids['inat_id'],
      'label' => 'iNaturalist',
      'title' => "View {$com_h} on iNaturalist",
      'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="11" fill="#74ac00"/><path d="M7 16 Q8 9 12 7 Q16 5 17 8 Q17 11 13 12 Q9 13 7 16 Z" fill="white"/><circle cx="14" cy="10" r="0.9" fill="#74ac00"/></svg>',
    ];
  }

  if (!empty($ids['observationorg_id'])) {
    $buttons[] = [
      'url'   => 'https://observation.org/species/' . (int)$ids['observationorg_id'],
      'label' => 'observation.org',
      'title' => "View {$com_h} on observation.org",
      'svg'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="11" fill="#2563eb"/><circle cx="12" cy="12" r="6.5" stroke="white" stroke-width="1.5" fill="none"/><path d="M12 5.5 V18.5 M5.5 12 H18.5" stroke="white" stroke-width="1.2"/><path d="M12 5.5 Q14.5 12 12 18.5 Q9.5 12 12 5.5" stroke="white" stroke-width="1.2" fill="none"/></svg>',
    ];
  }

  if (empty($buttons)) return;

  echo "\n<!-- perchpi:external-links-v1 -->\n";
  echo '<section class="section pp-external-links-section">' . "\n";
  echo '  <p class="pp-external-links-label">Find this species on</p>' . "\n";
  echo '  <div class="pp-external-links">' . "\n";
  foreach ($buttons as $b) {
    echo '    <a class="pp-ext-btn" href="' . htmlspecialchars($b['url'], ENT_QUOTES)
       . '" target="_blank" rel="noopener noreferrer"'
       . ' title="' . $b['title'] . '">'
       . $b['svg']
       . '<span>' . htmlspecialchars($b['label'], ENT_QUOTES) . '</span>'
       . '</a>' . "\n";
  }
  echo '  </div>' . "\n";
  echo '</section>' . "\n";
}
// /perchpi:external-links-v1

// perchpi:quarantine-review-log-hook-v1
// perchpi:quarantine-review-log-tz-fix-v1 -- decision_at + age both UTC
// perchpi:qrl-audio-snapshot-v1 -- snapshot d.File_Name + d.Date at decision time
// Logs human review decisions to quarantine_review_log BEFORE destructive
// action. Captures species, embedding logit, geomodel probability, and
// quarantine age. Survives the CASCADE that destroys quarantine rows on
// detection deletion because quarantine_review_log has no FK on detection_id.
function pp_log_quarantine_decisions($db, array $ids, string $decision): void {
  if (empty($ids)) return;
  if (!in_array($decision, ['delete', 'unhide'], true)) {
    throw new InvalidArgumentException("decision must be 'delete' or 'unhide'");
  }
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $sql = "
    INSERT INTO quarantine_review_log
      (detection_id, decision_at, decision, species_sci, species_com, confidence,
       embedding_logit, embedding_verdict, geomodel_probability, quarantine_reason,
       audio_file, audio_date,
       quarantine_age_minutes, source)
    SELECT
      qd.detection_id,
      datetime('now'),
      ?,
      d.Sci_Name,
      d.Com_Name,
      d.Confidence,
      ad.embedding_logit,
      ad.embedding_verdict,
      qd.geomodel_probability,
      qd.reason,
      d.File_Name,
      d.Date,
      CAST((julianday('now') - julianday(qd.quarantined_at)) * 24 * 60 AS INTEGER),
      'ui'
    FROM quarantined_detections qd
    LEFT JOIN detections d ON d.ROWID = qd.detection_id
    LEFT JOIN ai_decisions ad ON ad.id = (
      SELECT MAX(id) FROM ai_decisions
      WHERE detection_id = qd.detection_id AND embedding_logit IS NOT NULL
    )
    WHERE qd.detection_id IN ($placeholders)
  ";
  $stmt = $db->prepare($sql);
  $stmt->bindValue(1, $decision, SQLITE3_TEXT);
  foreach ($ids as $i => $id) {
    $stmt->bindValue($i + 2, $id, SQLITE3_INTEGER);
  }
  $stmt->execute();
}

/* ============================================================================
   perchpi:notable-sightings-v1 · pp_is_bird()
   ----------------------------------------------------------------------------
   Genus/species crosswalk into geomodel/bird_mask.json.

   The mask is keyed in geomodel-vocab sci-names (post-2024-AOS). BirdNET writes
   detections under older AOS names, so a naive mask[$sci] lookup silently
   returns "not a bird" for renamed species (e.g. Evening Grosbeak, Hairy
   Woodpecker). We apply the same alias crosswalk the geomodel lookup uses.

   Rule: a name is a bird IFF the (aliased) key is present in the mask AND
   is_bird === true. Absent key => not-a-bird. Non-bird vocab labels
   (Dog/Engine/Power tools/Siren) are absent from the mask entirely and so
   correctly resolve to false; genuinely out-of-vocab birds also resolve to
   false (accepted limitation — surfaced via the candidate list, not gated).
   ============================================================================ */
if (!function_exists('pp_is_bird')) {

  /* geomodel-vocab alias map: BirdNET (older-AOS) sci-name => bird_mask key */
  function pp_bird_mask_aliases() {
    return [
      'Accipiter cooperii'         => 'Astur cooperii',
      'Coccothraustes vespertinus' => 'Hesperiphona vespertina',
      'Dryobates villosus'         => 'Leuconotopicus villosus',
      'Psilorhinus morio'          => 'Cyanocorax morio',
      'Apus melba'                 => 'Tachymarptis melba',
      'Basileuterus lachrymosus'   => 'Euthlypis lachrymosa',
      'Cicinnurus magnificus'      => 'Diphyllodes magnificus',
      'Dryobates borealis'         => 'Leuconotopicus borealis',
      'Ixobrychus exilis'          => 'Botaurus exilis',
      'Ixobrychus minutus'         => 'Botaurus minutus',
    ];
  }

  /**
   * Is the given scientific name a bird, per geomodel/bird_mask.json?
   * Static-cached load. Returns true only if the (aliased) key is present
   * and is_bird is true; false otherwise (including absent keys).
   */
  function pp_is_bird($sci) {
    static $mask = null;
    if ($mask === null) {
      $mask = [];
      $home = function_exists('get_home') ? get_home() : '/home/birder';
      $path = $home . '/BirdNET-Pi/geomodel/bird_mask.json';
      if (is_readable($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
          $data = json_decode($raw, true);
          if (is_array($data) && isset($data['mask']) && is_array($data['mask'])) {
            $mask = $data['mask'];
          }
        }
      }
    }
    if (!is_string($sci) || $sci === '') return false;
    $aliases = pp_bird_mask_aliases();
    $key = isset($aliases[$sci]) ? $aliases[$sci] : $sci;
    return isset($mask[$key]['is_bird']) && $mask[$key]['is_bird'] === true;
  }
}
/* end perchpi:notable-sightings-v1 pp_is_bird */

/* ============================================================================
   perchpi:notable-banner-helpers-v1
   Shared NOTABLE predicate + recent-count, so the Overview banner and the
   Notable Sightings page agree by construction (one definition, no drift).
   ============================================================================ */
if (!function_exists('pp_is_notable_row')) {
  /* The 2-conjunct NOTABLE predicate, lifted verbatim from notable.php:
       (1) is-a-bird via genus crosswalk (pp_is_bird)
       (2) locally rare: geomodel prob NULL/'' OR reason 'geomodel_no_data'
           OR prob < SF_THRESH
     $r is an assoc row from quarantine_review_log. */
  function pp_is_notable_row(array $r, float $sf_thresh): bool {
    $sci = isset($r['species_sci']) ? $r['species_sci'] : '';
    if (!function_exists('pp_is_bird') || !pp_is_bird($sci)) {
      return false;
    }
    $prob   = isset($r['geomodel_probability']) ? $r['geomodel_probability'] : null;
    $reason = isset($r['quarantine_reason']) ? $r['quarantine_reason'] : '';
    /* perchpi:notable-tighten-v1: a missing prob counts as locally rare ONLY
       when the geomodel explicitly found no data (reason=geomodel_no_data).
       Incidental no-prob rows (e.g. user_hidden siren FPs) no longer qualify. */
    if ($reason === 'geomodel_no_data') return true;
    if ($prob === null || $prob === '') return false;
    return (floatval($prob) < $sf_thresh);
  }
}

if (!function_exists('pp_notable_count_recent')) {
  /* Count NOTABLE review-log rows decided within the last $days days.
     Read-only, self-contained, fail-open (returns 0 on any error).
     Window applied in PHP via strtotime() — the parser notable.php trusts —
     to avoid SQLite UTC-vs-stored-local timestamp skew. */
  function pp_notable_count_recent(string $db_path, float $sf_thresh, int $days = 7): int {
    try {
      $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
      $db->busyTimeout(5000);
      $has = (int)$db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='quarantine_review_log'"
      );
      if ($has <= 0) { $db->close(); return 0; }
      $res = $db->query(
        "SELECT decision_at, species_sci, geomodel_probability, quarantine_reason
         FROM quarantine_review_log
         ORDER BY decision_at DESC LIMIT 1000"
      );
      $cutoff = time() - ($days * 86400);
      $n = 0;
      while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $t = $r['decision_at'] ? strtotime((string)$r['decision_at']) : false;
        if ($t === false || $t < $cutoff) continue;   /* outside window (scan all; log is small) */
        if (pp_is_notable_row($r, $sf_thresh)) $n++;
      }
      $db->close();
      return $n;
    } catch (Exception $e) {
      return 0;   /* fail open — no banner rather than a flash/error */
    }
  }
}

