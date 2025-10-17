<?php
// public/invoices/edit.php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_eur($n){ return number_format((float)$n, 2, ',', '.'); }

// ----- Invoice laden -----
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0) { echo '<div class="alert alert-danger">Ungültige Rechnungs-ID.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

$inv = $pdo->prepare("SELECT i.*, c.name AS company_name
                      FROM invoices i
                      JOIN companies c ON c.id = i.company_id AND c.account_id = i.account_id
                      WHERE i.id = ? AND i.account_id = ?");
$inv->execute([$id, $account_id]);
$invoice = $inv->fetch();
if (!$invoice) { echo '<div class="alert alert-danger">Rechnung nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

$company_id = (int)$invoice['company_id'];
$err = null; $ok = null;

// ----- Export XML (GET) -----
if (isset($_GET['export']) && $_GET['export']==='xml') {
  // Daten für XML holen
  $it = $pdo->prepare("SELECT ii.*, p.title AS project_title
                       FROM invoice_items ii
                       LEFT JOIN projects p ON p.id = ii.project_id AND p.account_id = ?
                       WHERE ii.invoice_id = ? ORDER BY ii.id");
  $it->execute([$account_id, $id]);
  $items = $it->fetchAll();

  $map = $pdo->prepare("SELECT it.time_id, ti.started_at, ti.ended_at, ti.minutes, it.invoice_item_id
                        FROM invoice_item_times it
                        JOIN times ti ON ti.id = it.time_id AND ti.account_id = ?
                        WHERE it.invoice_item_id IN (SELECT id FROM invoice_items WHERE invoice_id = ?)");
  $map->execute([$account_id, $id]);
  $times = $map->fetchAll();

  header('Content-Type: application/xml; charset=UTF-8');
  header('Content-Disposition: attachment; filename="invoice_'.$id.'.xml"');

  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
  ?>
<invoice>
  <id><?= (int)$invoice['id'] ?></id>
  <company_id><?= (int)$invoice['company_id'] ?></company_id>
  <company_name><?= h($invoice['company_name']) ?></company_name>
  <status><?= h($invoice['status']) ?></status>
  <issue_date><?= h($invoice['issue_date']) ?></issue_date>
  <due_date><?= h($invoice['due_date']) ?></due_date>
  <total_net><?= number_format((float)$invoice['total_net'], 2, '.', '') ?></total_net>
  <total_gross><?= number_format((float)$invoice['total_gross'], 2, '.', '') ?></total_gross>
  <items>
    <?php foreach ($items as $it): ?>
    <item>
      <id><?= (int)$it['id'] ?></id>
      <project_title><?= h($it['project_title'] ?? '') ?></project_title>
      <description><?= h($it['description']) ?></description>
      <quantity><?= number_format((float)$it['quantity'], 3, '.', '') ?></quantity>
      <unit_price><?= number_format((float)$it['unit_price'], 2, '.', '') ?></unit_price>
      <vat_rate><?= number_format((float)$it['vat_rate'], 2, '.', '') ?></vat_rate>
      <total_net><?= number_format((float)$it['total_net'], 2, '.', '') ?></total_net>
      <total_gross><?= number_format((float)$it['total_gross'], 2, '.', '') ?></total_gross>
      <times>
        <?php foreach ($times as $t) if ((int)$t['invoice_item_id']===(int)$it['id']): ?>
        <time>
          <id><?= (int)$t['time_id'] ?></id>
          <started_at><?= h($t['started_at']) ?></started_at>
          <ended_at><?= h($t['ended_at']) ?></ended_at>
          <minutes><?= (int)$t['minutes'] ?></minutes>
        </time>
        <?php endif; ?>
      </times>
    </item>
    <?php endforeach; ?>
  </items>
</invoice>
<?php
  exit;
}

// ----- POST: Speichern / Statuswechsel / Zeit verschieben / Item hinzufügen -----
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'save_invoice') {
      $status = $_POST['status'] ?? $invoice['status'];
      $issue_date = $_POST['issue_date'] ?: $invoice['issue_date'];
      $due_date   = $_POST['due_date']   ?: $invoice['due_date'];

      $pdo->beginTransaction();

      // 1) Rechnung kopfdaten
      $upd = $pdo->prepare("UPDATE invoices SET status = ?, issue_date = ?, due_date = ? WHERE id = ? AND account_id = ?");
      $upd->execute([$status, $issue_date, $due_date, $id, $account_id]);

      // 2) Items aktualisieren
      if (isset($_POST['item']) && is_array($_POST['item'])) {
        $updIt = $pdo->prepare("UPDATE invoice_items
                                SET description = ?, quantity = ?, unit_price = ?, vat_rate = ?, total_net = ?, total_gross = ?
                                WHERE id = ? AND invoice_id = ?");

        $sum_net = 0.0; $sum_gross = 0.0;
        foreach ($_POST['item'] as $iid => $row) {
          $iid = (int)$iid;
          $desc = trim($row['description'] ?? '');
          $qty  = (float)str_replace(',', '.', $row['quantity'] ?? 0);
          $unit = (float)str_replace(',', '.', $row['unit_price'] ?? 0);
          $vat  = (float)str_replace(',', '.', $row['vat_rate'] ?? 19.0);

          $net = $qty * $unit;
          $gross = $net * (1.0 + $vat/100.0);

          $updIt->execute([$desc, $qty, $unit, $vat, $net, $gross, $iid, $id]);

          $sum_net   += $net;
          $sum_gross += $gross;
        }
        $pdo->prepare("UPDATE invoices SET total_net=?, total_gross=? WHERE id = ? AND account_id = ?")
            ->execute([$sum_net, $sum_gross, $id, $account_id]);
      }

      // 3) Statusfolge → Zeiten-Status anpassen
      if ($status === 'gestellt') {
        // Zeiten endgültig „abgerechnet“
        $pdo->prepare("
          UPDATE times ti
          JOIN invoice_item_times it ON it.time_id = ti.id
          JOIN invoice_items ii      ON ii.id = it.invoice_item_id
          SET ti.status = 'abgerechnet'
          WHERE ii.invoice_id = ? AND ti.account_id = ?
        ")->execute([$id, $account_id]);
      } elseif ($status === 'storniert') {
        // Vormerkung zurücknehmen
        $pdo->prepare("
          UPDATE times ti
          JOIN invoice_item_times it ON it.time_id = ti.id
          JOIN invoice_items ii      ON ii.id = it.invoice_item_id
          SET ti.status = 'offen'
          WHERE ii.invoice_id = ? AND ti.account_id = ?
        ")->execute([$id, $account_id]);
      } elseif ($status === 'in_vorbereitung' || $status === 'gemahnt' || $status === 'bezahlt') {
        // Nichts an Zeiten ändern
      }

      $pdo->commit();
      $ok = 'Rechnung gespeichert.';

    } elseif ($action === 'add_item') {
      // Manuelle Position hinzufügen
      $desc = trim($_POST['new_desc'] ?? '');
      $qty  = (float)str_replace(',', '.', $_POST['new_qty'] ?? '1');
      $unit = (float)str_replace(',', '.', $_POST['new_unit'] ?? '0');
      $vat  = (float)str_replace(',', '.', $_POST['new_vat'] ?? '19');

      if ($desc !== '') {
        $net = $qty * $unit;
        $gross = $net * (1.0 + $vat/100.0);

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO invoice_items (invoice_id, project_id, task_id, description, quantity, unit_price, vat_rate, total_net, total_gross)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$id, null, null, $desc, $qty, $unit, $vat, $net, $gross]);

        // Summen neu
        $recalc = $pdo->prepare("SELECT SUM(total_net) sn, SUM(total_gross) sg FROM invoice_items WHERE invoice_id = ?");
        $recalc->execute([$id]);
        $sums = $recalc->fetch();
        $pdo->prepare("UPDATE invoices SET total_net=?, total_gross=? WHERE id=? AND account_id=?")
            ->execute([(float)$sums['sn'], (float)$sums['sg'], $id, $account_id]);

        $pdo->commit();
        $ok = 'Position hinzugefügt.';
      }

    } elseif ($action === 'move_time') {
      // Eine Zeit von Position A nach B verschieben
      $time_id = isset($_POST['time_id']) ? (int)$_POST['time_id'] : 0;
      $to_item = isset($_POST['to_item']) ? (int)$_POST['to_item'] : 0;
      if ($time_id > 0 && $to_item > 0) {
        $pdo->beginTransaction();
        // Sicherheitscheck: Ziel-Item gehört zu dieser Rechnung
        $own = $pdo->prepare("SELECT COUNT(*) FROM invoice_items WHERE id=? AND invoice_id=?");
        $own->execute([$to_item, $id]);
        if ($own->fetchColumn()) {
          // umhängen
          $pdo->prepare("UPDATE invoice_item_times SET invoice_item_id = ? WHERE time_id = ? AND invoice_item_id IN (SELECT id FROM invoice_items WHERE invoice_id = ?)")
              ->execute([$to_item, $time_id, $id]);
          $ok = 'Zeit verschoben.';
        }
        $pdo->commit();
      }
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = 'Aktion fehlgeschlagen.';
    // Debug optional:
    // $err .= ' ('.$e->getMessage().')';
  }

  // Invoice neu laden
  $inv->execute([$id, $account_id]);
  $invoice = $inv->fetch();
}

// ----- Daten für Anzeige -----
$items = $pdo->prepare("SELECT ii.*, p.title AS project_title
                        FROM invoice_items ii
                        LEFT JOIN projects p ON p.id = ii.project_id AND p.account_id = ?
                        WHERE ii.invoice_id = ?
                        ORDER BY ii.id");
$items->execute([$account_id, $id]);
$items = $items->fetchAll();

$times = $pdo->prepare("SELECT it.invoice_item_id, ti.*
                        FROM invoice_item_times it
                        JOIN times ti ON ti.id = it.time_id AND ti.account_id = ?
                        WHERE it.invoice_item_id IN (SELECT id FROM invoice_items WHERE invoice_id = ?)
                        ORDER BY ti.started_at");
$times->execute([$account_id, $id]);
$times = $times->fetchAll();

// Items für „Move to“-Dropdown
$itemOptions = [];
foreach ($items as $it) $itemOptions[(int)$it['id']] = ($it['project_title'] ? $it['project_title'].' — ' : '').mb_strimwidth($it['description'], 0, 60, '…');

$statuses = ['in_vorbereitung','gestellt','gemahnt','bezahlt','storniert'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Rechnung #<?= (int)$invoice['id'] ?> – <?=h($invoice['company_name'])?></h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?=h(url('/companies/show.php').'?id='.$company_id)?>">Zurück zur Firma</a>
    <a class="btn btn-outline-primary" href="<?=h(url('/invoices/edit.php').'?id='.$id.'&export=xml')?>">XML exportieren</a>
  </div>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>
<?php if ($ok):  ?><div class="alert alert-success"><?=h($ok)?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <form method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="save_invoice">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach ($statuses as $st): ?>
              <option value="<?=$st?>" <?=$invoice['status']===$st?'selected':''?>><?=$st?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Rechnungsdatum</label>
          <input type="date" class="form-control" name="issue_date" value="<?=h($invoice['issue_date'])?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Fällig am</label>
          <input type="date" class="form-control" name="due_date" value="<?=h($invoice['due_date'])?>">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary w-100">Speichern</button>
        </div>
      </div>

      <hr>

      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th style="width:28%">Beschreibung</th>
              <th style="width:10%">Menge (h)</th>
              <th style="width:12%">Einzelpreis €</th>
              <th style="width:10%">MwSt. %</th>
              <th style="width:12%">Netto €</th>
              <th style="width:12%">Brutto €</th>
              <th>Zeit-Einträge</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it):
              $iid = (int)$it['id'];
              $ownTimes = array_filter($times, fn($t) => (int)$t['invoice_item_id'] === $iid);
            ?>
              <tr>
                <td>
                  <div class="small text-muted"><?=h($it['project_title'] ?? '')?></div>
                  <textarea name="item[<?=$iid?>][description]" class="form-control" rows="2"><?=h($it['description'])?></textarea>
                </td>
                <td><input type="text" name="item[<?=$iid?>][quantity]" class="form-control" value="<?=h(number_format((float)$it['quantity'],3,',','.'))?>"></td>
                <td><input type="text" name="item[<?=$iid?>][unit_price]" class="form-control" value="<?=h(number_format((float)$it['unit_price'],2,',','.'))?>"></td>
                <td><input type="text" name="item[<?=$iid?>][vat_rate]" class="form-control" value="<?=h(number_format((float)$it['vat_rate'],2,',','.'))?>"></td>
                <td class="text-nowrap">€ <?=fmt_eur($it['total_net'])?></td>
                <td class="text-nowrap">€ <?=fmt_eur($it['total_gross'])?></td>
                <td>
                  <?php if ($ownTimes): ?>
                    <ul class="list-unstyled mb-0">
                      <?php foreach ($ownTimes as $t): ?>
                        <li class="mb-1">
                          <form method="post" class="d-inline">
                            <?=csrf_field()?>
                            <input type="hidden" name="action" value="move_time">
                            <input type="hidden" name="time_id" value="<?=$t['id']?>">
                            <?=h($t['started_at'])?> – <?=h($t['ended_at'])?> (<?= (int)$t['minutes'] ?> min)
                            <select name="to_item" class="form-select form-select-sm d-inline-block" style="width:auto">
                              <?php foreach ($itemOptions as $oid => $olabel): ?>
                                <option value="<?=$oid?>" <?=$oid===$iid?'selected':''?>>#<?=$oid?> <?=$olabel?></option>
                              <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-secondary">Verschieben</button>
                          </form>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <span class="text-muted">–</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <?php if ($items): ?>
            <tfoot>
              <tr>
                <th colspan="4" class="text-end">Summe</th>
                <th class="text-nowrap">€ <?=fmt_eur($invoice['total_net'])?></th>
                <th class="text-nowrap">€ <?=fmt_eur($invoice['total_gross'])?></th>
                <th></th>
              </tr>
            </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </form>

    <hr class="my-3">

    <form method="post" class="row g-2">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="add_item">
      <div class="col-md-6">
        <label class="form-label">Neue Position (Beschreibung)</label>
        <input type="text" class="form-control" name="new_desc" placeholder="z. B. Pauschale Beratung">
      </div>
      <div class="col-md-2">
        <label class="form-label">Menge (h)</label>
        <input type="text" class="form-control" name="new_qty" value="1">
      </div>
      <div class="col-md-2">
        <label class="form-label">Einzelpreis €</label>
        <input type="text" class="form-control" name="new_unit" value="0">
      </div>
      <div class="col-md-2">
        <label class="form-label">MwSt. %</label>
        <input type="text" class="form-control" name="new_vat" value="19">
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button class="btn btn-outline-primary">Manuelle Position hinzufügen</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>