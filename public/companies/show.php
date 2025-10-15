<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$account_id = (int)$user['account_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cs = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$id, $account_id]);
$company = $cs->fetch();
if (!$company) {
  echo '<div class="alert alert-danger">Firma nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}

// Kontakte der Firma laden
$ks = $pdo->prepare('SELECT * FROM contacts WHERE account_id = ? AND company_id = ? ORDER BY name');
$ks->execute([$account_id, $id]);
$contacts = $ks->fetchAll();

// Projekte der Firma laden
$ps = $pdo->prepare('SELECT id, title, status, hourly_rate FROM projects WHERE account_id = ? AND company_id = ? ORDER BY title');
$ps->execute([$account_id, $id]);
$projects = $ps->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Firma: <?=h($company['name'])?></h3>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?=url('/companies/index.php')?>">Zurück zur Übersicht</a>
    <a class="btn btn-primary" href="<?=url('/companies/edit.php')?>?id=<?=$company['id']?>">Firma bearbeiten</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header fw-bold">Firmendaten</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?=h($company['status'])?></dd>
          <dt class="col-sm-4">Stundensatz</dt><dd class="col-sm-8"><?= $company['hourly_rate'] !== null ? number_format($company['hourly_rate'], 2, ',', '.') . ' €' : '–' ?></dd>
          <dt class="col-sm-4">USt-ID</dt><dd class="col-sm-8"><?=h($company['vat_id'] ?? '')?></dd>
          <dt class="col-sm-4">Adresse</dt><dd class="col-sm-8"><?=nl2br(h($company['address'] ?? ''))?></dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0">Ansprechpartner</h4>
  <a class="btn btn-sm btn-primary" href="<?=url('/companies/contacts_new.php')?>?company_id=<?=$company['id']?>">Neu</a>
</div>
<div class="card mb-4">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Name</th>
            <th>E-Mail</th>
            <th>Telefon</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contacts as $c): ?>
            <tr>
              <td><?=h($c['name'])?></td>
              <td><?=h($c['email'] ?? '')?></td>
              <td><?=h($c['phone'] ?? '')?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?=url('/companies/contacts_edit.php')?>?id=<?=$c['id']?>">Bearbeiten</a>
                <form class="d-inline" method="post" action="<?=url('/companies/contacts_delete.php')?>" onsubmit="return confirm('Diesen Ansprechpartner wirklich löschen?');">
                  <?=csrf_field()?>
                  <input type="hidden" name="id" value="<?=$c['id']?>">
                  <button class="btn btn-sm btn-outline-danger">Löschen</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$contacts): ?>
            <tr><td colspan="4" class="text-center text-muted">Noch keine Ansprechpartner.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0">Projekte</h4>
  <a class="btn btn-sm btn-primary" href="<?=url('/companies/projects_new.php')?>?company_id=<?=$company['id']?>">Neu</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Titel</th>
            <th>Status</th>
            <th>Projekt-Stundensatz</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td><?=h($p['title'])?></td>
              <td><?=h($p['status'])?></td>
              <td><?= $p['hourly_rate'] !== null ? number_format($p['hourly_rate'], 2, ',', '.') . ' €' : '–' ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?=url('/projects/edit.php')?>?id=<?=$p['id']?>">Bearbeiten</a>
                <form class="d-inline" method="post" action="<?=url('/projects/delete.php')?>" onsubmit="return confirm('Dieses Projekt wirklich löschen?');">
                  <?=csrf_field()?>
                  <input type="hidden" name="id" value="<?=$p['id']?>">
                  <button class="btn btn-sm btn-outline-danger">Löschen</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$projects): ?>
            <tr><td colspan="4" class="text-center text-muted">Noch keine Projekte.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
