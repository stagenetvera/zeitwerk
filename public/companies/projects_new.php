<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];

$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
// Firma prüfen (Mandantenschutz)
$cs = $pdo->prepare('SELECT id, name, hourly_rate FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$company_id,$account_id]);
$company = $cs->fetch();
if (!$company) { echo '<div class="alert alert-danger">Firma nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

$company_rate = $company['hourly_rate'];

$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $title = trim($_POST['title'] ?? '');
  $status = $_POST['status'] ?? 'offen';
  $rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
  if ($title) {
    $ins = $pdo->prepare('INSERT INTO projects(account_id, company_id, title, status, hourly_rate) VALUES(?,?,?,?,?)');
    $ins->execute([$account_id, $company_id, $title, $status, $rate]);
    redirect('/companies/show.php?id=' . $company_id);
  } else {
    $err = 'Titel ist erforderlich.';
  }
}
?>
<div class="row">
  <div class="col-md-7">
    <h3>Neues Projekt – <?=h($company['name'])?></h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Titel</label>
        <input type="text" name="title" class="form-control" required>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="offen">offen</option>
            <option value="abgeschlossen">abgeschlossen</option>
            <option value="angeboten">angeboten</option>
          </select>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Projekt-Stundensatz (€)</label>
          <input type="number" step="0.01" name="hourly_rate" class="form-control" placeholder="<?= $company_rate !== null ? number_format($company_rate,2,',','.') . ' € (Firma, Standard wenn leer)' : 'leer lassen, wenn kein Projektsatz' ?>">
          <div class="form-text">
            <?php if ($company_rate !== null): ?>
              Leer lassen, um <strong>Firmensatz</strong> (<?=number_format($company_rate,2,',','.')?> €) zu verwenden.
            <?php else: ?>
              Kein Firmensatz hinterlegt – hier optional Projektsatz eintragen.
            <?php endif; ?>
          </div>
        </div>
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?=url('/companies/show.php')?>?id=<?=$company_id?>">Zurück</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
