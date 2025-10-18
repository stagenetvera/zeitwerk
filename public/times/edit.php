<?php
require __DIR__ . '/../../src/bootstrap.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];
$user_id    = (int)$user['id'];

$return_to = pick_return_to('/times/index.php');

// ---- Datensatz zuerst laden (BEVOR wir die Select-Listen bauen!) ----
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdo->prepare('SELECT * FROM times WHERE id = ? AND account_id = ? AND user_id = ?');
$st->execute([$id, $account_id, $user_id]);
$time = $st->fetch();

if (!$time) {
  require __DIR__ . '/../../src/layout/header.php';
  echo '<div class="alert alert-danger">Zeit nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}
if (($time['status'] ?? null) === 'abgerechnet') {
  require __DIR__ . '/../../src/layout/header.php';
  echo '<div class="alert alert-warning">Dieser Zeiteintrag ist bereits <strong>abgerechnet</strong> und kann hier nicht mehr geändert werden.</div>';
  echo '<a class="btn btn-outline-secondary" href="'.h($return_to).'">Zurück</a>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}

// ---- Vorauswahl aus dem Datensatz ableiten ----
$sel_task_id    = !empty($time['task_id']) ? (int)$time['task_id'] : 0;
$sel_project_id = 0;
$sel_company_id = 0;

if ($sel_task_id > 0) {
  $q = $pdo->prepare("
    SELECT t.id AS task_id, p.id AS project_id, c.id AS company_id
    FROM tasks t
    JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
    JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
    WHERE t.account_id = ? AND t.id = ?
    LIMIT 1
  ");
  $q->execute([$account_id, $sel_task_id]);
  if ($row = $q->fetch()) {
    $sel_project_id = (int)$row['project_id'];
    $sel_company_id = (int)$row['company_id'];
  }
}

// ---- Listen für abhängige Dropdowns laden ----
$cmpStmt = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cmpStmt->execute([$account_id]);
$edit_companies = $cmpStmt->fetchAll();

$prjStmt = $pdo->prepare('SELECT id, company_id, title FROM projects WHERE account_id = ? ORDER BY title');
$prjStmt->execute([$account_id]);
$edit_projects = $prjStmt->fetchAll();

$tskStmt = $pdo->prepare('SELECT id, project_id, description, status FROM tasks WHERE account_id = ? ORDER BY description');
$tskStmt->execute([$account_id]);
$edit_tasks = $tskStmt->fetchAll();

// (Falls du unten noch die alte $tasks-Liste für etwas anderes nutzt, entferne sie – sie ist hier nicht nötig.)
// require header JETZT:
require __DIR__ . '/../../src/layout/header.php';

$return_to = pick_return_to('/times/index.php');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdo->prepare('SELECT * FROM times WHERE id = ? AND account_id = ? AND user_id = ?');
$st->execute([$id,$account_id,$user_id]);
$time = $st->fetch();

if ($time && ($time['status'] ?? null) === 'abgerechnet') {
  $return_to = pick_return_to('/times/index.php');
  echo '<div class="alert alert-warning">Dieser Zeiteintrag ist bereits <strong>abgerechnet</strong> und kann hier nicht mehr geändert werden.</div>';
  echo '<a class="btn btn-outline-secondary" href="'.h($return_to).'">Zurück</a>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}

if (!$time) { echo '<div class="alert alert-danger">Zeit nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

// Aufgabenliste
$ts = $pdo->prepare('SELECT t.id, t.description, p.title AS project_title
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  WHERE t.account_id = ?
  ORDER BY p.title, t.id DESC
  LIMIT 500');
$ts->execute([$account_id]);
$tasks = $ts->fetchAll();

$err = $ok = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Status nie aus Formular übernehmen:
  if (isset($_POST['status'])) {
      unset($_POST['status']);
  }

  $task_id = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;
  $billable = isset($_POST['billable']) ? 1 : 0;
  $started_at = trim($_POST['started_at'] ?? '');
  $ended_at = trim($_POST['ended_at'] ?? '');

  if (!$started_at) {
    $err = 'Startzeit ist erforderlich.';
  } else {
    $start = new DateTimeImmutable($started_at);
    $end = $ended_at ? new DateTimeImmutable($ended_at) : null;
    $minutes = null;
    if ($end) {
      if ($end <= $start) {
        $err = 'Endzeit muss nach der Startzeit liegen.';
      } else {
        $minutes = max(1, (int)round(($end->getTimestamp() - $start->getTimestamp())/60));
      }
    }
    if (!$err) {
      $upd = $pdo->prepare("
        UPDATE times
          SET task_id = :task_id,
              started_at = :started_at,
              ended_at = :ended_at,
              minutes = :minutes,
              billable = :billable
        WHERE id = :id AND account_id = :acc AND user_id = :uid
      ");
      $upd->execute([
        ':task_id'    => $task_id ?: null,
        ':started_at' => $started_at,
        ':ended_at'   => $ended_at ?: null,
        ':minutes'    => $minutes ?: null,
        ':billable'   => $billable ? 1 : 0,
        ':id'         => $id,
        ':acc'        => $account_id,
        ':uid'        => $user_id,
      ]);
      $ok = 'Gespeichert.';
      flash('Zeit gespeichert.', 'success');
      redirect($return_to);
    }
  }
}

function fmt_dt_local($s) {
  if (!$s) return '';
  $dt = new DateTimeImmutable($s);
  return $dt->format('Y-m-d\TH:i');
}

?>
<div class="row">
  <div class="col-md-7">
    <h3>Zeit bearbeiten</h3>
    <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <?= return_to_hidden($return_to) ?>
      <!-- Firma / Projekt / Aufgabe (abhängige Dropdowns) -->
<div class="mb-3">
  <label class="form-label">Firma</label>
  <select class="form-select" name="company_id" id="e_company">
    <option value="">– bitte wählen –</option>
    <?php foreach (($edit_companies ?? []) as $co): ?>
      <option value="<?= (int)$co['id'] ?>" <?= ((int)$co['id'] === (int)$sel_company_id) ? 'selected' : '' ?>>
        <?= h($co['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>

<div class="mb-3">
  <label class="form-label">Projekt</label>
  <select class="form-select" name="project_id" id="e_project" <?= $sel_company_id ? '' : 'disabled' ?>>
    <option value="">
      <?= $sel_company_id ? '– bitte Projekt wählen –' : '– bitte zuerst Firma wählen –' ?>
    </option>
  </select>
</div>

<div class="mb-3">
  <label class="form-label">Aufgabe</label>
  <select class="form-select" name="task_id" id="e_task" <?= $sel_project_id ? '' : 'disabled' ?>>
    <option value="">
      <?= $sel_project_id ? '– optional: vorhandene Aufgabe wählen –' : '– bitte zuerst Projekt wählen –' ?>
    </option>
  </select>
  <div class="form-text">Optional. Wenn leer, bleibt der Eintrag ohne Aufgabe.</div>
</div>

<script>
(function(){
  // Daten aus PHP
  const projects = <?=json_encode($edit_projects ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
  const tasks    = <?=json_encode($edit_tasks    ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;

  const elCompany = document.getElementById('e_company');
  const elProject = document.getElementById('e_project');
  const elTask    = document.getElementById('e_task');

  const selCompany = '<?= (int)$sel_company_id ?>';
  const selProject = '<?= (int)$sel_project_id ?>';
  const selTask    = '<?= (int)$sel_task_id ?>';

  function resetSelect(el, placeholder, disable){
    el.innerHTML = '';
    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = placeholder;
    el.appendChild(opt0);
    el.disabled = !!disable;
  }

  function fillProjects(companyId, preselectId){
    resetSelect(elProject, companyId ? '– bitte Projekt wählen –' : '– bitte zuerst Firma wählen –', !companyId);
    resetSelect(elTask, '– bitte zuerst Projekt wählen –', true);

    if (!companyId) return;

    const cand = projects.filter(p => String(p.company_id) === String(companyId));
    cand.forEach(p => {
      const o = document.createElement('option');
      o.value = String(p.id);
      o.textContent = p.title;
      elProject.appendChild(o);
    });

    // Vorbelegung
    if (preselectId && cand.some(p => String(p.id) === String(preselectId))) {
      elProject.value = String(preselectId);
      elProject.disabled = false;
      fillTasks(preselectId, selTask);
      return;
    }
    // Auto-Select falls nur 1 Projekt
    if (cand.length === 1) {
      elProject.value = String(cand[0].id);
      elProject.disabled = false;
      fillTasks(String(cand[0].id), selTask);
    }
  }

  function fillTasks(projectId, preselectId){
    resetSelect(elTask, projectId ? '– optional: vorhandene Aufgabe wählen –' : '– bitte zuerst Projekt wählen –', !projectId);
    if (!projectId) return;

    const cand = tasks.filter(t => String(t.project_id) === String(projectId));
    cand.forEach(t => {
      const o = document.createElement('option');
      o.value = String(t.id);
      o.textContent = t.description + (t.status ? ' ('+t.status+')' : '');
      elTask.appendChild(o);
    });

    if (preselectId && cand.some(t => String(t.id) === String(preselectId))) {
      elTask.value = String(preselectId);
      elTask.disabled = false;
      return;
    }
    if (cand.length === 1) {
      elTask.value = String(cand[0].id);
      elTask.disabled = false;
    }
  }

  // Events
  elCompany.addEventListener('change', function(){
    fillProjects(this.value, '');
  });
  elProject.addEventListener('change', function(){
    fillTasks(this.value, '');
  });

  // Initiale Vorbelegung anhand bestehender Auswahl
  if (selCompany) {
    elCompany.value = selCompany;
  }
  fillProjects(elCompany.value || '', selProject || '');

  // Falls weder Firma noch Projekt gesetzt sind:
  // Wenn nur 1 Firma existiert → auto-select
  if (!elCompany.value) {
    const nonEmpty = Array.from(elCompany.options).filter(o => o.value !== '');
    if (nonEmpty.length === 1) {
      elCompany.value = nonEmpty[0].value;
      fillProjects(elCompany.value, '');
    }
  }
})();
</script>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Start</label>
          <input type="datetime-local" name="started_at" class="form-control" value="<?=h(fmt_dt_local($time['started_at']))?>" required>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Ende (optional)</label>
          <input type="datetime-local" name="ended_at" class="form-control" value="<?=h(fmt_dt_local($time['ended_at']))?>">
        </div>
      </div>
      <div class="row">

        <div class="col-md-4 mb-3 form-check mt-4">
          <input class="form-check-input" type="checkbox" name="billable" id="billable" <?=$time['billable']?'checked':''?>>
          <label class="form-check-label" for="billable">fakturierbar</label>
        </div>
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
