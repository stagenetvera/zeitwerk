<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$account_id = (int)$user['account_id'];
$user_id = (int)$user['id'];

// pagination
$per_page = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// total count (this user's times)
$cnt = $pdo->prepare('SELECT COUNT(*) FROM times WHERE account_id = ? AND user_id = ?');
$cnt->execute([$account_id, $user_id]);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// fetch rows
$sql = "SELECT
          t.id,
          t.task_id,
          t.started_at,
          t.ended_at,
          t.minutes,
          t.billable,
          t.status,
          tk.description AS task_desc,
          p.title AS project_title,
          c.name  AS company_name
        FROM times t
        LEFT JOIN tasks tk ON tk.id = t.task_id AND tk.account_id = t.account_id
        LEFT JOIN projects p ON p.id = tk.project_id AND p.account_id = t.account_id
        LEFT JOIN companies c ON c.id = p.company_id AND c.account_id = t.account_id
        WHERE t.account_id = ? AND t.user_id = ?
        ORDER BY COALESCE(t.ended_at, t.started_at) DESC, t.id DESC
        LIMIT ? OFFSET ?";
$st = $pdo->prepare($sql);
$st->bindValue(1, $account_id, PDO::PARAM_INT);
$st->bindValue(2, $user_id, PDO::PARAM_INT);
$st->bindValue(3, $per_page, PDO::PARAM_INT);
$st->bindValue(4, $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

function fmt_dt($s) { return $s ? (new DateTime($s))->format('d.m.Y H:i') : '—'; }
function fmt_minutes($m) {
  if ($m === null) return '—';
  $m = (int)$m;
  $h = intdiv($m, 60);
  $r = $m % 60;
  if ($h > 0) return sprintf('%d:%02d h', $h, $r);
  return $m . ' min';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Zeiten</h3>

</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Beginn</th>
            <th>Ende</th>
            <th>Dauer</th>
            <th>Firma (Projekt)</th>
            <th>Aufgabe</th>
            <th>fakturierbar</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?=h(fmt_dt($r['started_at']))?></td>
              <td><?=h(fmt_dt($r['ended_at']))?></td>
              <td><?=h(fmt_minutes($r['minutes']))?></td>
              <td><?=h($r['company_name'] ?? '—')?><?php if($r['project_title']): ?> (<?=h($r['project_title'])?>)<?php endif; ?></td>
              <td><?=h($r['task_desc'] ?? '—')?></td>
              <td><?= $r['billable'] ? 'Ja' : 'Nein' ?></td>
              <td><?=h($r['status'] ?? '—')?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-center text-muted">Keine Einträge.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex justify-content-end p-2">
      <?php
        // simple pagination
        $base = url('/times/index.php') . '?page';
        // render
        echo '<nav><ul class="pagination mb-0">';
        $prev = max(1, $page-1);
        $next = min($pages, $page+1);
        $disabledPrev = $page===1 ? ' disabled' : '';
        $disabledNext = $page===$pages ? ' disabled' : '';
        echo '<li class="page-item'.$disabledPrev.'"><a class="page-link" href="'.h($base).'=1">&laquo;</a></li>';
        echo '<li class="page-item'.$disabledPrev.'"><a class="page-link" href="'.h($base).'='.$prev.'">&lsaquo;</a></li>';
        $start = max(1, $page-2);
        $end = min($pages, $page+2);
        for ($i=$start; $i<=$end; $i++) {
          $active = $i===$page ? ' active' : '';
          echo '<li class="page-item'.$active.'"><a class="page-link" href="'.h($base).'='.$i.'">'.$i.'</a></li>';
        }
        echo '<li class="page-item'.$disabledNext.'"><a class="page-link" href="'.h($base).'='.$next.'">&rsaquo;</a></li>';
        echo '<li class="page-item'.$disabledNext.'"><a class="page-link" href="'.h($base).'='.$pages.'">&raquo;</a></li>';
        echo '</ul></nav>';
      ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
