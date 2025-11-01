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
$show_tax_reason = false; // für UI

if ($company_id) {
  $cchk = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
  $cchk->execute([$company_id, $account_id]);
  $company = $cchk->fetch();
  if (!$company) {
    $err = 'Ungültige Firma.'; $company_id = 0;
  }
}

// Default-Stundensatz für neu hinzugefügte manuelle Positionen
$default_manual_rate = 0.00;

// 1) Firmen-Default
if (!empty($company)) {
  $default_manual_rate = (float)($company['hourly_rate'] ?? 0.0);
}

// 2) Falls 0: nimm den ersten verfügbaren effektiven Satz aus den offenen Zeiten
if ($default_manual_rate <= 0 && !empty($groups) && !empty($groups[0]['rows'])) {
  foreach ($groups as $g) {
    foreach ($g['rows'] as $r) {
      $er = (float)($r['hourly_rate'] ?? 0);
      if ($er > 0) { $default_manual_rate = $er; break 2; }
    }
  }
}

// --- Preview der fälligen wiederkehrenden Positionen
$issue_for_preview = $_POST['issue_date'] ?? $issue_default;
$recurring_preview = [];
if ($company_id) {
  $recurring_preview = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_for_preview);
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
  $ri_selected_keys = array_keys($_POST['ri_pick'] ?? []);

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
    // Recurring-Vorschau für das tatsächlich gepostete Datum
    $due_all = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_date);
    if (ri_selected_has_nonstandard($due_all, $ri_selected_keys)) $hasNonStandard = true;

    if ($hasNonStandard && $tax_reason === '') {
      $err = 'Bitte Begründung für die Steuerbefreiung angeben.';
    }
  }

  // für UI beim Re-Rendern
  $show_tax_reason = $hasNonStandard || ($tax_reason !== '');

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

        $task_id  = (int)($row['task_id'] ?? 0);
        $time_ids = array_values(array_filter(array_map('intval', (array)($row['time_ids'] ?? [])), fn($v)=>$v>0));

        $net = 0.0; $gross = 0.0;

        if ($time_ids) {
          // AUTO (aus Zeiten)
          $minutes = sum_minutes_for_times($pdo, $account_id, $time_ids);
          if ($minutes <= 0) { continue; }

          $qty   = round($minutes / 60.0, 3);
          $net   = round($qty * $rate, 2);
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

          if ($desc === '' && $qty_hours <= 0 && $rate <= 0) { continue; }

          $qty   = round($qty_hours, 3);
          $net   = round($qty * $rate, 2);
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
      if ($ri_selected_keys) {
        list($ri_net, $ri_gross, $pos) = ri_attach_due_items(
          $pdo, $account_id, $company_id, $invoice_id, $issue_date, $pos, $ri_selected_keys
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
  } else {
    // Bei Fehler: Preview für das gepostete Datum anzeigen
    $recurring_preview = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_date);
  }
}

// ---------- View ----------
require __DIR__ . '/../../src/layout/header.php';
$return_to = pick_return_to('/companies/show.php?id='.$company_id);

// Für das UI: welche Recurring-Keys waren angehakt?
$ri_selected_keys_post = array_keys($_POST['ri_pick'] ?? []);
$ri_selected_set = array_flip($ri_selected_keys_post);
$tax_reason_value = isset($_POST['tax_exemption_reason']) ? (string)$_POST['tax_exemption_reason'] : '';
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

  <!-- Steuerbefreiung-Begründung -->
  <div class="card mb-3" id="tax-exemption-reason-wrap" style="<?php echo $show_tax_reason || ($tax_reason_value !== '') ? '' : 'display:none' ?>">
    <div class="card-body">
      <label class="form-label">Begründung für die Steuerbefreiung</label>
      <textarea
        class="form-control"
        id="tax-exemption-reason"
        name="tax_exemption_reason"
        rows="2"
        placeholder="z. B. § 19 UStG (Kleinunternehmer) / Reverse-Charge nach § 13b UStG / Art. 196 MwStSystRL"><?php echo h($tax_reason_value) ?></textarea>
      <div class="form-text">
        Wird benötigt, wenn mindestens eine Position steuerfrei oder Reverse-Charge ist, oder der MwSt-Satz 0,00 % beträgt.
      </div>
    </div>
  </div>

  <!-- Fällige wiederkehrende Positionen -->
  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title">Fällige wiederkehrende Positionen</h5>
      <?php if (!$recurring_preview): ?>
        <div class="text-muted">Für das Datum <?php echo h($issue_for_preview) ?> sind keine wiederkehrenden Positionen fällig.</div>
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
              <?php foreach ($recurring_preview as $r):
                $qty   = (float)$r['quantity'];
                $price = (float)$r['unit_price'];
                $scheme= (string)$r['tax_scheme'];
                $vat   = ($scheme === 'standard') ? (float)$r['vat_rate'] : 0.0;
                $net   = round($qty * $price, 2);
                $gross = round($net * (1 + $vat/100), 2);
                $checked = empty($_POST) ? true : isset($ri_selected_set[$r['key']]);
              ?>
                <tr class="ri-row" data-scheme="<?php echo h($scheme) ?>" data-vat="<?php echo h(number_format($vat,2,'.','')) ?>">
                  <td>
                    <input type="checkbox" class="form-check-input"
                           name="ri_pick[<?php echo h($r['key']) ?>]" value="1" <?php echo $checked ? 'checked' : '' ?>>
                  </td>
                  <td><?php echo h($r['description']) ?></td>
                  <td class="text-end"><?php echo h(_fmt_qty($qty)) ?></td>
                  <td class="text-end"><?php echo h(number_format($price,2,',','.')) ?></td>
                  <td class="text-end"><?php echo h($scheme) ?></td>
                  <td class="text-end"><?php echo h(number_format($vat,2,',','.')) ?></td>
                  <td class="text-end"><?php echo h(number_format($net,2,',','.')) ?></td>
                  <td class="text-end"><?php echo h(number_format($gross,2,',','.')) ?></td>
                  <td><?php echo h(_fmt_dmy($r['from'])) ?> – <?php echo h(_fmt_dmy($r['to'])) ?></td>
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
      <button
        type="button"
        id="addManualItem"
        class="btn btn-sm btn-outline-primary"
        data-default-vat="<?php echo h(number_format((float)$settings['default_vat_rate'],2,'.','')) ?>"
        data-default-rate="<?php echo h(number_format((float)$default_manual_rate,2,'.','')) ?>">+ Position</button>

    </div>
  </div>

  <div class="d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="<?php echo h(url($return_to)) ?>">Abbrechen</a>
    <button class="btn btn-primary" name="action" value="save">Rechnung anlegen</button>
  </div>
</form>

<script>
// Manuelle Zeilen (qty|time) mit ICON-Toggle in der input-group
(function(){
  const addBtn = document.getElementById('addManualItem');
  const root   = document.getElementById('invoice-items');
  if (!root) return;

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

  function setBtnActive(btn, active){
    btn.classList.toggle('btn-secondary', active);
    btn.classList.toggle('text-white', active);
    btn.classList.toggle('btn-outline-secondary', !active);
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
  }

  function switchRowMode(tr, mode){
    // helpers für optionale Wertübernahme beim Umschalten
    function decToHHMM(val){
      var n = parseFloat(String(val||'').replace(',','.'));
      if (!isFinite(n)) n = 0;
      var h = Math.floor(Math.max(0,n));
      var m = Math.round((n - h) * 60);
      if (m === 60) { h += 1; m = 0; }
      return (h<10?'0':'')+h+':' + (m<10?'0':'')+m;
    }
    function hhmmToDec(v){
      v = String(v||'').trim();
      if (v.includes(':')) {
        var p = v.split(':'); var h = parseInt(p[0]||'0',10)||0; var m = parseInt(p[1]||'0',10)||0;
        return (h + m/60).toFixed(3);
      }
      var n = parseFloat(v.replace(',','.')); return isFinite(n) ? n.toFixed(3) : '0.000';
    }

    const entry = tr.querySelector('input.entry-mode') || tr.appendChild(Object.assign(document.createElement('input'), {type:'hidden', className:'entry-mode', value:'qty'}));
    entry.value = mode;
    tr.setAttribute('data-mode', mode);

    const qty   = tr.querySelector('input.quantity');
    const hours = tr.querySelector('input.hours-input');

    if (mode === 'time') {
      // ggf. Menge → HH:MM übernehmen, wenn noch leer
      if (hours && qty && (!hours.value || hours.value === '00:00')) {
        hours.value = decToHHMM(qty.value || '0');
      }
      if (qty)   { qty.classList.add('d-none'); qty.disabled = true; }
      if (hours) { hours.classList.remove('d-none'); hours.disabled = false; }
    } else {
      // ggf. HH:MM → Menge übernehmen, wenn Menge leer ist
      if (qty && hours && (!qty.value || qty.value === '0' || qty.value === '0.000')) {
        qty.value = hhmmToDec(hours.value || '0');
      }
      if (qty)   { qty.classList.remove('d-none'); qty.disabled = false; }
      if (hours) { hours.classList.add('d-none');  hours.disabled = true; }
    }

    tr.querySelectorAll('.mode-btn').forEach(b=>{
      b.classList.toggle('btn-secondary', b.dataset.mode === mode);
      b.classList.toggle('text-white',   b.dataset.mode === mode);
      b.classList.toggle('btn-outline-secondary', b.dataset.mode !== mode);
      b.setAttribute('aria-pressed', b.dataset.mode === mode ? 'true' : 'false');
    });

    // WICHTIG: Neuberechnung triggern (delegierter 'input'-Listener im zweiten Script ruft recalcRow)
    const activeInput = (mode === 'time') ? hours : qty;
    if (activeInput) {
      activeInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }


  // Baut (falls nötig) die input-group mit Icon-Toggle um vorhandene Felder
  function ensureIconToggle(tr){
    // Toggle nur für nicht-auto Zeilen
    if (tr.getAttribute('data-mode') === 'auto') return;

    // schon vorhanden?
    if (tr.querySelector('.mode-btn')) return;

    const qtyCell = tr.querySelector('td:nth-child(3)');
    if (!qtyCell) return;

    let qty   = tr.querySelector('input.quantity');
    let hours = tr.querySelector('input.hours-input');

    if (!qty) {
      qty = document.createElement('input');
      qty.type = 'number';
      qty.className = 'form-control text-end quantity no-spin';
      qty.name = 'items['+(tr.getAttribute('data-row')||'')+'][quantity]';
      qty.value = '1';
    }
    if (!hours) {
      hours = document.createElement('input');
      hours.type = 'text';
      hours.className = 'form-control text-end hours-input d-none';
      hours.name = 'items['+(tr.getAttribute('data-row')||'')+'][hours]';
      hours.placeholder = 'hh:mm oder 1.5';
      hours.disabled = true;
    }

    const group = document.createElement('div');
    group.className = 'input-group input-group-sm flex-nowrap';

    const btnQty  = document.createElement('button');
    btnQty.type   = 'button';
    btnQty.className = 'btn btn-outline-secondary mode-btn no-spin';
    btnQty.dataset.mode = 'qty';
    btnQty.title  = 'Menge';
    btnQty.innerHTML = '<i class="bi bi-123" aria-hidden="true"></i><span class="visually-hidden">Menge</span>';

    const btnTime = document.createElement('button');
    btnTime.type  = 'button';
    btnTime.className = 'btn btn-outline-secondary mode-btn';
    btnTime.dataset.mode = 'time';
    btnTime.title = 'Zeit';
    btnTime.innerHTML = '<i class="bi bi-clock" aria-hidden="true"></i><span class="visually-hidden">Zeit</span>';

    group.appendChild(btnQty);
    group.appendChild(btnTime);
    group.appendChild(qty);
    group.appendChild(hours);

    qtyCell.innerHTML = '';
    qtyCell.appendChild(group);
  }

  function initRow(tr){
    // Toggle-UI sicherstellen (auch in edit.php für bestehende Zeilen)
    ensureIconToggle(tr);

    // Ausgangsmodus bestimmen
    const attr  = tr.getAttribute('data-mode');
    const entry = tr.querySelector('input.entry-mode')?.value;
    const mode  = (attr || entry || 'qty');
    switchRowMode(tr, mode);
  }

  // Delegierte Clicks (Buttons & Keyboard)
  root.addEventListener('click', function(e){
    const btn = e.target.closest('.mode-btn');
    if (!btn) return;
    const tr = btn.closest('tr.inv-item-row');
    if (!tr) return;
    switchRowMode(tr, btn.dataset.mode);
  });
  root.addEventListener('keydown', function(e){
    const btn = e.target.closest('.mode-btn');
    if (!btn) return;
    if (e.key === ' ' || e.key === 'Enter') {
      e.preventDefault();
      btn.click();
    }
  });

  // "+ Position" (neue manuelle Zeile mit Icon-Toggle)
  if (addBtn) {
    addBtn.addEventListener('click', function(){
      const idx    = nextIndex();
      const defVat = addBtn.getAttribute('data-default-vat') || '19.00';
      const defRate = addBtn.getAttribute('data-default-rate') || '0.00';

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
           <div class="input-group input-group-sm flex-nowrap">
             <button type="button" class="btn btn-outline-secondary mode-btn" data-mode="qty" title="Menge" aria-pressed="true">
               <i class="bi bi-123" aria-hidden="true"></i><span class="visually-hidden">Menge</span>
             </button>
             <button type="button" class="btn btn-outline-secondary mode-btn" data-mode="time" title="Zeit" aria-pressed="false">
               <i class="bi bi-clock" aria-hidden="true"></i><span class="visually-hidden">Zeit</span>
             </button>

             <input type="number"
                    class="form-control text-end quantity no-spin"
                    name="items[${idx}][quantity]" value="1">
             <input type="text"
                    class="form-control text-end hours-input d-none"
                    name="items[${idx}][hours]" placeholder="hh:mm oder 1.5" disabled>
           </div>
         </td>

         <td class="text-end">
           <input type="number" class="form-control text-end rate no-spin"
                  name="items[${idx}][hourly_rate]"  value="${defRate}">
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
           <input type="number" min="0" max="100" class="form-control text-end inv-vat-input no-spin"
                  name="items[${idx}][vat_rate]" value="${defVat}">
         </td>

         <td class="text-end"><span class="net">0,00</span></td>
         <td class="text-end"><span class="gross">0,00</span></td>

         <td class="text-end text-nowrap">
           <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
         </td>`;

      insertBeforeGrand(tr);
      // Aktiv-Optik setzen
      const btnQty = tr.querySelector('.mode-btn[data-mode="qty"]');
      const btnTim = tr.querySelector('.mode-btn[data-mode="time"]');
      setBtnActive(btnQty, true);
      setBtnActive(btnTim, false);
      switchRowMode(tr, 'qty');
    });
  }

  // Bestehende Zeilen (v.a. in edit.php) initialisieren und ggf. upgraden
  document.querySelectorAll('#invoice-items tr.inv-item-row').forEach(initRow);
})();
</script>
<script>

// --- Clientseitige Pflichtprüfung Steuerbefreiungshinweis ---
(function(){
  const form = document.getElementById('invForm');
  if (!form) return;

  const wrap = document.getElementById('tax-exemption-reason-wrap');
  const reason = document.getElementById('tax-exemption-reason');

  function requiresTaxReason(){
    let need = false;

    // Manuelle/Zeiten-Positionen
    document.querySelectorAll('#invoice-items tr.inv-item-row').forEach(function(row){
      const sel = row.querySelector('select[name*="[tax_scheme]"]');
      const vatEl = row.querySelector('input[name*="[vat_rate]"]');
      const scheme = sel ? sel.value : 'standard';
      const vat = vatEl ? parseFloat(vatEl.value || '0') : 0;
      if (scheme !== 'standard' || vat <= 0) need = true;
    });

    // Recurring rows (nur angehakte)
    document.querySelectorAll('tr.ri-row input[type="checkbox"]').forEach(function(cb){
      if (!cb.checked) return;
      const tr = cb.closest('tr');
      if (!tr) return;
      const scheme = (tr.dataset.scheme || 'standard').toLowerCase();
      const vat = parseFloat(tr.dataset.vat || '0');
      if (scheme !== 'standard' || vat <= 0) need = true;
    });

    return need;
  }

  function setReasonVisible(v){
    if (!wrap) return;
    wrap.style.display = v ? '' : 'none';
  }

  function enforce(){
    const need = requiresTaxReason();
    setReasonVisible(need || (reason && reason.value.trim() !== ''));
    return need;
  }

  // Bind changes on tax fields and recurring checkboxes
  function bindTaxListeners(scope){
    const root = scope || document;
    root.querySelectorAll('.inv-tax-sel, .inv-vat-input').forEach(function(el){
      el.addEventListener('change', enforce);
      el.addEventListener('input', enforce);
    });
    root.querySelectorAll('tr.ri-row input[type="checkbox"]').forEach(function(cb){
      cb.addEventListener('change', enforce);
    });
  }

  bindTaxListeners(document);

  // Initial state
  enforce();

  form.addEventListener('submit', function(e){
    const need = requiresTaxReason();
    if (need && reason && reason.value.trim() === '') {
      e.preventDefault();
      setReasonVisible(true);
      reason.focus();
      reason.classList.add('is-invalid');
      // kleine optische Hilfe
      if (!reason.nextElementSibling || !reason.nextElementSibling.classList.contains('invalid-feedback')) {
        const fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        fb.textContent = 'Bitte eine Begründung für die Steuerbefreiung angeben.';
        reason.insertAdjacentElement('afterend', fb);
      }
      return false;
    }
    // Cleanup beim erfolgreichen Submit
    if (reason) {
      reason.classList.remove('is-invalid');
      const fb = reason.nextElementSibling;
      if (fb && fb.classList.contains('invalid-feedback')) fb.remove();
    }
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>