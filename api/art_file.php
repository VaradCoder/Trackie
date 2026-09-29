<?php
/** Streams an artwork image to its owner (?id=, &s=t for thumbnail). 404 for anyone else. */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../app/Modules/Photography/PhotoStorage.php';

requireAuth();
$uid  = currentUserId();
$row  = fetchOne("SELECT image_file, image_thumb FROM artworks WHERE id=? AND user_id=?", [(int)($_GET['id'] ?? 0), $uid]);
$name = $row ? ((($_GET['s'] ?? '') === 't' && $row['image_thumb']) ? $row['image_thumb'] : $row['image_file']) : null;
$path = $name ? PhotoStorage::path($uid, $name) : null;
if (!$path || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain'); exit('Not found'); }

header_remove('Pragma');
header_remove('Expires');
$etag = '"' . md5($name . filemtime($path)) . '"';
header('Cache-Control: private, max-age=2592000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
$ext = pathinfo($path, PATHINFO_EXTENSION);
header('Content-Type: ' . (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
readfile($path);
