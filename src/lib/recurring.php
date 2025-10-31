<?php
/**
 * src/lib/recurring.php
 *
 * Wiederkehrende Positionen auf Basis der Tabelle `recurring_items`.
 * Verhindert Doppel-Abrechnungen ausschließlich über `recurring_item_ledger`.
 * Storno-Unterstützung: Beim Storno werden Ledger-Links gelöst, so dass
 * die Perioden wieder fällig werden.
 *
 * Öffentliche Helfer:
 *  - ri_compute_due_runs(PDO $pdo, int $account_id, int $company_id, string $issue_date): array
 *  - ri_preview_due_flags(PDO $pdo, int $account_id, int $company_id, string $issue_date): array{bool,bool}
 *  - ri_attach_due_items(PDO $pdo, int $account_id, int $company_id, int $invoice_id, string $issue_date, int $start_pos, ?array $selected_keys = null): array{float,float,int}
 *  - ri_unlink_runs_for_invoice(PDO $pdo, int $account_id, int $invoice_id): void
 *  - ri_selected_has_nonstandard(...): bool   // NEU (siehe unten, 2 Varianten)
 *
 * Erwartetes Schema:
 *  - recurring_items(...)
 *  - recurring_item_ledger(id AI, account_id, company_id, recurring_item_id,
 *      period_from, period_to, run_key, invoice_id, created_at,
 *      UNIQUE (account_id, recurring_item_id, period_from, period_to))
 */

if (!function_exists('ri_compute_due_runs')) {

  // --- intern: Datums-Helfer ---
  function _ri_dt(string $ymd): DateTimeImmutable { return new DateTimeImmutable($ymd ?: '1970-01-01'); }
  function _ri_end_inclusive(DateTimeImmutable $nextStart): DateTimeImmutable { return $nextStart->modify('-1 day'); }
  function _ri_add_interval(DateTimeImmutable $start, string $unit, int $count): DateTimeImmutable {
    $count = max(1, (int)$count);
    switch (strtolower($unit)) {
      case 'day': return $start->modify("+{$count} day");
      case 'week': return $start->modify("+{$count} week");
      case 'quarter': return $start->modify('+' . (3*$count) . ' month');
      case 'year': return $start->modify("+{$count} year");
      case 'month': default: return $start->modify("+{$count} month");
    }
  }
  function _ri_month_name_de(int $m): string {
    static $n=[1=>'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    return $n[$m] ?? '';
  }

  // --- Beschreibung rendern ---
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

  // --- Perioden je Item bis issue_date ---

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

      // Laufzeitlimit: wenn Start nach Endedatum liegt -> Schluss
      if ($endLimit && $cur > $endLimit) break;

      // VORTRÄGLICH: fällig, sobald der Periodenstart erreicht oder unterschritten ist
      if ($cur <= $issue) {
        $periods[] = [$cur, $to];
        $cur = $next;
        continue;
      }
      // Nächster Start liegt in der Zukunft -> abbrechen
      break;
    }
    return $periods;
  }

  // --- Items laden ---
  function _ri_load_items(PDO $pdo, int $account_id, int $company_id): array {
    $st = $pdo->prepare("
      SELECT id, account_id, company_id, description_tpl, quantity, unit_price,
             tax_scheme, vat_rate, interval_unit, interval_count, start_date, end_date, active
      FROM recurring_items
      WHERE account_id=? AND company_id=? AND COALESCE(active,1)=1
      ORDER BY start_date, id
    ");
    $st->execute([$account_id,$company_id]);
    return $st->fetchAll() ?: [];
  }

  // --- Run-Key ---
  function _ri_run_key(int $ri_id, DateTimeImmutable $from, DateTimeImmutable $to): string {
    return 'ri:'.$ri_id.':'.$from->format('Y-m-d').':'.$to->format('Y-m-d');
  }

  // --- runs filtern, die fest an NICHT-stornierte Rechnungen gelinkt sind ---
  function _ri_filter_out_ledger_linked(PDO $pdo, int $account_id, array $runs): array {
    if (!$runs) return [];
    $triples=[]; foreach($runs as $r){ $triples[]=[(int)$r['recurring_item_id'],$r['from'],$r['to']]; }
    $keep=[];
    foreach (array_chunk($triples,200) as $chunk) {
      $place=[]; $params=[$account_id];
      foreach ($chunk as $c){ $place[]='(?,?,?)'; array_push($params,$c[0],$c[1],$c[2]); }
      $sql="
        SELECT l.recurring_item_id, l.period_from, l.period_to, l.invoice_id, i.status
        FROM recurring_item_ledger l
        LEFT JOIN invoices i ON i.account_id=l.account_id AND i.id=l.invoice_id
        WHERE l.account_id=? AND (l.recurring_item_id,l.period_from,l.period_to) IN (".implode(',',$place).")
      ";
      $st=$pdo->prepare($sql); $st->execute($params);
      $blocked=[];
      foreach($st->fetchAll() as $row){
        $k=$row['recurring_item_id'].'|'.$row['period_from'].'|'.$row['period_to'];
        $blocked[$k]=(!empty($row['invoice_id']) && ($row['status']??'')!=='storniert');
      }
      foreach($chunk as $c){
        $k=$c[0].'|'.$c[1].'|'.$c[2];
        if (empty($blocked[$k])) $keep[]=['recurring_item_id'=>$c[0],'from'=>$c[1],'to'=>$c[2]];
      }
    }
    $index=[]; foreach($runs as $r){ $index[$r['recurring_item_id'].'|'.$r['from'].'|'.$r['to']]=$r; }
    $out=[]; foreach($keep as $k){ $kk=$k['recurring_item_id'].'|'.$k['from'].'|'.$k['to']; if(isset($index[$kk])) $out[]=$index[$kk]; }
    return $out;
  }

  // --- fällige Runs berechnen ---
  function ri_compute_due_runs(PDO $pdo, int $account_id, int $company_id, string $issue_date): array {
    $issue=_ri_dt($issue_date);
    $items=_ri_load_items($pdo,$account_id,$company_id);
    $runs=[];
    foreach($items as $it){
      $ri_id=(int)$it['id'];
      $periods=_ri_periods_for_item($it,$issue);
      if(!$periods) continue;
      $qty=(float)$it['quantity'];
      $price=(float)$it['unit_price'];
      $scheme=(string)($it['tax_scheme']??'standard');
      $vat=(float)($scheme==='standard' ? ($it['vat_rate']??0.0) : 0.0);
      $tpl=(string)$it['description_tpl'];
      foreach($periods as [$pf,$pt]){
        $runs[]=[
          'key'               => _ri_run_key($ri_id,$pf,$pt),
          'recurring_item_id' => $ri_id,
          'from'              => $pf->format('Y-m-d'),
          'to'                => $pt->format('Y-m-d'),
          'description_tpl'   => $tpl,
          'description'       => ri_render_description($tpl,$pf,$pt),
          'quantity'          => $qty,
          'unit_price'        => $price,
          'tax_scheme'        => $scheme,
          'vat_rate'          => $vat,
        ];
      }
    }
    $runs=_ri_filter_out_ledger_linked($pdo,$account_id,$runs);
    usort($runs,function($a,$b){ return ($a['from']===$b['from']) ? ($a['recurring_item_id']<=>$b['recurring_item_id']) : strcmp($a['from'],$b['from']); });
    return $runs;
  }

  // --- Flags für UI ---
  function ri_preview_due_flags(PDO $pdo, int $account_id, int $company_id, string $issue_date): array {
    $runs = ri_compute_due_runs($pdo,$account_id,$company_id,$issue_date);
    $hasAny = !empty($runs);
    $hasNonStd=false;
    foreach($runs as $r){
      if (($r['tax_scheme']??'standard')!=='standard' || (float)($r['vat_rate']??0.0)<=0.0) { $hasNonStd=true; break; }
    }
    return [$hasAny,$hasNonStd];
  }

  // --- Ledger-Link setzen ---
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
        $account_id,
        $company_id,
        (int)$r['recurring_item_id'],
        $r['from'],
        $r['to'],
        $invoice_id
      ]);
    }
  }

  // --- Ledger-Links für Rechnung entfernen (Storno) ---
  function ri_unlink_runs_for_invoice(PDO $pdo, int $account_id, int $invoice_id): void {
    $pdo->prepare("DELETE FROM recurring_item_ledger WHERE account_id=? AND invoice_id=?")
        ->execute([$account_id,$invoice_id]);
  }

  // --- Runs anhängen + ledger markieren ---
  function ri_attach_due_items(PDO $pdo, int $account_id, int $company_id, int $invoice_id, string $issue_date, int $start_pos, ?array $selected_keys=null): array {
    $all = ri_compute_due_runs($pdo,$account_id,$company_id,$issue_date);

    if ($selected_keys===null) {
      $runs=$all; $picked=array_column($runs,'key');
    } elseif (empty($selected_keys)) {
      return [0.0,0.0,$start_pos];
    } else {
      $sel=array_flip($selected_keys);
      $runs=array_values(array_filter($all,fn($r)=>isset($sel[$r['key']])));
      $picked=$selected_keys;
    }
    if(!$runs) return [0.0,0.0,$start_pos];

    $insItem=$pdo->prepare("
      INSERT INTO invoice_items
        (account_id, invoice_id, project_id, task_id, description,
         quantity, unit_price, vat_rate, total_net, total_gross, position, tax_scheme, entry_mode)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $pos=$start_pos; $sum_net=0.0; $sum_gross=0.0;
    foreach($runs as $r){
      $desc=(string)$r['description'];
      $qty=round((float)$r['quantity'],3);
      $price=(float)$r['unit_price'];
      $scheme=(string)$r['tax_scheme'];
      $vat=(float)($scheme==='standard' ? $r['vat_rate'] : 0.0);
      $net=round($qty*$price,2);
      $gross=round($net*(1+$vat/100),2);
      $insItem->execute([$account_id,$invoice_id,null,null,$desc,$qty,$price,$vat,$net,$gross,$pos++,$scheme,'qty']);
      $sum_net+=$net; $sum_gross+=$gross;
    }

    ri_mark_runs_linked($pdo,$account_id,$company_id,$invoice_id,$runs,$picked);
    return [$sum_net,$sum_gross,$pos];
  }

  // -------------------------------
  // NEU: Non-Standard-Prüf-Helfer
  // -------------------------------

  /**
   * Variante A: Direkt übergebene Runs prüfen.
   * @param array $runs Array der von ri_compute_due_runs() gelieferten Runs (oder Teilmenge)
   */
  function ri_runs_has_nonstandard(array $runs): bool {
    foreach ($runs as $r) {
      $scheme = $r['tax_scheme'] ?? 'standard';
      $vat    = (float)($r['vat_rate'] ?? 0.0);
      if ($scheme !== 'standard' || $vat <= 0.0) return true;
    }
    return false;
  }

  /**
   * Variante B: Ausgewählte Keys gegen die berechneten Runs prüfen.
   * Signatur für bequemen Aufruf aus new.php:
   *   ri_selected_has_nonstandard($pdo, $account_id, $company_id, $issue_date, $selected_keys)
   *
   * Alternativ akzeptiert die Funktion auch direkt ein Runs-Array:
   *   ri_selected_has_nonstandard($runs)
   */
  function ri_selected_has_nonstandard(...$args): bool {
    // Aufruf mit direkt übergebenen Runs
    if (count($args) === 1 && is_array($args[0])) {
      return ri_runs_has_nonstandard($args[0]);
    }
    // Aufruf mit (pdo, acc, comp, issue_date, selected_keys)
    if (count($args) >= 5) {
      /** @var PDO $pdo */
      [$pdo, $account_id, $company_id, $issue_date, $selected_keys] = $args;
      $all = ri_compute_due_runs($pdo, (int)$account_id, (int)$company_id, (string)$issue_date);
      $sel = array_flip((array)$selected_keys);
      $picked = array_values(array_filter($all, fn($r)=>isset($sel[$r['key']])));
      return ri_runs_has_nonstandard($picked);
    }
    // Fallback: sicherheitshalber "false"
    return false;
  }

}

/**
 * Liefert alle aktuell mit einer Rechnung verknüpften Recurring-Runs inkl. gerenderter Beschreibung.
 * Rückgabe: [
 *   ['recurring_item_id'=>int, 'from'=>'YYYY-MM-DD', 'to'=>'YYYY-MM-DD', 'key'=>'ri:..', 'description'=>string],
 *   ...
 * ]
 */
function ri_runs_for_invoice(PDO $pdo, int $account_id, int $invoice_id): array {
  $st = $pdo->prepare("
    SELECT l.recurring_item_id, l.period_from, l.period_to, r.description_tpl
    FROM recurring_item_ledger l
    JOIN recurring_items r
      ON r.account_id = l.account_id AND r.id = l.recurring_item_id
    WHERE l.account_id = ? AND l.invoice_id = ?
  ");
  $st->execute([$account_id, $invoice_id]);
  $rows = $st->fetchAll() ?: [];

  $out = [];
  foreach ($rows as $row) {
    $from = _ri_dt((string)$row['period_from']);
    $to   = _ri_dt((string)$row['period_to']);
    $out[] = [
      'recurring_item_id' => (int)$row['recurring_item_id'],
      'from'              => $from->format('Y-m-d'),
      'to'                => $to->format('Y-m-d'),
      'key'               => _ri_run_key((int)$row['recurring_item_id'], $from, $to),
      'description'       => ri_render_description((string)$row['description_tpl'], $from, $to),
    ];
  }
  return $out;
}