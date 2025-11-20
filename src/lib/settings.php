<?php
// src/lib/settings.php

function settings_defaults(): array {
  return [
    'invoice_number_pattern'         => '{YYYY}-{SEQ}',
    'invoice_seq_pad'                => 5,
    'invoice_next_seq'               => 1,
    'default_vat_rate'               => 19.00,
    'default_tax_scheme'             => 'standard',
    'default_due_days'               => 14,
    'invoice_round_minutes'          => 0,      // Rundung in Minuten
    'invoice_intro_text'             => '',
    'invoice_outro_text'             => '',
    'bank_iban'                      => '',
    'bank_bic'                       => '',
    'sender_name'                    => '',
    'sender_street'                  => '',
    'sender_postcode'                => '',
    'sender_city'                    => '',
    'sender_country'                 => 'DE',
    'sender_vat_id'                  => '',
    // Briefbogen / Layout
    'invoice_letterhead_first_pdf'     => '',
    'invoice_letterhead_first_preview' => '',
    'invoice_letterhead_next_pdf'      => '',
    'invoice_letterhead_next_preview'  => '',
    'invoice_layout_zones'             => '',
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
        (
          account_id,
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
          sender_name,
          sender_street,
          sender_postcode,
          sender_city,
          sender_country,
          sender_vat_id,
          invoice_letterhead_first_pdf,
          invoice_letterhead_first_preview,
          invoice_letterhead_next_pdf,
          invoice_letterhead_next_preview,
          invoice_layout_zones
        )
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
      $defs['sender_name'],
      $defs['sender_street'],
      $defs['sender_postcode'],
      $defs['sender_city'],
      $defs['sender_country'],
      $defs['sender_vat_id'],
      $defs['invoice_letterhead_first_pdf'],
      $defs['invoice_letterhead_first_preview'],
      $defs['invoice_letterhead_next_pdf'],
      $defs['invoice_letterhead_next_preview'],
      $defs['invoice_layout_zones'],
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
 * Speichert/aktualisiert die Account-Settings.
 * Wichtig: Felder, die NICHT in $in enthalten sind, behalten ihren bisherigen Wert
 * (damit Teil-Updates, z.B. nur Layout, nicht andere Settings überschreiben).
 */
function save_account_settings(PDO $pdo, int $account_id, array $in): void {
  // Aktuellen Stand holen (legt bei Bedarf den Datensatz an)
  $current = get_account_settings($pdo, $account_id);

  // --- Sanitizing / Normalisierung ---
  // Wenn im $in nicht vorhanden, behalten wir den bisherigen Wert aus $current

  // Rechnungsnummern-Schema
  if (array_key_exists('invoice_number_pattern', $in)) {
    $pattern = trim((string)$in['invoice_number_pattern']);
    if ($pattern === '') {
      $pattern = '{YYYY}-{SEQ}';
    }
  } else {
    $pattern = (string)$current['invoice_number_pattern'];
  }

  // Länge für {SEQ}
  if (array_key_exists('invoice_seq_pad', $in)) {
    $seqPad = (int)$in['invoice_seq_pad'];
    if ($seqPad < 1)  $seqPad = 1;
    if ($seqPad > 12) $seqPad = 12;
  } else {
    $seqPad = (int)$current['invoice_seq_pad'];
  }

  // Nächste Sequenz
  if (array_key_exists('invoice_next_seq', $in)) {
    $nextSeq = max(1, (int)$in['invoice_next_seq']);
  } else {
    $nextSeq = (int)$current['invoice_next_seq'];
    if ($nextSeq < 1) $nextSeq = 1;
  }

  // Default-VAT
  if (array_key_exists('default_vat_rate', $in)) {
    $vat = (float)str_replace(',', '.', (string)$in['default_vat_rate']);
    $vat = round($vat, 2);
    if ($vat < 0)     $vat = 0;
    if ($vat > 99.99) $vat = 99.99;
  } else {
    $vat = (float)$current['default_vat_rate'];
  }

  // Steuer-Scheme
  if (array_key_exists('default_tax_scheme', $in)) {
    $scheme = (string)$in['default_tax_scheme'];
    if (!in_array($scheme, ['standard','tax_exempt','reverse_charge'], true)) {
      $scheme = 'standard';
    }
  } else {
    $scheme = (string)$current['default_tax_scheme'];
    if (!in_array($scheme, ['standard','tax_exempt','reverse_charge'], true)) {
      $scheme = 'standard';
    }
  }

  // Fälligkeitstage
  if (array_key_exists('default_due_days', $in)) {
    $dueDays = max(0, (int)$in['default_due_days']); // 0 = „heute“
  } else {
    $dueDays = max(0, (int)$current['default_due_days']);
  }

  // Einleitungstext
  if (array_key_exists('invoice_intro_text', $in)) {
    $intro = trim((string)$in['invoice_intro_text']);
  } else {
    $intro = (string)$current['invoice_intro_text'];
  }

  // Schlussformel
  if (array_key_exists('invoice_outro_text', $in)) {
    $outro = trim((string)$in['invoice_outro_text']);
  } else {
    $outro = (string)$current['invoice_outro_text'];
  }

  // IBAN
  if (array_key_exists('bank_iban', $in)) {
    $iban = strtoupper(preg_replace('~[^A-Z0-9]~', '', (string)$in['bank_iban']));
    if ($iban !== '' && !preg_match('~^[A-Z]{2}[0-9A-Z]{13,32}$~', $iban)) {
      // locker validieren; nicht blockieren
    }
  } else {
    $iban = strtoupper((string)$current['bank_iban']);
  }

  // BIC
  if (array_key_exists('bank_bic', $in)) {
    $bic = strtoupper(preg_replace('~[^A-Z0-9]~', '', (string)$in['bank_bic']));
    if ($bic !== '' && !preg_match('~^[A-Z0-9]{8}([A-Z0-9]{3})?$~', $bic)) {
      // locker validieren; nicht blockieren
    }
  } else {
    $bic = strtoupper((string)$current['bank_bic']);
  }

  // NEU: strukturierte Absenderfelder
  if (array_key_exists('sender_name', $in)) {
    $senderName = trim((string)$in['sender_name']);
  } else {
    $senderName = (string)$current['sender_name'];
  }

  if (array_key_exists('sender_street', $in)) {
    $senderStreet = trim((string)$in['sender_street']);
  } else {
    $senderStreet = (string)$current['sender_street'];
  }

  if (array_key_exists('sender_postcode', $in)) {
    $senderPostcode = trim((string)$in['sender_postcode']);
  } else {
    $senderPostcode = (string)$current['sender_postcode'];
  }

  if (array_key_exists('sender_city', $in)) {
    $senderCity = trim((string)$in['sender_city']);
  } else {
    $senderCity = (string)$current['sender_city'];
  }

  if (array_key_exists('sender_country', $in)) {
    $senderCountry = strtoupper(trim((string)$in['sender_country']));
    if ($senderCountry === '') {
      $senderCountry = 'DE';
    }
    if (!preg_match('~^[A-Z]{2}$~', $senderCountry)) {
      // Falls irgendwas Komisches kommt, altes oder DE verwenden
      $senderCountry = $current['sender_country'] ?: 'DE';
    }
  } else {
    $senderCountry = strtoupper($current['sender_country'] ?: 'DE');
  }

  // NEU: USt-IdNr. des Absenders
  if (array_key_exists('sender_vat_id', $in)) {
    // Leerzeichen entfernen, alles groß, sonst nichts hart validieren
    $senderVatId = strtoupper(preg_replace('~\s+~', '', (string)$in['sender_vat_id']));
  } else {
    $senderVatId = strtoupper((string)$current['sender_vat_id']);
  }

  // Minuten-Rundung
  if (array_key_exists('invoice_round_minutes', $in)) {
    $roundMin = (int)$in['invoice_round_minutes'];
    if ($roundMin < 0)  $roundMin = 0;
    if ($roundMin > 60) $roundMin = 60; // harte Obergrenze
  } else {
    $roundMin = (int)$current['invoice_round_minutes'];
    if ($roundMin < 0)  $roundMin = 0;
    if ($roundMin > 60) $roundMin = 60;
  }

  // Briefbogen / Layout-Felder
  if (array_key_exists('invoice_letterhead_first_pdf', $in)) {
    $lhFirstPdf = (string)$in['invoice_letterhead_first_pdf'];
  } else {
    $lhFirstPdf = (string)$current['invoice_letterhead_first_pdf'];
  }

  if (array_key_exists('invoice_letterhead_first_preview', $in)) {
    $lhFirstPrev = (string)$in['invoice_letterhead_first_preview'];
  } else {
    $lhFirstPrev = (string)$current['invoice_letterhead_first_preview'];
  }

  if (array_key_exists('invoice_letterhead_next_pdf', $in)) {
    $lhNextPdf = (string)$in['invoice_letterhead_next_pdf'];
  } else {
    $lhNextPdf = (string)$current['invoice_letterhead_next_pdf'];
  }

  if (array_key_exists('invoice_letterhead_next_preview', $in)) {
    $lhNextPrev = (string)$in['invoice_letterhead_next_preview'];
  } else {
    $lhNextPrev = (string)$current['invoice_letterhead_next_preview'];
  }

  if (array_key_exists('invoice_layout_zones', $in)) {
    $layoutZones = (string)$in['invoice_layout_zones'];
  } else {
    $layoutZones = (string)$current['invoice_layout_zones'];
  }

  // --- UPDATE (Datensatz existiert durch get_account_settings immer) ---
  $upd = $pdo->prepare('
    UPDATE account_settings
       SET invoice_number_pattern          = ?,
           invoice_seq_pad                = ?,
           invoice_next_seq               = ?,
           default_vat_rate               = ?,
           default_tax_scheme             = ?,
           default_due_days               = ?,
           invoice_round_minutes          = ?,
           invoice_intro_text             = ?,
           invoice_outro_text             = ?,
           bank_iban                      = ?,
           bank_bic                       = ?,
           sender_name                    = ?,
           sender_street                  = ?,
           sender_postcode                = ?,
           sender_city                    = ?,
           sender_country                 = ?,
           sender_vat_id                  = ?,
           invoice_letterhead_first_pdf     = ?,
           invoice_letterhead_first_preview = ?,
           invoice_letterhead_next_pdf      = ?,
           invoice_letterhead_next_preview  = ?,
           invoice_layout_zones             = ?
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
    $senderName,
    $senderStreet,
    $senderPostcode,
    $senderCity,
    $senderCountry,
    $senderVatId,
    $lhFirstPdf,
    $lhFirstPrev,
    $lhNextPdf,
    $lhNextPrev,
    $layoutZones,
    $account_id,
  ]);
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