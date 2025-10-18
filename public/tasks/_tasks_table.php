<?php
// public/_partials/tasks_table.php
/**
 * Erwartet:
 * - $tasks           Array von Zeilen mit Keys:
 *      task_id, description, project_title, priority, status, deadline,
 *      planned_minutes, spent_minutes, has_billed,
 *      optional company_name (falls $show_company = true)
 * - $show_company    bool (optional, default false) → extra Spalte "Firma"
 * - $has_running     bool  | optional (für Start/Stop)
 * - $running_task_id int   | optional
 * - $running_time_id int   | optional
 * - $return_to       string|optional (für return_to_hidden)
 *
 * Benötigt globale Helper: h(), url(), csrf_field(), return_to_hidden().
 */

$show_company = !empty($show_company);

if (!function_exists('fmt_minutes')) {
  function fmt_minutes($m){
    if ($m === null) return '—';
    $m = (int)$m; $h = intdiv($m, 60); $r = $m % 60;
    return $h > 0 ? sprintf('%d:%02d h', $h, $r) : ($m.' min');
  }
}
?>
<div class="table-responsive">
  <table class="table table-striped table-hover mb-0">
    <thead>
      <tr>
        <?php if ($show_company): ?><th>Firma</th><?php endif; ?>
        <th>Projekt</th>
        <th>Aufgabe</th>
        <th>Priorität</th>
        <th>Status</th>
        <th>Deadline</th>
        <th>Geschätzt</th>
        <th>Zeit</th>
        <th class="text-end">Aktionen</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tasks as $r): ?>
        <?php
          $planned = $r['planned_minutes'] !== null ? (int)$r['planned_minutes'] : 0;
          $total   = (int)($r['spent_minutes'] ?? 0);

          $badge = '';
          if ($planned > 0) {
            $ratio = $total / $planned;
            if ($ratio >= 1.0)      $badge = 'badge bg-danger';
            elseif ($ratio >= 0.8)  $badge = 'badge bg-warning text-dark';
            else                    $badge = 'badge bg-success';
          }

          $tid = (int)$r['task_id'];
          $rt  = $return_to ?? ($_SERVER['REQUEST_URI'] ?? '');
        ?>
        <tr>
          <?php if ($show_company): ?>
            <td><?= h($r['company_name'] ?? '—') ?></td>
          <?php endif; ?>
          <td><?= h($r['project_title'] ?? '—') ?></td>
          <td><?= h($r['description'] ?? '') ?></td>
          <td><?= h($r['priority'] ?? '—') ?></td>
          <td><?= h($r['status'] ?? '—') ?></td>
          <td><?= !empty($r['deadline']) ? h($r['deadline']) : '—' ?></td>
          <td><?= $planned ? fmt_minutes($planned) : '—' ?></td>
          <td>
            <?php if ($badge): ?>
              <span class="<?= $badge ?>"><?= fmt_minutes($total) ?></span>
            <?php else: ?>
              <?= fmt_minutes($total) ?>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if (!empty($has_running) && (int)$running_task_id === $tid): ?>
              <form method="post" action="<?= url('/times/stop.php') ?>" class="d-inline me-1">
                <?= csrf_field() ?>
                <?= return_to_hidden($rt) ?>
                <input type="hidden" name="id" value="<?= (int)($running_time_id ?? 0) ?>">
                <button class="btn btn-sm btn-warning">Stop</button>
              </form>
            <?php else: ?>
              <form method="post" action="<?= url('/times/start.php') ?>" class="d-inline me-1">
                <?= csrf_field() ?>
                <?= return_to_hidden($rt) ?>
                <input type="hidden" name="task_id" value="<?= $tid ?>">
                <button class="btn btn-sm btn-success">Start</button>
              </form>
            <?php endif; ?>

            <?php
                $is_done = isset($r['status']) && $r['status'] === 'abgeschlossen';
            ?>
            <form method="post"
                action="<?= url('/tasks/complete.php') ?>"
                class="d-inline ms-1">
            <?= csrf_field() ?>
            <?= return_to_hidden($_SERVER['REQUEST_URI'] ?? '') ?>
            <input type="hidden" name="id" value="<?= $tid ?>">
            <button class="btn btn-sm btn-outline-primary"
                    <?= $is_done ? 'disabled title="Schon abgeschlossen"' : '' ?>>
                Fertig
            </button>
            </form>

            <a class="btn btn-sm btn-outline-secondary"
               href="<?= url('/tasks/edit.php') ?>?id=<?= $tid ?>&return_to=<?= urlencode($rt) ?>">
              Bearbeiten
            </a>

            <?php if (empty($r['has_billed'])): ?>
              <form method="post" action="<?= url('/tasks/delete.php') ?>"
                    class="d-inline"
                    onsubmit="return confirm('Wollen Sie diese Aufgabe wirklich löschen? Zugewiesene, nicht abgerechnete Zeiten werden damit ebenfalls gelöscht.');">
                <?= csrf_field() ?>
                <?= return_to_hidden($rt) ?>
                <input type="hidden" name="id" value="<?= $tid ?>">
                <button class="btn btn-sm btn-outline-danger">Löschen</button>
              </form>
            <?php else: ?>
              <button class="btn btn-sm btn-outline-danger" disabled
                      title="Diese Aufgabe enthält Zeiten im Status ‚in Abrechnung‘/‚abgerechnet‘. Löschen nicht erlaubt.">
                Löschen
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$tasks): ?>
        <tr>
          <td colspan="<?= $show_company ? 9 : 8 ?>" class="text-center text-muted">
            Keine Aufgaben nach diesem Filter.
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>