<?php
// import_alt.php
//
// ALT (zeiterfassung)  ->  NEU (zeitwerk)
//
// Läuft in EINER großen Transaktion. Bei DRY_RUN wird am Ende gerollt.
// Robust gegen Schema-Unterschiede: vor jedem Insert/Update werden die
// vorhandenen Spalten pro Tabelle ermittelt und nur diese befüllt.
//
// - Adressen OHNE <br />, HTML entfernen (Zeilenumbrüche erhalten).
// - Company-Status: "abgeschlossen" für Firmen ohne Zeiten im letzten Jahr, sonst "aktiv".
// - Aufgaben: Status 1->'offen', 2->'abgeschlossen'; Priorität 1=>'high', 2|3=>'medium', 4=>'low'.
// - Rechnungen: issue_date aus ALT.Datum, due_date aus ALT.Zahlungsziel (UNIX) – Fallback: issue_date + IMPORT_DEFAULT_DUE_DAYS.
// - Rechnungspositionen: description aus ALT.Ausweisung.
// - Verwaiste Zeiten (ohne Task-FK) werden übersprungen und gezählt.
// - Eindeutige Rechnungsnummern (Suffix falls nötig).
// - NEU: Zeiten-Status nach Verlinkung per Rechnungsstatus gesetzt (gestellt/gemahnt/bezahlt -> abgerechnet, in_vorbereitung -> in_abrechnung).
// - NEU: In ALT unfakturierbare Aufgaben ⇒ alle zugehörigen Zeiten beim Import als NICHT fakturierbar speichern.
// - NEU: Tasks von abgeschlossenen Projekten werden auf 'abgeschlossen' gesetzt.
//
//////////// CONFIG ////////////
$ALT_DSN  = 'mysql:host=127.0.0.1;dbname=zeiterfassung;charset=utf8mb4';
$ALT_USER = 'vera';
$ALT_PASS = 'secret';

$NEW_DSN  = 'mysql:host=127.0.0.1;dbname=zeitwerk;charset=utf8mb4';
$NEW_USER = 'vera';
$NEW_PASS = 'secret';

$TARGET_ACCOUNT_ID       = 1;          // <- Ziel-Account
$DEFAULT_USER_ID         = null;       // <- optional fest vorgeben; null = automatisch (erster User des Accounts)
$DRY_RUN                 = false;      // true = nur zählen/prüfen, nichts schreiben
$IMPORT_DEFAULT_DUE_DAYS = 14;         // Fallback für due_date, wenn ALT.Zahlungsziel fehlt
////////////////////////////////

ini_set('display_errors', '1');
error_reporting(E_ALL);

function pdo_make(string $dsn, string $user, string $pass): PDO {
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
}

function normalize_address(?string $s): string {
    if ($s === null) return '';
    $s = str_ireplace(["<br />", "<br/>", "<br>"], "\n", $s);
    $s = strip_tags($s);
    $s = str_replace("\r\n", "\n", $s);
    $s = preg_replace("/[ \t]+/", " ", $s);
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    return trim($s);
}
function map_task_status($alt): string {
    $alt = (int)$alt;
    return ($alt === 2) ? 'abgeschlossen' : 'offen';
}
function map_priority($alt): string {
    $alt = (int)$alt;
    if ($alt === 1) return 'high';
    if ($alt === 4) return 'low';
    if ($alt === 2 || $alt === 3) return 'medium';
    return 'medium';
}
function ymd_from_unix($ts): ?string {
    if ($ts === null) return null;
    $ts = (int)$ts;
    if ($ts <= 0) return null;
    return gmdate('Y-m-d', $ts);
}
function ymdhis_from_unix($ts): ?string {
    if ($ts === null) return null;
    $ts = (int)$ts;
    if ($ts <= 0) return null;
    return gmdate('Y-m-d H:i:s', $ts);
}
function ensure_unique_invoice_number(PDO $pdo, int $accountId, ?string $base, int $altId): string {
    $base = trim((string)$base);
    if ($base === '') $base = 'ALT-'.$altId;
    $q = $pdo->prepare("SELECT 1 FROM invoices WHERE account_id=? AND invoice_number=?");
    $q->execute([$accountId, $base]);
    if (!$q->fetchColumn()) return $base;
    $i = 2;
    while (true) {
        $try = $base.'-'.$i;
        $q->execute([$accountId, $try]);
        if (!$q->fetchColumn()) return $try;
        $i++;
        if ($i > 1000) return $base.'-'.uniqid();
    }
}
function pick_default_user_id(PDO $pdo, int $accountId): int {
    $st = $pdo->prepare("SELECT id FROM users WHERE account_id=? ORDER BY id LIMIT 1");
    $st->execute([$accountId]);
    $uid = (int)$st->fetchColumn();
    if ($uid <= 0) throw new RuntimeException("Kein Benutzer im Ziel-Account {$accountId} gefunden.");
    return $uid;
}

// -------- helpers: dynamic insert/update based on real columns ----------
$__tableColsCache = [];
function db_name(PDO $pdo): string {
    $r = $pdo->query("SELECT DATABASE()")->fetchColumn();
    return (string)$r;
}
function table_columns(PDO $pdo, string $table): array {
    global $__tableColsCache;
    if (isset($__tableColsCache[$table])) return $__tableColsCache[$table];
    $db = db_name($pdo);
    $st = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ");
    $st->execute([$db, $table]);
    $cols = array_map(fn($r)=>$r['COLUMN_NAME'], $st->fetchAll());
    $__tableColsCache[$table] = $cols;
    return $cols;
}
function insert_filtered(PDO $pdo, string $table, array $row): int {
    $cols = table_columns($pdo, $table);
    $data = array_intersect_key($row, array_flip($cols));
    if (empty($data)) {
        throw new RuntimeException("No matching columns to insert into {$table}.");
    }
    $keys = array_keys($data);
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    $sql  = "INSERT INTO {$table} (".implode(',', $keys).") VALUES ({$ph})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}
function update_filtered_by_id(PDO $pdo, string $table, array $row, string $idCol, $idVal, array $andEq = []): void {
    $cols = table_columns($pdo, $table);
    $data = array_intersect_key($row, array_flip($cols));
    if (empty($data)) return;
    $set  = implode(', ', array_map(fn($c)=>"$c=?", array_keys($data)));
    $sql  = "UPDATE {$table} SET {$set} WHERE {$idCol}=?";
    $params = array_values($data);
    $params[] = $idVal;
    foreach ($andEq as $col => $val) {
        if (!in_array($col, $cols, true)) continue;
        $sql .= " AND {$col}=?";
        $params[] = $val;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

// -------------------------------------------------------------

echo "Starting import (DRY_RUN=".($DRY_RUN?'yes':'no').") for account_id={$TARGET_ACCOUNT_ID}".($DEFAULT_USER_ID?(", default user_id={$DEFAULT_USER_ID}"):"").PHP_EOL;

$alt = pdo_make($ALT_DSN, $ALT_USER, $ALT_PASS);
$new = pdo_make($NEW_DSN, $NEW_USER, $NEW_PASS);

if ($DEFAULT_USER_ID === null) {
    $DEFAULT_USER_ID = pick_default_user_id($new, $TARGET_ACCOUNT_ID);
}

$new->beginTransaction();

try {
    $mapCompany = [];
    $mapProject = [];
    $mapTask    = [];
    $mapTime    = [];
    $mapInvoice = [];
    $mapItem    = [];

    $recentActiveCompanies = []; // new company_id => true
    $lastYearCutoff = time() - 365*24*3600;

    // Merkliste: welche NEUEN Projekte sind abgeschlossen?
    $closedProjectsNew = []; // [new_project_id] => true

    // =================================================
    // COMPANIES
    // =================================================
    echo PHP_EOL."ALT companies='zf_auftraggeber', PK='ID_Auftraggeber'".PHP_EOL;
    $rows = $alt->query("SELECT * FROM zf_auftraggeber ORDER BY ID_Auftraggeber")->fetchAll();

    $cntCompanies = 0;
    foreach ($rows as $r) {
        $altId = (int)$r['ID_Auftraggeber'];

        $name = trim((string)($r['Firma'] ?? ''));
        if ($name === '') $name = trim((string)($r['Name'] ?? ''));
        if ($name === '') $name = 'Firma '.$altId;

        $addr  = normalize_address($r['Anschrift'] ?? '');
        $ustId = trim((string)($r['Ust_ID'] ?? ''));
        $rate  = (float)($r['Stundensatz'] ?? 0);
        $short = trim((string)($r['Kürzel'] ?? ''));

        $row = [
            'account_id'  => $TARGET_ACCOUNT_ID,
            'name'        => $name,
            'address'     => $addr,
            'hourly_rate' => $rate,
            'vat_id'      => $ustId,
            'shortcode'   => $short,
            'status'      => 'aktiv',
        ];

        if (!$DRY_RUN) {
            $newId = insert_filtered($new, 'companies', $row);
        } else {
            $newId = $altId;
        }

        $mapCompany[$altId] = $newId;
        $cntCompanies++;
    }
    echo "Imported companies: {$cntCompanies}".PHP_EOL;

    // =================================================
    // PROJECTS
    // =================================================
    echo "ALT projects='zf_projekte', PK='ID_Projekt'".PHP_EOL;
    $rows = $alt->query("SELECT * FROM zf_projekte ORDER BY ID_Projekt")->fetchAll();

    $cntProjects = 0;
    $skipProjectsMissingCompany = 0;
    foreach ($rows as $r) {
        $altId   = (int)$r['ID_Projekt'];
        $altCoId = (int)$r['FID_Auftraggeber'];
        if (empty($mapCompany[$altCoId])) { $skipProjectsMissingCompany++; continue; }

        $companyId = (int)$mapCompany[$altCoId];
        $title     = trim((string)$r['Projekt']);
        if ($title === '') $title = 'Projekt '.$altId;
        $desc      = trim((string)($r['Beschreibung'] ?? ''));
        $rate      = (float)($r['Stundensatz'] ?? 0);

        // ALT: 1 -> offen, 2 -> abgeschlossen
        $status = ($r["Status"] == 1 ? "offen" : ($r["Status"] == 2 ? "abgeschlossen" : ""));

        $row = [
            'account_id' => $TARGET_ACCOUNT_ID,
            'company_id' => $companyId,
            'title'      => $title,
            'description'=> $desc,
            'hourly_rate'=> $rate,
            'status'     => $status,
        ];

        if (!$DRY_RUN) {
            $newId = insert_filtered($new, 'projects', $row);
        } else {
            $newId = $altId;
        }
        $mapProject[$altId] = $newId;
        if ($status === 'abgeschlossen') {
            $closedProjectsNew[$newId] = true; // für Task-Override
        }
        $cntProjects++;
    }
    echo "Imported projects: {$cntProjects}".($skipProjectsMissingCompany ? " (skipped missing company FK: {$skipProjectsMissingCompany})" : "").PHP_EOL;

    // =================================================
    // TASKS
    // =================================================
    echo "ALT tasks='zf_aufgaben', PK='ID_Aufgabe'".PHP_EOL;
    $rows = $alt->query("SELECT * FROM zf_aufgaben ORDER BY ID_Aufgabe")->fetchAll();

    $cntTasks = 0;
    $skipTasksMissingProject = 0;
    foreach ($rows as $r) {
        $altId   = (int)$r['ID_Aufgabe'];
        $altProj = (int)$r['FID_Projekt'];
        if (empty($mapProject[$altProj])) { $skipTasksMissingProject++; continue; }

        $projectId = (int)$mapProject[$altProj];
        $desc      = trim((string)$r['Aufgabe']);
        if ($desc === '') $desc = 'Aufgabe '.$altId;

        $planned = (int)($r['geplante_Zeit'] ?? 0);
        $prio    = map_priority($r['Priorität'] ?? null);
        $pos     = (int)($r['Reihenfolge'] ?? 0);
        $due     = ymd_from_unix($r['Deadline'] ?? null);

        // Grundstatus laut ALT
        $status  = map_task_status($r['Status'] ?? null);
        // NEU: Wenn das zugehörige Projekt abgeschlossen ist, Task-Status hart auf 'abgeschlossen' setzen
        if (isset($closedProjectsNew[$projectId])) {
            $status = 'abgeschlossen';
        }

        $row = [
            'account_id'      => $TARGET_ACCOUNT_ID,
            'project_id'      => $projectId,
            'description'     => $desc,
            'planned_minutes' => $planned,
            'priority'        => $prio,
            'position'        => $pos,
            'due_date'        => $due,
            'status'          => $status,
        ];

        if (!$DRY_RUN) {
            $newId = insert_filtered($new, 'tasks', $row);
        } else {
            $newId = $altId;
        }
        $mapTask[$altId] = $newId;
        $cntTasks++;
    }
    echo "Imported tasks: {$cntTasks}".($skipTasksMissingProject ? " (skipped missing project FK: {$skipTasksMissingProject})" : "").PHP_EOL;

    // =================================================
    // TIMES
    // =================================================
    echo "ALT times='zf_zeiten', PK='ID_Zeit'".PHP_EOL;
    $rows = $alt->query("SELECT * FROM zf_zeiten ORDER BY ID_Zeit")->fetchAll();

    $cntTimes = 0;
    $skipTimesMissingTask = 0;

    // NEU: wir ziehen jetzt auch das Task-Flag 'fakturierbar' mit
    $getAltTask = $alt->prepare("SELECT FID_Projekt, fakturierbar FROM zf_aufgaben WHERE ID_Aufgabe=?");
    $getAltProj = $alt->prepare("SELECT FID_Auftraggeber FROM zf_projekte WHERE ID_Projekt=?");
    $cache_task_to_company = [];

    foreach ($rows as $r) {
        $altId   = (int)$r['ID_Zeit'];
        $altTask = (int)$r['FID_Aufgabe'];
        if (empty($mapTask[$altTask])) { $skipTimesMissingTask++; continue; }

        $taskId = (int)$mapTask[$altTask];
        $userId = (int)$DEFAULT_USER_ID;

        $von   = (int)$r['von'];
        $bis   = (int)$r['bis'];
        $start = ymdhis_from_unix($von);
        $end   = ymdhis_from_unix($bis);
        $minutes = 0;
        if ($start && $end) {
            $diff = max(0, $bis - $von);
            $minutes = (int)round($diff / 60);
        }

        // ALT-Flags laden: Task-Fakturierbarkeit + Projekt für Company-Ermittlung
        $getAltTask->execute([$altTask]);
        $trow = $getAltTask->fetch() ?: [];
        $altProjId       = (int)($trow['FID_Projekt']   ?? 0);
        $taskBillableAlt = (int)($trow['fakturierbar']  ?? 1);

        // Zeit-Flag aus ALT.Zeit, aber von ALT.Task übersteuern falls nicht fakturierbar
        $bill = (int)($r['fakturierbar'] ?? 0) ? 1 : 0;
        if ($taskBillableAlt === 0) {
            $bill = 0; // ← Korrektur
        }

        $status = 'offen';

        if (!$DRY_RUN) {
            $row = [
                'account_id' => $TARGET_ACCOUNT_ID,
                'task_id'    => $taskId,
                'user_id'    => $userId,
                'started_at' => $start,
                'ended_at'   => $end,
                'minutes'    => $minutes,
                'billable'   => $bill,
                'status'     => $status,
            ];
            $newId = insert_filtered($new, 'times', $row);
        } else {
            $newId = $altId;
        }
        $mapTime[$altId] = $newId;
        $cntTimes++;

        // recent activity -> company_id finden (für Status später)
        if ($von > 0) {
            $cidNew = null;
            if (isset($cache_task_to_company[$altTask])) {
                $cidNew = $cache_task_to_company[$altTask];
            } else {
                if ($altProjId > 0) {
                    $getAltProj->execute([$altProjId]);
                    $altCompanyId = (int)$getAltProj->fetchColumn();
                    if ($altCompanyId && isset($mapCompany[$altCompanyId])) {
                        $cidNew = (int)$mapCompany[$altCompanyId];
                        $cache_task_to_company[$altTask] = $cidNew;
                    }
                }
            }
            if ($cidNew && $von >= $lastYearCutoff) {
                $recentActiveCompanies[$cidNew] = true;
            }
        }
    }
    echo "Imported times: {$cntTimes}".($skipTimesMissingTask ? " (skipped missing task FK: {$skipTimesMissingTask})" : "").PHP_EOL;

    // =================================================
    // INVOICES
    // =================================================
    echo "ALT invoices='zf_rechnungen', PK='ID_Rechnung'".PHP_EOL;
    $rowsInv = $alt->query("SELECT * FROM zf_rechnungen ORDER BY ID_Rechnung")->fetchAll();

    $cntInv = 0;
    $skipInvMissingCompany = 0;

    // Merke Rechnungsstatus je NEUER ID (für spätere Zeit-Status-Updates)
    $invoiceStatusByNewId = [];

    foreach ($rowsInv as $r) {
        $altId   = (int)$r['ID_Rechnung'];
        $altCoId = (int)$r['FID_Auftraggeber'];
        if (empty($mapCompany[$altCoId])) { $skipInvMissingCompany++; continue; }

        $companyId = (int)$mapCompany[$altCoId];
        $issue     = ymd_from_unix($r['Datum'] ?? null);

        // due_date: primär aus ALT.Zahlungsziel, sonst issue_date + IMPORT_DEFAULT_DUE_DAYS (oder heute + …, falls issue leer)
        $dueRaw    = ymd_from_unix($r['Zahlungsziel'] ?? null);
        if ($dueRaw) {
            $due = $dueRaw;
        } else {
            $base = $issue ?: date('Y-m-d');
            $due  = date('Y-m-d', strtotime($base.' +'.$IMPORT_DEFAULT_DUE_DAYS.' days'));
        }

        $number    = ensure_unique_invoice_number($new, $TARGET_ACCOUNT_ID, trim((string)($r['Rechnungsnummer'] ?? '')), $altId);
        $gross     = (float)($r['Betrag'] ?? 0.0);

        // Du hattest "bezahlt" gewünscht:
        $statusNew = 'bezahlt';

        $row = [
            'account_id'            => $TARGET_ACCOUNT_ID,
            'company_id'            => $companyId,
            'status'                => $statusNew,
            'issue_date'            => $issue ?: date('Y-m-d'),
            'due_date'              => $due,             // niemals NULL
            'invoice_number'        => $number,
            'total_net'             => 0.0,              // wird nach Items aktualisiert
            'total_gross'           => $gross,           // vorläufig
            'tax_exemption_reason'  => null,
        ];

        if (!$DRY_RUN) {
            $newId = insert_filtered($new, 'invoices', $row);
        } else {
            $newId = $altId;
        }
        $mapInvoice[$altId]         = $newId;
        $invoiceStatusByNewId[$newId] = $statusNew;

        $cntInv++;
    }
    echo "Imported invoices: {$cntInv}".($skipInvMissingCompany ? " (skipped missing company FK: {$skipInvMissingCompany})" : "").PHP_EOL;

    // =================================================
    // INVOICE ITEMS + LINKS
    // =================================================
    echo "ALT items='zf_rechnungspositionen', PK='ID_Position'".PHP_EOL;

    $rowsItems = $alt->query("SELECT * FROM zf_rechnungspositionen ORDER BY ID_Position")->fetchAll();
    $rowsLinks = $alt->query("SELECT * FROM zf_rechnungspositionen_zuordnung ORDER BY ID_Zuordnung")->fetchAll();

    $linksByItem = [];
    foreach ($rowsLinks as $L) {
        $linksByItem[(int)$L['FID_Position']][] = (int)$L['FID_Zeit'];
    }

    $getAltZeit  = $alt->prepare("SELECT FID_Aufgabe, von, bis FROM zf_zeiten WHERE ID_Zeit=?");
    $getAltTask2 = $alt->prepare("SELECT FID_Projekt FROM zf_aufgaben WHERE ID_Aufgabe=?");

    $invVatCache = []; // ALT ID_Rechnung => float
    $sumByInvoiceNew = [];

    $cntItems = 0;
    $skipItemsMissingInvoice = 0;

    // Sammellisten für spätere Status-Updates der Zeiten:
    $timeIdsToMarkAbgerechnet   = [];
    $timeIdsToMarkInAbrechnung  = [];

    foreach ($rowsItems as $it) {
        $altItemId = (int)$it['ID_Position'];
        $altInvId  = (int)$it['FID_Rechnung'];
        if (empty($mapInvoice[$altInvId])) { $skipItemsMissingInvoice++; continue; }
        $newInvId  = (int)$mapInvoice[$altInvId];

        // Beschreibung aus ALT.Ausweisung, NICHT Projekt
        $desc = trim((string)$it['Ausweisung']);
        if ($desc === '') $desc = 'Position '.$altItemId;

        $rate     = (float)($it['Stundensatz'] ?? 0.0);
        $position = (int)($it['Reihenfolge'] ?? 0);

        if (!isset($invVatCache[$altInvId])) {
            $invVatCache[$altInvId] = (float)($alt->query("SELECT Steuersatz FROM zf_rechnungen WHERE ID_Rechnung=".(int)$altInvId)->fetchColumn() ?: 0.0);
        }
        $vat    = (float)$invVatCache[$altInvId];
        $scheme = ($vat > 0) ? 'standard' : 'tax_exempt';

        $linkedAltTimeIds = $linksByItem[$altItemId] ?? [];
        $minutes = 0;
        $projectIdNew = null;
        $taskIdNew    = null;

        foreach ($linkedAltTimeIds as $altTimeId) {
            if (empty($mapTime[$altTimeId])) continue;
            $getAltZeit->execute([$altTimeId]);
            if ($z = $getAltZeit->fetch()) {
                if ($projectIdNew === null || $taskIdNew === null) {
                    $altTask = (int)$z['FID_Aufgabe'];
                    if (!empty($mapTask[$altTask])) {
                        $taskIdNew = (int)$mapTask[$altTask];
                        $getAltTask2->execute([$altTask]);
                        $altProj = (int)$getAltTask2->fetchColumn();
                        if (!empty($mapProject[$altProj])) {
                            $projectIdNew = (int)$mapProject[$altProj];
                        }
                    }
                }
                $v=(int)$z['von']; $b=(int)$z['bis'];
                $minutes += max(0, (int)round(($b-$v)/60));
            }
        }

        $qty   = round($minutes / 60, 3);
        $net   = round(($minutes/60) * $rate, 2);
        $gross = round($net * (1 + $vat/100), 2);

        if (!$DRY_RUN) {
            $newItemId = insert_filtered($new, 'invoice_items', [
                'account_id' => $TARGET_ACCOUNT_ID,
                'invoice_id' => $newInvId,
                'project_id' => $projectIdNew,
                'task_id'    => $taskIdNew,
                'description'=> $desc,
                'quantity'   => $qty,
                'unit_price' => $rate,
                'vat_rate'   => $vat,
                'total_net'  => $net,
                'total_gross'=> $gross,
                'position'   => $position,
                'tax_scheme' => $scheme,
            ]);

            // Links setzen
            $cols_iit = table_columns($new, 'invoice_item_times');
            if (in_array('account_id', $cols_iit, true) && in_array('invoice_item_id', $cols_iit, true) && in_array('time_id', $cols_iit, true)) {
                $sql = "INSERT INTO invoice_item_times (account_id, invoice_item_id, time_id) VALUES (?,?,?)";
                $insLink = $new->prepare($sql);
                foreach ($linkedAltTimeIds as $altTimeId) {
                    if (empty($mapTime[$altTimeId])) continue;
                    $newTimeId = (int)$mapTime[$altTimeId];
                    $insLink->execute([$TARGET_ACCOUNT_ID, $newItemId, $newTimeId]);

                    // Zeit-Status vormerken je Rechnungsstatus:
                    $invStatus = $invoiceStatusByNewId[$newInvId] ?? 'gestellt';
                    if (in_array($invStatus, ['gestellt','gemahnt','bezahlt'], true)) {
                        $timeIdsToMarkAbgerechnet[$newTimeId] = true;
                    } else {
                        $timeIdsToMarkInAbrechnung[$newTimeId] = true;
                    }
                }
            }

            $mapItem[$altItemId] = $newItemId;
        } else {
            $mapItem[$altItemId] = $altItemId;
        }

        $sumByInvoiceNew[$newInvId]['net']   = ($sumByInvoiceNew[$newInvId]['net']   ?? 0) + $net;
        $sumByInvoiceNew[$newInvId]['gross'] = ($sumByInvoiceNew[$newInvId]['gross'] ?? 0) + $gross;
        $cntItems++;
    }

    echo "Imported items: {$cntItems}".($skipItemsMissingInvoice ? " (skipped missing invoice FK: {$skipItemsMissingInvoice})" : "").PHP_EOL;

    // Links-Statistik
    $totalLinks = count($rowsLinks);
    $okLinks = 0; $skipLinks = 0;
    foreach ($rowsLinks as $L) {
        $aItem = (int)$L['FID_Position'];
        $aTime = (int)$L['FID_Zeit'];
        if (!empty($mapItem[$aItem]) && !empty($mapTime[$aTime])) $okLinks++; else $skipLinks++;
    }
    echo "ALT item_links='zf_rechnungspositionen_zuordnung'".PHP_EOL;
    echo "Linked item-times: {$okLinks}".($skipLinks ? " (skipped with missing FK: {$skipLinks})" : "").PHP_EOL;

    // =================================================
    // UPDATE INVOICE TOTALS (aus Items)
    // =================================================
    if (!$DRY_RUN) {
        foreach ($sumByInvoiceNew as $invId => $sums) {
            update_filtered_by_id($new, 'invoices', [
                'total_net'   => (float)($sums['net']   ?? 0),
                'total_gross' => (float)($sums['gross'] ?? 0),
            ], 'id', $invId, ['account_id' => $TARGET_ACCOUNT_ID]);
        }
    }

    // =================================================
    // TIMES STATUS NACH VERLINKUNG SETZEN (gebündelt)
    // =================================================
    if (!$DRY_RUN) {
        // 'abgerechnet' hat Vorrang
        $idsAbgerechnet  = array_keys($timeIdsToMarkAbgerechnet);
        $idsInAbrechnung = array_diff(array_keys($timeIdsToMarkInAbrechnung), $idsAbgerechnet);

        if ($idsAbgerechnet) {
            $in = implode(',', array_fill(0, count($idsAbgerechnet), '?'));
            $params = array_merge([$TARGET_ACCOUNT_ID], $idsAbgerechnet);
            $new->prepare("UPDATE times SET status='abgerechnet' WHERE account_id=? AND id IN ($in)")->execute($params);
        }
        if ($idsInAbrechnung) {
            $in = implode(',', array_fill(0, count($idsInAbrechnung), '?'));
            $params = array_merge([$TARGET_ACCOUNT_ID], $idsInAbrechnung);
            $new->prepare("UPDATE times SET status='in_abrechnung' WHERE account_id=? AND id IN ($in)")->execute($params);
        }
    }

    // =================================================
    // COMPANY STATUS: "abgeschlossen" falls KEINE Zeit in letzten 12 Monaten
    // =================================================
    if (!$DRY_RUN) {
        if (in_array('status', table_columns($new,'companies'), true)) {
            // alle aktiv
            update_filtered_by_id($new, 'companies', ['status' => 'aktiv'], 'account_id', $TARGET_ACCOUNT_ID);
            // dann die ohne recent activity -> abgeschlossen
            $toClose = [];
            foreach ($mapCompany as $cidNew) {
                if (empty($recentActiveCompanies[$cidNew])) $toClose[] = $cidNew;
            }
            if ($toClose) {
                $in = implode(',', array_fill(0, count($toClose), '?'));
                $params = array_merge([$TARGET_ACCOUNT_ID], $toClose);
                $new->prepare("UPDATE companies SET status='abgeschlossen' WHERE account_id=? AND id IN ($in)")->execute($params);
            }
        }
    }

    if ($DRY_RUN) {
        $new->rollBack();
        echo "DRY_RUN active → rolling back.".PHP_EOL;
    } else {
        $new->commit();
        echo "Import committed.".PHP_EOL;
    }

} catch (Throwable $e) {
    $new->rollBack();
    echo "✖ Import failed: ".$e->getMessage().PHP_EOL;
    exit(1);
}