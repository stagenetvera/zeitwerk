<?php
require __DIR__ . '/../../src/bootstrap.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
$id = (int)($_POST['id'] ?? 0);
if ($id) {
  $del = $pdo->prepare('DELETE FROM times WHERE id = ? AND account_id = ? AND user_id = ?');
  $del->execute([$id, $account_id, $user_id]);
}
redirect('/times/index.php');
