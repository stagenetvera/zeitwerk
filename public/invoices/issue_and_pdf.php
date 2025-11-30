<?php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/return_to.php';
require_once __DIR__ . '/../../src/lib/invoices.php';
require_once __DIR__ . '/../../src/lib/invoice_number.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$invoice_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$return_to  = pick_return_to('/invoices/index.php');

if ($invoice_id <= 0) {
  flash('Ungültige Rechnungs-ID.', 'danger');
  redirect($return_to);
}

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT * FROM invoices WHERE id=? AND account_id=? FOR UPDATE");
  $st->execute([$invoice_id, $account_id]);
  $inv = $st->fetch();
  if (!$inv) {
    throw new RuntimeException('Rechnung nicht gefunden.');
  }

  if (($inv['status'] ?? '') !== 'in_vorbereitung') {
    throw new RuntimeException('Nur Rechnungen im Status „in_vorbereitung“ können gestellt werden.');
  }

  // Nummer vergeben, falls nötig
  $issue_date = (string)($inv['issue_date'] ?? date('Y-m-d'));
  $number     = assign_invoice_number_if_needed($pdo, $account_id, $invoice_id, $issue_date);
  $number     = $number ?: (string)($inv['invoice_number'] ?? '');

  // Status -> gestellt
  $pdo->prepare("UPDATE invoices SET status='gestellt', invoice_number=? WHERE account_id=? AND id=?")
      ->execute([$number, $account_id, $invoice_id]);

  // Zeiten auf abgerechnet
  set_times_status_for_invoice($pdo, $account_id, $invoice_id, 'abgerechnet');

  $pdo->commit();

  flash('Rechnung gestellt.', 'success');
  redirect(url('/invoices/pdf.php?id='.$invoice_id));
} catch (Throwable $e) {
  $pdo->rollBack();
  flash('Konnte Rechnung nicht stellen: '.$e->getMessage(), 'danger');
  redirect($return_to);
}
