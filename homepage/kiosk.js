/* =========================================================
   BirdNET-Pi Kiosk UI
   Client-side: clock, weather, audio playback, auto-refresh
   ========================================================= */
(function () {
  "use strict";

  var CFG = window.KIOSK || { tab: "overview", lat: null, lon: null, refreshMs: 20000 }; // perchpi:s21a2-g3-v1

  /* ----------------- Clock ----------------- */
  function pad(n) { return n < 10 ? "0" + n : "" + n; }
  function fmtTime(d) {
    var h = d.getHours();
    var m = d.getMinutes();
    var ampm = h >= 12 ? "PM" : "AM";
    var h12 = h % 12; if (h12 === 0) h12 = 12;
    return h12 + ":" + pad(m) + " " + ampm;
  }
  function fmtDate(d) {
    var days = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];
    var months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
    return days[d.getDay()] + " " + months[d.getMonth()] + " " + d.getDate();
  }
  function tickClock() {
    var d = new Date();
    var t = document.getElementById("clock-time");
    var dt = document.getElementById("clock-date");
    if (t)  t.textContent  = fmtTime(d);
    if (dt) dt.textContent = fmtDate(d);
  }
  tickClock();
  setInterval(tickClock, 15000);

  /* ----------------- Weather (Open-Meteo) ----------------- */
  // WMO weather codes -> compact icon + label.
  var WMO = {
    0:  ["☀",  "Clear"],
    1:  ["🌤", "Mostly clear"],
    2:  ["⛅", "Partly cloudy"],
    3:  ["☁",  "Overcast"],
    45: ["🌫", "Fog"],
    48: ["🌫", "Fog"],
    51: ["🌦", "Drizzle"],
    53: ["🌦", "Drizzle"],
    55: ["🌦", "Drizzle"],
    61: ["🌧", "Rain"],
    63: ["🌧", "Rain"],
    65: ["🌧", "Heavy rain"],
    71: ["🌨", "Snow"],
    73: ["🌨", "Snow"],
    75: ["🌨", "Heavy snow"],
    80: ["🌦", "Showers"],
    81: ["🌦", "Showers"],
    82: ["⛈", "Heavy showers"],
    95: ["⛈", "Thunderstorm"],
    96: ["⛈", "Thunderstorm"],
    99: ["⛈", "Thunderstorm"]
  };

  function fetchWeather() {
    if (CFG.lat === null || CFG.lon === null) { // perchpi:s21a2-g3-v1 (G3)
      var noLoc = document.getElementById("weather-meta");
      if (noLoc) noLoc.textContent = "location not configured";
      return;
    }
    // Fahrenheit (US). Includes current conditions + daily min/max.
    var url =
      "https://api.open-meteo.com/v1/forecast" +
      "?latitude="  + encodeURIComponent(CFG.lat) +
      "&longitude=" + encodeURIComponent(CFG.lon) +
      "&current=temperature_2m,weather_code,relative_humidity_2m,apparent_temperature" +
      "&daily=temperature_2m_max,temperature_2m_min" +
      "&temperature_unit=fahrenheit" +
      "&timezone=auto";

    fetch(url, { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.current) return;
        var c = data.current;
        var code = c.weather_code;
        var info = WMO[code] || ["·", ""];
        var temp = Math.round(c.temperature_2m);
        var feels = Math.round(c.apparent_temperature);

        var iconEl = document.getElementById("weather-icon");
        var tempEl = document.getElementById("weather-temp");
        var metaEl = document.getElementById("weather-meta");
        if (iconEl) iconEl.textContent = info[0];
        if (tempEl) tempEl.textContent = temp + "°F";
        if (metaEl) {
          // Standard detail: condition label + feels-like. Keep compact.
          metaEl.textContent = info[1] + " · feels " + feels + "°";
        }
      })
      .catch(function () { /* fail silently on the kiosk */ });
  }
  fetchWeather();
  setInterval(fetchWeather, 15 * 60 * 1000); // every 15 minutes

  /* ----------------- Audio playback ----------------- */
  var audio = document.getElementById("audio-player");
  var currentBtn = null;

  function setButtonPlaying(btn, playing) {
    if (!btn) return;
    if (playing) btn.classList.add("playing");
    else btn.classList.remove("playing");
  }

  function playAudio(src, btn) {
    if (!audio || !src) return;

    // Tapping the same button while playing = stop
    if (currentBtn === btn && !audio.paused) {
      audio.pause();
      setButtonPlaying(btn, false);
      currentBtn = null;
      return;
    }

    // Stop any previous
    if (currentBtn) setButtonPlaying(currentBtn, false);

    audio.src = src;
    var p = audio.play();
    if (p && typeof p.then === "function") {
      p.then(function () {
        setButtonPlaying(btn, true);
        currentBtn = btn;
      }).catch(function () {
        setButtonPlaying(btn, false);
      });
    } else {
      setButtonPlaying(btn, true);
      currentBtn = btn;
    }
  }

  if (audio) {
    audio.addEventListener("ended",  function () { if (currentBtn) setButtonPlaying(currentBtn, false); currentBtn = null; });
    audio.addEventListener("pause",  function () { if (currentBtn) setButtonPlaying(currentBtn, false); });
    audio.addEventListener("error",  function () { if (currentBtn) setButtonPlaying(currentBtn, false); currentBtn = null; });
  }

  /* ----------------- Bird image overlay ----------------- */
  var overlay = document.getElementById("bird-overlay");
  var overlayImg = document.getElementById("bird-overlay-img");
  var overlayName = document.getElementById("bird-overlay-name");
  var overlaySci = document.getElementById("bird-overlay-sci");
  var overlayCredit = document.getElementById("bird-overlay-credit");

  function openBirdOverlay(thumbEl) {
    if (!overlay || !thumbEl) return;
    var img = thumbEl.querySelector("img");
    if (!img) return;
    overlayImg.src = img.src;
    overlayImg.alt = thumbEl.getAttribute("data-common") || "";
    overlayName.textContent = thumbEl.getAttribute("data-common") || "";
    overlaySci.textContent = thumbEl.getAttribute("data-sci") || "";

    var artist = thumbEl.getAttribute("data-photo-artist") || "";
    var license = thumbEl.getAttribute("data-photo-license") || "";
    var source = thumbEl.getAttribute("data-photo-source") || "";
    var credit = "";
    if (artist) credit += "Photo by " + escapeHtml(artist); /* perchpi:s21a-xss-lightbox-kiosk-v1 -- SHIP-WITH #5 */
    if (license) credit += (artist ? " · " : "") + escapeHtml(license);
    if (credit) credit += " · ";
    credit += "via ";
    if (/^https?:\/\//i.test(source)) {
      credit += '<a href="' + escapeAttr(source) + '" target="_blank" rel="noopener">Wikimedia Commons</a>';
    } else {
      credit += "Wikimedia Commons";
    }
    overlayCredit.innerHTML = credit;

    overlay.classList.add("open");
    overlay.setAttribute("aria-hidden", "false");
  }

  function closeBirdOverlay() {
    if (!overlay) return;
    overlay.classList.remove("open");
    overlay.setAttribute("aria-hidden", "true");
    // Clear the image src a moment later to avoid a flash on next open
    setTimeout(function () {
      if (!overlay.classList.contains("open")) overlayImg.src = "";
    }, 200);
  }

  if (overlay) {
    overlay.addEventListener("click", function (e) {
      // Close on backdrop, close button, or any part outside the image itself
      if (e.target.matches(".bird-overlay-img")) return;
      closeBirdOverlay();
    });
    // ESC also closes, in case a keyboard is ever attached
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") closeBirdOverlay();
    });
  }

  // Delegated click — order matters:
  //   1. If user tapped a tappable thumb → open bird overlay, stop
  //   2. If user tapped the play button → play audio
  //   3. If user tapped anywhere else on a detection row → play audio
  document.addEventListener("click", function (e) {
    var thumb = e.target.closest(".thumb-tappable");
    if (thumb && thumb.getAttribute("data-is-bird-photo") === "1") {
      openBirdOverlay(thumb);
      e.preventDefault();
      e.stopPropagation();
      return;
    }

    var btn = e.target.closest(".play-btn");
    if (btn) {
      var host = btn.closest("[data-audio]");
      if (host) {
        playAudio(host.getAttribute("data-audio"), btn);
        e.preventDefault();
        return;
      }
    }
    // Also allow tapping the whole card/row to play
    var row = e.target.closest(".recent-item, .today-item, .latest-card");
    if (row && row.hasAttribute("data-audio") && !e.target.closest("a")) {
      var rowBtn = row.querySelector(".play-btn");
      playAudio(row.getAttribute("data-audio"), rowBtn);
    }
  });

  /* ----------------- Live spectrogram refresh ----------------- */
  // Preview on overview + full on spectrogram tab.
  // Uses double-buffering: preload the next frame off-screen, then swap the
  // visible image's src only after load fires — no blank flash during fetch.
  // Throttled refresh state (patched from perchpi.js pattern).
  var _kioskSpectroState = {
    interval: null,
    inFlight: false,
    lastRefresh: 0,
    REFRESH_MS: 15000,   // matches BirdNET RECORDING_LENGTH; faster wastes bytes
    MIN_GAP_MS: 10000    // hard floor matching update_spectrogram.sh looptime
  };

  function refreshSpectrogram() {
    if (document.hidden) return;
    if (_kioskSpectroState.inFlight) return;
    if (Date.now() - _kioskSpectroState.lastRefresh < _kioskSpectroState.MIN_GAP_MS) return;

    var targets = ["spectro-preview", "spectro-full"]
      .map(function (id) { return document.getElementById(id); })
      .filter(function (img) { return img && img.style.display !== "none"; });

    if (targets.length === 0) return;

    _kioskSpectroState.inFlight = true;
    _kioskSpectroState.lastRefresh = Date.now();
    var url = "/spectrogram.png?t=" + Date.now();
    var next = new Image();
    next.onload = function () {
      targets.forEach(function (img) { img.src = url; });
      _kioskSpectroState.inFlight = false;
    };
    next.onerror = function () { _kioskSpectroState.inFlight = false; };
    next.src = url;
  }

  function _kioskSpectroStart() {
    if (_kioskSpectroState.interval) return;
    _kioskSpectroState.interval = setInterval(refreshSpectrogram, _kioskSpectroState.REFRESH_MS);
  }
  function _kioskSpectroStop() {
    if (_kioskSpectroState.interval) {
      clearInterval(_kioskSpectroState.interval);
      _kioskSpectroState.interval = null;
    }
  }
  // Spectrogram refreshes every REFRESH_MS (15s) on Overview preview and
  // the dedicated Spectrogram tab. Pauses when tab is hidden.
  refreshSpectrogram();        // immediate first frame
  _kioskSpectroStart();
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) {
      _kioskSpectroStop();
    } else {
      if (Date.now() - _kioskSpectroState.lastRefresh >= _kioskSpectroState.MIN_GAP_MS) {
        refreshSpectrogram();
      }
      _kioskSpectroStart();
    }
  });

  /* ----------------- Overview auto-refresh (AJAX) ----------------- */
  // Only the Overview tab updates dynamically. Other tabs will refresh on nav.
  function buildRecentItemHtml(item) {
    var thumb = item.thumb
      ? '<img src="' + item.thumb + '" alt="">'
      : '<div class="thumb-placeholder-sm"></div>';
    var isBird = item.is_bird_photo ? "1" : "0";
    var thumbClass = "recent-thumb" + (item.is_bird_photo ? " thumb-tappable" : "");
    var attrs =
      ' data-is-bird-photo="' + isBird + '"' +
      ' data-common="'        + escapeAttr(item.com_name || "") + '"' +
      ' data-sci="'           + escapeAttr(item.sci_name || "") + '"' +
      ' data-photo-artist="'  + escapeAttr(item.photo_artist || "") + '"' +
      ' data-photo-license="' + escapeAttr(item.photo_license || "") + '"' +
      ' data-photo-source="'  + escapeAttr(item.photo_source || "") + '"';
    return (
      '<div class="recent-item fade-in" data-audio="' + escapeAttr(item.audio) + '">' +
        '<div class="' + thumbClass + '"' + attrs + '>' + thumb + '</div>' +
        '<div class="recent-body">' +
          '<div class="recent-name"><a class="recent-name-link" href="?view=Species%20Profile&species=' + encodeURIComponent(item.sci_name || '') + '&from=Overview">' + escapeHtml(item.com_name) + '</a></div>' + /* perchpi:overview-name-link-ajax-v1 */
          '<div class="recent-sub">' +
            '<span>' + escapeHtml(item.time.substring(0,5)) + '</span>' +
            '<span class="dot">·</span>' +
            '<span>' + item.confidence + '%</span>' +
          '</div>' +
        '</div>' +
        '<button class="play-btn" aria-label="Play">' +
          '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>' +
        '</button>' +
      '</div>'
    );
  }

  function escapeHtml(s) {
    return (s || "").replace(/[&<>"]/g, function (c) {
      return { "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;" }[c];
    });
  }
  function escapeAttr(s) { return escapeHtml(s).replace(/'/g, "&#39;"); }

  function refreshOverview() {
    fetch("kiosk.php?ajax=1", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data) return;

        // Stats
        var s = data.summary || {};
        var el;
        if ((el = document.getElementById("stat-today")))   el.textContent = s.today;
        if ((el = document.getElementById("stat-species"))) el.textContent = s.species;
        if ((el = document.getElementById("stat-hour")))    el.textContent = s.hour;

        // Latest detection card — update in place if species/time changed
        var card = document.getElementById("latest-card");
        if (card && data.latest) {
          var newAudio = data.latest.audio;
          var prevAudio = card.getAttribute("data-audio");
          if (newAudio !== prevAudio) {
            card.setAttribute("data-audio", newAudio);
            card.setAttribute("data-thumb", data.latest.thumb || "");
            var nameEl = document.getElementById("latest-name");
            var sciEl  = document.getElementById("latest-sci");
            var confEl = document.getElementById("latest-conf");
            var timeEl = document.getElementById("latest-time");
            var thumbWrap = document.getElementById("latest-thumb");
            // perchpi:hero-ajaxlink-v1 — wrap name+sci in <a> so AJAX rebuild preserves clickability
            var sciEnc = encodeURIComponent(data.latest.sci_name || "");
            var heroHref = "?view=Species%20Profile&species=" + sciEnc + "&from=Overview";
            function escapeHeroHtml(s) {
              return (s || "").toString().replace(/[&<>"]/g, function(c){
                return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c];
              });
            }
            if (nameEl) nameEl.innerHTML = '<a class="latest-spp-link" href="' + heroHref + '">' + escapeHeroHtml(data.latest.com_name) + '</a>';
            if (sciEl)  sciEl.innerHTML  = '<a class="latest-spp-link" href="' + heroHref + '">' + escapeHeroHtml(data.latest.sci_name) + '</a>';
            if (confEl) confEl.textContent = data.latest.confidence + "%";
            if (timeEl) timeEl.textContent = data.latest.time.substring(0,5);
            if (thumbWrap) {
              thumbWrap.innerHTML = data.latest.thumb
                ? '<img src="' + data.latest.thumb + '" alt="">'
                : '<div class="thumb-placeholder"></div>';
              // Sync tappable state + overlay data attrs for the new detection
              thumbWrap.classList.toggle("thumb-tappable", !!data.latest.is_bird_photo);
              thumbWrap.setAttribute("data-is-bird-photo", data.latest.is_bird_photo ? "1" : "0");
              thumbWrap.setAttribute("data-common",        data.latest.com_name      || "");
              thumbWrap.setAttribute("data-sci",           data.latest.sci_name      || "");
              thumbWrap.setAttribute("data-photo-artist",  data.latest.photo_artist  || "");
              thumbWrap.setAttribute("data-photo-license", data.latest.photo_license || "");
              thumbWrap.setAttribute("data-photo-source",  data.latest.photo_source  || "");
            }
            card.classList.remove("fade-in");
            void card.offsetWidth; // restart animation
            card.classList.add("fade-in");
          }
        }

        // Recent list — DO NOT destroy the DOM while audio is playing
        // from one of its rows (would detach the play button's handle and
        // lose the 'playing' state). Skip the refresh in that case; the
        // next cycle will update once the audio finishes.
        var list = document.getElementById("recent-list");
        var isPlayingFromList = currentBtn && list && list.contains(currentBtn);
        if (list && Array.isArray(data.recent) && !isPlayingFromList) {
          if (data.recent.length === 0) {
            list.innerHTML = '<div class="empty">No detections today.</div>';
          } else {
            list.innerHTML = data.recent.map(buildRecentItemHtml).join("");
          }
        }

        // System status
        if (data.system) {
          var diskEl = document.getElementById("sys-disk");
          if (diskEl && data.system.disk_pct !== null && data.system.disk_pct !== undefined) {
            diskEl.textContent = data.system.disk_pct + "% used";
          }
          var anEl = document.getElementById("sys-analyzer");
          var anDot = document.getElementById("sys-analyzer-dot");
          if (anEl)  anEl.textContent  = data.system.analyzer_ok ? "running" : "stopped";
          if (anDot) {
            anDot.classList.toggle("ok",  !!data.system.analyzer_ok);
            anDot.classList.toggle("err", !data.system.analyzer_ok);
          }

          // Mic input level (0-100) — animates the meter via CSS transition
          var micFill = document.getElementById("sys-mic-fill");
          var micVal  = document.getElementById("sys-mic-value");
          var lvl = data.system.mic_level;
          if (micFill && micVal) {
            if (lvl === null || lvl === undefined) {
              micFill.style.width = "0%";
              micVal.textContent = "—";
            } else {
              micFill.style.width = Math.max(0, Math.min(100, lvl)) + "%";
              micVal.textContent = lvl + "%";
            }
          }
        }
      })
      .catch(function () { /* silent */ });
  }

  if (CFG.tab === "overview") {
    // Fire one refresh almost immediately so server-side values (disk %,
    // analyzer status) settle quickly, then continue at the configured rate.
    setTimeout(refreshOverview, 1500);
    setInterval(refreshOverview, CFG.refreshMs);
  }

  /* ----------------- Initial disk placeholder ----------------- */
  // The real disk % arrives from the AJAX endpoint on first refresh.
  // Until then the server-rendered value is already correct from PHP.
  // (Removed the browser navigator.storage.estimate() fallback — it reports
  // the browser's quota, not the Pi's disk, so it was misleading.)

  /* perchpi:kiosk-live-audio-js-v1 — Kiosk Live Audio toggle */
  (function setupKioskLiveAudio() {
    var tile = document.getElementById("kla-tile");
    var audio = document.getElementById("kla-audio");
    var dot = document.getElementById("kla-dot");
    var val = document.getElementById("kla-val");
    if (!tile || !audio || !dot || !val) return;  // not on overview tab

    var STREAM_URL = "/perchpi-hls/stream.m3u8";
    var hls = null;
    var playing = false;

    function setState(s) {
      dot.className = "sys-dot";
      if (s === "live") { dot.classList.add("live"); val.textContent = "Live"; }
      else if (s === "buffering") { dot.classList.add("buffering"); val.textContent = "Loading…"; }
      else if (s === "error") { dot.classList.add("error"); val.textContent = "Error"; }
      else { val.textContent = "Off"; }
    }

    function teardown() {
      if (hls) { try { hls.destroy(); } catch (e) {} hls = null; }
      audio.removeAttribute("src");
      audio.load();
      playing = false;
    }

    function start() {
      setState("buffering");
      if (window.Hls && Hls.isSupported()) {
        hls = new Hls({ liveSyncDuration: 4, liveMaxLatencyDuration: 10 });
        hls.loadSource(STREAM_URL);
        hls.attachMedia(audio);
        hls.on(Hls.Events.MANIFEST_PARSED, function () {
          audio.play().then(function () { playing = true; setState("live"); })
                      .catch(function () { setState("error"); });
        });
        hls.on(Hls.Events.ERROR, function (e, data) {
          if (data.fatal) { setState("error"); teardown(); }
        });
      } else if (audio.canPlayType("application/vnd.apple.mpegurl") ||
                 audio.canPlayType("audio/x-mpegurl")) {
        audio.src = STREAM_URL;
        audio.play().then(function () { playing = true; setState("live"); })
                    .catch(function () { setState("error"); });
      } else {
        setState("error");
      }
    }

    function stop() { teardown(); setState("off"); }

    function toggle() { if (playing) stop(); else start(); }

    tile.addEventListener("click", toggle);
    tile.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggle(); }
    });

    window.addEventListener("beforeunload", teardown);
  })();

})();