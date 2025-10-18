<?php
require __DIR__ . '/../../src/bootstrap.php';  // << keine Ausgabe!

require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

$running = get_running_time($pdo, $account_id, $user_id);
if (!$running) {
  echo '<div class="alert alert-info">Es läuft kein Timer.</div>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}

$err=null; $ok=null;

$return_to = pick_return_to('/dashboard/index.php');

/**
 * NEU: Quick-Stop auf GET, wenn die Aufgabe bereits gesetzt ist.
 * Kein Formular, sofort beenden und zurück.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($running['task_id'])) {
  try {
    $pdo->beginTransaction();

    // Pro-User serialisieren (Mutex)
    $pdo->prepare('SELECT id FROM users WHERE id = ? AND account_id = ? FOR UPDATE')
        ->execute([$user_id, $account_id]);

    // Laufenden Timer exklusiv sperren
    $rs = $pdo->prepare('
      SELECT * FROM times
      WHERE account_id = ? AND user_id = ? AND ended_at IS NULL
      ORDER BY id DESC
      LIMIT 1
      FOR UPDATE
    ');
    $rs->execute([$account_id, $user_id]);
    $cur = $rs->fetch();

    if ($cur && !empty($cur['task_id'])) {
      $now    = new DateTimeImmutable('now');
      $start  = new DateTimeImmutable($cur['started_at']);
      $diff_s = max(0, $now->getTimestamp() - $start->getTimestamp());
      $minutes= max(1, (int)round($diff_s / 60));

      $stp = $pdo->prepare('
        UPDATE times
        SET ended_at = ?, minutes = ?
        WHERE id = ? AND account_id = ? AND user_id = ? AND ended_at IS NULL
      ');
      $stp->execute([$now->format('Y-m-d H:i:s'), $minutes, (int)$cur['id'], $account_id, $user_id]);
    }

    $pdo->commit();
    redirect($return_to);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Fallback: Wenn Quick-Stop fehlschlägt, zeig das Formular (unten)
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $pdo->beginTransaction();

    // Pro-User serialisieren (Mutex)
    $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? AND account_id = ? FOR UPDATE');
    $lock->execute([$user_id, $account_id]);

    // Laufenden Timer exklusiv sperren
    $rs = $pdo->prepare('
      SELECT * FROM times
      WHERE account_id = ? AND user_id = ? AND ended_at IS NULL
      ORDER BY id DESC
      LIMIT 1
      FOR UPDATE
    ');
    $rs->execute([$account_id, $user_id]);
    $cur = $rs->fetch();

    if (!$cur) {
      // Zwischenzeitlich schon gestoppt → idempotent zurück
      $pdo->commit();
      redirect($return_to);
    }

    // Wenn der Timer bisher KEINE Aufgabe hat: Zuweisung/Neuanlage (wie in deinem Code)
    if (empty($cur['task_id'])) {
      $company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
      $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
      $task_id    = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;
      $create_new = isset($_POST['create_new']) && $_POST['create_new'] === '1';

      if ($create_new) {
        $desc     = trim($_POST['new_description'] ?? '');
        // ▼ NEU: Priorität robust mappen (de/en erlaubt)
        $prio_raw = $_POST['new_priority'] ?? null;
        $prio = null;
        if ($prio_raw !== null && $prio_raw !== '') {
          $prio_map = [
            'hoch'    => 'high',
            'mittel'  => 'medium',
            'niedrig' => 'low',
            'high'    => 'high',
            'medium'  => 'medium',
            'low'     => 'low',
          ];
          $prio = $prio_map[$prio_raw] ?? null;   // unbekannt ⇒ null
        }
        $planned  = (isset($_POST['new_planned']) && $_POST['new_planned'] !== '') ? (int)$_POST['new_planned'] : null;
        $deadline = $_POST['new_deadline'] ?? null;
        if ($deadline === '') $deadline = null;

        if ($project_id <= 0 || $desc === '') {
          $pdo->rollBack();
          $err = 'Bitte Projekt wählen und eine Aufgabenbeschreibung angeben.';
        } else {
          $chk = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE id = ? AND account_id = ?' . ($company_id ? ' AND company_id = ?' : ''));
          $params = [$project_id, $account_id];
          if ($company_id) $params[] = $company_id;
          $chk->execute($params);
          if (!$chk->fetchColumn()) {
            $pdo->rollBack();
            $err = 'Ungültige Projekt-/Firmenauswahl.';
          } else {
            $ins = $pdo->prepare('
              INSERT INTO tasks (account_id, project_id, description, planned_minutes, priority, deadline, status)
              VALUES (?,?,?,?,?,?,?)
            ');
            $ins->execute([$account_id, $project_id, $desc, $planned, $prio, $deadline, 'offen']);
            $task_id = (int)$pdo->lastInsertId();
          }
        }
      }

      if (!isset($err) && !$task_id) {
        $pdo->rollBack();
        $err = 'Bitte Aufgabe auswählen oder neu anlegen.';
      }

      if (!isset($err)) {
        // Safety: Task muss zum Account (und optional zum Projekt) gehören
        $chkT = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE id = ? AND account_id = ?');
        $chkT->execute([$task_id, $account_id]);
        if (!$chkT->fetchColumn()) {
          $pdo->rollBack();
          $err = 'Ungültige Aufgabe für diesen Account.';
        } else {
          // Aufgabe vor dem Stoppen zuweisen (atomar)
          $up = $pdo->prepare('UPDATE times SET task_id = ? WHERE id = ? AND account_id = ? AND user_id = ? AND ended_at IS NULL');
          $up->execute([$task_id, (int)$cur['id'], $account_id, $user_id]);
          $cur['task_id'] = $task_id; // für die nachfolgende Logik
        }
      }
    }

    // Timer stoppen (nur wenn keine Fehlermeldung)
    if (!isset($err)) {
      $now    = new DateTimeImmutable('now');
      $start  = new DateTimeImmutable($cur['started_at']);
      $diff_s = max(0, $now->getTimestamp() - $start->getTimestamp());
      $minutes= max(1, (int)round($diff_s / 60));

      $stp = $pdo->prepare('
        UPDATE times
        SET ended_at = ?, minutes = ?
        WHERE id = ? AND account_id = ? AND user_id = ? AND ended_at IS NULL
      ');
      $stp->execute([$now->format('Y-m-d H:i:s'), $minutes, (int)$cur['id'], $account_id, $user_id]);

      $pdo->commit();
      redirect($return_to);
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = 'Timer-Stop fehlgeschlagen.';
  }
}

// Daten nur für das Auswahl-Formular laden, falls noch keine Aufgabe gesetzt ist
if (!$running['task_id']) {
  $cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
  $cs->execute([$account_id]);
  $companies = $cs->fetchAll();

  $ps = $pdo->prepare('SELECT id, company_id, title FROM projects WHERE account_id = ? ORDER BY title');
  $ps->execute([$account_id]);
  $projects = $ps->fetchAll();

  $tsAll = $pdo->prepare('SELECT id, project_id, description, status FROM tasks WHERE account_id = ? ORDER BY description');
  $tsAll->execute([$account_id]);
  $tasksAll = $tsAll->fetchAll();
}

?>
<div class="row">
  <div class="col-md-7">
    <h3>Timer stoppen</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <div class="mb-3">
      <strong>Gestartet:</strong> <?=h($running['started_at'])?>
      <?php if ($running['task_id']): ?>
        <div><span class="badge bg-secondary">Aufgabe bereits zugeordnet</span></div>
      <?php else: ?>
        <div class="text-muted">Aktuell ohne Aufgabe</div>
      <?php endif; ?>
    </div>
    <form method="post">
  <?=csrf_field()?>
  <?= return_to_hidden($return_to) ?>

  <?php if (!$running['task_id']): ?>
    <div class="mb-3">
      <label class="form-label">Firma</label>
      <select class="form-select" name="company_id" id="asg_company" required>
        <option value="">– bitte wählen –</option>
        <?php foreach (($companies ?? []) as $c): ?>
          <option value="<?=$c['id']?>"><?=h($c['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label">Projekt</label>
      <select class="form-select" name="project_id" id="asg_project" required disabled>
        <option value="">– bitte zuerst Firma wählen –</option>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label">Aufgabe</label>
      <select class="form-select" name="task_id" id="asg_task" disabled>
        <option value="">– optional: vorhandene Aufgabe wählen –</option>
      </select>
      <div class="form-text">Alternativ unten „Neue Aufgabe anlegen“ aktivieren.</div>
    </div>

    <div class="form-check form-switch mb-3">
      <input class="form-check-input" type="checkbox" id="asg_new_toggle" name="create_new" value="1">
      <label class="form-check-label" for="asg_new_toggle">Neue Aufgabe anlegen</label>
    </div>

    <div id="asg_new_fields" class="border rounded p-3 mb-3 d-none">
      <div class="mb-3">
        <label class="form-label">Beschreibung der neuen Aufgabe</label>
        <input type="text" class="form-control" name="new_description" placeholder="z. B. Landingpage Header umsetzen">
      </div>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Priorität</label>
          <select class="form-select" name="new_priority">
            <option value="hoch">hoch</option>
            <option value="mittel">mittel</option>
            <option value="niedrig">niedrig</option>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Geschätzt (Minuten)</label>
          <input type="number" class="form-control" name="new_planned" min="0" step="1">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Deadline</label>
          <input type="date" class="form-control" name="new_deadline">
        </div>
      </div>
      <div class="form-text">Die neue Aufgabe wird im ausgewählten Projekt mit Status „offen“ angelegt.</div>
    </div>
  <?php endif; ?>

  <button class="btn btn-warning">Stoppen</button>
  <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
</form>
  </div>
</div>
<?php if (!$running['task_id']): ?>
<script>
(function(){
  // Diese Arrays kommen aus PHP:
  const projects = <?=json_encode($projects ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
  const tasks    = <?=json_encode($tasksAll ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;

  const elCompany = document.getElementById('asg_company');
  const elProject = document.getElementById('asg_project');
  const elTask    = document.getElementById('asg_task');
  const tgNew     = document.getElementById('asg_new_toggle');
  const boxNew    = document.getElementById('asg_new_fields');

  function resetTaskSelect(placeholder){
    elTask.innerHTML = '';
    const t0 = document.createElement('option');
    t0.value = '';
    t0.textContent = placeholder;
    elTask.appendChild(t0);
    elTask.disabled = true;
  }

  function fillProjects(cid){
    elProject.innerHTML = '';
    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = cid ? '– bitte Projekt wählen –' : '– bitte zuerst Firma wählen –';
    elProject.appendChild(opt0);
    elProject.disabled = !cid;

    resetTaskSelect('– optional: vorhandene Aufgabe wählen –');
    if (!cid) return;

    const cand = projects.filter(p => String(p.company_id) === String(cid));
    cand.forEach(p => {
      const o = document.createElement('option');
      o.value = String(p.id);
      o.textContent = p.title;
      elProject.appendChild(o);
    });

    if (cand.length === 1) {
      elProject.value = String(cand[0].id);
      elProject.disabled = false;
      fillTasks(String(cand[0].id));
    }
  }

  function fillTasks(pid){
    elTask.innerHTML = '';
    const t0 = document.createElement('option');
    t0.value = '';
    t0.textContent = pid ? '– optional: vorhandene Aufgabe wählen –' : '– bitte zuerst Projekt wählen –';
    elTask.appendChild(t0);
    elTask.disabled = !pid;
    if (!pid) return;

    const cand = tasks.filter(function(t){ return String(t.project_id) === String(pid); });
    cand.forEach(function(t){
      const o = document.createElement('option');
      o.value = String(t.id);
      o.textContent = t.description + (t.status ? ' ('+t.status+')' : '');
      elTask.appendChild(o);
    });

    if (cand.length === 1) {
      elTask.value = String(cand[0].id);
      elTask.disabled = false;
    }

    if (cand.length === 0 && tgNew && boxNew) {
      tgNew.checked = true;
      boxNew.classList.remove('d-none');
    }
  }

  elCompany.addEventListener('change', function(){ fillProjects(this.value); });
  elProject.addEventListener('change', function(){ fillTasks(this.value); });
  tgNew && tgNew.addEventListener('change', function(){
    if (this.checked) { boxNew.classList.remove('d-none'); }
    else { boxNew.classList.add('d-none'); }
  });

  // Auto-Select Firma, falls nur eine vorhanden ist
  const nonEmptyCompanyOptions = Array.from(elCompany.options).filter(o => o.value !== '');
  if (nonEmptyCompanyOptions.length === 1) {
    elCompany.value = nonEmptyCompanyOptions[0].value;
    fillProjects(elCompany.value);
  }
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>