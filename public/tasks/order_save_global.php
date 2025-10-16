<?php
// public/tasks/order_save_global.php
require_once __DIR__ . '/../../src/bootstrap.php';   // <= KEIN layout/header.php laden!
require_login();
header('Content-Type: application/json; charset=utf-8');

// Standard-CSRF-Prüfung auf POST-Feld (kommt aus csrf_field())
try {
  csrf_check();
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'msg'=>'Ungültiges CSRF-Token']);
  exit;
}

// order[] aus POST lesen
$order = $_POST['order'] ?? null;
if (!is_array($order)) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'msg'=>'order[] fehlt']);
  exit;
}

$user = auth_user();
$account_id = (int)$user['account_id'];

// Tabelle sicherstellen
$pdo->exec("CREATE TABLE IF NOT EXISTS task_ordering_global (
  account_id INT NOT NULL,
  task_id INT NOT NULL,
  position INT NOT NULL,
  PRIMARY KEY (account_id, task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Speichern
$pdo->beginTransaction();
$stmt = $pdo->prepare(
  "INSERT INTO task_ordering_global (account_id, task_id, position)
   VALUES (?, ?, ?)
   ON DUPLICATE KEY UPDATE position = VALUES(position)"
);
$pos = 1;
foreach ($order as $tid) {
  $tid = (int)$tid;
  if ($tid > 0) {
    $stmt->execute([$account_id, $tid, $pos++]);
  }
}
$pdo->commit();

echo json_encode(['ok'=>true, 'saved'=>$pos-1]);