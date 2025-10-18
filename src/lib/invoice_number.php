<?php
// src/lib/invoice_number.php
require_once __DIR__ . '/settings.php';

/**
 * Platzhalter: {YYYY} {YY} {MM} {DD} und {N}, {NN}, {NNN}, {NNNN} bzw. {N:x}
 */
function inv_format_number(string $pattern, int $counter, DateTimeInterface $date): string {
  $out = strtr($pattern, [
    '{YYYY}' => $date->format('Y'),
    '{YY}'   => $date->format('y'),
    '{MM}'   => $date->format('m'),
    '{DD}'   => $date->format('d'),
  ]);

  // {NNN...}
  $out = preg_replace_callback('/\{N{1,}\}/', function($m) use ($counter){
    $len = strlen($m[0]) - 1; // Anzahl 'N'
    return str_pad((string)$counter, $len, '0', STR_PAD_LEFT);
  }, $out);

  // {N:x}
  $out = preg_replace_callback('/\{N:(\d+)\}/', function($m) use ($counter){
    $len = max(1, (int)$m[1]);
    return str_pad((string)$counter, $len, '0', STR_PAD_LEFT);
  }, $out);

  // Einfaches {N}
  $out = str_replace('{N}', (string)$counter, $out);

  return $out;
}

/**
 * Vergibt eine neue, eindeutige Rechnungsnummer je Account.
 * Nutzt account_settings (Keys: invoice_no_pattern, invoice_no_next).
 * Transaktion + FOR UPDATE für Race-Free Zuweisung.
 */
function inv_allocate_number(PDO $pdo, int $account_id, DateTimeInterface $issue_date): string {
  $pdo->beginTransaction();
  try {
    // existierende Settings sperren (alle relevanten Keys)
    $lock = $pdo->prepare("
      SELECT name, value
      FROM account_settings
      WHERE account_id = ? AND name IN ('invoice_no_pattern','invoice_no_next')
      FOR UPDATE
    ");
    $lock->execute([$account_id]);
    $rows = $lock->fetchAll(PDO::FETCH_KEY_PAIR);

    $pattern = $rows['invoice_no_pattern'] ?? 'INV-{YYYY}-{NNNN}';
    $counter = isset($rows['invoice_no_next']) ? max(1, (int)$rows['invoice_no_next']) : 1;

    // Falls Key fehlt: Defaults anlegen (in derselben TX)
    if (!isset($rows['invoice_no_pattern'])) {
      settings_set($pdo, $account_id, 'invoice_no_pattern', $pattern);
    }
    if (!isset($rows['invoice_no_next'])) {
      settings_set($pdo, $account_id, 'invoice_no_next', (string)$counter);
    }

    // Kollisionen vermeiden (falls es manuell vergebene Nummern gibt)
    while (true) {
      $candidate = inv_format_number($pattern, $counter, $issue_date);
      $chk = $pdo->prepare('SELECT 1 FROM invoices WHERE account_id = ? AND invoice_number = ? LIMIT 1');
      $chk->execute([$account_id, $candidate]);
      if (!$chk->fetch()) {
        // Zähler hochsetzen & speichern
        settings_set($pdo, $account_id, 'invoice_no_next', (string)($counter + 1));
        $pdo->commit();
        return $candidate;
      }
      $counter++;
    }
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}