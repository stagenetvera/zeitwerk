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

// Offene Aufgaben mit globaler Sortierung
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
          ), 0) AS spent_minutes,
          og.position AS sort_pos
        FROM tasks t
        JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
        JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
        LEFT JOIN task_ordering_global og ON og.account_id = t.account_id AND og.task_id = t.id
        WHERE t.account_id = :acc AND t.status <> 'abgeschlossen'
        ORDER BY (og.position IS NULL), og.position, c.name, p.title, t.deadline IS NULL, t.deadline, t.id DESC";
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
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Dashboard – Offene Aufgaben</h3>
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
              $planned = $r['planned_minutes'] !== null ? (int)$r['planned_minutes'] : null;
              $badge = '';
              if ($planned && $planned > 0) {
                $ratio = $total_minutes / $planned;
                if     ($ratio >= 1.0) $badge = 'badge bg-danger';
                elseif ($ratio >= 0.8) $badge = 'badge bg-warning text-dark';
                else                   $badge = 'badge bg-success';
              }
            ?>
            <tr>
              <td><?=h($r['company_name'])?> (<?=h($r['project_title'])?>)</td>
              <td><?=h($r['description'])?></td>
              <td><?= $r['deadline'] ? h($r['deadline']) : '—' ?></td>
              <td><?php if ($planned): ?><?php if ($badge): ?><span class="<?=$badge?>"><?=fmt_minutes($planned)?></span><?php else: ?><?=fmt_minutes($planned)?><?php endif; ?><?php else: ?>—<?php endif; ?></td>
              <td><?=fmt_minutes($total_minutes)?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?=url('/tasks/edit.php')?>?id=<?=$r['task_id']?>&return_to=<?=urlencode($_SERVER['REQUEST_URI'])?>">Bearbeiten</a>
                <form class="d-inline" method="post" action="<?=url('/tasks/delete.php')?>" onsubmit="return confirm('Diese Aufgabe wirklich löschen?');">
                  <?=csrf_field()?>
                  <input type="hidden" name="id" value="<?=$r['task_id']?>">
                  <input type="hidden" name="return_to" value="<?=h($_SERVER['REQUEST_URI'])?>">
                  <button class="btn btn-sm btn-outline-danger">Löschen</button>
                </form>
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
