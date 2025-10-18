<?php
// public/tasks/delete.php
require __DIR__ . '/../../src/layout/header.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_login();
csrf_check();

$user = auth_user();
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

// Ownership + minimale Kontextdaten
$st = $pdo->prepare('
  SELECT t.id, t.project_id, p.company_id
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  WHERE t.id = ? AND t.account_id = ?
');
$st->execute([$task_id, $account_id]);
$row = $st->fetch();
if (!$row) {
  flash('Aufgabe nicht gefunden.', 'danger');
  redirect($return_to);
  exit;
}

// 1) Blocker prüfen: Zeiten im Status in_abrechnung/abgerechnet -> NICHT löschen
$cntBlock = $pdo->prepare("
  SELECT COUNT(*)
  FROM times
  WHERE account_id = ?
    AND task_id    = ?
    AND status IN ('in_abrechnung','abgerechnet')
");
$cntBlock->execute([$account_id, $task_id]);
if ((int)$cntBlock->fetchColumn() > 0) {
  flash('Aufgabe kann nicht gelöscht werden: Es existieren Zeiten im Status „in Abrechnung“ oder „abgerechnet“.', 'warning');
  redirect($return_to);
  exit;
}

// 2) Offene Zeiten zählen (nur Info-Zweck — Löschung folgt in der Transaktion)
$cntOpen = $pdo->prepare("
  SELECT COUNT(*)
  FROM times
  WHERE account_id = ?
    AND task_id    = ?
    AND (status = 'offen' OR status IS NULL)
");
$cntOpen->execute([$account_id, $task_id]);
$openCount = (int)$cntOpen->fetchColumn();

try {
  $pdo->beginTransaction();

  // 3) Offene Zeiten dieser Aufgabe wirklich löschen (statt FK SET NULL -> keine Waisen)
  if ($openCount > 0) {
    $delTimes = $pdo->prepare("
      DELETE FROM times
      WHERE account_id = ?
        AND task_id    = ?
        AND (status = 'offen' OR status IS NULL)
    ");
    $delTimes->execute([$account_id, $task_id]);
  }

  // 4) Sortierreste entfernen
  $pdo->prepare('DELETE FROM task_ordering_global WHERE account_id = ? AND task_id = ?')
      ->execute([$account_id, $task_id]);

  // 5) Aufgabe löschen
  $pdo->prepare('DELETE FROM tasks WHERE id = ? AND account_id = ?')
      ->execute([$task_id, $account_id]);

  $pdo->commit();

  $msg = $openCount > 0
    ? 'Aufgabe und zugewiesene offene Zeiten wurden gelöscht.'
    : 'Aufgabe wurde gelöscht.';
  flash($msg, 'success');
} catch (Throwable $e) {
  $pdo->rollBack();
  flash('Aufgabe konnte nicht gelöscht werden: '.$e->getMessage(), 'danger');
}

redirect($return_to);
exit;