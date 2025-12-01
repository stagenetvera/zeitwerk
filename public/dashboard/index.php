<?php
// public/dashboard/index_filters_v2.php
require __DIR__ . '/../../src/layout/header.php';
require_once __DIR__ . '/../../src/lib/settings.php';
require_login();

$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

// ---------- helpers ----------
function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ---------- input ----------
$company_id = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : 0;
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : 0;
$prio_raw   = $_GET['priority'] ?? ($_GET['priorities'] ?? null);
$prio_selected = [];
$prio_default  = ['low','medium','high'];
if (is_array($prio_raw)) {
  foreach ($prio_raw as $pr) {
    if (!is_string($pr)) continue;
    $pr = trim($pr);
    if ($pr !== '') $prio_selected[] = $pr;
  }
} elseif (is_string($prio_raw) && $prio_raw !== '') {
  $prio_selected = [trim($prio_raw)];
}
if (!$prio_selected) {
  $prio_selected = $prio_default;
}

$status_allowed  = ['angeboten','offen','warten'];
$status_selected = [];
if (isset($_GET['status']) && is_array($_GET['status'])) {
  foreach ($_GET['status'] as $st) {
    if (!is_string($st)) continue;
    $st = strtolower(trim($st));
    if (in_array($st, $status_allowed, true)) $status_selected[] = $st;
  }
}
if (!$status_selected) {
  $status_selected = ['offen']; // Default
}
$status_include_null = in_array('offen', $status_selected, true);

$has_filters = ($company_id !== 0)
  || ($project_id !== 0)
  || ($status_selected !== ['offen'])
  || (count(array_diff($prio_selected, $prio_default)) > 0 || count(array_diff($prio_default, $prio_selected)) > 0);
// ---------- filter options (nur mit tatsächlich vorhandenen Aufgaben) ----------

// Firmen: aus Aufgaben ableiten (Status-/Priority-Filter wie unten), aber OHNE company-Filter.
// Wenn ein Projekt gewählt ist, wird automatisch auf dessen Firma eingeschränkt.
$c_where = [ 'ta.account_id = :acc' ];
$c_params = [':acc' => $account_id];
if ($prio_selected) {
  $in = [];
  foreach ($prio_selected as $i => $pr) {
    $key = ':cprio'.$i;
    $in[] = $key;
    $c_params[$key] = $pr;
  }
  $c_where[] = 'ta.priority IN ('.implode(',', $in).')';
}
if ($project_id)  { $c_where[] = 'p.id = :pid';        $c_params[':pid']  = $project_id; }
if ($status_selected) {
  $in = [];
  foreach ($status_selected as $i => $st) {
    $key = ':cst'.$i;
    $in[] = $key;
    $c_params[$key] = $st;
  }
  $cond = 'ta.status IN ('.implode(',', $in).')';
  if ($status_include_null) { $cond = '(' . $cond . ' OR ta.status IS NULL)'; }
  $c_where[] = $cond;
}

$c_sql = "
  SELECT DISTINCT c.id, c.name
  FROM tasks ta
  JOIN projects p ON p.id = ta.project_id AND p.account_id = ta.account_id
  JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
  WHERE " . implode(' AND ', $c_where) . "
  ORDER BY c.name
";
$cstmt = $pdo->prepare($c_sql);
foreach ($c_params as $k=>$v){ $cstmt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$cstmt->execute();
$companies = $cstmt->fetchAll();

// Projekte: nur laden, wenn Firma gewählt – dann aber ebenfalls nur Projekte mit mindestens einer passenden Aufgabe.
$projects = [];
if ($company_id) {
  $p_where = [
    'ta.account_id = :acc',
    'c.id = :cid',
  ];
  $p_params = [':acc'=>$account_id, ':cid'=>$company_id];
  if ($prio_selected) {
    $in = [];
    foreach ($prio_selected as $i => $pr) {
      $key = ':pprio'.$i;
      $in[] = $key;
      $p_params[$key] = $pr;
    }
    $p_where[] = 'ta.priority IN ('.implode(',', $in).')';
  }
  if ($status_selected) {
    $in = [];
    foreach ($status_selected as $i => $st) {
      $key = ':pst'.$i;
      $in[] = $key;
      $p_params[$key] = $st;
    }
    $cond = 'ta.status IN ('.implode(',', $in).')';
    if ($status_include_null) { $cond = '(' . $cond . ' OR ta.status IS NULL)'; }
    $p_where[] = $cond;
  }

  $p_sql = "
    SELECT DISTINCT p.id, p.title
    FROM tasks ta
    JOIN projects p ON p.id = ta.project_id AND p.account_id = ta.account_id
    JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
    WHERE " . implode(' AND ', $p_where) . "
    ORDER BY p.title
  ";
  $pstmt = $pdo->prepare($p_sql);
  foreach ($p_params as $k=>$v){ $pstmt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
  $pstmt->execute();
  $projects = $pstmt->fetchAll();
}

// Prioritäten: unverändert (falls gewünscht, kann man analog auch nach aktuellem Filter schneiden)
$prstmt = $pdo->prepare('SELECT DISTINCT priority FROM tasks WHERE account_id = ? ORDER BY priority');
$prstmt->execute([$account_id]);
$priorities = array_values(array_filter(array_map(function($r){ return $r['priority']; }, $prstmt->fetchAll())));

// ---------- query tasks ----------
$where = ['ta.account_id = :acc'];
$params = [':acc'=>$account_id];
$status_cond = '';
if ($status_selected) {
  $in = [];
  foreach ($status_selected as $i => $st) {
    $key = ':st'.$i;
    $in[] = $key;
    $params[$key] = $st;
  }
  $status_cond = 'ta.status IN ('.implode(',', $in).')';
  if ($status_include_null) { $status_cond = '(' . $status_cond . ' OR ta.status IS NULL)'; }
  $where[] = $status_cond;
}

if ($company_id) { $where[] = 'c.id = :cid';  $params[':cid']  = $company_id; }
if ($project_id) { $where[] = 'p.id = :pid';  $params[':pid']  = $project_id; }
if ($prio_selected){
  $in = [];
  foreach ($prio_selected as $i => $pr) {
    $key = ':prio'.$i;
    $in[] = $key;
    $params[$key] = $pr;
  }
  $where[] = 'ta.priority IN ('.implode(',', $in).')';
}

$WHERE = implode(' AND ', $where);

$sql = "SELECT
    ta.id AS task_id,
    ta.description,
    ta.priority,
    ta.deadline,
    ta.status,
    ta.planned_minutes,
    ta.billing_mode,
    ta.fixed_price_cents,
    p.id    AS project_id,
    p.title AS project_title,
    c.id    AS company_id,
    c.name  AS company_name,
    COALESCE(tsum.sum_minutes, 0) AS spent_minutes,
    COALESCE(p.hourly_rate, 0) AS effective_rate
  FROM tasks ta
  JOIN projects p ON p.id = ta.project_id AND p.account_id = ta.account_id
  JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
  LEFT JOIN (
    SELECT task_id, SUM(minutes) AS sum_minutes
    FROM times
    WHERE account_id = :acc AND minutes IS NOT NULL
    GROUP BY task_id
  ) tsum ON tsum.task_id = ta.id
  LEFT JOIN task_ordering_global og ON og.account_id = ta.account_id AND og.task_id = ta.id
  WHERE $WHERE
  ORDER BY (og.position IS NULL), og.position,
           (ta.deadline IS NULL), ta.deadline ASC, ta.id DESC";

$st = $pdo->prepare($sql);
foreach ($params as $k=>$v){
  $st->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
}
$st->execute();
$rows = $st->fetchAll();

$base = url('/dashboard/index.php');

function qs_keep($base, $keep, $extra = []){
  $q = array_merge($keep, $extra);
  return htmlspecialchars($base.'?'.http_build_query($q), ENT_QUOTES, 'UTF-8');
}
$keep = [
  'company_id'=>$company_id?:'',
  'project_id'=>$project_id?:'',
  'priority'=>$prio_selected,
  'status'=>$status_selected,
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Dashboard – Aufgaben</h3>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2" method="get" action="<?=hurl($base)?>" id="dashFilter">
      <div class="col-md-4">
        <label class="form-label">Firma</label>
        <select name="company_id" class="form-select" onchange="document.getElementById('dashFilter').submit()">
          <option value="">– alle –</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?=$c['id']?>" <?=$company_id===$c['id']?'selected':''?>><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>

        <label class="form-label">Projekt</label>
        <select name="project_id" class="form-select" <?=$company_id ? '' : 'disabled'?> onchange="document.getElementById('dashFilter').submit()">
          <option value="">– alle –</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?=$p['id']?>" <?=$project_id===$p['id']?'selected':''?>><?=h($p['title'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label mb-1">Priorität</label>
        <div class="d-flex flex-wrap gap-3">
          <?php foreach ($priorities as $pr): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" id="pr-<?=h($pr)?>" name="priority[]" value="<?=h($pr)?>"
                     <?= in_array($pr, $prio_selected, true) ? 'checked' : '' ?>
                     onchange="document.getElementById('dashFilter').submit()">
              <label class="form-check-label" for="pr-<?=h($pr)?>"><?=h($pr)?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Standard: low, medium, high.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label mb-1">Status</label>
        <div class="d-flex flex-wrap gap-3">
          <?php
            $status_labels = [
              'angeboten' => 'angeboten',
              'offen'     => 'offen',
              'warten'    => 'warten',
            ];
          ?>
          <?php foreach ($status_labels as $key => $label): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" id="st-<?=$key?>" name="status[]" value="<?=$key?>"
                     <?= in_array($key, $status_selected, true) ? 'checked' : '' ?>
                     onchange="document.getElementById('dashFilter').submit()">
              <label class="form-check-label" for="st-<?=$key?>"><?=h($label)?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Standard: „offen“.</div>
      </div>
      <div class="col-md-12 d-flex justify-content-end">
        <a href="<?=hurl($base)?>" class="btn btn-outline-secondary">Reset</a>
      </div>
    </form>

    <!-- Unsichtbares Formular nur für den CSRF-Token -->
    <form id="dndCsrf" class="d-none">
      <?= csrf_field() ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <?php
      $show_company = true;
      $tasks = $rows;

      $runStmt = $pdo->prepare('
        SELECT id, task_id
        FROM times
        WHERE account_id = ? AND user_id = ? AND ended_at IS NULL
        ORDER BY id DESC
        LIMIT 1
      ');
      $runStmt->execute([$account_id, $user_id]);
      $running = $runStmt->fetch();

      $has_running      = (bool)$running;
      $running_task_id  = $running && $running['task_id'] ? (int)$running['task_id'] : 0;
      $running_time_id  = $running ? (int)$running['id'] : 0;
      $table_body_id = 'dashTaskBody';
      $is_sortable = !$has_filters;
      $settings = get_account_settings($pdo, $account_id);
      $progress_warn_pct  = (float)($settings['task_progress_warn_pct']  ?? 90.0);
      $progress_alert_pct = (float)($settings['task_progress_alert_pct'] ?? 100.0);

      require __DIR__ . '/../tasks/_tasks_table.php';
    ?>

    <script>
      (function(){
        var sortable = <?= $is_sortable ? 'true' : 'false' ?>;
        if (!sortable) {
          // Handles explizit entschärfen (falls das Partial sie trotzdem zeigt)
          var tb = document.getElementById('dashTaskBody');
          if (tb) {
            tb.querySelectorAll('.drag-handle').forEach(function(h){
              h.removeAttribute('draggable');
            });
          }
          return; // keine DnD-Events registrieren
        }
        var tbody = document.getElementById('dashTaskBody');
        if (!tbody) return;

        tbody.querySelectorAll('.drag-handle').forEach(function(h){
          if (!h.hasAttribute('draggable')) h.setAttribute('draggable','true');
        });

        function getCsrf() {
          var m = document.querySelector('meta[name="csrf-token"]');
          if (m && m.content) return m.content;
          var i = document.querySelector('input[name="csrf_token"]');
          return i ? i.value : '';
        }

        var dragEl = null;

        tbody.addEventListener('dragstart', function(e){
          var tr = e.target.closest('tr[data-task-id]');
          if (!tr) return;
          dragEl = tr;
          tr.classList.add('dragging');
          if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', tr.dataset.taskId); } catch(_) {}
          }
        }, true);

        tbody.addEventListener('dragend', function(){
          if (dragEl) dragEl.classList.remove('dragging');
          dragEl = null;
        });

        tbody.addEventListener('dragover', function(e){
          e.preventDefault();
          if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
          var tr = e.target.closest('tr[data-task-id]');
          if (!tr || tr === dragEl) return;
          var rect = tr.getBoundingClientRect();
          var after = (e.clientY - rect.top) > (rect.height / 2);
          tbody.insertBefore(dragEl, after ? tr.nextSibling : tr);
        });

        tbody.addEventListener('drop', function(e){
          e.preventDefault();
          saveOrder();
        });

        function saveOrder(){
          var order = Array.from(tbody.querySelectorAll('tr[data-task-id]'))
            .map(function(tr){ return tr.dataset.taskId; });

          var fd = new FormData(document.getElementById('dndCsrf'));
          order.forEach(function(id){ fd.append('order[]', id); });

          fetch('<?=url('/tasks/order_save_global.php')?>', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
          })
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j || !j.ok) console.error('Order-Save fehlgeschlagen', j);
          })
          .catch(function(err){
            console.error('Order-Save Fehler', err);
          });
        }
      })();
    </script>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
