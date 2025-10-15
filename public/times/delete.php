<?php
// public/times/delete_v2.php
require __DIR__ . '/../../src/layout/header.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

function is_safe_return($url) {
  if (!$url) return false;
  if (preg_match('~^(?:https?:)?//~i', $url)) return false;
  return str_starts_with($url, '/');
}

$return_to = $_POST['return_to'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  if (is_safe_return($return_to)) redirect($return_to); else redirect('/times/index.php');
  exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
  flash('Ungültige Zeit-ID.', 'danger');
  if (is_safe_return($return_to)) redirect($return_to); else redirect('/times/index.php');
  exit;
}

// Ownership + Status prüfen
$st = $pdo->prepare('SELECT id, status FROM times WHERE id = ? AND account_id = ? AND user_id = ?');
$st->execute([$id, $account_id, $user_id]);
$row = $st->fetch();

if (!$row) {
  flash('Zeiteintrag nicht gefunden.', 'danger');
  if (is_safe_return($return_to)) redirect($return_to); else redirect('/times/index.php');
  exit;
}

$status = strtolower((string)$row['status']);
// Sperre: nicht löschen, wenn bereits abgerechnet
if ($status === 'abgerechnet') {
  flash('Dieser Zeiteintrag ist bereits abgerechnet und kann nicht gelöscht werden.', 'warning');
  if (is_safe_return($return_to)) redirect($return_to); else redirect('/times/index.php');
  exit;
}

// (Optional) weitere Sperren hier ergänzen, z.B. wenn Zeiteintrag an Rechnungsposition verknüpft ist.

$del = $pdo->prepare('DELETE FROM times WHERE id = ? AND account_id = ? AND user_id = ?');
$del->execute([$id, $account_id, $user_id]);

flash('Zeiteintrag gelöscht.', 'success');
if (is_safe_return($return_to)) redirect($return_to); else redirect('/times/index.php');
exit;
