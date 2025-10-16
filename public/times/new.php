<?php
// public/times/new.php
require __DIR__ . '/../../src/layout/header.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_login();

$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

$return_to = pick_return_to('/times/index.php');

$err = null;
$today = (new DateTimeImmutable('now'))->format('Y-m-d');
$now_time = (new DateTimeImmutable('now'))->format('H:i');

// current selection via GET (for dependent selects UI)
$company_id = isset($_GET['company_id']) && $_GET['company_id']!=='' ? (int)$_GET['company_id'] : 0;
$project_id = isset($_GET['project_id']) && $_GET['project_id']!=='' ? (int)$_GET['project_id'] : 0;

// fetch companies
$cstmt = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cstmt->execute([$account_id]);
$companies = $cstmt->fetchAll();

// fetch projects if company selected
$projects = [];
if ($company_id) {
  $pstmt = $pdo->prepare('SELECT id, title FROM projects WHERE account_id = ? AND company_id = ? ORDER BY title');
  $pstmt->execute([$account_id, $company_id]);
  $projects = $pstmt->fetchAll();
}

// fetch tasks if project selected
$tasks = [];
if ($project_id) {
  $tstmt = $pdo->prepare('SELECT id, description FROM tasks WHERE account_id = ? AND project_id = ? ORDER BY description');
  $tstmt->execute([$account_id, $project_id]);
  $tasks = $tstmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $task_id   = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
  $started_at = trim($_POST['started_at'] ?? '');
  $ended_at   = trim($_POST['ended_at'] ?? '');
  $minutes_in = trim($_POST['minutes'] ?? '');
  $billable   = isset($_POST['billable']) ? (int)($_POST['billable'] === '1') : 0;
  $status     = $_POST['status'] ?? 'offen';

  if ($task_id <= 0) {
    $err = 'Bitte wähle eine Aufgabe.';
  } elseif ($started_at === '') {
    $err = 'Bitte Startzeit angeben.';
  } else {
    // derive minutes if needed
    $minutes = null;
    if ($minutes_in !== '') {
      $minutes = (int)$minutes_in;
    } elseif ($started_at !== '' && $ended_at !== '') {
      $s = strtotime($started_at);
      $e = strtotime($ended_at);
      if ($s !== false && $e !== false && $e >= $s) {
        $minutes = (int) floor(($e - $s) / 60);
      } else {
        $ended_at = null; // invalid end -> ignore
      }
    }

    // normalize ended_at
    if ($ended_at === '') $ended_at = null;

    $ins = $pdo->prepare('INSERT INTO times (account_id, user_id, task_id, started_at, ended_at, minutes, billable, status, created_at)
                          VALUES (?,?,?,?,?,?,?,?,NOW())');
    $ins->execute([$account_id, $user_id, $task_id, $started_at, $ended_at, $minutes, $billable, $status]);

    flash('Zeit wurde angelegt.', 'success');
    redirect($return_to);

    exit;
  }
}

// helper to build self url with changed query
function qs_self($arr) {
  $base = strtok($_SERVER['REQUEST_URI'], '?');
  $q = array_merge($_GET, $arr);
  return htmlspecialchars($base.'?'.http_build_query($q), ENT_QUOTES, 'UTF-8');
}

// default datetime-local values
$default_start = $today.'T'.$now_time;
$default_end = '';
?>
<div class="row">
  <div class="col-lg-8">
    <h3>Neue Zeit</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>

    <!-- Dependent selects (company -> project) to narrow tasks -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Firma</label>
            <select class="form-select" onchange="location.href=this.value">
              <option value="<?=qs_self(['company_id'=>'','project_id'=>''])?>" <?=$company_id? '':'selected'?>>– bitte wählen –</option>
              <?php foreach ($companies as $c): ?>
                <option value="<?=qs_self(['company_id'=>$c['id'],'project_id'=>''])?>" <?=$company_id===$c['id']?'selected':''?>><?=h($c['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Projekt</label>
            <select class="form-select" <?=$company_id? '':'disabled'?> onchange="location.href=this.value">
              <option value="<?=qs_self(['project_id'=>''])?>" <?=$project_id? '':'selected'?>>– bitte wählen –</option>
              <?php foreach ($projects as $p): ?>
                <option value="<?=qs_self(['project_id'=>$p['id']])?>" <?=$project_id===$p['id']?'selected':''?>><?=h($p['title'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <form method="post" class="card">
      <div class="card-body">
        <?=csrf_field()?>
        <input type="hidden" name="return_to" value="<?=h($return_to)?>">
        <div class="mb-3">
          <label class="form-label">Aufgabe</label>
          <select name="task_id" class="form-select" required <?=$project_id? '' : 'disabled'?>>
            <option value="">– bitte wählen –</option>
            <?php foreach ($tasks as $t): ?>
              <option value="<?=$t['id']?>"><?=h($t['description'])?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$project_id): ?>
            <div class="form-text">Bitte Firma und Projekt wählen, um die Aufgaben zu sehen.</div>
          <?php endif; ?>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Start</label>
            <input type="datetime-local" name="started_at" class="form-control" value="<?=$default_start?>" required>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Ende</label>
            <input type="datetime-local" name="ended_at" class="form-control" value="<?=$default_end?>">
            <div class="form-text">Leer lassen, wenn die Zeit noch läuft.</div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-4 mb-3">
            <label class="form-label">Minuten (optional)</label>
            <input type="number" name="minutes" class="form-control" min="0" step="1" placeholder="auto">
            <div class="form-text">Wird automatisch berechnet, wenn Start & Ende gesetzt sind.</div>
          </div>
          <div class="col-md-4 mb-3">
            <label class="form-label">Fakturierbar</label>
            <select name="billable" class="form-select">
              <option value="1">ja</option>
              <option value="0">nein</option>
            </select>
          </div>
          <div class="col-md-4 mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="offen" selected>offen</option>
              <option value="abgerechnet">abgerechnet</option>
            </select>
          </div>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary" <?=$project_id? '' : 'disabled'?>>Speichern</button>
          <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
        </div>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
