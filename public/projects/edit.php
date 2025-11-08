<?php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';

require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdo->prepare('SELECT id, company_id, title, status, hourly_rate FROM projects WHERE id = ? AND account_id = ?');
$st->execute([$id, $account_id]);
$project = $st->fetch();
if (!$project) { echo '<div class="alert alert-danger">Projekt nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }
$company_id = (int)$project['company_id'];

$return_to = pick_return_to('/companies/show.php?id='.$company_id);

$cs = $pdo->prepare('SELECT id, name, hourly_rate FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$company_id, $account_id]);
$company = $cs->fetch();
$company_rate = $company['hourly_rate'] ?? null;

$ok = $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim($_POST['title'] ?? '');
  $status = $_POST['status'] ?? 'offen';
  $rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;

  if ($title) {
    $upd = $pdo->prepare('UPDATE projects SET title=?, status=?, hourly_rate=? WHERE id=? AND account_id=?');
    $upd->execute([$title, $status, $rate, $id, $account_id]);
    flash('Projekt gespeichert.', 'success');
    redirect($return_to);
  } else {
    $err = 'Titel ist erforderlich.';
  }
}
require __DIR__ . '/../../src/layout/header.php';

?>
<div class="row">
  <div class="col-md-7">
    <h3>Projekt bearbeiten – <?=h($company['name'] ?? '')?></h3>
    <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <?= return_to_hidden($return_to) ?>
      <div class="mb-3">
        <label class="form-label">Titel</label>
        <input type="text" name="title" class="form-control" value="<?=h($project['title'])?>" required>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php $stSel = $project['status'] ?? 'offen'; ?>
            <option value="offen" <?=$stSel==='offen'?'selected':''?>>offen</option>
            <option value="abgeschlossen" <?=$stSel==='abgeschlossen'?'selected':''?>>abgeschlossen</option>
            <option value="angeboten" <?=$stSel==='angeboten'?'selected':''?>>angeboten</option>
          </select>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Projekt-Stundensatz (€)</label>
          <input type="number" step="0.01" name="hourly_rate" class="form-control" value="<?=h($project['hourly_rate'] ?? '')?>" placeholder="<?= $company_rate !== null ? number_format($company_rate,2,',','.') . ' € (Firma, Standard wenn leer)' : '' ?>">
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
      <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
