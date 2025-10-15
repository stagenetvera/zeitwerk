<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();

// Companies for select
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cs->execute([(int)$user['account_id']]);
$companies = $cs->fetchAll();

$err=null; $ok=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $title = trim($_POST['title'] ?? '');
  $company_id = (int)($_POST['company_id'] ?? 0);
  $status = $_POST['status'] ?? 'offen';
  $rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
  if ($title && $company_id) {
    $stmt = $pdo->prepare('INSERT INTO projects(account_id,company_id,title,status,hourly_rate) VALUES(?,?,?,?,?)');
    $stmt->execute([(int)$user['account_id'],$company_id,$title,$status,$rate]);
    $ok = "Gespeichert.";
  } else {
    $err = "Titel und Firma sind erforderlich.";
  }
}
?>
<div class="row">
  <div class="col-md-7">
    <h3>Neues Projekt</h3>
    <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Titel</label>
        <input type="text" name="title" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Firma</label>
        <select name="company_id" class="form-select" required>
          <option value="">– bitte wählen –</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?=$c['id']?>"><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="offen">offen</option>
          <option value="abgeschlossen">abgeschlossen</option>
          <option value="angeboten">angeboten</option>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Projekt-Stundensatz (€)</label>
        <input type="number" step="0.01" name="hourly_rate" class="form-control">
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?=url('/projects/index.php')?>">Zurück</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
