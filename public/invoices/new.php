<?php
// public/invoices/new.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/utils.php'; // dec(), parse_hours_to_decimal()
require_once __DIR__ . '/../../src/lib/recurring.php';
require_once __DIR__ . '/../../src/lib/return_to.php';
require_once __DIR__ . '/../../src/lib/invoices.php';
require_once __DIR__ . '/../../src/lib/invoice_number.php';
require_once __DIR__ . '/../../src/lib/flash.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$settings = get_account_settings($pdo, $account_id);

function contact_greeting_line(array $c): string {
  $sal = strtolower(trim((string)($c['salutation'] ?? '')));
  $fn  = trim((string)($c['first_name'] ?? ''));
  $ln  = trim((string)($c['last_name'] ?? ''));
  $gl  = trim((string)($c['greeting_line'] ?? ''));

  $withComma = function(string $s): string {
    $s = rtrim($s);
    $s = preg_replace('/\s*,+\s*$/', '', $s);
    return $s === '' ? '' : $s . ',';
  };

  if ($gl !== '') return $withComma($gl);
  if ($sal === 'frau' && $ln !== '') return $withComma("Sehr geehrte Frau $ln");
  if ($sal === 'herr' && $ln !== '') return $withComma("Sehr geehrter Herr $ln");
  $full = trim($fn.' '.$ln);
  return $withComma($full !== '' ? "Guten Tag $full" : "Guten Tag");
}

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($v){ return number_format((float)$v, 2, ',', '.'); }

/** Minuten über Time-IDs (Account-sicher) */
function sum_minutes_for_times(PDO $pdo, int $account_id, array $ids): int {
  $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
  if (!$ids) return 0;
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT COALESCE(SUM(minutes),0) FROM times WHERE account_id=? AND id IN ($in)");
  $st->execute(array_merge([$account_id], $ids));
  return (int)$st->fetchColumn();
}

/** Rundet Minuten auf den nächsten N-Minuten-Block auf. */
function round_minutes_up(int $minutes, int $unit): int {
  if ($unit <= 0) return $minutes;
  if ($minutes <= 0) return 0;
  return (int)(ceil($minutes / $unit) * $unit);
}


// Defaults
$DEFAULT_TAX      = (float)$settings['default_vat_rate'];
$DEFAULT_SCHEME   = $settings['default_tax_scheme']; // 'standard'|'tax_exempt'|'reverse_charge'
$DEFAULT_DUE_DAYS = (int)($settings['default_due_days'] ?? 14);
$ROUND_UNIT_MINS  = max(0, (int)($settings['invoice_round_minutes'] ?? 0));

$issue_default = date('Y-m-d');
$due_default   = date('Y-m-d', strtotime('+' . max(0, $DEFAULT_DUE_DAYS) . ' days'));

// --------- Kontext / Daten laden ----------
$company_id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$return_to = pick_return_to('/companies/show.php?id='.$company_id);

// Offene Zeiten der Firma, inkl. Task-Festpreis-Daten + billed_in_invoice_id + Status der Rechnung
$q = $pdo->prepare("
  SELECT
    t.id          AS time_id,
    t.minutes,
    t.started_at, t.ended_at,
    ta.id          AS task_id,
    ta.description AS task_desc,
    ta.billing_mode,
    ta.billed_in_invoice_id           AS billed_in_invoice_id,
    inv_lock.status                   AS billed_invoice_status,
    COALESCE(ta.fixed_price_cents,0)  AS fixed_price_cents,
    COALESCE(p.hourly_rate, c.hourly_rate) AS effective_rate
  FROM times t
  JOIN tasks ta    ON ta.id = t.task_id AND ta.account_id = t.account_id
  JOIN projects p  ON p.id = ta.project_id AND p.account_id = ta.account_id
  JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
  /* ⬇︎ Fix-Tasks, die bereits an eine Rechnung gekoppelt sind, sperren */
  LEFT JOIN invoices inv_lock
         ON inv_lock.id = ta.billed_in_invoice_id
        AND inv_lock.account_id = ta.account_id
  WHERE t.account_id = :acc
    AND c.id         = :cid
    AND t.billable   = 1
    AND ta.billable  = 1
    AND t.status     = 'offen'
    AND t.minutes IS NOT NULL
    /* ⬇︎ Wenn Task an eine gestellte/bezahlt Rechnung hängt, keine neuen Zeiten hier anzeigen */
     AND NOT (
       ta.billing_mode = 'fixed'
       AND ta.billed_in_invoice_id IS NOT NULL
       AND inv_lock.status IN ('gestellt','bezahlt')
  )
  ORDER BY ta.id, t.started_at
");
$q->execute([':acc'=>$account_id, ':cid'=>$company_id]);
$rows = $q->fetchAll();

$groups = [];
if ($rows) {
  $byTask = [];
  foreach ($rows as $r) {
    $tid = (int)$r['task_id'];
    if (!isset($byTask[$tid])) {
      $byTask[$tid] = [
        'task_id'              => $tid,
        'task_desc'            => (string)$r['task_desc'],
        'hourly_rate'          => (float)$r['effective_rate'],
        'tax_rate'             => ($DEFAULT_SCHEME === 'standard') ? (float)$settings['default_vat_rate'] : 0.0,
        'billing_mode'         => (string)$r['billing_mode'],
        'fixed_price_cents'    => $r['fixed_price_cents'] !== null ? (int)$r['fixed_price_cents'] : null,
        'billed_in_invoice_id' => $r['billed_in_invoice_id'] !== null ? (int)$r['billed_in_invoice_id'] : null,
        'billed_invoice_status'=> $r['billed_invoice_status'] ?? null,
        'times'                => [],
        'minutes_sum'          => 0,
      ];
    }
    $byTask[$tid]['times'][] = [
      'id'         => (int)$r['time_id'],
      'minutes'    => (int)$r['minutes'],
      'started_at' => $r['started_at'],
      'ended_at'   => $r['ended_at'],
    ];
    $byTask[$tid]['minutes_sum'] += (int)$r['minutes'];
  }

  foreach ($byTask as &$t) {
    $t['minutes_sum'] = round_minutes_up((int)$t['minutes_sum'], $ROUND_UNIT_MINS);
  }
  unset($t);

  $groups = [[ 'rows' => array_values($byTask) ]];
}

// Firmenliste
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cs->execute([$account_id]);
$companies = $cs->fetchAll();

// geprüfte Firma laden
$company = null;
$err = null; $ok = null;
$show_tax_reason = false;

if ($company_id) {
  $cchk = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
  $cchk->execute([$company_id, $account_id]);
  $company = $cchk->fetch();
  if (!$company) {
    $err = 'Ungültige Firma.'; $company_id = 0;
  }
}

// --- Kontakte (Rechnungsempfänger) der Firma
$contacts = [];
$recipient_contact_id = 0;
if ($company) {
  $cst = $pdo->prepare("
    SELECT id, salutation, first_name, last_name, greeting_line, is_invoice_addressee
    FROM contacts
    WHERE account_id = ? AND company_id = ?
    ORDER BY is_invoice_addressee DESC, last_name, first_name
  ");
  $cst->execute([$account_id, (int)$company['id']]);
  $contacts = $cst->fetchAll();

  foreach ($contacts as $ct) {
    if ((int)($ct['is_invoice_addressee'] ?? 0) === 1) { $recipient_contact_id = (int)$ct['id']; break; }
  }
}

$recipient_contact_id = (int)($_POST['recipient_contact_id'] ?? $recipient_contact_id);

// Default-Stundensatz für „+ Position“
$default_manual_rate = 0.00;
if (!empty($company)) {
  $default_manual_rate = (float)($company['hourly_rate'] ?? 0.0);
}
if ($default_manual_rate <= 0 && !empty($groups) && !empty($groups[0]['rows'])) {
  foreach ($groups as $g) {
    foreach ($g['rows'] as $r) {
      $er = (float)($r['hourly_rate'] ?? 0);
      if ($er > 0) { $default_manual_rate = $er; break 2; }
    }
  }
}

// --- Preview wiederkehrende Positionen
$issue_for_preview = $_POST['issue_date'] ?? $issue_default;
$recurring_preview = [];
if ($company_id) {
  $recurring_preview = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_for_preview);
}

// --- Effektive Default-Texte
$eff_intro = '';
$eff_outro = '';
if ($company) {
  $coIntro = isset($company['invoice_intro_text']) ? (string)$company['invoice_intro_text'] : '';
  $coOutro = isset($company['invoice_outro_text']) ? (string)$company['invoice_outro_text'] : '';
  $eff_intro = (trim($coIntro) !== '') ? $coIntro : (string)($settings['invoice_intro_text'] ?? '');
  $eff_outro = (trim($coOutro) !== '') ? $coOutro : (string)($settings['invoice_outro_text'] ?? '');
} else {
  $eff_intro = (string)($settings['invoice_intro_text'] ?? '');
  $eff_outro = (string)($settings['invoice_outro_text'] ?? '');
}

// Ausgewählten Kontakt suchen (für Vorbelegung)
$sel_contact = null;
if ($recipient_contact_id && $contacts) {
  foreach ($contacts as $ct) {
    if ((int)$ct['id'] === $recipient_contact_id) { $sel_contact = $ct; break; }
  }
}

// Prefill
$prefill_intro = array_key_exists('invoice_intro_text', $_POST) ? (string)$_POST['invoice_intro_text'] : $eff_intro;
$prefill_outro = array_key_exists('invoice_outro_text', $_POST) ? (string)$_POST['invoice_outro_text'] : $eff_outro;

if (!array_key_exists('invoice_intro_text', $_POST) && $sel_contact) {
  $greet = contact_greeting_line($sel_contact);
  if ($greet !== '') { $prefill_intro = $greet . "\n\n" . ltrim((string)$prefill_intro); }
}

$prefill_intro = array_key_exists('invoice_intro_text', $_POST) ? (string)$_POST['invoice_intro_text'] : $eff_intro;
$prefill_outro = array_key_exists('invoice_outro_text', $_POST) ? (string)$_POST['invoice_outro_text'] : $eff_outro;

// ---------- Speichern ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && ($_POST['action']==='save' || $_POST["action"]==='save_and_issue')) {
  $company_id = (int)($_POST['company_id'] ?? 0);
  $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
  $due_date   = $_POST['due_date']   ?? date('Y-m-d', strtotime('+14 days'));
  $tax_reason = trim($_POST['tax_exemption_reason'] ?? '');

  if (!$company_id) {
    $err = 'Bitte eine Firma auswählen.';
  } else {
    $cchk = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND account_id = ?');
    $cchk->execute([$company_id, $account_id]);
    if (!$cchk->fetchColumn()) $err = 'Ungültige Firma.';
  }

  $itemsForm = $_POST['items'] ?? [];
  $ri_selected_keys = array_keys($_POST['ri_pick'] ?? []);

  // Muss es überhaupt Positionen geben?
  if (!$err) {
    $hasAnyItem = false;
    foreach ((array)$itemsForm as $row) {
      $desc = trim((string)($row['description'] ?? ''));
      $rate = (float)dec($row['hourly_rate'] ?? 0);
      $qty  = (float)dec($row['quantity'] ?? 0);
      $mode = strtolower(trim((string)($row['entry_mode'] ?? '')));
      $tids = array_values(array_filter(array_map('intval', (array)($row['time_ids'] ?? [])), fn($v)=>$v>0));
      if ($mode==='fixed') { // Fixpreis-Zeilen zählen, auch wenn 0 €, weil Zeiten ausgebucht werden müssen (aber später keine Position erzeugen)
        if ($desc !== '') { $hasAnyItem = true; break; }
      } else if ($tids || $desc !== '' || ($qty > 0 && $rate >= 0)) { $hasAnyItem = true; break; }
    }
    if (!$hasAnyItem && !$ri_selected_keys) {
      $err = 'Keine Position ausgewählt.';
    }
  }

  // Pflichtprüfung Steuerbegründung, falls Non-Standard in Items oder Recurring-Auswahl
  $hasNonStandard = false;
  if (!$err) {
    foreach ((array)$itemsForm as $row) {
      $scheme = $row['tax_scheme'] ?? $DEFAULT_SCHEME;
      $vat    = ($scheme === 'standard') ? dec($row['vat_rate'] ?? $DEFAULT_TAX) : 0.0;
      if ($scheme !== 'standard' || $vat <= 0.0) { $hasNonStandard = true; break; }
    }
    // Recurring-Vorschau für das tatsächlich gepostete Datum
    $due_all = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_date);
    if (ri_selected_has_nonstandard($due_all, $ri_selected_keys)) $hasNonStandard = true;

    if ($hasNonStandard && $tax_reason === '') {
      $err = 'Bitte Begründung für die Steuerbefreiung angeben.';
    }
  }

  $show_tax_reason = $hasNonStandard || ($tax_reason !== '');

  $intro_text = (string)($_POST['invoice_intro_text'] ?? '');
  $outro_text = (string)($_POST['invoice_outro_text'] ?? '');

  $recipient_contact_id = (int)($_POST['recipient_contact_id'] ?? 0);
  $prepend_greet = isset($_POST['prepend_contact_greeting']) && $_POST['prepend_contact_greeting'] === '1';

  if ($recipient_contact_id && $prepend_greet) {
    $gst = $pdo->prepare("
      SELECT salutation, first_name, last_name, greeting_line
      FROM contacts
      WHERE account_id=? AND company_id=? AND id=?
    ");
    $gst->execute([$account_id, $company_id, $recipient_contact_id]);
    $rc = $gst->fetch();
    if ($rc) {
      $greet = contact_greeting_line($rc);
      if ($greet !== '' && strpos((string)$intro_text, $greet) !== 0) {
        $intro_text = $greet . "\n\n" . ltrim((string)$intro_text);
      }
    }
  }

  if (!$err) {
    $pdo->beginTransaction();

    $visible_count = 0;        // zählt sichtbare invoice_items (is_hidden = 0)
    $created_invoice_header = true;

    try {
      // Rechnung anlegen
      $insInv = $pdo->prepare("
        INSERT INTO invoices (
          account_id, company_id, status, issue_date, due_date,
          total_net, total_gross, tax_exemption_reason,
          invoice_intro_text, invoice_outro_text
        ) VALUES (?,?,?,?,?,?,?,?,?,?)
      ");
      $insInv->execute([
        $account_id, $company_id, 'in_vorbereitung', $issue_date, $due_date,
        0.00, 0.00, ($hasNonStandard ? $tax_reason : ''),  $intro_text, $outro_text
      ]);
      $invoice_id = (int)$pdo->lastInsertId();

      // Prepared Statements
      $insItem = $pdo->prepare("
        INSERT INTO invoice_items
          (account_id, invoice_id, project_id, task_id, description,
           quantity, unit_price, vat_rate, total_net, total_gross, position, tax_scheme, entry_mode, is_hidden)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      $insLink = $pdo->prepare("INSERT IGNORE INTO invoice_item_times (account_id, invoice_item_id, time_id) VALUES (?,?,?)");
      $setTimeStatus = $pdo->prepare("UPDATE times SET status=? WHERE account_id=? AND id IN (%s)");

      $pos = 1; $sum_net = 0.00;
      $flash_backattach = []; // [task_id => count]

      // Hilfsfunktionen
      $getTask = $pdo->prepare("SELECT id, account_id, project_id, billing_mode, fixed_price_cents, billed_in_invoice_id FROM tasks WHERE account_id=? AND id=?");
      $getInv  = $pdo->prepare("SELECT id, status FROM invoices WHERE id=? AND account_id=?");
      $getFixedItemForTask = $pdo->prepare("
        SELECT id FROM invoice_items
        WHERE account_id=? AND invoice_id=? AND task_id=? AND entry_mode='fixed'
        ORDER BY id ASC LIMIT 1
      ");

      // (Zeiten/Mengen/FIXED) in Reihenfolge
      foreach ((array)$itemsForm as $row) {
        $desc    = trim((string)($row['description'] ?? ''));
        $mode    = strtolower(trim((string)($row['entry_mode'] ?? 'qty')));
        $rateIn  = (float)dec($row['hourly_rate'] ?? 0);
        $scheme  = $row['tax_scheme'] ?? $DEFAULT_SCHEME;
        $vat     = ($scheme === 'standard') ? (float)dec($row['vat_rate'] ?? $DEFAULT_TAX) : 0.0;
        $vat     = max(0.0, min(100.0, $vat));

        $task_id  = (int)($row['task_id'] ?? 0);
        $time_ids = array_values(array_filter(array_map('intval', (array)($row['time_ids'] ?? [])), fn($v)=>$v>0));

        $net = 0.0; $gross = 0.0;

        if ($mode === 'fixed' && $task_id) {
          // Task holen
          $getTask->execute([$account_id, $task_id]);
          $task = $getTask->fetch();
          if (!$task) { continue; }

          // Task an diese neue Rechnung koppeln, falls noch nicht gekoppelt
          $lockTask = $pdo->prepare("
            UPDATE tasks
              SET billed_in_invoice_id = ?
            WHERE account_id = ? AND id = ? AND billed_in_invoice_id IS NULL
          ");
          $lockTask->execute([$invoice_id, $account_id, $task_id]);

          $projId = (int)$task['project_id'] ?: null;
          $fixedCents = (int)($task['fixed_price_cents'] ?? 0);
          $fixedPrice = round($fixedCents / 100.0, 2);

          $boundInvId = $task['billed_in_invoice_id'] !== null ? (int)$task['billed_in_invoice_id'] : null;

          if ($boundInvId === null) {
            // ERSTABRECHNUNG: fixe Position erzeugen (Menge 1, Preis = fixed_price_cents), Zeiten verknüpfen, Soft-Lock
            if ($desc === '') $desc = (string)($row['description'] ?? 'Festpreis-Task #'.$task_id);
            $qty   = 1.0;
            $rate  = $fixedPrice; // erzwingen
            $net   = round($rate * $qty, 2);
            $gross = round($net * (1 + $vat/100), 2);

            $insItem->execute([
              $account_id, $invoice_id, $projId, $task_id, $desc,
              $qty, $rate, $vat, $net, $gross, $pos++, $scheme, 'fixed', 0
            ]);
            $visible_count++;
            $item_id = (int)$pdo->lastInsertId();

            // Zeiten verlinken + auf 'in_abrechnung'
            if ($time_ids) {
              foreach ($time_ids as $tid) { $insLink->execute([$account_id, $item_id, (int)$tid]); }
              $placeholders = implode(',', array_fill(0, count($time_ids), '?'));
              $sql = sprintf($setTimeStatus->queryString, $placeholders);
              $st  = $pdo->prepare($sql);
              $args = array_merge(['in_abrechnung', $account_id], $time_ids);
              $st->execute($args);
            }

            // Soft-Lock setzen
            $pdo->prepare("UPDATE tasks SET billed_in_invoice_id=? WHERE account_id=? AND id=?")
                ->execute([$invoice_id, $account_id, $task_id]);

            $sum_net  += $net;
            continue;

          } else {
            // BEREITS ABGERECHNET oder in anderem Entwurf → neue Zeiten an alte Fixposition anhängen
            $getInv->execute([$boundInvId, $account_id]);
            $inv = $getInv->fetch();
            if (!$inv) {
              // Fallback: Soft-Lock lösen
              $pdo->prepare("UPDATE tasks SET billed_in_invoice_id=NULL WHERE account_id=? AND id=?")
                  ->execute([$account_id, $task_id]);
            } else {
              // existierende Fix-Position suchen
              $getFixedItemForTask->execute([$account_id, $boundInvId, $task_id]);
              $oldItemId = (int)$getFixedItemForTask->fetchColumn();

              if ($oldItemId && $time_ids) {
                foreach ($time_ids as $tid) { $insLink->execute([$account_id, $oldItemId, (int)$tid]); }

                $statusTarget = in_array($inv['status'], ['gestellt','bezahlt'], true) ? 'abgerechnet' : 'in_abrechnung';
                $placeholders = implode(',', array_fill(0, count($time_ids), '?'));
                $sql = sprintf($setTimeStatus->queryString, $placeholders);
                $st  = $pdo->prepare($sql);
                $args = array_merge([$statusTarget, $account_id], $time_ids);
                $st->execute($args);

                $flash_backattach[$task_id] = ($flash_backattach[$task_id] ?? 0) + count($time_ids);
              }
            }
            // WICHTIG: keine neue Position an dieser neuen Rechnung erzeugen
            continue;
          }
        }

        if ($mode === 'auto') {
          // Zeiten, die zu bereits endgültig abgerechneten FIX-Tasks gehören, werden NICHT in diese neue Rechnung übernommen,
          // sondern direkt der alten Fixposition angehängt (Back-Attach).
          if ($time_ids) {
            $in = implode(',', array_fill(0, count($time_ids), '?'));
            $stChk = $pdo->prepare("
              SELECT ti.id   AS time_id,
                     ta.id   AS task_id,
                     ta.billing_mode,
                     ta.billed_in_invoice_id,
                     inv.status AS billed_invoice_status
              FROM times ti
              JOIN tasks ta ON ta.id = ti.task_id AND ta.account_id = ti.account_id
              LEFT JOIN invoices inv ON inv.id = ta.billed_in_invoice_id
              WHERE ti.account_id=? AND ti.id IN ($in)
            ");
            $stChk->execute(array_merge([$account_id], $time_ids));
            $special = $stChk->fetchAll();

            $retain_ids = [];  // bleiben in dieser Position
            foreach ($special as $sp) {
              $tid  = (int)$sp['time_id'];
              $bm   = (string)$sp['billing_mode'];
              $bind = $sp['billed_in_invoice_id'] !== null ? (int)$sp['billed_in_invoice_id'] : null;
              $ist  = $sp['billed_invoice_status'] ?? null;

              if ($bm === 'fixed' && $bind && in_array($ist, ['gestellt','bezahlt'], true)) {
                // back-attach an alte Fixposition
                $getFixedItemForTask->execute([$account_id, $bind, (int)$sp['task_id']]);
                $oldItemId = (int)$getFixedItemForTask->fetchColumn();
                if ($oldItemId) {
                  $insLink->execute([$account_id, $oldItemId, $tid]);

                  $statusTarget = 'abgerechnet';
                  $pdo->prepare("UPDATE times SET status='abgerechnet' WHERE account_id=? AND id=?")->execute([$account_id, $tid]);

                  $flash_backattach[(int)$sp['task_id']] = ($flash_backattach[(int)$sp['task_id']] ?? 0) + 1;
                }
              } else {
                $retain_ids[] = $tid;
              }
            }
            $time_ids = $retain_ids;
          }

          if (!$time_ids) { continue; }

          $minutes = sum_minutes_for_times($pdo, $account_id, $time_ids);
          $minutes = round_minutes_up($minutes, $ROUND_UNIT_MINS);
          if ($minutes <= 0) { continue; }

          $hours_precise = $minutes / 60.0;
          $qty           = round($hours_precise, 3);
          $rate          = (float)$rateIn;
          $net           = round($hours_precise * $rate, 2);
          $gross         = round($net * (1 + $vat/100), 2);

          $insItem->execute([
            $account_id, $invoice_id, null, ($task_id ?: null), $desc,
            $qty, $rate, $vat, $net, $gross, $pos++, $scheme, 'auto', 0
          ]);
          $visible_count++;
          $item_id = (int)$pdo->lastInsertId();

          foreach ($time_ids as $tid) { $insLink->execute([$account_id, $item_id, (int)$tid]); }
          // 'in_abrechnung'
          $placeholders = implode(',', array_fill(0, count($time_ids), '?'));
          $sql = sprintf($setTimeStatus->queryString, $placeholders);
          $st  = $pdo->prepare($sql);
          $args = array_merge(['in_abrechnung', $account_id], $time_ids);
          $st->execute($args);

          // FIX: Summen für auto-Positionen addieren und dann weiter
          $sum_net  += $net;
          $sum_gross+= $gross;
          continue;
        }

        // --- MANUELL (time|qty) ---
        if ($mode === 'time') {
          $qty_hours = ($row['quantity'] ?? '') !== ''
            ? (float)dec($row['quantity'])
            : (float)parse_hours_to_decimal($row['hours'] ?? '0');
        } else {
          $mode = 'qty';
          $qty_hours = (float)dec($row['quantity'] ?? 0);
        }

        if ($desc === '' && $qty_hours <= 0 && $rateIn <= 0) { continue; }
        $qty   = round($qty_hours, 3);
        $rate  = (float)$rateIn;
        $net   = round($qty * $rate, 2);
        $gross = round($net * (1 + $vat/100), 2);

        $insItem->execute([
          $account_id, $invoice_id, null, null, $desc,
          $qty, $rate, $vat, $net, $gross, $pos++, $scheme, $mode, 0
        ]);
        $visible_count++;
        $sum_net  += $net;
        $sum_gross+= $gross;
      }

      // Wiederkehrende Positionen (nur die angehakten Keys)
      if ($ri_selected_keys) {
        $pos_before = $pos;
        list($ri_net, $ri_gross, $pos) = ri_attach_due_items(
          $pdo, $account_id, $company_id, $invoice_id, $issue_date, $pos, $ri_selected_keys
        );

        $visible_count += max(0, $pos - $pos_before);
        $sum_net   += $ri_net;
        $sum_gross += $ri_gross;
      }

      // Summen aktualisieren
      // Netto/Brutto/MwSt aus den gespeicherten Positionen je Steuersatz berechnen
      $totals = calculate_invoice_totals($pdo, $account_id, $invoice_id);

      $updInv = $pdo->prepare("UPDATE invoices SET total_net=?, total_gross=? WHERE id=? AND account_id=?");
      $updInv->execute([$totals['total_net'], $totals['total_gross'], $invoice_id, $account_id]);
      // Falls keine sichtbaren Positionen: Header löschen, nur Back-Attachs behalten
      if ($visible_count === 0) {
        $pdo->prepare("DELETE FROM invoices WHERE id=? AND account_id=?")->execute([$invoice_id, $account_id]);
        $pdo->commit();

        if (function_exists('flash')) {
          flash('Es wurden nur Zeiten bestehenden Fixpreis-Rechnungen zugeordnet. Es wurde keine neue Rechnung erstellt.', 'info');
        }
        redirect_to_return_to(url('/companies/show.php').'?id='.$company_id);
        exit;
      }

      // --- Abschluss / Nummernvergabe / Statuswechsel
      if (($_POST['action'] ?? '') === 'save_and_issue') {
        // 1) Rechnungsnummer vergeben
        $number = assign_invoice_number_if_needed($pdo, $account_id, (int)$invoice_id, $issue_date);

        // 2) Status auf "gestellt"
        $pdo->prepare("UPDATE invoices SET status='gestellt', invoice_number=? WHERE id=? AND account_id=?")
            ->execute([$number, (int)$invoice_id, $account_id]);

        // 3) Alle dieser Rechnung zugeordneten Zeiten -> 'abgerechnet'
        set_times_status_for_invoice($pdo, $account_id, (int)$invoice_id, 'abgerechnet');

        $pdo->commit();

        if (function_exists('flash') && !empty($flash_backattach)) {
          $cnt = array_sum($flash_backattach);
          $msg = $cnt." Zeit-Einträge an bereits abgerechnete Fixpreis-Positionen früherer Rechnungen angehängt.";
          flash($msg, 'info');
        }

        redirect(url('/invoices/export_xml.php?id='.(int)$invoice_id));
        exit;

      } else {
        // nur gespeichert (Entwurf)
        $pdo->commit();

        if (function_exists('flash') && !empty($flash_backattach)) {
          $cnt = array_sum($flash_backattach);
          $msg = $cnt." Zeit-Einträge an Fixpreis-Entwürfe/alte Rechnungen angehängt.";
          flash($msg, 'info');
        }

        redirect_to_return_to(url('/companies/show.php').'?id='.$company_id);
      }

    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = 'Rechnung konnte nicht angelegt werden. ('.$e->getMessage().')';
    }
  } else {
    // Bei Fehler: Preview für das gepostete Datum anzeigen
    $recurring_preview = ri_compute_due_runs($pdo, $account_id, $company_id, $issue_date);
  }
}
// ---------- View ----------
require __DIR__ . '/../../src/layout/header.php';
$return_to = pick_return_to('/companies/show.php?id='.$company_id);

// Für das UI: welche Recurring-Keys waren angehakt?
$ri_selected_keys_post = array_keys($_POST['ri_pick'] ?? []);
$ri_selected_set = array_flip($ri_selected_keys_post);
$tax_reason_value = isset($_POST['tax_exemption_reason']) ? (string)$_POST['tax_exemption_reason'] : '';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Neue Rechnung</h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?php echo h(url($return_to)) ?>">Zurück</a>
  </div>
</div>

<?php if (!empty($err)): ?>
  <div class="alert alert-danger"><?php echo h($err) ?></div>
<?php endif; ?>

<?php if ($company_id): ?>
<form method="post" id="invForm" action="<?php echo hurl(url('/invoices/new.php')) ?>">
  <?php echo csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="company_id" value="<?php echo (int)$company_id ?>">
  <input type="hidden" name="return_to" value="<?php echo h($return_to) ?>">

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Rechnungsdatum</label>
          <input type="date" name="issue_date" class="form-control" value="<?php echo h($_POST['issue_date'] ?? $issue_default) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Fällig bis</label>
          <input type="date" name="due_date" class="form-control" value="<?php echo h($_POST['due_date'] ?? $due_default) ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- Steuerbefreiung-Begründung -->
  <div class="card mb-3" id="tax-exemption-reason-wrap" style="<?php echo ($tax_reason_value !== '') ? '' : 'display:none' ?>">
    <div class="card-body">
      <label class="form-label">Begründung für die Steuerbefreiung</label>
      <textarea
        class="form-control"
        id="tax-exemption-reason"
        name="tax_exemption_reason"
        rows="2"
        placeholder="z. B. § 19 UStG (Kleinunternehmer) / Reverse-Charge nach § 13b UStG / Art. 196 MwStSystRL"><?php echo h($tax_reason_value) ?></textarea>
      <div class="form-text">
        Wird benötigt, wenn mindestens eine Position steuerfrei oder Reverse-Charge ist, oder der MwSt-Satz 0,00 % beträgt.
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Rechnungsempfänger (Ansprechpartner)</label>
          <select name="recipient_contact_id" id="recipient_contact_id" class="form-select">
            <option value="0">— keiner —</option>
            <?php foreach ($contacts as $ct):
              $name = trim(($ct['first_name'] ?? '').' '.($ct['last_name'] ?? ''));
              if ($name === '') $name = 'Kontakt #'.(int)$ct['id'];
              $label = $name . (!empty($ct['is_invoice_addressee']) ? ' • Standard' : '');
            ?>
              <option value="<?= (int)$ct['id'] ?>" <?= $recipient_contact_id===(int)$ct['id']?'selected':'' ?>>
                <?= h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Voreinstellung ist der Kontakt mit „Rechnungen erhalten“.</div>
        </div>

        <div class="col-md-6 align-self-end">
          <?php
            $checked = isset($_POST['prepend_contact_greeting'])
              ? ($_POST['prepend_contact_greeting'] === '1')
              : true;
          ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox"
                  id="prepend_contact_greeting" name="prepend_contact_greeting" value="1"
                  <?= $checked ? 'checked' : '' ?>>
            <label class="form-check-label" for="prepend_contact_greeting">
              Anrede des Empfängers in die Einleitung einsetzen
            </label>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">Rechnungs-Einleitung</label>
        <textarea
          class="form-control"
          name="invoice_intro_text"
          rows="3"
          placeholder="<?= h($eff_intro) ?>"
        ><?= h($prefill_intro) ?></textarea>
      </div>
    </div>
  </div>

  <!-- Fällige wiederkehrende Positionen -->
  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title">Fällige wiederkehrende Positionen</h5>
      <?php if (!$recurring_preview): ?>
        <div class="text-muted">Für das Datum <?php echo h($issue_for_preview) ?> sind keine wiederkehrenden Positionen fällig.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th style="width:36px"></th>
                <th>Bezeichnung</th>
                <th class="text-end">Menge</th>
                <th class="text-end">Einzelpreis</th>
                <th class="text-end">Steuerart</th>
                <th class="text-end">MwSt %</th>
                <th class="text-end">Netto</th>
                <th class="text-end">Brutto</th>
                <th>Zeitraum</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recurring_preview as $r):
                $qty   = (float)$r['quantity'];
                $price = (float)$r['unit_price'];
                $scheme= (string)$r['tax_scheme'];
                $vat   = ($scheme === 'standard') ? (float)$r['vat_rate'] : 0.0;
                $net   = round($qty * $price, 2);
                $gross = round($net * (1 + $vat/100), 2);
                $checked = empty($_POST) ? true : isset($ri_selected_set[$r['key']]);
              ?>
                <tr class="ri-row" data-scheme="<?php echo h($scheme) ?>" data-vat="<?php echo h(number_format($vat,2,'.','')) ?>">
                  <td>
                    <input type="checkbox" class="form-check-input"
                           name="ri_pick[<?php echo h($r['key']) ?>]" value="1" <?php echo $checked ? 'checked' : '' ?>>
                  </td>
                  <td><?php echo h($r['description']) ?></td>
                  <td class="text-end"><?php echo h(number_format($qty,3,',','.')) ?></td>
                  <td class="text-end"><?php echo h(number_format($price,2,',','.')) ?></td>
                  <td class="text-end"><?php echo h($scheme) ?></td>
                  <td class="text-end"><?php echo h(number_format($vat,2,',','.')) ?></td>
                  <td class="text-end"><?php echo h(number_format($net,2,',','.')) ?></td>
                  <td class="text-end"><?php echo h(number_format($gross,2,',','.')) ?></td>
                  <td><?php echo h(_fmt_dmy($r['from'])) ?> – <?php echo h(_fmt_dmy($r['to'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="form-text mt-2">
          Das Vorschau-Datum ist das Rechnungsdatum. Wenn du das Rechnungsdatum änderst, wird die tatsächliche Auswahl beim Speichern
          automatisch für dieses Datum neu berechnet.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <?php
        $mode = 'new';
        $rowName   = 'items';
        $timesName = 'time_ids';
        require __DIR__ . '/_items_table.php';
      ?>
      <button
        type="button"
        id="addManualItem"
        class="btn btn-sm btn-outline-primary"
        data-default-vat="<?php echo h(number_format((float)$settings['default_vat_rate'],2,'.','')) ?>"
        data-default-rate="<?php echo h(number_format((float)$default_manual_rate,2,'.','')) ?>">+ Position</button>

    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <div>
        <label class="form-label">Rechnungs-Schlussformel</label>
        <textarea
          class="form-control"
          name="invoice_outro_text"
          rows="3"
          placeholder="<?= h($eff_outro) ?>"
        ><?= h($prefill_outro) ?></textarea>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="<?php echo h(url($return_to)) ?>">Abbrechen</a>
    <button class="btn btn-outline-primary" name="action" value="save">Speichern</button>
    <button class="btn btn-primary" name="action" value="save_and_issue">Speichern & Rechnung generieren</button>
  </div>

</form>

<script>
/* ===== Invoice Items UI (klassisch) ===== */
(function(){
  var root = document.getElementById('invoice-items'); if (!root) return;
  var roundUnit = parseInt(root.getAttribute('data-round-unit') || '0', 10) || 0;

  /* ---------- Helpers ---------- */
  function getDetailsRowByMain(mainTr){
    if (!mainTr) return null;
    var det = mainTr.nextElementSibling;
    if (det && det.classList.contains('inv-details') && det.getAttribute('data-row') === mainTr.getAttribute('data-row')) {
      return det;
    }
    var rowId = mainTr.getAttribute('data-row');
    det = root.querySelector('.inv-details[data-row="'+rowId+'"]');
    return det || null;
  }
  function getDetailsRowById(rowId){
    return root.querySelector('.inv-details[data-row="'+rowId+'"]') || null;
  }
  function getDetailsTbodyById(rowId){
    var det = getDetailsRowById(rowId);
    return det ? (det.querySelector('tbody') || det.querySelector('table tbody') || det) : null;
  }
  function getAfterPairNode(refMain){
    var next = refMain.nextElementSibling;
    if (next && next.classList.contains('inv-details') && next.getAttribute('data-row') === refMain.getAttribute('data-row')) {
      return next.nextElementSibling;
    }
    return next;
  }
  function toNumber(str){
    if (typeof str !== 'string') str = String(str ?? '');
    str = str.trim(); if (!str) return 0;
    if (str.indexOf(',') !== -1 && str.indexOf('.') !== -1) { str = str.replace(/\./g,'').replace(',', '.'); }
    else if (str.indexOf(',') !== -1) { str = str.replace(',', '.'); }
    var n = parseFloat(str); return isFinite(n) ? n : 0;
  }
  function fmt2(n){ return (n||0).toFixed(2).replace('.',','); }
  function hhmm(min){ min = Math.max(0, parseInt(min||0,10)); var h = Math.floor(min/60), m = min%60; return (h<10?'0':'')+h+':' + (m<10?'0':'')+m; }

  /* ---------- Summen ---------- */
  function recalcTotals(){
    var gnet=0, ggross=0;
    root.querySelectorAll('tr.inv-item-row').forEach(function(tr){
      gnet  += toNumber(tr.querySelector('.net')?.textContent || '0');
      ggross+= toNumber(tr.querySelector('.gross')?.textContent || '0');
    });
    var gN = document.getElementById('grand-net'); var gG = document.getElementById('grand-gross');
    if (gN) gN.textContent = fmt2(gnet); if (gG) gG.textContent = fmt2(ggross);
  }

  function recalcRow(tr){
    var mode = (tr.getAttribute('data-mode') || tr.querySelector('.entry-mode')?.value || 'qty').toLowerCase();
    var rate = toNumber(tr.querySelector('.rate')?.value||'0');
    var vat  = toNumber(tr.querySelector('.inv-vat-input')?.value||'0');

    if (mode === 'auto' || mode === 'fixed') {
      var minutes = 0;
      var rowId   = tr.getAttribute('data-row');
      var details = getDetailsRowById(rowId);
      if (details) {
        details.querySelectorAll('.time-checkbox:checked').forEach(function(cb){
          minutes += parseInt(cb.getAttribute('data-min')||'0',10);
        });
      }
      if (roundUnit > 0 && minutes > 0 && mode === 'auto') {
        minutes = Math.ceil(minutes / roundUnit) * roundUnit;
      }

      if (mode === 'auto') {
        var net   = (minutes/60.0) * rate;
        var gross = net * (1 + vat/100);
        var hh    = tr.querySelector('.sum-hhmm'); if (hh)  hh.textContent = hhmm(minutes);
        var nspan = tr.querySelector('.net');      if (nspan) nspan.textContent = fmt2(net);
        var gspan = tr.querySelector('.gross');    if (gspan) gspan.textContent = fmt2(gross);
      } else { // fixed: Menge 1; Preis ist Festpreis; Klammer-Info mit HH:MM
        var hhInfo = tr.querySelector('.fixed-hhmm'); if (hhInfo) hhInfo.textContent = '('+hhmm(minutes)+')';
        var net   = rate * 1.0;
        var gross = net * (1 + vat/100);
        var nspan = tr.querySelector('.net');   if (nspan) nspan.textContent = fmt2(net);
        var gspan = tr.querySelector('.gross'); if (gspan) gspan.textContent = fmt2(gross);
      }
      recalcTotals();
      return;
    }

    // manuell 'time'|'qty' (nur Anzeige; Zeiten werden hier NICHT verlinkt)
    var qtyDec = 0;
    if (mode === 'time') {
      var hinp = tr.querySelector('.hours-input');
      var v = String(hinp ? hinp.value : '0').trim();
      if (v.includes(':')) { var p=v.split(':'), h=parseInt(p[0]||'0',10)||0, m=parseInt(p[1]||'0',10)||0; qtyDec = h + m/60; }
      else { qtyDec = toNumber(v); }
      var qHidden = tr.querySelector('.quantity-dec');
      if (qHidden) qHidden.value = String((qtyDec||0).toFixed(3));
    } else {
      qtyDec = toNumber(tr.querySelector('.quantity')?.value||'0');
    }
    var net   = qtyDec * rate;
    var gross = net * (1 + vat/100);
    var nspan = tr.querySelector('.net'); if (nspan) nspan.textContent = fmt2(net);
    var gspan = tr.querySelector('.gross'); if (gspan) gspan.textContent = fmt2(gross);
    recalcTotals();
  }

  function updateVatFromScheme(sel){
    var tr  = sel.closest('tr.inv-item-row'); if (!tr) return;
    var vat = tr.querySelector('.inv-vat-input'); if (!vat) return;
    var map = {
      'standard':       sel.dataset.rateStandard || '19.00',
      'tax_exempt':     sel.dataset.rateTaxExempt || '0.00',
      'reverse_charge': sel.dataset.rateReverseCharge || '0.00'
    };
    if (sel.value in map) vat.value = map[sel.value];
    recalcRow(tr);
  }

  function toggleTaxReason(){
    var wrap = document.getElementById('tax-exemption-reason-wrap');
    if (!wrap) return;
    var any = false;
    root.querySelectorAll('.inv-tax-sel').forEach(function(sel){
      var tr = sel.closest('tr.inv-item-row');
      var vatEl = tr?.querySelector('.inv-vat-input');
      var vat = vatEl ? parseFloat(vatEl.value || '0') : 0;
      if (sel.value !== 'standard' || vat <= 0) any = true;
    });
    wrap.style.display = any ? '' : 'none';
    var ta = document.getElementById('tax-exemption-reason'); if (!any && ta) ta.value = '';
  }

  // Reindex: passt data-row & name="items[old]" → "items[new]" an
  function reindexRows(){
    var pairs = [];
    root.querySelectorAll('tr.inv-item-row').forEach(function(main){
      var det = main.nextElementSibling;
      if (!(det && det.classList.contains('inv-details'))) det = null;
      pairs.push({ main: main, det: det, oldIdx: String(main.getAttribute('data-row') || '') });
    });

    pairs.forEach(function(p, i){
      var newIdx = String(i + 1);
      var oldIdx = p.oldIdx || newIdx;

      p.main.setAttribute('data-row', newIdx);
      if (p.det) p.det.setAttribute('data-row', newIdx);

      [p.main, p.det].forEach(function(scope){
        if (!scope) return;
        scope.querySelectorAll('[name^="items["]').forEach(function(el){
          var nm = el.getAttribute('name'); if (!nm) return;
          var esc = oldIdx.replace(/[-/\\^$*+?.()|[\]{}]/g,'\\$&');
          var re  = new RegExp('^items\\[' + esc + '\\]');
          var nn  = nm.replace(re, 'items['+newIdx+']');
          if (nn !== nm) el.setAttribute('name', nn);
        });
      });
    });
  }

  function ensurePlaceholder(){
    var ph = document.getElementById('invoice-reorder-placeholder');
    if (!ph) {
      ph = document.createElement('tr');
      ph.id = 'invoice-reorder-placeholder';
      ph.className = 'reorder-placeholder';
      var td = document.createElement('td');
      td.colSpan = (root.querySelector('thead tr')?.children.length) || 9;
      ph.appendChild(td);
    }
    return ph;
  }
  function removePlaceholder(){
    var ph = document.getElementById('invoice-reorder-placeholder');
    if (ph && ph.parentNode) ph.parentNode.removeChild(ph);
  }
  function clearReorderIndicators(){
    root.querySelectorAll('tr.inv-item-row').forEach(function(r){
      r.classList.remove('reorder-indicator-before','reorder-indicator-after');
    });
  }
  function updateOrderHidden(){
    var box = document.getElementById('invoice-order-tracker'); if (!box) return;
    box.innerHTML = '';
    root.querySelectorAll('tr.inv-item-row').forEach(function(r){
      var itemIdInput = r.querySelector('input[name^="items["][name$="[id]"]');
      if (itemIdInput && itemIdInput.value) {
        var i = document.createElement('input'); i.type='hidden'; i.name='item_order[]'; i.value=String(itemIdInput.value); box.appendChild(i);
      }
    });
  }

  function removeSourceItemIfEmpty(sourceRow){
    if (!sourceRow) return;
    var rowId = sourceRow.getAttribute('data-row');
    var srcTbody = getDetailsTbodyById(rowId);
    if (!srcTbody) return;
    var anyLeft = srcTbody.querySelector('.time-checkbox:checked');
    if (anyLeft) return;

    var srcItemIdInput = sourceRow.querySelector('input[name^="items["][name$="[id]"]');

    var det = getDetailsRowById(rowId);
    if (det) det.remove();

    if (srcItemIdInput && srcItemIdInput.value) {
      var trash = document.getElementById('invoice-hidden-trash');
      if (trash) {
        var hidden = document.createElement('input');
        hidden.type='hidden';
        hidden.name='items_deleted[]';
        hidden.value=srcItemIdInput.value;
        trash.appendChild(hidden);
      }
    }
    sourceRow.remove();
    reindexRows();
    updateOrderHidden();
  }

  /* ---------- DnD ---------- */
  var dragData = null; // {kind:'time'|'task'|'reorder', fromRowId, timeId?}

  // Nur Details-TBodies sind Drop-Ziele für Zeiten
  function markDropTargets(on){
    root.querySelectorAll('.inv-details tbody').forEach(function(tb){
      tb.classList.toggle('dnd-drop-target', !!on);
    });
  }

  // Zeit-Zeilen draggable
  root.querySelectorAll('.inv-details tbody tr.time-row[draggable="true"]').forEach(function(tr){
    tr.addEventListener('dragstart', function(e){
      var detailsTr = tr.closest('tr.inv-details');
      var fromRowId = detailsTr ? detailsTr.getAttribute('data-row') : (tr.closest('tr.inv-item-row')?.getAttribute('data-row') || '');
      var timeId = tr.getAttribute('data-time-id') || '';
      dragData = { kind:'time', timeId:String(timeId), fromRowId:String(fromRowId) };
      tr.classList.add('dnd-dragging');
      markDropTargets(true);
      try { e.dataTransfer.setData('text/plain','time:'+timeId); } catch(_) {}
      if (e.dataTransfer) e.dataTransfer.effectAllowed='move';
    });
    tr.addEventListener('dragend', function(){
      tr.classList.remove('dnd-dragging');
      dragData = null; markDropTargets(false);
    });
  });

  // Ganze Task-Zeile als Draggable (inkl. fixed/auto)
  root.querySelectorAll('tr.inv-item-row').forEach(function(row){
    var handle = row.querySelector('.row-reorder-handle');
    if (!handle) return;
    handle.setAttribute('draggable','true');

    handle.addEventListener('dragstart', function(e){
      var fromRowId = row.getAttribute('data-row') || '';
      dragData = { kind:'reorder', fromRowId:String(fromRowId) };
      row.classList.add('dnd-dragging');
      if (e.stopPropagation) e.stopPropagation();
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', 'reorder:'+fromRowId); } catch(_) {}
      }
    });
    handle.addEventListener('dragend', function(e){
      row.classList.remove('dnd-dragging');
      dragData = null; clearReorderIndicators(); removePlaceholder();
      if (e.stopPropagation) e.stopPropagation();
    });
  });

  // Reorder-Drop auf Kopfzeilen
  root.querySelectorAll('tr.inv-item-row').forEach(function(targetRow){
    targetRow.addEventListener('dragover', function(e){
      if (!dragData || dragData.kind !== 'reorder') return;
      e.preventDefault();

      var rect = targetRow.getBoundingClientRect();
      var after = (e.clientY - rect.top) > (rect.height / 2);

      clearReorderIndicators();
      targetRow.classList.add(after ? 'reorder-indicator-after' : 'reorder-indicator-before');

      var ph = ensurePlaceholder();
      var tbody = targetRow.parentNode;
      var ref   = after ? getAfterPairNode(targetRow) : targetRow;
      if (ph !== ref) tbody.insertBefore(ph, ref || null);
    });

    targetRow.addEventListener('drop', function(e){
      if (!dragData || dragData.kind !== 'reorder') return;
      e.preventDefault();

      var rect = targetRow.getBoundingClientRect();
      var after = (e.clientY - rect.top) > (rect.height / 2);

      var fromId = dragData.fromRowId;
      var toId   = targetRow.getAttribute('data-row') || '';

      clearReorderIndicators();

      (function moveRowPair(fromRowId, targetRowId, placeAfter){
        if (!fromRowId || !targetRowId || fromRowId === targetRowId) return;
        var fromMain   = root.querySelector('tr.inv-item-row[data-row="'+fromRowId+'"]');
        var targetMain = root.querySelector('tr.inv-item-row[data-row="'+targetRowId+'"]');
        if (!fromMain || !targetMain) return;
        var fromDet = getDetailsRowById(fromRowId);
        var tbody = targetMain.parentNode;
        var refNode = placeAfter ? getAfterPairNode(targetMain) : targetMain;
        if (refNode) {
          tbody.insertBefore(fromMain, refNode);
          if (fromDet) tbody.insertBefore(fromDet, refNode);
        } else {
          tbody.appendChild(fromMain);
          if (fromDet) tbody.appendChild(fromDet);
        }
        reindexRows();
      })(fromId, toId, after);

      removePlaceholder();
      reindexRows();
      updateOrderHidden();
    });
  });

  function rejectDropIfIllegal(targetRow){
    if (!targetRow) return true;
    var mode = (targetRow.getAttribute('data-mode') || '').toLowerCase();
    // Zeiten dürfen NUR in auto/fixed; nicht in qty
    return (mode === 'qty');
  }

  // Drop in Details-Tabellenkörper
  root.querySelectorAll('.inv-details tbody').forEach(function(tb){
    tb.addEventListener('dragover', function(e){
      if (!dragData || dragData.kind!=='time') return;
      var trItem = tb.closest('tr.inv-details')?.previousElementSibling;
      if (rejectDropIfIllegal(trItem)) return;
      e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect='move';
    });
    tb.addEventListener('drop', function(e){
      if (!dragData || dragData.kind!=='time') return;
      e.preventDefault();

      var targetDetails = tb.closest('tr.inv-details');
      var targetRow = targetDetails ? targetDetails.previousElementSibling : null;
      if (!targetRow || rejectDropIfIllegal(targetRow)) return;

      var timeTr = getDetailsRowById(dragData.fromRowId)?.querySelector('tbody tr.time-row[data-time-id="'+dragData.timeId+'"]');
      if (!timeTr) return;

      // an das neue tbody anhängen
      tb.appendChild(timeTr);
      var cb = timeTr.querySelector('.time-checkbox');
      if (cb) {
        var targetRowId = targetRow.getAttribute('data-row') || '';
        cb.name = 'items[' + targetRowId + '][time_ids][]';
        cb.checked = true;
      }

      var fromRow  = root.querySelector('tr.inv-item-row[data-row="'+dragData.fromRowId+'"]');
      if (fromRow) recalcRow(fromRow);
      recalcRow(targetRow);
      removeSourceItemIfEmpty(fromRow);
      recalcTotals(); toggleTaxReason(); updateOrderHidden();
    });
  });

  /* ---------- Delegierte UI-Events ---------- */
  root.addEventListener('click', function(e){
    // Details auf/zu (Chevron)
    var btn = e.target.closest && e.target.closest('.inv-toggle-btn');
    if (btn && root.contains(btn)) {
      var tr  = btn.closest('tr.inv-item-row'); if (!tr) return;
      var det = getDetailsRowByMain(tr);
      if (!det) return;
      var open = det.style.display === 'table-row';
      det.style.display = open ? 'none' : 'table-row';
      tr.setAttribute('aria-expanded', String(!open));
      btn.querySelector('.bi')?.classList.toggle('bi-chevron-up', !open);
      btn.querySelector('.bi')?.classList.toggle('bi-chevron-down', open);
      return;
    }

    // Zeile entfernen
    var del = e.target.closest && e.target.closest('.btn-remove-item');
    if (del && root.contains(del)) {
      if (!confirm('Diese Position aus der Rechnung entfernen?')) return;
      var tr = del.closest('tr.inv-item-row'); if (!tr) return;
      var rowId = tr.getAttribute('data-row');
      var idInput = tr.querySelector('input[name^="items["][name$="[id]"]');
      if (idInput && idInput.value) {
        var trash = document.getElementById('invoice-hidden-trash');
        if (trash) { var hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='items_deleted[]'; hidden.value=idInput.value; trash.appendChild(hidden); }
      }
      var det = getDetailsRowById(rowId); if (det) det.remove();
      tr.remove();
      reindexRows();
      toggleTaxReason();
      recalcTotals();
      updateOrderHidden();
      return;
    }

    // Toggle qty/time in manueller Zeile
    var modeBtn = e.target.closest && e.target.closest('.mode-btn');
    if (modeBtn && root.contains(modeBtn)) {
      var tr = modeBtn.closest('tr.inv-item-row');
      if (!tr) return;
      var mode = modeBtn.dataset.mode;
      if (!mode) return;
      var entry = tr.querySelector('input.entry-mode');
      if (entry) entry.value = mode;
      tr.setAttribute('data-mode', mode);

      var qtyIn  = tr.querySelector('.quantity');
      var hoursIn= tr.querySelector('.hours-input');

      // Konvertieren für Anzeige
      if (mode === 'time' && qtyIn && hoursIn && (!hoursIn.value || hoursIn.value==='')) {
        var val = parseFloat((qtyIn.value||'0').replace(',','.')) || 0;
        var h = Math.floor(val), m = Math.round((val - h)*60);
        hoursIn.value = (h<10?'0':'')+h+':' + (m<10?'0':'')+m;
      }
      if (mode === 'qty' && qtyIn && hoursIn && (!qtyIn.value || qtyIn.value==='0')) {
        var v = String(hoursIn.value||'').trim();
        var dec = 0;
        if (v.includes(':')) { var p=v.split(':'), h=parseInt(p[0]||'0',10)||0, m=parseInt(p[1]||'0',10)||0; dec = h + m/60; }
        else { dec = parseFloat(v.replace(',','.'))||0; }
        qtyIn.value = dec.toFixed(3).replace('.',',');
      }

      // UI ein/aus
      if (qtyIn)   { qtyIn.classList.toggle('d-none', mode==='time'); qtyIn.disabled = (mode==='time'); }
      if (hoursIn) { hoursIn.classList.toggle('d-none', mode!=='time'); hoursIn.disabled = (mode!=='time'); }

      tr.querySelectorAll('.mode-btn').forEach(function(b){
        var active = (b.dataset.mode === mode);
        b.classList.toggle('btn-secondary', active);
        b.classList.toggle('text-white', active);
        b.classList.toggle('btn-outline-secondary', !active);
        b.setAttribute('aria-pressed', active ? 'true' : 'false');
      });

      recalcRow(tr);
      return;
    }
  });

  root.addEventListener('change', function(e){
    var sel = e.target.closest && e.target.closest('.inv-tax-sel');
    if (sel && root.contains(sel)) { updateVatFromScheme(sel); toggleTaxReason(); return; }
    if (e.target.closest && e.target.closest('.time-checkbox')) {
      var rowTr = e.target.closest('tr.inv-details')?.previousElementSibling;
      if (rowTr && rowTr.classList.contains('inv-item-row')) recalcRow(rowTr);
      return;
    }
  });

  root.addEventListener('input', function(e){
    if (e.target.matches && (e.target.matches('.rate') || e.target.matches('.inv-vat-input') || e.target.matches('.quantity') || e.target.matches('.hours-input'))) {
      var tr = e.target.closest('tr.inv-item-row'); if (tr) recalcRow(tr);
    }
  });

  /* ---------- Initial ---------- */
  root.querySelectorAll('tr.inv-item-row').forEach(function(tr){
    var sel = tr.querySelector('.inv-tax-sel');
    var vat = tr.querySelector('.inv-vat-input');
    if (sel && vat && (vat.value === '' || vat.value === null)) updateVatFromScheme(sel);
    recalcRow(tr);
  });
  toggleTaxReason();
  recalcTotals();

  (function(){
    var f = window.requestAnimationFrame || setTimeout;
    f(function(){
      reindexRows();
      updateOrderHidden();
    });
  })();
})();
</script>

<script>
/* ===== „+ Position“ (manuell) — mit Qty/Time-Toggle, ohne Aufklapper ===== */
(function(){
  const addBtn = document.getElementById('addManualItem');
  const root   = document.getElementById('invoice-items');
  if (!root || !addBtn) return;

  function nextIndex(){
    const rows = root.querySelectorAll('tr.inv-item-row');
    let max = 0;
    rows.forEach(r => { const n = parseInt(r.getAttribute('data-row')||'0',10); if(n>max) max=n; });
    return max + 1;
  }
  function insertBeforeGrand(el){
    const gt = root.querySelector('tr.inv-grand-total');
    const tb = root.querySelector('tbody');
    if (gt && tb) tb.insertBefore(el, gt);
  }
  function setBtnActive(btn, active){
    btn.classList.toggle('btn-secondary', active);
    btn.classList.toggle('text-white', active);
    btn.classList.toggle('btn-outline-secondary', !active);
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
  }

  addBtn.addEventListener('click', function(){
    const idx     = nextIndex();
    const defVat  = addBtn.getAttribute('data-default-vat') || '19.00';
    const defRate = addBtn.getAttribute('data-default-rate') || '0.00';

    const tr = document.createElement('tr');
    tr.className = 'inv-item-row';
    tr.setAttribute('data-row', String(idx));
    tr.setAttribute('data-mode', 'qty');           // Start in qty
    tr.setAttribute('data-fixed','0');
    tr.setAttribute('aria-expanded','false');

    tr.innerHTML =
      `<td class="text-center">
         <div class="d-flex justify-content-center gap-1">
           <!-- Kein Aufklapp-Button bei manuellen qty/time -->
           <span class="row-reorder-handle" title="Ziehen zum Sortieren" aria-label="Position verschieben">
             <svg class="grip" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
               <path d="M5 4h2v2H5V4Zm4 0h2v2H9V4ZM5 8h2v2H5V8Zm4 0h2v2H9V8ZM5 12h2v2H5v-2Zm4 0h2v2H9v-2Z" fill="currentColor"/>
             </svg>
           </span>
         </div>
         <input type="hidden" class="entry-mode" name="items[${idx}][entry_mode]" value="qty">
       </td>

       <td>
         <input type="text" class="form-control form-control-sm" name="items[${idx}][description]" value="">
       </td>

       <td class="text-end">
         <div class="input-group input-group-sm flex-nowrap">
           <button type="button" class="btn btn-outline-secondary mode-btn" data-mode="qty" title="Menge" aria-pressed="true">
             <i class="bi bi-123" aria-hidden="true"></i><span class="visually-hidden">Menge</span>
           </button>
           <button type="button" class="btn btn-outline-secondary mode-btn" data-mode="time" title="Zeit" aria-pressed="false">
             <i class="bi bi-clock" aria-hidden="true"></i><span class="visually-hidden">Zeit</span>
           </button>

           <input type="number"
                  class="form-control form-control-sm text-end quantity no-spin"
                  name="items[${idx}][quantity]" value="1" step="0.001" min="0">

           <input type="text"
                  class="form-control form-control-sm text-end hours-input d-none"
                  name="items[${idx}][hours]" placeholder="hh:mm oder 1.5" disabled>
         </div>
       </td>

       <td class="text-end">
         <input type="number" class="form-control form-control-sm text-end rate no-spin"
                name="items[${idx}][hourly_rate]"  value="${defRate}" step="0.01" min="0">
       </td>

       <td class="text-end">
         <select name="items[${idx}][tax_scheme]" class="form-select form-select-sm inv-tax-sel"
                 data-rate-standard="${defVat}" data-rate-tax-exempt="0.00" data-rate-reverse-charge="0.00">
           <option value="standard" selected>standard (mit MwSt)</option>
           <option value="tax_exempt">steuerfrei</option>
           <option value="reverse_charge">Reverse-Charge</option>
         </select>
       </td>

       <td class="text-end">
         <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm text-end inv-vat-input no-spin"
                name="items[${idx}][vat_rate]" value="${defVat}">
       </td>

       <td class="text-end"><span class="net">0,00</span></td>
       <td class="text-end"><span class="gross">0,00</span></td>

       <td class="text-end text-nowrap">
         <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
       </td>`;

    // Keine inv-details-Zeile bei manuellen +Positionen (wie gewünscht)
    insertBeforeGrand(tr);

    // Buttons initial färben + erste Berechnung
    const btnQty = tr.querySelector('.mode-btn[data-mode="qty"]');
    const btnTim = tr.querySelector('.mode-btn[data-mode="time"]');
    (function setActive(btn, active){
      btn.classList.toggle('btn-secondary', active);
      btn.classList.toggle('text-white', active);
      btn.classList.toggle('btn-outline-secondary', !active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    })(btnQty, true);
    (function setInactive(btn){ btn.classList.add('btn-outline-secondary'); })(btnTim);

    var evt = new Event('input', {bubbles:true});
    var rate = tr.querySelector('.rate'); if (rate) rate.dispatchEvent(evt);
  });
})();
</script>

<script>
(function(){
  var root = document.getElementById('invoice-items'); if (!root) return;

  function getDetailsRowByMain(mainTr){
    if (!mainTr) return null;
    var det = mainTr.nextElementSibling;
    if (det && det.classList.contains('inv-details') && det.getAttribute('data-row') === mainTr.getAttribute('data-row')) {
      return det;
    }
    var rowId = mainTr.getAttribute('data-row');
    return root.querySelector('.inv-details[data-row="'+rowId+'"]') || null;
  }

  // Nur Chevron-Logik – nichts anderes!
  root.addEventListener('click', function(e){
    var btn = e.target.closest && e.target.closest('.inv-toggle-btn');
    if (!btn || !root.contains(btn)) return;

    var tr  = btn.closest('tr.inv-item-row'); if (!tr) return;
    var det = getDetailsRowByMain(tr);       if (!det) return;

    var isOpen = window.getComputedStyle(det).display !== 'none';
    det.style.display = isOpen ? 'none' : 'table-row';
    tr.setAttribute('aria-expanded', String(!isOpen));

    // Icon sicher umschalten
    var ico = btn.querySelector('.bi');
    if (ico) {
      ico.classList.remove('bi-chevron-down','bi-chevron-up');
      ico.classList.add(isOpen ? 'bi-chevron-down' : 'bi-chevron-up');
    }

    // verhindert, dass andere Handler dazwischenfunken
    e.preventDefault();
    e.stopPropagation();
  }, true); // capture = true, damit dieser Handler gewinnt
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>