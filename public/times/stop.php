<?php
require __DIR__ . '/../../src/layout/header.php';
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


$return_to = $_POST['return_to'] ?? '';
if (!$return_to && isset($_SERVER['HTTP_REFERER'])) {
  $return_to = $_SERVER['HTTP_REFERER'];
}
// sanitize: allow only same-site relative URLs
$valid = false;
if ($return_to && !preg_match('~^(?:https?:)?//~i', $return_to)) {
  $valid = (str_starts_with($return_to, '/'));
}

if (!$valid) {
    $return_to = "/dashboard/index.php";
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Wenn der Timer bisher KEINE Aufgabe hat, erlauben wir Firma/Projekt/Aufgabe oder "Neue Aufgabe"
  if (!$running['task_id']) {
    $company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $task_id    = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;
    $create_new = isset($_POST['create_new']) && $_POST['create_new'] === '1';

    if ($create_new) {
      $desc = trim($_POST['new_description'] ?? '');
      $prio = $_POST['new_priority'] ?? null;
      $planned = (isset($_POST['new_planned']) && $_POST['new_planned'] !== '') ? (int)$_POST['new_planned'] : null;
      $deadline = $_POST['new_deadline'] ?? null;
      if ($deadline === '') $deadline = null;

      if ($project_id <= 0 || $desc === '') {
        $err = 'Bitte Projekt wählen und eine Aufgabenbeschreibung angeben.';
      } else {
        $ins = $pdo->prepare('INSERT INTO tasks (account_id, project_id, description, planned_minutes, priority, deadline, status) VALUES (?,?,?,?,?,?,?)');
        $ins->execute([$account_id, $project_id, $desc, $planned, $prio, $deadline, 'offen']);
        $task_id = (int)$pdo->lastInsertId();
      }
    }

    if (!$err && !$task_id) {
      $err = 'Bitte Aufgabe auswählen oder neu anlegen.';
    }

    if (!$err) {
      // Aufgabe zuweisen
      $up = $pdo->prepare('UPDATE times SET task_id = ? WHERE id = ? AND account_id = ? AND user_id = ?');
      $up->execute([$task_id, $running['id'], $account_id, $user_id]);
      $running['task_id'] = $task_id;
    }
  }

  // Timer stoppen (egal ob vorher zugewiesen oder schon vorhanden)
  if (!$err) {
    $end = new DateTimeImmutable('now');
    $start = new DateTimeImmutable($running['started_at']);
    $minutes = max(1, (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60));
    $stp = $pdo->prepare('UPDATE times SET ended_at = ?, minutes = ? WHERE id = ? AND account_id = ? AND user_id = ?');
    $stp->execute([$end->format('Y-m-d H:i:s'), $minutes, $running['id'], $account_id, $user_id]);
    redirect($return_to);
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
  <input type="hidden" name="return_to" value="<?=h($return_to)?>">

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
            <option value="">– keine –</option>
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
  <a class="btn btn-outline-secondary" href="<?=h($return_to)?>">Abbrechen</a>
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
    // Grundzustand
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

    // Auto-Select, wenn genau ein Projekt vorhanden ist
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

    // Kandidaten im gewählten Projekt
    const cand = tasks.filter(function(t){ return String(t.project_id) === String(pid); });

    cand.forEach(function(t){
      const o = document.createElement('option');
      o.value = String(t.id);
      o.textContent = t.description + (t.status ? ' ('+t.status+')' : '');
      elTask.appendChild(o);
    });

    // ✅ Neu: Auto-Select, wenn genau 1 Aufgabe vorhanden
    if (cand.length === 1) {
      elTask.value = String(cand[0].id);
      elTask.disabled = false;
    }

    // (Optional) Wenn gar keine Aufgaben existieren, automatisch „Neue Aufgabe anlegen“ öffnen
    if (cand.length === 0 && tgNew && boxNew) {
      tgNew.checked = true;
      boxNew.classList.remove('d-none');
    }
  }

  // Events
  elCompany.addEventListener('change', function(){ fillProjects(this.value); });
  elProject.addEventListener('change', function(){ fillTasks(this.value); });
  tgNew && tgNew.addEventListener('change', function(){
    if (this.checked) { boxNew.classList.remove('d-none'); }
    else { boxNew.classList.add('d-none'); }
  });

  // --- Initiales Auto-Select ---
  // Firma: wenn nur 1 echte Option (ohne placeholder) -> auto-select
  const nonEmptyCompanyOptions = Array.from(elCompany.options).filter(o => o.value !== '');
  if (nonEmptyCompanyOptions.length === 1) {
    elCompany.value = nonEmptyCompanyOptions[0].value;
    fillProjects(elCompany.value); // ruft bei Bedarf auto-select fürs Projekt auf
  }
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
