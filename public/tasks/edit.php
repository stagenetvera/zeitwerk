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
// Festpreis (Tasks-only) Helpers & aktuelle Werte
// --------------------------------------------------
function _price_to_cents($value) {
  if ($value === '' || $value === null) return null;
  $s = trim((string)$value);
  // "1.234,56" -> "1234.56", "1234,56" -> "1234.56"
  if (strpos($s, ',') !== false && strpos($s, '.') === false) {
    $s = str_replace(',', '.', $s);
  } else {
    $s = str_replace([' ', ','], ['', ''], $s);
  }
  return (int)round(((float)$s) * 100);
}

$current_bm = $task['billing_mode'] ?? 'time';
$__billing_mode = $_POST['billing_mode'] ?? $current_bm;
if ($__billing_mode !== 'fixed') $__billing_mode = 'time';

$__fixed_price_cents = null;
if ($__billing_mode === 'fixed') {
  if (isset($_POST['fixed_price'])) {
    $__fixed_price_cents = _price_to_cents($_POST['fixed_price']);
  } else {
    $__fixed_price_cents = isset($task['fixed_price_cents']) ? (int)$task['fixed_price_cents'] : null;
  }
}

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

  if ($ok) {
    // Billing-Mode/Festpreis übernehmen
    $billing_mode = ($__billing_mode === 'fixed') ? 'fixed' : 'time';
    $fixed_price_cents = ($billing_mode === 'fixed') ? $__fixed_price_cents : null;

    $upd = $pdo->prepare('UPDATE tasks
                          SET project_id = ?, description = ?, planned_minutes = ?, priority = ?, deadline = ?, status = ?, billable = ?,
                              billing_mode = ?, fixed_price_cents = ?
                          WHERE id = ? AND account_id = ?');
    $upd->execute([
      $project_id, $description, $planned, $priority, $deadline, $status, $billable,
      $billing_mode, $fixed_price_cents,
      $id, $account_id
    ]);
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

      <fieldset class="border rounded p-3 mb-3">
        <legend class="float-none w-auto px-2 fs-6 text-muted mb-0">Abrechnung</legend>

        <div class="mb-2">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" id="bm_time" name="billing_mode" value="time"
                   <?php echo ($__billing_mode === 'time') ? 'checked' : ''; ?>>
            <label class="form-check-label" for="bm_time">Zeitbasiert</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" id="bm_fixed" name="billing_mode" value="fixed"
                   <?php echo ($__billing_mode === 'fixed') ? 'checked' : ''; ?>>
            <label class="form-check-label" for="bm_fixed">Festpreis</label>
          </div>
        </div>

        <div class="row g-2 align-items-end" data-fixed-price-row style="display:none">
          <div class="col-sm-6">
            <label class="form-label">Festpreis (netto)</label>
            <div class="input-group">
              <span class="input-group-text">€</span>
              <input type="number" step="0.01" min="0" class="form-control" name="fixed_price"
                     value="<?php
                       if (isset($_POST['fixed_price'])) {
                         echo htmlspecialchars($_POST['fixed_price']);
                       } else {
                         echo isset($task['fixed_price_cents'])
                           ? number_format(((int)$task['fixed_price_cents'])/100, 2, ',', '')
                           : '';
                       }
                     ?>">
            </div>
            <div class="form-text">Zeiten werden weiterhin getrackt, aber nicht abgerechnet.</div>
          </div>
        </div>
      </fieldset>

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
    <script>
      document.addEventListener('DOMContentLoaded', function(){
        const form = document.getElementById('taskEditForm');
        if (!form) return;
        const radios = form.querySelectorAll('input[name="billing_mode"]');
        const fpRow  = form.querySelector('[data-fixed-price-row]');
        function toggle(){
          const mode = form.querySelector('input[name="billing_mode"]:checked')?.value || 'time';
          if (fpRow) fpRow.style.display = (mode === 'fixed') ? '' : 'none';
        }
        radios.forEach(r => r.addEventListener('change', toggle));
        toggle();
      });
    </script>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>