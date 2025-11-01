<?php
// src/lib/settings.php

function settings_defaults(): array {
  return [
    'invoice_number_pattern' => '{YYYY}-{SEQ}',
    'invoice_seq_pad'        => 5,       // NEU: Breite für {SEQ} ohne :N
    'invoice_next_seq'       => 1,
    'default_vat_rate'       => 19.00,
    'default_tax_scheme'     => 'standard', // standard | tax_exempt | reverse_charge
    'default_due_days'       => 14,
    'invoice_intro_text'     => '',
    'bank_iban'              => '',
    'bank_bic'               => '',
    'sender_address'         => '',
  ];
}

function get_account_settings(PDO $pdo, int $account_id): array {
  // 1) Lesen – zunächst mit invoice_seq_pad
  try {
    $st = $pdo->prepare('SELECT * FROM account_settings WHERE account_id = ?');
    $st->execute([$account_id]);
    $row = $st->fetch() ?: [];
  } catch (PDOException $e) {
    // Sollte hier praktisch nicht auftreten; halten den Fallback-Flow konsistent
    $row = [];
  }

  // Falls es keinen Eintrag gibt → Anlage mit Defaults
  if (!$row) {
    $defs = settings_defaults();

    // Versuch: Insert MIT invoice_seq_pad
    try {
      $ins = $pdo->prepare('
        INSERT INTO account_settings
          (account_id, invoice_number_pattern, invoice_seq_pad, invoice_next_seq,
           default_vat_rate, default_tax_scheme, default_due_days,
           invoice_intro_text, bank_iban, bank_bic, sender_address)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ');
      $ins->execute([
        $account_id,
        $defs['invoice_number_pattern'],
        (int)$defs['invoice_seq_pad'],
        $defs['invoice_next_seq'],
        $defs['default_vat_rate'],
        $defs['default_tax_scheme'],
        $defs['default_due_days'],
        $defs['invoice_intro_text'],
        $defs['bank_iban'],
        $defs['bank_bic'],
        $defs['sender_address'],
      ]);
    } catch (PDOException $e) {
      // Fallback: Insert OHNE invoice_seq_pad (älteres Schema)
      $ins = $pdo->prepare('
        INSERT INTO account_settings
          (account_id, invoice_number_pattern, invoice_next_seq,
           default_vat_rate, default_tax_scheme, default_due_days,
           invoice_intro_text, bank_iban, bank_bic, sender_address)
        VALUES (?,?,?,?,?,?,?,?,?,?)
      ');
      $ins->execute([
        $account_id,
        $defs['invoice_number_pattern'],
        $defs['invoice_next_seq'],
        $defs['default_vat_rate'],
        $defs['default_tax_scheme'],
        $defs['default_due_days'],
        $defs['invoice_intro_text'],
        $defs['bank_iban'],
        $defs['bank_bic'],
        $defs['sender_address'],
      ]);
    }

    // Danach erneut lesen (mit evtl. vorhandener Spalte)
    try {
      $st = $pdo->prepare('SELECT * FROM account_settings WHERE account_id = ?');
      $st->execute([$account_id]);
      $row = $st->fetch() ?: [];
    } catch (PDOException $e) {
      $row = [];
    }

    // Merge mit Defaults sicherstellen
    return array_merge($defs, $row);
  }

  // Merge mit defaults (falls neue Spalten hinzukamen)
  return array_merge(settings_defaults(), $row);
}

function save_account_settings(PDO $pdo, int $account_id, array $in): void {
  // Sanitizing
  $pattern = trim($in['invoice_number_pattern'] ?? '{YYYY}-{SEQ}');

  $seqPad  = isset($in['invoice_seq_pad']) ? (int)$in['invoice_seq_pad'] : 5;
  if ($seqPad < 1)  $seqPad = 1;
  if ($seqPad > 12) $seqPad = 12;

  $nextSeq = max(1, (int)($in['invoice_next_seq'] ?? 1));

  $vat     = (float)str_replace(',', '.', (string)($in['default_vat_rate'] ?? 19));
  $vat     = round($vat, 2);
  if ($vat < 0)     $vat = 0;
  if ($vat > 99.99) $vat = 99.99;

  $scheme  = $in['default_tax_scheme'] ?? 'standard';
  if (!in_array($scheme, ['standard','tax_exempt','reverse_charge'], true)) {
    $scheme = 'standard';
  }

  $dueDays = max(0, (int)($in['default_due_days'] ?? 14)); // 0 = „heute“, ansonsten n Tage

  $intro   = trim((string)($in['invoice_intro_text'] ?? ''));

  $iban    = strtoupper(preg_replace('~[^A-Z0-9]~', '', (string)($in['bank_iban'] ?? '')));
  // Locker prüfen, nicht blockieren
  if ($iban !== '' && !preg_match('~^[A-Z]{2}[0-9A-Z]{13,32}$~', $iban)) {
    // optional: Logging / Hinweis
  }

  $bic     = strtoupper(preg_replace('~[^A-Z0-9]~', '', (string)($in['bank_bic'] ?? '')));
  if ($bic !== '' && !preg_match('~^[A-Z0-9]{8}([A-Z0-9]{3})?$~', $bic)) {
    // optional: Logging / Hinweis
  }

  $sender  = trim((string)($in['sender_address'] ?? ''));

  // Upsert
  $st = $pdo->prepare('SELECT 1 FROM account_settings WHERE account_id = ?');
  $st->execute([$account_id]);
  $exists = (bool)$st->fetchColumn();

  if ($exists) {
    // Versuch: UPDATE MIT invoice_seq_pad
    try {
      $upd = $pdo->prepare('
        UPDATE account_settings
           SET invoice_number_pattern = ?,
               invoice_seq_pad        = ?,
               invoice_next_seq       = ?,
               default_vat_rate       = ?,
               default_tax_scheme     = ?,
               default_due_days       = ?,
               invoice_intro_text     = ?,
               bank_iban              = ?,
               bank_bic               = ?,
               sender_address         = ?
         WHERE account_id = ?
      ');
      $upd->execute([$pattern, $seqPad, $nextSeq, $vat, $scheme, $dueDays, $intro, $iban, $bic, $sender, $account_id]);
    } catch (PDOException $e) {
      // Fallback: UPDATE OHNE invoice_seq_pad (älteres Schema)
      $upd = $pdo->prepare('
        UPDATE account_settings
           SET invoice_number_pattern = ?,
               invoice_next_seq       = ?,
               default_vat_rate       = ?,
               default_tax_scheme     = ?,
               default_due_days       = ?,
               invoice_intro_text     = ?,
               bank_iban              = ?,
               bank_bic               = ?,
               sender_address         = ?
         WHERE account_id = ?
      ');
      $upd->execute([$pattern, $nextSeq, $vat, $scheme, $dueDays, $intro, $iban, $bic, $sender, $account_id]);
    }
  } else {
    // Versuch: INSERT MIT invoice_seq_pad
    try {
      $ins = $pdo->prepare('
        INSERT INTO account_settings
          (account_id, invoice_number_pattern, invoice_seq_pad, invoice_next_seq,
           default_vat_rate, default_tax_scheme, default_due_days,
           invoice_intro_text, bank_iban, bank_bic, sender_address)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ');
      $ins->execute([$account_id, $pattern, $seqPad, $nextSeq, $vat, $scheme, $dueDays, $intro, $iban, $bic, $sender]);
    } catch (PDOException $e) {
      // Fallback: INSERT OHNE invoice_seq_pad
      $ins = $pdo->prepare('
        INSERT INTO account_settings
          (account_id, invoice_number_pattern, invoice_next_seq,
           default_vat_rate, default_tax_scheme, default_due_days,
           invoice_intro_text, bank_iban, bank_bic, sender_address)
        VALUES (?,?,?,?,?,?,?,?,?,?)
      ');
      $ins->execute([$account_id, $pattern, $nextSeq, $vat, $scheme, $dueDays, $intro, $iban, $bic, $sender]);
    }
  }
}

/**
 * Ermittelt die effektiven Steuer-Defaults für eine Firma:
 * - nimmt Firmen-Override, wenn vorhanden
 * - sonst Account-Standard
 */
function get_effective_tax_defaults(array $acct, ?array $company): array {
  $scheme = $company && $company['default_tax_scheme'] !== null
    ? (string)$company['default_tax_scheme']
    : (string)$acct['default_tax_scheme'];

  $vat = $company && $company['default_vat_rate'] !== null
    ? (float)$company['default_vat_rate']
    : (float)$acct['default_vat_rate'];

  return [$scheme, $vat];
}