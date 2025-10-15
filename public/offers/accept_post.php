<?php
require __DIR__ . '/../../src/bootstrap.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
$id = (int)($_POST['id'] ?? 0);
if ($id) {
  // Angebot annehmen
  $upd = $pdo->prepare('UPDATE offers SET status="angenommen" WHERE id = ? AND account_id = ?');
  $upd->execute([$id, $account_id]);
  // Aufgaben im Angebot von "angeboten" -> "offen"
  $sql = 'UPDATE tasks t
          JOIN offer_tasks ot ON ot.task_id = t.id AND ot.account_id = t.account_id
          SET t.status = "offen"
          WHERE ot.offer_id = ? AND t.account_id = ? AND t.status = "angeboten"';
  $pdo->prepare($sql)->execute([$id, $account_id]);
}
redirect('/offers/list.php');
