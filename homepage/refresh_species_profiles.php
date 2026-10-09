<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // perchpi:cli-only-guard-v1 (D-S9-1): CLI tool, never served over HTTP
/* ============================================================================
   refresh_species_profiles.php — Wikipedia summary + key facts cache builder
   ============================================================================
   Fetches a Wikipedia summary + structured facts for every species in the
   detections DB and saves them as JSON files at:
     ~/BirdSongs/Extracted/species_profiles/{Genus_species}.json

   Strategy per species:
     1. Try sci name first (Wikipedia REST summary endpoint)
     2. If 404, fall back to common name
     3. Once article is found, try MediaWiki API for infobox-derived facts
     4. If facts can't be parsed, save summary with facts = null per field

   Usage:
     php refresh_species_profiles.php          # skip already-cached
     php refresh_species_profiles.php --force  # re-fetch everything
     php refresh_species_profiles.php --species "Sci Name"  # one species

   Idempotent. Polite delay between fetches.
   ============================================================================ */

@set_time_limit(0);
ini_set('memory_limit', '256M');

$is_cli = (php_sapi_name() === 'cli');
$force = false;
$single_species = null;

if ($is_cli) {
    foreach ($argv as $a) {
        if ($a === '--force') $force = true;
        if (strpos($a, '--species=') === 0) $single_species = substr($a, 10);
    }
    // Also accept --species "Sci Name" form
    for ($i = 0; $i < count($argv); $i++) {
        if ($argv[$i] === '--species' && isset($argv[$i+1])) {
            $single_species = $argv[$i+1];
        }
    }
}

$home = getenv('HOME') ?: '/home/birder';
$db_path = $home . '/BirdNET-Pi/scripts/birds.db';
$cache_dir = $home . '/BirdSongs/Extracted/species_profiles';

if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0775, true);
    echo "Created cache dir: $cache_dir\n";
}

if (!file_exists($db_path)) {
    fwrite(STDERR, "ERROR: birds.db not found at $db_path\n");
    exit(1);
}

// ────────────────────────────────────────────────────────────────────────
// Build species list from DB (distinct sci/common pairs)
// ────────────────────────────────────────────────────────────────────────
$species_list = [];
try {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);
    if ($single_species) {
        $stmt = $db->prepare("SELECT DISTINCT Sci_Name, Com_Name FROM detections WHERE Sci_Name = :sci");
        $stmt->bindValue(':sci', $single_species, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare("SELECT DISTINCT Sci_Name, Com_Name FROM detections ORDER BY Sci_Name");
    }
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $species_list[] = $row;
    }
    $db->close();
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: DB query failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (empty($species_list)) {
    fwrite(STDERR, "ERROR: no species found in DB" . ($single_species ? " (filter: '$single_species')" : "") . "\n");
    exit(1);
}

$total = count($species_list);
echo "Refreshing species profiles for $total species" . ($force ? " (force mode)" : "") . "\n";
echo str_repeat('-', 60) . "\n";

// ────────────────────────────────────────────────────────────────────────
// Helper: HTTP GET with cURL (Wikipedia requires User-Agent header)
// ────────────────────────────────────────────────────────────────────────
function http_get($url, &$http_code = 0) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'User-Agent: BirdNET-Pi-PerchPi/1.0 (https://github.com/Nachtzuster/BirdNET-Pi)',
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $body;
}

// ────────────────────────────────────────────────────────────────────────
// Helper: fetch Wikipedia summary by title (REST API)
// Returns array with summary fields, or null on failure
// ────────────────────────────────────────────────────────────────────────
function wp_summary($title) {
    $url = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $title));
    $http = 0;
    $body = http_get($url, $http);
    if ($http !== 200 || !$body) return null;
    $data = json_decode($body, true);
    if (!$data || empty($data['extract'])) return null;
    return [
        'title'        => $data['title'] ?? $title,
        'extract'      => $data['extract'] ?? '',
        'description'  => $data['description'] ?? '',
        'page_url'     => $data['content_urls']['desktop']['page'] ?? "https://en.wikipedia.org/wiki/" . rawurlencode(str_replace(' ', '_', $title)),
        'pageid'       => $data['pageid'] ?? null,
    ];
}

// ────────────────────────────────────────────────────────────────────────
// Helper: fetch MediaWiki page wikitext for infobox parsing
// Returns raw wikitext or null
// ────────────────────────────────────────────────────────────────────────
function wp_wikitext($pageid) {
    if (!$pageid) return null;
    $url = "https://en.wikipedia.org/w/api.php?action=parse&pageid=$pageid&prop=wikitext&format=json&formatversion=2";
    $http = 0;
    $body = http_get($url, $http);
    if ($http !== 200 || !$body) return null;
    $data = json_decode($body, true);
    return $data['parse']['wikitext'] ?? null;
}

// ────────────────────────────────────────────────────────────────────────
// Helper: parse infobox facts from wikitext.
// Wikipedia bird infoboxes commonly have fields:
//   |status= (e.g. "LC", "NT", "VU", "EN", "CR", "EX", "DD")
//   |status_system= (e.g. "iucn3.1", "iucn2.3")
//   |genus=, |species=, |familia=, |ordo=, |classis=
//   |range_map_caption=
// Returns associative array; missing fields = null
// ────────────────────────────────────────────────────────────────────────
function parse_infobox_facts($wikitext) {
    if (!$wikitext) return null;

    $facts = [
        'taxonomy_family'   => null,
        'taxonomy_order'    => null,
        'taxonomy_genus'    => null,
        'conservation_status' => null,
        'conservation_status_full' => null,
        'length'            => null,
        'wingspan'          => null,
        'range_caption'     => null,
        'binomial'          => null,
    ];

    // Generic pattern: |key  = value
    // Stops at next pipe-newline or }} closing
    // perchpi:wiki-infobox-regex-fix-v1 — stricter terminator + length cap
    $extract = function($key) use ($wikitext) {
        $pattern = '/^\s*\|\s*' . preg_quote($key, '/') . '\s*=\s*(.+?)(?=^\s*\||^\s*\}\}|\z)/sum';
        if (preg_match($pattern, $wikitext, $m)) {
            $val = trim($m[1]);
            if (strlen($val) > 400) return null;
            return $val;
        }
        return null;
    };

    // Strip wikitext markup helpers (links, refs, templates we don't need)
    // perchpi:wiki-infobox-regex-fix-v1 — stronger markup stripping + length cap
    $clean = function($val) {
        if ($val === null) return null;
        // <ref>...</ref>
        $val = preg_replace('/<ref[^>]*>.*?<\/ref>/su', '', $val);
        $val = preg_replace('/<ref[^>]*\/>/u', '', $val);
        // HTML tags
        $val = strip_tags($val);
        // [[File:...|caption]] / [[Image:...|caption]] — keep caption only
        $val = preg_replace('/\[\[(?:File|Image):[^\]]*?\|([^\[\]]+)\]\]/u', '$1', $val);
        $val = preg_replace('/\[\[(?:File|Image):[^\]]*?\]\]/u', '', $val);
        // [[link|display]] → display, [[link]] → link
        $val = preg_replace_callback('/\[\[([^\|\]]+)\|([^\]]+)\]\]/u', function($m) { return $m[2]; }, $val);
        $val = preg_replace_callback('/\[\[([^\]]+)\]\]/u', function($m) { return $m[1]; }, $val);
        // {{templates}} — 3 passes for nesting
        for ($i = 0; $i < 3; $i++) {
            $val = preg_replace('/\{\{[^{}]*\}\}/u', '', $val);
        }
        // Orphan }} or {{ left over
        $val = str_replace(array('}}', '{{'), '', $val);
        // == Section headers ==
        $val = preg_replace('/={2,}\s*[^=]+\s*={2,}/u', '', $val);
        // ''' bold ''' / '' italic '' — use chr(39) to avoid quote-escape hell
        $q = chr(39);
        $val = preg_replace('/' . $q . $q . $q . '([^' . $q . ']+)' . $q . $q . $q . '/u', '$1', $val);
        $val = preg_replace('/' . $q . $q . '([^' . $q . ']+)' . $q . $q . '/u', '$1', $val);
        // File-annotation prefixes
        $val = preg_replace('/\b(?:thumb|left|right|center|upright|frameless|border|\d+px)\|/u', '', $val);
        // Collapse whitespace
        $val = preg_replace('/\s+/u', ' ', $val);
        $val = trim($val);
        if (strlen($val) > 400) return null;
        return $val ?: null;
    };

    // Taxonomy
    $facts['taxonomy_family'] = $clean($extract('familia'));
    if (!$facts['taxonomy_family']) $facts['taxonomy_family'] = $clean($extract('family'));
    $facts['taxonomy_order'] = $clean($extract('ordo'));
    if (!$facts['taxonomy_order']) $facts['taxonomy_order'] = $clean($extract('order'));
    $facts['taxonomy_genus'] = $clean($extract('genus'));

    // Conservation status (raw code like LC, VU)
    $status = $clean($extract('status'));
    if ($status) {
        $facts['conservation_status'] = strtoupper(substr($status, 0, 5));
        $status_map = [
            'LC' => 'Least Concern',
            'NT' => 'Near Threatened',
            'VU' => 'Vulnerable',
            'EN' => 'Endangered',
            'CR' => 'Critically Endangered',
            'EW' => 'Extinct in the Wild',
            'EX' => 'Extinct',
            'DD' => 'Data Deficient',
        ];
        $code = $facts['conservation_status'];
        $facts['conservation_status_full'] = $status_map[$code] ?? $status;
    }

    // Range/region (often in range_map_caption)
    $facts['range_caption'] = $clean($extract('range_map_caption'));

    // Binomial name (helpful for confirmation)
    $facts['binomial'] = $clean($extract('binomial'));

    // Length / wingspan — these vary wildly; just a best-effort extract
    $facts['length'] = $clean($extract('length'));
    $facts['wingspan'] = $clean($extract('wingspan'));

    // Decide if any facts were actually found; if all null, return null
    foreach ($facts as $v) if ($v !== null) return $facts;
    return null;
}

// ────────────────────────────────────────────────────────────────────────
// Main loop
// ────────────────────────────────────────────────────────────────────────
$ok = 0; $skip = 0; $fail = 0;

foreach ($species_list as $i => $sp) {
    $sci = $sp['Sci_Name'];
    $com = $sp['Com_Name'];
    $slug = str_replace([' ', '/'], '_', $sci);
    $cache_file = "$cache_dir/$slug.json";

    $n = $i + 1;
    $prefix = sprintf("[%3d/%3d] %s (%s):", $n, $total, $sci, $com);

    if (!$force && file_exists($cache_file)) {
        echo "$prefix CACHED\n";
        $skip++;
        continue;
    }

    // Try sci name first
    $sum = wp_summary($sci);
    $title_used = $sci;
    if (!$sum) {
        // Fall back to common name
        $sum = wp_summary($com);
        $title_used = $com;
    }
    if (!$sum) {
        echo "$prefix NOT FOUND on Wikipedia\n";
        // Save a minimal stub so we don't keep retrying
        $stub = [
            'sci_name'   => $sci,
            'com_name'   => $com,
            'fetched_at' => date('c'),
            'source'     => null,
            'extract'    => null,
            'description'=> null,
            'page_url'   => null,
            'facts'      => null,
            'error'      => 'not_found',
        ];
        file_put_contents($cache_file, json_encode($stub, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $fail++;
        usleep(750000);
        continue;
    }

    // Try for facts (optional, may be null)
    $facts = null;
    if (!empty($sum['pageid'])) {
        $wt = wp_wikitext($sum['pageid']);
        $facts = parse_infobox_facts($wt);
    }

    $entry = [
        'sci_name'   => $sci,
        'com_name'   => $com,
        'fetched_at' => date('c'),
        'source'     => $title_used,
        'extract'    => $sum['extract'],
        'description'=> $sum['description'],
        'page_url'   => $sum['page_url'],
        'facts'      => $facts,
        'error'      => null,
    ];
    file_put_contents($cache_file, json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $facts_count = $facts ? count(array_filter($facts, fn($v) => $v !== null)) : 0;
    echo "$prefix OK (" . strlen($sum['extract']) . " chars, $facts_count facts)\n";
    $ok++;

    // Polite delay
    usleep(750000); // 750ms
}

echo str_repeat('-', 60) . "\n";
echo "Done: $ok fetched, $skip cached (skipped), $fail not found\n";
echo "Cache dir: $cache_dir\n";
