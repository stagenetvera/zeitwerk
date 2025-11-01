<?php
declare(strict_types=1);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function url(string $path): string {
    if (defined('APP_BASE_URL') && strpos($path, APP_BASE_URL) === 0) {
        return $path;
    }
    $base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
    return $base . $path; // $path beginnt mit '/' z.B. '/dashboard.php'
}

function redirect(string $path): void {
    header('Location: ' . url($path));
    exit;
}

// get running time for user (ended_at IS NULL)
function get_running_time(PDO $pdo, int $account_id, int $user_id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM times WHERE account_id = ? AND user_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$account_id, $user_id]);
    return $stmt->fetch() ?: null;
}

// Komma-/Punkt-Dezimal robust in Float wandeln
function dec($s) {
  if ($s === null) return 0.0;
  if (is_float($s) || is_int($s)) return (float)$s;
  $s = trim((string)$s);

  // NBSP/Spaces als Tausendertrenner entfernen
  $s = str_replace(["\xC2\xA0", ' '], '', $s);

  $posComma = strrpos($s, ',');
  $posDot   = strrpos($s, '.');

  if ($posComma !== false && $posDot !== false) {
    // Das spätere Zeichen ist das Dezimaltrennzeichen
    if ($posComma > $posDot) { // EU: 1.234,56
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    } else {                   // US: 1,234.56
      $s = str_replace(',', '', $s);
      // Punkt bleibt Dezimalpunkt
    }
  } else {
    // Nur eines vorhanden → Komma zu Punkt
    $s = str_replace(',', '.', $s);
  }

  $n = (float)$s;
  return is_finite($n) ? $n : 0.0;
}

// "1.5" oder "01:30" -> Stunden als Dezimalzahl
function parse_hours_to_decimal($val): float {
  $val = trim((string)($val ?? ''));
  if ($val === '') return 0.0;
  if (strpos($val, ':') !== false) {
    [$h,$m] = array_pad(explode(':',$val,2), 2, '0');
    return max(0.0, (int)$h + ((int)$m)/60.0);
  }
  return max(0.0, dec($val));
}
function fmt_minutes($m){
  if ($m === null) return '—';
  $m = (int)$m; $h = intdiv($m,60); $r = $m%60;
  return sprintf('%d:%02d',$h,$r);
}
// NEU: Datum in TT.MM.YYYY (Zeit behalten, Sekunden kappen)
function _fmt_dmy($s){
  if (!$s) return '';
  $ts = strtotime((string)$s);
  if (!$ts) return (string)$s;
  $hasTime = preg_match('/\d{2}:\d{2}/', (string)$s);
  return $hasTime ? date('d.m.Y H:i', $ts) : date('d.m.Y', $ts);
}

// NEU: Quantity ohne Nachkommastellen, wenn ganzzahlig; sonst bis 3, ohne trailing zeros
function _fmt_qty($q){
  $q = (float)$q;
  if (fmod($q, 1.0) == 0.0) return number_format($q, 0, '.', '');
  return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
}

// Robuste Parser (fallen auf deine utils-Funktionen zurück)
$NUM = function($v): float {
  if ($v === null || $v === '') return 0.0;
  if (is_numeric($v)) return (float)$v;     // "1.5" oder "2" etc.
  return (float)dec($v);                    // z. B. "1,5"
};
$HOURS = function($v) use ($NUM): float {
  $s = (string)($v ?? '');
  if (strpos($s, ':') !== false) {
    // "hh:mm" → utils
    return (float)parse_hours_to_decimal($s);
  }
  return $NUM($s); // "1.5" oder "1,5"
};
