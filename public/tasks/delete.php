<?php
// public/tasks/delete.php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('/dashboard/index.php');
  exit;
}
$task_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$return_to = $_POST['return_to'] ?? '';

function is_safe_return($url) {
  if (!$url) return false;
  if (preg_match('~^(?:https?:)?//~i', $url)) return false; // no external
  return str_starts_with($url, '/');
}

if ($task_id <= 0) {
  flash('Ungültige Aufgabe.', 'danger');
  redirect('/dashboard/index.php');
  exit;
}

// Ownership + fetch project/company
$st = $pdo->prepare('SELECT t.id, t.project_id, p.company_id
                     FROM tasks t
                     JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
                     WHERE t.id = ? AND t.account_id = ?');
$st->execute([$task_id, $account_id]);


$row = $st->fetch();
if (!$row) {
  // flash('Aufgabe nicht gefunden.', 'danger');
  redirect('/dashboard/index.php');
  exit;
}

// Block delete if times exist
$cnt = $pdo->prepare('SELECT COUNT(*) FROM times WHERE account_id = ? AND task_id = ?');
$cnt->execute([$account_id, $task_id]);
$hasTimes = (int)$cnt->fetchColumn() > 0;
if ($hasTimes) {
  // flash('Aufgabe kann nicht gelöscht werden, da bereits Zeiten existieren. Bitte Zeiten löschen/umbuchen oder Aufgabe abschließen.', 'warning');
  redirect('/companies/show.php?id=' . (int)$row['company_id']);
  exit;
}

// Delete from ordering (if present)
$pdo->prepare('DELETE FROM task_ordering_global WHERE account_id = ? AND task_id = ?')->execute([$account_id, $task_id]);
// Delete task
$pdo->prepare('DELETE FROM tasks WHERE id = ? AND account_id = ?')->execute([$task_id, $account_id]);
// flash('Aufgabe gelöscht.', 'success');
redirect('/companies/show.php?id=' . (int)$row['company_id']);
exit;
