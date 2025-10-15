<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$err = null; $ok = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim($_POST['name'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
  $vat = trim($_POST['vat_id'] ?? '');
  $status = $_POST['status'] ?? 'laufend';
  if ($name) {
    $stmt = $pdo->prepare('INSERT INTO companies(account_id,name,address,hourly_rate,vat_id,status) VALUES(?,?,?,?,?,?)');
    $stmt->execute([(int)$user['account_id'],$name,$address,$rate,$vat,$status]);
    $ok = "Gespeichert.";
  } else {
    $err = "Name ist erforderlich.";
  }
}
?>
<div class="row">
  <div class="col-md-7">
    <h3>Neue Firma</h3>
    <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Adresse</label>
        <textarea name="address" class="form-control" rows="3"></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Vereinbarter Stundensatz (€)</label>
        <input type="number" step="0.01" name="hourly_rate" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">Umsatzsteuer-ID</label>
        <input type="text" name="vat_id" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="laufend">laufend</option>
          <option value="abgeschlossen">abgeschlossen</option>
        </select>
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?=url('/companies/index.php')?>">Zurück</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
