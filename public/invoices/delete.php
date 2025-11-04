<?php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/return_to.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$invoice_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$return_to  = pick_return_to('/invoices/index.php');

if ($invoice_id <= 0) {
  flash('Ungültige Rechnungs-ID.', 'danger');
  redirect($return_to); exit;
}

$pdo->beginTransaction();
try {
  // Rechnung sperren + prüfen
  $st = $pdo->prepare("SELECT * FROM invoices WHERE id=? AND account_id=? FOR UPDATE");
  $st->execute([$invoice_id, $account_id]);
  $inv = $st->fetch();
  if (!$inv) throw new RuntimeException('Rechnung nicht gefunden.');

  // Nur löschen, wenn noch keine Rechnungsnummer (und z.B. Status in_vorbereitung)
  if (!empty($inv['invoice_number'])) {
    throw new RuntimeException('Rechnung hat bereits eine Nummer und kann nicht gelöscht werden. Bitte stornieren.');
  }
  if (($inv['status'] ?? '') !== 'in_vorbereitung') {
    throw new RuntimeException('Nur Rechnungen im Status „in_vorbereitung“ dürfen gelöscht werden.');
  }

  // 1) verknüpfte Time-IDs holen (für Freigabe)
  $q = $pdo->prepare("
    SELECT t.id AS time_id
    FROM invoice_items ii
    JOIN invoice_item_times iit ON iit.invoice_item_id = ii.id AND iit.account_id = ii.account_id
    JOIN times t                ON t.id = iit.time_id      AND t.account_id  = iit.account_id
    WHERE ii.account_id = ? AND ii.invoice_id = ?
  ");
  $q->execute([$account_id, $invoice_id]);
  $timeIds = array_map(fn($r)=>(int)$r['time_id'], $q->fetchAll());

  // 2) Links kappen
  $pdo->prepare("
    DELETE iit
    FROM invoice_item_times iit
    JOIN invoice_items ii ON ii.id = iit.invoice_item_id AND ii.account_id = iit.account_id
    WHERE ii.account_id = ? AND ii.invoice_id = ?
  ")->execute([$account_id, $invoice_id]);

  // SAFETY-NET: alle "in_abrechnung"-Zeiten ohne jegliche Verknüpfung wieder öffnen
    $pdo->prepare("
    UPDATE times t
    SET t.status = 'offen'
    WHERE t.account_id = ?
    AND t.billable = 1
    AND t.minutes IS NOT NULL
    AND t.status IN ('in_abrechnung','abgerechnet')
    AND NOT EXISTS (
        SELECT 1
        FROM invoice_item_times iit
        JOIN invoice_items ii ON ii.account_id = iit.account_id AND ii.id = iit.invoice_item_id
        JOIN invoices i       ON i.account_id  = ii.account_id  AND i.id  = ii.invoice_id
        WHERE iit.account_id = t.account_id
        AND iit.time_id    = t.id
        AND i.status IN ('in_vorbereitung','gestellt','gemahnt','bezahlt', 'ausgebucht')
    )")->execute([$account_id]);


  // 3) Zeiten ggf. auf 'offen'
  if ($timeIds) {
    $ph = implode(',', array_fill(0, count($timeIds), '?'));
    $sql = "
      UPDATE times t
      LEFT JOIN invoice_item_times still ON still.account_id = t.account_id AND still.time_id = t.id
      SET t.status = 'offen'
      WHERE t.account_id = ? AND t.id IN ($ph) AND still.time_id IS NULL
    ";
    $params = array_merge([$account_id], $timeIds);
    $pdo->prepare($sql)->execute($params);
  }

  // 4) Recurring-Runs freigeben
  $pdo->prepare("DELETE FROM recurring_item_ledger WHERE account_id=? AND invoice_id=?")
      ->execute([$account_id, $invoice_id]);

  // 5) Positionen löschen
  $pdo->prepare("DELETE FROM invoice_items WHERE account_id=? AND invoice_id=?")
      ->execute([$account_id, $invoice_id]);

  // 6) Rechnung löschen
  $pdo->prepare("DELETE FROM invoices WHERE account_id=? AND id=?")
      ->execute([$account_id, $invoice_id]);




  $pdo->commit();
  flash('Rechnung gelöscht. Verknüpfte Zeiten sind wieder offen.', 'success');
} catch (Throwable $e) {
  $pdo->rollBack();
  flash('Löschen fehlgeschlagen: '.$e->getMessage(), 'danger');
}

redirect($return_to);