<?php
require __DIR__ . '/../../src/bootstrap.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }

$id = (int)($_POST['id'] ?? 0);

// company_id für Redirect ermitteln, bevor wir löschen
$proj = null;
if ($id) {
  $st = $pdo->prepare('SELECT id, company_id FROM projects WHERE id = ? AND account_id = ?');
  $st->execute([$id, $account_id]);
  $proj = $st->fetch();
}

if ($proj) {
  $company_id = (int)$proj['company_id'];
  $del = $pdo->prepare('DELETE FROM projects WHERE id = ? AND account_id = ?');
  $del->execute([$id, $account_id]);
  redirect('/companies/show.php?id=' . $company_id);
}

// Fallback
redirect('/companies/index.php');
