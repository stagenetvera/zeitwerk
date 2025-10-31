<?php
/**
 * src/lib/recurring.php
 *
 * Wiederkehrende Positionen auf Basis der Tabelle `recurring_items`
 * (description_tpl, interval_unit, interval_count, start_date, end_date).
 *
 * Doppel-Abrechnung wird über die Tabelle `recurring_item_ledger` verhindert.
 * Storno: Ledger-Verknüpfungen zur stornierten Rechnung werden entfernt.
 *
 * Öffentliche Helfer:
 *  - ri_compute_due_runs($pdo, $account_id, $company_id, $issue_date): array
 *  - ri_preview_due_flags($pdo, $account_id, $company_id, $issue_date): [bool,bool]
 *  - ri_attach_due_items($pdo, $account_id, $company_id, $invoice_id, $issue_date, $start_pos, ?array $selected_keys=null): [sum_net, sum_gross, next_pos]
 *  - ri_unlink_runs_for_invoice($pdo, $account_id, $invoice_id): void
 */

function _ri_dt(string $ymd): DateTimeImmutable {
  return new DateTimeImmutable($ymd ?: '1970-01-01');
}
function _ri_end_inclusive(DateTimeImmutable $nextStart): DateTimeImmutable {
  return $nextStart->modify('-1 day');
}
function _ri_add_interval(DateTimeImmutable $start, string $unit, int $count): DateTimeImmutable {
  $count = max(1, (int)$count);
  switch ($unit) {
    case 'day':    return $start->modify("+{$count} day");
    case 'week':   return $start->modify("+{$count} week");
    case 'quarter':return $start->modify("+".(3*$count)." month");
    case 'year':   return $start->modify("+{$count} year");
    case 'month':
    default:       return $start->modify("+{$count} month");
  }
}

function _ri_month_name_de(int $m): string {
  static $n = [1=>'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
  return $n[$m] ?? '';
}

function ri_render_description(string $tpl, DateTimeImmutable $from, DateTimeImmutable $to): string {
  $repl = [
    '{from}'    => $from->format('d.m.Y'),
    '{to}'      => $to->format('d.m.Y'),
    '{period}'  => $from->format('d.m.Y').' – '.$to->format('d.m.Y'),
    '{month}'   => _ri_month_name_de((int)$from->format('n')),
    '{year}'    => $from->format('Y'),
    '{YYYY-MM}' => $from->format('Y-m'),
  ];
  return strtr($tpl, $repl);
}

/**
 * Perioden eines Items bis zum issue_date (alle Perioden, deren Ende <= issue_date)
 * end_date begrenzt optional.
 */
function _ri_periods_for_item(array $it, DateTimeImmutable $issue): array {
  $unit  = strtolower((string)($it['interval_unit'] ?? 'month'));
  $cnt   = max(1, (int)($it['interval_count'] ?? 1));
  $start = _ri_dt((string)$it['start_date']);
  $endLimit = !empty($it['end_date']) ? _ri_dt((string)$it['end_date']) : null;

  $periods = [];
  $cur = $start->setTime(0,0,0);
  while (true) {
    $next = _ri_add_interval($cur, $unit, $cnt)->setTime(0,0,0);
    $to   = _ri_end_inclusive($next);

    // Laufzeitlimit
    if ($endLimit && $cur > $endLimit) break;

    // Fällig nur, wenn Ende <= issue
    if ($to <= $issue) {
      $periods[] = [$cur, $to];
      $cur = $next;
      continue;
    }
    break;
  }
  return $periods;
}

function _ri_load_items(PDO $pdo, int $account_id, int $company_id): array {
  $st = $pdo->prepare("
    SELECT id, account_id, company_id, description_tpl, quantity, unit_price,
           tax_scheme, vat_rate, interval_unit, interval_count, start_date, end_date, active
    FROM recurring_items
    WHERE account_id = ? AND company_id = ? AND COALESCE(active,1)=1
    ORDER BY start_date, id
  ");
  $st->execute([$account_id, $company_id]);
  return $st->fetchAll() ?: [];
}

function _ri_run_key(int $ri_id, DateTimeImmutable $from, DateTimeImmutable $to): string {
  return 'ri:'.$ri_id.':'.$from->format('Y-m-d').':'.$to->format('Y-m-d');
}

/**
 * Filtert Runs, die bereits mit einer NICHT-stornierten Rechnung im Ledger stehen.
 */
function _ri_filter_out_ledger_linked(PDO $pdo, int $account_id, array $runs): array {
  if (!$runs) return [];
  // triples aufbereiten
  $triples = [];
  foreach ($runs as $r) {
    $triples[] = [(int)$r['recurring_item_id'], $r['from'], $r['to']];
  }
  $keep = [];
  foreach (array_chunk($triples, 200) as $chunk) {
    $place = []; $params = [$account_id];
    foreach ($chunk as $c) { $place[]='(?,?,?)'; $params[]=$c[0]; $params[]=$c[1]; $params[]=$c[2]; }
    $sql = "
      SELECT l.recurring_item_id, l.period_from, l.period_to, l.invoice_id, i.status
      FROM recurring_item_ledger l
      LEFT JOIN invoices i ON i.account_id=l.account_id AND i.id=l.invoice_id
      WHERE l.account_id=? AND (l.recurring_item_id,l.period_from,l.period_to) IN (".implode(',', $place).")
    ";
    $st = $pdo->prepare($sql); $st->execute($params);
    $blocked = [];
    foreach ($st->fetchAll() as $row) {
      $key = $row['recurring_item_id'].'|'.$row['period_from'].'|'.$row['period_to'];
      $blocked[$key] = (!empty($row['invoice_id']) && ($row['status'] ?? '') !== 'storniert');
    }
    foreach ($chunk as $c) {
      $k = $c[0].'|'.$c[1].'|'.$c[2];
      if (empty($blocked[$k])) {
        $keep[] = ['recurring_item_id'=>$c[0], 'from'=>$c[1], 'to'=>$c[2]];
      }
    }
  }
  // map zurück
  $index = [];
  foreach ($runs as $r) {
    $index[$r['recurring_item_id'].'|'.$r['from'].'|'.$r['to']] = $r;
  }
  $out = [];
  foreach ($keep as $k) {
    $kk = $k['recurring_item_id'].'|'.$k['from'].'|'.$k['to'];
    if (isset($index[$kk])) $out[] = $index[$kk];
  }
  return $out;
}

/**
 * Berechnet fällige Runs (inkl. Beschreibung & Preisen) bis issue_date.
 */
function ri_compute_due_runs(PDO $pdo, int $account_id, int $company_id, string $issue_date): array {
  $issue = _ri_dt($issue_date);
  $items = _ri_load_items($pdo, $account_id, $company_id);
  $runs  = [];

  foreach ($items as $it) {
    $ri_id   = (int)$it['id'];
    $periods = _ri_periods_for_item($it, $issue);
    if (!$periods) continue;

    $qty   = (float)$it['quantity'];
    $price = (float)$it['unit_price'];
    $scheme= (string)$it['tax_scheme'];
    $vat   = (float)($scheme==='standard' ? $it['vat_rate'] : 0.0);
    $tpl   = (string)$it['description_tpl'];

    foreach ($periods as [$pf,$pt]) {
      $desc = ri_render_description($tpl, $pf, $pt);
      $runs[] = [
        'key'               => _ri_run_key($ri_id, $pf, $pt),
        'recurring_item_id' => $ri_id,
        'from'              => $pf->format('Y-m-d'),
        'to'                => $pt->format('Y-m-d'),
        'description_tpl'   => $tpl,
        'description'       => $desc,
        'quantity'          => $qty,
        'unit_price'        => $price,
        'tax_scheme'        => $scheme,
        'vat_rate'          => $vat,
      ];
    }
  }

  // alles rauswerfen, was im Ledger an NICHT-STORNIERTE Rechnung gebunden ist
  $runs = _ri_filter_out_ledger_linked($pdo, $account_id, $runs);

  usort($runs, function($a,$b){
    if ($a['from']===$b['from']) return $a['recurring_item_id'] <=> $b['recurring_item_id'];
    return strcmp($a['from'],$b['from']);
  });
  return $runs;
}

function ri_preview_due_flags(PDO $pdo, int $account_id, int $company_id, string $issue_date): array {
  $runs = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_date);
  $hasAny = !empty($runs);
  $hasNonStd = false;
  foreach ($runs as $r) {
    if (($r['tax_scheme'] ?? 'standard') !== 'standard') { $hasNonStd = true; break; }
    if ((float)($r['vat_rate'] ?? 0.0) <= 0.0)            { $hasNonStd = true; break; }
  }
  return [$hasAny, $hasNonStd];
}

function ri_mark_runs_linked(PDO $pdo, int $account_id, int $company_id, int $invoice_id, array $runs, array $selected_keys): void {
  if (!$runs || !$selected_keys) return;
  $sel = array_flip($selected_keys);
  $ins = $pdo->prepare("
    INSERT INTO recurring_item_ledger
      (account_id, company_id, recurring_item_id, period_from, period_to, invoice_id)
    VALUES (?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE invoice_id=VALUES(invoice_id)
  ");
  foreach ($runs as $r) {
    $key = (string)$r['key'];
    if (!isset($sel[$key])) continue;
    $ins->execute([
      $account_id, $company_id, (int)$r['recurring_item_id'], $r['from'], $r['to'], $invoice_id
    ]);
  }
}

function ri_unlink_runs_for_invoice(PDO $pdo, int $account_id, int $invoice_id): void {
  $pdo->prepare("DELETE FROM recurring_item_ledger WHERE account_id=? AND invoice_id=?")
      ->execute([$account_id, $invoice_id]);
}

/**
 * Hängt fällige Runs an Rechnung (als qty-Zeilen) und markiert Ledger.
 * selected_keys:
 *   - null  => alle fälligen anhängen
 *   - []    => keine
 *   - ['ri:...'] => nur ausgewählte
 */
function ri_attach_due_items(
  PDO $pdo,
  int $account_id,
  int $company_id,
  int $invoice_id,
  string $issue_date,
  int $start_pos,
  ?array $selected_keys = null
): array {
  $all = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_date);

  if ($selected_keys === null) {
    $runs = $all;
    $picked = array_column($runs, 'key');
  } elseif (empty($selected_keys)) {
    return [0.0, 0.0, $start_pos];
  } else {
    $sel = array_flip($selected_keys);
    $runs = array_values(array_filter($all, fn($r)=>isset($sel[$r['key']])));
    $picked = $selected_keys;
  }

  if (!$runs) return [0.0, 0.0, $start_pos];

  $insItem = $pdo->prepare("
    INSERT INTO invoice_items
      (account_id, invoice_id, project_id, task_id, description,
       quantity, unit_price, vat_rate, total_net, total_gross, position, tax_scheme, entry_mode)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");

  $pos = $start_pos; $sum_net=0.0; $sum_gross=0.0;

  foreach ($runs as $r) {
    $desc   = (string)$r['description'];
    $qty    = round((float)$r['quantity'], 3);
    $price  = (float)$r['unit_price'];
    $scheme = (string)$r['tax_scheme'];
    $vat    = (float)($scheme==='standard' ? $r['vat_rate'] : 0.0);

    $net   = round($qty * $price, 2);
    $gross = round($net * (1 + $vat/100), 2);

    $insItem->execute([
      $account_id, $invoice_id, null, null, $desc,
      $qty, $price, $vat, $net, $gross, $pos++, $scheme, 'qty'
    ]);

    $sum_net  += $net;
    $sum_gross+= $gross;
  }

  ri_mark_runs_linked($pdo, $account_id, $company_id, $invoice_id, $runs, $picked);

  return [$sum_net, $sum_gross, $pos];
}