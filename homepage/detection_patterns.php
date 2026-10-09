<?php
/* perchpi:detection-patterns */
/* ============================================================================
   detection_patterns.php — perchpi shell wrapping the Streamlit dashboard.
   Provides back-nav header, "Open in full window" link, and a fixed-height
   iframe with internal scroll. The Streamlit app itself runs as a separate
   Python service on localhost:8501, reverse-proxied through Caddy at /stats.
   ============================================================================ */
require_once __DIR__ . '/perchpi_helpers.php';

pp_render_back_nav(
  '?view=Overview',
  'Overview',
  'Detection Patterns',
  'Time-series analysis across all detections'
);
?>
<section class="pp-content">
  <div style="margin-bottom: var(--space-3); text-align: right;">
    <a href="stats" target="_blank" rel="noopener"
       style="color: var(--pp-accent, #4ade80); text-decoration: none; font-size: 0.9em;">
      Open in full window ↗
    </a>
  </div>
  <div style="position: relative; width: 100%; height: calc(100vh - 180px);
              min-height: 500px; border-radius: var(--radius-lg, 12px);
              overflow: hidden; background: var(--pp-surface, #111);">
    <iframe src="stats"
            title="Detection Patterns Dashboard"
            style="width: 100%; height: 100%; border: 0; display: block;">
    </iframe>
  </div>
</section>
