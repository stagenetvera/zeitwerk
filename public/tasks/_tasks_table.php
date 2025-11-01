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

$taskless_running = !empty($has_running) && (empty($running_task_id) || (int)$running_task_id === 0);

$show_company = !empty($show_company);
$is_sortable = $is_sortable ?? false;


?>
<?php
    $table_body_id = $table_body_id ?? 'dashTaskBody';
?>
<div class="table-responsive">
  <table class="table  table-hover mb-0">
    <thead>
      <tr>
        <?php if ($is_sortable): ?><th style="width:32px"></th><?php endif; ?>
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
    <tbody id="<?= h($table_body_id) ?>">
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

          $badge_deadline = '';
          $deadlineStr = $r['deadline'] ?? null;
          if (!empty($deadlineStr)) {
              $dlTs = strtotime($deadlineStr);
              if ($dlTs !== false) {
                  $today = strtotime('today');
                  if ($dlTs < $today) {
                      // Deadline bereits vorbei
                      $badge_deadline = 'badge bg-danger';
                  } else {
                      // Diff in vollen Tagen ab heute
                      $diffDays = (int) floor(($dlTs - $today) / 86400);
                      if ($diffDays <= 2) {
                          // innerhalb der nächsten 2 Tage (inkl. heute)
                          $badge_deadline = 'badge bg-warning text-dark';
                      } else {
                          // weiter in der Zukunft als 2 Tage
                          $badge_deadline = 'badge bg-success';
                      }
                  }
              }
          }

          $tid = (int)$r['task_id'];
          $rt  = $return_to ?? ($_SERVER['REQUEST_URI'] ?? '');

          $priority_class = '';
          switch ($r['priority']) {
            case "high" :
              $priority_class="badge bg-danger";
              break;
            case "medium" :
              $priority_class="badge bg-warning";
              break;
            case "low" :
              $priority_class="badge bg-secondary";
              break;
          }
        ?>
        <tr data-task-id="<?= (int)$r['task_id'] ?>"
            <?= $is_sortable ? 'draggable="true" class="can-drag"' : '' ?>
            >
          <?php if ($is_sortable): ?>
            <td title="Ziehen, um zu sortieren">↕</td>

          <?php endif; ?>
          <?php if ($show_company): ?>
            <td><?= h($r['company_name'] ?? '—') ?></td>
          <?php endif; ?>
          <td><?= h($r['project_title'] ?? '—') ?></td>
          <td><?= h($r['description'] ?? '') ?></td>
          <td><span class="<?=$priority_class?>">
              <?= h($r['priority'] ?? '—') ?>
          </span></td>
          <td><?= h($r['status'] ?? '—') ?></td>
          <td><?php if ($badge_deadline): ?>
            <span class="<?= $badge_deadline ?>"><?= !empty($r['deadline']) ? h(_fmt_dmy($r['deadline'])) : '—' ?></span>
            <?php else: ?>
                <?= !empty($r['deadline']) ? h(_fmt_dmy($r['deadline'])) : '—' ?>
            <?php endif; ?>
            </td>
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
              <form method="post" action="<?= url('/times/stop.php') ?>" class="d-inline">
                <?= csrf_field() ?>
                <?= return_to_hidden($rt) ?>
                <input type="hidden" name="id" value="<?= (int)($running_time_id ?? 0) ?>">
                <button class="btn btn-sm btn-warning btn-icon" title="Stop" aria-label="Stop">
                    <i class="bi bi-stop-fill"></i>
                    <span class="visually-hidden">Stop</span>
                </button>
              </form>
            <?php else: ?>
              <form method="post" action="<?= url('/times/start.php') ?>" class="d-inline">
                <?= csrf_field() ?>
                <?= return_to_hidden($rt) ?>
                <input type="hidden" name="task_id" value="<?= $tid ?>">

                <button class="btn btn-sm btn-success btn-icon" title="Start" aria-label="Start">
                    <i class="bi bi-play-fill"></i>
                    <span class="visually-hidden">Start</span>
                </button>
              </form>
            <?php endif; ?>

            <?php
                $is_done = isset($r['status']) && $r['status'] === 'abgeschlossen';
            ?>
            <form method="post"
                action="<?= url('/tasks/complete.php') ?>"
                class="d-inline">
                <?= csrf_field() ?>
                <?= return_to_hidden($_SERVER['REQUEST_URI'] ?? '') ?>
                <input type="hidden" name="id" value="<?= $tid ?>">
                <button class="btn btn-sm btn-outline-primary btn-icon"
                        <?= $is_done ? 'disabled title="Schon abgeschlossen" aria-disabled="true"' : 'title="Fertig" aria-label="Fertig"' ?>>
                <i class="bi bi-check2"></i>
                <span class="visually-hidden">Fertig</span>
                </button>
            </form>

            <a class="btn btn-sm btn-outline-secondary"
               href="<?= url('/tasks/edit.php') ?>?id=<?= $tid ?>&return_to=<?= urlencode($rt) ?>">
              <i class="bi bi-pencil"></i>
              <span class="visually-hidden">Bearbeiten</span>
            </a>

            <?php if (empty($r['has_billed'])): ?>
              <form method="post" action="<?= url('/tasks/delete.php') ?>"
                    class="d-inline"
                    onsubmit="return confirm('Wollen Sie diese Aufgabe wirklich löschen? Zugewiesene, nicht abgerechnete Zeiten werden damit ebenfalls gelöscht.');">
                <?= csrf_field() ?>
                <?= return_to_hidden($rt) ?>
                <input type="hidden" name="id" value="<?= $tid ?>">
                <button class="btn btn-sm btn-outline-danger btn-icon" title="Löschen" aria-label="Löschen">
                    <i class="bi bi-trash"></i>
                    <span class="visually-hidden">Löschen</span>
                </button>
              </form>
            <?php else: ?>
              <button class="btn btn-sm btn-outline-danger" disabled
                      title="Diese Aufgabe enthält Zeiten im Status ‚in Abrechnung‘/‚abgerechnet‘. Löschen nicht erlaubt.">
                <i class="bi bi-trash"></i>
                    <span class="visually-hidden">Löschen</span>
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$tasks): ?>
        <tr>
          <td colspan="<?= ($show_company ? 10 : 8) ?>" class="text-center text-muted">
            Keine Aufgaben nach diesem Filter.
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>