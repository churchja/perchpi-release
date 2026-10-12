#!/usr/bin/env bash
# newinstaller.sh — PerchPi installer
#
# perchpi:newinstaller-v1
#
# Rewritten from scratch against .local-patches/INSTALLER-SPEC-20260717.md.
# This is NOT the upstream Nachtzuster/BirdNET-Pi installer with a URL swap —
# see spec §0 for why that fails silently. It installs *PerchPi's* stack:
# its own repo, its own package delta, its own services (19 units + 3 cron
# jobs), its own self-hosted model assets, its own Caddy routes.
#
# Run as a normal user with sudo access. Do NOT run this whole script under
# sudo — sudo sets HOME=/root, which breaks every path below (spec §2.7).
#
# Every phase name below (in square brackets) matches a spec section number,
# so a failure or a future edit can be cross-referenced directly.

set -euo pipefail

# ============================================================================
# Constants
# ============================================================================

PERCHPI_USER="birder"                       # spec §1: fixed, non-negotiable
PERCHPI_HOME="/home/${PERCHPI_USER}"
REPO_DIR="${PERCHPI_HOME}/BirdNET-Pi"
EXTRACTED="${PERCHPI_HOME}/BirdSongs/Extracted"
STREAMDATA="${PERCHPI_HOME}/BirdSongs/StreamData"
PROCESSED="${PERCHPI_HOME}/BirdSongs/Processed"

# perchpi:s21a2-d7-v1 (D7): installs come from the release repo, an export of the dev repo
# without .local-patches/, the dev files or LIVE's lock list. Its releases carry the assets.
REPO_URL="https://github.com/churchja/perchpi-release.git"
# No code-release tag has been cut yet (this installer is that milestone).
# Spec §5 requires pinning to a release tag, not `main`, once one exists.
# TODO: set PERCHPI_REF to the first tag when v1 ships; until then this
# loudly announces that it is not yet pinned rather than silently drifting.
PERCHPI_REF="${PERCHPI_REF:-main}"

MODEL_RELEASE_REPO="churchja/perchpi-release"
MODEL_RELEASE_TAG="model-assets-v1"

MIN_FREE_BYTES=$((8 * 1024 * 1024 * 1024))   # spec §2.4: 8 GB, not the old 20 GB
REQUIRED_PY_MAJOR_MINOR="3.13"

LOG_PREFIX="[newinstaller]"

# ============================================================================
# Logging / abort helpers
# ============================================================================

log()  { echo "${LOG_PREFIX} $*"; }
step() { echo; echo "${LOG_PREFIX} === $* ==="; }

# Every preflight/verify check aborts with a named reason — spec §2, §13:
# "no check may warn-and-continue."
abort() {
    echo "${LOG_PREFIX} ABORT: $*" >&2
    exit 1
}

# ============================================================================
# [§2] Preflight — fail fast, fail loud
# ============================================================================

preflight() {
    step "§2 Preflight"

    [ "${EUID:-$UID}" -ne 0 ] || abort \
        "Do not run this script with sudo/as root — sudo sets HOME=/root and" \
        " every path in this installer is HOME-relative. Run as a normal user;" \
        " the script calls sudo internally where needed (§2.7)."

    [ "$(uname -m)" = "aarch64" ] || abort \
        "Unsupported architecture $(uname -m). PerchPi requires aarch64 —" \
        " the shipped tflite wheel is linux_aarch64 only (§2.1, §6 D5)."

    if [ -r /etc/os-release ]; then
        . /etc/os-release
        [ "${VERSION_CODENAME:-}" = "trixie" ] || abort \
            "OS codename '${VERSION_CODENAME:-unknown}' is not the pinned release" \
            " (trixie). PerchPi is pinned to the Debian release that ships" \
            " Python 3.13, because the tflite wheel is cp313-only. A newer OS" \
            " with Python 3.14 breaks the install with an unhelpful wheel" \
            " error (§2.2, §6 D5)."
    else
        abort "/etc/os-release not found; cannot verify OS release."
    fi

    local py_ver
    py_ver=$(python3 -c 'import sys; print(f"{sys.version_info[0]}.{sys.version_info[1]}")' 2>/dev/null) \
        || abort "python3 not found."
    [ "${py_ver}" = "${REQUIRED_PY_MAJOR_MINOR}" ] || abort \
        "python3 is ${py_ver}, need ${REQUIRED_PY_MAJOR_MINOR} exactly (§2.3, §6 D5)."

    local avail_bytes
    avail_bytes=$(df --output=avail -B1 / | tail -1 | tr -d ' ')
    [ "${avail_bytes}" -ge "${MIN_FREE_BYTES}" ] || abort \
        "Only $((avail_bytes / 1024 / 1024)) MiB free on /; need at least 8 GiB." \
        " Clean install footprint is ~2.1 GB (venv 1.4 GB + models ~0.6 GB +" \
        " code ~0.1 GB); 8 GB covers that plus the apt layer and headroom (§2.4)." \
        " This does not include audio recordings — plan >=64 GB of separate" \
        " media storage for retention."

    for host in github.com pypi.org; do
        curl -fsS --max-time 10 -o /dev/null "https://${host}" || abort \
            "Cannot reach https://${host} — network is required for the apt" \
            " layer, venv packages, repo clone, and model assets (§2.5)."
    done

    # D-S7-4 (perchpi:installer-s16-mic-preflight-v1): §12's microphone step is the
    # first place a missing mic would otherwise surface, well into the run. Fail here,
    # before any prompt. /proc only -- alsa-utils is not installed until §3.
    compgen -G '/proc/asound/card*/pcm*c' >/dev/null || abort \
        "No audio capture device found. Plug in the USB microphone, check it" \
        " shows up in 'arecord -l', then re-run (§2, D-S7-4)."

    # D4 (perchpi:s21a-reinstall-flag-v1, audit C4): a reinstall moves the old tree aside and
    # starts a fresh database, so it takes an explicit flag instead of a y/N prompt.
    if id "${PERCHPI_USER}" &>/dev/null \
        && { [ -d "${REPO_DIR}/.git" ] || [ -f /etc/birdnet/birdnet.conf ]; }; then
        log "Existing install detected (${REPO_DIR} or /etc/birdnet/birdnet.conf)."
        [ "${PERCHPI_REINSTALL:-0}" = "1" ] || abort \
            "Refusing to reinstall over an existing station. Re-run with PERCHPI_REINSTALL=1" \
            " to move the old install aside and start fresh; detections are not carried over (§2.6, D4)."
        log "PERCHPI_REINSTALL=1: proceeding; the old tree is moved aside."
    fi

    if ! sudo -v; then
        abort "This user does not have sudo access, or the sudo password was" \
            " not accepted. The installer calls sudo internally for package" \
            " installs, service/Caddy/systemd config, and user creation (§2.7)."
    fi
    # Keep the sudo credential cache warm for the life of this (long-running)
    # script instead of re-prompting mid-apt-install.
    ( while true; do sudo -n -v; sleep 60; kill -0 "$$" 2>/dev/null || exit; done ) &
    SUDO_KEEPALIVE_PID=$!
    trap 'kill "${SUDO_KEEPALIVE_PID}" 2>/dev/null || true' EXIT

    log "Preflight passed."
}

# ============================================================================
# [§3] System packages (apt layer)
# ============================================================================

install_apt_packages() {
    step "§3 System packages"

    sudo apt-get update -qq

    # Caddy is not in Debian; add its apt repo first.
    if ! apt-cache policy caddy 2>/dev/null | grep -q cloudsmith; then
        sudo apt-get install -y -qq apt-transport-https debian-keyring
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
            | sudo gpg --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
        # S16 sweep (perchpi:installer-s16-caddy-key-pin-v1): apt checks Caddy's packages
        # against this key, but the key itself arrived with nothing pinning it. Pin its
        # fingerprint (read from LIVE's installed keyring and a fresh fetch, S16).
        local caddy_fpr gpg_home
        gpg_home=$(mktemp -d)
        caddy_fpr=$(gpg --homedir "${gpg_home}" --show-keys --with-colons \
                        /usr/share/keyrings/caddy-stable-archive-keyring.gpg 2>/dev/null \
                    | awk -F: '$1 == "fpr" { print $10; exit }')
        rm -rf "${gpg_home}"
        if [ "${caddy_fpr}" != "65760C51EDEA2017CEA2CA15155B6D79CA56EA34" ]; then
            sudo rm -f /usr/share/keyrings/caddy-stable-archive-keyring.gpg
            abort "Caddy apt key fingerprint '${caddy_fpr}' is not the pinned key (§3)."
        fi
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
            | sudo tee /etc/apt/sources.list.d/caddy-stable.list >/dev/null
        sudo apt-get update -qq
    fi

    # icecast2 is dropped (§12.11) so it must never be pulled in transitively
    # or prompt a debconf dialog if something else drags it in.
    echo "icecast2 icecast2/icecast-setup boolean false" | sudo debconf-set-selections

    # The actual stack, per the 2026-08-02 apt-mark showmanual diff (§3).
    # Four corrections vs. the old pre-recon list are folded in already:
    # unversioned php metapackages (not php8.4-*), ethtool dropped (Desktop
    # baseline already has it), and the nine packages the old list missed.
    local packages=(
        caddy
        # perchpi:s21a-php-cli-v1 (S20 §7): php-cli, not the php metapackage, which pulled in
        # libapache2-mod-php8.4.
        php-cli php-fpm php-curl php-imagick php-mbstring php-sqlite3 php-xml php-zip
        sqlite3
        ffmpeg
        alsa-utils
        sox libsox-fmt-mp3
        imagemagick
        bc jq inotify-tools lsof wget
        git  # perchpi:s21a-git-v1 (D-S20-1): Pi OS Lite ships without it; §5 clones the repo
        avahi-utils
        python3-pip python3-dev libsndfile1
    )
    sudo apt-get install -y -qq --no-install-recommends "${packages[@]}"

    log "Core apt layer installed (${#packages[@]} packages)."
}

# First-boot-conditional packages — installed later, only if the fullscreen
# display path is chosen (§12.9). Capture is pure ALSA dsnoop and needs no sound server.
# D-S20-2 (perchpi:s21a-no-pulse-v1): no pulseaudio daemon. It raced the recorder for the
# USB mic at every start. pactl (the HDMI volume boost, §12.9) comes from pulseaudio-utils
# and talks to the Desktop's own PipeWire pulse server.
install_display_packages() {
    sudo apt-get install -y -qq --no-install-recommends pulseaudio-utils fonts-noto-color-emoji
}

# ============================================================================
# [§4] User, directories, permissions
# ============================================================================

create_user_and_dirs() {
    step "§4 User + directories"

    if ! id "${PERCHPI_USER}" &>/dev/null; then
        sudo useradd -m -s /bin/bash "${PERCHPI_USER}"
        log "Created user ${PERCHPI_USER}."
    fi
    sudo usermod -aG "${PERCHPI_USER}" caddy 2>/dev/null || true

    # D-S6-1 (perchpi:installer-s7b-20260813): Debian adduser/useradd honours
    # DIR_MODE=0700, so ${PERCHPI_HOME} is created traversable only by
    # birder. Two things break, both silently:
    #   1. §5's NOTICE test runs as the *invoking* user and fails on
    #      permission, reporting the file as missing when it is present.
    #   2. Caddy serves ${EXTRACTED} as the caddy user and 403s every
    #      page -- an install that "succeeds" and serves nothing.
    # Do NOT fix this by sudo-ing the test calls: that repairs the check
    # and leaves Caddy broken, which is strictly worse than an abort.
    sudo chmod 755 "${PERCHPI_HOME}"

    # D-S7-2 (perchpi:installer-s7b-20260813): the service user must be in the audio
    # group to open the ALSA capture device; without it
    # birdnet_recording fails at runtime, after a clean install.
    sudo usermod -aG audio "${PERCHPI_USER}"

    # D-S8-1 (perchpi:installer-s9-20260906): the service user must ALSO be a
    # MEMBER of the caddy group. setgid on scripts/ (D-S6-8b) makes new WAL
    # sidecars inherit the caddy group, but group-write only helps birder if
    # birder is in that group. Membership is what makes open-order irrelevant;
    # without it the 2026-08-30 outage recurs (analyzer read-only for 17 days
    # after php-fpm recreated the sidecars caddy:caddy).
    sudo usermod -aG caddy "${PERCHPI_USER}"

    # D3 / B6 (perchpi:installer-s19a-journal-group-v1): birdnet_log.sh and the flatline
    # watchdog read the journal. On LIVE birder was the Imager user (in adm); here it is
    # not, so /log was blank and the watchdog's journal checks never fired.
    sudo usermod -aG systemd-journal "${PERCHPI_USER}"

    sudo -u "${PERCHPI_USER}" mkdir -p \
        "${REPO_DIR}" \
        "${EXTRACTED}/By_Date" "${EXTRACTED}/Charts" \
        "${STREAMDATA}" "${PROCESSED}"

    # Must be /var/lib, not /tmp — caddy.service has PrivateTmp=yes, so /tmp
    # is invisible to Caddy even though it's visible to the writer (§4).
    sudo mkdir -p /var/lib/perchpi-hls /var/lib/perchpi-weather
    sudo chown "${PERCHPI_USER}:${PERCHPI_USER}" /var/lib/perchpi-hls /var/lib/perchpi-weather

    log "Directories ready."
}

# ============================================================================
# [§5] Repository
# ============================================================================

clone_repo() {
    step "§5 Repository (ref: ${PERCHPI_REF})"

    if [ "${PERCHPI_REF}" = "main" ]; then
        log "WARNING: no release tag exists yet — cloning 'main'. Pin this to" \
            " a tag once v1 ships (spec §5: 'pin to a release tag, not main')."
    fi

    # Cloned as the invoking user (plain git, no sudo) so it uses THEIR
    # working GitHub credentials — churchja/perchpi is private, and birder
    # itself is never expected to have any GitHub auth of its own (§2 only
    # assumes the invoking user has sudo, not a repo credential). Clone to a
    # scratch dir and swap it into place rather than fetching in-place as
    # root, which would need root's own separate credential store (root's
    # HOME=/root during sudo — the same pitfall §2.7 already warns about).
    local tmp_clone
    tmp_clone=$(mktemp -d)
    git clone --branch "${PERCHPI_REF}" --depth 1 "${REPO_URL}" "${tmp_clone}/repo" || abort \
        "git clone of ${REPO_URL} failed. This repo is private — the user" \
        " running this installer needs working GitHub credentials (e.g." \
        " 'gh auth login', which also configures git's credential helper)." \
        " birder itself does not need any credential (§5)."

    if [ -d "${REPO_DIR}/.git" ]; then
        sudo mv "${REPO_DIR}" "${REPO_DIR}.bak-$(date +%Y%m%d-%H%M%S)"
    else
        sudo rmdir "${REPO_DIR}" 2>/dev/null || true
    fi
    sudo mv "${tmp_clone}/repo" "${REPO_DIR}"
    rmdir "${tmp_clone}" 2>/dev/null || true
    sudo chown -R "${PERCHPI_USER}:${PERCHPI_USER}" "${REPO_DIR}"
    # perchpi:s21a2-f9-v1 (F9/G4): the UI lock list is per-station data, so the release repo
    # doesn't carry one. play.php, perchpi-retention.py and disk_species_clean.sh read it.
    if [ ! -f "${REPO_DIR}/scripts/disk_check_exclude.txt" ]; then
        printf '##start\n##end\n' | sudo -u "${PERCHPI_USER}" tee "${REPO_DIR}/scripts/disk_check_exclude.txt" >/dev/null
    fi

    # D-S6-3 (perchpi:installer-s7b-20260813): the tflite wheel is gitignored
    # (.gitignore: *.whl) so it can never arrive in a clone, and the
    # mv-aside above carries the previous copy off with the old
    # directory. First install survives on luck (empty dir); every
    # REinstall wipes the file §6 aborts without. Restore it from the
    # displaced tree before §6 looks. D-S6-2 has since landed
    # (§6 fetches it as a checksum-pinned release asset); this restore now just saves a
    # 50 MB re-download on reinstalls.
    local _prev_tree _whl
    _prev_tree=$(ls -1d "${REPO_DIR}".bak-* 2>/dev/null | tail -1 || true)
    if [ -n "${_prev_tree}" ]; then
        for _whl in "${_prev_tree}"/*.whl; do
            [ -e "${_whl}" ] || continue
            sudo cp -p "${_whl}" "${REPO_DIR}/"
            sudo chown "${PERCHPI_USER}:${PERCHPI_USER}" "${REPO_DIR}/$(basename "${_whl}")"
            log "Restored $(basename "${_whl}") from the previous tree."
        done
    fi

    # NOTICE is load-bearing for v1, not housekeeping (§5): CC BY-NC-SA 4.0 is
    # BY-and-SA, so the full licence text must ship with the model, not a link.
    [ -f "${REPO_DIR}/NOTICE" ] || abort \
        "NOTICE missing from the cloned repo — this is a licence-compliance" \
        " blocker (§5), not a cosmetic gap. Refusing to continue."

    log "Repo at $(sudo -u "${PERCHPI_USER}" git -C "${REPO_DIR}" rev-parse --short HEAD)."
}

# ============================================================================
# [§6] Python venv — per-arch requirements selection
# ============================================================================

setup_venv() {
    step "§6 Python venv"

    local venv="${REPO_DIR}/birdnet"
    sudo -u "${PERCHPI_USER}" python3 -m venv "${venv}"

    local pip="${venv}/bin/pip"
    sudo -u "${PERCHPI_USER}" "${pip}" install --quiet --upgrade pip

    # requirements.txt and requirements_custom.txt are NOT duplicates — they
    # are mutually exclusive backends, differing only in line 1 (tensorflow
    # vs. the tflite wheel). scripts/utils/models.py try/excepts between them.
    # On aarch64 the rule is always requirements_custom.txt (§6).
    local req="${REPO_DIR}/requirements_custom.txt"
    [ -f "${req}" ] || abort "requirements_custom.txt not found in the cloned repo."

    # D5 fix #1: line 1 is a bare wheel filename with no path, so pip
    # resolves it against CWD — the old installer silently depended on
    # running from the repo root. Install it explicitly by absolute path
    # first, then install the rest of the file with that line stripped
    # (install-time only; the tracked file is untouched).
    ensure_tflite_wheel
    local wheel="${REPO_DIR}/${TFLITE_WHEEL}"
    sudo -u "${PERCHPI_USER}" "${pip}" install --quiet "${wheel}"

    sudo -u "${PERCHPI_USER}" bash -c \
        "grep -v '^tflite_runtime' '${req}' | '${pip}' install --quiet -r /dev/stdin"

    # §6: the two system-Python cron scripts must stay stdlib-only forever —
    # record the constraint at the top of both files so a future maintainer
    # adding e.g. `import requests` doesn't break every fresh install while
    # the live station (with an accumulated system Python) keeps working.
    for f in "${REPO_DIR}/scripts/process_ai_queue.py" \
             "${REPO_DIR}/watchdog/detection_flatline_watchdog.py"; do
        if [ -f "${f}" ] && ! grep -q "perchpi:system-python-stdlib-only" "${f}"; then
            sudo -u "${PERCHPI_USER}" python3 - "${f}" <<'PYEOF'
import sys
path = sys.argv[1]
with open(path) as fh:
    lines = fh.readlines()
marker = (
    "# perchpi:system-python-stdlib-only — this runs under /usr/bin/python3\n"
    "# (system interpreter), NOT the birdnet venv. Keep imports stdlib-only.\n"
    "# A fresh install's system Python has none of the venv's packages; the\n"
    "# live station's system Python has accumulated extras by luck. Adding a\n"
    "# non-stdlib import here breaks every clean install while looking fine\n"
    "# on the box that already has it (spec §6).\n"
)
insert_at = 1 if lines and lines[0].startswith("#!") else 0
lines[insert_at:insert_at] = [marker]
with open(path, "w") as fh:
    fh.writelines(lines)
PYEOF
        fi
    done

    log "Venv ready: $("${venv}/bin/python3" --version)."
}

# ============================================================================
# [§7] Models & assets — self-hosted release, checksum-verified
# ============================================================================

# name -> sha256, verified by independent fresh-download+rehash 2026-08-04
# (see .local-patches/INSTALLER-SPEC-20260717.md §7). Never trust a truncated
# hash — full 64-char hashes only, matching this project's own established
# discipline after catching two transcription typos in a truncated table.
declare -A MODEL_ASSETS=(
    [BirdNET_6K_GLOBAL_MODEL.tflite]=f073d0aa626b4a5bb0705982d06de74c562f041ba76ebada18bc3e29126f6755
    [BirdNET_GLOBAL_6K_V2.4_Model_FP16.tflite]=5c64ba3ffab264ccbfafe784896392051a86be63b0cc507e7683db7df114546b
    [BirdNET_GLOBAL_6K_V2.4_MData_Model_V2_FP16.tflite]=1226f23fc20362617deb09178f366111b70cf085c2c82893f2814e5acedce6c2
    [BirdNET_GLOBAL_6K_V2.4_MData_Model_FP16.tflite]=4813567791d2fe2f38fb9e195e61a6261141a6f3b134b3056b6b062d22ac88f5
    [perch_v2.onnx]=4dcf71c18a147198545944bb5149697e89e3ad2e16637fa8f0edf6d13035a017
    [labels.csv]=e4d5c0397d8fb08bf90c6b13a34810af53504faad927e472fcc567793c9de057
    [tflite_runtime-2.17.1-cp313-cp313-linux_aarch64.whl]=bc5531deb0a36cacef481f9dbdabf097708f58a6486b8bbfed2823774a455157
)
# Destination path relative to REPO_DIR for each asset above.
declare -A MODEL_DEST=(
    [BirdNET_6K_GLOBAL_MODEL.tflite]="model/BirdNET_6K_GLOBAL_MODEL.tflite"
    [BirdNET_GLOBAL_6K_V2.4_Model_FP16.tflite]="model/BirdNET_GLOBAL_6K_V2.4_Model_FP16.tflite"
    [BirdNET_GLOBAL_6K_V2.4_MData_Model_V2_FP16.tflite]="model/BirdNET_GLOBAL_6K_V2.4_MData_Model_V2_FP16.tflite"
    [BirdNET_GLOBAL_6K_V2.4_MData_Model_FP16.tflite]="model/BirdNET_GLOBAL_6K_V2.4_MData_Model_FP16.tflite"
    [perch_v2.onnx]="perch/perch_v2.onnx"
    [labels.csv]="perch/labels.csv"
    [tflite_runtime-2.17.1-cp313-cp313-linux_aarch64.whl]="tflite_runtime-2.17.1-cp313-cp313-linux_aarch64.whl"
)

# D-S6-2 (perchpi:installer-s13-wheel-v1): the tflite wheel is a checksum-pinned release asset like
# the models (spec §7). It is gitignored (*.whl), so a first install never has it in the
# clone. Public upstream for strangers: Nachtzuster/BirdNET-Pi release v0.1 (spec §7).
TFLITE_WHEEL="tflite_runtime-2.17.1-cp313-cp313-linux_aarch64.whl"

# Fetches one release asset with a three-tier fallback, in order of least to
# most infrastructure required:
#   1. plain anonymous curl on the public release URL — works with ZERO
#      installer changes the day churchja/perchpi goes public (§5's stated
#      precondition for strangers). This is deliberately tried first.
#   2. `gh release download`, if gh is installed and authenticated — this is
#      Jason's own path today, while the repo is private.
#   3. GitHub API + a GITHUB_TOKEN/GH_TOKEN env var — a private-repo path
#      that needs neither the gh CLI nor interactive auth.
# Every path converges on the same checksum verification (§7's design rule:
# "models are fetched with checksum verification, never assumed").
fetch_release_asset() {
    local name="$1" sha="$2" dest="$3"
    local url="https://github.com/${MODEL_RELEASE_REPO}/releases/download/${MODEL_RELEASE_TAG}/${name}"
    local tmp
    tmp=$(mktemp)

    if curl -fsSL --max-time 300 -o "${tmp}" "${url}"; then
        log "  fetched ${name} via public URL."
    elif command -v gh &>/dev/null && gh auth status &>/dev/null; then
        gh release download "${MODEL_RELEASE_TAG}" -R "${MODEL_RELEASE_REPO}" \
            -p "${name}" -O "${tmp}" --clobber
        log "  fetched ${name} via gh CLI (repo is private)."
    elif [ -n "${GITHUB_TOKEN:-${GH_TOKEN:-}}" ]; then
        local token="${GITHUB_TOKEN:-${GH_TOKEN:-}}"
        local asset_id
        asset_id=$(curl -fsSL -H "Authorization: token ${token}" \
            "https://api.github.com/repos/${MODEL_RELEASE_REPO}/releases/tags/${MODEL_RELEASE_TAG}" \
            | python3 -c "import json,sys; a=[x['id'] for x in json.load(sys.stdin)['assets'] if x['name']=='${name}']; print(a[0] if a else '')")
        [ -n "${asset_id}" ] || abort "Could not resolve asset id for ${name} via the API."
        curl -fsSL -H "Authorization: token ${token}" -H "Accept: application/octet-stream" \
            -o "${tmp}" \
            "https://api.github.com/repos/${MODEL_RELEASE_REPO}/releases/assets/${asset_id}"
        log "  fetched ${name} via GitHub API + token (repo is private)."
    else
        rm -f "${tmp}"
        abort "Could not fetch model asset '${name}'. ${MODEL_RELEASE_REPO} is" \
            " private, the public URL 404s, and neither an authenticated" \
            " gh CLI nor GITHUB_TOKEN/GH_TOKEN is available. See spec §7."
    fi

    local got
    got=$(sha256sum "${tmp}" | cut -d' ' -f1)
    if [ "${got}" != "${sha}" ]; then
        rm -f "${tmp}"
        abort "Checksum mismatch on ${name}: expected ${sha}, got ${got}." \
            " A silently-truncated model download is the worst class of" \
            " install failure — the station runs and produces garbage (§7)."
    fi

    sudo -u "${PERCHPI_USER}" mkdir -p "$(dirname "${dest}")"
    sudo mv "${tmp}" "${dest}"
    sudo chown "${PERCHPI_USER}:${PERCHPI_USER}" "${dest}"
}

# Makes sure the pinned tflite wheel is present and verified before §6 builds the venv.
# main() runs setup_venv before fetch_models, so leaving the wheel to the MODEL_ASSETS loop
# alone would abort every first install (D-S6-2). fetch_models still re-verifies it after.
ensure_tflite_wheel() {
    local wheel="${REPO_DIR}/${TFLITE_WHEEL}"
    local want="${MODEL_ASSETS[${TFLITE_WHEEL}]:-}"
    [ -n "${want}" ] || abort "No MODEL_ASSETS checksum for ${TFLITE_WHEEL} (installer bug)."
    if [ -f "${wheel}" ] && [ "$(sudo sha256sum "${wheel}" | cut -d' ' -f1)" = "${want}" ]; then  # D-S21-2 perchpi:installer-s21b-sha-sudo-v1
        log "  ${TFLITE_WHEEL} already present and verified."
        return 0
    fi
    log "Fetching ${TFLITE_WHEEL} (release asset, D-S6-2)..."
    fetch_release_asset "${TFLITE_WHEEL}" "${want}" "${wheel}"
}

# D-S7-8 (perchpi:installer-s16-gotty-v1): gotty serves /log and /terminal (§10). It is a
# compiled binary, not a repo script, so the §10 farm (scripts/*.sh, *.py) cannot link it.
# Self-hosted on model-assets-v1 like the wheel; aarch64 only, since §2 aborts on anything
# else.
# perchpi:s21a-gotty-v1 (S19 §5, option a): sorenisanerd/gotty v1.8.0 (MIT), the linux arm64
# binary from gotty_v1.8.0_linux_arm64.tar.gz (sha256 fcef4efcf6cbdf81540c765ebdfd533ada86f7d58322b4f831573d098712b0dd,
# matches upstream SHA256SUMS), re-hosted on model-assets-v1. It replaces the
# fork.dabble.be build, whose copyright holder can't be traced. Same flags (-a/-p/--path/-w).
GOTTY_ASSET="gotty-v1.8.0-aarch64"
GOTTY_SHA256="e2e1b868cfecda63402ae56c22f0414e367a04cf1d3f16c02cae6cd68fd911aa"

ensure_gotty() {
    local dest="/usr/local/bin/gotty"
    if [ -x "${dest}" ] && [ "$(sha256sum "${dest}" | cut -d' ' -f1)" = "${GOTTY_SHA256}" ]; then
        log "  gotty already present and verified, skipping."
        return 0
    fi
    log "Fetching ${GOTTY_ASSET} (release asset, D-S7-8)..."
    local stage="/tmp/perchpi-${GOTTY_ASSET}"
    fetch_release_asset "${GOTTY_ASSET}" "${GOTTY_SHA256}" "${stage}"
    # install(1) unlinks an existing dest first, so a stale symlink (LIVE's layout) is
    # replaced rather than written through.
    sudo install -m 0755 -o root -g root "${stage}" "${dest}"
    sudo rm -f "${stage}"
}

fetch_models() {
    step "§7 Models & assets"

    cd "${REPO_DIR}"
    for name in "${!MODEL_ASSETS[@]}"; do
        local dest="${REPO_DIR}/${MODEL_DEST[${name}]}"
        if [ -f "${dest}" ] && [ "$(sudo sha256sum "${dest}" | cut -d' ' -f1)" = "${MODEL_ASSETS[${name}]}" ]; then  # D-S21-2 perchpi:installer-s21b-sha-sudo-v1
            log "  ${name} already present and verified, skipping."
            continue
        fi
        log "Fetching ${name}..."
        fetch_release_asset "${name}" "${MODEL_ASSETS[${name}]}" "${dest}"
    done

    fetch_geomodel
    ensure_gotty

    # NOTICE must live alongside the model, not just at repo root (§5, §12.10)
    # — "alongside the model" is satisfied literally.
    sudo -u "${PERCHPI_USER}" cp "${REPO_DIR}/NOTICE" "${REPO_DIR}/model/NOTICE"

    log "All model assets present and checksum-verified."
}

# Geomodel is unchanged from deploy-geomodel.sh's already-working, tested
# logic (§7: "official, stable, checksum-verifiable GitHub Releases URL, no
# self-hosting needed"). The release asset names have a URL-encoded '+' and
# don't match the simple names the codebase expects, hence the rename.
fetch_geomodel() {
    local geomodel_dir="${REPO_DIR}/geomodel"
    local tag="v3.0.2"
    local base="https://github.com/birdnet-team/geomodel/releases/download/${tag}"
    local onnx_name="BirdNET%2B_Geomodel_V3.0.2_Global_12K_FP16.onnx"
    local labels_name="BirdNET%2B_Geomodel_V3.0.2_Global_12K_Labels.txt"
    # S16 sweep (perchpi:installer-s16-geomodel-sha-v1): pinned like every other asset (§7).
    local onnx_sha="2bc5a9b1e7c24115730015a97dbb688e9e8cd49c02c34a011439182c65ef0017"
    local labels_sha="c15818db07e55978d909a9bcd916cd0615b0183f789227d9516059151787c784"

    sudo -u "${PERCHPI_USER}" mkdir -p "${geomodel_dir}"

    if [ ! -f "${geomodel_dir}/geomodel_fp16.onnx" ]; then
        log "Fetching geomodel_fp16.onnx (${tag})..."
        curl -fL --retry 3 --retry-delay 2 -o /tmp/geomodel_fp16.onnx.tmp "${base}/${onnx_name}"
        [ "$(sha256sum /tmp/geomodel_fp16.onnx.tmp | cut -d' ' -f1)" = "${onnx_sha}" ] || abort \
            "Checksum mismatch on geomodel_fp16.onnx (${tag}) (§7)."
        # D-S6-4 (perchpi:installer-s7b-20260813): curl above ran as the invoking user,
        # so the temp file is theirs; /tmp is 1777 and only the owner may
        # rename. Move as root, then hand ownership over.
        sudo mv /tmp/geomodel_fp16.onnx.tmp "${geomodel_dir}/geomodel_fp16.onnx"
        sudo chown "${PERCHPI_USER}:${PERCHPI_USER}" "${geomodel_dir}/geomodel_fp16.onnx"
    fi
    if [ ! -f "${geomodel_dir}/labels.txt" ]; then
        log "Fetching geomodel labels.txt (${tag})..."
        curl -fL --retry 3 --retry-delay 2 -o /tmp/geomodel_labels.txt.tmp "${base}/${labels_name}"
        [ "$(sha256sum /tmp/geomodel_labels.txt.tmp | cut -d' ' -f1)" = "${labels_sha}" ] || abort \
            "Checksum mismatch on geomodel labels.txt (${tag}) (§7)."
        sudo mv /tmp/geomodel_labels.txt.tmp "${geomodel_dir}/labels.txt"
        sudo chown "${PERCHPI_USER}:${PERCHPI_USER}" "${geomodel_dir}/labels.txt"
    fi
    echo "${tag}" | sudo -u "${PERCHPI_USER}" tee "${geomodel_dir}/VERSION" >/dev/null
}

# ============================================================================
# [§8] Caddy
# ============================================================================

configure_caddy() {
    step "§8 Caddy"

    sudo mkdir -p /etc/caddy
    [ -f /etc/caddy/Caddyfile ] && sudo cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.pre-perchpi

    local hashword
    hashword=$(caddy hash-password --plaintext "${CADDY_PWD}")

    # NOTE on the HLS block: the spec's §8 decision (2026-07-19) is LAN
    # allowlist + no ACAO + narrowed root, replacing an open/no-auth/no-CORS
    # config. Ship the decided shape here even though the live station (as
    # of 2026-08-04) still runs the old open block — this installer is
    # building the corrected station, not mirroring an unfixed live gap.
    sudo tee /etc/caddy/Caddyfile >/dev/null <<CADDYEOF
http:// {
	root * ${EXTRACTED}
	file_server browse
	handle /By_Date/* {
		basic_auth {
			birdnet ${hashword}
		}
		file_server browse
	}
	handle /Charts/* {
		basic_auth {
			birdnet ${hashword}
		}
		file_server browse
	}

	# perchpi:caddy-live-audio-route-v5 — LAN-only, no ACAO (spec §8 decision)
	# perchpi:installer-s19a-handle-path-v1 (D-S17-8, audit E6): handle_path strips the
	# prefix; plain handle made file_server look for /var/lib/perchpi-hls/perchpi-hls/...
	handle_path /perchpi-hls/* {
		@notlan not remote_ip 127.0.0.1/8 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 fc00::/7
		respond @notlan 403
		header Cache-Control "no-cache"
		root * /var/lib/perchpi-hls
		file_server
	}
	# perchpi:installer-s19a-handle-path-v1 weather
	handle_path /perchpi-weather/* {
		header Access-Control-Allow-Origin "*"
		header Cache-Control "no-cache"
		root * /var/lib/perchpi-weather
		file_server
	}
	@authviews {
		expression {query.view} in ['Settings', 'Advanced', 'System Controls', 'Services', 'Webterm', 'Included', 'Excluded', 'Whitelisted', 'Species Management', 'Quarantine', 'Notable Sightings']
	}
	basic_auth @authviews {
		birdnet ${hashword}
	}

	basic_auth /Processed* {
		birdnet ${hashword}
	}
	basic_auth /scripts* {
		birdnet ${hashword}
	}
	basic_auth /stream {
		birdnet ${hashword}
	}
	basic_auth /phpsysinfo* {
		birdnet ${hashword}
	}
	basic_auth /terminal* {
		birdnet ${hashword}
	}
	basic_auth /upload_user_photo.php {
		birdnet ${hashword}
	}
	basic_auth /remove_user_photo.php {
		birdnet ${hashword}
	}
	basic_auth /log* {
		birdnet ${hashword}
	}
	basic_auth /stats* {
		birdnet ${hashword}
	}
	reverse_proxy /stream localhost:8000
	php_fastcgi unix//run/php/php-fpm.sock
	reverse_proxy /log* localhost:8080
	reverse_proxy /stats* localhost:8501
	reverse_proxy /terminal* localhost:8888
}
CADDYEOF

    sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile \
        || abort "Generated Caddyfile failed validation."

    sudo mkdir -p /etc/systemd/system/caddy.service.d
    sudo tee /etc/systemd/system/caddy.service.d/override.conf >/dev/null <<'EOF'
[Service]
TimeoutSec=300s
EOF

    # PHP-FPM runs its pool as www-data by default; Caddy needs it as caddy.
    sudo sed -i 's/www-data/caddy/g' /etc/php/*/fpm/pool.d/www.conf
    sudo usermod -aG "${PERCHPI_USER}" caddy
    sudo usermod -aG video caddy
    sudo chmod g+rx "${PERCHPI_HOME}"

    sudo systemctl daemon-reload
    # D-S6-5 (perchpi:installer-s7b-20260813): systemctl does not expand globs and the
    # shell cannot either (no matching *filename*), so the literal
    # "php*-fpm" reaches systemd as an invalid unit name. §3 installs
    # UNversioned metapackages on purpose, but the resulting unit is
    # versioned (php8.4-fpm.service) -- resolve it at run time.
    local fpm_unit
    fpm_unit=$(systemctl list-unit-files --no-legend "php*-fpm.service" \
                 | awk '{print $1; exit}')
    [ -n "${fpm_unit}" ] || abort "No php*-fpm.service unit found after §3 (D-S6-5)."
    sudo systemctl enable --now caddy "${fpm_unit}"

    log "Caddy configured and validated."
}

# ============================================================================
# [§9] Web-root symlinks — the silent-200 trap
# ============================================================================

install_symlinks() {
    step "§9 Web-root symlinks"

    # Every homepage/*.php file, generated from a manifest (the glob), not
    # hand-copied — a missing symlink returns HTTP 200 + the index.php HTML
    # shell, not a 404, so an installer that misses one *looks* like it worked.
    # D-S6-12 (perchpi:installer-s7b-20260813): the old glob was homepage/*.php only, so
    # every non-PHP sibling (CSS, JS, images/) was never linked -- the
    # pages resolved and rendered unstyled. Link EVERY entry; §13
    # verifies by content-type, because a missing asset under this web
    # root returns 200 + the index.php HTML shell, not a 404.
    sudo -u "${PERCHPI_USER}" bash -c \
        "ln -sfn '${REPO_DIR}'/homepage/* '${EXTRACTED}'/"
    # D-S17-7 / audit X1 (perchpi:installer-s19a-scripts-link-v1): index.php and
    # views.php `require_once 'scripts/common.php'` relative to the web root.
    # Upstream's installer made this link and LIVE has it (S6, by hand); without it
    # every PHP page is a 500. Caddy already gates /scripts* with basic_auth.
    sudo -u "${PERCHPI_USER}" ln -sfn "${REPO_DIR}/scripts" "${EXTRACTED}/scripts"

    # A handful of legacy top-level endpoints live under scripts/ instead of
    # homepage/ — scripts/ also holds non-web files (Python, SQL, dev
    # patchers, adminer*.php, config.php/common.php/advanced.php which are
    # `include`d via views.php, not hit directly), so this list is explicit,
    # not a glob.
    local scripts_web_files=(
        history.php play.php spectrogram.php stats.php
        todays_detections.php weekly_report.php
    )
    for f in "${scripts_web_files[@]}"; do
        sudo -u "${PERCHPI_USER}" ln -sf "${REPO_DIR}/scripts/${f}" "${EXTRACTED}/${f}"
    done

    # Species list files + model/labels.txt are consumed by scripts/ code via
    # relative sibling paths.
    for f in exclude_species_list.txt confirmed_species_list.txt \
             include_species_list.txt whitelist_species_list.txt; do
        sudo -u "${PERCHPI_USER}" ln -sf "${REPO_DIR}/${f}" "${REPO_DIR}/scripts/${f}"
        sudo chown -h "${PERCHPI_USER}:caddy" "${REPO_DIR}/${f}"
        sudo chmod 664 "${REPO_DIR}/${f}"
    done
    sudo -u "${PERCHPI_USER}" ln -sf "${REPO_DIR}/model/labels.txt" "${REPO_DIR}/scripts/labels.txt"

    sudo -u "${PERCHPI_USER}" ln -sf "${STREAMDATA}/spectrogram.png" "${EXTRACTED}/spectrogram.png"
    sudo -u "${PERCHPI_USER}" ln -sf "${REPO_DIR}/homepage/images/favicon.ico" "${EXTRACTED}/favicon.ico"

    # phpsysinfo — third-party project, cloned fresh; /phpsysinfo* is one of
    # the routed Caddy paths.
    if [ ! -d "${PERCHPI_HOME}/phpsysinfo" ]; then
        sudo -u "${PERCHPI_USER}" git clone --quiet \
            https://github.com/phpsysinfo/phpsysinfo.git "${PERCHPI_HOME}/phpsysinfo"
        # S16 sweep (perchpi:installer-s16-phpsysinfo-pin-v1): the commit LIVE runs, not
        # whatever upstream HEAD is on install day. A full commit id is content-addressed.
        sudo -u "${PERCHPI_USER}" git -C "${PERCHPI_HOME}/phpsysinfo" \
            -c advice.detachedHead=false checkout --quiet 496e8ff73231b6bc06836681e33d293fc31735a0 \
            || abort "phpsysinfo commit 496e8ff73231 not found upstream (§9)."
    fi
    sudo -u "${PERCHPI_USER}" ln -sf "${REPO_DIR}/templates/phpsysinfo.ini" "${PERCHPI_HOME}/phpsysinfo/"
    sudo -u "${PERCHPI_USER}" ln -sf "${REPO_DIR}/templates/green_bootstrap.css" "${PERCHPI_HOME}/phpsysinfo/templates/"
    sudo -u "${PERCHPI_USER}" ln -sf "${REPO_DIR}/templates/index_bootstrap.html" "${PERCHPI_HOME}/phpsysinfo/templates/html"
    sudo -u "${PERCHPI_USER}" ln -sf "${PERCHPI_HOME}/phpsysinfo" "${EXTRACTED}/phpsysinfo"

    sudo chmod -R g+rw "${REPO_DIR}" "${PERCHPI_HOME}/BirdSongs"

    # §9's own DoD gate, run inline: verify every homepage/*.php symlink
    # resolves. Full HTTP-level verification (index.php route, PHP-not-HTML)
    # happens in §13, once Caddy+PHP are actually serving.
    local broken=0
    for f in "${REPO_DIR}"/homepage/*; do
        local name; name=$(basename "${f}")
        [ -e "${EXTRACTED}/${name}" ] || { log "  MISSING: ${name}"; broken=1; }
    done
    [ "${broken}" -eq 0 ] || abort "One or more homepage/*.php symlinks failed to resolve (§9)."

    log "Symlinks installed and resolve."
}

# ============================================================================
# [§10] Services — systemd units and cron
# ============================================================================

# All 19 units the audit found (15 from the birdnet*/perchpi* glob + 4 the
# glob missed: chart_viewer, spectrogram_viewer, web_terminal, avahi-alias@).
# Written directly rather than templated from git, matching this repo's
# existing convention (install_services.sh does the same) since several of
# the newer perchpi-* units were captured live but never checked into
# templates/.
install_systemd_units() {
    step "§10 systemd units"

    local venv_py="${REPO_DIR}/birdnet/bin/python3"

    # B3 / G9 (perchpi:installer-s19a-startlimit-v1 comment): StartLimitIntervalSec/Burst
    # are [Unit] keys; under [Service] systemd ignored them ("Unknown key") on all 8.
    sudo tee /etc/systemd/system/birdnet_analysis.service >/dev/null <<EOF
[Unit]
StartLimitIntervalSec=300
StartLimitBurst=5
Description=BirdNET Analysis
[Service]
Restart=always
Type=simple
RestartSec=2
User=${PERCHPI_USER}
ExecStart=${venv_py} /usr/local/bin/birdnet_analysis.py
[Install]
WantedBy=multi-user.target
EOF

    sudo tee /etc/systemd/system/birdnet_recording.service >/dev/null <<EOF
[Unit]
# perchpi:s21a-capture-units-v1 (D-S20-4): no start limit on the capture path. A mic glitch
# or a lost race must never stop recording for good (B3's 5-in-300 s did).
StartLimitIntervalSec=0
Description=BirdNET Recording
[Service]
Restart=always
Type=simple
RestartSec=3
User=${PERCHPI_USER}
ExecStart=/usr/local/bin/birdnet_recording.sh
[Install]
WantedBy=multi-user.target
EOF

    sudo tee /etc/systemd/system/birdnet_stats.service >/dev/null <<EOF
[Unit]
StartLimitIntervalSec=300
StartLimitBurst=5
Description=BirdNET Stats
[Service]
Restart=on-failure
RestartSec=5
Type=simple
User=${PERCHPI_USER}
ExecStart=${REPO_DIR}/birdnet/bin/streamlit run ${REPO_DIR}/scripts/plotly_streamlit.py --browser.gatherUsageStats false --server.address localhost --server.baseUrlPath "/stats"

[Install]
WantedBy=multi-user.target
EOF

    sudo tee /etc/systemd/system/birdnet_log.service >/dev/null <<EOF
[Unit]
StartLimitIntervalSec=300
StartLimitBurst=5
Description=BirdNET Analysis Log
[Service]
Restart=on-failure
RestartSec=3
Type=simple
User=${PERCHPI_USER}
Environment=TERM=xterm-256color
ExecStart=/usr/local/bin/gotty --address localhost -p 8080 --path log --title-format "BirdNET-Pi Log" birdnet_log.sh
[Install]
WantedBy=multi-user.target
EOF

    sudo tee /etc/systemd/system/chart_viewer.service >/dev/null <<EOF
[Unit]
StartLimitIntervalSec=300
StartLimitBurst=5
Description=BirdNET-Pi Chart Viewer Service
[Service]
Restart=always
RestartSec=120
Type=simple
User=${PERCHPI_USER}
ExecStart=${venv_py} /usr/local/bin/daily_plot.py --daemon --sleep 2
[Install]
WantedBy=multi-user.target
EOF

    sudo tee /etc/systemd/system/spectrogram_viewer.service >/dev/null <<EOF
[Unit]
StartLimitIntervalSec=300
StartLimitBurst=5
Description=BirdNET-Pi Spectrogram Viewer
[Service]
Restart=always
RestartSec=10
Type=simple
User=${PERCHPI_USER}
ExecStart=/usr/local/bin/spectrogram.sh
[Install]
WantedBy=multi-user.target
EOF

    sudo tee /etc/systemd/system/web_terminal.service >/dev/null <<EOF
[Unit]
StartLimitIntervalSec=300
StartLimitBurst=5
Description=BirdNET-Pi Web Terminal
[Service]
Restart=on-failure
RestartSec=3
Type=simple
User=${PERCHPI_USER}
Environment=TERM=xterm-256color
ExecStart=/usr/local/bin/gotty --address localhost -w -p 8888 --path terminal --title-format "BirdNET-Pi Terminal" bash -c 'read -p "Login: " username && [[ "\$username" =~ ^[-_.a-z0-9]{1,30}\$ ]] && su --pty -l \$username'
[Install]
WantedBy=multi-user.target
EOF

    # perchpi:live-audio-streamer-path-v1 — output moved off /tmp to escape
    # Caddy's PrivateTmp (see §4, §8).
    sudo tee /etc/systemd/system/perchpi-live-audio-stream.service >/dev/null <<'EOF'
[Unit]
# perchpi:s21a-capture-units-v1 (D-S20-4): no start limit on the capture path.
StartLimitIntervalSec=0
Description=PerchPi Live Audio HLS Streamer
After=network.target sound.target birdnet_recording.service
Wants=birdnet_recording.service

[Service]
Type=simple
User=birder
Group=birder
ExecStartPre=/bin/mkdir -p /var/lib/perchpi-hls
ExecStartPre=/bin/bash -c "rm -f /var/lib/perchpi-hls/*.ts /var/lib/perchpi-hls/*.m3u8"
ExecStart=/usr/bin/ffmpeg -nostdin -hide_banner -loglevel warning -f alsa -ac 1 -ar 48000 -i pp_mic_in -c:a aac -b:a 96k -ac 1 -f hls -hls_time 2 -hls_list_size 5 -hls_flags delete_segments+omit_endlist+independent_segments -hls_segment_filename /var/lib/perchpi-hls/seg%%05d.ts /var/lib/perchpi-hls/stream.m3u8
# perchpi:s21a-live-restart-v1 (D-S20-3): ffmpeg exits cleanly when the mic goes away,
# so Restart=on-failure never brought it back.
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

    sudo tee /etc/systemd/system/perchpi-archive.service >/dev/null <<'EOF'
[Unit]
Description=PerchPi audio archive (rsync old detections to UNAS, mark DB)
Documentation=https://github.com/churchja/perchpi-release
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=birder
Group=birder
ExecStart=/usr/bin/python3 /home/birder/BirdNET-Pi/scripts/perchpi-archive.py --apply
StandardOutput=journal
StandardError=journal
SyslogIdentifier=perchpi-archive
Nice=10
IOSchedulingClass=idle
# perchpi:archive-timeout-4h-v1 - 5,000-file cap ~2.1 h at LIVE->CIFS ~0.65 files/s (S11 incident, S12 landing)
TimeoutStartSec=4h
EOF
    sudo tee /etc/systemd/system/perchpi-archive.timer >/dev/null <<'EOF'
[Unit]
Description=Run PerchPi archive daily at 02:00 (1h before retention)
Documentation=https://github.com/churchja/perchpi-release

[Timer]
OnCalendar=02:00
Persistent=true
RandomizedDelaySec=300
Unit=perchpi-archive.service

[Install]
WantedBy=timers.target
EOF

    sudo tee /etc/systemd/system/perchpi-retention.service >/dev/null <<'EOF'
[Unit]
Description=PerchPi audio retention (delete old non-protected detections)
Documentation=https://github.com/churchja/perchpi-release
After=network.target

[Service]
Type=oneshot
User=birder
Group=birder
ExecStart=/usr/bin/python3 /home/birder/BirdNET-Pi/scripts/perchpi-retention.py --apply
StandardOutput=journal
StandardError=journal
SyslogIdentifier=perchpi-retention
Nice=10
IOSchedulingClass=idle
EOF
    sudo tee /etc/systemd/system/perchpi-retention.timer >/dev/null <<'EOF'
[Unit]
Description=Run PerchPi retention hourly (age pass + free-space floor; perchpi:installer-s19a-retention-hourly-v1)
Documentation=https://github.com/churchja/perchpi-release

[Timer]
OnCalendar=hourly
Persistent=true
RandomizedDelaySec=300
Unit=perchpi-retention.service

[Install]
WantedBy=timers.target
EOF

    sudo tee /etc/systemd/system/perchpi-geomodel-weekly.service >/dev/null <<EOF
[Unit]
Description=PerchPi geomodel weekly prediction cache rebuild
Documentation=https://github.com/birdnet-team/birdnet-geomodel
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=${PERCHPI_USER}
Group=${PERCHPI_USER}
ExecStart=${venv_py} ${REPO_DIR}/scripts/utils/predict_geomodel_weekly.py
StandardOutput=journal
StandardError=journal
TimeoutStartSec=600
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7

[Install]
WantedBy=multi-user.target
EOF
    sudo tee /etc/systemd/system/perchpi-geomodel-weekly.timer >/dev/null <<'EOF'
[Unit]
Description=PerchPi geomodel weekly prediction cache rebuild timer
Documentation=https://github.com/birdnet-team/birdnet-geomodel

[Timer]
OnCalendar=Mon *-*-* 03:00:00
RandomizedDelaySec=15min
Persistent=true
Unit=perchpi-geomodel-weekly.service

[Install]
WantedBy=timers.target
EOF

    sudo tee /etc/systemd/system/perchpi-refresh-bird-photos.service >/dev/null <<EOF
[Unit]
Description=PerchPi: refresh Wikipedia bird photo cache
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=${PERCHPI_USER}
Group=${PERCHPI_USER}
ExecStart=/usr/bin/php ${REPO_DIR}/homepage/refresh_bird_photos.php
StandardOutput=journal
StandardError=journal
TimeoutStartSec=600

[Install]
WantedBy=multi-user.target
EOF
    sudo tee /etc/systemd/system/perchpi-refresh-bird-photos.timer >/dev/null <<'EOF'
[Unit]
Description=PerchPi: hourly trigger for bird photo refresh

[Timer]
OnCalendar=hourly
RandomizedDelaySec=300
Persistent=true
Unit=perchpi-refresh-bird-photos.service

[Install]
WantedBy=timers.target
EOF

    sudo tee /etc/systemd/system/perchpi-weather-poll.service >/dev/null <<EOF
[Unit]
Description=PerchPi: poll Wunderground PWS current conditions into cache
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=${PERCHPI_USER}
Group=caddy
ExecStart=/usr/bin/python3 ${REPO_DIR}/scripts/wu_weather_poll.py
StandardOutput=journal
StandardError=journal
TimeoutStartSec=60

[Install]
WantedBy=multi-user.target
EOF
    sudo tee /etc/systemd/system/perchpi-weather-poll.timer >/dev/null <<'EOF'
[Unit]
Description=PerchPi: trigger for Wunderground PWS weather poll

[Timer]
OnBootSec=60
OnUnitActiveSec=150
RandomizedDelaySec=30
Persistent=true
Unit=perchpi-weather-poll.service

[Install]
WantedBy=timers.target
EOF

    # avahi-alias@ must be templated on the first-boot hostname, not
    # hardcoded to birdnetpi (§10, §12.2) — the repo's own tracked template
    # is reused here rather than re-embedded.
    sudo ln -sf "${REPO_DIR}/templates/avahi-alias@.service" /etc/systemd/system/avahi-alias@.service

    sudo systemctl daemon-reload
    # D-S17-2 (perchpi:installer-s19a-no-archive-timer-v1): the archive ships as inert
    # code (spec §-269 MUST-NOT-SHIP: an enabled perchpi-archive.timer). Unit files only.
    sudo systemctl enable \
        birdnet_analysis birdnet_recording birdnet_stats birdnet_log \
        chart_viewer spectrogram_viewer \
        perchpi-live-audio-stream \
        perchpi-retention.timer \
        perchpi-refresh-bird-photos.timer perchpi-weather-poll.timer
    # perchpi:s21a-geomodel-on-v1 (D2, audit B1 / D-S17-9): USE_GEOMODEL=1 ships on, so the
    # weekly geomodel timer is enabled (the timer only: the .service is WantedBy=multi-user).
    # perchpi:s21a-no-webterm-v1 (D5, audit E7): web_terminal is installed but not enabled;
    # an owner turns it on from Services.
    sudo systemctl enable perchpi-geomodel-weekly.timer
    sudo systemctl enable "avahi-alias@${HOSTNAME_CHOICE}.local.service"

    install_local_bin_farm

    install_cron_and_logrotate

    log "systemd units installed."
}

# D-S6-9 (perchpi:installer-s7b-20260813): §10 installs unit files whose ExecStart lines
# all point into /usr/local/bin, but NOTHING ever populated it. This is
# not one missing file -- the entire step is absent, and every unit
# fails 203/EXEC on a fresh install. Symlink (not copy) so a git pull
# updates the running station; -n so a re-run replaces a link instead of
# nesting inside a directory it points at.
install_local_bin_farm() {
    step "§10 /usr/local/bin farm"

    local f name linked=0
    for f in "${REPO_DIR}"/scripts/*.sh "${REPO_DIR}"/scripts/*.py; do
        [ -e "${f}" ] || continue
        name=$(basename "${f}")
        sudo ln -sfn "${f}" "/usr/local/bin/${name}"
        sudo chmod 755 "${f}"
        linked=$((linked + 1))
    done
    log "Linked ${linked} scripts into /usr/local/bin."

    # DoD: every ExecStart path referenced by the units we just wrote
    # must resolve to something executable. gotty included: since D-S7-8
    # (perchpi:installer-s16-gotty-v1) §7 installs it here directly, so a
    # missing gotty is now a real failure, not a known gap.
    local missing=0 target
    for target in $(grep -ho '/usr/local/bin/[A-Za-z0-9._-]*' \
                    /etc/systemd/system/birdnet_*.service \
                    /etc/systemd/system/chart_viewer.service \
                    /etc/systemd/system/spectrogram_viewer.service \
                    /etc/systemd/system/web_terminal.service 2>/dev/null \
                    | sort -u); do
        [ -x "${target}" ] && continue
        log "  MISSING: ${target}"
        missing=$((missing + 1))
    done
    [ "${missing}" -eq 0 ] || abort \
        "${missing} unit ExecStart target(s) missing from /usr/local/bin (§10, D-S6-9)."
}

# ============================================================================
# [§10b] Web sudo -- D1 (perchpi:s21a2-d1-v1)
# ============================================================================
# The web runs as caddy (§8 points the PHP-FPM pool at it). A fresh image gave caddy no sudo,
# so every Services / System / Settings action that needs root failed. This grants the exact
# command lines the pages and their scripts issue (only "systemctl status" takes a wildcard),
# plus two root-owned helpers that check their own input. Nothing here runs a file caddy or
# birder can write: §8 puts caddy in birder's group and §9 makes the repo g+rw, so sudo on a
# repo script would be root for the web. Work on birder's files runs as birder, which caddy
# can already reach. Replaces update_caddyfile.sh (retired).
install_web_sudo() {
    step "§10b Web sudo (D1)"

    local mount_unit
    mount_unit=$(systemd-escape -p --suffix=mount "${STREAMDATA}")
    [[ "${mount_unit}" =~ ^[A-Za-z0-9._-]+$ ]] \
        || abort "Unexpected StreamData mount unit name '${mount_unit}' (§10b, D1)."

    sudo install -d -m 755 -o root -g root /usr/local/sbin
    sudo tee /usr/local/sbin/perchpi-time >/dev/null <<'EOF'
#!/bin/bash
# perchpi:s21a2-time-v1 (D1) -- root-owned; written by newinstaller.sh §10b.
# The web's only way to change the clock: timezone, NTP on/off, or a manual date and time.
set -euo pipefail
case "${1:-}" in
    tz)
        zone="${2:-}"
        timedatectl list-timezones | grep -qxF -- "${zone}" \
            || { echo "perchpi-time: unknown timezone" >&2; exit 2; }
        timedatectl set-timezone "${zone}"
        if [ -f /etc/timezone ]; then printf '%s\n' "${zone}" > /etc/timezone; fi
        ;;
    ntp)
        case "${2:-}" in
            on)  timedatectl set-ntp true ;;
            off) timedatectl set-ntp false ;;
            *)   echo "perchpi-time: ntp takes on|off" >&2; exit 2 ;;
        esac
        ;;
    set)
        [[ "${2:-}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}$ ]] \
            || { echo "perchpi-time: set takes 'YYYY-MM-DD HH:MM'" >&2; exit 2; }
        date -s "${2}" >/dev/null
        ;;
    *)
        echo "usage: perchpi-time tz ZONE | ntp on|off | set 'YYYY-MM-DD HH:MM'" >&2
        exit 2
        ;;
esac
EOF
    sudo tee /usr/local/sbin/perchpi-caddy-pwd >/dev/null <<'EOF'
#!/bin/bash
# perchpi:s21a2-caddy-pwd-v1 (D1) -- root-owned; written by newinstaller.sh §10b.
# Reads the new web password on stdin (never argv), puts its hash on every "birdnet <hash>"
# line of /etc/caddy/Caddyfile, validates, swaps the file in and reloads Caddy. Changes
# nothing else in the Caddyfile and leaves no backup copy behind. Never prints the password.
set -euo pipefail
umask 077
cf=/etc/caddy/Caddyfile
pw=""
IFS= read -r pw || true
pw=${pw%$'\r'}
if [ -z "${pw}" ] || [ "${#pw}" -gt 128 ]; then
    echo "perchpi-caddy-pwd: refusing an empty or over-long password" >&2
    exit 2
fi
hash=$(printf '%s\n' "${pw}" | caddy hash-password)
pw=""
if ! [[ "${hash}" =~ ^\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}$ ]]; then
    echo "perchpi-caddy-pwd: unexpected hash format from caddy hash-password" >&2
    exit 1
fi
tmp=$(mktemp /etc/caddy/.Caddyfile.XXXXXX)
trap 'rm -f "${tmp}"' EXIT
n=$(awk -v h="${hash}" '
    /^[[:space:]]*birdnet[[:space:]]+[^[:space:]]+[[:space:]]*$/ {
        sub(/birdnet[[:space:]]+[^[:space:]]+/, "birdnet " h); c++
    }
    { print }
    END { print c + 0 > "/dev/stderr" }' "${cf}" 2>&1 >"${tmp}")
if [ "${n}" -lt 1 ]; then
    echo "perchpi-caddy-pwd: no 'birdnet <hash>' lines in ${cf}; nothing changed" >&2
    exit 1
fi
chown --reference="${cf}" "${tmp}"
chmod --reference="${cf}" "${tmp}"
if ! caddy validate --config "${tmp}" --adapter caddyfile >/dev/null 2>&1; then
    echo "perchpi-caddy-pwd: the new Caddyfile does not validate; nothing changed" >&2
    exit 1
fi
mv -f "${tmp}" "${cf}"
trap - EXIT
systemctl reload caddy
echo "perchpi-caddy-pwd: ${n} basic_auth entries updated"
EOF
    sudo chown root:root /usr/local/sbin/perchpi-time /usr/local/sbin/perchpi-caddy-pwd
    sudo chmod 755 /usr/local/sbin/perchpi-time /usr/local/sbin/perchpi-caddy-pwd

    local sc=/usr/bin/systemctl u units=()
    for u in livestream web_terminal birdnet_log birdnet_analysis birdnet_stats \
             birdnet_recording chart_viewer spectrogram_viewer; do
        units+=("${sc} stop ${u}.service" "${sc} restart ${u}.service"
                "${sc} disable --now ${u}.service" "${sc} enable --now ${u}.service")
    done
    units+=("${sc} stop icecast2.service" "${sc} restart icecast2.service"
            "${sc} start icecast2.service" "${sc} enable icecast2" "${sc} disable icecast2"
            "${sc} stop custom_recording.service"
            "${sc} start --no-block perchpi-refresh-bird-photos.service"
            "${sc} enable ${mount_unit}" "${sc} disable ${mount_unit}"
            "${sc} status *")
    local joined tmp
    joined=$(printf '%s, ' "${units[@]}")
    joined=${joined%, }
    tmp=$(mktemp)
    {
        echo "# perchpi:s21a2-d1-v1 (D1) -- written by newinstaller.sh §10b; change the installer, not this file."
        echo "# The web's sudo: exact command lines and two root-owned helpers. caddy is the web (PHP-FPM),"
        echo "# ${PERCHPI_USER} runs the scripts the web starts as ${PERCHPI_USER}."
        echo "User_Alias PP_WEB = caddy, ${PERCHPI_USER}"
        echo "Cmnd_Alias PP_UNITS = ${joined}"
        echo 'Cmnd_Alias PP_POWER = /usr/sbin/reboot "", /usr/sbin/shutdown now, /usr/sbin/service php*-fpm restart'
        echo 'Cmnd_Alias PP_HELPERS = /usr/local/sbin/perchpi-time, /usr/local/sbin/perchpi-caddy-pwd ""'
        echo "PP_WEB ALL=(root) NOPASSWD: PP_UNITS, PP_POWER, PP_HELPERS"
        echo "PP_WEB ALL=(${PERCHPI_USER}) NOPASSWD: ALL"
    } > "${tmp}"
    sudo visudo -cf "${tmp}" >/dev/null \
        || { rm -f "${tmp}"; abort "The generated web sudoers fails visudo -c (§10b, D1)."; }
    sudo install -m 440 -o root -g root "${tmp}" /etc/sudoers.d/010-perchpi-web
    rm -f "${tmp}"
    sudo visudo -c >/dev/null || abort "sudo's configuration fails visudo -c after §10b (D1)."
    log "Web sudoers + perchpi-time + perchpi-caddy-pwd installed (D1)."
}

# The cron layer (3 jobs) is invisible to every systemctl command — a
# glob-only inventory would miss it entirely (§10). None of the three
# rotate their own logs; two run every minute (2,880 interpreter starts/day)
# and perch/worker.log was already 70 MB on the live station. This is new
# scope the installer must ship — it doesn't exist upstream either.
install_cron_and_logrotate() {
    # D-S6-6 (perchpi:installer-s7b-20260813): on a fresh install `crontab -l` exits 1
    # ("no crontab for birder") and `grep -v` ALSO exits 1 when it
    # filters everything out. Under `set -e` either one kills the run
    # with no abort line and nothing in any log. 2>/dev/null hides the
    # message, not the status -- both need neutralising, either alone
    # still fails.
    ( { sudo -u "${PERCHPI_USER}" crontab -l 2>/dev/null || true; } | grep -v "perchpi:e6-queue-worker-cron-v1\|perchpi:detection-flatline-watchdog-v1\|process_ai_queue.py\|process_embedding_queue.py\|detection_flatline_watchdog.py" || true
      echo "# perchpi:e6-queue-worker-cron-v1 - AI false-positive queue worker, runs every minute"
      echo "* * * * * /usr/bin/python3 ${REPO_DIR}/scripts/process_ai_queue.py >> ${REPO_DIR}/scripts/ai_queue_worker.log 2>&1"
      echo "* * * * * ${REPO_DIR}/birdnet/bin/python3 ${REPO_DIR}/perch/process_embedding_queue.py >> ${REPO_DIR}/perch/worker.log 2>&1"
      echo "# perchpi:detection-flatline-watchdog-v1"
      echo "*/15 * * * * /usr/bin/python3 ${REPO_DIR}/watchdog/detection_flatline_watchdog.py >> ${REPO_DIR}/watchdog/cron.log 2>&1"
    ) | sudo -u "${PERCHPI_USER}" crontab -

    sudo tee /etc/logrotate.d/perchpi-cron-logs >/dev/null <<EOF
${REPO_DIR}/perch/worker.log
${REPO_DIR}/scripts/ai_queue_worker.log
${REPO_DIR}/watchdog/cron.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su ${PERCHPI_USER} ${PERCHPI_USER}
}
EOF
    # rotate 14: Jason's stated default for D-S5-2 (decided 2026-09-12). The same stanza is
    # live on the station as /etc/logrotate.d/perchpi-cron-logs.
    # copytruncate (not create+reopen) because these are one-shot
    # per-minute/per-15-min interpreters re-opening the file with >> each
    # run, not a long-lived daemon holding an fd open.
}

# ============================================================================
# [§11] Database
# ============================================================================

init_database() {
    step "§11 Database"

    local db="${REPO_DIR}/scripts/birds.db"

    # D-S6-8 (perchpi:installer-s7b-20260813): the old guard was [ -f "${db}" ], which is
    # unreachable as a protection -- §5 clones a birds.db into place on
    # EVERY run, so §11 always saw a file, logged "leaving it alone" and
    # never loaded the schema. The cloned file is 0 bytes. PHP then opens
    # a database with no tables and fatals: HTTP 500 with nothing in
    # php-fpm.log, nothing in the journal, and no abort anywhere. No
    # fresh install has ever had a working database.
    # Guard on schema CONTENT, not file existence.
    local tables=0
    if [ -s "${db}" ]; then
        tables=$(sqlite3 "${db}" \
            "SELECT count(*) FROM sqlite_master WHERE type='table';" 2>/dev/null || echo 0)
    fi
    if [ "${tables}" -ge 9 ]; then
        log "birds.db has ${tables} tables -- populated, leaving it alone."
        return
    fi
    if [ -e "${db}" ]; then
        log "birds.db present but only ${tables} table(s) -- loading schema."
        sudo rm -f "${db}" "${db}-wal" "${db}-shm"
    fi

    # Apply the tracked incremental migrations in order (0001 baseline, 0002
    # geomodel columns, 0003 quarantine audio snapshot) rather than the dev
    # scratch dump in .local-patches/ — these are the actual versioned source
    # of truth. schema_migrations is created after the replay by `migrate.py
    # mark` (perchpi:installer-s19a-migrate-mark-v1, D-S17-6).
    for f in "${REPO_DIR}"/scripts/migrations/000*.sql; do
        # Known harmless quirk: SQLite auto-creates sqlite_sequence the
        # moment a table with AUTOINCREMENT exists; an explicit CREATE TABLE
        # for it in a replayed dump errors even though nothing is actually
        # broken. Strip it before replay instead of ignoring sqlite3's exit
        # code wholesale (spec §11).
        grep -v '^CREATE TABLE sqlite_sequence' "${f}" | sudo -u "${PERCHPI_USER}" sqlite3 "${db}"
    done
    # D-S17-6 / audit C1 (perchpi:installer-s19a-migrate-mark-v1 (loop)): record the
    # replayed migrations. That creates schema_migrations (the 9th table the gate
    # below counts) and stops a later `migrate.py up` re-running 0002/0003's ALTERs.
    for f in "${REPO_DIR}"/scripts/migrations/000*.sql; do
        sudo -u "${PERCHPI_USER}" python3 "${REPO_DIR}/scripts/migrate.py" --db "${db}" \
            mark "$(basename "${f}" .sql)" >/dev/null \
            || abort "migrate.py mark $(basename "${f}" .sql) failed (§11, D-S17-6)."
    done

    # Ownership: the FPM pool runs as caddy and SQLite in WAL mode needs
    # write access to the DIRECTORY (to create/resize -wal and -shm), not
    # just the file. Whichever process opens the db first creates those
    # sidecars owned by itself, locking the other side out -- this is
    # exactly what took the live station down on 2026-08-13 after a
    # restore (99x "attempt to write a readonly database" while audio
    # extraction kept working, so it looked like a quiet mic). setgid on
    # the directory makes new sidecars inherit the caddy group, so it
    # cannot silently un-fix itself at the next checkpoint.
    sudo chown "${PERCHPI_USER}:caddy" "${db}"
    sudo chmod 664 "${db}"
    sudo chgrp caddy "${REPO_DIR}/scripts"
    sudo chmod 2775 "${REPO_DIR}/scripts"

    tables=$(sqlite3 "${db}" "SELECT count(*) FROM sqlite_master WHERE type='table';")
    [ "${tables}" -ge 9 ] || abort \
        "birds.db has only ${tables} tables after migration replay; expected >=9 (§11, D-S6-8)."

    local count
    count=$(sqlite3 "${db}" 'select count(*) from detections;')
    [ "${count}" = "0" ] || abort "Fresh birds.db has ${count} rows in detections; expected 0."

    log "birds.db created (${tables} tables, 0 detections)."
}

# ============================================================================
# [§12] First boot
# ============================================================================
# Order matters — identity before network (§12).

first_boot_identity() {
    # DEFECT FOUND 2026-08-02: the correct unit name has underscores
    # (regenerate_ssh_host_keys.service), not hyphens — every prior record
    # had this wrong, and `systemctl enable` on the hyphenated name silently
    # fails, reproducing D-S3-1 (host keys deleted, sshd refuses to start,
    # console-only box) on the next image capture (§12.1).
    sudo ssh-keygen -A
    sudo systemctl enable regenerate_ssh_host_keys.service
    sudo rm -f /etc/machine-id
    sudo systemd-machine-id-setup
    # C9 (perchpi:installer-s19a-journald-v1): journald keeps writing under the old
    # machine-id until restarted, so journalctl showed "No entries" until a reboot.
    sudo systemctl restart systemd-journald
}

# D-S13-1 (perchpi:installer-s16-seed-strip-v1): Raspberry Pi Imager writes a cloud-init
# NoCloud seed to the FAT boot partition, readable by every local user -- a web-terminal
# session included. It holds the imaging account's password hash and, if Raspberry Pi
# Connect was set up in Imager, a Connect token. Remove those lines only: the same edit
# S13 dry-ran and applied on LIVE (SESSION-LOG-S13-20260912.md). cloud-init does not
# re-read the seed for an instance that has already booted, so nothing re-runs, and the
# account's working password in /etc/shadow is untouched. No .bak: a backup would be a
# second copy of the secret. Deliberately NOT done here: the root-only cloud-init cache
# (cleared at image capture, RUNBOOK-S2 Phase 2, until Phase 9 proves it safe on a
# running station) and network-config's Wi-Fi block (the owner's own PSK; removing it
# risks a headless station's only link if the cache is ever rebuilt).
SEED_SECRET_RE='^[[:space:]]+(passwd|hashed_passwd|plain_text_passwd):|com\.raspberrypi\.connect/auth\.key'

strip_cloudinit_seed() {
    local seed="/boot/firmware/user-data"
    if [ ! -f "${seed}" ]; then
        log "No cloud-init seed at ${seed}; nothing to strip (D-S13-1)."
        return 0
    fi
    # Never edit the seed while first boot is still running (runcmd may not be done).
    if command -v cloud-init &>/dev/null; then
        sudo cloud-init status --wait >/dev/null || true
    fi
    local tmp
    tmp=$(mktemp)
    grep -vE "${SEED_SECRET_RE}" "${seed}" > "${tmp}" || true
    python3 -c 'import sys, yaml; assert isinstance(yaml.safe_load(open(sys.argv[1])), dict)' \
        "${tmp}" 2>/dev/null || { rm -f "${tmp}"; abort \
        "The stripped cloud-init seed does not parse as YAML; ${seed} left unchanged (D-S13-1)."; }
    sudo cp "${tmp}" "${seed}"
    rm -f "${tmp}"
    log "Removed password-hash and Connect-token lines from ${seed} (D-S13-1)."
}

first_boot_hostname() {
    # D-S21-1 (perchpi:installer-s21b-hostname-v1): the answer used to reach hostnamectl unvalidated.
    # In S21 it carried stray arrow-key bytes, hostnamectl refused it, and set -e ended
    # the install with no ABORT line after sections 4-7 had run (same class as D-S17-5).
    # Re-prompt until it is a valid single-label hostname; Enter keeps 'perchpi'.
    local re='^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$'
    while true; do
        read -r -p "${LOG_PREFIX} Hostname [perchpi]: " HOSTNAME_CHOICE
        # Default is 'perchpi', NOT 'birdnetpi' — three-way mDNS collisions are a
        # live problem on Jason's own LAN (§12.2).
        HOSTNAME_CHOICE="${HOSTNAME_CHOICE:-perchpi}"
        [[ "${HOSTNAME_CHOICE}" =~ ${re} ]] && break
        log "Not a valid hostname -- letters, digits and hyphens, 1-63 characters, no hyphen at either end. Enter keeps 'perchpi'."
    done
    sudo hostnamectl set-hostname "${HOSTNAME_CHOICE}" || abort "hostnamectl set-hostname '${HOSTNAME_CHOICE}' failed (§12.2)."
    sudo sed -i "s/127.0.1.1.*/127.0.1.1\t${HOSTNAME_CHOICE}/" /etc/hosts
    export HOSTNAME_CHOICE
}

first_boot_timezone() {
    # D-S17-5 / audit C2 (perchpi:installer-s19a-tz-v1): `[ -n ] && cmd` as the last
    # statement returned 1 on Enter, and set -e ended the install with no ABORT line.
    # Re-prompt until the zone is real; Enter keeps the current one. The process
    # substitution keeps grep -q's early exit from SIGPIPE-ing a pipefail pipeline.
    local tz
    while true; do
        read -r -p "${LOG_PREFIX} Timezone (e.g. America/Chicago) [keep current: $(timedatectl show -p Timezone --value)]: " tz
        [ -n "${tz}" ] || return 0
        grep -qxF -- "${tz}" < <(timedatectl list-timezones) && break
        log "Unknown timezone '${tz}' -- use a name from 'timedatectl list-timezones', or Enter to keep the current one."
    done
    sudo timedatectl set-timezone "${tz}" || abort "timedatectl set-timezone '${tz}' failed (§12.3)."
}

# UI default birdnet/birdnet MUST NOT survive first boot — the gate is that
# the image cannot reach a usable state on the shipped default (§12.5).
# is_authenticated() compares PHP_AUTH_USER/PW literally, so rotation has to
# actually change the config, not just document that it should.
first_boot_credentials() {
    local pwd1 pwd2
    while true; do
        read -r -s -p "${LOG_PREFIX} Set the web UI password (birdnet/____): " pwd1; echo
        read -r -s -p "${LOG_PREFIX} Confirm: " pwd2; echo
        # D-S17-4 / STAGE-2 S2-1 (perchpi:installer-s19a-pwd-charset-v1): letters and
        # digits only (advanced.php's field pattern; bash sources the conf unquoted), and
        # not a word PHP's INI parser turns into a boolean/null, which blanks the password.
        if [[ "${pwd1}" =~ ^[A-Za-z0-9]+$ ]] && [ "${pwd1}" = "${pwd2}" ] \
           && [ "${pwd1}" != "birdnet" ] \
           && ! [[ "${pwd1,,}" =~ ^(true|false|on|off|yes|no|none|null)$ ]]; then
            break
        fi
        log "Use letters and digits only, type it the same twice, and not 'birdnet'"
        log "or true/false/on/off/yes/no/none/null -- try again."
    done
    CADDY_PWD="${pwd1}"
    export CADDY_PWD
}

first_boot_microphone() {
    log "Detecting capture devices:"
    arecord -l || true
    read -r -p "${LOG_PREFIX} ALSA capture card index [0]: " card
    card="${card:-0}"

    # D-S7-4 (perchpi:installer-s7b-20260813): card NUMBERS reshuffle across boots and
    # replugs -- a station that works today comes up deaf after an
    # unrelated USB change. Resolve the index the user just picked to a
    # stable card NAME and bind to that instead.
    local card_name
    card_name=$(awk -v c="${card}" '$1 == c { gsub(/[][]/, "", $2); print $2; exit }' \
                /proc/asound/cards)
    [ -n "${card_name}" ] || abort \
        "No ALSA capture card at index ${card} (§12, D-S7-4). Is the mic plugged in?"
    log "Binding capture to card name '${card_name}' (index ${card} is not stable)."

    # Shared mic capture via dsnoop — two readers (arecord + ffmpeg HLS) need
    # simultaneous access; without dsnoop the first reader gets exclusive
    # lock and the second fails with "Device or resource busy" (§10).
    sudo tee /etc/asound.conf >/dev/null <<EOF
# perchpi:asound-dsnoop-v1 — shared USB mic capture for birdnet_recording + HLS
pcm.pp_mic_dsnoop {
    type dsnoop
    ipc_key 12345
    ipc_key_add_uid true
    slave {
        pcm "hw:CARD=${card_name},DEV=0"
        channels 1
        rate 48000
        format S16_LE
        period_size 1024
        buffer_size 8192
    }
}
pcm.pp_mic_in {
    type plug
    slave.pcm "pp_mic_dsnoop"
}
EOF

    log "Recording a 5-second level test..."
    arecord -D pp_mic_in -f S16_LE -r 48000 -c 1 -d 5 /tmp/perchpi-mic-test.wav
    sox /tmp/perchpi-mic-test.wav -n stat 2>&1 | grep -i "maximum amplitude" || true
    rm -f /tmp/perchpi-mic-test.wav

    read -r -p "${LOG_PREFIX} ALSA capture gain 0-100 [80]: " gain
    gain="${gain:-80}"
    # D-S7-6 (perchpi:installer-s7b-20260813): the old line hardcoded the control name
    # "Mic,0" and swallowed all output with 2>/dev/null || true, so on a
    # device that names its capture control differently the gain was
    # never applied and NOTHING said so. Observed both ways on identical
    # hardware: one USB device exposes Capture, another exposes
    # Mic/Auto Gain Control. Discover the control; say so if there
    # is none.
    local gain_ctl
    gain_ctl=$(amixer -c "${card}" scontrols 2>/dev/null \
               | grep -oE "'(Capture|Mic)'" | tr -d "'" | head -1)
    if [ -n "${gain_ctl}" ]; then
        amixer -c "${card}" sset "${gain_ctl}" "${gain}%" >/dev/null \
            || log "  WARNING: setting ${gain_ctl} to ${gain}% failed; check with alsamixer."
        log "Capture gain set via '${gain_ctl}' control."
    else
        log "  WARNING: no Capture/Mic mixer control on card ${card};"
        log "           gain NOT applied. Set it manually with alsamixer if"
        log "           detections come back weak."
    fi
    sudo alsactl store
}

first_boot_station_conf() {
    # A6 (perchpi:installer-s19a-latlon-v1): analysis.py getfloat()s these; a blank,
    # '38,97' or '-87.5 W' made every WAV fail (zero detections) while §13 passed.
    # Re-prompt until each is a decimal in range; a decimal comma becomes a dot.
    while true; do
        read -r -p "${LOG_PREFIX} Latitude (decimal degrees, south negative, e.g. 38.01): " lat
        lat="${lat//,/.}"
        [[ "${lat}" =~ ^-?[0-9]{1,2}(\.[0-9]+)?$ ]] \
            && awk -v v="${lat}" 'BEGIN { exit !(v >= -90 && v <= 90) }' && break
        log "Latitude must be a decimal number from -90 to 90."
    done
    while true; do
        read -r -p "${LOG_PREFIX} Longitude (decimal degrees, west negative, e.g. -87.56): " lon
        lon="${lon//,/.}"
        [[ "${lon}" =~ ^-?[0-9]{1,3}(\.[0-9]+)?$ ]] \
            && awk -v v="${lon}" 'BEGIN { exit !(v >= -180 && v <= 180) }' && break
        log "Longitude must be a decimal number from -180 to 180."
    done
    read -r -p "${LOG_PREFIX} Station/site name [PerchPi]: " site
    site="${site:-PerchPi}"

    sudo mkdir -p /etc/birdnet
    # 11 keys ship blank (spec §12.7): credentials/URLs the owner opts into
    # later via the UI. ICE_PWD is gone — icecast2 is dropped (§12.11), so
    # there is nothing left for that key to configure.
    # BIRDWEATHER_STATION (perchpi:installer-s16-bw-station-v1) is the PUBLIC station
    # number the Overview link uses; BIRDWEATHER_ID is the upload token (S15, D-S6-13).
    sudo tee /etc/birdnet/birdnet.conf >/dev/null <<EOF
SITE_NAME="${site}"
LATITUDE=${lat}
LONGITUDE=${lon}
MODEL=BirdNET_GLOBAL_6K_V2.4_Model_FP16
SF_THRESH=0.03
DATA_MODEL_VERSION=2
BIRDWEATHER_ID=
BIRDWEATHER_STATION=
CADDY_PWD=${CADDY_PWD}
BIRDNETPI_URL=
RTSP_STREAM=
RTSP_STREAM_TO_LIVESTREAM="0"
APPRISE_NOTIFICATION_TITLE="New Detection"
GEMINI_API_KEY=
HEARTBEAT_URL=
WUNDERGROUND_STATION_ID=
WUNDERGROUND_API_KEY=
TAILSCALE_AUTHKEY=
PERCHPI_RETENTION_DAYS=45
RETENTION_REQUIRE_ARCHIVE=false
PERCHPI_FREE_FLOOR_PCT=15
USE_GEOMODEL=1
GEOMODEL_DIR=${REPO_DIR}/geomodel
# perchpi:installer-s19a-conf-keys-v1 (D-S7-1, audit A1/A2): keys the recorder,
# analyzer, PHP and notifications read. Upstream install_config.sh defaults except
# REC_CARD/CHANNELS (dsnoop, mono), AUDIOFMT=flac, and LIVE's observed thresholds.
RECS_DIR=${PERCHPI_HOME}/BirdSongs
PROCESSED=${PERCHPI_HOME}/BirdSongs/Processed
EXTRACTED=${EXTRACTED}
REC_CARD="pp_mic_in"
CHANNELS=1
RECORDING_LENGTH=15
EXTRACTION_LENGTH=
AUDIOFMT=flac
CONFIDENCE=0.58
SENSITIVITY=1.25
OVERLAP=0.0
PRIVACY_THRESHOLD=0
DATABASE_LANG=en
RAW_SPECTROGRAM=0
COLOR_SCHEME="light"
IMAGE_PROVIDER=WIKIPEDIA
INFO_SITE="ALLABOUTBIRDS"
MAX_FILES_SPECIES=0
BIRDNET_USER=${PERCHPI_USER}
APPRISE_NOTIFY_EACH_DETECTION=0
APPRISE_NOTIFY_NEW_SPECIES=0
APPRISE_WEEKLY_REPORT=1
APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY=0
APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES=0
APPRISE_ONLY_NOTIFY_SPECIES_NAMES=""
APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2=""
FREQSHIFT_TOOL=sox
FREQSHIFT_HI=6000
FREQSHIFT_LO=3000
FREQSHIFT_RECONNECT_DELAY=4000
FREQSHIFT_PITCH=-1500
SILENCE_UPDATE_INDICATOR=0
AUTOMATIC_UPDATE=0
CUSTOM_IMAGE=
CUSTOM_IMAGE_TITLE=""
RARE_SPECIES_THRESHOLD=30
LAST_RUN=
THIS_RUN=
IDFILE=${REPO_DIR}/IdentifiedSoFar.txt
LogLevel_BirdnetRecordingService="error"
LogLevel_LiveAudioStreamService="error"
LogLevel_SpectrogramViewerService="error"
EOF
    sudo chown "${PERCHPI_USER}:${PERCHPI_USER}" /etc/birdnet/birdnet.conf
    sudo chmod 664 /etc/birdnet/birdnet.conf
    sudo -u "${PERCHPI_USER}" ln -sf /etc/birdnet/birdnet.conf "${REPO_DIR}/birdnet.conf"
    # D-S8-3 (perchpi:installer-s9-20260906): assert the repo-side conf is a
    # symlink, not a materialized fork. The 2026-08-12 restore materialized
    # /etc/birdnet/birdnet.conf as a regular file, splitting config brain
    # (home edit had no effect until collapsed). Cheap guard against a restore
    # or a stray editor turning the link into a copy.
    [ -L "${REPO_DIR}/birdnet.conf" ] || abort \
        "${REPO_DIR}/birdnet.conf is not a symlink to /etc/birdnet/birdnet.conf (§12, D-S8-3)."

    # BirdDB.txt is not a model asset (§7) — it's created here, touch+header,
    # exactly as install_services.sh always did, and grows on every detection.
    if [ ! -f "${REPO_DIR}/BirdDB.txt" ]; then
        sudo -u "${PERCHPI_USER}" touch "${REPO_DIR}/BirdDB.txt"
        echo "Date;Time;Sci_Name;Com_Name;Confidence;Lat;Lon;Cutoff;Week;Sens;Overlap" \
            | sudo -u "${PERCHPI_USER}" tee -a "${REPO_DIR}/BirdDB.txt" >/dev/null
    fi
}

first_boot_display() {
    read -r -p "${LOG_PREFIX} Fullscreen display on this Pi's monitor? [y/N] " reply
    case "${reply}" in
        [yY]*) ;;
        *) return ;;
    esac

    install_display_packages

    # D-S6-7 (perchpi:installer-s7b-20260813): plain `sudo mkdir` creates these root:root,
    # and the `sudo -u birder tee` further down then fails with Permission
    # denied. Every other mkdir in this script already uses sudo -u; this
    # was the lone outlier (verified by sweep 2026-08-13 -- no siblings).
    sudo -u "${PERCHPI_USER}" mkdir -p \
        "${PERCHPI_HOME}/.config/labwc" "${PERCHPI_HOME}/.config/autostart"

    # swayidle is deliberately NOT included — 2026-07-19 decision (§12.9):
    # on an input-less kiosk, "idle" never ends, so the old autostart's
    # comment ("Keep display awake") and its swayidle call (sleep after 10
    # min, nothing ever wakes it) contradicted each other. Removing it makes
    # the comment true and drops a daemon that can misbehave.
    #
    # Every flag below is scar tissue from a specific failure — ship
    # verbatim, do not "clean up" (§12.9):
    #   --disable-session-crashed-bubble  = post-power-loss "restore?" modal
    #   --overscroll-history-navigation=0 = touchscreen swipe-back
    #   --autoplay-policy=no-user-gesture-required = live audio w/o a click
    #   --check-for-update-interval=31536000 = update nag
    #   --disk-cache-size=52428800 = 50 MB cap. The profile is in /tmp, which Trixie
    #       mounts in RAM; uncapped it held 994 MB on LIVE (D-S16c-2,
    #       perchpi:installer-s19a-kiosk-cache-v1)
    sudo -u "${PERCHPI_USER}" tee "${PERCHPI_HOME}/.config/labwc/autostart" >/dev/null <<'EOF'
# Clean up stale kiosk profile locks (prevents kiosk failure after unclean shutdown)
rm -rf /tmp/perchpi-kiosk-profile/Singleton* 2>/dev/null

# Wait for Wayland + web stack to be ready
sleep 5

chromium \
  --kiosk \
  --user-data-dir=/tmp/perchpi-kiosk-profile \
  --disk-cache-size=52428800 \
  --noerrdialogs \
  --disable-infobars \
  --disable-translate \
  --disable-features=TranslateUI \
  --disable-session-crashed-bubble \
  --check-for-update-interval=31536000 \
  --overscroll-history-navigation=0 \
  --autoplay-policy=no-user-gesture-required \
  --ozone-platform=wayland \
  --app=http://localhost/?view=Overview &
EOF

    sudo -u "${PERCHPI_USER}" tee "${PERCHPI_HOME}/.config/autostart/perchpi-sink-volume.desktop" >/dev/null <<'EOF'
[Desktop Entry]
Type=Application
Name=PerchPi HDMI Volume Boost
Comment=Boost HDMI sink volume to 150% on login (perchpi:sink-volume-boost-v1)
Exec=/usr/bin/pactl set-sink-volume @DEFAULT_SINK@ 150%
Terminal=false
X-GNOME-Autostart-enabled=true
EOF

    # Desktop auto-login — required for the sink-volume autostart to fire.
    if sudo raspi-config nonint do_boot_behaviour B4 2>/dev/null; then
        # D-S17-3 (perchpi:installer-s19a-autologin-birder-v1): B4 sets autologin-user to
        # the invoking (Imager) user, but the kiosk's labwc autostart lives in
        # ${PERCHPI_HOME}. The kiosk session logs in as ${PERCHPI_USER}.
        sudo sed -i "s/^autologin-user=.*/autologin-user=${PERCHPI_USER}/" /etc/lightdm/lightdm.conf
        sudo grep -qx "autologin-user=${PERCHPI_USER}" /etc/lightdm/lightdm.conf \
            || abort "lightdm autologin-user is not ${PERCHPI_USER} after B4 (§12.9, D-S17-3)."
    else
        log "  raspi-config not available; enable Desktop autologin manually."
    fi
    # S15 (perchpi:installer-s16-kiosk-lightdm-only-v1): the kiosk is lightdm's autologin
    # session and nothing else. B4 ALSO writes a tty1 console autologin drop-in; a
    # console autologin on tty1 is what fed LIVE's getty->labwc restart loop (S9).
    sudo rm -f /etc/systemd/system/getty@tty1.service.d/autologin.conf

    # RTK CX101 panel returns 0-byte EDID; force audio over HDMI anyway.
    if ! grep -q '^hdmi_force_edid_audio=1' /boot/firmware/config.txt; then
        {
            echo "# perchpi:hdmi-force-audio-v1"
            echo "hdmi_force_edid_audio=1"
        } | sudo tee -a /boot/firmware/config.txt >/dev/null
    fi

    log "Fullscreen display configured — takes effect after reboot."
}

# ============================================================================
# [§13] Verify — the installer's own DoD
# ============================================================================
# "An installer that cannot prove it worked has not worked." Every failure
# here is a non-zero exit + named reason — no silent partial success.

verify() {
    step "§13 Verify"

    local venv_py="${REPO_DIR}/birdnet/bin/python3"

    # 2. venv imports the real thing
    sudo -u "${PERCHPI_USER}" "${venv_py}" -c \
        "import onnxruntime, scipy.signal, tflite_runtime" \
        || abort "venv cannot import onnxruntime/scipy.signal/tflite_runtime (§13.2)."

    # 3. embedding worker starts and exits clean — the D1/D2 regression.
    # 124 (timeout's own "still running" code) counts as a pass: this is a
    # queue-polling daemon that idles waiting for work, so surviving 10s
    # without crashing IS the clean-start signal, not a failure.
    # C3 (perchpi:installer-s19a-worker-rc-v1): `| tee ... || true` reset PIPESTATUS to
    # (0), so exits 1 and 139 passed too. Capture the worker's own status.
    local worker_rc=0
    # shellcheck disable=SC2024  # the log is the invoking user's, as tee's was
    sudo -u "${PERCHPI_USER}" timeout 10 "${venv_py}" \
        "${REPO_DIR}/perch/process_embedding_queue.py" \
        >/tmp/perchpi-embedding-worker-verify.log 2>&1 || worker_rc=$?
    if [ "${worker_rc}" -ne 0 ] && [ "${worker_rc}" -ne 124 ]; then
        abort "Embedding worker exited ${worker_rc} (§13.3): $(tail -5 /tmp/perchpi-embedding-worker-verify.log)"
    fi
    rm -f /tmp/perchpi-embedding-worker-verify.log

    # 4/5. every homepage/*.php symlink resolves, and a known endpoint
    # returns PHP output — not the index.php HTML shell (the silent-200 trap)
    for f in "${REPO_DIR}"/homepage/*.php; do
        local name; name=$(basename "${f}")
        [ -e "${EXTRACTED}/${name}" ] || abort "Symlink for ${name} does not resolve (§13.4/§9)."
    done
    # perchpi:installer-s19a-gate5-v1 (D-S17-7, audit C6/G5): test the index.php route
    # the UI uses, fail loudly on any HTTP error (curl -f under set -e exited 22 with
    # no ABORT), and require markers only the rendered Overview emits -- overview.php's
    # grid and kiosk.php's live-audio tile. The index.php shell alone is a silent 200.
    [ "$(readlink "${EXTRACTED}/scripts")" = "${REPO_DIR}/scripts" ] \
        || abort "${EXTRACTED}/scripts is not a link to ${REPO_DIR}/scripts (§13.5, D-S17-7)."
    local body body_file http
    body_file=$(mktemp)
    http=$(curl -sS -o "${body_file}" -w '%{http_code}' "http://127.0.0.1/?view=Overview") \
        || { rm -f "${body_file}"; abort "curl to http://127.0.0.1/?view=Overview failed (§13.5)."; }
    body=$(cat "${body_file}")
    rm -f "${body_file}"
    [ "${http}" = "200" ] || abort "/?view=Overview returned HTTP ${http}, expected 200 (§13.5)."
    grep -qi "<?php" <<<"${body}" && abort "/?view=Overview returned raw PHP source, not rendered output (§13.5)."
    { grep -q 'class="overview-grid"' <<<"${body}" && grep -q 'id="kla-tile"' <<<"${body}"; } \
        || abort "/?view=Overview has no Overview markers: PHP failed or only the shell came back (§13.5)."

    # 6. site root reachable
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/)
    [ "${code}" = "200" ] || abort "http://127.0.0.1/ returned ${code}, expected 200 (§13.6)."

    # 7. fresh detections table is empty
    local count
    count=$(sqlite3 "${REPO_DIR}/scripts/birds.db" 'select count(*) from detections;')
    [ "${count}" = "0" ] || abort "detections table has ${count} rows on a fresh install (§13.7)."

    # 8. every systemd unit enabled+active (mic-dependent ones may legitimately
    # fail without a mic attached during this verify pass — enabled is still
    # required of them, active is not).
    local all_units=(birdnet_analysis birdnet_recording birdnet_stats birdnet_log
                      chart_viewer spectrogram_viewer caddy
                      perchpi-live-audio-stream
                      perchpi-retention.timer  # perchpi:installer-s19a-no-archive-timer-v1 (gate 8), D-S17-2
                      perchpi-refresh-bird-photos.timer perchpi-weather-poll.timer
                      perchpi-geomodel-weekly.timer)  # perchpi:s21a-geomodel-on-v1 (gate 8), D2
    for u in "${all_units[@]}"; do
        systemctl is-enabled --quiet "${u}" || abort "${u} is not enabled (§13.8)."
    done
    # perchpi:installer-s19a-no-archive-timer-v1 (gate 8b): MUST-NOT-SHIP, D-S17-2
    ! systemctl is-enabled --quiet perchpi-archive.timer \
        || abort "perchpi-archive.timer is enabled; the archive ships inert (§13.8, D-S17-2)."
    # perchpi:s21a-no-webterm-v1 (gate 8c, D5): the web terminal ships off.
    ! systemctl is-enabled --quiet web_terminal \
        || abort "web_terminal is enabled; it ships off (§13.8, D5)."
    # C10 (perchpi:installer-s19a-gate8-v1): the §2 preflight requires a mic, so every
    # service must be active -- and not crash-looping (B3: a unit that restarts forever
    # is "active" most of the time). NRestarts must hold still across a 30 s settle.
    local svc_units=(birdnet_analysis birdnet_recording birdnet_stats birdnet_log
                     chart_viewer spectrogram_viewer caddy
                     perchpi-live-audio-stream)
    local -A restarts0=()
    for u in "${svc_units[@]}"; do
        systemctl is-active --quiet "${u}" || abort "${u} is enabled but not active (§13.8)."
        restarts0[${u}]=$(systemctl show -p NRestarts --value "${u}")
    done
    sleep 30
    for u in "${svc_units[@]}"; do
        systemctl is-active --quiet "${u}" || abort "${u} stopped within 30 s of starting (§13.8)."
        [ "$(systemctl show -p NRestarts --value "${u}")" = "${restarts0[${u}]}" ] \
            || abort "${u} restarted during a 30 s settle -- crash loop (§13.8)."
    done

    # 9. models present + checksums match
    for name in "${!MODEL_ASSETS[@]}"; do
        local dest="${REPO_DIR}/${MODEL_DEST[${name}]}"
        [ -f "${dest}" ] || abort "${dest} missing (§13.9)."
        # perchpi:s21a-gate9-v1 (D-S20-5): the models are 660 birder:birder. Hash them with sudo,
        # and abort loudly if the read fails (under set -e a failed read exited silently).
        local got; got=$(sudo sha256sum "${dest}" | cut -d' ' -f1) \
            || abort "${dest} could not be read for its checksum (§13.9, D-S20-5)."
        [ "${got}" = "${MODEL_ASSETS[${name}]}" ] || abort "${dest} checksum mismatch (§13.9)."
    done

    # 10. birdnet.conf secret keys blank (freshly generated ones only — the
    # web UI password the owner just set is expected to be non-blank)
    for key in GEMINI_API_KEY HEARTBEAT_URL WUNDERGROUND_API_KEY TAILSCALE_AUTHKEY; do
        grep -q "^${key}=$" /etc/birdnet/birdnet.conf \
            || abort "${key} is not blank in a fresh birdnet.conf (§13.10)."
    done

    # 11. default creds REJECTED — the hard gate
    # D-S6-11 (perchpi:installer-s7b-20260813): this used to authenticate as
    # birdnet:birdnet -- the shipped default that §12.5 exists to
    # eliminate. If that passes, either the rotation never reached the
    # PHP side or the gate is proving a credential that must no longer
    # work; either way the check could not do its job. Use the password
    # set during §12.5, and assert the default is now REJECTED.
    local default_code
    default_code=$(curl -s -o /dev/null -w '%{http_code}' -u birdnet:birdnet \
                   "http://127.0.0.1/?view=Settings")
    [ "${default_code}" = "401" ] || abort \
        "Shipped default birdnet:birdnet still authenticates (got ${default_code});"\
        " §12.5 password rotation did not take (§13, D-S6-11)."
    code=$(curl -s -o /dev/null -w '%{http_code}' -u "birdnet:${CADDY_PWD}" \
           "http://127.0.0.1/?view=Settings")
    # D-S17-1 (perchpi:installer-s19a-gate11-v1): this test was inverted -- it aborted
    # when the password set at §12.5 WORKED.
    [ "${code}" = "200" ] || abort "The web password set at §12.5 was rejected (got ${code}) (§13.11)."
    # STAGE-2 S2-1: if PHP can't parse the conf, get_config() has no CADDY_PWD and the
    # old `==` let an EMPTY password in. Prove PHP reads a non-empty one.
    php -r '$c = @parse_ini_string(preg_replace("~^#+.*$~m", "", (string)@file_get_contents("/etc/birdnet/birdnet.conf"))); exit(is_array($c) && isset($c["CADDY_PWD"]) && (string)$c["CADDY_PWD"] !== "" ? 0 : 1);' \
        || abort "PHP cannot read a non-empty CADDY_PWD from birdnet.conf (§13.11, S2-1)."

    # 12. /perchpi-hls/* — LAN source 200, non-LAN source 403 (approximated:
    # loopback counts as LAN; true non-LAN requires an external vantage point
    # and is not fully verifiable from localhost, so this checks the LAN leg
    # and confirms the @notlan matcher exists in the shipped Caddyfile)
    grep -q '@notlan not remote_ip' /etc/caddy/Caddyfile \
        || abort "Caddyfile is missing the HLS LAN-allowlist matcher (§13.12)."
    # D-S17-8 (perchpi:installer-s19a-gate12-v1): request the playlist through Caddy;
    # the grep above only reads the file this script wrote. ffmpeg needs a few seconds
    # to write the first playlist after the unit starts.
    local hls_code="000"
    for _ in $(seq 1 15); do
        hls_code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1/perchpi-hls/stream.m3u8") || true
        [ "${hls_code}" = "200" ] && break
        sleep 2
    done
    [ "${hls_code}" = "200" ] \
        || abort "/perchpi-hls/stream.m3u8 returned ${hls_code}, expected 200 (§13.12, D-S17-8)."

    # 13. sshd survives — checked here as "unit enabled", full reboot-survival
    # is confirmed by the operator after the final reboot this script triggers
    # D4 (perchpi:installer-s19a-ssh-gate-v1): SSH is the owner's Imager choice, and a
    # keyboard-only Desktop install has it off -- that must not abort here. When SSH IS
    # enabled it must have host keys (D-S3-1).
    if systemctl is-enabled --quiet ssh || systemctl is-enabled --quiet sshd; then
        compgen -G '/etc/ssh/ssh_host_*_key' >/dev/null \
            || abort "SSH is enabled but /etc/ssh has no host keys (§13.13, D-S3-1)."
    else
        log "SSH is not enabled (an Imager setting); §13.13 skipped."
    fi

    # 14. NOTICE present at repo root AND in the model dir
    [ -f "${REPO_DIR}/NOTICE" ] || abort "NOTICE missing from repo root (§13.14)."
    [ -f "${REPO_DIR}/model/NOTICE" ] || abort "NOTICE missing from model dir (§13.14, §12.10)."

    # 15. D-S13-1 (perchpi:installer-s16-seed-gate-v1): the world-readable cloud-init
    # seed carries no password hash and no Connect token
    if [ -f /boot/firmware/user-data ]; then
        ! grep -qE "${SEED_SECRET_RE}" /boot/firmware/user-data || abort \
            "/boot/firmware/user-data still holds a password hash or Connect token (§13.15, D-S13-1)."
    fi

    # 16. perchpi:s21a2-d1-v1 (D1): the web's sudo is the literal list, nothing more, and the
    # password helper really changes Caddy's hash (re-set to the same password, then log in).
    sudo visudo -c >/dev/null || abort "sudo's configuration fails visudo -c (§13.16, D1)."
    [ "$(stat -c '%U:%G %a' /etc/sudoers.d/010-perchpi-web)" = "root:root 440" ] \
        || abort "/etc/sudoers.d/010-perchpi-web is not root:root 0440 (§13.16, D1)."
    local helper
    for helper in perchpi-time perchpi-caddy-pwd; do
        [ "$(stat -c '%U:%G %a' "/usr/local/sbin/${helper}")" = "root:root 755" ] \
            || abort "/usr/local/sbin/${helper} is not root:root 0755 (§13.16, D1)."
    done
    sudo -n -l -U caddy /usr/bin/systemctl restart birdnet_stats.service >/dev/null \
        || abort "caddy may not restart birdnet_stats; the web's Services page is dead (§13.16, D1)."
    ! sudo -n -l -U caddy /usr/bin/bash >/dev/null 2>&1 \
        || abort "caddy may run a root shell (§13.16, D1)."
    ! sudo -n -l -U caddy /usr/bin/systemctl restart caddy.service >/dev/null 2>&1 \
        || abort "caddy may restart units outside the list (§13.16, D1)."
    printf '%s\n' "${CADDY_PWD}" | sudo /usr/local/sbin/perchpi-caddy-pwd >/dev/null \
        || abort "perchpi-caddy-pwd could not re-set the current web password (§13.16, D1)."
    code=$(curl -s -o /dev/null -w '%{http_code}' -u "birdnet:${CADDY_PWD}" \
           "http://127.0.0.1/?view=Settings")
    [ "${code}" = "200" ] || abort "The web password stopped working after perchpi-caddy-pwd (got ${code}) (§13.16, D1)."

    log "All §13 verification gates passed."
}

# ============================================================================
# Main
# ============================================================================

main() {
    preflight
    install_apt_packages
    create_user_and_dirs
    clone_repo
    setup_venv
    fetch_models
    first_boot_hostname     # needed before avahi-alias@ unit is enabled
    first_boot_identity
    strip_cloudinit_seed    # D-S13-1
    first_boot_timezone
    first_boot_credentials  # sets CADDY_PWD, needed before configure_caddy
    configure_caddy
    install_symlinks
    install_systemd_units
    install_web_sudo        # perchpi:s21a2-d1-v1 (D1)
    init_database
    first_boot_microphone
    first_boot_station_conf
    first_boot_display
    # D-S6-5 (perchpi:installer-s7b-20260813): same glob defect as §8.
    local fpm_unit
    fpm_unit=$(systemctl list-unit-files --no-legend "php*-fpm.service" \
                 | awk '{print $1; exit}')
    [ -n "${fpm_unit}" ] || abort "No php*-fpm.service unit found (D-S6-5)."
    sudo systemctl restart caddy "${fpm_unit}"
    sudo systemctl start birdnet_analysis birdnet_recording birdnet_stats \
        birdnet_log chart_viewer spectrogram_viewer \
        perchpi-live-audio-stream
    # perchpi:s21a-geomodel-on-v1 (D2): one prediction now, after first_boot_station_conf wrote
    # LATITUDE/LONGITUDE, so the Perch feed has a cache before the first Monday timer run.
    log "Running the first geomodel prediction (D2)..."
    sudo systemctl start perchpi-geomodel-weekly.service \
        || abort "The first geomodel prediction failed (D2). See: journalctl -u perchpi-geomodel-weekly"
    verify

    step "Done"
    log "PerchPi installed and verified. Rebooting in 10s to apply hostname," \
        " display, and first-boot machine-identity changes (Ctrl-C to skip)."
    sleep 10
    sudo reboot
}

main "$@"

# perchpi:installer-s9-20260906 — S9 batch applied (2026-09-06)
# perchpi:installer-s16-20260922 — S16 batch applied (2026-09-22)
# perchpi:installer-s19a-20261003 — S19a batch applied (2026-10-03)
