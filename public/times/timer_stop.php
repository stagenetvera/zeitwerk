<?php
require __DIR__ . '/../../src/bootstrap.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
$task_id = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;

// Laufenden Timer finden
$find = $pdo->prepare('SELECT id, task_id, started_at FROM times WHERE account_id = ? AND user_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
$find->execute([$account_id, $user_id]);
$open = $find->fetch();
if ($open) {
  $now = new DateTimeImmutable('now');
  $start = new DateTimeImmutable($open['started_at']);
  $mins = max(1, (int)round(($now->getTimestamp() - $start->getTimestamp())/60));

  // Falls beim Stoppen eine Aufgabe mitgegeben wurde und bisher keine gesetzt war → zuordnen
  if ($task_id && empty($open['task_id'])) {
    $upd = $pdo->prepare('UPDATE times SET task_id=?, ended_at=?, minutes=? WHERE id=? AND account_id=? AND user_id=?');
    $upd->execute([$task_id, $now->format('Y-m-d H:i:s'), $mins, $open['id'], $account_id, $user_id]);
  } else {
    $upd = $pdo->prepare('UPDATE times SET ended_at=?, minutes=? WHERE id=? AND account_id=? AND user_id=?');
    $upd->execute([$now->format('Y-m-d H:i:s'), $mins, $open['id'], $account_id, $user_id]);
  }
}

redirect('/dashboard/index.php');
