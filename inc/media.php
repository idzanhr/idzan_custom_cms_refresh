<?php
declare(strict_types=1);

const IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'image/avif' => 'avif',
];

/**
 * Store an uploaded image under uploads/YYYY/MM/ and return its relative path.
 * JPEG/PNG/WebP are re-encoded with GD (when available), which also strips EXIF
 * data such as GPS location from phone photos.
 */
function store_upload(array $file, string $nameHint = ''): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $codes = [UPLOAD_ERR_INI_SIZE => 'File is larger than the server allows (upload_max_filesize).',
                  UPLOAD_ERR_FORM_SIZE => 'File is too large.', UPLOAD_ERR_PARTIAL => 'Upload was interrupted.',
                  UPLOAD_ERR_NO_FILE => 'No file was sent.'];
        throw new RuntimeException($codes[$file['error'] ?? 4] ?? 'Upload failed.');
    }
    $max = (int)cfg('upload_max_mb', 10) * 1024 * 1024;
    if ($file['size'] > $max) throw new RuntimeException('Image is larger than ' . cfg('upload_max_mb', 10) . ' MB.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(IMAGE_TYPES[$mime])) throw new RuntimeException('Only JPG, PNG, WebP, GIF or AVIF images are allowed.');
    $ext = IMAGE_TYPES[$mime];
    if ($mime !== 'image/avif' && @getimagesize($file['tmp_name']) === false) throw new RuntimeException('That file is not a valid image.');

    $dir = 'uploads/' . date('Y/m');
    $abs = public_dir() . '/' . $dir;
    if (!is_dir($abs) && !mkdir($abs, 0775, true)) throw new RuntimeException('Cannot create ' . $dir . ' — check folder permissions.');

    $base = slugify(pathinfo($nameHint ?: $file['name'], PATHINFO_FILENAME));
    $base = substr($base, 0, 60);
    $name = $base . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    $target = $abs . '/' . $name;

    if (!reencode_image($file['tmp_name'], $target, $mime)) {
        if (!move_uploaded_file($file['tmp_name'], $target) && !rename($file['tmp_name'], $target)) {
            throw new RuntimeException('Could not save the image.');
        }
    }
    @chmod($target, 0644);
    img_convert_upload($dir . '/' . $name);   // AVIF + WebP copies right away
    return $dir . '/' . $name;
}

function reencode_image(string $src, string $target, string $mime): bool
{
    if (!extension_loaded('gd')) return false;
    $loaders = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'];
    if (!isset($loaders[$mime]) || !function_exists($loaders[$mime])) return false;
    $img = @$loaders[$mime]($src);
    if (!$img) return false;

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
        if ($rot) $img = imagerotate($img, $rot, 0);
    }

    $maxPx = (int)cfg('image_max_px', 2400);
    $w = imagesx($img);
    $h = imagesy($img);
    if ($maxPx > 0 && max($w, $h) > $maxPx) {
        $scale = $maxPx / max($w, $h);
        $nw = (int)round($w * $scale);
        $nh = (int)round($h * $scale);
        $res = imagecreatetruecolor($nw, $nh);
        if ($mime !== 'image/jpeg') {
            imagealphablending($res, false);
            imagesavealpha($res, true);
        }
        imagecopyresampled($res, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $img = $res;
    } elseif ($mime !== 'image/jpeg') {
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }

    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($img, $target, 85),
        'image/png'  => imagepng($img, $target, 7),
        'image/webp' => imagewebp($img, $target, 85),
    };
    if ($ok) @unlink($src);
    return (bool)$ok;
}
