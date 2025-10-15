<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

// Läuft aktuell ein Timer?
$runStmt = $pdo->prepare('SELECT id, task_id, started_at FROM times WHERE account_id = ? AND user_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
$runStmt->execute([$account_id, $user_id]);
$running = $runStmt->fetch();
$has_running = (bool)$running;
$running_task_id = $running && $running['task_id'] ? (int)$running['task_id'] : 0;

// Offene Aufgaben inkl. geplanter Zeit und summierter beendeter Minuten
$sql = "SELECT
          t.id   AS task_id,
          t.description,
          t.deadline,
          t.planned_minutes,
          p.title AS project_title,
          c.name  AS company_name,
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
        JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
        WHERE t.account_id = :acc AND t.status <> 'abgeschlossen'
        ORDER BY c.name, p.title, t.deadline IS NULL, t.deadline, t.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute(['uid' => $user_id, 'acc' => $account_id]);
$rows = $stmt->fetchAll();

function fmt_minutes($m) {
  if ($m === null) return '—';
  $m = (int)$m;
  $h = intdiv($m, 60);
  $r = $m % 60;
  if ($h > 0) return sprintf('%d:%02d h', $h, $r);
  return $m . ' min';
}

// Laufzeit (Minuten) des evtl. laufenden Timers jetzt berechnen
$running_extra = 0;
if ($has_running) {
  $start = new DateTimeImmutable($running['started_at']);
  $now   = new DateTimeImmutable('now');
  $running_extra = max(0, (int) floor(($now->getTimestamp() - $start->getTimestamp()) / 60));
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Dashboard – Offene Aufgaben</h3>
  <?php if ($has_running && !$running_task_id): ?>
    <div class="alert alert-info mb-0 ms-3" role="alert">
      Ein Timer läuft <strong>ohne</strong> zugeordnete Aufgabe. Klicke bei der passenden Aufgabe auf <em>Stop</em>, um sie zuzuordnen.
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Firma (Projekt)</th>
            <th>Aufgabe</th>
            <th>Deadline</th>
            <th>Geschätzt</th>
            <th>Aufgelaufene Zeit</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $total_minutes = (int)$r['spent_minutes'];
              if ($has_running && $running && (int)$r['task_id'] === (int)$running['task_id']) {
                $total_minutes += $running_extra;
              }
              $planned = $r['planned_minutes'] !== null ? (int)$r['planned_minutes'] : null;
              $warn_class = '';
              if ($planned && $planned > 0) {
                $ratio = $total_minutes / $planned;
                if ($ratio >= 1.0) {
                  $warn_class = 'badge bg-danger';
                } elseif ($ratio >= 0.8) {
                  $warn_class = 'badge bg-warning text-dark';
                }
                else {
                  $warn_class = 'badge bg-success ';
                }
              }

              // Start/Stop-Logik: Stop nur für die laufende Aufgabe; wenn Timer ohne Task läuft → Stop überall anbieten
              $is_running_here = $has_running && ( ($running_task_id === 0) || ($running_task_id === (int)$r['task_id']) );
            ?>
            <tr>
              <td><?=h($r['company_name'])?> (<?=h($r['project_title'])?>)</td>
              <td><?=h($r['description'])?></td>
              <td><?= $r['deadline'] ? h($r['deadline']) : '—' ?></td>
              <td>
                <?php if ($planned): ?>
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
                <?= fmt_minutes($total_minutes) ?>
                <?php if ($has_running && $running_task_id === (int)$r['task_id']): ?>
                  <small class="text-muted">(inkl. laufend)</small>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <?php if ($is_running_here): ?>
                  <form class="d-inline" method="post" action="<?=url('/times/timer_stop.php')?>">
                    <?=csrf_field()?>
                    <input type="hidden" name="return_to" value="<?=h($_SERVER['REQUEST_URI'])?>">
                    <input type="hidden" name="task_id" value="<?=$r['task_id']?>">
                    <button class="btn btn-sm btn-danger">Stop</button>
                  </form>
                <?php else: ?>
                  <form class="d-inline" method="post" action="<?=url('/times/timer_start.php')?>">
                    <?=csrf_field()?>
                    <input type="hidden" name="return_to" value="<?=h($_SERVER['REQUEST_URI'])?>">
                    <input type="hidden" name="task_id" value="<?=$r['task_id']?>">
                    <button class="btn btn-sm btn-success">Start</button>
                  </form>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?=url('/tasks/edit.php')?>?id=<?=$r['task_id']?>">Bearbeiten</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted">Keine offenen Aufgaben.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
