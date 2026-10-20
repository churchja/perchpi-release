# PerchPi

A bird audio detection and identification station for the Raspberry Pi 5, derived from [BirdNET-Pi](https://github.com/Nachtzuster/BirdNET-Pi). It listens through a USB microphone, identifies birds with BirdNET and Google's Perch model, and shows them on a web UI and an optional touchscreen kiosk.

## Status

**v1** is the first public release: a ready-to-flash Raspberry Pi OS (trixie) image for the Raspberry Pi 5. It is a hobby project maintained by one person; expect rough edges (see Known issues).

## Hardware

- Raspberry Pi 5 (8 GB recommended) with its official power supply
- microSD card, 32 GB or larger (recordings are stored on the card)
- USB microphone
- Optional: an HDMI touchscreen for the kiosk display (the developer's station uses an 8" 1280×800 panel)
- Boots from SD card. NVMe via the official M.2 HAT+ runs on the developer's station but has not been tested from this image.

## Install with Raspberry Pi Imager

You need **Raspberry Pi Imager 2.0 or newer**. PerchPi is installed through its own Imager repository, so that Imager offers the customisation step (hostname, user, Wi-Fi, SSH). Imager 2.x offers no customisation for "Use custom" files, so a card written that way boots with no login and no Wi-Fi.

1. Open Imager → **App Options** → **Content Repository** → **Edit** → **Use custom URL**, and paste:
   ```
   https://github.com/churchja/perchpi-release/releases/latest/download/perchpi-os-list.json
   ```
   (Command line: `rpi-imager --repo https://github.com/churchja/perchpi-release/releases/latest/download/perchpi-os-list.json`)
2. Device: **Raspberry Pi 5** → OS: **PerchPi v1** → your card.
3. In the customisation step, set a hostname, a username and password, Wi-Fi and its country, your time zone, and enable SSH (a public key is best).
4. Write. Put the card in the Pi, plug in the microphone, and power on. The first boot resizes the card and reboots; give it a few minutes.

Imager verifies the written image against its sha256 by itself. For a manual download, the release notes list the image file, its size and both sha256s (the `.img.xz` download and the extracted `.img`), and the repository commit the image was built from.

## First boot

- Open `http://<your-hostname>.local/` in a browser.
- Log in with **`birdnet` / `birdnet`**, then change the password under **Settings → Advanced**.
- **Set your location** under Settings. The image ships with the Cornell Lab of Ornithology's coordinates (42.4800, −76.4510), so species likelihood and weather are wrong until you change them.
- The time zone comes from Imager's customisation. If you skipped it, the image defaults to America/Chicago.

## Known issues (v1)

- Photo uploads larger than 2 MB fail (PHP's default upload limit). Fix planned.
- The web terminal ships turned off. Use SSH.
- There is no in-app Update. Update by flashing a newer image.
- No File Manager or Adminer.
- The Perch geomodel is on by default.
- Imager's first-boot settings, including your password hash, stay in `/boot/firmware/user-data` on the card, as on any Raspberry Pi OS card written by Imager.
- `newinstaller.sh` (the from-scratch installer this image was built with) has no error trap: when a step fails it exits without a message, so check its log.

## What's different from BirdNET-Pi

PerchPi adds:

- **Embedding-based false-positive gate** using Google's Perch v2 model
- **Geomodel** for species likelihood by location and week-of-year
- **Quarantine review system** with audit logging for human-labeled FP/TP decisions
- **Rich species profiles** with Wikipedia photos, external cross-links (eBird, Macaulay, iNaturalist), phenology charts
- **AI false-positive checker** (LLM-based; currently observational-only after evidence of unreliability)
- A touchscreen kiosk view and substantial UI/UX changes throughout

Most PerchPi-specific code lives in `scripts/`, `homepage/`, `perch/`, and `geomodel/`.

## Lineage

```
BirdNET (Kahl et al. 2021, Cornell K. Lisa Yang Center for Conservation Bioacoustics)
  └─ BirdNET-Pi (mcguirepr89; original Raspberry Pi port)
       └─ BirdNET-Pi fork (Nachtzuster; immediate upstream of PerchPi)
            └─ PerchPi (this repo)
```

## License

CC BY-NC-SA 4.0 — inherited from BirdNET-Lite (Cornell) via the BirdNET-Pi chain. The ShareAlike clause requires PerchPi to use the same license. The NonCommercial clause restricts commercial use of derived works (no hardware bundles, no paid hosted service, no paid distribution).

See `LICENSE` for the full text and `NOTICE` for the attribution chain and the licences of bundled components.

## Maintainer

Jason Church
