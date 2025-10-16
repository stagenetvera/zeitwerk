<?php
// src/lib/return_to.php

// Sanitizer: nur gleiche Site / relative Pfade erlauben (kein http://, https://)
function sanitize_return_to(?string $s): ?string {
  if (!$s) return null;
  // absolute URLs verbieten
  if (preg_match('~^(?:https?:)?//~i', $s)) return null;
  // nur absolute *relative* Pfade erlauben (beginnt mit "/")
  if (!str_starts_with($s, '/')) return null;

  // Optional: nur innerhalb deiner App erlauben (Wenn dein App-Pfad z. B. "/zeitwerk/public")
  if (!str_starts_with($s, APP_BASE_URL)) return null;
  return $s;
}

/**
 * Ermittelt einen gültigen return_to:
 * 1) POST['return_to'] → 2) GET['return_to'] → 3) HTTP_REFERER → 4) Fallback
 */
function pick_return_to(string $fallback = '/dashboard/index.php'): string {
  $rt = $_POST['return_to'] ?? ($_GET['return_to'] ?? '');
  if (!$rt && isset($_SERVER['HTTP_REFERER'])) {
    $rt = $_SERVER['HTTP_REFERER'];
  }
  return sanitize_return_to($rt) ?: $fallback;
}

/** Hidden-Feld fürs Formular (nimmt optional vorgegebenen Wert, sonst pickt es selbst). */
function return_to_hidden(?string $rt = null): string {
  $val = $rt !== null ? sanitize_return_to($rt) : pick_return_to('');
  return $val ? '<input type="hidden" name="return_to" value="'.htmlspecialchars($val, ENT_QUOTES, 'UTF-8').'">' : '';
}

/** URL-Helfer: hängt return_to als Query an, wenn erlaubt. */
function with_return_to(string $url, ?string $rt = null): string {
  $val = $rt !== null ? sanitize_return_to($rt) : (isset($_SERVER['REQUEST_URI']) ? sanitize_return_to($_SERVER['REQUEST_URI']) : null);
  if (!$val) return $url;
  $sep = (str_contains($url, '?') ? '&' : '?');
  return $url . $sep . 'return_to=' . rawurlencode($val);
}

/** Komfort: sofort zurück (nutzt pick_return_to + redirect) */
function redirect_back(string $fallback = '/dashboard/index.php'): void {
  redirect(pick_return_to($fallback));
}