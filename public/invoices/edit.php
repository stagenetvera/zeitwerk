<?php
// public/invoices/edit.php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($v){ return number_format((float)$v, 2, ',', '.'); }
function fmt_minutes_hhmm(int $m): string { $h=intdiv($m,60); $r=$m%60; return sprintf('%02d:%02d',$h,$r); }

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

if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Einzelnes Item löschen?
  if (isset($_POST['delete_item_id']) && ctype_digit((string)$_POST['delete_item_id'])) {
    try {
      $pdo->beginTransaction();
      delete_item_with_times($pdo, $account_id, $invoice_id, (int)$_POST['delete_item_id']);
      // Summen neu berechnen (später unten, nach generellem Recalc)
      $pdo->commit();
      $ok = 'Position gelöscht.';
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = 'Löschen fehlgeschlagen: '.$e->getMessage();
    }
  }
  // „Delete“-Flags aus dem großen Formular (falls dein Partial so postet)
  if (isset($_POST['items']) && is_array($_POST['items'])) {
    foreach ($_POST['items'] as $iid => $row) {
      if (!empty($row['delete']) && ctype_digit((string)$iid)) {
        try {
          $pdo->beginTransaction();
          delete_item_with_times($pdo, $account_id, $invoice_id, (int)$iid);
          $pdo->commit();
          $ok = 'Position gelöscht.';
        } catch (Throwable $e) {
          $pdo->rollBack();
          $err = 'Löschen fehlgeschlagen: '.$e->getMessage();
        }
      }
    }
  }

  // Allgemeines Speichern
  if (isset($_POST['action']) && $_POST['action']==='save') {
    $issue_date = $_POST['issue_date'] ?? $invoice['issue_date'];
    $due_date   = $_POST['due_date']   ?? $invoice['due_date'];
    $status     = $_POST['status']     ?? $invoice['status'];

    $itemsPost  = $_POST['items'] ?? [];             // aus Partial (edit-Modus)
    $timesPost  = $_POST['times_selected'] ?? [];    // aus Partial (edit-Modus)
    // Manuelle neue Positionen (optional, eigener Editor unten – identisch wie in new.php)
    $manual     = $_POST['manual'] ?? [];

    try {
      $pdo->beginTransaction();

      // 1) Header updaten
      $updInv = $pdo->prepare("UPDATE invoices SET issue_date = ?, due_date = ?, status = ? WHERE id = ? AND account_id = ?");
      $updInv->execute([$issue_date, $due_date, $status, $invoice_id, $account_id]);

      // 2) Bestehende Items updaten & Time-Links synchronisieren
      // Bestehende Items laden (für Differenzen)
      $dbItems = load_invoice_items($pdo, $account_id, $invoice_id);
      $mapDb   = [];
      foreach ($dbItems as $di) { $mapDb[(int)$di['item_id']] = $di; }

      // Aktuelle Links
      $timesByItem = load_times_by_item($pdo, $account_id, array_keys($mapDb));

      $upItem = $pdo->prepare("
        UPDATE invoice_items
           SET description = ?, unit_price = ?, vat_rate = ?
         WHERE account_id = ? AND id = ? AND invoice_id = ?
      ");
      $delLink = $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id = ? AND invoice_item_id = ? AND time_id = ?");
      $addLink = $pdo->prepare("INSERT IGNORE INTO invoice_item_times (account_id, invoice_item_id, time_id) VALUES (?,?,?)");

      // Für Netto/Brutto-Neuberechnung
      $recalc = $pdo->prepare("
        UPDATE invoice_items
           SET quantity = ?, total_net = ?, total_gross = ?
         WHERE account_id = ? AND id = ? AND invoice_id = ?
      ");

      foreach ($itemsPost as $iid => $row) {
        if (!ctype_digit((string)$iid)) continue;
        $iid = (int)$iid;
        if (!isset($mapDb[$iid])) continue; // gehört nicht zu dieser Rechnung
        $db = $mapDb[$iid];

        $desc = trim($row['description'] ?? $db['description']);
        $rate = (float)($row['rate'] ?? $db['unit_price']);
        $vat  = (float)($row['vat']  ?? $db['vat_rate']);

        $upItem->execute([$desc, $rate, $vat, $account_id, $iid, $invoice_id]);

        // Time-Links synchronisieren (nur wenn im POST vorhanden)
        $postedTimes = [];
        if (isset($timesPost[$iid]) && is_array($timesPost[$iid])) {
          foreach ($timesPost[$iid] as $tid) {
            $tid = (int)$tid; if ($tid>0) $postedTimes[$tid] = true;
          }
          // Bisher verlinkte Zeiten
          $current = [];
          foreach ($timesByItem[$iid] ?? [] as $t) {
            $current[(int)$t['id']] = true;
          }
          // Entfernen
          foreach ($current as $tid => $_) {
            if (!isset($postedTimes[$tid])) {
              $delLink->execute([$account_id, $iid, $tid]);
              // Falls Zeit nirgends mehr verlinkt -> Status zurück auf 'offen'
              $chk = $pdo->prepare("SELECT COUNT(*) FROM invoice_item_times WHERE account_id = ? AND time_id = ?");
              $chk->execute([$account_id, $tid]);
              if ((int)$chk->fetchColumn() === 0) {
                $pdo->prepare("UPDATE times SET status='offen' WHERE account_id=? AND id=?")->execute([$account_id, $tid]);
              }
            }
          }
          // Hinzufügen
          foreach ($postedTimes as $tid => $_) {
            if (!isset($current[$tid])) {
              $addLink->execute([$account_id, $iid, $tid]);
              // Falls Rechnung noch in Vorbereitung -> Zeit auf 'in_abrechnung'
              if ($status === 'in_vorbereitung') {
                $pdo->prepare("UPDATE times SET status='in_abrechnung' WHERE account_id=? AND id=?")->execute([$account_id, $tid]);
              }
            }
          }
          // Neu laden für Minuten-Berechnung
          $timesByItem[$iid] = load_times_by_item($pdo, $account_id, [$iid])[$iid] ?? [];
        }

        // Menge (Stunden) und Summen neu berechnen
        $minutes = 0;
        foreach ($timesByItem[$iid] ?? [] as $t) { $minutes += (int)$t['minutes']; }
        // Wenn keine Times (manuelle Position) -> Menge unverändert aus DB
        $qty = $minutes > 0 ? round($minutes/60, 2) : (float)$db['quantity'];
        $net = round($qty * $rate, 2);
        $gross = round($net * (1 + $vat/100), 2);
        $recalc->execute([$qty, $net, $gross, $account_id, $iid, $invoice_id]);
      }

      // 3) Neue manuelle Positionen (falls aus Editor unten hinzugefügt)
      if (is_array($manual)) {
        $posMax = $pdo->prepare("SELECT COALESCE(MAX(position),0) FROM invoice_items WHERE account_id = ? AND invoice_id = ?");
        $posMax->execute([$account_id, $invoice_id]);
        $pos = (int)$posMax->fetchColumn() + 1;

        $insItem = $pdo->prepare("
          INSERT INTO invoice_items
            (account_id, invoice_id, project_id, task_id, description, quantity, unit_price, vat_rate, total_net, total_gross, position)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        foreach ($manual as $m) {
          $mdesc = trim($m['description'] ?? '');
          if ($mdesc === '') continue;
          $mqty   = (float)($m['quantity'] ?? 0);
          $mprice = (float)($m['unit_price'] ?? 0);
          $mvat   = (float)($m['vat_rate'] ?? 19.0);
          $mnet   = round($mqty * $mprice, 2);
          $mgross = round($mnet * (1 + $mvat/100), 2);
          $insItem->execute([$account_id, $invoice_id, null, null, $mdesc, $mqty, $mprice, $mvat, $mnet, $mgross, $pos++]);
        }
      }

      // 4) Gesamtsummen der Rechnung neu berechnen
      $sum = $pdo->prepare("SELECT COALESCE(SUM(total_net),0), COALESCE(SUM(total_gross),0) FROM invoice_items WHERE account_id=? AND invoice_id=?");
      $sum->execute([$account_id, $invoice_id]);
      [$total_net, $total_gross] = $sum->fetch(PDO::FETCH_NUM);
      $pdo->prepare("UPDATE invoices SET total_net=?, total_gross=? WHERE account_id=? AND id=?")
          ->execute([(float)$total_net, (float)$total_gross, $account_id, $invoice_id]);

      // 5) Zeit-Status je nach Rechnungsstatus
      if ($status === 'in_vorbereitung') {
        set_times_status_for_invoice($pdo, $account_id, $invoice_id, 'in_abrechnung');
      } elseif (in_array($status, ['gestellt','gemahnt','bezahlt'], true)) {
        set_times_status_for_invoice($pdo, $account_id, $invoice_id, 'abgerechnet');
      } elseif ($status === 'storniert') {
        set_times_status_for_invoice($pdo, $account_id, $invoice_id, 'offen');
      }

      $pdo->commit();
      $ok = 'Rechnung gespeichert.';
      // Neu laden aktueller Daten unten
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = 'Speichern fehlgeschlagen: '.$e->getMessage();
    }
  }
}

// ---------- Anzeige-Daten laden (nach evtl. Save/Delete) ----------
$invoice = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND account_id = ?");
$invoice->execute([$invoice_id, $account_id]);
$invoice = $invoice->fetch();

$items = load_invoice_items($pdo, $account_id, $invoice_id);
$itemIds = array_map(fn($r)=>(int)$r['item_id'], $items);
$timesByItem = load_times_by_item($pdo, $account_id, $itemIds);

// Für Partial $groups nach Projekt gruppieren
$byProj = [];
foreach ($items as $r) {
  $pid = (int)($r['project_id'] ?? 0);
  $ptitle = $r['project_title'] ?? '';
  if (!isset($byProj[$pid])) {
    $byProj[$pid] = ['project_id'=>$pid, 'project_title'=>$ptitle, 'rows'=>[]];
  }
  $iid = (int)$r['item_id'];

  // „Row“-Struktur so benennen, wie das Partial es vom NEW-Flow kennt:
  $times = $timesByItem[$iid] ?? [];
  $byProj[$pid]['rows'][] = [
    'item_id'     => $iid,                      // echte Item-ID
    'task_id'     => $iid,                      // Alias für Partial-Names
    'task_desc'   => (string)$r['description'], // Textfeld
    'description' => (string)$r['description'],
    'unit_price'  => (float)$r['unit_price'],
    'vat_rate'    => (float)$r['vat_rate'],
    'quantity'    => (float)$r['quantity'],
    'hourly_rate' => (float)$r['unit_price'],   // fürs Partial
    'tax_rate'    => (float)$r['vat_rate'],     // fürs Partial
    'times'       => $times,                    // expandierbare Liste
  ];
}
$groups = array_values($byProj);

// ---------- View ----------
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Rechnung bearbeiten</h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?=hurl($return_to)?>">Zurück</a>
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
              // Partial wie im NEW-Flow – nur Modus/Name-Mapping setzen:
              $mode = 'edit';
              $rowName = 'items';               // -> name="items[<id>][...]"
              $timesName = 'times_selected';    // -> name="times_selected[<id>][]"
              require __DIR__ . '/_items_table.php';
            ?>

            <div class="mt-3 text-end">
              <button class="btn btn-primary">Speichern</button>
            </div>
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
        <a class="btn btn-outline-secondary" href="<?=hurl($return_to)?>">Abbrechen</a>
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