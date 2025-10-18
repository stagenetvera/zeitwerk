<?php
// src/lib/settings.php

function settings_defaults(): array {
  return [
    'invoice_number_pattern' => '{YYYY}-{SEQ}',
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
  $st = $pdo->prepare('SELECT * FROM account_settings WHERE account_id = ?');
  $st->execute([$account_id]);
  $row = $st->fetch() ?: [];

  // Falls es keinen Eintrag gibt → Anlage mit Defaults
  if (!$row) {
    $defs = settings_defaults();
    $ins = $pdo->prepare('
      INSERT INTO account_settings
        (account_id, invoice_number_pattern, invoice_next_seq, default_vat_rate, default_tax_scheme,
         default_due_days, invoice_intro_text, bank_iban, bank_bic, sender_address)
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
    return $defs;
  }

  // merge mit defaults (falls neue Spalten hinzukamen)
  return array_merge(settings_defaults(), $row);
}

function save_account_settings(PDO $pdo, int $account_id, array $in): void {
  // Sanitizing
  $pattern = trim($in['invoice_number_pattern'] ?? '{YYYY}-{SEQ}');
  $nextSeq = max(1, (int)($in['invoice_next_seq'] ?? 1));
  $vat     = round((float)str_replace(',', '.', (string)($in['default_vat_rate'] ?? 19)), 2);
  if ($vat < 0) $vat = 0; if ($vat > 99.99) $vat = 99.99;

  $scheme  = $in['default_tax_scheme'] ?? 'standard';
  if (!in_array($scheme, ['standard','tax_exempt','reverse_charge'], true)) $scheme = 'standard';

  $dueDays = max(0, (int)($in['default_due_days'] ?? 14)); // 0 = „heute“, ansonsten n Tage

  $intro   = trim((string)($in['invoice_intro_text'] ?? ''));

  $iban    = strtoupper(preg_replace('~[^A-Z0-9]~', '', (string)($in['bank_iban'] ?? '')));
  if ($iban !== '' && !preg_match('~^[A-Z]{2}[0-9A-Z]{13,32}$~', $iban)) {
    // locker validiert; nicht blockieren – du kannst hier sonst Exception werfen
  }

  $bic     = strtoupper(preg_replace('~[^A-Z0-9]~', '', (string)($in['bank_bic'] ?? '')));
  if ($bic !== '' && !preg_match('~^[A-Z0-9]{8}([A-Z0-9]{3})?$~', $bic)) {
    // dito – locker prüfen
  }

  $sender  = trim((string)($in['sender_address'] ?? ''));

  // Upsert
  $st = $pdo->prepare('SELECT 1 FROM account_settings WHERE account_id = ?');
  $st->execute([$account_id]);
  $exists = (bool)$st->fetchColumn();

  if ($exists) {
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
  } else {
    $ins = $pdo->prepare('
      INSERT INTO account_settings
        (account_id, invoice_number_pattern, invoice_next_seq, default_vat_rate, default_tax_scheme,
         default_due_days, invoice_intro_text, bank_iban, bank_bic, sender_address)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ');
    $ins->execute([$account_id, $pattern, $nextSeq, $vat, $scheme, $dueDays, $intro, $iban, $bic, $sender]);
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