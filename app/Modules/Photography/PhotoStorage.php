<?php
/**
 * Private photo files: storage/photos/{uid}/{random}.jpg (+ _t thumbnail).
 * storage/.htaccess denies all direct access; files are served only by
 * api/photo_file.php after an ownership check.
 *
 * The browser already re-encodes photos to ≤2560px JPEG before upload (which
 * also bakes in rotation and strips GPS). The server is the second line:
 *   - GD available  → fix orientation, cap at 2560px, make a 640px thumbnail.
 *   - GD missing    → keep the file as sent; the thumbnail is the same file.
 * A missing extension must degrade to "keep the original", never to a failure.
 */

final class PhotoStorage
{
    public const MAX_BYTES = 12 * 1048576;
    private const MAX_PX   = 2560;
    private const THUMB_PX = 640;
    private const MIME     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function dir(int $uid): string
    {
        return ROOT_PATH . '/storage/photos/' . $uid;
    }

    /** Absolute path for a stored name, or null if the name isn't one we generate. */
    public static function path(int $uid, string $name): ?string
    {
        if (!preg_match('/^[a-f0-9]{24}(_t)?\.(jpg|png|webp)$/', $name)) return null;
        return self::dir($uid) . '/' . $name;
    }

    /**
     * Validate + store an uploaded file.
     * @return array{file:string, thumb:string, width:?int, height:?int, bytes:int, exif:array}
     * @throws InvalidArgumentException on a bad upload
     */
    public static function store(int $uid, array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $tooBig = in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new InvalidArgumentException($tooBig ? 'That photo is too large to upload.' : 'Upload failed — try again.');
        }
        if ($upload['size'] > self::MAX_BYTES) throw new InvalidArgumentException('Photos must be under 12 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if (!isset(self::MIME[$mime])) throw new InvalidArgumentException('Only JPEG, PNG and WebP photos are supported.');
        if (!@getimagesize($upload['tmp_name'])) throw new InvalidArgumentException("That file isn't a readable image.");

        $dir = self::dir($uid);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Photo storage is not writable.');

        $base = bin2hex(random_bytes(12));
        $file = $base . '.' . self::MIME[$mime];
        $abs  = $dir . '/' . $file;
        if (!move_uploaded_file($upload['tmp_name'], $abs)) throw new RuntimeException('Could not save the photo.');

        // Read EXIF from the file as received (only present when the browser
        // sent the original, e.g. API callers or browsers without canvas).
        $exif = $mime === 'image/jpeg' ? self::readExif($abs) : [];

        $thumb = $file;
        if (extension_loaded('gd')) {
            $orientation = (int)($exif['_orientation'] ?? 1);
            if (self::resize($abs, $abs, self::MAX_PX, 86, $orientation)) {
                // Re-encoded as JPEG: make sure the extension says so.
                if (!str_ends_with($file, '.jpg')) {
                    $newFile = $base . '.jpg';
                    rename($abs, $dir . '/' . $newFile);
                    $file = $newFile; $abs = $dir . '/' . $file;
                }
            }
            $tName = $base . '_t.jpg';
            if (self::resize($abs, $dir . '/' . $tName, self::THUMB_PX, 78, 1, true)) $thumb = $tName;
        }
        unset($exif['_orientation']);

        $size = @getimagesize($abs);
        return [
            'file' => $file, 'thumb' => $thumb,
            'width' => $size[0] ?? null, 'height' => $size[1] ?? null,
            'bytes' => (int)filesize($abs) + ($thumb !== $file ? (int)filesize($dir . '/' . $thumb) : 0),
            'exif' => $exif,
        ];
    }

    public static function delete(int $uid, string $file, string $thumb): void
    {
        foreach (array_unique([$file, $thumb]) as $n) {
            $p = self::path($uid, $n);
            if ($p && is_file($p)) @unlink($p);
        }
    }

    /**
     * Downscale (and rotate per EXIF orientation) into $dest as JPEG.
     * $always forces a write even when no resize is needed (thumbnails).
     */
    private static function resize(string $src, string $dest, int $maxPx, int $quality, int $orientation = 1, bool $always = false): bool
    {
        $info = @getimagesize($src);
        if (!$info) return false;
        [$w, $h] = $info;
        $needsRotate = in_array($orientation, [3, 6, 8], true);
        $scale = min(1, $maxPx / max($w, $h));
        if (!$always && $scale >= 1 && !$needsRotate) return false;

        $img = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default      => false,
        };
        if (!$img) return false;
        if ($needsRotate) {
            $rot = imagerotate($img, [3 => 180, 6 => -90, 8 => 90][$orientation], 0);
            if ($rot) { imagedestroy($img); $img = $rot; [$w, $h] = [imagesx($img), imagesy($img)]; }
        }
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = @imagejpeg($dst, $dest, $quality);
        imagedestroy($img);
        imagedestroy($dst);
        return (bool)$ok;
    }

    /** Camera settings from EXIF. Missing tags stay missing. GPS is ignored. */
    private static function readExif(string $path): array
    {
        if (!function_exists('exif_read_data')) return [];
        $e = @exif_read_data($path);
        if (!is_array($e)) return [];
        $num = static function ($v): ?float {
            if (is_array($v)) $v = $v[0] ?? null;
            if ($v === null || $v === '') return null;
            if (is_string($v) && str_contains($v, '/')) {
                [$a, $b] = array_map('floatval', explode('/', $v, 2));
                return $b ? $a / $b : null;
            }
            return is_numeric($v) ? (float)$v : null;
        };
        $out = ['_orientation' => (int)($e['Orientation'] ?? 1)];
        $make  = trim((string)($e['Make'] ?? ''));
        $model = trim((string)($e['Model'] ?? ''));
        if ($model !== '') $out['camera'] = ($make && stripos($model, strtok($make, ' ')) === false) ? "$make $model" : $model;
        $lens = $e['LensModel'] ?? $e['UndefinedTag:0xA434'] ?? null;
        if (is_string($lens) && trim($lens) !== '') $out['lens'] = trim($lens);
        $iso = $e['ISOSpeedRatings'] ?? $e['PhotographicSensitivity'] ?? null;
        if (is_array($iso)) $iso = $iso[0] ?? null;
        if (is_numeric($iso)) $out['iso'] = (int)$iso;
        $t = $num($e['ExposureTime'] ?? null);
        if ($t) $out['shutter'] = $t >= 1 ? rtrim(rtrim(number_format($t, 1), '0'), '.') : '1/' . round(1 / $t);
        $f = $num($e['FNumber'] ?? null);
        if ($f) $out['aperture'] = round($f, 1);
        $fl = $num($e['FocalLength'] ?? null);
        if ($fl) $out['focal_mm'] = round($fl, 1);
        $dt = $e['DateTimeOriginal'] ?? $e['DateTime'] ?? null;
        if (is_string($dt) && preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2})/', $dt, $m)) $out['taken_at'] = "$m[1]-$m[2]-$m[3] $m[4]";
        return $out;
    }
}
