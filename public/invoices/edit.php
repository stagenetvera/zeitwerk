<?php
// public/invoices/edit.php
require __DIR__ . '/../../src/layout/header.php';
require_once __DIR__ . '/../../src/lib/invoice_number.php';
require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($v){ return number_format((float)$v, 2, ',', '.'); }
function fmt_minutes_hhmm(int $m): string { $h=intdiv($m,60); $r=$m%60; return sprintf('%02d:%02d',$h,$r); }

// Komma-Dezimal in Float
function dec($s) {
  if ($s === null) return 0.0;
  if (is_float($s) || is_int($s)) return (float)$s;
  $s = str_replace(['.', ','], ['', '.'], (string)$s);
  $n = (float)$s;
  return is_finite($n) ? $n : 0.0;
}

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
$cstmt = $pdo->prepare("SELECT id, name FROM companies WHERE id = ? AND account_id = ?");
$cstmt->execute([$company_id, $account_id]);
$company = $cstmt->fetch();

// ---------- Hilfsfunktionen DB ----------
/** Liefert alle Items der Rechnung mit Projekt-Infos */
function load_invoice_items(PDO $pdo, int $account_id, int $invoice_id): array {
  $st = $pdo->prepare("
    SELECT
      ii.id AS item_id, ii.project_id, ii.task_id, ii.description,
      ii.quantity, ii.unit_price, ii.vat_rate, ii.position,
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
  // Zielstatus auf verlinkte Zeiten der Rechnung anwenden
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
  // Welche Zeiten hängen dran?
  $ts = $pdo->prepare("SELECT time_id FROM invoice_item_times WHERE account_id = ? AND invoice_item_id = ?");
  $ts->execute([$account_id, $item_id]);
  $timeIds = array_map(fn($r)=>(int)$r['time_id'], $ts->fetchAll());

  // Links löschen
  $delL = $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id = ? AND invoice_item_id = ?");
  $delL->execute([$account_id, $item_id]);

  // Item löschen (nur falls zur gleichen Rechnung gehört)
  $delI = $pdo->prepare("DELETE FROM invoice_items WHERE account_id = ? AND id = ? AND invoice_id = ?");
  $delI->execute([$account_id, $item_id, $invoice_id]);

  // Zeiten-Status ggf. zurücksetzen (nur solche, die nun an KEINEM Item mehr hängen)
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
    // params: [acc, timeIds..., acc, timeIds...]
    $pdo->prepare($sql)->execute(array_merge([$account_id], $timeIds, [$account_id], $timeIds));
  }
}

// ---------- POST: Speichern / Löschen ----------
$err = null; $ok = null;

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='save') {

     // Status aus Formular (whitelist)
    $allowed_status = ['in_vorbereitung','gestellt','gemahnt','bezahlt','storniert'];
    $new_status = $_POST['status'] ?? ($invoice['status'] ?? 'in_vorbereitung');
    if (!in_array($new_status, $allowed_status, true)) {
        $new_status = $invoice['status'] ?? 'in_vorbereitung';
    }

    $issue_date = $_POST['issue_date'] ?? ($invoice['issue_date'] ?? date('Y-m-d'));
    $due_date   = $_POST['due_date']   ?? $invoice['due_date'];

    $assign_number = false;
    $will_be_issued = in_array($new_status, ['gestellt','gemahnt','bezahlt','storniert'], true);

    if (empty($invoice['invoice_number']) && $will_be_issued) {
        $assign_number = true;
    }

    $number = $invoice['invoice_number'] ?? null;
    if ($assign_number) {
        $number = assign_invoice_number_if_needed($pdo, $account_id, (int)$invoice['id'], $issue_date);
    }



  // Items dürfen nur verändert werden, solange die Rechnung in Vorbereitung ist
  $canEditItems = (($invoice['status'] ?? '') === 'in_vorbereitung');

  $itemsPosted   = $_POST['items'] ?? [];          // items[idx][id|description|hourly_rate|tax_rate|time_ids[]]
  $deletedPosted = $_POST['items_deleted'] ?? [];  // array von invoice_item.id

  if (!$err) {
    $pdo->beginTransaction();
    try {
      $sum_net   = 0.0;
      $sum_gross = 0.0;


      if ($canEditItems) {
        // -----------------------
        // 1) GELÖSCHTE POSITIONEN
        // -----------------------
        if ($deletedPosted) {
          $deletedIds = array_values(array_filter(array_map('intval', (array)$deletedPosted), fn($v)=>$v>0));
          if ($deletedIds) {
            $inDel = implode(',', array_fill(0, count($deletedIds), '?'));

            // Time-IDs der Items ermitteln
            $getTimes = $pdo->prepare("
              SELECT time_id FROM invoice_item_times
              WHERE account_id=? AND invoice_item_id IN ($inDel)
            ");
            $getTimes->execute(array_merge([$account_id], $deletedIds));
            $toOpen = array_map(fn($r)=>(int)$r['time_id'], $getTimes->fetchAll());

            // Links löschen
            $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id=? AND invoice_item_id IN ($inDel)")
                ->execute(array_merge([$account_id], $deletedIds));

            // Items löschen
            $pdo->prepare("DELETE FROM invoice_items WHERE account_id=? AND invoice_id=? AND id IN ($inDel)")
                ->execute(array_merge([$account_id, (int)$invoice['id']], $deletedIds));

            // Zeiten zurück auf 'offen', aber nur wenn sie nirgendwo mehr verknüpft sind
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
             SET description=?, unit_price=?, vat_rate=?, quantity=?, total_net=?, total_gross=?
           WHERE account_id=? AND invoice_id=? AND id=?
        ");

        $getCurrTimes = $pdo->prepare("
          SELECT time_id FROM invoice_item_times
          WHERE account_id=? AND invoice_item_id=?
        ");
        $addLink = $pdo->prepare("INSERT INTO invoice_item_times (account_id, invoice_item_id, time_id) VALUES (?,?,?)");
        $delLink = $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id=? AND invoice_item_id=? AND time_id=?");

        // Hilfsfunktion
        $dec = function($s){
          if ($s === null) return 0.0;
          if (is_float($s) || is_int($s)) return (float)$s;
          $s = str_replace(['.', ','], ['', '.'], (string)$s);
          $n = (float)$s;
          return is_finite($n) ? $n : 0.0;
        };
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
          $rate = $dec($row['hourly_rate'] ?? 0);
          $vat  = $dec($row['tax_rate'] ?? 19.0);

          $newTimes = array_values(array_filter(array_map('intval', $row['time_ids'] ?? []), fn($v)=>$v>0));

          // Aktuell verlinkte Zeiten
          $getCurrTimes->execute([$account_id, $item_id]);
          $currTimes = array_map(fn($r)=>(int)$r['time_id'], $getCurrTimes->fetchAll());

          // Diff
          $toAdd    = array_values(array_diff($newTimes, $currTimes));
          $toRemove = array_values(array_diff($currTimes, $newTimes));

          // Minuten neu berechnen
          $minutes = $sum_minutes($pdo, $account_id, $newTimes);
          $qty     = round($minutes/60, 2);
          $net     = round($qty * $rate, 2);
          $gross   = round($net * (1 + $vat/100), 2);

          // Item aktualisieren
          $updItem->execute([
            $desc, $rate, $vat, $qty, $net, $gross,
            $account_id, (int)$invoice['id'], $item_id
          ]);

          // Links + Status
          if ($toAdd) {
            foreach ($toAdd as $tid) { $addLink->execute([$account_id, $item_id, $tid]); }
            $ph = implode(',', array_fill(0, count($toAdd), '?'));
            $pdo->prepare("UPDATE times SET status='in_abrechnung' WHERE account_id=? AND id IN ($ph)")
                ->execute(array_merge([$account_id], $toAdd));
          }
          if ($toRemove) {
            foreach ($toRemove as $tid) { $delLink->execute([$account_id, $item_id, $tid]); }
            $ph = implode(',', array_fill(0, count($toRemove), '?'));
            // Nur verwaiste Zeiten zurück auf 'offen'
            $sqlOpen = "
              UPDATE times t
              LEFT JOIN invoice_item_times iit
                ON iit.account_id=t.account_id AND iit.time_id=t.id
              SET t.status='offen'
              WHERE t.account_id=? AND t.id IN ($ph) AND iit.time_id IS NULL
            ";
            $pdo->prepare($sqlOpen)->execute(array_merge([$account_id], $toRemove));
          }
        }
      }

      // -------------------------------------------
      // 3) Summen und Status updaten
      // -------------------------------------------
      // Summen immer sauber aus invoice_items ziehen
      $sumSt = $pdo->prepare("SELECT COALESCE(SUM(total_net),0), COALESCE(SUM(total_gross),0)
                              FROM invoice_items WHERE account_id=? AND invoice_id=?");
      $sumSt->execute([$account_id, (int)$invoice['id']]);
      [$sum_net, $sum_gross] = $sumSt->fetch(PDO::FETCH_NUM);

      // Rechnungskopf inkl. Status speichern

      if ($assign_number) {
        $updInv = $pdo->prepare("
            UPDATE invoices
            SET issue_date=?, due_date=?, status=?, invoice_number=?, total_net=?, total_gross=?
            WHERE account_id=? AND id=?
        ");
        $updInv->execute([$issue_date, $due_date, $new_status, $number, $sum_net, $sum_gross, $account_id, (int)$invoice['id']]);
      } else {
        $updInv = $pdo->prepare("
            UPDATE invoices
            SET issue_date=?, due_date=?, status=?, total_net=?, total_gross=?
            WHERE account_id=? AND id=?
        ");
        $updInv->execute([$issue_date, $due_date, $new_status, $sum_net, $sum_gross, $account_id, (int)$invoice['id']]);
      }

      // Times-Status an Rechnungsstatus anpassen (nur wenn Änderung)
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
      // Nach dem Speichern auf der gleichen Seite bleiben
      redirect(url('/invoices/edit.php').'?id='.(int)$invoice['id']);
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

/**
 * Das Partial rendert im EDIT-Modus, wenn $groups leer ist und $items befüllt sind.
 * Wir liefern $items im erwarteten Format (time_entries[] etc.).
 */
$items  = [];
foreach ($rawItems as $r) {
  $iid = (int)$r['item_id'];
  $items[] = [
    'id'            => $iid,
    'project_id'    => (int)($r['project_id'] ?? 0),
    'project_title' => (string)($r['project_title'] ?? ''),
    'description'   => (string)($r['description'] ?? ''),
    'hourly_rate'   => (float)($r['unit_price'] ?? 0),
    'tax_rate'      => (float)($r['vat_rate'] ?? 19),
    'quantity'      => (float)($r['quantity'] ?? 0),
    // EDIT-Part erwartet time_entries[] mit selected=true
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
// WICHTIG: $groups leer lassen, damit das Partial den EDIT-Zweig nimmt.
$groups = [];

// ---------- View ----------
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

      <div class="col-12">
        <div class="card mt-3">
          <div class="card-body">
            <h5 class="card-title">Positionen / Zeiten</h5>

            <?php
                // Partial im EDIT-Modus verwenden
                $mode = 'edit';
                $rowName = 'items';        // erzeugt name="items[...][...]"
                $timesName = 'time_ids';   // (vom EDIT-Part ignoriert, aber ok)
                require __DIR__ . '/_items_table.php';
            ?>

          </div>
        </div>
      </div>

      <!-- Optional: Neue manuelle Positionen ergänzen (wie in new.php) -->
      <div class="col-12">
        <div class="card mt-3">
          <div class="card-body">
            <h5 class="card-title d-flex justify-content-between align-items-center">
              <span>Manuelle Positionen hinzufügen</span>
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
                  <label class="form-label">Menge (Stunden)</label>
                  <input type="number" step="0.01" class="form-control" name="__NAME__[quantity]" value="1">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Einzelpreis (€)</label>
                  <input type="number" step="0.01" class="form-control" name="__NAME__[unit_price]" value="0.00">
                </div>
                <div class="col-md-1">
                  <label class="form-label">MwSt %</label>
                  <input type="number" step="0.01" class="form-control" name="__NAME__[vat_rate]" value="19.00">
                </div>
                <div class="col-md-1">
                  <button type="button" class="btn btn-outline-danger w-100 removeManual">–</button>
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
  // Manuelle Positionen (Add/Remove), identisch zu new.php
  const box = document.getElementById('manualBox');
  const tpl = document.getElementById('manualTpl');
  const add = document.getElementById('addManual');
  if (box && tpl && add) {
    let idx = 0;
    function addRow(){
      const html = tpl.innerHTML.replaceAll('__NAME__', 'manual['+(idx++)+']');
      const frag = document.createElement('div');
      frag.innerHTML = html;
      const row = frag.firstElementChild;
      box.appendChild(row);
      row.querySelector('.removeManual').addEventListener('click', function(){ row.remove(); });
    }
    add.addEventListener('click', addRow);
  }
})();
</script>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>