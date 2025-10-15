<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$account_id = (int)$user['account_id'];

$stmt = $pdo->prepare('SELECT id, name, address, hourly_rate, vat_id, status FROM companies WHERE account_id = ? ORDER BY name');
$stmt->execute([$account_id]);
$companies = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Firmen</h3>
  <a class="btn btn-primary" href="<?=url('/companies/new.php')?>">Neu</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Name</th>
            <th>Status</th>
            <th>Stundensatz</th>
            <th>USt-ID</th>
            <th>Adresse</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($companies as $c): ?>
          <tr>
            <td><?=h($c['name'])?></td>
            <td><span class="badge <?=($c['status']==='abgeschlossen'?'bg-secondary':'bg-success')?>"><?=h($c['status'])?></span></td>
            <td><?= $c['hourly_rate'] !== null ? number_format($c['hourly_rate'], 2, ',', '.') . ' €' : '–' ?></td>
            <td><?=h($c['vat_id'] ?? '')?></td>
            <td class="text-truncate" style="max-width: 380px;"><?=nl2br(h($c['address'] ?? ''))?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-secondary" href="<?=url('/companies/edit.php')?>?id=<?=$c['id']?>">Bearbeiten</a>
              <form class="d-inline" method="post" action="<?=url('/companies/delete.php')?>" onsubmit="return confirm('Diese Firma wirklich löschen?');">
                <?=csrf_field()?>
                <input type="hidden" name="id" value="<?=$c['id']?>">
                <button class="btn btn-sm btn-outline-danger">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$companies): ?>
          <tr><td colspan="6" class="text-center text-muted">Noch keine Firmen.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
