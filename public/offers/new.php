<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];

// Firmen/Projekte/Kontakte
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cs->execute([$account_id]);
$companies = $cs->fetchAll();

$ps = $pdo->prepare('SELECT id, title FROM projects WHERE account_id = ? ORDER BY title');
$ps->execute([$account_id]);
$projects = $ps->fetchAll();

$cts = $pdo->prepare('SELECT id, name FROM contacts WHERE account_id = ? ORDER BY name');
$cts->execute([$account_id]);
$contacts = $cts->fetchAll();

$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $company_id = (int)($_POST['company_id'] ?? 0);
  $contact_id = isset($_POST['contact_id']) && $_POST['contact_id'] !== '' ? (int)$_POST['contact_id'] : null;
  $project_id = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
  $hourly_rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
  if ($company_id) {
    $ins = $pdo->prepare('INSERT INTO offers(account_id,company_id,contact_id,project_id,hourly_rate,status) VALUES(?,?,?,?,?, "offen")');
    $ins->execute([$account_id,$company_id,$contact_id,$project_id,$hourly_rate]);
    redirect('/offers/list.php');
  } else {
    $err = 'Firma ist erforderlich.';
  }
}
?>
<div class="row">
  <div class="col-md-7">
    <h3>Neues Angebot</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
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
        <label class="form-label">Ansprechpartner (optional)</label>
        <select name="contact_id" class="form-select">
          <option value="">(keiner)</option>
          <?php foreach ($contacts as $c): ?>
            <option value="<?=$c['id']?>"><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Projekt (optional)</label>
        <select name="project_id" class="form-select">
          <option value="">(kein Projekt)</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?=$p['id']?>"><?=h($p['title'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Stundensatz (€)</label>
        <input type="number" step="0.01" name="hourly_rate" class="form-control">
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
