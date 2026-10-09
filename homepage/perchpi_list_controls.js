/* perchpi:list-controls-module-v1
 * ────────────────────────────────────────────────────────────────────────
 * Shared sort + filter controls for list-style pages (Best Recordings,
 * Species Stats, etc).  Auto-initializes on DOMContentLoaded by scanning
 * for `.pp-list-controls` elements.
 *
 * Required markup (emitted by pp_render_list_controls() PHP helper):
 *
 *   <div class="pp-list-controls"
 *        data-pp-storage-key="..."           // localStorage key prefix
 *        data-pp-row-selector=".item-row"    // selector for sortable rows
 *        data-pp-filter-fields="com,sci"     // comma-sep data-pp-* names
 *        data-pp-item-label="species">       // for counter (default "items")
 *     <div class="pp-list-sort">
 *       <a data-pp-sort="alpha"
 *          data-pp-default="1"               // implicit default if none flagged
 *          data-pp-dir-default="asc"
 *          data-pp-label-asc="Alphabetical"
 *          data-pp-label-desc="Reverse alphabetical"
 *          data-pp-row-attr="com"
 *          data-pp-type="string"             // 'string' | 'number'
 *          data-pp-xc-last="0"               // '1' to push XC rows to bottom
 *          href="#sort=alpha&dir=asc">Alphabetical</a>
 *       ...
 *     </div>
 *     <div class="pp-list-filter-wrap">
 *       <input class="pp-list-filter-input" placeholder="...">
 *       <span class="pp-list-counter"></span>
 *     </div>
 *   </div>
 *
 * Rows (anywhere on the page, matching data-pp-row-selector):
 *
 *   <div class="item-row"
 *        data-pp-com="Northern Cardinal"
 *        data-pp-sci="Cardinalis cardinalis"
 *        data-pp-conf="99"
 *        data-pp-dt="2026-05-12 18:30"
 *        data-pp-is-xc="0">...</div>
 *
 * URL behavior:
 *   On load, read ?sort and ?dir from URL (else use defaults). Apply sort.
 *   On sort-button click, update URL via history.replaceState (no reload).
 *   Filter is local-only (localStorage), never in URL.
 * ────────────────────────────────────────────────────────────────────────
 */
(function() {
  'use strict';

  function attrName(s) {
    // 'pp-conf' -> 'ppConf' (dataset camelCase)
    return s.replace(/-([a-z])/g, function(_, c) { return c.toUpperCase(); });
  }

  function findBtn(buttons, sortKey) {
    for (var i = 0; i < buttons.length; i++) {
      if (buttons[i].dataset.ppSort === sortKey) return buttons[i];
    }
    return null;
  }

  function init(container) {
    var rowSelector = container.dataset.ppRowSelector;
    var storageKey = container.dataset.ppStorageKey || 'pp-list';
    var filterFields = (container.dataset.ppFilterFields || 'com,sci').split(',');
    var itemLabel = container.dataset.ppItemLabel || 'items';

    if (!rowSelector) { return; }

    var rows = Array.prototype.slice.call(document.querySelectorAll(rowSelector));
    var buttons = Array.prototype.slice.call(
      container.querySelectorAll('.pp-list-sort [data-pp-sort]')
    );
    var filterInput = container.querySelector('.pp-list-filter-input');
    var counter = container.querySelector('.pp-list-counter');

    if (rows.length === 0) {
      if (counter) counter.textContent = '0 ' + itemLabel;
      return;
    }
    if (buttons.length === 0) { return; }

    // ─── Determine current sort+dir from URL or defaults ───────────────
    var params = new URLSearchParams(location.search);
    var sort = params.get('sort');
    var dir = params.get('dir');

    var validSortKeys = buttons.map(function(b) { return b.dataset.ppSort; });
    if (!sort || validSortKeys.indexOf(sort) < 0) {
      var defBtn = null;
      for (var i = 0; i < buttons.length; i++) {
        if (buttons[i].dataset.ppDefault === '1') { defBtn = buttons[i]; break; }
      }
      sort = defBtn ? defBtn.dataset.ppSort : buttons[0].dataset.ppSort;
    }
    if (dir !== 'asc' && dir !== 'desc') {
      dir = findBtn(buttons, sort).dataset.ppDirDefault || 'asc';
    }

    // ─── Sort ──────────────────────────────────────────────────────────
    function applySort() {
      var btn = findBtn(buttons, sort);
      var rowAttr = btn.dataset.ppRowAttr;
      var sortType = btn.dataset.ppType || 'string';
      var xcLast = btn.dataset.ppXcLast === '1';
      var mult = (dir === 'desc') ? -1 : 1;
      var attrCamel = attrName('pp-' + rowAttr);

      rows.sort(function(a, b) {
        if (xcLast) {
          var aIsXc = a.dataset.ppIsXc === '1';
          var bIsXc = b.dataset.ppIsXc === '1';
          if (aIsXc && !bIsXc) return 1;
          if (!aIsXc && bIsXc) return -1;
        }
        var av, bv;
        if (sortType === 'number') {
          av = parseFloat(a.dataset[attrCamel]);
          bv = parseFloat(b.dataset[attrCamel]);
          if (isNaN(av)) av = -Infinity;
          if (isNaN(bv)) bv = -Infinity;
          if (av !== bv) return mult * (av > bv ? 1 : -1);
        } else {
          av = (a.dataset[attrCamel] || '').toLowerCase();
          bv = (b.dataset[attrCamel] || '').toLowerCase();
          if (av !== bv) return mult * (av < bv ? -1 : 1);
        }
        // Tiebreaker: common name alpha (case-insensitive, direction-independent)
        var aCom = (a.dataset.ppCom || '').toLowerCase();
        var bCom = (b.dataset.ppCom || '').toLowerCase();
        if (aCom !== bCom) return aCom < bCom ? -1 : 1;
        return 0;
      });

      // Reorder DOM
      var parent = rows[0].parentNode;
      var frag = document.createDocumentFragment();
      rows.forEach(function(r) { frag.appendChild(r); });
      parent.appendChild(frag);

      // Update button labels + active state + arrows
      buttons.forEach(function(b) {
        var isActive = b.dataset.ppSort === sort;
        b.classList.toggle('active', isActive);
        var label;
        var arrow = '';
        if (isActive) {
          label = b.dataset[dir === 'desc' ? 'ppLabelDesc' : 'ppLabelAsc'];
          arrow = (dir === 'desc') ? ' \u25BC' : ' \u25B2';
        } else {
          var def = b.dataset.ppDirDefault || 'asc';
          label = b.dataset[def === 'desc' ? 'ppLabelDesc' : 'ppLabelAsc'];
        }
        if (!label) label = b.dataset.ppSort;
        b.textContent = label + arrow;

        // Update href for graceful degradation if JS later breaks
        var nextDir;
        if (isActive) {
          nextDir = (dir === 'desc') ? 'asc' : 'desc';
        } else {
          nextDir = b.dataset.ppDirDefault || 'asc';
        }
        b.setAttribute('href', '#sort=' + b.dataset.ppSort + '&dir=' + nextDir);
      });

      // Update URL without navigation
      try {
        var url = new URL(location);
        url.searchParams.set('sort', sort);
        url.searchParams.set('dir', dir);
        history.replaceState(null, '', url);
      } catch (e) { /* History API unavailable */ }
    }

    // ─── Filter ────────────────────────────────────────────────────────
    function applyFilter() {
      if (!filterInput) return;
      var query = (filterInput.value || '').trim().toLowerCase();
      var shown = 0;
      var total = rows.length;
      rows.forEach(function(row) {
        var match = !query;
        if (!match) {
          for (var i = 0; i < filterFields.length; i++) {
            var f = filterFields[i].trim();
            if (!f) continue;
            var v = (row.dataset[attrName('pp-' + f)] || '').toLowerCase();
            if (v.indexOf(query) >= 0) { match = true; break; }
          }
        }
        row.style.display = match ? '' : 'none';
        if (match) shown++;
      });
      if (counter) {
        counter.textContent = query
          ? ('Showing ' + shown + ' of ' + total)
          : (total + ' ' + itemLabel);
      }
    }

    // ─── Wire up sort buttons ──────────────────────────────────────────
    buttons.forEach(function(b) {
      b.addEventListener('click', function(e) {
        e.preventDefault();
        var clicked = b.dataset.ppSort;
        if (clicked === sort) {
          dir = (dir === 'desc') ? 'asc' : 'desc';
        } else {
          sort = clicked;
          dir = b.dataset.ppDirDefault || 'asc';
        }
        applySort();
        applyFilter();
      });
    });

    // ─── Wire up filter input ──────────────────────────────────────────
    if (filterInput) {
      try {
        var saved = localStorage.getItem(storageKey + '-filter');
        if (saved !== null) filterInput.value = saved;
      } catch (e) { /* SecurityError in sandboxes */ }

      filterInput.addEventListener('input', function() {
        try { localStorage.setItem(storageKey + '-filter', filterInput.value); }
        catch (e) {}
        applyFilter();
      });
    }

    // Initial render
    applySort();
    applyFilter();
  }

  function initAll() {
    document.querySelectorAll('.pp-list-controls').forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
