<?php
/* ============================================================================
   remove_user_photo.php — delete personal photo + sidecar for a species.
   perchpi:user-photo-remove-v1

   POST application/x-www-form-urlencoded with field:
     sci_name : Scientific name (e.g., "Cardinalis cardinalis")

   Auth: requires is_authenticated() (PHP_AUTH_USER/PW vs CADDY_PWD).

   Soft undo: deletes <slug>.user.jpg + <slug>.user.json. Provider chain
   then falls back to .wikipedia.jpg / legacy .jpg automatically.
   ============================================================================ */

require_once __DIR__ . '/../scripts/common.php';

header('Content-Type: application/json');

function pp_json_error($code, $msg, $extra = []) {
  http_response_code($code);
  if ($code === 401) {
    header('WWW-Authenticate: Basic realm="PerchPi"');
  }
  echo json_encode(array_merge(['error' => $msg], $extra));
  exit;
}

function pp_sci_slug($sci_name) {
  $s = preg_replace('/[^A-Za-z ]/', '', $sci_name);
  $s = trim($s);
  return str_replace(' ', '_', $s);
}

// Auth
if (!is_authenticated()) {
  pp_json_error(401, 'authentication required');
}

// Method
csrf_check(); // perchpi:s21a-csrf-v1 (stage-2 §4)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  pp_json_error(405, 'POST required');
}

// sci_name validation
$sci_name = trim($_POST['sci_name'] ?? '');
if ($sci_name === '' || !preg_match("/^[A-Za-z][A-Za-z\\-' ]+[A-Za-z]$/", $sci_name)) {
  pp_json_error(400, 'invalid sci_name', ['received' => $sci_name]);
}

$slug = pp_sci_slug($sci_name);
if ($slug === '') {
  pp_json_error(400, 'sci_name produced empty slug');
}

$bdir = '/home/birder/BirdSongs/Extracted/bird_images';
$user_jpg  = $bdir . '/' . $slug . '.user.jpg';
$user_json = $bdir . '/' . $slug . '.user.json';

$removed = [];
$failed  = [];

if (file_exists($user_jpg)) {
  if (@unlink($user_jpg)) { $removed[] = '.user.jpg'; }
  else                     { $failed[] = '.user.jpg'; }
}
if (file_exists($user_json)) {
  if (@unlink($user_json)) { $removed[] = '.user.json'; }
  else                      { $failed[] = '.user.json'; }
}

if (!empty($failed)) {
  pp_json_error(500, 'some files could not be removed',
    ['removed' => $removed, 'failed' => $failed]);
}

echo json_encode([
  'ok'       => true,
  'sci_name' => $sci_name,
  'slug'     => $slug,
  'removed'  => $removed,
]);
