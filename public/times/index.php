<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$stmt = $pdo->prepare('SELECT t.*, ta.description AS task_desc, p.title AS project_title
  FROM times t
  LEFT JOIN tasks ta ON ta.id = t.task_id
  LEFT JOIN projects p ON p.id = ta.project_id
  WHERE t.account_id = ? AND t.user_id = ?
  ORDER BY t.id DESC LIMIT 50');
$stmt->execute([(int)$user['account_id'], (int)$user['id']]);
$times = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Meine Zeiten</h3>
  <?php $running = get_running_time($pdo, (int)$user['account_id'], (int)$user['id']); ?>
  <?php if ($running): ?>
    <a class="btn btn-warning" href="<?=url('/times/stop.php')?>">Laufende Zeit stoppen</a>
  <?php else: ?>
    <a class="btn btn-outline-success" href="<?=url('/times/start.php')?>">Zeit starten</a>
  <?php endif; ?>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-striped table-hover mb-0">
      <thead><tr><th>Start</th><th>Ende</th><th>Dauer (min)</th><th>Aufgabe</th><th>Projekt</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($times as $t): ?>
          <tr>
            <td><?=h($t['started_at'])?></td>
            <td><?=h($t['ended_at'] ?? '—')?></td>
            <td><?=h($t['minutes'] ?? '—')?></td>
            <td><?=h($t['task_desc'] ?? '(ohne Aufgabe)')?></td>
            <td><?=h($t['project_title'] ?? '')?></td>
            <td><?=h($t['status'])?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$times): ?>
          <tr><td colspan="6" class="text-center text-muted">Noch keine Zeiten.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
