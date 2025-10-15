<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$stmt = $pdo->prepare('SELECT p.*, c.name AS company_name FROM projects p JOIN companies c ON c.id=p.company_id AND c.account_id=p.account_id WHERE p.account_id = ? ORDER BY p.id DESC');
$stmt->execute([(int)$user['account_id']]);
$projects = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Projekte</h3>
  <a class="btn btn-primary" href="<?=url('/projects/new.php')?>">Neues Projekt</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-striped table-hover mb-0">
      <thead><tr><th>Titel</th><th>Firma</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($projects as $p): ?>
          <tr>
            <td><?=h($p['title'])?></td>
            <td><?=h($p['company_name'])?></td>
            <td><?=h($p['status'])?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$projects): ?>
          <tr><td colspan="3" class="text-center text-muted">Noch keine Projekte.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
