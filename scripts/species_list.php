<?php
    error_reporting(E_ALL);
    ini_set('display_errors',1);

    if ($species_list=="include") {
        $title="Included";
        $message="Warning!<br>If this list contains ANY species, the system will ONLY recognize those species. Keep this list EMPTY unless you are ONLY interested in detecting specific species.";
        $selectedfilename = './scripts/include_species_list.txt';
    } elseif ($species_list=="exclude") {
        $title="Excluded";
        $message="Once the desired species has been highlighted, click it and then click ADD to have it excluded.";
        $selectedfilename = './scripts/exclude_species_list.txt';
    } elseif ($species_list=="whitelist") {
        $title="Whitelisted";
        $message="Once the desired species has been highlighted, click it and then click ADD to have it whitelisted. This species will be detected even if below the Species Occurrence Frequency Threshold defined in the settings.<br>This is not a recommended way of working : it is preferable to first try first both Species Occurrence models (v1 and v2.4).";
        $selectedfilename = './scripts/whitelist_species_list.txt';   
    }
    

    if (file_exists($selectedfilename)) {
        $eachselected = file($selectedfilename, FILE_IGNORE_NEW_LINES);
    }
    else {
        $eachselected = [];
    }
    $filename = './scripts/labels.txt';
    $eachlabel = file($filename, FILE_IGNORE_NEW_LINES);
?>


<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- perchpi:species-list-redesign-v2 -->
<div class="pp-species-editor">

  <div class="pp-species-editor-banner">
    <?php echo $message ?>
  </div>

  <div class="pp-species-editor-cols">

    <!-- LEFT COLUMN: All Species (master BirdNET label list) -->
    <div class="pp-species-editor-col pp-col-all">
      <h3>All Species</h3>

      <div class="pp-species-editor-sort-row">
        <span class="pp-sort-label">Sort by:</span>
        <label class="pp-sort-option">
          <input type="radio" name="pp_sortmode" value="common" checked> Common Name
        </label>
        <label class="pp-sort-option">
          <input type="radio" name="pp_sortmode" value="sci"> Scientific Name
        </label>
      </div>

      <form action="" method="POST" id="add"><?php echo csrf_field(); /* perchpi:s21a-csrf-v1 */ ?>
        <input autocomplete="off" type="text" placeholder="Search Species..." id="species_searchterm" name="species_searchterm">
        <select name="species[]" id="species" multiple size="25">
          <?php
          foreach($eachlabel as $lines){echo
            "<option value=\"".$lines."\">$lines</option>";
          } ?>
        </select>
        <input type="hidden" name="add" value="add">
      </form>
      <button type="submit" name="view" value="<?php echo $title ?>" form="add" class="pp-species-editor-btn pp-btn-add">&gt;&gt; ADD &gt;&gt;</button>
    </div>

    <!-- RIGHT COLUMN: Selected species (Included/Excluded/Whitelisted) -->
    <div class="pp-species-editor-col pp-col-selected">
      <h3><?php echo "$title" ?> Species</h3>

      <!-- Invisible spacer mirroring the LEFT column's sort-row DOM exactly.
           perchpi:species-list-align-fix-v1 — using identical markup (not a min-height
           override) guarantees pixel-perfect alignment regardless of font
           metrics or browser rendering quirks. -->
      <div class="pp-species-editor-sort-row pp-spacer" aria-hidden="true">
        <span class="pp-sort-label">Sort by:</span>
        <label class="pp-sort-option">
          <input type="radio" tabindex="-1"> Common Name
        </label>
        <label class="pp-sort-option">
          <input type="radio" tabindex="-1"> Scientific Name
        </label>
      </div>

      <form action="" method="POST" id="del"><?php echo csrf_field(); /* perchpi:s21a-csrf-v1 */ ?>
        <input autocomplete="off" type="text" placeholder="Search $title Species..." id="selected_searchterm" name="dummy">
        <select name="species[]" id="value2" multiple size="25">
          <?php
          if (count($eachselected) == 0) echo '<option disabled value="base">Please Select</option>';
          foreach($eachselected as $lines){echo
            "<option value=\"".$lines."\">$lines</option>";
          } ?>
        </select>
        <input type="hidden" name="del" value="del">
      </form>
      <button type="submit" name="view" value="<?php echo $title ?>" form="del" class="pp-species-editor-btn pp-btn-remove">REMOVE</button>
    </div>

  </div><!-- /.pp-species-editor-cols -->
</div><!-- /.pp-species-editor -->

<script>
/* perchpi:species-list-redesign-v2 - sort toggle, display flip, dual-column search.

   Display rule: the <option> shows 'Common (Scientific)' but the value=
   attribute stays as the original 'Sci_Common' string so form submission
   semantics and the include/exclude/whitelist files are unchanged. */
(function() {
  var originalOptions = {};   // {selectId: [raw "Sci_Common" strings]}
  var SORT_STORAGE_KEY = 'pp_species_sort_mode';

  function parseLabel(raw) {
    // Input: 'Genus species_Common Name'
    // Returns: {sci, common, display}
    var idx = raw.indexOf('_');
    if (idx === -1) {
      return { sci: raw, common: raw, display: raw };
    }
    var sci = raw.substring(0, idx);
    var common = raw.substring(idx + 1);
    return {
      sci: sci,
      common: common,
      display: common + ' (' + sci + ')'
    };
  }

  function sortLabels(labels, mode) {
    return labels.slice().sort(function(a, b) {
      var pa = parseLabel(a);
      var pb = parseLabel(b);
      var ka = (mode === 'sci') ? pa.sci : pa.common;
      var kb = (mode === 'sci') ? pb.sci : pb.common;
      return ka.toLowerCase().localeCompare(kb.toLowerCase());
    });
  }

  function getSortMode() {
    try {
      var saved = localStorage.getItem(SORT_STORAGE_KEY);
      if (saved === 'sci' || saved === 'common') return saved;
    } catch (e) {}
    return 'common';
  }

  function setSortMode(mode) {
    try { localStorage.setItem(SORT_STORAGE_KEY, mode); } catch (e) {}
  }

  function captureOriginal(selectId) {
    var sel = document.getElementById(selectId);
    if (!sel) return;
    if (originalOptions[selectId]) return;
    originalOptions[selectId] = Array.from(sel.options)
      .map(function(o) { return o.value; })
      .filter(function(v) { return v !== 'base'; });
  }

  function renderSelect(selectId, rawItems) {
    var sel = document.getElementById(selectId);
    if (!sel) return;
    while (sel.firstChild) sel.removeChild(sel.firstChild);

    if (rawItems.length === 0) {
      var ph = document.createElement('option');
      ph.disabled = true;
      ph.value = 'base';
      ph.textContent = 'Please Select';
      sel.appendChild(ph);
      return;
    }
    rawItems.forEach(function(raw) {
      var opt = document.createElement('option');
      opt.value = raw;                         // backend-format string preserved
      opt.textContent = parseLabel(raw).display; // user-facing display flipped
      sel.appendChild(opt);
    });
  }

  function applySortToBoth(mode) {
    ['species', 'value2'].forEach(function(id) {
      captureOriginal(id);
      var src = originalOptions[id] || [];
      var sorted = sortLabels(src, mode);
      originalOptions[id] = sorted;
      // For 'species' (All Species) re-apply active search filter.
      // For 'value2' (selected list) re-apply its own search filter.
      if (id === 'species') {
        applySearchFilter('species_searchterm', 'species');
      } else {
        applySearchFilter('selected_searchterm', 'value2');
      }
    });
  }

  function applySearchFilter(inputId, selectId) {
    var input = document.getElementById(inputId);
    var filter = input ? input.value.toUpperCase() : '';
    var src = originalOptions[selectId] || [];
    // Search matches against BOTH the raw value AND the display string
    // so users can search by common OR scientific name regardless of sort mode.
    var filtered = filter
      ? src.filter(function(raw) {
          var p = parseLabel(raw);
          var hay = (raw + ' ' + p.display).toUpperCase();
          return hay.indexOf(filter) > -1;
        })
      : src;
    renderSelect(selectId, filtered);
  }

  // ── Wire-up ─────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function() {
    captureOriginal('species');
    captureOriginal('value2');

    var mode = getSortMode();
    var radio = document.querySelector('input[name="pp_sortmode"][value="' + mode + '"]');
    if (radio) radio.checked = true;

    applySortToBoth(mode);

    document.querySelectorAll('input[name="pp_sortmode"]').forEach(function(r) {
      r.addEventListener('change', function() {
        if (this.checked) {
          setSortMode(this.value);
          applySortToBoth(this.value);
        }
      });
    });

    var allSearch = document.getElementById('species_searchterm');
    if (allSearch) {
      allSearch.addEventListener('keyup', function() {
        applySearchFilter('species_searchterm', 'species');
      });
    }

    var selSearch = document.getElementById('selected_searchterm');
    if (selSearch) {
      selSearch.addEventListener('keyup', function() {
        applySearchFilter('selected_searchterm', 'value2');
      });
    }

    var addForm = document.getElementById('add');
    if (addForm) {
      addForm.addEventListener('submit', function(event) {
        var sp = document.getElementById('species');
        if (sp.selectedIndex < 0) {
          alert('Please click the species you want to add.');
          var v = document.querySelector('.views');
          if (v) v.style.opacity = 1;
          event.preventDefault();
        }
      });
    }
  });
})();
</script>
