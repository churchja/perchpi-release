/* ============================================================================
   BirdNET-Pi / PerchPi Foundation JavaScript — v2 (kiosk-aligned)
   ============================================================================
   Vanilla JS, ES2020+. Targets modern browsers (Chromium kiosk + macOS Safari).

   v2 changes:
   - Adopted the kiosk's audio playback pattern (single audio element, button
     state syncing, click-anywhere-on-row plays).
   - Adopted the kiosk's bird image overlay (lightbox) pattern.
   - Adopted the double-buffered spectrogram refresh.
   - Adopted the AJAX overview refresh that updates stats/recent/system in
     place without losing playing audio state.

   Public namespace: window.PerchPi
   Auto-initializes on DOMContentLoaded.

   Build: Phase C-2.2 (Kiosk-aligned)
   ============================================================================ */

(function() {
  'use strict';

  window.PerchPi = window.PerchPi || {};

  /* ======================================================================
     SIDEBAR — open/close/swipe behavior (unchanged from C-2.1)
     ====================================================================== */
  const Sidebar = {
    sidebar: null,
    backdrop: null,
    toggle: null,
    isOpen: false,
    isDesktop: false,

    init() {
      this.sidebar  = document.querySelector('.app-sidebar');
      this.backdrop = document.querySelector('.sidebar-backdrop');
      this.toggle   = document.querySelector('.sidebar-toggle');
      if (!this.sidebar) return;

      this.checkBreakpoint();
      window.addEventListener('resize', () => this.checkBreakpoint());

      if (this.toggle) {
        this.toggle.addEventListener('click', (e) => {
          e.preventDefault();
          this.open();
        });
      }
      if (this.backdrop) {
        this.backdrop.addEventListener('click', () => this.close());
      }
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.isOpen) this.close();
      });

      this._initSwipeGestures();

      this.sidebar.addEventListener('click', (e) => {
        const item = e.target.closest('.nav-item');
        if (!item) return;
        if (item.parentElement.classList.contains('nav-group') &&
            !item.parentElement.classList.contains('nav-leaf')) {
          if (e.target.closest('.nav-item-toggle') || !item.dataset.url) {
            e.preventDefault();
            item.parentElement.classList.toggle('is-open');
            return;
          }
        }
        if (!this.isDesktop) this.close();
      });
    },

    checkBreakpoint() {
      this.isDesktop = window.innerWidth >= 1025;
      if (this.isDesktop) {
        this.sidebar.classList.remove('is-open');
        if (this.backdrop) this.backdrop.classList.remove('is-visible');
        this.isOpen = false;
      }
    },

    open() {
      if (this.isDesktop || !this.sidebar) return;
      this.sidebar.classList.add('is-open');
      if (this.backdrop) this.backdrop.classList.add('is-visible');
      this.isOpen = true;
      setTimeout(() => {
        const firstFocusable = this.sidebar.querySelector('a, button');
        if (firstFocusable) firstFocusable.focus();
      }, 100);
    },

    close() {
      if (this.isDesktop || !this.sidebar) return;
      this.sidebar.classList.remove('is-open');
      if (this.backdrop) this.backdrop.classList.remove('is-visible');
      this.isOpen = false;
    },

    _initSwipeGestures() {
      let edgeStartX = null, edgeStartY = null, edgeStartTime = null;
      const EDGE_THRESHOLD = 24;
      const SWIPE_THRESHOLD = 60;
      const SWIPE_TIME_MAX = 600;

      document.addEventListener('touchstart', (e) => {
        if (this.isDesktop) return;
        const t = e.touches[0];
        if (t.clientX <= EDGE_THRESHOLD && !this.isOpen) {
          edgeStartX = t.clientX;
          edgeStartY = t.clientY;
          edgeStartTime = Date.now();
        }
      }, { passive: true });

      document.addEventListener('touchend', (e) => {
        if (this.isDesktop) return;
        if (edgeStartX === null) return;
        const t = e.changedTouches[0];
        const dx = t.clientX - edgeStartX;
        const dy = Math.abs(t.clientY - edgeStartY);
        const dt = Date.now() - edgeStartTime;
        if (dx > SWIPE_THRESHOLD && dy < 80 && dt < SWIPE_TIME_MAX) {
          this.open();
        }
        edgeStartX = null; edgeStartY = null; edgeStartTime = null;
      }, { passive: true });

      let sbStartX = null, sbStartY = null, sbStartTime = null;
      this.sidebar.addEventListener('touchstart', (e) => {
        if (this.isDesktop || !this.isOpen) return;
        const t = e.touches[0];
        sbStartX = t.clientX; sbStartY = t.clientY; sbStartTime = Date.now();
      }, { passive: true });

      this.sidebar.addEventListener('touchend', (e) => {
        if (this.isDesktop || !this.isOpen) return;
        if (sbStartX === null) return;
        const t = e.changedTouches[0];
        const dx = t.clientX - sbStartX;
        const dy = Math.abs(t.clientY - sbStartY);
        const dt = Date.now() - sbStartTime;
        if (dx < -SWIPE_THRESHOLD && dy < 80 && dt < SWIPE_TIME_MAX) {
          this.close();
        }
        sbStartX = null; sbStartY = null; sbStartTime = null;
      }, { passive: true });
    }
  };

  /* ======================================================================
     DROPDOWNS (unchanged from C-2.1)
     ====================================================================== */
  const Dropdown = {
    init() {
      document.querySelectorAll('[data-dropdown-trigger]').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          this.toggle(trigger);
        });
      });
      document.addEventListener('click', (e) => {
        document.querySelectorAll('.dropdown.is-open').forEach(dropdown => {
          if (!dropdown.contains(e.target)) {
            dropdown.classList.remove('is-open');
          }
        });
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          document.querySelectorAll('.dropdown.is-open').forEach(d => {
            d.classList.remove('is-open');
          });
        }
      });
    },
    toggle(trigger) {
      const dropdown = trigger.closest('.dropdown');
      if (!dropdown) return;
      document.querySelectorAll('.dropdown.is-open').forEach(d => {
        if (d !== dropdown) d.classList.remove('is-open');
      });
      dropdown.classList.toggle('is-open');
    }
  };

  /* ======================================================================
     CLOCK (unchanged)
     ====================================================================== */
  const Clock = {
    elements: [],
    _interval: null,

    init() {
      this.elements = document.querySelectorAll('[data-clock]');
      if (this.elements.length === 0) return;
      this.update();
      clearInterval(this._interval);
      this._interval = setInterval(() => this.update(), 60_000);
    },

    update() {
      const now = new Date();
      const time = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      const date = now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
      this.elements.forEach(el => {
        const format = el.dataset.clock;
        if      (format === 'time') el.textContent = time;
        else if (format === 'date') el.textContent = date;
        else                        el.textContent = `${date} · ${time}`;
      });
    }
  };

  /* ======================================================================
     ACTIVE NAV HIGHLIGHTING (unchanged)
     ====================================================================== */
  const Nav = {
    init() {
      const params = new URLSearchParams(window.location.search);
      const currentView = params.get('view') || 'Overview';

      document.querySelectorAll('[data-nav-view]').forEach(item => {
        if (item.dataset.navView === currentView) {
          item.classList.add('is-active');
          let parent = item.closest('.nav-group');
          while (parent) {
            parent.classList.add('is-open');
            parent = parent.parentElement.closest('.nav-group');
          }
        }
      });
    }
  };

  /* ======================================================================
     AUDIO PLAYBACK
     ----------------------------------------------------------------------
     Single shared <audio id="audio-player"> element. Click any .play-btn
     or any row with a data-audio attribute to play. Clicking a playing
     button stops it. Adapted from kiosk.js.
     ====================================================================== */
  const Audio = {
    audio: null,
    currentBtn: null,

    init() {
      // perchpi:dock-scrubber-wireup-v1 — prefer pp_scrubber dock, fall back to <audio> for legacy pages
      this.dock = document.getElementById('pp-dock-scrubber');
      this.audio = document.getElementById('audio-player');
      if (!this.dock && !this.audio) {
        // Legacy fallback for pages with neither
        this.audio = document.createElement('audio');
        this.audio.id = 'audio-player';
        this.audio.preload = 'none';
        document.body.appendChild(this.audio);
      }

      // <audio> listeners only attach if we're using the legacy element
      if (this.audio) {
        this.audio.addEventListener('ended', () => this._onStop());
        this.audio.addEventListener('pause', () => {
          if (this.currentBtn) this._setButtonPlaying(this.currentBtn, false);
        });
        this.audio.addEventListener('error', () => this._onStop());
      }

      // perchpi:dock-dismiss-on-end-v1 — when dock audio ends naturally, slide it down + clear button state
      if (this.dock) {
        this.dock.addEventListener('pp-scrubber-ended', () => {
          this._onStop();
          this.dock.classList.remove('pp-scrubber-ready');
        });
      }

      // Delegated click handler — runs alongside the bird overlay handler
      // (the overlay handler stops propagation and runs first if a tappable
      // bird thumb was clicked; otherwise we fall through to here).
      document.addEventListener('click', (e) => {
        // Don't fire on overlay close, sidebar nav, etc.
        if (e.target.closest('.bird-overlay')) return;
        if (e.target.closest('.app-sidebar')) return;
        if (e.target.closest('.app-header')) return;
        if (e.target.closest('a[href]')) return;

        // Direct play button click
        const btn = e.target.closest('.play-btn, .play-btn-lg, .play-btn-md');
        if (btn) {
          const host = btn.closest('[data-audio]');
          if (host) {
            // perchpi:dock-scrubber-wireup-v1 — pass spectrogram URL too
            this.play(host.getAttribute('data-audio'), btn, host.getAttribute('data-spectrogram'));
            e.preventDefault();
            return;
          }
        }

        // perchpi:row-anchor-passthrough-v1 — if click is on an anchor inside a
        // row, let the browser navigate; don't also start audio playback.
        if (e.target.closest('a')) return;
        // Whole-row click (recent-item, today-item, latest-card)
        const row = e.target.closest('.recent-item, .today-item, .latest-card');
        if (row && row.hasAttribute('data-audio')) {
          const rowBtn = row.querySelector('.play-btn, .play-btn-lg, .play-btn-md');
          // perchpi:dock-scrubber-wireup-v1 — pass spectrogram URL too
          this.play(row.getAttribute('data-audio'), rowBtn, row.getAttribute('data-spectrogram'));
        }
      });
    },

    play(src, btn, spec) {
      // perchpi:dock-scrubber-wireup-v1 — accepts optional spectrogram URL; routes to dock if present
      if (!src) return;

      // Tapping the same button while playing = stop
      if (this.currentBtn === btn && this._isPlaying()) {
        this._pauseAll();
        this._setButtonPlaying(btn, false);
        this.currentBtn = null;
        // perchpi:dock-dismiss-on-stop-v1 — slide dock back down on explicit stop
        if (this.dock) this.dock.classList.remove('pp-scrubber-ready');
        return;
      }

      if (this.currentBtn) this._setButtonPlaying(this.currentBtn, false);

      if (this.dock && typeof window.pp_scrubber_load === 'function') {
        // Route through docked scrubber
        window.pp_scrubber_load(this.dock, src, spec || '');
        // perchpi:audio-normalize-bridge-v1 — route audible playback through PPAudio for
        // 95th-percentile RMS gain normalization. The scrubber's internal
        // <audio> becomes a silent timing clock; PPAudio provides the sound.
        // Falls back to native (unmuted) audio on any failure.
        if (typeof window.PPAudio === 'object' && window.PPAudio &&
            typeof window.PPAudio.isEnabled === 'function' && window.PPAudio.isEnabled()) {
          var self_pp = this;
          if (self_pp._ppHandle) { try { self_pp._ppHandle.stop(); } catch (_) {} self_pp._ppHandle = null; }
          // Mute the scrubber's <audio> on next tick (after pp_scrubber_load builds it)
          setTimeout(function () {
            var a = self_pp.dock && self_pp.dock.querySelector('audio');
            if (a) a.muted = true;
          }, 50);
          window.PPAudio.play(src).then(function (h) {
            self_pp._ppHandle = h;
          }).catch(function (e) {
            console.warn('PPAudio.play failed, falling back to native:', e);
            var a = self_pp.dock && self_pp.dock.querySelector('audio');
            if (a) a.muted = false;
          });
        }
        // Treat play attempt as success (scrubber handles user-gesture rules itself)
        this._setButtonPlaying(btn, true);
        this.currentBtn = btn;
      } else if (this.audio) {
        // Legacy fallback
        this.audio.src = src;
        const p = this.audio.play();
        if (p && typeof p.then === 'function') {
          p.then(() => {
            this._setButtonPlaying(btn, true);
            this.currentBtn = btn;
          }).catch(() => {
            this._setButtonPlaying(btn, false);
          });
        } else {
          this._setButtonPlaying(btn, true);
          this.currentBtn = btn;
        }
      }
    },

    _isPlaying() {
      // perchpi:dock-scrubber-wireup-v1
      if (this.dock) {
        var dockAudio = this.dock.querySelector('audio');
        return !!(dockAudio && !dockAudio.paused);
      }
      return !!(this.audio && !this.audio.paused);
    },

    _pauseAll() {
      // perchpi:dock-scrubber-wireup-v1
      if (this.dock && typeof this.dock.ppPause === 'function') this.dock.ppPause();
      if (this.audio && !this.audio.paused) this.audio.pause();
      // perchpi:audio-normalize-bridge-v1 — release PPAudio handle alongside legacy <audio>
      if (this._ppHandle) { try { this._ppHandle.stop(); } catch (_) {} this._ppHandle = null; }
    },

    stop() {
      if (this.audio && !this.audio.paused) this.audio.pause();
      if (this.currentBtn) this._setButtonPlaying(this.currentBtn, false);
      this.currentBtn = null;
    },

    _setButtonPlaying(btn, playing) {
      if (!btn) return;
      if (playing) btn.classList.add('playing');
      else btn.classList.remove('playing');
    },

    _onStop() {
      if (this.currentBtn) this._setButtonPlaying(this.currentBtn, false);
      this.currentBtn = null;
    }
  };

  /* ======================================================================
     BIRD IMAGE OVERLAY (lightbox)
     ----------------------------------------------------------------------
     Tapping a .thumb-tappable element with data-is-bird-photo="1" opens
     the lightbox. Adapted from kiosk.js.
     ====================================================================== */
  const BirdOverlay = {
    overlay: null,
    img: null,
    name: null,
    sci: null,
    credit: null,

    init() {
      this.overlay = document.getElementById('bird-overlay');
      this.img    = document.getElementById('bird-overlay-img');
      this.name   = document.getElementById('bird-overlay-name');
      this.sci    = document.getElementById('bird-overlay-sci');
      this.credit = document.getElementById('bird-overlay-credit');

      if (!this.overlay) return;

      this.overlay.addEventListener('click', (e) => {
        if (e.target.matches('.bird-overlay-img')) return;
        this.close();
      });

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') this.close();
      });

      // Delegated tap handler — runs BEFORE Audio's handler. If a bird
      // photo thumb is tapped, open overlay and stop propagation.
      document.addEventListener('click', (e) => {
        const thumb = e.target.closest('.thumb-tappable');
        if (thumb && thumb.getAttribute('data-is-bird-photo') === '1') {
          this.open(thumb);
          e.preventDefault();
          e.stopPropagation();
        }
      }, true);
    },

    open(thumbEl) {
      if (!this.overlay || !thumbEl) return;
      const img = thumbEl.querySelector('img');
      if (!img) return;

      this.img.src = img.src;
      this.img.alt = thumbEl.getAttribute('data-common') || '';
      this.name.textContent = thumbEl.getAttribute('data-common') || '';
      this.sci.textContent  = thumbEl.getAttribute('data-sci')    || '';

      const artist  = thumbEl.getAttribute('data-photo-artist')  || '';
      const license = thumbEl.getAttribute('data-photo-license') || '';
      const source  = thumbEl.getAttribute('data-photo-source')  || '';
      let credit = '';
      if (artist) credit += 'Photo by ' + this._escapeHtml(artist);
      if (license) credit += (artist ? ' · ' : '') + this._escapeHtml(license);
      if (credit) credit += ' · ';
      credit += 'via ';
      if (/^https?:\/\//i.test(source)) { /* perchpi:s21a-xss-lightbox-birdoverlay-v1 -- http(s) only */
        credit += '<a href="' + this._escapeAttr(source) + '" target="_blank" rel="noopener">Wikimedia Commons</a>';
      } else {
        credit += 'Wikimedia Commons';
      }
      this.credit.innerHTML = credit;

      this.overlay.classList.add('open');
      this.overlay.setAttribute('aria-hidden', 'false');
    },

    close() {
      if (!this.overlay) return;
      this.overlay.classList.remove('open');
      this.overlay.setAttribute('aria-hidden', 'true');
      setTimeout(() => {
        if (!this.overlay.classList.contains('open')) this.img.src = '';
      }, 200);
    },

    _escapeHtml(s) {
      return (s || '').replace(/[&<>"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]));
    },
    _escapeAttr(s) { return this._escapeHtml(s).replace(/'/g, '&#39;'); }
  };

  /* ======================================================================
     SPECTROGRAM REFRESH
     ----------------------------------------------------------------------
     Double-buffered: preload the next frame off-screen, swap visible img's
     src only after load fires — no blank flash. Refreshes any element with
     id ending in "spectro-preview" or "spectro-full" or marked with
     data-spectrogram-refresh.
     ====================================================================== */
const Spectrogram = {
    _interval: null,
    _inFlight: false,
    _lastRefresh: 0,
    REFRESH_MS: 15000,   // matches BirdNET's RECORDING_LENGTH; faster wastes bytes
    MIN_GAP_MS: 10000,   // hard floor matching update_spectrogram.sh looptime
    init() {
      const ids = ['spectro-preview', 'spectro-full'];
      const hasAny = ids.some(id => document.getElementById(id)) ||
                     document.querySelector('[data-spectrogram-refresh]');
      if (!hasAny) return;

      this.refresh();
      this._start();

      // Pause when tab hidden; resume + immediate refresh when visible again.
      document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
          this._stop();
        } else {
          // If we've been gone longer than MIN_GAP_MS, refresh immediately.
          if (Date.now() - this._lastRefresh >= this.MIN_GAP_MS) {
            this.refresh();
          }
          this._start();
        }
      });
    },
    _start() {
      if (this._interval) return;
      this._interval = setInterval(() => this.refresh(), this.REFRESH_MS);
    },
    _stop() {
      if (this._interval) {
        clearInterval(this._interval);
        this._interval = null;
      }
    },
    refresh() {
      if (document.hidden) return;          // belt-and-suspenders
      if (this._inFlight) return;           // skip if previous load not done
      if (Date.now() - this._lastRefresh < this.MIN_GAP_MS) return;

      const targets = [
        document.getElementById('spectro-preview'),
        document.getElementById('spectro-full'),
        ...document.querySelectorAll('[data-spectrogram-refresh]')
      ].filter(Boolean).filter(img => img.style.display !== 'none');

      if (targets.length === 0) return;

      this._inFlight = true;
      this._lastRefresh = Date.now();
      const url = '/spectrogram.png?t=' + Date.now();
      const next = new Image();
      next.onload = () => {
        targets.forEach(img => { img.src = url; });
        this._inFlight = false;
      };
      next.onerror = () => { this._inFlight = false; };
      next.src = url;
    }
  };

  /* ======================================================================
     OVERVIEW AJAX REFRESH
     ----------------------------------------------------------------------
     Hits kiosk.php?ajax=1 for live data. Updates stats, latest detection
     card, recent list, and system status without losing playing audio
     state. Adapted from kiosk.js.
     ====================================================================== */
  const OverviewRefresh = {
    _interval: null,

    init() {
      // Only run on Overview page (where the elements actually exist)
      if (!document.getElementById('latest-card') &&
          !document.getElementById('stat-today')) return;

      // Initial fast refresh, then steady cadence
      setTimeout(() => this.refresh(), 1500);
      this._interval = setInterval(() => this.refresh(), 20000);

      document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
          clearInterval(this._interval);
          this._interval = null;
        } else if (!this._interval) {
          this.refresh();
          this._interval = setInterval(() => this.refresh(), 20000);
        }
      });
    },

    refresh() {
      fetch('kiosk.php?ajax=1', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => this._apply(data))
        .catch(() => { /* silent on transient failures */ });
    },

    _apply(data) {
      if (!data) return;

      // Stats
      const s = data.summary || {};
      this._setText('stat-today',   s.today);
      this._setText('stat-species', s.species);
      this._setText('stat-hour',    s.hour);
      this._setText('stat-total',   s.total);

      // Latest detection card
      const card = document.getElementById('latest-card');
      if (card && data.latest) {
        const newAudio = data.latest.audio;
        const prevAudio = card.getAttribute('data-audio');
        if (newAudio !== prevAudio) {
          card.setAttribute('data-audio', newAudio);
          // perchpi:latest-spectrogram-ajax-v1 — data-spectrogram for dock scrubber
          card.setAttribute('data-spectrogram', data.latest.spectrogram || '');
          card.setAttribute('data-thumb', data.latest.thumb || '');
          // perchpi:phase2b-pills — latest-name uses innerHTML to include pill
          // perchpi:hero-ajaxlink-v1 — wrap name+sci in <a> so AJAX rebuild preserves clickability
          (function(){
            const sciEnc = encodeURIComponent(data.latest.sci_name || '');
            const heroHref = '?view=Species%20Profile&species=' + sciEnc + '&from=Overview';
            const nameEl = document.getElementById('latest-name');
            if (nameEl) {
              nameEl.innerHTML = '<a class="latest-spp-link" href="' + heroHref + '">' +
                this._escapeHtml(data.latest.com_name) + '</a>' + (data.latest.pill_html || '');
            }
            const sciEl = document.getElementById('latest-sci');
            if (sciEl) {
              sciEl.innerHTML = '<a class="latest-spp-link" href="' + heroHref + '">' +
                this._escapeHtml(data.latest.sci_name) + '</a>';
            }
          }).call(this);
          this._setText('latest-conf', data.latest.confidence + '%');
          this._setText('latest-time', (data.latest.time || '').substring(0, 5));

          const thumbWrap = document.getElementById('latest-thumb');
          if (thumbWrap) {
            thumbWrap.innerHTML = data.latest.thumb
              ? '<img src="' + this._escapeAttr(data.latest.thumb) + '" alt="">'
              : '<div class="thumb-placeholder"></div>';
            thumbWrap.classList.toggle('thumb-tappable', !!data.latest.is_bird_photo);
            thumbWrap.setAttribute('data-is-bird-photo', data.latest.is_bird_photo ? '1' : '0');
            thumbWrap.setAttribute('data-common',        data.latest.com_name      || '');
            thumbWrap.setAttribute('data-sci',           data.latest.sci_name      || '');
            thumbWrap.setAttribute('data-photo-artist',  data.latest.photo_artist  || '');
            thumbWrap.setAttribute('data-photo-license', data.latest.photo_license || '');
            thumbWrap.setAttribute('data-photo-source',  data.latest.photo_source  || '');
          }
          card.classList.remove('fade-in');
          void card.offsetWidth;
          card.classList.add('fade-in');
        }
      }

      // Recent list — don't destroy DOM if audio is currently playing from it
      const list = document.getElementById('recent-list');
      const playingFromList = Audio.currentBtn && list && list.contains(Audio.currentBtn);
      if (list && Array.isArray(data.recent) && !playingFromList) {
        if (data.recent.length === 0) {
          list.innerHTML = '<div class="empty">No detections today.</div>';
        } else {
          list.innerHTML = data.recent.map(r => this._buildRecentItem(r)).join('');
        }
      }

      // System status
      if (data.system) {
        const diskEl = document.getElementById('sys-disk');
        if (diskEl && data.system.disk_pct !== null && data.system.disk_pct !== undefined) {
          diskEl.textContent = data.system.disk_pct + '% used';
        }
        const anEl  = document.getElementById('sys-analyzer');
        const anDot = document.getElementById('sys-analyzer-dot');
        if (anEl)  anEl.textContent = data.system.analyzer_ok ? 'running' : 'stopped';
        if (anDot) {
          anDot.classList.toggle('ok',  !!data.system.analyzer_ok);
          anDot.classList.toggle('err', !data.system.analyzer_ok);
        }

        const micFill = document.getElementById('sys-mic-fill');
        const micVal  = document.getElementById('sys-mic-value');
        const lvl = data.system.mic_level;
        if (micFill && micVal) {
          if (lvl === null || lvl === undefined) {
            micFill.style.width = '0%';
            micVal.textContent = '—';
          } else {
            micFill.style.width = Math.max(0, Math.min(100, lvl)) + '%';
            micVal.textContent = lvl + '%';
          }
        }
      }
    },

    _setText(id, text) {
      const el = document.getElementById(id);
      if (el && text !== undefined && text !== null) el.textContent = text;
    },

    _buildRecentItem(item) {
      const thumb = item.thumb
        ? '<img src="' + this._escapeAttr(item.thumb) + '" alt="">'
        : '<div class="thumb-placeholder-sm"></div>';
      const isBird = item.is_bird_photo ? '1' : '0';
      const thumbClass = 'recent-thumb' + (item.is_bird_photo ? ' thumb-tappable' : '');
      const attrs =
        ' data-is-bird-photo="' + isBird + '"' +
        ' data-common="'        + this._escapeAttr(item.com_name      || '') + '"' +
        ' data-sci="'           + this._escapeAttr(item.sci_name      || '') + '"' +
        ' data-photo-artist="'  + this._escapeAttr(item.photo_artist  || '') + '"' +
        ' data-photo-license="' + this._escapeAttr(item.photo_license || '') + '"' +
        ' data-photo-source="'  + this._escapeAttr(item.photo_source  || '') + '"';
      return (
        // perchpi:dock-scrubber-wireup-v1 — include data-spectrogram for AJAX rebuilds
        '<div class="recent-item fade-in" data-audio="' + this._escapeAttr(item.audio) + '" data-spectrogram="' + this._escapeAttr(item.spectrogram || '') + '">' +
          '<div class="' + thumbClass + '"' + attrs + '>' + thumb + '</div>' +
          '<div class="recent-body">' +
            '<div class="recent-name"><a class="recent-name-link" href="?view=Species%20Profile&species=' + encodeURIComponent(item.sci_name || '') + '&from=Overview">' + this._escapeHtml(item.com_name) + '</a>' + (item.pill_html || '') + '</div>' + /* perchpi:overview-name-link-ajax-v1 */
            '<div class="recent-sub">' +
              '<span>' + this._escapeHtml((item.time || '').substring(0,5)) + '</span>' +
              '<span class="dot">·</span>' +
              '<span>' + item.confidence + '%</span>' +
            '</div>' +
          '</div>' +
          '<button class="play-btn" aria-label="Play">' +
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>' +
          '</button>' +
        '</div>'
      );
    },

    _escapeHtml(s) {
      return (s || '').toString().replace(/[&<>"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]));
    },
    _escapeAttr(s) { return this._escapeHtml(s).replace(/'/g, '&#39;'); }
  };

  /* ======================================================================
     TOAST NOTIFICATIONS (kept from C-2.1)
     ====================================================================== */
  const Toast = {
    container: null,
    _dismissTimer: null,

    ensureContainer() {
      if (!this.container) {
        this.container = document.createElement('div');
        this.container.className = 'toast';
        this.container.setAttribute('role', 'status');
        this.container.setAttribute('aria-live', 'polite');
        document.body.appendChild(this.container);
      }
      return this.container;
    },

    show(message, options = {}) {
      const { duration = 4000, type = 'info' } = options;
      const container = this.ensureContainer();
      container.textContent = message;
      container.className = 'toast is-visible toast--' + type;
      clearTimeout(this._dismissTimer);
      this._dismissTimer = setTimeout(() => {
        container.classList.remove('is-visible');
      }, duration);
    },
    success(msg, opts) { this.show(msg, { ...opts, type: 'success' }); },
    error(msg, opts)   { this.show(msg, { ...opts, type: 'error' }); },
    warn(msg, opts)    { this.show(msg, { ...opts, type: 'warn' }); }
  };

  /* ======================================================================
     FORMAT UTILITIES
     ====================================================================== */
  const Format = {
    number(n) { return Number(n).toLocaleString(); },
    percent(decimal) { return `${Math.round(decimal * 100)}%`; },
    relativeTime(isoString) {
      const then = new Date(isoString);
      const diffSec = Math.floor((Date.now() - then) / 1000);
      if (diffSec < 60)    return 'just now';
      if (diffSec < 3600)  return `${Math.floor(diffSec / 60)}m ago`;
      if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
      return `${Math.floor(diffSec / 86400)}d ago`;
    },
    confidenceLevel(conf) {
      if (conf >= 0.8) return 'high';
      if (conf >= 0.5) return 'medium';
      return 'low';
    }
  };

  /* ======================================================================
     EXPORTS + AUTO-INIT
     ====================================================================== */
  window.PerchPi.Sidebar         = Sidebar;
  window.PerchPi.Dropdown        = Dropdown;
  window.PerchPi.Toast           = Toast;
  window.PerchPi.Clock           = Clock;
  window.PerchPi.Nav             = Nav;
  window.PerchPi.Audio           = Audio;
  window.PerchPi.BirdOverlay     = BirdOverlay;
  window.PerchPi.Spectrogram     = Spectrogram;
  window.PerchPi.OverviewRefresh = OverviewRefresh;
  window.PerchPi.Format          = Format;

  function initAll() {
    Sidebar.init();
    Dropdown.init();
    Clock.init();
    Nav.init();
    Audio.init();
    BirdOverlay.init();
    Spectrogram.init();
    OverviewRefresh.init();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
/* ============================================================================
   Phase C-2.2.1 additions — append to perchpi.js
   ============================================================================
   Adds Weather module that fetches Open-Meteo and updates the header weather
   widget. Pulls lat/lon from window.PERCHPI (set by index.php).

   Adapted from kiosk.js — same WMO code mapping, same fetch cadence.
   ============================================================================ */

(function() {
  'use strict';

  // WMO weather codes -> compact icon + label
  const WMO = {
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

  const Weather = {
    _interval: null,

    init() {
      const widget = document.getElementById('header-weather');
      if (!widget) return;
      if (!window.PERCHPI || !window.PERCHPI.lat || !window.PERCHPI.lon) {
        // No coords configured — hide widget instead of showing placeholders forever
        widget.style.display = 'none';
        return;
      }

      this.fetch();
      // Open-Meteo updates ~every 15min — refresh at that cadence
      this._interval = setInterval(() => this.fetch(), 15 * 60 * 1000);
    },

    fetch() {
      // perchpi:wu-weather-js-v1 — source-aware: pws hybrid (WU numbers + Open-Meteo icon) or Open-Meteo only
      const src = (window.PERCHPI && window.PERCHPI.weatherSource) || "open-meteo";
      if (src === "pws") { this._fetchPws(); } else { this._fetchOpenMeteo(); }
    },
    _openMeteoUrl() {
      return "https://api.open-meteo.com/v1/forecast" +
        "?latitude="  + encodeURIComponent(window.PERCHPI.lat) +
        "&longitude=" + encodeURIComponent(window.PERCHPI.lon) +
        "&current=temperature_2m,weather_code,relative_humidity_2m,apparent_temperature" +
        "&temperature_unit=fahrenheit&timezone=auto";
    },
    _fetchOpenMeteo() {
      fetch(this._openMeteoUrl(), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
          if (!data || !data.current) return;
          const c = data.current;
          const info = WMO[c.weather_code] || ["·", ""];
          const temp = Math.round(c.temperature_2m);
          const feels = Math.round(c.apparent_temperature);
          this._paint(info[0], temp + "°F", info[1] + " · feels " + feels + "°");
        })
        .catch(() => {});
    },
    _fetchPws() {
      fetch("/perchpi-weather/current.json", { cache: 'no-store' })
        .then(r => r.json())
        .then(w => {
          if (!w || w.status !== "ok" || typeof w.temp !== "number") { this._fetchOpenMeteo(); return; }
          this._paint("·", Math.round(w.temp) + "°F", this._pwsMeta(w));
          fetch(this._openMeteoUrl(), { cache: 'no-store' })
            .then(r => r.json())
            .then(data => {
              if (data && data.current) {
                const info = WMO[data.current.weather_code] || ["·", ""];
                const iconEl = document.getElementById("weather-icon");
                if (iconEl) iconEl.textContent = info[0];
              }
            })
            .catch(() => {});
        })
        .catch(() => { this._fetchOpenMeteo(); });
    },
    _pwsMeta(w) {
      const parts = [];
      const feels = (typeof w.heatIndex === "number") ? w.heatIndex
                  : (typeof w.windChill === "number") ? w.windChill : null;
      if (feels !== null)                    parts.push("feels " + Math.round(feels) + "°");
      if (typeof w.humidity === "number")    parts.push(w.humidity + "%");
      if (typeof w.precipTotal === "number") parts.push(w.precipTotal.toFixed(2) + '"');
      if (typeof w.pressure === "number")    parts.push(w.pressure.toFixed(2) + '"Hg');
      if (typeof w.windSpeed === "number") {
        if (w.windSpeed === 0) { parts.push("calm"); }
        else { const d = this._compass(w.winddir); parts.push((d ? d + " " : "") + Math.round(w.windSpeed) + "mph"); }
      }
      return parts.join(" · ");
    },
    _compass(deg) {
      if (typeof deg !== "number") return "";
      const dirs = ["N","NNE","NE","ENE","E","ESE","SE","SSE","S","SSW","SW","WSW","W","WNW","NW","NNW"];
      return dirs[Math.round(deg / 22.5) % 16];
    },
    _paint(icon, temp, meta) {
      const iconEl = document.getElementById("weather-icon");
      const tempEl = document.getElementById("weather-temp");
      const metaEl = document.getElementById("weather-meta");
      if (iconEl) iconEl.textContent = icon;
      if (tempEl) tempEl.textContent = temp;
      if (metaEl) metaEl.textContent = meta;
    }
  };

  // Register with PerchPi namespace and auto-init
  if (window.PerchPi) {
    window.PerchPi.Weather = Weather;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Weather.init());
  } else {
    Weather.init();
  }
})();
/* ============================================================================
   Phase C-2.2.3 additions — append to perchpi.js
   ============================================================================
   - Stat tile clickability on Overview (Today / Species / Last Hour)
   - Recent Detections collapsing (consecutive same-species → +N badge)
   - Species row click delegation (photo / body / play button)
   - Swipe-back gesture (left-edge swipe = back instead of sidebar) on
     pages with .pp-back-nav present
   - Bucket toggle (collapse/expand) on Species Detail page
   - Smart-collapse defaults for the largest bucket on touch
   ============================================================================ */

(function() {
  'use strict';

  /* ======================================================================
     STAT TILE CLICKABILITY (Overview only)
     ----------------------------------------------------------------------
     The kiosk's <div class="stat"> tiles aren't natively links. On the
     embedded admin Overview, wrap them so they navigate to the appropriate
     list page on click. Skip this on the standalone kiosk (which has no
     admin shell).
     ====================================================================== */
  function initStatTileLinks() {
    // Only run on the admin Overview (recognized by app shell presence)
    if (!document.querySelector('.app-main')) return;
    if (document.body.dataset.tab) return; // dataset.tab present = standalone kiosk

    const tileMap = {
      'stat-today':   { view: "Today's Detections", label: 'Today' },
      'stat-species': { view: 'Birds Today',         label: 'Species' },
      'stat-hour':    { view: 'Last Hour',           label: 'Last Hour' },
    };

    Object.entries(tileMap).forEach(([id, config]) => {
      const valueEl = document.getElementById(id);
      if (!valueEl) return;
      const tile = valueEl.closest('.stat');
      if (!tile) return;
      // Make the entire tile a link
      tile.classList.add('pp-stat-clickable');
      tile.setAttribute('role', 'link');
      tile.setAttribute('tabindex', '0');
      tile.setAttribute('aria-label', config.label + ' — open list');
      tile.style.cursor = 'pointer';

      const navigate = () => {
        window.location.href = '?view=' + encodeURIComponent(config.view);
      };
      tile.addEventListener('click', navigate);
      tile.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          navigate();
        }
      });
    });
  }

  /* ======================================================================
     RECENT DETECTIONS COLLAPSING
     ----------------------------------------------------------------------
     Walk the recent-list in DOM order (newest first). Group consecutive
     same-species rows into a single visible row with a "+N" badge. The
     collapsed row, when clicked, expands inline below it to reveal the
     hidden sibling rows. Click again to collapse.

     We mutate the existing DOM rather than rebuilding from data so the
     OverviewRefresh AJAX cycle keeps working — when the list is rebuilt
     by AJAX, we just re-run the collapse pass.
     ====================================================================== */
  function getSpeciesKey(item) {
    // Prefer the recent-name text content as the dedup key (the kiosk's
    // recent-item rows always include common name).
    const nameEl = item.querySelector('.recent-name');
    return nameEl ? nameEl.textContent.trim() : '';
  }

  function collapseRecentList() {
    const list = document.getElementById('recent-list');
    if (!list) return;

    // Don't re-collapse if we already have collapsed groups (idempotent).
    // Strategy: remove any prior collapse state, then re-apply.
    list.querySelectorAll('.pp-collapse-badge').forEach(b => b.remove());
    list.querySelectorAll('.pp-collapsed-extra').forEach(e => {
      e.classList.remove('pp-collapsed-extra', 'pp-collapsed-hidden', 'pp-collapsed-shown');
      e.removeAttribute('data-collapse-group');
    });
    list.querySelectorAll('.pp-collapse-leader').forEach(l => {
      l.classList.remove('pp-collapse-leader', 'pp-expanded');
      l.removeAttribute('data-collapse-group');
      l.removeAttribute('data-collapsed-count');
    });

    const items = Array.from(list.querySelectorAll('.recent-item'));
    if (items.length < 2) return;

    let groupIdx = 0;
    let i = 0;
    while (i < items.length) {
      const leader = items[i];
      const speciesKey = getSpeciesKey(leader);
      let j = i + 1;
      // Find consecutive same-species rows
      while (j < items.length && getSpeciesKey(items[j]) === speciesKey && speciesKey !== '') {
        j++;
      }
      const groupSize = j - i;
      if (groupSize >= 2) {
        // Multi-row group — mark the leader, hide the followers
        const groupId = 'pp-grp-' + groupIdx++;
        leader.classList.add('pp-collapse-leader');
        leader.setAttribute('data-collapse-group', groupId);
        leader.setAttribute('data-collapsed-count', String(groupSize - 1));
        // perchpi:leader-noanchor-v1 — leader rows EXPAND on click, they don't
        // navigate. Replace the .recent-name-link anchor with a span so the
        // click bubbles to the collapse handler without first navigating.
        const leaderAnchor = leader.querySelector('.recent-name-link');
        if (leaderAnchor) {
          const span = document.createElement('span');
          span.className = 'recent-name-text';
          span.textContent = leaderAnchor.textContent;
          leaderAnchor.replaceWith(span);
        }

        // Add badge inside the leader's body
        const subEl = leader.querySelector('.recent-sub');
        if (subEl) {
          const badge = document.createElement('span');
          badge.className = 'pp-collapse-badge';
          badge.textContent = '+' + (groupSize - 1);
          badge.setAttribute('aria-label', (groupSize - 1) + ' more consecutive detection' + (groupSize - 1 === 1 ? '' : 's'));
          subEl.appendChild(badge);
        }

        for (let k = i + 1; k < j; k++) {
          items[k].classList.add('pp-collapsed-extra', 'pp-collapsed-hidden');
          items[k].setAttribute('data-collapse-group', groupId);
        }
      }
      i = j;
    }
  }

  function initRecentCollapseClicks() {
    const list = document.getElementById('recent-list');
    if (!list) return;

    list.addEventListener('click', (e) => {
      const leader = e.target.closest('.pp-collapse-leader');
      if (!leader) return;

      // Don't expand if user clicked the play button or the photo (those
      // have their own behavior — audio playback or lightbox).
      if (e.target.closest('.play-btn, .play-btn-md, .play-btn-lg')) return;
      if (e.target.closest('.thumb-tappable')) return;

      const groupId = leader.getAttribute('data-collapse-group');
      if (!groupId) return;

      const wasExpanded = leader.classList.contains('pp-expanded');
      leader.classList.toggle('pp-expanded');

      const followers = list.querySelectorAll('[data-collapse-group="' + groupId + '"].pp-collapsed-extra');
      followers.forEach((row) => {
        if (wasExpanded) {
          row.classList.remove('pp-collapsed-shown');
          row.classList.add('pp-collapsed-hidden');
        } else {
          row.classList.remove('pp-collapsed-hidden');
          row.classList.add('pp-collapsed-shown');
        }
      });

      // Stop propagation so the row's tap-to-play doesn't also fire
      e.stopPropagation();
      e.preventDefault();
    }, true);
  }

  // Re-run collapse after AJAX refresh of the recent list
  function observeRecentList() {
    const list = document.getElementById('recent-list');
    if (!list) return;
    const observer = new MutationObserver((mutations) => {
      // Only re-collapse if the children themselves changed (not just
      // attribute updates from us)
      const childChange = mutations.some(m => m.type === 'childList');
      if (childChange) {
        // Defer to next tick so any in-progress mutations settle
        setTimeout(collapseRecentList, 0);
      }
    });
    observer.observe(list, { childList: true });
  }

  /* ======================================================================
     SPECIES ROW CLICK DELEGATION (Birds Today)
     ----------------------------------------------------------------------
     Three click zones per row:
     - .thumb-tappable (photo)  → bird image lightbox (BirdOverlay handles)
     - .pp-species-play (button) → play audio sample (Audio handles via
       data-audio attribute on parent)
     - .pp-species-body (everything else) → drill into Species Detail
     ====================================================================== */
  function initSpeciesRowClicks() {
    document.querySelectorAll('.pp-species-row').forEach(row => {
      const body = row.querySelector('.pp-species-body');
      if (!body) return;
      const link = row.getAttribute('data-species-link');
      if (!link) return;

      const navigate = () => { window.location.href = link; };

      body.addEventListener('click', (e) => {
        // The row's data-audio means the global Audio handler may try to
        // play — but Audio's handler checks for a row element with class
        // 'recent-item, today-item, latest-card'. Species rows don't match,
        // so Audio will skip. We just need to navigate.
        navigate();
        e.stopPropagation();
      });
      body.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          navigate();
        }
      });
    });
  }

  /* ======================================================================
     SWIPE-BACK GESTURE
     ----------------------------------------------------------------------
     On pages that have a .pp-back-nav element, the left-edge swipe right
     becomes "back" instead of "open sidebar". The sidebar can still be
     opened via the hamburger button.

     Implementation: set a flag the Sidebar module checks. We hook into
     touchstart at the document level (capture phase) so we win over the
     Sidebar's listeners.
     ====================================================================== */
  function initSwipeBack() {
    const backNav = document.querySelector('.pp-back-nav');
    if (!backNav) return;
    const backHref = backNav.getAttribute('data-back-href');
    if (!backHref) return;

    // Disable the sidebar's swipe-open by toggling a class on the body.
    // CSS observes .pp-no-edge-swipe-sidebar to neutralize the sidebar
    // gesture; JS reads the same flag below.
    document.body.classList.add('pp-back-mode');

    let edgeStartX = null, edgeStartY = null, edgeStartTime = null;
    const EDGE_THRESHOLD = 24;
    const SWIPE_THRESHOLD = 60;
    const SWIPE_TIME_MAX = 600;

    document.addEventListener('touchstart', (e) => {
      const t = e.touches[0];
      if (t.clientX <= EDGE_THRESHOLD) {
        edgeStartX = t.clientX;
        edgeStartY = t.clientY;
        edgeStartTime = Date.now();
      }
    }, { passive: true, capture: true });

    document.addEventListener('touchend', (e) => {
      if (edgeStartX === null) return;
      const t = e.changedTouches[0];
      const dx = t.clientX - edgeStartX;
      const dy = Math.abs(t.clientY - edgeStartY);
      const dt = Date.now() - edgeStartTime;
      if (dx > SWIPE_THRESHOLD && dy < 80 && dt < SWIPE_TIME_MAX) {
        window.location.href = backHref;
      }
      edgeStartX = null;
      edgeStartY = null;
      edgeStartTime = null;
    }, { passive: true, capture: true });
  }

  /* ======================================================================
     BUCKET TOGGLE (Species Detail page)
     ----------------------------------------------------------------------
     Each .pp-bucket section has a header that toggles its body's display.
     On touch, the largest bucket may default to collapsed
     (data-collapse-touch-default="1").
     ====================================================================== */
  function initBucketToggles() {
    const buckets = document.querySelectorAll('.pp-bucket');
    if (buckets.length === 0) return;

    const isTouch = window.matchMedia('(max-width: 1024px)').matches;

    buckets.forEach(bucket => {
      const header = bucket.querySelector('.pp-bucket-header');
      if (!header) return;
      const shouldCollapseDefault = true;  // c227 polish: collapse all buckets by default
      if (shouldCollapseDefault) {
        bucket.classList.add('pp-bucket-collapsed');
        header.setAttribute('aria-expanded', 'false');
      }

      const toggle = () => {
        const expanded = !bucket.classList.contains('pp-bucket-collapsed');
        bucket.classList.toggle('pp-bucket-collapsed');
        header.setAttribute('aria-expanded', String(!expanded));
      };

      header.addEventListener('click', toggle);
      header.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggle();
        }
      });
    });
  }

  /* ======================================================================
     INIT
     ====================================================================== */
  function init() {
    initStatTileLinks();
    initSpeciesRowClicks();
    initSwipeBack();
    initBucketToggles();
    collapseRecentList();
    initRecentCollapseClicks();
    observeRecentList();
  }

  // Register methods so other code can re-trigger if needed
  if (window.PerchPi) {
    window.PerchPi.collapseRecentList = collapseRecentList;
    window.PerchPi.initStatTileLinks  = initStatTileLinks;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
/* ============================================================================
   Phase C-2.2.3 — Fix patch (append to perchpi.js)
   ============================================================================
   - Tag body with .pp-on-spectrogram when on Spectrogram view
   - Route Today stat tile to "Daily Detections" (avoids apostrophe URL issue)
   ============================================================================ */

(function() {
  'use strict';

  // Tag body for spectrogram CSS hooks
  function tagSpectrogramBody() {
    const params = new URLSearchParams(window.location.search);
    const view = params.get('view');
    if (view === 'Spectrogram') {
      document.body.classList.add('pp-on-spectrogram');
      // Wrap the main content in a class for full-width spectrogram styling
      const main = document.querySelector('.app-main');
      if (main) main.classList.add('pp-spectrogram-full');
    }
  }

  // Re-route the Today stat tile click to the apostrophe-free view name
  function fixTodayStatRoute() {
    if (!document.querySelector('.app-main')) return;
    const valueEl = document.getElementById('stat-today');
    if (!valueEl) return;
    const tile = valueEl.closest('.stat');
    if (!tile) return;

    // Replace any prior click handlers with a single one pointing at the
    // apostrophe-free view. Cloning the node is the simplest way to detach
    // listeners that were bound earlier in this same load.
    const fresh = tile.cloneNode(true);
    tile.parentNode.replaceChild(fresh, tile);

    fresh.classList.add('pp-stat-clickable');
    fresh.setAttribute('role', 'link');
    fresh.setAttribute('tabindex', '0');
    fresh.setAttribute('aria-label', 'Today — open list');
    fresh.style.cursor = 'pointer';

    const navigate = () => {
      window.location.href = '?view=' + encodeURIComponent('Daily Detections');
    };
    fresh.addEventListener('click', navigate);
    fresh.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        navigate();
      }
    });
  }

  function init() {
    tagSpectrogramBody();
    fixTodayStatRoute();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
/* ============================================================================
   Phase C-2.2.5 — Photo-click handler (v3, surgical)
   ============================================================================
   v3: Only intercept clicks on photos inside .pp-species-row (Birds Today /
       All Species ranked rows). Every other bird photo keeps its native
       behavior:
       - Latest Detection hero (Overview)         → kiosk lightbox handler
       - Species Detail hero                       → kiosk lightbox handler
       - Recent / Today / Last Hour / bucket rows → kiosk row-level audio play

   This file replaces v1 and v2. Earlier versions intercepted too aggressively
   and broke the lightbox flow on hero cards.
   ============================================================================ */

(function() {
  'use strict';

  function handleSpeciesRowPhotoClick(e) {
    const thumb = e.target.closest('.thumb-tappable');
    if (!thumb) return;

    // Only fire for photos inside species-list rows (Birds Today / All Species)
    const speciesRow = thumb.closest('.pp-species-row');
    if (!speciesRow) return;

    // perchpi:bestrec-lightbox-passthrough-v1 — Best Recordings rows want
    // lightbox on photo, not drill. Let BirdOverlay's handler take over.
    if (speciesRow.classList.contains('best-item')) return;

    // Bird photo only (skip placeholders)
    if (thumb.getAttribute('data-is-bird-photo') !== '1') return;

    // Skip if user clicked a play button inside the row
    if (e.target.closest('.play-btn, .play-btn-md, .play-btn-lg')) return;

    // Drill into Species Detail
    const sci = speciesRow.getAttribute('data-species-sci') ||
                thumb.getAttribute('data-sci');
    if (!sci) return;

    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    const explicitLink = speciesRow.getAttribute('data-species-link');
    if (explicitLink) {
      window.location.href = explicitLink;
    } else {
      window.location.href = '?view=' + encodeURIComponent('Species Detail') +
                             '&species=' + encodeURIComponent(sci);
    }
  }

  function init() {
    // Window-level capture phase: wins the race against the kiosk's
    // document-level lightbox handler, but only for the narrow case we want.
    window.addEventListener('click', handleSpeciesRowPhotoClick, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
/* ============================================================================
   Phase C-2.2.6 — Surgical add-on (append to perchpi.js)
   ============================================================================
   Adds only what's missing from the restored c225 backup:
   - All-Time Species sys-item click → /?view=All Species
   - Emoji icons on sys-grid (⚙️ Analyzer, 💾 Disk, ∑ All-Time, 🦜 All-Time Species)

   Does NOT touch anything else. Idempotent — safe to run multiple times.
   ============================================================================ */

(function() {
  'use strict';

  function initAllTimeSpeciesTile() {
    if (!document.querySelector('.app-main')) return;

    document.querySelectorAll('.sys-grid .sys-item').forEach(item => {
      const nameEl = item.querySelector('.sys-name');
      if (!nameEl) return;
      const labelText = (nameEl.textContent || '').trim().toUpperCase();

      const matches = (
        labelText === 'ALL-TIME SPECIES' ||
        labelText === 'ALLTIME SPECIES' ||
        labelText === 'ALL TIME SPECIES' ||
        labelText === 'TOTAL SPECIES'
      );
      if (!matches) return;
      if (item.classList.contains('pp-sys-clickable')) return;

      item.classList.add('pp-sys-clickable');
      item.setAttribute('role', 'link');
      item.setAttribute('tabindex', '0');
      item.setAttribute('aria-label', 'All-time species — open list');
      item.style.cursor = 'pointer';

      const navigate = () => {
        window.location.href = '?view=' + encodeURIComponent('All Species');
      };
      item.addEventListener('click', navigate);
      item.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          navigate();
        }
      });
    });
  }

  const SYS_ICON_MAP = {
    'ANALYZER':         '⚙️',
    'DISK':             '💾',
    'ALL-TIME':         '∑',
    'ALL TIME':         '∑',
    'ALLTIME':          '∑',
    'ALL-TIME SPECIES': '🦜',
    'ALL TIME SPECIES': '🦜',
    'ALLTIME SPECIES':  '🦜',
    'TOTAL SPECIES':    '🦜',
  };

  function injectSysIcons() {
    document.querySelectorAll('.sys-grid .sys-item').forEach(item => {
      const nameEl = item.querySelector('.sys-name');
      if (!nameEl) return;
      const labelText = (nameEl.textContent || '').trim().toUpperCase();

      let icon = null;
      const keys = Object.keys(SYS_ICON_MAP).sort((a, b) => b.length - a.length);
      for (const key of keys) {
        if (labelText === key) { icon = SYS_ICON_MAP[key]; break; }
      }
      if (!icon) return;

      let iconEl = item.querySelector('.sys-icon');
      if (iconEl) {
        if ((iconEl.textContent || '').trim() === '') {
          iconEl.textContent = icon;
        }
      } else {
        iconEl = document.createElement('div');
        iconEl.className = 'sys-icon pp-injected-icon';
        iconEl.textContent = icon;

        const dot = item.querySelector('.sys-dot');
        if (dot && dot.parentNode === item) {
          dot.insertAdjacentElement('afterend', iconEl);
        } else {
          item.insertBefore(iconEl, item.firstChild);
        }
      }
    });
  }

  function init() {
    initAllTimeSpeciesTile();
    injectSysIcons();

    // Watch for AJAX rebuilds of the sys-grid
    const sysGrid = document.querySelector('.sys-grid');
    if (sysGrid) {
      const observer = new MutationObserver(() => {
        setTimeout(() => {
          initAllTimeSpeciesTile();
          injectSysIcons();
        }, 0);
      });
      observer.observe(sysGrid, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
/* ============================================================================
   Phase C-2.2.7 — Persist expand state across AJAX refreshes
   ============================================================================
   The Recent Detections list rebuilds itself every ~20s via AJAX. Without
   intervention, any rows the user expanded re-collapse on each refresh.

   Fix: maintain a shadow Set of "currently expanded species" (keyed by
   scientific name from data-sci attribute). Update the set on every
   user-initiated expand/collapse click. After each rebuild, walk the new
   leader rows and re-apply the expanded state for any species in the set.

   Hooks into the existing collapseRecentList() flow without modifying it —
   uses event delegation for click capture and a MutationObserver for the
   post-rebuild restore.
   ============================================================================ */

(function() {
  'use strict';

  // Shadow state: scientific names of currently-expanded species clusters.
  // Survives AJAX rebuilds (a JS variable lives outside the DOM).
  const expandedSpecies = new Set();

  /**
   * Read the scientific name from a leader row. The thumb element carries
   * data-sci; if not present, fall back to data-common (less reliable).
   */
  function getRowSci(leader) {
    const thumb = leader.querySelector('.thumb-tappable, .recent-thumb');
    if (thumb && thumb.getAttribute('data-sci')) {
      return thumb.getAttribute('data-sci');
    }
    // Fallback: use the visible name text
    const name = leader.querySelector('.recent-name');
    return name ? name.textContent.trim() : '';
  }

  /**
   * Capture handler: when a user clicks/expands a leader, update the shadow
   * set. We listen at document-level capture phase so we run BEFORE the
   * existing collapseRecentList click handler toggles the class.
   */
  function trackExpansionClicks(e) {
    const leader = e.target.closest('.pp-collapse-leader');
    if (!leader) return;
    // Match the same skips as the existing handler — so we only update the
    // shadow when the existing handler will also toggle expansion.
    if (e.target.closest('.play-btn, .play-btn-md, .play-btn-lg')) return;
    if (e.target.closest('.thumb-tappable')) return;

    const sci = getRowSci(leader);
    if (!sci) return;

    // The existing handler toggles the pp-expanded class. We mirror its
    // logic: if currently expanded, this click will collapse → remove from
    // set. If currently collapsed, this click will expand → add to set.
    const wasExpanded = leader.classList.contains('pp-expanded');
    if (wasExpanded) {
      expandedSpecies.delete(sci);
    } else {
      expandedSpecies.add(sci);
    }
  }

  /**
   * Restore handler: after the recent-list rebuilds, re-apply the expanded
   * state for any species in the shadow set. Walks the new leader rows,
   * reads sci, and dispatches a synthetic "click" on the row's body to
   * trigger the existing collapse logic naturally.
   *
   * Implementation note: rather than dispatching clicks (which could
   * interfere with other handlers), we directly mimic what the existing
   * handler does: toggle pp-expanded on the leader and adjust visibility
   * of the followers.
   */
  function restoreExpandedState() {
    const list = document.getElementById('recent-list');
    if (!list) return;
    if (expandedSpecies.size === 0) return;

    // Clean stale entries: drop sci names that aren't visible in the new list.
    const visibleSci = new Set();
    list.querySelectorAll('.pp-collapse-leader').forEach(leader => {
      const sci = getRowSci(leader);
      if (sci) visibleSci.add(sci);
    });
    for (const sci of Array.from(expandedSpecies)) {
      if (!visibleSci.has(sci)) {
        expandedSpecies.delete(sci);
      }
    }

    // For each leader matching a tracked sci, force-expand
    list.querySelectorAll('.pp-collapse-leader').forEach(leader => {
      const sci = getRowSci(leader);
      if (!sci || !expandedSpecies.has(sci)) return;
      if (leader.classList.contains('pp-expanded')) return; // already

      const groupId = leader.getAttribute('data-collapse-group');
      if (!groupId) return;

      leader.classList.add('pp-expanded');
      const followers = list.querySelectorAll(
        '[data-collapse-group="' + groupId + '"].pp-collapsed-extra'
      );
      followers.forEach(row => {
        row.classList.remove('pp-collapsed-hidden');
        row.classList.add('pp-collapsed-shown');
      });
    });
  }

  /**
   * Watch the recent-list for AJAX rebuilds. The existing observer in
   * perchpi.js re-runs collapseRecentList(); ours runs AFTER that to
   * restore expansion state.
   *
   * setTimeout(fn, 0) defers our work to the end of the current task queue,
   * so we run after the existing observer's setTimeout(0) has finished its
   * collapse pass.
   */
  function observeRecentList() {
    const list = document.getElementById('recent-list');
    if (!list) return;

    const observer = new MutationObserver((mutations) => {
      const childChange = mutations.some(m => m.type === 'childList');
      if (!childChange) return;

      // Wait two ticks: first lets the existing observer's collapse run,
      // second runs after that completes. Together they sequence:
      //   tick 0: AJAX replaces children
      //   tick 1: existing observer runs collapseRecentList()
      //   tick 2: we run restoreExpandedState()
      setTimeout(() => {
        setTimeout(restoreExpandedState, 0);
      }, 0);
    });

    observer.observe(list, { childList: true });
  }

  function init() {
    // Capture-phase click listener so we update the shadow before the
    // existing handler toggles classes
    document.addEventListener('click', trackExpansionClicks, true);

    // Watch for AJAX rebuilds
    observeRecentList();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
/* ============================================================================
   Phase C-2.2.8 — Consecutive collapse on Today's Detections
   ============================================================================
   Mirrors the Recent Detections collapse behavior on the Today's Detections
   page. Different list container, different item class, different name
   element — generalized helper here, plus a small wrapper invocation.

   Idempotent. Re-runs cleanly if Today's Detections is navigated to multiple
   times in one session.

   Click behavior matches Recent Detections:
   - Click leader row → expand cluster
   - Click leader again → collapse
   - Click play button → audio (cluster state unchanged)
   - Click photo → audio (Today rows play audio per Q4=A)
   ============================================================================ */

(function() {
  'use strict';

  /**
   * Generalized consecutive-cluster collapse. Operates on any list with a
   * given item selector and name element selector.
   *
   * Adds:
   *   - .pp-collapse-leader on the head row of clusters with 2+ items
   *   - .pp-collapsed-extra + .pp-collapsed-hidden on follower rows
   *   - .pp-collapse-badge with "+N" inside the leader's badge target
   *   - data-collapse-group attribute linking leader and followers
   *
   * The existing click handler (initRecentCollapseClicks) already handles
   * any element with .pp-collapse-leader, so it works here without changes.
   */
  function collapseList(opts) {
    const list = opts.list;
    const itemSel = opts.itemSel;
    const nameSel = opts.nameSel;
    const badgeTargetSel = opts.badgeTargetSel;
    const groupPrefix = opts.groupPrefix || 'pp-grp-tdy-';

    if (!list) return;

    // Idempotent reset
    list.querySelectorAll('.pp-collapse-badge').forEach(b => b.remove());
    list.querySelectorAll('.pp-collapsed-extra').forEach(e => {
      e.classList.remove('pp-collapsed-extra', 'pp-collapsed-hidden', 'pp-collapsed-shown');
      e.removeAttribute('data-collapse-group');
    });
    list.querySelectorAll('.pp-collapse-leader').forEach(l => {
      l.classList.remove('pp-collapse-leader', 'pp-expanded');
      l.removeAttribute('data-collapse-group');
      l.removeAttribute('data-collapsed-count');
    });

    const items = Array.from(list.querySelectorAll(itemSel));
    if (items.length < 2) return;

    function getKey(item) {
      const nameEl = item.querySelector(nameSel);
      return nameEl ? nameEl.textContent.trim() : '';
    }

    let groupIdx = 0;
    let i = 0;
    while (i < items.length) {
      const leader = items[i];
      const speciesKey = getKey(leader);
      let j = i + 1;
      while (j < items.length && getKey(items[j]) === speciesKey && speciesKey !== '') {
        j++;
      }
      const groupSize = j - i;
      if (groupSize >= 2) {
        const groupId = groupPrefix + groupIdx++;
        leader.classList.add('pp-collapse-leader');
        leader.setAttribute('data-collapse-group', groupId);
        leader.setAttribute('data-collapsed-count', String(groupSize - 1));

        const target = leader.querySelector(badgeTargetSel);
        if (target) {
          const badge = document.createElement('span');
          badge.className = 'pp-collapse-badge';
          badge.textContent = '+' + (groupSize - 1);
          badge.setAttribute('aria-label',
            (groupSize - 1) + ' more consecutive detection' +
            (groupSize - 1 === 1 ? '' : 's'));
          target.appendChild(badge);
        }

        for (let k = i + 1; k < j; k++) {
          items[k].classList.add('pp-collapsed-extra', 'pp-collapsed-hidden');
          items[k].setAttribute('data-collapse-group', groupId);
        }
      }
      i = j;
    }
  }

  /**
   * Apply collapse to the Today's Detections list. Idempotent.
   */
  function collapseTodayList() {
    const list = document.getElementById('today-list') ||
                 document.querySelector('.today-list');
    if (!list) return;
    collapseList({
      list: list,
      itemSel: '.today-item',
      nameSel: '.today-name',
      badgeTargetSel: '.today-meta',
      groupPrefix: 'pp-grp-tdy-'
    });
  }

  /**
   * Hook into the existing collapse click handler. The existing
   * initRecentCollapseClicks() listens on #recent-list only. We add a
   * matching listener on the Today's Detections list.
   */
  function initTodayCollapseClicks() {
    const list = document.getElementById('today-list') ||
                 document.querySelector('.today-list');
    if (!list) return;

    list.addEventListener('click', (e) => {
      const leader = e.target.closest('.pp-collapse-leader');
      if (!leader) return;
      // Don't expand if user clicked play or photo
      if (e.target.closest('.play-btn, .play-btn-md, .play-btn-lg')) return;
      if (e.target.closest('.thumb-tappable')) return;

      const groupId = leader.getAttribute('data-collapse-group');
      if (!groupId) return;

      const wasExpanded = leader.classList.contains('pp-expanded');
      leader.classList.toggle('pp-expanded');

      const followers = list.querySelectorAll(
        '[data-collapse-group="' + groupId + '"].pp-collapsed-extra'
      );
      followers.forEach((row) => {
        if (wasExpanded) {
          row.classList.remove('pp-collapsed-shown');
          row.classList.add('pp-collapsed-hidden');
        } else {
          row.classList.remove('pp-collapsed-hidden');
          row.classList.add('pp-collapsed-shown');
        }
      });

      e.stopPropagation();
      e.preventDefault();
    }, true);
  }

  function init() {
    collapseTodayList();
    initTodayCollapseClicks();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
/* ============================================================================
   Phase C-2.2.9 — Lightbox available on every page (append to perchpi.js)
   ============================================================================
   Problem: kiosk.js caches a reference to #bird-overlay at load time. On
   pages that don't render kiosk.php's HTML (Species Detail, Birds Today,
   All Species, Today's Detections, etc.), the overlay element doesn't
   exist → cached reference is null → clicking a hero photo silently does
   nothing.

   Fix: inject the overlay HTML into the body if missing, and add a
   window-capture-phase click handler that opens the lightbox for hero
   photos (anything inside .pp-species-hero or .latest-card.pp-species-hero).
   The existing photo-drill handler at line ~178 already excludes
   .pp-species-hero, so this fills that gap.

   Self-contained — does not depend on kiosk.js's IIFE-scoped functions.
   ============================================================================ */

(function() {
  'use strict';

  /* perchpi:s21a-xss-lightbox-c229-v1 -- SHIP-WITH #5: escape Commons credit fields before innerHTML,
     and only link http(s) sources (mirrors BirdOverlay._escapeHtml/_escapeAttr). */
  function ppEscHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  }
  function ppSafeHref(u) { return /^https?:\/\//i.test(u || '') ? ppEscHtml(u) : ''; }

  const OVERLAY_HTML = `
    <div class="bird-overlay" id="bird-overlay" aria-hidden="true">
      <div class="bird-overlay-backdrop" data-close="1"></div>
      <div class="bird-overlay-content">
        <button class="bird-overlay-close" id="bird-overlay-close" aria-label="Close">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
          </svg>
        </button>
        <img class="bird-overlay-img" id="bird-overlay-img" alt="">
        <div class="bird-overlay-info">
          <div class="bird-overlay-name" id="bird-overlay-name"></div>
          <div class="bird-overlay-sci" id="bird-overlay-sci"></div>
          <div class="bird-overlay-credit" id="bird-overlay-credit"></div>
          <div class="bird-overlay-hint">Tap anywhere to close</div>
        </div>
      </div>
    </div>
  `;

  function ensureOverlayHtml() {
    if (document.getElementById('bird-overlay')) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = OVERLAY_HTML.trim();
    document.body.appendChild(wrap.firstElementChild);
  }

  function openOverlay(thumbEl) {
    const overlay = document.getElementById('bird-overlay');
    if (!overlay || !thumbEl) return;

    const img = thumbEl.querySelector('img');
    if (!img) return;

    const overlayImg    = document.getElementById('bird-overlay-img');
    const overlayName   = document.getElementById('bird-overlay-name');
    const overlaySci    = document.getElementById('bird-overlay-sci');
    const overlayCredit = document.getElementById('bird-overlay-credit');

    overlayImg.src = img.src;
    overlayImg.alt = thumbEl.getAttribute('data-common') || '';
    overlayName.textContent = thumbEl.getAttribute('data-common') || '';
    overlaySci.textContent  = thumbEl.getAttribute('data-sci')    || '';

    const artist  = thumbEl.getAttribute('data-photo-artist')  || '';
    const license = thumbEl.getAttribute('data-photo-license') || '';
    const source  = thumbEl.getAttribute('data-photo-source')  || '';

    let credit = '';
    if (artist)  credit += 'Photo by ' + ppEscHtml(artist);
    if (license) credit += (artist ? ' · ' : '') + ppEscHtml(license);
    if (credit)  credit += ' · ';
    credit += 'via ';
    if (ppSafeHref(source)) {
      credit += '<a href="' + ppSafeHref(source) + '" target="_blank" rel="noopener">Wikimedia Commons</a>';
    } else {
      credit += 'Wikimedia Commons';
    }
    overlayCredit.innerHTML = credit;

    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
  }

  function closeOverlay() {
    const overlay = document.getElementById('bird-overlay');
    if (!overlay) return;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    setTimeout(() => {
      if (!overlay.classList.contains('open')) {
        const img = document.getElementById('bird-overlay-img');
        if (img) img.src = '';
      }
    }, 200);
  }

  /**
   * Click handler — fires when user taps a hero bird photo.
   * Runs at window-level capture phase to win the race against kiosk.js's
   * document-level bubble-phase listener (which would otherwise call its
   * cached null-overlay openBirdOverlay and do nothing useful).
   */
  function handleHeroClick(e) {
    const thumb = e.target.closest('.thumb-tappable');
    if (!thumb) return;
    if (thumb.getAttribute('data-is-bird-photo') !== '1') return;

    // Only fire for hero photos — not list-row thumbs (those have other
    // behaviors handled elsewhere). Exception: .best-item rows want the
    // lightbox on the photo (perchpi:bestrec-c229-extend-v1).
    const isHero = thumb.closest('.pp-species-hero, .latest-card, .best-item');
    if (!isHero) return;

    // Skip if click was on a play button inside the hero
    if (e.target.closest('.play-btn, .play-btn-md, .play-btn-lg')) return;

    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    ensureOverlayHtml();
    openOverlay(thumb);
  }

  /**
   * Backdrop / close button click → close
   */
  function handleOverlayDismiss(e) {
    const overlay = e.target.closest('#bird-overlay');
    if (!overlay) return;
    // Don't close when clicking the image itself (lets user view it bigger)
    if (e.target.matches('.bird-overlay-img')) return;
    closeOverlay();
  }

  function init() {
    // Ensure overlay HTML exists on this page
    if (document.body) {
      ensureOverlayHtml();
    } else {
      document.addEventListener('DOMContentLoaded', ensureOverlayHtml);
    }

    // Window capture for hero photos — wins over kiosk.js's bubble handler
    window.addEventListener('click', handleHeroClick, true);

    // Backdrop / close
    document.addEventListener('click', handleOverlayDismiss);

    // ESC key closes
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeOverlay();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

// perchpi:batch3-2c:detection-row-play
/* ── Detection row tap-to-play ─────────────────────────────────────────────
 * Attaches a click handler to every .detection-spec on the per-detection
 * page. Single shared <audio> element; tapping a row plays its audio,
 * tapping again (or tapping a different row) pauses/swaps. Marks the
 * playing row with .is-playing for visual feedback.
 * ──────────────────────────────────────────────────────────────────────── */
(function () {
  function init() {
    var rows = document.querySelectorAll('.detection-spec[data-audio]');
    if (!rows.length) return;

    var audio = null;
    var currentItem = null;
    // perchpi:option-b-detection-row-bridge-v1 - PPAudio loudness bridge state.
    var ppHandle = null;
    function ppEnabled() {
      return (typeof window.PPAudio === 'object' && window.PPAudio &&
              typeof window.PPAudio.isEnabled === 'function' && window.PPAudio.isEnabled() &&
              typeof window.PPAudio.play === 'function');
    }
    function ppStop() {
      if (ppHandle) { try { ppHandle.stop(); } catch (_) {} ppHandle = null; }
    }

    function ensureAudio() {
      if (audio) return audio;
      audio = document.createElement('audio');
      audio.preload = 'none';
      audio.addEventListener('ended', function () {
        ppStop();
        if (currentItem) currentItem.classList.remove('is-playing');
        currentItem = null;
      });
      audio.addEventListener('error', function () {
        if (currentItem) currentItem.classList.remove('is-playing');
        currentItem = null;
      });
      return audio;
    }

    rows.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var item = btn.closest('.detection-item');
        var src = btn.getAttribute('data-audio');
        if (!src || !item) return;

        var a = ensureAudio();

        // Tap on the currently playing row → pause and clear.
        if (currentItem === item && !a.paused) {
          a.pause();
          ppStop();
          item.classList.remove('is-playing');
          currentItem = null;
          return;
        }

        // Switching rows or starting fresh.
        if (currentItem && currentItem !== item) {
          currentItem.classList.remove('is-playing');
        }
        if (a.src !== location.origin + src && a.src !== src) {
          a.src = src;
        }
        item.classList.add('is-playing');
        currentItem = item;
        // perchpi:option-b-detection-row-bridge-v1 - route sound through PPAudio
        // (normalized); local <audio> stays a muted clock. Native on failure.
        var _ppOn = ppEnabled();
        a.muted = _ppOn;
        ppStop();
        if (_ppOn) {
          window.PPAudio.play(src).then(function (h) { ppHandle = h; })
            .catch(function (e) {
              console.warn('PPAudio.play failed, falling back to native:', e);
              a.muted = false;
            });
        }
        var p = a.play();
        if (p && typeof p.catch === 'function') {
          p.catch(function () {
            item.classList.remove('is-playing');
            if (currentItem === item) currentItem = null;
          });
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
/* perchpi:phase2b-pills */


/* ============================================================================
   perchpi:refresh-buttons-js-v1 — per-species "Refresh photo" buttons
   Adds a small refresh button overlay on cards/rows where the bird photo
   is missing (data-is-bird-photo="0"). Tap → POST → swap image on success.
   ============================================================================ */
(function() {
  function ppAttachRefreshButtons() {
    var els = document.querySelectorAll('[data-is-bird-photo="0"][data-sci]:not(.pp-refresh-btn-attached)');
    els.forEach(function(el) {
      el.classList.add('pp-refresh-btn-attached');
      var sci = el.getAttribute('data-sci') || '';
      if (!sci) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pp-refresh-photo-btn';
      btn.innerHTML = '&#8635;';
      btn.title = 'Try to fetch photo for ' + sci;
      btn.setAttribute('aria-label', 'Refresh photo for ' + sci);
      btn.addEventListener('click', function(ev) {
        ev.stopPropagation();
        ev.preventDefault();
        if (btn.disabled) return;
        btn.disabled = true;
        btn.classList.add('pp-spinning');
        var fd = new FormData();
        fd.append('sci_name', sci);
        fetch('/refresh_one_photo.php', { method: 'POST', body: fd,
                                          headers: { 'X-CSRF-Token': window.PP_CSRF || '' } })  // perchpi:s21a-csrf-v1
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.ok && data.photo_url) {
              // Replace any spectrogram/img inside the element with a real photo
              var img = el.querySelector('img');
              if (img) {
                img.src = data.photo_url;
              } else {
                // No img — could be a CSS background or other markup. Reload page after a brief pause.
                setTimeout(function() { location.reload(); }, 500);
              }
              el.setAttribute('data-is-bird-photo', '1');
              btn.remove();
            } else {
              btn.classList.remove('pp-spinning');
              btn.classList.add('pp-refresh-failed');
              btn.title = 'Wikipedia has no photo for this species';
              btn.disabled = true;
            }
          })
          .catch(function(err) {
            btn.classList.remove('pp-spinning');
            btn.disabled = false;
            console.error('refresh photo failed:', err);
          });
      });
      el.style.position = el.style.position || 'relative';
      el.appendChild(btn);
    });
  }
  // Run on initial load + after AJAX refreshes (kiosk page rebuilds Latest + Recent every 20s)
  document.addEventListener('DOMContentLoaded', ppAttachRefreshButtons);
  window.ppAttachRefreshButtons = ppAttachRefreshButtons;
  // Re-scan periodically in case markup changes (kiosk AJAX, infinite scroll)
  setInterval(ppAttachRefreshButtons, 5000);
})();

// ════════════════════════════════════════════════════════════════════════════
// perchpi:quarantine-pulse-js-v1 — amber pulse on Quarantine sidebar item
// when there are unseen quarantined detections.
// Compares data-latest-quarantine attribute (server-rendered ISO 8601 string)
// to localStorage 'pp_last_quarantine_visit'. ISO strings sort lexically so
// no Date parsing required. Defensive against localStorage unavailability
// (private browsing mode) — fail open, no flash.
// ════════════════════════════════════════════════════════════════════════════
(function() {
  function ppApplyQuarantinePulse() {
    try {
      var navLink = document.querySelector('a[data-nav-view="Quarantine"]');
      if (!navLink) return;
      var latest = navLink.getAttribute('data-latest-quarantine') || '';
      if (!latest) return;  // no quarantines at all → no pulse

      var lastVisit = '';
      try { lastVisit = localStorage.getItem('pp_last_quarantine_visit') || ''; }
      catch (e) { return; }  // localStorage unavailable → fail open

      // ISO 8601 string compare: latest > lastVisit means there are unseen items
      if (!lastVisit || latest > lastVisit) {
        navLink.classList.add('pp-quarantine-pulse');
      }

      // On click: stamp visit time + remove pulse class
      navLink.addEventListener('click', function() {
        try { localStorage.setItem('pp_last_quarantine_visit', new Date().toISOString()); }
        catch (e) { /* fail open */ }
        navLink.classList.remove('pp-quarantine-pulse');
      });
    } catch (e) { /* defensive — never break the page */ }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ppApplyQuarantinePulse);
  } else {
    ppApplyQuarantinePulse();
  }
})();

/* perchpi:rec-lazy-v1 — lazy-load Recordings spectrogram thumbs via IntersectionObserver.
   Each .pp-rec-spec has data-src; we set src only as the row nears the viewport. */
(function () {
  function initRecLazy() {
    var imgs = document.querySelectorAll('img.pp-rec-spec[data-src]');
    if (!imgs.length) return;
    if (!('IntersectionObserver' in window)) {
      imgs.forEach(function (im) { im.src = im.getAttribute('data-src'); im.removeAttribute('data-src'); });
      return;
    }
    var io = new IntersectionObserver(function (entries, obs) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          var im = e.target;
          im.src = im.getAttribute('data-src');
          im.removeAttribute('data-src');
          obs.unobserve(im);
        }
      });
    }, { rootMargin: '300px 0px' });
    imgs.forEach(function (im) { io.observe(im); });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRecLazy);
  } else {
    initRecLazy();
  }
})();
