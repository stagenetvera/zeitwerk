<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

// Ensure ordering table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS task_ordering_global (
  account_id INT NOT NULL,
  task_id INT NOT NULL,
  position INT NOT NULL,
  PRIMARY KEY (account_id, task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cs = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$id, $account_id]);
$company = $cs->fetch();
if (!$company) {
  echo '<div class="alert alert-danger">Firma nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}

// Projekte & Kontakte wie gehabt (gekürzt, Fokus auf Aufgabenliste in globaler Reihenfolge)
$ps = $pdo->prepare('SELECT id, title FROM projects WHERE account_id = ? AND company_id = ? ORDER BY title');
$ps->execute([$account_id, $id]);
$projects = $ps->fetchAll();

// aktuell laufender Timer
$runStmt = $pdo->prepare('SELECT id, task_id, started_at FROM times WHERE account_id = ? AND user_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
$runStmt->execute([$account_id, $user_id]);
$running = $runStmt->fetch();
$has_running = (bool)$running;
$running_task_id = $running && $running['task_id'] ? (int)$running['task_id'] : 0;
$running_extra = 0;
if ($has_running) {
  $start = new DateTimeImmutable($running['started_at']);
  $now   = new DateTimeImmutable('now');
  $running_extra = max(0, (int) floor(($now->getTimestamp() - $start->getTimestamp()) / 60));
}

// Aufgaben dieser Firma, sortiert nach globaler Reihenfolge (Lücken möglich)
$sqlTasks = "SELECT
    t.id AS task_id,
    t.description,
    t.deadline,
    t.planned_minutes,
    p.title AS project_title,
    og.position AS sort_pos,
    COALESCE((
      SELECT SUM(tt.minutes)
      FROM times tt
      WHERE tt.account_id = t.account_id
        AND tt.user_id    = :uid
        AND tt.task_id    = t.id
        AND tt.minutes IS NOT NULL
    ), 0) AS spent_minutes
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  LEFT JOIN task_ordering_global og ON og.account_id = t.account_id AND og.task_id = t.id
  WHERE t.account_id = :acc AND p.company_id = :cid AND t.status <> 'abgeschlossen'
  ORDER BY (og.position IS NULL), og.position, p.title, t.deadline IS NULL, t.deadline, t.id DESC";
$st = $pdo->prepare($sqlTasks);
$st->execute(['uid' => $user_id, 'acc' => $account_id, 'cid' => $id]);
$tasks = $st->fetchAll();

function fmt_minutes($m) {
  if ($m === null) return '—';
  $m = (int)$m;
  $h = intdiv($m, 60);
  $r = $m % 60;
  if ($h > 0) return sprintf('%d:%02d h', $h, $r);
  return $m . ' min';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Firma: <?=h($company['name'])?></h3>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?=url('/companies/index.php')?>">Zurück zur Übersicht</a>
    <a class="btn btn-success" href="<?=url('/tasks/new_scoped.php')?>?company_id=<?=$company['id']?>">Neue Aufgabe</a>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Projekt</th>
            <th>Aufgabe</th>
            <th>Deadline</th>
            <th>Geschätzt</th>
            <th>Aufgelaufene Zeit</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tasks as $t): ?>
            <?php
              $total = (int)$t['spent_minutes'];
              if ($has_running && $running && (int)$t['task_id'] === (int)$running['task_id']) {
                $total += $running_extra;
              }
            ?>
            <tr>
              <td><?=h($t['project_title'])?></td>
              <td><?=h($t['description'])?></td>
              <td><?= $t['deadline'] ? h($t['deadline']) : '—' ?></td>
              <td>
                <?php if ($t['planned_minutes'] !== null): ?>
                  <?php
                    $planned = (int)$t['planned_minutes'];
                    $warn_class = '';
                    if ($planned > 0) {
                      $ratio = $total / $planned;
                      if ($ratio >= 1.0)      $warn_class = 'badge bg-danger';
                      elseif ($ratio >= 0.8)  $warn_class = 'badge bg-warning text-dark';
                    }
                  ?>
                  <?php if ($warn_class): ?>
                    <span class="<?= $warn_class ?>"><?= fmt_minutes($planned) ?></span>
                  <?php else: ?>
                    <?= fmt_minutes($planned) ?>
                  <?php endif; ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td>
                <?= fmt_minutes($total) ?>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?=url('/tasks/edit.php')?>?id=<?=$t['task_id']?>">Bearbeiten</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tasks): ?>
            <tr><td colspan="6" class="text-center text-muted">Keine offenen Aufgaben.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
