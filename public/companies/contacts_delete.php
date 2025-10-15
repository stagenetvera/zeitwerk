<?php
require __DIR__ . '/../../src/bootstrap.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }

$id = (int)($_POST['id'] ?? 0);
if ($id) {
  // company_id für Redirect ermitteln
  $get = $pdo->prepare('SELECT company_id FROM contacts WHERE id = ? AND account_id = ?');
  $get->execute([$id,$account_id]);
  $row = $get->fetch();
  $company_id = $row ? (int)$row['company_id'] : 0;

  $del = $pdo->prepare('DELETE FROM contacts WHERE id = ? AND account_id = ?');
  $del->execute([$id, $account_id]);

  if ($company_id) {
    redirect('/companies/show.php?id=' . $company_id);
  }
}

redirect('/companies/index.php');
