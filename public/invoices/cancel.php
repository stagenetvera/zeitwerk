<?php
// public/invoices/cancel.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/recurring.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$invoice_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$return_to  = pick_return_to('/companies/show.php?id=' . (int)($_GET['company_id'] ?? 0));

if ($invoice_id <= 0) {
  flash('Ungültige Rechnungs-ID.', 'danger');
  redirect($return_to);
}

$st = $pdo->prepare('SELECT id, account_id, company_id, status FROM invoices WHERE id = ? AND account_id = ?');
$st->execute([$invoice_id, $account_id]);
$inv = $st->fetch();

if (!$inv) {
  flash('Rechnung nicht gefunden.', 'danger');
  redirect($return_to);
}

if ($inv['status'] === 'storniert') {
  flash('Rechnung ist bereits storniert.', 'info');
  redirect($return_to);
}

$pdo->beginTransaction();
try {
  // 1) Rechnung auf 'storniert'
  $u = $pdo->prepare("UPDATE invoices SET status='storniert' WHERE id=? AND account_id=?");
  $u->execute([$invoice_id, $account_id]);

  // 2) Zeiten freigeben (aus dieser Rechnung)
  //    Alle times, die über invoice_item_times an diese Rechnung gebunden sind, wieder 'offen' setzen.
  $pdo->prepare("
    UPDATE times t
    JOIN invoice_item_times iit
      ON iit.account_id = t.account_id AND iit.time_id = t.id
    JOIN invoice_items ii
      ON ii.account_id = iit.account_id AND ii.id = iit.invoice_item_id
    SET t.status = 'offen'
    WHERE ii.account_id = ? AND ii.invoice_id = ?
  ")->execute([$account_id, $invoice_id]);

  // 3) Recurring-Runs freigeben
  ri_unlink_runs_for_invoice($pdo, $account_id, $invoice_id);

  $pdo->commit();
  flash('Rechnung wurde storniert. Zugeordnete Zeiten und wiederkehrende Positionen sind wieder offen.', 'success');
} catch (Throwable $e) {
  $pdo->rollBack();
  flash('Storno fehlgeschlagen: '.$e->getMessage(), 'danger');
}

redirect($return_to);