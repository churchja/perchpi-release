/* perchpi:scrubber-component-v1 */
/*
 * pp_scrubber.js — reusable touch-friendly audio scrubber.
 *
 * Usage:
 *   <div class="pp-scrubber" data-audio="path/to/audio.mp3"
 *                            data-spectrogram="path/to/spectrogram.png">
 *   </div>
 *
 * The element will be filled with: a spectrogram image + a position cursor +
 * an invisible <audio> element. Click/tap/drag anywhere on the spectrogram
 * to seek + play. Cursor moves as audio plays.
 *
 * Public API:
 *   pp_scrubber_init()                — scan document for .pp-scrubber elements,
 *                                       wire them up. Idempotent (skips initialized).
 *   pp_scrubber_load(el, audio, spec) — swap audio + spectrogram on an existing
 *                                       scrubber. Pauses current playback, rebuilds,
 *                                       and auto-plays new content.
 *   element.ppPause()                 — programmatic pause (added to each element)
 *
 * Events (dispatched on .pp-scrubber elements):
 *   pp-scrubber-ended                 — audio reached natural end (cursor reset to 0)
 *
 * Only one scrubber plays at a time — starting playback on one pauses others.
 * perchpi:scrubber-docstring-v1
 */
(function () {
  'use strict';

  var registry = [];

  function fmt(t) {
    if (!isFinite(t)) return '0:00';
    var m = Math.floor(t / 60);
    var s = Math.floor(t % 60);
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function pauseOthers(except) {
    registry.forEach(function (entry) {
      if (entry !== except && entry.audio && !entry.audio.paused) {
        entry.audio.pause();
      }
    });
  }

  function initOne(el) {
    if (el.dataset.ppInitialized === '1') return;
    el.dataset.ppInitialized = '1';

    var audioSrc = el.getAttribute('data-audio');
    var specSrc  = el.getAttribute('data-spectrogram');
    if (!audioSrc) {
      el.innerHTML = '<div class="pp-scrubber-empty">no audio</div>';
      return;
    }

    el.innerHTML = '';
    el.classList.add('pp-scrubber-ready');

    // Spectrogram image (or placeholder)
    var img = document.createElement('img');
    img.className = 'pp-scrubber-spec';
    img.draggable = false;
    if (specSrc) {
      img.src = specSrc;
      img.alt = 'spectrogram';
    } else {
      img.alt = '';
      el.classList.add('pp-scrubber-no-spec');
    }
    el.appendChild(img);

    // Position cursor
    var cursor = document.createElement('div');
    cursor.className = 'pp-scrubber-cursor';
    cursor.style.left = '0%';
    el.appendChild(cursor);

    // Time label (small, bottom-right)
    var time = document.createElement('div');
    time.className = 'pp-scrubber-time';
    time.textContent = '0:00';
    el.appendChild(time);

    // Audio element (hidden)
    var audio = document.createElement('audio');
    audio.preload = 'metadata';
    audio.src = audioSrc;
    el.appendChild(audio);

    var entry = { el: el, audio: audio, cursor: cursor, time: time };
    registry.push(entry);

    el.ppPause = function () { audio.pause(); };

    // ---- seek logic --------------------------------------------------------

    var dragging = false;

    function pctFromEvent(e) {
      var rect = el.getBoundingClientRect();
      var x;
      if (e.touches && e.touches.length) {
        x = e.touches[0].clientX;
      } else if (e.changedTouches && e.changedTouches.length) {
        x = e.changedTouches[0].clientX;
      } else {
        x = e.clientX;
      }
      var pct = (x - rect.left) / rect.width;
      if (pct < 0) pct = 0;
      if (pct > 1) pct = 1;
      return pct;
    }

    function applyPct(pct, andPlay) {
      cursor.style.left = (pct * 100) + '%';
      if (audio.duration && isFinite(audio.duration)) {
        audio.currentTime = pct * audio.duration;
        time.textContent = fmt(audio.currentTime);
      }
      if (andPlay) {
        pauseOthers(entry);
        audio.play().catch(function () { /* user gesture required, ignore */ });
      }
    }

    el.addEventListener('mousedown', function (e) {
      e.preventDefault();
      dragging = true;
      applyPct(pctFromEvent(e), true);
    });
    document.addEventListener('mousemove', function (e) {
      if (!dragging) return;
      applyPct(pctFromEvent(e), false);
    });
    document.addEventListener('mouseup', function () {
      if (dragging) dragging = false;
    });

    el.addEventListener('touchstart', function (e) {
      e.preventDefault();
      dragging = true;
      applyPct(pctFromEvent(e), true);
    }, { passive: false });
    el.addEventListener('touchmove', function (e) {
      if (!dragging) return;
      e.preventDefault();
      applyPct(pctFromEvent(e), false);
    }, { passive: false });
    el.addEventListener('touchend', function () {
      if (dragging) dragging = false;
    });

    // ---- audio events updating cursor + time --------------------------------

    audio.addEventListener('timeupdate', function () {
      if (audio.duration && isFinite(audio.duration)) {
        var pct = audio.currentTime / audio.duration;
        // perchpi:scrubber-cursor-clamp-v1 — decode overshoot can briefly
        // make currentTime > duration; clamp so the cursor never visually
        // exits the right edge of the spectrogram.
        if (pct < 0) pct = 0;
        if (pct > 1) pct = 1;
        cursor.style.left = (pct * 100) + '%';
        time.textContent = fmt(audio.currentTime);
      }
    });
    audio.addEventListener('ended', function () {
      cursor.style.left = '0%';
      audio.currentTime = 0;
      time.textContent = '0:00';
      // perchpi:scrubber-ended-event-v1 — fire event so consumers can react (dismiss dock, clear UI state, etc.)
      el.dispatchEvent(new CustomEvent('pp-scrubber-ended'));
    });
    audio.addEventListener('play', function () { el.classList.add('pp-scrubber-playing'); });
    audio.addEventListener('pause', function () { el.classList.remove('pp-scrubber-playing'); });
    audio.addEventListener('loadedmetadata', function () {
      time.textContent = fmt(0) + ' / ' + fmt(audio.duration);
    });
  }

  function initAll() {
    // perchpi:dock-scrubber-relocate-v1 — relocate the fixed-position
    // dock to <body> so its position:fixed escapes any containing-block-
    // establishing ancestor (transform/filter/contain on .app-main or
    // similar would otherwise make position:fixed behave like
    // position:absolute relative to that ancestor, shrinking the dock
    // and pushing the playhead cursor off the right side of the viewport).
    var dock = document.getElementById('pp-dock-scrubber');
    if (dock && dock.parentNode && dock.parentNode !== document.body) {
      document.body.appendChild(dock);
    }
    var nodes = document.querySelectorAll('.pp-scrubber');
    for (var i = 0; i < nodes.length; i++) initOne(nodes[i]);
  }

  // perchpi:scrubber-load-v1 — swap audio + spectrogram on an existing scrubber
  function loadOne(el, newAudioSrc, newSpecSrc) {
    if (!el) return;
    // Find existing entry, pause + remove
    var idx = -1;
    for (var i = 0; i < registry.length; i++) {
      if (registry[i].el === el) { idx = i; break; }
    }
    if (idx >= 0) {
      var entry = registry[idx];
      try { entry.audio.pause(); } catch (e) { /* ignore */ }
      registry.splice(idx, 1);
    }
    // Reset state
    el.dataset.ppInitialized = '';
    el.classList.remove('pp-scrubber-ready', 'pp-scrubber-no-spec', 'pp-scrubber-playing');
    el.innerHTML = '';
    // Apply new attrs
    if (newAudioSrc) { el.setAttribute('data-audio', newAudioSrc); }
    else { el.removeAttribute('data-audio'); }
    if (newSpecSrc)  { el.setAttribute('data-spectrogram', newSpecSrc); }
    else { el.removeAttribute('data-spectrogram'); }
    // Rebuild
    initOne(el);
    // perchpi:scrubber-load-autoplay-v1 — auto-play after load.
    // initOne attached <audio> to el; find it and trigger play(). Pause others first.
    if (newAudioSrc) {
      var newEntry = null;
      for (var j = 0; j < registry.length; j++) {
        if (registry[j].el === el) { newEntry = registry[j]; break; }
      }
      if (newEntry && newEntry.audio) {
        pauseOthers(newEntry);
        var p = newEntry.audio.play();
        if (p && typeof p.catch === 'function') {
          p.catch(function () { /* user gesture not yet granted, ignore */ });
        }
      }
    }
  }

  // Public API
  window.pp_scrubber_init = initAll;
  window.pp_scrubber_load = loadOne;

  // Auto-init on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
