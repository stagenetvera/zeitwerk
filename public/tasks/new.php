<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

// Firma/Projekt aus POST (bei Save) oder aus GET (bei Reload nach Auswahl)
$company_id = isset($_POST['company_id']) ? (int)$_POST['company_id']
            : (isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0);

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
  // Wenn genau 1 Projekt existiert, als Default setzen (nur für Anzeige)
  if (count($projects) === 1) {
    $_POST['project_id'] = (int)$projects[0]['id'];
  }
}
$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id']
            : (isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0);
$err = null;


$return_to = pick_return_to('/companies/show.php?id='.$company_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["action"]) && $_POST["action"] == "save") {
  $company_id = (int)($_POST['company_id'] ?? 0);
  $project_id = (int)($_POST['project_id'] ?? 0);
  $description = trim($_POST['description'] ?? '');
  $planned = $_POST['planned_minutes'] !== '' ? (int)$_POST['planned_minutes'] : null;
  $priority = $_POST['priority'] ?? 'medium';
  $deadline = $_POST['deadline'] ?: null;
  $status = $_POST['status'] ?? 'offen';
  $billable = isset($_POST['billable']) ? 1 : 0;

  // Validierungen: Firma & Projekt gehören zum Account und zueinander
  $ok = true;
  if (!$company_id) { $ok = false; $err = 'Bitte eine Firma auswählen.'; }
  if ($ok && !$project_id) { $ok = false; $err = 'Bitte ein Projekt auswählen.'; }
  if ($ok && !$description) { $ok = false; $err = 'Bitte eine Beschreibung angeben.'; }

  if ($ok) {
    $chk = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE id = ? AND company_id = ? AND account_id = ?');
    $chk->execute([$project_id, $company_id, $account_id]);
    if (!$chk->fetchColumn()) {
      $ok = false;
      $err = 'Ungültige Projekt-/Firmenkombination.';
    }
  }


  if ($ok) {
    $ins = $pdo->prepare('INSERT INTO tasks(account_id, project_id, description, planned_minutes, priority, deadline, status, billable) VALUES (?,?,?,?,?,?,?,?)');
    $ins->execute([$account_id, $project_id, $description, $planned, $priority, $deadline, $status, $billable]);

    $task_id = (int)$pdo->lastInsertId();

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_ordering_global (
      account_id INT NOT NULL,
      task_id INT NOT NULL,
      position INT NOT NULL,
      PRIMARY KEY (account_id, task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 4) Nächste Position ermitteln (MAX+1) und setzen -> neue Aufgabe steht unten
    try {
      $pdo->beginTransaction();

      $posStmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM task_ordering_global WHERE account_id = ?");
      $posStmt->execute([$account_id]);
      $nextPos = (int)$posStmt->fetchColumn();
      if ($nextPos < 1) $nextPos = 1;

      $ordIns = $pdo->prepare("INSERT INTO task_ordering_global (account_id, task_id, position)
                              VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE position = VALUES(position)");
      $ordIns->execute([$account_id, $task_id, $nextPos]);

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      // optional: loggen, aber den Redirect trotzdem machen
    }
    redirect($return_to);

  }
}

?>
<div class="row">
  <div class="col-md-8">
    <h3>Neue Aufgabe (nach Firma &amp; Projekt)</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>

    <form method="post" id="taskForm">
      <?=csrf_field()?>
      <?= return_to_hidden($return_to) ?>
      <input type="hidden" name="action" value="save">
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
        <textarea name="description" class="form-control" rows="3" required><?=h($_POST['description'] ?? '')?></textarea>
      </div>

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Geplante Minuten</label>
          <input type="number" name="planned_minutes" class="form-control" value="<?=h($_POST['planned_minutes'] ?? '')?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Priorität</label>
          <select name="priority" class="form-select">
            <?php $prio = $_POST['priority'] ?? 'medium'; ?>
            <option value="low" <?=$prio==='low'?'selected':''?>>low</option>
            <option value="medium" <?=$prio==='medium'?'selected':''?>>medium</option>
            <option value="high" <?=$prio==='high'?'selected':''?>>high</option>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Deadline</label>
          <input type="date" name="deadline" class="form-control" value="<?=h($_POST['deadline'] ?? '')?>">
        </div>
      </div>

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Status</label>
          <?php $st = $_POST['status'] ?? 'offen'; ?>
          <select name="status" class="form-select">
            <option value="offen" <?=$st==='offen'?'selected':''?>>offen</option>
            <option value="warten" <?=$st==='warten'?'selected':''?>>warten</option>
            <option value="angeboten" <?=$st==='angeboten'?'selected':''?>>angeboten</option>
            <option value="fakturierbar" <?=$st==='fakturierbar'?'selected':''?>>fakturierbar</option>
            <option value="abgeschlossen" <?=$st==='abgeschlossen'?'selected':''?>>abgeschlossen</option>
          </select>
        </div>
        <div class="col-md-4 mb-3 form-check mt-4">
          <input class="form-check-input" type="checkbox" name="billable" id="billable" <?=isset($_POST['billable'])?'checked':''?>>
          <label class="form-check-label" for="billable">fakturierbar</label>
        </div>
      </div>

      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
    </form>

    <script>
      (function() {
        const company = document.getElementById('company_id');
        if (company) {
          company.addEventListener('change', function() {
            const base = '<?=url('/tasks/new.php')?>';
            const val = this.value || '';
            const rt = document.querySelector('input[name="return_to"]');
            const rtVal = rt ? rt.value : '';

            let url = base;
            const params = [];
            if (val) params.push('company_id=' + encodeURIComponent(val));
            if (rtVal) params.push('return_to=' + encodeURIComponent(rtVal));
            if (params.length) url += '?' + params.join('&');

            window.location.href = url;
          });
        }
      })();
    </script>

  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
