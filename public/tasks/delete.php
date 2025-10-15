<?php
// public/tasks/delete.php
require __DIR__ . '/../../src/layout/header.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];


$return_to = $_POST['return_to'] ?? '';
if (!$return_to && isset($_SERVER['HTTP_REFERER'])) {
  $return_to = $_SERVER['HTTP_REFERER'];
}
// sanitize: allow only same-site relative URLs
$valid = false;
if ($return_to && !preg_match('~^(?:https?:)?//~i', $return_to)) {
  $valid = (str_starts_with($return_to, '/'));
}

if (!$valid) {
    $return_to = "/dashboard/index.php";
}
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

// Ownership + fetch project/company
$st = $pdo->prepare('SELECT t.id, t.project_id, p.company_id
                     FROM tasks t
                     JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
                     WHERE t.id = ? AND t.account_id = ?');
$st->execute([$task_id, $account_id]);


$row = $st->fetch();
if (!$row) {
  flash('Aufgabe nicht gefunden.', 'danger');
  redirect($return_to);
  exit;
}

// Block delete if times exist
$cnt = $pdo->prepare('SELECT COUNT(*) FROM times WHERE account_id = ? AND task_id = ?');
$cnt->execute([$account_id, $task_id]);
$hasTimes = (int)$cnt->fetchColumn() > 0;
if ($hasTimes) {
  flash('Aufgabe kann nicht gelöscht werden, da bereits Zeiten existieren. Bitte Zeiten löschen/umbuchen oder Aufgabe abschließen.', 'warning');
  redirect($return_to);
  exit;
}

// Delete from ordering (if present)
$pdo->prepare('DELETE FROM task_ordering_global WHERE account_id = ? AND task_id = ?')->execute([$account_id, $task_id]);
// Delete task
$pdo->prepare('DELETE FROM tasks WHERE id = ? AND account_id = ?')->execute([$task_id, $account_id]);
// try to redirect back where we came from

if ($valid) {
  flash('Aufgabe gelöscht.', 'success');
  redirect($return_to);
}
exit;
