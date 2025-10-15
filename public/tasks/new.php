<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();

$ps = $pdo->prepare('SELECT id, title FROM projects WHERE account_id = ? ORDER BY title');
$ps->execute([(int)$user['account_id']]);
$projects = $ps->fetchAll();

$err=null; $ok=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $project_id = (int)($_POST['project_id'] ?? 0);
  $description = trim($_POST['description'] ?? '');
  $planned = $_POST['planned_minutes'] !== '' ? (int)$_POST['planned_minutes'] : null;
  $priority = $_POST['priority'] ?? 'medium';
  $deadline = $_POST['deadline'] ?: null;
  $status = $_POST['status'] ?? 'offen';
  $billable = isset($_POST['billable']) ? 1 : 0;
  if ($project_id && $description) {
    $stmt = $pdo->prepare('INSERT INTO tasks(account_id,project_id,description,planned_minutes,priority,deadline,status,billable) VALUES(?,?,?,?,?,?,?,?)');
    $stmt->execute([(int)$user['account_id'],$project_id,$description,$planned,$priority,$deadline,$status,$billable]);
    $ok = "Gespeichert.";
  } else {
    $err = "Projekt und Beschreibung sind erforderlich.";
  }
}
?>
<div class="row">
  <div class="col-md-8">
    <h3>Neue Aufgabe</h3>
    <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Projekt</label>
        <select name="project_id" class="form-select" required>
          <option value="">– bitte wählen –</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?=$p['id']?>"><?=h($p['title'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Beschreibung</label>
        <textarea name="description" class="form-control" rows="3" required></textarea>
      </div>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Geplante Minuten</label>
          <input type="number" name="planned_minutes" class="form-control">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Priorität</label>
          <select name="priority" class="form-select">
            <option value="low">low</option>
            <option value="medium" selected>medium</option>
            <option value="high">high</option>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Deadline</label>
          <input type="date" name="deadline" class="form-control">
        </div>
      </div>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="offen">offen</option>
            <option value="warten">warten</option>
            <option value="angeboten">angeboten</option>
            <option value="fakturierbar">fakturierbar</option>
            <option value="abgeschlossen">abgeschlossen</option>
          </select>
        </div>
        <div class="col-md-4 mb-3 form-check mt-4">
          <input class="form-check-input" type="checkbox" name="billable" id="billable" checked>
          <label class="form-check-label" for="billable">fakturierbar</label>
        </div>
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?=url('/tasks/index.php')?>">Zurück</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
