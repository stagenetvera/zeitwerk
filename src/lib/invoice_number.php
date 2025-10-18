<?php
// src/lib/invoice_number.php

/**
 * Lädt die Account-Settings oder erzeugt einen Default-Datensatz.
 * Gibt ein Array mit allen benötigten Feldern zurück.
 */
function load_account_settings(PDO $pdo, int $account_id): array {
  // Versuchen zu lesen
  $st = $pdo->prepare("
    SELECT account_id, invoice_number_pattern, invoice_next_seq,
           default_vat_rate, default_tax_scheme, default_due_days,
           invoice_intro_text, bank_iban, bank_bic, sender_address
    FROM account_settings
    WHERE account_id = ?
    FOR UPDATE
  ");
  $st->execute([$account_id]);
  $row = $st->fetch();

  if ($row) return $row;

  // Wenn es keinen Datensatz gibt: einen Default anlegen
  $ins = $pdo->prepare("
    INSERT INTO account_settings
      (account_id, invoice_number_pattern, invoice_next_seq,
       default_vat_rate, default_tax_scheme, default_due_days,
       invoice_intro_text, bank_iban, bank_bic, sender_address)
    VALUES
      (?, '{YYYY}-{SEQ:5}', 1, 19.00, 'standard', 14, NULL, NULL, NULL, NULL)
  ");
  $ins->execute([$account_id]);

  // und erneut (mit FOR UPDATE) laden
  $st->execute([$account_id]);
  return $st->fetch();
}

/**
 * Ersetzt Platzhalter im Pattern:
 *  {YYYY}, {YY}, {MM}, {DD}, {SEQ} oder {SEQ:N} (N = Breite, mit führenden Nullen)
 * Default-Breite für {SEQ} ohne N ist 5.
 */
function format_invoice_number(string $pattern, int $seq, string $issue_date): string {
  $dt = DateTime::createFromFormat('Y-m-d', $issue_date) ?: new DateTime();
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

  // Dann {SEQ} / {SEQ:N}
  $out = preg_replace_callback('/\{SEQ(?::(\d+))?\}/', function($m) use ($seq){
    $width = isset($m[1]) && (int)$m[1] > 0 ? (int)$m[1] : 5; // Default 5
    return str_pad((string)$seq, $width, '0', STR_PAD_LEFT);
  }, $out);

  return $out;
}

/**
 * Vergibt atomar die nächste Rechnungsnummer für den Account
 * und erhöht den Zähler in account_settings.
 *
 * Gibt die vergebene Nummer als String zurück.
 */
function allocate_next_invoice_number(PDO $pdo, int $account_id, string $issue_date): string {
  // WICHTIG: Hier wird vorausgesetzt, dass bereits eine äußere Transaction läuft!
  // Wir holen uns die Settings-Zeile FOR UPDATE, um parallel Vergaben zu vermeiden.
  $settings = load_account_settings($pdo, $account_id);

  $pattern = (string)($settings['invoice_number_pattern'] ?? '{YYYY}-{SEQ:5}');
  $seq     = (int)($settings['invoice_next_seq'] ?? 1);

  // Retry-Schleife, falls die Nummer wegen UNIQUE (account_id, invoice_number)
  // kollidiert (z. B. durch parallele Vergabe).
  for ($i = 0; $i < 10; $i++) {
    $number = format_invoice_number($pattern, $seq, $issue_date);

    // auf Kollision prüfen
    $chk = $pdo->prepare("SELECT 1 FROM invoices WHERE account_id = ? AND invoice_number = ? LIMIT 1");
    $chk->execute([$account_id, $number]);
    $exists = (bool)$chk->fetchColumn();

    if (!$exists) {
      // Zähler in settings hochzählen
      $upd = $pdo->prepare("UPDATE account_settings SET invoice_next_seq = ? WHERE account_id = ?");
      $upd->execute([$seq + 1, $account_id]);
      return $number;
    }

    // Kollision → nächste Nummer probieren
    $seq++;
  }

  throw new RuntimeException('Konnte keine eindeutige Rechnungsnummer vergeben (zu viele Kollisionen).');
}

/**
 * Weist einer Rechnung (falls noch leer) eine Nummer zu.
 * Läuft innerhalb einer bestehenden DB-Transaction.
 */
function assign_invoice_number_if_needed(PDO $pdo, int $account_id, int $invoice_id, string $issue_date): ?string {
  // Aktuellen Stand lesen + sperren
  $st = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE id = ? AND account_id = ? FOR UPDATE");
  $st->execute([$invoice_id, $account_id]);
  $inv = $st->fetch();
  if (!$inv) {
    throw new RuntimeException('Rechnung nicht gefunden.');
  }

  if (!empty($inv['invoice_number'])) {
    return null; // already set → nichts tun
  }

  $num = allocate_next_invoice_number($pdo, $account_id, $issue_date);

  $upd = $pdo->prepare("UPDATE invoices SET invoice_number = ? WHERE id = ? AND account_id = ?");
  $upd->execute([$num, $invoice_id, $account_id]);

  return $num;
}