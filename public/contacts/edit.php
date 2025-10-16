<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdo->prepare('SELECT * FROM contacts WHERE id = ? AND account_id = ?');
$st->execute([$id,$account_id]);
$contact = $st->fetch();
if (!$contact) { echo '<div class="alert alert-danger">Ansprechpartner nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }
$company_id = (int)$contact['company_id'];

// Firma für Titel
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$company_id,$account_id]);
$company = $cs->fetch();

$ok = $err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  if ($name) {
    $upd = $pdo->prepare('UPDATE contacts SET name=?, email=?, phone=? WHERE id=? AND account_id=?');
    $upd->execute([$name,$email ?: null,$phone ?: null,$id,$account_id]);
    flash('Ansprechpartner gespeichert.', 'success');
    redirect('/companies/show.php?id=' . $company_id);
    $ok = 'Gespeichert.';
    $st->execute([$id,$account_id]); $contact = $st->fetch();
  } else {
    $err = 'Name ist erforderlich.';
  }
}
?>
<div class="row">
  <div class="col-md-7">
    <h3>Ansprechpartner bearbeiten – <?=h($company['name'] ?? '')?></h3>
    <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" value="<?=h($contact['name'])?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">E-Mail</label>
        <input type="email" name="email" class="form-control" value="<?=h($contact['email'] ?? '')?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Telefon</label>
        <input type="text" name="phone" class="form-control" value="<?=h($contact['phone'] ?? '')?>">
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?=url('/companies/show.php')?>?id=<?=$company_id?>">Abbrechen</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
