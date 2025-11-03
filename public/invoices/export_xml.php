<?php
// public/invoices/export_xml.php
require __DIR__ . '/../../src/bootstrap.php';

require_login();

$user       = auth_user();
$account_id = (int)$user['account_id'];

// Eingaben
$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$place = trim((string)($_GET['place'] ?? 'Berlin'));
if ($id <= 0) { http_response_code(404); exit('Invalid'); }

$pdo = isset($pdo) ? $pdo : db();

// Rechnung + Firma
$inv = $pdo->prepare('
  SELECT i.*,
         c.name    AS company_name,
         c.address AS company_address,
         c.vat_id  AS company_vat
    FROM invoices i
    JOIN companies c
      ON c.id = i.company_id AND c.account_id = i.account_id
   WHERE i.id = ? AND i.account_id = ?
   LIMIT 1
');
$inv->execute([$id, $account_id]);
$invoice = $inv->fetch();
if (!$invoice) { http_response_code(404); exit('Not found'); }

// Positionen (achte: bei dir heißt die Spalte "position")
$it = $pdo->prepare('
  SELECT id, description, quantity, unit_price, vat_rate, tax_scheme, entry_mode, position
    FROM invoice_items
   WHERE account_id = ? AND invoice_id = ?
   ORDER BY position ASC, id ASC
');
$it->execute([$account_id, $id]);
$items = $it->fetchAll() ?: [];

// Leistungszeitraum aus verlinkten Zeiten (min/max)
$spanStmt = $pdo->prepare('
  SELECT MIN(t.started_at) AS min_start, MAX(t.ended_at) AS max_end
    FROM times t
    JOIN invoice_item_times iit
      ON iit.time_id = t.id AND iit.account_id = t.account_id
    JOIN invoice_items ii
      ON ii.id = iit.invoice_item_id AND ii.account_id = iit.account_id
   WHERE ii.account_id = ? AND ii.invoice_id = ?
');
$spanStmt->execute([$account_id, $id]);
$span = $spanStmt->fetch() ?: ['min_start'=>null, 'max_end'=>null];

// ---------- Helpers ----------
function de_date_long(?string $ymd, bool $with_place = false, string $place = ''): string {
  if (!$ymd) return '';
  $ts = strtotime($ymd);
  if ($ts === false) return '';
  $mon = [
    1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',
    7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'
  ];
  $d = (int)date('j', $ts);
  $m = (int)date('n', $ts);
  $y = (int)date('Y', $ts);
  $out = sprintf('%d. %s %d', $d, $mon[$m] ?? date('F',$ts), $y);
  return $with_place && $place !== '' ? ($place . ', ' . $out) : $out;
}

// Zahlen kompakt (ohne ".00")
function num_plain($v): string {
  $s = number_format((float)$v, 2, '.', '');
  return (substr($s, -3) === '.00') ? substr($s, 0, -3) : $s;
}

// Einleitung = kompletter Intro-Text (Anrede darin enthalten)
$intro_full = trim((string)($invoice['invoice_intro_text'] ?? ''));

// Adressat (Firma + Anschrift)
$adressat = trim(
  // (string)($invoice['company_name'] ?? '') . "\n" .
  (string)($invoice['company_address'] ?? '')
);

// Eine MwSt.-Zahl bestimmen (wie im Beispiel eine einzige Zahl):
// nimm die höchste > 0 % aus Standard-Positionen; sonst 0
$vatCandidates = [];
foreach ($items as $r) {
  $scheme = (string)($r['tax_scheme'] ?? 'standard');
  $vr = (float)($r['vat_rate'] ?? 0);
  if ($scheme === 'standard' && $vr > 0) $vatCandidates[] = $vr;
}
$mwst = $vatCandidates ? max($vatCandidates) : 0.0;

// ---------- XML ausgeben ----------
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.str_replace(" ","_", (string)$invoice['company_name']).'_' . (string)$invoice['invoice_number'] . '.xml"');

$xml = new DOMDocument('1.0', 'UTF-8');
$xml->formatOutput = true;

$root = $xml->createElement('Rechnung');

// <Adressat> (mehrzeilig)
$elAdr = $xml->createElement('Adressat');
$elAdr->appendChild($xml->createTextNode($adressat));
$root->appendChild($elAdr);

// <Datum> "Berlin, 03. November 2025"
$root->appendChild($xml->createElement('Datum', de_date_long((string)$invoice['issue_date'], true, $place)));

// <Rechnungsnummer>
$root->appendChild($xml->createElement('Rechnungsnummer', (string)($invoice['invoice_number'] ?? '')));

// <Betreff>
$root->appendChild($xml->createElement('Betreff', 'Rechnung'));

// <Anrede> (bewusst leer, da kompletter Text in <Einleitung>)
$root->appendChild($xml->createElement('Anrede', ''));

// <Einleitung> (kompletter Einleitungstext inkl. evtl. Anrede)
$elIntro = $xml->createElement('Einleitung');
$elIntro->appendChild($xml->createTextNode($intro_full));
$root->appendChild($elIntro);

// <Positionen>
$elPos = $xml->createElement('Positionen');

foreach ($items as $r) {
  $pos = $xml->createElement('Position');

  $desc = (string)($r['description'] ?? '');
  $pos->appendChild($xml->createElement('Beschreibung', $desc));

  $mode = strtolower((string)($r['entry_mode'] ?? 'qty')); // 'auto' | 'time' | 'qty'
  $rate = (float)($r['unit_price'] ?? 0);
  $qty  = (float)($r['quantity'] ?? 0);

  if ($mode === 'auto' || $mode === 'time') {
    // Zeitbasierte (auch *manuelle* Stundenbasis) → <Stundensatz> + <Vera in Minuten>
    $minutes = (int)round($qty * 60); // Menge ist Dezimalstunden auf der Rechnung
    $pos->appendChild($xml->createElement('Stundensatz', num_plain($rate, 2)));
    $pos->appendChild($xml->createElement('Vera', (string)$minutes));
  } else {
    // Mengen-/Preisbasierte (auch *wiederkehrende* Items) → <Preis> = Netto-Gesamt
    $total_net = isset($r['total_net']) ? (float)$r['total_net'] : round($qty * $rate, 2);
    $pos->appendChild($xml->createElement('Preis', num_plain($total_net, 2)));
  }

  $elPos->appendChild($pos);
}

$root->appendChild($elPos);

// <Mwst>
$root->appendChild($xml->createElement('Mwst', num_plain($mwst)));

// <Leistungszeitraum>
$lv = $xml->createElement('Leistungszeitraum');
$von = $span['min_start'] ? de_date_long($span['min_start']) : '';
$bis = $span['max_end']   ? de_date_long($span['max_end'])   : '';
$lv->appendChild($xml->createElement('von', $von));
$lv->appendChild($xml->createElement('bis', $bis));
$root->appendChild($lv);

// <Zahlungsziel>
$root->appendChild($xml->createElement('Zahlungsziel', de_date_long((string)$invoice['due_date'])));

$xml->appendChild($root);
echo $xml->saveXML();