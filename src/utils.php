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