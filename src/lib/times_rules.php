<?php
// src/lib/times_rules.php
if (!function_exists('enforce_task_billable_on_time')) {
  /**
   * Erzwingt: Wenn die ausgewählte Aufgabe nicht fakturierbar ist,
   * MUSS die Zeit als nicht fakturierbar gespeichert werden.
   * Gibt das bereinigte billable-Flag (0/1) zurück.
   */
  function enforce_task_billable_on_time(PDO $pdo, int $account_id, ?int $task_id, $incoming_billable): int {
    $incoming = (int)!empty($incoming_billable);
    if (!$task_id) return $incoming; // keine Aufgabe gewählt -> nichts erzwingen

    $st = $pdo->prepare("SELECT billable FROM tasks WHERE account_id=? AND id=?");
    $st->execute([$account_id, (int)$task_id]);
    $taskBillable = $st->fetchColumn();
    if ($taskBillable === false) {
      // unbekannte Aufgabe -> sicherheitshalber eingehenden Wert verwenden
      return $incoming;
    }
    return ((int)$taskBillable === 1) ? $incoming : 0;
  }
}