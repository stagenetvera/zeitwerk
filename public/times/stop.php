<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

$running = get_running_time($pdo, $account_id, $user_id);
if (!$running) {
  echo '<div class="alert alert-info">Es läuft kein Timer.</div>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}

$err=null; $ok=null;


$return_to = $_POST['return_to'] ?? '';
if (!$return_to && isset($_SERVER['HTTP_REFERER'])) {
  $return_to = $_SERVER['HTTP_REFERER'];
}
// sanitize: allow only same-site relative URLs
$valid = false;
if ($return_to && !preg_match('~^(?:https?:)?//~i', $return_to)) {
  $valid = (str_starts_with($return_to, '/'));
}

if (!$valid) {
    $return_to = "/dashboard/index.php";
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  // optional: assign task if none
  $task_id = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;
  if (!$running['task_id'] && !$task_id) {
    $err = "Bitte Aufgabe auswählen oder neu anlegen.";
  } else {
    if (!$running['task_id'] && $task_id) {
      // assign before stopping
      $up = $pdo->prepare('UPDATE times SET task_id = ? WHERE id = ? AND account_id = ? AND user_id = ?');
      $up->execute([$task_id, $running['id'], $account_id, $user_id]);
      $running['task_id'] = $task_id;
    }
    // stop timer
    $end = new DateTimeImmutable('now');
    $start = new DateTimeImmutable($running['started_at']);
    $minutes = max(1, (int)round(($end->getTimestamp() - $start->getTimestamp())/60));
    $stp = $pdo->prepare('UPDATE times SET ended_at = ?, minutes = ? WHERE id = ? AND account_id = ? AND user_id = ?');
    $stp->execute([$end->format('Y-m-d H:i:s'), $minutes, $running['id'], $account_id, $user_id]);
    redirect($return_to);
  }
}

// task list for selection
$ts = $pdo->prepare('SELECT t.id, t.description, p.title AS project_title FROM tasks t JOIN projects p ON p.id=t.project_id AND p.account_id=t.account_id WHERE t.account_id = ? ORDER BY t.id DESC LIMIT 100');
$ts->execute([$account_id]);
$tasks = $ts->fetchAll();
?>
<div class="row">
  <div class="col-md-7">
    <h3>Timer stoppen</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <div class="mb-3">
      <strong>Gestartet:</strong> <?=h($running['started_at'])?>
      <?php if ($running['task_id']): ?>
        <div><span class="badge bg-secondary">Aufgabe bereits zugeordnet</span></div>
      <?php else: ?>
        <div class="text-muted">Aktuell ohne Aufgabe</div>
      <?php endif; ?>
    </div>
    <form method="post">
      <?=csrf_field()?>
      <?php if (!$running['task_id']): ?>
      <div class="mb-3">
        <label class="form-label">Aufgabe auswählen</label>
        <select name="task_id" class="form-select">
          <option value="">– bitte wählen –</option>
          <?php foreach ($tasks as $t): ?>
            <option value="<?=$t['id']?>"><?=h($t['project_title'])?> — <?=h(mb_strimwidth($t['description'],0,60,'…'))?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <button class="btn btn-warning">Stoppen</button>
      <a class="btn btn-outline-secondary" href="<?=url('/times/index.php')?>">Abbrechen</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
