<?php
// src/lib/settings.php

function settings_get(PDO $pdo, int $account_id, string $name, ?string $default = null): ?string {
  $st = $pdo->prepare('SELECT value FROM account_settings WHERE account_id = ? AND name = ?');
  $st->execute([$account_id, $name]);
  $v = $st->fetchColumn();
  return ($v === false) ? $default : (string)$v;
}

function settings_set(PDO $pdo, int $account_id, string $name, string $value): void {
  $st = $pdo->prepare('
    INSERT INTO account_settings (account_id, name, value) VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE value = VALUES(value)
  ');
  $st->execute([$account_id, $name, $value]);
}

function settings_get_int(PDO $pdo, int $account_id, string $name, int $default = 0): int {
  $v = settings_get($pdo, $account_id, $name, null);
  if ($v === null) return $default;
  $n = (int)$v;
  return $n >= 0 ? $n : $default;
}