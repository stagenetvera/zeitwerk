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

// Kontakte der Firma
$ks = $pdo->prepare('SELECT * FROM contacts WHERE account_id = ? AND company_id = ? ORDER BY name');
$ks->execute([$account_id, $id]);
$contacts = $ks->fetchAll();

// Projekte der Firma
$ps = $pdo->prepare('SELECT id, title, status, hourly_rate FROM projects WHERE account_id = ? AND company_id = ? ORDER BY title');
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

// Aufgaben der Firma (projektübergreifend, nur nicht-abgeschlossene) + Summe der beendeten Minuten des Users
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

  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header fw-bold">Firmendaten</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?=h($company['status'])?></dd>
          <dt class="col-sm-4">Stundensatz</dt><dd class="col-sm-8"><?= $company['hourly_rate'] !== null ? number_format($company['hourly_rate'], 2, ',', '.') . ' €' : '–' ?></dd>
          <dt class="col-sm-4">USt-ID</dt><dd class="col-sm-8"><?=h($company['vat_id'] ?? '')?></dd>
          <dt class="col-sm-4">Adresse</dt><dd class="col-sm-8"><?=nl2br(h($company['address'] ?? ''))?></dd>
        </dl>
      </div>
    </div>
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
  <a class="btn btn-sm btn-primary" href="<?=url('/companies/projects_new.php')?>?company_id=<?=$company['id']?>">Neu</a>
</div>
<div class="card mb-4">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Titel</th>
            <th>Status</th>
            <th>Projekt-Stundensatz</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td><?=h($p['title'])?></td>
              <td><?=h($p['status'])?></td>
              <td><?= $p['hourly_rate'] !== null ? number_format($p['hourly_rate'], 2, ',', '.') . ' €' : '–' ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?=url('/projects/edit.php')?>?id=<?=$p['id']?>">Bearbeiten</a>
                <form class="d-inline" method="post" action="<?=url('/projects/delete.php')?>" onsubmit="return confirm('Dieses Projekt wirklich löschen?');">
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
  <a class="btn btn-sm btn-primary" href="<?=url('/tasks/new.php')?>?company_id=<?=$company['id']?>&returnTo=companies">Neu</a>
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
