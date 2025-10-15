<?php
// src/lib/flash.php
// Simple session-based flash messages with Bootstrap rendering.
if (!function_exists('flash')) {
  function flash(string $message, string $type = 'info'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      // Do not start the session here; assume it's already started by bootstrap.
    }
    $_SESSION['flash'][] = ['m' => $message, 't' => $type];
  }
}

if (!function_exists('flash_peek_all')) {
  function flash_peek_all(): array {
    return $_SESSION['flash'] ?? [];
  }
}

if (!function_exists('flash_take_all')) {
  function flash_take_all(): array {
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
  }
}

if (!function_exists('flash_render_bootstrap')) {
  function flash_render_bootstrap(): void {
    $map = [
      'success' => 'success',
      'info'    => 'info',
      'warning' => 'warning',
      'danger'  => 'danger',
      'error'   => 'danger',
    ];
    $all = flash_take_all();
    foreach ($all as $f) {
      $t = $map[$f['t']] ?? 'info';
      $m = htmlspecialchars($f['m'], ENT_QUOTES, 'UTF-8');
      echo '<div class="alert alert-'.$t.'">'.$m.'</div>';
    }
  }
}