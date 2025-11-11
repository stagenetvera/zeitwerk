<?php
// public/settings/file.php
require __DIR__ . '/../../src/bootstrap.php';
require_login();

$rel = $_GET['path'] ?? '';
$rel = trim($rel, '/');
$base = realpath(__DIR__ . '/../../storage');

if (!$rel || strpos($rel, '..') !== false) {
  http_response_code(400);
  exit('Bad path');
}

$file = realpath($base . '/' . $rel);
if (!$file || strpos($file, $base) !== 0 || !is_file($file)) {
  http_response_code(404);
  exit('Not found');
}

// Content-Type anhand der Endung bestimmen
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$type = [
  'pdf' => 'application/pdf',
  'png' => 'image/png',
  'jpg' => 'image/jpeg',
  'jpeg' => 'image/jpeg',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=86400');
readfile($file);