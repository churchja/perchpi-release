<?php
/* ============================================================================
   upload_user_photo.php — accept user-uploaded bird photo for a species.
   perchpi:user-photo-upload-v1

   POST multipart/form-data with fields:
     sci_name : Scientific name (e.g., "Cardinalis cardinalis")
     photo    : Image file (JPEG, PNG, WebP, or HEIC; max 12MB)

   Auth: requires is_authenticated() (PHP_AUTH_USER/PW vs CADDY_PWD).

   Pipeline:
     1. Auth check  -> 401 on failure
     2. Method = POST  -> 405 otherwise
     3. sci_name validation + DB lookup  -> 400 on missing/unknown
     4. File present + size <= 12MB  -> 400 / 413
     5. MIME sniff via finfo  -> 400 if not in allowed list
     6. Imagick: auto-orient (EXIF), resize 2400px long edge, strip metadata,
        encode JPEG q90  -> 500 on failure
     7. Write <bird_images>/<slug>.user.jpg + <slug>.user.json sidecar
     8. Return JSON {ok, sci_name, photo_url}
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

// ---- 1. Auth ----
if (!is_authenticated()) {
  pp_json_error(401, 'authentication required');
}

// ---- 2. Method ----
csrf_check(); // perchpi:s21a-csrf-v1 (stage-2 §4)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  pp_json_error(405, 'POST required');
}

// ---- 3. sci_name validation ----
$sci_name = trim($_POST['sci_name'] ?? '');
if ($sci_name === '' || !preg_match("/^[A-Za-z][A-Za-z\\-' ]+[A-Za-z]$/", $sci_name)) {
  pp_json_error(400, 'invalid sci_name', ['received' => $sci_name]);
}

// Verify species exists in DB (defense against arbitrary writes)
$db_path = __DIR__ . '/../scripts/birds.db';
if (!file_exists($db_path)) {
  pp_json_error(500, 'database not found');
}
try {
  $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
  $db->busyTimeout(1000);
  $stmt = $db->prepare('SELECT 1 FROM detections WHERE Sci_Name = :sn LIMIT 1');
  $stmt->bindValue(':sn', $sci_name, SQLITE3_TEXT);
  $r = $stmt->execute();
  $found = $r && $r->fetchArray() !== false;
  $db->close();
  if (!$found) {
    pp_json_error(400, 'species not found in detections database', ['sci_name' => $sci_name]);
  }
} catch (Throwable $e) {
  pp_json_error(500, 'database query failed', ['detail' => $e->getMessage()]);
}

// ---- 4. File present + size ----
if (!isset($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  $err_code = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
  pp_json_error(400, 'upload failed', ['upload_err' => $err_code]);
}

$file = $_FILES['photo'];
$max_bytes = 12 * 1024 * 1024;
if ($file['size'] > $max_bytes) {
  pp_json_error(413, 'file too large (max 12MB)', ['size' => $file['size']]);
}

// ---- 5. MIME sniff ----
$allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detected_mime = $finfo->file($file['tmp_name']);
if (!in_array($detected_mime, $allowed_mimes, true)) {
  pp_json_error(400, 'unsupported image type', [
    'detected_mime' => $detected_mime,
    'allowed' => $allowed_mimes,
  ]);
}

// ---- 6. Imagick processing ----
if (!class_exists('Imagick')) {
  pp_json_error(500, 'imagick not available — run: sudo apt install php-imagick');
}

try {
  $im = new Imagick($file['tmp_name']);

  // Auto-orient based on EXIF, then mark as upright
  $orientation = $im->getImageOrientation();
  switch ($orientation) {
    case Imagick::ORIENTATION_BOTTOMRIGHT:
      $im->rotateImage('#000', 180); break;
    case Imagick::ORIENTATION_RIGHTTOP:
      $im->rotateImage('#000', 90);  break;
    case Imagick::ORIENTATION_LEFTBOTTOM:
      $im->rotateImage('#000', -90); break;
  }
  $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);

  // Resize 2400px long edge if larger; preserve smaller-than-target as-is
  $w = $im->getImageWidth();
  $h = $im->getImageHeight();
  $long = max($w, $h);
  if ($long > 2400) {
    $scale = 2400 / $long;
    $im->resizeImage((int)round($w * $scale), (int)round($h * $scale),
                     Imagick::FILTER_LANCZOS, 1);
  }

  // Strip EXIF/ICC (orientation already burned into pixels above)
  $im->stripImage();

  // Encode JPEG q90
  $im->setImageFormat('jpeg');
  $im->setImageCompressionQuality(90);
  $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
} catch (Throwable $e) {
  pp_json_error(500, 'image processing failed', ['detail' => $e->getMessage()]);
}

// ---- 7. Save ----
$slug = pp_sci_slug($sci_name);
if ($slug === '') {
  pp_json_error(400, 'sci_name produced empty slug');
}

$bdir = '/home/birder/BirdSongs/Extracted/bird_images';
if (!is_dir($bdir)) {
  if (!@mkdir($bdir, 0755, true)) {
    pp_json_error(500, 'failed to create bird_images directory');
  }
}

$target = $bdir . '/' . $slug . '.user.jpg';
$sidecar = $bdir . '/' . $slug . '.user.json';

try {
  $im->writeImage($target);
  $final_w = $im->getImageWidth();
  $final_h = $im->getImageHeight();
  $im->clear();
  $im->destroy();
} catch (Throwable $e) {
  pp_json_error(500, 'save failed', ['detail' => $e->getMessage()]);
}

// Sidecar metadata
$metadata = [
  'uploaded_at'       => time(),
  'uploaded_by'       => $_SERVER['PHP_AUTH_USER'] ?? 'unknown',
  'sci_name'          => $sci_name,
  'original_filename' => $file['name'],
  'original_size'     => $file['size'],
  'original_mime'     => $detected_mime,
  'final_width'       => $final_w,
  'final_height'      => $final_h,
];
@file_put_contents($sidecar, json_encode($metadata, JSON_PRETTY_PRINT));

// ---- 8. Success ----
echo json_encode([
  'ok'        => true,
  'sci_name'  => $sci_name,
  'slug'      => $slug,
  'photo_url' => '/bird_images/' . rawurlencode($slug) . '.user.jpg',
  'width'     => $final_w,
  'height'    => $final_h,
]);
