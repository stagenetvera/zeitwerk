<?php
// src/lib/contacts.php

/**
 * 'frau' | 'herr' | 'div' | null
 */
function contacts_sanitize_salutation($s): ?string {
  $s = strtolower(trim((string)$s));
  return in_array($s, ['frau','herr','div'], true) ? $s : null;
}

/**
 * Automatisch eine höfliche Begrüßung bauen.
 * - Frau  → "Sehr geehrte Frau Nachname"
 * - Herr  → "Sehr geehrter Herr Nachname"
 * - Div   → "Guten Tag <Vorname Nachname>" (neutral)
 * - Fallback, wenn nichts da: "Guten Tag"
 */
function contacts_greeting_line(?string $salutation, string $first, string $last): string {
  $first = trim($first);
  $last  = trim($last);

  if ($salutation === 'frau' && $last !== '') return "Sehr geehrte Frau {$last}";
  if ($salutation === 'herr' && $last !== '') return "Sehr geehrter Herr {$last}";

  $full = trim($first . ' ' . $last);
  return $full !== '' ? "Guten Tag {$full}" : "Guten Tag";
}

/**
 * Normalisiert Eingaben aus $_POST für Kontakte.
 */
function contacts_normalize_input(array $in): array {
  $salutation = contacts_sanitize_salutation($in['salutation'] ?? null);
  $first_name = trim((string)($in['first_name'] ?? ''));
  $last_name  = trim((string)($in['last_name'] ?? ''));
  $greeting   = trim((string)($in['greeting_line'] ?? ''));
  if ($greeting === '') {
    $greeting = contacts_greeting_line($salutation, $first_name, $last_name);
  }
  $is_invoice_addressee = !empty($in['is_invoice_addressee']) ? 1 : 0;

  $department = trim((string)($in['department'] ?? ''));
  if (mb_strlen($department) > 120) $department = mb_substr($department, 0, 120);

  $email = trim((string)($_POST['email'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));

  $phone_alt  = trim((string)($in['phone_alt'] ?? ''));
  $phone_alt  = preg_replace('~[^0-9+()\s/\-]~', '', $phone_alt); // nur übliche Tel.-Zeichen


  return [
    'salutation'            => $salutation,
    'first_name'            => $first_name,
    'last_name'             => $last_name,
    'greeting_line'         => $greeting,
    'is_invoice_addressee'  => $is_invoice_addressee,
    'email'                 => $email,
    'phone'                 => $phone,
    'phone_alt'             => $phone_alt,
    'department'            => $department
  ];
}