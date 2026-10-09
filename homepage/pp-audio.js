/**
 * pp-audio.js — perceptual-loudness-normalized FLAC playback
 *
 * Marker: perchpi:audio-normalize-v8
 *
 * v8 (2026-05-23): The Big Discovery
 *
 *   Diagnostic confirmed all prior versions (v2-v7) were never in the
 *   audio path. The perchpi.js bridge only fired inside the dock branch,
 *   which is bypassed when window.pp_scrubber_load is undefined.
 *   The Best Recordings page (and likely others) has no scrubber loaded,
 *   so a static <audio> element played the raw FLAC directly.
 *
 *   v8 fix: install a universal interceptor that hooks every <audio>
 *   element on the page (existing + future). When any <audio>.play() is
 *   called, the interceptor:
 *     1. Reads the .src
 *     2. Mutes the native element (silent timing reference)
 *     3. Routes audio through PPAudio.play() with proper gain
 *
 *   This works on every page regardless of scrubber state.
 *
 *   Algorithm restored to v3 (the one Jason confirmed sounded best):
 *     - gainDivisor = 12.0
 *     - gainFloor   = 15.0    (was lost in v7 cleanup)
 *     - gainCeiling = 500.0
 *     - No compressor/limiter/peak-norm
 *     - 5-line BirdWeather RMS gain with floor/ceiling clamp
 *
 * Public API:
 *   PPAudio.play(url, opts)
 *   PPAudio.stopAll()
 *   PPAudio.setEnabled(bool) / isEnabled()
 *   PPAudio.setGainDivisor(n)         default 12.0
 *   PPAudio.setGainFloor(n)           default 15.0
 *   PPAudio.setGainCeiling(n)         default 500.0
 *   PPAudio.installInterceptor()      manually retrigger interceptor install
 *   PPAudio.uninstallInterceptor()    disable interception (for debugging)
 *   PPAudio.interceptorStats()        { hooked: n, intercepted: n }
 *   PPAudio.tuning
 *   PPAudio.peak() / PPAudio.lastPeak
 *   PPAudio.clearCache()
 */
(function (window) {
  'use strict';

  let audioCtx = null;
  const activeClips = new Set();
  const bufferCache = new Map();
  const CACHE_MAX = 8;
  let enabled = true;

  const tuning = {
    gainDivisor: 12.0,
    gainFloor:   15.0,
    gainCeiling: 500.0
  };

  try {
    const E = localStorage.getItem('pp-audio-normalize-enabled');
    if (E !== null) enabled = E === '1';
    const d = parseFloat(localStorage.getItem('pp-audio-gain-divisor'));
    if (isFinite(d) && d > 0) tuning.gainDivisor = d;
    const f = parseFloat(localStorage.getItem('pp-audio-gain-floor'));
    if (isFinite(f) && f >= 0) tuning.gainFloor = f;
    const c = parseFloat(localStorage.getItem('pp-audio-gain-ceiling'));
    if (isFinite(c) && c > 0) tuning.gainCeiling = c;
  } catch (_) {}

  function ensureCtx() {
    if (!audioCtx) {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) throw new Error('Web Audio API not supported');
      audioCtx = new AC();
    }
    if (audioCtx.state === 'suspended') audioCtx.resume();
    return audioCtx;
  }

  function calculateGain(buffer, sampleRate) {
    const sliceLen = Math.floor(sampleRate * 0.05);
    if (sliceLen <= 0) return tuning.gainFloor;
    const averages = [];
    let sum = 0;
    for (let i = 0; i < buffer.length; ++i) {
      sum += buffer[i] * buffer[i];
      if (i % sliceLen === 0) {
        averages.push(Math.sqrt(sum / sliceLen));
        sum = 0;
      }
    }
    if (averages.length === 0) return tuning.gainFloor;
    averages.sort((a, b) => a - b);
    const p95 = averages[Math.floor(averages.length * 0.95)];
    if (!p95 || p95 < 1e-6) return tuning.gainFloor;
    let g = 1.0 / (p95 * tuning.gainDivisor);
    if (g < tuning.gainFloor)   g = tuning.gainFloor;
    if (g > tuning.gainCeiling) g = tuning.gainCeiling;
    return g;
  }

  function cacheGet(url) {
    if (!bufferCache.has(url)) return null;
    const e = bufferCache.get(url);
    bufferCache.delete(url); bufferCache.set(url, e);
    return e;
  }
  function cachePut(url, entry) {
    if (bufferCache.has(url)) bufferCache.delete(url);
    bufferCache.set(url, entry);
    while (bufferCache.size > CACHE_MAX) bufferCache.delete(bufferCache.keys().next().value);
  }
  function clearCache() { bufferCache.clear(); }

  async function loadAndAnalyze(url) {
    const cached = cacheGet(url);
    if (cached) return cached;
    const ctx = ensureCtx();
    const resp = await fetch(url);
    if (!resp.ok) throw new Error('fetch ' + url + ': ' + resp.status);
    const arr = await resp.arrayBuffer();
    const buffer = await ctx.decodeAudioData(arr);
    const gain = calculateGain(buffer.getChannelData(0), buffer.sampleRate);
    const entry = { buffer: buffer, gain: gain };
    cachePut(url, entry);
    return entry;
  }

  let mostRecentHandle = null;

  async function play(url, opts) {
    opts = opts || {};
    const ctx = ensureCtx();
    const loaded = await loadAndAnalyze(url);
    const buffer = loaded.buffer;
    const gain = loaded.gain;

    const src = ctx.createBufferSource();
    src.buffer = buffer;

    const gainNode = ctx.createGain();
    gainNode.gain.value = (opts.bypass || !enabled) ? 1.0 : gain;

    // perchpi:option-b-ppaudio-opts-v1 - optional biquad filters + manual gain
    // for the custom-audio-player delegation path; built only when requested.
    let hpFilter = null, lpFilter = null;
    if (isFinite(opts.highpassHz) && opts.highpassHz > 0) {
      hpFilter = ctx.createBiquadFilter();
      hpFilter.type = 'highpass';
      hpFilter.frequency.value = opts.highpassHz;
    }
    if (isFinite(opts.lowpassHz) && opts.lowpassHz > 0) {
      lpFilter = ctx.createBiquadFilter();
      lpFilter.type = 'lowpass';
      lpFilter.frequency.value = opts.lowpassHz;
    }
    const manualGainNode = ctx.createGain();
    manualGainNode.gain.value =
      (isFinite(opts.manualGain) && opts.manualGain > 0) ? opts.manualGain : 1.0;

    const analyser = ctx.createAnalyser();
    analyser.fftSize = 256;
    const peakData = new Float32Array(analyser.fftSize);
    let runningPeak = -120, livePeak = -120;

    // perchpi:option-b-ppaudio-opts-v1 - routing:
    // src -> [hp] -> [lp] -> gainNode(normalized) -> manualGainNode -> analyser -> dest
    let _node = src;
    if (hpFilter) { _node.connect(hpFilter); _node = hpFilter; }
    if (lpFilter) { _node.connect(lpFilter); _node = lpFilter; }
    _node.connect(gainNode);
    gainNode.connect(manualGainNode);
    manualGainNode.connect(analyser);
    analyser.connect(ctx.destination);

    let meterRaf = null;
    function tickMeter() {
      analyser.getFloatTimeDomainData(peakData);
      let p = 0;
      for (let i = 0; i < peakData.length; ++i) {
        const a = Math.abs(peakData[i]);
        if (a > p) p = a;
      }
      livePeak = p > 0 ? 20 * Math.log10(p) : -120;
      if (livePeak > runningPeak) runningPeak = livePeak;
      meterRaf = requestAnimationFrame(tickMeter);
    }
    tickMeter();

    const startAt = Math.max(0, Math.min(opts.startAt || 0, buffer.duration));
    const startedCtxTime = ctx.currentTime;
    src.start(0, startAt);

    let paused = false, pausedAt = 0, endedCb = null, ended = false;

    const handle = {
      pause: function () {
        if (paused || ended) return;
        pausedAt = (ctx.currentTime - startedCtxTime) + startAt;
        try { src.stop(); } catch (_) {}
        paused = true;
        if (meterRaf) cancelAnimationFrame(meterRaf);
      },
      resume: function () {
        if (!paused || ended) return;
        const sub = play(url, { bypass: opts.bypass, startAt: pausedAt,
          highpassHz: opts.highpassHz, lowpassHz: opts.lowpassHz,
          manualGain: opts.manualGain });
        sub.then(function (n) { Object.assign(handle, n); });
        paused = false;
      },
      stop: function () {
        if (ended) return;
        try { src.stop(); } catch (_) {}
        ended = true;
        if (meterRaf) cancelAnimationFrame(meterRaf);
        activeClips.delete(handle);
        window.PPAudio.lastPeak = runningPeak;
        if (mostRecentHandle === handle) mostRecentHandle = null;
        if (endedCb) endedCb();
      },
      getTime: function () {
        if (ended) return buffer.duration;
        if (paused) return pausedAt;
        return (ctx.currentTime - startedCtxTime) + startAt;
      },
      getDuration: function () { return buffer.duration; },
      getPeak: function () { return runningPeak; },
      getLivePeak: function () { return livePeak; },
      onEnded: function (cb) { endedCb = cb; },
      _gain: gainNode.gain.value,
      _gainRaw: gain,
      _url: url,
      _ended: function () { return ended; }
    };

    src.onended = function () {
      if (ended) return;
      ended = true;
      if (meterRaf) cancelAnimationFrame(meterRaf);
      activeClips.delete(handle);
      window.PPAudio.lastPeak = runningPeak;
      if (mostRecentHandle === handle) mostRecentHandle = null;
      if (endedCb) endedCb();
    };

    activeClips.add(handle);
    mostRecentHandle = handle;
    return handle;
  }

  function stopAll() {
    activeClips.forEach(function (h) { try { h.stop(); } catch (_) {} });
    activeClips.clear();
  }
  function setEnabled(v) {
    enabled = !!v;
    try { localStorage.setItem('pp-audio-normalize-enabled', enabled ? '1' : '0'); } catch (_) {}
  }
  function isEnabled() { return enabled; }
  function setGainDivisor(n) {
    if (!isFinite(n) || n <= 0) { console.warn('setGainDivisor: invalid', n); return; }
    tuning.gainDivisor = n;
    try { localStorage.setItem('pp-audio-gain-divisor', String(n)); } catch (_) {}
    clearCache();
    console.log('PPAudio: gainDivisor =', n, '(cache cleared)');
  }
  function setGainFloor(n) {
    if (!isFinite(n) || n < 0) { console.warn('setGainFloor: invalid', n); return; }
    tuning.gainFloor = n;
    try { localStorage.setItem('pp-audio-gain-floor', String(n)); } catch (_) {}
    clearCache();
    console.log('PPAudio: gainFloor =', n, '(cache cleared)');
  }
  function setGainCeiling(n) {
    if (!isFinite(n) || n <= 0) { console.warn('setGainCeiling: invalid', n); return; }
    tuning.gainCeiling = n;
    try { localStorage.setItem('pp-audio-gain-ceiling', String(n)); } catch (_) {}
    clearCache();
    console.log('PPAudio: gainCeiling =', n, '(cache cleared)');
  }
  function currentPeak() {
    if (!mostRecentHandle || mostRecentHandle._ended()) return null;
    return {
      live:    +mostRecentHandle.getLivePeak().toFixed(2),
      running: +mostRecentHandle.getPeak().toFixed(2)
    };
  }

  // ============== UNIVERSAL <audio> INTERCEPTOR ==============
  let interceptorObserver = null;
  let interceptorActive = false;
  const hookedAudioElements = new WeakSet();
  const stats = { hooked: 0, intercepted: 0 };

  function hookAudioElement(el) {
    if (!el || hookedAudioElements.has(el)) return;
    // perchpi:option-b-ppaudio-opts-v1 - the custom audio player drives
    // PPAudio.play() itself; do not also hook its <audio> or we double-play.
    if (el.closest && el.closest('.custom-audio-player')) return;
    hookedAudioElements.add(el);
    stats.hooked++;

    // Mute the native element so it becomes a silent timing reference
    // It still progresses through the file (so any UI synced to its
    // currentTime/duration keeps working), but produces no sound.
    try { el.muted = true; el.volume = 0; } catch (_) {}

    // Intercept the play() method
    const _origPlay = el.play.bind(el);
    el.play = function() {
      if (interceptorActive && enabled) {
        const src = el.src || el.currentSrc;
        if (src) {
          stats.intercepted++;
          // Stop any active PPAudio clips
          stopAll();
          // Route through PPAudio
          play(src).then(function(h) {
            el._ppHandle = h;
            // When PPAudio finishes, also "end" the native element
            h.onEnded(function() {
              try { el.pause(); el.currentTime = 0; } catch (_) {}
              el._ppHandle = null;
            });
          }).catch(function(e) {
            console.warn('PPAudio.play failed, falling back to native:', e);
            // Unmute and play native as fallback
            try { el.muted = false; el.volume = 1; } catch (_) {}
            return _origPlay();
          });
        }
      }
      // Always call native play() too — keeps the element's state machine
      // moving (timeupdate events, ended events, etc.) — but silently.
      return _origPlay();
    };

    // Watch for pause — also stop PPAudio
    el.addEventListener('pause', function() {
      if (el._ppHandle) {
        try { el._ppHandle.stop(); } catch (_) {}
        el._ppHandle = null;
      }
    });

    // Watch for src changes — clear PPAudio cache entry so a fresh
    // analysis happens if user toggles between files
    let _lastSrc = el.src || el.currentSrc;
    const srcObserver = new MutationObserver(function() {
      const newSrc = el.src || el.currentSrc;
      if (newSrc !== _lastSrc) {
        if (el._ppHandle) {
          try { el._ppHandle.stop(); } catch (_) {}
          el._ppHandle = null;
        }
        _lastSrc = newSrc;
      }
    });
    srcObserver.observe(el, { attributes: true, attributeFilter: ['src'] });
  }

  function installInterceptor() {
    if (interceptorActive) return;
    interceptorActive = true;

    // Hook all existing <audio> elements
    document.querySelectorAll('audio').forEach(hookAudioElement);

    // Watch DOM for new <audio> elements (added by JS later)
    interceptorObserver = new MutationObserver(function(mutations) {
      for (const m of mutations) {
        for (const node of m.addedNodes) {
          if (node.nodeType !== 1) continue;
          if (node.tagName === 'AUDIO') {
            hookAudioElement(node);
          } else if (node.querySelectorAll) {
            node.querySelectorAll('audio').forEach(hookAudioElement);
          }
        }
      }
    });
    interceptorObserver.observe(document.documentElement, {
      childList: true,
      subtree: true
    });

    console.log('PPAudio: universal <audio> interceptor installed.',
      stats.hooked, 'existing element(s) hooked.');
  }

  function uninstallInterceptor() {
    interceptorActive = false;
    if (interceptorObserver) {
      interceptorObserver.disconnect();
      interceptorObserver = null;
    }
    console.log('PPAudio: interceptor disabled. (Hooked elements remain wrapped — reload to fully reset.)');
  }

  function interceptorStats() {
    return Object.assign({}, stats, { active: interceptorActive });
  }

  // Auto-install when DOM is ready
  function autoInstall() {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', installInterceptor);
    } else {
      installInterceptor();
    }
  }
  autoInstall();

  window.PPAudio = {
    play: play,
    stopAll: stopAll,
    setEnabled: setEnabled,
    isEnabled: isEnabled,
    setGainDivisor: setGainDivisor,
    setGainFloor: setGainFloor,
    setGainCeiling: setGainCeiling,
    installInterceptor: installInterceptor,
    uninstallInterceptor: uninstallInterceptor,
    interceptorStats: interceptorStats,
    peak: currentPeak,
    clearCache: clearCache,
    tuning: tuning,
    lastPeak: null,
    _calculateGain: calculateGain
  };
})(window);
