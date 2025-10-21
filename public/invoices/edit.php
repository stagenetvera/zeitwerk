<?php
// public/invoices/edit.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/invoice_number.php';
require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/utils.php';
require_once __DIR__ . '/../../src/lib/flash.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$settings = get_account_settings($pdo, $account_id);

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($v){ return number_format((float)$v, 2, ',', '.'); }


// Minuten über eine Menge Time-IDs (Account-sicher) summieren
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
/** Liefert alle Items der Rechnung mit Projekt-Infos */
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
      p.title AS project_title
    FROM invoice_items ii
    LEFT JOIN projects p
      ON p.id = ii.project_id AND p.account_id = ii.account_id
    WHERE ii.account_id = ? AND ii.invoice_id = ?
    ORDER BY ii.position ASC, ii.id ASC
  ");
  $st->execute([$account_id, $invoice_id]);
  return $st->fetchAll();
}
/** Liefert verlinkte Zeiten pro Item */
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
    if (!isset($out[$iid])) $out[$iid] = [];
    $out[$iid][] = [
      'id'         => (int)$r['time_id'],
      'started_at' => $r['started_at'],
      'ended_at'   => $r['ended_at'],
      'minutes'    => (int)$r['minutes'],
    ];
  }
  return $out;
}

/** Setzt Status der Times für diese Rechnung gesammelt */
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

/** Entfernt einen Item-Eintrag inkl. Time-Links (und setzt deren Status zurück auf 'offen') */
function delete_item_with_times(PDO $pdo, int $account_id, int $invoice_id, int $item_id): void {
  $ts = $pdo->prepare("SELECT time_id FROM invoice_item_times WHERE account_id = ? AND invoice_item_id = ?");
  $ts->execute([$account_id, $item_id]);
  $timeIds = array_map(fn($r)=>(int)$r['time_id'], $ts->fetchAll());

  $delL = $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id = ? AND invoice_item_id = ?");
  $delL->execute([$account_id, $item_id]);

  $delI = $pdo->prepare("DELETE FROM invoice_items WHERE account_id = ? AND id = ? AND invoice_id = ?");
  $delI->execute([$account_id, $item_id, $invoice_id]);

  if ($timeIds) {
    $in = implode(',', array_fill(0, count($timeIds), '?'));
    $params = array_merge([$account_id], $timeIds);
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
    $pdo->prepare($sql)->execute(array_merge([$account_id], $timeIds, [$account_id], $timeIds));
  }
}

// Robuste Parser (fallen auf deine utils-Funktionen zurück)
$NUM = function($v): float {
  if ($v === null || $v === '') return 0.0;
  if (is_numeric($v)) return (float)$v;     // "1.5" oder "2" etc.
  return (float)dec($v);                    // z. B. "1,5"
};
$HOURS = function($v) use ($NUM): float {
  $s = (string)($v ?? '');
  if (strpos($s, ':') !== false) {
    // "hh:mm" → utils
    return (float)parse_hours_to_decimal($s);
  }
  return $NUM($s); // "1.5" oder "1,5"
};

// ---------- POST: Speichern / Löschen ----------
$err = null; $ok = null;

// Items dürfen nur verändert werden, solange die Rechnung in Vorbereitung ist
$canEditItems = (($invoice['status'] ?? '') === 'in_vorbereitung');

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='save') {

  // Status aus Formular (whitelist)
  $allowed_status = ['in_vorbereitung','gestellt','gemahnt','bezahlt','storniert'];
  $new_status = $_POST['status'] ?? ($invoice['status'] ?? 'in_vorbereitung');
  if (!in_array($new_status, $allowed_status, true)) {
      $new_status = $invoice['status'] ?? 'in_vorbereitung';
  }

  $issue_date = $_POST['issue_date'] ?? ($invoice['issue_date'] ?? date('Y-m-d'));
  $due_date   = $_POST['due_date']   ?? $invoice['due_date'];
  $tax_reason = trim($_POST['tax_exemption_reason'] ?? '');

  $assign_number = false;
  $will_be_issued = in_array($new_status, ['gestellt','gemahnt','bezahlt','storniert'], true);
  if (empty($invoice['invoice_number']) && $will_be_issued) {
      $assign_number = true;
  }

  $number = $invoice['invoice_number'] ?? null;
  if ($assign_number) {
      $number = assign_invoice_number_if_needed($pdo, $account_id, (int)$invoice['id'], $issue_date);
  }

  // // Items dürfen nur verändert werden, solange die Rechnung in Vorbereitung ist
  // $canEditItems = (($invoice['status'] ?? '') === 'in_vorbereitung');

  $itemsPosted   = $_POST['items'] ?? [];          // existierende Items (Partial)
  $deletedPosted = $_POST['items_deleted'] ?? [];  // array von invoice_item.id

  // Zusatzpositionen (mit Toggle)
  // akzeptiere sowohl extras[...] als auch extra[...]
  $extrasPosted  = $_POST['extras'] ?? ($_POST['extra'] ?? []);

  if (!$err) {
    $pdo->beginTransaction();
    try {
      $sum_net   = 0.0;
      $sum_gross = 0.0;
      $hasNonStandard = false;

      if ($canEditItems) {
        // -----------------------
        // 1) GELÖSCHTE POSITIONEN
        // -----------------------
        if ($deletedPosted) {
          $deletedIds = array_values(array_filter(array_map('intval', (array)$deletedPosted), fn($v)=>$v>0));
          if ($deletedIds) {
            $inDel = implode(',', array_fill(0, count($deletedIds), '?'));

            $getTimes = $pdo->prepare("SELECT time_id FROM invoice_item_times WHERE account_id=? AND invoice_item_id IN ($inDel)");
            $getTimes->execute(array_merge([$account_id], $deletedIds));
            $toOpen = array_map(fn($r)=>(int)$r['time_id'], $getTimes->fetchAll());

            $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id=? AND invoice_item_id IN ($inDel)")
                ->execute(array_merge([$account_id], $deletedIds));

            $pdo->prepare("DELETE FROM invoice_items WHERE account_id=? AND invoice_id=? AND id IN ($inDel)")
                ->execute(array_merge([$account_id, (int)$invoice['id']], $deletedIds));

            if ($toOpen) {
              $toOpen = array_values(array_unique(array_filter($toOpen, fn($v)=>$v>0)));
              $inTimes = implode(',', array_fill(0, count($toOpen), '?'));
              $sql = "
                UPDATE times t
                LEFT JOIN invoice_item_times iit
                  ON iit.account_id=t.account_id AND iit.time_id=t.id
                SET t.status='offen'
                WHERE t.account_id=? AND t.id IN ($inTimes) AND iit.time_id IS NULL
              ";
              $pdo->prepare($sql)->execute(array_merge([$account_id], $toOpen));
            }
          }
        }

        // -------------------------------------------
        // 2) EXISTIERENDE POSITIONEN AKTUALISIEREN
        // -------------------------------------------
        $updItem = $pdo->prepare("
          UPDATE invoice_items
            SET description=?, unit_price=?, vat_rate=?, quantity=?, total_net=?, total_gross=?, tax_scheme=?,entry_mode=?
          WHERE account_id=? AND invoice_id=? AND id=?
        ");

        $getCurrTimes = $pdo->prepare("
          SELECT time_id FROM invoice_item_times
          WHERE account_id=? AND invoice_item_id=?
        ");
        $addLink = $pdo->prepare("INSERT INTO invoice_item_times (account_id, invoice_item_id, time_id) VALUES (?,?,?)");
        $delLink = $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id=? AND invoice_item_id=? AND time_id=?");

        // Hilfs-SELECT: bestehende Menge/Preis aus DB laden (für Fixed-Items)
        $existingMap = [];
        if (!empty($itemsPosted)) {
          $ids = array_values(array_filter(array_map(fn($r)=> (int)($r['id'] ?? 0), $itemsPosted), fn($v)=>$v>0));
          if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stExist = $pdo->prepare("
              SELECT id, quantity, unit_price
              FROM invoice_items
              WHERE account_id=? AND invoice_id=? AND id IN ($ph)
            ");
            $stExist->execute(array_merge([$account_id, (int)$invoice['id']], $ids));
            foreach ($stExist->fetchAll() as $r) {
              $existingMap[(int)$r['id']] = [
                'quantity'   => (float)$r['quantity'],
                'unit_price' => (float)$r['unit_price'],
              ];
            }
          }
        }

        $sum_minutes = function(PDO $pdo, int $account_id, array $ids): int {
          $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
          if (!$ids) return 0;
          $in = implode(',', array_fill(0, count($ids), '?'));
          $st = $pdo->prepare("SELECT COALESCE(SUM(minutes),0) FROM times WHERE account_id=? AND id IN ($in)");
          $st->execute(array_merge([$account_id], $ids));
          return (int)$st->fetchColumn();
        };

        foreach ($itemsPosted as $row) {
          $item_id = isset($row['id']) ? (int)$row['id'] : 0;
          if ($item_id <= 0) continue;

          $desc = trim($row['description'] ?? '');
          $rate = dec($row['hourly_rate'] ?? 0);

          $scheme = $row['tax_scheme'] ?? 'standard';
          if ($scheme !== 'standard') { $hasNonStandard = true; }

          $vatStr = $row['vat_rate'] ?? ($row['tax_rate'] ?? '');
          $vat    = dec($vatStr);
          if ($scheme && $scheme !== 'standard') { $vat = 0.0; }
          $vat = max(0.0, min(100.0, $vat));

          // Gewählte Times aus dem Formular
          $newTimes = array_values(array_filter(array_map('intval', $row['time_ids'] ?? []), fn($v)=>$v>0));

          // Aktuell verlinkte Times aus der DB
          $getCurrTimes->execute([$account_id, $item_id]);
          $currTimes = array_map(fn($r)=>(int)$r['time_id'], $getCurrTimes->fetchAll());

          // Diff anwenden (Links setzen/löschen + Status)
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

          // --- Kern: Berechnung unterscheiden ---
          // --- Kern: Modus & Berechnung ---
          // Gewählte Times aus dem Formular
          $newTimes = array_values(array_filter(array_map('intval', $row['time_ids'] ?? []), fn($v)=>$v>0));

          // Aktuell verlinkte Times aus der DB (bleibt wie gehabt)
          $getCurrTimes->execute([$account_id, $item_id]);
          $currTimes = array_map(fn($r)=>(int)$r['time_id'], $getCurrTimes->fetchAll());

          // Links add/remove & Status (dein bestehender Code bleibt)

          // Modus: mit Times immer 'auto', sonst das, was das Formular schickt (qty|time)
          $postedMode = strtolower(trim($row['entry_mode'] ?? 'qty'));
          $entry_mode = !empty($newTimes) ? 'auto' : (in_array($postedMode, ['time','qty'], true) ? $postedMode : 'qty');

          // Preise/Steuern
          $unit = (float)dec($row['hourly_rate'] ?? 0); // unit_price
          $vat  = (float)dec($row['vat_rate']     ?? ($row['tax_rate'] ?? 0));
          if ($scheme && $scheme !== 'standard') { $vat = 0.0; }
          $vat = max(0.0, min(100.0, $vat));

          // Menge & Summen je nach Modus
          if ($entry_mode === 'auto') {
            // minutenbasiert
            $minutes = 0;
            if (!empty($newTimes)) {
              $in = implode(',', array_fill(0, count($newTimes), '?'));
              $st = $pdo->prepare("SELECT COALESCE(SUM(minutes),0) FROM times WHERE account_id=? AND id IN ($in)");
              $st->execute(array_merge([$account_id], $newTimes));
              $minutes = (int)$st->fetchColumn();
            }
            $qty   = round($minutes / 60.0, 3);                    // DEC(10,3)
            $net   = round(($minutes / 60.0) * $unit, 2);          // aus Rohstunden
            $gross = round($net * (1 + $vat/100), 2);
          } elseif ($entry_mode === 'time') {
            // Stundenmodus (Form liefert hidden quantity = Dezimalstunden; Fallback: "hh:mm")
            $qty_hours = ($row['quantity'] ?? '') !== ''
              ? (float)dec($row['quantity'])
              : (float)parse_hours_to_decimal($row['hours'] ?? '0');
            $qty   = round($qty_hours, 3);
            $net   = round($qty_hours * $unit, 2);
            $gross = round($net * (1 + $vat/100), 2);
          } else { // qty
            $qty_dec = (float)dec($row['quantity'] ?? 0);
            $qty   = round($qty_dec, 3);
            $net   = round($qty_dec * $unit, 2);
            $gross = round($net * (1 + $vat/100), 2);
          }

          // Update inkl. entry_mode
          $updItem->execute([
            $desc, $unit, $vat, $qty, $net, $gross, ($scheme ?? 'standard'), $entry_mode,
            $account_id, (int)$invoice['id'], $item_id
          ]);
        }

        // -------------------------------------------
        // 2b) ZUSÄTZLICHE POSITIONEN EINFÜGEN (ohne Times)
        // Erwartet: extras[idx][description|mode|hours|hourly_rate|quantity|unit_price|tax_scheme|vat_rate]
        // -------------------------------------------
        if (!empty($extrasPosted) && is_array($extrasPosted)) {
          // nächste Position ermitteln
          $posSt = $pdo->prepare("SELECT COALESCE(MAX(position),0) FROM invoice_items WHERE account_id=? AND invoice_id=?");
          $posSt->execute([$account_id, (int)$invoice['id']]);
          $pos = (int)$posSt->fetchColumn() + 1;

          $insExtra = $pdo->prepare("
            INSERT INTO invoice_items
              (account_id, invoice_id, project_id, task_id, description,
               quantity, unit_price, vat_rate, total_net, total_gross, position, tax_scheme, entry_mode)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
          ");

          foreach ($extrasPosted as $row) {
            $desc = trim($row['description'] ?? '');
            $entry_mode = ($row['mode'] ?? 'qty');

            // Mengen/Preise robust parsen
            if ($entry_mode === 'time') {
              $qty_hours = $HOURS($row['hours'] ?? '0');       // "1.5" oder "01:30"
              $price     = $NUM($row['hourly_rate'] ?? 0);
            } else {
              $qty_hours = $NUM($row['quantity'] ?? 0);
              $price     = $NUM($row['unit_price'] ?? 0);
            }

            $scheme = $row['tax_scheme'] ?? 'standard';
            $vat    = $NUM($row['vat_rate']  ?? '');

            // Nur nicht-standard erzwingt Begründung (wie von dir spezifiziert)
            if ($scheme !== 'standard') {
              $hasNonStandard = true;
              $vat = 0.0; // Sicherheit: nicht-standard => 0%
            }

            // Berechnung (immer serverseitig, saubere Rundung)
            $qty   = round($qty_hours, 3);              // DECIMAL(10,3)
            $net   = round($qty_hours * $price, 2);     // aus qty_hours, nicht aus qty!
            $gross = round($net * (1 + max(0.0, min(100.0, $vat))/100), 2);

            // komplett leere Zeile ignorieren
            if ($desc === '' && $qty <= 0 && $price <= 0) { continue; }



            $insExtra->execute([
              $account_id, (int)$invoice['id'], null, null, $desc,
              (float)$qty, (float)$price, (float)$vat, (float)$net, (float)$gross, (int)$pos++, $scheme, $entry_mode
            ]);
          }
        }
      }
      else {
        if (!empty($extrasPosted) && is_array($extrasPosted)) {
            flash("Die Rechnung hat bereits den Status \"gestellt\". Es kann daher keine Position hinzugefügt werden.", "danger");
        }
        // Wenn Positionen nicht editierbar sind, aus der DB prüfen
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM invoice_items WHERE account_id=? AND invoice_id=? AND (tax_scheme IS NOT NULL AND tax_scheme <> 'standard')");
        $cnt->execute([$account_id, (int)$invoice['id']]);
        $hasNonStandard = ((int)$cnt->fetchColumn()) > 0;
      }

      // Wenn mind. eine Position nicht-standard ist, Begründung serverseitig erzwingen
      if ($hasNonStandard && $tax_reason === '') {
        throw new RuntimeException('Bitte Begründung für die Steuerbefreiung angeben.');
      }

      // -------------------------------------------
      // 3) Summen und Status updaten
      // -------------------------------------------
      $sumSt = $pdo->prepare("
        SELECT COALESCE(SUM(total_net),0), COALESCE(SUM(total_gross),0)
        FROM invoice_items WHERE account_id=? AND invoice_id=?");
      $sumSt->execute([$account_id, (int)$invoice['id']]);
      [$sum_net, $sum_gross] = $sumSt->fetch(PDO::FETCH_NUM);

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

      $pdo->commit();
      $ok = 'Rechnung gespeichert.';
      redirect(url('/invoices/edit.php').'?id='.(int)$invoice['id']);
      exit; // wichtig, damit kein weiterer Output nach dem Redirect kommt
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

// Für _items_table.php (EDIT-Modus)
$items  = [];
foreach ($rawItems as $r) {
  $iid = (int)$r['item_id'];
  $items[] = [
    'id'            => $iid,
    'project_id'    => (int)($r['project_id'] ?? 0),
    'project_title' => (string)($r['project_title'] ?? ''),
    'description'   => (string)($r['description'] ?? ''),
    'hourly_rate'   => (float)($r['unit_price'] ?? 0),
    'vat_rate'      => (float)($r['vat_rate'] ?? 0),
    'tax_scheme'    => $r['tax_scheme'] ?? null,
    'quantity'      => (float)($r['quantity'] ?? 0),
    'entry_mode'    => $r['entry_mode'] ?? null,
    // ⬇️ NEU: die gespeicherten Summen wirklich an das Partial übergeben
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
            <h5 class="card-title">Positionen / Zeiten</h5>
            <?php
              $mode = 'edit';
              $rowName  = 'items';
              $timesName = 'time_ids';
              require __DIR__ . '/_items_table.php';
            ?>
          </div>
        </div>
      </div>

      <!-- Zusätzliche Positionen (mit Toggle) -->
      <div class="col-12">
        <div class="card mt-3">
          <div class="card-body">
            <h5 class="card-title d-flex justify-content-between align-items-center">
              <span>Zusätzliche Positionen hinzufügen</span>
              <button <?php if (!$canEditItems) echo " disabled ";?> class="btn btn-sm btn-outline-primary" type="button" id="addExtra">+ Position</button>
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
                      <input type="number" step="0.25" class="form-control extra-quantity" name="__NAME__[quantity]" value="1">
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
      </div>

      <div class="col-12 text-end">
        <a class="btn btn-outline-secondary" href="<?=h(url($return_to))?>">Abbrechen</a>
        <button class="btn btn-primary">Speichern</button>
      </div>
    </form>
  </div>
</div>

<script>
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

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>