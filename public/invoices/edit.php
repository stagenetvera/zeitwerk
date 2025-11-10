<?php
// public/invoices/edit.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/invoice_number.php';
require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/utils.php';            // dec(), parse_hours_to_decimal(), h(), url()
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/recurring.php';
require_once __DIR__ . '/../../src/lib/return_to.php';
require_once __DIR__ . '/../../src/lib/invoices.php';     // set_times_status_for_invoice(), delete_item_with_times()

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$settings = get_account_settings($pdo, $account_id);

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($v){ return number_format((float)$v, 2, ',', '.'); }

$DEFAULT_VAT       = (float)($settings['default_vat_rate'] ?? 19.0);
$DEFAULT_SCHEME    = (string)($settings['default_tax_scheme'] ?? 'standard'); // 'standard'|'tax_exempt'|'reverse_charge'
$ROUND_UNIT_MINS   = max(0, (int)($settings['invoice_round_minutes'] ?? 0));  // Rundung wie in new.php

// ---------- Eingaben ----------
$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($invoice_id <= 0) {
  require __DIR__ . '/../../src/layout/header.php';
  echo '<div class="alert alert-danger">Ungültige Rechnungs-ID.</div>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}

// ---------- Rechnung laden ----------
$inv = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND account_id = ?");
$inv->execute([$invoice_id, $account_id]);
$invoice = $inv->fetch();
if (!$invoice) {
  require __DIR__ . '/../../src/layout/header.php';
  echo '<div class="alert alert-danger">Rechnung nicht gefunden.</div>';
  require __DIR__ . '/../../src/layout/footer.php';
  exit;
}
$company_id = (int)$invoice['company_id'];

$return_to = pick_return_to('/companies/show.php?id='.$company_id);

// Firma laden (nur Anzeige + Defaults)
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
      ii.entry_mode,
      ii.is_hidden
    FROM invoice_items ii
    WHERE ii.account_id = ? AND ii.invoice_id = ?
    ORDER BY COALESCE(ii.position, 999999), ii.id ASC
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
    ORDER BY tm.started_at, tm.id
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

/** Einzelne Minuten-Summe (Account-sicher) */
function sum_minutes_for_times_edit(PDO $pdo, int $account_id, array $ids): int {
  $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
  if (!$ids) return 0;
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT COALESCE(SUM(minutes),0) FROM times WHERE account_id=? AND id IN ($in)");
  $st->execute(array_merge([$account_id], $ids));
  return (int)$st->fetchColumn();
}

/** Zeiten auf 'offen' zurücksetzen, wenn sie nirgendwo mehr verlinkt sind */
function free_times_if_unlinked(PDO $pdo, int $account_id, array $timeIds): void {
  $timeIds = array_values(array_filter(array_map('intval', $timeIds), fn($v)=>$v>0));
  if (!$timeIds) return;
  $in  = implode(',', array_fill(0, count($timeIds), '?'));
  $sql = "
    UPDATE times t
       SET t.status = 'offen'
     WHERE t.account_id = ?
       AND t.id IN ($in)
       AND NOT EXISTS (
             SELECT 1
               FROM invoice_item_times i
              WHERE i.account_id = t.account_id
                AND i.time_id     = t.id
           )
       AND t.status <> 'abgerechnet'
  ";
  $pdo->prepare($sql)->execute(array_merge([$account_id], $timeIds));
}

/** Bulk Status für Zeiten setzen */
function set_times_status_bulk(PDO $pdo, int $account_id, array $time_ids, string $status): void {
  $time_ids = array_values(array_filter(array_map('intval', $time_ids), fn($v)=>$v>0));
  if (!$time_ids) return;
  $in = implode(',', array_fill(0, count($time_ids), '?'));
  $pdo->prepare("UPDATE times SET status=? WHERE account_id=? AND id IN ($in)")
      ->execute(array_merge([$status, $account_id], $time_ids));
}

// ---------- POST ----------
$err = null; $ok = null;
$canEditItems = (($invoice['status'] ?? '') === 'in_vorbereitung');
$isLockedAll  = !$canEditItems;                       // für UI: alles außer Status sperren

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save') {

  // Basisfelder
  $allowed_status = ['in_vorbereitung','gestellt','gemahnt','bezahlt','storniert','ausgebucht'];
  $new_status = $_POST['status'] ?? ($invoice['status'] ?? 'in_vorbereitung');
  if (!in_array($new_status, $allowed_status, true)) $new_status = $invoice['status'] ?? 'in_vorbereitung';

  $issue_date = $_POST['issue_date'] ?? ($invoice['issue_date'] ?? date('Y-m-d'));
  $due_date   = $_POST['due_date']   ?? $invoice['due_date'];
  $tax_reason = trim($_POST['tax_exemption_reason'] ?? '');

  $intro_text = (string)($_POST['invoice_intro_text'] ?? '');
  $outro_text = (string)($_POST['invoice_outro_text'] ?? '');

  // Nummer vergeben, falls nötig (gleiches Verhalten wie bisher)
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
      $sum_net = 0.0; $hasNonStandard = false;

      if ($canEditItems) {
        // 1) Gelöschte Positionen
        if ($deletedPosted) {
          $deletedIds = array_values(array_filter(array_map('intval', (array)$deletedPosted), fn($v)=>$v>0));
          if ($deletedIds) {
            foreach ($deletedIds as $delId) {
              delete_item_with_times($pdo, $account_id, (int)$invoice['id'], (int)$delId);
            }
          }
        }

        // Statements
        $updItem = $pdo->prepare("
          UPDATE invoice_items
             SET description=?, unit_price=?, vat_rate=?, quantity=?, total_net=?, total_gross=?, tax_scheme=?, entry_mode=?, position=?
           WHERE account_id=? AND invoice_id=? AND id=?
        ");
        $getCurrTimes = $pdo->prepare("SELECT time_id FROM invoice_item_times WHERE account_id=? AND invoice_item_id=?");
        $addLink      = $pdo->prepare("INSERT INTO invoice_item_times (account_id, invoice_item_id, time_id) VALUES (?,?,?)");
        $delLink      = $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id=? AND invoice_item_id=? AND time_id=?");

        $insItem = $pdo->prepare("
          INSERT INTO invoice_items
            (account_id, invoice_id, project_id, task_id, description,
             quantity, unit_price, vat_rate, total_net, total_gross, position, tax_scheme, entry_mode)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $pos = 1;

        $addedTimeCount = 0;       // wie viele Times wurden neu zugeordnet?
        $addedTimesToExistingInvoice = false;

        // 2) Alle übergebenen Items (in *Formular-Reihenfolge*)
        foreach ((array)$itemsPosted as $row) {
          $item_id = (int)($row['id'] ?? 0);
          $desc    = trim((string)($row['description'] ?? ''));
          $scheme  = (string)($row['tax_scheme'] ?? $DEFAULT_SCHEME);
          $rate    = (float)dec($row['hourly_rate'] ?? 0);
          $vat     = ($scheme === 'standard') ? (float)dec($row['vat_rate'] ?? $DEFAULT_VAT) : 0.0;
          $vat     = max(0.0, min(100.0, $vat));

          $postedMode = strtolower(trim((string)($row['entry_mode'] ?? 'qty')));
          // fixed | auto | time | qty
          $entry_mode = in_array($postedMode, ['fixed','auto','time','qty'], true) ? $postedMode : 'qty';

          // Zeit-IDs (nur bei auto/fixed relevant)
          $newTimes = array_values(array_filter(array_map('intval', (array)($row['time_ids'] ?? [])), fn($v)=>$v>0));

          $qty = 0.0; $net = 0.0; $gross = 0.0;

          if ($entry_mode === 'fixed') {
            // Festpreis: Menge = 1, Preis = unit_price
            $qty = 1.0;
            $net = round($rate * $qty, 2);
            $gross = round($net * (1 + $vat/100), 2);
          } elseif ($entry_mode === 'auto') {
            // AUTO: Menge = Summe Minuten (gerundet) / 60, Preis = rate
            $minutes = $newTimes ? sum_minutes_for_times_edit($pdo, $account_id, $newTimes) : 0;
            if ($ROUND_UNIT_MINS > 0 && $minutes > 0) {
              $minutes = (int)(ceil($minutes / $ROUND_UNIT_MINS) * $ROUND_UNIT_MINS);
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
            // existierendes Item: Times-Diff anwenden (bei auto/fixed)
            if ($entry_mode === 'auto' || $entry_mode === 'fixed') {
              $getCurrTimes->execute([$account_id, $item_id]);
              $currTimes = array_map(fn($r)=>(int)$r['time_id'], $getCurrTimes->fetchAll());

              $toAdd    = array_values(array_diff($newTimes, $currTimes));
              $toRemove = array_values(array_diff($currTimes, $newTimes));

              if ($toAdd) {
                foreach ($toAdd as $tid) { $addLink->execute([$account_id, $item_id, $tid]); }
                set_times_status_bulk($pdo, $account_id, $toAdd, 'in_abrechnung');


                // Merken für Flash-Meldung
                $addedTimesToExistingInvoice = true;
                $addedTimeCount += count($toAdd);
              }
              if ($toRemove) {
                foreach ($toRemove as $tid) { $delLink->execute([$account_id, $item_id, $tid]); }
                free_times_if_unlinked($pdo, $account_id, $toRemove);
              }
            }

            // Update + Position
            $x = $updItem->execute([
              $desc, $rate, $vat, $qty, $net, $gross, $scheme, $entry_mode, (int)$pos,
              $account_id, (int)$invoice['id'], (int)$item_id
            ]);
          } else {
            // neues Item
            $insItem->execute([
              $account_id, (int)$invoice['id'], null, null, $desc,
              $qty, $rate, $vat, $net, $gross, (int)$pos, $scheme, $entry_mode
            ]);
            $item_id = (int)$pdo->lastInsertId();

            if (in_array($entry_mode, ['auto','fixed'], true) && $newTimes) {
              foreach ($newTimes as $tid) { $addLink->execute([$account_id, $item_id, $tid]); }
              set_times_status_bulk($pdo, $account_id, $newTimes, 'in_abrechnung');
            }
          }

          if ($scheme !== 'standard' || $vat <= 0.0) $hasNonStandard = true;
          $sum_net  += $net;
          $pos++;
        }

        // -----------------------------------------
        // NEU: Kopf & Summen der Rechnung aktualisieren
        // -----------------------------------------
        $tax_reason_to_save = $hasNonStandard ? $tax_reason : '';

        // Netto/Brutto/MwSt je Steuersatz aus der DB neu berechnen
        $totals = calculate_invoice_totals($pdo, $account_id, (int)$invoice['id']);
        $sum_net   = $totals['total_net'];
        $sum_gross = $totals['total_gross'];

        $updInv = $pdo->prepare("
          UPDATE invoices
             SET issue_date = ?,
                 due_date   = ?,
                 total_net  = ?,
                 total_gross = ?,
                 tax_exemption_reason = ?,
                 invoice_intro_text   = ?,
                 invoice_outro_text   = ?
           WHERE account_id = ? AND id = ?
        ");
        $updInv->execute([
          $issue_date,
          $due_date,
          $sum_net,
          $sum_gross,
          $tax_reason_to_save,
          $intro_text,
          $outro_text,
          $account_id,
          (int)$invoice['id'],
        ]);

        // 3) Status + evtl. Rechnungsnummer (auch im "editierbaren" Zustand)
        if ($assign_number) {
          $pdo->prepare("UPDATE invoices SET status = ?, invoice_number = ? WHERE account_id = ? AND id = ?")
              ->execute([$new_status, $number, $account_id, (int)$invoice['id']]);
        } else {
          $pdo->prepare("UPDATE invoices SET status = ? WHERE account_id = ? AND id = ?")
              ->execute([$new_status, $account_id, (int)$invoice['id']]);
        }

        // 4) Times-Status entsprechend dem neuen Rechnungs-Status anpassen
        if ($new_status !== ($invoice['status'] ?? '')) {
          $map = [
            'in_vorbereitung' => 'in_abrechnung',
            'gestellt'        => 'abgerechnet',
            'gemahnt'         => 'abgerechnet',
            'bezahlt'         => 'abgerechnet',
            'storniert'       => 'offen',
            'ausgebucht'      => 'abgerechnet',
          ];
          if (isset($map[$new_status])) {
            set_times_status_for_invoice($pdo, $account_id, (int)$invoice['id'], $map[$new_status]);
          }
        }

        // 5) Transaktion festschreiben und zurück zur Edit-Seite
        $pdo->commit();

        if ($addedTimesToExistingInvoice) {
          // „ältere Rechnung“: Invoice existiert schon, wir benutzen hier einfach die vorhandene Nummer/Datum
          $msg = 'Rechnung gespeichert. Es wurden ' . $addedTimeCount . ' offene Zeiten einer bestehenden Rechnung zugeschlagen.';
          flash($msg, 'success');
        } else {
          flash('Rechnung gespeichert.', 'success');
        }
        redirect(url('/invoices/edit.php').'?id='.(int)$invoice['id']);
        exit;
      } else {
        // --- LOCKED: keine Änderungen an Items/Kopf. Nur Status (und ggf. Rechnungsnummer) ---
        // Nummer nur vergeben, wenn noch keine existiert und der neue Status eine Nummer erfordert.
        // Wichtig: Datum aus der DB verwenden (nicht aus POST), damit Kopf nicht "indirekt" geändert wird.
        if ($assign_number) {
          $number = assign_invoice_number_if_needed(
            $pdo,
            $account_id,
            (int)$invoice['id'],
            (string)$invoice['issue_date'] // bestehendes Datum verwenden
          );
        } else {
          $number = $invoice['invoice_number'] ?? null;
        }

        // Nur Status (und evtl. Rechnungsnummer) aktualisieren – keine Summen/Texte/Daten anfassen
        if ($assign_number) {
          $pdo->prepare("UPDATE invoices SET status=?, invoice_number=? WHERE account_id=? AND id=?")
              ->execute([$new_status, $number, $account_id, (int)$invoice['id']]);
        } else {
          $pdo->prepare("UPDATE invoices SET status=? WHERE account_id=? AND id=?")
              ->execute([$new_status, $account_id, (int)$invoice['id']]);
        }

        // Times-Status zur Statusänderung mappen
        if ($new_status !== ($invoice['status'] ?? '')) {
          $map = [
            'in_vorbereitung' => 'in_abrechnung',
            'gestellt'        => 'abgerechnet',
            'gemahnt'         => 'abgerechnet',
            'bezahlt'         => 'abgerechnet',
            'storniert'       => 'offen',
            'ausgebucht'      => 'abgerechnet',
          ];
          if (isset($map[$new_status])) {
            set_times_status_for_invoice($pdo, $account_id, (int)$invoice['id'], $map[$new_status]);
          }
        }

        // Keine Item-/Ledger-/"hängende Zeiten"-Aufräum-Logik hier – es ändert sich ja nichts an den Positionen.
        $pdo->commit();
        flash('Rechnung gespeichert.', 'success');
        redirect(url('/invoices/edit.php').'?id='.(int)$invoice['id']);
        exit;
      }

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
  if ((int)($r['is_hidden'] ?? 0) === 1) continue; // versteckte Items nicht im UI
  $iid = (int)$r['item_id'];
  $mode = strtolower((string)($r['entry_mode'] ?? 'qty'));
  $items[] = [
    'id'            => $iid,
    'task_id'       => $r['task_id'] !== null ? (int)$r['task_id'] : null,
    'description'   => (string)($r['description'] ?? ''),
    'hourly_rate'   => (float)($r['unit_price'] ?? 0),
    'vat_rate'      => ($r['tax_scheme'] ?? 'standard') === 'standard' ? (float)($r['vat_rate'] ?? 0) : 0.0,
    'tax_scheme'    => $r['tax_scheme'] ?? 'standard',
    'quantity'      => (float)($r['quantity'] ?? 0),
    'entry_mode'    => in_array($mode, ['fixed','auto','time','qty'], true) ? $mode : 'qty',
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

// Default-Stundensatz für neu hinzugefügte manuelle Positionen
$default_manual_rate = 0.00;
// 1) Firmen-Default
if (!empty($company)) {
  $default_manual_rate = (float)($company['hourly_rate'] ?? 0.0);
}
// 2) Falls 0: nimm ersten Satz aus vorhandenen Items
if ($default_manual_rate <= 0 && !empty($items)) {
  foreach ($items as $it) {
    $er = (float)($it['hourly_rate'] ?? 0.0);
    if ($er > 0) { $default_manual_rate = $er; break; }
  }
}

// Platzhalter-Texte für Intro/Outro (analog new.php)
$eff_intro_edit = (string)($settings['invoice_intro_text'] ?? '');
$eff_outro_edit = (string)($settings['invoice_outro_text'] ?? '');
if ($company) {
  $coIntro = isset($company['invoice_intro_text']) ? (string)$company['invoice_intro_text'] : '';
  $coOutro = isset($company['invoice_outro_text']) ? (string)$company['invoice_outro_text'] : '';
  if (trim($coIntro) !== '') $eff_intro_edit = $coIntro;
  if (trim($coOutro) !== '') $eff_outro_edit = $coOutro;
}

// Recurring-Runs (für ri_key Hidden in _items_table.php)
$ri_runs = ri_runs_for_invoice($pdo, $account_id, $invoice_id);
$ri_key_by_desc = [];
foreach ($ri_runs as $r) { $ri_key_by_desc[$r['description']] = $r['key']; }

// ---------- View ----------
require __DIR__ . '/../../src/layout/header.php';
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
    <form method="post" id="invForm" class="row g-3" action="<?= h(url('/invoices/edit.php')) ?>">
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
        <input type="date" name="issue_date" class="form-control" value="<?=h($invoice['issue_date'] ?? date('Y-m-d'))?>" <?= $isLockedAll ? 'disabled' : '' ?>>
      </div>
      <div class="col-md-3">
        <label class="form-label">Fällig bis</label>
        <input type="date" name="due_date" class="form-control" value="<?=h($invoice['due_date'] ?? date('Y-m-d', strtotime('+14 days')))?>" <?= $isLockedAll ? 'disabled' : '' ?>>
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
          <option value="ausgebucht" <?=$st==='ausgebucht'?'selected':''?>>ausgebucht</option>
        </select>
      </div>

      <div class="col-12">
        <div class="card mt-3">
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label">Rechnungs-Einleitung</label>
              <textarea
                class="form-control"
                name="invoice_intro_text"
                rows="3"
                placeholder="<?= h($eff_intro_edit) ?>"
                <?= $isLockedAll ? 'disabled' : '' ?>
              ><?= h((string)($invoice['invoice_intro_text'] ?? '')) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3" id="tax-exemption-reason-wrap" style="<?= !empty($invoice['tax_exemption_reason']) ? '' : 'display:none' ?>">
        <div class="card-body">
          <label class="form-label">Begründung für die Steuerbefreiung</label>
          <textarea
            class="form-control"
            id="tax-exemption-reason"
            name="tax_exemption_reason"
            rows="2"
            placeholder="z. B. § 19 UStG (Kleinunternehmer) / Reverse-Charge nach § 13b UStG / Art. 196 MwStSystRL"
            <?= $isLockedAll ? 'disabled' : '' ?>
          ><?= h($invoice['tax_exemption_reason'] ?? '') ?></textarea>
          <div class="form-text">
            Wird benötigt, wenn mindestens eine Position steuerfrei oder Reverse-Charge ist, oder der MwSt-Satz 0,00 % beträgt.
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card mt-3">
          <div class="card-body">
            <?php
              // so erwartet es _items_table.php
              $mode       = 'edit';
              $rowName    = 'items';
              $timesName  = 'time_ids';

              $allow_edit = $canEditItems;   // <— NEU: für _items_table.php

              // $items, $groups, $ri_key_by_desc sind oben gesetzt
              require __DIR__ . '/_items_table.php';
            ?>
            <?php if ($canEditItems): ?>
            <button
              type="button"
              id="addManualItem"
              class="btn btn-sm btn-outline-primary"
              data-default-vat="<?= h(number_format($DEFAULT_VAT,2,'.','')) ?>"
              data-default-rate="<?= h(number_format((float)$default_manual_rate,2,'.','')) ?>"
            >+ Position</button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card mt-3">
          <div class="card-body">
            <label class="form-label">Rechnungs-Schlussformel</label>
            <textarea
              class="form-control"
              name="invoice_outro_text"
              rows="3"
              placeholder="<?= h($eff_outro_edit) ?>"
              <?= $isLockedAll ? 'disabled' : '' ?>
            ><?= h((string)($invoice['invoice_outro_text'] ?? '')) ?></textarea>
          </div>
        </div>
      </div>

      <div class="col-12 text-end">
        <a class="btn btn-outline-secondary" href="<?=h(url($return_to))?>">Abbrechen</a>
        <button class="btn btn-primary" name="action" value="save">Speichern</button>
      </div>
    </form>

    <?php
      $st = $invoice['status'] ?? 'in_vorbereitung';
      $has_no_number = empty($invoice['invoice_number']);
      $can_delete = ($st === 'in_vorbereitung' && $has_no_number);
      $can_cancel = in_array($st, ['gestellt','gemahnt','bezahlt','ausgebucht'], true);
    ?>

    <!-- Stornieren -->
    <form method="post" action="<?= url('/invoices/cancel.php') ?>" class="d-inline"
          onsubmit="return confirm('Rechnung wirklich stornieren?');">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$invoice['id'] ?>">
      <input type="hidden" name="return_to" value="<?= h($return_to) ?>">
      <button class="btn btn-outline-danger" <?= $can_cancel ? '' : 'disabled' ?>>Stornieren</button>
    </form>

    <!-- Löschen (hart) -->
    <form method="post" action="<?= url('/invoices/delete.php') ?>" class="d-inline"
          onsubmit="return confirm('Rechnung wirklich löschen?');">
      <?= csrf_field() ?>
      <?= return_to_hidden($return_to) ?>
      <input type="hidden" name="id" value="<?= (int)$invoice['id'] ?>">
      <button class="btn btn-outline-danger" <?= $can_delete ? '' : 'disabled' ?>>
        <i class="bi bi-trash"></i>
      </button>
    </form>

  </div>
</div>

<script>
// Manuelle Zeilen (qty|time) mit ICON-Toggle (auch für bestehende Zeilen)
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

    const entry = tr.querySelector('input.entry-mode') || tr.appendChild(Object.assign(document.createElement('input'), {type:'hidden', className:'entry-mode', name:'items['+(tr.getAttribute('data-row')||'')+'][entry_mode]', value:'qty'}));
    entry.value = mode;
    tr.setAttribute('data-mode', mode);

    const qty   = tr.querySelector('input.quantity');
    const hours = tr.querySelector('input.hours-input');

    if (mode === 'time') {
      if (hours && qty && (!hours.value || hours.value === '00:00')) {
        hours.value = decToHHMM(qty.value || '0');
      }
      if (qty)   { qty.classList.add('d-none'); qty.disabled = true; }
      if (hours) { hours.classList.remove('d-none'); hours.disabled = false; }
    } else {
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

    const activeInput = (mode === 'time') ? hours : qty;
    if (activeInput) activeInput.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function ensureIconToggle(tr){
    if (tr.getAttribute('data-mode') === 'auto' || tr.getAttribute('data-mode') === 'fixed') return;
    if (tr.querySelector('.mode-btn')) return;

    const qtyCell = tr.querySelector('td:nth-child(3)');
    if (!qtyCell) return;

    let qty   = tr.querySelector('input.quantity');
    let hours = tr.querySelector('input.hours-input');

    if (!qty) {
      qty = document.createElement('input');
      qty.type = 'number';
      qty.className = 'form-control form-control-sm text-end quantity no-spin';
      qty.name = 'items['+(tr.getAttribute('data-row')||'')+'][quantity]';
      qty.value = '1';
    }
    if (!hours) {
      hours = document.createElement('input');
      hours.type = 'text';
      hours.className = 'form-control form-control-sm text-end hours-input d-none';
      hours.name = 'items['+(tr.getAttribute('data-row')||'')+'][hours]';
      hours.placeholder = 'hh:mm oder 1.5';
      hours.disabled = true;
    }

    const group = document.createElement('div');
    group.className = 'input-group input-group-sm flex-nowrap';

    const btnQty  = document.createElement('button');
    btnQty.type   = 'button';
    btnQty.className = 'btn btn-outline-secondary mode-btn';
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
    ensureIconToggle(tr);
    const attr  = tr.getAttribute('data-mode');
    const entry = tr.querySelector('input.entry-mode')?.value;
    const mode  = (attr || entry || 'qty');
    switchRowMode(tr, mode);
  }

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
           <input type="hidden" class="entry-mode" name="items[${idx}][entry_mode]" value="qty">
         </td>

         <td>
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
                    class="form-control form-control-sm text-end quantity no-spin"
                    name="items[${idx}][quantity]" value="1" step="0.001" min="0">

             <input type="text"
                    class="form-control form-control-sm text-end hours-input d-none"
                    name="items[${idx}][hours]" placeholder="hh:mm oder 1.5" disabled>
           </div>
         </td>

         <td class="text-end">
           <input type="number" class="form-control form-control-sm text-end rate no-spin"
                  name="items[${idx}][hourly_rate]" value="${defRate}" step="0.01" min="0">
         </td>

         <td class="text-end">
           <select name="items[${idx}][tax_scheme]" class="form-select form-select-sm inv-tax-sel"
                   data-rate-standard="${defVat}" data-rate-tax-exempt="0.00" data-rate-reverse-charge="0.00">
             <option value="standard" selected>standard (mit MwSt)</option>
             <option value="tax_exempt">steuerfrei</option>
             <option value="reverse_charge">Reverse-Charge</option>
           </select>
         </td>

         <td class="text-end">
           <input type="number" min="0" max="100" step="0.01"
                  class="form-control form-control-sm text-end inv-vat-input no-spin"
                  name="items[${idx}][vat_rate]" value="${defVat}">
         </td>

         <td class="text-end"><span class="net">0,00</span></td>
         <td class="text-end"><span class="gross">0,00</span></td>

         <td class="text-end text-nowrap">
           <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
         </td>`;

      insertBeforeGrand(tr);
      const btnQty = tr.querySelector('.mode-btn[data-mode="qty"]');
      const btnTim = tr.querySelector('.mode-btn[data-mode="time"]');
      setBtnActive(btnQty, true);
      setBtnActive(btnTim, false);
      switchRowMode(tr, 'qty');
    });
  }

  document.querySelectorAll('#invoice-items tr.inv-item-row').forEach(initRow);
})();
</script>

<script>
// Steuer-Begründung automatisch ein-/ausblenden (analog new.php)
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

      if (scheme !== 'standard' || vat <= 0) return true;
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

  if (table) {
    const mo = new MutationObserver(updateReasonUI);
    mo.observe(table, { childList: true, subtree: true });
  }

  // Initial
  updateReasonUI();

  if (form) {
    form.addEventListener('submit', function (e) {
      if (needsReason()) {
        if (!reason || !reason.value.trim()) {
          wrap && (wrap.style.display = '');
          reason && reason.setAttribute('required', 'required');
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