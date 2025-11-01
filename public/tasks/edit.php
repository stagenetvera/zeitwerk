<?php
// public/tasks/edit.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/return_to.php';

require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];


// --------------------------------------------------
// Datensatz laden
// --------------------------------------------------
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  echo '<div class="alert alert-danger">Ungültige Aufgaben-ID.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}

$st = $pdo->prepare('SELECT t.*, p.company_id
                     FROM tasks t
                     JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
                     WHERE t.id = ? AND t.account_id = ?');
$st->execute([$id, $account_id]);
$task = $st->fetch();

if (!$task) {
  echo '<div class="alert alert-danger">Aufgabe nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}

// --------------------------------------------------
// Firmen-/Projektlisten vorbereiten (abhängig)
// --------------------------------------------------
$company_id = isset($_POST['company_id']) ? (int)$_POST['company_id']
            : (isset($_GET['company_id']) ? (int)$_GET['company_id'] : (int)$task['company_id']);


$return_to = pick_return_to('/companies/show.php?id='.$company_id);

$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : (int)$task['project_id'];

// Firmenliste
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cs->execute([$account_id]);
$companies = $cs->fetchAll();

// Projekte (abhängig von Firma)
$projects = [];
if ($company_id) {
  $ps = $pdo->prepare('SELECT id, title FROM projects WHERE account_id = ? AND company_id = ? ORDER BY title');
  $ps->execute([$account_id, $company_id]);
  $projects = $ps->fetchAll();

  // Wenn genau 1 Projekt existiert und aktuell keins/anderes gewählt ist -> auto-select
  if (count($projects) === 1 && $project_id !== (int)$projects[0]['id']) {
    $project_id = (int)$projects[0]['id'];
  }
}

$err = null;

// --------------------------------------------------
// POST: Update
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $company_id = (int)($_POST['company_id'] ?? 0);
  $project_id = (int)($_POST['project_id'] ?? 0);
  $description = trim($_POST['description'] ?? '');
  $planned = $_POST['planned_minutes'] !== '' ? (int)$_POST['planned_minutes'] : null;
  $priority = $_POST['priority'] ?? 'medium';
  $deadline = $_POST['deadline'] ?: null;
  $status = $_POST['status'] ?? 'offen';
  $billable = isset($_POST['billable']) ? 1 : 0;

  // Validierung
  $ok = true;
  if (!$company_id) { $ok = false; $err = 'Bitte eine Firma wählen.'; }
  if ($ok && !$project_id) { $ok = false; $err = 'Bitte ein Projekt wählen.'; }
  if ($ok && $description === '') { $ok = false; $err = 'Bitte eine Beschreibung angeben.'; }

  // Projekt/Firma/Account-Konsistenz prüfen
  if ($ok) {
    $chk = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE id = ? AND company_id = ? AND account_id = ?');
    $chk->execute([$project_id, $company_id, $account_id]);
    if (!$chk->fetchColumn()) {
      $ok = false; $err = 'Ungültige Projekt-/Firmenkombination.';
    }
  }
var_dump($return_to);
  if ($ok) {
    $upd = $pdo->prepare('UPDATE tasks
                          SET project_id = ?, description = ?, planned_minutes = ?, priority = ?, deadline = ?, status = ?, billable = ?
                          WHERE id = ? AND account_id = ?');
    $upd->execute([$project_id, $description, $planned, $priority, $deadline, $status, $billable, $id, $account_id]);
    flash('Aufgabe gespeichert.', 'success');

    redirect($return_to);
  } else {
    // Werte für erneutes Rendern überschreiben
    $task['project_id'] = $project_id;
    $task['description'] = $description;
    $task['planned_minutes'] = $planned;
    $task['priority'] = $priority;
    $task['deadline'] = $deadline;
    $task['status'] = $status;
    $task['billable'] = $billable;
  }
}

// --------------------------------------------------
// View
// --------------------------------------------------
require __DIR__ . '/../../src/layout/header.php';

?>
<div class="row">
  <div class="col-md-8">
    <h3>Aufgabe bearbeiten</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

    <form method="post" id="taskEditForm">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $task['id'] ?>">
      <?= return_to_hidden($return_to) ?>

      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Firma</label>
          <select name="company_id" id="company_id" class="form-select" required>
            <option value="">– bitte wählen –</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?=$c['id']?>" <?=$c['id']==$company_id?'selected':''?>><?=h($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6 mb-3">
          <label class="form-label">Projekt</label>
          <select name="project_id" id="project_id" class="form-select" required <?= $company_id ? '' : 'disabled' ?>>
            <?php if (!$company_id): ?>
              <option value="">(zuerst Firma wählen)</option>
            <?php else: ?>
              <option value="">– bitte wählen –</option>
              <?php foreach ($projects as $p): ?>
                <option value="<?=$p['id']?>" <?=$p['id']==$project_id?'selected':''?>><?=h($p['title'])?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Beschreibung</label>
        <textarea name="description" class="form-control" rows="3" required><?=h($task['description'] ?? '')?></textarea>
      </div>

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Geplante Minuten</label>
          <input type="number" name="planned_minutes" class="form-control" value="<?=h($task['planned_minutes'] ?? '')?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Priorität</label>
          <?php $prio = $task['priority'] ?? 'medium'; ?>
          <select name="priority" class="form-select">
            <option value="low"    <?=$prio==='low'?'selected':''?>>low</option>
            <option value="medium" <?=$prio==='medium'?'selected':''?>>medium</option>
            <option value="high"   <?=$prio==='high'?'selected':''?>>high</option>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Deadline</label>
          <input type="date" name="deadline" class="form-control" value="<?=h($task['deadline'] ?? '')?>">
        </div>
      </div>

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Status</label>
          <?php $st = $task['status'] ?? 'offen'; ?>
          <select name="status" class="form-select">
            <option value="offen"        <?=$st==='offen'?'selected':''?>>offen</option>
            <option value="warten"       <?=$st==='warten'?'selected':''?>>warten</option>
            <option value="angeboten"    <?=$st==='angeboten'?'selected':''?>>angeboten</option>
            <option value="abgeschlossen"<?=$st==='abgeschlossen'?'selected':''?>>abgeschlossen</option>
          </select>
        </div>
        <div class="col-md-4 mb-3 form-check mt-4">
          <input class="form-check-input" type="checkbox" name="billable" id="billable" <?=$task['billable'] ? 'checked' : ''?>>
          <label class="form-check-label" for="billable">fakturierbar</label>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-primary">Speichern</button>
        <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
      </div>
    </form>

    <script>
      (function() {
        var company = document.getElementById('company_id');
        if (!company) return;
        company.addEventListener('change', function() {
          var base = '<?=url('/tasks/edit.php')?>';
          var id   = '<?= (int)$task['id'] ?>';
          var val  = this.value || '';
          var rt   = document.querySelector('input[name="return_to"]');
          var rtVal= rt ? rt.value : '';
          var params = [];
          params.push('id=' + encodeURIComponent(id));
          if (val) params.push('company_id=' + encodeURIComponent(val));
          if (rtVal) params.push('return_to=' + encodeURIComponent(rtVal));
          var url = base + '?' + params.join('&');
          window.location.href = url;
        });
      })();
    </script>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>