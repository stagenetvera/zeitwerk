<?php
// public/invoices/pdf.php
//
// Erzeugt ein PDF für eine Rechnung auf Basis der Layout-Zonen
// und der Briefbogen-PDFs aus den Einstellungen.
//
// Aufruf: /invoices/pdf.php?id=123

declare(strict_types=1);

// *** WICHTIG: Kein layout/header.php einbinden, sonst kommt HTML-Ausgabe! ***
require __DIR__ . '/../../src/bootstrap.php';         // <- an dein Projekt anpassen
require_once __DIR__ . '/../../src/lib/settings.php';
// ggf. weitere Libs, aber ohne Ausgabe:
// require_once __DIR__ . '/../../src/lib/whatever.php';

require_login();
csrf_check();

require __DIR__ . '/../../vendor/autoload.php';

use setasign\Fpdi\Fpdi;

$user       = auth_user();
$account_id = (int)$user['account_id'];

$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoice_id <= 0) {
    // Noch KEINE Ausgabe passiert → safe:
    http_response_code(400);
    echo 'Missing or invalid invoice id.';
    exit;
}

// -----------------------------------------------------------------------------
// 1. Rechnung + Positionen laden
// -----------------------------------------------------------------------------

// TODO: Diese Funktion an dein tatsächliches Schema anpassen
function load_invoice_with_items(PDO $pdo, int $account_id, int $invoice_id): ?array {
    // Beispiel: Tabellen "invoices" und "invoice_items" – bitte anpassen!
    $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND account_id = ?');
    $st->execute([$invoice_id, $account_id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return null;

    $st2 = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY position ASC');
    $st2->execute([$invoice_id]);
    $items = $st2->fetchAll(PDO::FETCH_ASSOC);

    // Beispiel: Firma / Kunde, falls benötigt
    $company = null;
    if (!empty($inv['company_id'])) {
        $st3 = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
        $st3->execute([(int)$inv['company_id'], $account_id]);
        $company = $st3->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return [
        'invoice' => $inv,
        'items'   => $items,
        'company' => $company,
    ];
}

$data = load_invoice_with_items($pdo, $account_id, $invoice_id);
if (!$data) {
    http_response_code(404);
    echo 'Rechnung nicht gefunden.';
    exit;
}

$invoice = $data['invoice'];
$items   = $data['items'];
$company = $data['company'];

// -----------------------------------------------------------------------------
// 2. Account-Settings + Layout-Zonen laden
// -----------------------------------------------------------------------------

$settings = get_account_settings($pdo, $account_id);

$layoutData = [];
if (!empty($settings['invoice_layout_zones'])) {
    $decoded = json_decode($settings['invoice_layout_zones'], true);
    if (is_array($decoded)) {
        $layoutData = $decoded;
    }
}
if (empty($layoutData)) {
    $layoutData = [
        'page_size' => 'A4',
        'units'     => 'percent',
        'zones'     => [],
    ];
}
$zones = $layoutData['zones'] ?? [];

// Hilfsfunktion: Zone holen
function get_zone(array $zones, string $key): ?array {
    if (!isset($zones[$key]) || !is_array($zones[$key])) return null;
    $z = $zones[$key];
    return [
        'page' => (int)($z['page'] ?? 1),
        'x'    => (float)($z['x'] ?? 0),
        'y'    => (float)($z['y'] ?? 0),
        'w'    => (float)($z['w'] ?? 100),
        'h'    => (float)($z['h'] ?? 100),
    ];
}

// Zonen für Seite 1
$zAddress     = get_zone($zones, 'address');   // NEU: Empfängeradresse
$zInvoiceInfo = get_zone($zones, 'invoice_info');
$zItems1      = get_zone($zones, 'items');
$zTotals1     = get_zone($zones, 'totals');
$zPageNo1     = get_zone($zones, 'page_number');

// Zonen für Folgeseiten
$zItems2      = get_zone($zones, 'page2_items')       ?: $zItems1;
$zTotals2     = get_zone($zones, 'page2_totals')      ?: $zTotals1;
$zPageNo2     = get_zone($zones, 'page2_page_number') ?: $zPageNo1;

// -----------------------------------------------------------------------------
// 2b. Briefbogen-Pfade auflösen (Filesystem)
// -----------------------------------------------------------------------------

// In den Settings hast du vermutlich (je nach Implementierung) einen Pfad wie:
//  - 'storage/accounts/1/letterhead-first.pdf'   (relativ zum Projektroot)
//  oder
//  - '/storage/accounts/1/letterhead-first.pdf'  (Web-Pfad)
// oder schon einen absoluten Pfad.
//
// Wir machen daraus einen echten Filesystem-Pfad.

$publicDir   = realpath(__DIR__ . '/..');              // .../zeitwerk/public
$projectRoot = dirname($publicDir);                    // .../zeitwerk
$storageRoot = dirname($projectRoot) . '/storage';     // .../storage

/**
 * Wandelt einen Web-Pfad oder eine URL wie
 *   "/storage/accounts/1/letterhead-first.pdf"
 *   "storage/accounts/1/letterhead-first.pdf"
 *   APP_BASE_URL . "/storage/..."
 * in einen echten Filesystem-Pfad unterhalb von $storageRoot.
 */
function storage_web_to_fs(?string $value, string $storageRoot): ?string {
    if (!$value) {
        return null;
    }

    $path = $value;

    // 0. Spezieller Fall: /settings/file.php?path=...
    //    -> Pfad aus dem Query-Parameter holen
    if (strpos($path, 'file.php') !== false && strpos($path, 'path=') !== false) {
        $query = parse_url($path, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $params);
            if (!empty($params['path'])) {
                $rel = urldecode($params['path']); // z.B. "layout/letterhead_first_1_1762848330.pdf"
                $fs  = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                return is_file($fs) ? $fs : null;
            }
        }
        // wenn irgendwas damit schiefgeht, weiter unten normal versuchen
    }

    // 1. Falls eine komplette URL (http/https) -> nur den Pfadanteil nehmen
    if (preg_match('~^https?://~i', $path)) {
        $urlPath = parse_url($path, PHP_URL_PATH);
        if ($urlPath !== false && $urlPath !== null) {
            $path = $urlPath; // z.B. "/storage/accounts/1/letterhead-first.pdf"
        }
    }

    // 2. Falls APP_BASE_URL vorne dran hängt, abschneiden
    if (defined('APP_BASE_URL') && strpos($path, APP_BASE_URL) === 0) {
        $path = substr($path, strlen(APP_BASE_URL));
    }

    // 3. Jetzt behandeln wir es als Web-Pfad
    $path = ltrim($path, '/'); // "storage/..." oder "layout/..."

    // a) Wenn es mit "storage/" beginnt → alles dahinter relativ zu $storageRoot
    $posStorage = strpos($path, 'storage/');
    if ($posStorage === 0) {
        $rel = substr($path, strlen('storage/'));  // "accounts/..."
        $fs  = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
        return is_file($fs) ? $fs : null;
    }

    // b) Wenn es direkt wie "layout/..." aussieht, auch relativ zu $storageRoot
    //    (so wie bei deinem file.php-Parameter)
    $fsDirect = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    if (is_file($fsDirect)) {
        return $fsDirect;
    }

    // Wenn nichts passt: gib null zurück
    return null;
}

$publicDir   = realpath(__DIR__ . '/..');              // .../zeitwerk/public
$projectRoot = dirname($publicDir);                    // .../zeitwerk
$storageRoot = $projectRoot . '/storage';     // .../storage

$letterFirstPdf = $settings['invoice_letterhead_first_pdf'] ?? null;
$letterNextPdf  = $settings['invoice_letterhead_next_pdf']  ?? $letterFirstPdf;

$letterFirstPdfFs = storage_web_to_fs($letterFirstPdf, $storageRoot);
$letterNextPdfFs  = storage_web_to_fs($letterNextPdf,  $storageRoot);

// Wenn selbst danach nichts existiert → sauber abbrechen, aber ohne HTML-Layer
if (!$letterFirstPdfFs) {
    http_response_code(500);
    echo 'Briefbogen-PDF für die erste Seite wurde nicht gefunden.';
    exit;
}

// -----------------------------------------------------------------------------
// 3. Hilfsfunktionen für Koordinaten (Prozent → mm) und Text
// -----------------------------------------------------------------------------

// Wir gehen von A4 aus, überschreiben aber gleich mit tatsächlicher Seitengröße
$pageWidthMm  = 210.0;
$pageHeightMm = 297.0;

function zone_to_mm(array $zone, float $pageWidthMm, float $pageHeightMm): array {
    return [
        'x' => $zone['x'] / 100.0 * $pageWidthMm,
        'y' => $zone['y'] / 100.0 * $pageHeightMm,
        'w' => $zone['w'] / 100.0 * $pageWidthMm,
        'h' => $zone['h'] / 100.0 * $pageHeightMm,
    ];
}

// -----------------------------------------------------------------------------
// 4. PDF erzeugen (sichtbare Rechnung)
// -----------------------------------------------------------------------------

$pdf = new Fpdi();
$pdf->SetAuthor('Deine App');
$pdf->SetTitle('Rechnung ' . ($invoice['number'] ?? $invoice_id));
$pdf->SetCreator('Deine App');

// Hintergrund-Seite importieren (erste Seite)
$pdf->setSourceFile($letterFirstPdfFs);
$tplFirst = $pdf->importPage(1);
$size     = $pdf->getTemplateSize($tplFirst);

$pageWidthMm  = $size['width'];
$pageHeightMm = $size['height'];

// Folgeseiten-Vorlage
if ($letterNextPdfFs && is_file($letterNextPdfFs)) {
    $pdf->setSourceFile($letterNextPdfFs);
    $tplNext = $pdf->importPage(1);
} else {
    $tplNext = $tplFirst;
}

// Fonts
$pdf->SetFont('Helvetica', '', 10);

// Seitenzähler
$currentPage    = 0;
$showTotalPages = false; // erstmal nur "Seite X", nicht "von Y"

// Items vorbereiten
// TODO: Feldnamen an dein Schema anpassen
$rows = [];
foreach ($items as $it) {
    $rows[] = [
        'pos'   => $it['position']    ?? '',
        'desc'  => $it['description'] ?? '',
        'qty'   => (float)($it['quantity']    ?? 1),
        'unit'  => $it['unit']        ?? '',
        'price' => (float)($it['unit_price']  ?? 0),
        'total' => (float)($it['total']       ?? 0),
    ];
}

function fmt_money(float $v): string {
    return number_format($v, 2, ',', '.');
}
function fmt_date(?string $d): string {
    if (!$d) return '';
    $ts = strtotime($d);
    if (!$ts) return $d;
    return date('d.m.Y', $ts);
}

function get_recipient_address_components(?array $company, array $invoice): array {
    // TODO: Feldnamen an dein Schema anpassen

    // Name/Firma
    $name = '';
    if (!empty($company['name'])) {
        $name = $company['name'];
    } elseif (!empty($invoice['recipient_name'])) {
        $name = $invoice['recipient_name'];
    }

    // Straße / Hausnummer
    $line1 = '';
    if (!empty($company['address_line1'])) {
        $line1 = $company['address_line1'];
    } elseif (!empty($company['street'])) {
        $line1 = $company['street'];
    }

    // optionale 2. Zeile (z.B. Abteilung, c/o)
    $line2 = '';
    if (!empty($company['address_line2'])) {
        $line2 = $company['address_line2'];
    } elseif (!empty($company['address_extra'])) {
        $line2 = $company['address_extra'];
    }

    // PLZ / Ort
    $postcode = '';
    if (!empty($company['zip'])) {
        $postcode = $company['zip'];
    } elseif (!empty($company['postal_code'])) {
        $postcode = $company['postal_code'];
    }

    $city = $company['city'] ?? '';

    // ISO-Ländercode (für ZUGFeRD: z.B. "DE", "FR", "CH")
    $countryCode = '';
    if (!empty($company['country_code'])) {
        $countryCode = strtoupper($company['country_code']);
    } elseif (!empty($company['country'])) {
        $countryCode = strtoupper($company['country']);
    } else {
        $countryCode = 'DE'; // Fallback
    }

    return [
        'name'        => trim($name),
        'line1'       => trim($line1),
        'line2'       => trim($line2),
        'postcode'    => trim($postcode),
        'city'        => trim($city),
        'countryCode' => $countryCode,
    ];
}

function build_recipient_address(?array $company, array $invoice): string {
    $addr = get_recipient_address_components($company, $invoice);

    $lines = [];

    if ($addr['name'] !== '') {
        $lines[] = $addr['name'];
    }
    if ($addr['line1'] !== '') {
        $lines[] = $addr['line1'];
    }
    if ($addr['line2'] !== '') {
        $lines[] = $addr['line2'];
    }

    $cityLine = trim($addr['postcode'] . ' ' . $addr['city']);
    if ($cityLine !== '') {
        $lines[] = $cityLine;
    }

    // Land nur ausgeben, wenn nicht DE – kannst du nach Wunsch ändern
    if ($addr['countryCode'] !== '' && $addr['countryCode'] !== 'DE') {
        $lines[] = $addr['countryCode'];
    }

    return implode("\n", $lines);
}
// Seite beginnen
$addPage = function(bool $isFirstPage) use (
    $pdf, $tplFirst, $tplNext, $size,
    &$currentPage, $invoice, $company, $zAddress, $zInvoiceInfo,
    $pageWidthMm, $pageHeightMm
) {
    $currentPage++;

    // Seite mit Briefbogen anlegen
    $pdf->AddPage('P', [$size['width'], $size['height']]);
    $tpl = $isFirstPage ? $tplFirst : $tplNext;
    $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);

    // 1) Empfängeradresse nur auf Seite 1
    if ($isFirstPage && $zAddress) {
        $r = zone_to_mm($zAddress, $pageWidthMm, $pageHeightMm);

        $addressText = build_recipient_address($company, $invoice);
        if ($addressText !== '') {
            $pdf->SetXY($r['x'], $r['y']);
            $pdf->SetFont('Helvetica', '', 10);
            // Mehrzeilige Ausgabe innerhalb des Rahmens
            $pdf->MultiCell($r['w'], 4, $addressText, 0, 'L');
        }
    }

    // 2) Rechnungsinfo nur auf Seite 1
    if ($isFirstPage && $zInvoiceInfo) {
        $r = zone_to_mm($zInvoiceInfo, $pageWidthMm, $pageHeightMm);

        $pdf->SetXY($r['x'], $r['y']);
        $pdf->SetFont('Helvetica', 'B', 11);

        $invNo = $invoice['number'] ?? ('Rechnung ' . $invoice['id']);
        $date  = fmt_date($invoice['date'] ?? $invoice['invoice_date'] ?? null);

        $pdf->Cell($r['w'], 5, 'Rechnung ' . $invNo, 0, 2, 'L');

        $pdf->SetFont('Helvetica', '', 10);
        if ($date) {
            $pdf->Cell($r['w'], 5, 'Rechnungsdatum: ' . $date, 0, 2, 'L');
        }

        if ($company) {
            $line = [];
            if (!empty($company['name']))   $line[] = $company['name'];
            if (!empty($company['city']))   $line[] = $company['city'];
            $pdf->Cell($r['w'], 5, implode(' · ', $line), 0, 2, 'L');
        }
    }
};

// Items rendern
$renderItemsOnPage = function(
    array $rows,
    int $startIndex,
    array $zoneItems,
    Fpdi $pdf
) use ($pageWidthMm, $pageHeightMm): int {

    if (!$zoneItems) return $startIndex;

    $r = zone_to_mm($zoneItems, $pageWidthMm, $pageHeightMm);

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetXY($r['x'], $r['y']);

    $colPos   = 10;
    $colDesc  = $r['w'] - 10 - 20 - 25 - 25;
    if ($colDesc < 40) $colDesc = 40;
    $colQty   = 20;
    $colPrice = 25;
    $colTotal = 25;

    $pdf->Cell($colPos,   5, 'Pos',          0, 0, 'L');
    $pdf->Cell($colDesc,  5, 'Beschreibung', 0, 0, 'L');
    $pdf->Cell($colQty,   5, 'Menge',        0, 0, 'R');
    $pdf->Cell($colPrice, 5, 'Einzelpreis',  0, 0, 'R');
    $pdf->Cell($colTotal, 5, 'Gesamt',       0, 1, 'R');

    $pdf->SetFont('Helvetica', '', 9);

    $lineHeight = 5.0;
    $maxY = $r['y'] + $r['h'];

    $idx = $startIndex;
    $n   = count($rows);

    while ($idx < $n) {
        $row = $rows[$idx];

        if ($pdf->GetY() + $lineHeight > $maxY) {
            break;
        }

        $pdf->SetX($r['x']);

        $pdf->Cell($colPos,   $lineHeight, (string)$row['pos'], 0, 0, 'L');
        $pdf->Cell($colDesc,  $lineHeight, $row['desc'],        0, 0, 'L');
        $pdf->Cell($colQty,   $lineHeight, rtrim(rtrim(number_format($row['qty'], 2, ',', '.'), '0'), ','), 0, 0, 'R');
        $pdf->Cell($colPrice, $lineHeight, fmt_money($row['price']), 0, 0, 'R');
        $pdf->Cell($colTotal, $lineHeight, fmt_money($row['total']), 0, 1, 'R');

        $idx++;
    }

    return $idx;
};

// Summen
$renderTotals = function(
    array $invoice,
    array $zoneTotals,
    Fpdi $pdf
) use ($pageWidthMm, $pageHeightMm) {
    if (!$zoneTotals) return;

    $r = zone_to_mm($zoneTotals, $pageWidthMm, $pageHeightMm);
    $pdf->SetXY($r['x'], $r['y']);

    $net   = (float)($invoice['total_net']   ?? 0);
    $vat   = (float)($invoice['total_vat']   ?? 0);
    $gross = (float)($invoice['total_gross'] ?? ($net + $vat));

    $pdf->SetFont('Helvetica', '', 9);

    $labelWidth = $r['w'] * 0.5;
    $valWidth   = $r['w'] * 0.5;

    $pdf->Cell($labelWidth, 5, 'Zwischensumme (netto)', 0, 0, 'L');
    $pdf->Cell($valWidth,   5, fmt_money($net) . ' €',   0, 1, 'R');

    $pdf->Cell($labelWidth, 5, 'Umsatzsteuer',          0, 0, 'L');
    $pdf->Cell($valWidth,   5, fmt_money($vat) . ' €',  0, 1, 'R');

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell($labelWidth, 6, 'Gesamtbetrag',          0, 0, 'L');
    $pdf->Cell($valWidth,   6, fmt_money($gross) . ' €',0, 1, 'R');
};

// Seitenzahl
$renderPageNumber = function(
    int $page,
    ?int $total,
    array $zonePage,
    Fpdi $pdf
) use ($pageWidthMm, $pageHeightMm) {
    if (!$zonePage) return;
    $r = zone_to_mm($zonePage, $pageWidthMm, $pageHeightMm);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetXY($r['x'], $r['y']);

    $text = 'Seite ' . $page;
    if ($total !== null) {
        $text .= ' von ' . $total;
    }
    $pdf->Cell($r['w'], 4, $text, 0, 0, 'R');
};

// -----------------------------------------------------------------------------
// 5. Seiten rendern
// -----------------------------------------------------------------------------

$currentPage = 0;
$idx         = 0;
$n           = count($rows);

while (true) {
    $isFirst = ($currentPage === 0);
    $addPage($isFirst);

    if ($isFirst) {
        $idx = $renderItemsOnPage($rows, $idx, $zItems1, $pdf);
        if ($idx >= $n) {
            $renderTotals($invoice, $zTotals1, $pdf);
        }
        $renderPageNumber($currentPage, $showTotalPages ? null : null, $zPageNo1, $pdf);
    } else {
        $idx = $renderItemsOnPage($rows, $idx, $zItems2, $pdf);
        if ($idx >= $n) {
            $renderTotals($invoice, $zTotals2, $pdf);
        }
        $renderPageNumber($currentPage, $showTotalPages ? null : null, $zPageNo2, $pdf);
    }

    if ($idx >= $n) {
        break;
    }
}

// -----------------------------------------------------------------------------
// 6. PDF ausgeben (keine HTML-Ausgabe vorher!)
// -----------------------------------------------------------------------------

$filename = 'Rechnung-' . ($invoice['number'] ?? $invoice_id) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');

$pdf->Output('I', $filename);
exit;