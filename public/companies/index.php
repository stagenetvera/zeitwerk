<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$stmt = $pdo->prepare('SELECT * FROM companies WHERE account_id = ? ORDER BY name');
$stmt->execute([(int)$user['account_id']]);
$companies = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Firmen</h3>
  <a class="btn btn-primary" href="<?=url('/companies/new.php')?>">Neue Firma</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-striped table-hover mb-0">
      <thead><tr><th>Name</th><th>Status</th><th>Stundensatz</th><th>USt-ID</th></tr></thead>
      <tbody>
        <?php foreach ($companies as $c): ?>
          <tr>
            <td><?=h($c['name'])?></td>
            <td><?=h($c['status'])?></td>
            <td><?=h($c['hourly_rate'])?></td>
            <td><?=h($c['vat_id'])?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$companies): ?>
          <tr><td colspan="4" class="text-center text-muted">Noch keine Firmen.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
