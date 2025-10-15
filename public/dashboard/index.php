<?php
// public/dashboard/index_filters_v2.php
require __DIR__ . '/../../src/layout/header.php';
require_login();

$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

// ---------- helpers ----------
function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_minutes($m){
  if ($m === null) return '—';
  $m = (int)$m; $h = intdiv($m,60); $r = $m%60;
  return $h>0 ? sprintf('%d:%02d h',$h,$r) : ($m.' min');
}


// ---------- input ----------
$company_id = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : 0;
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : 0;
$prio       = isset($_GET['priority']) ? trim($_GET['priority']) : '';

// ---------- filter options ----------
// companies
$cstmt = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cstmt->execute([$account_id]);
$companies = $cstmt->fetchAll();

// projects depend on company
$projects = [];
if ($company_id) {
  $pstmt = $pdo->prepare('SELECT id, title FROM projects WHERE account_id = ? AND company_id = ? ORDER BY title');
  $pstmt->execute([$account_id, $company_id]);
  $projects = $pstmt->fetchAll();
}

// distinct priorities (we don't assume fixed set; read what's there)
$prstmt = $pdo->prepare('SELECT DISTINCT priority FROM tasks WHERE account_id = ? ORDER BY priority');
$prstmt->execute([$account_id]);
$priorities = array_values(array_filter(array_map(function($r){ return $r['priority']; }, $prstmt->fetchAll())));

// ---------- query tasks ----------
$where = ['ta.account_id = :acc'];
$params = [':acc'=>$account_id];

$where[] = "(ta.status IS NULL OR ta.status NOT IN ('abgeschlossen','angeboten'))";

if ($company_id) { $where[] = 'c.id = :cid'; $params[':cid'] = $company_id; }
if ($project_id) { $where[] = 'p.id = :pid'; $params[':pid'] = $project_id; }
if ($prio !== '') { $where[] = 'ta.priority = :prio'; $params[':prio'] = $prio; }

$WHERE = implode(' AND ', $where);

$sql = "SELECT
    ta.id,
    ta.description,
    ta.priority,
    ta.deadline,
    ta.status,
    ta.planned_minutes,
    ta.planned_minutes,
    p.id   AS project_id,
    p.title AS project_title,
    c.id   AS company_id,
    c.name AS company_name,
    COALESCE(tsum.sum_minutes, 0) AS sum_minutes
  FROM tasks ta
  JOIN projects p ON p.id = ta.project_id AND p.account_id = ta.account_id
  JOIN companies c ON c.id = p.company_id AND c.account_id = p.account_id
  LEFT JOIN (
    SELECT task_id, SUM(minutes) AS sum_minutes
    FROM times
    WHERE account_id = :acc
    GROUP BY task_id
  ) tsum ON tsum.task_id = ta.id
  WHERE $WHERE
  ORDER BY
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
  'priority'=>$prio
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
      </div>
      <div class="col-md-4">
        <label class="form-label">Projekt</label>
        <select name="project_id" class="form-select" <?=$company_id ? '' : 'disabled'?> onchange="document.getElementById('dashFilter').submit()">
          <option value="">– alle –</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?=$p['id']?>" <?=$project_id===$p['id']?'selected':''?>><?=h($p['title'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Priorität</label>
        <select name="priority" class="form-select" onchange="document.getElementById('dashFilter').submit()">
          <option value="">– alle –</option>
          <?php foreach ($priorities as $pr): ?>
            <option value="<?=h($pr)?>" <?=$prio===$pr?'selected':''?>><?=h($pr)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <a href="<?=hurl($base)?>" class="btn btn-outline-secondary w-100">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Firma (Projekt)</th>
            <th>Aufgabe</th>
            <th>Priorität</th>
            <th>Deadline</th>
            <th>Geschätzt</th>
            <th>Aufgelaufene Zeit</th>

            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $sum = (int)($r['sum_minutes'] ?? 0);
              $est = $r['planned_minutes'] ?? ($r['planned_minutes'] ?? null);
              $badge = '';
              if ($est > 0) {
                $ratio = $sum / $est;
                if ($ratio >= 1.0) $badge = 'badge bg-danger';
                elseif ($ratio >= 0.8) $badge = 'badge bg-warning text-dark';
                else $badge = 'badge bg-success';
              }
            ?>
            <tr>
              <td><?=h($r['company_name'])?><?= $r['project_title'] ? ' ('.h($r['project_title']).')' : '' ?></td>
              <td><?=h($r['description'])?></td>
              <td><?=h($r['priority'] ?? '—')?></td>
              <td><?= $r['deadline'] ? h($r['deadline']) : '—' ?></td>
              <td><?= $est ? fmt_minutes($est) : '—' ?></td>
              <td>
                <?php if ($badge): ?>
                  <span class="<?=$badge?>"><?=fmt_minutes($sum)?></span>
                <?php else: ?>
                  <?=fmt_minutes($sum)?>
                <?php endif; ?>
              </td>

              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="<?=url('/tasks/edit.php')?>?id=<?=$r['id']?>&return_to=<?=urlencode($_SERVER['REQUEST_URI'])?>">Bearbeiten</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted">Keine Aufgaben nach diesen Filtern.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
