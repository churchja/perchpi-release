# PerchPi

A bird audio detection and identification station for the Raspberry Pi 5, derived from [BirdNET-Pi](https://github.com/Nachtzuster/BirdNET-Pi). It listens through a USB microphone, identifies birds with BirdNET and Google's Perch model, and shows them on a web UI and an optional touchscreen kiosk.

## Status

**v1** is the first public release: a ready-to-flash Raspberry Pi OS (trixie) image for the Raspberry Pi 5. It is a hobby project maintained by one person; expect rough edges (see Known issues).

**New here? Start with the [Setup Guide](docs/SETUP-GUIDE.md).** It goes step by step from a box of parts to your first detected bird. For everyday use, see the [User Manual](docs/USER-MANUAL.md). Help: **perchpi@shadyacres.my**.

## Hardware

**Strongly recommended:** the exact parts PerchPi is built and tested with. Full list with links in the [Setup Guide](docs/SETUP-GUIDE.md#1-what-you-need).

- Raspberry Pi 5, **8 GB** (tested), with the official **Active Cooler** and the official **27W USB-C power supply**
- Samsung microSD card, **64 GB** (128 GB also good). Recordings and the database live on the card.
- **Clippy EM272 Mono Microphone** (Micbooster, 3.5 mm plug) in the red mic jack of a **UGREEN US205** USB sound adapter

> **⚠ Use this microphone and adapter.** With a different microphone, detection may be severely degraded or nearly useless. v1 is also pre-set for the UGREEN US205; with a different USB adapter the station may record nothing at all.

Optional: an HDMI touchscreen for the kiosk display (tested: Howens CX080PI-A, 8", 1280×800), and the 3D-printed microphone hood in [`hardware/em272-hood`](hardware/em272-hood). NVMe via the official M.2 HAT+ runs on the developer's station but hasn't been tested from this image.

## Install with Raspberry Pi Imager

You need **Raspberry Pi Imager 2.0 or newer** (https://www.raspberrypi.com/software/). PerchPi installs through its own Imager repository, so Imager offers the customisation step (hostname, user, Wi-Fi). **Don't write the image with "Use custom"**: Imager 2.x then skips customisation, and the card boots with no login and no Wi-Fi.

1. Point Imager at PerchPi, in either of these ways:
   - **Any computer:** in Imager, click **App Options** → next to **Content Repository**, click **Edit** → **Use custom URL** → paste the address below → **Apply & Restart**.
     ```
     https://github.com/churchja/perchpi-release/releases/latest/download/perchpi-os-list.json
     ```
   - **Mac, from Terminal:**
     ```
     osascript -e 'quit app "Raspberry Pi Imager"'; sleep 2; open -a "Raspberry Pi Imager" --args --repo https://github.com/churchja/perchpi-release/releases/latest/download/perchpi-os-list.json
     ```
   - **Linux, from a terminal:** `rpi-imager --repo https://github.com/churchja/perchpi-release/releases/latest/download/perchpi-os-list.json`
2. Choose **Raspberry Pi 5** → **PerchPi v1** → your microSD card.
3. In the customisation step, set a hostname, a username and password, Wi-Fi and its country, and your time zone. SSH is optional.
4. Write. Put the card in the Pi, plug in the microphone, and power on. The first start enlarges the storage and restarts once by itself; allow about 5 minutes.

Imager checks the written image against its sha256 by itself. For a manual download, the release notes list the image file, its size, both sha256s (the `.img.xz` download and the extracted `.img`), and the commit the image was built from.

## First boot

- Open `http://<your-hostname>.local/` in a browser.
- Log in with **`birdnet` / `birdnet`**, then change the password: gear icon (top right) → **Advanced Settings** → scroll to **BirdNET-Pi Password** → **Update Settings** (letters and numbers only).
- **Set your location:** gear icon → **Settings** → **Location** (at most 4 decimals) → **Update Settings**. The image ships with the Cornell Lab of Ornithology's coordinates (42.4800, −76.4510), so species likelihood and weather are wrong until you change them.
- The time zone comes from Imager's customisation. If you skipped it, the image defaults to America/Chicago.

## Known issues (v1)

- Photo uploads larger than 2 MB fail (PHP's default upload limit). Fix planned.
- The web terminal ships turned off. Use SSH.
- There is no in-app Update. Update by flashing a newer image.
- **Backup data** doesn't include the settings file (`birdnet.conf` is a symlink and the backup stores only the link), and **Restore data** can turn that link into a separate copy. Note your settings by hand; fix planned for v1.1.
- No File Manager or Adminer.
- The Perch geomodel is on by default.
- Imager's first-boot settings, including your password hash, stay in `/boot/firmware/user-data` on the card, as on any Raspberry Pi OS card written by Imager.
- `newinstaller.sh` (the from-scratch installer this image was built with) has no error trap: when a step fails it exits without a message, so check its log.
- The species-image API (`/api/v1/image/…`, which notifications use for bird photos) answers without a password to anyone who can reach the station. It returns only public Wikipedia image details, but a caller can make the station fetch from Wikipedia and add entries to its image cache. A password gate, which still lets the station itself through, is planned for v1.1.
- On the first boot, cloud-init's package step fails (`apt-get update` exits 100), so `cloud-init status` reports an error and `systemctl --failed` lists two cloud-init units. The station is unaffected.

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

Jason Church · perchpi@shadyacres.my
