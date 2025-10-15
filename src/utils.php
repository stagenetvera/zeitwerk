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
