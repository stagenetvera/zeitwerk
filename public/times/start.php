<?php
// public/times/start.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];
$user_id    = (int)$user['id'];

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$task_id   = (isset($_POST['task_id']) && $_POST['task_id'] !== '') ? (int)$_POST['task_id'] : null;
$return_to = pick_return_to($_POST['return_to'] ?? '/dashboard/index.php');

try {
  $pdo->beginTransaction();

  // 1) User-Row sperren → Start/Stop atomar
  $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? AND account_id = ? FOR UPDATE');
  $lock->execute([$user_id, $account_id]);

  // 2) Laufenden Timer exklusiv laden/sperren
  $rs = $pdo->prepare("
    SELECT id, task_id, started_at, ended_at, status
    FROM times
    WHERE account_id = ? AND user_id = ? AND ended_at IS NULL
    ORDER BY id DESC
    LIMIT 1
    FOR UPDATE
  ");
  $rs->execute([$account_id, $user_id]);
  $running = $rs->fetch();

  $now = new DateTimeImmutable('now');

  if ($running) {
    // Idempotenz: gleicher Task & in den letzten 3s gestartet → als Doppel-Start werten
    $running_task_id = $running['task_id'] !== null ? (int)$running['task_id'] : null;
    $sameTask = ($running_task_id === $task_id);
    $started  = new DateTimeImmutable($running['started_at']);
    $ageSec   = max(0, $now->getTimestamp() - $started->getTimestamp());

    if ($sameTask && $ageSec <= 3) {
      // Doppel-Start → nichts ändern
      $pdo->commit();
      redirect($return_to);
    }

    // Sicherheitsnetz: in Abrechnung/abgerechnet darf NICHT verändert werden
    if (in_array((string)$running['status'], ['in_abrechnung','abgerechnet'], true)) {
      $pdo->rollBack();
      flash('Ein laufender Eintrag ist bereits in Abrechnung/abgerechnet und kann nicht beendet werden.', 'warning');
      redirect($return_to);
    }

    // Laufenden Timer beenden (Minuten serverseitig berechnen)
    $upd = $pdo->prepare("
      UPDATE times
         SET ended_at = :now,
             minutes  = GREATEST(0, TIMESTAMPDIFF(MINUTE, started_at, :now))
       WHERE id = :id AND account_id = :acc AND user_id = :uid AND ended_at IS NULL
    ");
    $upd->execute([
      ':now' => $now->format('Y-m-d H:i:s'),
      ':id'  => (int)$running['id'],
      ':acc' => $account_id,
      ':uid' => $user_id,
    ]);
  }

  // 3) Neuen Timer anlegen (mit/ohne task_id)
  $ins = $pdo->prepare('
    INSERT INTO times (account_id, user_id, task_id, started_at)
    VALUES (?, ?, ?, ?)
  ');
  $ins->execute([$account_id, $user_id, $task_id, $now->format('Y-m-d H:i:s')]);

  $pdo->commit();
  redirect($return_to);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash('Timer-Start fehlgeschlagen.', 'danger');
  redirect($return_to);
}