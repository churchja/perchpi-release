<?php
// perchpi:live-audio-view-v1
// PerchPi Live Audio view.
// Plays the HLS stream produced by perchpi-live-audio-stream.service via Caddy.
// Stream URL: /perchpi-hls/stream.m3u8 (relative — works regardless of host).
//
// Browser strategy:
//   - Safari (and modern iOS): native HLS via <audio src="...m3u8">
//   - Chrome/Firefox/Edge: hls.js (bundled at static/hls.min.js)
?>

<div class="page-header">
  <h1>🎙 Live Audio</h1>
  <p class="page-header-subtitle">Direct mic stream — about 6 seconds behind real time.</p>
</div>

<div class="live-audio-card" style="
    background: var(--surface-1);
    border: 1px solid var(--border-soft);
    border-radius: var(--radius-md);
    padding: var(--space-6);
    max-width: 560px;
    margin: var(--space-4) 0;">

  <div style="display: flex; align-items: center; gap: var(--space-4); margin-bottom: var(--space-4);">
    <button id="pp-live-toggle" type="button"
            style="
              background: var(--accent);
              color: var(--accent-contrast, white);
              border: 0;
              border-radius: 50%;
              width: 64px;
              height: 64px;
              font-size: 28px;
              cursor: pointer;
              display: flex;
              align-items: center;
              justify-content: center;
              flex-shrink: 0;">
      ▶
    </button>

    <div style="flex: 1;">
      <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-1);">
        <span id="pp-live-dot" style="
              display: inline-block;
              width: 10px;
              height: 10px;
              border-radius: 50%;
              background: var(--text-3, #999);
              transition: background 0.3s;"></span>
        <span id="pp-live-state" style="font-weight: 600;">Stopped</span>
      </div>
      <div id="pp-live-detail" style="color: var(--text-2); font-size: 0.9em;">
        Click play to start streaming.
      </div>
    </div>
  </div>

  <audio id="pp-live-audio" preload="none" style="width: 100%;"></audio>

  <details style="margin-top: var(--space-4); font-size: 0.9em; color: var(--text-2);">
    <summary style="cursor: pointer;">About this stream</summary>
    <p style="margin-top: var(--space-2);">
      Audio is streamed via HLS (HTTP Live Streaming) using 2-second segments.
      There's roughly 6 seconds of buffering latency, so what you hear is
      a few seconds behind the actual mic. Audio is AAC 96 kbps mono at 48 kHz.
    </p>
    <p>
      The stream is also available directly at:
      <code style="background: var(--surface-2); padding: 2px 6px; border-radius: 3px;">/perchpi-hls/stream.m3u8</code>
    </p>
  </details>
</div>

<style>
  #pp-live-dot.live {
    background: #e74c3c;
    animation: pp-live-pulse 1.6s ease-in-out infinite;
  }
  @keyframes pp-live-pulse {
    0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.6); }
    50%      { opacity: 0.6; box-shadow: 0 0 0 8px rgba(231, 76, 60, 0); }
  }
  #pp-live-toggle:hover { filter: brightness(1.1); }
  #pp-live-toggle:active { transform: scale(0.95); }
</style>

<script src="static/hls.min.js"></script>
<script>
(function() {
  var audio = document.getElementById('pp-live-audio');
  var btn = document.getElementById('pp-live-toggle');
  var dot = document.getElementById('pp-live-dot');
  var stateLabel = document.getElementById('pp-live-state');
  var detail = document.getElementById('pp-live-detail');

  var STREAM_URL = '/perchpi-hls/stream.m3u8';
  var hls = null;
  var playing = false;

  function setState(s, d) {
    stateLabel.textContent = s;
    if (d !== undefined) detail.textContent = d;
    if (s === 'Live') {
      dot.classList.add('live');
      btn.textContent = '⏸';
    } else {
      dot.classList.remove('live');
      btn.textContent = '▶';
    }
  }

  function teardown() {
    if (hls) {
      try { hls.destroy(); } catch (e) {}
      hls = null;
    }
    audio.removeAttribute('src');
    audio.load();
    playing = false;
  }

  function start() {
    setState('Buffering…', 'Loading stream segments…');

    if (window.Hls && Hls.isSupported()) {
      // Chrome, Firefox, Edge path — hls.js + MSE
      hls = new Hls({
        liveSyncDuration: 4,        // start ~4 sec behind live edge
        liveMaxLatencyDuration: 10  // recover toward live if we fall behind
      });
      hls.loadSource(STREAM_URL);
      hls.attachMedia(audio);
      hls.on(Hls.Events.MANIFEST_PARSED, function() {
        audio.play().then(function() {
          playing = true;
          setState('Live', 'Streaming.');
        }).catch(function(err) {
          setState('Error', 'Playback blocked: ' + err.message);
        });
      });
      hls.on(Hls.Events.ERROR, function(event, data) {
        if (data.fatal) {
          setState('Error', 'Fatal: ' + (data.details || data.type));
          teardown();
        }
      });
    } else if (audio.canPlayType('application/vnd.apple.mpegurl') ||
               audio.canPlayType('audio/x-mpegurl')) {
      // Safari / iOS native HLS path
      audio.src = STREAM_URL;
      audio.play().then(function() {
        playing = true;
        setState('Live', 'Streaming (native HLS).');
      }).catch(function(err) {
        setState('Error', 'Playback blocked: ' + err.message);
      });
    } else {
      setState('Error', 'Your browser does not support HLS playback.');
    }
  }

  function stop() {
    teardown();
    setState('Stopped', 'Click play to resume.');
  }

  btn.addEventListener('click', function() {
    if (playing) stop(); else start();
  });

  // Clean up when navigating away
  window.addEventListener('beforeunload', teardown);
})();
</script>
