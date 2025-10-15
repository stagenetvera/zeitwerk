<?php
require __DIR__ . '/../../src/bootstrap.php';
require_login();
csrf_check();

header('Content-Type: application/json');

$user = auth_user();
$account_id = (int)$user['account_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
  exit;
}

$order = $_POST['order'] ?? []; // expected array of task_ids in new dashboard order

if (!is_array($order)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Bad request']);
  exit;
}

// Ensure table exists (idempotent)
$pdo->exec("CREATE TABLE IF NOT EXISTS task_ordering_global (
  account_id INT NOT NULL,
  task_id INT NOT NULL,
  position INT NOT NULL,
  PRIMARY KEY (account_id, task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (count($order) === 0) {
  // nothing to change
  echo json_encode(['ok'=>true, 'count'=>0]);
  exit;
}

$pdo->beginTransaction();
try {
  // Rebuild all positions for this account based on provided order
  $del = $pdo->prepare('DELETE FROM task_ordering_global WHERE account_id=?');
  $del->execute([$account_id]);

  $pos = 0;
  $ins = $pdo->prepare('INSERT INTO task_ordering_global(account_id, task_id, position) VALUES(?,?,?)');
  foreach ($order as $tid) {
    $tid = (int)$tid;
    if ($tid <= 0) continue;
    $ins->execute([$account_id, $tid, $pos]);
    $pos += 10; // gaps for future inserts
  }

  $pdo->commit();
  echo json_encode(['ok'=>true, 'count'=>intval($pos/10)]);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB error']);
}
