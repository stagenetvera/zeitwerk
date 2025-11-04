<?php
// public/companies/show.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';

require_once __DIR__ . '/../../src/utils.php'; // dec(), parse_hours_to_decimal()
require_once __DIR__ . '/../../src/lib/settings.php';

require_once __DIR__ . '/../../src/lib/recurring.php'; // falls du die Helper brauchst

require_login();

$user       = auth_user();
$account_id = (int)$user['account_id'];
$user_id    = (int)$user['id'];

$settings  = get_account_settings($pdo, $account_id);


// -------- helpers --------
function page_int($v, $d = 1) { $n = (int)$v; return $n > 0 ? $n : $d; }
function hurl($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function render_pagination_named_keep($baseUrl, $param, $current, $total, $keepParams = [])
{
  if ($total <= 1) {
    return '';
  }

  $qs = function ($extra) use ($keepParams) {
    $arr = array_merge($keepParams, $extra);
    return http_build_query($arr);
  };
  $prev = max(1, $current - 1);
  $next = min($total, $current + 1);
  ob_start(); ?>
  <nav><ul class="pagination mb-0">
    <li class="page-item <?= $current === 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= hurl($baseUrl . '?' . $qs([$param => 1])) ?>">&laquo;</a></li>
    <li class="page-item <?= $current === 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= hurl($baseUrl . '?' . $qs([$param => $prev])) ?>">&lsaquo;</a></li>
    <?php $s = max(1, $current - 2);
      $e = min($total, $current + 2);
      for ($i = $s; $i <= $e; $i++): ?>
      <li class="page-item<?= $i === $current ? ' active' : '' ?>"><a class="page-link" href="<?= hurl($baseUrl . '?' . $qs([$param => $i])) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
    <li class="page-item<?= $current === $total ? ' disabled' : '' ?>"><a class="page-link" href="<?= hurl($baseUrl . '?' . $qs([$param => $next])) ?>">&rsaquo;</a></li>
    <li class="page-item<?= $current === $total ? ' disabled' : '' ?>"><a class="page-link" href="<?= hurl($baseUrl . '?' . $qs([$param => $total])) ?>">&raquo;</a></li>
  </ul></nav>
  <?php return ob_get_clean();
}


// -------- input --------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$return_to = pick_return_to('/companies/show.php?id=' . $id);

if ($id <= 0) { echo '<div class="alert alert-danger">Ungültige Firmen-ID.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

// Fetch company
$cs = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$id, $account_id]);
$company = $cs->fetch();
if (!$company) {
  echo '<div class="alert alert-danger">Firma nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}

// Contacts (no pagination change)
$ks = $pdo->prepare('SELECT * FROM contacts WHERE account_id = ? AND company_id = ? ORDER BY last_name');
$ks->execute([$account_id, $id]);
$contacts = $ks->fetchAll();

// -------- project status filter --------
$allowed_status = ['angeboten', 'offen', 'abgeschlossen'];
$proj_status    = [];
$has_psf        = isset($_GET['psf']); // wurde das Projekte-Filterformular abgesendet?

if (isset($_GET['proj_status']) && is_array($_GET['proj_status'])) {
  foreach ($_GET['proj_status'] as $st) {
    if (!is_string($st)) { continue; }
    $st = strtolower(trim($st));
    if (in_array($st, $allowed_status, true)) {
      $proj_status[] = $st;
    }
  }
}
if (!$has_psf) {
  // Default-Auswahl, wenn nicht aktiv gefiltert
  $proj_status = ['angeboten', 'offen'];
}

// $keep für Pagination (immer ID + Projekte-Filter mitgeben)
$keep_projects = ['id' => $id, 'psf' => 1, 'proj_status' => $proj_status];

// -------- projects pagination + query --------
$proj_per_page = 5;
$proj_page     = page_int($_GET['proj_page'] ?? 1);
$proj_offset   = ($proj_page - 1) * $proj_per_page;

$whereP  = ['p.account_id = :acc', 'p.company_id = :cid'];
$paramsP = [':acc' => $account_id, ':cid' => $id];

if ($proj_status) {
  // IN list
  $in = [];
  foreach ($proj_status as $i => $st) {
    $key           = ':st' . $i;
    $in[]          = $key;
    $paramsP[$key] = $st;
  }
  $whereP[] = 'p.status IN (' . implode(',', $in) . ')';
}
$WHEREP = implode(' AND ', $whereP);

// Count
$pcnt = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE $WHEREP");
foreach ($paramsP as $k => $v) { $pcnt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
$pcnt->execute();
$projects_total = (int)$pcnt->fetchColumn();
$projects_pages = max(1, (int)ceil($projects_total / $proj_per_page));

// Fetch rows
$ps = $pdo->prepare("SELECT p.id, p.title, p.status, p.hourly_rate AS project_rate,
                          c.hourly_rate AS company_rate,
                          COALESCE(p.hourly_rate, c.hourly_rate) AS effective_rate,
                          EXISTS (
                              SELECT 1
                              FROM times tm
                              JOIN tasks t2
                                ON t2.id = tm.task_id AND t2.account_id = tm.account_id
                              WHERE tm.account_id = p.account_id
                                AND t2.project_id = p.id
                                AND tm.status IN ('abgerechnet','in_abrechnung')
                              LIMIT 1
                            ) AS has_billed

                   FROM projects p
                   JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
                   WHERE $WHEREP
                   ORDER BY p.title
                   LIMIT :limit OFFSET :offset");
foreach ($paramsP as $k => $v) { $ps->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
$ps->bindValue(':limit', $proj_per_page, PDO::PARAM_INT);
$ps->bindValue(':offset', $proj_offset, PDO::PARAM_INT);
$ps->execute();
$projects = $ps->fetchAll();

// -------- tasks pagination (unchanged logic) --------
$task_per_page = 5;
$task_page     = page_int($_GET['task_page'] ?? 1);
$task_offset   = ($task_page - 1) * $task_per_page;

// total open tasks for this company
$tcnt = $pdo->prepare("SELECT COUNT(*)
FROM tasks t
JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
WHERE t.account_id = ? AND p.company_id = ? AND t.status <> 'abgeschlossen'");
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
  t.id AS task_id, t.description, t.deadline, t.planned_minutes, t.priority, t.status,
  p.id AS project_id, p.title AS project_title,
  COALESCE((
    SELECT SUM(tt.minutes) FROM times tt
    WHERE tt.account_id = t.account_id AND tt.user_id = :uid AND tt.task_id = t.id AND tt.minutes IS NOT NULL
  ), 0) AS spent_minutes,
  og.position AS sort_pos,
  EXISTS (
    SELECT 1
    FROM times tt
    WHERE tt.account_id = t.account_id
      AND tt.task_id    = t.id
      AND tt.status IN ('abgerechnet','in_abrechnung')
    LIMIT 1
  ) AS has_billed

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

// running timer info
$runStmt = $pdo->prepare('SELECT id, task_id, started_at FROM times WHERE account_id = ? AND user_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
$runStmt->execute([$account_id, $user_id]);
$running         = $runStmt->fetch();
$has_running     = (bool)$running;
$running_task_id = $running && $running['task_id'] ? (int)$running['task_id'] : 0;
$running_extra   = 0;
if ($has_running) {
  $start         = new DateTimeImmutable($running['started_at']);
  $now           = new DateTimeImmutable('now');
  $running_extra = max(0, (int)floor(($now->getTimestamp() - $start->getTimestamp()) / 60));
}


$company_id = $id;


// -------- view --------
require __DIR__ . '/../../src/layout/header.php';

?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Firma: <?= h($company['name']) ?></h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?= url('/companies/index.php') ?>">Zurück zur Übersicht</a>
    <a class="btn btn-primary" href="<?= url('/companies/edit.php') ?>?id=<?= $company['id'] ?>">Firma bearbeiten</a>
  </div>
</div>

<div class="row mb-3">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Stammdaten</h5>
        <dl class="row mb-0">
          <dt class="col-sm-4">Adresse</dt><dd class="col-sm-8"><?= nl2br(h($company['address'] ?? '')) ?></dd>
          <dt class="col-sm-4">USt-ID</dt><dd class="col-sm-8"><?= h($company['vat_id'] ?? '—') ?></dd>
          <dt class="col-sm-4">Stundensatz (Firma)</dt><dd class="col-sm-8">€ <?= h(number_format((float)($company['hourly_rate'] ?? 0), 2, ',', '.')) ?></dd>
          <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= h($company['status'] ?? '') ?></dd>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="card-title mb-0">Ansprechpartner</h5>
          <form class="d-inline" method="post" action="<?= url('/contacts/new.php') ?>">
            <?= csrf_field() ?>
            <?= return_to_hidden($return_to) ?>
            <input type="hidden" name="company_id" value="<?= $company['id'] ?>">
            <button class="btn btn-sm btn-primary" type="submit">Neu</button>
          </form>
        </div>
        <?php if ($contacts): ?>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Name</th><th>E-Mail</th><th>Telefon</th><th class="text-end">Aktionen</th></tr></thead>
              <tbody>
                <?php foreach ($contacts as $k): ?>
                  <tr>
                    <td><?= h(trim($k['first_name']." ".$k['last_name'])) ?></td>
                    <td><?= h($k['email'] ?? '') ?></td>
                    <td><?= h($k['phone'] ?? '') ?><br />
                        <?= h($k['phone_alt'] ?? '') ?>
                    </td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-secondary" href="<?= url('/contacts/edit.php') ?>?id=<?= $k['id'] ?>"><i class="bi bi-pencil"></i>
              <span class="visually-hidden">Bearbeiten</span>

                      </a>
                      <form method="post" action="<?= url('/contacts/delete.php') ?>" class="d-inline" onsubmit="return confirm('Kontakt wirklich löschen?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $k['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i>
                    <span class="visually-hidden">Löschen</span></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-muted">Noch keine Ansprechpartner.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="card-title mb-0">Projekte</h5>
      <form class="d-inline" method="post" action="<?= url('/projects/new.php') ?>">
        <?= csrf_field() ?>
        <?= return_to_hidden($return_to) ?>
        <input type="hidden" name="company_id" value="<?= $company['id'] ?>">
        <button class="btn btn-sm btn-primary" type="submit">Neu</button>
      </form>
    </div>
    <form class="row g-2 mb-3" method="get" action="<?= hurl(url('/companies/show.php')) ?>">
      <input type="hidden" name="id" value="<?= $company['id'] ?>">
      <input type="hidden" name="psf" value="1">
      <div class="col-md-6">
        <label class="form-label">Projekt-Status</label>
        <div class="d-flex flex-wrap gap-3">
          <?php foreach ($allowed_status as $opt): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="proj_status[]" id="ps-<?= h($opt) ?>" value="<?= h($opt) ?>" <?= in_array($opt, $proj_status, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="ps-<?= h($opt) ?>"><?= h($opt) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Standard: „offen“ und „angeboten“.</div>
      </div>
      <div class="col-md-6 d-flex align-items-end justify-content-end gap-2">
        <button class="btn btn-outline-secondary" type="submit">Filtern</button>
        <a class="btn btn-outline-secondary" href="<?= hurl(url('/companies/show.php') . '?id=' . $company['id']) ?>">Reset (Standard)</a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead><tr><th>Titel</th><th>Status</th><th>Stundensatz (effektiv)</th><th class="text-end">Aktionen</th></tr></thead>
        <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td><?= h($p['title']) ?></td>
              <td><?= h($p['status']) ?></td>
              <td>€ <?= h(number_format((float)$p['effective_rate'], 2, ',', '.')) ?><?= $p['project_rate'] === null ? ' <small class="text-muted">(von Firma)</small>' : '' ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?= url('/projects/edit.php') ?>?id=<?= $p['id'] ?>"><i class="bi bi-pencil"></i>
              <span class="visually-hidden">Bearbeiten</span></a>

                <?php if (empty($p['has_billed'])): ?>
                  <form method="post" action="<?= url('/projects/delete.php') ?>"
                        class="d-inline"
                        onsubmit="return confirm('Wollen Sie dieses Projekt wirklich löschen? Zugewiesene Aufgaben, sowie nicht abgerechnete Zeiten werden damit ebenfalls gelöscht.');">
                    <?= csrf_field() ?>
                    <?= return_to_hidden($return_to) ?>
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i>
                    <span class="visually-hidden">Löschen</span></button>
                  </form>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-danger" disabled
                          title="Dieses Projekt enthält Zeiten im Status ‚in Abrechnung‘/‚abgerechnet‘. Löschen nicht erlaubt.">
                    <i class="bi bi-trash"></i>
                    <span class="visually-hidden">Löschen</span>
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$projects): ?>
            <tr><td colspan="4" class="text-center text-muted">Keine Projekte mit diesem Filter.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="mt-2 d-flex justify-content-end">
      <?= render_pagination_named_keep(url('/companies/show.php'), 'proj_page', $proj_page, $projects_pages, $keep_projects) ?>
    </div>
  </div>
</div>

<?php
// ---- Aufgaben-Filter + Query (nur in diesem Block) ----

// Rücksprungziel lokal bestimmen (für Buttons in dieser Card)
$return_to = $_SERVER['REQUEST_URI'] ?? (url('/companies/show.php').'?id='.$company['id']);

// erlaubte Task-Status laut Tabelle `tasks`
$allowed_task_status = ['offen','warten','angeboten','abgeschlossen'];

// Flag: Wurde das Aufgaben-Filterformular abgesendet?
$has_tsf = isset($_GET['tsf']);

// Auswahl aus GET einlesen
$task_status = [];
if (isset($_GET['task_status']) && is_array($_GET['task_status'])) {
  foreach ($_GET['task_status'] as $st) {
    if (!is_string($st)) continue;
    $st = strtolower(trim($st));
    if (in_array($st, $allowed_task_status, true)) {
      $task_status[] = $st;
    }
  }
}
// Default-Auswahl, wenn nicht aktiv gefiltert wurde
if (!$has_tsf) {
  $task_status = ['offen','warten'];
}

// Keep-Parameter für Pagination dieser Card (proj-Filter übernehmen!)
$keep_projects = ['id'=>$company['id'], 'psf'=>1, 'proj_status'=>$proj_status ?? []];
$keep_tasks = $keep_projects;
$keep_tasks['tsf'] = 1;
$keep_tasks['task_status'] = $task_status;

// Pagination-Parameter
$task_per_page = $task_per_page ?? 20;
$task_page     = max(1, (int)($_GET['task_page'] ?? 1));
$task_offset   = ($task_page - 1) * $task_per_page;

// WHERE/Params für Task-Query
$whereT  = ['t.account_id = :acc', 'p.company_id = :cid'];
$paramsT = [':acc' => $account_id, ':cid' => $company['id']];

if ($task_status) {
  $inT = [];
  foreach ($task_status as $i => $st) {
    $key = ':tst'.$i;
    $inT[] = $key;
    $paramsT[$key] = $st;
  }
  $whereT[] = 't.status IN ('.implode(',', $inT).')';
}
$WHERET = implode(' AND ', $whereT);

// Count
$tcnt = $pdo->prepare("SELECT COUNT(*)
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  WHERE $WHERET");
foreach ($paramsT as $k => $v) {
  $tcnt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$tcnt->execute();
$tasks_total = (int)$tcnt->fetchColumn();
$tasks_pages = max(1, (int)ceil($tasks_total / $task_per_page));

// Daten laden
$sqlTasks = "SELECT
    t.id AS task_id, t.description, t.deadline, t.planned_minutes, t.priority, t.status,
    p.id AS project_id, p.title AS project_title,
    COALESCE((
      SELECT SUM(tt.minutes) FROM times tt
      WHERE tt.account_id = t.account_id AND tt.user_id = :uid AND tt.task_id = t.id AND tt.minutes IS NOT NULL
    ), 0) AS spent_minutes,
    og.position AS sort_pos,
    EXISTS (
      SELECT 1
      FROM times tt
      WHERE tt.account_id = t.account_id
        AND tt.task_id    = t.id
        AND tt.status IN ('abgerechnet','in_abrechnung')
      LIMIT 1
    ) AS has_billed
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  LEFT JOIN task_ordering_global og ON og.account_id = t.account_id AND og.task_id = t.id
  WHERE $WHERET
  ORDER BY (og.position IS NULL), og.position, p.title, t.deadline IS NULL, t.deadline, t.id DESC
  LIMIT :limit OFFSET :offset";
$st = $pdo->prepare($sqlTasks);
foreach ($paramsT as $k => $v) {
  $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$st->bindValue(':uid', $user_id, PDO::PARAM_INT);
$st->bindValue(':limit', $task_per_page, PDO::PARAM_INT);
$st->bindValue(':offset', $task_offset, PDO::PARAM_INT);
$st->execute();
$tasks = $st->fetchAll();

// Laufender Timer (einmalig)
static $__run_checked = false, $__running = null;
if (!$__run_checked) {
  $runStmt = $pdo->prepare('SELECT id, task_id, started_at
                            FROM times
                            WHERE account_id = ? AND user_id = ?
                              AND ended_at IS NULL
                            ORDER BY id DESC LIMIT 1');
  $runStmt->execute([$account_id, $user_id]);
  $__running = $runStmt->fetch();
  $__run_checked = true;
}
$has_running     = (bool)$__running;
$running_task_id = $has_running && !empty($__running['task_id']) ? (int)$__running['task_id'] : 0;
$running_time_id = $has_running ? (int)$__running['id'] : 0;
?>
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="card-title mb-0">Aufgaben</h5>

      <form class="d-inline" method="post" action="<?= url('/tasks/new.php') ?>">
        <?= csrf_field() ?>
        <?= return_to_hidden($return_to) ?>
        <input type="hidden" name="company_id" value="<?= (int)$company['id'] ?>">
        <button class="btn btn-sm btn-primary" type="submit">Neu</button>
      </form>
    </div>

    <form class="row g-2 mb-3" method="get" action="<?= hurl(url('/companies/show.php')) ?>">
      <input type="hidden" name="id"  value="<?= (int)$company['id'] ?>">
      <input type="hidden" name="tsf" value="1">
      <?php foreach (($proj_status ?? []) as $st): ?>
        <input type="hidden" name="proj_status[]" value="<?= h($st) ?>">
      <?php endforeach; ?>

      <div class="col-md-8">
        <label class="form-label">Aufgaben-Status</label>
        <div class="d-flex flex-wrap gap-3">
          <?php foreach ($allowed_task_status as $opt): ?>
            <div class="form-check">
              <input class="form-check-input"
                     type="checkbox"
                     name="task_status[]"
                     id="ts-<?= h($opt) ?>"
                     value="<?= h($opt) ?>"
                     <?= in_array($opt, $task_status, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="ts-<?= h($opt) ?>"><?= h($opt) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Standard: „offen“ und „warten“.</div>
      </div>

      <div class="col-md-4 d-flex align-items-end justify-content-end gap-2">
        <a class="btn btn-outline-secondary" href="<?= hurl(url('/companies/show.php').'?id='.(int)$company['id']) ?>">Reset (Standard)</a>
      </div>
    </form>

    <?php
      // In companies/show: ohne Firmen-Spalte
      $show_company = false;
      require __DIR__ . '/../tasks/_tasks_table.php';

    ?>

    <div class="p-2 d-flex justify-content-end">
      <?= render_pagination_named_keep(url('/companies/show.php'), 'task_page', $task_page, $tasks_pages, $keep_tasks) ?>
    </div>
  </div>
</div>

<?php
// --- Rechnungen: Filter + Pagination + Liste ---

$allowed_inv_status = ['in_vorbereitung','gestellt','gemahnt','bezahlt','ausgebucht','storniert'];
$has_isf = isset($_GET['isf']); // invoice status filter flag
$inv_status = [];
if (isset($_GET['inv_status']) && is_array($_GET['inv_status'])) {
  foreach ($_GET['inv_status'] as $st) {
    if (!is_string($st)) continue;
    $st = strtolower(trim($st));
    if (in_array($st, $allowed_inv_status, true)) $inv_status[] = $st;
  }
}
if (!$has_isf) {
  // Default: in_vorbereitung, gestellt, gemahnt
  $inv_status = ['in_vorbereitung','gestellt','gemahnt'];
}

// --- NEU: Datumsfilter für Rechnungen (issue_date) ---
$inv_from = $_GET['inv_from'] ?? '';
$inv_to   = $_GET['inv_to']   ?? '';
$validDate = function($d){
  if ($d === '' || $d === null) return false;
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $d)) return false;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if (!$validDate($inv_from)) $inv_from = '';
if (!$validDate($inv_to))   $inv_to   = '';

$inv_per_page = 10;
$inv_page = page_int($_GET['inv_page'] ?? 1);
$inv_offset = ($inv_page-1)*$inv_per_page;

$whereI = ['i.account_id = :acc', 'i.company_id = :cid'];
$paramsI = [':acc'=>$account_id, ':cid'=>$id];
if ($inv_status) {
  $in = [];
  foreach ($inv_status as $i=>$st) {
    $key = ':is'.$i;
    $in[] = $key;
    $paramsI[$key] = $st;
  }
  $whereI[] = 'i.status IN ('.implode(',', $in).')';
}
// NEU: Datumskorridor
if ($inv_from !== '') { $whereI[] = 'i.issue_date >= :inv_from'; $paramsI[':inv_from'] = $inv_from; }
if ($inv_to   !== '') { $whereI[] = 'i.issue_date <= :inv_to';   $paramsI[':inv_to']   = $inv_to;   }

$WHEREI = implode(' AND ', $whereI);

// Count
$icnt = $pdo->prepare("SELECT COUNT(*) FROM invoices i WHERE $WHEREI");
foreach ($paramsI as $k=>$v) { $icnt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$icnt->execute();
$inv_total  = (int)$icnt->fetchColumn();
$inv_pages  = max(1, (int)ceil($inv_total / $inv_per_page));

// Fetch invoices (jüngste nach issue_date zuerst)
$is = $pdo->prepare("
  SELECT
    i.id, i.invoice_number,
    i.issue_date, i.due_date, i.status, i.total_net, i.total_gross
  FROM invoices i
  WHERE $WHEREI
  ORDER BY i.issue_date DESC, i.id DESC
  LIMIT :lim OFFSET :off
");
foreach ($paramsI as $k=>$v) { $is->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$is->bindValue(':lim', $inv_per_page, PDO::PARAM_INT);
$is->bindValue(':off', $inv_offset, PDO::PARAM_INT);
$is->execute();
$invoices = $is->fetchAll();

// Keep-Params
$inv_keep = [
  'id'=>$id,
  'isf'=>1,
  'proj_page'=>$proj_page,
  'task_page'=>$task_page,
  'inv_from'=>$inv_from, // NEU
  'inv_to'=>$inv_to      // NEU
];
foreach ($proj_status as $st)      { $inv_keep['proj_status[]'][] = $st; }
foreach ($task_status ?? [] as $st){ $inv_keep['task_status[]'][] = $st; }
foreach ($inv_status as $st)       { $inv_keep['inv_status[]'][] = $st; }
?>

<?php
// --- Übersicht: Wiederkehrende Positionen (nur Anzeige + Links "Neu"/"Bearbeiten") ---
$riStmt = $pdo->prepare("
  SELECT id, description_tpl, quantity, unit_price, tax_scheme, vat_rate,
         interval_unit, interval_count, start_date, end_date, active
  FROM recurring_items
  WHERE account_id=? AND company_id=?
  ORDER BY start_date, id
");
$riStmt->execute([$account_id, (int)$company['id']]);
$recurrings = $riStmt->fetchAll();
?>

<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="card-title mb-0">Wiederkehrende Positionen</h5>
      <a class="btn btn-sm btn-primary" href="<?= url('/recurring/new.php') ?>?company_id=<?= (int)$company['id'] ?>">Neu</a>
    </div>

    <?php if (!$recurrings): ?>
      <div class="text-muted">Noch keine wiederkehrenden Positionen.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Bezeichnung (Template)</th>
              <th class="text-end">Menge</th>
              <th class="text-end">Einzelpreis</th>
              <th class="text-end">Steuerart</th>
              <th class="text-end">MwSt %</th>
              <th class="text-end">Netto</th>
              <th class="text-end">Brutto</th>
              <th>Intervall</th>
              <th>Laufzeit</th>
              <th class="text-end">Aktionen</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recurrings as $ri):
            $qty  = (float)$ri['quantity'];
            $unit = (float)$ri['unit_price'];
            $sch  = (string)$ri['tax_scheme'];
            $vat  = ($sch==='standard') ? (float)$ri['vat_rate'] : 0.0;
            $net  = round($qty*$unit, 2);
            $gross= round($net*(1+$vat/100), 2);

            $ic = (int)$ri['interval_count'];
            $intLabel = match($ri['interval_unit']) {
              'quarter' => 'alle '.$ic.' Quartale',
              'week'    => 'alle '.$ic.' Wochen',
              'day'     => 'alle '.$ic.' Tage',
              'year'    => 'alle '.$ic.' Jahre',
              default   => 'alle '.$ic.' Monate',
            };
          ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?=h($ri['description_tpl'])?></div>
                  <div class="text-muted small">
                    Platzhalter: {from}, {to}, {period}, {month}, {year}
                  </div>
                </td>
                <td class="text-end"><?= h(_fmt_qty($qty)) ?></td>
                <td class="text-end"><?= number_format($unit,2,',','.') ?></td>
                <td class="text-end"><?= h($sch) ?></td>
                <td class="text-end"><?= number_format($vat,2,',','.') ?></td>
                <td class="text-end"><?= number_format($net,2,',','.') ?></td>
                <td class="text-end"><?= number_format($gross,2,',','.') ?></td>
                <td><?= h($intLabel) ?></td>
                <td>
                  <?= h(_fmt_dmy($ri['start_date'])) ?>
                  <?php if (!empty($ri['end_date'])): ?> – <?= h(_fmt_dmy($ri['end_date'])) ?><?php else: ?> (unbegrenzt)<?php endif; ?>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-secondary" href="<?= url('/recurring/edit.php') ?>?id=<?= (int)$ri['id'] ?>">
                    <i class="bi bi-pencil"></i>
                    <span class="visually-hidden">Bearbeiten</span>
                  </a>


                  <form method="post" class="d-inline">
                    <?=csrf_field()?>
                    <input type="hidden" name="ri_action" value="del">
                    <input type="hidden" name="ri_id" value="<?= (int)$ri['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Löschen?')">
                      <i class="bi bi-trash"></i>
                      <span class="visually-hidden">Löschen</span>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="card-title mb-0">Rechnungen</h5>
      <a class="btn btn-sm btn-primary" href="<?=url('/invoices/new.php')?>?company_id=<?=$company['id']?>">Neu</a>
    </div>

    <form class="row g-2 mb-3" method="get" action="<?=hurl(url('/companies/show.php'))?>">
      <input type="hidden" name="id" value="<?=$company['id']?>">
      <input type="hidden" name="isf" value="1">
      <?php foreach ($proj_status as $st): ?><input type="hidden" name="proj_status[]" value="<?=h($st)?>"><?php endforeach; ?>
      <?php foreach ($task_status ?? [] as $st): ?><input type="hidden" name="task_status[]" value="<?=h($st)?>"><?php endforeach; ?>

      <div class="col-md-6">
        <label class="form-label">Rechnungs-Status</label>
        <div class="d-flex flex-wrap gap-3">
          <?php foreach ($allowed_inv_status as $opt): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox"
                     name="inv_status[]" id="is-<?=h($opt)?>" value="<?=h($opt)?>"
                     <?= in_array($opt, $inv_status, true) ? 'checked' : '' ?>
                     onchange="this.form.requestSubmit()">
              <label class="form-check-label" for="is-<?=h($opt)?>"><?=h($opt)?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Standard: „in_vorbereitung“, „gestellt“, „gemahnt“.</div>
      </div>

      <!-- NEU: Datumsbereich (issue_date) -->
      <div class="col-md-2">
        <label class="form-label">von (Rechnungsdatum)</label>
        <input type="date" name="inv_from" class="form-control" value="<?=h($inv_from)?>" onchange="this.form.requestSubmit()">
      </div>
      <div class="col-md-2">
        <label class="form-label">bis (Rechnungsdatum)</label>
        <input type="date" name="inv_to" class="form-control" value="<?=h($inv_to)?>" onchange="this.form.requestSubmit()">
      </div>

      <div class="col-md-2 d-flex align-items-end justify-content-end gap-2">
        <button class="btn btn-outline-secondary" type="submit">Filtern</button>
        <a class="btn btn-outline-secondary" href="<?=hurl(url('/companies/show.php').'?id='.$company['id'])?>">Reset (Standard)</a>
      </div>
    </form>

    <?php
      $invoice_table_mode = 'without_company';
      $empty_message = 'Keine Rechnungen für diesen Filter.';
      require __DIR__ . '/../invoices/_table.php';
    ?>

    <div class="mt-2 d-flex justify-content-end">
      <?= render_pagination_named_keep(url('/companies/show.php'), 'inv_page', $inv_page, $inv_pages, $inv_keep) ?>
    </div>
  </div>
</div>

<script>
/**
 * On-place Reload für Projekt-/Aufgaben-/Rechnungs-Card.
 * - Auto-Filter onChange (Checkboxen)
 * - Paginierung abfangen ( *_page )
 * - Reset-Links abfangen
 * - Nur die betroffene Card wird via fetch() ersetzt
 */
(function () {
  function getFormByFilterField(root, fieldName) {
    var input = root.querySelector('form input[name="' + fieldName + '"]');
    return input ? input.form : null;
  }
  function getCardForForm(form) {
    return form ? form.closest('.card') : null;
  }
  function isResetLink(href, pageParam) {
    if (!href) return false;
    var isShow = href.indexOf('/companies/show.php') !== -1;
    var hasPage = href.indexOf(pageParam + '=') !== -1;
    // WICHTIG: alle drei Filter-Flags berücksichtigen
    var hasTaskFlag = href.indexOf('tsf=') !== -1;
    var hasProjFlag = href.indexOf('psf=') !== -1;
    var hasInvFlag  = href.indexOf('isf=') !== -1;
    return isShow && !hasPage && !hasTaskFlag && !hasProjFlag && !hasInvFlag;
  }

  function bindCard(fieldName, pageParam) {
    var form = getFormByFilterField(document, fieldName);
    var card = getCardForForm(form);
    if (!form || !card) return;

    // "Filtern"-Button ausblenden (wir filtern onChange)
    var submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.style.display = 'none';

    // Auto-Submit bei Änderung einer Checkbox in diesem Formular
    form.addEventListener('change', function (e) {
      if (e.target && e.target.name === fieldName) {
        if (form.requestSubmit) form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });

    // Submit abfangen und on-place nachladen
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var params = new URLSearchParams(new FormData(form));
      var url = (form.action || window.location.pathname).split('#')[0] + '?' + params.toString();
      fetchAndSwap(url, fieldName, pageParam);
    });

    // Paginierung & Reset-Links in der jeweiligen Card abfangen
    card.addEventListener('click', function (e) {
      var a = e.target && e.target.closest('a');
      if (!a) return;
      var href = a.getAttribute('href') || '';

      if (href.indexOf(pageParam + '=') !== -1 || isResetLink(href, pageParam)) {
        e.preventDefault();
        fetchAndSwap(href, fieldName, pageParam);
      }
    });
  }

  function fetchAndSwap(url, fieldName, pageParam) {
    fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var newForm = getFormByFilterField(doc, fieldName);
        var newCard = getCardForForm(newForm);
        if (!newCard) return;

        var curForm = getFormByFilterField(document, fieldName);
        var curCard = getCardForForm(curForm);
        if (!curCard) return;

        curCard.replaceWith(newCard);

        // URL aktualisieren
        try { history.replaceState(null, '', url); } catch (e) {}

        // Rebind nur für diese Card
        setTimeout(function () {
          var onlyThisForm = getFormByFilterField(document, fieldName);
          var onlyThisCard = getCardForForm(onlyThisForm);
          if (!onlyThisForm || !onlyThisCard) return;

          var submitBtn = onlyThisForm.querySelector('button[type="submit"]');
          if (submitBtn) submitBtn.style.display = 'none';

          onlyThisForm.addEventListener('change', function (e) {
            if (e.target && e.target.name === fieldName) {
              if (onlyThisForm.requestSubmit) onlyThisForm.requestSubmit();
              else onlyThisForm.dispatchEvent(new Event('submit', { cancelable: true }));
            }
          });

          onlyThisForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var params = new URLSearchParams(new FormData(onlyThisForm));
            var url = (onlyThisForm.action || window.location.pathname).split('#')[0] + '?' + params.toString();
            fetchAndSwap(url, fieldName, pageParam);
          });

          onlyThisCard.addEventListener('click', function (e) {
            var a = e.target && e.target.closest('a');
            if (!a) return;
            var href = a.getAttribute('href') || '';
            if (href.indexOf(pageParam + '=') !== -1 || isResetLink(href, pageParam)) {
              e.preventDefault();
              fetchAndSwap(href, fieldName, pageParam);
            }
          });
        }, 0);
      })
      .catch(function (err) {
        console && console.error && console.error('Reload fehlgeschlagen:', err);
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Aufgaben
    bindCard('task_status[]', 'task_page');
    // Projekte
    bindCard('proj_status[]', 'proj_page');
    // Rechnungen (Status-Checkboxen werden überwacht; Datepicker submitten via inline onchange)
    bindCard('inv_status[]',  'inv_page');
  });
})();
</script>
<?php if (($_GET['dl'] ?? '') === 'xml'): ?>
  <?php if (isset($_GET["invoice_id"])) {
    $invoice_id = $_GET["invoice_id"];
  }?>
  <script>
    // Öffnet den XML-Export in neuem Tab, Nutzer bleibt in der Bearbeitung
    window.open('<?= h(url("/invoices/export_xml.php")."?id=".(int)$invoice_id) ?>', '_blank');
  </script>
<?php endif; ?>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>