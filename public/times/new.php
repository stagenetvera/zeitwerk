<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

// Aufgabenliste (optional)
$ts = $pdo->prepare('SELECT t.id, t.description, p.title AS project_title
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  WHERE t.account_id = ?
  ORDER BY p.title, t.id DESC
  LIMIT 500');
$ts->execute([$account_id]);
$tasks = $ts->fetchAll();

$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $task_id = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;
  $billable = isset($_POST['billable']) ? 1 : 0;
  $status = $_POST['status'] ?? 'offen';
  $started_at = trim($_POST['started_at'] ?? '');
  $ended_at = trim($_POST['ended_at'] ?? '');

  if (!$started_at) {
    $err = 'Startzeit ist erforderlich.';
  } else {
    $start = new DateTimeImmutable($started_at);
    $end = $ended_at ? new DateTimeImmutable($ended_at) : null;
    $minutes = null;
    if ($end) {
      if ($end <= $start) {
        $err = 'Endzeit muss nach der Startzeit liegen.';
      } else {
        $minutes = max(1, (int)round(($end->getTimestamp() - $start->getTimestamp())/60));
      }
    }
    if (!$err) {
      $ins = $pdo->prepare('INSERT INTO times(account_id,user_id,task_id,started_at,ended_at,minutes,billable,status) VALUES(?,?,?,?,?,?,?,?)');
      $ins->execute([$account_id,$user_id,$task_id,$start->format('Y-m-d H:i:s'), $end? $end->format('Y-m-d H:i:s') : null, $minutes,$billable,$status]);
      redirect('/times/index.php');
    }
  }
}
?>
<div class="row">
  <div class="col-md-7">
    <h3>Zeit manuell erfassen</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Aufgabe (optional)</label>
        <select name="task_id" class="form-select">
          <option value="">(ohne Aufgabe)</option>
          <?php foreach ($tasks as $t): ?>
            <option value="<?=$t['id']?>"><?=h($t['project_title'])?> — <?=h(mb_strimwidth($t['description'],0,60,'…'))?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Start</label>
          <input type="datetime-local" name="started_at" class="form-control" required>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Ende (optional)</label>
          <input type="datetime-local" name="ended_at" class="form-control">
        </div>
      </div>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="offen">offen</option>
            <option value="abgerechnet">abgerechnet</option>
          </select>
        </div>
        <div class="col-md-4 mb-3 form-check mt-4">
          <input class="form-check-input" type="checkbox" name="billable" id="billable" checked>
          <label class="form-check-label" for="billable">fakturierbar</label>
        </div>
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?=url('/times/index.php')?>">Zurück</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
