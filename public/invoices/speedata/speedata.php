<?php

function speedata_publish(array $files, string $version = 'latest'): string
{
    $apiBase = "https://api.speedata.de";
    $token   = "sdapi_26_28e1b4fff80b93acc94e225c32b6602dbe13b6ed";

    $payload = ['files' => []];


    foreach ($files as $filename => $contents) {
        $payload['files'][] = [
            'filename' => $filename,
            'contents' => base64_encode(file_get_contents($contents)),
        ];
    }
    // 1. Publish anstoßen
    $ch = curl_init($apiBase . '/v0/publish');
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => $token . ':',       // Basic Auth sdapi_xxx:
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $resp =curl_exec($ch);
    if ($resp === false) {
        throw new RuntimeException('speedata publish: cURL error: ' . curl_error($ch));
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);

    if ($status !== 201 || !isset($data['id'])) {
        throw new RuntimeException('speedata publish: unexpected response: ' . $resp);
    }

    $id = $data['id'];

    // 2. PDF abholen (blockiert, bis fertig)  [oai_citation:4‡doc.speedata.de](https://doc.speedata.de/publisher/de/saasapi/)
    $ch = curl_init($apiBase . '/v0/pdf/' . rawurlencode($id));
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => $token . ':',
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $pdf = curl_exec($ch);
    if ($pdf === false) {
        throw new RuntimeException('speedata pdf: cURL error: ' . curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new RuntimeException('speedata pdf: HTTP ' . $status);
    }

    return $pdf; // Binärdaten
}