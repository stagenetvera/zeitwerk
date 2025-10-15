<?php
require __DIR__ . '/../../src/bootstrap.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
$id = (int)($_POST['id'] ?? 0);
if ($id) {
  $del = $pdo->prepare('DELETE FROM offers WHERE id = ? AND account_id = ?');
  $del->execute([$id, $account_id]);
}
redirect('/offers/list.php');
