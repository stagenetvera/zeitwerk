<?php
// public/times/update_and_redirect.php
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
if ($id <= 0) {
  flash('Ungültige Zeit-ID.', 'danger');
  redirect('/times/index.php');
  exit;
}

// Load existing time (ownership check)
$st = $pdo->prepare('SELECT * FROM times WHERE id = ? AND account_id = ? AND user_id = ?');
$st->execute([$id, $account_id, $user_id]);
$time = $st->fetch();
if (!$time) {
  flash('Zeiteintrag nicht gefunden.', 'danger');
  redirect('/times/index.php');
  exit;
}

// Gather inputs (keep existing as fallback)
$task_id   = isset($_POST['task_id']) && $_POST['task_id']!=='' ? (int)$_POST['task_id'] : $time['task_id'];
$started   = $_POST['started_at'] ?? $time['started_at'];
$ended     = array_key_exists('ended_at', $_POST) ? ($_POST['ended_at'] ?: null) : $time['ended_at'];
$minutes   = array_key_exists('minutes', $_POST) ? (($_POST['minutes']!=='') ? (int)$_POST['minutes'] : null) : $time['minutes'];
$billable  = isset($_POST['billable']) ? (int)!!$_POST['billable'] : (int)$time['billable'];
$status    = $_POST['status'] ?? $time['status'];

// Normalize dates
$started_sql = $started !== '' ? $started : $time['started_at'];
$ended_sql   = ($ended !== '') ? $ended : null;

// Update
$upd = $pdo->prepare('UPDATE times
  SET task_id = ?, started_at = ?, ended_at = ?, minutes = ?, billable = ?, status = ?, updated_at = NOW()
  WHERE id = ? AND account_id = ? AND user_id = ?');
$upd->execute([$task_id, $started_sql, $ended_sql, $minutes, $billable, $status, $id, $account_id, $user_id]);

flash('Zeit gespeichert.', 'success');

// Return-to logic
$return_to = $_POST['return_to'] ?? '';
if (!$return_to && isset($_SERVER['HTTP_REFERER'])) {
  $return_to = $_SERVER['HTTP_REFERER'];
}
// allow only relative same-site
if ($return_to && !preg_match('~^(?:https?:)?//~i', $return_to) && str_starts_with($return_to, '/')) {
  redirect($return_to); exit;
}
redirect('/times/index.php');
exit;
