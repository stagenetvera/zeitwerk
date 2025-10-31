<?php
// src/lib/recurring.php

/**
 * Platzhalter im Template ersetzen.
 * {from},{to} -> YYYY-MM-DD
 * {period}    -> DD.MM.YYYY–DD.MM.YYYY
 * {month}     -> zweistellige Monatszahl der Startgrenze
 * {year}      -> Jahr der Startgrenze
 */
function ri_render_description(string $tpl, string $from, string $to): string {
  $dtFrom = DateTimeImmutable::createFromFormat('Y-m-d', $from) ?: new DateTimeImmutable($from);
  $dtTo   = DateTimeImmutable::createFromFormat('Y-m-d', $to)   ?: new DateTimeImmutable($to);

  $repl = [
    '{from}'   => $dtFrom->format('Y-m-d'),
    '{to}'     => $dtTo->format('Y-m-d'),
    '{period}' => $dtFrom->format('d.m.Y') . '–' . $dtTo->format('d.m.Y'),
    '{month}'  => $dtFrom->format('m'),
    '{year}'   => $dtFrom->format('Y'),
  ];
  return strtr($tpl, $repl);
}

/** Date helper: add interval (day/week/month/quarter/year) * count to a date */
function ri_add_interval(DateTimeImmutable $start, string $unit, int $count): DateTimeImmutable {
  $count = max(1, (int)$count);
  switch ($unit) {
    case 'day':     return $start->modify("+{$count} day");
    case 'week':    return $start->modify("+{$count} week");
    case 'quarter': return $start->modify('+' . (3*$count) . ' month');
    case 'year':    return $start->modify("+{$count} year");
    default:        return $start->modify("+{$count} month"); // month
  }
}

/**
 * Ermittle fällige Perioden für alle aktiven recurring_items einer Firma bis inkl. $asOf (issue_date).
 * Gibt eine Liste von „Runs“ (noch NICHT in recurring_item_runs gespeichert) zurück.
 *
 * Jedes Element:
 *  [
 *    'key' => "{$ri_id}|{$from}|{$to}",
 *    'ri_id','from','to','description','quantity','unit_price','tax_scheme','vat_rate',
 *    'total_net','total_gross'
 *  ]
 *
 * „Schon abgerechnete“ Perioden werden ausgeschlossen, indem die letzte (NICHT stornierte)
 * period_end aus recurring_item_runs bestimmt wird.
 */
function ri_compute_due_runs(PDO $pdo, int $account_id, int $company_id, string $asOf): array {
  $asOf = (new DateTimeImmutable($asOf))->format('Y-m-d');

  $stmt = $pdo->prepare("
    SELECT *
    FROM recurring_items
    WHERE account_id = ? AND company_id = ? AND active = 1
    ORDER BY start_date, id
  ");
  $stmt->execute([$account_id, $company_id]);
  $items = $stmt->fetchAll();
  if (!$items) return [];

  $getLastBilledEnd = $pdo->prepare("
    SELECT MAX(r.period_end)
    FROM recurring_item_runs r
    LEFT JOIN invoices i
      ON i.id = r.invoice_id AND i.account_id = r.account_id
    WHERE r.account_id = ? AND r.recurring_item_id = ?
      AND (r.invoice_id IS NULL OR i.status <> 'storniert')
  ");

  $runs = [];

  foreach ($items as $ri) {
    $ri_id = (int)$ri['id'];
    $unit  = $ri['interval_unit'];            // day|week|month|quarter|year
    $cnt   = max(1, (int)$ri['interval_count']);
    $start = new DateTimeImmutable($ri['start_date']);
    $endLim= !empty($ri['end_date']) ? new DateTimeImmutable($ri['end_date']) : null;

    // Ab wo geht's weiter? -> Tag nach der letzten nicht-stornierten Periode
    $getLastBilledEnd->execute([$account_id, $ri_id]);
    $last = $getLastBilledEnd->fetchColumn();
    $cursor = $last ? (new DateTimeImmutable($last))->modify('+1 day') : $start;

    // Wenn Start > asOf -> noch nichts fällig
    $asOfDt = new DateTimeImmutable($asOf);
    if ($cursor > $asOfDt) continue;

    // Perioden erzeugen, bis period_end <= asOf
    $qty  = (float)$ri['quantity'];
    $unitPrice = (float)$ri['unit_price'];
    $scheme  = $ri['tax_scheme'] ?? 'standard';
    $vatRate = ($scheme === 'standard') ? (float)$ri['vat_rate'] : 0.0;

    while (true) {
      // Endgrenze = cursor + interval - 1 Tag
      $nextStart = ri_add_interval($cursor, $unit, $cnt);
      $periodEnd = $nextStart->modify('-1 day');

      // End-of-life beachten
      if ($endLim && $cursor > $endLim) break;

      // Nur aufnehmen, wenn Enddatum <= asOf
      if ($periodEnd > $asOfDt) break;

      // Perioden-Key bauen
      $fromStr = $cursor->format('Y-m-d');
      $toStr   = $periodEnd->format('Y-m-d');

      $desc = ri_render_description((string)$ri['description_tpl'], $fromStr, $toStr);
      $net  = round($qty * $unitPrice, 2);
      $gross= round($net * (1 + $vatRate/100), 2);

      $runs[] = [
        'key'         => $ri_id . '|' . $fromStr . '|' . $toStr,
        'ri_id'       => $ri_id,
        'from'        => $fromStr,
        'to'          => $toStr,
        'description' => $desc,
        'quantity'    => $qty,
        'unit_price'  => $unitPrice,
        'tax_scheme'  => $scheme,
        'vat_rate'    => $vatRate,
        'total_net'   => $net,
        'total_gross' => $gross,
      ];

      $cursor = $nextStart;
      // endLim kann die Startgrenze irgendwann „überschreiten“ – dann brich ab
      if ($endLim && $cursor > $endLim) break;
    }
  }

  // stabil sortieren (zuerst von-Datum, dann ri_id)
  usort($runs, function($a, $b){
    return [$a['from'],$a['ri_id']] <=> [$b['from'],$b['ri_id']];
  });

  return $runs;
}

/**
 * Prüft, ob in der Auswahl (Keys) Non-Standard enthalten ist.
 */
function ri_selected_has_nonstandard(array $allRuns, array $keys): bool {
  if (!$keys) return false;
  $map = [];
  foreach ($allRuns as $r) $map[$r['key']] = $r;
  foreach ($keys as $k) {
    if (isset($map[$k]) && ($map[$k]['tax_scheme'] ?? 'standard') !== 'standard') return true;
  }
  return false;
}

/**
 * Hängt ausgewählte „Runs“ als invoice_items an und schreibt sie in recurring_item_runs.
 * $posStart = Startposition (laufende Positionen in der Rechnung).
 * Rückgabe: [sum_net, sum_gross, posEnd]
 */
function ri_attach_selected_runs(PDO $pdo, int $account_id, int $invoice_id, int $company_id,
                                 string $issue_date, array $selected_keys, int $posStart = 1): array {
  if (!$selected_keys) return [0.00, 0.00, $posStart];

  // Fällige Runs zu diesem Stichtag neu berechnen, dann Auswahl filtern
  $invStmt = $pdo->prepare("SELECT id FROM invoices WHERE id=? AND account_id=? AND company_id=? LIMIT 1");
  $invStmt->execute([$invoice_id, $account_id, $company_id]);
  if (!$invStmt->fetchColumn()) { return [0.00, 0.00, $posStart]; }

  // Die Firma ziehen wir aus der Rechnung, $company_id ist schon geprüft.
  // Alle Runs:
  $dueAll = []; // wir brauchen company_id -> aber compute braucht company_id; hole aus invoice
  $compStmt = $pdo->prepare("SELECT company_id FROM invoices WHERE id=? AND account_id=?");
  $compStmt->execute([$invoice_id, $account_id]);
  $cid = (int)$compStmt->fetchColumn();

  $dueAll = ri_compute_due_runs($pdo, $account_id, $cid, $issue_date);
  if (!$dueAll) return [0.00, 0.00, $posStart];

  $pick = [];
  $sel = array_flip($selected_keys);
  foreach ($dueAll as $r) if (isset($sel[$r['key']])) $pick[] = $r;
  if (!$pick) return [0.00, 0.00, $posStart];

  // Insert-Statements
  $insItem = $pdo->prepare("
    INSERT INTO invoice_items
      (account_id, invoice_id, project_id, task_id, description,
       quantity, unit_price, vat_rate, total_net, total_gross, position, tax_scheme, entry_mode)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");
  $insRun = $pdo->prepare("
    INSERT INTO recurring_item_runs
      (account_id, recurring_item_id, period_start, period_end, invoice_id,
       description_rendered, quantity, unit_price, vat_rate, tax_scheme, total_net, total_gross)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
  ");

  $sumN = 0.00; $sumG = 0.00; $pos = $posStart;

  foreach ($pick as $r) {
    $desc  = $r['description'];
    $qty   = (float)$r['quantity'];
    $rate  = (float)$r['unit_price'];
    $vat   = (float)$r['vat_rate'];
    $sch   = (string)$r['tax_scheme'];
    $net   = (float)$r['total_net'];
    $gross = (float)$r['total_gross'];

    $insItem->execute([
      $account_id, $invoice_id, null, null, $desc,
      $qty, $rate, $vat, $net, $gross, $pos++, $sch, 'qty' // wiederkehrend = mengenbasierte Position
    ]);
    // recurring run protokollieren
    $insRun->execute([
      $account_id, (int)$r['ri_id'], $r['from'], $r['to'], $invoice_id,
      $desc, $qty, $rate, $vat, $sch, $net, $gross
    ]);

    $sumN += $net; $sumG += $gross;
  }

  return [$sumN, $sumG, $pos];
}

/**
 * Aufruf bei Status-Änderungen der Rechnung – z. B. auf „storniert“.
 * Wenn storniert: recurring_item_runs.invoice_id auf NULL setzen, damit die Perioden wieder fällig werden.
 * Bei Wechsel von „storniert“ -> aktivem Status könnte man die Runs wieder binden (hier nicht benötigt).
 */
function ri_on_invoice_status_change(PDO $pdo, int $account_id, int $invoice_id, string $new_status): void {
  if ($new_status === 'storniert') {
    $upd = $pdo->prepare("UPDATE recurring_item_runs SET invoice_id = NULL WHERE account_id=? AND invoice_id=?");
    $upd->execute([$account_id, $invoice_id]);
  }
}