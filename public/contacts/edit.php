<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM contacts WHERE id = ? AND account_id = ?');
$stmt->execute([$id, $account_id]);
$contact = $stmt->fetch();
if (!$contact) {
  echo '<div class="alert alert-danger">Kontakt nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}

// Firmenliste für Auswahl
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cs->execute([$account_id]);
$companies = $cs->fetchAll();

$ok = $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $company_id = (int)($_POST['company_id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  if ($company_id && $name) {
    $upd = $pdo->prepare('UPDATE contacts SET company_id=?, name=?, email=?, phone=? WHERE id=? AND account_id=?');
    $upd->execute([$company_id,$name,$email ?: null,$phone ?: null,$id,$account_id]);
    $ok = 'Gespeichert.';
    // Reload aktuellen Datensatz
    $stmt->execute([$id, $account_id]);
    $contact = $stmt->fetch();
  } else {
    $err = 'Firma und Name sind erforderlich.';
  }
}
?>
<div class="row">
  <div class="col-md-7">
    <h3>Ansprechpartner bearbeiten</h3>
    <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Firma</label>
        <select name="company_id" class="form-select" required>
          <?php foreach ($companies as $c): ?>
            <option value="<?=$c['id']?>" <?=$c['id']==$contact['company_id']?'selected':''?>><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
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
      <a class="btn btn-outline-secondary" href="<?=url('/contacts/index.php')?>">Zurück</a>
    </form>
  </div>
  </div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
