<?php
// src/lib/flash.php
// Session-based flash messages with Bootstrap-like rendering.
// Assumes the session is started in your bootstrap.

if (!function_exists('flash')) {
  function flash(string $message, string $type = 'info'): void {
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
      $_SESSION['flash'] = [];
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
      'error'   => 'danger', // alias
    ];

    $all = flash_take_all();
    if (!$all) return;

    // Inject CSS/JS once per request
    static $assetsInjected = false;
    if (!$assetsInjected) {
      $assetsInjected = true;



    }

    // Render the stack and alerts
    echo '<div class="flash-stack" id="flash-stack">';
    foreach ($all as $f) {
      $t = $map[$f['t']] ?? 'info';
      $m = htmlspecialchars((string)$f['m'], ENT_QUOTES, 'UTF-8');
      // Each alert can optionally override duration via data-ms (keeps default 5000 otherwise)
      echo '<div class="alert alert-' . $t . '" role="alert" data-ms="5000">';
      echo   '<div class="d-flex justify-content-between align-items-start gap-3">';
      echo     '<div>' . $m . '</div>';
      echo     '<button type="button" class="btn-close" aria-label="Close"></button>';
      echo   '</div>';
      echo '</div>';
    }
    echo '</div>';
  }
}