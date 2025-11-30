<?php
// public/invoices/export_facturx_speedata.php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/utils.php';
require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/lib/speedata.php';
require_once __DIR__ . '/../../src/lib/invoice_number.php';

// Robustere Fehlerausgabe + Logging (hilfreich auf Live, wenn 500er ohne Details auftreten)
$__pdf_log_dir = __DIR__ . '/../../storage/logs';
if (!is_dir($__pdf_log_dir)) {
    @mkdir($__pdf_log_dir, 0775, true);
}
function _pdf_fail(string $msg, int $httpCode = 500, ?Throwable $e = null): void {
    global $__pdf_log_dir, $account_id, $id;
    $id = $id ?? (int)($_GET['id'] ?? 0);
    $logMsg = '[' . date('c') . "] invoice_id={$id} acc_id={$account_id} :: " . $msg;
    if ($e) {
        $logMsg .= " | Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    }
    if ($__pdf_log_dir) {
        if (@file_put_contents($__pdf_log_dir . '/pdf_export.log', $logMsg . "\n", FILE_APPEND) === false) {
            error_log($logMsg);
        }
    } else {
        error_log($logMsg);
    }
    http_response_code($httpCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $msg;
    exit;
}
set_exception_handler(function($e){
    $e = $e instanceof Throwable ? $e : null;
    $detail = $e ? (' Details: ' . $e->getMessage()) : '';
    _pdf_fail('Interner Fehler beim PDF-Export. Bitte Admin informieren.' . $detail, 500, $e);
});

require_login();

use easybill\eInvoicing\CII\Documents\CrossIndustryInvoice;
use easybill\eInvoicing\CII\Models\DocumentContextParameter;
use easybill\eInvoicing\CII\Models\ExchangedDocument;
use easybill\eInvoicing\CII\Models\ExchangedDocumentContext;
use easybill\eInvoicing\CII\Models\DateTime as CiiDateTime;
use easybill\eInvoicing\CII\Models\SupplyChainTradeTransaction;
use easybill\eInvoicing\CII\Models\TradeParty;
use easybill\eInvoicing\CII\Models\TradeAddress;
use easybill\eInvoicing\CII\Models\HeaderTradeAgreement;
use easybill\eInvoicing\CII\Models\HeaderTradeDelivery;
use easybill\eInvoicing\CII\Models\HeaderTradeSettlement;
use easybill\eInvoicing\CII\Models\TradeTax;
use easybill\eInvoicing\CII\Models\SupplyChainTradeLineItem;
use easybill\eInvoicing\CII\Models\LineTradeAgreement;
use easybill\eInvoicing\CII\Models\LineTradeDelivery;
use easybill\eInvoicing\CII\Models\LineTradeSettlement;
use easybill\eInvoicing\CII\Models\TradeProduct;
use easybill\eInvoicing\CII\Models\TradePrice;
use easybill\eInvoicing\CII\Models\SupplyChainEvent;
use easybill\eInvoicing\CII\Models\TradeSettlementHeaderMonetarySummation;
use easybill\eInvoicing\CII\Models\Amount;
use easybill\eInvoicing\CII\Models\TradePaymentTerms;
use easybill\eInvoicing\CII\Models\Quantity;
use easybill\eInvoicing\CII\Models\TradeSettlementLineMonetarySummation;
use easybill\eInvoicing\CII\Models\DocumentLineDocument;

use easybill\eInvoicing\Transformer;
use easybill\eInvoicing\Enums\DocumentType;
use easybill\eInvoicing\Enums\CountryCode;
use easybill\eInvoicing\CII\Models\TaxRegistration;
use easybill\eInvoicing\Enums\CurrencyCode;
use easybill\eInvoicing\Enums\UnitCode;

use easybill\eInvoicing\CII\Models\TradeContact;

// ----------------------------------------------------------
// Hilfsfunktionen
// ----------------------------------------------------------

function de_date_long(?string $ymd): string {
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
    return sprintf('%d. %s %d', $d, $mon[$m] ?? date('F', $ts), $y);
}

// „round half up“ – wie in deinem restlichen Code
if (!function_exists('round_half_up')) {
    function round_half_up(float $value, int $precision = 0): float {
        $factor = pow(10, $precision);
        return floor($value * $factor + 0.5) / $factor;
    }
}

/**
 * Settings-XML auf Basis von account_settings['invoice_layout_zones'] bauen.
 * Erwartet JSON der Form:
 * {
 *   "page_size": "A4",
 *   "units": "percent" | "cm",
 *   "zones": {
 *     "addressee":        { "page": 1, "x": ..., "y": ..., "w": ..., "h": ... },
 *     "invoice_info":     { ... },
 *     "main_area":        { ... },
 *     "main_area_page_2": { "page": 2, ... }
 *   }
 * }
 *
 * Fallback auf statische Demo-Werte, wenn nichts konfiguriert ist.
 */
function build_settings_xml_from_layout(array $acct, array $invoice): string
{
    $zonesJson = $acct['invoice_layout_zones'] ?? '';
    $layout    = null;

    if (is_string($zonesJson) && trim($zonesJson) !== '') {
        $decoded = json_decode($zonesJson, true);
        if (is_array($decoded)) {
            $layout = $decoded;
        }
    }

    $ns = 'urn:billingcat.de/ns/billingcatsettings';

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = false;

    $root = $dom->createElementNS($ns, 'BillingcatSettings');
    $root->setAttribute('version', '1.0');
    $dom->appendChild($root);

    // Basis-Metadaten
    $root->appendChild($dom->createElementNS($ns, 'generatedAt', gmdate('c')));
    $root->appendChild($dom->createElementNS($ns, 'invoiceId', (string)($invoice['id'] ?? '0')));
    $root->appendChild($dom->createElementNS($ns, 'invoiceNumber', (string)($invoice['invoice_number'] ?? $invoice['id'])));

    // Die Speedata-Settings arbeiten in cm
    $root->appendChild($dom->createElementNS($ns, 'units', 'cm'));

    // Einleitung / Schluss aus Rechnung, dann aus Account-Defaults, sonst Fallback
    $intro = trim((string)($invoice['invoice_intro_text'] ?? ''));
    if ($intro === '') {
        $intro = trim((string)($acct['invoice_intro_text'] ?? ''));
    }
    // Zeilenumbrüche → <br/>
    $intro = preg_replace("~\r\n|\r|\n~", "<br/>", $intro);

    $outro = trim((string)($invoice['invoice_outro_text'] ?? ''));
    if ($outro === '') {
        $outro = trim((string)($acct['invoice_outro_text'] ?? ''));
    }
    $outro = preg_replace("~\r\n|\r|\n~", "<br/>", $outro);

    $root->appendChild($dom->createElementNS($ns, 'introductionText', $intro));
    $root->appendChild($dom->createElementNS($ns, 'conclusionText', $outro));

    // Letterhead
    $letterhead = $dom->createElementNS($ns, 'letterhead');
    $root->appendChild($letterhead);

    $name = $layout['name'] ?? 'demobilling-2';
    $letterhead->appendChild($dom->createElementNS($ns, 'name', $name));

    // page_size -> Seitenmaße (cm)
    $pageSize = $layout['page_size'] ?? 'A4';
    switch ($pageSize) {
        case 'A4':
        default:
            $pageWidth  = 21.01;
            $pageHeight = 29.7;
            break;
    }

    $letterhead->appendChild($dom->createElementNS($ns, 'pageWidthCm',  (string)$pageWidth));
    $letterhead->appendChild($dom->createElementNS($ns, 'pageHeightCm', (string)$pageHeight));

    // Regions-Container
    $regionsEl = $dom->createElementNS($ns, 'regions');
    $letterhead->appendChild($regionsEl);

    // Welche Einheit steht im JSON? (z.B. "percent" oder "cm")
    $unitsSource = isset($layout['units']) ? (string)$layout['units'] : 'percent';

    if ($layout && !empty($layout['zones']) && is_array($layout['zones'])) {
        foreach ($layout['zones'] as $zoneKey => $reg) {
            if (!is_array($reg)) {
                continue;
            }

            $kind = (string)$zoneKey;
            $page = isset($reg['page']) ? (int)$reg['page'] : 1;

            $regionEl = $dom->createElementNS($ns, 'region');
            $regionEl->setAttribute('kind', $kind);
            $regionEl->setAttribute('page', (string)$page);

            // Helper zum Formatieren von Floats
            $fmt = static function (float $v): string {
                $s = sprintf('%.6F', $v);
                $s = rtrim(rtrim($s, '0'), '.');
                return $s === '' ? '0' : $s;
            };

            // x/y/w/h aus dem JSON holen
            $x = isset($reg['x']) ? (float)$reg['x'] : null;
            $y = isset($reg['y']) ? (float)$reg['y'] : null;
            $w = isset($reg['w']) ? (float)$reg['w'] : null;
            $h = isset($reg['h']) ? (float)$reg['h'] : null;

            // Wenn Prozentwerte: auf cm umrechnen
            if ($unitsSource === 'percent') {
                if ($x !== null) {
                    $x = $x * $pageWidth / 100.0;
                }
                if ($y !== null) {
                    $y = $y * $pageHeight / 100.0;
                }
                if ($w !== null) {
                    $w = $w * $pageWidth / 100.0;
                }
                if ($h !== null) {
                    $h = $h * $pageHeight / 100.0;
                }
            }

            if ($x !== null) {
                $regionEl->appendChild($dom->createElementNS($ns, 'xCm', $fmt($x)));
            }
            if ($y !== null) {
                $regionEl->appendChild($dom->createElementNS($ns, 'yCm', $fmt($y)));
            }
            if ($w !== null) {
                $regionEl->appendChild($dom->createElementNS($ns, 'widthCm', $fmt($w)));
            }
            if ($h !== null) {
                $regionEl->appendChild($dom->createElementNS($ns, 'heightCm', $fmt($h)));
            }

            // Optionale Typo/Format-Infos – falls im JSON vorhanden
            if (isset($reg['hAlign'])) {
                $regionEl->appendChild($dom->createElementNS($ns, 'hAlign', (string)$reg['hAlign']));
            }
            if (isset($reg['fontSizePt'])) {
                $regionEl->appendChild($dom->createElementNS($ns, 'fontSizePt', (string)$reg['fontSizePt']));
            }
            if (isset($reg['lineSpacing'])) {
                $regionEl->appendChild($dom->createElementNS($ns, 'lineSpacing', (string)$reg['lineSpacing']));
            }

            $regionsEl->appendChild($regionEl);
        }
    } else {
        // Fallback: feste Standard-Regionen, falls noch nichts konfiguriert ist
        $fallbackXml = <<<XML
        <regions xmlns="$ns">
            <region kind="addressee" page="1">
                <xCm>2.01546951160221</xCm>
                <yCm>5.081546540773481</yCm>
                <widthCm>8</widthCm>
                <heightCm>3</heightCm>
                <hAlign>left</hAlign>
                <fontSizePt>10</fontSizePt>
                <lineSpacing>1.2</lineSpacing>
            </region>
            <region kind="invoice_info" page="1">
                <xCm>10.577294326629834</xCm>
                <yCm>9.17635367801105</yCm>
                <widthCm>8</widthCm>
                <heightCm>4</heightCm>
                <hAlign>right</hAlign>
                <fontSizePt>10</fontSizePt>
                <lineSpacing>1.2</lineSpacing>
            </region>
            <region kind="main_area" page="1">
                <xCm>10.577294326629834</xCm>
                <yCm>9.17635367801105</yCm>
                <widthCm>8</widthCm>
                <heightCm>4</heightCm>
                <hAlign>right</hAlign>
                <fontSizePt>10</fontSizePt>
                <lineSpacing>1.2</lineSpacing>
            </region>
            <region kind="main_area_page_2" page="2">
                <xCm>2.01546951160221</xCm>
                <yCm>2.01546951160221</yCm>
                <widthCm>8</widthCm>
                <heightCm>20</heightCm>
                <hAlign>left</hAlign>
                <fontSizePt>10</fontSizePt>
                <lineSpacing>1.2</lineSpacing>
            </region>
        </regions>
        XML;
        $tmp = new DOMDocument('1.0', 'UTF-8');
        $tmp->loadXML($fallbackXml);
        foreach ($tmp->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $imported = $dom->importNode($node, true);
                $regionsEl->appendChild($imported);
            }
        }
    }

    // pdfPath + fonts (Dateinamen müssen zu denen passen, die du an speedata_publish schickst)
    $letterhead->appendChild($dom->createElementNS($ns, 'pdfPath', 'letterhead.pdf'));

    $fontsEl = $dom->createElementNS($ns, 'fonts');
    $letterhead->appendChild($fontsEl);

    $fontsEl->appendChild($dom->createElementNS($ns, 'normal', 'regular_font.otf'));
    $fontsEl->appendChild($dom->createElementNS($ns, 'bold',   'medium_font.otf'));

    return $dom->saveXML();
}

// ----------------------------------------------------------
// Eingaben / Basis
// ----------------------------------------------------------

$user       = auth_user();
$account_id = (int)$user['account_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404);
    exit('Invalid invoice id');
}

$pdo = isset($pdo) ? $pdo : db();

// ----------------------------------------------------------
// Rechnung + Firma
// ----------------------------------------------------------
$inv = $pdo->prepare('
  SELECT
    i.*,
    c.name          AS company_name,
    c.address_line1 AS company_address_line1,
    c.address_line2 AS company_address_line2,
    c.address_line3 AS company_address_line3,
    c.postal_code   AS company_postal_code,
    c.city          AS company_city,
    c.country_code  AS company_country_code,
    c.vat_id        AS company_vat,

    -- salutation: erster Buchstabe groß
    CONCAT(
        UPPER(LEFT(ct.salutation, 1)),
        LOWER(SUBSTRING(ct.salutation, 2))
    ) AS contact_salutation,

    ct.first_name   AS contact_first_name,
    ct.last_name    AS contact_last_name,

    TRIM(CONCAT(
        CONCAT(
            UPPER(LEFT(ct.salutation, 1)),
            LOWER(SUBSTRING(ct.salutation, 2))
        ),
        CASE WHEN ct.salutation IS NOT NULL AND ct.salutation <> "" THEN " " ELSE "" END,
        COALESCE(ct.first_name, ""),
        CASE WHEN ct.first_name IS NOT NULL AND ct.first_name <> "" THEN " " ELSE "" END,
        COALESCE(ct.last_name, "")
    )) AS contact_person_name

  FROM invoices i
  JOIN companies c
    ON c.id = i.company_id
   AND c.account_id = i.account_id

  LEFT JOIN contacts ct
    ON ct.company_id = c.id
   AND ct.account_id = c.account_id
   AND ct.is_invoice_addressee = 1

  WHERE i.id = ?
    AND i.account_id = ?
  LIMIT 1
');
$inv->execute([$id, $account_id]);
$invoice = $inv->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found');
}

// Falls noch keine Rechnungsnummer vergeben ist, jetzt erzeugen
if (empty($invoice['invoice_number'] ?? '')) {
    $issueDate = (string)($invoice['issue_date'] ?? date('Y-m-d'));
    $invoice['invoice_number'] = assign_invoice_number_if_needed(
        $pdo,
        $account_id,
        (int)$invoice['id'],
        $issueDate
    );
}

// ----------------------------------------------------------
// Positionen (sichtbare)
// ----------------------------------------------------------

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
$rawItems = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

$items = [];
foreach ($rawItems as $row) {
    if ((int)($row['is_hidden'] ?? 0) === 1) {
        continue;
    }
    $items[] = $row;
}

// ----------------------------------------------------------
// Leistungszeitraum (aus verlinkten Zeiten)
// ----------------------------------------------------------

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
$span = $spanStmt->fetch(PDO::FETCH_ASSOC) ?: ['min_start' => null, 'max_end' => null];

// ----------------------------------------------------------
// Summen für Factur-X
// ----------------------------------------------------------

$totalNet   = 0.0;
$totalTax   = 0.0;
$totalGross = 0.0;
$byVatRate  = []; // [rate => ['net' => ..., 'tax' => ...]]

foreach ($items as $r) {
    $scheme  = (string)($r['tax_scheme'] ?? 'standard');
    $vatRate = ($scheme === 'standard') ? (float)($r['vat_rate'] ?? 0) : 0.0;

    $lineNet = isset($r['total_net'])
        ? (float)$r['total_net']
        : round_half_up((float)$r['unit_price'] * (float)$r['quantity'], 2);

    $totalNet += $lineNet;

    if ($scheme === 'standard' && $vatRate > 0.0) {
        if (!isset($byVatRate[$vatRate])) {
            $byVatRate[$vatRate] = ['net' => 0.0, 'tax' => 0.0];
        }
        $byVatRate[$vatRate]['net'] += $lineNet;
    }
}

foreach ($byVatRate as $rate => &$group) {
    $group['tax'] = round_half_up($group['net'] * $rate / 100.0, 2);
    $totalTax += $group['tax'];
}
unset($group);

$totalNet   = round_half_up($totalNet, 2);
$totalTax   = round_half_up($totalTax, 2);
$totalGross = round_half_up($totalNet + $totalTax, 2);

// ----------------------------------------------------------
// Seller-Daten aus account_settings
// ----------------------------------------------------------

$acct = get_account_settings($pdo, $account_id);

$senderVatId = trim((string)($acct['sender_vat_id'] ?? ''));

$seller = [
    'name'    => (string)($acct['sender_name'] ?? ''),
    'street'  => (string)($acct['sender_street'] ?? ''),
    'zip'     => (string)($acct['sender_postcode'] ?? ''),
    'city'    => (string)($acct['sender_city'] ?? ''),
    'country' => strtoupper((string)($acct['sender_country'] ?? 'DE') ?: 'DE'),
    'vat_id'  => $senderVatId,
    'contact' => $user['email'] ?? null,
];

$byVatRateNonZero       = array_filter(array_keys($byVatRate), fn($r) => $r > 0);
$hasStandardRatedLines  = !empty($byVatRateNonZero);

// Falls Standard steuerpflichtige Zeilen existieren, muss Seller-VAT gesetzt sein
if ($hasStandardRatedLines && $seller['vat_id'] === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Fehler beim Factur-X-Export: Für Rechnungen mit Standard-MwSt muss eine USt-IdNr. des Absenders (Einstellungen → Absender) hinterlegt sein.";
    exit;
}

// ----------------------------------------------------------
// Buyer-Daten aus Invoice (NEU: strukturierte Felder)
// ----------------------------------------------------------

$buyerName = (string)($invoice['company_name'] ?? '');

// Straße = address_line1 [+ address_line2]
$buyerLine1 = trim((string)($invoice['company_address_line1'] ?? ''));
$buyerLine2 = trim((string)($invoice['company_address_line2'] ?? ''));
$buyerLine3 = trim((string)($invoice['company_address_line3'] ?? ''));
$buyerStreet = trim(implode(' ', array_filter([$buyerLine1, $buyerLine2, $buyerLine3])));

$buyerZip     = trim((string)($invoice['company_postal_code'] ?? ''));
$buyerCity    = trim((string)($invoice['company_city'] ?? ''));
$buyerCountry = strtoupper((string)($invoice['company_country_code'] ?? 'DE') ?: 'DE');

// Für Fallbacks (z.B. Name, falls kein Name gesetzt)
$addressLines = [];
if ($buyerStreet !== '') {
    $addressLines[] = $buyerStreet;
}
$cityLine = trim($buyerZip . ' ' . $buyerCity);
if ($cityLine !== '') {
    $addressLines[] = $cityLine;
}
$buyerAddress = implode("\n", $addressLines);

// ----------------------------------------------------------
// Factur-X / CrossIndustryInvoice aufbauen
// ----------------------------------------------------------

$document = new CrossIndustryInvoice();

// Kontext
$document->exchangedDocumentContext                                   = new ExchangedDocumentContext();
$document->exchangedDocumentContext->documentContextParameter         = new DocumentContextParameter();
$document->exchangedDocumentContext->documentContextParameter->id     = 'urn:cen.eu:en16931:2017';

// Kopf
$document->exchangedDocument                               = new ExchangedDocument();
$document->exchangedDocument->id                           = (string)($invoice['invoice_number'] ?? $invoice['id']);
$document->exchangedDocument->typeCode                     = DocumentType::COMMERCIAL_INVOICE;
$issueDate                                                 = (string)($invoice['issue_date'] ?? date('Y-m-d'));
$document->exchangedDocument->issueDateTime                = CiiDateTime::create(102, date('Ymd', strtotime($issueDate)));

// TradeTransaction
$tradeTransaction = new SupplyChainTradeTransaction();

// Agreement
$agreement = new HeaderTradeAgreement();

// Seller
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
    $agreement->sellerTradeParty->taxRegistrations[] =
        TaxRegistration::create($seller['vat_id'], 'VA');
}

// Buyer
$agreement->buyerTradeParty       = new TradeParty();
$agreement->buyerTradeParty->name = $buyerName ?: $buyerStreet ?: $buyerCity ?: $buyerAddress;

$buyerAddr           = new TradeAddress();
$buyerAddr->lineOne  = $buyerLine1 !== '' ? $buyerLine1 : null;
$buyerAddr->lineTwo  = $buyerLine2 !== '' ? $buyerLine2 : null;
$buyerAddr->lineThree= $buyerLine3 !== '' ? $buyerLine3 : null;
$buyerAddr->postcode = $buyerZip ?: null;
$buyerAddr->city     = $buyerCity ?: null;

$country = $buyerCountry ?: 'DE';
try {
    $buyerAddr->countryCode = CountryCode::from($country);
} catch (\ValueError $e) {
    $buyerAddr->countryCode = null;
}

$agreement->buyerTradeParty->postalTradeAddress = $buyerAddr;

// Beispiel 1: ein kombinierter Name im Invoice-Record
$contactPersonName = '';
if (!empty($invoice['contact_person_name'])) {
    $contactPersonName = trim((string)$invoice['contact_person_name']);
} else {
    // Beispiel 2: Vor- und Nachname separat im Invoice-Record
    $cpFirst = trim((string)($invoice['contact_first_name'] ?? ''));
    $cpLast  = trim((string)($invoice['contact_last_name'] ?? ''));
    $contactPersonName = trim($cpFirst . ' ' . $cpLast);
}

// Wenn wir einen Namen haben, als DefinedTradeContact setzen
if ($contactPersonName !== '') {
    $contact = new TradeContact();
    $contact->personName = $contactPersonName;
    $agreement->buyerTradeParty->definedTradeContact = $contact;
}

if (!empty($invoice['company_vat'])) {
    $agreement->buyerTradeParty->taxRegistrations[] =
        TaxRegistration::create((string)$invoice['company_vat'], 'VA');
}

$tradeTransaction->applicableHeaderTradeAgreement = $agreement;

// Delivery
$delivery = new HeaderTradeDelivery();

if (!empty($span['min_start'])) {
    $event = new SupplyChainEvent();
    $deliveryDate = date('Ymd', strtotime($span['min_start']));
    $event->date  = CiiDateTime::create(102, $deliveryDate);
    $delivery->chainEvent = $event;
}

$tradeTransaction->applicableHeaderTradeDelivery = $delivery;

// Settlement
$settlement = new HeaderTradeSettlement();
$settlement->invoiceCurrency = CurrencyCode::EUR;
$settlement->taxCurrency     = CurrencyCode::EUR;

// TradeTaxes
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

    $categoryCode = $rate > 0 ? 'S' : 'Z';

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

// Summen
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

// Zahlungsziel
if (!empty($invoice['due_date'])) {
    $terms       = new TradePaymentTerms();
    $due         = date('Ymd', strtotime((string)$invoice['due_date']));
    $terms->dueDate = CiiDateTime::create(102, $due);
    $settlement->specifiedTradePaymentTerms = $terms;
}

$tradeTransaction->applicableHeaderTradeSettlement = $settlement;

// Zeilen
$tradeTransaction->lineItems = [];
$lineNumber = 0;
foreach ($items as $r) {
    $lineNumber++;

    $lineItem = new SupplyChainTradeLineItem();
    $lineItem->associatedDocumentLineDocument = DocumentLineDocument::create((string)$lineNumber);

    $product       = new TradeProduct();
    $product->name = (string)($r['description'] ?? ('Pos. ' . $lineNumber));
    $lineItem->specifiedTradeProduct = $product;

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

    $lineAgreement->netPrice  = $price;
    $lineItem->tradeAgreement = $lineAgreement;

    $lineDelivery = new LineTradeDelivery();
    $lineDelivery->billedQuantity = Quantity::create(
        number_format($qty, 4, '.', ''),
        $unitEnum
    );
    $lineItem->delivery = $lineDelivery;

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

    $lineSettlement->monetarySummation      = $lineSum;
    $lineItem->specifiedLineTradeSettlement = $lineSettlement;

    $tradeTransaction->lineItems[] = $lineItem;
}

$document->supplyChainTradeTransaction = $tradeTransaction;

// ----------------------------------------------------------
// Factur-X XML als $dataXml
// ----------------------------------------------------------

$dataXml = Transformer::create()->transformToXml($document);

// ----------------------------------------------------------
// settings.xml dynamisch aus invoice_layout_zones
// ----------------------------------------------------------

$settingsXml = build_settings_xml_from_layout($acct, $invoice);

// // Debug: settings.xml temporär ablegen
// $tmpDir = __DIR__ . '/../../storage/logs';
// if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }
// if (is_dir($tmpDir) && is_writable($tmpDir)) {
//     $tmpName = $tmpDir . '/settings_xml_' . (int)($invoice['id'] ?? 0) . '_' . time() . '.xml';
//     @file_put_contents($tmpName, $settingsXml);
// }


// Layout laden (dein fertiges layout.xml)
$layoutPath = __DIR__ . '/speedata/layout.xml';
if (!is_readable($layoutPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Layout-Datei nicht lesbar: ' . $layoutPath;
    exit;
}
$layoutXml = file_get_contents($layoutPath);
if ($layoutXml === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Konnte Layout-Datei nicht einlesen.';
    exit;
}

// Briefbogen + Fonts (ggf. Pfade anpassen)
$letterheadSettingRaw = trim((string)($acct['invoice_letterhead_first_pdf'] ?? ''));
$letterheadPath       = '';

// Wenn in den Settings ein Wert gesetzt ist:
if ($letterheadSettingRaw !== '') {

    // Fall 1: Es ist eine URL wie /settings/file.php?path=layout%2Fletterhead_first_....
    if (strpos($letterheadSettingRaw, 'file.php') !== false) {
        $urlParts = parse_url($letterheadSettingRaw);
        $relativePath = '';

        if (!empty($urlParts['query'])) {
            parse_str($urlParts['query'], $query);
            if (!empty($query['path'])) {
                // path-Parameter dekodieren (layout%2Ffoo.pdf → layout/foo.pdf)
                $relativePath = urldecode($query['path']);
            }
        }

        if ($relativePath !== '') {
            // Sicherheitshalber führende Slashes entfernen
            $relativePath = ltrim($relativePath, '/');
            // Deine file.php liest wahrscheinlich aus storage/, also:
            $letterheadPath = __DIR__ . '/../../storage/' . $relativePath;
        }

    // Fall 2: Absoluter Pfad im Setting
    } elseif ($letterheadSettingRaw[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $letterheadSettingRaw)) {
        $letterheadPath = $letterheadSettingRaw;

    // Fall 3: Nur Dateiname o. ä. → unter storage/layout/
    } else {
        $letterheadPath = __DIR__ . '/../../storage/layout/' . $letterheadSettingRaw;
    }
}

// Fallback, falls oben nichts Sinnvolles herauskam
if ($letterheadPath === '') {
    $letterheadPath = __DIR__ . '/../../storage/layout/letterhead_first_1_1762847651.pdf';
}

// Fonts ggf. aus Settings lesen; fallback auf statische Dateien
$fontRegular = '';
$fontMedium  = '';

$fontSettingRegular = trim((string)($acct['invoice_font_regular'] ?? ''));
$fontSettingBold    = trim((string)($acct['invoice_font_bold'] ?? ''));

if ($fontSettingRegular !== '') {
    // Wenn als /settings/file.php?... hinterlegt, dekodieren
    if (strpos($fontSettingRegular, 'file.php') !== false) {
        $parts = parse_url($fontSettingRegular);
        $rel = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['path'])) {
                $rel = urldecode($q['path']);
                $rel = ltrim($rel, '/');
                $fontRegular = __DIR__ . '/../../storage/' . $rel;
            }
        }
    } elseif ($fontSettingRegular[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $fontSettingRegular)) {
        $fontRegular = $fontSettingRegular;
    } else {
        $fontRegular = __DIR__ . '/../../storage/layout/' . $fontSettingRegular;
    }
}

if ($fontSettingBold !== '') {
    if (strpos($fontSettingBold, 'file.php') !== false) {
        $parts = parse_url($fontSettingBold);
        $rel = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['path'])) {
                $rel = urldecode($q['path']);
                $rel = ltrim($rel, '/');
                $fontMedium = __DIR__ . '/../../storage/' . $rel;
            }
        }
    } elseif ($fontSettingBold[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $fontSettingBold)) {
        $fontMedium = $fontSettingBold;
    } else {
        $fontMedium = __DIR__ . '/../../storage/layout/' . $fontSettingBold;
    }
}

// Fallback auf statische Fonts, falls nichts gesetzt oder nicht lesbar
if ($fontRegular === '' || !is_readable($fontRegular)) {
    $fontRegular = __DIR__ . '/../../storage/layout/DINPro-Regular.otf';
}
if ($fontMedium === '' || !is_readable($fontMedium)) {
    $fontMedium  = __DIR__ . '/../../storage/layout/DINPro-Medium.otf';
}

if (!is_readable($letterheadPath)) {
    _pdf_fail('Briefbogen nicht lesbar: ' . $letterheadPath, 500);
}
if (!is_readable($fontRegular) || !is_readable($fontMedium)) {
    _pdf_fail('Schriftdateien nicht lesbar: ' . $fontRegular . ' / ' . $fontMedium, 500);
}

// ----------------------------------------------------------
// speedata.publish
// ----------------------------------------------------------

try {
    $pdf = speedata_publish([
        'layout.xml'       => $layoutXml,
        'data.xml'         => $dataXml,
        'settings.xml'     => $settingsXml,
        'letterhead.pdf'   => file_get_contents($letterheadPath),
        'regular_font.otf' => file_get_contents($fontRegular),
        'medium_font.otf'  => file_get_contents($fontMedium),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Fehler beim Speedata-Export:\n\n" . $e->getMessage();
    exit;
}

// ----------------------------------------------------------
// PDF ausliefern
// ----------------------------------------------------------

$filename = 'R-' . preg_replace('~[^A-Za-z0-9_-]+~', '_', (string)($invoice['invoice_number'] ?? $invoice['id'])) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));

echo $pdf;
exit;
