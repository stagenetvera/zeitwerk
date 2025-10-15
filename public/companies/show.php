<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cs = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$id, $account_id]);
$company = $cs->fetch();
if (!$company) {
  echo '<div class="alert alert-danger">Firma nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}

// Kontakte
$ks = $pdo->prepare('SELECT * FROM contacts WHERE account_id = ? AND company_id = ? ORDER BY name');
$ks->execute([$account_id, $id]);
$contacts = $ks->fetchAll();

// Projekte (+ effektiver Satz: Projektsatz oder Firmensatz)
$ps = $pdo->prepare('SELECT p.id, p.title, p.status, p.hourly_rate AS project_rate, c.hourly_rate AS company_rate, COALESCE(p.hourly_rate, c.hourly_rate) AS effective_rate FROM projects p JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id WHERE p.account_id = ? AND p.company_id = ? ORDER BY p.title');
$ps->execute([$account_id, $id]);
$projects = $ps->fetchAll();

// Läuft aktuell ein Timer?
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

// Aufgaben (projektübergreifend, offen)
$sqlTasks = "SELECT
    t.id AS task_id,
    t.description,
    t.deadline,
    t.planned_minutes,
    t.status,
    p.id AS project_id,
    p.title AS project_title,
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
  WHERE t.account_id = :acc AND p.company_id = :cid AND t.status <> 'abgeschlossen'
  ORDER BY p.title, t.deadline IS NULL, t.deadline, t.id DESC";
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
    <a class="btn btn-primary" href="<?=url('/companies/edit.php')?>?id=<?=$company['id']?>">Firma bearbeiten</a>
    <a class="btn btn-success" href="<?=url('/tasks/new_scoped.php')?>?company_id=<?=$company['id']?>">Neue Aufgabe</a>
  </div>
</div>

<!-- Ansprechpartner -->
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

<!-- Projekte -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0">Projekte</h4>
  <a class="btn btn-sm btn-primary" href="<?=url('/companies/projects_new_v2.php')?>?company_id=<?=$company['id']?>">Neu</a>
</div>
<div class="card mb-4">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Titel</th>
            <th>Status</th>
            <th>Stundensatz (effektiv)</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td><?=h($p['title'])?></td>
              <td><?=h($p['status'])?></td>
              <td>
                <?php if ($p['effective_rate'] !== null): ?>
                  <?= number_format($p['effective_rate'], 2, ',', '.') ?> €
                  <?php if ($p['project_rate'] === null && $p['company_rate'] !== null): ?>
                    <small class="text-muted">(von Firma)</small>
                  <?php endif; ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?=url('/companies/projects_edit_v2.php')?>?id=<?=$p['id']?>">Bearbeiten</a>
                <form class="d-inline" method="post" action="<?=url('/projects/delete_to_company.php')?>" onsubmit="return confirm('Dieses Projekt wirklich löschen?');">
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

<!-- Aufgaben (projektübergreifend) -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0">Aufgaben</h4>
  <a class="btn btn-sm btn-primary" href="<?=url('/tasks/new_scoped.php')?>?company_id=<?=$company['id']?>">Neu</a>
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
              $planned = $t['planned_minutes'] !== null ? (int)$t['planned_minutes'] : null;
              $warn_class = '';
              if ($planned && $planned > 0) {
                $ratio = $total / $planned;
                if ($ratio >= 1.0)      $warn_class = 'badge bg-danger';
                elseif ($ratio >= 0.8)  $warn_class = 'badge bg-warning text-dark';
              }
              $is_running_here = $has_running && ( ($running_task_id === 0) || ($running_task_id === (int)$t['task_id']) );
            ?>
            <tr>
              <td><?=h($t['project_title'])?></td>
              <td><?=h($t['description'])?></td>
              <td><?= $t['deadline'] ? h($t['deadline']) : '—' ?></td>
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
                <?= fmt_minutes($total) ?>
                <?php if ($has_running && (int)$t['task_id'] === $running_task_id): ?>
                  <small class="text-muted">(inkl. laufend)</small>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <?php if ($is_running_here): ?>
                  <form class="d-inline" method="post" action="<?=url('/times/timer_stop.php')?>">
                    <?=csrf_field()?>
                    <input type="hidden" name="return_to" value="<?=h($_SERVER['REQUEST_URI'])?>">
                    <input type="hidden" name="task_id" value="<?=$t['task_id']?>">
                    <button class="btn btn-sm btn-danger">Stop</button>
                  </form>
                <?php else: ?>
                  <form class="d-inline" method="post" action="<?=url('/times/timer_start.php')?>">
                    <?=csrf_field()?>
                    <input type="hidden" name="return_to" value="<?=h($_SERVER['REQUEST_URI'])?>">
                    <input type="hidden" name="task_id" value="<?=$t['task_id']?>">
                    <button class="btn btn-sm btn-success">Start</button>
                  </form>
                <?php endif; ?>
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
