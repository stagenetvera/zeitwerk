<?php
// public/invoices/new.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/utils.php'; // dec(), parse_hours_to_decimal()
require_once __DIR__ . '/../../src/lib/recurring.php';
require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$settings = get_account_settings($pdo, $account_id);

// Defaults
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

// --------- Kontext / Daten laden ----------
$company_id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$return_to = pick_return_to('/companies/show.php?id='.$company_id);

// Offene Zeiten der Firma flach (NEW-Modus)
$q = $pdo->prepare("
  SELECT
    t.id          AS time_id,
    t.minutes,
    t.started_at, t.ended_at,
    ta.id          AS task_id,
    ta.description AS task_desc,
    COALESCE(p.hourly_rate, c.hourly_rate) AS effective_rate
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
  ORDER BY ta.id, t.started_at
");
$q->execute([':acc'=>$account_id, ':cid'=>$company_id]);
$rows = $q->fetchAll();

$groups = [];
if ($rows) {
  $byTask = [];
  foreach ($rows as $r) {
    $tid = (int)$r['task_id'];
    if (!isset($byTask[$tid])) {
      $byTask[$tid] = [
        'task_id'     => $tid,
        'task_desc'   => (string)$r['task_desc'],
        'hourly_rate' => (float)$r['effective_rate'],
        'tax_rate'    => ($DEFAULT_SCHEME === 'standard') ? (float)$settings['default_vat_rate'] : 0.0,
        'times'       => [],
      ];
    }
    $byTask[$tid]['times'][] = [
      'id'         => (int)$r['time_id'],
      'minutes'    => (int)$r['minutes'],
      'started_at' => $r['started_at'],
      'ended_at'   => $r['ended_at'],
    ];
  }
  $groups = [[ 'rows' => array_values($byTask) ]];
}

// Firmenliste
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

// --- Preview der fälligen wiederkehrenden Positionen (zum initialen issue_date)
$recurring_preview = [];
if ($company_id) {
  $recurring_preview = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_default);
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

  $itemsForm = $_POST['items'] ?? []; // flache Items

  // Muss es überhaupt Positionen geben?
  if (!$err) {
    $hasAnyItem = false;
    foreach ((array)$itemsForm as $row) {
      $desc = trim((string)($row['description'] ?? ''));
      $rate = (float)dec($row['hourly_rate'] ?? 0);
      $qty  = (float)dec($row['quantity'] ?? 0);
      $tids = array_values(array_filter(array_map('intval', (array)($row['time_ids'] ?? [])), fn($v)=>$v>0));
      if ($tids || $desc !== '' || ($qty > 0 && $rate >= 0)) { $hasAnyItem = true; break; }
    }
    // auch wenn noch keine manuelle/Zeiten-Position: evtl. recurring wird gewählt
    $ri_selected_keys = array_keys($_POST['ri_pick'] ?? []);
    if (!$hasAnyItem && !$ri_selected_keys) {
      $err = 'Keine Position ausgewählt.';
    }
  }

  // Pflichtprüfung Steuerbegründung, falls Non-Standard in Items oder Recurring-Auswahl
  $hasNonStandard = false;
  if (!$err) {
    foreach ((array)$itemsForm as $row) {
      $scheme = $row['tax_scheme'] ?? $DEFAULT_SCHEME;
      $vat    = ($scheme === 'standard') ? dec($row['vat_rate'] ?? $DEFAULT_TAX) : 0.0;
      if ($scheme !== 'standard' || $vat <= 0.0) { $hasNonStandard = true; break; }
    }
    // Recurring-Vorschau für das tatsächlich gepostete Datum ermitteln
    $due_all = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_date);
    $ri_selected_keys = array_keys($_POST['ri_pick'] ?? []);
    if (ri_selected_has_nonstandard($due_all, $ri_selected_keys)) $hasNonStandard = true;

    if ($hasNonStandard && $tax_reason === '') {
      $err = 'Bitte Begründung für die Steuerbefreiung angeben.';
    }
  }

  if (!$err) {
    $pdo->beginTransaction();
    try {
      // Rechnung anlegen
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

      // Statements
      $insItem = $pdo->prepare("
        INSERT INTO invoice_items
          (account_id, invoice_id, project_id, task_id, description,
           quantity, unit_price, vat_rate, total_net, total_gross, position, tax_scheme, entry_mode)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      $insLink = $pdo->prepare("INSERT INTO invoice_item_times (account_id, invoice_item_id, time_id) VALUES (?,?,?)");
      $setTimeStatus = $pdo->prepare("UPDATE times SET status='in_abrechnung' WHERE account_id=? AND id=?");

      $pos = 1; $sum_net = 0.00; $sum_gross = 0.00;

      // Items aus Formular (Zeiten/Mengen) in Reihenfolge
      foreach ((array)$itemsForm as $row) {
        $desc   = trim((string)($row['description'] ?? ''));
        $mode   = strtolower(trim((string)($row['entry_mode'] ?? 'qty')));
        $rate   = (float)dec($row['hourly_rate'] ?? 0);
        $scheme = $row['tax_scheme'] ?? $DEFAULT_SCHEME;
        $vat    = ($scheme === 'standard') ? (float)dec($row['vat_rate'] ?? $DEFAULT_TAX) : 0.0;
        $vat    = max(0.0, min(100.0, $vat));

        $task_id = (int)($row['task_id'] ?? 0);
        $time_ids = array_values(array_filter(array_map('intval', (array)($row['time_ids'] ?? [])), fn($v)=>$v>0));

        if ($time_ids) {
          // AUTO (aus Zeiten)
          $minutes = sum_minutes_for_times($pdo, $account_id, $time_ids);
          if ($minutes <= 0) continue;
          $qty   = round($minutes / 60.0, 3);
          $net   = round(($minutes / 60.0) * $rate, 2);
          $gross = round($net * (1 + $vat/100), 2);

          $insItem->execute([
            $account_id, $invoice_id, null, ($task_id ?: null), $desc,
            $qty, $rate, $vat, $net, $gross, $pos++, $scheme, 'auto'
          ]);
          $item_id = (int)$pdo->lastInsertId();

          foreach ($time_ids as $tid) {
            $insLink->execute([$account_id, $item_id, (int)$tid]);
            $setTimeStatus->execute([$account_id, (int)$tid]);
          }
        } else {
          // MANUELL (time|qty)
          if ($mode === 'time') {
            $qty_hours = ($row['quantity'] ?? '') !== ''
              ? (float)dec($row['quantity'])
              : (float)parse_hours_to_decimal($row['hours'] ?? '0');
          } else {
            $mode = 'qty';
            $qty_hours = (float)dec($row['quantity'] ?? 0);
          }
          if ($desc === '' && $qty_hours <= 0 && $rate <= 0) continue;

          $qty   = round($qty_hours, 3);
          $net   = round($qty_hours * $rate, 2);
          $gross = round($net * (1 + $vat/100), 2);

          $insItem->execute([
            $account_id, $invoice_id, null, null, $desc,
            $qty, $rate, $vat, $net, $gross, $pos++, $scheme, $mode
          ]);
        }

        $sum_net  += $net;
        $sum_gross+= $gross;
      }

      // --- Wiederkehrende Positionen (nur die angehakten Keys)
      $ri_selected_keys = array_keys($_POST['ri_pick'] ?? []);
      if ($ri_selected_keys) {
        [$ri_net, $ri_gross, $pos] = ri_attach_selected_runs(
          $pdo, $account_id, $invoice_id, $company_id, $issue_date, $ri_selected_keys, $pos
        );
        $sum_net   += $ri_net;
        $sum_gross += $ri_gross;
      }

      // Summen aktualisieren
      $updInv = $pdo->prepare("UPDATE invoices SET total_net=?, total_gross=? WHERE id=? AND account_id=?");
      $updInv->execute([$sum_net, $sum_gross, $invoice_id, $account_id]);

      $pdo->commit();

      redirect(url('/companies/show.php').'?id='.$company_id);
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = 'Rechnung konnte nicht angelegt werden. ('.$e->getMessage().')';
    }
  }
}

// ---------- View ----------
require __DIR__ . '/../../src/layout/header.php';
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

  <!-- Steuerbefreiung-Begründung (wird evtl. benötigt) -->
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

  <!-- NEU: fällige wiederkehrende Positionen -->
  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title">Fällige wiederkehrende Positionen</h5>
      <?php if (!$recurring_preview): ?>
        <div class="text-muted">Für das Datum <?php echo h($issue_default) ?> sind keine wiederkehrenden Positionen fällig.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th style="width:36px"></th>
                <th>Bezeichnung</th>
                <th class="text-end">Menge</th>
                <th class="text-end">Einzelpreis</th>
                <th class="text-end">Steuerart</th>
                <th class="text-end">MwSt %</th>
                <th class="text-end">Netto</th>
                <th class="text-end">Brutto</th>
                <th>Zeitraum</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recurring_preview as $r): ?>
                <tr>
                  <td>
                    <input type="checkbox" class="form-check-input"
                           name="ri_pick[<?php echo h($r['key']) ?>]" value="1" checked>
                  </td>
                  <td><?php echo h($r['description']) ?></td>
                  <td class="text-end"><?php echo h(number_format((float)$r['quantity'],3,',','.')) ?></td>
                  <td class="text-end"><?php echo h(number_format((float)$r['unit_price'],2,',','.')) ?></td>
                  <td class="text-end"><?php echo h($r['tax_scheme']) ?></td>
                  <td class="text-end"><?php echo h(number_format((float)$r['vat_rate'],2,',','.')) ?></td>
                  <td class="text-end"><?php echo h(number_format((float)$r['total_net'],2,',','.')) ?></td>
                  <td class="text-end"><?php echo h(number_format((float)$r['total_gross'],2,',','.')) ?></td>
                  <td><?php echo h($r['from']) ?> – <?php echo h($r['to']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="form-text mt-2">
          Das Vorschau-Datum ist das Rechnungsdatum. Wenn du das Rechnungsdatum änderst, wird die tatsächliche Auswahl beim Speichern
          automatisch für dieses Datum neu berechnet.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <?php
        $mode = 'new';
        $rowName   = 'items';
        $timesName = 'time_ids';
        require __DIR__ . '/_items_table.php';
      ?>
      <button type="button" id="addManualItem" class="btn btn-sm btn-outline-primary"
                data-default-vat="<?php echo h(number_format((float)$settings['default_vat_rate'],2,'.','')) ?>">
          + Position
        </button>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="<?php echo h(url($return_to)) ?>">Abbrechen</a>
    <button class="btn btn-primary" name="action" value="save">Rechnung anlegen</button>
  </div>
</form>

<script>
// "+ Position": manuelle Zeile (qty) in der flachen Tabelle anlegen
(function(){
  const btn = document.getElementById('addManualItem');
  if (!btn) return;
  const root = document.getElementById('invoice-items');
  function nextIndex(){
    const rows = root.querySelectorAll('tr.inv-item-row');
    let max = 0;
    rows.forEach(r => { const n = parseInt(r.getAttribute('data-row')||'0',10); if(n>max) max=n; });
    return max + 1;
  }
  function insertBeforeGrand(tr){
    const gt = root.querySelector('tr.inv-grand-total');
    const tb = root.querySelector('tbody');
    if (gt && tb) tb.insertBefore(tr, gt);
  }
  btn.addEventListener('click', function(){
    const idx = nextIndex();
    const defVat = btn.getAttribute('data-default-vat') || '19.00';
    const tr = document.createElement('tr');
    tr.className = 'inv-item-row';
    tr.setAttribute('data-row', String(idx));
    tr.setAttribute('data-mode', 'qty');
    tr.setAttribute('aria-expanded','false');
    tr.innerHTML =
      `<td class="text-center">
         <div class="d-flex justify-content-center gap-1">
           <span class="row-reorder-handle" draggable="true" aria-label="Position verschieben" title="Ziehen zum Sortieren">
             <svg class="grip" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
               <path d="M5 4h2v2H5V4Zm4 0h2v2H9V4ZM5 8h2v2H5V8Zm4 0h2v2H9V8ZM5 12h2v2H5v-2Zm4 0h2v2H9v-2Z" fill="currentColor"/>
             </svg>
           </span>
         </div>
       </td>
       <td>
         <input type="hidden" class="entry-mode" name="items[${idx}][entry_mode]" value="qty">
         <input type="text" class="form-control" name="items[${idx}][description]" value="">
       </td>
       <td class="text-end">
         <input type="number" step="0.001" class="form-control text-end quantity" name="items[${idx}][quantity]" value="1.000">
       </td>
       <td class="text-end">
         <input type="number" step="0.01" class="form-control text-end rate" name="items[${idx}][hourly_rate]" value="0.00">
       </td>
       <td class="text-end">
         <select name="items[${idx}][tax_scheme]" class="form-select inv-tax-sel"
                 data-rate-standard="${defVat}" data-rate-tax-exempt="0.00" data-rate-reverse-charge="0.00">
           <option value="standard" selected>standard (mit MwSt)</option>
           <option value="tax_exempt">steuerfrei</option>
           <option value="reverse_charge">Reverse-Charge</option>
         </select>
       </td>
       <td class="text-end">
         <input type="number" step="0.01" min="0" max="100" class="form-control text-end inv-vat-input"
                name="items[${idx}][vat_rate]" value="${defVat}">
       </td>
       <td class="text-end"><span class="net">0,00</span></td>
       <td class="text-end"><span class="gross">0,00</span></td>
       <td class="text-end text-nowrap">
         <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
       </td>`;
    insertBeforeGrand(tr);
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>