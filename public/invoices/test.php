<?php
// public/invoices/test.php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';         // <- an dein Projekt anpassen
require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/speedata/speedata.php';

require_login();
csrf_check();

require __DIR__ . '/../../vendor/autoload.php';

$layoutXml = __DIR__ . '/speedata/layout.xml';
$dataXml =  __DIR__ . '/speedata/data.xml';
$settingsXml =  __DIR__ . '/speedata/settings.xml';
$letterheadPdf  = __DIR__.'/../../storage/layout/letterhead_first_1_1762847651.pdf';
$font_regular = __DIR__ . '/../../storage/layout/DINPro-Regular.otf';
$font_bold = __DIR__ . '/../../storage/layout/DINPro-Medium.otf';

$pdf = speedata_publish([
    'layout.xml'    => $layoutXml,
    'data.xml'      => $dataXml,
    'settings.xml'  => $settingsXml,
    'letterhead.pdf' => $letterheadPdf,
    'DINPro-Regular.otf' => $font_regular,
    'DINPro-Medium.otf' => $font_bold
]);

// 6. PDF ausliefern
// $filename = 'RG-' . preg_replace('~[^A-Za-z0-9_-]+~', '_', (string)($invoice['invoice_number'] ?? $invoice['id'])) . '.pdf';
$filename = "test.pdf";
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));

echo $pdf;