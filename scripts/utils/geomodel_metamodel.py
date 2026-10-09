"""
GeoMDataModel — PerchPi geomodel integration (Session B).

Drop-in replacement for MDataModel that uses the birdnet-team geomodel ONNX
model (https://github.com/birdnet-team/geomodel) instead of the upstream
TFLite metadata model.

Lives behind the same interface as MDataModel:
    .set_meta_data(lat, lon, week)        -> None
    .get_species_list(labels)             -> list[str]              (sci_names)
    .get_species_list_details(labels)     -> list[(score, label)]   (sorted desc)

Selected via USE_GEOMODEL=1 in /etc/birdnet/birdnet.conf. When the flag is
absent or 0, the upstream MDataModel path is used and this class is never
loaded — onnxruntime is imported only inside __init__.

Defensive default: BirdNET species not in the geomodel's vocabulary
(~305 of 6,522 V2.4 labels — taxonomic drift, recent splits/lumps) are
returned with sentinel score 0.0 and ALWAYS pass the location filter.
The geomodel never makes things worse than running with no location filter
for the species it can't speak to.

Marker: perchpi:geomodel-metamodel-v1
"""

import logging
import os
from pathlib import Path

import numpy as np

from .helpers import get_settings

log = logging.getLogger(__name__)

# Module-level marker so a deploy script can verify the class is present.
PERCHPI_GEOMODEL_MARKER = "perchpi:geomodel-metamodel-v1"


class GeoMDataModel:
    """ONNX-based spatiotemporal occurrence model with MDataModel-compatible API."""

    def __init__(self, sf_thresh):
        # Late import: only pulled in when geomodel is actually selected.
        try:
            import onnxruntime as ort
        except ImportError as e:
            raise ImportError(
                "onnxruntime is required for GeoMDataModel. Install with:\n"
                "  ~/BirdNET-Pi/birdnet/bin/pip install onnxruntime"
            ) from e

        conf = get_settings()
        # Resolve geomodel directory: conf override -> default under BirdNET-Pi.
        if 'GEOMODEL_DIR' in conf:
            geomodel_dir = Path(os.path.expanduser(conf['GEOMODEL_DIR']))
        else:
            geomodel_dir = Path(os.path.expanduser('~/BirdNET-Pi/geomodel'))

        onnx_path = geomodel_dir / 'geomodel_fp16.onnx'
        labels_path = geomodel_dir / 'labels.txt'

        if not onnx_path.exists():
            raise FileNotFoundError(
                f"Geomodel ONNX not found: {onnx_path}\n"
                f"Run .local-patches/deploy-geomodel.sh to fetch it."
            )
        if not labels_path.exists():
            raise FileNotFoundError(
                f"Geomodel labels not found: {labels_path}\n"
                f"Run .local-patches/deploy-geomodel.sh to fetch it."
            )

        log.info("Loading geomodel ONNX from %s", onnx_path)
        sess_options = ort.SessionOptions()
        # The Pi has limited cores and the classifier is the hot path.
        # Geomodel runs once per recording — sub-second is fine; don't
        # contend with the BirdNET classifier on threads.
        sess_options.intra_op_num_threads = 1
        sess_options.inter_op_num_threads = 1
        self._session = ort.InferenceSession(
            str(onnx_path),
            sess_options=sess_options,
            providers=['CPUExecutionProvider'],
        )

        # Discover I/O names dynamically — robust to upstream renames.
        # As of geomodel v3.0.2: input='input' (B,3), output='probabilities' (B,12012).
        self._input_name = self._session.get_inputs()[0].name
        self._output_name = self._session.get_outputs()[0].name

        # Load geomodel labels.txt into a sci_name -> row_index lookup.
        # Format: id<TAB>sci_name<TAB>com_name (12,012 rows in v3.0.2).
        # Row index N == output position N in the probabilities vector.
        self._geo_sci_to_idx = {}
        with open(labels_path, encoding='utf-8') as f:
            for idx, line in enumerate(f):
                parts = line.rstrip('\n').split('\t')
                if len(parts) >= 2:
                    self._geo_sci_to_idx[parts[1]] = idx
        log.info(
            "Geomodel ready: %d species, input=%s, output=%s",
            len(self._geo_sci_to_idx), self._input_name, self._output_name,
        )

        self._sf_thresh = float(sf_thresh)

        # Per-(lat, lon, week) cache, invalidated by set_meta_data.
        self._mdata_params = None
        self._mdata = None

        # BirdNET-label -> geomodel-index crosswalk. Built lazily on first
        # get_species_list_details call (we don't have BirdNET labels at
        # __init__ time — they belong to the classifier).
        self._birdnet_labels_signature = None
        self._birdnet_to_geo_idx = None
        self._birdnet_unknown_count = 0

    # ------------------------------------------------------------------
    # MDataModel-compatible interface
    # ------------------------------------------------------------------

    def set_meta_data(self, lat, lon, week):
        params = (float(lat), float(lon), int(week))
        if self._mdata_params != params:
            self._mdata = None
        self._mdata_params = params

    def get_species_list_details(self, labels):
        if self._mdata is not None:
            return self._mdata
        if self._mdata_params is None:
            raise RuntimeError("set_meta_data() must be called before inference")

        self._ensure_crosswalk(labels)
        probs = self._infer()

        # Build (score, label) pairs:
        #   - geomodel-known species above threshold -> real probability
        #   - geomodel-unknown species               -> sentinel 0.0 (always include)
        # Threshold dropping is mimicking MDataModel's behavior.
        results = []
        for label, geo_idx in zip(labels, self._birdnet_to_geo_idx):
            if geo_idx is None:
                results.append((0.0, label))  # always-include sentinel
            else:
                p = float(probs[geo_idx])
                if p >= self._sf_thresh:
                    results.append((p, label))

        # Sort by score descending; unknowns (0.0) sink to the bottom.
        results.sort(key=lambda x: x[0], reverse=True)

        log.info(
            "Geomodel @ (%.4f, %.4f, w%d): %d species pass filter "
            "(thresh=%.3f, %d defensive unknowns always included)",
            self._mdata_params[0], self._mdata_params[1], self._mdata_params[2],
            len(results), self._sf_thresh, self._birdnet_unknown_count,
        )
        if log.isEnabledFor(logging.DEBUG):
            top = [(round(s, 3), l.split('_')[-1]) for s, l in results[:20]]
            log.debug("Geomodel top 20: %s", top)

        self._mdata = results
        return self._mdata

    def get_species_list(self, labels):
        details = self.get_species_list_details(labels)
        return [s[1].split('_')[0] for s in details]

    # ------------------------------------------------------------------
    # Internals
    # ------------------------------------------------------------------

    def _ensure_crosswalk(self, labels):
        """Build the BirdNET-label-list -> geomodel-index map once per labels set."""
        sig = (id(labels), len(labels))
        if self._birdnet_labels_signature == sig:
            return
        crosswalk = []
        unknown = 0
        for label in labels:
            sci_name = label.split('_')[0]
            geo_idx = self._geo_sci_to_idx.get(sci_name)
            crosswalk.append(geo_idx)
            if geo_idx is None:
                unknown += 1
        self._birdnet_to_geo_idx = crosswalk
        self._birdnet_unknown_count = unknown
        self._birdnet_labels_signature = sig
        n_known = len(labels) - unknown
        log.info(
            "Geomodel crosswalk built: %d / %d BirdNET species mapped "
            "(%d unknown will always pass filter)",
            n_known, len(labels), unknown,
        )

    def _infer(self):
        """Run ONNX inference. Returns shape-(n_species,) float32, already sigmoid'd."""
        lat, lon, week = self._mdata_params
        sample = np.array([[lat, lon, week]], dtype=np.float32)
        out = self._session.run([self._output_name], {self._input_name: sample})[0]
        return out[0].astype(np.float32, copy=False)
