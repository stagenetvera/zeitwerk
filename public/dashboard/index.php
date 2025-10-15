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

// Aktuell laufender Timer des Users
$runStmt = $pdo->prepare('SELECT id, task_id, started_at FROM times WHERE account_id = ? AND user_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
$runStmt->execute([$account_id, $user_id]);
$running = $runStmt->fetch();
$has_running = (bool)$running;
$running_task_id = $running && $running['task_id'] ? (int)$running['task_id'] : 0;

// Offene Aufgaben + Join auf globale Reihenfolge
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
  <div id="saveHint" class="text-muted">Per Drag&Drop sortieren</div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <form id="orderForm" class="d-none"><?=csrf_field()?></form>
      <table class="table table-striped table-hover mb-0" id="tasksTable">
        <thead>
          <tr>
            <th style="width:34px;"></th>
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
              if ($has_running && $running && (int)$r['task_id'] === (int)$running['task_id']) {
                // Live-Anteil addieren
                $start = new DateTimeImmutable($running['started_at']);
                $now   = new DateTimeImmutable('now');
                $total_minutes += max(0, (int) floor(($now->getTimestamp() - $start->getTimestamp()) / 60));
              }
              $is_running_here = $has_running && (($running_task_id === 0) || ($running_task_id === (int)$r['task_id']));

              // Ampel: grün <80%, gelb >=80%, rot >=100%
              $badge_class = '';
              if ($planned && $planned > 0) {
                $ratio = $total_minutes / $planned;
                if ($ratio >= 1.0)      $badge_class = 'badge bg-danger';
                elseif ($ratio >= 0.8)  $badge_class = 'badge bg-warning text-dark';
                else                    $badge_class = 'badge bg-success';
              }
            ?>
            <tr draggable="true" data-task-id="<?=$r['task_id']?>">
              <td class="text-muted" title="ziehen zum Sortieren" style="cursor:grab;">⇅</td>
              <td><?=h($r['company_name'])?> (<?=h($r['project_title'])?>)</td>
              <td><?=h($r['description'])?></td>
              <td><?= $r['deadline'] ? h($r['deadline']) : '—' ?></td>
              <td>
                <?php if ($planned): ?>
                  <?php if ($badge_class): ?>
                    <span class="<?= $badge_class ?>"><?= fmt_minutes($planned) ?></span>
                  <?php else: ?>
                    <?= fmt_minutes($planned) ?>
                  <?php endif; ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td>
                <?=fmt_minutes($total_minutes)?>
                <?php if ($has_running && (int)$r['task_id'] === $running_task_id): ?>
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
            <tr><td colspan="7" class="text-center text-muted">Keine offenen Aufgaben.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="p-2 text-end">
        <button id="saveBtn" class="btn btn-outline-secondary btn-sm d-none">Reihenfolge speichern</button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const table = document.getElementById('tasksTable');
  const tbody = table ? table.querySelector('tbody') : null;
  const hint = document.getElementById('saveHint');
  const saveBtn = document.getElementById('saveBtn');
  if (!tbody) return;

  let dragged = null;
  let changed = false;

  function addRowListeners(tr) {
    tr.addEventListener('dragstart', (e) => {
      dragged = tr;
      tr.classList.add('table-active');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', tr.dataset.taskId);
    });

    tr.addEventListener('dragover', (e) => {
      e.preventDefault();
      if (!dragged || dragged === tr) return;
      const rect = tr.getBoundingClientRect();
      const after = (e.clientY - rect.top) > (rect.height / 2);
      if (after) tr.after(dragged); else tr.before(dragged);
      changed = true;
      toggleSaveBtn();
    });

    tr.addEventListener('drop', (e) => {
      e.preventDefault();
      saveOrder(); // Save on drop
    });

    tr.addEventListener('dragend', () => {
      tr.classList.remove('table-active');
      dragged = null;
      if (changed) saveOrder(); // Fallback save
    });
  }

  function toggleSaveBtn() {
    if (!saveBtn) return;
    if (changed) saveBtn.classList.remove('d-none');
    else saveBtn.classList.add('d-none');
  }

  Array.from(tbody.querySelectorAll('tr[data-task-id]')).forEach(addRowListeners);

  function serializeOrder() {
    return Array.from(tbody.querySelectorAll('tr[data-task-id]')).map(tr => tr.dataset.taskId);
  }

  async function saveOrder() {
    if (!changed) return;
    const form = document.getElementById('orderForm');
    const formData = new FormData(form); // enthält CSRF
    // optional: Header-CSRF
    let csrfHeader = null;
    for (const [k, v] of formData.entries()) {
      if (k.toLowerCase().includes('csrf')) { csrfHeader = v; break; }
    }
    serializeOrder().forEach(id => formData.append('order[]', id));

    // FormData -> urlencoded für PHP
    const params = new URLSearchParams();
    formData.forEach((v, k) => params.append(k, v));

    try {
      const res = await fetch('<?=url('/tasks/order_save_global.php')?>', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(csrfHeader ? {'X-CSRF-Token': csrfHeader} : {})
        },
        credentials: 'same-origin',
        body: params.toString()
      });
      changed = false;
      toggleSaveBtn();
      if (res.ok) {
        if (hint) {
          hint.textContent = 'Gespeichert';
          hint.classList.remove('text-muted');
          hint.classList.add('text-success');
          setTimeout(() => {
            hint.textContent = 'Per Drag&Drop sortieren';
            hint.classList.add('text-muted');
            hint.classList.remove('text-success');
          }, 1200);
        }
      } else {
        throw new Error('HTTP ' + res.status);
      }
    } catch (e) {
      console.error('order_save_global failed', e);
      if (hint) {
        hint.textContent = 'Fehler beim Speichern';
        hint.classList.remove('text-muted');
        hint.classList.add('text-danger');
        setTimeout(() => {
          hint.textContent = 'Per Drag&Drop sortieren';
          hint.classList.add('text-muted');
          hint.classList.remove('text-danger');
        }, 2000);
      }
    }
  }

  if (saveBtn) saveBtn.addEventListener('click', (e) => { e.preventDefault(); saveOrder(); });
})();
</script>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
