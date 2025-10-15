<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND account_id = ?');
$stmt->execute([$id, $account_id]);
$task = $stmt->fetch();
if (!$task) { echo '<div class="alert alert-danger">Aufgabe nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

// Projekte für Auswahl
$ps = $pdo->prepare('SELECT id, title FROM projects WHERE account_id = ? ORDER BY title');
$ps->execute([$account_id]);
$projects = $ps->fetchAll();

$ok = $err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $project_id = (int)($_POST['project_id'] ?? 0);
  $description = trim($_POST['description'] ?? '');
  $planned = $_POST['planned_minutes'] !== '' ? (int)$_POST['planned_minutes'] : null;
  $priority = $_POST['priority'] ?? 'medium';
  $deadline = $_POST['deadline'] ?: null;
  $status = $_POST['status'] ?? 'offen';
  $billable = isset($_POST['billable']) ? 1 : 0;
  if ($project_id && $description) {
    $upd = $pdo->prepare('UPDATE tasks SET project_id=?, description=?, planned_minutes=?, priority=?, deadline=?, status=?, billable=? WHERE id=? AND account_id=?');
    $upd->execute([$project_id,$description,$planned,$priority,$deadline,$status,$billable,$id,$account_id]);
    $ok = 'Gespeichert.';
    $stmt->execute([$id, $account_id]); $task = $stmt->fetch();
  } else { $err = 'Projekt und Beschreibung sind erforderlich.'; }
}
?>
<div class="row"><div class="col-md-8">
  <h3>Aufgabe bearbeiten</h3>
  <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
  <form method="post">
    <?=csrf_field()?>
    <div class="mb-3">
      <label class="form-label">Projekt</label>
      <select name="project_id" class="form-select" required>
        <?php foreach ($projects as $p): ?>
          <option value="<?=$p['id']?>" <?=$p['id']==$task['project_id']?'selected':''?>><?=h($p['title'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label">Beschreibung</label>
      <textarea name="description" class="form-control" rows="3" required><?=h($task['description'])?></textarea>
    </div>
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Geplante Minuten</label>
        <input type="number" name="planned_minutes" class="form-control" value="<?=h($task['planned_minutes'] ?? '')?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Priorität</label>
        <select name="priority" class="form-select">
          <option value="low" <?=$task['priority']==='low'?'selected':''?>>low</option>
          <option value="medium" <?=$task['priority']==='medium'?'selected':''?>>medium</option>
          <option value="high" <?=$task['priority']==='high'?'selected':''?>>high</option>
        </select>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label">Deadline</label>
        <input type="date" name="deadline" class="form-control" value="<?=h($task['deadline'] ?? '')?>">
      </div>
    </div>
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="offen" <?=$task['status']==='offen'?'selected':''?>>offen</option>
          <option value="warten" <?=$task['status']==='warten'?'selected':''?>>warten</option>
          <option value="angeboten" <?=$task['status']==='angeboten'?'selected':''?>>angeboten</option>
          <option value="fakturierbar" <?=$task['status']==='fakturierbar'?'selected':''?>>fakturierbar</option>
          <option value="abgeschlossen" <?=$task['status']==='abgeschlossen'?'selected':''?>>abgeschlossen</option>
        </select>
      </div>
      <div class="col-md-4 mb-3 form-check mt-4">
        <input class="form-check-input" type="checkbox" name="billable" id="billable" <?=$task['billable']?'checked':''?>>
        <label class="form-check-label" for="billable">fakturierbar</label>
      </div>
    </div>
    <button class="btn btn-primary">Speichern</button>
    <a class="btn btn-outline-secondary" href="<?=url('/tasks/index.php')?>">Zurück</a>
  </form>
</div></div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
