<?php
// src/lib/invoice_number.php

/**
 * Lädt die Account-Settings (mit FOR UPDATE) oder erzeugt einen Default-Datensatz.
 * Erkennt optional die Spalte invoice_seq_pad, fällt sonst auf Default zurück.
 *
 * Rückgabe: Array mit gelesenen Feldern; 'invoice_seq_pad' ist gesetzt, wenn vorhanden.
 */
function load_account_settings(PDO $pdo, int $account_id): array {
  // HINWEIS: Erwartet laufende Transaktion (FOR UPDATE)!
  $sel = $pdo->prepare("
    SELECT
      account_id,
      invoice_number_pattern,
      invoice_seq_pad,
      invoice_next_seq,
      default_vat_rate,
      default_tax_scheme,
      default_due_days,
      invoice_intro_text,
      invoice_outro_text,
      bank_iban,
      bank_bic,
      sender_address
    FROM account_settings
    WHERE account_id = ?
    FOR UPDATE
  ");
  $sel->execute([$account_id]);
  $row = $sel->fetch();

  if ($row) return $row;

  // Kein Datensatz vorhanden → mit Defaults anlegen
  $ins = $pdo->prepare("
    INSERT INTO account_settings
      (account_id,
       invoice_number_pattern,
       invoice_seq_pad,
       invoice_next_seq,
       default_vat_rate,
       default_tax_scheme,
       default_due_days,
       invoice_intro_text,
       invoice_outro_text,
       bank_iban,
       bank_bic,
       sender_address)
    VALUES
      (?, '{YYYY}-{SEQ}', 5, 1, 19.00, 'standard', 14, NULL, NULL, NULL, NULL, NULL)
  ");
  $ins->execute([$account_id]);

  // Danach erneut (gesperrt) laden
  $sel->execute([$account_id]);
  $row = $sel->fetch();

  if (!$row) {
    throw new RuntimeException('Konnte account_settings nicht laden/erzeugen.');
  }

  return $row;
}

/**
 * Ersetzt Platzhalter im Pattern:
 *  {YYYY}, {YY}, {MM}, {DD},
 *  {SEQ} (Breite = $default_seq_width) oder {SEQ:N} (N = Breite, mit führenden Nullen)
 *
 * $default_seq_width:
 *  - kommt i. d. R. aus account_settings.invoice_seq_pad (falls vorhanden), sonst 5.
 *  - wird nur verwendet, wenn das Pattern KEIN {SEQ:N} enthält.
 */
function format_invoice_number(string $pattern, int $seq, string $issue_date, int $default_seq_width = 5): string {
  $dt = \DateTime::createFromFormat('Y-m-d', $issue_date) ?: new \DateTime();
  $repl = [
    'YYYY' => $dt->format('Y'),
    'YY'   => $dt->format('y'),
    'MM'   => $dt->format('m'),
    'DD'   => $dt->format('d'),
  ];

  // Erst die einfachen Tokens
  $out = strtr($pattern, [
    '{YYYY}' => $repl['YYYY'],
    '{YY}'   => $repl['YY'],
    '{MM}'   => $repl['MM'],
    '{DD}'   => $repl['DD'],
  ]);

  // Dann {SEQ} / {SEQ:N} – {SEQ:N} hat Vorrang, sonst default_seq_width
  $out = preg_replace_callback('/\{SEQ(?::(\d+))?\}/', function($m) use ($seq, $default_seq_width){
    $width = isset($m[1]) && (int)$m[1] > 0 ? (int)$m[1] : (int)$default_seq_width;
    if ($width < 1)  $width = 1;
    if ($width > 12) $width = 12; // pragmatische Obergrenze
    return str_pad((string)$seq, $width, '0', STR_PAD_LEFT);
  }, $out);

  return $out;
}

/**
 * Vergibt atomar die nächste Rechnungsnummer für den Account
 * und erhöht den Zähler in account_settings.
 *
 * Erwartet, dass bereits eine äußere DB-Transaktion läuft.
 *
 * Rückgabe: vergebene Rechnungsnummer (String)
 */
function allocate_next_invoice_number(PDO $pdo, int $account_id, string $issue_date): string {
  // Settings-Zeile FOR UPDATE laden (parallel-sicher)
  $settings = load_account_settings($pdo, $account_id);

  $pattern = (string)($settings['invoice_number_pattern'] ?? '{YYYY}-{SEQ:5}');
  $seq     = (int)($settings['invoice_next_seq'] ?? 1);

  // Default-Breite aus Settings, wenn vorhanden (nur für {SEQ} ohne :N)
  $pad = isset($settings['invoice_seq_pad']) && (int)$settings['invoice_seq_pad'] > 0
    ? (int)$settings['invoice_seq_pad']
    : 5;
  if ($pad < 1)  $pad = 1;
  if ($pad > 12) $pad = 12;

  // Retry-Schleife bei potenzieller UNIQUE-Kollision (account_id, invoice_number)
  for ($i = 0; $i < 10; $i++) {
    $number = format_invoice_number($pattern, $seq, $issue_date, $pad);

    // auf Kollision prüfen
    $chk = $pdo->prepare("SELECT 1 FROM invoices WHERE account_id = ? AND invoice_number = ? LIMIT 1");
    $chk->execute([$account_id, $number]);
    $exists = (bool)$chk->fetchColumn();

    if (!$exists) {
      // Zähler hochzählen
      $upd = $pdo->prepare("UPDATE account_settings SET invoice_next_seq = ? WHERE account_id = ?");
      $upd->execute([$seq + 1, $account_id]);
      return $number;
    }

    // Kollision → nächste Nummer probieren
    $seq++;
  }

  throw new \RuntimeException('Konnte keine eindeutige Rechnungsnummer vergeben (zu viele Kollisionen).');
}

/**
 * Weist einer Rechnung (falls noch leer) eine Nummer zu.
 * Läuft innerhalb einer bestehenden DB-Transaktion.
 *
 * Rückgabe: vergebene Nummer oder null (wenn bereits vorhanden).
 */
function assign_invoice_number_if_needed(PDO $pdo, int $account_id, int $invoice_id, string $issue_date): ?string {
  // Rechnung sperren/lesen
  $st = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE id = ? AND account_id = ? FOR UPDATE");
  $st->execute([$invoice_id, $account_id]);
  $inv = $st->fetch();
  if (!$inv) {
    throw new \RuntimeException('Rechnung nicht gefunden.');
  }

  if (!empty($inv['invoice_number'])) {
    return null; // bereits vorhanden → nichts tun
  }

  $num = allocate_next_invoice_number($pdo, $account_id, $issue_date);

  $upd = $pdo->prepare("UPDATE invoices SET invoice_number = ? WHERE id = ? AND account_id = ?");
  $upd->execute([$num, $invoice_id, $account_id]);

  return $num;
}