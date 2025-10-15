<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $task_id = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;
  // ensure no running time
  $running = get_running_time($pdo, $account_id, $user_id);
  if ($running) {
    $err = "Es läuft bereits ein Timer.";
  } else {
    $stmt = $pdo->prepare('INSERT INTO times(account_id,user_id,task_id,started_at,billable,status) VALUES(?,?,?,?,1,"offen")');
    $stmt->execute([$account_id,$user_id,$task_id, date('Y-m-d H:i:s')]);
    redirect('/times/index.php');
  }
}

// For GET: if task_id provided in URL, start immediately
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['task_id'])) {
  $task_id = (int)$_GET['task_id'];
  $running = get_running_time($pdo, $account_id, $user_id);
  if (!$running) {
    $stmt = $pdo->prepare('INSERT INTO times(account_id,user_id,task_id,started_at,billable,status) VALUES(?,?,?,?,1,"offen")');
    $stmt->execute([$account_id,$user_id,$task_id, date('Y-m-d H:i:s')]);
    redirect('/times/index.php');
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
      <a class="btn btn-outline-secondary" href="<?=url('/times/index.php')?>">Abbrechen</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
