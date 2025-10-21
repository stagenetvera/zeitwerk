<?php
// public/times/delete_v2.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];
$user_id    = (int)$user['id'];

function is_safe_return($url) {
  if (!$url) return false;
  if (preg_match('~^(?:https?:)?//~i', $url)) return false; // keine absolute/externen URLs
  return str_starts_with($url, '/');
}

$return_to = pick_return_to('/times/index.php'); // früh setzen, da unten direkt genutzt
$fallback  = '/times/index.php';

// Nur POST zulassen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect(is_safe_return($return_to) ? $return_to : $fallback);
  exit;
}

// ID prüfen
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  flash('Ungültige Zeit-ID.', 'danger');
  redirect(is_safe_return($return_to) ? $return_to : $fallback);
  exit;
}

// Einmalig prüfen: Existenz, Besitz & Status
$st = $pdo->prepare('SELECT id, status FROM times WHERE id = ? AND account_id = ? AND user_id = ?');
$st->execute([$id, $account_id, $user_id]);
$time = $st->fetch();

if (!$time) {
  flash('Zeiteintrag nicht gefunden.', 'danger');
  redirect(is_safe_return($return_to) ? $return_to : $fallback);
  exit;
}

// Sperre: abgerechnet -> nicht löschen
if (strtolower((string)$time['status']) === 'abgerechnet') {
  flash('Abgerechnete Zeiten können nicht gelöscht werden.', 'danger');
  redirect(is_safe_return($return_to) ? $return_to : $fallback);
  exit;
}

// (Optional) weitere Sperren hier ergänzen, z. B. wenn Zeiteintrag an Rechnungsposition verknüpft ist.

// Löschen
$del = $pdo->prepare('DELETE FROM times WHERE id = ? AND account_id = ? AND user_id = ? LIMIT 1');
$del->execute([$id, $account_id, $user_id]);

flash('Zeiteintrag gelöscht.', 'success');
redirect(is_safe_return($return_to) ? $return_to : $fallback);
exit;