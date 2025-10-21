<?php
// public/invoices/new.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/utils.php'; // dec(), parse_hours_to_decimal()
require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$settings = get_account_settings($pdo, $account_id);

// Defaults aus Account-Einstellungen
$DEFAULT_TAX      = (float)$settings['default_vat_rate'];
$DEFAULT_SCHEME   = $settings['default_tax_scheme']; // 'standard'|'tax_exempt'|'reverse_charge'
$DEFAULT_DUE_DAYS = (int)($settings['default_due_days'] ?? 14);

$issue_default = date('Y-m-d');
$due_default   = date('Y-m-d', strtotime('+' . max(0, $DEFAULT_DUE_DAYS) . ' days'));

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($v){ return number_format((float)$v, 2, ',', '.'); }


/** Summe Minuten über Time-IDs (Account-sicher) */
function sum_minutes_for_times(PDO $pdo, int $account_id, array $ids): int {
  $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
  if (!$ids) return 0;
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT COALESCE(SUM(minutes),0) FROM times WHERE account_id=? AND id IN ($in)");
  $st->execute(array_merge([$account_id], $ids));
  return (int)$st->fetchColumn();
}

/** Normalisiert $_POST['time_ids'] zu: [task_id => [time_id,...]] */
function normalize_times_selection($timesForm, PDO $pdo, int $account_id): array {
  if (empty($timesForm)) return [];
  $isNested = false;
  foreach ($timesForm as $v) { if (is_array($v)) { $isNested = true; break; } }
  if ($isNested) {
    $out = [];
    foreach ($timesForm as $tid => $ids) {
      $tid = (int)$tid;
      $ids = array_values(array_unique(array_map('intval', (array)$ids)));
      if ($tid > 0 && $ids) $out[$tid] = $ids;
    }
    return $out;
  }
  // flach -> nachladen
  $flat = array_values(array_unique(array_map('intval', (array)$timesForm)));
  if (!$flat) return [];
  $in = implode(',', array_fill(0, count($flat), '?'));
  $st = $pdo->prepare("SELECT id, task_id FROM times WHERE account_id=? AND id IN ($in)");
  $st->execute(array_merge([$account_id], $flat));
  $out = [];
  while ($row = $st->fetch()) {
    $tid = (int)$row['task_id']; $id = (int)$row['id'];
    if ($tid > 0) $out[$tid][] = $id;
  }
  return $out;
}

// --------- Kontext / Daten laden ----------
$company_id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);

// return_to
$return_to = pick_return_to('/companies/show.php?id='.$company_id);

// Offene Zeiten der Firma für das NEW-Partial ($groups)
$q = $pdo->prepare("
  SELECT
    t.id          AS time_id,
    t.minutes,
    t.started_at, t.ended_at,
    ta.id         AS task_id,
    ta.description AS task_desc,
    p.id          AS project_id,
    p.title       AS project_title,
    COALESCE(p.hourly_rate, c.hourly_rate) AS effective_rate,
    c.id          AS company_id,
    c.name        AS company_name
  FROM times t
  JOIN tasks ta    ON ta.id = t.task_id AND ta.account_id = t.account_id
  JOIN projects p  ON p.id = ta.project_id AND p.account_id = ta.account_id
  JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
  WHERE t.account_id = :acc
    AND c.id         = :cid
    AND t.billable   = 1
    AND ta.billable  = 1
    AND t.status     = 'offen'
    AND t.minutes IS NOT NULL
  ORDER BY p.title, ta.id, t.started_at
");
$q->execute([':acc'=>$account_id, ':cid'=>$company_id]);
$rows = $q->fetchAll();

$groups = []; // für _items_table.php (NEW-Modus)
$byProject = [];
foreach ($rows as $r) {
  $pid = (int)$r['project_id'];
  if (!isset($byProject[$pid])) {
    $byProject[$pid] = ['project_id'=>$pid, 'project_title'=>$r['project_title'], 'rows'=>[]];
  }
  $taskId = (int)$r['task_id'];
  if (!isset($byProject[$pid]['rows'][$taskId])) {
    $byProject[$pid]['rows'][$taskId] = [
      'task_id'     => $taskId,
      'task_desc'   => $r['task_desc'],
      'hourly_rate' => (float)$r['effective_rate'],
      'tax_rate'    => ($DEFAULT_SCHEME === 'standard') ? (float)$settings['default_vat_rate'] : 0.0,
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
foreach ($byProject as &$g) { $g['rows'] = array_values($g['rows']); } unset($g);
$groups = array_values($byProject);

// Firmenliste (für Auswahl)
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cs->execute([$account_id]);
$companies = $cs->fetchAll();

// geprüfte Firma laden
$company = null;
$err = null; $ok = null;

if ($company_id) {
  $cchk = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
  $cchk->execute([$company_id, $account_id]);
  $company = $cchk->fetch();
  if (!$company) {
    $err = 'Ungültige Firma.'; $company_id = 0;
  }
}

// ---------- Speichern ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='save') {
  $company_id = (int)($_POST['company_id'] ?? 0);
  $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
  $due_date   = $_POST['due_date']   ?? date('Y-m-d', strtotime('+14 days'));
  $tax_reason = trim($_POST['tax_exemption_reason'] ?? '');

  // Firma validieren
  if (!$company_id) {
    $err = 'Bitte eine Firma auswählen.';
  } else {
    $cchk = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND account_id = ?');
    $cchk->execute([$company_id, $account_id]);
    if (!$cchk->fetchColumn()) $err = 'Ungültige Firma.';
  }

  // Eingaben
  $itemsForm = $_POST['items']   ?? []; // items[idx][task_id|description|hourly_rate|tax_scheme|vat_rate]
  $timesRaw  = $_POST['time_ids']?? []; // time_ids[task_id][] = time_id (oder flach)
  $extras    = $_POST['extras']  ?? ($_POST['extra'] ?? []); // Zusatzpositionen mit Toggle

  // Times normalisieren
  $timesByTask = normalize_times_selection($timesRaw, $pdo, $account_id);

  // Mindestens irgendetwas?
  $hasAnyItem = false;
  if (!empty($timesByTask)) $hasAnyItem = true;
  if (!$hasAnyItem && is_array($extras)) {
    foreach ($extras as $e) {
      $d = trim($e['description'] ?? '');
      $q = dec($e['quantity'] ?? $e['hours'] ?? '0');
      $p = dec($e['unit_price'] ?? $e['hourly_rate'] ?? '0');
      if ($d !== '' && ($q > 0 || $p > 0)) { $hasAnyItem = true; break; }
    }
  }
  if (!$hasAnyItem && is_array($itemsForm)) {
    foreach ($itemsForm as $row) {
      if (!empty($row['task_id'])) { $hasAnyItem = true; break; }
    }
  }
  if (!$hasAnyItem && !$err) $err = 'Keine Position ausgewählt.';

  // Effektive Defaults aus Firma/Account
  if (!$err) {
    $coStmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
    $coStmt->execute([$company_id, $account_id]);
    $company = $coStmt->fetch();

    $eff_vat_default    = (isset($company['default_vat_rate']) && $company['default_vat_rate'] !== null)
      ? (float)$company['default_vat_rate'] : (float)$settings['default_vat_rate'];
    $eff_scheme_default = $company['default_tax_scheme'] ?? ($settings['default_tax_scheme'] ?? 'standard');

    // --- PROPERTIES VORAB SAMMELN (damit wir hasNonStandard vor dem Rechnung-Insert kennen) ---
    $propsByTask    = []; // task_id => [description, rate, vat, scheme]
    $hasNonStandard = false;

    foreach ($itemsForm as $row) {
      $tid = (int)($row['task_id'] ?? 0);
      if (!$tid) continue;

      $desc = trim((string)($row['description'] ?? ''));
      $rate = ($row['hourly_rate'] !== '' && $row['hourly_rate'] !== null)
                ? (float)str_replace(',', '.', (string)$row['hourly_rate']) : 0.0;

      $scheme = $row['tax_scheme'] ?? $eff_scheme_default;

      $vat_input = $row['vat_rate'] ?? ($row['tax_rate'] ?? '');
      if ($vat_input !== '' && $vat_input !== null) {
        $vat = (float)str_replace(',', '.', (string)$vat_input);
      } else {
        $vat = ($scheme === 'standard') ? $eff_vat_default : 0.0;
      }
      $vat = max(0.0, min(100.0, (float)$vat));
      if ($scheme !== 'standard') $hasNonStandard = true;

      $propsByTask[$tid] = [
        'description' => $desc,
        'rate'        => (float)$rate,
        'vat'         => (float)$vat,
        'scheme'      => $scheme,
      ];
    }

    // Zusatzpositionen bzgl. Steuer prüfen
    if (is_array($extras)) {
      foreach ($extras as $e) {
        $descEx = trim($e['description'] ?? '');
        if ($descEx === '') continue;
        $schemeEx = $e['tax_scheme'] ?? $eff_scheme_default;
        $vatEx    = ($schemeEx === 'standard')
          ? (float)dec($e['vat_rate'] ?? $eff_vat_default)
          : 0.0;
        if ($schemeEx !== 'standard' || $vatEx <= 0.0) {
          $hasNonStandard = true;
          break;
        }
      }
    }

    // Pflichtprüfung: Begründung erforderlich
    if ($hasNonStandard && $tax_reason === '') {
      $err = 'Bitte Begründung für die Steuerbefreiung angeben.';
    }
  }

  if (!$err) {
    $pdo->beginTransaction();
    try {
      // 1) Rechnung anlegen (tax_exemption_reason jetzt korrekt bekannt)
      $insInv = $pdo->prepare("
        INSERT INTO invoices (
          account_id, company_id, status, issue_date, due_date,
          total_net, total_gross, tax_exemption_reason
        ) VALUES (?,?,?,?,?,?,?,?)
      ");
      $insInv->execute([
        $account_id, $company_id, 'in_vorbereitung', $issue_date, $due_date,
        0.00, 0.00, ($hasNonStandard ? $tax_reason : ''),
      ]);
      $invoice_id = (int)$pdo->lastInsertId();

      // 2) Statements für Positionen/Links/Times
      $insItem = $pdo->prepare("
        INSERT INTO invoice_items
          (account_id, invoice_id, project_id, task_id, description, quantity, unit_price, vat_rate, total_net, total_gross, position, tax_scheme, entry_mode)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      $insLink = $pdo->prepare("INSERT INTO invoice_item_times (account_id, invoice_item_id, time_id) VALUES (?,?,?)");
      $setTimeStatus = $pdo->prepare("UPDATE times SET status='in_abrechnung' WHERE account_id=? AND id=?");
      $getTaskProject = $pdo->prepare("SELECT project_id FROM tasks WHERE account_id=? AND id=?");

      $pos = 1; $sum_net = 0.00; $sum_gross = 0.00;

      // 2a) Normale Erstellung – pro Task explizit ausgewählte Zeiten
      foreach ($timesByTask as $task_id => $picked) {
        $task_id = (int)$task_id;
        $picked  = array_values(array_filter(array_map('intval', (array)$picked), fn($v)=>$v>0));
        if (!$picked) continue;

        $p      = $propsByTask[$task_id] ?? ['description'=>'','rate'=>0.0,'vat'=>$DEFAULT_TAX,'scheme'=>$DEFAULT_SCHEME];
        $desc   = $p['description'];
        $rate   = (float)$p['rate'];
        $vat    = (float)$p['vat'];
        $scheme = $p['scheme'];

        // Projekt
        $getTaskProject->execute([$account_id, $task_id]);
        $project_id = ($pid = $getTaskProject->fetchColumn()) ? (int)$pid : null;

        // Mengen & Summen
        $minutes = sum_minutes_for_times($pdo, $account_id, $picked);
        $qty     = round($minutes / 60, 3);             // DECIMAL(10,3)
        $net     = round(($minutes / 60) * $rate, 2);   // nicht aus gerundetem qty
        $gross   = round($net * (1 + $vat/100), 2);

        // Position
        $insItem->execute([
          $account_id, $invoice_id, $project_id, $task_id, $desc,
          $qty, $rate, $vat, $net, $gross, $pos++, $scheme, "auto"
        ]);
        $item_id = (int)$pdo->lastInsertId();

        // Zeiten verknüpfen + Status
        foreach ($picked as $time_id) {
          $insLink->execute([$account_id, $item_id, (int)$time_id]);
          $setTimeStatus->execute([$account_id, (int)$time_id]);
        }

        $sum_net += $net; $sum_gross += $gross;
      }

      // 2b) Fallback – keine expliziten time_ids, aber Items vorhanden -> alle offenen billable Zeiten je Task
      if ($sum_net == 0.0 && $sum_gross == 0.0 && !empty($propsByTask)) {
        $getOpenTimes = $pdo->prepare("
          SELECT t.id
            FROM times t
            JOIN tasks ta
            ON ta.id = t.task_id AND ta.account_id = t.account_id
            WHERE t.account_id=? AND t.task_id=?
            AND t.status='offen'
            AND t.billable=1
            AND ta.billable=1
            AND t.minutes IS NOT NULL
        ");
        foreach ($propsByTask as $task_id => $p) {
          $getOpenTimes->execute([$account_id, (int)$task_id]);
          $picked = array_map(fn($r)=>(int)$r['id'], $getOpenTimes->fetchAll());
          if (!$picked) continue;

          $desc   = $p['description'];
          $rate   = (float)$p['rate'];
          $vat    = (float)$p['vat'];
          $scheme = $p['scheme'] ?? $DEFAULT_SCHEME;

          $getTaskProject->execute([$account_id, (int)$task_id]);
          $project_id = ($pid = $getTaskProject->fetchColumn()) ? (int)$pid : null;

          $minutes = sum_minutes_for_times($pdo, $account_id, $picked);
          $qty     = round($minutes / 60, 3);
          $net     = round(($minutes / 60) * $rate, 2);
          $gross   = round($net * (1 + $vat/100), 2);

          $insItem->execute([
            $account_id, $invoice_id, $project_id, (int)$task_id, $desc,
            $qty, $rate, $vat, $net, $gross, $pos++, $scheme,"auto"
          ]);
          $item_id = (int)$pdo->lastInsertId();

          foreach ($picked as $time_id) {
            $insLink->execute([$account_id, $item_id, (int)$time_id]);
            $setTimeStatus->execute([$account_id, (int)$time_id]);
          }

          $sum_net += $net; $sum_gross += $gross;
        }
      }

      // 2c) Zusatzpositionen (wie in edit.php)
      if (!empty($extras) && is_array($extras)) {
        foreach ($extras as $e) {
          $desc = trim($e['description'] ?? '');
          if ($desc === '') continue;

          $entry_mode = ($e['mode'] ?? 'qty') === 'time' ? 'time' : 'qty';
          if ($entry_mode === 'time') {
            $qty_hours = parse_hours_to_decimal($e['hours'] ?? '0'); // "1.5" oder "01:30"
            $price     = dec($e['hourly_rate'] ?? 0);
          } else {
            $qty_hours = dec($e['quantity'] ?? 0);
            $price     = dec($e['unit_price'] ?? 0);
          }


          $scheme = $e['tax_scheme'] ?? $DEFAULT_SCHEME;
          $vat    = ($scheme === 'standard') ? dec($e['vat_rate'] ?? $DEFAULT_TAX) : 0.0;
          $vat    = max(0.0, min(100.0, (float)$vat));

          $qty   = round($qty_hours, 3);
          $net   = round($qty_hours * $price, 2);
          $gross = round($net * (1 + $vat/100), 2);

          // komplett leere Zeile ignorieren
          if ($desc === '' && $qty <= 0 && $price <= 0) continue;

          $insItem->execute([
            $account_id, $invoice_id, null, null, $desc,
            $qty, $price, $vat, $net, $gross, $pos++, $scheme, $entry_mode
          ]);

          $sum_net  += $net;
          $sum_gross+= $gross;
        }
      }

      // 3) Summen aktualisieren
      $updInv = $pdo->prepare("UPDATE invoices SET total_net=?, total_gross=? WHERE id=? AND account_id=?");
      $updInv->execute([$sum_net, $sum_gross, $invoice_id, $account_id]);

      $pdo->commit();

      // zurück zur Firma
      redirect(url('/companies/show.php').'?id='.$company_id);
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = 'Rechnung konnte nicht angelegt werden. ('.$e->getMessage().')';
    }
  }
}

// ---------- View ----------
require __DIR__ . '/../../src/layout/header.php';
// return_to
$return_to = pick_return_to('/companies/show.php?id='.$company_id);

?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Neue Rechnung</h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?php echo h(url($return_to)) ?>">Zurück</a>
  </div>
</div>

<?php if (!empty($err)): ?>
  <div class="alert alert-danger"><?php echo h($err) ?></div>
<?php endif; ?>

<?php if ($company_id): ?>
<form method="post" id="invForm" action="<?php echo hurl(url('/invoices/new.php')) ?>">
  <?php echo csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="company_id" value="<?php echo (int)$company_id ?>">
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
          <input type="date" name="due_date" class="form-control" value="<?php echo h($_POST['due_date'] ?? $due_default) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3" id="tax-exemption-reason-wrap" style="display:none">
    <div class="card-body">
      <label class="form-label">Begründung für die Steuerbefreiung</label>
      <textarea
        class="form-control"
        id="tax-exemption-reason"
        name="tax_exemption_reason"
        rows="2"
        placeholder="z. B. § 19 UStG (Kleinunternehmer) / Reverse-Charge nach § 13b UStG / Art. 196 MwStSystRL"></textarea>
      <div class="form-text">
        Wird nur benötigt, wenn mindestens eine Position steuerfrei oder Reverse-Charge ist.
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title">Offene Zeiten / Aufgaben der Firma</h5>
      <?php if (!$groups): ?>
        <div class="text-muted">Keine offenen, fakturierbaren Zeiten gefunden.</div>
      <?php else: ?>
        <?php
          $mode = 'new';
          $rowName   = 'tasks';
          $timesName = 'time_ids';
          require __DIR__ . '/_items_table.php';
        ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Zusätzliche Positionen (ohne Times) -->
  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title d-flex justify-content-between align-items-center">
        <span>Zusätzliche Positionen hinzufügen</span>
        <button class="btn btn-sm btn-outline-primary" type="button" id="addExtra">+ Position</button>
      </h5>

      <div id="extraBox"></div>

      <template id="extraTpl">
        <div class="row g-2 align-items-start mb-2 extra-row">
          <!-- 1) Beschreibung -->
          <div class="col-12 col-md-4">
            <label class="form-label">Beschreibung</label>
            <input type="text" class="form-control" name="__NAME__[description]" placeholder="z. B. Lizenzkosten">
            <input type="hidden" name="__NAME__[mode]" value="qty" class="extra-mode">
          </div>

          <!-- 2) Art + Eingaben (Zeit/Stundensatz ODER Menge/Einzelpreis) -->
          <div class="col-12 col-md-4">
            <label class="form-label d-block">Art</label>
            <div class="btn-group w-100" role="group" aria-label="Modus">
              <button type="button" class="btn btn-outline-secondary btn-sm extra-switch" data-mode="time">Zeit</button>
              <button type="button" class="btn btn-outline-secondary btn-sm extra-switch active" data-mode="qty">Menge</button>
            </div>

            <!-- Zeit/Stundensatz -->
            <div class="row g-2 mt-1 extra-time d-none">
              <div class="col-6">
                <label class="form-label">Zeit (Std. oder hh:mm)</label>
                <input type="text" class="form-control extra-hours" name="__NAME__[hours]" placeholder="z. B. 1.5 oder 01:30">
              </div>
              <div class="col-6">
                <label class="form-label">Stundensatz (€)</label>
                <input type="number" step="0.01" class="form-control extra-rate" name="__NAME__[hourly_rate]" value="0.00">
              </div>
            </div>

            <!-- Menge/Einzelpreis -->
            <div class="row g-2 mt-1 extra-qty">
              <div class="col-6">
                <label class="form-label">Menge</label>
                <input type="number" step="0.25" class="form-control extra-quantity" name="__NAME__[quantity]" value="1.000">
              </div>
              <div class="col-6">
                <label class="form-label">Einzelpreis (€)</label>
                <input type="number" step="0.01" class="form-control extra-unit" name="__NAME__[unit_price]" value="0.00">
              </div>
            </div>
          </div>

          <!-- 3) Steuerart + MwSt -->
          <div class="col-12 col-md-2">
            <div class="mb-2">
              <label class="form-label">Steuerart</label>
              <select class="form-select inv-tax-sel extra-scheme"
                      name="__NAME__[tax_scheme]"
                      data-rate-standard="<?= h(number_format((float)$settings['default_vat_rate'], 2, '.', '')) ?>">
                <option value="standard" selected>standard (mit MwSt)</option>
                <option value="tax_exempt">steuerfrei</option>
                <option value="reverse_charge">Reverse-Charge</option>
              </select>
            </div>
            <div>
              <label class="form-label">MwSt %</label>
              <input type="number" step="0.01" class="form-control extra-vat" name="__NAME__[vat_rate]"
                    value="<?= h(number_format((float)$settings['default_vat_rate'], 2, '.', '')) ?>">
            </div>
          </div>

          <!-- 4) Aside: Netto / Brutto / Aktion (rechts untereinander) -->
          <div class="col-12 col-md-2">
            <div class="d-grid gap-2">
              <div>
                <label class="form-label">Netto (€)</label>
                <input type="text" class="form-control extra-net" value="0,00" readonly>
              </div>
              <div>
                <label class="form-label">Brutto (€)</label>
                <input type="text" class="form-control extra-gross" value="0,00" readonly>
              </div>
              <button type="button" class="btn btn-outline-danger removeExtra">
                <i class="bi bi-trash"></i>
                <span class="visually-hidden">Löschen</span>
              </button>
            </div>
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
// Zusatzpositionen (Toggle + Live-Berechnung)
(function(){
  const box = document.getElementById('extraBox');
  const tpl = document.getElementById('extraTpl');
  const add = document.getElementById('addExtra');
  if (!box || !tpl || !add) return;

  let idx = 0;

  function toFloat(x){
    if (typeof x !== 'string') x = String(x ?? '');
    x = x.replace(/\u00A0/g, '').replace(/\s+/g, '').replace(',', '.');
    const n = parseFloat(x);
    return isFinite(n) ? n : 0;
  }
  function fmtMoney(n){ return (n||0).toFixed(2).replace('.', ','); }
  function parseHours(v){
    v = String(v||'').trim();
    if (v.includes(':')) {
      const [h, m='0'] = v.split(':', 2);
      return (parseInt(h||'0',10)||0) + (parseInt(m||'0',10)||0)/60;
    }
    return toFloat(v);
  }

  function recalc(row){
    const mode = row.querySelector('.extra-mode')?.value || 'qty';
    const vat  = Math.max(0, Math.min(100, toFloat(row.querySelector('.extra-vat')?.value || '0')));
    let net = 0;

    if (mode === 'time') {
      const hrs  = Math.max(0, parseHours(row.querySelector('.extra-hours')?.value || '0'));
      const rate = Math.max(0, toFloat(row.querySelector('.extra-rate')?.value || '0'));
      net = hrs * rate; // ungerundet
    } else {
      const qty = Math.max(0, toFloat(row.querySelector('.extra-quantity')?.value || '0'));
      const up  = Math.max(0, toFloat(row.querySelector('.extra-unit')?.value || '0'));
      net = qty * up;
    }
    net = Math.round(net * 100) / 100;
    const gross = Math.round(net * (1 + vat/100) * 100) / 100;

    row.querySelector('.extra-net').value   = fmtMoney(net);
    row.querySelector('.extra-gross').value = fmtMoney(gross);
  }

  function setMode(row, mode){
    row.querySelector('.extra-mode').value = mode;
    row.querySelectorAll('.extra-switch').forEach(b=> b.classList.toggle('active', b.dataset.mode===mode));
    row.querySelectorAll('.extra-time').forEach(el => el.classList.toggle('d-none', mode!=='time'));
    row.querySelectorAll('.extra-qty') .forEach(el => el.classList.toggle('d-none', mode!=='qty'));
    recalc(row);
  }

  function attach(row){
    // Toggle
    row.querySelectorAll('.extra-switch').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        setMode(row, btn.dataset.mode);
        updateTaxReasonVisibility?.();
      });
    });

    // Steuer-Select
    const schemeSel = row.querySelector('.extra-scheme');
    const vatInput  = row.querySelector('.extra-vat');
    if (schemeSel && vatInput) {
      schemeSel.addEventListener('change', ()=>{
        if (schemeSel.value && schemeSel.value !== 'standard') {
          vatInput.value = '0.00';
        } else {
          const def = schemeSel.dataset.rateStandard || '19.00';
          if (!vatInput.value || toFloat(vatInput.value) === 0) vatInput.value = def;
        }
        recalc(row);
        updateTaxReasonVisibility?.();
      });
    }

    // Eingaben
    row.querySelectorAll('input').forEach(inp=>{
      inp.addEventListener('input', ()=> recalc(row));
    });

    // Entfernen
    row.querySelector('.removeExtra')?.addEventListener('click', ()=>{
      row.remove();
      updateTaxReasonVisibility?.();
    });

    // Default: Menge/Einzelpreis
    setMode(row, 'qty');
  }

  function addRow(){
    const html = tpl.innerHTML.replaceAll('__NAME__', 'extras[' + (idx++) + ']')
    const frag = document.createElement('div');
    frag.innerHTML = html;
    const row = frag.firstElementChild;
    box.appendChild(row);
    attach(row);
  }

  add.addEventListener('click', addRow);
})();

// Begründungsfeld dynamisch ein-/ausblenden + required setzen
(function(){
  const wrap = document.getElementById('tax-exemption-reason-wrap');
  const area = document.getElementById('tax-exemption-reason');
  if (!wrap) return;

  function hasNonStandard(){
    let any = false;
    document.querySelectorAll('.inv-tax-sel').forEach(sel=>{
      if (sel.value && sel.value !== 'standard') any = true;
    });
    return any;
  }
  function update(){
    const show = hasNonStandard();
    wrap.style.display = show ? '' : 'none';
    if (area) area.required = !!show;
    if (!show && area) area.value = '';
  }

  document.addEventListener('change', (e)=>{
    const t = e.target;
    if (t && t.classList && t.classList.contains('inv-tax-sel')) update();
  });

  // auch beim Laden prüfen
  update();

  // Globale Helfer-Funktion, damit andere Blöcke aufrufen können
  window.updateTaxReasonVisibility = update;
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>