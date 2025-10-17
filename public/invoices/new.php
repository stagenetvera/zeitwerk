<?php
// public/invoices/new.php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($v){ return number_format((float)$v, 2, ',', '.'); }
function fmt_minutes_to_hours($m){ return round(((int)$m)/60, 2); }

// ---- Daten für neue Rechnung: offene, fakturierbare Zeiten der Firma, gruppiert nach Projekten -> Tasks -> Times
function fmt_minutes_hhmm(int $m): string {
  $h = intdiv($m, 60); $r = $m % 60;
  return sprintf('%02d:%02d', $h, $r);
}

// $company muss schon bestimmt sein; ansonsten company_id aus GET/POST holen.
$company_id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);

// -------- return_to ----------
$return_to =  pick_return_to('/companies/show.php?id='.$company_id);


// Alle offenen billable Zeiten dieser Firma, inkl. Projekt/Task
$q = $pdo->prepare("
  SELECT
    t.id          AS time_id,
    t.minutes     AS minutes,
    t.started_at, t.ended_at,
    t.billable,
    ta.id         AS task_id,
    ta.description AS task_desc,
    p.id          AS project_id,
    p.title       AS project_title,
    COALESCE(p.hourly_rate, c.hourly_rate) AS effective_rate,
    c.id          AS company_id,
    c.name        AS company_name
  FROM times t
  JOIN tasks ta      ON ta.id = t.task_id AND ta.account_id = t.account_id
  JOIN projects p    ON p.id = ta.project_id AND p.account_id = ta.account_id
  JOIN companies c   ON c.id = p.company_id AND c.account_id = p.account_id
  WHERE t.account_id = :acc
    AND c.id         = :cid
    AND t.billable   = 1
    AND t.status     = 'offen'
    AND t.minutes IS NOT NULL
  ORDER BY p.title, ta.id, t.started_at
");
$q->execute([':acc'=>$account_id, ':cid'=>$company_id]);
$rows = $q->fetchAll();

$groups = []; // [ [project_id,title, rows:[ {task_id, task_desc, hourly_rate, tax_rate, times:[{id, minutes, started_at, ended_at}]} ] ] ]

$DEFAULT_TAX = 19.0; // %; gern später dynamisch je Firma
$byProject = [];
foreach ($rows as $r) {
  $pid = (int)$r['project_id'];
  if (!isset($byProject[$pid])) {
    $byProject[$pid] = [
      'project_id'    => $pid,
      'project_title' => $r['project_title'],
      'rows'          => []
    ];
  }
  $taskId = (int)$r['task_id'];
  // Key je Task in diesem Projekt
  if (!isset($byProject[$pid]['rows'][$taskId])) {
    $byProject[$pid]['rows'][$taskId] = [
      'task_id'       => $taskId,
      'task_desc'     => $r['task_desc'],
      'hourly_rate'   => (float)$r['effective_rate'],
      'tax_rate'      => $DEFAULT_TAX,
      'times'         => []
    ];
  }
  $byProject[$pid]['rows'][$taskId]['times'][] = [
    'id'         => (int)$r['time_id'],
    'minutes'    => (int)$r['minutes'],
    'started_at' => $r['started_at'],
    'ended_at'   => $r['ended_at']
  ];
}
// Reihen in numerische Arrays umwandeln
foreach ($byProject as &$g) {
  $g['rows'] = array_values($g['rows']);
}
unset($g);
$groups = array_values($byProject);

$err = null;
$ok  = null;

// -------- Firmenliste ----------
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cs->execute([$account_id]);
$companies = $cs->fetchAll();

// Gewählte Firma
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : (int)($_POST['company_id'] ?? 0);

// Prüfen ob Firma zum Account gehört (bei Auswahl)
$company = null;
if ($company_id) {
  $cchk = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
  $cchk->execute([$company_id, $account_id]);
  $company = $cchk->fetch();
  if (!$company) {
    $err = 'Ungültige Firma.';
    $company_id = 0;
  }
}

// -------- Zeiten / Aufgaben der Firma laden (nur Anzeige) ----------
$taskRows = [];
if ($company_id) {
  // Alle offenen, fakturierbaren Zeiten, die zu dieser Firma gehören
  // Gruppiert nach Task, inkl. Stundensatz (effektiv) über Projekt/Firma
  $sql = "
    SELECT
      t.id                 AS task_id,
      t.description        AS task_description,
      p.id                 AS project_id,
      p.title              AS project_title,
      COALESCE(p.hourly_rate, c.hourly_rate) AS rate,
      SUM(tm.minutes)      AS sum_minutes,
      GROUP_CONCAT(tm.id ORDER BY tm.id) AS time_ids_csv
    FROM times tm
    JOIN tasks t     ON t.id = tm.task_id AND t.account_id = tm.account_id
    JOIN projects p  ON p.id = t.project_id AND p.account_id = t.account_id
    JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
    WHERE
      tm.account_id = :acc
      AND c.id = :cid
      AND tm.status = 'offen'
      AND tm.billable = 1
    GROUP BY t.id, t.description, p.id, p.title, COALESCE(p.hourly_rate, c.hourly_rate)
    ORDER BY p.title, t.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':acc'=>$account_id, ':cid'=>$company_id]);
  $taskRows = $st->fetchAll();
}

// -------- Speichern ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='save') {
  $company_id = (int)($_POST['company_id'] ?? 0);
  $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
  $due_date   = $_POST['due_date']   ?? date('Y-m-d', strtotime('+14 days'));

  if (!$company_id) {
    $err = 'Bitte eine Firma auswählen.';
  } else {
    // Firma validieren
    $cchk = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND account_id = ?');
    $cchk->execute([$company_id, $account_id]);
    if (!$cchk->fetchColumn()) {
      $err = 'Ungültige Firma.';
    }
  }

  // Aus Formular: Ausgewählte Task-Positionen
  // tasks[<task_id>][include]=1
  // tasks[<task_id>][project_id], [description], [minutes], [rate], [vat]
  // times[<task_id>][] = time_id
  $tasksForm = $_POST['tasks'] ?? [];
  $timesForm = $_POST['times'] ?? [];

  // Manuelle Positionen:
  // manual[i][description], [quantity], [unit_price], [vat_rate]
  $manual = $_POST['manual'] ?? [];

  // Mindestens irgendetwas muss drin sein
  $hasAnyItem = false;
  if (!$err) {
    foreach ($tasksForm as $tid => $row) {
      if (!empty($row['include'])) { $hasAnyItem = true; break; }
    }
    if (!$hasAnyItem && is_array($manual)) {
      foreach ($manual as $m) {
        if (trim($m['description'] ?? '') !== '') { $hasAnyItem = true; break; }
      }
    }
    if (!$hasAnyItem) $err = 'Keine Position ausgewählt.';
  }

  if (!$err) {
    $pdo->beginTransaction();
    try {
      // 1) Rechnung anlegen
      $insInv = $pdo->prepare("
        INSERT INTO invoices (account_id, company_id, status, issue_date, due_date, total_net, total_gross)
        VALUES (?,?,?,?,?,?,?)
      ");
      $insInv->execute([
        $account_id, $company_id, 'in_vorbereitung', $issue_date, $due_date, 0.00, 0.00
      ]);
      $invoice_id = (int)$pdo->lastInsertId();

      // 2) Positionen vorbereiten
      $insItem = $pdo->prepare("
        INSERT INTO invoice_items
          (account_id, invoice_id, project_id, task_id, description, quantity, unit_price, vat_rate, total_net, total_gross, position)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ");
      $insLink = $pdo->prepare("
        INSERT INTO invoice_item_times (account_id, invoice_item_id, time_id)
        VALUES (?,?,?)
      ");
      $setTimeStatus = $pdo->prepare("
        UPDATE times SET status = 'in_abrechnung' WHERE account_id = ? AND id = ?
      ");

      $pos = 1;
      $sum_net = 0.00;
      $sum_gross = 0.00;

      // 2a) Ausgewählte Task-Positionen
      foreach ($tasksForm as $tid => $row) {
        if (empty($row['include'])) continue;

        $task_id    = (int)$tid;
        $project_id = isset($row['project_id']) && $row['project_id'] !== '' ? (int)$row['project_id'] : null;
        $desc       = trim($row['description'] ?? '');
        $minutes    = (int)($row['minutes'] ?? 0);
        $rate       = (float)($row['rate'] ?? 0);
        $vat        = (float)($row['vat'] ?? 19.0);

        // Menge in Stunden
        $qty = round($minutes / 60, 2);
        $net = round($qty * $rate, 2);
        $gross = round($net * (1 + $vat/100), 2);

        $insItem->execute([
          $account_id, $invoice_id, $project_id, $task_id, $desc, $qty, $rate, $vat, $net, $gross, $pos++
        ]);
        $item_id = (int)$pdo->lastInsertId();

        // Zeiten linken (nur die übergebenen IDs)
        $timeIds = $timesForm[$tid] ?? [];
        foreach ($timeIds as $time_id) {
          $time_id = (int)$time_id;
          if ($time_id <= 0) continue;
          $insLink->execute([$account_id, $item_id, $time_id]);
          $setTimeStatus->execute([$account_id, $time_id]); // -> in_abrechnung
        }

        $sum_net   += $net;
        $sum_gross += $gross;
      }

      // 2b) Manuelle Positionen
      if (is_array($manual)) {
        foreach ($manual as $m) {
          $mdesc = trim($m['description'] ?? '');
          if ($mdesc === '') continue;
          $mqty   = (float)($m['quantity'] ?? 0);
          $mprice = (float)($m['unit_price'] ?? 0);
          $mvat   = (float)($m['vat_rate'] ?? 19.0);
          $mnet   = round($mqty * $mprice, 2);
          $mgross = round($mnet * (1 + $mvat/100), 2);

          $insItem->execute([
            $account_id, $invoice_id, null, null, $mdesc, $mqty, $mprice, $mvat, $mnet, $mgross, $pos++
          ]);

          $sum_net   += $mnet;
          $sum_gross += $mgross;
        }
      }

      // 3) Rechnungssummen aktualisieren
      $updInv = $pdo->prepare("UPDATE invoices SET total_net = ?, total_gross = ? WHERE id = ? AND account_id = ?");
      $updInv->execute([$sum_net, $sum_gross, $invoice_id, $account_id]);

      $pdo->commit();

      // Zurück zur Firma
      redirect(url('/companies/show.php').'?id='.$company_id);
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = 'Rechnung konnte nicht angelegt werden. ('.$e->getMessage().')';
    }
  }
}

// -------- View ----------
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Neue Rechnung</h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?=hurl($return_to)?>">Zurück</a>
  </div>
</div>

<?php if ($err): ?>
  <div class="alert alert-danger"><?=h($err)?></div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" action="<?=hurl(url('/invoices/new.php'))?>" class="row g-2">
      <div class="col-md-6">
        <label class="form-label">Firma</label>
        <select name="company_id" class="form-select" onchange="this.form.submit()">
          <option value="">– bitte wählen –</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?=$c['id']?>" <?=$company_id===$c['id']?'selected':''?>><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 d-flex align-items-end justify-content-end">
        <input type="hidden" name="return_to" value="<?=h($return_to)?>">
        <a class="btn btn-outline-secondary" href="<?=hurl(url('/invoices/new.php'))?>">Reset</a>
      </div>
    </form>
  </div>
</div>

<?php if ($company_id): ?>
<form method="post">
  <?=csrf_field()?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="company_id" value="<?=$company_id?>">
  <input type="hidden" name="return_to" value="<?=h($return_to)?>">

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Rechnungsdatum</label>
          <input type="date" name="issue_date" class="form-control" value="<?=h($_POST['issue_date'] ?? date('Y-m-d'))?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Fällig bis</label>
          <input type="date" name="due_date" class="form-control" value="<?=h($_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days')))?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title">Offene Zeiten / Aufgaben der Firma</h5>
      <?php if (!$taskRows): ?>
        <div class="text-muted">Keine offenen, fakturierbaren Zeiten gefunden.</div>
      <?php else: ?>
        <?php require __DIR__ . '/_items_table.php'; ?>

      <?php endif; ?>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title d-flex justify-content-between align-items-center">
        <span>Manuelle Positionen</span>
        <button class="btn btn-sm btn-outline-primary" type="button" id="addManual">+ Position</button>
      </h5>
      <div id="manualBox"></div>
      <template id="manualTpl">
        <div class="row g-2 align-items-end mb-2 manual-row">
          <div class="col-md-6">
            <label class="form-label">Beschreibung</label>
            <input type="text" class="form-control" name="__NAME__[description]" placeholder="z. B. Lizenzkosten">
          </div>
          <div class="col-md-2">
            <label class="form-label">Menge</label>
            <input type="number" step="0.01" class="form-control" name="__NAME__[quantity]" value="1">
          </div>
          <div class="col-md-2">
            <label class="form-label">Einzelpreis (€)</label>
            <input type="number" step="0.01" class="form-control" name="__NAME__[unit_price]" value="0.00">
          </div>
            <div class="col-md-1">
            <label class="form-label">MWSt %</label>
            <input type="number" step="0.01" class="form-control" name="__NAME__[vat_rate]" value="19.00">
          </div>
          <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger w-100 removeManual">–</button>
          </div>
        </div>
      </template>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="<?=hurl($return_to)?>">Abbrechen</a>
    <button class="btn btn-primary">Rechnung anlegen</button>
  </div>
</form>

<script>
(function(){
  const box = document.getElementById('manualBox');
  const tpl = document.getElementById('manualTpl');
  const add = document.getElementById('addManual');
  let idx = 0;

  function addRow(){
    const html = tpl.innerHTML.replaceAll('__NAME__', 'manual['+(idx++)+']');
    const frag = document.createElement('div');
    frag.innerHTML = html;
    const row = frag.firstElementChild;
    box.appendChild(row);
    row.querySelector('.removeManual').addEventListener('click', function(){
      row.remove();
    });
  }

  add.addEventListener('click', addRow);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>