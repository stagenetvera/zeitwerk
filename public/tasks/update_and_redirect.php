<?php
// public/tasks/update_and_redirect.php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('/dashboard/index.php');
  exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
  flash('Ungültige Aufgabe.', 'danger');
  redirect('/dashboard/index.php');
  exit;
}

// Load existing task (for defaults + ownership)
$st = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND account_id = ?');
$st->execute([$id, $account_id]);
$task = $st->fetch();
if (!$task) {
  flash('Aufgabe nicht gefunden.', 'danger');
  redirect('/dashboard/index.php');
  exit;
}

// Resolve incoming values, fallback to existing
$description = trim($_POST['description'] ?? $task['description']);
$project_id  = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : (int)$task['project_id'];
$planned     = isset($_POST['planned_minutes']) && $_POST['planned_minutes'] !== '' ? (int)$_POST['planned_minutes'] : $task['planned_minutes'];
$priority    = $_POST['priority'] ?? $task['priority'];
$deadline    = array_key_exists('deadline', $_POST) && $_POST['deadline'] !== '' ? $_POST['deadline'] : $task['deadline'];
$status      = $_POST['status'] ?? $task['status'];

// Normalize empty strings to null
$deadline_sql = $deadline !== '' ? $deadline : null;
$planned_sql  = ($planned !== '' && $planned !== null) ? (int)$planned : null;

// Update task
$upd = $pdo->prepare('UPDATE tasks
  SET description = ?, project_id = ?, planned_minutes = ?, priority = ?, deadline = ?, status = ?
  WHERE id = ? AND account_id = ?');
$upd->execute([$description, $project_id, $planned_sql, $priority, $deadline_sql, $status, $id, $account_id]);

// figure out company id from (new) project
$cs = $pdo->prepare('SELECT company_id FROM projects WHERE id = ? AND account_id = ?');
$cs->execute([$project_id, $account_id]);
$company_id = (int)$cs->fetchColumn();

if ($company_id > 0) {
  redirect('/companies/show.php?id=' . $company_id);
} else {
  // Fallback
  redirect('/dashboard/index.php');
}
exit;
