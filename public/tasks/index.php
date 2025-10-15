<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$stmt = $pdo->prepare('SELECT t.*, p.title AS project_title FROM tasks t JOIN projects p ON p.id=t.project_id AND p.account_id=t.account_id WHERE t.account_id = ? ORDER BY t.id DESC');
$stmt->execute([(int)$user['account_id']]);
$tasks = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Aufgaben</h3>
  <a class="btn btn-primary" href="<?=url('/tasks/new.php')?>">Neue Aufgabe</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-striped table-hover mb-0">
      <thead><tr><th>Beschreibung</th><th>Projekt</th><th>Status</th><th>Deadline</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($tasks as $t): ?>
          <tr>
            <td><?=nl2br(h($t['description']))?></td>
            <td><?=h($t['project_title'])?></td>
            <td><?=h($t['status'])?></td>
            <td><?=h($t['deadline'] ?? '')?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="<?=url('/times/start.php?task_id='.$t['id'])?>">Start</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tasks): ?>
          <tr><td colspan="5" class="text-center text-muted">Noch keine Aufgaben.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
