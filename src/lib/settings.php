<?php
// src/lib/settings.php

function settings_defaults(): array {
  return [
    'invoice_number_pattern' => '{YYYY}-{SEQ}',
    'invoice_seq_pad'        => 5,
    'invoice_next_seq'       => 1,
    'default_vat_rate'       => 19.00,
    'default_tax_scheme'     => 'standard',
    'default_due_days'       => 14,
    'invoice_round_minutes'  => 0,      // NEU: Rundung in Minuten
    'invoice_intro_text'     => '',
    'invoice_outro_text'     => '',
    'bank_iban'              => '',
    'bank_bic'               => '',
    'sender_address'         => '',
  ];
}

/**
 * Lädt die Settings des Accounts. Existiert kein Datensatz, wird er mit Defaults angelegt.
 * Gibt immer ein vollständiges Array (Defaults gemerged) zurück.
 */
function get_account_settings(PDO $pdo, int $account_id): array {
  $st = $pdo->prepare('SELECT * FROM account_settings WHERE account_id = ?');
  $st->execute([$account_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  if (!$row) {
    $defs = settings_defaults();
    $ins = $pdo->prepare('
      INSERT INTO account_settings
        (account_id,
        invoice_number_pattern,
        invoice_seq_pad,
        invoice_next_seq,
        default_vat_rate,
        default_tax_scheme,
        default_due_days,
        invoice_round_minutes,
        invoice_intro_text,
        invoice_outro_text,
        bank_iban,
        bank_bic,
        sender_address)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $ins->execute([
      $account_id,
      $defs['invoice_number_pattern'],
      (int)$defs['invoice_seq_pad'],
      (int)$defs['invoice_next_seq'],
      (float)$defs['default_vat_rate'],
      $defs['default_tax_scheme'],
      (int)$defs['default_due_days'],
      (int)$defs['invoice_round_minutes'],
      $defs['invoice_intro_text'],
      $defs['invoice_outro_text'],
      $defs['bank_iban'],
      $defs['bank_bic'],
      $defs['sender_address'],
    ]);

    // Nach Insert erneut lesen
    $st->execute([$account_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $row['account_id'] = $account_id;
  }

  // Merge, damit neue Defaults (neue Felder) gesetzt sind
  return array_merge(settings_defaults(), $row);
}

/**
 * Speichert/aktualisiert die Account-Settings (Upsert).
 */
function save_account_settings(PDO $pdo, int $account_id, array $in): void {
  // --- Sanitizing / Normalisierung ---
  $pattern = trim((string)($in['invoice_number_pattern'] ?? '{YYYY}-{SEQ}'));

  $seqPad = (int)($in['invoice_seq_pad'] ?? 5);
  if ($seqPad < 1)  $seqPad = 1;
  if ($seqPad > 12) $seqPad = 12;

  $nextSeq = max(1, (int)($in['invoice_next_seq'] ?? 1));

  $vat = (float)str_replace(',', '.', (string)($in['default_vat_rate'] ?? 19));
  $vat = round($vat, 2);
  if ($vat < 0)     $vat = 0;
  if ($vat > 99.99) $vat = 99.99;

  $scheme = (string)($in['default_tax_scheme'] ?? 'standard');
  if (!in_array($scheme, ['standard','tax_exempt','reverse_charge'], true)) {
    $scheme = 'standard';
  }

  $dueDays = max(0, (int)($in['default_due_days'] ?? 14)); // 0 = „heute“

  $intro = trim((string)($in['invoice_intro_text'] ?? ''));
  $outro = trim((string)($in['invoice_outro_text'] ?? ''));

  $iban = strtoupper(preg_replace('~[^A-Z0-9]~', '', (string)($in['bank_iban'] ?? '')));
  if ($iban !== '' && !preg_match('~^[A-Z]{2}[0-9A-Z]{13,32}$~', $iban)) {
    // locker validieren; nicht blockieren
  }

  $bic = strtoupper(preg_replace('~[^A-Z0-9]~', '', (string)($in['bank_bic'] ?? '')));
  if ($bic !== '' && !preg_match('~^[A-Z0-9]{8}([A-Z0-9]{3})?$~', $bic)) {
    // locker validieren; nicht blockieren
  }

  $sender = trim((string)($in['sender_address'] ?? ''));

  // --- Upsert (ohne Schema-Fallbacks) ---
  $st = $pdo->prepare('SELECT 1 FROM account_settings WHERE account_id = ?');
  $st->execute([$account_id]);
  $exists = (bool)$st->fetchColumn();

  $roundMin = (int)($in['invoice_round_minutes'] ?? 0);
  if ($roundMin < 0)  $roundMin = 0;
  if ($roundMin > 60) $roundMin = 60; // harte Obergrenze

  if ($exists) {
    $upd = $pdo->prepare('
      UPDATE account_settings
        SET invoice_number_pattern = ?,
            invoice_seq_pad        = ?,
            invoice_next_seq       = ?,
            default_vat_rate       = ?,
            default_tax_scheme     = ?,
            default_due_days       = ?,
            invoice_round_minutes  = ?,
            invoice_intro_text     = ?,
            invoice_outro_text     = ?,
            bank_iban              = ?,
            bank_bic               = ?,
            sender_address         = ?
      WHERE account_id = ?
    ');
    $upd->execute([
      $pattern,
      $seqPad,
      $nextSeq,
      $vat,
      $scheme,
      $dueDays,
      $roundMin,
      $intro,
      $outro,
      $iban,
      $bic,
      $sender,
      $account_id,
    ]);
  } else {
     $ins = $pdo->prepare('
        INSERT INTO account_settings
          (account_id,
          invoice_number_pattern,
          invoice_seq_pad,
          invoice_next_seq,
          default_vat_rate,
          default_tax_scheme,
          default_due_days,
          invoice_round_minutes,   -- NEU
          invoice_intro_text,
          invoice_outro_text,
          bank_iban,
          bank_bic,
          sender_address)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ');
      $ins->execute([
        $account_id,
        $pattern,
        $seqPad,
        $nextSeq,
        $vat,
        $scheme,
        $dueDays,
        $roundMin,    // NEU
        $intro,
        $outro,
        $iban,
        $bic,
        $sender,
      ]);
  }
}

/**
 * Effektive Steuer-Defaults für eine Firma ermitteln.
 */
function get_effective_tax_defaults(array $acct, ?array $company): array {
  $scheme = ($company && $company['default_tax_scheme'] !== null)
    ? (string)$company['default_tax_scheme']
    : (string)$acct['default_tax_scheme'];

  $vat = ($company && $company['default_vat_rate'] !== null)
    ? (float)$company['default_vat_rate']
    : (float)$acct['default_vat_rate'];

  return [$scheme, $vat];
}