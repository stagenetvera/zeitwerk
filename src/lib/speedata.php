<?php
// src/lib/speedata.php
declare(strict_types=1);

/**
 * speedata_publish
 *
 * @param array $files   Assoziatives Array:
 *                       [
 *                         'layout.xml'   => '<Layout>...</Layout>',
 *                         'data.xml'     => '<invoice>...</invoice>',
 *                         'factur-x.xml' => '<?xml ... > ...'
 *                       ]
 * @param string $version speedata Publisher Version, z.B. 'latest'
 *
 * @return string Binärer PDF-Inhalt
 *
 * @throws RuntimeException bei Fehlern
 */
function speedata_publish(array $files, string $version = 'latest'): string
{
    // 👉 In der Praxis: Token in config/.env auslagern
    $apiBase = 'https://api.speedata.de';
    $token   = 'sdapi_26_28e1b4fff80b93acc94e225c32b6602dbe13b6ed';

    $payload = ['files' => []];
    // $files: ['filename' => $contents]
    foreach ($files as $filename => $contents) {
        if (!is_string($contents)) {
            throw new RuntimeException("speedata_publish: Inhalt für {$filename} ist kein String.");
        }

        $payload['files'][] = [
            'filename' => $filename,
            'contents' => base64_encode($contents),
        ];
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('speedata_publish: JSON-Encode fehlgeschlagen.');
    }

    // 1. Publish anstoßen
    $ch = curl_init($apiBase . '/v0/publish?version=' . urlencode($version));
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => $token . ':',       // Basic Auth sdapi_xxx:
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $resp = curl_exec($ch); // ✅ tatsächlicher Request
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('speedata_publish: cURL-Fehler: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);

    if ($status !== 201 || !is_array($data) || empty($data['id'])) {
        throw new RuntimeException(
            'speedata_publish: unerwartete Antwort (HTTP ' . $status . '): ' . $resp
        );
    }

    $id = $data['id'];

    // 2. PDF abholen (blockiert, bis fertig)
    $ch = curl_init($apiBase . '/v0/pdf/' . rawurlencode($id));
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => $token . ':',
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $pdf = curl_exec($ch);
    if ($pdf === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('speedata_publish: cURL-Fehler beim PDF-Download: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new RuntimeException('speedata_publish: PDF-HTTP-Status ' . $status);
    }

    return $pdf; // Binärdaten
}