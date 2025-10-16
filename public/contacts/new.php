<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];

$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
// Firma prüfen (Mandantenschutz)
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$company_id,$account_id]);
$company = $cs->fetch();
if (!$company) { echo '<div class="alert alert-danger">Firma nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  if ($name) {
    $ins = $pdo->prepare('INSERT INTO contacts(account_id,company_id,name,email,phone) VALUES(?,?,?,?,?)');
    $ins->execute([$account_id,$company_id,$name,$email ?: null,$phone ?: null]);
    redirect('/companies/show.php?id=' . $company_id);
  } else {
    $err = 'Name ist erforderlich.';
  }
}
?>
<div class="row">
  <div class="col-md-7">
    <h3>Neuer Ansprechpartner – <?=h($company['name'])?></h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">E-Mail</label>
        <input type="email" name="email" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">Telefon</label>
        <input type="text" name="phone" class="form-control">
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?=url('/companies/show.php')?>?id=<?=$company_id?>">Abbrechen</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
