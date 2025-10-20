<?php
// public/companies/index.php
require __DIR__ . '/../../src/layout/header.php';
require_login();

$user       = auth_user();
$account_id = (int)$user['account_id'];

// --- helpers ---
function page_int($v, $d=1){ $n=(int)$v; return $n>0?$n:$d; }
function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function render_pagination_keep($base, $params, $page, $pages){
  if ($pages<=1) return '';
  $qs = function($extra) use ($params){ return http_build_query(array_merge($params,$extra)); };
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

// --- input (Checkbox-Filter) ---
$allowed_status = ['aktiv','abgeschlossen'];
$statuses = [];
if (isset($_GET['status']) && is_array($_GET['status'])) {
  foreach ($_GET['status'] as $st) {
    if (in_array($st, $allowed_status, true)) $statuses[] = $st;
  }
}
if (!$statuses) { // Default
  $statuses = ['aktiv'];
}

$per_page = 20;
$page     = page_int($_GET['page'] ?? 1);
$offset   = ($page-1)*$per_page;

// --- query ---
$where  = ['account_id = :acc'];
$params = [':acc'=>$account_id];

if (count($statuses) === 1) {
  $where[]       = 'status = :st0';
  $params[':st0'] = $statuses[0];
} elseif (count($statuses) === 2) {
  // beide gewählt → IN (...), funktional wie „alle“ für diese zwei Zustände
  $where[] = 'status IN (:st0, :st1)';
  $params[':st0'] = $statuses[0];
  $params[':st1'] = $statuses[1];
}

$WHERE = implode(' AND ', $where);

// count
$cnt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE $WHERE");
foreach ($params as $k=>$v) { $cnt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$cnt->execute();
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total/$per_page));
if ($page > $pages) { $page=$pages; $offset=($page-1)*$per_page; }

// rows
$sql = "SELECT id, name, address, status, hourly_rate
        FROM companies
        WHERE $WHERE
        ORDER BY name
        LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) { $st->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$st->bindValue(':lim',$per_page,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

$base    = url('/companies/index.php');
// Für Pagination die Checkbox-Wahl mitnehmen:
$persist = [];
foreach ($statuses as $st) { $persist['status[]'][] = $st; }
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Firmen</h3>
  <form method="post" action="<?= url('/companies/new.php') ?>" class="mb-0">
    <?= csrf_field() ?>
    <?= return_to_hidden($_SERVER['REQUEST_URI'] ?? $base) ?>
    <button class="btn btn-primary">Neu</button>
  </form>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get" action="<?= hurl($base) ?>" id="companyFilter">
      <div class="col-md-10">
        <label class="form-label d-block">Status</label>
        <div class="d-flex flex-wrap gap-4">
          <?php foreach ($allowed_status as $opt): ?>
            <div class="form-check">
              <input class="form-check-input"
                     type="checkbox"
                     id="st-<?=h($opt)?>"
                     name="status[]"
                     value="<?=h($opt)?>"
                     <?= in_array($opt, $statuses, true) ? 'checked' : '' ?>
                     onchange="document.getElementById('companyFilter').submit()">
              <label class="form-check-label" for="st-<?=h($opt)?>"><?=h($opt)?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Standard: „aktiv“.</div>
      </div>
      <div class="col-md-2">
        <a class="btn btn-outline-secondary w-100" href="<?= hurl($base.'?'.http_build_query(['status[]'=>'aktiv'])) ?>">Reset</a>
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
            <th>Name</th>
            <th>Status</th>
            <th>Stundensatz</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <a class=""
                 href="<?= url('/companies/edit.php') ?>?id=<?= (int)$r['id'] ?>&return_to=/companies/index.php"
                 title="Bearbeiten" aria-label="Bearbeiten">
                    <?= h($r['name']) ?>
              </a>
            </td>
            <td><?= h($r['status']) ?></td>
            <td>€ <?= $r['hourly_rate'] !== null ? h(number_format((float)$r['hourly_rate'], 2, ',', '.')) : '—' ?></td>
            <td class="text-end">
              <!-- Ansehen -->
              <a class="btn btn-sm btn-outline-secondary btn-icon"
                 href="<?= url('/companies/show.php') ?>?id=<?= (int)$r['id'] ?>"
                 title="Ansehen" aria-label="Ansehen">
                <i class="bi bi-eye"></i>
                <span class="visually-hidden">Ansehen</span>
              </a>
              <!-- Bearbeiten -->
              <a class="btn btn-sm btn-outline-secondary btn-icon"
                 href="<?= url('/companies/edit.php') ?>?id=<?= (int)$r['id'] ?>&return_to=/companies/index.php"
                 title="Bearbeiten" aria-label="Bearbeiten">
                <i class="bi bi-pencil"></i>
                <span class="visually-hidden">Bearbeiten</span>
              </a>
              <!-- Löschen -->
              <form method="post" action="<?= url('/companies/delete.php') ?>" class="d-inline"
                    onsubmit="return confirm('Firma wirklich löschen?');">
                <?= csrf_field() ?>
                <?= return_to_hidden($_SERVER['REQUEST_URI'] ?? $base) ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger btn-icon" title="Löschen" aria-label="Löschen">
                  <i class="bi bi-trash"></i>
                  <span class="visually-hidden">Löschen</span>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="text-center text-muted">Keine Firmen für diesen Filter.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="p-2 d-flex justify-content-end">
      <?= render_pagination_keep($base, $persist, $page, $pages) ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>