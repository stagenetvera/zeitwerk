<?php
// public/invoices/index.php
require __DIR__ . '/../../src/layout/header.php';
require_login();

$user       = auth_user();
$account_id = (int)$user['account_id'];

function page_int($v, $d=1){ $n=(int)$v; return $n>0?$n:$d; }
function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_money($v){ return number_format((float)$v, 2, ',', '.'); }

// --- Filter-Inputs ---
$company_id   = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : 0;
$allowed_stat = ['in_vorbereitung','gestellt','gemahnt','bezahlt','storniert'];
$isf          = isset($_GET['isf']); // Flag: Filter aktiv gesetzt?

$status = [];
if (isset($_GET['status']) && is_array($_GET['status'])) {
  foreach ($_GET['status'] as $st) {
    if (!is_string($st)) continue;
    $st = strtolower(trim($st));
    if (in_array($st, $allowed_stat, true)) $status[] = $st;
  }
}
// Default wie auf der Firmenseite
if (!$isf) $status = ['in_vorbereitung','gestellt','gemahnt'];

// Zeitraum (nach Rechnungsdatum = issue_date)
$start = $_GET['start'] ?? '';
$end   = $_GET['end']   ?? '';
$valid_date = function($d){
  if ($d === '') return true;
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $d)) return false;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if (!$valid_date($start)) $start = '';
if (!$valid_date($end))   $end   = '';
if ($start && $end && $end < $start) { $tmp=$start; $start=$end; $end=$tmp; }

// Pagination
$per_page = 10;
$page     = page_int($_GET['page'] ?? 1);
$offset   = ($page-1)*$per_page;

// Firmenliste für Filter
$cstmt = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cstmt->execute([$account_id]);
$companies = $cstmt->fetchAll();

// WHERE bauen
$where = ['i.account_id = :acc'];
$params = [':acc'=>$account_id];

if ($company_id) { $where[]='i.company_id = :cid'; $params[':cid']=$company_id; }
if ($status) {
  $in = [];
  foreach ($status as $i=>$st) { $key=':s'.$i; $in[]=$key; $params[$key]=$st; }
  $where[]='i.status IN ('.implode(',', $in).')';
}
if ($start !== '') { $where[]='i.issue_date >= :start'; $params[':start']=$start; }
if ($end   !== '') { $where[]='i.issue_date <= :end';   $params[':end']=$end; }

$WHERE = implode(' AND ', $where);

// Count
$cnt = $pdo->prepare("SELECT COUNT(*)
  FROM invoices i
  WHERE $WHERE");
foreach ($params as $k=>$v) { $cnt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$cnt->execute();
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total/$per_page));
if ($page>$pages){ $page=$pages; $offset=($page-1)*$per_page; }

// Fetch (jüngste zuerst – nach Rechnungsdatum; Fallback created_at)
$sql = "SELECT
          i.id, i.invoice_number,
          i.issue_date, i.due_date, i.status, i.total_net, i.total_gross,
          c.name AS company_name, c.id AS company_id
        FROM invoices i
        JOIN companies c ON c.id = i.company_id AND c.account_id = i.account_id
        WHERE $WHERE
        ORDER BY COALESCE(i.issue_date, i.created_at) DESC, i.id DESC
        LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) { $st->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$st->bindValue(':lim',$per_page,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

// Helper für Pagination mit persistierenden Filtern
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

$base   = url('/invoices/index.php');
$persist = [
  'isf'=>1,
  'company_id'=>$company_id?:'',
  'start'=>$start,
  'end'=>$end,
];
// Mehrfachauswahl Status persistieren
foreach ($status as $st) $persist['status[]'][] = $st;

?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Rechnungen</h3>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2" method="get" action="<?=hurl($base)?>">
      <input type="hidden" name="isf" value="1">
      <div class="col-md-4">
        <label class="form-label">Firma</label>
        <select name="company_id" class="form-select" onchange="this.form.submit()">
          <option value="">– alle –</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?=$c['id']?>" <?=$company_id===$c['id']?'selected':''?>><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Rechnungs-Status</label>
        <div class="d-flex flex-wrap gap-3">
          <?php foreach ($allowed_stat as $opt): ?>
            <div class="form-check">
              <input class="form-check-input"
                     type="checkbox"
                     name="status[]"
                     id="st-<?=h($opt)?>"
                     value="<?=h($opt)?>"
                     <?= in_array($opt, $status, true) ? 'checked' : '' ?>
                     onchange="this.form.submit()">
              <label class="form-check-label" for="st-<?=h($opt)?>"><?=h($opt)?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Standard: „in_vorbereitung“, „gestellt“, „gemahnt“.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Zeitraum (Rechnungsdatum)</label>
        <div class="d-flex gap-2">
          <input type="date" name="start" class="form-control" value="<?=h($start)?>" onchange="this.form.submit()">
          <input type="date" name="end"   class="form-control" value="<?=h($end)?>"   onchange="this.form.submit()">
        </div>
      </div>

      <div class="col-12 d-flex justify-content-end">
        <a class="btn btn-outline-secondary" href="<?=hurl($base)?>">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">

    <?php
    // Example: ensure your SELECT provides company fields when using the 'with_company' mode:
    // SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.status, i.total_net, i.total_gross,
    //        c.id AS company_id, c.name AS company_name
    // FROM invoices i
    // JOIN companies c ON c.id = i.company_id AND c.account_id = i.account_id
    // WHERE i.account_id = :acc
    // ... (filters, order, limit/offset)

    $invoice_table_mode = 'with_company';
    $empty_message = 'Keine Rechnungen gefunden.';
    require __DIR__ . '/_table.php';
    ?>
    <div class="p-2 d-flex justify-content-end">
      <?= render_pagination_keep($base, $persist, $page, $pages) ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>