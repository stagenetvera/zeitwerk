<?php
// public/invoices/edit.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/invoice_number.php';
require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/utils.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/recurring.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$settings = get_account_settings($pdo, $account_id);

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($v){ return number_format((float)$v, 2, ',', '.'); }

// Minuten über Time-IDs (Account-sicher) summieren
function sum_minutes_for_times_edit(PDO $pdo, int $account_id, array $ids): int {
  $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
  if (!$ids) return 0;
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT COALESCE(SUM(minutes),0) FROM times WHERE account_id=? AND id IN ($in)");
  $st->execute(array_merge([$account_id], $ids));
  return (int)$st->fetchColumn();
}

// ---------- Eingaben ----------
$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($invoice_id <= 0) {
  echo '<div class="alert alert-danger">Ungültige Rechnungs-ID.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}

// ---------- Rechnung laden ----------
$inv = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND account_id = ?");
$inv->execute([$invoice_id, $account_id]);
$invoice = $inv->fetch();
if (!$invoice) {
  echo '<div class="alert alert-danger">Rechnung nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php'; exit;
}
$company_id = (int)$invoice['company_id'];

$return_to = pick_return_to('/companies/show.php?id='.$company_id);

// Firma laden (nur Anzeige)
$cstmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? AND account_id = ?");
$cstmt->execute([$company_id, $account_id]);
$company = $cstmt->fetch();

// ---------- Hilfsfunktionen DB ----------
/** Alle Items der Rechnung */
function load_invoice_items(PDO $pdo, int $account_id, int $invoice_id): array {
  $st = $pdo->prepare("
    SELECT
      ii.id AS item_id,
      ii.project_id,
      ii.task_id,
      ii.description,
      ii.quantity,
      ii.unit_price,
      ii.vat_rate,
      ii.tax_scheme,
      ii.position,
      ii.total_net,
      ii.total_gross,
      ii.entry_mode
    FROM invoice_items ii
    WHERE ii.account_id = ? AND ii.invoice_id = ?
    ORDER BY ii.position ASC, ii.id ASC
  ");
  $st->execute([$account_id, $invoice_id]);
  return $st->fetchAll();
}
/** verlinkte Zeiten je Item */
function load_times_by_item(PDO $pdo, int $account_id, array $itemIds): array {
  if (!$itemIds) return [];
  $in = implode(',', array_fill(0, count($itemIds), '?'));
  $params = array_merge([$account_id], $itemIds);
  $st = $pdo->prepare("
    SELECT iit.invoice_item_id, tm.id AS time_id, tm.started_at, tm.ended_at, tm.minutes
    FROM invoice_item_times iit
    JOIN times tm
      ON tm.id = iit.time_id AND tm.account_id = iit.account_id
    WHERE iit.account_id = ? AND iit.invoice_item_id IN ($in)
    ORDER BY tm.started_at
  ");
  $st->execute($params);
  $out = [];
  foreach ($st->fetchAll() as $r) {
    $iid = (int)$r['invoice_item_id'];
    $out[$iid][] = [
      'id'         => (int)$r['time_id'],
      'started_at' => $r['started_at'],
      'ended_at'   => $r['ended_at'],
      'minutes'    => (int)$r['minutes'],
    ];
  }
  return $out;
}

/** Status der Times für diese Rechnung gesammelt setzen */
function set_times_status_for_invoice(PDO $pdo, int $account_id, int $invoice_id, string $target): void {
  $upd = $pdo->prepare("
    UPDATE times t
    JOIN invoice_item_times iit
      ON iit.time_id = t.id AND iit.account_id = t.account_id
    JOIN invoice_items ii
      ON ii.id = iit.invoice_item_id AND ii.account_id = iit.account_id
    SET t.status = ?
    WHERE t.account_id = ? AND ii.invoice_id = ?
  ");
  $upd->execute([$target, $account_id, $invoice_id]);
}

/** Item inkl. Time-Links löschen + Times ggf. zurücksetzen */
function delete_item_with_times(PDO $pdo, int $account_id, int $invoice_id, int $item_id): void {
  $ts = $pdo->prepare("SELECT time_id FROM invoice_item_times WHERE account_id = ? AND invoice_item_id = ?");
  $ts->execute([$account_id, $item_id]);
  $timeIds = array_map(fn($r)=>(int)$r['time_id'], $ts->fetchAll());

  $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id = ? AND invoice_item_id = ?")
      ->execute([$account_id, $item_id]);
  $pdo->prepare("DELETE FROM invoice_items WHERE account_id = ? AND id = ? AND invoice_id = ?")
      ->execute([$account_id, $item_id, $invoice_id]);

  if ($timeIds) {
    $in = implode(',', array_fill(0, count($timeIds), '?'));
    $sql = "
      UPDATE times t
      LEFT JOIN (
        SELECT iit.time_id
        FROM invoice_item_times iit
        WHERE iit.account_id = ? AND iit.time_id IN ($in)
        GROUP BY iit.time_id
      ) still_linked ON still_linked.time_id = t.id
      SET t.status = 'offen'
      WHERE t.account_id = ? AND t.id IN ($in) AND still_linked.time_id IS NULL
    ";
    $params = array_merge([$account_id], $timeIds, [$account_id], $timeIds);
    $pdo->prepare($sql)->execute($params);
  }
}

// ---------- POST ----------
$err = null; $ok = null;
$canEditItems = (($invoice['status'] ?? '') === 'in_vorbereitung');

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save') {

  // Basisfelder
  $allowed_status = ['in_vorbereitung','gestellt','gemahnt','bezahlt','storniert'];
  $new_status = $_POST['status'] ?? ($invoice['status'] ?? 'in_vorbereitung');
  if (!in_array($new_status, $allowed_status, true)) $new_status = $invoice['status'] ?? 'in_vorbereitung';

  $issue_date = $_POST['issue_date'] ?? ($invoice['issue_date'] ?? date('Y-m-d'));
  $due_date   = $_POST['due_date']   ?? $invoice['due_date'];
  $tax_reason = trim($_POST['tax_exemption_reason'] ?? '');

  $assign_number = (empty($invoice['invoice_number']) && in_array($new_status, ['gestellt','gemahnt','bezahlt','storniert'], true));
  $number = $invoice['invoice_number'] ?? null;
  if ($assign_number) {
    $number = assign_invoice_number_if_needed($pdo, $account_id, (int)$invoice['id'], $issue_date);
  }

  $itemsPosted   = $_POST['items'] ?? [];
  $deletedPosted = $_POST['items_deleted'] ?? []; // aus "Entfernen"-Button

  if (!$err) {
    $pdo->beginTransaction();
    try {
      $sum_net = 0.0; $sum_gross = 0.0; $hasNonStandard = false;

      if ($canEditItems) {
        // 1) Gelöschte Positionen
        if ($deletedPosted) {
          $deletedIds = array_values(array_filter(array_map('intval', (array)$deletedPosted), fn($v)=>$v>0));
          if ($deletedIds) {
            foreach ($deletedIds as $delId) delete_item_with_times($pdo, $account_id, (int)$invoice['id'], (int)$delId);
          }
        }

        // Statements
        $updItem = $pdo->prepare("
          UPDATE invoice_items
             SET description=?, unit_price=?, vat_rate=?, quantity=?, total_net=?, total_gross=?, tax_scheme=?, entry_mode=?, position=?
           WHERE account_id=? AND invoice_id=? AND id=?
        ");
        $getCurrTimes = $pdo->prepare("SELECT time_id FROM invoice_item_times WHERE account_id=? AND invoice_item_id=?");
        $addLink = $pdo->prepare("INSERT INTO invoice_item_times (account_id, invoice_item_id, time_id) VALUES (?,?,?)");
        $delLink = $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id=? AND invoice_item_id=? AND time_id=?");

        $insItem = $pdo->prepare("
          INSERT INTO invoice_items
            (account_id, invoice_id, project_id, task_id, description,
             quantity, unit_price, vat_rate, total_net, total_gross, position, tax_scheme, entry_mode)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $pos = 1;

        // 2) Alle übergebenen Items (in *Formular-Reihenfolge*)
        foreach ((array)$itemsPosted as $row) {
          $item_id = (int)($row['id'] ?? 0);
          $desc    = trim((string)($row['description'] ?? ''));
          $scheme  = $row['tax_scheme'] ?? 'standard';
          $rate    = (float)dec($row['hourly_rate'] ?? 0);
          $vat     = (float)dec($row['vat_rate']     ?? ($row['tax_rate'] ?? 0));
          if ($scheme !== 'standard') $vat = 0.0;
          $vat = max(0.0, min(100.0, $vat));

          $newTimes = array_values(array_filter(array_map('intval', (array)($row['time_ids'] ?? [])), fn($v)=>$v>0));
          $postedMode = strtolower(trim((string)($row['entry_mode'] ?? 'qty')));
          $entry_mode = !empty($newTimes) ? 'auto' : (in_array($postedMode, ['time','qty'], true) ? $postedMode : 'qty');

          $qty = 0.0; $net = 0.0; $gross = 0.0;

          if ($entry_mode === 'auto') {
            $minutes = 0;
            if ($newTimes) {
              $in = implode(',', array_fill(0, count($newTimes), '?'));
              $st = $pdo->prepare("SELECT COALESCE(SUM(minutes),0) FROM times WHERE account_id=? AND id IN ($in)");
              $st->execute(array_merge([$account_id], $newTimes));
              $minutes = (int)$st->fetchColumn();
            }
            $qty   = round($minutes / 60.0, 3);
            $net   = round(($minutes / 60.0) * $rate, 2);
            $gross = round($net * (1 + $vat/100), 2);
          } elseif ($entry_mode === 'time') {
            $qty_hours = ($row['quantity'] ?? '') !== ''
              ? (float)dec($row['quantity'])
              : (float)parse_hours_to_decimal($row['hours'] ?? '0');
            $qty   = round($qty_hours, 3);
            $net   = round($qty_hours * $rate, 2);
            $gross = round($net * (1 + $vat/100), 2);
          } else { // qty
            $qty_dec = (float)dec($row['quantity'] ?? 0);
            $qty   = round($qty_dec, 3);
            $net   = round($qty_dec * $rate, 2);
            $gross = round($net * (1 + $vat/100), 2);
          }

          if ($item_id > 0) {
            // existierendes Item: Times-Diff anwenden
            $getCurrTimes->execute([$account_id, $item_id]);
            $currTimes = array_map(fn($r)=>(int)$r['time_id'], $getCurrTimes->fetchAll());

            $toAdd    = array_values(array_diff($newTimes, $currTimes));
            $toRemove = array_values(array_diff($currTimes, $newTimes));

            if ($toAdd) {
              foreach ($toAdd as $tid) { $addLink->execute([$account_id, $item_id, $tid]); }
              $ph = implode(',', array_fill(0, count($toAdd), '?'));
              $pdo->prepare("UPDATE times SET status='in_abrechnung' WHERE account_id=? AND id IN ($ph)")
                  ->execute(array_merge([$account_id], $toAdd));
            }
            if ($toRemove) {
              foreach ($toRemove as $tid) { $delLink->execute([$account_id, $item_id, $tid]); }
              $ph = implode(',', array_fill(0, count($toRemove), '?'));
              $sqlOpen = "
                UPDATE times t
                LEFT JOIN invoice_item_times iit
                  ON iit.account_id=t.account_id AND iit.time_id=t.id
                SET t.status='offen'
                WHERE t.account_id=? AND t.id IN ($ph) AND iit.time_id IS NULL
              ";
              $pdo->prepare($sqlOpen)->execute(array_merge([$account_id], $toRemove));
            }

            // Update + Position
            $updItem->execute([
              $desc, $rate, $vat, $qty, $net, $gross, $scheme, $entry_mode, (int)$pos,
              $account_id, (int)$invoice['id'], (int)$item_id
            ]);
          } else {
            // neues Item anlegen (manuell oder auto)
            $insItem->execute([
              $account_id, (int)$invoice['id'], null, null, $desc,
              $qty, $rate, $vat, $net, $gross, (int)$pos, $scheme, $entry_mode
            ]);
            $item_id = (int)$pdo->lastInsertId();

            if ($entry_mode === 'auto' && $newTimes) {
              foreach ($newTimes as $tid) { $addLink->execute([$account_id, $item_id, $tid]); }
              $ph = implode(',', array_fill(0, count($newTimes), '?'));
              $pdo->prepare("UPDATE times SET status='in_abrechnung' WHERE account_id=? AND id IN ($ph)")
                  ->execute(array_merge([$account_id], $newTimes));
            }
          }

          if ($scheme !== 'standard') $hasNonStandard = true;
          $sum_net  += $net;
          $sum_gross+= $gross;
          $pos++;
        }
      } else {
        // nicht editierbar → prüfen, ob nicht-standard existiert
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM invoice_items WHERE account_id=? AND invoice_id=? AND (tax_scheme IS NOT NULL AND tax_scheme <> 'standard')");
        $cnt->execute([$account_id, (int)$invoice['id']]);
        $hasNonStandard = ((int)$cnt->fetchColumn()) > 0;
        // Summen aus DB
        $sumSt = $pdo->prepare("SELECT COALESCE(SUM(total_net),0), COALESCE(SUM(total_gross),0) FROM invoice_items WHERE account_id=? AND invoice_id=?");
        $sumSt->execute([$account_id, (int)$invoice['id']]);
        [$sum_net, $sum_gross] = $sumSt->fetch(PDO::FETCH_NUM);
      }

      // Begründung erzwingen
      if ($hasNonStandard && $tax_reason === '') {
        throw new RuntimeException('Bitte Begründung für die Steuerbefreiung angeben.');
      }

      // Summen final aus DB (robust)
      $sumSt = $pdo->prepare("SELECT COALESCE(SUM(total_net),0), COALESCE(SUM(total_gross),0) FROM invoice_items WHERE account_id=? AND invoice_id=?");
      $sumSt->execute([$account_id, (int)$invoice['id']]);
      [$sum_net, $sum_gross] = $sumSt->fetch(PDO::FETCH_NUM);

      // Rechnung updaten
      if ($assign_number) {
        $updInv = $pdo->prepare("
          UPDATE invoices
            SET issue_date=?, due_date=?, status=?, invoice_number=?, total_net=?, total_gross=?, tax_exemption_reason=?
          WHERE account_id=? AND id=?
        ");
        $updInv->execute([$issue_date, $due_date, $new_status, $number, (float)$sum_net, (float)$sum_gross, $tax_reason, $account_id, (int)$invoice['id']]);
      } else {
        $updInv = $pdo->prepare("
          UPDATE invoices
            SET issue_date=?, due_date=?, status=?, total_net=?, total_gross=?, tax_exemption_reason=?
          WHERE account_id=? AND id=?
        ");
        $updInv->execute([$issue_date, $due_date, $new_status, (float)$sum_net, (float)$sum_gross, $tax_reason, $account_id, (int)$invoice['id']]);
      }

      // Times-Status bei Statuswechsel
      if ($new_status !== ($invoice['status'] ?? '')) {
        $map = [
          'in_vorbereitung' => 'in_abrechnung',
          'gestellt'        => 'abgerechnet',
          'gemahnt'         => 'abgerechnet',
          'bezahlt'         => 'abgerechnet',
          'storniert'       => 'offen',
        ];
        if (isset($map[$new_status])) {
          set_times_status_for_invoice($pdo, $account_id, (int)$invoice['id'], $map[$new_status]);
        }
      }

      // --- Recurring-Ledger aufräumen: Entfernte Runs freigeben ---
      $linked_runs = ri_runs_for_invoice($pdo, $account_id, $invoice_id);

      // 2 Varianten, um "behaltene" Runs zu erkennen:
      // A) Best Case: pro Zeile kam ein Hidden-Feld items[idx][ri_key] mit (siehe Punkt 3)
      // B) Fallback: match nur über die Beschreibung (robust, auch wenn Werte geändert wurden)

      $keptKeys = [];
      foreach ((array)($_POST['items'] ?? []) as $row) {
        $k = trim((string)($row['ri_key'] ?? ''));
        if ($k !== '') { $keptKeys[$k] = true; }
      }

      $del = $pdo->prepare("
        DELETE FROM recurring_item_ledger
        WHERE account_id=? AND invoice_id=? AND recurring_item_id=? AND period_from=? AND period_to=?
      ");

      if ($keptKeys) {
        // Variante A: vergleiche Keys
        foreach ($linked_runs as $L) {
          if (empty($keptKeys[$L['key']])) {
            $del->execute([$account_id, $invoice_id, $L['recurring_item_id'], $L['from'], $L['to']]);
          }
        }
      } else {
        // Variante B (Fallback): vergleiche Beschreibungen
        $descSet = [];
        foreach ((array)($_POST['items'] ?? []) as $row) {
          $d = trim((string)($row['description'] ?? ''));
          if ($d !== '') $descSet[$d] = true;
        }
        foreach ($linked_runs as $L) {
          if (empty($descSet[$L['description']])) {
            $del->execute([$account_id, $invoice_id, $L['recurring_item_id'], $L['from'], $L['to']]);
          }
        }
      }
      // --- Ende Ledger-Aufräumen ---
      $pdo->commit();
      $ok = 'Rechnung gespeichert.';
      redirect(url('/invoices/edit.php').'?id='.(int)$invoice['id']);
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = 'Rechnung konnte nicht gespeichert werden: '.$e->getMessage();
    }
  }
}

// ---------- Anzeige-Daten laden (nach evtl. Save/Delete) ----------
$invStmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND account_id = ?");
$invStmt->execute([$invoice_id, $account_id]);
$invoice = $invStmt->fetch();

$rawItems = load_invoice_items($pdo, $account_id, $invoice_id);
$itemIds  = array_map(fn($r)=>(int)$r['item_id'], $rawItems);
$timesByItem = load_times_by_item($pdo, $account_id, $itemIds);

// Für _items_table.php (EDIT-Modus: flach)
$items  = [];
foreach ($rawItems as $r) {
  $iid = (int)$r['item_id'];
  $items[] = [
    'id'            => $iid,
    'description'   => (string)($r['description'] ?? ''),
    'hourly_rate'   => (float)($r['unit_price'] ?? 0),
    'vat_rate'      => (float)($r['vat_rate'] ?? 0),
    'tax_scheme'    => $r['tax_scheme'] ?? 'standard',
    'quantity'      => (float)($r['quantity'] ?? 0),
    'entry_mode'    => $r['entry_mode'] ?? null,
    'total_net'     => isset($r['total_net'])   ? (float)$r['total_net']   : null,
    'total_gross'   => isset($r['total_gross']) ? (float)$r['total_gross'] : null,
    'time_entries'  => array_map(function($t){
      return [
        'id'         => (int)($t['id'] ?? $t['time_id'] ?? 0),
        'minutes'    => (int)($t['minutes'] ?? 0),
        'started_at' => $t['started_at'] ?? null,
        'ended_at'   => $t['ended_at'] ?? null,
        'selected'   => true,
      ];
    }, $timesByItem[$iid] ?? []),
  ];
}
$groups = []; // EDIT-Modus

// ---------- View ----------
require __DIR__ . '/../../src/layout/header.php';
// return_to
$return_to = pick_return_to('/companies/show.php?id='.$company_id);

?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Rechnung bearbeiten</h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?=h(url($return_to))?>">Zurück</a>
  </div>
</div>

<?php if (!empty($ok)): ?>
  <div class="alert alert-success"><?=h($ok)?></div>
<?php endif; ?>
<?php if (!empty($err)): ?>
  <div class="alert alert-danger"><?=h($err)?></div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <form method="post" class="row g-3">
      <?=csrf_field()?>
      <input type="hidden" name="id" value="<?=$invoice_id?>">
      <input type="hidden" name="action" value="save">
      <?=return_to_hidden($return_to)?>

      <div class="col-md-4">
        <label class="form-label">Firma</label>
        <input class="form-control" value="<?=h($company['name'] ?? ('#'.$company_id))?>" disabled>
      </div>
      <div class="col-md-3">
        <label class="form-label">Rechnungsdatum</label>
        <input type="date" name="issue_date" class="form-control" value="<?=h($invoice['issue_date'] ?? date('Y-m-d'))?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Fällig bis</label>
        <input type="date" name="due_date" class="form-control" value="<?=h($invoice['due_date'] ?? date('Y-m-d', strtotime('+14 days')))?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Status</label>
        <?php $st = $invoice['status'] ?? 'in_vorbereitung'; ?>
        <select name="status" class="form-select">
          <option value="in_vorbereitung" <?=$st==='in_vorbereitung'?'selected':''?>>in Vorbereitung</option>
          <option value="gestellt" <?=$st==='gestellt'?'selected':''?>>gestellt</option>
          <option value="gemahnt" <?=$st==='gemahnt'?'selected':''?>>gemahnt</option>
          <option value="bezahlt" <?=$st==='bezahlt'?'selected':''?>>bezahlt</option>
          <option value="storniert" <?=$st==='storniert'?'selected':''?>>storniert</option>
        </select>
      </div>

      <div class="card mb-3" id="tax-exemption-reason-wrap" style="<?= !empty($invoice['tax_exemption_reason']) ? '' : 'display:none' ?>">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <?php $defVat = number_format((float)$settings['default_vat_rate'],2,'.',''); ?>

          </div>
          <label class="form-label">Begründung für die Steuerbefreiung</label>
          <textarea
            class="form-control"
            id="tax-exemption-reason"
            name="tax_exemption_reason"
            rows="2"
            placeholder="z. B. § 19 UStG (Kleinunternehmer) / Reverse-Charge nach § 13b UStG / Art. 196 MwStSystRL"
          ><?= h($invoice['tax_exemption_reason'] ?? '') ?></textarea>
          <div class="form-text">
            Wird benötigt, wenn mindestens eine Position steuerfrei oder Reverse-Charge ist.
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card mt-3">
          <div class="card-body">
            <?php
              $mode = 'edit';
              $rowName  = 'items';
              $timesName = 'time_ids';

              $ri_runs = ri_runs_for_invoice($pdo, $account_id, $invoice_id);
              $ri_key_by_desc = [];
              foreach ($ri_runs as $r) { $ri_key_by_desc[$r['description']] = $r['key']; }

              require __DIR__ . '/_items_table.php';
            ?>
            <button type="button" id="addManualItem" class="btn btn-sm btn-outline-primary" data-default-vat="<?= h($defVat) ?>">+ Position</button>
          </div>
        </div>
      </div>

      <div class="col-12 text-end">
        <a class="btn btn-outline-secondary" href="<?=h(url($return_to))?>">Abbrechen</a>
        <button class="btn btn-primary">Speichern</button>
      </div>
    </form>
  </div>
</div>

<script>
// "+ Position": manuelle Zeile (qty) direkt in der Items-Tabelle anlegen
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
<script>
(function () {
  const form   = document.getElementById('invForm');
  const wrap   = document.getElementById('tax-exemption-reason-wrap');
  const reason = document.getElementById('tax-exemption-reason');
  const table  = document.getElementById('invoice-items');

  function parseVat(input) {
    if (!input) return 0;
    const v = String(input.value || '').replace(',', '.');
    const n = parseFloat(v);
    return isFinite(n) ? n : 0;
  }

  function needsReason() {
    if (!table) return false;
    const rows = table.querySelectorAll('tr.inv-item-row');
    for (const tr of rows) {
      const schemeSel = tr.querySelector('.inv-tax-sel');
      const vatInput  = tr.querySelector('.inv-vat-input');
      if (!schemeSel || !vatInput) continue;

      const scheme = (schemeSel.value || '').toLowerCase();
      const vat    = parseVat(vatInput);

      // Gleiche Logik wie serverseitig:
      // - jede Steuerart ≠ 'standard' → Begründung nötig
      // - oder 'standard' mit 0,00 % → Begründung nötig
      if (scheme !== 'standard' || vat <= 0) {
        return true;
      }
    }
    return false;
  }

  function updateReasonUI() {
    const need = needsReason();
    if (need) {
      wrap && (wrap.style.display = '');
      if (reason) {
        reason.setAttribute('required', 'required');
        reason.setAttribute('aria-required', 'true');
      }
    } else {
      wrap && (wrap.style.display = 'none');
      if (reason) {
        reason.removeAttribute('required');
        reason.removeAttribute('aria-required');
      }
    }
  }

  // Reagiert auf Änderungen an Steuerart & MwSt
  document.addEventListener('input', function (e) {
    if (e.target && (e.target.classList.contains('inv-tax-sel') || e.target.classList.contains('inv-vat-input'))) {
      updateReasonUI();
    }
  });
  document.addEventListener('change', function (e) {
    if (e.target && (e.target.classList.contains('inv-tax-sel') || e.target.classList.contains('inv-vat-input'))) {
      updateReasonUI();
    }
  });

  // Beobachte Hinzufügen/Entfernen von Zeilen (MutationObserver)
  if (table) {
    const mo = new MutationObserver(updateReasonUI);
    mo.observe(table, { childList: true, subtree: true });
  }

  // Initial
  updateReasonUI();

  // Blockiere Submit, wenn Begründung fehlt, obwohl nötig
  if (form) {
    form.addEventListener('submit', function (e) {
      if (needsReason()) {
        if (!reason || !reason.value.trim()) {
          wrap && (wrap.style.display = '');
          reason && reason.setAttribute('required', 'required');
          // Native Validierung triggern (sofern verfügbar)
          reason && reason.reportValidity && reason.reportValidity();
          reason && reason.focus && reason.focus();
          e.preventDefault();
          e.stopPropagation();
        }
      }
    });
  }
})();
</script>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>