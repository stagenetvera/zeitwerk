<?php
  // public/companies/show.php
  require __DIR__ . '/../../src/layout/header.php';
  require_login();

  $user       = auth_user();
  $account_id = (int)$user['account_id'];
  $user_id    = (int)$user['id'];

  // -------- helpers --------
  function page_int($v, $d = 1)
  {$n = (int)$v;return $n > 0 ? $n : $d;}
  function hurl($s)
  {return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');}

  function render_pagination_named_keep($baseUrl, $param, $current, $total, $keepParams = [])
  {
    if ($total <= 1) {
      return '';
    }

    $qs = function ($extra) use ($keepParams) {
      $arr = array_merge($keepParams, $extra);
      return http_build_query($arr);
    };
    $prev = max(1, $current - 1);
    $next = min($total, $current + 1);
  ob_start(); ?>
  <nav><ul class="pagination mb-0">
    <li class="page-item <?php echo $current === 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?php echo hurl($baseUrl . '?' . $qs([$param => 1])) ?>">&laquo;</a></li>
    <li class="page-item <?php echo $current === 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?php echo hurl($baseUrl . '?' . $qs([$param => $prev])) ?>">&lsaquo;</a></li>
    <?php $s = max(1, $current - 2);
      $e           = min($total, $current + 2);for ($i = $s; $i <= $e; $i++): ?>
      <li class="page-item <?php echo $i === $current ? 'active' : '' ?>"><a class="page-link" href="<?php echo hurl($baseUrl . '?' . $qs([$param => $i])) ?>"><?php echo $i ?></a></li>
    <?php endfor; ?>
    <li class="page-item <?php echo $current === $total ? 'disabled' : '' ?>"><a class="page-link" href="<?php echo hurl($baseUrl . '?' . $qs([$param => $next])) ?>">&rsaquo;</a></li>
    <li class="page-item <?php echo $current === $total ? 'disabled' : '' ?>"><a class="page-link" href="<?php echo hurl($baseUrl . '?' . $qs([$param => $total])) ?>">&raquo;</a></li>
  </ul></nav>
  <?php return ob_get_clean();
    }

    // -------- input --------
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {echo '<div class="alert alert-danger">Ungültige Firmen-ID.</div>';require __DIR__ . '/../../src/layout/footer.php';exit;}

    // Fetch company
    $cs = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
    $cs->execute([$id, $account_id]);
    $company = $cs->fetch();
    if (! $company) {
      echo '<div class="alert alert-danger">Firma nicht gefunden.</div>';
      require __DIR__ . '/../../src/layout/footer.php';exit;
    }

    // Contacts (no pagination change)
    $ks = $pdo->prepare('SELECT * FROM contacts WHERE account_id = ? AND company_id = ? ORDER BY name');
    $ks->execute([$account_id, $id]);
    $contacts = $ks->fetchAll();

    // -------- project status filter --------
    $allowed_status = ['angeboten', 'offen', 'abgeschlossen'];
    $proj_status    = [];
    $has_psf        = isset($_GET['psf']); // wurde das Projekte-Filterformular abgesendet?

    if (isset($_GET['proj_status']) && is_array($_GET['proj_status'])) {
      foreach ($_GET['proj_status'] as $st) {
        if (! is_string($st)) {
          continue;
        }
        // wichtig: nur Strings
        $st = strtolower(trim($st));
        if (in_array($st, $allowed_status, true)) {
          $proj_status[] = $st;
        }
      }
    }
    if (! $has_psf) {
      // Default-Auswahl, wenn nicht aktiv gefiltert
      $proj_status = ['angeboten', 'offen'];
    }

    // $keep für Pagination (immer ID + Projekte-Filter mitgeben)
    $keep_projects = ['id' => $id, 'psf' => 1, 'proj_status' => $proj_status];

    // -------- projects pagination + query --------
    $proj_per_page = 5;
    $proj_page     = page_int($_GET['proj_page'] ?? 1);
    $proj_offset   = ($proj_page - 1) * $proj_per_page;

    $whereP  = ['p.account_id = :acc', 'p.company_id = :cid'];
    $paramsP = [':acc' => $account_id, ':cid' => $id];

    if ($proj_status) {
      // IN list
      $in = [];
      foreach ($proj_status as $i => $st) {
        $key           = ':st' . $i;
        $in[]          = $key;
        $paramsP[$key] = $st;
      }
      $whereP[] = 'p.status IN (' . implode(',', $in) . ')';
    }
    $WHEREP = implode(' AND ', $whereP);

    // Count
    $pcnt = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE $WHEREP");
    foreach ($paramsP as $k => $v) {$pcnt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);}
    $pcnt->execute();
    $projects_total = (int)$pcnt->fetchColumn();
    $projects_pages = max(1, (int)ceil($projects_total / $proj_per_page));

    // Fetch rows
    $ps = $pdo->prepare("SELECT p.id, p.title, p.status, p.hourly_rate AS project_rate,
                            c.hourly_rate AS company_rate,
                            COALESCE(p.hourly_rate, c.hourly_rate) AS effective_rate
                     FROM projects p
                     JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
                     WHERE $WHEREP
                     ORDER BY p.title
                     LIMIT :limit OFFSET :offset");
    foreach ($paramsP as $k => $v) {$ps->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);}
    $ps->bindValue(':limit', $proj_per_page, PDO::PARAM_INT);
    $ps->bindValue(':offset', $proj_offset, PDO::PARAM_INT);
    $ps->execute();
    $projects = $ps->fetchAll();

    // -------- tasks pagination (unchanged logic) --------
    $task_per_page = 5;
    $task_page     = page_int($_GET['task_page'] ?? 1);
    $task_offset   = ($task_page - 1) * $task_per_page;

    // total open tasks for this company
    $tcnt = $pdo->prepare("SELECT COUNT(*)
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  WHERE t.account_id = ? AND p.company_id = ? AND t.status <> 'abgeschlossen'");
    $tcnt->execute([$account_id, $id]);
    $tasks_total = (int)$tcnt->fetchColumn();
    $tasks_pages = max(1, (int)ceil($tasks_total / $task_per_page));

    // Ensure ordering table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_ordering_global (
  account_id INT NOT NULL,
  task_id INT NOT NULL,
  position INT NOT NULL,
  PRIMARY KEY (account_id, task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // fetch paginated tasks
    $sqlTasks = "SELECT
    t.id AS task_id, t.description, t.deadline, t.planned_minutes, t.priority, t.status,
    p.id AS project_id, p.title AS project_title,
    COALESCE((
      SELECT SUM(tt.minutes) FROM times tt
      WHERE tt.account_id = t.account_id AND tt.user_id = :uid AND tt.task_id = t.id AND tt.minutes IS NOT NULL
    ), 0) AS spent_minutes,
    og.position AS sort_pos
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  LEFT JOIN task_ordering_global og ON og.account_id = t.account_id AND og.task_id = t.id
  WHERE t.account_id = :acc AND p.company_id = :cid AND t.status <> 'abgeschlossen'
  ORDER BY (og.position IS NULL), og.position, p.title, t.deadline IS NULL, t.deadline, t.id DESC
  LIMIT :limit OFFSET :offset";
    $st = $pdo->prepare($sqlTasks);
    $st->bindValue(':uid', $user_id, PDO::PARAM_INT);
    $st->bindValue(':acc', $account_id, PDO::PARAM_INT);
    $st->bindValue(':cid', $id, PDO::PARAM_INT);
    $st->bindValue(':limit', $task_per_page, PDO::PARAM_INT);
    $st->bindValue(':offset', $task_offset, PDO::PARAM_INT);
    $st->execute();
    $tasks = $st->fetchAll();

    // running timer info
    $runStmt = $pdo->prepare('SELECT id, task_id, started_at FROM times WHERE account_id = ? AND user_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
    $runStmt->execute([$account_id, $user_id]);
    $running         = $runStmt->fetch();
    $has_running     = (bool)$running;
    $running_task_id = $running && $running['task_id'] ? (int)$running['task_id'] : 0;
    $running_extra   = 0;
    if ($has_running) {
      $start         = new DateTimeImmutable($running['started_at']);
      $now           = new DateTimeImmutable('now');
      $running_extra = max(0, (int)floor(($now->getTimestamp() - $start->getTimestamp()) / 60));
    }
    function fmt_minutes($m)
    {
      if ($m === null) {
        return '—';
      }

      $m = (int)$m;
      $h = intdiv($m, 60);
      $r = $m % 60;
      return $h > 0 ? sprintf('%d:%02d h', $h, $r) : ($m . ' min');
    }

    // -------- view --------
  ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Firma: <?php echo h($company['name']) ?></h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?php echo url('/companies/index.php') ?>">Zurück zur Übersicht</a>
    <a class="btn btn-primary" href="<?php echo url('/companies/edit.php') ?>?id=<?php echo $company['id'] ?>">Firma bearbeiten</a>
  </div>
</div>

<div class="row mb-3">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Stammdaten</h5>
        <dl class="row mb-0">
          <dt class="col-sm-4">Adresse</dt><dd class="col-sm-8"><?php echo nl2br(h($company['address'] ?? '')) ?></dd>
          <dt class="col-sm-4">USt-ID</dt><dd class="col-sm-8"><?php echo h($company['vat_id'] ?? '—') ?></dd>
          <dt class="col-sm-4">Stundensatz (Firma)</dt><dd class="col-sm-8">€ <?php echo h(number_format((float)($company['hourly_rate'] ?? 0), 2, ',', '.')) ?></dd>
          <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?php echo h($company['status'] ?? '') ?></dd>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="card-title mb-0">Ansprechpartner</h5>
          <a class="btn btn-sm btn-primary" href="<?php echo url('/contacts/new.php') ?>?company_id=<?php echo $company['id'] ?>">Neu</a>
        </div>
        <?php if ($contacts): ?>




        <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Name</th><th>E-Mail</th><th>Telefon</th><th class="text-end">Aktionen</th></tr></thead>
              <tbody>
                <?php foreach ($contacts as $k): ?>
                  <tr>
                    <td><?php echo h($k['name']) ?></td>
                    <td><?php echo h($k['email'] ?? '') ?></td>
                    <td><?php echo h($k['phone'] ?? '') ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-secondary" href="<?php echo url('/contacts/edit.php') ?>?id=<?php echo $k['id'] ?>">Bearbeiten</a>
                      <form method="post" action="<?php echo url('/contacts/delete.php') ?>" class="d-inline" onsubmit="return confirm('Kontakt wirklich löschen?');">
                        <?php echo csrf_field() ?>
                        <input type="hidden" name="id" value="<?php echo $k['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Löschen</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-muted">Noch keine Ansprechpartner.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="card-title mb-0">Projekte</h5>
      <a class="btn btn-sm btn-primary" href="<?php echo url('/projects/new.php') ?>?company_id=<?php echo $company['id'] ?>">Neu</a>
    </div>
    <form class="row g-2 mb-3" method="get" action="<?php echo hurl(url('/companies/show.php')) ?>">
      <input type="hidden" name="id" value="<?php echo $company['id'] ?>">
      <input type="hidden" name="psf" value="1">
      <div class="col-md-6">
        <label class="form-label">Projekt-Status</label>
        <div class="d-flex flex-wrap gap-3">
          <?php foreach ($allowed_status as $opt): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="proj_status[]" id="ps-<?php echo h($opt) ?>" value="<?php echo h($opt) ?>" <?php echo in_array($opt, $proj_status, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="ps-<?php echo h($opt) ?>"><?php echo h($opt) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Standard: „offen“ und „angeboten“.</div>
      </div>
      <div class="col-md-6 d-flex align-items-end justify-content-end gap-2">
        <button class="btn btn-outline-secondary" type="submit">Filtern</button>
        <a class="btn btn-outline-secondary" href="<?php echo hurl(url('/companies/show.php') . '?id=' . $company['id']) ?>">Reset (Standard)</a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead><tr><th>Titel</th><th>Status</th><th>Stundensatz (effektiv)</th><th class="text-end">Aktionen</th></tr></thead>
        <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td><?php echo h($p['title']) ?></td>
              <td><?php echo h($p['status']) ?></td>
              <td>€ <?php echo h(number_format((float)$p['effective_rate'], 2, ',', '.')) ?><?php echo $p['project_rate'] === null ? ' <small class="text-muted">(von Firma)</small>' : '' ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo url('/companies/projects_edit.php') ?>?id=<?php echo $p['id'] ?>">Bearbeiten</a>
                <form method="post" action="<?php echo url('/projects/delete.php') ?>" class="d-inline" onsubmit="return confirm('Projekt wirklich löschen?');">
                  <?php echo csrf_field() ?>
                  <input type="hidden" name="id" value="<?php echo $p['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Löschen</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (! $projects): ?>
            <tr><td colspan="4" class="text-center text-muted">Keine Projekte mit diesem Filter.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="mt-2 d-flex justify-content-end">
      <?php echo render_pagination_named_keep(url('/companies/show.php'), 'proj_page', $proj_page, $projects_pages, $keep_projects) ?>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
     <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="card-title mb-0">Aufgaben</h5>
      <a class="btn btn-sm btn-primary" href="<?php echo url('/tasks/new.php') ?>?company_id=<?php echo $company['id'] ?>">Neu</a>
    </div>
    <form class="row g-2 mb-3" method="get" action="<?php echo hurl(url('/companies/show.php')) ?>">
  <input type="hidden" name="id" value="<?php echo $company['id'] ?>">
  <input type="hidden" name="tsf" value="1">
  <!-- <input type="hidden" name="psf" value="1"> -->
  <?php foreach ($proj_status as $st): ?>
    <input type="hidden" name="proj_status[]" value="<?php echo h($st) ?>">
  <?php endforeach; ?>
  <div class="col-md-8">
    <label class="form-label">Aufgaben-Status</label>
    <div class="d-flex flex-wrap gap-3">
      <?php
        // --- Aufgabenstatus-Filter (Default: offen + warten) ---
        $allowed_task_status = ['offen', 'warten', 'angeboten', 'abgeschlossen'];
        $task_status         = [];
        $has_tsf             = isset($_GET['tsf']);

        if (isset($_GET['task_status']) && is_array($_GET['task_status'])) {
          foreach ($_GET['task_status'] as $st) {
            if (! is_string($st)) {
              continue;
            }
            // NEU
            $st = strtolower(trim($st));
            if (in_array($st, $allowed_task_status, true)) {
              $task_status[] = $st;
            }
          }
        }
        if (! $has_tsf) {
          $task_status = ['offen', 'warten'];
        }
        // Filter-Params für Pagination beibehalten
        // $keep für Pagination (immer ID + Aufgaben-Filter mitgeben)
        $keep_tasks                = $keep_projects; // proj_status & psf mitnehmen
        $keep_tasks['tsf']         = 1;
        $keep_tasks['task_status'] = $task_status;

        // WHERE/Parameter für Task-Filter
        $whereT  = ['t.account_id = :acc', 'p.company_id = :cid'];
        $paramsT = [':acc' => $account_id, ':cid' => $id];
        if ($task_status) {
          $inT = [];
          foreach ($task_status as $i => $st) {
            $key           = ':tst' . $i;
            $inT[]         = $key;
            $paramsT[$key] = $st;
          }
          $whereT[] = 't.status IN (' . implode(',', $inT) . ')';
        }
        $WHERET = implode(' AND ', $whereT);
        // Counts aktualisieren
        $tcnt = $pdo->prepare("SELECT COUNT(*)
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  WHERE $WHERET");
        foreach ($paramsT as $k => $v) {$tcnt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);}
        $tcnt->execute();
        $tasks_total = (int)$tcnt->fetchColumn();
        $tasks_pages = max(1, (int)ceil($tasks_total / $task_per_page));
        // Tasks neu laden
        $sqlTasks = "SELECT
    t.id AS task_id, t.description, t.deadline, t.planned_minutes, t.priority, t.status,
    p.id AS project_id, p.title AS project_title,
    COALESCE((
      SELECT SUM(tt.minutes) FROM times tt
      WHERE tt.account_id = t.account_id AND tt.user_id = :uid AND tt.task_id = t.id AND tt.minutes IS NOT NULL
    ), 0) AS spent_minutes,
    og.position AS sort_pos
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  LEFT JOIN task_ordering_global og ON og.account_id = t.account_id AND og.task_id = t.id
  WHERE $WHERET
  ORDER BY (og.position IS NULL), og.position, p.title, t.deadline IS NULL, t.deadline, t.id DESC
  LIMIT :limit OFFSET :offset";
        $st = $pdo->prepare($sqlTasks);
        foreach ($paramsT as $k => $v) {$st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);}
        $st->bindValue(':uid', $user_id, PDO::PARAM_INT);
        $st->bindValue(':limit', $task_per_page, PDO::PARAM_INT);
        $st->bindValue(':offset', $task_offset, PDO::PARAM_INT);
        $st->execute();
        $tasks = $st->fetchAll();
      ?>

      <?php foreach ($allowed_task_status as $opt): ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="task_status[]" id="ts-<?php echo h($opt) ?>" value="<?php echo h($opt) ?>" <?php echo in_array($opt, $task_status, true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="ts-<?php echo h($opt) ?>"><?php echo h($opt) ?></label>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="form-text">Standard: „offen“ und „warten“.</div>
  </div>
  <div class="col-md-4 d-flex align-items-end justify-content-end gap-2">

    <a class="btn btn-outline-secondary" href="<?php echo hurl(url('/companies/show.php') . '?id=' . $company['id']) ?>">Reset (Standard)</a>
  </div>
</form>
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Projekt</th>
            <th>Aufgabe</th>
            <th>Priorität</th>
            <th>Status</th>
            <th>Deadline</th>
            <th>Geschätzt</th>
            <th>Aufgelaufene Zeit</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tasks as $r): ?>
            <?php
              $planned = $r['planned_minutes'] !== null ? (int)$r['planned_minutes'] : 0;
              $total   = (int)$r['spent_minutes'] + (($has_running && $running_task_id == $r['task_id']) ? $running_extra : 0);
              $badge   = '';
              if ($planned > 0) {
                $ratio = $total / $planned;
                if ($ratio >= 1.0) {
                  $badge = 'badge bg-danger';
                } elseif ($ratio >= 0.8) {
                  $badge = 'badge bg-warning text-dark';
                } else {
                  $badge = 'badge bg-success';
                }

              }
            ?>
            <tr>
              <td><?php echo h($r['project_title']) ?></td>
              <td><?php echo h($r['description']) ?></td>
              <td><?php echo h($r['priority'] ?? '—') ?></td>
              <td><?php echo h($r['status'] ?? '—') ?></td>
              <td><?php echo $r['deadline'] ? h($r['deadline']) : '—' ?></td>
              <td><?php echo $planned ? fmt_minutes($planned) : '—' ?></td>
              <td>
                <?php if ($badge): ?>
                  <span class="<?php echo $badge ?>"><?php echo fmt_minutes($total) ?></span>
                <?php else: ?>
                  <?php echo fmt_minutes($total) ?>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo url('/tasks/edit.php') ?>?id=<?php echo $r['task_id'] ?>&return_to=<?php echo urlencode($_SERVER['REQUEST_URI']) ?>">Bearbeiten</a>
                <form method="post" action="<?php echo url('/tasks/delete.php') ?>" class="d-inline" onsubmit="return confirm('Aufgabe wirklich löschen?');">
                  <?php echo csrf_field() ?>
                  <input type="hidden" name="id" value="<?php echo $r['task_id'] ?>">
                  <input type="hidden" name="return_to" value="<?php echo ($_SERVER['REQUEST_URI']) ?>">
                  <button class="btn btn-sm btn-outline-danger">Löschen</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (! $tasks): ?>
            <tr><td colspan="7" class="text-center text-muted">Keine offenen Aufgaben.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="p-2 d-flex justify-content-end">
      <?php echo render_pagination_named_keep(url('/companies/show.php'), 'task_page', $task_page, $tasks_pages, $keep_tasks) ?>
    </div>
  </div>
</div>
<script>
/**
 * On-place Reload für Aufgaben- und Projekt-Card.
 * - Auto-Filter onChange (Checkboxen)
 * - Paginierung abfangen (task_page= / proj_page=)
 * - "Reset (Standard)"-Links abfangen (on place neu laden)
 * - fetch() lädt die Seite im Hintergrund und ersetzt nur die betroffene Card
 * - Kein Scroll nach oben; URL wird via history.replaceState aktualisiert
 */
(function () {
  function getFormByFilterField(root, fieldName) {
    var input = root.querySelector('form input[name="' + fieldName + '"]');
    return input ? input.form : null;
  }
  function getCardForForm(form) {
    return form ? form.closest('.card') : null;
  }

  function isResetLink(href, pageParam) {
    if (!href) return false;
    // Reset zeigt auf companies/show.php?id=... ohne Filter-/Page-Parameter
    var isShow = href.indexOf('/companies/show.php') !== -1;
    var hasPage = href.indexOf(pageParam + '=') !== -1;
    var hasTaskFlag = href.indexOf('tsf=') !== -1;
    var hasProjFlag = href.indexOf('psf=') !== -1;
    return isShow && !hasPage && !hasTaskFlag && !hasProjFlag;
  }

  function bindCard(fieldName, pageParam) {
    var form = getFormByFilterField(document, fieldName);
    var card = getCardForForm(form);
    if (!form || !card) return;

    // "Filtern"-Button ausblenden (wir filtern onChange)
    var submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.style.display = 'none';

    // Auto-Submit bei Änderung einer Checkbox in diesem Formular
    form.addEventListener('change', function (e) {
      if (e.target && e.target.name === fieldName) {
        if (form.requestSubmit) form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });

    // Submit abfangen und on-place nachladen
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var params = new URLSearchParams(new FormData(form));
      var url = (form.action || window.location.pathname).split('#')[0] + '?' + params.toString();
      fetchAndSwap(url, fieldName);
    });

    // Paginierung & Reset-Links in der jeweiligen Card abfangen
    card.addEventListener('click', function (e) {
      var a = e.target && e.target.closest('a');
      if (!a) return;
      var href = a.getAttribute('href') || '';

      // Nur eigene Paginierung oder der Reset-Link dieser Card
      if (href.indexOf(pageParam + '=') !== -1 || isResetLink(href, pageParam)) {
        e.preventDefault();
        fetchAndSwap(href, fieldName);
      }
    });
  }

  function fetchAndSwap(url, fieldName) {
    fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var newForm = getFormByFilterField(doc, fieldName);
        var newCard = getCardForForm(newForm);
        if (!newCard) return;

        var curForm = getFormByFilterField(document, fieldName);
        var curCard = getCardForForm(curForm);
        if (!curCard) return;

        curCard.replaceWith(newCard);

        // URL aktualisieren (ohne Scroll)
        try { history.replaceState(null, '', url); } catch (e) {}

        // Rebind nur für diese Card
        setTimeout(function () {
          var onlyThisForm = getFormByFilterField(document, fieldName);
          var onlyThisCard = getCardForForm(onlyThisForm);
          if (!onlyThisForm || !onlyThisCard) return;

          var submitBtn = onlyThisForm.querySelector('button[type="submit"]');
          if (submitBtn) submitBtn.style.display = 'none';

          // OnChange erneut binden
          onlyThisForm.addEventListener('change', function (e) {
            if (e.target && e.target.name === fieldName) {
              if (onlyThisForm.requestSubmit) onlyThisForm.requestSubmit();
              else onlyThisForm.dispatchEvent(new Event('submit', { cancelable: true }));
            }
          });

          // Submit erneut binden
          onlyThisForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var params = new URLSearchParams(new FormData(onlyThisForm));
            var url = (onlyThisForm.action || window.location.pathname).split('#')[0] + '?' + params.toString();
            fetchAndSwap(url, fieldName);
          });

          // Pagination/Reset erneut binden
          onlyThisCard.addEventListener('click', function (e) {
            var a = e.target && e.target.closest('a');
            if (!a) return;
            var href = a.getAttribute('href') || '';
            var pageParam = (fieldName === 'task_status[]') ? 'task_page' : 'proj_page';
            if (href.indexOf(pageParam + '=') !== -1 || isResetLink(href, pageParam)) {
              e.preventDefault();
              fetchAndSwap(href, fieldName);
            }
          });
        }, 0);
      })
      .catch(function (err) {
        console && console.error && console.error('Reload fehlgeschlagen:', err);
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Aufgaben
    bindCard('task_status[]', 'task_page');
    // Projekte
    bindCard('proj_status[]', 'proj_page');
  });
})();
</script>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>