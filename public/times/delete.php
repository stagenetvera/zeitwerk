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


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  if (is_safe_return($return_to)) redirect($return_to); else redirect('/times/index.php');
  exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$return_to = pick_return_to('/times/index.php');

$st = $pdo->prepare('SELECT status FROM times WHERE id = ? AND account_id = ?');
$st->execute([$id, (int)auth_user()['account_id']]);
$cur = $st->fetch();

if (!$cur) {
  echo '<div class="alert alert-danger">Zeiteintrag nicht gefunden.</div>';
  echo '<a class="btn btn-outline-secondary" href="'.h($return_to).'">Zurück</a>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}

if (($cur['status'] ?? '') === 'abgerechnet') {
  echo '<div class="alert alert-warning">Abgerechnete Zeiten können nicht gelöscht werden.</div>';
  echo '<a class="btn btn-outline-secondary" href="'.h($return_to).'">Zurück</a>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}

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
