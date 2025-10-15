<?php
// public/times/delete.php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('/times/index.php');
  exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$return_to = $_POST['return_to'] ?? '';

function is_safe_return($url) {
  if (!$url) return false;
  if (preg_match('~^(?:https?:)?//~i', $url)) return false;
  return str_starts_with($url, '/');
}

if ($id <= 0) {
  flash('Ungültige Zeit-ID.', 'danger');
  if (is_safe_return($return_to)) redirect($return_to); else redirect('/times/index.php');
  exit;
}

// Ensure ownership
$st = $pdo->prepare('SELECT id FROM times WHERE id = ? AND account_id = ? AND user_id = ?');
$st->execute([$id, $account_id, $user_id]);
if (!$st->fetchColumn()) {
  flash('Zeiteintrag nicht gefunden.', 'danger');
  if (is_safe_return($return_to)) redirect($return_to); else redirect('/times/index.php');
  exit;
}

// TODO: Block if time is already invoiced in future integration

$del = $pdo->prepare('DELETE FROM times WHERE id = ? AND account_id = ? AND user_id = ?');
$del->execute([$id, $account_id, $user_id]);

flash('Zeiteintrag gelöscht.', 'success');
if (is_safe_return($return_to)) redirect($return_to);
else redirect('/times/index.php');
exit;
