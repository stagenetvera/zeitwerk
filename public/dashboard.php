<?php
require __DIR__ . '/../src/layout/header.php';
require_login();
$user = auth_user();
$account_id = (int)$user['account_id'];

// offene Aufgaben (vereinfachte Liste)
$stmt = $pdo->prepare('SELECT t.*, p.title AS project_title, c.name AS company_name
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  JOIN companies c ON c.id = p.company_id AND c.account_id = t.account_id
  WHERE t.account_id = ? AND t.status IN ("offen","warten","fakturierbar")
  ORDER BY t.deadline IS NULL, t.deadline ASC, t.id DESC
  LIMIT 20');
$stmt->execute([$account_id]);
$tasks = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Dashboard</h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?=url('/times/index.php')?>">Meine Zeiten</a>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Offene Aufgaben</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr><th>Aufgabe</th><th>Projekt</th><th>Firma</th><th>Deadline</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($tasks as $t): ?>
          <tr>
            <td><?=nl2br(h($t['description']))?></td>
            <td><?=h($t['project_title'])?></td>
            <td><?=h($t['company_name'])?></td>
            <td><?=h($t['deadline'] ?? '')?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="<?=url('/times/start.php?task_id='.$t['id'])?>">Start</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tasks): ?>
          <tr><td colspan="5" class="text-center text-muted">Keine offenen Aufgaben gefunden.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
