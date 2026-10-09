# PerchPi

A heavily customized bird audio detection and identification platform for Raspberry Pi, derived from [BirdNET-Pi](https://github.com/Nachtzuster/BirdNET-Pi).

## Status

**Phase A: Private development.** Not yet ready for third-party installation. This repository exists for version control, backup, and progress tracking. Public release (Phase B) is planned but pending substantial cleanup work (config externalization, install scripts, fresh-Pi install testing).

## What's different from BirdNET-Pi

PerchPi adds:

- **Embedding-based false-positive gate** using Google's Perch v2 model
- **Geomodel** for species likelihood by location and week-of-year
- **Quarantine review system** with audit logging for human-labeled FP/TP decisions
- **Rich species profiles** with Wikipedia photos, external cross-links (eBird, Macaulay, iNaturalist), phenology charts
- **AI false-positive checker** (LLM-based; currently observational-only after evidence of unreliability)
- Substantial UI/UX changes throughout

Most PerchPi-specific code lives in `.local-patches/` (design docs and deployment scripts) and in `scripts/`, `homepage/`, `perch/`, and `geomodel/`.

## Lineage

```
BirdNET (Kahl et al. 2021, Cornell K. Lisa Yang Center for Conservation Bioacoustics)
  └─ BirdNET-Pi (mcguirepr89; original Raspberry Pi port)
       └─ BirdNET-Pi fork (Nachtzuster; immediate upstream of PerchPi)
            └─ PerchPi (this repo)
```

## License

CC BY-NC-SA 4.0 — inherited from BirdNET-Lite (Cornell) via the BirdNET-Pi chain. The ShareAlike clause requires PerchPi to use the same license. The NonCommercial clause restricts commercial use of derived works (no hardware bundles, no paid hosted service, no paid distribution).

See `LICENSE` for the full text.

## Maintainer

Jason Church
