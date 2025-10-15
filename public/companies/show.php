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

// helpers
function page_int($val, $default=1) {
  $n = (int)$val;
  return $n > 0 ? $n : $default;
}
function render_pagination_named($baseUrl, $param, $current, $total) {
  if ($total <= 1) return '';
  $html = '<nav><ul class="pagination mb-0">';
  $prev = max(1, $current-1);
  $next = min($total, $current+1);
  $disabledPrev = $current===1 ? ' disabled' : '';
  $disabledNext = $current===$total ? ' disabled' : '';

  $html .= '<li class="page-item'.$disabledPrev.'"><a class="page-link" href="'.h($baseUrl).'&'.$param.'=1" aria-label="Erste">&laquo;</a></li>';
  $html .= '<li class="page-item'.$disabledPrev.'"><a class="page-link" href="'.h($baseUrl).'&'.$param.'='.$prev.'" aria-label="Zurück">&lsaquo;</a></li>';

  $start = max(1, $current-2);
  $end   = min($total, $current+2);
  for ($i=$start; $i<=$end; $i++) {
    $active = $i===$current ? ' active' : '';
    $html .= '<li class="page-item'.$active.'"><a class="page-link" href="'.h($baseUrl).'&'.$param.'='.$i.'">'.$i.'</a></li>';
  }

  $html .= '<li class="page-item'.$disabledNext.'"><a class="page-link" href="'.h($baseUrl).'&'.$param.'='.$next.'" aria-label="Weiter">&rsaquo;</a></li>';
  $html .= '<li class="page-item'.$disabledNext.'"><a class="page-link" href="'.h($baseUrl).'&'.$param.'='.$total.'" aria-label="Letzte">&raquo;</a></li>';
  $html .= '</ul></nav>';
  return $html;
}

// CONTACTS (no pagination here)
$ks = $pdo->prepare('SELECT * FROM contacts WHERE account_id = ? AND company_id = ? ORDER BY name');
$ks->execute([$account_id, $id]);
$contacts = $ks->fetchAll();

// PROJECTS pagination (10 per page)
$proj_per_page = 10;
$proj_page = page_int($_GET['proj_page'] ?? 1);
$proj_offset = ($proj_page - 1) * $proj_per_page;

$pcnt = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE account_id = ? AND company_id = ?');
$pcnt->execute([$account_id, $id]);
$projects_total = (int)$pcnt->fetchColumn();
$projects_pages = max(1, (int)ceil($projects_total / $proj_per_page));

$ps = $pdo->prepare('SELECT p.id, p.title, p.status, p.hourly_rate AS project_rate, c.hourly_rate AS company_rate, COALESCE(p.hourly_rate, c.hourly_rate) AS effective_rate
                     FROM projects p JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
                     WHERE p.account_id = ? AND p.company_id = ?
                     ORDER BY p.title
                     LIMIT ? OFFSET ?');
$ps->bindValue(1, $account_id, PDO::PARAM_INT);
$ps->bindValue(2, $id, PDO::PARAM_INT);
$ps->bindValue(3, $proj_per_page, PDO::PARAM_INT);
$ps->bindValue(4, $proj_offset, PDO::PARAM_INT);
$ps->execute();
$projects = $ps->fetchAll();

// TASKS pagination (10 per page), sorted by global dashboard order
$task_per_page = 10;
$task_page = page_int($_GET['task_page'] ?? 1);
$task_offset = ($task_page - 1) * $task_per_page;

// total open tasks for this company
$tcnt = $pdo->prepare('SELECT COUNT(*)
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  WHERE t.account_id = ? AND p.company_id = ? AND t.status <> "abgeschlossen"');
$tcnt->execute([$account_id, $id]);
$tasks_total = (int)$tcnt->fetchColumn();
$tasks_pages = max(1, (int)ceil($tasks_total / $task_per_page));

// Ensure ordering table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS task_ordering_global (
  account_id INT NOT NULL,
  task_id INT NOT NULL,
  position INT NOT NULL,
  PRIMARY KEY (account_id, task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// fetch paginated tasks
$sqlTasks = "SELECT
    t.id AS task_id,
    t.description,
    t.deadline,
    t.planned_minutes,
    t.priority,
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
    ), 0) AS spent_minutes,
    og.position AS sort_pos
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  LEFT JOIN task_ordering_global og ON og.account_id = t.account_id AND og.task_id = t.id
  WHERE t.account_id = :acc AND p.company_id = :cid AND t.status <> 'abgeschlossen'
  ORDER BY (og.position IS NULL), og.position, p.title, t.deadline IS NULL, t.deadline, t.id DESC
  LIMIT :limit OFFSET :offset";
$st = $pdo->prepare($sqlTasks);
$st->bindValue(':uid', $user_id, PDO::PARAM_INT);
$st->bindValue(':acc', $account_id, PDO::PARAM_INT);
$st->bindValue(':cid', $id, PDO::PARAM_INT);
$st->bindValue(':limit', $task_per_page, PDO::PARAM_INT);
$st->bindValue(':offset', $task_offset, PDO::PARAM_INT);
$st->execute();
$tasks = $st->fetchAll();

// running timer for info
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

function fmt_minutes($m) {
  if ($m === null) return '—';
  $m = (int)$m;
  $h = intdiv($m, 60);
  $r = $m % 60;
  if ($h > 0) return sprintf('%d:%02d h', $h, $r);
  return $m . ' min';
}
function prio_badge($p) {
  $p = strtolower((string)$p);
  if ($p === 'high') return '<span class=\"badge bg-danger\">high</span>';
  if ($p === 'low')  return '<span class=\"badge bg-secondary\">low</span>';
  return '<span class=\"badge bg-info text-dark\">medium</span>';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Firma: <?=h($company['name'])?></h3>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?=url('/companies/index.php')?>">Zurück zur Übersicht</a>
    <a class="btn btn-primary" href="<?=url('/companies/edit.php')?>?id=<?=$company['id']?>">Firma bearbeiten</a>
    <a class="btn btn-success" href="<?=url('/tasks/new.php')?>?company_id=<?=$company['id']?>">Neue Aufgabe</a>
  </div>
</div>

<!-- Firmendaten -->
<div class="card mb-3">
  <div class="card-body">
    <div class="row">
      <div class="col-md-6">
        <div class="mb-2"><strong>Adresse:</strong><br><pre class="mb-0" style="white-space:pre-wrap;"><?=h($company['address'] ?? '')?></pre></div>
        <div class="mb-2"><strong>USt-ID:</strong> <?=h($company['vat_id'] ?? '—')?></div>
      </div>
      <div class="col-md-6">
        <div class="mb-2"><strong>Stundensatz (Firma):</strong>
          <?php if ($company['hourly_rate'] !== null): ?>
            <?= number_format((float)$company['hourly_rate'], 2, ',', '.') ?> €
          <?php else: ?>—<?php endif; ?>
        </div>
        <div class="mb-2"><strong>Status:</strong> <?=h($company['status'] ?? '—')?></div>
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
            <tr><td colspan="4" class="text-center text-muted">Keine Projekte.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex justify-content-end p-2">
      <?php
        // preserve other group's current page
        $base = url('/companies/show.php') . '?id=' . $company['id'] . '&task_page=' . $task_page . '&proj_page';
        echo render_pagination_named($base, 'proj_page', $proj_page, $projects_pages);
      ?>
    </div>
  </div>
</div>

<!-- Aufgaben (projektübergreifend, globale Reihenfolge, paginiert) -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0">Aufgaben</h4>
  <a class="btn btn-sm btn-primary" href="<?=url('/tasks/new.php')?>?company_id=<?=$company['id']?>">Neu</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Projekt</th>
            <th>Aufgabe</th>
            <th>Priorität</th>
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
            ?>
            <tr>
              <td><?=h($t['project_title'])?></td>
              <td><?=h($t['description'])?></td>
              <td><?= prio_badge($t['priority']) ?></td>
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
              <td><?= fmt_minutes($total) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?=url('/tasks/edit.php')?>?id=<?=$t['task_id']?>">Bearbeiten</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tasks): ?>
            <tr><td colspan="7" class="text-center text-muted">Keine offenen Aufgaben.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex justify-content-end p-2">
      <?php
        $base = url('/companies/show.php') . '?id=' . $company['id'] . '&proj_page=' . $proj_page . '&task_page';
        echo render_pagination_named($base, 'task_page', $task_page, $tasks_pages);
      ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
