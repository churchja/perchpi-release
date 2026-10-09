#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────
# validate_birdnet_conf.sh — verify birdnet.conf parses under BOTH
# bash (source) AND PHP (parse_ini_string), since PerchPi reads it via
# PHP but BirdNET-Pi shell scripts read it via source. Mismatched
# parsing rules between the two are the recurring failure mode.
# ─────────────────────────────────────────────────────────────────────
CFG="${1:-/etc/birdnet/birdnet.conf}"
echo "=== Validating $CFG ==="

if [ ! -r "$CFG" ]; then
  echo "  ✗ Cannot read $CFG"
  exit 2
fi

echo
echo "── bash source test ──"
if ( set -e; source "$CFG" ) 2>/dev/null; then
  echo "  ✓ bash OK"
else
  echo "  ✗ bash FAILED — run 'bash -n $CFG' for line number"
  exit 1
fi

echo
echo "── PHP parse_ini_string test (PerchPi-equivalent) ──"
php -r '
$src = preg_replace("~^#+.*$~m", "", file_get_contents($argv[1]));
$r = parse_ini_string($src);
if ($r) {
  printf("  ✓ PHP OK: %d keys parsed\n", count($r));
  foreach (["LATITUDE","LONGITUDE","CADDY_PWD","REC_CARD","MODEL","SITE_NAME","BIRDWEATHER_ID"] as $k) {
    printf("    %-18s = %s\n", $k, $r[$k] ?? "[MISSING]");
  }
} else {
  echo "  ✗ PHP FAILED\n";
  print_r(error_get_last());
  exit(1);
}
' "$CFG" || exit 1

echo
echo "── PerchPi session cache state ──"
SESS_COUNT=$(sudo find /var/lib/php/sessions -name 'sess_*' 2>/dev/null | wc -l)
echo "  PHP session files cached: $SESS_COUNT"
echo "  (clear with: sudo rm -f /var/lib/php/sessions/sess_*)"

echo
echo "✓ All validations passed."
