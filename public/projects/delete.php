<?php
// public/projects/delete.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$project_id = (int)($_POST['id'] ?? 0);

// Projekt laden (für Redirect-Ziel)
$proj = null;
if ($project_id) {
  $st = $pdo->prepare('SELECT id, company_id FROM projects WHERE id = ? AND account_id = ?');
  $st->execute([$project_id, $account_id]);
  $proj = $st->fetch();
}

if ($proj) {
  $company_id = (int)$proj['company_id'];
  $return_to = pick_return_to('/companies/show.php?id=' . $company_id);
} else {
  $return_to = pick_return_to('/companies/index.php');
}

// 1) Blockieren, wenn verknüpfte Zeiten im Status in_abrechnung/abgerechnet existieren
$chkBlock = $pdo->prepare("
  SELECT COUNT(*)
  FROM times tm
  JOIN tasks t ON t.id = tm.task_id AND t.account_id = tm.account_id
  WHERE tm.account_id = ?
    AND t.project_id  = ?
    AND tm.status IN ('in_abrechnung','abgerechnet')
");
$chkBlock->execute([$account_id, $project_id]);
if ((int)$chkBlock->fetchColumn() > 0) {
  flash('Projekt kann nicht gelöscht werden: Es existieren Zeiten im Status „in Abrechnung“ oder „abgerechnet“.', 'danger');
  redirect($return_to);
  exit;
}

// 2) Vorab zählen (für Feedback)
$cntTasks = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE account_id = ? AND project_id = ?");
$cntTasks->execute([$account_id, $project_id]);
$tasksCount = (int)$cntTasks->fetchColumn();

$chkOpen = $pdo->prepare("
  SELECT COUNT(*)
  FROM times tm
  JOIN tasks t ON t.id = tm.task_id AND t.account_id = tm.account_id
  WHERE tm.account_id = ?
    AND t.project_id  = ?
    AND (tm.status = 'offen' OR tm.status IS NULL)
");
$chkOpen->execute([$account_id, $project_id]);
$openCount = (int)$chkOpen->fetchColumn();

try {
  $pdo->beginTransaction();

  // 3) Nicht-abgerechnete (offene) Zeiten des Projekts löschen
  if ($openCount > 0) {
    $delTimes = $pdo->prepare("
      DELETE tm
      FROM times tm
      JOIN tasks t ON t.id = tm.task_id AND t.account_id = tm.account_id
      WHERE tm.account_id = ?
        AND t.project_id  = ?
        AND (tm.status = 'offen' OR tm.status IS NULL)
    ");
    $delTimes->execute([$account_id, $project_id]);
  }

  // 4) Sortierreste zu allen Tasks dieses Projekts entfernen
  $pdo->prepare("
    DELETE tog
    FROM task_ordering_global tog
    JOIN tasks t ON t.id = tog.task_id
    WHERE tog.account_id = ?
      AND t.account_id   = ?
      AND t.project_id   = ?
  ")->execute([$account_id, $account_id, $project_id]);

  // 5) Alle Aufgaben des Projekts löschen (explizit, um FK-RESTRICT-Konflikte zu vermeiden)
  $pdo->prepare("DELETE FROM tasks WHERE account_id = ? AND project_id = ?")
      ->execute([$account_id, $project_id]);

  // 6) Projekt löschen
  $pdo->prepare("DELETE FROM projects WHERE id = ? AND account_id = ?")
      ->execute([$project_id, $account_id]);

  $pdo->commit();

  // Feedback-Meldung passend zum Warntext:
  // „Zugewiesene Aufgaben, sowie nicht abgerechnete Zeiten werden damit ebenfalls gelöscht.“
  if ($tasksCount > 0 || $openCount > 0) {
    flash('Projekt, zugehörige Aufgaben und nicht abgerechnete Zeiten wurden gelöscht.', 'success');
  } else {
    flash('Projekt wurde gelöscht.', 'success');
  }
} catch (Throwable $e) {
  $pdo->rollBack();
  flash('Projekt konnte nicht gelöscht werden: ' . $e->getMessage(), 'danger');
}

redirect($return_to);