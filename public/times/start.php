<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

$return_to = pick_return_to('/dashboard/index.php');

// Vereinheitlichtes Verhalten:
// "Start" stoppt immer einen ggf. laufenden Timer und startet sofort einen neuen
// (auch ohne task_id -> dann mit NULL-Aufgabe). Kein zusätzliches Formular.
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); }

    $user = auth_user();
    $account_id = (int)$user['account_id'];
    $user_id = (int)$user['id'];

    $task_id_raw = $_POST['task_id'] ?? $_GET['task_id'] ?? null;
    $task_id = null;
    if ($task_id_raw !== null && $task_id_raw !== '') {
        $task_id = (int)$task_id_raw;
        if ($task_id <= 0) $task_id = null;
    }



    $pdo->beginTransaction();

    // 1) Laufenden Timer stoppen (falls vorhanden)
    $runStmt = $pdo->prepare('SELECT id, started_at
                              FROM times
                              WHERE account_id = ? AND user_id = ? AND ended_at IS NULL
                              ORDER BY id DESC LIMIT 1');
    $runStmt->execute([$account_id, $user_id]);
    if ($running = $runStmt->fetch()) {
        $upd = $pdo->prepare('UPDATE times
                              SET ended_at = NOW(),
                                  minutes = GREATEST(0, TIMESTAMPDIFF(MINUTE, started_at, NOW()))
                              WHERE id = ? AND account_id = ? AND user_id = ?');
        $upd->execute([(int)$running['id'], $account_id, $user_id]);
    }

    // 2) Neuen Timer starten (task_id kann NULL sein)
    $ins = $pdo->prepare('INSERT INTO times (account_id, user_id, task_id, started_at)
                          VALUES (?, ?, ?, NOW())');
    $ins->execute([$account_id, $user_id, $task_id]);

    $pdo->commit();

    redirect($return_to);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    if (function_exists('flash')) {
        flash('Fehler beim Starten des Timers: ' . $e->getMessage(), 'danger');
    }
    $fallback = url('/dashboard/index.php');
    redirect($fallback);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $task_id = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;
  // ensure no running time
  $running = get_running_time($pdo, $account_id, $user_id);
  if ($running) {
    $err = "Es läuft bereits ein Timer.";
  } else {
    $stmt = $pdo->prepare('INSERT INTO times(account_id,user_id,task_id,started_at,billable,status) VALUES(?,?,?,?,1,"offen")');
    $stmt->execute([$account_id,$user_id,$task_id, date('Y-m-d H:i:s')]);
    redirect($return_to);
  }
}

// For GET: if task_id provided in URL, start immediately
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['task_id'])) {
  $task_id = (int)$_GET['task_id'];
  $running = get_running_time($pdo, $account_id, $user_id);
  if (!$running) {
    $stmt = $pdo->prepare('INSERT INTO times(account_id,user_id,task_id,started_at,billable,status) VALUES(?,?,?,?,1,"offen")');
    $stmt->execute([$account_id,$user_id,$task_id, date('Y-m-d H:i:s')]);
    redirect($return_to);
  } else {
    $err = "Es läuft bereits ein Timer.";
  }
}

// tasks select for manual start
$ts = $pdo->prepare('SELECT t.id, t.description, p.title AS project_title FROM tasks t JOIN projects p ON p.id=t.project_id AND p.account_id=t.account_id WHERE t.account_id = ? AND t.status IN ("offen","warten","fakturierbar") ORDER BY t.id DESC LIMIT 100');
$ts->execute([$account_id]);
$tasks = $ts->fetchAll();
?>
<div class="row">
  <div class="col-md-7">
    <h3>Timer starten</h3>
    <?php if (!empty($err)): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Optional: Aufgabe zuordnen</label>
        <select name="task_id" class="form-select">
          <option value="">(ohne Aufgabe)</option>
          <?php foreach ($tasks as $t): ?>
            <option value="<?=$t['id']?>"><?=h($t['project_title'])?> — <?=h(mb_strimwidth($t['description'],0,60,'…'))?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-success">Start</button>
      <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
