<?php
 // public/invoices/new.php
 require __DIR__ . '/../../src/layout/header.php';
 require_once __DIR__ . '/../../src/lib/settings.php';
 require_login();
 csrf_check();

 $user       = auth_user();
 $account_id = (int)$user['account_id'];

$settings = get_account_settings($pdo, $account_id);

// Defaults
$DEFAULT_TAX     = (float)$settings['default_vat_rate'];      // ersetzt deine bisherige 19.0-Konstante
$DEFAULT_DUE_DAYS= (int)$settings['default_due_days'];
$DEFAULT_SCHEME  = $settings['default_tax_scheme'];           // 'standard'|'tax_exempt'|'reverse_charge'
$DEFAULT_DUE_DAYS = (int)($settings['default_due_days'] ?? 14);

$issue_default = date('Y-m-d');
$due_default   = date('Y-m-d', strtotime('+' . max(0,$DEFAULT_DUE_DAYS) . ' days'));

function hurl($s)
 {return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');}
 function fmt_money($v)
 {return number_format((float)$v, 2, ',', '.');}
 function fmt_minutes_to_hours($m)
 {return round(((int)$m) / 60, 2);}

 // ...
 /** Summe Minuten für eine Menge von Time-IDs dieser Firma/Account */
 function sum_minutes_for_times(PDO $pdo, int $account_id, array $ids): int
 {
  $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
  if (! $ids) {
   return 0;
  }

  $in     = implode(',', array_fill(0, count($ids), '?'));
  $params = array_merge([$account_id], $ids);
  $st     = $pdo->prepare("SELECT COALESCE(SUM(minutes),0) AS m FROM times WHERE account_id = ? AND id IN ($in)");
  $st->execute($params);
  return (int)$st->fetchColumn();
 }

 // ---- Daten für neue Rechnung: offene, fakturierbare Zeiten der Firma, gruppiert nach Projekten -> Tasks -> Times
 function fmt_minutes_hhmm(int $m): string
 {
  $h = intdiv($m, 60);
  $r = $m % 60;
  return sprintf('%02d:%02d', $h, $r);
 }

 // Normalisiert $_POST['time_ids'] zu: [task_id => [time_id, ...]]
 function normalize_times_selection($timesForm, PDO $pdo, int $account_id): array
 {
  if (empty($timesForm)) {
   return [];
  }

  // Fall A: schon verschachtelt: time_ids[task_id][] = time_id
  $isNested = false;
  foreach ($timesForm as $k => $v) {
   if (is_array($v)) {$isNested = true;
    break;}
  }
  if ($isNested) {
   $out = [];
   foreach ($timesForm as $tid => $ids) {
    $tid = (int)$tid;
    $ids = array_values(array_unique(array_map('intval', (array)$ids)));
    if ($tid > 0 && $ids) {
     $out[$tid] = $ids;
    }

   }
   return $out;
  }

  // Fall B: flaches Array: time_ids[] = 123,124,...
  $flat = array_values(array_unique(array_map('intval', (array)$timesForm)));
  if (! $flat) {
   return [];
  }

  $in = implode(',', array_fill(0, count($flat), '?'));
  $st = $pdo->prepare("SELECT id, task_id FROM times WHERE account_id = ? AND id IN ($in)");
  $st->execute(array_merge([$account_id], $flat));

  $out = [];
  while ($row = $st->fetch()) {
   $tid = (int)$row['task_id'];
   $id  = (int)$row['id'];
   if ($tid > 0) {
    $out[$tid][] = $id;
   }

  }
  return $out;
 }

 // $company muss schon bestimmt sein; ansonsten company_id aus GET/POST holen.
 $company_id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);

 // -------- return_to ----------
 $return_to = pick_return_to('/companies/show.php?id=' . $company_id);

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
 $q->execute([':acc' => $account_id, ':cid' => $company_id]);
 $rows = $q->fetchAll();

 $groups = []; // [ [project_id,title, rows:[ {task_id, task_desc, hourly_rate, tax_rate, times:[{id, minutes, started_at, ended_at}]} ] ] ]

 $DEFAULT_TAX = (float)$settings['default_vat_rate']; // %; gern später dynamisch je Firma
 $byProject   = [];
 foreach ($rows as $r) {
  $pid = (int)$r['project_id'];
  if (! isset($byProject[$pid])) {
   $byProject[$pid] = [
    'project_id'    => $pid,
    'project_title' => $r['project_title'],
    'rows'          => [],
   ];
  }
  $taskId = (int)$r['task_id'];
  // Key je Task in diesem Projekt
  if (! isset($byProject[$pid]['rows'][$taskId])) {
   $byProject[$pid]['rows'][$taskId] = [
    'task_id'     => $taskId,
    'task_desc'   => $r['task_desc'],
    'hourly_rate' => (float)$r['effective_rate'],
    'tax_rate' => ($DEFAULT_SCHEME === 'standard') ? (float)$settings['default_vat_rate'] : 0.0,
    'times'       => [],
   ];
  }
  $byProject[$pid]['rows'][$taskId]['times'][] = [
   'id'         => (int)$r['time_id'],
   'minutes'    => (int)$r['minutes'],
   'started_at' => $r['started_at'],
   'ended_at'   => $r['ended_at'],
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
  if (! $company) {
   $err        = 'Ungültige Firma.';
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
  $st->execute([':acc' => $account_id, ':cid' => $company_id]);
  $taskRows = $st->fetchAll();
 }

 // -------- Speichern ----------
 if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
  $company_id = (int)($_POST['company_id'] ?? 0);
  $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
  $due_date   = $_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days'));

  if (! $company_id) {
   $err = 'Bitte eine Firma auswählen.';
  } else {
   // Firma validieren
   $cchk = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND account_id = ?');
   $cchk->execute([$company_id, $account_id]);
   if (! $cchk->fetchColumn()) {
    $err = 'Ungültige Firma.';
   }
  }

  // Eingaben aus dem Formular
  $tasksForm = $_POST['tasks'] ?? [];
  $timesRaw  = $_POST['time_ids'] ?? []; // kann flach oder verschachtelt sein
  $manual    = $_POST['manual'] ?? [];

  // Zuordnung time_ids => [task_id => [time_id,...]] herstellen
  $timesByTask = normalize_times_selection($timesRaw, $pdo, $account_id);

  // Mindestens irgendetwas muss drin sein
  $hasAnyItem = false;
  if (! $err) {
   // Primär: gibt es ausgewählte Zeiten?
   if (! empty($timesByTask)) {
    $hasAnyItem = true;
   }

   // Fallback: alte tasks[tid][include]-Checkboxen
   if (! $hasAnyItem) {
    foreach (($tasksForm ?? []) as $tid => $row) {
     if (! empty($row['include'])) {$hasAnyItem = true;
      break;}
    }
   }

   // Manuelle Positionen
   if (! $hasAnyItem && is_array($manual)) {
    foreach ($manual as $m) {
     if (trim($m['description'] ?? '') !== '') {$hasAnyItem = true;
      break;}
    }
   }

   if (! $hasAnyItem) {
    $err = 'Keine Position ausgewählt.';
   }

  }
  if (! $err) {
   $pdo->beginTransaction();
   try {
    // 1) Rechnung anlegen
    $insInv = $pdo->prepare("
        INSERT INTO invoices (account_id, company_id, status, issue_date, due_date, total_net, total_gross)
        VALUES (?,?,?,?,?,?,?)
      ");
    $insInv->execute([
     $account_id, $company_id, 'in_vorbereitung', $issue_date, $due_date, 0.00, 0.00,
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


// ---------- Effektive Defaultwerte aus Firma/Account ----------
$eff_vat_default = (isset($company['default_vat_rate']) && $company['default_vat_rate'] !== null)
  ? (float)$company['default_vat_rate']
  : (float)$settings['default_vat_rate'];

$eff_scheme_default = $company['default_tax_scheme'] ?? ($settings['default_tax_scheme'] ?? 'standard');

// ---------- Formular-Felder einsammeln ----------
$itemsForm   = $_POST['items']    ?? []; // items[idx][task_id|description|hourly_rate|tax_scheme|vat_rate]
$manual      = $_POST['manual']   ?? []; // manual[idx][description|quantity|unit_price|vat_rate]
$timesRaw    = $_POST['time_ids'] ?? []; // time_ids[task_id][] = time_id
$timesByTask = normalize_times_selection($timesRaw, $pdo, $account_id);

// Map: task_id => { description, rate, vat }
$propsByTask = [];
foreach ($itemsForm as $row) {
  $tid = isset($row['task_id']) ? (int)$row['task_id'] : 0;
  if (!$tid) continue;

  $desc = trim((string)($row['description'] ?? ''));

  // Stundensatz (Komma zulassen)
  $rate = isset($row['hourly_rate']) && $row['hourly_rate'] !== ''
    ? (float)str_replace(',', '.', (string)$row['hourly_rate'])
    : 0.0;

  // VAT: bevorzugt 'vat_rate', danach (BC) 'tax_rate'
  $vat_input = null;
  if (array_key_exists('vat_rate', $row) && $row['vat_rate'] !== '') {
    $vat_input = (float)str_replace(',', '.', (string)$row['vat_rate']);
  } elseif (array_key_exists('tax_rate', $row) && $row['tax_rate'] !== '') {
    $vat_input = (float)str_replace(',', '.', (string)$row['tax_rate']);
  }

  $scheme = $row['tax_scheme'] ?? $eff_scheme_default;
  $vat    = $vat_input;
  if ($vat === null) {
    // Wenn kein expliziter Satz eingegeben: aus Scheme ableiten
    $vat = ($scheme === 'standard') ? $eff_vat_default : 0.0; // tax_exempt/reverse_charge => 0%
  }
  $vat = max(0.0, min(100.0, (float)$vat)); // Clamp

  $propsByTask[$tid] = [
    'description' => $desc,
    'rate'        => (float)$rate,
    'vat'         => (float)$vat,
  ];
}

$getTaskProject = $pdo->prepare("SELECT project_id FROM tasks WHERE account_id = ? AND id = ?");

$pos       = 1;
$sum_net   = 0.00;
$sum_gross = 0.00;

/** 2a) Normale Erstellung: pro Task die explizit ausgewählten Zeiten abrechnen */
foreach ($timesByTask as $task_id => $picked) {
  $task_id = (int)$task_id;
  $picked  = array_values(array_filter(array_map('intval', (array)$picked), fn($v)=>$v>0));
  if (!$picked) continue;

  // Eigenschaften aus items[...] (nicht aus tasks[...])
  $p     = $propsByTask[$task_id] ?? ['description'=>'', 'rate'=>0.0, 'vat'=>$eff_vat_default];
  $desc  = $p['description'];
  $rate  = (float)$p['rate'];
  $vat   = (float)$p['vat'];

  // Projekt bestimmen
  $getTaskProject->execute([$account_id, $task_id]);
  $project_id = ($pid = $getTaskProject->fetchColumn()) ? (int)$pid : null;

  // Minuten summieren
  $minutes = sum_minutes_for_times($pdo, $account_id, $picked);
  $qty     = round($minutes / 60, 2);

  $net   = round($qty * $rate, 2);
  $gross = round($net * (1 + $vat/100), 2);

  // Position schreiben
  $insItem->execute([
    $account_id, $invoice_id, $project_id, $task_id, $desc,
    $qty, $rate, $vat, $net, $gross, $pos++
  ]);
  $item_id = (int)$pdo->lastInsertId();

  // Zeiten verknüpfen + Status
  foreach ($picked as $time_id) {
    $insLink->execute([$account_id, $item_id, (int)$time_id]);
    $setTimeStatus->execute([$account_id, (int)$time_id]);
  }

  $sum_net   += $net;
  $sum_gross += $gross;
}

/** 2b) Fallback: keine expliziten time_ids, aber Items vorhanden → alle offenen billable Zeiten pro Task abrechnen */
if ($sum_net == 0.0 && $sum_gross == 0.0 && !empty($propsByTask)) {
  $getOpenTimes = $pdo->prepare("
    SELECT id FROM times
    WHERE account_id = ? AND task_id = ? AND status = 'offen' AND billable = 1 AND minutes IS NOT NULL
  ");
  foreach ($propsByTask as $task_id => $p) {
    $getOpenTimes->execute([$account_id, (int)$task_id]);
    $picked = array_map(fn($r)=>(int)$r['id'], $getOpenTimes->fetchAll());
    if (!$picked) continue;

    $desc = $p['description'];
    $rate = (float)$p['rate'];
    $vat  = (float)$p['vat'];

    $getTaskProject->execute([$account_id, (int)$task_id]);
    $project_id = ($pid = $getTaskProject->fetchColumn()) ? (int)$pid : null;

    $minutes = sum_minutes_for_times($pdo, $account_id, $picked);
    $qty     = round($minutes / 60, 2);

    $net   = round($qty * $rate, 2);
    $gross = round($net * (1 + $vat/100), 2);

    $insItem->execute([
      $account_id, $invoice_id, $project_id, (int)$task_id, $desc,
      $qty, $rate, $vat, $net, $gross, $pos++
    ]);
    $item_id = (int)$pdo->lastInsertId();

    foreach ($picked as $time_id) {
      $insLink->execute([$account_id, $item_id, (int)$time_id]);
      $setTimeStatus->execute([$account_id, (int)$time_id]);
    }

    $sum_net   += $net;
    $sum_gross += $gross;
  }
}

/** 2c) Manuelle Positionen (ohne Task/Projekt) */
if (!empty($manual) && is_array($manual)) {
  foreach ($manual as $m) {
    $desc = trim($m['description'] ?? '');
    if ($desc === '') continue;

    $qty    = isset($m['quantity'])   ? (float)str_replace(',', '.', (string)$m['quantity'])    : 0.0;
    $price  = isset($m['unit_price']) ? (float)str_replace(',', '.', (string)$m['unit_price'])  : 0.0;
    $vat    = isset($m['vat_rate'])   ? (float)str_replace(',', '.', (string)$m['vat_rate'])    : $eff_vat_default;
    $vat    = max(0.0, min(100.0, (float)$vat));

    $net   = round($qty * $price, 2);
    $gross = round($net * (1 + $vat/100), 2);

    $insItem->execute([
      $account_id, $invoice_id, null, null, $desc,
      $qty, $price, $vat, $net, $gross, $pos++
    ]);

    $sum_net   += $net;
    $sum_gross += $gross;
  }
}

// -------- Summen in der Rechnung aktualisieren --------
$updInv = $pdo->prepare("UPDATE invoices SET total_net = ?, total_gross = ? WHERE id = ? AND account_id = ?");
$updInv->execute([$sum_net, $sum_gross, $invoice_id, $account_id]);

    // 3) Rechnungssummen aktualisieren

    $pdo->commit();

    // Zurück zur Firma
    redirect(url('/companies/show.php') . '?id=' . $company_id);
   } catch (Throwable $e) {
    $pdo->rollBack();
    $err = 'Rechnung konnte nicht angelegt werden. (' . $e->getMessage() . ')';
   }
  }
 }

 // -------- View ----------
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Neue Rechnung</h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?php echo h(url($return_to)) ?>">Zurück</a>
  </div>
</div>

<?php if ($err): ?>
  <div class="alert alert-danger"><?php echo h($err) ?></div>
<?php endif; ?>


<?php if ($company_id): ?>
<form method="post" id="invForm" action="<?php echo hurl(url('/invoices/new.php')) ?>">
  <?php echo csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="company_id" value="<?php echo $company_id ?>">
  <input type="hidden" name="return_to" value="<?php echo h($return_to) ?>">

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Rechnungsdatum</label>
          <input type="date" name="issue_date" class="form-control" value="<?php echo h($_POST['issue_date'] ?? $issue_default) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Fällig bis</label>
   		  <input type="date" name="due_date" class="form-control"
       		value="<?= h($_POST['due_date'] ?? $due_default) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title">Offene Zeiten / Aufgaben der Firma</h5>
      <?php if (! $taskRows): ?>
        <div class="text-muted">Keine offenen, fakturierbaren Zeiten gefunden.</div>
      <?php else: ?>
        <?php $mode = 'new';
         $rowName            = 'tasks';
         $timesName          = 'time_ids';
        require __DIR__ . '/_items_table.php'; ?>

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
    <a class="btn btn-outline-secondary" href="<?php echo h(url($return_to)) ?>">Abbrechen</a>
    <button class="btn btn-primary" name="action" value="save">Rechnung anlegen</button>
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