<?php
// public/invoices/export_factur-x.php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/utils.php';        // dec(), parse_hours_to_decimal()
require_once __DIR__ . '/../../src/lib/settings.php'; // get_account_settings()

require_login();

use easybill\eInvoicing\CII\Documents\CrossIndustryInvoice;
use easybill\eInvoicing\CII\Models\DocumentContextParameter;
use easybill\eInvoicing\CII\Models\ExchangedDocument;
use easybill\eInvoicing\CII\Models\ExchangedDocumentContext;
use easybill\eInvoicing\CII\Models\DateTime as CiiDateTime;
use easybill\eInvoicing\CII\Models\SupplyChainTradeTransaction;
use easybill\eInvoicing\CII\Models\TradeParty;
use easybill\eInvoicing\CII\Models\TradeAddress;
use easybill\eInvoicing\CII\Models\TradeContact;
use easybill\eInvoicing\CII\Models\HeaderTradeAgreement;
use easybill\eInvoicing\CII\Models\HeaderTradeDelivery;
use easybill\eInvoicing\CII\Models\HeaderTradeSettlement;
use easybill\eInvoicing\CII\Models\TradeTax;
use easybill\eInvoicing\CII\Models\MonetarySummation;
use easybill\eInvoicing\CII\Models\SupplyChainTradeLineItem;
use easybill\eInvoicing\CII\Models\LineTradeAgreement;
use easybill\eInvoicing\CII\Models\LineTradeDelivery;
use easybill\eInvoicing\CII\Models\LineTradeSettlement;
use easybill\eInvoicing\CII\Models\TradeProduct;
use easybill\eInvoicing\CII\Models\TradePrice;
use easybill\eInvoicing\Transformer;

use easybill\eInvoicing\Enums\DocumentType;
use easybill\eInvoicing\Enums\CountryCode;
use easybill\eInvoicing\CII\Models\TaxRegistration;

use easybill\eInvoicing\CII\Models\SupplyChainEvent;
use easybill\eInvoicing\Enums\CurrencyCode;
use easybill\eInvoicing\CII\Models\TradeSettlementHeaderMonetarySummation;

use easybill\eInvoicing\CII\Models\Amount;
use easybill\eInvoicing\CII\Models\TradePaymentTerms;

use easybill\eInvoicing\CII\Models\Quantity;
use easybill\eInvoicing\Enums\UnitCode;
use easybill\eInvoicing\CII\Models\TradeSettlementLineMonetarySummation;
use easybill\eInvoicing\CII\Models\DocumentLineDocument;

// ----------------------------------------------------------------------------
// Eingaben / Basis
// ----------------------------------------------------------------------------

$user       = auth_user();
$account_id = (int)$user['account_id'];

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$place = trim((string)($_GET['place'] ?? 'Berlin'));
if ($id <= 0) {
    http_response_code(404);
    exit('Invalid');
}

$pdo = isset($pdo) ? $pdo : db();

// ----------------------------------------------------------------------------
// Rechnung + Firma (wie in export_xml.php)
// ----------------------------------------------------------------------------

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
if (!$invoice) {
    http_response_code(404);
    exit('Not found');
}

// ----------------------------------------------------------------------------
// Positionen (wie in edit.php)
// ----------------------------------------------------------------------------

$it = $pdo->prepare('
  SELECT
    id           AS item_id,
    description,
    quantity,
    unit_price,
    vat_rate,
    tax_scheme,
    position,
    total_net,
    total_gross,
    entry_mode,
    is_hidden
  FROM invoice_items
  WHERE account_id = ? AND invoice_id = ?
  ORDER BY COALESCE(position, 999999), id ASC
');
$it->execute([$account_id, $id]);
$rawItems = $it->fetchAll() ?: [];

// wie im edit-Formular: versteckte Items ignorieren
$items = [];
foreach ($rawItems as $row) {
    if ((int)($row['is_hidden'] ?? 0) === 1) {
        continue;
    }
    $items[] = $row;
}

// ----------------------------------------------------------------------------
// Leistungszeitraum aus verlinkten Zeiten (min/max) – wie im export_xml.php
// ----------------------------------------------------------------------------

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
$span = $spanStmt->fetch() ?: ['min_start' => null, 'max_end' => null];

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function de_date_long(?string $ymd, bool $with_place = false, string $place = ''): string {
    if (!$ymd) return '';
    $ts = strtotime($ymd);
    if ($ts === false) return '';
    $mon = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli',   8 => 'August',  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
    ];
    $d = (int)date('j', $ts);
    $m = (int)date('n', $ts);
    $y = (int)date('Y', $ts);
    $out = sprintf('%d. %s %d', $d, $mon[$m] ?? date('F', $ts), $y);
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
    (string)($invoice['company_address'] ?? '')
);

// Eine MwSt.-Zahl bestimmen (wie im Beispiel eine einzige Zahl):
// nimm die höchste > 0 % aus Standard-Positionen; sonst 0
$vatCandidates = [];
foreach ($items as $r) {
    $scheme = (string)($r['tax_scheme'] ?? 'standard');
    $vr     = (float)($r['vat_rate'] ?? 0);
    if ($scheme === 'standard' && $vr > 0) {
        $vatCandidates[] = $vr;
    }
}
$mwst = $vatCandidates ? max($vatCandidates) : 0.0;

// ----------------------------------------------------------------------------
// Summen aus Positionen berechnen (für Factur-X Pflichtfelder, EN 16931)
// ----------------------------------------------------------------------------

$totalNet   = 0.0;
$totalTax   = 0.0;
$totalGross = 0.0;
$byVatRate  = []; // [rate => ['net' => ..., 'tax' => ...]]

// 1. Nettosummen je Steuersatz aufaddieren
foreach ($items as $r) {
    $scheme  = (string)($r['tax_scheme'] ?? 'standard');
    $vatRate = ($scheme === 'standard') ? (float)($r['vat_rate'] ?? 0) : 0.0;

    // Netto je Zeile: aus DB oder fallback (bereits gerundet)
    $lineNet = isset($r['total_net'])
        ? (float)$r['total_net']
        : round_half_up((float)$r['unit_price'] * (float)$r['quantity'], 2);

    $totalNet += $lineNet;

    // Nur Standard-Sätze mit > 0 % in die MwSt-Gruppen
    if ($scheme === 'standard' && $vatRate > 0.0) {
        if (!isset($byVatRate[$vatRate])) {
            $byVatRate[$vatRate] = ['net' => 0.0, 'tax' => 0.0];
        }
        $byVatRate[$vatRate]['net'] += $lineNet;
    }
}

// 2. MwSt je Steuersatz EN 16931-konform berechnen (round half up)
foreach ($byVatRate as $rate => &$group) {
    $group['tax'] = round_half_up($group['net'] * $rate / 100.0, 2);
    $totalTax += $group['tax'];
}
unset($group);

// 3. Gesamtsummen runden
$totalNet   = round_half_up($totalNet, 2);
$totalTax   = round_half_up($totalTax, 2);
$totalGross = round_half_up($totalNet + $totalTax, 2);

// Leistungszeitraum (optional in den Notes)
$leistungszeitraumText = '';
if (!empty($span['min_start']) || !empty($span['max_end'])) {
    $von = $span['min_start'] ? de_date_long($span['min_start']) : '';
    $bis = $span['max_end']   ? de_date_long($span['max_end'])   : '';
    $leistungszeitraumText = trim("Leistungszeitraum: {$von}" . ($bis ? " – {$bis}" : ''));
}

// ----------------------------------------------------------------------------
// Seller-Daten (Absender) aus account_settings
// ----------------------------------------------------------------------------

$acct = get_account_settings($pdo, $account_id);

$senderVatId = trim((string)($acct['sender_vat_id'] ?? '')); // <-- neue Setting-Spalte

$seller = [
    'name'    => (string)($acct['sender_name'] ?? ''),
    'street'  => (string)($acct['sender_street'] ?? ''),
    'zip'     => (string)($acct['sender_postcode'] ?? ''),
    'city'    => (string)($acct['sender_city'] ?? ''),
    'country' => strtoupper((string)($acct['sender_country'] ?? 'DE') ?: 'DE'),
    'vat_id'  => $senderVatId,
    'contact' => $user['email'] ?? null,
];

// Prüfen: gibt es überhaupt standardbesteuerte Zeilen?
$hasStandardRatedLines = !empty($byVatRate);

// BR-S-02: Wenn Standard-Steuersätze vorhanden sind, muss eine USt-ID des Verkäufers vorhanden sein.
if ($hasStandardRatedLines && $seller['vat_id'] === '') {
    http_response_code(500);
    echo 'Fehler beim Factur-X-Export: Für Rechnungen mit Standard-MwSt (BR-S-02) muss eine USt-IdNr '
       . 'des Absenders in den Einstellungen hinterlegt sein.';
    exit;
}

// Buyer-Daten (kommen aus $invoice / $adressat)
$buyerName    = (string)($invoice['company_name'] ?? '');
$buyerAddress = (string)($invoice['company_address'] ?? '');

// Versuch einer simplen Adress-Aufteilung: letzte Zeile = "PLZ ORT"
$buyerStreet  = '';
$buyerZip     = '';
$buyerCity    = '';
$buyerCountry = 'DE';

$lines = preg_split('~\R+~', $buyerAddress);
$lines = array_values(array_filter(array_map('trim', $lines), static function ($v) {
    return $v !== '';
}));

if ($lines) {
    if (count($lines) >= 2) {
        $buyerStreet = $lines[count($lines) - 2];
        $plzOrt      = $lines[count($lines) - 1];
    } else {
        $buyerStreet = $lines[0];
        $plzOrt      = '';
    }

    if (!empty($plzOrt)) {
        // sehr einfache Heuristik: "12345 Ort"
        if (preg_match('~^(\d{4,5})\s+(.+)$~u', $plzOrt, $m)) {
            $buyerZip  = $m[1];
            $buyerCity = $m[2];
        } else {
            $buyerCity = $plzOrt;
        }
    }
}

// ----------------------------------------------------------------------------
// Factur-X / EN16931-Dokument bauen (CII)
// ----------------------------------------------------------------------------

$document = new CrossIndustryInvoice();

// Kontext
$document->exchangedDocumentContext                                   = new ExchangedDocumentContext();
$document->exchangedDocumentContext->documentContextParameter         = new DocumentContextParameter();
$document->exchangedDocumentContext->documentContextParameter->id     = 'urn:cen.eu:en16931:2017';

// Kopf: Rechnung
$document->exchangedDocument                               = new ExchangedDocument();
$document->exchangedDocument->id                           = (string)($invoice['invoice_number'] ?? $invoice['id']);
$document->exchangedDocument->typeCode                     = DocumentType::COMMERCIAL_INVOICE;
$issueDate                                                 = (string)($invoice['issue_date'] ?? date('Y-m-d'));
$document->exchangedDocument->issueDateTime                = CiiDateTime::create(102, date('Ymd', strtotime($issueDate)));


// SupplyChainTradeTransaction (Kern der Rechnung)
$tradeTransaction = new SupplyChainTradeTransaction();

// ------------------------
// HeaderTradeAgreement (Vertrag / Parteien)
// ------------------------

$agreement = new HeaderTradeAgreement();

// Seller (Lieferant)
$agreement->sellerTradeParty       = new TradeParty();
$agreement->sellerTradeParty->name = $seller['name'] !== '' ? $seller['name'] : 'Absender';


$sellerAddress           = new TradeAddress();
$sellerAddress->lineOne  = $seller['street'] ?: null;
$sellerAddress->postcode = $seller['zip'] ?: null;
$sellerAddress->city     = $seller['city'] ?: null;

$country = $seller['country'] ?: 'DE';
try {
    $sellerAddress->countryCode = CountryCode::from($country);
} catch (\ValueError $e) {
    $sellerAddress->countryCode = null;
}

$agreement->sellerTradeParty->postalTradeAddress = $sellerAddress;

if ($seller['vat_id'] !== '') {
    // BR-S-02 / BR-CO-26: VAT ID / TaxRegistration
    $agreement->sellerTradeParty->taxRegistrations[] =
        TaxRegistration::create($seller['vat_id'], 'VA');
}

// Buyer (Kunde)
$agreement->buyerTradeParty       = new TradeParty();
$agreement->buyerTradeParty->name = $buyerName ?: $buyerStreet ?: $buyerCity ?: $buyerAddress;

$buyerAddr           = new TradeAddress();
$buyerAddr->lineOne  = $buyerStreet ?: null;
$buyerAddr->postcode = $buyerZip ?: null;
$buyerAddr->city     = $buyerCity ?: null;

$country = strtoupper((string)($buyerCountry ?: 'DE'));
try {
    $buyerAddr->countryCode = CountryCode::from($country);
} catch (\ValueError $e) {
    $buyerAddr->countryCode = null;
}

$agreement->buyerTradeParty->postalTradeAddress = $buyerAddr;

if (!empty($invoice['company_vat'])) {
    $agreement->buyerTradeParty->taxRegistrations[] =
        TaxRegistration::create((string)$invoice['company_vat'], 'VA');
}

$tradeTransaction->applicableHeaderTradeAgreement = $agreement;

// ------------------------
// HeaderTradeDelivery (Lieferinfos)
// ------------------------

$delivery = new HeaderTradeDelivery();

if (!empty($span['min_start'])) {
    $event = new SupplyChainEvent();

    $deliveryDate = date('Ymd', strtotime($span['min_start']));
    $event->date  = CiiDateTime::create(102, $deliveryDate);

    $delivery->chainEvent = $event;
}

$tradeTransaction->applicableHeaderTradeDelivery = $delivery;

// ------------------------
// HeaderTradeSettlement (Zahlungsinfos, Summen, Steuern)
// ------------------------

$settlement = new HeaderTradeSettlement();
$settlement->invoiceCurrency = CurrencyCode::EUR;
$settlement->taxCurrency     = CurrencyCode::EUR;

// Steuern pro MwSt.-Satz (Header-Ebene)
$settlement->tradeTaxes = [];

foreach ($byVatRate as $rate => $sumByRate) {
    $basisAmount = Amount::create(
        number_format($sumByRate['net'], 2, '.', ''),
        CurrencyCode::EUR
    );

    $calcAmount = Amount::create(
        number_format($sumByRate['tax'], 2, '.', ''),
        CurrencyCode::EUR
    );

    $categoryCode = $rate > 0 ? 'S' : 'Z'; // S = Standard, Z = Zero

    $tax = TradeTax::create(
        'VAT',
        $calcAmount,
        $basisAmount,
        null,
        null,
        null,
        $categoryCode,
        number_format($rate, 2, '.', '')
    );

    $settlement->tradeTaxes[] = $tax;
}

// Kopf-Summen (Monetary Summation)
$headerSum = new TradeSettlementHeaderMonetarySummation();

$headerSum->lineTotalAmount = Amount::create(
    number_format($totalNet, 2, '.', ''),
    CurrencyCode::EUR
);

$headerSum->taxBasisTotalAmount = [
    Amount::create(
        number_format($totalNet, 2, '.', ''),
        CurrencyCode::EUR
    ),
];

$headerSum->taxTotalAmount = [
    Amount::create(
        number_format($totalTax, 2, '.', ''),
        CurrencyCode::EUR
    ),
];

$headerSum->grandTotalAmount = [
    Amount::create(
        number_format($totalGross, 2, '.', ''),
        CurrencyCode::EUR
    ),
];

$headerSum->duePayableAmount = Amount::create(
    number_format($totalGross, 2, '.', ''),
    CurrencyCode::EUR
);

$settlement->specifiedTradeSettlementHeaderMonetarySummation = $headerSum;

// Zahlungsziel / Fälligkeitsdatum
if (!empty($invoice['due_date'])) {
    $terms       = new TradePaymentTerms();
    $due         = date('Ymd', strtotime((string)$invoice['due_date']));
    $terms->dueDate = CiiDateTime::create(102, $due);

    $settlement->specifiedTradePaymentTerms = $terms;
}

$tradeTransaction->applicableHeaderTradeSettlement = $settlement;

// ------------------------
// Positionen als SupplyChainTradeLineItem
// ------------------------

$tradeTransaction->lineItems = [];

$lineNumber = 0;
foreach ($items as $r) {
    $lineNumber++;

    $lineItem = new SupplyChainTradeLineItem();

    // Pflichtfeld: LineID
    $lineItem->associatedDocumentLineDocument = DocumentLineDocument::create((string)$lineNumber);

    // Produkt
    $product       = new TradeProduct();
    $product->name = (string)($r['description'] ?? ('Pos. ' . $lineNumber));
    $lineItem->specifiedTradeProduct = $product;

    // Agreement (Preis / Einheit)
    $lineAgreement = new LineTradeAgreement();

    $qty       = (float)($r['quantity'] ?? 0);
    $unitPrice = (float)($r['unit_price'] ?? 0);

    $price = new TradePrice();
    $price->chargeAmount = Amount::create(
        number_format($unitPrice, 2, '.', ''),
        CurrencyCode::EUR
    );

    $mode     = strtolower((string)($r['entry_mode'] ?? 'qty'));
    $unitEnum = ($mode === 'auto' || $mode === 'time')
        ? UnitCode::HUR
        : UnitCode::C62;

    $basisQuantity        = Quantity::create('1', $unitEnum);
    $price->basisQuantity = $basisQuantity;

    $lineAgreement->netPrice     = $price;
    $lineItem->tradeAgreement    = $lineAgreement;

    // Lieferung
    $lineDelivery = new LineTradeDelivery();
    $lineDelivery->billedQuantity = Quantity::create(
        number_format($qty, 4, '.', ''),
        $unitEnum
    );
    $lineItem->delivery = $lineDelivery;

    // Settlement (Zeile)
    $lineSettlement = new LineTradeSettlement();

    $vatRate = (float)($r['vat_rate'] ?? 0);
    $lineNet = isset($r['total_net'])
        ? (float)$r['total_net']
        : round_half_up($qty * $unitPrice, 2);

    $lineTaxObj = TradeTax::create(
        'VAT',
        null,
        null,
        null,
        null,
        null,
        $vatRate > 0 ? 'S' : 'Z',
        number_format($vatRate, 2, '.', '')
    );

    $lineSettlement->tradeTax = [$lineTaxObj];

    $lineSum              = new TradeSettlementLineMonetarySummation();
    $lineSum->totalAmount = Amount::create(
        number_format($lineNet, 2, '.', ''),
        CurrencyCode::EUR
    );

    $lineSettlement->monetarySummation          = $lineSum;
    $lineItem->specifiedLineTradeSettlement     = $lineSettlement;

    $tradeTransaction->lineItems[] = $lineItem;
}

// Trade-Objekt ans Dokument hängen
$document->supplyChainTradeTransaction = $tradeTransaction;

// ----------------------------------------------------------------------------
// XML erzeugen & ausgeben
// ----------------------------------------------------------------------------

$xml = Transformer::create()->transformToXml($document);

$filename = 'factur-x_' . preg_replace('~[^A-Za-z0-9_-]+~', '_', (string)($invoice['invoice_number'] ?? $invoice['id'])) . '.xml';

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo $xml;
exit;