<?php
// public/tasks/complete.php
require __DIR__ . '/../../src/bootstrap.php';  // << keine Ausgabe!
require_once __DIR__ . '/../../src/lib/flash.php';
require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$return_to = pick_return_to('/dashboard/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect($return_to);
  exit;
}

$task_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($task_id <= 0) {
  flash('Ungültige Aufgabe.', 'danger');
  redirect($return_to);
  exit;
}

// Ownership prüfen & Status lesen
$st = $pdo->prepare('SELECT id, status FROM tasks WHERE id = ? AND account_id = ?');
$st->execute([$task_id, $account_id]);
$task = $st->fetch();

if (!$task) {
  flash('Aufgabe nicht gefunden.', 'danger');
  redirect($return_to);
  exit;
}

if (($task['status'] ?? '') === 'abgeschlossen') {
  flash('Aufgabe ist bereits abgeschlossen.', 'info');
  redirect($return_to);
  exit;
}

// Optional: laufenden, eigenen Timer zu dieser Aufgabe stoppen (nice to have)
try {
  $pdo->beginTransaction();

  // eigenen, laufenden Timer auf dieser Aufgabe stoppen (falls vorhanden)
  $rt = $pdo->prepare('SELECT id, started_at FROM times
                       WHERE account_id = ? AND user_id = ? AND task_id = ? AND ended_at IS NULL
                       ORDER BY id DESC LIMIT 1 FOR UPDATE');
  $rt->execute([$account_id, (int)$user['id'], $task_id]);
  if ($cur = $rt->fetch()) {
    $now    = new DateTimeImmutable('now');
    $start  = new DateTimeImmutable($cur['started_at']);
    $diff_s = max(0, $now->getTimestamp() - $start->getTimestamp());
    $minutes= max(1, (int)round($diff_s / 60));

    $updT = $pdo->prepare('UPDATE times SET ended_at=?, minutes=? WHERE id=? AND account_id=?');
    $updT->execute([$now->format('Y-m-d H:i:s'), $minutes, (int)$cur['id'], $account_id]);
  }

  // Task abschließen
  $upd = $pdo->prepare('UPDATE tasks SET status = ? WHERE id = ? AND account_id = ?');
  $upd->execute(['abgeschlossen', $task_id, $account_id]);

  $pdo->commit();
  flash('Aufgabe als abgeschlossen markiert.', 'success');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash('Konnte Aufgabe nicht abschließen.', 'danger');
}

redirect($return_to);