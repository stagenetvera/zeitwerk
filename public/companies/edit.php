<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
$stmt->execute([$id, $account_id]);
$company = $stmt->fetch();
if (!$company) { echo '<div class="alert alert-danger">Firma nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

$ok = $err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim($_POST['name'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
  $vat = trim($_POST['vat_id'] ?? '');
  $status = $_POST['status'] ?? 'laufend';
  if ($name) {
    $upd = $pdo->prepare('UPDATE companies SET name=?, address=?, hourly_rate=?, vat_id=?, status=? WHERE id=? AND account_id=?');
    $upd->execute([$name,$address,$rate,$vat,$status,$id,$account_id]);
    $ok = 'Gespeichert.';
    $stmt->execute([$id, $account_id]); $company = $stmt->fetch();
  } else {
    $err = 'Name ist erforderlich.';
  }
}
?>
<div class="row"><div class="col-md-7">
  <h3>Firma bearbeiten</h3>
  <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
  <form method="post" action="<?=url('/companies/update_and_redirect.php')?>">
    <?=csrf_field()?>
    <input type="hidden" name="id" value="<?=$company['id']?>">
    <div class="mb-3"><label class="form-label">Name</label>
      <input type="text" name="name" class="form-control" value="<?=h($company['name'])?>" required></div>
    <div class="mb-3"><label class="form-label">Adresse</label>
      <textarea name="address" class="form-control" rows="3"><?=h($company['address'] ?? '')?></textarea></div>
    <div class="mb-3"><label class="form-label">Stundensatz (€)</label>
      <input type="number" step="0.01" name="hourly_rate" class="form-control" value="<?=h($company['hourly_rate'] ?? '')?>"></div>
    <div class="mb-3"><label class="form-label">USt-ID</label>
      <input type="text" name="vat_id" class="form-control" value="<?=h($company['vat_id'] ?? '')?>"></div>
    <div class="mb-3"><label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="laufend" <?=$company['status']==='laufend'?'selected':''?>>laufend</option>
        <option value="abgeschlossen" <?=$company['status']==='abgeschlossen'?'selected':''?>>abgeschlossen</option>
      </select>
    </div>
    <button class="btn btn-primary">Speichern</button>
    <a class="btn btn-outline-secondary" href="<?=url('/companies/index.php')?>">Zurück</a>
  </form>
</div></div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
