<?php
// src/lib/return_to.php

/**
 * Gibt den aktuellen Pfad + Query der Seite zurück (z.B. "/invoices/edit.php?id=12").
 */
function rt_current_path_and_query(): string {
  $uri = $_SERVER['REQUEST_URI'] ?? '/';
  // Nur Pfad + Query (Fragment spielt beim Redirect eh keine Rolle)
  return (string)$uri;
}

/**
 * Sanitizer gegen Open-Redirect:
 * - Erlaubt nur relative Pfade (beginnend mit "/")
 * - oder absolute URLs zur selben Host-Domain → wird zu Pfad+Query reduziert
 * - alles andere -> $fallback
 */
function rt_sanitize_return_to(?string $raw, string $fallback): string {
  $raw = trim((string)$raw);
  if ($raw === '') return $fallback;

  // CRLF/Whitespaces entfernen
  $raw = preg_replace('/[\r\n]/', '', $raw);

  // Relative Pfade erlauben
  if (strpos($raw, '/') === 0) return $raw;

  // Absolute URL? Nur gleiche Host-Domain zulassen
  $p = @parse_url($raw);
  if ($p && !empty($p['scheme']) && !empty($p['host'])) {
    $currHost = $_SERVER['HTTP_HOST'] ?? '';
    $isSame = (strcasecmp($p['host'], $currHost) === 0);
    if ($isSame) {
      $path = ($p['path'] ?? '/');
      $qry  = isset($p['query']) && $p['query'] !== '' ? ('?'.$p['query']) : '';
      return $path.$qry;
    }
    return $fallback;
  }

  // alles andere verwerfen
  return $fallback;
}

/**
 * Ermittelt das gewünschte return_to aus GET/POST, ansonsten:
 * - wenn $fallback gesetzt → den
 * - sonst die aktuelle Seite
 */
function pick_return_to(?string $fallback = null): string {
  $default = $fallback !== null ? $fallback : rt_current_path_and_query();
  $fromReq = $_POST['return_to'] ?? $_GET['return_to'] ?? '';
  return rt_sanitize_return_to($fromReq, $default);
}

/**
 * Hidden-Feld fürs Formular.
 */
function return_to_hidden(string $return_to): string {
  $v = htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8');
  return '<input type="hidden" name="return_to" value="'.$v.'">';
}

/**
 * Nach Erfolg: leite zu return_to (oder $fallback) weiter.
 * $fallback kann z. B. die aktuelle Seite oder eine „Show“-Seite sein.
 */
function redirect_to_return_to(string $fallback): void {
  $target = pick_return_to($fallback);
  // Falls du eine url()-Funktion hast, kannst du hier wrappen:
  if (function_exists('url')) {
    $target = url($target);
  }
  header('Location: '.$target);
  exit;
}
/**
 * Hängt ?return_to=<aktuelle Seite> an die übergebene URL an.
 * - Nutzt Pfad+Query der aktuellen Seite als Wert (gegen Open-Redirect sanitiziert).
 * - Überschreibt keinen bereits vorhandenen return_to-Parameter.
 * - Erhält vorhandene Query-Parameter und Fragment (#...).
 */
function with_return_to(string $url, ?string $return_to = null): string {
  // 1) Wert für return_to bestimmen und sicher machen
  $fallback = rt_current_path_and_query();
  $rt_value = rt_sanitize_return_to($return_to ?? $fallback, $fallback);

  // 2) Ziel-URL parsen (wir rekonstruieren bewusst als Pfad+Query+Fragment)
  $parts = @parse_url($url) ?: [];
  $path  = $parts['path'] ?? $url;

  // vorhandene Query in Array
  $query = [];
  if (!empty($parts['query'])) {
    parse_str($parts['query'], $query);
  }

  // 3) return_to nur setzen, wenn nicht schon vorhanden
  if (!array_key_exists('return_to', $query) || $query['return_to'] === '' || $query['return_to'] === null) {
    $query['return_to'] = $rt_value;
  }

  // 4) wieder zusammenbauen
  $qs   = http_build_query($query);
  $frag = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

  return $path . ($qs !== '' ? '?'.$qs : '') . $frag;
}