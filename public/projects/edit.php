<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? AND account_id = ?');
$stmt->execute([$id, $account_id]);
$project = $stmt->fetch();
if (!$project) { echo '<div class="alert alert-danger">Projekt nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }
$company_id = (int)$project['company_id'];

// Firmen für Auswahl
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cs->execute([$account_id]);
$companies = $cs->fetchAll();


$ok = $err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $title = trim($_POST['title'] ?? '');
  $company_id = (int)($_POST['company_id'] ?? 0);
  $status = $_POST['status'] ?? 'offen';
  $rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
  if ($title && $company_id) {
    $upd = $pdo->prepare('UPDATE projects SET title=?, company_id=?, status=?, hourly_rate=? WHERE id=? AND account_id=?');
    $upd->execute([$title,$company_id,$status,$rate,$id,$account_id]);
    $ok = 'Gespeichert.';
    $stmt->execute([$id, $account_id]); $project = $stmt->fetch();
  } else { $err = 'Titel und Firma sind erforderlich.'; }
}
?>
<div class="row"><div class="col-md-7">
  <h3>Projekt bearbeiten</h3>
  <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
  <form method="post">
    <?=csrf_field()?>
    <div class="mb-3"><label class="form-label">Titel</label>
      <input type="text" name="title" class="form-control" value="<?=h($project['title'])?>" required></div>
    <div class="mb-3"><label class="form-label">Firma</label>
      <select name="company_id" class="form-select" required>
        <?php foreach ($companies as $c): ?>
          <option value="<?=$c['id']?>" <?=$c['id']==$project['company_id']?'selected':''?>><?=h($c['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mb-3"><label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="offen" <?=$project['status']==='offen'?'selected':''?>>offen</option>
        <option value="abgeschlossen" <?=$project['status']==='abgeschlossen'?'selected':''?>>abgeschlossen</option>
        <option value="angeboten" <?=$project['status']==='angeboten'?'selected':''?>>angeboten</option>
      </select>
    </div>
    <div class="mb-3"><label class="form-label">Projekt-Stundensatz (€)</label>
      <input type="number" step="0.01" name="hourly_rate" class="form-control" value="<?=h($project['hourly_rate'] ?? '')?>">
    </div>
    <button class="btn btn-primary">Speichern</button>
    <a class="btn btn-outline-secondary" href="<?=url('/companies/show.php?id='.$company_id)?>">Zurück</a>
  </form>
</div></div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
