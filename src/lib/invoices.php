<?php
// src/lib/invoices.php

/**
 * Setzt den Status aller Times, die über invoice_item_times an eine Rechnung gebunden sind,
 * in einem Rutsch.
 */
function set_times_status_for_invoice(PDO $pdo, int $account_id, int $invoice_id, string $target): void {
  $upd = $pdo->prepare("
    UPDATE times t
    JOIN invoice_item_times iit
      ON iit.time_id = t.id AND iit.account_id = t.account_id
    JOIN invoice_items ii
      ON ii.id = iit.invoice_item_id AND ii.account_id = iit.account_id
    SET t.status = ?
    WHERE t.account_id = ? AND ii.invoice_id = ?
  ");
  $upd->execute([$target, $account_id, $invoice_id]);
}

/**
 * Löscht ein einzelnes Item (inkl. Links) und setzt betroffene Times ggf. zurück auf 'offen'
 * – nur wenn diese Times nicht mehr in einer anderen Rechnung verlinkt sind.
 */
function delete_item_with_times(PDO $pdo, int $account_id, int $invoice_id, int $item_id): void {
  $ts = $pdo->prepare("SELECT time_id FROM invoice_item_times WHERE account_id = ? AND invoice_item_id = ?");
  $ts->execute([$account_id, $item_id]);
  $timeIds = array_map(fn($r)=>(int)$r['time_id'], $ts->fetchAll());

  $pdo->prepare("DELETE FROM invoice_item_times WHERE account_id = ? AND invoice_item_id = ?")
      ->execute([$account_id, $item_id]);
  $pdo->prepare("DELETE FROM invoice_items WHERE account_id = ? AND id = ? AND invoice_id = ?")
      ->execute([$account_id, $item_id, $invoice_id]);

  if ($timeIds) {
    $in = implode(',', array_fill(0, count($timeIds), '?'));
    $sql = "
      UPDATE times t
      LEFT JOIN (
        SELECT iit.time_id
        FROM invoice_item_times iit
        WHERE iit.account_id = ? AND iit.time_id IN ($in)
        GROUP BY iit.time_id
      ) still_linked ON still_linked.time_id = t.id
      SET t.status = 'offen'
      WHERE t.account_id = ? AND t.id IN ($in) AND still_linked.time_id IS NULL
    ";
    $params = array_merge([$account_id], $timeIds, [$account_id], $timeIds);
    $pdo->prepare($sql)->execute($params);
  }
}