<?php
/* ============================================================================
   BirdNET-Pi / PerchPi — overview.php (v2.2.2)
   ============================================================================
   Sets $_GET['tab'] = 'overview' so kiosk.php's tab parser picks it up.
   $EMBEDDED skips kiosk.php's outer chrome.
   ============================================================================ */

$_GET['tab'] = 'overview';
$EMBEDDED = true;

echo '<div class="overview-grid">';
include __DIR__ . '/kiosk.php';
echo '</div>';

/* perchpi:overview-live-audio-v1 — make the kla-tile functional in embedded ?view=Overview */
?>
<script src="static/hls.min.js"></script>
<script>
(function setupOverviewLiveAudio() {
  var tile = document.getElementById('kla-tile');
  var audio = document.getElementById('kla-audio');
  var dot = document.getElementById('kla-dot');
  var val = document.getElementById('kla-val');
  if (!tile || !audio || !dot || !val) return;
  if (tile.dataset.klaInitialized === '1') return;  /* idempotent if both kiosk.js and this run */
  tile.dataset.klaInitialized = '1';

  var STREAM_URL = '/perchpi-hls/stream.m3u8';
  var hls = null;
  var playing = false;

  function setState(s) {
    dot.className = 'sys-dot';
    if (s === 'live') { dot.classList.add('live'); val.textContent = 'Live'; }
    else if (s === 'buffering') { dot.classList.add('buffering'); val.textContent = 'Loading…'; }
    else if (s === 'error') { dot.classList.add('error'); val.textContent = 'Error'; }
    else { val.textContent = 'Off'; }
  }

  function teardown() {
    if (hls) { try { hls.destroy(); } catch (e) {} hls = null; }
    audio.removeAttribute('src');
    audio.load();
    playing = false;
  }

  function start() {
    setState('buffering');
    if (window.Hls && Hls.isSupported()) {
      hls = new Hls({ liveSyncDuration: 4, liveMaxLatencyDuration: 10 });
      hls.loadSource(STREAM_URL);
      hls.attachMedia(audio);
      hls.on(Hls.Events.MANIFEST_PARSED, function () {
        audio.play().then(function () { playing = true; setState('live'); })
                    .catch(function () { setState('error'); });
      });
      hls.on(Hls.Events.ERROR, function (e, data) {
        if (data.fatal) { setState('error'); teardown(); }
      });
    } else if (audio.canPlayType('application/vnd.apple.mpegurl') ||
               audio.canPlayType('audio/x-mpegurl')) {
      audio.src = STREAM_URL;
      audio.play().then(function () { playing = true; setState('live'); })
                  .catch(function () { setState('error'); });
    } else {
      setState('error');
    }
  }

  function stop() { teardown(); setState('off'); }
  function toggle() { if (playing) stop(); else start(); }

  tile.addEventListener('click', toggle);
  tile.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
  });

  window.addEventListener('beforeunload', teardown);
})();
</script>
<?php

