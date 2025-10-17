<?php
require __DIR__.'/../../src/bootstrap.php'; // falls du header.php brauchst, nimm header + require_login + csrf NICHT, hier GET-download
require __DIR__.'/../../src/auth.php';

require_login();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0) { http_response_code(404); exit('Invalid'); }

$pdo = db(); // je nach deinem Bootstrap

$inv = $pdo->prepare('
  SELECT i.*, c.name AS company_name, c.address AS company_address, c.vat_id AS company_vat
  FROM invoices i
  JOIN companies c ON c.id=i.company_id AND c.account_id=i.account_id
  WHERE i.id=? AND i.account_id=?
');
$inv->execute([$id,$account_id]);
$invoice = $inv->fetch();
if (!$invoice) { http_response_code(404); exit('Not found'); }

$items = $pdo->prepare('SELECT * FROM invoice_items WHERE account_id=? AND invoice_id=? ORDER BY position_no');
$items->execute([$account_id,$id]);
$rows = $items->fetchAll();

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="invoice_'.$id.'.xml"');

$xml = new DOMDocument('1.0','UTF-8');
$xml->formatOutput = true;

$root = $xml->createElement('invoice');
$root->appendChild($xml->createElement('id', (string)$invoice['id']));
$root->appendChild($xml->createElement('number', (string)($invoice['number'] ?? '')));
$root->appendChild($xml->createElement('created_at', (string)$invoice['created_at']));
$root->appendChild($xml->createElement('due_at', (string)($invoice['due_at'] ?? '')));
$root->appendChild($xml->createElement('status', (string)$invoice['status']));
$root->appendChild($xml->createElement('company', (string)$invoice['company_name']));
$root->appendChild($xml->createElement('company_address', (string)($invoice['company_address'] ?? '')));
$root->appendChild($xml->createElement('company_vat_id', (string)($invoice['company_vat'] ?? '')));
$root->appendChild($xml->createElement('net_total', number_format((float)$invoice['net_total'],2,'.','')));
$root->appendChild($xml->createElement('gross_total', number_format((float)$invoice['gross_total'],2,'.','')));

$xmlItems = $xml->createElement('items');
foreach ($rows as $r) {
  $it = $xml->createElement('item');
  $it->appendChild($xml->createElement('position_no', (string)$r['position_no']));
  $it->appendChild($xml->createElement('title', (string)$r['title']));
  $it->appendChild($xml->createElement('qty', number_format((float)$r['qty'],2,'.','')));
  $it->appendChild($xml->createElement('unit', (string)$r['unit']));
  $it->appendChild($xml->createElement('unit_price', number_format((float)$r['unit_price'],2,'.','')));
  $it->appendChild($xml->createElement('vat_rate', number_format((float)$r['vat_rate'],2,'.','')));
  $it->appendChild($xml->createElement('net_amount', number_format((float)$r['net_amount'],2,'.','')));
  $it->appendChild($xml->createElement('gross_amount', number_format((float)$r['gross_amount'],2,'.','')));

  $xmlItems->appendChild($it);
}
$root->appendChild($xmlItems);
$xml->appendChild($root);
echo $xml->saveXML();