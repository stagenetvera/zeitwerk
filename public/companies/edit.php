<?php
// public/companies/edit.php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

// ---------- return_to (analog zu deinen anderen Skripten) ----------
$return_to = $_POST['return_to'] ?? '';
if (!$return_to && isset($_SERVER['HTTP_REFERER'])) {
  $return_to = $_SERVER['HTTP_REFERER'];
}
// sanitize: allow only same-site relative URLs
$valid = false;
if ($return_to && !preg_match('~^(?:https?:)?//~i', $return_to)) {
  $valid = (str_starts_with($return_to, '/'));
}
if (!$valid) {
  $return_to = "/companies/index.php";
}

// ---------- Input & Datensatz laden ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  echo '<div class="alert alert-danger">Ungültige Firmen-ID.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}
$st = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
$st->execute([$id, $account_id]);
$company = $st->fetch();
if (!$company) {
  echo '<div class="alert alert-danger">Firma nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}

$err = null;

// ---------- POST (Update) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name    = trim($_POST['name'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $rate    = ($_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null);
  $vat     = trim($_POST['vat_id'] ?? '');
  $status  = $_POST['status'] ?? 'laufend';

  if ($name === '') {
    $err = 'Name ist erforderlich.';
  }

  if (!$err) {
    $upd = $pdo->prepare('UPDATE companies
                          SET name = ?, address = ?, hourly_rate = ?, vat_id = ?, status = ?
                          WHERE id = ? AND account_id = ?');
    $upd->execute([$name, $address, $rate, $vat, $status, $id, $account_id]);

    flash('Firma gespeichert.','success');
    redirect($return_to);
  } else {
    // Bei Fehlern Felder für die Re-Render füllen
    $company['name']        = $name;
    $company['address']     = $address;
    $company['hourly_rate'] = $rate;
    $company['vat_id']      = $vat;
    $company['status']      = $status;
  }
}

// ---------- View ----------
?>
<div class="row">
  <div class="col-md-8 col-lg-7">
    <h3>Firma bearbeiten</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $company['id'] ?>">
      <input type="hidden" name="return_to" value="<?= h($return_to) ?>">

      <div class="mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" value="<?= h($company['name']) ?>" required>
      </div>

      <div class="mb-3">
        <label class="form-label">Adresse</label>
        <textarea name="address" class="form-control" rows="3"><?= h($company['address'] ?? '') ?></textarea>
      </div>

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Stundensatz (€)</label>
          <input type="number" step="0.01" name="hourly_rate" class="form-control"
                 value="<?= $company['hourly_rate'] !== null ? h((float)$company['hourly_rate']) : '' ?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">USt-ID</label>
          <input type="text" name="vat_id" class="form-control" value="<?= h($company['vat_id'] ?? '') ?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php $st = $company['status'] ?? 'laufend'; ?>
            <option value="laufend"     <?= $st==='laufend'     ? 'selected' : '' ?>>laufend</option>
            <option value="abgeschlossen" <?= $st==='abgeschlossen' ? 'selected' : '' ?>>abgeschlossen</option>
          </select>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-primary">Speichern</button>
        <a class="btn btn-outline-secondary" href="<?= h($return_to) ?>">Abbrechen</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>