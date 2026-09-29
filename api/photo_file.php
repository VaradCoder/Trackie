<?php
/**
 * Streams a user's own photo from private storage.
 *   GET api/photo_file.php?id=123          full size
 *   GET api/photo_file.php?id=123&s=t      thumbnail
 * Only the owner can read it; anything else is a 404 (don't reveal existence).
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../app/Modules/Photography/PhotoStorage.php';

requireAuth();
$uid = currentUserId();
$id  = (int)($_GET['id'] ?? 0);

$row  = $id ? fetchOne("SELECT file, thumb FROM photo_images WHERE id=? AND user_id=?", [$id, $uid]) : null;
$name = $row ? (($_GET['s'] ?? '') === 't' ? $row['thumb'] : $row['file']) : null;
$path = $name ? PhotoStorage::path($uid, $name) : null;

if (!$path || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Not found');
}

// Stored names are random and never reused, so the content never changes:
// cache hard, but only in this user's browser (private).
header_remove('Pragma');   // session_start() sends no-cache headers by default
header_remove('Expires');
$etag = '"' . md5($name . filemtime($path)) . '"';
header('Cache-Control: private, max-age=2592000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
$ext = pathinfo($path, PATHINFO_EXTENSION);
header('Content-Type: ' . (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
readfile($path);
