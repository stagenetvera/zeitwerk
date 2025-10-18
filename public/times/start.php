<?php
// public/times/start.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];
$user_id    = (int)$user['id'];

// optional: Start für konkrete Aufgabe; leer = "allgemein"
$task_id = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;

$return_to = pick_return_to('/dashboard/index.php');

try {
  $pdo->beginTransaction();

  // Pro-User sperren
  $pdo->prepare('SELECT id FROM users WHERE id = ? AND account_id = ? FOR UPDATE')
      ->execute([$user_id, $account_id]);

  // Läuft bereits ein Timer?
  $rs = $pdo->prepare('
    SELECT * FROM times
    WHERE account_id = ? AND user_id = ? AND ended_at IS NULL
    ORDER BY id DESC
    LIMIT 1
    FOR UPDATE
  ');
  $rs->execute([$account_id, $user_id]);
  $running = $rs->fetch();

  // ❌ Falls task-loser Timer läuft → kein Start erlaubt
  if ($running && empty($running['task_id'])) {
    $pdo->commit();
    flash('Es läuft bereits ein Timer <strong>ohne Aufgabe</strong>. Bitte zuerst zuordnen oder stoppen.', 'warning');
    // Direkt zur Zuweisung/Stop-Seite führen
    redirect(url('/times/stop.php') . '?return_to=' . urlencode($return_to));
    exit;
  }

  // Idempotenz: Gleiches Task in den letzten 3s → nichts tun
  $now = new DateTimeImmutable('now');
  if ($running && $running['task_id'] !== null && (int)$running['task_id'] === (int)$task_id) {
    $started = new DateTimeImmutable($running['started_at']);
    if (($now->getTimestamp() - $started->getTimestamp()) <= 3) {
      $pdo->commit();
      redirect($return_to);
      exit;
    }
  }

  // Optional: laufenden Timer (mit Task) sauber stoppen, wenn neuer gestartet wird
  if ($running) {
    $started = new DateTimeImmutable($running['started_at']);
    $ageSec  = max(0, $now->getTimestamp() - $started->getTimestamp());
    $minutes = max(1, (int)round($ageSec / 60));

    $pdo->prepare('UPDATE times SET ended_at = ?, minutes = ? WHERE id = ? AND account_id = ? AND user_id = ?')
        ->execute([$now->format('Y-m-d H:i:s'), $minutes, (int)$running['id'], $account_id, $user_id]);
  }

  // Neuen Timer anlegen (mit/ohne Task)
  $pdo->prepare('INSERT INTO times (account_id, user_id, task_id, started_at) VALUES (?,?,?,?)')
      ->execute([$account_id, $user_id, $task_id, $now->format('Y-m-d H:i:s')]);

  $pdo->commit();
  redirect($return_to);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo '<div class="alert alert-danger">Timer-Start fehlgeschlagen.</div>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}