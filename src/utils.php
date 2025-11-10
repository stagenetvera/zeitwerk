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


/**
 * Berechnet die Summen für eine Rechnung anhand der gespeicherten invoice_items.
 *
 * - Nur sichtbare Positionen (is_hidden = 0)
 * - Gruppiert Netto nach (tax_scheme, vat_rate)
 * - Berechnet MwSt pro Gruppe und daraus Total-Brutto
 *
 * Rückgabe:
 * [
 *   'total_net'   => float,
 *   'total_gross' => float,
 *   'total_vat'   => float,
 *   'vat_groups'  => [
 *     ['scheme' => 'standard', 'rate' => 19.00, 'net' => 123.45, 'vat' => 23.46],
 *     ...
 *   ],
 * ]
 */
function calculate_invoice_totals(PDO $pdo, int $account_id, int $invoice_id): array {
  $st = $pdo->prepare("
    SELECT tax_scheme, vat_rate, total_net, COALESCE(is_hidden,0) AS is_hidden
    FROM invoice_items
    WHERE account_id = ? AND invoice_id = ?
  ");
  $st->execute([$account_id, $invoice_id]);

  $sum_net = 0.0;
  $vatGroups = []; // key: "$scheme:$rate"

  while ($row = $st->fetch()) {
    $isHidden = (int)($row['is_hidden'] ?? 0);
    if ($isHidden === 1) continue; // wie beim sichtbaren UI

    $scheme = (string)($row['tax_scheme'] ?? 'standard');
    $vat    = ($scheme === 'standard') ? (float)($row['vat_rate'] ?? 0.0) : 0.0;
    $net    = (float)($row['total_net'] ?? 0.0);

    $sum_net += $net;

    // Nur echte MwSt (standard, >0%) in Gruppen
    if ($scheme === 'standard' && $vat > 0.0) {
      $key = sprintf('%s:%.4f', $scheme, $vat);
      if (!isset($vatGroups[$key])) {
        $vatGroups[$key] = [
          'scheme' => $scheme,
          'rate'   => $vat,
          'net'    => 0.0,
          'vat'    => 0.0,
        ];
      }
      $vatGroups[$key]['net'] += $net;
    }
  }

  $sum_vat = 0.0;
  foreach ($vatGroups as &$group) {
    $baseVat     = $group['net'] * $group['rate'] / 100.0;
    $group['vat'] = round_half_up($baseVat, 2);
    $sum_vat += $group['vat'];
  }
  unset($group);

  $total_net   = round($sum_net, 2);
  $total_vat   = round($sum_vat, 2);
  $total_gross = round($total_net + $total_vat, 2);

  return [
    'total_net'   => $total_net,
    'total_gross' => $total_gross,
    'total_vat'   => $total_vat,
    'vat_groups'  => array_values($vatGroups),
  ];
}


/**
 * Kaufmännisches Runden (round half up) auf $places Nachkommastellen.
 * EN 16931-konform für MwSt-Beträge.
 */
function round_half_up(float $value, int $places = 2): float {
    $factor = pow(10, $places);

    if ($value >= 0) {
        return floor($value * $factor + 0.5) / $factor;
    }
    // negative Werte symmetrisch behandeln
    return ceil($value * $factor - 0.5) / $factor;
}