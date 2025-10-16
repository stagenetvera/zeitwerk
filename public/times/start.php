<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id    = (int)$user['id'];

// optionaler Task (Start ohne Aufgabe erlaubt)
$task_id = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;

// return_to robust bestimmen (wie bei dir üblich)
$return_to = pick_return_to('/dashboard/index.php');

try {
  $pdo->beginTransaction();

  // 1) Pro-User serialisieren → sperrt konkurrierende Starts/Stops
  //    Falls deine Users-Tabelle anders heißt/spalten anders → hier anpassen.
  $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? AND account_id = ? FOR UPDATE');
  $lock->execute([$user_id, $account_id]);

  // 2) Laufenden Timer exklusiv sperren
  $rs = $pdo->prepare('
    SELECT * FROM times
    WHERE account_id = ? AND user_id = ? AND ended_at IS NULL
    ORDER BY id DESC
    LIMIT 1
    FOR UPDATE
  ');
  $rs->execute([$account_id, $user_id]);
  $running = $rs->fetch();

  $now = new DateTimeImmutable('now');

  if ($running) {
    // Idempotenz: gleicher Task in den letzten 3s → als Doppelstart werten und nur zurück
    $running_task_id = $running['task_id'] !== null ? (int)$running['task_id'] : null;
    $sameTask = ($running_task_id === $task_id);
    $started  = new DateTimeImmutable($running['started_at']);
    $ageSec   = $now->getTimestamp() - $started->getTimestamp();

    if (!($sameTask && $ageSec <= 3)) {
      // laufenden Timer stoppen
      $minutes = max(1, (int)round($ageSec / 60));
      $upd = $pdo->prepare('
        UPDATE times
        SET ended_at = ?, minutes = ?
        WHERE id = ? AND account_id = ? AND user_id = ?
      ');
      $upd->execute([$now->format('Y-m-d H:i:s'), $minutes, (int)$running['id'], $account_id, $user_id]);
    } else {
      // Doppelstart → nichts weiter tun
      $pdo->commit();
      redirect($return_to);
    }
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
  // optional: flash('err','Timer-Start fehlgeschlagen.');
  echo '<div class="alert alert-danger">Timer-Start fehlgeschlagen.</div>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}