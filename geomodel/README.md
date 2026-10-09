# PerchPi geomodel files

Managed by .local-patches/deploy-geomodel.sh.

| File                  | Source                                                        |
|-----------------------|---------------------------------------------------------------|
| geomodel_fp16.onnx    | birdnet-team/geomodel v3.0.2 GitHub release          |
| labels.txt            | same release; 12,012 species (10,185 birds + 1,827 non-birds) |
| VERSION               | release tag                                                   |

To upgrade: edit GEOMODEL_TAG in deploy-geomodel.sh and re-run.

Asset names on the GitHub release have a leading 'BirdNET+_Geomodel_V3.0.2_Global_12K_'
prefix; deploy script downloads them and renames to the simple names above.
