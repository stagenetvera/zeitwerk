<?php
// scripts/migrate_company_addresses.php
// CLI ausführen: php scripts/migrate_company_addresses.php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Alle Companies holen
$st = $pdo->query('SELECT * FROM companies');
$companies = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($companies as $c) {
    // schon migriert?
    if (!empty($c['address_line1']) || !empty($c['postal_code']) || !empty($c['city'])) {
        continue;
    }

    $addrRaw = (string)($c['address'] ?? '');
    if (trim($addrRaw) === '') {
        continue;
    }

    $name = (string)$c['name'];

    // Zeilen normalisieren
    $tmp  = str_replace(["\r\n", "\r"], "\n", $addrRaw);
    $lines = array_filter(array_map('trim', explode("\n", $tmp)), 'strlen');

    if (!$lines) {
        continue;
    }

    // Falls erste Zeile exakt dem Namen entspricht → ignorieren
    if (strcasecmp($lines[0], $name) === 0) {
        array_shift($lines);
    }

    if (!$lines) {
        continue;
    }

    $line1 = '';
    $line2 = '';
    $postal = '';
    $city  = '';
    $country = 'DE';

    if (count($lines) === 1) {
        // Nur eine Zeile → alles in line1
        $line1 = $lines[0];
    } elseif (count($lines) === 2) {
        // z.B. "Straße Hausnr." + "PLZ Ort"
        $line1 = $lines[0];
        $last  = $lines[1];

        if (preg_match('~^(\S+)\s+(.+)$~u', $last, $m)) {
            $postal = $m[1];
            $city   = $m[2];
        } else {
            $city = $last;
        }
    } else {
        // >= 3 Zeilen:  [Zeilen...] ~ Street + (evtl. Zusatz...) + letzte Zeile "PLZ Ort"
        $last = array_pop($lines);

        if (preg_match('~^(\S+)\s+(.+)$~u', $last, $m)) {
            $postal = $m[1];
            $city   = $m[2];
        } else {
            $city = $last;
        }

        // erste der restlichen Zeilen als line1, der Rest in line2 zusammenfassen
        $line1 = array_shift($lines);
        if ($lines) {
            $line2 = implode(' / ', $lines);
        }
    }

    $upd = $pdo->prepare('
        UPDATE companies
           SET address_line1 = :l1,
               address_line2 = :l2,
               postal_code   = :pc,
               city          = :city,
               country_code  = :cc
         WHERE id = :id
    ');
    $upd->execute([
        ':l1'   => $line1 ?: null,
        ':l2'   => $line2 ?: null,
        ':pc'   => $postal ?: null,
        ':city' => $city ?: null,
        ':cc'   => $country,
        ':id'   => $c['id'],
    ]);

    echo "Migrated company #{$c['id']} ({$name})\n";
}

echo "Done.\n";