<?php
// public/times/index_filtered_v3_with_newbtn.php
require __DIR__ . '/../../src/layout/header.php';
require_login();

$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

// We'll re-use the latest guard-list if present to avoid duplication.
// For simplicity here, we inline the filter query similar to index_filtered_v3_with_delete_guard.php

function page_int($v, $d=1){ $n=(int)$v; return $n>0?$n:$d; }
function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function render_pagination_keep($base, $params, $page, $pages){
  if ($pages<=1) return '';
  $qs = function($extra) use ($params){
    $all = array_merge($params, $extra);
    return http_build_query($all);
  };
  $prev = max(1,$page-1); $next=min($pages,$page+1);
  ob_start(); ?>
  <nav><ul class="pagination mb-0">
    <li class="page-item <?= $page===1?'disabled':'' ?>"><a class="page-link" href="<?=hurl($base.'?'.$qs(['page'=>1]))?>">&laquo;</a></li>
    <li class="page-item <?= $page===1?'disabled':'' ?>"><a class="page-link" href="<?=hurl($base.'?'.$qs(['page'=>$prev]))?>">&lsaquo;</a></li>
    <?php $s=max(1,$page-2); $e=min($pages,$page+2); for($i=$s;$i<=$e;$i++): ?>
      <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="<?=hurl($base.'?'.$qs(['page'=>$i]))?>"><?=$i?></a></li>
    <?php endfor; ?>
    <li class="page-item <?= $page===$pages?'disabled':'' ?>"><a class="page-link" href="<?=hurl($base.'?'.$qs(['page'=>$next]))?>">&rsaquo;</a></li>
    <li class="page-item <?= $page===$pages?'disabled':'' ?>"><a class="page-link" href="<?=hurl($base.'?'.$qs(['page'=>$pages]))?>">&raquo;</a></li>
  </ul></nav>
  <?php return ob_get_clean();
}

// Input
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$start = $_GET['start'] ?? $today;
$end   = $_GET['end']   ?? $today;
$company_id = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : 0;
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : 0;
$task_id    = isset($_GET['task_id'])    && $_GET['task_id']    !== '' ? (int)$_GET['task_id']    : 0;
$billable_filter = isset($_GET['billable']) && ($_GET['billable'] === '1' || $_GET['billable'] === '0') ? $_GET['billable'] : '';

// Status-Filter (Checkboxen): wenn leer -> alle
$STATUS_WHITELIST = ['offen','in_abrechnung','abgerechnet'];
$statuses = $_GET['status'] ?? [];
if (!is_array($statuses)) $statuses = [$statuses];
$statuses = array_values(array_intersect($STATUS_WHITELIST, array_map('strval', $statuses)));

$valid_date = function($d){
  if (!preg_match('~^\\d{4}-\\d{2}-\\d{2}$~', $d)) return false;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if (!$valid_date($start)) $start = $today;
if (!$valid_date($end))   $end   = $start;
if ($end < $start) { $tmp=$start; $start=$end; $end=$tmp; }

$start_dt = $start.' 00:00:00';
$end_dt   = $end.' 23:59:59';

$per_page = 20;
$page = page_int($_GET['page'] ?? 1);
$offset = ($page-1)*$per_page;

// options
$cstmt = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cstmt->execute([$account_id]);
$companies = $cstmt->fetchAll();

$projects = [];
if ($company_id) {
  $pstmt = $pdo->prepare('SELECT id, title FROM projects WHERE account_id = ? AND company_id = ? ORDER BY title');
  $pstmt->execute([$account_id, $company_id]);
  $projects = $pstmt->fetchAll();
}

$tasks = [];
if ($project_id) {
  $tstmt = $pdo->prepare('SELECT id, description FROM tasks WHERE account_id = ? AND project_id = ? ORDER BY description');
  $tstmt->execute([$account_id, $project_id]);
  $tasks = $tstmt->fetchAll();
}

// where
$where=[]; $params=[
  ':acc'=>$account_id, ':uid'=>$user_id, ':end_dt'=>$end_dt, ':start_dt'=>$start_dt
];
$where[]='t.account_id = :acc';
$where[]='t.user_id = :uid';
$where[]='t.started_at <= :end_dt';
$where[]='(t.ended_at IS NULL OR t.ended_at >= :start_dt)';
if ($company_id){ $where[]='c.id = :cid'; $params[':cid']=$company_id; }
if ($project_id){ $where[]='p.id = :pid'; $params[':pid']=$project_id; }
if ($task_id){ $where[]='t.task_id = :tid'; $params[':tid']=$task_id; }
if ($billable_filter==='1'){ $where[]='t.billable = 1'; }
elseif ($billable_filter==='0'){ $where[]='(t.billable = 0 OR t.billable IS NULL)'; }

// Status-Checkboxen → IN(...) bauen (named placeholders :st0, :st1, …)
if (!empty($statuses)) {
  $ph = [];
  foreach ($statuses as $i => $stval) {
    $name = ":st{$i}";
    $ph[] = $name;
    $params[$name] = $stval;
  }
  $where[] = 't.status IN ('.implode(',', $ph).')';
}

$WHERE = implode(' AND ', $where);

// count
$cnt = $pdo->prepare("SELECT COUNT(*)
  FROM times t
  LEFT JOIN tasks ta ON ta.id = t.task_id AND ta.account_id = t.account_id
  LEFT JOIN projects p ON p.id = ta.project_id AND p.account_id = t.account_id
  LEFT JOIN companies c ON c.id = p.company_id AND c.account_id = t.account_id
  WHERE $WHERE");
foreach($params as $k=>$v){ $cnt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$cnt->execute();
$total=(int)$cnt->fetchColumn();
$pages=max(1,(int)ceil($total/$per_page)); if($page>$pages){$page=$pages;$offset=($page-1)*$per_page;}

// fetch
$sql = "SELECT t.id, t.task_id, t.started_at, t.ended_at, t.minutes, t.billable, t.status,
               ta.description AS task_desc, p.title AS project_title, c.name AS company_name
        FROM times t
        LEFT JOIN tasks ta ON ta.id = t.task_id AND ta.account_id = t.account_id
        LEFT JOIN projects p ON p.id = ta.project_id AND p.account_id = t.account_id
        LEFT JOIN companies c ON c.id = p.company_id AND c.account_id = t.account_id
        WHERE $WHERE
        ORDER BY t.started_at DESC, t.id DESC
        LIMIT :limit OFFSET :offset";
$st = $pdo->prepare($sql);
foreach($params as $k=>$v){ $st->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$st->bindValue(':limit',$per_page,PDO::PARAM_INT);
$st->bindValue(':offset',$offset,PDO::PARAM_INT);
$st->execute();
$rows=$st->fetchAll();

$sumStmt = $pdo->prepare("SELECT COALESCE(SUM(t.minutes),0)
  FROM times t
  LEFT JOIN tasks ta ON ta.id = t.task_id AND ta.account_id = t.account_id
  LEFT JOIN projects p ON p.id = ta.project_id AND p.account_id = t.account_id
  LEFT JOIN companies c ON c.id = p.company_id AND c.account_id = t.account_id
  WHERE $WHERE AND t.minutes IS NOT NULL");
foreach($params as $k=>$v){ $sumStmt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$sumStmt->execute();
$sum_minutes=(int)$sumStmt->fetchColumn();

$base = url('/times/index.php');
$persist = [
  'start'=>$start,'end'=>$end,
  'company_id'=>$company_id?:'',
  'project_id'=>$project_id?:'',
  'task_id'=>$task_id?:'',
  'billable'=>$billable_filter,
  // Status-Checkboxen persistieren
  'status'=>$statuses,
];
function qs($base,$arr){return htmlspecialchars($base.'?'.http_build_query($arr),ENT_QUOTES,'UTF-8');}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Zeiten</h3>
  <div class="d-flex gap-2">
    <a class="btn btn-primary" href="<?= h(with_return_to(url('/times/new.php'))) ?>">Neu</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?=qs($base, array_merge($persist, ['start'=>$today,'end'=>$today,'page'=>1]))?>">Heute</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?php
      $now = new DateTimeImmutable('today');
      $ws = $now->modify('monday this week')->format('Y-m-d');
      $we = $now->modify('sunday this week')->format('Y-m-d');
      echo qs($base, array_merge($persist, ['start'=>$ws,'end'=>$we,'page'=>1]));
    ?>">Diese Woche</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?php
      $ms = $now->modify('first day of this month')->format('Y-m-d');
      $me = $now->modify('last day of this month')->format('Y-m-d');
      echo qs($base, array_merge($persist, ['start'=>$ms,'end'=>$me,'page'=>1]));
    ?>">Dieser Monat</a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2" method="get" action="<?=hurl($base)?>" id="filterForm">
      <div class="col-auto">
        <label class="form-label">Von</label>
        <input type="date" name="start" class="form-control" value="<?=$start?>">
      </div>
      <div class="col-auto">
        <label class="form-label">Bis</label>
        <input type="date" name="end" class="form-control" value="<?=$end?>">
      </div>

      <div class="col-auto">
        <label class="form-label">Firma</label>
        <select name="company_id" class="form-select" onchange="document.getElementById('filterForm').submit()">
          <option value="">– alle –</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?=$c['id']?>" <?=$company_id===$c['id']?'selected':''?>><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-auto">
        <label class="form-label">Projekt</label>
        <select name="project_id" class="form-select" <?=$company_id ? '' : 'disabled'?> onchange="document.getElementById('filterForm').submit()">
          <option value="">– alle –</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?=$p['id']?>" <?=$project_id===$p['id']?'selected':''?>><?=h($p['title'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-auto">
        <label class="form-label">Aufgabe</label>
        <select name="task_id" class="form-select" <?=$project_id ? '' : 'disabled'?> onchange="document.getElementById('filterForm').submit()">
          <option value="">– alle –</option>
          <?php foreach ($tasks as $t): ?>
            <option value="<?=$t['id']?>" <?=$task_id===$t['id']?'selected':''?>><?=h($t['description'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-auto">
        <label class="form-label">Fakturierbarkeit</label>
        <select name="billable" class="form-select" onchange="document.getElementById('filterForm').submit()">
          <option value="" <?= $billable_filter===''?'selected':'' ?>>– alle –</option>
          <option value="1" <?= $billable_filter==='1'?'selected':'' ?>>fakturierbar</option>
          <option value="0" <?= $billable_filter==='0'?'selected':'' ?>>nicht fakturierbar</option>
        </select>
      </div>

      <!-- Status-Filter (Checkboxen) -->
      <div class="col-auto">
        <label class="form-label d-block">Status</label>
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <?php
            $labels = [
              'offen'          => 'offen',
              'in_abrechnung'  => 'in Abrechnung',
              'abgerechnet'    => 'abgerechnet',
            ];
            foreach ($labels as $val => $label):
              $checked = in_array($val, $statuses, true) ? 'checked' : '';
          ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" id="st-<?=$val?>" name="status[]" value="<?=$val?>" <?=$checked?> onchange="document.getElementById('filterForm').submit()">
              <label class="form-check-label" for="st-<?=$val?>"><?=$label?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Keine Auswahl = alle.</div>
      </div>

      <div class="col-auto align-self-end">
        <button class="btn btn-primary">Filtern</button>
      </div>
      <div class="col-auto align-self-end">
        <a class="btn btn-outline-secondary" href="<?=qs($base, ['start'=>$today,'end'=>$today,'page'=>1])?>">Reset</a>
      </div>

      <div class="col-auto align-self-end ms-auto">
        <div class="text-muted">Summe im Zeitraum: <strong><?=fmt_minutes($sum_minutes)?></strong></div>
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
            <th>Start</th>
            <th>Ende</th>
            <th>Dauer</th>
            <th>Firma / Projekt</th>
            <th>Aufgabe</th>
            <th>Fakturierbar</th>
            <th>Status</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="text-nowrap"><?=h($r['started_at'])?></td>
              <td class="text-nowrap"><?= $r['ended_at'] ? h($r['ended_at']) : '—' ?></td>
              <td><?= $r['minutes'] !== null ? fmt_minutes((int)$r['minutes']) : '—' ?></td>
              <td><?= h($r['company_name'] ?: '—') ?><?= $r['project_title'] ? ' / '.h($r['project_title']) : '' ?></td>
              <td><?= h($r['task_desc'] ?: '—') ?></td>
              <td><?= ($r['billable'] ?? 0) ? 'ja' : 'nein' ?></td>
              <td><?= h($r['status'] ?? '—') ?></td>
              <td class="text-nowrap text-end">
                <?php $locked = (isset($r['status']) && $r['status'] === 'abgerechnet'); ?>

                <?php if ($locked): ?>
                  <span class="badge bg-secondary">abgerechnet – gesperrt</span>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-secondary"
                    href="<?=url('/times/edit.php')?>?id=<?=$r['id']?>&return_to=<?=urlencode($_SERVER['REQUEST_URI'])?>">
                    <i class="bi bi-pencil"></i>
                    <span class="visually-hidden">Bearbeiten</span>
                  </a>
                  <form class="d-inline" method="post" action="<?=url('/times/delete.php')?>"
                        onsubmit="return confirm('Diesen Zeiteintrag wirklich löschen?');">
                    <?=csrf_field()?>
                    <input type="hidden" name="id" value="<?=$r['id']?>">
                    <input type="hidden" name="return_to" value="<?=h($_SERVER['REQUEST_URI'])?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i>
                    <span class="visually-hidden">Löschen</span></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted">Keine Zeiten für die gesetzten Filter.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="p-2 d-flex justify-content-end">
      <?= render_pagination_keep($base, array_merge($persist, []), $page, $pages) ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>